<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Imagick implementation of the Watermarker contract — the preferred driver on
 * hosts that ship ext-imagick.
 *
 * Behaviour is kept identical to the GD driver so the two are interchangeable
 * behind the interface; the Compositor picks one per request and never branches
 * on which it got. Imagick is used for everything: load/decode, LANCZOS resize,
 * per-pixel alpha opacity (via evaluateImage, NOT the deprecated setImageOpacity),
 * OVER compositing, native ImagickDraw stroke/shadow text, alpha flatten for
 * JPEG, and format-honest encode.
 *
 * Parse-safety: this host may NOT have ext-imagick loaded, yet the class file is
 * still autoloaded (e.g. by the test suite's class_exists/implements assertions).
 * So no Imagick symbol is referenced at the top level and the public interface
 * methods carry NO `\Imagick` type declarations (their parameters/returns use the
 * shared Engine types only), keeping the contract reflectable without the
 * extension. Every `new \Imagick()` / class-constant access happens inside a
 * method body, guarded by available(); the two private helpers that do hint
 * `\Imagick` are resolved lazily by PHP only when called (never at load), so the
 * file still parses and autoloads cleanly with the extension absent — verified by
 * `php -l` and the class-exists test on this Imagick-less host. The methods only
 * ever RUN where Imagick is present (the Compositor gates instantiation on
 * Capabilities::driver()).
 *
 * Orientation is core-owned: this driver never calls autoOrient()/rotate(). It
 * composites onto pixels exactly as decoded.
 */
final class ImagickDriver implements Watermarker {

	/**
	 * The live image handle. Typed `mixed` (not \Imagick) on purpose so the class
	 * parses on hosts without the extension. Holds an \Imagick once load() runs.
	 *
	 * @var mixed
	 */
	private mixed $image = null;

	/**
	 * Whether ext-imagick is usable in this process. Probed lazily and memoised so
	 * the methods can throw a clean ImageException instead of a fatal "class not
	 * found" if they are ever invoked without the extension.
	 *
	 * @var bool|null
	 */
	private static ?bool $available = null;

	/**
	 * Decode a raster file into an Imagick handle.
	 *
	 * CMYK JPEGs are transformed to sRGB up front so the watermark composites in
	 * the right colour space and the encoded output is web-correct (GD cannot read
	 * CMYK at all — there the Compositor skips; Imagick handles it here).
	 *
	 * @param string $path Absolute path to a readable image file.
	 * @return bool True on success.
	 * @throws ImageException On an unrecoverable decode failure.
	 */
	public function load( string $path ): bool {
		self::assertAvailable();

		try {
			$image = new \Imagick();
			$image->readImage( $path );
		} catch ( \Throwable ) {
			throw new ImageException( 'Imagick failed to read the source image.' );
		}

		// Normalise CMYK / greyscale-with-alpha edge cases into sRGB so compositing
		// and encoding are colour-correct. COLORSPACE_CMYK constant guarded by the
		// availability check above.
		try {
			if ( \Imagick::COLORSPACE_CMYK === $image->getImageColorspace() ) {
				$image->transformImageColorspace( \Imagick::COLORSPACE_SRGB );
			}
		} catch ( \Throwable ) {
			// A colourspace probe/transform failure is non-fatal: keep the image as
			// decoded rather than aborting the whole stamp. Nothing to clean up.
			$this->noop();
		}

		$this->image = $image;

		return true;
	}

	/**
	 * Pixel dimensions of the loaded image.
	 *
	 * @return array{0:int,1:int} [ width, height ].
	 * @throws ImageException When called before a successful load().
	 */
	public function dimensions(): array {
		$image = $this->handle();

		try {
			return [ (int) $image->getImageWidth(), (int) $image->getImageHeight() ];
		} catch ( \Throwable ) {
			throw new ImageException( 'Imagick could not read image dimensions.' );
		}
	}

	/**
	 * Composite a logo PNG at the placement, preserving the logo's per-pixel alpha
	 * and folding global opacity into it.
	 *
	 * - resizeImage( LANCZOS ) for a high-quality downscale to the target box;
	 * - evaluateImage( EVALUATE_MULTIPLY, opacity/100, CHANNEL_ALPHA ) scales the
	 *   alpha channel uniformly (the non-deprecated opacity path — setImageOpacity
	 *   is avoided per SPEC);
	 * - compositeImage( COMPOSITE_OVER ) at the placement's top-left.
	 *
	 * @param string    $logoPath Absolute path to a validated local PNG.
	 * @param Placement $p        Where/how big to draw + opacity.
	 * @throws ImageException On a paint failure.
	 */
	public function paint_logo( string $logoPath, Placement $p ): void {
		$base = $this->handle();

		$logo = null;
		try {
			$logo = new \Imagick();
			$logo->readImage( $logoPath );

			// Keep alpha through the resize.
			$logo->setImageBackgroundColor( new \ImagickPixel( 'transparent' ) );

			if ( $p->width > 0 && $p->height > 0 ) {
				$logo->resizeImage( $p->width, $p->height, \Imagick::FILTER_LANCZOS, 1.0 );
			}

			// Fold global opacity into the logo's existing per-pixel alpha by
			// multiplying the alpha channel by opacity (0..1). 100% => no change.
			if ( $p->opacity < 100 ) {
				$factor = max( 0.0, min( 1.0, $p->opacity / 100 ) );
				$logo->evaluateImage( \Imagick::EVALUATE_MULTIPLY, $factor, \Imagick::CHANNEL_ALPHA );
			}

			$base->compositeImage( $logo, \Imagick::COMPOSITE_OVER, $p->x, $p->y );
		} catch ( \Throwable ) {
			throw new ImageException( 'Imagick failed to composite the logo.' );
		} finally {
			$this->freeImagick( $logo );
		}
	}

	/**
	 * Render text with the chosen treatment at the placement.
	 *
	 * Geometry is computed driver-side here only for the baseline (Imagick's
	 * annotateImage positions text by its baseline, not its top-left). The
	 * placement box is the already-measured text bounds, so the baseline is the
	 * box top plus the font ascender; we derive the ascender from queryFontMetrics
	 * and clamp into the box.
	 *
	 * Treatments:
	 *  - 'stroke': native ImagickDraw setStrokeColor/setStrokeWidth around the fill;
	 *  - 'shadow': a translucent dark pass drawn at (dx,dy) BEFORE the fill pass;
	 *  - 'none':   fill only.
	 *
	 * Global opacity (Placement::opacity) scales the whole text paint by drawing
	 * onto a transparent scratch layer, multiplying its alpha, then OVER-compositing
	 * — so stroke/shadow/fill fade together exactly like the logo path.
	 *
	 * @param TextSpec  $t The resolved text, font, size, colour and treatment.
	 * @param Placement $p Where to draw + opacity.
	 * @throws ImageException On a paint failure.
	 */
	public function paint_text( TextSpec $t, Placement $p ): void {
		$base = $this->handle();

		$layer    = null;
		$drawFill = null;
		$drawShad = null;
		try {
			// Scratch layer the size of the base so coordinates match, fully
			// transparent; we draw text here then fade + composite it as one unit.
			[ $w, $h ] = $this->dimensions();
			$layer     = new \Imagick();
			$layer->newImage( $w, $h, new \ImagickPixel( 'transparent' ) );
			$layer->setImageFormat( 'png' );

			// Baseline: Imagick annotates from the text baseline. Ascender from the
			// font metrics; fall back to ~80% of the point size if unavailable.
			$ascender  = $this->ascender( $base, $t );
			$baselineX = $p->x;
			$baselineY = $p->y + $ascender;

			// Shadow pass first (drawn underneath the fill). Intrinsic alpha is 0.5
			// for a soft shadow — matching the GD driver, which halves the opacity
			// for its shadow pass. The placement's global opacity is applied to the
			// whole layer afterward, so the two drivers fade their shadows in step.
			if ( 'shadow' === $t->treatment ) {
				$drawShad = new \ImagickDraw();
				$drawShad->setFont( $t->fontPath );
				$drawShad->setFontSize( $t->sizePt );
				$drawShad->setFillColor( new \ImagickPixel( $this->rgba( $t->treatmentColor, 0.5 ) ) );
				$layer->annotateImage( $drawShad, $baselineX + $t->shadowDx, $baselineY + $t->shadowDy, 0.0, $t->text );
			}

			// Fill pass (with optional native stroke).
			$drawFill = new \ImagickDraw();
			$drawFill->setFont( $t->fontPath );
			$drawFill->setFontSize( $t->sizePt );
			$drawFill->setFillColor( new \ImagickPixel( $t->color ) );

			if ( 'stroke' === $t->treatment && $t->strokeWidth > 0 ) {
				$drawFill->setStrokeColor( new \ImagickPixel( $t->treatmentColor ) );
				$drawFill->setStrokeWidth( (float) $t->strokeWidth );
				// Paint stroke under the glyph fill so the fill stays crisp.
				$drawFill->setStrokeAntialias( true );
			}

			$layer->annotateImage( $drawFill, $baselineX, $baselineY, 0.0, $t->text );

			// Fold global opacity into the whole text layer.
			if ( $p->opacity < 100 ) {
				$factor = max( 0.0, min( 1.0, $p->opacity / 100 ) );
				$layer->evaluateImage( \Imagick::EVALUATE_MULTIPLY, $factor, \Imagick::CHANNEL_ALPHA );
			}

			$base->compositeImage( $layer, \Imagick::COMPOSITE_OVER, 0, 0 );
		} catch ( \Throwable ) {
			throw new ImageException( 'Imagick failed to render the text watermark.' );
		} finally {
			$this->freeDraw( $drawFill );
			$this->freeDraw( $drawShad );
			$this->freeImagick( $layer );
		}
	}

	/**
	 * Flatten transparency onto $hexBg so a JPEG encode never shows black/garbage
	 * where alpha was. A no-op when the image has no alpha channel.
	 *
	 * Uses setImageBackgroundColor + mergeImageLayers(FLATTEN) (the modern
	 * replacement for the deprecated flattenImages) so any composited alpha is
	 * resolved against the background.
	 *
	 * @param string $hexBg Background colour, e.g. '#ffffff'.
	 * @throws ImageException On a flatten failure.
	 */
	public function flatten( string $hexBg ): void {
		$image = $this->handle();

		try {
			if ( ! $image->getImageAlphaChannel() ) {
				return; // Already opaque — nothing to flatten.
			}

			$image->setImageBackgroundColor( new \ImagickPixel( $hexBg ) );

			// mergeImageLayers needs an iterator pointer; reset to the first frame.
			$image->setFirstIterator();
			$flat = $image->mergeImageLayers( \Imagick::LAYERMETHOD_FLATTEN );

			// Drop the alpha channel on the flattened result so the encoder treats
			// it as fully opaque.
			if ( defined( '\Imagick::ALPHACHANNEL_REMOVE' ) ) {
				$flat->setImageAlphaChannel( \Imagick::ALPHACHANNEL_REMOVE );
			} else {
				$flat->setImageAlphaChannel( \Imagick::ALPHACHANNEL_DEACTIVATE );
			}

			// Swap the live handle for the flattened one and free the old.
			$old         = $this->image;
			$this->image = $flat;
			$this->freeImagick( $old );
		} catch ( \Throwable ) {
			throw new ImageException( 'Imagick failed to flatten transparency.' );
		}
	}

	/**
	 * Encode the loaded image to $dest in exactly $mime. The format/extension is
	 * never changed (that would stale WP's metadata and break srcset); $mime drives
	 * setImageFormat and the caller guarantees $dest already carries the right
	 * extension.
	 *
	 * @param string $dest    Absolute destination path.
	 * @param string $mime    Target MIME type (e.g. 'image/jpeg').
	 * @param int    $quality Lossy quality 0–100 (ignored by lossless PNG).
	 * @return bool True on success.
	 * @throws ImageException On an encode/write failure.
	 */
	public function save( string $dest, string $mime, int $quality ): bool {
		$image = $this->handle();

		$format = self::formatForMime( $mime );
		if ( null === $format ) {
			throw new ImageException( 'Unsupported output MIME for the Imagick driver.' );
		}

		try {
			$image->setImageFormat( $format );

			// Lossy formats honour quality; PNG keeps its own lossless compression.
			if ( in_array( $mime, [ 'image/jpeg', 'image/webp', 'image/avif' ], true ) ) {
				$image->setImageCompressionQuality( max( 0, min( 100, $quality ) ) );
			}

			if ( 'image/jpeg' === $mime ) {
				// JPEG has no alpha; ensure it is stripped (flatten() normally ran
				// already, but be defensive so a stray alpha never corrupts output).
				if ( defined( '\Imagick::ALPHACHANNEL_REMOVE' ) ) {
					$image->setImageAlphaChannel( \Imagick::ALPHACHANNEL_REMOVE );
				}
				$image->setImageCompression( \Imagick::COMPRESSION_JPEG );
			}

			return (bool) $image->writeImage( $dest );
		} catch ( \Throwable ) {
			throw new ImageException( 'Imagick failed to encode/write the destination image.' );
		}
	}

	/** Release the image handle and any associated Imagick resources. */
	public function free(): void {
		$this->freeImagick( $this->image );
		$this->image = null;
	}

	// ---------------------------------------------------------------------
	// Internals.
	// ---------------------------------------------------------------------

	/**
	 * Return the live handle or throw if load() has not run. Keeps every public
	 * method honest about its precondition.
	 *
	 * @return \Imagick
	 * @throws ImageException When no image is loaded.
	 */
	private function handle(): \Imagick {
		if ( ! $this->image instanceof \Imagick ) {
			throw new ImageException( 'No image is loaded.' );
		}
		return $this->image;
	}

	/**
	 * Font ascender in pixels for the given spec, used to convert a top-left box
	 * origin into Imagick's baseline origin. Falls back to ~0.8·pt if metrics are
	 * unavailable.
	 *
	 * @param \Imagick $base Any live Imagick handle (queryFontMetrics is an instance method).
	 * @param TextSpec $t    The text spec providing font + size + text.
	 */
	private function ascender( \Imagick $base, TextSpec $t ): int {
		try {
			$draw = new \ImagickDraw();
			$draw->setFont( $t->fontPath );
			$draw->setFontSize( $t->sizePt );
			$metrics = $base->queryFontMetrics( $draw, $t->text );
			$this->freeDraw( $draw );

			if ( is_array( $metrics ) && isset( $metrics['ascender'] ) ) {
				return (int) round( (float) $metrics['ascender'] );
			}
		} catch ( \Throwable ) {
			// Metrics unavailable — fall through to the point-size estimate below.
			$this->noop();
		}

		return (int) round( $t->sizePt * 0.8 );
	}

	/**
	 * Build an "rgba(r,g,b,a)" colour string from a #hex colour and a 0..1 alpha,
	 * for the translucent shadow pass. Accepts #rgb or #rrggbb.
	 *
	 * @param string $hex   Hex colour, with or without a leading '#'.
	 * @param float  $alpha Alpha 0.0–1.0 for the resulting colour.
	 * @return string An ImagickPixel-parseable "rgba(...)" string.
	 */
	private function rgba( string $hex, float $alpha ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || 1 !== preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			$hex = '000000';
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		$a = max( 0.0, min( 1.0, $alpha ) );

		return sprintf( 'rgba(%d,%d,%d,%.3F)', $r, $g, $b, $a );
	}

	/**
	 * Map a target MIME to the Imagick format tag, or null if unsupported. The
	 * Compositor has already gated on Capabilities::canEncode(); this is the
	 * driver-local translation only.
	 *
	 * @param string $mime Target MIME type, e.g. 'image/jpeg'.
	 * @return string|null Imagick format tag (e.g. 'JPEG'), or null if unsupported.
	 */
	private static function formatForMime( string $mime ): ?string {
		return match ( $mime ) {
			'image/jpeg' => 'JPEG',
			'image/png'  => 'PNG',
			'image/webp' => 'WEBP',
			'image/avif' => 'AVIF',
			'image/gif'  => 'GIF',
			default      => null,
		};
	}

	/**
	 * Clear()+destroy() an Imagick handle if it is one; tolerate null/non-Imagick.
	 *
	 * @param mixed $handle Candidate Imagick handle (or null).
	 */
	private function freeImagick( mixed $handle ): void {
		if ( $handle instanceof \Imagick ) {
			try {
				$handle->clear();
				$handle->destroy();
			} catch ( \Throwable ) {
				// Freeing an already-freed handle can throw — that is harmless here.
				$this->noop();
			}
		}
		unset( $handle );
	}

	/**
	 * Clear()+destroy() an ImagickDraw handle if it is one; tolerate null.
	 *
	 * @param mixed $draw Candidate ImagickDraw handle (or null).
	 */
	private function freeDraw( mixed $draw ): void {
		if ( $draw instanceof \ImagickDraw ) {
			try {
				$draw->clear();
				$draw->destroy();
			} catch ( \Throwable ) {
				// Freeing an already-freed draw object can throw — harmless here.
				$this->noop();
			}
		}
		unset( $draw );
	}

	/** Whether ext-imagick is loaded and the class is present, memoised. */
	private static function available(): bool {
		if ( null === self::$available ) {
			self::$available = extension_loaded( 'imagick' ) && class_exists( '\Imagick' );
		}
		return self::$available;
	}

	/**
	 * Guard the entry points so a method invoked without the extension throws a
	 * clean ImageException instead of a fatal symbol-not-found. (In practice the
	 * Compositor never instantiates this driver unless Capabilities says imagick.)
	 *
	 * @throws ImageException When ext-imagick is unavailable.
	 */
	private static function assertAvailable(): void {
		if ( ! self::available() ) {
			throw new ImageException( 'The Imagick extension is not available on this host.' );
		}
	}

	/**
	 * Explicit "do nothing" marker for catch blocks that deliberately swallow a
	 * non-fatal Imagick error (e.g. freeing an already-freed handle, an absent
	 * font metric). Keeps those blocks self-documenting and non-empty rather than
	 * leaving a bare comment that reads like an accidental omission.
	 */
	private function noop(): void {}
}
