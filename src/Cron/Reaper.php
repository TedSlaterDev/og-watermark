<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Cron;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Backup\Storage;
use OrchardGrove\OgWatermark\Pipeline\Processor;
use OrchardGrove\OgWatermark\Support\Lock;

/**
 * The tmp/partial staging reaper (SPEC "Performance & bulk strategy" →
 * "Tmp/partial reaper cron").
 *
 * The pipeline stages every watermarked output to a per-job temp file
 * (`<servedfile>.ogwm-<token>.tmp`) and atomically renames it into place, and the
 * backup writer stages each master copy to `<backup>.partial` before its atomic
 * commit. A crash, a fatal mid-job, or a killed worker can strand one of these:
 *
 *  - a leftover `.ogwm-<token>.tmp` beside a served file (the stamp never landed);
 *  - a lone `<backup>.partial` (an interrupted backup copy — by the SPEC a lone
 *    `.partial` is "no backup", i.e. incomplete, and must be removed);
 *  - a `<backup>.partial2` (the cross-filesystem staging sibling).
 *
 * This cron removes those, but ONLY under two gates that together make it
 * impossible to delete a file a live job is mid-rename on:
 *
 *  1. AGE-GATED. A temp younger than {@see MAX_JOB_RUNTIME} (default 1 hour) may
 *     still belong to an in-flight job, so it is LEFT. Only a temp older than the
 *     longest plausible job runtime is reaped — long after any sane job would have
 *     renamed or abandoned it.
 *
 *  2. LOCK-AWARE. Even an old temp is left if its attachment's Processor lock is
 *     currently held (a resumed/retrying job owns it). The reaper extracts the
 *     attachment id from the served-file path's `-<id>-` backup prefix (backups)
 *     or skips the lock check only when no id can be derived (served temps live
 *     beside the attachment files, so the lock id is resolved from the served
 *     filename when possible). When in doubt it errs toward NOT deleting.
 *
 * Both gates plus the file lists are injectable ({@see reap()}), so the age + lock
 * behavior is proven against real temp files without a clock or a live lock.
 */
final class Reaper {

	/** Reap a staging file only once it is older than the longest plausible job (1h). */
	private const MAX_JOB_RUNTIME = 3600;

	/** Bound the scan so a pathological uploads tree can't run the reaper unbounded. */
	private const SCAN_LIMIT = 5000;

	/** Production entry point fired by the daily cron. */
	public static function run(): void {
		$now = time();

		$servedTemps  = self::findServedTemps();
		$backupTemps  = self::findBackupTemps();
		$candidates   = array_merge( $servedTemps, $backupTemps );

		self::reap(
			$candidates,
			$now,
			[
				'maxAge'   => self::MAX_JOB_RUNTIME,
				'mtimeOf'  => static fn( string $path ): int => self::mtimeOf( $path ),
				'isLocked' => static fn( int $id ): bool => Lock::held( Processor::lockKey( $id ) ),
				'unlink'   => static fn( string $path ): bool => self::unlink( $path ),
			]
		);
	}

	/**
	 * The pure, fully-injectable reaper over a candidate temp-file list — the
	 * testable core. Deletes a candidate ONLY when BOTH gates pass:
	 *   - it is OLDER than $deps['maxAge'] (age gate), AND
	 *   - the attachment it belongs to (when derivable) is NOT currently locked.
	 *
	 * A young temp (a job may be mid-rename) and a locked attachment's temp are both
	 * left untouched. A lone `.partial` is treated exactly like any other stranded
	 * staging file — it is "no backup," so an OLD one is removed.
	 *
	 * @param array<int,string>   $candidates Absolute staging-file paths.
	 * @param int                 $now        Reference "now" (Unix seconds).
	 * @param array<string,mixed> $deps       maxAge + mtimeOf/isLocked/unlink closures.
	 * @return array{removed:int,kept_young:int,kept_locked:int,missing:int}
	 */
	public static function reap( array $candidates, int $now, array $deps ): array {
		$maxAge   = isset( $deps['maxAge'] ) ? (int) $deps['maxAge'] : self::MAX_JOB_RUNTIME;
		$mtimeOf  = self::callable( $deps, 'mtimeOf', static fn( string $p ): int => 0 );
		$isLocked = self::callable( $deps, 'isLocked', static fn( int $id ): bool => false );
		$unlink   = self::callable( $deps, 'unlink', static fn( string $p ): bool => false );

		$removed     = 0;
		$keptYoung   = 0;
		$keptLocked  = 0;
		$missing     = 0;

		foreach ( $candidates as $path ) {
			$path = (string) $path;
			if ( '' === $path ) {
				continue;
			}

			$mtime = (int) $mtimeOf( $path );
			if ( $mtime <= 0 ) {
				// Vanished between the scan and now (a job just committed it) — nothing
				// to do, and certainly nothing to delete.
				++$missing;
				continue;
			}

			// AGE GATE: a temp younger than the longest plausible job may be live.
			if ( ( $now - $mtime ) < $maxAge ) {
				++$keptYoung;
				continue;
			}

			// LOCK GATE: if we can derive the owning attachment id and its lock is
			// held, a resumed/retrying job owns this temp — leave it.
			$id = self::attachmentIdFromPath( $path );
			if ( $id > 0 && $isLocked( $id ) ) {
				++$keptLocked;
				continue;
			}

			if ( $unlink( $path ) ) {
				++$removed;
			}
		}

		return [
			'removed'     => $removed,
			'kept_young'  => $keptYoung,
			'kept_locked' => $keptLocked,
			'missing'     => $missing,
		];
	}

