<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Pure format-policy helper — the single source of truth for which MIME types
 * the engine will stamp, which need an alpha→opaque flatten before encode, and
 * whether a GIF on disk is animated (and must therefore be skipped).
 *
 * Deliberately free of GD/Imagick/WordPress so the policy is unit-testable in
 * isolation. The Compositor layers driver-capability checks (Capabilities::
 * canEncode) and CMYK/load failures on top of these decisions; this class only
 * answers "is this format eligible at all?".
 */
final class MimePolicy {

	/**
	 * MIME types the engine is willing to stamp, format-wise. WebP/AVIF appear
	 * here because the format is eligible in principle; whether the *active
	 * driver* can actually encode them is a separate gate the Compositor applies
	 * via Capabilities::canEncode(). Animated GIF is excluded at stamp time by
	 * the byte sniff below even though image/gif is listed (static GIF is fine).
	 */
	private const STAMPABLE = [
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/avif',
		'image/gif',
	];

	/** Whether the engine will ever stamp this MIME type (format policy only). */
	public static function isStampable( string $mime ): bool {
		return in_array( strtolower( trim( $mime ) ), self::STAMPABLE, true );
	}

	/**
	 * Whether output of this MIME type requires flattening transparency onto a
	 * solid background before encode. Only JPEG (no alpha channel) needs it; PNG
	 * and WebP/AVIF preserve alpha and GIF is handled by its own path.
	 */
	public static function needsFlatten( string $mime ): bool {
		return 'image/jpeg' === strtolower( trim( $mime ) );
	}

	/**
	 * Sniff whether the file at $path is an animated GIF by reading its header
	 * bytes — no GD decode, no full read. An animated GIF has more than one
	 * image frame, each introduced by an Image Descriptor block (0x2C). We scan
	 * for a second image-separator that follows a Graphic Control Extension
	 * (0x21 0xF9), which is how multi-frame GIFs delimit frames.
	 *
	 * Returns false for a missing/unreadable file or a non-GIF (the Compositor
	 * has already gated on MIME, so a false here means "static GIF, stamp it").
	 * Conservative on ambiguity: any read error reports "not animated" so a
	 * single still frame is still stampable rather than wrongly skipped.
	 */
	public static function isAnimatedGif( string $path ): bool {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $handle ) {
			return false;
		}

		$bytes = '';
		$read  = 0;
		// Read in chunks; bail as soon as we can decide. 256 KiB is plenty for the
		// first couple of frame markers on any real GIF while bounding memory.
		$limit = 256 * 1024;
		while ( ! feof( $handle ) && $read < $limit ) {
			$chunk = fread( $handle, 8192 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$bytes .= $chunk;
			$read  += strlen( $chunk );
		}
		fclose( $handle );

		return self::sniffAnimatedGifBytes( $bytes );
	}

	/**
	 * The pure byte-level animated-GIF test, exposed for unit testing on crafted
	 * samples. A GIF is animated when it contains two or more frames, and each
	 * frame is introduced by a Graphic Control Extension block whose first two
	 * bytes are the extension introducer `0x21` and the graphic-control label
	 * `0xF9`. Counting `0x21 0xF9` occurrences is the long-standing, encoder-
	 * agnostic heuristic used by WordPress core and common sniffers: two or more
	 * GCEs means an animation. A leading scan for the full marker `0x21 0xF9 0x04`
	 * (block-size byte is always 4) keeps the count from matching stray bytes
	 * inside pixel data.
	 *
	 * @param string $bytes Raw leading bytes of a candidate GIF.
	 */
	public static function sniffAnimatedGifBytes( string $bytes ): bool {
		// Must be a GIF at all; GIF87a predates the GCE block and is never animated.
		if ( 0 !== strncmp( $bytes, 'GIF89a', 6 ) ) {
			return false;
		}

		$frames = 0;
		$len    = strlen( $bytes );
		$offset = 0;

		// Graphic Control Extension marker: 0x21 (introducer) 0xF9 (label) 0x04
		// (fixed block size). Two or more frames => animated.
		while ( false !== ( $offset = strpos( $bytes, "\x21\xF9\x04", $offset ) ) ) {
			++$frames;
			if ( $frames > 1 ) {
				return true;
			}
			$offset += 3;
		}

		return false;
	}
}
