<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Admin;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Support\Meta;
use WP_Post;

/**
 * The per-image opt-in surface that lives ON the attachment itself (SPEC "Admin UX
 * → per-image opt-in"). It hangs a "Watermark this image" control off the
 * `attachment_fields_to_edit` filter so the SAME control appears everywhere core
 * renders attachment fields: the media-modal sidebar, the grid "Attachment
 * details" view, and the classic edit-attachment screen.
 *
 * The field is THREE things in one cell:
 *   1. the opt-in checkbox (the `_ogwm_enabled` flag),
 *   2. a live status badge (rendered from the {@see Meta} status constants by
 *      {@see MediaColumn::badgeHtml()}, the single source of badge markup shared
 *      with the Media list column), and
 *   3. inline Apply / Remove buttons that media.js wires to the AJAX layer.
 *
 * The save half ({@see save()}) is the NO-JS fallback: when the checkbox is
 * toggled and the form is posted normally (no AJAX), it persists {@see Meta::ENABLED}
 * and seeds {@see Meta::STATUS} to {@see Meta::STATUS_QUEUED} so the attachment is
 * picked up by the next bulk run — it NEVER composites inline. Clearing the box
 * removes the flag; it does not restore (undo is an explicit Remove action).
 *
 * Non-image attachments (PDFs, audio, zips) are not watermarkable, so the field
 * renders nothing for them — the guard is `wp_attachment_is_image`.
 */
final class MediaField {

	/** The form field name the checkbox posts under (namespaced to avoid collisions). */
	private const FIELD = 'ogwm_enabled';

	/** Wire the edit + save halves of the attachment field. */
	public static function register(): void {
		add_filter( 'attachment_fields_to_edit', [ self::class, 'addField' ], 10, 2 );
		add_filter( 'attachment_fields_to_save', [ self::class, 'save' ], 10, 2 );
	}

	/**
	 * Inject the "Watermark this image" field into the attachment-edit field set.
	 * Returns the fields untouched for a non-image attachment so nothing renders.
	 *
	 * @param array<string,mixed> $fields The existing attachment fields.
	 * @param WP_Post             $post   The attachment post.
	 * @return array<string,mixed>
	 */
	public static function addField( array $fields, WP_Post $post ): array {
		$id = (int) $post->ID;

		// Only images are watermarkable — render NOTHING for any other attachment.
		if ( ! wp_attachment_is_image( $id ) ) {
			return $fields;
		}

		$fields[ self::FIELD ] = [
			'label' => __( 'OG Watermark', 'og-watermark' ),
			'input' => 'html',
			'html'  => self::controlHtml( $id ),
		];

		return $fields;
	}

	/**
	 * Persist the opt-in flag from a NO-JS form save. Toggling the box on flags the
	 * attachment ({@see Meta::ENABLED} = '1') and seeds {@see Meta::STATUS_QUEUED} so
	 * the next bulk run picks it up; toggling it off removes the flag. NO compositing
	 * happens here — this only marks intent.
	 *
	 * Returns $post unchanged (this filter is consumed for its side effect only).
	 *
	 * @param array<string,mixed> $post       The attachment post array (passthrough).
	 * @param array<string,mixed> $attachment The submitted attachment field values.
	 * @return array<string,mixed>
	 */
	public static function save( array $post, array $attachment ): array {
		$id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
			return $post;
		}

		// The checkbox is only present in the submitted set when our field rendered,
		// so a save from a screen WITHOUT the field never clears the flag.
		if ( ! array_key_exists( self::FIELD, $attachment ) ) {
			return $post;
		}

		$enabled = ! empty( $attachment[ self::FIELD ] );

		if ( $enabled ) {
			update_post_meta( $id, Meta::ENABLED, '1' );

			// Seed the queued status ONLY when the attachment isn't already in a
			// committed lifecycle state (don't stomp 'watermarked'/'failed' just
			// because the box stayed ticked through an unrelated save).
			$current = (string) get_post_meta( $id, Meta::STATUS, true );
			if ( '' === $current || Meta::STATUS_NONE === $current || Meta::STATUS_RESTORED === $current ) {
				update_post_meta( $id, Meta::STATUS, Meta::STATUS_QUEUED );
			}
		} else {
			delete_post_meta( $id, Meta::ENABLED );
		}

		return $post;
	}

	/**
	 * The inner HTML for the field cell: the checkbox, the status badge, and the
	 * inline Apply/Remove buttons media.js binds to. Every dynamic value is escaped.
	 */
	private static function controlHtml( int $id ): string {
		$enabled = '1' === (string) get_post_meta( $id, Meta::ENABLED, true );

		$checkboxId = 'ogwm-enabled-' . $id;

		$html  = '<span class="ogwm-field" data-id="' . esc_attr( (string) $id ) . '">';
		$html .= '<label class="ogwm-field-toggle" for="' . esc_attr( $checkboxId ) . '">';
		$html .= '<input type="checkbox" id="' . esc_attr( $checkboxId ) . '"'
			. ' name="attachments[' . esc_attr( (string) $id ) . '][' . esc_attr( self::FIELD ) . ']"'
			. ' value="1" class="ogwm-enabled-checkbox"' . checked( $enabled, true, false ) . ' /> ';
		$html .= esc_html__( 'Watermark this image', 'og-watermark' );
		$html .= '</label>';

		$html .= ' <span class="ogwm-field-badge">' . MediaColumn::badgeHtml( $id ) . '</span>';

		$html .= '<span class="ogwm-field-actions">';
		$html .= '<button type="button" class="button button-small ogwm-apply" data-id="' . esc_attr( (string) $id ) . '">'
			. esc_html__( 'Apply now', 'og-watermark' ) . '</button> ';
		$html .= '<button type="button" class="button button-small ogwm-remove" data-id="' . esc_attr( (string) $id ) . '">'
			. esc_html__( 'Remove', 'og-watermark' ) . '</button>';
		$html .= '<span class="spinner ogwm-field-spinner"></span>';
		$html .= '</span>';

		$html .= '</span>';

		return $html;
	}
}
