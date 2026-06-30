<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Integration;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Backup\Manager;
use OrchardGrove\OgWatermark\Pipeline\Processor;
use OrchardGrove\OgWatermark\Queue\Scheduler;
use OrchardGrove\OgWatermark\Support\Lock;
use OrchardGrove\OgWatermark\Support\Meta;

/**
 * The crop/rotate EDITOR BRIDGE — the safety-critical limb of Decision #2.
 *
 * Because Decision #2 stamps the on-disk high-res original, that file is NOT a
 * clean source after first apply. The pristine, hash-verified backup is the sole
 * clean master. WP's image editor (Image Editor screen: Crop, Rotate, Flip,
 * Scale, plus "Restore original image") loads its edit source from
 * `wp_get_original_image_path()` / `get_attached_file()` — i.e. the WATERMARKED
 * on-disk original. Left alone, a crop would operate on stamped pixels, WP would
 * write a new clean-looking `-e<timestamp>` edited original from those stamped
 * pixels, and the gated refresh would then promote a WATERMARKED file into the
 * backup. That is the single most dangerous failure in the whole product
 * (SPEC "Top risks"): it bakes a permanent, unremovable watermark into the only
 * master.
 *
 * This class closes that hole with cooperating seams:
 *
 *  1. {@see cleanEditSource()} on `load_image_to_edit_path` — when a flagged
 *     attachment is watermarked AND a VALID backup exists AND the editor is
 *     requesting the FULL-size source, substitute a CLEAN source so the editor
 *     loads pristine pixels:
 *       - small image (attached file IS the original): hand back the backup path
 *         directly — its dimensions match what WP would otherwise edit;
 *       - big image (attached file is the `-scaled` derivative): the backup is the
 *         TRUE full-res original, whose dimensions DIFFER from the `-scaled` file
 *         WP renders into the editor. Handing the full-res backup back would make
 *         the crop selection (computed against `-scaled` dimensions) land on the
 *         wrong region/scale. So we instead derive a CLEAN copy of the backup at
 *         the exact `-scaled` dimensions and hand THAT back — clean pixels at the
 *         geometry WP expects. If we cannot derive it, we DO NOT substitute (and
 *         onAfterEdit correspondingly refuses to refresh, leaving the master safe).
 *     The user crops/rotates clean pixels; WP writes a genuinely clean
 *     `-e<timestamp>` edited original.
 *
 *  2. {@see onAfterEdit()} on `wp_update_attachment_metadata` — after a user
 *     crop/rotate produces a new `-e<digits>` edited original for a flagged
 *     attachment, refresh the backup from that now-clean edited original and
 *     queue a re-stamp. The refresh seeds the master from the EXPLICIT clean
 *     edited file the editor just wrote — `get_attached_file($id)`, the `-e` file —
 *     via {@see Manager::seedFrom()}. It must NEVER seed via Manager::ensureBackup()
 *     here: ensureBackup() re-resolves the source through
 *     `wp_get_original_image_path()`, which for a BIG image still names the OLD,
 *     already-WATERMARKED full-res `original_image` (WP's wp_save_image() never
 *     repoints that key), so ensureBackup() would copy a watermarked file into the
 *     master. After seeding, Scheduler::enqueue($id,'regenerate') re-stamps.
 *
 *     WHY THE EDITED FILE IS CLEAN — and how we KNOW it. cleanEditSource() above
 *     records a per-REQUEST marker the moment it actually substitutes a clean edit
 *     source. WP's wp_save_image() invokes `_load_image_to_edit_path($id,'full')`
 *     (firing cleanEditSource → marker set) and then, at the very end of the SAME
 *     request, `wp_update_attachment_metadata($id,$meta)` (firing onAfterEdit →
 *     marker read). onAfterEdit seeds the master ONLY when the marker is set, which
 *     proves cleanEditSource fed the editor pristine pixels and the `-e` file is
 *     therefore clean. If cleanEditSource DECLINED to substitute (no marker), the
 *     editor may have read watermarked pixels, so we touch NOTHING.
 *
 *  3. {@see onAfterEdit()} also coexists with WP's "Restore original image"
 *     action (SPEC line 173/354). `wp_restore_image()` repoints the attached file
 *     back to the ORIGINAL (non-`-e`) basename and fires
 *     `wp_update_attachment_metadata`. We must NOT seed a new master from the
 *     (possibly watermarked) on-disk file — instead we clear only the served-size
 *     fingerprint (signature + sizes-done + applied time), set status `queued`,
 *     and enqueue a regenerate so the served sizes are rebuilt + re-stamped FROM
 *     the existing pristine backup. The backup_* meta is left untouched.
 *
 * HARD SAFETY RULE (data-loss guard): the master-replacing refresh in (2) is SAFE
 * only because of the substitution in (1). If there is ANY doubt the edited file
 * is clean — specifically if cleanEditSource() did NOT set its per-request
 * clean-substitution marker for this attachment (it declined to substitute, e.g.
 * no valid backup existed, or the big-image clean-scaled copy could not be
 * produced), so the editor may have read watermarked pixels — DO NOT refresh.
 * Leave the existing backup meta untouched. The marker is the precise signal that
 * distinguishes "substituted clean" from "declined": a valid prior backup can
 * exist in BOTH cases (the big-image declined path still verifies), so the old
 * verify()-passes test was not a sufficient guard. Likewise never refresh while
 * the Processor is itself mid-(re)generate, or while the per-attachment lock is
 * held by a worker: our own regenerate is NOT a user edit, and refreshing under
 * it could race a concurrent stamp into the master.
 *
 * SEAM CHOICE — why `wp_update_attachment_metadata` and not the
 * `wp_save_image_editor_file` / `wp_save_image_file` write seam:
 *
 *  - The write seam fires per-file mid-save, BEFORE the attached-file pointer and
 *    `_wp_attachment_metadata` are repointed to the new `-e<timestamp>` original;
 *    reading the attachment's "current original" there would still see the OLD
 *    file, and we would have to reconstruct WP's edit bookkeeping ourselves.
 *  - `wp_update_attachment_metadata` fires ONCE, at the very end of WP's editor
 *    save (`wp_save_image()` → `wp_update_attachment_metadata()`), AFTER the new
 *    edited original is on disk and the attached file has been repointed to it.
 *    The new original's basename is then observable as `-e<13-digit-timestamp>`,
 *    which is the SPEC's exact, reliable signal of a USER crop/rotate. Detecting
 *    that basename pattern (not filesize/mtime — SPEC: "gated, not fingerprinted")
 *    distinguishes a real edit from our own regenerate.
 *  - Crucially, our own Processor regenerate does NOT trip this: it calls
 *    wp_create_image_subsizes()/wp_update_attachment_metadata() while
 *    Processor::isProcessing() is true, and it never produces an `-e<digits>`
 *    edited original — so the isProcessing() gate AND the `-e` basename gate each
 *    independently exclude our own writes. The lock gate is the third belt: a
 *    held per-attachment lock means a worker owns this id right now.
 */
