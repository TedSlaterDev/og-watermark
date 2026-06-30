<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * The raster-driver contract. Two implementations (Imagick preferred, GD
 * fallback) fulfil it identically; the Compositor and the rest of the plugin
 * only ever talk to this interface, never to a concrete driver.
 *
 * A driver wraps a single image handle through its lifecycle: load → read
 * dimensions → paint (logo or text) → optionally flatten → save → free. All
 * geometry arrives as a driver-agnostic Placement (absolute pixels), so the
 * driver does no placement math of its own.
 *
 * Orientation is NOT the driver's concern: WordPress core regenerates sizes
 * from already-oriented pixels, so a driver must never call autoOrient() /
 * imagerotate(). It composites onto the pixels exactly as loaded.
 */
interface Watermarker {

	/**
	 * Decode a raster file into an internal handle.
	 *
	 * @param string $path Absolute path to a readable image file.
	 * @return bool True on success.
	 * @throws ImageException On an unrecoverable decode failure.
	 */
	public function load( string $path ): bool;

	/**
	 * Pixel dimensions of the loaded image.
	 *
	 * @return array{0:int,1:int} [ width, height ].
	 */
	public function dimensions(): array;

	/**
	 * Composite a logo PNG onto the loaded image at the given placement,
	 * preserving the logo's per-pixel alpha and folding in global opacity.
	 *
	 * @param string    $logoPath Absolute path to a validated local PNG.
	 * @param Placement $p        Where/how big to draw + opacity.
	 * @throws ImageException On a paint failure.
	 */
	public function paint_logo( string $logoPath, Placement $p ): void;

	/**
	 * Render text (with the spec's treatment) onto the loaded image at the
	 * given placement.
	 *
	 * @param TextSpec  $t The resolved text, font, size, color and treatment.
	 * @param Placement $p Where to draw + opacity.
	 * @throws ImageException On a paint failure.
	 */
	public function paint_text( TextSpec $t, Placement $p ): void;

	/**
	 * Flatten any transparency onto the given background so the image can be
	 * encoded as JPEG without black/garbage where alpha was. A no-op when the
	 * image is already fully opaque.
	 *
	 * @param string $hexBg Background color, e.g. '#ffffff'.
	 */
	public function flatten( string $hexBg ): void;

	/**
	 * Encode the loaded image to a destination path in the given MIME type.
	 *
	 * The MIME is honored exactly — the driver never changes the format or
	 * extension (that would stale WP's metadata and break srcset).
	 *
	 * @param string $dest    Absolute destination path.
	 * @param string $mime    Target MIME type (e.g. 'image/jpeg').
	 * @param int    $quality Lossy quality 0–100 (ignored by lossless formats).
	 * @return bool True on success.
	 * @throws ImageException On an encode/write failure.
	 */
	public function save( string $dest, string $mime, int $quality ): bool;

	/** Release the image handle and any associated driver resources. */
	public function free(): void;
}
