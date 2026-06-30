<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Pipeline;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Backup\Manager;
use OrchardGrove\OgWatermark\Engine\Compositor;
use OrchardGrove\OgWatermark\Engine\LogoAsset;
use OrchardGrove\OgWatermark\Engine\Result;
use OrchardGrove\OgWatermark\Integration\CachePurge;
use OrchardGrove\OgWatermark\Settings\Options;
use OrchardGrove\OgWatermark\Support\Lock;
use OrchardGrove\OgWatermark\Support\Meta;
use OrchardGrove\OgWatermark\Support\Signature;

/**
 * THE single idempotent orchestration seam (SPEC: "Processing flow").
 *
 * Every caller — single-flag AJAX, bulk job, the regenerate listener, a
 * settings-change reprocess, WP-CLI — routes through {@see process()}. The
 * Processor ties the M2 rendering engine ({@see Compositor}) to the M3 backup
 * manager ({@see Manager}) into ONE operation that is:
 *
 *  - idempotent — a matching signature with all recorded sizes still present is a
 *    no-op, decided BEFORE any decode/backup/regenerate work;
 *  - never-stacking — every served size is rebuilt FROM the immutable pristine
 *    backup (restore → wp_create_image_subsizes), so a re-apply can only ever
 *    produce a single fresh stamp, never a stamp-on-a-stamp;
 *  - crash-safe — each output is composited to a per-job temp file and atomically
 *    renamed into place, and the completion signature is written LAST, only after
 *    the highest-value file (full / -scaled, and the big-image original when
 *    present) actually stamped.
 *
 * Ordering is the whole correctness story and is enforced top-to-bottom:
 *   1. backup is ENSURED before any served file is touched;
 *   2. clean sizes are rebuilt from that backup;
 *   3. outputs are staged to temp, then committed by atomic rename;
 *   4. the signature/`watermarked` status is committed ONLY after the required
 *      targets stamped.
 *
 * {@see restore()} runs the same discipline in reverse: it rebuilds clean sizes
 * from the backup FIRST and clears lifecycle meta only after the clean files are
 * in place (clearing meta before the clean files exist is forbidden).
 *
 * {@see $processing} is the reentrancy flag the M5 wp_generate_attachment_metadata
 * listener reads: while it is true (i.e. the Processor is itself mid-(re)generate),
 * that listener no-ops at the top of its own callback so a FOREIGN
 * wp_generate_attachment_metadata caller (Regenerate Thumbnails, `wp media
 * regenerate`) that routes back into Processor::process can't recurse into us.
 *
 * NOTE: this guards against an OUTSIDE caller of the wp_generate_attachment_metadata
 * filter re-entering us; it is NOT that core fires that filter from inside our own
 * regenerate. Core's call graph is the reverse — wp_generate_attachment_metadata()
 * CALLS wp_create_image_subsizes(); wp_create_image_subsizes() (the only core call
 * we make, via {@see SubsizeRegenerator}) does not itself fire the
 * wp_generate_attachment_metadata filter, and neither does
 * wp_update_attachment_metadata(). So our own regenerate path never trips the
 * listener; the flag is purely defensive against a re-entrant foreign caller.
 */
final class Processor {

	/** Bundled text-mode font, relative to OGWM_DIR (mirrors Compositor::FONT_REL). */
	private const FONT_REL = 'assets/fonts/DejaVuSans-Bold.ttf';

	/** Lock key prefix; the full key is this + the attachment id. */
	private const LOCK_PREFIX = 'ogwm_lock_';

	/** Lock lifetime; refreshed each per-file commit so a long job never lapses. */
	private const LOCK_TTL = 300;

	/**
	 * True while THIS class is mid-(re)generate, so the M5
	 * wp_generate_attachment_metadata listener no-ops instead of recursing.
	 */
	private static bool $processing = false;

	/** Injectable subsize regenerator (the only caller of wp_create_image_subsizes). */
	private SubsizeRegenerator $regenerator;

	/** @param SubsizeRegenerator|null $regenerator Real wrapper by default; tests inject a fake. */
	public function __construct( ?SubsizeRegenerator $regenerator = null ) {
		$this->regenerator = $regenerator ?? new SubsizeRegenerator();
	}