final class EditorBridge {

	/**
	 * Per-REQUEST record of attachments whose edit source cleanEditSource() actually
	 * SUBSTITUTED with clean pixels (small-image backup, or the big-image clean
	 * `-scaled` copy). Keyed by attachment id; the value is unused (presence is the
	 * signal).
	 *
	 * This is the data-loss gate for {@see refreshFromUserEdit()}: WP's
	 * wp_save_image() fires `load_image_to_edit_path` (→ cleanEditSource, which sets
	 * this) and then, at the end of the SAME request, `wp_update_attachment_metadata`
	 * (→ onAfterEdit, which reads this). Static-array lifetime is exactly one PHP
	 * request, which is exactly the scope of one editor save — so a marker set by
	 * cleanEditSource is observable by the onAfterEdit of the same save and by no
	 * other request. A marker is set ONLY when a real substitution happened; a
	 * declined substitution (original returned unchanged) leaves it unset.
	 *
	 * @var array<int,true>
	 */
	private static array $cleanSubstituted = [];

	/**
	 * Register the two cooperating seams.
	 *
	 * `load_image_to_edit_path` (10,3): feed the editor a clean source.
	 * `wp_update_attachment_metadata` (10,2): refresh after a user edit / restore.
	 */
	public static function register(): void {
		add_filter( 'load_image_to_edit_path', [ self::class, 'cleanEditSource' ], 10, 3 );
		add_filter( 'wp_update_attachment_metadata', [ self::class, 'onAfterEdit' ], 10, 2 );
	}

