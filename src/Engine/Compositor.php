<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Engine;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Support\Capabilities;

/**
 * The engine facade the processor (M4) calls once per file.
 *
 * Compositor owns the per-file orchestration and the format policy: it picks a
 * driver, gates the MIME against what the engine + the active driver can do,
 * runs a memory pre-flight, loads the source, computes driver-agnostic geometry
 * via Geometry (measuring the logo's native size or the rendered text box first),
 * paints the mark, flattens for JPEG, and encodes to a SEPARATE destination —
 * never mutating the source in place and never changing the format/extension.
 *
 * Every outcome is a Result: stamped on success, skipped (with a safe reason)
 * for a format-policy bail, too_large for the memory pre-flight, or failed for a
 * load/save/driver error. A single bad image therefore never fatals a bulk run.
 *
 * Orientation is core-owned: this class composites onto already-oriented pixels
 * and never re-orients.
 */
final class Compositor {

	/** Bundled font, relative to OGWM_DIR. Used for all text rendering. */
	private const FONT_REL = 'assets/fonts/DejaVuSans-Bold.ttf';

	/** Raw-decode memory estimate multiplier: w*h*4 bytes * this fudge factor. */
	private const MEMORY_FUDGE = 1.8;

	/** Fraction of the resolvable memory budget a single decode may use. */
	private const MEMORY_BUDGET_FRACTION = 0.6;

	/**
	 * Absolute hard cap (bytes) for a single decode, applied even when the host
	 * reports an unlimited memory_limit (-1). 768 MiB ≈ a 9000x9000 RGBA image.
	 */
	private const MEMORY_HARD_CAP = 805306368;

	/** Fallback budget (bytes) when memory_limit cannot be resolved at all. */
	private const MEMORY_FALLBACK = 268435456; // 256 MiB.

	/**
	 * Optional driver override for tests: when set, driver() returns it and
	 * stamp() uses it instead of probing Capabilities. Never set in production.
	 */
	private static ?Watermarker $testDriver = null;

	/** Inject a stub driver (tests only). */
	public static function setTestDriver( ?Watermarker $driver ): void {
		self::$testDriver = $driver;
	}

	/** Clear the test driver override. */
	public static function resetTestDriver(): void {
		self::$testDriver = null;
	}

	/**
	 * The active raster driver, or null when none is usable.
	 *
	 * Selection mirrors Capabilities: Imagick when it is the chosen driver AND
	 * its class is loadable, else GD when chosen AND loadable, else null. A test
	 * override short-circuits the probe.
	 */
	public static function driver(): ?Watermarker {
		if ( null !== self::$testDriver ) {
			return self::$testDriver;
		}

		$chosen = Capabilities::driver();

		if ( 'imagick' === $chosen && class_exists( ImagickDriver::class ) ) {
			return new ImagickDriver();
		}

		if ( 'gd' === $chosen && class_exists( GdDriver::class ) ) {
			return new GdDriver();
		}

		return null;
	}