	/**
	 * Static convenience used by hooks/the queue: (new self())->process($id,$context).
	 *
	 * @param int    $id      Attachment ID.
	 * @param string $context One of flag|bulk|regenerate|settings-change|cli.
	 */
	public static function process_attachment( int $id, string $context ): Outcome {
		return ( new self() )->process( $id, $context );
	}

	/** Whether the Processor is mid-(re)generate (read by the M5 listener). */
	public static function isProcessing(): bool {
		return self::$processing;
	}

	/**
	 * The per-attachment advisory-lock key for $id (the SINGLE source of truth for
	 * the lock-key shape). The M5 EditorBridge gates its backup-refresh on this same
	 * key via {@see Lock::held()}, so both layers MUST derive it from here — a
	 * hand-copied literal would silently desync the data-loss guard if the prefix
	 * ever changed.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function lockKey( int $id ): string {
		return self::LOCK_PREFIX . $id;
	}

	/**
	 * Apply (or refresh) the watermark for one attachment, idempotently.
	 *
	 * @param int    $id      Attachment ID.
	 * @param string $context One of flag|bulk|regenerate|settings-change|cli.
	 */
	public function process( int $id, string $context ): Outcome {
		// 1) Only images are ever touched.
		if ( ! wp_attachment_is_image( $id ) ) {
			return Outcome::skipped( 'not-an-image' );
		}

		// 2) Re-entrant / passive contexts require an explicit flag. A direct flag,
		//    bulk, or CLI run is allowed to apply to a not-yet-flagged attachment;
		//    a regenerate/settings sweep must never silently stamp an unflagged one.
		if ( in_array( $context, [ 'regenerate', 'settings-change' ], true ) && ! $this->isFlagged( $id ) ) {
			return Outcome::skipped( 'not-flagged' );
		}

		// 3) Atomic per-attachment lock. Everything below runs inside try/finally so
		//    the reentrancy flag and the lock are ALWAYS released.
		$key   = self::lockKey( $id );
		$token = Lock::acquire( $key, self::LOCK_TTL );
		if ( null === $token ) {
			return Outcome::locked();
		}

		try {
			self::$processing = true;
			return $this->runProcess( $id, $token, $key );
		} finally {
			self::$processing = false;
			Lock::release( $key, $token );
		}
	}