	/**
	 * Substitute a PRISTINE source as the editor's full-size edit source.
	 *
	 * Fires on `load_image_to_edit_path`. When the attachment is already
	 * watermarked AND a valid backup exists AND the editor is requesting the
	 * full-size source, return a clean source so the crop/rotate operates on clean
	 * pixels and can never promote a watermarked file into the master:
	 *
	 *  - small image  → the backup path directly (its dimensions are the ones WP
	 *                   edits);
	 *  - big image    → a clean copy of the backup resized to the `-scaled`
	 *                   dimensions WP actually edits (matching geometry), or no
	 *                   substitution if that copy cannot be made.
	 *
	 * Otherwise the on-disk file is returned unchanged:
	 *  - not watermarked yet → the on-disk file is already clean, no substitution;
	 *  - no valid backup → there is nothing safe to substitute (and onAfterEdit
	 *    will correspondingly refuse to refresh);
	 *  - not the full-size request → intermediate sizes are never the edit source.
	 *
	 * On — and ONLY on — an actual substitution, this records a per-request marker
	 * ({@see markCleanSubstituted()}) so {@see refreshFromUserEdit()} in the SAME
	 * request KNOWS the resulting `-e` edited file was produced from clean pixels and
	 * may safely seed the master from it. A declined substitution (any path that
	 * returns $filepath unchanged) deliberately leaves the marker unset.
	 *
	 * @param string     $filepath     The path WP would load for editing.
	 * @param int        $attachmentId Attachment ID.
	 * @param string|int $size         Requested size ('full' for the editable source).
	 * @return string The path to load — a clean source, or the original unchanged.
	 */
	public static function cleanEditSource( string $filepath, int $attachmentId, mixed $size ): string {
		if ( $attachmentId <= 0 ) {
			return $filepath;
		}

		// Only the full-size request is the editable source. The image editor loads
		// 'full' to crop/rotate; intermediate sizes are derived, never edited.
		if ( ! self::isFullSizeRequest( $size ) ) {
			return $filepath;
		}

		// The on-disk file is only watermarked once status is watermarked; before
		// that it is still the pristine original and needs no substitution.
		$status = (string) get_post_meta( $attachmentId, Meta::STATUS, true );
		if ( Meta::STATUS_WATERMARKED !== $status ) {
			return $filepath;
		}

		// Only substitute a VERIFIED master (existing file, sha1 === stored hash).
		// A missing/corrupt backup means we have no clean source to offer — leave
		// the original path so the editor at least functions, and onAfterEdit will
		// (correctly) refuse to refresh from the resulting edit.
		if ( ! Manager::verify( $attachmentId ) ) {
			return $filepath;
		}

		$backup = Manager::backupPath( $attachmentId );
		if ( null === $backup || '' === $backup ) {
			return $filepath;
		}

		// BIG-IMAGE GEOMETRY GUARD. For a big image (>2560px) the attached file WP
		// edits is the `-scaled` derivative, NOT the true original the backup mirrors.
		// The crop selection is computed against the `-scaled` dimensions, so handing
		// back the full-res backup would apply the crop to the wrong region/scale and
		// corrupt the edited original. Derive a clean copy at the `-scaled`
		// dimensions instead; if that fails, do NOT substitute (and let onAfterEdit
		// refuse the master-replacing refresh — the existing master stays safe).
		if ( self::isBigImage( $attachmentId, $filepath ) ) {
			$scaledClean = self::cleanScaledSource( $attachmentId, $backup, $filepath );
			if ( null === $scaledClean ) {
				// Declined: no clean-scaled copy could be produced. Do NOT mark — the
				// editor reads the watermarked -scaled file and onAfterEdit must refuse.
				return $filepath;
			}

			// Substituted a CLEAN big-image source at the -scaled geometry.
			self::markCleanSubstituted( $attachmentId );
			return $scaledClean;
		}

		// Substituted the CLEAN small-image backup directly.
		self::markCleanSubstituted( $attachmentId );
		return $backup;
	}