	/**
	 * Watermark $srcPath → $destPath in the given MIME, per the grouped settings.
	 *
	 * Flow: format-policy gate → memory pre-flight → load → measure → place via
	 * Geometry → paint (logo or text) → flatten (JPEG only) → save → free. The
	 * source is read-only; the destination must be a DISTINCT path (M4 passes a
	 * temp file and renames it into place itself).
	 *
	 * @param string              $srcPath  Absolute path to the (already-oriented) source image.
	 * @param string              $destPath Absolute destination path (must differ from $srcPath).
	 * @param string              $mime     Target MIME — honored exactly; format never changes.
	 * @param array<string,mixed> $settings Grouped settings (Options shape).
	 * @param string|null         $logoPath Validated local PNG path for logo mode, or null.
	 */
	public static function stamp(
		string $srcPath,
		string $destPath,
		string $mime,
		array $settings,
		?string $logoPath
	): Result {
		$mime = strtolower( trim( $mime ) );

		// --- Format-policy gate -------------------------------------------
		if ( ! MimePolicy::isStampable( $mime ) ) {
			return Result::skipped( 'unsupported format: ' . $mime );
		}
		if ( 'image/gif' === $mime && MimePolicy::isAnimatedGif( $srcPath ) ) {
			return Result::skipped( 'animated GIF not stamped' );
		}

		$driver = self::driver();
		if ( null === $driver ) {
			return Result::skipped( 'no usable image driver' );
		}
		if ( ! self::driverCanEncode( $mime ) ) {
			return Result::skipped( 'driver cannot encode ' . $mime );
		}

		// Never mutate the source in place — M4 writes to a temp dest and renames.
		if ( '' === $destPath || $destPath === $srcPath ) {
			return Result::failed( 'destination must differ from source' );
		}

		// --- Pre-flight: source readable + memory budget ------------------
		$size = self::sourceDimensions( $srcPath );
		if ( null === $size ) {
			return Result::failed( 'cannot read source dimensions' );
		}
		[ $imgW, $imgH ] = $size;

		// GD cannot decode CMYK JPEG reliably — skip rather than corrupt. Imagick
		// transforms to sRGB inside its driver, so only gate GD here.
		if ( 'image/jpeg' === $mime && self::isGdDriver( $driver ) && self::isCmykJpeg( $srcPath ) ) {
			return Result::skipped( 'CMYK JPEG unsupported on GD' );
		}

		if ( ! self::fitsMemory( $imgW, $imgH ) ) {
			return Result::tooLarge();
		}

		// --- Build the mark spec from settings ----------------------------
		$type = self::stringSetting( $settings, 'watermark', 'type', 'text' );

		if ( 'logo' === $type ) {
			if ( null === $logoPath || '' === $logoPath ) {
				return Result::skipped( 'logo mode but no logo configured' );
			}
		} elseif ( ! Capabilities::hasFreeType() ) {
			// Text mode with no FreeType has no honest output (no bitmap fallback).
			return Result::skipped( 'text mode requires FreeType' );
		}

		// --- Load, measure, place, paint, save ----------------------------
		try {
			if ( ! $driver->load( $srcPath ) ) {
				$driver->free();
				return Result::failed( 'driver failed to load source' );
			}

			// Prefer the driver's own dimensions once loaded (authoritative);
			// fall back to the pre-flight read if the driver returns nothing.
			$dims = $driver->dimensions();
			if ( isset( $dims[0], $dims[1] ) && (int) $dims[0] > 0 && (int) $dims[1] > 0 ) {
				$imgW = (int) $dims[0];
				$imgH = (int) $dims[1];
			}

			if ( 'logo' === $type ) {
				$result = self::paintLogo( $driver, (string) $logoPath, $imgW, $imgH, $settings );
			} else {
				$result = self::paintText( $driver, $imgW, $imgH, $settings );
			}

			if ( ! $result->isOk() ) {
				$driver->free();
				return $result; // Geometry skip (too small / mark too big).
			}

			if ( MimePolicy::needsFlatten( $mime ) ) {
				$driver->flatten( self::stringSetting( $settings, 'output', 'jpeg_bg', '#ffffff' ) );
			}

			$saved = $driver->save( $destPath, $mime, self::jpegQuality() );
			$driver->free();

			if ( ! $saved ) {
				return Result::failed( 'driver failed to save destination' );
			}

			return Result::stamped();
		} catch ( ImageException $e ) {
			$driver->free();
			return Result::failed( $e->getMessage() );
		} catch ( \Throwable $e ) {
			$driver->free();
			return Result::failed( 'unexpected engine error' );
		}
	}

	/**
	 * Measure the logo, compute placement, and paint it. Returns a non-OK Result
	 * when Geometry decides this size should be skipped.
	 *
	 * @param array<string,mixed> $settings
	 */
	private static function paintLogo(
		Watermarker $driver,
		string $logoPath,
		int $imgW,
		int $imgH,
		array $settings
	): Result {
		$logoSize = self::sourceDimensions( $logoPath );
		if ( null === $logoSize ) {
			return Result::skipped( 'logo file unreadable' );
		}
		[ $logoW, $logoH ] = $logoSize;

		$placement = Geometry::logoPlacement( $imgW, $imgH, $logoW, $logoH, $settings );
		if ( null === $placement ) {
			return Result::skipped( 'image too small for logo placement' );
		}

		$driver->paint_logo( $logoPath, $placement );
		return Result::stamped();
	}

