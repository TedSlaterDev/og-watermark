<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Backup;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Support\Meta;

/**
 * Pristine-backup orchestration — the single source of truth.
 *
 * The Manager is the ONLY code that creates, verifies, restores, and deletes
 * the immutable master copies that every (re)stamp derives from. It wires the
 * filesystem primitive ({@see BackupWriter}), the offload/stub validation
 * ({@see Preflight}), the protected storage tree ({@see Storage}), and the
 * attachment meta ({@see Meta}) into the transactional create/verify/restore
 * lifecycle described in the SPEC's "Backup & source-of-truth model".
 *
 * The cardinal safety rule (Decision #2 / #3): a backup hash may ONLY ever be
 * derived from a file the plugin has never composited. Because the on-disk
 * original is itself watermarked after first apply, `wp_get_original_image_path()`
 * is read as a clean source EXACTLY ONCE — on first apply, while the attachment
 * is still pristine. {@see ensureBackup()} enforces this with a hard status
 * guard; once an attachment has been stamped without a valid backup, it returns
 * `backup_missing` and NEVER fabricates a master from the (now dirty) original.
 *
 * After a USER crop/rotate the clean source is NOT the resolver's original (for a
 * big image that is still the watermarked full-res `original_image`) but the new
 * `-e<timestamp>` edited file. {@see seedFrom()} seeds the master from THAT
 * explicit path; both it and {@see ensureBackup()} funnel through the single
 * transactional {@see createFromPath()} helper, so there is exactly ONE
 * backup-writing implementation and the hash is always derived solely from the
 * source the caller named — never from a re-resolved (possibly watermarked) file.
 */
final class Manager {

	/** Transient caching the rolled-up backup storage footprint. */
	private const STATS_TRANSIENT = 'ogwm_backup_stats';

	/** Stats cache lifetime in seconds (12 hours), recomputed on create/delete. */
	private const STATS_TTL = 12 * 3600;

	/**
	 * Ensure a valid, hash-verified pristine backup exists for an attachment.
	 *
	 * Idempotent: a no-op success when a valid backup is already present. The
	 * HARD GUARD below is the load-bearing data-safety property — a backup is
	 * created ONLY from a never-composited original.
	 *
	 * ADVISORY-STATUS WARNING: on the success paths this returns
	 * `status => Meta::STATUS_WATERMARKED` only as a forward-looking hint; this
	 * method has NOT composited anything. M4 (the Processor) OWNS the lifecycle
	 * status and MUST derive the real `_ogwm_status` from whether pixels actually
	 * stamped — it must never blindly persist this returned status, or it would
	 * mark an attachment "watermarked" before any file was touched.
	 *
	 * @param int $id Attachment ID.
	 * @return array{ok:bool,status:string,reason:string}
	 */
	public static function ensureBackup( int $id ): array {
		if ( $id <= 0 ) {
			return self::result( false, Meta::STATUS_FAILED, 'invalid-attachment-id' );
		}

		// 1) Idempotent fast-path: an existing, hash-matching backup is enough.
		if ( self::verify( $id ) ) {
			return self::result( true, Meta::STATUS_WATERMARKED, 'backup-already-valid' );
		}

		// 2) HARD GUARD (source-of-truth). Only create a backup when the file has
		//    NEVER been stamped: status in {none, queued, ''} AND no stored hash.
		//    If the attachment is already watermarked/restored/etc. but the backup
		//    is gone, the on-disk "original" may be watermarked — refuse to seed a
		//    master from it. This enforces "read wp_get_original_image_path only on
		//    first apply while pristine".
		$status      = (string) get_post_meta( $id, Meta::STATUS, true );
		$stored_hash = (string) get_post_meta( $id, Meta::BACKUP_HASH, true );

		$pristine_status = in_array( $status, [ Meta::STATUS_NONE, Meta::STATUS_QUEUED, '' ], true );

		if ( ! $pristine_status || '' !== $stored_hash ) {
			// The file was stamped before (or a hash was recorded) but no valid
			// backup exists now. Never re-derive a master from a served file.
			return self::result( false, Meta::STATUS_BACKUP_MISSING, 'no-valid-backup-and-already-stamped' );
		}

		// 3) Resolve the TRUE original while still pristine (first apply only), then
		//    seed from it through the ONE transactional create-from-path helper.
		$source = wp_get_original_image_path( $id, true );
		if ( ! is_string( $source ) || '' === $source ) {
			return self::result( false, Meta::STATUS_FAILED, 'cannot-resolve-original-path' );
		}

		return self::createFromPath( $id, $source );
	}