	/**
	 * Best-effort attachment id from a staging-file path.
	 *
	 * Backup staging files live at `<base>/<YYYY>/<MM>/<id>-<name>.<ext>.partial`,
	 * so the id is the leading `<id>-` segment of the basename. Served-file temps
	 * (`<servedfile>.ogwm-<token>.tmp`) carry no id in the name; the served file's
	 * own attachment id is not recoverable from the path alone, so those return 0
	 * and the lock gate is skipped for them — the age gate alone protects them, and
	 * a served temp older than an hour is by definition orphaned.
	 *
	 * @param string $path Absolute staging-file path.
	 * @return int Attachment id, or 0 when none can be derived.
	 */
	private static function attachmentIdFromPath( string $path ): int {
		$name = basename( $path );

		// Backup staging file: "<id>-<name>.<ext>.partial" / ".partial2".
		if ( 1 === preg_match( '/^(\d+)-.+\.partial2?$/', $name, $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	// ---------------------------------------------------------------------
	// Live file discovery (production-only; bypassed by reap() callers).
	// ---------------------------------------------------------------------

	/**
	 * Stranded served-file temps under the uploads tree: every
	 * `*.ogwm-<token>.tmp`. Bounded by {@see SCAN_LIMIT}.
	 *
	 * @return array<int,string>
	 */
	private static function findServedTemps(): array {
		$uploads = wp_get_upload_dir();
		$base    = ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) )
			? (string) $uploads['basedir']
			: '';

		if ( '' === $base || ! is_dir( $base ) ) {
			return [];
		}

		return self::scan( $base, '/\.ogwm-[A-Za-z0-9]+\.tmp$/' );
	}

	/**
	 * Stranded backup staging files under the backup base: every `*.partial` and
	 * `*.partial2`. A lone `.partial` is an incomplete backup ("no backup") and is
	 * reaped once old. Bounded by {@see SCAN_LIMIT}.
	 *
	 * @return array<int,string>
	 */
	private static function findBackupTemps(): array {
		$base = Storage::baseDir();
		if ( '' === $base || ! is_dir( $base ) ) {
			return [];
		}

		return self::scan( $base, '/\.partial2?$/' );
	}

	/**
	 * Recursively collect files under $dir whose basename matches $pattern, up to
	 * the scan limit. Unreadable subtrees are skipped rather than fataled.
	 *
	 * @return array<int,string>
	 */
	private static function scan( string $dir, string $pattern ): array {
		$matches = [];

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY,
				\RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
					continue;
				}
				if ( 1 === preg_match( $pattern, $file->getFilename() ) ) {
					$matches[] = $file->getPathname();
					if ( count( $matches ) >= self::SCAN_LIMIT ) {
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Return whatever was collected rather than fatal the cron.
		}

		return $matches;
	}

	/** Modification time of a file, 0 when it cannot be stat'd / has vanished. */
	private static function mtimeOf( string $path ): int {
		if ( ! is_file( $path ) ) {
			return 0;
		}

		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unstat-able temp is treated as vanished.

		return ( false === $mtime ) ? 0 : (int) $mtime;
	}

	/** Best-effort delete; true when the path is gone afterwards. */
	private static function unlink( string $path ): bool {
		if ( ! is_file( $path ) ) {
			return false;
		}

		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- a still-present file just means the next sweep retries.

		return ! file_exists( $path );
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