	/**
	 * Resolve + measure the text, compute placement, and paint it. Returns a
	 * non-OK Result when the resolved text is empty or Geometry skips this size.
	 *
	 * @param array<string,mixed> $settings
	 */
	private static function paintText(
		Watermarker $driver,
		int $imgW,
		int $imgH,
		array $settings
	): Result {
		$template = self::stringSetting( $settings, 'watermark', 'text', '' );
		$text     = TokenResolver::resolve( $template );
		if ( '' === trim( $text ) ) {
			return Result::skipped( 'resolved watermark text is empty' );
		}

		$fontPath = self::fontPath();
		if ( '' === $fontPath || ! is_file( $fontPath ) ) {
			return Result::skipped( 'bundled font unavailable' );
		}

		$sizePt = Geometry::pointSize( $imgW, $settings );

		[ $textW, $textH ] = self::measureText( $text, $fontPath, $sizePt );
		if ( $textW < 1 || $textH < 1 ) {
			return Result::skipped( 'could not measure watermark text' );
		}

		$placement = Geometry::textPlacement( $imgW, $imgH, $textW, $textH, $settings );
		if ( null === $placement ) {
			return Result::skipped( 'image too small for text placement' );
		}

		$spec = self::buildTextSpec( $text, $fontPath, $sizePt, $settings );
		$driver->paint_text( $spec, $placement );
		return Result::stamped();
	}

	/**
	 * Assemble the immutable TextSpec from resolved text + settings. Stroke width
	 * and shadow offsets scale with the point size so the treatment reads at any
	 * image resolution.
	 *
	 * @param array<string,mixed> $settings
	 */
	private static function buildTextSpec( string $text, string $fontPath, float $sizePt, array $settings ): TextSpec {
		$treatment      = self::stringSetting( $settings, 'watermark', 'treatment', 'stroke' );
		$color          = self::stringSetting( $settings, 'watermark', 'text_color', '#ffffff' );
		$treatmentColor = self::stringSetting( $settings, 'watermark', 'treatment_color', '#000000' );

		// Stroke ~ 1/16 of the cap height; shadow ~ 6% of point size (per SPEC).
		$strokeWidth = max( 1, (int) round( $sizePt / 16 ) );
		$shadow      = max( 1, (int) round( $sizePt * 0.06 ) );

		return new TextSpec(
			$text,
			$fontPath,
			$sizePt,
			$color,
			$treatment,
			$treatmentColor,
			$strokeWidth,
			$shadow,
			$shadow
		);
	}

	/**
	 * Measure a rendered string's bounding box without a loaded driver, using
	 * GD's FreeType bbox when available (it is whenever GD-FreeType is present).
	 * Falls back to a metrics estimate from the point size and character count
	 * so an Imagick-only host (no GD) still gets a usable placement box.
	 *
	 * @return array{0:int,1:int} [ width, height ] in pixels.
	 */
	private static function measureText( string $text, string $fontPath, float $sizePt ): array {
		if ( function_exists( 'imagettfbbox' ) ) {
			$bbox = @imagettfbbox( $sizePt, 0, $fontPath, $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $bbox ) && 8 === count( $bbox ) ) {
				$xs = [ $bbox[0], $bbox[2], $bbox[4], $bbox[6] ];
				$ys = [ $bbox[1], $bbox[3], $bbox[5], $bbox[7] ];
				$w  = (int) ( max( $xs ) - min( $xs ) );
				$h  = (int) ( max( $ys ) - min( $ys ) );
				return [ max( 0, $w ), max( 0, $h ) ];
			}
		}

