<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable description of a text watermark, resolved to concrete render inputs.
 *
 * Tokens are already substituted into $text, the bundled font path is resolved,
 * and the point size is computed (see Geometry::pointSize). The treatment is one
 * of 'stroke' | 'shadow' | 'none'; $strokeWidth applies to the stroke pass and
 * $shadowDx/$shadowDy to the shadow pass — drivers consume whichever the chosen
 * treatment uses and ignore the rest.
 */
final class TextSpec {

	public function __construct(
		public readonly string $text,
		public readonly string $fontPath,
		public readonly float $sizePt,
		public readonly string $color,
		public readonly string $treatment,
		public readonly string $treatmentColor,
		public readonly int $strokeWidth,
		public readonly int $shadowDx,
		public readonly int $shadowDy
	) {}
}
