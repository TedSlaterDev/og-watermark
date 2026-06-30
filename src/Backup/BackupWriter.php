<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * Transactional file primitive for the pristine-backup store.
 *
 * This is the lowest, most data-safety-critical layer of the backup subsystem.
 * Its single job is to get a source file onto disk at a destination path either
 * COMPLETELY and byte-for-byte verified, or NOT AT ALL — never a half-written,
 * truncated, or silently-corrupt file. The pristine backup is the plugin's sole
 * clean master (see SPEC "Backup & source-of-truth model"), so a backup that
 * passed this primitive must be a perfect copy of what was handed in.
 *
 * It deliberately has NO WordPress dependency: it is pure filesystem, so it is
 * fully exercisable against real temp files in unit tests. Callers (Manager)
 * own the WordPress-facing concerns — meta, status guards, path containment.
 *
 * The write protocol (copyVerified):
 *   1. Ensure the destination's parent directory exists.
 *   2. Copy source → "<dest>.partial" (the partial name makes an interrupted
 *      write self-identifying; the reaper cron treats lone .partial as "no
 *      backup").
 *   3. fopen + fflush + fsync the partial then fclose, so the bytes are durably
 *      on the platter before we promise anything.
 *   4. Decide the commit strategy by whether source and destination live on the
 *      SAME filesystem: same FS → atomic rename(); cross-FS → a verified
 *      copy-with-rollback (rename() across filesystems is non-atomic and can
 *      leave a torn destination, so we never rely on it there).
 *   5. fsync the containing directory so the rename/creation is durable.
 *   6. MANDATORY full-content sha1(dest) === sha1(src) check. Byte size alone is
 *      not enough — a same-size corruption must be caught.
 *   7. On ANY failure at any step, delete the partial AND the dest and return
 *      false, leaving nothing behind.
 *
 * Low-level fs calls are @-silenced so a failed primitive becomes a clean
 * `false` (and a guaranteed cleanup) rather than a warning/fatal that could
 * abort mid-protocol and strand a partial file.
 */
final class BackupWriter {

	/** Read/copy chunk size for the streaming cross-filesystem fallback copy. */
	private const CHUNK_BYTES = 1048576; // 1 MiB.

	/**
	 * Copy $src to $dest transactionally, with mandatory full-content
	 * verification. Returns true only when $dest is present and byte-for-byte
	 * identical to $src; on any failure $dest and the work file are removed and
	 * the method returns false.
	 *
	 * @param string $src  Absolute path to an existing, readable source file.
	 * @param string $dest Absolute destination path (created/replaced).
	 * @return bool True only on a fully-verified write.
	 */
	public static function copyVerified( string $src, string $dest ): bool {
		if ( '' === $src || '' === $dest ) {
			return false;
		}

		// The source must be a readable regular file we can hash up front; if we
		// can't establish a source baseline there is nothing safe to copy.
		if ( ! is_file( $src ) || ! is_readable( $src ) ) {
			return false;
		}

		$src_hash = self::sha1Of( $src );
		if ( null === $src_hash ) {
			return false;
		}

		// 1) Ensure the destination's parent directory exists.
		$dir = \dirname( $dest );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- a failed mkdir must become a clean false, and the trailing is_dir() handles a concurrent creator.
			return false;
		}

		$partial = $dest . '.partial';

		// Never inherit a stale work file or a prior dest from an aborted run.
		self::unlinkQuietly( $partial );

		// 2) Copy source → "<dest>.partial".
		if ( ! @copy( $src, $partial ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- copy() failure is handled as a clean false with cleanup below.
			self::cleanup( $partial, $dest );
			return false;
		}

		// 3) Best-effort durable flush of the partial. fsync is a crash-safety
		//    optimization, not a correctness gate — it is unsupported on some
		//    filesystems (network mounts, certain containers) where it simply
		//    fails. Byte correctness is enforced unconditionally by the
		//    mandatory full sha1 check in step 6, so a failed flush degrades
		//    durability without ever admitting a corrupt backup.
		self::fsyncFile( $partial );

		// 4) Commit. Same filesystem → atomic rename; cross-filesystem → a
		//    verified copy-with-rollback (rename is non-atomic across FS).
		if ( self::sameFilesystem( $partial, $dir ) ) {
			// Replace any prior dest atomically with the verified partial.
			if ( ! @rename( $partial, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- rename() failure is handled as a clean false with cleanup below.
				self::cleanup( $partial, $dest );
				return false;
			}
		} else {
			// Cross-filesystem: rename() is non-atomic across filesystems and can
			// leave a torn destination, so we stream a SECOND sibling temp on the
			// dest FS, fsync + fully sha1-verify it, and ONLY THEN atomically rename
			// it over $dest. We NEVER unlink the live $dest before the replacement is
			// proven good — so a transient write/verify failure can never destroy a
			// pre-existing valid backup (the same safety the same-FS rename gives).
			$stage = $dest . '.partial2';
			self::unlinkQuietly( $stage );

			if ( ! self::streamCopy( $partial, $stage ) ) {
				self::unlinkQuietly( $stage );
				self::unlinkQuietly( $partial );
				return false; // Prior $dest untouched.
			}
			self::fsyncFile( $stage );

			// Verify the staged copy BEFORE it is allowed to replace $dest.
			$stage_hash = self::sha1Of( $stage );
			if ( null === $stage_hash || ! hash_equals( $src_hash, $stage_hash ) ) {
				self::unlinkQuietly( $stage );
				self::unlinkQuietly( $partial );
				return false; // Prior $dest untouched.
			}

			// Same-FS atomic swap of the verified stage over $dest.
			if ( ! @rename( $stage, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- a failed swap leaves the prior dest intact; clean up and abort.
				self::unlinkQuietly( $stage );
				self::unlinkQuietly( $partial );
				return false; // Prior $dest untouched.
			}

			self::unlinkQuietly( $partial );
			self::fsyncFile( $dest );
		}

		// 5) fsync the containing directory so the new entry is durable.
		self::fsyncDir( $dir );

		// 6) MANDATORY full-content verification. A passing byte size is NOT
		//    sufficient — we require an exact sha1 match against the source. (On the
		//    cross-FS path the staged copy was already verified before the swap; this
		//    is the unconditional final gate that also covers the same-FS path.)
		$dest_hash = self::sha1Of( $dest );
		if ( null === $dest_hash || ! hash_equals( $src_hash, $dest_hash ) ) {
			self::cleanup( $partial, $dest );
			return false;
		}

		return true;
	}

	/**
	 * Full-content sha1 of a file, or null when it is unreadable.
	 *
	 * @param string $path Absolute path.
	 * @return string|null 40-char hex digest, or null on any read failure.
	 */
	public static function sha1Of( string $path ): ?string {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$hash = @sha1_file( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unreadable file must become null, not a warning.

		return ( false === $hash ) ? null : $hash;
	}

	/**
	 * Whether two paths live on the same filesystem.
	 *
	 * Compares the device id (st_dev) of each path's nearest existing directory:
	 * for a not-yet-existing target we cannot stat the file itself, so we stat
	 * the directory it will live in. Identical device ids mean a rename() between
	 * them is atomic. When either side cannot be stat()'d we conservatively
	 * return false so the caller takes the safe verified-copy path rather than
	 * relying on a possibly-cross-FS rename.
	 *
	 * @param string $a First path (file or directory).
	 * @param string $b Second path (file or directory).
	 * @return bool True only when both resolve to the same device id.
	 */
	public static function sameFilesystem( string $a, string $b ): bool {
		$dev_a = self::deviceId( $a );
		$dev_b = self::deviceId( $b );

		if ( null === $dev_a || null === $dev_b ) {
			return false;
		}

		return $dev_a === $dev_b;
	}

	/**
	 * Device id (st_dev) of $path's nearest existing location.
	 *
	 * If $path itself exists we stat it directly; otherwise we walk up to the
	 * nearest existing ancestor directory and stat that, so a destination that
	 * has not been written yet still resolves to the filesystem it will land on.
	 *
	 * @return int|null st_dev, or null when nothing along the path exists/stats.
	 */
	private static function deviceId( string $path ): ?int {
		if ( '' === $path ) {
			return null;
		}

		// Walk up to the nearest existing path component.
		$current = $path;
		while ( '' !== $current && ! file_exists( $current ) ) {
			$parent = \dirname( $current );
			if ( $parent === $current ) {
				return null; // Reached the root without finding anything real.
			}
			$current = $parent;
		}

		if ( '' === $current || ! file_exists( $current ) ) {
			return null;
		}

		$stat = @stat( $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a stat failure must become null, not a warning.
		if ( false === $stat || ! isset( $stat['dev'] ) ) {
			return null;
		}

		return (int) $stat['dev'];
	}

	/**
	 * Best-effort durable flush of a file's contents to disk.
	 *
	 * fsync() exists on PHP 8.1+. We open in 'r' mode (the bytes are already on
	 * disk via copy()/stream-copy) which is enough for fsync() to flush the
	 * kernel page cache for the file. This is a CRASH-SAFETY optimization, not a
	 * correctness gate: fsync legitimately fails on some filesystems (network
	 * mounts, certain containers, and under test instrumentation), and byte
	 * correctness is enforced unconditionally by copyVerified()'s mandatory full
	 * sha1 check. A failed flush therefore degrades durability without ever
	 * admitting a corrupt backup, so we swallow failures rather than abort.
	 *
	 * @return bool True when the file was opened and synced (informational only).
	 */
	private static function fsyncFile( string $path ): bool {
		$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- best-effort; an unopenable file just means no flush.
		if ( false === $handle ) {
			return false;
		}

		// fsync() exists on PHP 8.1+, but some managed hosts DISABLE it via
		// disable_functions — calling a disabled function fatals (the @ does not
		// suppress that). Skip the flush when it is unavailable; copyVerified()'s
		// mandatory full sha1 still enforces correctness, so durability degrades
		// without ever admitting a corrupt backup.
		if ( ! function_exists( 'fsync' ) ) {
			@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return false;
		}

		$ok = @fsync( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- fsync failure (e.g. unsupported FS) is non-fatal; the sha1 gate enforces correctness.

		@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		return false !== $ok;
	}

	/**
	 * Best-effort fsync of a directory so a rename()/create within it is durable.
	 *
	 * Directory fsync is advisory and not supported everywhere (notably it can
	 * fail on Windows or some network filesystems), so its result is NOT treated
	 * as fatal — the mandatory sha1 check in copyVerified() is the real safety
	 * gate. We open read-only and fsync; failures are swallowed.
	 */
	private static function fsyncDir( string $dir ): void {
		$handle = @fopen( $dir, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- directory fsync is advisory; failures are intentionally ignored.
		if ( false === $handle ) {
			return;
		}

		if ( function_exists( 'fsync' ) ) { // Guard: some managed hosts disable fsync() (calling it would fatal).
			@fsync( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- advisory; not all filesystems support directory fsync.
		}
		@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}

	/**
	 * Streaming copy used only on the cross-filesystem commit path. Reads $src
	 * and writes $dest in chunks, so an oversized original never has to be held
	 * fully in memory. Returns false (with the caller cleaning up) on any I/O
	 * error or short write.
	 */
	private static function streamCopy( string $src, string $dest ): bool {
		$in = @fopen( $src, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( false === $in ) {
			return false;
		}

		$out = @fopen( $dest, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( false === $out ) {
			@fclose( $in ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return false;
		}

		$ok = true;
		while ( ! feof( $in ) ) {
			$chunk = @fread( $in, self::CHUNK_BYTES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $chunk ) {
				$ok = false;
				break;
			}
			if ( '' === $chunk ) {
				continue;
			}
			$written = @fwrite( $out, $chunk ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $written || \strlen( $chunk ) !== $written ) {
				$ok = false;
				break;
			}
		}

		// Flush our own writes before closing so a subsequent fsync sees them.
		if ( $ok ) {
			$ok = false !== @fflush( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		@fclose( $in ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		@fclose( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		return $ok;
	}

	/**
	 * Remove both the work file and the destination, leaving nothing behind.
	 * Called on every failure path so a torn write never persists.
	 */
	private static function cleanup( string $partial, string $dest ): void {
		self::unlinkQuietly( $partial );
		self::unlinkQuietly( $dest );
	}

	/** Delete a file if it exists, swallowing any failure. */
	private static function unlinkQuietly( string $path ): void {
		if ( '' !== $path && is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- best-effort cleanup; a leftover is the reaper's job, not a fatal here.
		}
	}
}
