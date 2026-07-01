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

	/** wp-cron hook for the bounded reprocess catch-up drain. */
	public const DRAIN_HOOK = 'ogwm_reprocess_drain';

	/**
	 * Max reprocess jobs enqueued IMMEDIATELY per PHP process before a foreign mass
	 * regenerate is deferred to the drain. A normal edit re-stamps one image at once;
	 * only a `wp media regenerate --all`-style burst (thousands in one process) crosses
	 * this and is paced instead of flooding cron / Action Scheduler.
	 */
	private const BURST_CAP = 100;

	/** Attachments enqueued per drain pass (the catch-up batch size). */
	private const DRAIN_BATCH = 100;

	/** Seconds between drain passes — paces the backlog rather than firing it at once. */
	private const DRAIN_DELAY = 30;

	/** Immediate reprocess enqueues so far in THIS process (the mass-regenerate guard). */
	private static int $burst = 0;

	/**
	 * Hook the listener at priority 9999 so it runs AFTER core (and virtually any
	 * third-party regenerate plugin) has finished writing the rebuilt size set —
	 * we only ever read the final metadata and schedule, never mutate. Also wires the
	 * bounded catch-up drain (registered unconditionally so wp-cron can fire it).
	 */
	public static function register(): void {
		add_filter( 'wp_generate_attachment_metadata', [ self::class, 'onGenerate' ], 9999, 3 );
		add_action( self::DRAIN_HOOK, [ self::class, 'drain' ] );
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
			// Async only: schedule a single deduped reprocess; the Scheduler collapses a
			// herd of regenerate triggers for THIS id to one job. But a FOREIGN mass
			// regenerate (`wp media regenerate --all`) fires this filter for thousands of
			// DISTINCT flagged attachments in one process — enqueuing each immediately
			// would flood cron / Action Scheduler and bypass the Bulk Runner's pacing. So
			// we cap the immediate enqueues per process; past the cap we mark the
			// attachment for a bounded, self-draining catch-up ({@see drain()}).
			if ( self::$burst < self::BURST_CAP ) {
				++self::$burst;
				Scheduler::enqueue( $attachmentId, 'regenerate' );
			} else {
				update_post_meta( $attachmentId, Meta::REPROCESS, '1' );
				self::scheduleDrain();
			}
		}

		// 3) ALWAYS return the metadata unchanged — we never stamp inline here.
		return $meta;
	}

	/**
	 * Bounded, self-draining catch-up for the reprocess markers a mass regenerate
	 * left behind. Enqueues up to DRAIN_BATCH flagged-dirty attachments per pass
	 * (each still deduped by the Scheduler), clears their marker, and reschedules
	 * itself while any remain — so a huge backlog drains at a controlled rate instead
	 * of all at once. Fired off-request by wp-cron ({@see DRAIN_HOOK}).
	 */
	public static function drain(): void {
		if ( ! function_exists( 'get_posts' ) ) {
			return;
		}

		$ids = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'fields'           => 'ids',
				'posts_per_page'   => self::DRAIN_BATCH,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => Meta::REPROCESS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded by posts_per_page; postmeta is indexed on meta_key.
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		if ( ! is_array( $ids ) || [] === $ids ) {
			return;
		}

		foreach ( $ids as $id ) {
			$id = (int) $id;
			// Clear the catch-up marker ONLY when a job actually exists for this id —
			// either we just scheduled it, or one is already pending (deduped). If the
			// enqueue was REFUSED with no pending job (scheduling short-circuited), leave
			// the marker so the next drain pass retries it rather than stranding it.
			if ( Scheduler::enqueue( $id, 'regenerate' ) || Scheduler::isPending( $id ) ) {
				delete_post_meta( $id, Meta::REPROCESS );
			}
		}

		// A full page likely means more remain — pace the next batch.
		if ( count( $ids ) >= self::DRAIN_BATCH ) {
			self::scheduleDrain();
		}
	}

	/** Schedule a single near-future drain pass, unless one is already queued. */
	private static function scheduleDrain(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( wp_next_scheduled( self::DRAIN_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + self::DRAIN_DELAY, self::DRAIN_HOOK );
	}

	/** Whether the attachment carries the opt-in flag (stored as the string '1'). */
	private static function isFlagged( int $attachmentId ): bool {
		return '1' === (string) get_post_meta( $attachmentId, Meta::ENABLED, true );
	}
}
