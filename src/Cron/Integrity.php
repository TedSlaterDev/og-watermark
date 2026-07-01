<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Cron;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Backup\BackupWriter;
use OrchardGrove\OgWatermark\Backup\Manager;
use OrchardGrove\OgWatermark\Pipeline\Processor;
use OrchardGrove\OgWatermark\Support\Lock;
use OrchardGrove\OgWatermark\Support\Meta;

/**
 * The daily backup-integrity sweep (SPEC "Performance & bulk strategy" →
 * "Integrity cron (daily)" + the terminal `backup_missing` recovery story).
 *
 * Backups are the whole product: the pristine, hash-verified master is the SOLE
 * clean source every (re)stamp derives from, and Decision #2 means the on-disk
 * "original" is itself watermarked, so a lost/corrupt backup is unrecoverable
 * out-of-band. This cron is the watchdog that NOTICES that loss early and surfaces
 * it as the terminal {@see Meta::STATUS_BACKUP_MISSING} state the admin UI shows —
 * it NEVER auto-deletes a backup, never auto-recreates one from a (dirty) served
 * file, and never restores. Detection only.
 *
 * Three cost-control properties make it safe on a large library:
 *
 *  1. CHEAP CHECKS ALWAYS, SHA1 ON A ROTATING SUBSET. For every backed-up
 *     attachment we always run the cheap existence + byte-size stat checks. Full
 *     sha1 verification (re-reading the whole file) is expensive, so above
 *     {@see SHA1_THRESHOLD} backed-up attachments we sha1 only a rotating SLICE
 *     each day — chosen by a day index so the WHOLE set is covered over a window
 *     of {@see sha1WindowDays()} days, never the entire library in a single run.
 *     Below the threshold every candidate is sha1'd (a small set is cheap).
 *
 *  2. LOCK-AWARE. An attachment whose Processor lock is currently held is mid-
 *     apply: its meta/backup are in flux, so we SKIP it entirely rather than read
 *     a half-written state and falsely flag it. The locked id is left for the next
 *     sweep.
 *
 *  3. CHUNKED. Each run processes at most {@see BATCH_LIMIT} attachments; if more
 *     remain it reschedules a near-future continuation so a huge library can't
 *     time out a single cron tick.
 *
 * The subset selection, thresholds, the day index, and the candidate id source
 * are all injectable ({@see runOn()}), so the rotating-window coverage and the
 * "don't sha1 everything above the threshold" guarantee are deterministically
 * testable against real temp files without touching the database.
 */
final class Integrity {

	/** Above this many backed-up attachments, sha1 only a rotating subset per day. */
	private const SHA1_THRESHOLD = 200;

	/** Rotating-subset window: the whole set is sha1-covered over this many days. */
	private const SHA1_WINDOW_DAYS = 7;

	/** Max attachments examined per run; the remainder is deferred to a continuation. */
	private const BATCH_LIMIT = 250;

	/**
	 * Continuation hook fired to finish a sweep that exceeded BATCH_LIMIT. Public so
	 * the {@see Cron} facade (and uninstall) can clear any pending continuation on
	 * deactivation/uninstall — a stray one-shot must never survive teardown.
	 */
	public const CONTINUE_HOOK = 'ogwm_integrity_continue';

	/** Option holding the running count of backed-up attachments (rotating-subset anchor). */
	private const COUNT_OPTION = 'ogwm_integrity_count';

	/** Option holding the cursor (last attachment id examined) for chunked runs. */
	private const CURSOR_OPTION = 'ogwm_integrity_cursor';

	/**
	 * Request-scoped keyset cursor for {@see keysetWhere()}. Null when no cursor query
	 * is in flight, so the posts_where filter only ever rewrites OUR backedUpIds query.
	 */
	private static ?int $keysetAfter = null;

