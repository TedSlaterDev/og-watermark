<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * GD implementation of the Watermarker contract.
 *
 * This is a first-class driver, not a degraded fallback: Imagick is absent on
 * many shared hosts, so the GD path must produce a correct, alpha-faithful
 * watermark on its own. The class wraps a single \GdImage handle through the
 * load → dimensions → paint → flatten → save → free lifecycle.
 *
 * Compositing notes (the load-bearing parts):
 * - Logos are NEVER drawn with imagecopymerge() — its PCT mode mishandles
 *   per-pixel alpha (the classic "black box behind a transparent PNG" bug).
 *   Instead the logo is resampled into a truecolor canvas with blending OFF
 *   and savealpha ON, global opacity is folded into each pixel's alpha, and the
 *   result is imagecopy()'d onto the base with blending ON so it alpha-blends.
 * - Text is drawn with imagettftext(): an 8-direction offset stroke pass (or a
 *   single translucent shadow pass) before the fill pass, using the bundled
 *   DejaVu Sans Bold face the TextSpec already resolved.
 *
 * Orientation is core-owned (see Watermarker): this driver never rotates.
 * CMYK / unreadable inputs return false from load() rather than fatal, so the
 * Compositor can record a clean skip/failure for a single bad file.
 */
final class GdDriver implements Watermarker {

	/**
	 * Base alpha for the shadow treatment on GD's 0(opaque)..127(transparent)
	 * scale. ~30% transparent keeps the shadow soft ("translucent dark pass")
	 * yet dark enough to lift text off a busy background.
	 */
	private const SHADOW_BASE_ALPHA = 38;

	/** The live image handle, or null before load()/after free(). */
	private ?\GdImage $image = null;

	/**
	 * Decode a raster file into a truecolor GD handle.
	 *
	 * The image type is detected with getimagesize() (not the filename), the
	 * matching imagecreatefrom* reader is gated on actual GD support, palette
	 * images are promoted to truecolor, and alpha handling is configured so a
	 * subsequent PNG/WebP save preserves transparency. GD cannot read CMYK
	 * JPEGs reliably, so a failed decode is reported as false (the Compositor
	 * turns that into a skip/failure) rather than allowed to fatal.
	 *
	 * @param string $path Absolute path to a readable image file.
	 * @return bool True on success.
	 * @throws ImageException When the file is unreadable or has no decoder.
	 */
	public function load( string $path ): bool {
		$this->free();

		if ( '' === $path || ! is_readable( $path ) ) {
			throw new ImageException( 'source image is not readable' );
		}

		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- corrupt/CMYK sources warn; we report false instead.
		if ( false === $info ) {
			// Not a recognisable raster (or a CMYK/SVG/corrupt file) — let the
			// caller decide; never fatal on one bad source.
			return false;
		}

		$image = $this->decode( (int) ( $info[2] ?? 0 ), $path );
		if ( ! $image instanceof \GdImage ) {
			return false;
		}

		// Promote palette images so compositing and alpha behave predictably.
		if ( ! imageistruecolor( $image ) ) {
			imagepalettetotruecolor( $image );
		}

		// Keep blending ON for incoming paints, but make sure a later PNG/WebP
		// save writes the alpha channel rather than discarding it.
		imagealphablending( $image, true );
		imagesavealpha( $image, true );

		$this->image = $image;
		return true;
	}