	/**
	 * After a USER crop/rotate (or a WP "Restore original image"), refresh state and
	 * queue a re-stamp. Always returns $meta unchanged (this is a filter).
	 *
	 * Fires on `wp_update_attachment_metadata`. Two distinct transitions are
	 * handled, both gated so they NEVER fire for our own regenerate or under a
	 * concurrent worker:
	 *
	 *  - USER CROP/ROTATE — the attached-file basename now matches
	 *    `-e<13-digit-timestamp>` (WP's edited-original naming). The edited file is
	 *    clean ONLY because cleanEditSource() fed the editor a clean source THIS
	 *    request, recorded by the per-request clean-substitution marker; that marker
	 *    is the data-loss guard. We seed a FRESH master from the clean edited original
	 *    (the `-e` file at get_attached_file()) via Manager::seedFrom().
	 *
	 *  - WP RESTORE — the attached file is the ordinary (non-`-e`) original but the
	 *    attachment is still flagged + watermarked with a valid backup. WP just
	 *    repointed the served original back. We must NOT seed a master from the
	 *    (possibly watermarked) on-disk file; instead we clear only the served-size
	 *    fingerprint and enqueue a regenerate so served sizes are rebuilt + re-
	 *    stamped from the EXISTING pristine backup. The backup_* meta is untouched.
	 *
	 * @param mixed $meta         The attachment metadata being saved.
	 * @param int   $attachmentId Attachment ID.
	 * @return mixed $meta, ALWAYS unchanged.
	 */
	public static function onAfterEdit( mixed $meta, int $attachmentId ): mixed {
		// 0) Never our own regenerate. The Processor sets this true across its whole
		//    restore→regenerate→stamp run; wp_update_attachment_metadata() is called
		//    inside it, so this is the primary exclusion of our own writes.
		if ( Processor::isProcessing() ) {
			return $meta;
		}

		if ( $attachmentId <= 0 ) {
			return $meta;
		}

		// 1) Only flagged attachments participate. A non-flagged image is none of our
		//    business (its on-disk original was never stamped).
		if ( '1' !== (string) get_post_meta( $attachmentId, Meta::ENABLED, true ) ) {
			return $meta;
		}

		// 2) Never refresh while a worker owns this attachment's lock — our own
		//    regenerate (or a concurrent reprocess) is in flight; refreshing under it
		//    could race a stamp into the master. isProcessing() covers the same-request
		//    case; the lock covers a cross-process worker. The key is derived from the
		//    Processor's single source of truth, never a hand-copied literal.
		if ( Lock::held( Processor::lockKey( $attachmentId ) ) ) {
			return $meta;
		}

		$attached = get_attached_file( $attachmentId );
		$attached = is_string( $attached ) ? $attached : '';

		// 3) A USER crop/rotate is signalled by the attached-file basename now being a
		//    `-e<13-digit-timestamp>` edited original. Filesize/mtime are deliberately
		//    NOT used (SPEC: "gated, not fingerprinted").
		if ( '' !== $attached && self::isEditedOriginal( $attached ) ) {
			self::refreshFromUserEdit( $attachmentId );
			return $meta;
		}

		// 4) Otherwise, a non-`-e` attached file on a still-watermarked attachment
		//    with a valid backup is WP's "Restore original image" repoint. Rebuild
		//    served sizes from the existing pristine backup WITHOUT seeding a new
		//    master from the on-disk file.
		if ( Meta::STATUS_WATERMARKED === (string) get_post_meta( $attachmentId, Meta::STATUS, true )
			&& Manager::verify( $attachmentId ) ) {
			self::refreshFromRestore( $attachmentId );
		}

		return $meta;
	}

