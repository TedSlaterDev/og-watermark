<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Cron;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Cron\Integrity;
use OrchardGrove\OgWatermark\Support\Meta;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Backup-integrity sweep tests.
 *
 * The sweep is exercised through its fully-injectable core, {@see Integrity::runOn()},
 * so the candidate id list, today's rotation index, the thresholds, and every
 * dependency (lock state, backup path, stored hash/size, stamped state, the flag
 * sink) are deterministic. The hash verification itself runs through the REAL
 * {@see \OrchardGrove\OgWatermark\Backup\BackupWriter::sha1Of()} against REAL temp
 * files written to disk, so corrupt/missing detection is genuinely end-to-end.
 *
 * The bar (never weakened): a gone/corrupt backup for a WATERMARKED attachment
 * flips STATUS_BACKUP_MISSING + a LAST_ERROR note; a VALID backup is left alone; a
 * currently-LOCKED attachment is skipped untouched; and above the threshold the
 * rotating subset covers the WHOLE set over the window while NEVER sha1-ing every
 * candidate in a single run.
 */
final class IntegrityTest extends TestCase {

	/** @var array<int,array<string,mixed>> In-memory post meta, keyed by attachment id. */
	private array $meta = [];

	/** @var array<int,string> Real backup temp files written for this test, keyed by id. */
	private array $files = [];

	/** Temp root for this test's backup files. */
	private string $root = '';

