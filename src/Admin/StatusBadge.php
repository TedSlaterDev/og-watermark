<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Admin;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Support\Meta;

/**
 * The status-badge boundary for the AJAX layer (SPEC M7: the AJAX apply/remove
 * responses surface a status pill that must match the one the Media column first
 * rendered).
 *
 * This is NOT a second badge renderer — that was the source of markup drift. The
 * single source of badge markup is {@see MediaColumn::badgeHtml()}; this class
 * merely delegates to it ({@see forAttachment()}) and shares the SAME wp_kses
 * allowlist ({@see kses()} → {@see MediaColumn::badgeKses()}), so the fragment the
 * AJAX swap returns is byte-for-byte the same vocabulary (classes, data-status,
 * title, the nested date span) the initial server render produced. It also owns
 * the "out of date" signature check ({@see isStale()}) that the column consults.
 */
final class StatusBadge {

	/**
	 * The badge fragment for an attachment's current status. Delegates to the
	 * single source of badge markup so the AJAX response and the column cell never
	 * diverge.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function forAttachment( int $id ): string {
		return MediaColumn::badgeHtml( $id );
	}

	/**
	 * The wp_kses allowlist for a badge fragment crossing the AJAX boundary — the
	 * SAME allowlist the column render uses, so nothing is stripped on the swap.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowedHtml(): array {
		return MediaColumn::badgeKses();
	}

	/** Pass a rendered badge fragment through wp_kses with the shared allowlist. */
	public static function kses( string $html ): string {
		return wp_kses( $html, self::allowedHtml() );
	}

	/**
	 * Whether a watermarked attachment is now out of date (its stored signature no
	 * longer matches the current settings). Best-effort: if the Signature helper
	 * or settings are unavailable, report not-out-of-date rather than guessing.
	 * Consulted by {@see MediaColumn::badgeHtml()} for the "Out of date" pill.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function isStale( int $id ): bool {
		$stored = (string) get_post_meta( $id, Meta::SIGNATURE, true );
		if ( '' === $stored ) {
			return false;
		}

		if ( ! class_exists( \OrchardGrove\OgWatermark\Settings\Options::class )
			|| ! class_exists( \OrchardGrove\OgWatermark\Support\Signature::class ) ) {
			return false;
		}

		$options  = new \OrchardGrove\OgWatermark\Settings\Options();
		$settings = $options->all();

		$logoPath = null;
		if ( 'logo' === ( $settings['watermark']['type'] ?? '' ) ) {
			$logoId   = (int) ( $settings['watermark']['logo_id'] ?? 0 );
			$logoPath = \OrchardGrove\OgWatermark\Engine\LogoAsset::resolvePath( $logoId );
		}

		$fontPath = defined( 'OGWM_DIR' ) ? OGWM_DIR . 'assets/fonts/DejaVuSans-Bold.ttf' : '';

		$current = \OrchardGrove\OgWatermark\Support\Signature::compute( $settings, $logoPath, $fontPath );

		return ! hash_equals( $stored, $current );
	}
}
