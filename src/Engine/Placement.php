<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, driver-agnostic geometry for a single watermark paint.
 *
 * Computed once by Geometry from the image dimensions + settings (no GD/Imagick
 * involved) and handed to whichever driver is active. All coordinates are
 * absolute pixels in the already-oriented image's coordinate space: ($x,$y) is
 * the top-left corner of the mark and ($width,$height) its target box.
 */
final class Placement {

	public function __construct(
		public readonly int $x,
		public readonly int $y,
		public readonly int $width,
		public readonly int $height,
		public readonly int $opacity
	) {}
}
