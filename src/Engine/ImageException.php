<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by a Watermarker driver when a raster operation fails irrecoverably
 * (bad decode, unwritable destination, a driver call that errored mid-paint).
 *
 * The Compositor catches this per-file and converts it into a failed Result so
 * a single bad image never fatals a bulk run.
 */
final class ImageException extends \RuntimeException {}
