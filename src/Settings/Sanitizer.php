<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The `register_setting` sanitize callback for `ogwm_settings`.
 *
 * Never trusts the input shape: a partial or hostile POST always yields a
 * complete, valid option array. Every value is whitelisted (enums), clamped
 * (numeric ranges), absint-ed (pixel values), hex-validated (colors), or
 * coerced (booleans), falling back to the Options defaults when invalid.
 */
final class Sanitizer {

	/** Allowed watermark types. */
	private const TYPES = [ 'text', 'logo' ];

	/** Allowed legibility treatments. */
	private const TREATMENTS = [ 'stroke', 'shadow', 'none' ];

	/** Allowed 9-point grid positions. */
	private const POSITIONS = [
		'top-left',
		'top-center',
		'top-right',
		'mid-left',
		'mid-center',
		'mid-right',
		'bot-left',
		'bot-center',
		'bot-right',
	];

	/**
	 * @param mixed $input Raw POST value (untrusted; may not be an array).
	 * @return array<string,mixed> Complete, valid settings array.
	 */
	public static function sanitize( $input ): array {
		$defaults = ( new Options() )->defaults();
		$in       = is_array( $input ) ? $input : [];

		return [
			'watermark' => self::sanitizeWatermark( self::group( $in, 'watermark' ), $defaults['watermark'] ),
			'placement' => self::sanitizePlacement( self::group( $in, 'placement' ), $defaults['placement'] ),
			'sizing'    => self::sanitizeSizing( self::group( $in, 'sizing' ), $defaults['sizing'] ),
			'sizes'     => self::sanitizeSizes( self::group( $in, 'sizes' ), $defaults['sizes'] ),
			'output'    => self::sanitizeOutput( self::group( $in, 'output' ), $defaults['output'] ),
			'advanced'  => self::sanitizeAdvanced( self::group( $in, 'advanced' ), $defaults['advanced'] ),
		];
	}

	/**
	 * @param array<string,mixed> $in
	 * @param array<string,mixed> $def
	 * @return array<string,mixed>
	 */
	private static function sanitizeWatermark( array $in, array $def ): array {
		return [
			'type'            => self::enum( $in['type'] ?? null, self::TYPES, $def['type'] ),
			'logo_id'         => isset( $in['logo_id'] ) ? absint( $in['logo_id'] ) : (int) $def['logo_id'],
			'text'            => isset( $in['text'] ) ? sanitize_text_field( (string) $in['text'] ) : (string) $def['text'],
			'text_color'      => self::hex( $in['text_color'] ?? null, $def['text_color'] ),
			'treatment'       => self::enum( $in['treatment'] ?? null, self::TREATMENTS, $def['treatment'] ),
			'treatment_color' => self::hex( $in['treatment_color'] ?? null, $def['treatment_color'] ),
			'text_scale_pct'  => self::clamp( $in['text_scale_pct'] ?? null, 1, 30, (int) $def['text_scale_pct'] ),
		];
	}

	/**
	 * @param array<string,mixed> $in
	 * @param array<string,mixed> $def
	 * @return array<string,mixed>
	 */
	private static function sanitizePlacement( array $in, array $def ): array {
		return [
			'position'   => self::enum( $in['position'] ?? null, self::POSITIONS, $def['position'] ),
			'margin_pct' => self::clamp( $in['margin_pct'] ?? null, 0, 25, (int) $def['margin_pct'] ),
		];
	}

	/**
	 * @param array<string,mixed> $in
	 * @param array<string,mixed> $def
	 * @return array<string,mixed>
	 */
	private static function sanitizeSizing( array $in, array $def ): array {
		$min = isset( $in['min_px'] ) ? absint( $in['min_px'] ) : (int) $def['min_px'];
		$max = isset( $in['max_px'] ) ? absint( $in['max_px'] ) : (int) $def['max_px'];

		// Ensure min <= max; if inverted, swap so the range stays usable.
		if ( $min > $max ) {
			[ $min, $max ] = [ $max, $min ];
		}

		return [
			'scale_pct' => self::clamp( $in['scale_pct'] ?? null, 1, 100, (int) $def['scale_pct'] ),
			'min_px'    => $min,
			'max_px'    => $max,
			'opacity'   => self::clamp( $in['opacity'] ?? null, 0, 100, (int) $def['opacity'] ),
		];
	}

	/**
	 * @param array<string,mixed> $in
	 * @param array<string,mixed> $def
	 * @return array<string,mixed>
	 */
	private static function sanitizeSizes( array $in, array $def ): array {
		$enabled = [];
		if ( isset( $in['enabled'] ) && is_array( $in['enabled'] ) ) {
			// Slug list (registered size names + 'full'); de-duplicate, drop empties.
			$enabled = array_values(
				array_unique(
					array_filter(
						array_map(
							static fn( $slug ): string => sanitize_key( (string) $slug ),
							$in['enabled']
						)
					)
				)
			);
			// sizes.enabled is a SET — order has no pixel meaning. Sort it so a
			// re-ordered checkbox submission yields a stable signature (and thus
			// no needless reprocess-from-backup churn).
			sort( $enabled );
		}

		return [
			'enabled'          => $enabled,
			'min_dimension_px' => isset( $in['min_dimension_px'] )
				? absint( $in['min_dimension_px'] )
				: (int) $def['min_dimension_px'],
		];
	}

	/**
	 * @param array<string,mixed> $in
	 * @param array<string,mixed> $def
	 * @return array<string,mixed>
	 */
	private static function sanitizeOutput( array $in, array $def ): array {
		return [
			'jpeg_bg'               => self::hex( $in['jpeg_bg'] ?? null, $def['jpeg_bg'] ),
			'srcset_hide_unstamped' => self::boolean( $in['srcset_hide_unstamped'] ?? null ),
		];
	}

	/**
	 * @param array<string,mixed> $in
	 * @param array<string,mixed> $def
	 * @return array<string,mixed>
	 */
	private static function sanitizeAdvanced( array $in, array $def ): array {
		return [
			'delete_data_on_uninstall'    => self::boolean( $in['delete_data_on_uninstall'] ?? null ),
			'delete_backups_on_uninstall' => self::boolean( $in['delete_backups_on_uninstall'] ?? null ),
		];
	}

	/**
	 * Pull a group sub-array, tolerating a missing or non-array group.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	private static function group( array $input, string $key ): array {
		return ( isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) ? $input[ $key ] : [];
	}

	/**
	 * Whitelist a value against an allowed set, falling back to a default.
	 *
	 * @param mixed         $value
	 * @param array<string> $allowed
	 */
	private static function enum( $value, array $allowed, string $default ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Clamp a numeric value into [min, max], falling back to a default when
	 * the input is non-numeric.
	 *
	 * @param mixed $value
	 */
	private static function clamp( $value, int $min, int $max, int $default ): int {
		if ( ! is_numeric( $value ) ) {
			return $default;
		}
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Validate a hex color via WordPress, falling back to a default.
	 *
	 * @param mixed $value
	 */
	private static function hex( $value, string $default ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$clean = sanitize_hex_color( $value );
		return ( is_string( $clean ) && '' !== $clean ) ? $clean : $default;
	}

	/**
	 * Coerce a checkbox/boolean-ish value to a real bool.
	 *
	 * @param mixed $value
	 */
	private static function boolean( $value ): bool {
		return (bool) rest_sanitize_boolean( $value );
	}
}
