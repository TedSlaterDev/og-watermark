<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a logo attachment ID to a validated, real, local PNG file path.
 *
 * The watermark logo must be a genuine PNG on local disk (alpha compositing
 * relies on it, and SVG/polyglot uploads are rejected outright — see the
 * security checklist). resolvePath() returns the absolute path only when every
 * check passes; otherwise null, so callers degrade rather than feed garbage to
 * the engine.
 */
final class LogoAsset {

	/**
	 * Absolute path to the attachment's PNG file, or null when the id is not a
	 * usable local PNG.
	 *
	 * Checks, in order: a positive id; get_attached_file() returns a path;
	 * realpath() resolves (the file truly exists on local disk); and
	 * getimagesize() reports IMAGETYPE_PNG (content sniff, not extension).
	 */
	public static function resolvePath( int $logoId ): ?string {
		if ( $logoId < 1 ) {
			return null;
		}

		$path = get_attached_file( $logoId );
		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}

		$real = realpath( $path );
		if ( false === $real ) {
			return null;
		}

		$info = getimagesize( $real );
		if ( ! is_array( $info ) || ! isset( $info[2] ) || IMAGETYPE_PNG !== $info[2] ) {
			return null;
		}

		return $real;
	}
}
