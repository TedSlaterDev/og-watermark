<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Integration;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Pipeline\Processor;

/**
 * Imagick-preference seam for the clean-resize step (SPEC M5 "Hook table":
 * wp_image_editors @ PHP_INT_MAX — "Prefer Imagick … removed after job").
 *
 * Some managed hosts (e.g. SiteGround / WordKeeper) force WP_Image_Editor_GD to
 * the front of the editor list. GD's resampling is lower fidelity than Imagick's,
 * and our pipeline rebuilds every served size from the pristine backup before
 * stamping — so the resize quality matters for the un-watermarked pixels too. When
 * Imagick is available we want it preferred for THAT rebuild.
 *
 * We deliberately scope this to OUR OWN regenerate window only: the filter
 * reorders editors solely while {@see Processor::isProcessing()} is true. Outside
 * that window it returns the editor list untouched, so a host's deliberate editor
 * choice for every other image operation (and any front-end request) is left
 * exactly as configured. Registered at PHP_INT_MAX so our reordering wins over a
 * host filter that pins GD first.
 */
final class ImageEditors {

	/** The core Imagick editor class moved to the front during our regenerate. */
	private const IMAGICK = 'WP_Image_Editor_Imagick';

	/**
	 * Hook at PHP_INT_MAX so our reordering runs LAST and therefore wins over a
	 * host/plugin filter that forces GD to the front.
	 */
	public static function register(): void {
		add_filter( 'wp_image_editors', [ self::class, 'prefer' ], PHP_INT_MAX );
	}

	/**
	 * Move Imagick to the front of the editor list — but ONLY while our own
	 * regenerate is running. Returns the list unchanged otherwise, and is a no-op
	 * when Imagick is not even in the list.
	 *
	 * @param array $editors Ordered list of WP_Image_Editor_* class names.
	 * @return array The (possibly Imagick-first) editor list.
	 */
	public static function prefer( array $editors ): array {
		// Only intervene during OUR regenerate; never touch a host's editor choice
		// for any other operation or request.
		if ( ! Processor::isProcessing() ) {
			return $editors;
		}

		// Find Imagick's current position. If it is absent (Imagick not installed on
		// this host) there is nothing to prefer — leave the list as-is.
		$index = array_search( self::IMAGICK, $editors, true );
		if ( false === $index ) {
			return $editors;
		}

		// Pull Imagick out and unshift it to the front, preserving the relative order
		// of the remaining editors (GD et al. stay as the fallback chain).
		unset( $editors[ $index ] );
		array_unshift( $editors, self::IMAGICK );

		return array_values( $editors );
	}
}
