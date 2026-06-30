<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * WP-integration facade (SPEC M5: "Hook table").
 *
 * The single entry point {@see Plugin::boot()} calls to wire every WordPress
 * seam that connects the M2-M4 engine/backup/processor + the M6 queue to core's
 * media pipeline. It owns NO logic of its own — it simply delegates to each hook
 * class's own {@see register()} so a reader sees the whole integration surface in
 * one place, and so each seam can be unit-tested in isolation.
 *
 * Every seam registered here is ASYNC-ONLY: not one of them composites a
 * watermark inline inside a web request. The Metadata listener schedules a single
 * deduped reprocess job; the editor bridge substitutes the clean backup as the
 * edit source + queues a reprocess after a user crop/rotate; the srcset filter
 * only hides still-unstamped variants during the brief processing window; and the
 * image-editor preference only reorders editors while OUR own regenerate runs.
 */
final class Integration {

	/**
	 * Register every M5 integration seam. Mirrors how the M6 queue is wired in
	 * {@see \OrchardGrove\OgWatermark\Plugin::boot()} — minimal, side-effect-only.
	 */
	public static function register(): void {
		MetadataListener::register();
		EditorBridge::register();
		Srcset::register();
		ImageEditors::register();
	}
}