	/**
	 * The locked body of process(). Resolves the signature, short-circuits on a
	 * match, ensures the backup, rebuilds clean sizes from it, stamps each target
	 * to a temp file + atomic rename, and commits the signature LAST.
	 */
	private function runProcess( int $id, string $token, string $key ): Outcome {
		$options  = new Options();
		$settings = $options->all();

		$logoPath = $this->resolveLogoPath( $settings );
		$fontPath = $this->fontPath();
		$signature = Signature::compute( $settings, $logoPath, $fontPath );

		// 6) SIGNATURE SHORT-CIRCUIT — before ANY decode/backup/regenerate work.
		//    A matching signature whose every recorded size file still exists means
		//    the served set is already current; do nothing.
		if ( $this->signatureIsCurrent( $id, $signature ) ) {
			return Outcome::noop();
		}

		// 7) Ensure a valid, hash-verified pristine backup. NOTHING is stamped until
		//    the master exists — it is the sole clean source every rebuild derives
		//    from. ensureBackup() returns an ADVISORY status; we map its FAILURE
		//    statuses onto the real lifecycle, but never blindly persist a success
		//    status (it has composited nothing).
		$backup = Manager::ensureBackup( $id );
		if ( empty( $backup['ok'] ) ) {
			return $this->failFromBackup( $id, $backup );
		}

		// 8) Rebuild clean sizes FROM the backup. Restore the pristine original to
		//    the canonical on-disk path, then regenerate all subsizes against that
		//    in-place file. self::$processing stays true throughout so a FOREIGN
		//    wp_generate_attachment_metadata caller (Regenerate Thumbnails, wp media
		//    regenerate) that re-enters Processor::process mid-run is short-circuited
		//    by the M5 listener — wp_create_image_subsizes() itself does NOT fire that
		//    filter, so the guard is purely against re-entrant outside callers.
		$originalPath = wp_get_original_image_path( $id, true );
		if ( ! is_string( $originalPath ) || '' === $originalPath ) {
			return $this->fail( $id, Meta::STATUS_FAILED, 'cannot-resolve-original-path', Outcome::failed( 'cannot-resolve-original-path' ) );
		}

		if ( ! Manager::restoreOriginal( $id ) ) {
			// The backup verified inside ensureBackup() but the restore swap failed:
			// treat as backup_missing so the attachment freezes rather than stamping
			// a possibly-dirty served set.
			return $this->fail( $id, Meta::STATUS_BACKUP_MISSING, 'restore-from-backup-failed', Outcome::backupMissing( 'restore-from-backup-failed' ) );
		}

		$newMeta = $this->regenerator->regenerate( $id, $originalPath );
		if ( null === $newMeta ) {
			// Core could not regenerate subsizes (a WP_Error from the image editor —
			// most often an OOM on a big original). DO NOT call
			// wp_update_attachment_metadata: writing the empty result would destroy the
			// attachment's existing _wp_attachment_metadata (sizes / original_image /
			// dimensions) and break srcset + the Media library. Freeze as failed and
			// leave the restored-clean original (and the existing meta) in place.
			return $this->fail( $id, Meta::STATUS_FAILED, 'regenerate-failed', Outcome::failed( 'regenerate-failed' ) );
		}
		wp_update_attachment_metadata( $id, $newMeta );

		// 9) Enumerate the stamp targets from the FRESH, clean metadata.
		$targets = SizeResolver::targets( $id, $settings );

		// 10) Stage + commit each target. The full/original outcomes gate completion.
		$staged = $this->stampTargets( $id, $targets, $settings, $logoPath, $token, $key );

		// 11) Persist the per-size audit map regardless of overall success. Each run
		//     is a FULL rebuild from the pristine backup (restore → regenerate →
		//     re-stamp every target), so this is the authoritative committed-at map for
		//     this run; it is a full overwrite by design, not an incremental merge.
		update_post_meta( $id, Meta::SIZES_DONE, $staged['sizesStamped'] );

		// Block 'watermarked' unless the highest-value file actually stamped.
		if ( ! $staged['requiredStamped'] ) {
			return $this->failRequired( $id, $staged );
		}

		// 11b) Commit signature LAST — only now is the served set genuinely current.
		update_post_meta( $id, Meta::SIGNATURE, $signature );
		update_post_meta( $id, Meta::STATUS, Meta::STATUS_WATERMARKED );
		update_post_meta( $id, Meta::APPLIED_GMT, gmdate( 'c' ) );
		delete_post_meta( $id, Meta::LAST_ERROR );

		// 11c) The watermark is baked into the existing files at their existing URLs,
		//      so any cache keyed on those URLs is now serving stale pre-watermark
		//      bytes. Flush THIS attachment's cached URLs. Best-effort + self-guarded:
		//      CachePurge never throws, so this can never fail the committed run.
		CachePurge::purgeAttachment( $id );

		return Outcome::watermarked( $staged['sizesStamped'] );
	}

