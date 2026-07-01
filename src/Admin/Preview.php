<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Admin;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Engine\Compositor;
use OrchardGrove\OgWatermark\Engine\LogoAsset;
use OrchardGrove\OgWatermark\Engine\Result;
use OrchardGrove\OgWatermark\Settings\Sanitizer;
use OrchardGrove\OgWatermark\Support\Capabilities;

/**
 * The live-preview renderer behind {@see Ajax::preview()} (SPEC M7 "Live
 * preview"). Split out of {@see Ajax} so the AJAX layer stays a thin
 * validate→cap→nonce shell and this owns the engine-facing work.
 *
 * Safety properties this class enforces (the SPEC's "preview throttle" +
 * "sanitize POSTed settings through the production callback" + "path never
 * echoed in errors"):
 *
 *  - The POSTed (unsaved) settings are run through the REAL
 *    {@see Sanitizer::sanitize()} — the same register_setting callback — BEFORE
 *    the engine ever sees them, so a hostile direct POST cannot smuggle an
 *    out-of-range scale, a non-PNG logo id, or a giant text template past the
 *    whitelist/clamp/validate layer.
 *  - The sample image is GENERATED at a small, capped size (never user-supplied),
 *    and the resolved text is hard-truncated, so a direct-POST loop cannot pin
 *    the CPU on a huge canvas or a megabyte of glyphs.
 *  - Output is returned as a base64 `data:` URL built entirely in memory; no temp
 *    file path is ever exposed, and a failure returns a short reason code with NO
 *    filesystem path in it.
 *
 * The per-user rate limit (a short transient) lives in {@see Ajax} so the cap/
 * nonce gate runs first; this class is the pure render once admission is granted.
 */
final class Preview {

	/** Sample canvas width in pixels — small + fixed (never from the request). */
	private const SAMPLE_W = 600;

	/** Sample canvas height in pixels — small + fixed (never from the request). */
	private const SAMPLE_H = 400;

	/** Hard cap on the resolved text length fed to the engine, defeating a glyph-bomb POST. */
	private const MAX_TEXT_CHARS = 200;

	/**
	 * Render a preview for the given raw (unsaved) settings POST.
	 *
	 * @param array<string,mixed> $rawSettings The untrusted `ogwm_settings`-shaped POST array.
	 * @return array{ok:bool,data_url?:string,reason?:string}
	 */
	public static function render( array $rawSettings ): array {
		// No usable raster driver → there is nothing honest to render.
		if ( ! Capabilities::isUsable() ) {
			return self::error( 'no-image-engine' );
		}

		// 1) Run the untrusted POST through the PRODUCTION sanitize callback. After
		//    this the settings are guaranteed in-range; the engine never sees raw
		//    input. Logo ids that aren't real local PNGs are dropped to null below.
		$settings = Sanitizer::sanitize( $rawSettings );

		// 2) Defend text mode: hard-cap the (post-token) template length BEFORE the
		//    engine measures/renders it, so a direct POST can't hand the font
		//    rasterizer an enormous string. We trim the stored template here; the
		//    engine resolves tokens itself.
		$settings = self::capText( $settings );

		// 3) Resolve the logo path through the same validated resolver the Processor
		//    uses (PNG-only, real local file) — null for text mode or an invalid id.
		$logoPath = self::resolveLogoPath( $settings );

		// Text mode with no FreeType has no honest output — surface it rather than
		// silently producing a blank sample.
		$type = self::stringSetting( $settings, 'watermark', 'type', 'text' );
		if ( 'text' === $type && ! Capabilities::hasFreeType() ) {
			return self::error( 'text-mode-needs-freetype' );
		}

		// 4) Generate the small sample image into a temp source, stamp it into a
		//    second temp dest with the REAL engine, read the bytes back, and ALWAYS
		//    sweep both temps. The dest path is never returned or echoed.
		$src = self::makeSampleImage();
		if ( null === $src ) {
			return self::error( 'sample-generation-failed' );
		}

		$dest = $src . '.stamped.png';

		try {
			$result = Compositor::stamp( $src, $dest, 'image/png', $settings, $logoPath );

			if ( Result::STAMPED !== $result->status || ! is_file( $dest ) ) {
				// Map an engine non-stamp to a SAFE reason code — never the engine's
				// raw reason (which is path-free already, but we normalize anyway so a
				// future engine message can't leak detail into the response).
				return self::error( 'render-' . self::safeStatus( $result->status ) );
			}

			$bytes = self::readFile( $dest );
			if ( null === $bytes || '' === $bytes ) {
				return self::error( 'render-readback-failed' );
			}

			return [
				'ok'       => true,
				'data_url' => 'data:image/png;base64,' . base64_encode( $bytes ),
			];
		} finally {
			self::deleteTemp( $src );
			self::deleteTemp( $dest );
		}
	}