	/**
	 * Seed a pristine backup from an EXPLICIT clean source path, bypassing
	 * wp_get_original_image_path() entirely.
	 *
	 * This is the M5 EditorBridge's master-replacing seam after a USER crop/rotate.
	 * For a big image, wp_get_original_image_path() still resolves to the OLD,
	 * already-WATERMARKED full-res `original_image` (WP's wp_save_image() never
	 * repoints it), so ensureBackup() — which seeds from that resolver — would copy
	 * a watermarked file into the master. The bridge instead hands us the CLEAN
	 * `-e<timestamp>` edited file (get_attached_file()) and we seed STRICTLY from it.
	 *
	 * HARD INVARIANT: this method only ever derives a backup hash from
	 * $cleanSourcePath — the exact file path it was explicitly given. It NEVER
	 * consults wp_get_original_image_path(). The caller (EditorBridge) owns the
	 * data-loss gate that proves $cleanSourcePath is clean (the per-request
	 * clean-substitution marker); the safety of seeding is the caller's to assert,
	 * and the integrity of seeding from the given path is ours.
	 *
	 * It reuses the SAME transactional create internals as ensureBackup
	 * (Preflight against the CURRENT _wp_attachment_metadata dims + disk pre-flight,
	 * BackupWriter::copyVerified with the mandatory full sha1, persist
	 * BACKUP_REL/HASH/BYTES, invalidate stats) via the shared
	 * {@see createFromPath()} helper. It deliberately does NOT apply ensureBackup()'s
	 * status/stored-hash HARD GUARD: the caller has already reset state + deleted the
	 * superseded master, and the safety here is the explicit clean path, not the
	 * resolver.
	 *
	 * @internal SHARP EDGE — single sanctioned caller.
	 * Because seedFrom() drops ensureBackup()'s status/stored-hash hard guard, the
	 * ONLY thing preventing a watermarked file from being copied into the master is
	 * the caller proving $cleanSourcePath is clean. Preflight checks dimensions and
	 * decodability — it CANNOT tell clean pixels from watermarked ones. The sole
	 * sanctioned caller is {@see \OrchardGrove\OgWatermark\Integration\EditorBridge::refreshFromUserEdit()},
	 * whose per-request clean-substitution marker is the safety precondition. Any new
	 * caller MUST establish equivalent proof that $cleanSourcePath was never
	 * composited before invoking this method.
	 *
	 * @param int    $id              Attachment ID.
	 * @param string $cleanSourcePath Absolute path of a proven-clean source file.
	 * @return array{ok:bool,status:string,reason:string}
	 */
	public static function seedFrom( int $id, string $cleanSourcePath ): array {
		if ( $id <= 0 ) {
			return self::result( false, Meta::STATUS_FAILED, 'invalid-attachment-id' );
		}

		if ( '' === $cleanSourcePath ) {
			return self::result( false, Meta::STATUS_FAILED, 'empty-clean-source-path' );
		}

		return self::createFromPath( $id, $cleanSourcePath );
	}

	/**
	 * The ONE transactional create-from-path implementation shared by
	 * {@see ensureBackup()} (resolver source, first-apply only) and
	 * {@see seedFrom()} (explicit clean source). It NEVER consults
	 * wp_get_original_image_path() — the source is whatever the caller passes — so
	 * there is a single backup-writing code path and the immutable-hash invariant
	 * (hash derived only from the file we were handed) holds for both callers.
	 *
	 * Steps: Preflight against the CURRENT attachment metadata, ensure the storage
	 * tree, compute + disk-check the destination, transactional hash-verified copy,
	 * derive the integrity hash from the BACKUP we just wrote, persist meta,
	 * invalidate stats.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $source Absolute path of the source to copy into the master.
	 * @return array{ok:bool,status:string,reason:string}
	 */
	private static function createFromPath( int $id, string $source ): array {
		// 1) Pre-flight: real local raster, dimensions match meta, above byte floor.
		//    Ambiguity (offload stub / zero-byte / dimension mismatch) => skipped.
		$meta       = wp_get_attachment_metadata( $id );
		$meta_array = is_array( $meta ) ? $meta : [];
		$pre        = Preflight::validateLocalOriginal( $source, $meta_array );

		if ( empty( $pre['ok'] ) ) {
			$pre_status = isset( $pre['status'] ) && is_string( $pre['status'] )
				? $pre['status']
				: Meta::STATUS_FAILED;
			$reason = isset( $pre['reason'] ) && is_string( $pre['reason'] )
				? $pre['reason']
				: 'preflight-failed';

			return self::result( false, $pre_status, $reason );
		}

		// 2) Ensure the protected backup tree exists.
		if ( ! Storage::ensureDir() ) {
			return self::result( false, Meta::STATUS_FAILED, 'backup-dir-unavailable' );
		}

		// 3) Compute the backup target path mirroring year/month + id-prefixed name.
		$dest = self::computeBackupPath( $id, $source );
		if ( null === $dest ) {
			return self::result( false, Meta::STATUS_FAILED, 'cannot-compute-backup-path' );
		}

		// 4) Disk-space pre-flight against the destination directory.
		$bytes = (int) @filesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! Preflight::hasDiskSpaceFor( $bytes, dirname( $dest ) ) ) {
			return self::result( false, Meta::STATUS_FAILED, 'insufficient-disk-space' );
		}