	// ---------------------------------------------------------------------
	// Refresh transitions.
	// ---------------------------------------------------------------------

	/**
	 * USER crop/rotate refresh: seed a FRESH master from the CLEAN edited original
	 * and queue a re-stamp.
	 *
	 * HARD DATA-LOSS GUARD: the refresh proceeds ONLY when cleanEditSource() set its
	 * per-request clean-substitution marker for this attachment THIS request — proof
	 * it fed the editor pristine pixels, so the `-e` edited file is clean. If the
	 * marker is unset (cleanEditSource declined: no valid backup, or the big-image
	 * clean-scaled copy could not be made), the editor may have read WATERMARKED
	 * pixels: we touch the master NOT AT ALL and do NOT reprocess, recording a
	 * skip note instead. Conservative: zero chance of poisoning the master.
	 *
	 * VISIBILITY CAVEAT (the conservative skip can hide a DESYNC). The marker rests
	 * on cleanEditSource() firing in the SAME request as onAfterEdit, which holds for
	 * the standard wp-admin image-editor flow (wp_save_image() →
	 * _load_image_to_edit_path($id,'full') → load_image_to_edit_path filter). A
	 * storage/offload or CDN plugin that short-circuits _load_image_to_edit_path
	 * (streamed/temp source, or feeds the editor bytes another way) can suppress the
	 * filter for this id on a genuinely CLEAN edit → marker unset → we skip. That is
	 * still SAFE (the master is never poisoned), but the served `-e` crop is left
	 * un-re-stamped and the verified master no longer matches it — the attachment
	 * silently DESYNCS. So when the skipped attached file IS an `-e` edited original
	 * AND a valid prior backup still verifies, we record a DISTINCT, operator-facing
	 * note (still prefixed `edit-refresh-skipped`) flagging the stale-master desync
	 * for a manual reprocess — rather than the generic "no clean source" note.
	 *
	 * On the proceed path we seed STRICTLY from the explicit clean edited file
	 * (`get_attached_file()` — the `-e` original). We must NOT use
	 * Manager::ensureBackup() here: for a big image it re-resolves the source via
	 * wp_get_original_image_path(), which still names the OLD, already-WATERMARKED
	 * full-res `original_image` (wp_save_image() never repoints that key), and would
	 * copy a watermarked file into the master. Manager::seedFrom() seeds only from
	 * the path we hand it.
	 */
	private static function refreshFromUserEdit( int $attachmentId ): void {
		// THE data-loss gate. Without a clean substitution this request, we cannot
		// prove the edited file is clean — leave the existing master fully intact.
		if ( ! self::wasCleanSubstituted( $attachmentId ) ) {
			update_post_meta(
				$attachmentId,
				Meta::LAST_ERROR,
				self::skipNote( $attachmentId )
			);
			return;
		}

		// The explicit CLEAN source: the `-e<timestamp>` edited file WP just wrote and
		// repointed the attached file to. Seed STRICTLY from this — never the resolver.
		$cleanPath = get_attached_file( $attachmentId );
		if ( ! is_string( $cleanPath ) || '' === $cleanPath ) {
			update_post_meta(
				$attachmentId,
				Meta::LAST_ERROR,
				'edit-refresh-skipped: the edited file path could not be resolved; the pristine master was left untouched.'
			);
			return;
		}

		// Clear the watermark fingerprint so the queued re-stamp rebuilds the served
		// set against the new master.
		delete_post_meta( $attachmentId, Meta::SIGNATURE );
		delete_post_meta( $attachmentId, Meta::SIZES_DONE );
		delete_post_meta( $attachmentId, Meta::APPLIED_GMT );

		// Delete the SUPERSEDED prior master (file + BACKUP_REL/HASH/BYTES meta) so it
		// is not orphaned on disk — the edited original gets its own fresh backup.
		Manager::deleteBackup( $attachmentId );

		update_post_meta( $attachmentId, Meta::STATUS, Meta::STATUS_NONE );

		// Seed the fresh master from the EXPLICIT clean edited original. seedFrom()
		// bypasses wp_get_original_image_path() entirely, so a big image's stale
		// watermarked full-res original can never be promoted into the master.
		Manager::seedFrom( $attachmentId, $cleanPath );

		// Re-stamp all sizes asynchronously (deduped, threaded via the Scheduler).
		Scheduler::enqueue( $attachmentId, 'regenerate' );
	}

