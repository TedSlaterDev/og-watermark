<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Admin;

use OrchardGrove\OgWatermark\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The global "watermarking is disabled" heads-up (SPEC M8 — "Compatibility &
 * edge cases", capability gating).
 *
 * When the host has neither a usable Imagick nor GD, nothing can stamp an image,
 * so every apply control is inert. M7 already disables the per-image + bulk
 * controls on the settings/media surfaces; THIS notice is the one site-wide
 * banner that tells the admin WHY, on every admin screen, so a confused operator
 * is never left wondering why "Apply" does nothing.
 *
 * It is a single, escaped, dismissible `notice-error`. It renders ONLY when
 * {@see Capabilities::isUsable()} is false — a healthy host shows nothing.
 */
final class CapabilityNotice {

	/** Wire the admin_notices hook. Called from {@see Admin::register()}. */
	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	/**
	 * Print the notice when no usable image driver is present. A no-op otherwise,
	 * so a host with Imagick or GD never sees it.
	 */
	public static function render(): void {
		if ( Capabilities::isUsable() ) {
			return;
		}

		$message = __( 'OG Watermark is disabled: this server has neither Imagick nor GD with image support, so the plugin cannot stamp watermarks onto your images. Ask your host to enable the PHP Imagick or GD extension, then revisit the settings.', 'og-watermark' );

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