		// Estimate: ~0.6em average glyph advance, ~1.2em line height. Coarse but
		// only used when no FreeType bbox is reachable (rare; Imagick re-measures).
		$emPx = $sizePt * 96 / 72; // pt → px at 96 DPI.
		$w    = (int) round( strlen( $text ) * $emPx * 0.6 );
		$h    = (int) round( $emPx * 1.2 );
		return [ max( 0, $w ), max( 0, $h ) ];
	}

	/**
	 * Source pixel dimensions via getimagesize (no decode), or null when the path
	 * is not a readable image.
	 *
	 * @return array{0:int,1:int}|null
	 */
	private static function sourceDimensions( string $path ): ?array {
		if ( '' === $path || ! is_file( $path ) ) {
			return null;
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $info ) || ! isset( $info[0], $info[1] ) ) {
			return null;
		}
		$w = (int) $info[0];
		$h = (int) $info[1];
		if ( $w < 1 || $h < 1 ) {
			return null;
		}
		return [ $w, $h ];
	}

	/**
	 * Whether decoding a $w×$h image fits the host's memory budget. Runs even when
	 * memory_limit is -1 (unlimited) — the hard cap still applies, so a pathological
	 * original can't OOM a worker.
	 */
	private static function fitsMemory( int $w, int $h ): bool {
		$need   = (float) $w * (float) $h * 4.0 * self::MEMORY_FUDGE;
		$budget = self::memoryBudget();
		return $need <= $budget;
	}

	/**
	 * Bytes a single decode may consume: a fraction of (limit − current usage),
	 * never above the hard cap. When the limit is unlimited/unparseable, the hard
	 * cap (or a sane fallback) governs so the pre-flight is always meaningful.
	 */
	private static function memoryBudget(): float {
		$limit = self::memoryLimitBytes();

		if ( $limit <= 0 ) {
			// Unlimited (-1) or unknown: cap at the hard ceiling.
			return (float) self::MEMORY_HARD_CAP;
		}

		$used      = (float) ( function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0 );
		$available = max( 0.0, ( $limit * self::MEMORY_BUDGET_FRACTION ) - $used );

		// If headroom collapses to ~0, still allow up to a small floor so we don't
		// wrongly flag every image too_large on a near-full worker; the hard cap
		// remains the absolute ceiling.
		$budget = max( $available, (float) min( self::MEMORY_FALLBACK, (int) $limit ) );

		return min( $budget, (float) self::MEMORY_HARD_CAP );
	}

	/**
	 * memory_limit in bytes, or -1 for unlimited, or 0 when unparseable. Uses
	 * wp_convert_hr_to_bytes when present, else a self-contained parser so the
	 * pre-flight works the same with or without WordPress loaded.
	 */
	private static function memoryLimitBytes(): int {
		$raw = (string) ini_get( 'memory_limit' );
		if ( '' === $raw ) {
			return 0;
		}
		if ( '-1' === trim( $raw ) ) {
			return -1;
		}

		if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
			return (int) wp_convert_hr_to_bytes( $raw );
		}

		return self::hrToBytes( $raw );
	}

	/** Parse a PHP shorthand byte string (e.g. "128M", "1G") to bytes. */
	private static function hrToBytes( string $value ): int {
		$value = trim( $value );
		$unit  = strtolower( substr( $value, -1 ) );
		$num   = (int) $value;

		return match ( $unit ) {
			'g'     => $num * 1024 * 1024 * 1024,
			'm'     => $num * 1024 * 1024,
			'k'     => $num * 1024,
			default => (int) $value,
		};
	}

	/** JPEG encode quality, filterable like core (default 82, context 'ogwm'). */
	private static function jpegQuality(): int {
		return (int) apply_filters( 'jpeg_quality', 82, 'ogwm' );
	}

	/** Whether the ACTIVE driver can encode $mime (per Capabilities). */
	private static function driverCanEncode( string $mime ): bool {
		// A test driver bypasses the capability probe (the stub encodes anything).
		if ( null !== self::$testDriver ) {
			return true;
		}
		return Capabilities::canEncode( $mime );
	}

	/** Absolute path to the bundled font, or '' when OGWM_DIR is undefined. */
	private static function fontPath(): string {
		if ( ! defined( 'OGWM_DIR' ) ) {
			return '';
		}
		return OGWM_DIR . self::FONT_REL;
	}

	/** Whether $driver is the concrete GD driver (for the CMYK-on-GD gate). */
	private static function isGdDriver( Watermarker $driver ): bool {
		return class_exists( GdDriver::class ) && $driver instanceof GdDriver;
	}

	/**
	 * Sniff whether a JPEG is CMYK (which GD cannot decode reliably). Reads the
	 * SOFn marker's component count from getimagesize's channel report; 4 channels
	 * indicates CMYK/YCCK. Best-effort — any ambiguity reports false (don't skip).
	 */
	private static function isCmykJpeg( string $path ): bool {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $info ) ) {
			return false;
		}
		return isset( $info['channels'] ) && 4 === (int) $info['channels'];
	}

	/**
	 * Read a nested string setting from the grouped array.
	 *
	 * @param array<string,mixed> $settings
	 */
	private static function stringSetting( array $settings, string $group, string $key, string $default ): string {
		if ( isset( $settings[ $group ] ) && is_array( $settings[ $group ] ) && isset( $settings[ $group ][ $key ] ) ) {
			$value = $settings[ $group ][ $key ];
			if ( is_scalar( $value ) ) {
				return (string) $value;
			}
		}
		return $default;
	}
}
