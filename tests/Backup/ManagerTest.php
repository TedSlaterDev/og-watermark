<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Backup;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Backup\Manager;
use OrchardGrove\OgWatermark\Backup\Storage;
use OrchardGrove\OgWatermark\Support\Meta;
use OrchardGrove\OgWatermark\Tests\TestCase;
use ReflectionClass;

/**
 * Manager orchestration tests.
 *
 * WP functions are Brain-Monkey-stubbed, but the actual backup copy goes through
 * the REAL BackupWriter against REAL temp files, so create->verify->restore->
 * delete is genuinely exercised end-to-end. Post meta is modeled with an
 * in-memory store so the HARD GUARD (never back up an already-stamped original)
 * and the immutable-hash invariant are tested against real read/write behavior.
 */
final class ManagerTest extends TestCase {

	private string $contentDir = '';
	private string $uploadsDir = '';
	private string $monthDir = '';
	private string $backupBase = '';

	/** @var array<int,array<string,mixed>> In-memory post-meta store, keyed by id. */
	private array $meta = [];

	/** @var array<string,mixed> In-memory transient store. */
	private array $transients = [];

	/** Dimensions of the generated pristine PNG; large enough to clear Preflight's
	 *  256-byte floor and match the stubbed attachment metadata exactly. */
	private const IMG_W = 64;
	private const IMG_H = 48;

