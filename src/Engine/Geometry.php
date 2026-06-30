<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Pure placement / scale / margin / 9-point math — the single source of truth
 * for WHERE and HOW BIG a watermark lands. Deliberately free of any GD/Imagick
 * or WordPress call so the rules are exhaustively unit-testable without an image
 * library or a WP install.
 *
 * The grouped $settings array is the same shape Options produces (see
 * Settings\Options::defaults). Callers pass already-measured intrinsic sizes:
 * for a logo, its native PNG dimensions; for text, the bounding box the driver
 * measured for the resolved string at the chosen point size.
 */
final class Geometry {

	/** Smallest point size auto-sizing will produce (keeps text legible). */
	public const MIN_PT = 8.0;

	/** Largest point size auto-sizing will produce (keeps huge images sane). */
	public const MAX_PT = 240.0;

	/**
	 * Below this fraction of the image, a mark covering more than 90% of the
	 * width is treated as "too big to place" and the size is skipped.
	 */
	private const MAX_WIDTH_FRACTION = 0.9;

	/**
	 * Geometry for a logo watermark, or null to skip this image size.
	 *
	 * Scales the logo to a target width (% of image width, clamped to the px
	 * range), preserves aspect for the height, then positions it via the 9-point
	 * grid with a margin measured off the SHORTER edge. Returns null when the
	 * image is below the configured minimum dimension or the scaled logo would
	 * be wider than 90% of the image.
	 *
	 * @param array<string,mixed> $settings Grouped settings (Options shape).
	 */
	public static function logoPlacement( int $imgW, int $imgH, int $logoW, int $logoH, array $settings ): ?Placement {
		if ( $imgW < 1 || $imgH < 1 || $logoW < 1 || $logoH < 1 ) {
			return null;
		}

		$minDimension = self::intSetting( $settings, [ 'sizes', 'min_dimension_px' ], 0 );
		if ( min( $imgW, $imgH ) < $minDimension ) {
			return null;
		}

		$scalePct = self::intSetting( $settings, [ 'sizing', 'scale_pct' ], 18 );
		$minPx    = self::intSetting( $settings, [ 'sizing', 'min_px' ], 0 );
		$maxPx    = self::intSetting( $settings, [ 'sizing', 'max_px' ], $imgW );

		$targetW = self::clampInt( (int) round( $imgW * $scalePct / 100 ), $minPx, $maxPx );
		$targetW = max( 1, $targetW );

		// Skip when the mark would dominate the image (too big to be a watermark).
		if ( $targetW > $imgW * self::MAX_WIDTH_FRACTION ) {
			return null;
		}

		$targetH = max( 1, (int) round( $logoH * $targetW / $logoW ) );

		return self::place( $imgW, $imgH, $targetW, $targetH, $settings );
	}

	/**
	 * Geometry for a text watermark, or null to skip this image size.
	 *
	 * Unlike a logo, text is NOT rescaled here — the point size (see pointSize)
	 * already encodes the desired size and the caller has measured the resulting
	 * box. This positions that measured box via the 9-point grid with the
	 * shorter-edge margin, applying the same min-dimension and 90%-width skips.
	 *
	 * @param array<string,mixed> $settings Grouped settings (Options shape).
	 */
	public static function textPlacement( int $imgW, int $imgH, int $textW, int $textH, array $settings ): ?Placement {
		if ( $imgW < 1 || $imgH < 1 || $textW < 1 || $textH < 1 ) {
			return null;
		}

		$minDimension = self::intSetting( $settings, [ 'sizes', 'min_dimension_px' ], 0 );
		if ( min( $imgW, $imgH ) < $minDimension ) {
			return null;
		}

		if ( $textW > $imgW * self::MAX_WIDTH_FRACTION ) {
			return null;
		}

		return self::place( $imgW, $imgH, $textW, $textH, $settings );
	}

	/**
	 * Auto-sized point size for text: a fraction of image width, clamped to the
	 * sane [MIN_PT, MAX_PT] range. The driver may still shrink-to-fit after
	 * measuring; this is the starting size.
	 *
	 * @param array<string,mixed> $settings Grouped settings (Options shape).
	 */
	public static function pointSize( int $imgW, array $settings ): float {
		$scalePct = self::intSetting( $settings, [ 'watermark', 'text_scale_pct' ], 4 );
		$pt       = $imgW * $scalePct / 100;
		return self::clampFloat( $pt, self::MIN_PT, self::MAX_PT );
	}

