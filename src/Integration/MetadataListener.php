<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Integration;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Pipeline\Processor;
use OrchardGrove\OgWatermark\Queue\Scheduler;
use OrchardGrove\OgWatermark\Support\Meta;

/**
 * The wp_generate_attachment_metadata coexistence listener (SPEC M5 + the
 * "Coexistence with third-party regenerate / WP-CLI" subsection).
 *
 * When core, "Regenerate Thumbnails", or `wp media regenerate` rebuild an
 * attachment's served sizes, they fire wp_generate_attachment_metadata. For a
 * FLAGGED attachment those freshly-rebuilt sizes are clean (un-watermarked), so we
 * must re-stamp — but NEVER inline inside that foreign loop. This listener
 * therefore schedules exactly ONE deduped async ogwm_process_one job in the
 * 'regenerate' context and ALWAYS returns the metadata unchanged.
 *
 * Two hard rules make this safe:
 *
 *  1. ASYNC ONLY. We never composite here; the metadata is returned verbatim so a
 *     foreign regenerate completes with clean files, and the actual stamping
 *     happens later in the background job. The Scheduler dedups by attachment id,
 *     so a thundering herd of regenerate triggers collapses to one job.
 *
 *  2. NO RECURSION INTO OUR OWN REGENERATE. While {@see Processor::isProcessing()}
 *     is true we are mid-(re)generate ourselves; a FOREIGN caller of this filter
 *     re-entering us must do nothing (the Processor already owns the rebuild). We
 *     bail at the very top before reading any meta. (Core's own
 *     wp_create_image_subsizes() — the only core call our regenerate makes — does
 *     NOT fire this filter, so this guard is purely defensive against a re-entrant
 *     outside caller, never against our own call graph.)
 *
 * A brand-new upload is NOT yet flagged, so the ENABLED check below makes this a
 * no-op on upload — nothing is scheduled until an attachment is explicitly opted in.
 */
final class MetadataListener {

	/**
	 * Hook the listener at priority 9999 so it runs AFTER core (and virtually any
	 * third-party regenerate plugin) has finished writing the rebuilt size set —
	 * we only ever read the final metadata and schedule, never mutate.
	 */
	public static function register(): void {
		add_filter( 'wp_generate_attachment_metadata', [ self::class, 'onGenerate' ], 9999, 3 );
	}

	/**
	 * Schedule ONE async reprocess for a flagged attachment whose sizes were just
	 * rebuilt by a foreign regenerate. ALWAYS returns $meta unchanged (we never
	 * stamp inline here).
	 *
	 * The signature is intentionally loose: $meta is typed `mixed` and returned
	 * verbatim, because this runs at priority 9999 — AFTER every other
	 * wp_generate_attachment_metadata filter. A hostile/buggy co-plugin that returns
	 * a non-array would otherwise trip a TypeError at the hook boundary and break the
	 * entire metadata save. We never read $meta, so we pass whatever we are handed
	 * straight back. (Mirrors EditorBridge::onAfterEdit's defensive `mixed`.)
	 *
	 * @param mixed  $meta         The attachment metadata core/3rd-party just built.
	 * @param int    $attachmentId Attachment ID being (re)generated.
	 * @param string $context      'create' on initial upload, 'update' on regenerate.
	 * @return mixed The metadata, returned verbatim.
	 */
	public static function onGenerate( mixed $meta, int $attachmentId, string $context = 'create' ): mixed {
		unset( $context );

		// 0) Defensive: an earlier filter (or a failed core build) may hand us a
		//    non-array. We never read $meta, so pass it straight back before doing any
		//    work — a non-array means there is no clean rebuilt size set to re-stamp
		//    anyway. This avoids a TypeError at the 9999 hook boundary.
		if ( ! is_array( $meta ) ) {
			return $meta;
		}

		// 1) Our own (re)generate is in flight — do nothing, no recursion. The
		//    Processor already rebuilds + re-stamps from the pristine backup; a
		//    re-enqueue here would be a redundant (deduped, but pointless) job and
		//    blurs the "we never react to our own writes" invariant.
		if ( Processor::isProcessing() ) {
			return $meta;
		}

		// 2) Only react for an opted-in attachment. A brand-new upload is not yet
		//    flagged, so this is a no-op on upload; a foreign regenerate of a
		//    non-flagged attachment is likewise ignored.
		if ( self::isFlagged( $attachmentId ) ) {
			// Async only: schedule a single deduped reprocess; the Scheduler collapses
			// a herd of regenerate triggers for this id to one job.
			Scheduler::enqueue( $attachmentId, 'regenerate' );
		}

		// 3) ALWAYS return the metadata unchanged — we never stamp inline here.
		return $meta;
	}

	/** Whether the attachment carries the opt-in flag (stored as the string '1'). */
	private static function isFlagged( int $attachmentId ): bool {
		return '1' === (string) get_post_meta( $attachmentId, Meta::ENABLED, true );
	}
}
