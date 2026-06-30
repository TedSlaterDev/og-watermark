<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical idempotency signature for a watermarked attachment.
 *
 * The signature fingerprints everything that can change the baked-in pixels:
 * the pixel-affecting settings groups, the CONTENT of the resolved logo and
 * font files (so "Replace Media" or a bundled-asset swap is detected, not just
 * an attachment ID), and the plugin version. When a stored signature no longer
 * matches the recomputed one, the processor (M3) reprocesses the image from its
 * pristine backup.
 *
 * Deliberately decoupled from WordPress: this class is pure and deterministic,
 * so it unit-tests without any WP stubs. Settings that have no effect on the
 * rendered pixels (the `advanced` group and `output.srcset_hide_unstamped`)
 * MUST NOT influence the result. The one exception inside `output` is
 * `jpeg_bg` — the alpha→JPEG flatten color, which DOES change baked pixels and
 * therefore IS included (see Signature::PIXEL_KEYS).
 */
final class Signature {

	/**
	 * Settings groups that affect the rendered pixels, in a fixed order so the
	 * serialization (and therefore the hash) is stable regardless of input order.
	 */
	private const PIXEL_GROUPS = [ 'watermark', 'placement', 'sizing', 'sizes' ];

	/**
	 * Individual pixel-affecting keys pulled from groups that are otherwise
	 * non-pixel. `output.jpeg_bg` is the flatten color used when an alpha source
	 * is encoded to JPEG, so it genuinely changes baked-in pixels and MUST
	 * invalidate the signature (per SPEC). The rest of `output`
	 * (srcset_hide_unstamped) is render-irrelevant and stays excluded.
	 *
	 * Map of group => list of keys to include.
	 *
	 * @var array<string,string[]>
	 */
	private const PIXEL_KEYS = [
		'output' => [ 'jpeg_bg' ],
	];

	/**
	 * Compute the 16-hex-char signature.
	 *
	 * @param array       $settings Full (or partial) settings array.
	 * @param string|null $logoPath Absolute path to the resolved logo file, or null/'' for text mode.
	 * @param string|null $fontPath Absolute path to the resolved font file, or null/'' when none.
	 */
	public static function compute( array $settings, ?string $logoPath, ?string $fontPath ): string {
		$normalized = self::normalize( $settings );

		$payload = serialize( $normalized )
			. '|' . self::hashFile( $logoPath )
			. '|' . self::hashFile( $fontPath )
			. '|' . self::version();

		return substr( sha1( $payload ), 0, 16 );
	}

	/**
	 * Reduce the settings to only the pixel-affecting groups, recursively sorted
	 * by key, so logically-identical settings always serialize identically.
	 *
	 * @param array $settings Full settings array.
	 * @return array Normalized, key-sorted subset.
	 */
	private static function normalize( array $settings ): array {
		$subset = [];
		foreach ( self::PIXEL_GROUPS as $group ) {
			$subset[ $group ] = isset( $settings[ $group ] ) && is_array( $settings[ $group ] )
				? $settings[ $group ]
				: [];
		}

		// Include only the explicitly pixel-affecting keys from otherwise
		// non-pixel groups (e.g. output.jpeg_bg), so changing the JPEG flatten
		// color reprocesses but toggling srcset_hide_unstamped does not.
		foreach ( self::PIXEL_KEYS as $group => $keys ) {
			$source = isset( $settings[ $group ] ) && is_array( $settings[ $group ] ) ? $settings[ $group ] : [];
			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $source ) ) {
					$subset[ $group ][ $key ] = $source[ $key ];
				}
			}
		}

		self::ksortRecursive( $subset );
		return $subset;
	}

	/**
	 * Recursively sort associative arrays by key in place. List arrays (e.g. the
	 * enabled-sizes list) keep their order, which is meaningful for them.
	 *
	 * @param array $array Array to sort in place.
	 */
	private static function ksortRecursive( array &$array ): void {
		if ( self::isList( $array ) ) {
			foreach ( $array as &$value ) {
				if ( is_array( $value ) ) {
					self::ksortRecursive( $value );
				}
			}
			unset( $value );
			return;
		}
		ksort( $array );
		foreach ( $array as &$value ) {
			if ( is_array( $value ) ) {
				self::ksortRecursive( $value );
			}
		}
		unset( $value );
	}

	/**
	 * sha1 of a file's CONTENT, or the sha1 of an empty string when the path is
	 * null/empty/unreadable. Guarding here keeps the signature stable and never
	 * fatals on a missing asset.
	 */
	private static function hashFile( ?string $path ): string {
		if ( null === $path || '' === $path || ! is_string( $path ) || ! is_file( $path ) ) {
			return sha1( '' );
		}
		$hash = @sha1_file( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $hash ? sha1( '' ) : $hash;
	}

	/**
	 * Plugin version, with a deterministic fallback so the class is testable in
	 * isolation (the constant is defined by the main plugin file at runtime).
	 */
	private static function version(): string {
		return defined( 'OGWM_VERSION' ) ? (string) OGWM_VERSION : '0.0.0';
	}

	/**
	 * Whether $array is a zero-indexed sequential list (vs. an associative map).
	 */
	private static function isList( array $array ): bool {
		if ( [] === $array ) {
			return false; // Treat empty as a map; harmless for hashing, avoids resorting nothing.
		}
		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}
}