	/**
	 * Stamp every target to a per-job temp file, then atomically rename it into
	 * place. Tracks which sizes committed and whether the REQUIRED targets (full,
	 * and the big-image original when present) actually stamped.
	 *
	 * @param array<int,array{slug:string,path:string,mime:string,width:int,height:int}> $targets
	 * @param array<string,mixed>                                                         $settings
	 * @return array{sizesStamped:array<string,int>,requiredStamped:bool,failReason:string,failStatus:string}
	 */
	private function stampTargets(
		int $id,
		array $targets,
		array $settings,
		?string $logoPath,
		string $token,
		string $key
	): array {
		$sizesStamped    = [];
		$requiredStamped = true;
		$requiredSeen    = false;
		$failReason      = '';
		$failStatus      = Meta::STATUS_FAILED;

		foreach ( $targets as $target ) {
			$slug = $target['slug'];
			$path = $target['path'];
			$mime = $target['mime'];

			$isRequired = in_array( $slug, [ SizeResolver::SLUG_FULL, SizeResolver::SLUG_ORIGINAL ], true );
			if ( $isRequired ) {
				$requiredSeen = true;
			}

			$tmp = $path . '.ogwm-' . $token . '.tmp';

			// try/finally so the in-flight staging temp is ALWAYS swept, even if a
			// future change lets Compositor::stamp() (or anything below) throw. The
			// per-token name bounds any stray temp to this one job, and this guarantees
			// it is removed rather than left stranded.
			try {
				$result = Compositor::stamp( $path, $tmp, $mime, $settings, $logoPath );

				if ( Result::STAMPED === $result->status && $this->commitRename( $tmp, $path ) ) {
					$sizesStamped[ $slug ] = time();
				} elseif ( Result::STAMPED === $result->status ) {
					// Stamp produced a temp but the atomic rename failed. The clean file
					// is already in place from regeneration; a failed rename leaves it
					// clean. For a required target that means the highest-value file is
					// NOT watermarked.
					if ( $isRequired ) {
						$requiredStamped = false;
						$failStatus      = Meta::STATUS_FAILED;
						$failReason      = 'commit-rename-failed:' . $slug;
					}
				} else {
					// Not stamped. The clean (regenerated) file stays in place. For a
					// REQUIRED target (full / original) anything short of an actual stamp
					// blocks the 'watermarked' status — the anti-theft keystone must
					// truly carry the mark (SPEC: "block watermarked unless the full
					// actually stamped"). too_large maps to the too_large status; every
					// other non-stamp (failed, or a format-policy skip on the
					// highest-value file) maps to failed, with the engine reason surfaced.
					if ( $isRequired ) {
						$requiredStamped = false;
						$failStatus      = ( Result::TOO_LARGE === $result->status )
							? Meta::STATUS_TOO_LARGE
							: Meta::STATUS_FAILED;
						$failReason = $result->status . ':' . $slug . ( '' !== $result->reason ? ' (' . $result->reason . ')' : '' );
					}
				}
			} finally {
				// Always remove the staging temp for this target (a committed rename
				// already consumed it, so this is a harmless no-op on the success path).
				$this->cleanupTemp( $tmp );
			}

			// Heartbeat the lock between files so a multi-minute big-image job never
			// has its lock expire under a concurrent writer. If refresh() returns false
			// we are NO LONGER the owner (our TTL lapsed and another worker reclaimed
			// the key); ABORT the remaining targets immediately so two writers never
			// composite + atomically rename the same paths concurrently (the very
			// stack-watermark / torn-write race the lock exists to prevent). The
			// signature is withheld because requiredStamped is forced false.
			if ( ! Lock::refresh( $key, $token, self::LOCK_TTL ) ) {
				$requiredStamped = false;
				$failStatus      = Meta::STATUS_FAILED;
				$failReason      = 'lock-lost';
				break;
			}
		}

		unset( $id ); // $id is only carried for symmetry / future audit hooks.

		return [
			'sizesStamped'    => $sizesStamped,
			'requiredStamped' => $requiredStamped && $requiredSeen,
			'failReason'      => '' !== $failReason ? $failReason : ( $requiredSeen ? '' : 'no-required-target' ),
			'failStatus'      => $failStatus,
		];
	}