	/**
	 * Cap the watermark text template length so a hostile POST can't feed a
	 * massive string to the rasterizer. Tokens are short, so trimming the template
	 * pre-resolution is a safe upper bound on the rendered string.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	private static function capText( array $settings ): array {
		if ( isset( $settings['watermark']['text'] ) && is_string( $settings['watermark']['text'] ) ) {
			$text = $settings['watermark']['text'];
			if ( function_exists( 'mb_substr' ) ) {
				$text = mb_substr( $text, 0, self::MAX_TEXT_CHARS );
			} else {
				$text = substr( $text, 0, self::MAX_TEXT_CHARS );
			}
			$settings['watermark']['text'] = $text;
		}
		return $settings;
	}

	/**
	 * Resolve the logo path for logo mode, or null. Mirrors the Processor's
	 * resolution so the preview matches what a real apply would composite, and
	 * inherits LogoAsset's PNG-only / real-local-file guarantees.
	 *
	 * @param array<string,mixed> $settings
	 */
	private static function resolveLogoPath( array $settings ): ?string {
		$type = self::stringSetting( $settings, 'watermark', 'type', 'text' );
		if ( 'logo' !== $type ) {
			return null;
		}

		$logoId = 0;
		if ( isset( $settings['watermark']['logo_id'] ) && is_scalar( $settings['watermark']['logo_id'] ) ) {
			$logoId = (int) $settings['watermark']['logo_id'];
		}

		return LogoAsset::resolvePath( $logoId );
	}

	/**
	 * Generate a small, fixed-size sample PNG (a soft checkerboard so the mark's
	 * opacity/legibility reads against both light and dark areas) into a temp file.
	 * The dimensions are constants — NEVER taken from the request — so the canvas
	 * size can't be inflated by a direct POST. Returns the temp path, or null when
	 * GD can't draw it.
	 */
	private static function makeSampleImage(): ?string {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			return null;
		}

		$w   = self::SAMPLE_W;
		$h   = self::SAMPLE_H;
		$img = @imagecreatetruecolor( $w, $h ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $img ) {
			return null;
		}

		// Two muted tones in a coarse checkerboard — enough contrast variation to
		// preview a stroke/shadow without being noisy.
		$light = (int) imagecolorallocate( $img, 210, 218, 224 );
		$dark  = (int) imagecolorallocate( $img, 120, 132, 144 );
		$cell  = 50;

		for ( $y = 0; $y < $h; $y += $cell ) { // phpcs:ignore Generic.CodeAnalysis.JumbledIncrementer.Found -- $cell is a constant checkerboard step, not a mutated loop var.
			for ( $x = 0; $x < $w; $x += $cell ) {
				$color = ( ( (int) ( $x / $cell ) + (int) ( $y / $cell ) ) % 2 === 0 ) ? $light : $dark;
				imagefilledrectangle( $img, $x, $y, $x + $cell - 1, $y + $cell - 1, $color );
			}
		}

		$path = self::tempPath();
		if ( null === $path ) {
			self::freeImage( $img );
			return null;
		}

		$ok = @imagepng( $img, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		self::freeImage( $img );

		return $ok && is_file( $path ) ? $path : null;
	}

	/**
	 * Free a GD image handle. imagedestroy() is a no-op since PHP 8.0 (the GC frees
	 * the GdImage object) and emits a deprecation on PHP 8.5, so only call it on the
	 * older builds where it still does the work — mirroring the engine's GdDriver.
	 *
	 * @param \GdImage|resource $image
	 */
	private static function freeImage( $image ): void {
		if ( PHP_VERSION_ID < 80000 && function_exists( 'imagedestroy' ) ) {
			imagedestroy( $image ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- only runs on PHP < 8.0 where it still frees the resource.
		}
	}

	/**
	 * A writable temp path under the system temp dir (NEVER under the web root, so
	 * the intermediate is never fetchable). The path is internal only and is never
	 * returned to the client.
	 */
	private static function tempPath(): ?string {
		$dir = function_exists( 'get_temp_dir' ) ? (string) get_temp_dir() : sys_get_temp_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			$dir = sys_get_temp_dir();
		}

		$name = 'ogwm-preview-' . self::token() . '.png';
		$path = rtrim( $dir, '/\\' ) . '/' . $name;

		return '' !== $path ? $path : null;
	}

	/** Unguessable token for the temp filename. */
	private static function token(): string {
		if ( function_exists( 'wp_generate_password' ) ) {
			return (string) wp_generate_password( 12, false, false );
		}
		return bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Read a file's bytes, or null on failure. Wrapped so a read error returns a
	 * path-free reason upstream rather than surfacing a filesystem warning/path.
	 */
	private static function readFile( string $path ): ?string {
		$bytes = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return is_string( $bytes ) ? $bytes : null;
	}

	/** Best-effort temp cleanup; never throws, never leaks the path. */
	private static function deleteTemp( string $path ): void {
		if ( '' !== $path && is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Normalize an engine Result status to a known short token, so the response
	 * reason is always a fixed vocabulary and never echoes engine internals.
	 */
	private static function safeStatus( string $status ): string {
		$known = [ Result::SKIPPED, Result::TOO_LARGE, Result::TOO_SMALL, Result::FAILED, Result::STAMPED ];
		return in_array( $status, $known, true ) ? $status : 'failed';
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

	/**
	 * Shape a path-free failure result.
	 *
	 * @return array{ok:bool,reason:string}
	 */
	private static function error( string $reason ): array {
		return [
			'ok'     => false,
			'reason' => $reason,
		];
	}
}