	/**
	 * The operator-facing note recorded when refreshFromUserEdit() DECLINES (no
	 * clean-substitution marker this request). Both variants are prefixed
	 * `edit-refresh-skipped` so existing skip-detection stays stable.
	 *
	 * Two cases:
	 *  - DESYNC: the attached file is an `-e` edited original AND a prior backup still
	 *    verifies, yet the marker was missing. This is the offload/CDN short-circuit
	 *    of `load_image_to_edit_path` on a genuinely clean edit (see the docblock's
	 *    "VISIBILITY CAVEAT"): the served crop is clean but the master is now stale vs.
	 *    it. We surface that explicitly so an operator can trigger a manual reprocess.
	 *  - GENERIC: anything else (no `-e` file / no verifying backup) — the ordinary
	 *    "the edited file is not provably clean, master left untouched" decline.
	 */
	private static function skipNote( int $attachmentId ): string {
		$attached = get_attached_file( $attachmentId );
		$attached = is_string( $attached ) ? $attached : '';

		if ( '' !== $attached && self::isEditedOriginal( $attached ) && Manager::verify( $attachmentId ) ) {
			return 'edit-refresh-skipped: a clean edit was detected but no clean edit source was substituted this request '
				. '(commonly an offload/CDN plugin short-circuiting load_image_to_edit_path). The pristine master was left '
				. 'UNTOUCHED and is therefore now STALE relative to the edited image — reprocess this attachment manually to '
				. 're-stamp the served sizes and reseed the master from the edited original.';
		}

		return 'edit-refresh-skipped: no clean edit source was substituted this request, so the edited file is not provably clean; the pristine master was left untouched.';
	}

	/**
	 * WP "Restore original image" coexistence: rebuild served sizes from the EXISTING
	 * pristine backup. We must NOT seed a new master from the on-disk file (WP's
	 * full-orig may be the watermarked original captured at first edit), so the
	 * backup_* meta is deliberately left untouched.
	 *
	 * We clear only the served-size fingerprint (signature + sizes-done + applied
	 * time) and set status `queued`, then enqueue a regenerate. The Processor then
	 * restores from the backup and re-stamps, the same idempotent
	 * regenerate-from-backup path every reprocess uses.
	 *
	 * COST OF A FALSE POSITIVE: an innocuous `wp_update_attachment_metadata` on a
	 * watermarked attachment (not an actual restore) would also reach here and queue
	 * one regenerate-from-backup. That job is idempotent — it rebuilds pixel-
	 * identical files from the same master — so the only cost is a single redundant
	 * background job. It can NEVER touch or replace the master, which is the property
	 * that matters.
	 */
	private static function refreshFromRestore( int $attachmentId ): void {
		delete_post_meta( $attachmentId, Meta::SIGNATURE );
		delete_post_meta( $attachmentId, Meta::SIZES_DONE );
		delete_post_meta( $attachmentId, Meta::APPLIED_GMT );
		update_post_meta( $attachmentId, Meta::STATUS, Meta::STATUS_QUEUED );

		Scheduler::enqueue( $attachmentId, 'regenerate' );
	}

	// ---------------------------------------------------------------------
	// Big-image clean-scaled source derivation.
	// ---------------------------------------------------------------------

