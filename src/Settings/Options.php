<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Settings;

defined( 'ABSPATH' ) || exit;

// Sanitizer lives in the same namespace; referenced by save() to guarantee no
// settings write bypasses validation.

/**
 * Typed accessor over the single autoloaded option row (`ogwm_settings`).
 *
 * Stored values are deep-merged over defaults once and read by dotted path:
 *   $options->str( 'watermark.type' );
 *   $options->int( 'sizing.opacity' );
 *   $options->bool( 'advanced.delete_data_on_uninstall' );
 *
 * This grouped shape is the single source of truth for the option array — the
 * Sanitizer and the Signature both agree on it.
 */
final class Options {

	public const OPTION = 'ogwm_settings';

	private array $data;
	private ?array $merged = null;

	public function __construct() {
		$stored     = get_option( self::OPTION, [] );
		$this->data = is_array( $stored ) ? $stored : [];
	}

	/**
	 * The full grouped default shape. Every key the plugin ever reads lives here.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		$defaults = [
			'watermark' => [
				'type'            => 'text',          // 'text' | 'logo'.
				'logo_id'         => 0,               // PNG attachment ID; re-verified on use.
				'text'            => '{copy} {year} {site}',
				'text_color'      => '#ffffff',
				'treatment'       => 'stroke',        // 'stroke' | 'shadow' | 'none'.
				'treatment_color' => '#000000',
				'text_scale_pct'  => 4,               // % of image width → pt, clamped.
			],
			'placement' => [
				'position'   => 'bot-right', // 9-point enum (see Sanitizer::POSITIONS).
				'margin_pct' => 3,           // % of the shorter edge.
			],
			'sizing'    => [
				'scale_pct' => 18,  // Logo width as % of image width.
				'min_px'    => 60,  // Logo width clamp (lower).
				'max_px'    => 600, // Logo width clamp (upper).
				'opacity'   => 70,  // 0–100.
			],
			'sizes'     => [
				'enabled'          => [], // Empty array = full + all registered sizes.
				'min_dimension_px' => 300, // Shorter-edge skip threshold.
			],
			'output'    => [
				'jpeg_bg'               => '#ffffff', // Flatten bg for alpha → JPEG.
				'srcset_hide_unstamped' => false,
			],
			'advanced'  => [
				'delete_data_on_uninstall'    => false,
				'delete_backups_on_uninstall' => false,
			],
		];

		return apply_filters( 'ogwm/default_options', $defaults );
	}

	public function get( string $path, mixed $default = null ): mixed {
		$value = $this->merged();
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
			} else {
				return $default;
			}
		}
		return $value;
	}

	public function bool( string $path ): bool {
		return (bool) $this->get( $path, false );
	}

	public function str( string $path, string $default = '' ): string {
		$value = $this->get( $path, $default );
		return is_scalar( $value ) ? (string) $value : $default;
	}

	public function int( string $path, int $default = 0 ): int {
		return (int) $this->get( $path, $default );
	}

	public function arr( string $path ): array {
		$value = $this->get( $path, [] );
		return is_array( $value ) ? $value : [];
	}

	/** @return array<string,mixed> */
	public function all(): array {
		return $this->merged();
	}

	/** @return array<string,mixed> */
	public function raw(): array {
		return $this->data;
	}

	/**
	 * Persist settings. The input is ALWAYS run through the Sanitizer first, so
	 * this typed accessor can never become a bypass of the whitelist/clamp/
	 * validation layer no matter who calls it (the same callback M4 wires into
	 * register_setting). Use this for every settings write.
	 *
	 * @param array<string,mixed> $data
	 */
	public function save( array $data ): void {
		$clean        = Sanitizer::sanitize( $data );
		$this->data   = $clean;
		$this->merged = null;
		update_option( self::OPTION, $clean, true );
	}

	public function seedDefaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, [], '', 'yes' );
		}
	}

	/** @return array<string,mixed> */
	private function merged(): array {
		return $this->merged ??= self::deepMerge( $this->defaults(), $this->data );
	}

	/**
	 * @param array<string,mixed> $base
	 * @param array<string,mixed> $over
	 * @return array<string,mixed>
	 */
	private static function deepMerge( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			// A list override always REPLACES (never index-merges) so set-like
			// values such as sizes.enabled overwrite cleanly. This is explicit
			// rather than relying on isAssoc([]) === true for the empty default.
			if ( is_array( $value ) && ! self::isAssoc( $value ) ) {
				$base[ $key ] = $value;
				continue;
			}

			if (
				is_array( $value )
				&& isset( $base[ $key ] )
				&& is_array( $base[ $key ] )
				&& self::isAssoc( $base[ $key ] )
			) {
				$base[ $key ] = self::deepMerge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/** @param array<mixed> $array */
	private static function isAssoc( array $array ): bool {
		if ( [] === $array ) {
			return true;
		}
		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}
}