		// 5) Transactional, hash-verified copy. copyVerified leaves NOTHING behind
		//    on any failure (no partial, no dest).
		if ( ! BackupWriter::copyVerified( $source, $dest ) ) {
			return self::result( false, Meta::STATUS_FAILED, 'backup-copy-failed' );
		}

		// 6) Derive the integrity hash from the BACKUP we just wrote — which is a
		//    byte-for-byte copy of the source the caller handed us (copyVerified
		//    guarantees sha1 equality). This is the immutable-hash invariant: the
		//    hash comes ONLY from $source, never from a re-resolved original.
		$hash = BackupWriter::sha1Of( $dest );
		if ( null === $hash ) {
			// Cannot read back what we just wrote — do not leave an unverifiable
			// backup masquerading as the master.
			self::deletePath( $dest );
			return self::result( false, Meta::STATUS_FAILED, 'backup-hash-unreadable' );
		}

		// 7) Persist meta: relative path (portable across host moves) + hash + bytes.
		$rel = self::relativePath( $dest );
		if ( null === $rel ) {
			self::deletePath( $dest );
			return self::result( false, Meta::STATUS_FAILED, 'backup-path-escapes-base' );
		}

		update_post_meta( $id, Meta::BACKUP_REL, $rel );
		update_post_meta( $id, Meta::BACKUP_HASH, $hash );
		update_post_meta( $id, Meta::BACKUP_BYTES, (int) @filesize( $dest ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		self::invalidateStats();

		return self::result( true, Meta::STATUS_WATERMARKED, 'backup-created' );
	}

	/**
	 * Verify the stored backup still exists and matches its recorded hash.
	 *
	 * Never re-derives a hash from a served file; a mismatch means the master is
	 * compromised and the caller must treat the attachment as `backup_missing`.
	 *
	 * @param int $id Attachment ID.
	 * @return bool True only when the backup file exists AND sha1 === stored hash.
	 */
	public static function verify( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$stored_hash = (string) get_post_meta( $id, Meta::BACKUP_HASH, true );
		if ( '' === $stored_hash ) {
			return false;
		}

		$path = self::backupPath( $id );
		if ( null === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$actual = BackupWriter::sha1Of( $path );

		return null !== $actual && hash_equals( $stored_hash, $actual );
	}

	/**
	 * Absolute path of the backup, resolved from the relative meta against the
	 * Storage base and revalidated with the load-bearing containment guard.
	 *
	 * @param int $id Attachment ID.
	 * @return string|null Absolute path, or null when unset or escaping the base.
	 */
	public static function backupPath( int $id ): ?string {
		if ( $id <= 0 ) {
			return null;
		}

		$rel = (string) get_post_meta( $id, Meta::BACKUP_REL, true );
		if ( '' === $rel ) {
			return null;
		}

		$abs = Storage::baseDir() . '/' . ltrim( $rel, '/\\' );

		// Revalidate on every read: reject traversal / escaping symlinks.
		if ( ! Storage::within( $abs ) ) {
			return null;
		}

		return $abs;
	}

	/**
	 * Restore the pristine backup back over the canonical on-disk original.
	 *
	 * Verifies the backup first, then copies it to the live `original_image`
	 * location via a temp file + atomic replace, so a crash never leaves a
	 * half-written original in place. Does NOT touch meta — clearing lifecycle
	 * meta is the Processor's job (M4), which orchestrates the full re-derive of
	 * served sizes before declaring the attachment restored.
	 *
	 * @param int $id Attachment ID.
	 * @return bool True on a verified, atomically-committed restore.
	 */
	public static function restoreOriginal( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		// Never restore from an unverifiable master.
		if ( ! self::verify( $id ) ) {
			return false;
		}

		$backup = self::backupPath( $id );
		if ( null === $backup ) {
			return false;
		}

		$dest = wp_get_original_image_path( $id, true );
		if ( ! is_string( $dest ) || '' === $dest ) {
			return false;
		}

		$dir = dirname( $dest );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Stage to a unique temp beside the destination so the final swap is an
		// atomic same-directory rename. copyVerified does its own full-content
		// sha1 check, so the temp is guaranteed byte-identical to the backup.
		$temp = $dest . '.ogwm-restore-' . self::uniqueToken() . '.tmp';

		if ( ! BackupWriter::copyVerified( $backup, $temp ) ) {
			self::deletePath( $temp );
			return false;
		}

		// Atomic same-directory swap. On failure leave the live original untouched.
		if ( ! @rename( $temp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			self::deletePath( $temp );
			return false;
		}

		return true;
	}

	/**
	 * Delete the backup file (containment-guarded) and clear its meta keys.
	 *
	 * @param int $id Attachment ID.
	 * @return bool True when the backup file is gone afterwards (or never existed)
	 *              and meta was cleared.
	 */
	public static function deleteBackup( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$path = self::backupPath( $id );

		$removed = true;
		if ( null !== $path && is_file( $path ) ) {
			// within() already enforced by backupPath(); delete directly.
			$removed = self::deletePath( $path );
		}

		delete_post_meta( $id, Meta::BACKUP_REL );
		delete_post_meta( $id, Meta::BACKUP_HASH );
		delete_post_meta( $id, Meta::BACKUP_BYTES );

		self::invalidateStats();

		return $removed;
	}

	/**
	 * Aggregate backup storage footprint for the "Backup storage" admin card.
	 *
	 * Cached in a 12h transient and invalidated by ensureBackup()/deleteBackup().
	 * The cheap path sums the recorded `_ogwm_backup_bytes` meta; a directory
	 * walk of the Storage base is the fallback when no meta sum is available.
	 *
	 * @return array{bytes:int,count:int}
	 */
	public static function stats(): array {
		$cached = get_transient( self::STATS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['bytes'], $cached['count'] ) ) {
			return [
				'bytes' => (int) $cached['bytes'],
				'count' => (int) $cached['count'],
			];
		}

		$stats = self::computeStats();

		set_transient( self::STATS_TRANSIENT, $stats, self::STATS_TTL );

		return $stats;
	}

	// ---------------------------------------------------------------------
	// Internal helpers.
	// ---------------------------------------------------------------------

	/**
	 * Compute the absolute backup destination: <base>/<YYYY>/<MM>/<id>-<name>.
	 *
	 * The year/month mirror the live original's path when it carries them, so
	 * the backup tree shadows the uploads layout. The id prefix guarantees no
	 * collisions. The final path is containment-checked before it is returned.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $source Absolute path of the resolved original.
	 * @return string|null Absolute destination path, or null when it escapes base.
	 */
	private static function computeBackupPath( int $id, string $source ): ?string {
		$base     = Storage::baseDir();
		$basename = sanitize_file_name( basename( $source ) );
		if ( '' === $basename ) {
			$basename = 'original';
		}

		$dest = $base . '/' . self::yearMonthSegment( $source ) . $id . '-' . $basename;

		// Final guard: the computed path MUST live inside the backup base.
		if ( ! Storage::within( $dest ) ) {
			return null;
		}

		return $dest;
	}

	/**
	 * Derive a "YYYY/MM/" path segment from the source path's own directory when
	 * it ends in the WP uploads year/month pattern; empty string otherwise so
	 * the backup lands directly under the base.
	 *
	 * @param string $source Absolute source path.
	 * @return string Trailing-slashed "YYYY/MM/" segment, or '' when none.
	 */
	private static function yearMonthSegment( string $source ): string {
		$dir = str_replace( '\\', '/', dirname( $source ) );

		if ( 1 === preg_match( '#/(\d{4})/(\d{2})$#', $dir, $m ) ) {
			return $m[1] . '/' . $m[2] . '/';
		}

		return '';
	}

	/**
	 * Path RELATIVE to the Storage base for a validated absolute backup path.
	 *
	 * @param string $abs Absolute path already known to live inside the base.
	 * @return string|null Relative path (forward slashes), or null when it does
	 *                     not actually sit under the base.
	 */
	private static function relativePath( string $abs ): ?string {
		$base = Storage::baseDir();

		$base_norm = rtrim( str_replace( '\\', '/', $base ), '/' ) . '/';
		$abs_norm  = str_replace( '\\', '/', $abs );

		if ( 0 !== strncmp( $abs_norm, $base_norm, strlen( $base_norm ) ) ) {
			return null;
		}

		return substr( $abs_norm, strlen( $base_norm ) );
	}

	/**
	 * Recompute the storage footprint: prefer the fast meta-sum, fall back to a
	 * directory walk of the Storage base.
	 *
	 * @return array{bytes:int,count:int}
	 */
	private static function computeStats(): array {
		global $wpdb;

		$bytes = 0;
		$count = 0;

		// Fast path: sum the recorded backup bytes across all attachments.
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_row' ) ) {
			$key = Meta::BACKUP_BYTES;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS cnt, COALESCE( SUM( meta_value + 0 ), 0 ) AS total FROM {$wpdb->postmeta} WHERE meta_key = %s",
					$key
				),
				ARRAY_A
			);

			if ( is_array( $row ) && isset( $row['cnt'], $row['total'] ) ) {
				$count = (int) $row['cnt'];
				// SUM across a large library can exceed 2^31; cast via float first so
				// the total is not silently truncated on a 32-bit PHP build (the (int)
				// is then exact on the 64-bit norm and merely best-effort on 32-bit).
				$bytes = (int) (float) $row['total'];

				if ( $count > 0 ) {
					return [
						'bytes' => $bytes,
						'count' => $count,
					];
				}
			}
		}

