<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Probes the host's real image-processing support and caches the result.
 *
 * Detection probes actual capability, not just extension presence: Imagick is
 * queried for the formats it can encode; GD is read from `gd_info()` plus the
 * `imagecreatefrom*` functions. The probe never fatals — when neither library
 * is usable it reports driver 'none' and isUsable() === false, so callers can
 * degrade gracefully and the admin UI can explain why.
 *
 * The result array is cached in the `ogwm_capabilities` option and recomputed
 * on plugin version change (Plugin::boot) or on demand via refresh().
 */
final class Capabilities {

	public const OPTION = 'ogwm_capabilities';

	/** MIME types the rendering engine (M2) will care about. */
	private const FORMATS = [
		'image/jpeg' => 'JPEG',
		'image/png'  => 'PNG',
		'image/webp' => 'WEBP',
		'image/avif' => 'AVIF',
		'image/gif'  => 'GIF',
	];

	/** In-request memo so repeated calls don't re-hit the option store. */
	private static ?array $cache = null;

	/**
	 * Cached capability report. Probes (and caches) on first access.
	 *
	 * Shape:
	 *   driver     => 'imagick'|'gd'|'none'
	 *   imagick    => bool   (Imagick available AND usable)
	 *   gd         => bool   (GD available)
	 *   freetype   => bool   (text rendering possible)
	 *   formats    => array<string,bool>  MIME => can-encode
	 *   probed_gmt => string ISO-8601 timestamp of the probe
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, false );
		if ( is_array( $stored ) && self::isValidShape( $stored ) ) {
			self::$cache = $stored;
			return self::$cache;
		}

		return self::refresh();
	}

	/**
	 * Re-probe the host, persist, and return the fresh report.
	 *
	 * @return array<string,mixed>
	 */
	public static function refresh(): array {
		$caps = self::probe();
		// autoload = false: the capability report is read only by admin/cron/CLI
		// (the engine + settings screen), never on a front-end request, so it must
		// not be loaded into memory on every page view at scale.
		update_option( self::OPTION, $caps, false );
		self::$cache = $caps;
		return self::$cache;
	}

	/** The active driver: 'imagick', 'gd', or 'none'. */
	public static function driver(): string {
		$driver = self::get()['driver'] ?? 'none';
		return is_string( $driver ) ? $driver : 'none';
	}

	/** Whether the active driver can encode the given MIME type. */
	public static function canEncode( string $mime ): bool {
		$formats = self::get()['formats'] ?? [];
		return is_array( $formats ) && ! empty( $formats[ $mime ] );
	}

	/** Whether text watermarks can be rendered (FreeType present). */
	public static function hasFreeType(): bool {
		return ! empty( self::get()['freetype'] );
	}

	/** Whether any usable image driver is present. */
	public static function isUsable(): bool {
		return 'none' !== self::driver();
	}

	/**
	 * Run the actual host probe. Pure detection, no persistence.
	 *
	 * @return array<string,mixed>
	 */
	private static function probe(): array {
		$imagick = self::probeImagick();
		$gd      = self::probeGd();

		if ( $imagick['usable'] ) {
			$driver  = 'imagick';
			$formats = $imagick['formats'];
		} elseif ( $gd['usable'] ) {
			$driver  = 'gd';
			$formats = $gd['formats'];
		} else {
			$driver  = 'none';
			$formats = self::emptyFormats();
		}

		return [
			'driver'     => $driver,
			'imagick'    => $imagick['usable'],
			'gd'         => $gd['usable'],
			'freetype'   => 'imagick' === $driver ? $imagick['freetype'] : $gd['freetype'],
			'formats'    => $formats,
			'probed_gmt' => gmdate( 'c' ),
		];
	}

	/**
	 * Probe Imagick: extension loaded, class present, and which target formats it
	 * can encode (queryFormats reports support regardless of whether the encoder
	 * is wired in, so this is best-effort but the standard approach).
	 *
	 * @return array{usable:bool,freetype:bool,formats:array<string,bool>}
	 */
	private static function probeImagick(): array {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\Imagick' ) ) {
			return [
				'usable'   => false,
				'freetype' => false,
				'formats'  => self::emptyFormats(),
			];
		}

		$supported = [];
		try {
			$imagick = new \Imagick();
			$query   = $imagick->queryFormats(); // No arg = all supported formats.
			$imagick->clear();
			$imagick->destroy();
			$supported = array_map( 'strtoupper', is_array( $query ) ? $query : [] );
		} catch ( \Throwable $e ) {
			// A broken Imagick build can throw on construct — treat as unusable.
			return [
				'usable'   => false,
				'freetype' => false,
				'formats'  => self::emptyFormats(),
			];
		}

		$lookup  = array_flip( $supported );
		$formats = [];
		foreach ( self::FORMATS as $mime => $tag ) {
			$formats[ $mime ] = isset( $lookup[ $tag ] );
		}

		// Imagick links FreeType for text; assume present when the library loaded.
		// The engine (M2) re-checks at render time and degrades if needed.
		return [
			'usable'   => true,
			'freetype' => true,
			'formats'  => $formats,
		];
	}

	/**
	 * Probe GD via gd_info() plus the per-format reader functions. Encode support
	 * is inferred from gd_info()'s "<Format> Support" keys; WebP/AVIF readers are
	 * additionally gated on the imagecreatefrom* functions (AVIF needs PHP 8.1+).
	 *
	 * @return array{usable:bool,freetype:bool,formats:array<string,bool>}
	 */
	private static function probeGd(): array {
		if ( ! function_exists( 'gd_info' ) ) {
			return [
				'usable'   => false,
				'freetype' => false,
				'formats'  => self::emptyFormats(),
			];
		}

		$info = gd_info();
		$info = is_array( $info ) ? $info : [];

		$has = static function ( array $info, string $key ): bool {
			return ! empty( $info[ $key ] );
		};

		$webp = $has( $info, 'WebP Support' ) && function_exists( 'imagecreatefromwebp' );
		$avif = $has( $info, 'AVIF Support' ) && function_exists( 'imagecreatefromavif' );

		$formats = [
			'image/jpeg' => $has( $info, 'JPEG Support' ) && function_exists( 'imagecreatefromjpeg' ),
			'image/png'  => $has( $info, 'PNG Support' ) && function_exists( 'imagecreatefrompng' ),
			'image/webp' => $webp,
			'image/avif' => $avif,
			'image/gif'  => $has( $info, 'GIF Create Support' ) && function_exists( 'imagecreatefromgif' ),
		];

		return [
			'usable'   => true,
			'freetype' => $has( $info, 'FreeType Support' ),
			'formats'  => $formats,
		];
	}

	/** Every known MIME mapped to false. */
	private static function emptyFormats(): array {
		$formats = [];
		foreach ( array_keys( self::FORMATS ) as $mime ) {
			$formats[ $mime ] = false;
		}
		return $formats;
	}

	/**
	 * Whether a stored option still matches the expected shape (so a report from
	 * an older plugin layout is discarded and re-probed rather than misread).
	 *
	 * @param array<string,mixed> $caps Candidate report.
	 */
	private static function isValidShape( array $caps ): bool {
		return isset( $caps['driver'], $caps['formats'] )
			&& is_string( $caps['driver'] )
			&& is_array( $caps['formats'] )
			&& array_key_exists( 'freetype', $caps );
	}
}