	/**
	 * The production entry point fired by the daily cron. Resolves the live
	 * dependencies (the real candidate id list, today's day index, the real
	 * Manager/Lock checks) and delegates to the fully-injectable {@see runOn()}.
	 */
	public static function run(): void {
		self::register();

		$cursor     = (int) get_option( self::CURSOR_OPTION, 0 );
		$candidates = self::backedUpIds( $cursor, self::BATCH_LIMIT + 1 );

		// Anchor the rotating-subset decision to the TRUE library size, not this
		// batch's (capped) candidate count. At the start of a sweep we re-count the
		// whole backed-up set and cache it; continuation ticks reuse the cached count
		// so the sha1-subset gate keys off the library, never the per-batch slice.
		if ( $cursor > 0 ) {
			$libraryTotal = (int) get_option( self::COUNT_OPTION, 0 );
		} else {
			$libraryTotal = self::countBackedUp();
			update_option( self::COUNT_OPTION, $libraryTotal, false );
		}

		$report = self::runOn(
			$candidates,
			self::todayIndex(),
			[
				'threshold'    => self::SHA1_THRESHOLD,
				'window'       => self::SHA1_WINDOW_DAYS,
				'batchLimit'   => self::BATCH_LIMIT,
				'libraryTotal' => $libraryTotal,
				// Real dependency closures — overridable wholesale by runOn() callers
				// (tests) so the sweep logic is exercised without the DB or a live lock.
				'isLocked'   => static fn( int $id ): bool => Lock::held( Processor::lockKey( $id ) ),
				'backupPath' => static fn( int $id ): ?string => Manager::backupPath( $id ),
				'storedHash' => static fn( int $id ): string => (string) get_post_meta( $id, Meta::BACKUP_HASH, true ),
				'storedSize' => static fn( int $id ): int => (int) get_post_meta( $id, Meta::BACKUP_BYTES, true ),
				'isStamped'  => static fn( int $id ): bool => self::servedFilesWatermarked( $id ),
				'flag'       => static function ( int $id, string $note ): void {
					self::flagMissing( $id, $note );
				},
			]
		);

		// Advance/clear the cursor + reschedule a continuation when the batch was full.
		if ( $report['truncated'] ) {
			update_option( self::CURSOR_OPTION, $report['lastId'], false );
			if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
				wp_schedule_single_event( time() + 30, self::CONTINUE_HOOK );
			}
		} else {
			delete_option( self::CURSOR_OPTION );
			delete_option( self::COUNT_OPTION );
		}
	}

	/** Wire the chunked-continuation handler (idempotent; safe to call repeatedly). */
	public static function register(): void {
		add_action( self::CONTINUE_HOOK, [ self::class, 'run' ] );
	}

	/**
	 * The pure, fully-injectable sweep over a candidate id list — the testable core.
	 *
	 * For each candidate (up to the batch limit) it:
	 *   - SKIPS a currently-locked attachment (mid-apply; checked first);
	 *   - SKIPS an attachment with no recorded backup hash (nothing to verify);
	 *   - runs the CHEAP existence + byte-size checks always;
	 *   - runs full sha1 ONLY when the rotating-subset gate selects this id today
	 *     (below the threshold every id is selected; above it only the day's slice);
	 *   - on a missing/corrupt backup for an attachment whose served files are
	 *     watermarked, calls the injected `flag` to set STATUS_BACKUP_MISSING +
	 *     a LAST_ERROR note. A VALID backup is left completely untouched.
	 *
	 * @param array<int,int>      $candidates Backed-up attachment ids to consider (id-ascending).
	 * @param int                 $dayIndex   Today's rotation index (epoch-day or injected).
	 * @param array<string,mixed> $deps       Thresholds + dependency closures (see run()).
	 * @return array{checked:int,sha1:int,flagged:int,skipped:int,truncated:bool,lastId:int}
	 */
	public static function runOn( array $candidates, int $dayIndex, array $deps ): array {
		$threshold  = isset( $deps['threshold'] ) ? (int) $deps['threshold'] : self::SHA1_THRESHOLD;
		$window     = max( 1, isset( $deps['window'] ) ? (int) $deps['window'] : self::SHA1_WINDOW_DAYS );
		$batchLimit = max( 1, isset( $deps['batchLimit'] ) ? (int) $deps['batchLimit'] : self::BATCH_LIMIT );

		$isLocked   = self::callable( $deps, 'isLocked', static fn( int $id ): bool => false );
		$backupPath = self::callable( $deps, 'backupPath', static fn( int $id ): ?string => null );
		$storedHash = self::callable( $deps, 'storedHash', static fn( int $id ): string => '' );
		$storedSize = self::callable( $deps, 'storedSize', static fn( int $id ): int => 0 );
		$isStamped  = self::callable( $deps, 'isStamped', static fn( int $id ): bool => true );
		$flag       = self::callable( $deps, 'flag', static function ( int $id, string $note ): void {} );

		$ids       = array_values( array_filter( array_map( 'intval', $candidates ), static fn( int $id ): bool => $id > 0 ) );
		$total     = count( $ids );
		$truncated = $total > $batchLimit;
		$ids       = array_slice( $ids, 0, $batchLimit );

		// SHA1 GATING. Below the threshold every candidate is verified (a small set
		// is cheap). At/above it, only the rotating slice whose modulo-window bucket
		// matches today's day index is verified — so the whole set is covered over
		// `window` days and a single run NEVER sha1s everything.
		//
		// The decision is anchored to the TRUE library size (`libraryTotal`, the count
		// of backed-up attachments) when supplied, NOT this batch's candidate count: a
		// continuation's tail batch (or a 200–250-item library) could otherwise fall
		// below the threshold and sha1 every candidate, defeating the per-library
		// guarantee. Falls back to the per-batch count only when no library size is
		// injected (legacy callers / isolated core tests that drive a single batch).
		$libraryTotal = isset( $deps['libraryTotal'] ) ? (int) $deps['libraryTotal'] : $total;
		$useSubset    = $libraryTotal >= $threshold;

		$checked = 0;
		$sha1ed  = 0;
		$flagged = 0;
		$skipped = 0;
		$lastId  = 0;

		foreach ( $ids as $id ) {
			$lastId = $id;

			// 1) Lock-aware: never read meta/backup mid-apply.
			if ( $isLocked( $id ) ) {
				++$skipped;
				continue;
			}

			// 2) Nothing recorded → nothing this sweep can verify.
			$hash = (string) $storedHash( $id );
			if ( '' === $hash ) {
				++$skipped;
				continue;
			}

			++$checked;

			$path    = $backupPath( $id );
			$problem = '';

			// 3) CHEAP existence check (always).
			if ( null === $path || '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
				$problem = 'backup-file-missing';
			} else {
				// 4) CHEAP byte-size check (always) when a size was recorded.
				$expectedBytes = (int) $storedSize( $id );
				$actualBytes   = (int) self::sizeOf( $path );
				if ( $expectedBytes > 0 && $actualBytes !== $expectedBytes ) {
					$problem = 'backup-size-mismatch';
				} elseif ( self::sha1Selected( $id, $dayIndex, $window, $useSubset ) ) {
					// 5) FULL sha1 verification — only for the day's rotating slice.
					++$sha1ed;
					$actual = BackupWriter::sha1Of( $path );
					if ( null === $actual || ! hash_equals( $hash, $actual ) ) {
						$problem = 'backup-hash-mismatch';
					}
				}
			}

			// 6) A problem only becomes the terminal backup_missing state when the
			//    served files are actually watermarked (otherwise there is nothing
			//    unrecoverable to surface — a never-stamped attachment can just be
			//    re-stamped). A VALID backup falls through here untouched.
			if ( '' !== $problem && $isStamped( $id ) ) {
				$flag( $id, $problem );
				++$flagged;
			}
		}

		return [
			'checked'   => $checked,
			'sha1'      => $sha1ed,
			'flagged'   => $flagged,
			'skipped'   => $skipped,
			'truncated' => $truncated,
			'lastId'    => $lastId,
		];
	}

	/**
	 * Whether THIS candidate should be sha1-verified today.
	 *
	 * Below the threshold ($useSubset false) every candidate is verified. At/above
	 * it, the candidate's stable bucket (id modulo the window) must equal today's
	 * day-of-window bucket — so each day verifies a disjoint ~1/window slice and the
	 * union over `window` consecutive days covers the whole set exactly once.
	 *
	 * @param int  $id        Attachment id (the stable rotation key).
	 * @param int  $dayIndex  Today's day index.
	 * @param int  $window    Coverage window in days.
	 * @param bool $useSubset Whether the rotating subset is in effect.
	 */
	private static function sha1Selected( int $id, int $dayIndex, int $window, bool $useSubset ): bool {
		if ( ! $useSubset ) {
			return true;
		}

		$window = max( 1, $window );

		return ( $id % $window ) === ( ( $dayIndex % $window ) + $window ) % $window;
	}

	/**
	 * Set the terminal backup_missing state + a human-readable last-error note for
	 * an attachment whose master is gone/corrupt. NEVER deletes or recreates — this
	 * is the surface the admin UI's recovery affordance reads.
	 *
	 * @param int    $id   Attachment id.
	 * @param string $note Machine reason (e.g. backup-hash-mismatch).
	 */
	private static function flagMissing( int $id, string $note ): void {
		update_post_meta( $id, Meta::STATUS, Meta::STATUS_BACKUP_MISSING );
		update_post_meta(
			$id,
			Meta::LAST_ERROR,
			sprintf(
				/* translators: %s: machine-readable integrity-failure reason code. */
				__( 'Pristine backup integrity check failed (%s). The watermarked files are permanent until a clean original is re-uploaded.', 'og-watermark' ),
				$note
			)
		);
	}

	/**
	 * Whether the attachment's served files are watermarked (so a lost backup is the
	 * terminal, unrecoverable state). Watermarked or out-of-date both count: in
	 * either case the on-disk files carry a stamp and the master is the only clean
	 * source. A never-stamped attachment (none/queued/restored) is NOT terminal.
	 *
	 * @param int $id Attachment id.
	 */
	private static function servedFilesWatermarked( int $id ): bool {
		$status = (string) get_post_meta( $id, Meta::STATUS, true );

		// STATUS_TOO_SMALL is deliberately EXCLUDED: a too-small attachment never wrote
		// a stamp (the signature is withheld, the clean file stays in place), so its
		// served files are clean and a lost backup is recoverable — not terminal.
		// TOO_LARGE/FAILED are included as the conservative "assume the served set may
		// be dirty" choice. Do not add TOO_SMALL here.
		return in_array(
			$status,
			[ Meta::STATUS_WATERMARKED, Meta::STATUS_TOO_LARGE, Meta::STATUS_FAILED ],
			true
		);
	}

	// ---------------------------------------------------------------------
	// Live dependency resolution (production-only; bypassed by runOn() callers).
	// ---------------------------------------------------------------------

	/**
	 * Ids of attachments carrying a recorded backup hash, after $afterId, ascending,
	 * limited to $limit. The "has a backup" set is the only set the integrity sweep
	 * cares about — a never-backed-up attachment has nothing to verify.
	 *
	 * @return array<int,int>
	 */
	private static function backedUpIds( int $afterId, int $limit ): array {
		// The cursor is pushed INTO the SQL as a keyset bound (`ID > $afterId`) via a
		// scoped posts_where filter, so a continuation re-fetches the NEXT `$limit`
		// rows above the cursor — not the lowest `$limit` globally then post-filtered
		// (which would advance the sweep one attachment per tick on a large library).
		self::$keysetAfter = max( 0, $afterId );
		add_filter( 'posts_where', [ self::class, 'keysetWhere' ], 10, 1 );

		try {
			$ids = get_posts(
				[
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'fields'         => 'ids',
					'posts_per_page' => max( 1, $limit ),
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => Meta::BACKUP_HASH, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_compare'   => 'EXISTS',
					'no_found_rows'  => true,
					'cache_results'  => false,
				]
			);
		} finally {
			remove_filter( 'posts_where', [ self::class, 'keysetWhere' ], 10 );
			self::$keysetAfter = null;
		}

		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : [];

		// Defensive keyset enforcement: even if the host/test drops the WHERE filter,
		// only return ids strictly greater than the cursor so a continuation can never
		// re-examine the previous batch.
		if ( $afterId > 0 ) {
			$ids = array_values( array_filter( $ids, static fn( int $id ): bool => $id > $afterId ) );
		}

		return $ids;
	}

	/**
	 * `posts_where` filter appending the keyset bound for the in-flight cursor query.
	 * Active ONLY while {@see $keysetAfter} is set (between the add/remove pair in
	 * {@see backedUpIds()}), so it never rewrites any other query. The cursor is cast
	 * through (int), so it is injection-safe. Mirrors the M6 BulkRunner pager pattern.
	 *
	 * @param string $where The accumulated WHERE clause.
	 */
	public static function keysetWhere( string $where ): string {
		if ( null === self::$keysetAfter || self::$keysetAfter <= 0 ) {
			return $where;
		}

		global $wpdb;
		$after = (int) self::$keysetAfter;
		$table = ( isset( $wpdb ) && isset( $wpdb->posts ) ) ? (string) $wpdb->posts : 'wp_posts';

		return $where . ' AND ' . $table . '.ID > ' . $after . ' ';
	}

	/**
	 * Count of backed-up attachments across the WHOLE library (the rotating-subset
	 * anchor). Counted once at the start of a sweep and cached in an option so
	 * continuation ticks reuse it rather than re-counting each tick.
	 */
	private static function countBackedUp(): int {
		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => Meta::BACKUP_HASH, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare'   => 'EXISTS',
				'no_found_rows'  => true,
				'cache_results'  => false,
			]
		);

		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/** Today's rotation index: whole days since the Unix epoch (UTC). */
	private static function todayIndex(): int {
		return (int) floor( time() / self::dayLength() );
	}

	/** One day in seconds (literal so the class loads under PHPUnit). */
	private static function dayLength(): int {
		return 86400;
	}

	/** Byte size of a file, 0 when unreadable. */
	private static function sizeOf( string $path ): int {
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unreadable size is a 0, not a warning.

		return ( false === $size ) ? 0 : (int) $size;
	}

	/**
	 * Pull a callable dependency from the deps array, or a default.
	 *
	 * @param array<string,mixed> $deps
	 */
	private static function callable( array $deps, string $key, callable $default ): callable {
		$value = $deps[ $key ] ?? null;

		return is_callable( $value ) ? $value : $default;
	}
}