	/**
	 * Whether $attachmentId is a big image whose attached edit source is the
	 * `-scaled` derivative rather than the true original. Detected by the true
	 * original path (resolved by wp_get_original_image_path) differing from the
	 * attached/edited file — i.e. core kept a separate `original_image`.
	 *
	 * @param int    $attachmentId Attachment ID.
	 * @param string $editFilepath The path WP is about to edit (the attached file).
	 */
	private static function isBigImage( int $attachmentId, string $editFilepath ): bool {
		$original = wp_get_original_image_path( $attachmentId, true );
		if ( ! is_string( $original ) || '' === $original ) {
			return false;
		}

		return self::basename( $original ) !== self::basename( $editFilepath );
	}

	/**
	 * Derive a CLEAN copy of the backup at the exact dimensions WP will edit (the
	 * `-scaled` file's dimensions), written to a temp file that is cleaned up at end
	 * of request. Returns the temp path, or null when it cannot be produced (in which
	 * case the caller must NOT substitute, so the master stays safe).
	 *
	 * The `-scaled` dimensions are the attachment metadata's top-level width/height
	 * (which describe the attached `-scaled` file for big images). We load the
	 * pristine full-res backup through WP's image editor, resize to those dimensions,
	 * and save to a unique temp path. The resulting image has clean pixels at the
	 * geometry the editor's crop selection is computed against.
	 *
	 * @param int    $attachmentId Attachment ID.
	 * @param string $backupPath   Verified full-res pristine backup path.
	 * @param string $editFilepath The `-scaled` file WP would otherwise edit.
	 * @return string|null Temp clean-scaled path, or null when it cannot be derived.
	 */
	private static function cleanScaledSource( int $attachmentId, string $backupPath, string $editFilepath ): ?string {
		$dims = self::scaledDimensions( $attachmentId );
		if ( null === $dims ) {
			return null;
		}

		$editor = wp_get_image_editor( $backupPath );
		if ( ! is_object( $editor ) || ( function_exists( 'is_wp_error' ) && is_wp_error( $editor ) ) ) {
			return null;
		}

		// Hard down-scale to the EXACT `-scaled` dimensions ($crop = false keeps the
		// aspect ratio; for a true `-scaled` derivative the target IS the aspect-true
		// scale of the original, so width/height already agree).
		$resized = $editor->resize( $dims['width'], $dims['height'], false );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $resized ) ) {
			return null;
		}

		$temp = self::tempScaledPath( $editFilepath );
		if ( null === $temp ) {
			return null;
		}

		$saved = $editor->save( $temp );
		if ( ! is_array( $saved ) || empty( $saved['path'] ) || ! is_string( $saved['path'] ) ) {
			return null;
		}

		// Clean up the temp clean-scaled file at end of request — WP has already read
		// its pixels into the editor by then.
		self::scheduleTempCleanup( $saved['path'] );

		return $saved['path'];
	}

	/**
	 * The `-scaled` (attached) file dimensions for a big image, read from the
	 * attachment metadata top-level width/height. Returns null when unavailable so
	 * the caller refuses to substitute.
	 *
	 * @param int $attachmentId Attachment ID.
	 * @return array{width:int,height:int}|null
	 */
	private static function scaledDimensions( int $attachmentId ): ?array {
		$meta = wp_get_attachment_metadata( $attachmentId );
		if ( ! is_array( $meta ) ) {
			return null;
		}

		$w = isset( $meta['width'] ) && is_scalar( $meta['width'] ) ? (int) $meta['width'] : 0;
		$h = isset( $meta['height'] ) && is_scalar( $meta['height'] ) ? (int) $meta['height'] : 0;

		if ( $w <= 0 || $h <= 0 ) {
			return null;
		}

		return [
			'width'  => $w,
			'height' => $h,
		];
	}

	/**
	 * A unique temp path for the clean-scaled edit source, beside the file WP would
	 * have edited (same directory, so the editor's save stays within the uploads
	 * tree), suffixed so it can never collide with a real served file.
	 *
	 * @param string $editFilepath The `-scaled` file WP would otherwise edit.
	 * @return string|null
	 */
	private static function tempScaledPath( string $editFilepath ): ?string {
		$dir = dirname( $editFilepath );
		if ( '' === $dir || '.' === $dir ) {
			return null;
		}

		$ext  = pathinfo( $editFilepath, PATHINFO_EXTENSION );
		$ext  = '' === $ext ? 'tmp' : $ext;
		$base = pathinfo( $editFilepath, PATHINFO_FILENAME );
		$token = self::uniqueToken();

		return $dir . '/' . $base . '.ogwm-editsrc-' . $token . '.' . $ext;
	}

	/** Register a one-shot end-of-request removal of a temp clean-scaled file. */
	private static function scheduleTempCleanup( string $path ): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action(
			'shutdown',
			static function () use ( $path ): void {
				if ( is_file( $path ) ) {
					@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				}
			}
		);
	}

	/** Unguessable token for the temp clean-scaled filename. */
	private static function uniqueToken(): string {
		if ( function_exists( 'wp_generate_password' ) ) {
			return (string) wp_generate_password( 12, false, false );
		}

		return bin2hex( random_bytes( 6 ) );
	}

	// ---------------------------------------------------------------------
	// Per-request clean-substitution marker.
	// ---------------------------------------------------------------------

	/**
	 * Record that cleanEditSource() substituted a CLEAN edit source for $attachmentId
	 * this request — the data-loss gate refreshFromUserEdit() requires before it may
	 * seed the master from the resulting `-e` edited file.
	 */
	private static function markCleanSubstituted( int $attachmentId ): void {
		self::$cleanSubstituted[ $attachmentId ] = true;
	}

	/**
	 * Whether cleanEditSource() substituted a clean edit source for $attachmentId
	 * this request. Reading consumes the marker so a single substitution authorizes
	 * exactly one refresh (a subsequent metadata write in the same request — e.g. our
	 * own queued regenerate, were it to run inline — cannot reuse a stale grant).
	 */
	private static function wasCleanSubstituted( int $attachmentId ): bool {
		if ( ! isset( self::$cleanSubstituted[ $attachmentId ] ) ) {
			return false;
		}

		unset( self::$cleanSubstituted[ $attachmentId ] );
		return true;
	}

	// ---------------------------------------------------------------------
	// Internal helpers.
	// ---------------------------------------------------------------------

	/**
	 * Whether the editor's size request is the full-size editable source.
	 *
	 * The image editor passes 'full' for the source it crops/rotates; some callers
	 * pass false/null/'' meaning "the original/full file". Any concrete intermediate
	 * size slug (e.g. 'thumbnail', 'medium') is NOT the edit source.
	 *
	 * @param mixed $size The size argument from load_image_to_edit_path.
	 */
	private static function isFullSizeRequest( mixed $size ): bool {
		if ( 'full' === $size ) {
			return true;
		}

		// A falsy/empty size means "the full/original file" to WP's loader.
		return false === $size || null === $size || '' === $size;
	}

	/**
	 * Whether a path's basename is a WP edited original: `<name>-e<13 digits>.<ext>`.
	 *
	 * WordPress names every editor save-as-new/in-place original `-e` followed by a
	 * 13-digit millisecond timestamp (e.g. `photo-e1700000000000.jpg`). This is the
	 * SPEC's exact, reliable user-edit signal and is NOT something our own regenerate
	 * ever produces.
	 *
	 * @param string $path Absolute or relative file path.
	 */
	private static function isEditedOriginal( string $path ): bool {
		$basename = basename( $path );

		return 1 === preg_match( '/-e\d{13}\.[A-Za-z0-9]+$/', $basename );
	}

	/** Basename of a file path (forward-slash normalized). */
	private static function basename( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$pos  = strrpos( $path, '/' );
		return false === $pos ? $path : substr( $path, $pos + 1 );
	}
}