	/**
	 * Restore an attachment to its pristine, un-watermarked state.
	 *
	 * Rebuilds the clean original + all clean sizes FROM the backup BEFORE clearing
	 * any lifecycle meta — clearing meta while the served files are still
	 * watermarked is forbidden (the user would see "not watermarked" over
	 * watermarked pixels). The backup is retained (undo can be re-applied).
	 *
	 * @param int $id Attachment ID.
	 */
	public function restore( int $id ): Outcome {
		$key   = self::lockKey( $id );
		$token = Lock::acquire( $key, self::LOCK_TTL );
		if ( null === $token ) {
			return Outcome::locked();
		}

		try {
			// 2) No verified master → nothing to restore from; refuse.
			if ( ! Manager::verify( $id ) ) {
				return Outcome::backupMissing();
			}

			self::$processing = true;

			$originalPath = wp_get_original_image_path( $id, true );
			if ( ! is_string( $originalPath ) || '' === $originalPath ) {
				return Outcome::failed( 'cannot-resolve-original-path' );
			}

			// 3) Rebuild clean files: restore the pristine original in place, then
			//    regenerate every clean size from it. Meta is untouched until this
			//    completes.
			if ( ! Manager::restoreOriginal( $id ) ) {
				return Outcome::failed( 'restore-from-backup-failed' );
			}

			$newMeta = $this->regenerator->regenerate( $id, $originalPath );
			if ( null === $newMeta ) {
				// Core could not regenerate the clean sizes. Do NOT call
				// wp_update_attachment_metadata (it would wipe the existing metadata),
				// and do NOT clear lifecycle meta — clearing meta before the clean files
				// exist is forbidden. The pristine original was already restored in
				// place; report the failure and leave meta intact for a retry.
				return Outcome::failed( 'regenerate-failed' );
			}
			wp_update_attachment_metadata( $id, $newMeta );

			// 4) ONLY AFTER the clean sizes exist: clear lifecycle meta, mark restored,
			//    and unflag (undo also opts the attachment out). The backup is KEPT.
			delete_post_meta( $id, Meta::SIGNATURE );
			delete_post_meta( $id, Meta::SIZES_DONE );
			delete_post_meta( $id, Meta::APPLIED_GMT );
			delete_post_meta( $id, Meta::LAST_ERROR );
			update_post_meta( $id, Meta::STATUS, Meta::STATUS_RESTORED );
			delete_post_meta( $id, Meta::ENABLED );

			// The clean (un-watermarked) files now sit at the same URLs the
			// watermarked bytes were served from, so the cache must be flushed for
			// the restore to be visible too. Same best-effort, self-guarded purge.
			CachePurge::purgeAttachment( $id );

			return Outcome::restored();
		} finally {
			self::$processing = false;
			Lock::release( $key, $token );
		}
	}

	// ---------------------------------------------------------------------
	// Internal helpers.
	// ---------------------------------------------------------------------

	/** Whether the attachment carries the opt-in flag. */
	private function isFlagged( int $id ): bool {
		return '1' === (string) get_post_meta( $id, Meta::ENABLED, true );
	}

