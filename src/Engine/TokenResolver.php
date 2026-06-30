<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Substitutes the supported tokens in a text-watermark template, resolving them
 * against the current site at render time.
 *
 *   {site} → get_bloginfo( 'name' )
 *   {year} → wp_date( 'Y' )           (site-timezone year)
 *   {copy} → ©
 *   {url}  → host of home_url()       (e.g. "example.com")
 *
 * Unknown tokens are left intact (so a typo shows up literally rather than
 * silently vanishing). Resolution happens before the driver measures the string
 * so the placement math sees the final text.
 */
final class TokenResolver {

	/** The copyright glyph {copy} expands to. */
	private const COPY = "\u{00A9}";

	public static function resolve( string $template ): string {
		$replacements = [
			'{site}' => (string) get_bloginfo( 'name' ),
			'{year}' => (string) wp_date( 'Y' ),
			'{copy}' => self::COPY,
			'{url}'  => self::host(),
		];

		return strtr( $template, $replacements );
	}

	/** Host portion of the site home URL, or '' when it cannot be parsed. */
	private static function host(): string {
		$home = (string) home_url();
		$host = wp_parse_url( $home, PHP_URL_HOST );
		return is_string( $host ) ? $host : '';
	}
}