	protected function setUp(): void {
		parent::setUp();

		// Real temp roots so the whole backup tree is genuinely created/copied.
		$root             = sys_get_temp_dir() . '/ogwm-mgr-' . uniqid( '', true );
		$this->contentDir = $root . '/wp-content';
		$this->uploadsDir = $this->contentDir . '/uploads';
		$this->monthDir   = $this->uploadsDir . '/2026/06';
		mkdir( $this->monthDir, 0777, true );

		// Pre-create the backup base inside THIS test's temp root. WP_CONTENT_DIR
		// is a process-global constant that another test may have already bound to
		// a now-deleted dir, so we pin Storage's base via the persisted-base option
		// (Storage reuses a stored base verbatim when it exists on disk). This keeps
		// the backup tree isolated to this test regardless of test ordering.
		$this->backupBase = $this->contentDir . '/og-watermark-originals-test';
		mkdir( $this->backupBase, 0777, true );

		// NB: we deliberately do NOT define WP_CONTENT_DIR here. It is a
		// process-global constant shared with StorageTest, and Storage::baseDir()
		// never consults it once a persisted base is pinned (below), so defining it
		// would only risk clobbering StorageTest's content-dir assertion.

		// BackupWriterTest routes the namespaced copy()/rename() through Brain
		// Monkey, which instruments them process-wide (Patchwork). Once that test
		// has run in the same process, EVERY later call to those namespaced
		// functions must also be mocked or Brain Monkey throws "not defined nor
		// mocked". We therefore declare the SAME faithful pass-throughs here so the
		// real backup copy + restore rename still hit the genuine filesystem.
		Functions\when( 'OrchardGrove\OgWatermark\Backup\copy' )->alias(
			static fn( string $s, string $d ): bool => copy( $s, $d )
		);
		Functions\when( 'OrchardGrove\OgWatermark\Backup\rename' )->alias(
			static fn( string $a, string $b ): bool => rename( $a, $b )
		);

		// Storage helper stubs (identical set to StorageTest).
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) );
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) . '/' );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wp_generate_password' )->justReturn( 'tok123456789' );
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => $this->uploadsDir ] );
		Functions\when( 'wp_mkdir_p' )->alias(
			static fn( $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0777, true )
		);
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => 'ogwm_backup_base' === $name ? $this->backupBase : $default
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'sanitize_file_name' )->alias(
			static fn( $name ) => preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name )
		);

		// In-memory post meta.
		Functions\when( 'get_post_meta' )->alias(
			fn( $id, $key, $single = false ) => $this->meta[ $id ][ $key ] ?? ''
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);

		// In-memory transients (stats cache).
		Functions\when( 'get_transient' )->alias(
			fn( $key ) => $this->transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);

		self::resetBaseCache();
	}

	protected function tearDown(): void {
		self::resetBaseCache();
		$this->rrmdir( dirname( $this->contentDir ) );
		$this->meta       = [];
		$this->transients = [];
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Happy path: create -> verify -> restore -> delete.
	// -----------------------------------------------------------------

	public function testCreateVerifyRestoreDeleteHappyPath(): void {
		$id       = 42;
		$original = $this->writeOriginal( $id );
		$srcHash  = sha1_file( $original );

		$this->stubOriginalPath( $id, $original );
		$this->stubMeta( $id, [ '_width' => self::IMG_W, '_height' => self::IMG_H ] );

		// --- create ---
		$result = Manager::ensureBackup( $id );
		$this->assertTrue( $result['ok'], 'ensureBackup should succeed on a pristine original' );

		$backup = Manager::backupPath( $id );
		$this->assertNotNull( $backup );
		$this->assertFileExists( $backup );

		// The backup is a byte-for-byte copy of the never-composited original.
		$this->assertSame(
			$srcHash,
			sha1_file( $backup ),
			'backup must be byte-identical to the pristine original'
		);

		// The immutable-hash invariant: stored hash equals the pristine source hash.
		$this->assertSame( $srcHash, $this->meta[ $id ][ Meta::BACKUP_HASH ] );
		$this->assertSame( (string) filesize( $original ), (string) $this->meta[ $id ][ Meta::BACKUP_BYTES ] );

		// The stored path is RELATIVE to the Storage base and mirrors year/month.
		$this->assertSame(
			'2026/06/42-' . basename( $original ),
			$this->meta[ $id ][ Meta::BACKUP_REL ]
		);

		// --- verify ---
		$this->assertTrue( Manager::verify( $id ) );

		// --- restore: corrupt the live original, then restore from backup ---
		file_put_contents( $original, 'WATERMARKED-GARBAGE' );
		$this->assertNotSame( $srcHash, sha1_file( $original ) );

		$this->assertTrue( Manager::restoreOriginal( $id ) );
		$this->assertSame(
			$srcHash,
			sha1_file( $original ),
			'restore must put the pristine bytes back on the live original'
		);
		// No leftover temp file from the staged restore.
		$this->assertCount( 0, glob( dirname( $original ) . '/*.ogwm-restore-*.tmp' ) ?: [] );

		// --- delete ---
		$this->assertTrue( Manager::deleteBackup( $id ) );
		$this->assertFileDoesNotExist( $backup );
		$this->assertArrayNotHasKey( Meta::BACKUP_HASH, $this->meta[ $id ] ?? [] );
		$this->assertArrayNotHasKey( Meta::BACKUP_REL, $this->meta[ $id ] ?? [] );
		$this->assertArrayNotHasKey( Meta::BACKUP_BYTES, $this->meta[ $id ] ?? [] );

		// After delete, verify is false again.
		$this->assertFalse( Manager::verify( $id ) );
	}

	// -----------------------------------------------------------------
	// seedFrom(): seed the master from an EXPLICIT clean path, never the resolver.
	// -----------------------------------------------------------------

	public function testSeedFromSeedsTheMasterFromTheGivenPathAndNeverReadsTheResolver(): void {
		$id = 4242;

		// The EXPLICIT clean source the bridge hands seedFrom (e.g. the `-e` edited
		// file). seedFrom must copy THIS file and derive the hash from it.
		$cleanSource = $this->writeOriginal( $id );
		$cleanHash   = sha1_file( $cleanSource );

		// Preflight reads the CURRENT attachment metadata dims; they describe the
		// clean source. No status/stored-hash precondition applies to seedFrom.
		$this->stubMeta( $id, [ '_width' => self::IMG_W, '_height' => self::IMG_H ] );

		// THE invariant: seedFrom must NEVER consult wp_get_original_image_path — its
		// whole reason to exist is to bypass the (for big images, watermarked) resolver.
		Functions\expect( 'wp_get_original_image_path' )->never();

		$result = Manager::seedFrom( $id, $cleanSource );

		$this->assertTrue( $result['ok'], 'seedFrom must create a master from the explicit clean path' );

		// The stored hash is the clean source's hash, and the on-disk master matches it.
		$this->assertSame( $cleanHash, $this->meta[ $id ][ Meta::BACKUP_HASH ], 'hash must derive from the given path' );

		$backup = Manager::backupPath( $id );
		$this->assertNotNull( $backup );
		$this->assertFileExists( $backup );
		$this->assertSame( $cleanHash, sha1_file( $backup ), 'the master is a byte-for-byte copy of the given path' );

		// And the freshly-seeded master verifies.
		$this->assertTrue( Manager::verify( $id ) );
	}

	public function testSeedFromRejectsEmptyPathAndInvalidId(): void {
		Functions\expect( 'wp_get_original_image_path' )->never();

		$empty = Manager::seedFrom( 5, '' );
		$this->assertFalse( $empty['ok'] );
		$this->assertSame( 'empty-clean-source-path', $empty['reason'] );

		$badId = Manager::seedFrom( 0, '/tmp/whatever.png' );
		$this->assertFalse( $badId['ok'] );
		$this->assertSame( 'invalid-attachment-id', $badId['reason'] );
	}

	public function testSeedFromRejectsAStubSourceWithoutPoisoningExistingMeta(): void {
		$id = 4243;

		// A zero-byte stub source: Preflight rejects it (offload signature), so seedFrom
		// must write NO backup meta and create NO backup file from it.
		$stub = $this->monthDir . '/' . $id . '-stub.png';
		file_put_contents( $stub, '' );
		$this->stubMeta( $id, [ '_width' => 4000, '_height' => 3000 ] );

		Functions\expect( 'wp_get_original_image_path' )->never();

		$result = Manager::seedFrom( $id, $stub );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( Meta::STATUS_SKIPPED_OFFLOADED, $result['status'] );
		$this->assertArrayNotHasKey( Meta::BACKUP_HASH, $this->meta[ $id ] ?? [] );
		$this->assertArrayNotHasKey( Meta::BACKUP_REL, $this->meta[ $id ] ?? [] );
	}

	// -----------------------------------------------------------------
	// Idempotency.
	// -----------------------------------------------------------------

	public function testEnsureBackupIsIdempotentNoOpWhenValidBackupExists(): void {
		$id       = 7;
		$original = $this->writeOriginal( $id );
		$this->stubOriginalPath( $id, $original );
		$this->stubMeta( $id, [ '_width' => self::IMG_W, '_height' => self::IMG_H ] );

		$this->assertTrue( Manager::ensureBackup( $id )['ok'] );
		$backup      = Manager::backupPath( $id );
		$firstHash   = $this->meta[ $id ][ Meta::BACKUP_HASH ];
		$firstMtime  = filemtime( $backup );

		// Mark the attachment as watermarked: a second ensureBackup MUST be a
		// no-op (it already has a valid backup) and must NOT trip the hard guard.
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		// wp_get_original_image_path must NOT be consulted on the idempotent path.
		Functions\expect( 'wp_get_original_image_path' )->never();

		$result = Manager::ensureBackup( $id );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'backup-already-valid', $result['reason'] );
		$this->assertSame( $firstHash, $this->meta[ $id ][ Meta::BACKUP_HASH ], 'hash must be unchanged' );
		$this->assertSame( $firstMtime, filemtime( $backup ), 'backup file must not be rewritten' );
	}

	// -----------------------------------------------------------------
	// THE HARD GUARD: never back up an already-stamped original.
	// -----------------------------------------------------------------

	public function testHardGuardWatermarkedWithoutBackupReturnsBackupMissingAndWritesNothing(): void {
		$id       = 99;
		$original = $this->writeOriginal( $id ); // pretend this is now a WATERMARKED file
		$this->stubOriginalPath( $id, $original );
		$this->stubMeta( $id, [ '_width' => self::IMG_W, '_height' => self::IMG_H ] );

		// Already stamped, but no valid backup recorded.
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		// The resolver must NEVER be read for an already-stamped attachment, since
		// the on-disk original could itself be watermarked.
		Functions\expect( 'wp_get_original_image_path' )->never();

		$result = Manager::ensureBackup( $id );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( Meta::STATUS_BACKUP_MISSING, $result['status'] );

		// NOTHING written: no backup file, no backup meta.
		Storage::ensureDir();
		$this->assertCount(
			0,
			glob( Storage::baseDir() . '/2026/06/*' ) ?: [],
			'no backup file may be created from an already-stamped original'
		);
		$this->assertArrayNotHasKey( Meta::BACKUP_HASH, $this->meta[ $id ] );
		$this->assertArrayNotHasKey( Meta::BACKUP_REL, $this->meta[ $id ] );
	}

	public function testHardGuardAlsoTripsWhenAStaleHashIsPresentEvenIfStatusLooksPristine(): void {
		$id       = 101;
		$original = $this->writeOriginal( $id );
		$this->stubOriginalPath( $id, $original );
		$this->stubMeta( $id, [ '_width' => self::IMG_W, '_height' => self::IMG_H ] );

		// Status reads pristine, but a hash is recorded while the backup file is
		// gone => verify() is false, so this must NOT re-derive a master.
		$this->meta[ $id ][ Meta::STATUS ]      = Meta::STATUS_NONE;
		$this->meta[ $id ][ Meta::BACKUP_HASH ] = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

		Functions\expect( 'wp_get_original_image_path' )->never();

		$result = Manager::ensureBackup( $id );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( Meta::STATUS_BACKUP_MISSING, $result['status'] );
	}

	// -----------------------------------------------------------------
	// Offload / stub abort (skipped_offloaded) — no backup written.
	// -----------------------------------------------------------------

	public function testOffloadedStubYieldsSkippedOffloadedAndNoBackupFile(): void {
		$id = 55;

		// A zero-byte stub standing in for an offloaded original.
		$stub = $this->monthDir . '/' . $id . '-stub.jpg';
		file_put_contents( $stub, '' );

		$this->stubOriginalPath( $id, $stub );
		// Meta claims real dimensions the stub cannot satisfy => Preflight rejects.
		$this->stubMeta( $id, [ '_width' => 4000, '_height' => 3000 ] );
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_NONE;

		$result = Manager::ensureBackup( $id );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( Meta::STATUS_SKIPPED_OFFLOADED, $result['status'] );

		Storage::ensureDir();
		$this->assertCount(
			0,
			glob( Storage::baseDir() . '/2026/06/*' ) ?: [],
			'an offloaded stub must never produce a backup'
		);
		$this->assertArrayNotHasKey( Meta::BACKUP_HASH, $this->meta[ $id ] );
	}

	// -----------------------------------------------------------------
	// verify() integrity behavior.
	// -----------------------------------------------------------------

	public function testVerifyFailsWhenBackupBytesTamperedAfterCreation(): void {
		$id       = 70;
		$original = $this->writeOriginal( $id );
		$this->stubOriginalPath( $id, $original );
		$this->stubMeta( $id, [ '_width' => self::IMG_W, '_height' => self::IMG_H ] );

		$this->assertTrue( Manager::ensureBackup( $id )['ok'] );
		$backup = Manager::backupPath( $id );
		$this->assertTrue( Manager::verify( $id ) );

		// Tamper with the master on disk: verify() must catch the hash mismatch
		// and never re-bless the modified file.
		file_put_contents( $backup, 'tampered-master-bytes' );
		$this->assertFalse( Manager::verify( $id ) );
	}

	public function testVerifyFalseWhenNoHashStored(): void {
		$this->assertFalse( Manager::verify( 12345 ) );
	}

	public function testBackupPathNullWhenNoRelMeta(): void {
		$this->assertNull( Manager::backupPath( 8 ) );
	}

	public function testBackupPathRejectsTraversalEscape(): void {
		$id = 13;
		Storage::ensureDir();
		// A malicious/corrupt relative meta trying to climb out of the base.
		$this->meta[ $id ][ Meta::BACKUP_REL ] = '../../../../etc/passwd';
		$this->assertNull( Manager::backupPath( $id ) );
	}

	// -----------------------------------------------------------------
	// restore() guards.
	// -----------------------------------------------------------------

	public function testRestoreFailsWhenBackupUnverifiable(): void {
		$id       = 77;
		$original = $this->writeOriginal( $id );
		$this->stubOriginalPath( $id, $original );

		// A hash with no backup file => verify() false => restore must refuse and
		// must NOT touch the live original.
		$this->meta[ $id ][ Meta::BACKUP_HASH ] = 'cafebabecafebabecafebabecafebabecafebabe';
		$this->meta[ $id ][ Meta::BACKUP_REL ]  = '2026/06/77-original.png';

		$before = sha1_file( $original );
		$this->assertFalse( Manager::restoreOriginal( $id ) );
		$this->assertSame( $before, sha1_file( $original ), 'a failed restore must not alter the live original' );
	}

	// -----------------------------------------------------------------
	// stats() with meta-sum + transient caching.
	// -----------------------------------------------------------------

	public function testStatsReturnsCachedTransientWhenPresent(): void {
		$this->transients['ogwm_backup_stats'] = [
			'bytes' => 123456,
			'count' => 9,
		];

		$stats = Manager::stats();
		$this->assertSame( 123456, $stats['bytes'] );
		$this->assertSame( 9, $stats['count'] );
	}

	public function testEnsureBackupInvalidatesStatsCache(): void {
		$this->transients['ogwm_backup_stats'] = [
			'bytes' => 1,
			'count' => 1,
		];

		$id       = 200;
		$original = $this->writeOriginal( $id );
		$this->stubOriginalPath( $id, $original );
		$this->stubMeta( $id, [ '_width' => self::IMG_W, '_height' => self::IMG_H ] );

		Manager::ensureBackup( $id );
		$this->assertArrayNotHasKey(
			'ogwm_backup_stats',
			$this->transients,
			'creating a backup must invalidate the stats cache'
		);
	}

	public function testStatsDirectoryFallbackCountsBackupFilesExcludingHardeningFiles(): void {
		// No $wpdb in the test env, so computeStats falls back to a directory walk.
		$id       = 300;
		$original = $this->writeOriginal( $id );
		$this->stubOriginalPath( $id, $original );
		$this->stubMeta( $id, [ '_width' => self::IMG_W, '_height' => self::IMG_H ] );

		Manager::ensureBackup( $id );
		$this->transients = []; // force recompute

		$stats = Manager::stats();
		$this->assertSame( 1, $stats['count'], 'only the real backup file counts, not index.php/.htaccess/web.config' );
		$this->assertSame( (int) filesize( $original ), $stats['bytes'] );
	}

	public function testStatsFastPathUsesWpdbSumAndCountWithoutWalkingTheTree(): void {
		// Inject a fake $wpdb so the production fast path (SUM/COUNT query) actually
		// runs — the path that executes on every real install with a populated
		// postmeta table, which the directory-walk fallback never exercises.
		$wpdb = new class() {
			public string $postmeta = 'wp_postmeta';
			public ?string $lastKey = null;

			public function prepare( $query, ...$args ) {
				// Record the bound meta_key so we can assert it is the right one.
				$this->lastKey = (string) ( $args[0] ?? '' );
				return $query;
			}

			public function get_row( $query, $output = null ) {
				return [
					'cnt'   => 3,
					'total' => 999,
				];
			}
		};
		$GLOBALS['wpdb'] = $wpdb;

		$this->transients = []; // force a recompute through computeStats()

		$stats = Manager::stats();

		$this->assertSame( 999, $stats['bytes'], 'bytes must come from the wpdb SUM' );
		$this->assertSame( 3, $stats['count'], 'count must come from the wpdb COUNT' );
		$this->assertSame( Meta::BACKUP_BYTES, $wpdb->lastKey, 'the query must aggregate the backup-bytes meta key' );

		// And it must be cached for next time.
		$this->assertSame(
			[
				'bytes' => 999,
				'count' => 3,
			],
			$this->transients['ogwm_backup_stats']
		);
	}

	public function testStatsFallsBackToDirectoryWalkWhenWpdbCountIsZero(): void {
		// When the meta-sum reports zero rows (fresh install / backups predating the
		// bytes meta), computeStats() must fall through to the directory walk rather
		// than reporting an empty store while files exist on disk.
		$id       = 310;
		$original = $this->writeOriginal( $id );
		$this->stubOriginalPath( $id, $original );
		$this->stubMeta( $id, [ '_width' => self::IMG_W, '_height' => self::IMG_H ] );
		Manager::ensureBackup( $id );

		$wpdb = new class() {
			public string $postmeta = 'wp_postmeta';

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function get_row( $query, $output = null ) {
				return [
					'cnt'   => 0,
					'total' => 0,
				];
			}
		};
		$GLOBALS['wpdb'] = $wpdb;

		$this->transients = []; // force recompute

		$stats = Manager::stats();
		$this->assertSame( 1, $stats['count'], 'cnt=0 must fall back to the directory walk that finds the real backup' );
		$this->assertSame( (int) filesize( $original ), $stats['bytes'] );
	}

	public function testRestoreFailsAtomicallyWhenFinalRenameFails(): void {
		// The verified backup stages a temp beside the live original, then renames
		// it over the original. If THAT final swap fails, the live original must be
		// left byte-for-byte untouched and no staging temp may survive — the
		// restore-side equivalent of the writer's rename-failure guarantee.
		$id       = 88;
		$original = $this->writeOriginal( $id );
		$srcHash  = sha1_file( $original );
		$this->stubOriginalPath( $id, $original );
		$this->stubMeta( $id, [ '_width' => self::IMG_W, '_height' => self::IMG_H ] );

		$this->assertTrue( Manager::ensureBackup( $id )['ok'] );
		$this->assertTrue( Manager::verify( $id ) );

		// Corrupt the live original so a SUCCESSFUL restore would be observable,
		// then prove a FAILED final rename leaves this corrupted-but-current file
		// exactly as-is (restore must be all-or-nothing, never a partial write).
		file_put_contents( $original, 'CURRENT-LIVE-BYTES-BEFORE-FAILED-RESTORE' );
		$liveBefore = sha1_file( $original );
		$this->assertNotSame( $srcHash, $liveBefore );

		// Make ONLY the staged-temp → original swap fail. copyVerified (used to
		// stage the temp) does its own rename internally on the same-FS path, so we
		// must fail only the final restore swap: detect the .ogwm-restore-*.tmp name.
		Functions\when( 'OrchardGrove\OgWatermark\Backup\rename' )->alias(
			static function ( string $a, string $b ): bool {
				if ( str_contains( $a, '.ogwm-restore-' ) ) {
					return false; // The final restore swap fails.
				}
				return rename( $a, $b ); // copyVerified's own staging rename still works.
			}
		);

		$this->assertFalse( Manager::restoreOriginal( $id ) );
		$this->assertSame(
			$liveBefore,
			sha1_file( $original ),
			'a failed final swap must leave the live original byte-for-byte untouched'
		);
		$this->assertCount(
			0,
			glob( dirname( $original ) . '/*.ogwm-restore-*.tmp' ) ?: [],
			'no staging temp may survive a failed restore swap'
		);
	}

	public function testStatsDoesNotFatalOnAnUnreadableBackupSubdirectory(): void {
		// An unreadable descendant directory under the backup base must NOT fatal
		// the read-only "Backup storage" card: the iterator skips the bad subtree
		// (CATCH_GET_CHILD + try/catch) and returns whatever it could account for.
		$id       = 320;
		$original = $this->writeOriginal( $id );
		$this->stubOriginalPath( $id, $original );
		$this->stubMeta( $id, [ '_width' => self::IMG_W, '_height' => self::IMG_H ] );
		Manager::ensureBackup( $id );

		// Drop an unreadable subdirectory inside the backup base.
		$bad = Storage::baseDir() . '/2026/locked';
		mkdir( $bad, 0777, true );
		file_put_contents( $bad . '/hidden.bin', 'x' );
		chmod( $bad, 0000 );

		$this->transients = []; // force the directory-walk fallback (no $wpdb).

		try {
			$stats = Manager::stats(); // must not throw.
			$this->assertIsInt( $stats['bytes'] );
			$this->assertIsInt( $stats['count'] );
			// The real backup outside the locked subtree is still accounted for.
			$this->assertGreaterThanOrEqual( 1, $stats['count'] );
		} finally {
			chmod( $bad, 0700 ); // restore so tearDown can clean up.
		}
	}

	public function testDeleteBackupInvalidatesStatsAndClearsMeta(): void {
		$id       = 301;
		$original = $this->writeOriginal( $id );
		$this->stubOriginalPath( $id, $original );
		$this->stubMeta( $id, [ '_width' => self::IMG_W, '_height' => self::IMG_H ] );

		Manager::ensureBackup( $id );
		$this->transients['ogwm_backup_stats'] = [
			'bytes' => 1,
			'count' => 1,
		];

		$this->assertTrue( Manager::deleteBackup( $id ) );
		$this->assertArrayNotHasKey( 'ogwm_backup_stats', $this->transients );
	}

	// -----------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------

	/**
	 * Write a real PNG (IMG_W x IMG_H) at the uploads year/month location for $id.
	 * Generated with GD so it is a genuine decodable raster comfortably above
	 * Preflight's byte floor, with dimensions that exactly match the stubbed meta.
	 */
	private function writeOriginal( int $id ): string {
		$path = $this->monthDir . '/' . $id . '-original.png';

		$im = imagecreatetruecolor( self::IMG_W, self::IMG_H );
		// Random per-pixel noise so the encoded PNG is incompressible and lands
		// comfortably above Preflight's 256-byte floor (a smooth gradient would
		// compress down to ~150 bytes and read as an offload stub).
		for ( $x = 0; $x < self::IMG_W; $x++ ) {
			for ( $y = 0; $y < self::IMG_H; $y++ ) {
				$color = imagecolorallocate( $im, random_int( 0, 255 ), random_int( 0, 255 ), random_int( 0, 255 ) );
				imagesetpixel( $im, $x, $y, $color );
			}
		}
		imagepng( $im, $path );
		// No imagedestroy(): it is a deprecated no-op on PHP 8.0+ (the suite runs
		// with failOnWarning), and the handle is freed when it goes out of scope.

		return $path;
	}

	/** Stub wp_get_original_image_path() to return $path for $id. */
	private function stubOriginalPath( int $id, string $path ): void {
		Functions\when( 'wp_get_original_image_path' )->alias(
			static fn( $arg ) => (int) $arg === $id ? $path : false
		);
	}

	/**
	 * Stub wp_get_attachment_metadata() to return width/height for $id.
	 *
	 * @param int                  $id   Attachment id.
	 * @param array<string,int>    $dims ['_width'=>int,'_height'=>int]
	 */
	private function stubMeta( int $id, array $dims ): void {
		$meta = [
			'width'  => $dims['_width'] ?? 0,
			'height' => $dims['_height'] ?? 0,
		];
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			static fn( $arg ) => (int) $arg === $id ? $meta : false
		);
	}

	/** Reset Storage's private static base cache between tests. */
	private static function resetBaseCache(): void {
		$prop = ( new ReflectionClass( Storage::class ) )->getProperty( 'base' );
		$prop->setValue( null, null );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