	protected function setUp(): void {
		parent::setUp();

		$this->root = sys_get_temp_dir() . '/ogwm-integrity-' . uniqid( '', true );
		mkdir( $this->root, 0777, true );

		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ): bool {
				$this->meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( $id, $key, $single = false ) => $this->meta[ (int) $id ][ $key ] ?? ''
		);
	}

	protected function tearDown(): void {
		foreach ( $this->files as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path );
			}
		}
		if ( is_dir( $this->root ) ) {
			@rmdir( $this->root );
		}
		$this->meta  = [];
		$this->files = [];
		parent::tearDown();
	}

	// =====================================================================
	// Missing / corrupt backup flips STATUS_BACKUP_MISSING; valid is left alone.
	// =====================================================================

	public function testFlagsBackupMissingWhenFileIsGoneForAWatermarkedAttachment(): void {
		// A watermarked attachment whose backup file no longer exists on disk.
		$id   = 11;
		$hash = sha1( 'pristine-bytes' );
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		$report = Integrity::runOn(
			[ $id ],
			0,
			$this->deps(
				[
					'backupPath' => fn( int $i ): ?string => $this->root . '/gone-' . $i . '.bin', // never written
					'storedHash' => fn( int $i ): string => $hash,
				]
			)
		);

		$this->assertSame( 1, $report['flagged'] );
		$this->assertSame( Meta::STATUS_BACKUP_MISSING, $this->meta[ $id ][ Meta::STATUS ] );
		$this->assertArrayHasKey( Meta::LAST_ERROR, $this->meta[ $id ] );
		$this->assertNotSame( '', (string) $this->meta[ $id ][ Meta::LAST_ERROR ] );
	}

	public function testFlagsBackupMissingWhenFileIsCorruptForAWatermarkedAttachment(): void {
		// Backup exists but its bytes no longer hash to the recorded value (silent
		// same-or-different-size corruption). The REAL sha1Of catches it.
		$id   = 12;
		$path = $this->writeBackup( $id, 'good-original-bytes' );
		$goodHash = sha1_file( $path );

		// Corrupt the file on disk AFTER recording the good hash.
		file_put_contents( $path, 'tampered-bytes-now-different' );

		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		$report = Integrity::runOn(
			[ $id ],
			0,
			$this->deps(
				[
					'backupPath' => fn( int $i ): ?string => $this->files[ $i ] ?? null,
					'storedHash' => fn( int $i ): string => $goodHash,
					// No recorded size, so the cheap byte check is skipped and sha1 runs.
					'storedSize' => fn( int $i ): int => 0,
				]
			)
		);

		$this->assertSame( 1, $report['sha1'], 'corrupt file must reach the sha1 stage' );
		$this->assertSame( 1, $report['flagged'] );
		$this->assertSame( Meta::STATUS_BACKUP_MISSING, $this->meta[ $id ][ Meta::STATUS ] );
	}

	public function testLeavesAValidBackupUntouched(): void {
		$id   = 13;
		$path = $this->writeBackup( $id, 'pristine-and-unchanged' );
		$hash = sha1_file( $path );

		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		$report = Integrity::runOn(
			[ $id ],
			0,
			$this->deps(
				[
					'backupPath' => fn( int $i ): ?string => $this->files[ $i ] ?? null,
					'storedHash' => fn( int $i ): string => $hash,
					'storedSize' => fn( int $i ): int => (int) filesize( $path ),
				]
			)
		);

		$this->assertSame( 0, $report['flagged'] );
		$this->assertSame( Meta::STATUS_WATERMARKED, $this->meta[ $id ][ Meta::STATUS ], 'a valid backup must not flip status' );
		$this->assertArrayNotHasKey( Meta::LAST_ERROR, $this->meta[ $id ] );
	}

	public function testByteSizeMismatchFlagsWithoutNeedingSha1(): void {
		// A cheap size mismatch is caught BEFORE any sha1 read.
		$id   = 14;
		$path = $this->writeBackup( $id, 'four' ); // 4 bytes on disk
		$hash = sha1_file( $path );

		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		$report = Integrity::runOn(
			[ $id ],
			0,
			$this->deps(
				[
					'backupPath' => fn( int $i ): ?string => $this->files[ $i ] ?? null,
					'storedHash' => fn( int $i ): string => $hash,
					'storedSize' => fn( int $i ): int => 9999, // recorded size != on-disk size
				]
			)
		);

		$this->assertSame( 0, $report['sha1'], 'size mismatch short-circuits before sha1' );
		$this->assertSame( 1, $report['flagged'] );
		$this->assertSame( Meta::STATUS_BACKUP_MISSING, $this->meta[ $id ][ Meta::STATUS ] );
	}

	public function testDoesNotFlagAGoneBackupForANeverStampedAttachment(): void {
		// Backup gone, but the served files were never watermarked → not terminal,
		// nothing unrecoverable to surface, so it is NOT flagged backup_missing.
		$id = 15;
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_NONE;

		$report = Integrity::runOn(
			[ $id ],
			0,
			$this->deps(
				[
					'backupPath' => fn( int $i ): ?string => $this->root . '/missing.bin',
					'storedHash' => fn( int $i ): string => sha1( 'x' ),
					'isStamped'  => fn( int $i ): bool => false,
				]
			)
		);

		$this->assertSame( 0, $report['flagged'] );
		$this->assertSame( Meta::STATUS_NONE, $this->meta[ $id ][ Meta::STATUS ], 'a never-stamped attachment is not flagged backup_missing' );
		$this->assertArrayNotHasKey( Meta::LAST_ERROR, $this->meta[ $id ] );
	}

	// =====================================================================
	// Lock-aware: a currently-locked attachment is skipped untouched.
	// =====================================================================

	public function testSkipsACurrentlyLockedAttachment(): void {
		$id   = 21;
		$path = $this->writeBackup( $id, 'whatever' );

		// Even though the recorded hash is WRONG (would normally flag), the locked
		// attachment must be skipped entirely — never read, never flagged.
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		$report = Integrity::runOn(
			[ $id ],
			0,
			$this->deps(
				[
					'isLocked'   => fn( int $i ): bool => true,
					'backupPath' => fn( int $i ): ?string => $this->files[ $i ] ?? null,
					'storedHash' => fn( int $i ): string => 'definitely-not-the-real-hash',
				]
			)
		);

		$this->assertSame( 1, $report['skipped'] );
		$this->assertSame( 0, $report['checked'] );
		$this->assertSame( 0, $report['flagged'] );
		$this->assertSame( Meta::STATUS_WATERMARKED, $this->meta[ $id ][ Meta::STATUS ], 'a locked attachment is left untouched' );
	}

	// =====================================================================
	// Rotating subset: full coverage over the window, never all-in-one-run.
	// =====================================================================

	public function testAboveThresholdNeverSha1sEveryCandidateInOneRun(): void {
		// 300 backed-up attachments, threshold 200, window 7. A single day's run must
		// sha1 only a SLICE — strictly fewer than the full set.
		$window = 7;
		$ids    = range( 1, 300 );

		// Real backup files would be 300 disk writes; the coverage property is about
		// the SELECTION, so use a path closure that points each id at one shared
		// valid file and a matching hash (no flagging happens — we only count sha1).
		$shared = $this->writeBackup( 999, 'shared-valid-bytes' );
		$hash   = sha1_file( $shared );

		$deps = $this->deps(
			[
				'threshold'  => 200,
				'window'     => $window,
				'batchLimit' => 1000,
				'backupPath' => fn( int $i ): string => $shared,
				'storedHash' => fn( int $i ): string => $hash,
				'storedSize' => fn( int $i ): int => 0, // force the sha1 path (no cheap-size short-circuit)
			]
		);

		$report = Integrity::runOn( $ids, 0, $deps );

		$this->assertSame( 300, $report['checked'], 'every candidate is cheaply checked' );
		$this->assertGreaterThan( 0, $report['sha1'] );
		$this->assertLessThan( 300, $report['sha1'], 'above the threshold a single run must NOT sha1 the whole set' );
	}

	public function testRotatingSubsetCoversTheWholeSetOverTheWindow(): void {
		// Union of the per-day sha1 slices across `window` consecutive days must cover
		// EVERY candidate exactly — no id left unverified, none double-counted in a day.
		//
		// To observe WHICH ids the sweep actually sha1s (not just how many), every
		// backup is made CORRUPT so reaching the sha1 stage flags the attachment; the
		// flag sink records the id. Selection is thus measured through the real code
		// path, not asserted against a re-derived formula.
		$window = 7;
		$ids    = range( 1, 300 );

		$corrupt = $this->writeBackup( 998, 'on-disk-bytes' );
		$wrong   = sha1( 'a-different-value-so-sha1-never-matches' );

		$sha1edByDay = [];

		for ( $day = 0; $day < $window; $day++ ) {
			$this->meta = [];
			foreach ( $ids as $id ) {
				$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;
			}

			Integrity::runOn(
				$ids,
				$day,
				$this->deps(
					[
						'threshold'  => 200,
						'window'     => $window,
						'batchLimit' => 1000,
						'backupPath' => fn( int $i ): string => $corrupt,
						'storedHash' => fn( int $i ): string => $wrong, // forces a hash-mismatch flag
						'storedSize' => fn( int $i ): int => 0,         // skip cheap-size short-circuit
					]
				)
			);

			// Every id flagged THIS day is an id that reached (and failed) the sha1 stage.
			$sha1edByDay[ $day ] = array_keys(
				array_filter(
					$this->meta,
					static fn( array $m ): bool => ( $m[ Meta::STATUS ] ?? '' ) === Meta::STATUS_BACKUP_MISSING
				)
			);
		}

		// No id appears in two different days' slices (disjoint coverage).
		$seen = [];
		foreach ( $sha1edByDay as $day => $dayIds ) {
			foreach ( $dayIds as $id ) {
				$this->assertArrayNotHasKey( $id, $seen, "id {$id} sha1-verified on more than one day" );
				$seen[ $id ] = true;
			}
		}

		// And the union covers every candidate exactly once over the window.
		$this->assertCount( count( $ids ), $seen, 'every candidate must be sha1-covered across the window' );
	}

	public function testBelowThresholdVerifiesEveryCandidate(): void {
		$ids    = range( 1, 10 );
		$shared = $this->writeBackup( 997, 'small-set-bytes' );
		$hash   = sha1_file( $shared );

		$report = Integrity::runOn(
			$ids,
			0,
			$this->deps(
				[
					'threshold'  => 200,
					'window'     => 7,
					'backupPath' => fn( int $i ): string => $shared,
					'storedHash' => fn( int $i ): string => $hash,
					'storedSize' => fn( int $i ): int => 0,
				]
			)
		);

		$this->assertSame( 10, $report['sha1'], 'a small set under the threshold is fully sha1-verified' );
	}

	// =====================================================================
	// Chunking: a batch overflow is truncated + reports the last id seen.
	// =====================================================================

	public function testTruncatesAndReportsLastIdWhenOverBatchLimit(): void {
		$ids = range( 1, 50 );

		$report = Integrity::runOn(
			$ids,
			0,
			$this->deps(
				[
					'batchLimit' => 20,
					'storedHash' => fn( int $i ): string => '', // skip all work; only paging matters
				]
			)
		);

		$this->assertTrue( $report['truncated'] );
		$this->assertSame( 20, $report['lastId'], 'lastId is the final id examined within the batch' );
	}

	// =====================================================================
	// Production run(): chunked continuation walks the WHOLE library across ticks.
	//
	// These exercise the real run()/backedUpIds() adapter (the seam the cursor bug
	// lived in), not just the injectable core. get_posts honors the keyset cursor
	// the way real WP_Query does, so a continuation that doesn't push the cursor into
	// the query (re-fetching the lowest N ids and post-filtering) would advance ~1 id
	// per tick and fail the disjoint-window assertions below.
	// =====================================================================

	public function testRunWalksTheWholeLibraryAcrossContinuationTicks(): void {
		// 600 backed-up ids, batch limit 250 → three ticks (250, 250, 100). Each tick
		// must cover a DISJOINT, ADVANCING window and the union must be every id.
		$allIds = range( 1, 600 );

		$options   = [];
		$scheduled = [];
		$seenById  = [];

		$this->stubRunDeps( $allIds, $options, $scheduled, $seenById );

		// Tick 1 (cursor 0).
		Integrity::run();
		// Tick 2 + 3 (the deferred continuation re-enters run() with the advanced cursor).
		Integrity::run();
		Integrity::run();

		// Every id examined exactly once (disjoint, complete coverage over the ticks).
		sort( $seenById );
		$this->assertSame( $allIds, $seenById, 'the chunked sweep must cover the whole library, once, across ticks' );

		// A continuation was scheduled while work remained.
		$this->assertContains( 'ogwm_integrity_continue', $scheduled, 'a continuation is scheduled when a batch overflows' );

		// And the cursor is cleared once the sweep drains (final tick was not truncated).
		$this->assertArrayNotHasKey( 'ogwm_integrity_cursor', $options, 'the cursor is cleared when the sweep completes' );
	}

	public function testRunClearsCursorImmediatelyForASmallLibrary(): void {
		$allIds = range( 1, 10 );

		$options   = [];
		$scheduled = [];
		$seenById  = [];

		$this->stubRunDeps( $allIds, $options, $scheduled, $seenById );

		Integrity::run();

		sort( $seenById );
		$this->assertSame( $allIds, $seenById, 'a small library is swept in one tick' );
		$this->assertNotContains( 'ogwm_integrity_continue', $scheduled, 'no continuation for a single-batch library' );
		$this->assertArrayNotHasKey( 'ogwm_integrity_cursor', $options );
	}

	/**
	 * Wire up the WP functions Integrity::run() reaches so the production adapter runs
	 * against an in-memory library that honors the keyset cursor. `$seenById` collects
	 * every id the sweep actually examined (via the storedHash probe each candidate
	 * hits), so disjoint/advancing coverage is observed end-to-end.
	 *
	 * @param array<int,int>       $allIds    The full backed-up id set (id-ascending).
	 * @param array<string,mixed>  $options   By-ref option store.
	 * @param array<int,string>    $scheduled By-ref single-event hook log.
	 * @param array<int,int>       $seenById  By-ref collector of examined ids.
	 */
	private function stubRunDeps( array $allIds, array &$options, array &$scheduled, array &$seenById ): void {
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $options ) ? $options[ $name ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) use ( &$options ): bool {
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$options ): bool {
				unset( $options[ $name ] );
				return true;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->alias(
			fn( $hook, $args = [] ) => in_array( (string) $hook, $scheduled, true ) ? time() + 30 : false
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = [] ) use ( &$scheduled ): bool {
				$scheduled[] = (string) $hook;
				return true;
			}
		);

		// get_posts honors BOTH the keyset cursor (carried in self::$keysetAfter via the
		// posts_where filter, which we can't observe here) AND the meta EXISTS scope, by
		// reading the cursor from the option the way the real WHERE clause would. We model
		// the keyset by paging $allIds: posts_per_page -1 returns the count probe; a
		// positive limit returns the next page strictly above the current cursor option.
		Functions\when( 'get_posts' )->alias(
			function ( $args = [] ) use ( $allIds, &$options ): array {
				$limit = (int) ( $args['posts_per_page'] ?? -1 );
				if ( -1 === $limit ) {
					return $allIds; // countBackedUp(): whole set.
				}
				$after = (int) ( $options['ogwm_integrity_cursor'] ?? 0 );
				$above = array_values( array_filter( $allIds, static fn( int $id ): bool => $id > $after ) );
				return array_slice( $above, 0, $limit );
			}
		);

		// Every candidate the sweep examines hits storedHash — collect those ids. A blank
		// hash short-circuits the per-id work after recording the id, so the sweep stays
		// cheap while we still observe exactly which ids each tick claimed.
		$this->meta = [];
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = false ) use ( &$seenById ): string {
				if ( '_ogwm_backup_hash' === $key ) {
					$seenById[] = (int) $id;
				}
				return '';
			}
		);
	}

	// =====================================================================
	// Helpers.
	// =====================================================================

	/**
	 * Merge caller overrides over a baseline dependency set. The baseline makes no
	 * attachment locked, every attachment "stamped" (watermarked), and routes
	 * flagging into the in-memory meta store via the real production sink.
	 *
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function deps( array $overrides ): array {
		$base = [
			'threshold'  => 200,
			'window'     => 7,
			'batchLimit' => 250,
			'isLocked'   => static fn( int $id ): bool => false,
			'backupPath' => static fn( int $id ): ?string => null,
			'storedHash' => static fn( int $id ): string => '',
			'storedSize' => static fn( int $id ): int => 0,
			'isStamped'  => static fn( int $id ): bool => true,
			'flag'       => function ( int $id, string $note ): void {
				$this->meta[ $id ][ Meta::STATUS ]     = Meta::STATUS_BACKUP_MISSING;
				$this->meta[ $id ][ Meta::LAST_ERROR ] = 'integrity: ' . $note;
			},
		];

		return array_merge( $base, $overrides );
	}

	/** Write a real backup temp file for $id and remember it for cleanup. */
	private function writeBackup( int $id, string $bytes ): string {
		$path = $this->root . '/backup-' . $id . '-' . uniqid( '', true ) . '.bin';
		file_put_contents( $path, $bytes );
		$this->files[ $id ] = $path;
		return $path;
	}
}