	/**
	 * Margin in pixels: a percentage of the SHORTER edge (so the inset looks the
	 * same on portrait and landscape crops).
	 *
	 * @param array<string,mixed> $settings Grouped settings (Options shape).
	 */
	public static function margin( int $imgW, int $imgH, array $settings ): int {
		$marginPct = self::intSetting( $settings, [ 'placement', 'margin_pct' ], 3 );
		return (int) round( min( $imgW, $imgH ) * $marginPct / 100 );
	}

	/**
	 * Map the 9-point position + margin to a top-left (x,y) for a mark of the
	 * given box size, then clamp into [0, imgW-w] x [0, imgH-h] so it can never
	 * spill outside the image even on tiny sizes with a large margin.
	 *
	 * @param array<string,mixed> $settings Grouped settings (Options shape).
	 */
	private static function place( int $imgW, int $imgH, int $w, int $h, array $settings ): Placement {
		$margin   = self::margin( $imgW, $imgH, $settings );
		$position = self::strSetting( $settings, [ 'placement', 'position' ], 'bot-right' );
		$opacity  = self::clampInt( self::intSetting( $settings, [ 'sizing', 'opacity' ], 100 ), 0, 100 );

		[ $col, $row ] = self::split( $position );

		$x = match ( $col ) {
			'left'   => $margin,
			'center' => (int) round( ( $imgW - $w ) / 2 ),
			default  => $imgW - $w - $margin, // 'right'.
		};

		$y = match ( $row ) {
			'top'    => $margin,
			'mid'    => (int) round( ( $imgH - $h ) / 2 ),
			default  => $imgH - $h - $margin, // 'bot'.
		};

		$x = self::clampInt( $x, 0, max( 0, $imgW - $w ) );
		$y = self::clampInt( $y, 0, max( 0, $imgH - $h ) );

		return new Placement( $x, $y, $w, $h, $opacity );
	}

	/**
	 * Split a 9-point enum ('top-left' … 'bot-right') into [col, row].
	 *
	 * The stored form is "<row>-<col>" where row ∈ {top,mid,bot} and col ∈
	 * {left,center,right}. An unrecognized value falls back to bottom-right (the
	 * default position), so place() can never produce an undefined coordinate.
	 *
	 * @return array{0:string,1:string} [ col, row ].
	 */
	private static function split( string $position ): array {
		$parts = explode( '-', $position, 2 );
		$row   = $parts[0] ?? '';
		$col   = $parts[1] ?? '';

		$rows = [ 'top', 'mid', 'bot' ];
		$cols = [ 'left', 'center', 'right' ];

		if ( ! in_array( $row, $rows, true ) ) {
			$row = 'bot';
		}
		if ( ! in_array( $col, $cols, true ) ) {
			$col = 'right';
		}

		return [ $col, $row ];
	}

	/**
	 * Read a nested int from the grouped settings array, falling back when the
	 * path is missing or non-numeric.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<int,string>   $path
	 */
	private static function intSetting( array $settings, array $path, int $default ): int {
		$value = self::dig( $settings, $path );
		return is_numeric( $value ) ? (int) $value : $default;
	}

	/**
	 * Read a nested string from the grouped settings array.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<int,string>   $path
	 */
	private static function strSetting( array $settings, array $path, string $default ): string {
		$value = self::dig( $settings, $path );
		return is_string( $value ) && '' !== $value ? $value : $default;
	}

	/**
	 * Walk a path of keys into a nested array.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<int,string>   $path
	 */
	private static function dig( array $settings, array $path ): mixed {
		$value = $settings;
		foreach ( $path as $key ) {
			if ( is_array( $value ) && array_key_exists( $key, $value ) ) {
				$value = $value[ $key ];
			} else {
				return null;
			}
		}
		return $value;
	}

	private static function clampInt( int $value, int $min, int $max ): int {
		if ( $min > $max ) {
			[ $min, $max ] = [ $max, $min ];
		}
		return max( $min, min( $max, $value ) );
	}

	private static function clampFloat( float $value, float $min, float $max ): float {
		if ( $min > $max ) {
			[ $min, $max ] = [ $max, $min ];
		}
		return max( $min, min( $max, $value ) );
	}
}