	/**
	 * Dispatch to the GD reader for the detected IMAGETYPE_*.
	 *
	 * Each reader is guarded by gd_info()/function_exists so an unsupported
	 * build returns false instead of calling a missing function. WBMP/XBM and
	 * other niche types fall through to null (unsupported here).
	 *
	 * @param int    $type IMAGETYPE_* constant from getimagesize().
	 * @param string $path Absolute source path.
	 */
	private function decode( int $type, string $path ): \GdImage|false|null {
		$gd = function_exists( 'gd_info' ) ? gd_info() : [];

		// Decoders warn (not throw) on truncated/CMYK/corrupt input; we silence
		// and return false/null so the Compositor records a clean skip/failure.
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		switch ( $type ) {
			case IMAGETYPE_JPEG:
				return ! empty( $gd['JPEG Support'] ) && function_exists( 'imagecreatefromjpeg' )
					? @imagecreatefromjpeg( $path )
					: null;

			case IMAGETYPE_PNG:
				return ! empty( $gd['PNG Support'] ) && function_exists( 'imagecreatefrompng' )
					? @imagecreatefrompng( $path )
					: null;

			case IMAGETYPE_GIF:
				return ! empty( $gd['GIF Read Support'] ) && function_exists( 'imagecreatefromgif' )
					? @imagecreatefromgif( $path )
					: null;

			case IMAGETYPE_WEBP:
				return ! empty( $gd['WebP Support'] ) && function_exists( 'imagecreatefromwebp' )
					? @imagecreatefromwebp( $path )
					: null;

			case IMAGETYPE_AVIF:
				return ! empty( $gd['AVIF Support'] ) && function_exists( 'imagecreatefromavif' )
					? @imagecreatefromavif( $path )
					: null;

			default:
				return null;
		}
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Pixel dimensions of the loaded image.
	 *
	 * @return array{0:int,1:int} [ width, height ].
	 * @throws ImageException When called before a successful load().
	 */
	public function dimensions(): array {
		$image = $this->handle();
		return [ imagesx( $image ), imagesy( $image ) ];
	}

	/**
	 * Composite a logo PNG onto the base, preserving per-pixel alpha and folding
	 * in global opacity.
	 *
	 * Steps:
	 * 1. Load + truecolor-normalise the logo PNG.
	 * 2. Resample it into a fresh transparent truecolor canvas at the placement
	 *    size with blending OFF + savealpha ON (so source alpha is copied, not
	 *    blended against black).
	 * 3. Fold global opacity into every pixel's alpha:
	 *      na = 127 - round( ( 127 - a ) * opacity / 100 )
	 *    (a/na are GD's 0=opaque..127=transparent scale).
	 * 4. imagecopy() the resized+faded logo onto the base with the base's
	 *    blending ON, so it alpha-blends correctly. imagecopymerge() is never
	 *    used — it cannot honour per-pixel alpha.
	 *
	 * @param string    $logoPath Absolute path to a validated local PNG.
	 * @param Placement $p        Where/how big to draw + opacity.
	 * @throws ImageException On a load/resample failure.
	 */
	public function paint_logo( string $logoPath, Placement $p ): void {
		$base = $this->handle();

		if ( '' === $logoPath || ! is_readable( $logoPath ) ) {
			throw new ImageException( 'logo image is not readable' );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors -- a bad PNG warns; we throw ImageException below.
		$logo = function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $logoPath ) : false;
		if ( ! $logo instanceof \GdImage ) {
			throw new ImageException( 'logo PNG could not be decoded' );
		}

		if ( ! imageistruecolor( $logo ) ) {
			imagepalettetotruecolor( $logo );
		}

		$srcW = imagesx( $logo );
		$srcH = imagesy( $logo );
		$dstW = max( 1, $p->width );
		$dstH = max( 1, $p->height );

		// Fresh fully-transparent canvas at the target size.
		$resized = imagecreatetruecolor( $dstW, $dstH );
		if ( ! $resized instanceof \GdImage ) {
			$this->destroy( $logo );
			throw new ImageException( 'could not allocate logo canvas' );
		}

		// Copy source alpha verbatim — no blending against the new canvas.
		imagealphablending( $resized, false );
		imagesavealpha( $resized, true );
		$transparent = imagecolorallocatealpha( $resized, 0, 0, 0, 127 );
		imagefill( $resized, 0, 0, $transparent );

		imagecopyresampled( $resized, $logo, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH );
		$this->destroy( $logo );

		// Fold global opacity into per-pixel alpha (skip the walk at full opacity).
		$opacity = $this->clampOpacity( $p->opacity );
		if ( $opacity < 100 ) {
			$this->fadeAlpha( $resized, $dstW, $dstH, $opacity );
		}

		// Blend the prepared logo onto the base. Base blending ON so the logo's
		// alpha actually composites; imagecopy (NOT imagecopymerge) carries the
		// per-pixel alpha we just baked in.
		imagealphablending( $base, true );
		imagesavealpha( $base, true );
		imagecopy( $base, $resized, $p->x, $p->y, 0, 0, $dstW, $dstH );

		$this->destroy( $resized );
	}

	/**
	 * Walk every pixel of $img and scale its alpha toward transparent by the
	 * given opacity percentage. Operates in place on a savealpha/no-blend canvas.
	 *
	 * @param \GdImage $img     Truecolor canvas with per-pixel alpha.
	 * @param int      $w       Width.
	 * @param int      $h       Height.
	 * @param int      $opacity 0–100; 100 = unchanged.
	 */
	private function fadeAlpha( \GdImage $img, int $w, int $h, int $opacity ): void {
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$rgba  = imagecolorat( $img, $x, $y );
				$alpha = ( $rgba >> 24 ) & 0x7F;
				// 0=opaque..127=transparent: push toward transparent by opacity.
				$na = 127 - (int) round( ( 127 - $alpha ) * $opacity / 100 );
				if ( $na === $alpha ) {
					continue;
				}
				$color = imagecolorallocatealpha(
					$img,
					( $rgba >> 16 ) & 0xFF,
					( $rgba >> 8 ) & 0xFF,
					$rgba & 0xFF,
					$na
				);
				if ( false !== $color ) {
					imagesetpixel( $img, $x, $y, $color );
					imagecolordeallocate( $img, $color );
				}
			}
		}
	}

	/**
	 * Render text onto the base with the spec's legibility treatment.
	 *
	 * The baseline Y is computed from the glyph bounding box so the placement
	 * box ($p->y .. $p->y+height) contains the visible text. A 'stroke'
	 * treatment draws an 8-direction offset pass in the treatment colour before
	 * the fill; a 'shadow' treatment draws a single translucent offset pass; a
	 * 'none' treatment draws only the fill. Global opacity is folded into every
	 * colour's alpha so the whole mark fades together.
	 *
	 * @param TextSpec  $t The resolved text, font, size, colour and treatment.
	 * @param Placement $p Where to draw + opacity.
	 * @throws ImageException When FreeType/font is unavailable or a draw fails.
	 */
	public function paint_text( TextSpec $t, Placement $p ): void {
		$base = $this->handle();

		if ( ! function_exists( 'imagettftext' ) || ! function_exists( 'imagettfbbox' ) ) {
			throw new ImageException( 'FreeType text rendering is unavailable' );
		}
		if ( '' === $t->fontPath || ! is_readable( $t->fontPath ) ) {
			throw new ImageException( 'watermark font is not readable' );
		}

		// FreeType calls warn on a malformed glyph run; silence and convert to an
		// ImageException so a single bad string never fatals a bulk job.
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		$size = $t->sizePt;
		$bbox = @imagettfbbox( $size, 0.0, $t->fontPath, $t->text );
		if ( ! is_array( $bbox ) ) {
			throw new ImageException( 'could not measure watermark text' );
		}

		// imagettfbbox corners: 0,1 lower-left  6,7 upper-left. The pen X passed
		// to imagettftext should sit at the visual left edge; the baseline Y at
		// the visual top + ascent. Offsetting by the bbox left/top corners makes
		// the rendered glyphs start exactly at ($p->x,$p->y).
		$offsetX = - min( $bbox[0], $bbox[2], $bbox[4], $bbox[6] );
		$offsetY = - min( $bbox[1], $bbox[3], $bbox[5], $bbox[7] );

		$drawX = $p->x + $offsetX;
		$drawY = $p->y + $offsetY;

		$opacity = $this->clampOpacity( $p->opacity );

		imagealphablending( $base, true );
		imagesavealpha( $base, true );

		// Legibility pass (stroke or shadow) under the fill.
		if ( 'stroke' === $t->treatment ) {
			$strokeColor = $this->allocateHex( $base, $t->treatmentColor, $opacity, 0 );
			$width       = max( 1, $t->strokeWidth );
			for ( $dx = -$width; $dx <= $width; $dx++ ) {
				for ( $dy = -$width; $dy <= $width; $dy++ ) {
					if ( 0 === $dx && 0 === $dy ) {
						continue; // Centre is the fill, drawn last.
					}
					@imagettftext( $base, $size, 0.0, $drawX + $dx, $drawY + $dy, $strokeColor, $t->fontPath, $t->text );
				}
			}
		} elseif ( 'shadow' === $t->treatment ) {
			// Translucent dark offset pass under the fill. A small base alpha
			// (GD 0=opaque..127=transparent) keeps the shadow soft per SPEC while
			// still dark enough for legibility; the global mark opacity is folded
			// in so the shadow fades together with the rest of the mark.
			$shadowColor = $this->allocateHex( $base, $t->treatmentColor, $opacity, self::SHADOW_BASE_ALPHA );
			@imagettftext( $base, $size, 0.0, $drawX + $t->shadowDx, $drawY + $t->shadowDy, $shadowColor, $t->fontPath, $t->text );
		}

		// Fill pass on top.
		$fillColor = $this->allocateHex( $base, $t->color, $opacity, 0 );
		@imagettftext( $base, $size, 0.0, $drawX, $drawY, $fillColor, $t->fontPath, $t->text );
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Flatten any transparency onto a solid background so a JPEG encode does not
	 * show black/garbage where alpha was. A new opaque canvas is filled with the
	 * background colour and the current image is composited over it; the result
	 * replaces the handle and has its alpha channel dropped.
	 *
	 * Cheap to call unconditionally — when the image is already fully opaque the
	 * pixels are identical, so the Compositor may call this for every JPEG.
	 *
	 * @param string $hexBg Background colour, e.g. '#ffffff'.
	 * @throws ImageException When the flatten canvas can't be allocated.
	 */
	public function flatten( string $hexBg ): void {
		$src = $this->handle();
		$w   = imagesx( $src );
		$h   = imagesy( $src );

		$canvas = imagecreatetruecolor( $w, $h );
		if ( ! $canvas instanceof \GdImage ) {
			throw new ImageException( 'could not allocate flatten canvas' );
		}

		// Opaque background; no alpha saved (this is the JPEG path).
		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, false );
		$bg = $this->allocateHex( $canvas, $hexBg, 100, 0 );
		imagefilledrectangle( $canvas, 0, 0, $w, $h, $bg );

		// Composite the (possibly-transparent) image over the solid bg.
		imagealphablending( $canvas, true );
		imagecopy( $canvas, $src, 0, 0, 0, 0, $w, $h );

		$this->destroy( $src );
		$this->image = $canvas;
	}

	/**
	 * Encode the loaded image to a destination path honouring the given MIME.
	 *
	 * The format/extension is never changed: the caller passes the recorded
	 * per-size MIME and this method writes exactly that. Each encoder is guarded
	 * by gd_info()/function_exists so an unsupported build returns false rather
	 * than fataling. PNG is written losslessly with alpha preserved; JPEG uses
	 * the supplied quality; WebP/AVIF reuse the same quality value.
	 *
	 * @param string $dest    Absolute destination path.
	 * @param string $mime    Target MIME type (e.g. 'image/jpeg').
	 * @param int    $quality Lossy quality 0–100 (ignored by PNG).
	 * @return bool True on success; false when the format is unsupported here.
	 * @throws ImageException When called before load().
	 */
	public function save( string $dest, string $mime, int $quality ): bool {
		$image   = $this->handle();
		$gd      = function_exists( 'gd_info' ) ? gd_info() : [];
		$quality = max( 0, min( 100, $quality ) );

		// Encoders warn (not throw) when a destination is unwritable or the build
		// lacks the codec; silence and return false so the caller can fall back.
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		switch ( $mime ) {
			case 'image/jpeg':
				if ( empty( $gd['JPEG Support'] ) || ! function_exists( 'imagejpeg' ) ) {
					return false;
				}
				// JPEG has no alpha; ensure it isn't carried into the encoder.
				imagealphablending( $image, true );
				imagesavealpha( $image, false );
				return (bool) @imagejpeg( $image, $dest, $quality );

			case 'image/png':
				if ( empty( $gd['PNG Support'] ) || ! function_exists( 'imagepng' ) ) {
					return false;
				}
				imagealphablending( $image, false );
				imagesavealpha( $image, true );
				// Map 0–100 quality to PNG's 0(best)–9(worst) compression; PNG is
				// lossless so this only trades file size for CPU, never fidelity.
				$level = 9 - (int) round( $quality / 100 * 9 );
				$level = max( 0, min( 9, $level ) );
				return (bool) @imagepng( $image, $dest, $level );

			case 'image/webp':
				if ( empty( $gd['WebP Support'] ) || ! function_exists( 'imagewebp' ) ) {
					return false;
				}
				imagesavealpha( $image, true );
				return (bool) @imagewebp( $image, $dest, $quality );

			case 'image/avif':
				if ( empty( $gd['AVIF Support'] ) || ! function_exists( 'imageavif' ) ) {
					return false;
				}
				imagesavealpha( $image, true );
				return (bool) @imageavif( $image, $dest, $quality );

			case 'image/gif':
				if ( empty( $gd['GIF Create Support'] ) || ! function_exists( 'imagegif' ) ) {
					return false;
				}
				return (bool) @imagegif( $image, $dest );

			default:
				// Unknown/unsupported MIME — never guess a format.
				return false;
		}
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Release the image handle. imagedestroy() is a guarded no-op on PHP 8.5
	 * (GD resources are \GdImage objects freed by the GC), so the unset() is
	 * what actually drops the last reference.
	 */
	public function free(): void {
		if ( $this->image instanceof \GdImage ) {
			$this->destroy( $this->image );
		}
		$this->image = null;
	}

	/**
	 * Return the live handle or throw — every paint/save call funnels through
	 * here so a use-before-load is a clean ImageException, not a TypeError.
	 *
	 * @throws ImageException When no image is loaded.
	 */
	private function handle(): \GdImage {
		if ( ! $this->image instanceof \GdImage ) {
			throw new ImageException( 'no image loaded' );
		}
		return $this->image;
	}

	/**
	 * Free one GD handle and drop the reference.
	 *
	 * Since PHP 8.0 GD resources are \GdImage objects freed by refcounting, so
	 * imagedestroy() is a no-op there (and emits a deprecation on 8.5). We only
	 * call it where it still does real work — PHP < 8.0 — keeping the
	 * function_exists guard for that path; on modern PHP the unset() is what
	 * actually releases the handle.
	 *
	 * @param \GdImage $image Handle to free.
	 */
	private function destroy( \GdImage $image ): void {
		if ( PHP_VERSION_ID < 80000 && function_exists( 'imagedestroy' ) ) {
			imagedestroy( $image ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- only runs on PHP < 8.0 where it still frees the resource.
		}
		unset( $image );
	}

	/** Clamp an opacity value into the inclusive 0–100 range. */
	private function clampOpacity( int $opacity ): int {
		return max( 0, min( 100, $opacity ) );
	}

	/**
	 * Allocate a GD colour from a #rgb/#rrggbb hex string, folding the given
	 * opacity into the alpha channel.
	 *
	 * @param \GdImage $image     Target image (palette context for the colour).
	 * @param string   $hex       Hex colour, with or without leading '#'.
	 * @param int      $opacity   0–100 (100 = fully opaque).
	 * @param int      $baseAlpha Extra GD alpha 0–127 to start from (0 = opaque).
	 * @return int Allocated colour identifier.
	 */
	private function allocateHex( \GdImage $image, string $hex, int $opacity, int $baseAlpha = 0 ): int {
		[ $r, $g, $b ] = $this->hexToRgb( $hex );
		$opacity       = $this->clampOpacity( $opacity );
		// Combine the requested opacity with any base alpha, on GD's 0..127 scale.
		$alpha = 127 - (int) round( ( 127 - max( 0, min( 127, $baseAlpha ) ) ) * $opacity / 100 );
		$alpha = max( 0, min( 127, $alpha ) );

		$color = imagecolorallocatealpha( $image, $r, $g, $b, $alpha );
		return false === $color ? imagecolorallocatealpha( $image, 0, 0, 0, 0 ) : $color;
	}

	/**
	 * Parse a #rgb or #rrggbb hex colour into [r,g,b] (0–255), defaulting to
	 * white on anything unparseable so a bad colour never throws mid-paint.
	 *
	 * @param string $hex Hex colour string.
	 * @return array{0:int,1:int,2:int}
	 */
	private function hexToRgb( string $hex ): array {
		$hex = ltrim( trim( $hex ), '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) || 1 !== preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return [ 255, 255, 255 ];
		}

		return [
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		];
	}
}