	/**
	 * Whether the served set is genuinely current for $signature. Requires ALL of:
	 *
	 *  1. the stored signature matches the recomputed one (settings + logo + font +
	 *     plugin version are unchanged); AND
	 *  2. every currently-required target (full, the big-image original, and every
	 *     surviving enabled intermediate) is present in the recorded sizes_done map —
	 *     i.e. done ⊇ {current target slugs}; AND
	 *  3. every recorded size file still exists on disk.
	 *
	 * Condition (2) is the COVERAGE guard: the signature fingerprints settings/logo/
	 * font/version but NOT the set of WordPress-registered image sizes. A theme or
	 * plugin can register a new intermediate (add_image_size) in code AFTER an
	 * attachment was watermarked; SizeResolver then enumerates a new target whose
	 * clean (unstamped) file exists, and without (2) a matching signature would
	 * short-circuit to a no-op leaving that new size served un-watermarked. Requiring
	 * the recorded map to cover every current target forces a rebuild when a new
	 * target appears. Condition (3) forces a rebuild when a recorded file vanished.
	 */
	private function signatureIsCurrent( int $id, string $signature ): bool {
		$stored = (string) get_post_meta( $id, Meta::SIGNATURE, true );
		if ( '' === $stored || ! hash_equals( $stored, $signature ) ) {
			return false;
		}

		$done = get_post_meta( $id, Meta::SIZES_DONE, true );
		if ( ! is_array( $done ) || [] === $done ) {
			// A matching signature but no recorded sizes can't prove the served set
			// is present — force a rebuild.
			return false;
		}

		$targets = SizeResolver::targets( $id, ( new Options() )->all() );
		if ( [] === $targets ) {
			// No resolvable targets (e.g. the attached file is gone) — can't claim the
			// served set is current; force a rebuild rather than trust a stale map.
			return false;
		}

		$bySlug = [];
		foreach ( $targets as $target ) {
			$bySlug[ $target['slug'] ] = $target['path'];
		}

		// COVERAGE: every currently-required/enabled target must already be recorded
		// as done. A new target (e.g. a freshly-registered image size) that is NOT in
		// the recorded map forces a rebuild even when the signature matches.
		foreach ( array_keys( $bySlug ) as $slug ) {
			if ( ! array_key_exists( (string) $slug, $done ) ) {
				return false;
			}
		}

		// PRESENCE: every recorded size whose target path is known must still exist on
		// disk. A recorded slug that is no longer a current target (e.g. a size that
		// was de-registered) is ignored here — it is not a served file we must keep.
		foreach ( array_keys( $done ) as $slug ) {
			$slug = (string) $slug;
			if ( isset( $bySlug[ $slug ] ) && ! is_file( $bySlug[ $slug ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolve the logo path for logo mode (null for text mode or an invalid logo).
	 *
	 * @param array<string,mixed> $settings
	 */
	private function resolveLogoPath( array $settings ): ?string {
		$type = '';
		if ( isset( $settings['watermark']['type'] ) && is_scalar( $settings['watermark']['type'] ) ) {
			$type = (string) $settings['watermark']['type'];
		}
		if ( 'logo' !== $type ) {
			return null;
		}

		$logoId = 0;
		if ( isset( $settings['watermark']['logo_id'] ) && is_scalar( $settings['watermark']['logo_id'] ) ) {
			$logoId = (int) $settings['watermark']['logo_id'];
		}

		return LogoAsset::resolvePath( $logoId );
	}

	/** Absolute path to the bundled text font, or '' when OGWM_DIR is undefined. */
	private function fontPath(): string {
		if ( ! defined( 'OGWM_DIR' ) ) {
			return '';
		}
		return OGWM_DIR . self::FONT_REL;
	}

	/** Atomic same-directory commit of a staged temp file over its final path. */
	private function commitRename( string $tmp, string $dest ): bool {
		if ( ! is_file( $tmp ) ) {
			return false;
		}
		// @ so a cross-device or permission failure surfaces as a recordable failure
		// (we leave the clean file in place) rather than a warning under failOnWarning.
		return @rename( $tmp, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}

	/** Remove a leftover staging temp; best-effort. */
	private function cleanupTemp( string $tmp ): void {
		if ( is_file( $tmp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Map an ensureBackup() failure onto the lifecycle meta + the matching Outcome.
	 * Nothing has been stamped at this point.
	 *
	 * @param int                                         $id     Attachment ID.
	 * @param array{ok:bool,status:string,reason:string} $backup The ensureBackup() result.
	 */
	private function failFromBackup( int $id, array $backup ): Outcome {
		$status = isset( $backup['status'] ) && is_string( $backup['status'] ) ? $backup['status'] : Meta::STATUS_FAILED;
		$reason = isset( $backup['reason'] ) && is_string( $backup['reason'] ) ? $backup['reason'] : 'backup-failed';

		update_post_meta( $id, Meta::STATUS, $status );
		update_post_meta( $id, Meta::LAST_ERROR, $reason );

		return match ( $status ) {
			Meta::STATUS_BACKUP_MISSING    => Outcome::backupMissing( $reason ),
			Meta::STATUS_SKIPPED_OFFLOADED => Outcome::skipped( $reason ),
			default                        => Outcome::failed( $reason ),
		};
	}

	/**
	 * Persist a failure status + surfaced error and return the given Outcome. Used
	 * for failures AFTER the backup is ensured (restore/regenerate problems).
	 */
	private function fail( int $id, string $status, string $reason, Outcome $outcome ): Outcome {
		update_post_meta( $id, Meta::STATUS, $status );
		update_post_meta( $id, Meta::LAST_ERROR, $reason );
		return $outcome;
	}

	/**
	 * Handle a run where a REQUIRED target (full / original) did not stamp: persist
	 * the surfaced failure WITHOUT writing the completion signature, and return the
	 * too_large or failed Outcome (carrying whatever lesser sizes did stamp).
	 *
	 * @param int                                                                                             $id     Attachment ID.
	 * @param array{sizesStamped:array<string,int>,requiredStamped:bool,failReason:string,failStatus:string} $staged The stampTargets() summary.
	 */
	private function failRequired( int $id, array $staged ): Outcome {
		$status = $staged['failStatus'];
		$reason = '' !== $staged['failReason'] ? $staged['failReason'] : 'required-target-not-stamped';

		update_post_meta( $id, Meta::STATUS, $status );
		update_post_meta( $id, Meta::LAST_ERROR, $reason );
		// Deliberately NOT writing Meta::SIGNATURE — the served set is not current.

		if ( Meta::STATUS_TOO_LARGE === $status ) {
			return Outcome::tooLarge( $staged['sizesStamped'] );
		}

		return Outcome::failed( $reason, $staged['sizesStamped'] );
	}
}