		// Fallback: walk the backup tree (counts only real image files, not the
		// hardening index.php/.htaccess/web.config guards).
		return self::directoryStats();
	}

	/**
	 * Directory-walk fallback for stats: sum file sizes under the Storage base,
	 * excluding the directory-hardening guard files.
	 *
	 * @return array{bytes:int,count:int}
	 */
	private static function directoryStats(): array {
		$base = Storage::baseDir();
		if ( ! is_dir( $base ) ) {
			return [
				'bytes' => 0,
				'count' => 0,
			];
		}

		$skip  = [ 'index.php', '.htaccess', 'web.config' ];
		$bytes = 0;
		$count = 0;

		// CATCH_GET_CHILD makes the iterator SKIP an unreadable subtree instead of
		// throwing, and the try/catch is a belt-and-braces guard so a single bad
		// permission (or a file vanishing mid-walk) returns the partial total rather
		// than fataling the read-only "Backup storage" admin card.
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY,
				\RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
					continue;
				}
				if ( in_array( $file->getFilename(), $skip, true ) ) {
					continue;
				}

				$size = @$file->getSize(); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a file vanishing mid-walk must not warn; treat as 0.
				if ( false !== $size ) {
					$bytes += (int) $size;
				}
				++$count;
			}
		} catch ( \Throwable $e ) {
			// Return whatever was accumulated before the failure rather than fatal.
			unset( $e );
		}

		return [
			'bytes' => $bytes,
			'count' => $count,
		];
	}

	/** Drop the cached stats so the next stats() call recomputes. */
	private static function invalidateStats(): void {
		delete_transient( self::STATS_TRANSIENT );
	}

	/**
	 * Best-effort file removal. Returns true when the path is gone afterwards.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function deletePath( string $path ): bool {
		if ( ! file_exists( $path ) ) {
			return true;
		}

		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		return ! file_exists( $path );
	}

	/** Unguessable token for unique temp filenames during a restore. */
	private static function uniqueToken(): string {
		if ( function_exists( 'wp_generate_password' ) ) {
			return (string) wp_generate_password( 12, false, false );
		}

		return bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Shape an orchestration result.
	 *
	 * @param bool   $ok     Whether the operation succeeded.
	 * @param string $status A Meta::STATUS_* value.
	 * @param string $reason Machine-readable reason code.
	 * @return array{ok:bool,status:string,reason:string}
	 */
	private static function result( bool $ok, string $status, string $reason ): array {
		return [
			'ok'     => $ok,
			'status' => $status,
			'reason' => $reason,
		];
	}
}
