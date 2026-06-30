<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Enumerates exactly which files the Processor (M4) must stamp for an attachment.
 *
 * This is the "what to watermark" decision, isolated from the "how to watermark"
 * (Engine) and "where is the master" (Backup) concerns so it is unit-testable
 * against stubbed metadata. It runs against the FRESH, clean metadata produced by
 * regenerating from the pristine backup — so every path it returns is a clean file
 * the Processor stamps in place via the temp-build-then-rename commit.
 *
 * The target set, per SPEC Decision #2 (stamp the high-res too) and the format
 * policy:
 *
 *   - 'full'      The attached file (the `-scaled` derivative for big images, else
 *                 the full original). ALWAYS a target — it is the highest-value
 *                 fetchable file and the anti-theft keystone.
 *   - 'original'  The TRUE original (`original_image`) when a big image keeps a
 *                 separate `-scaled` full. ALWAYS a target when present; omitted
 *                 when the attached file already IS the original (path-identical).
 *   - each registered intermediate size in metadata['sizes'].
 *
 * Intermediates (and ONLY intermediates) are filtered:
 *   - included only when their slug is in settings.sizes.enabled — an EMPTY
 *     enabled list means "all sizes";
 *   - skipped when min(width,height) < settings.sizes.min_dimension_px.
 *
 * 'full' and 'original' bypass BOTH filters — they are always stamped regardless
 * of the enabled list and the min-dimension threshold. Identical paths are
 * de-duplicated (first occurrence wins), so a size whose file collides with the
 * full/original is never stamped twice.
 *
 * Each target carries its own recorded MIME so the engine honors per-size format
 * (a host may store some sizes as WebP); the format/extension is never changed.
 */
final class SizeResolver {

	/** Pseudo-slug for the attached (full / `-scaled`) file. Always stamped. */
	public const SLUG_FULL = 'full';

	/** Pseudo-slug for the true original on big images. Always stamped when present. */
	public const SLUG_ORIGINAL = 'original';

	/**
	 * Resolve the ordered list of stamp targets for an attachment.
	 *
	 * @param int                 $id       Attachment ID.
	 * @param array<string,mixed> $settings Grouped settings (Options shape).
	 * @return array<int,array{slug:string,path:string,mime:string,width:int,height:int}>
	 *         A de-duplicated list of targets; full first, then original (when
	 *         distinct), then the surviving intermediates in metadata order.
	 */
	public static function targets( int $id, array $settings ): array {
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : [];

		$fullPath = get_attached_file( $id );
		if ( ! is_string( $fullPath ) || '' === $fullPath ) {
			// With no attached file there is nothing to stamp; the engine can only
			// work files that exist on disk.
			return [];
		}

		$baseDir = self::dirOf( $fullPath );

		$targets = [];
		$seen    = []; // Normalized path => true, for path de-duplication.

		// --- 'full' — ALWAYS a target (never filtered) -------------------------
		self::push(
			$targets,
			$seen,
			[
				'slug'   => self::SLUG_FULL,
				'path'   => $fullPath,
				'mime'   => self::mimeForFull( $fullPath, $meta ),
				'width'  => self::intFrom( $meta, 'width' ),
				'height' => self::intFrom( $meta, 'height' ),
			]
		);

		// --- 'original' — the true big-image original, when distinct ----------
		$originalPath = wp_get_original_image_path( $id, true );
		if (
			is_string( $originalPath )
			&& '' !== $originalPath
			&& ! self::sameFile( $originalPath, $fullPath )
		) {
			$dims = self::originalDimensions( $meta );
			self::push(
				$targets,
				$seen,
				[
					'slug'   => self::SLUG_ORIGINAL,
					'path'   => $originalPath,
					'mime'   => self::mimeForFull( $originalPath, $meta ),
					'width'  => $dims['width'],
					'height' => $dims['height'],
				]
			);
		}

		// --- Registered intermediate sizes (filtered) ------------------------
		$enabled = self::enabledSlugs( $settings );
		$minDim  = self::minDimension( $settings );
		$sizes   = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : [];

		foreach ( $sizes as $slug => $size ) {
			$slug = (string) $slug;
			if ( ! is_array( $size ) ) {
				continue;
			}

			// Enabled-list filter: an empty list means "all sizes".
			if ( [] !== $enabled && ! in_array( $slug, $enabled, true ) ) {
				continue;
			}

			$file = isset( $size['file'] ) && is_string( $size['file'] ) ? $size['file'] : '';
			if ( '' === $file ) {
				continue;
			}

			$width  = isset( $size['width'] ) ? (int) $size['width'] : 0;
			$height = isset( $size['height'] ) ? (int) $size['height'] : 0;

			// Min-dimension filter (shorter edge), intermediates only.
			if ( $minDim > 0 && min( $width, $height ) < $minDim ) {
				continue;
			}

			$path = '' === $baseDir ? $file : $baseDir . '/' . $file;

			self::push(
				$targets,
				$seen,
				[
					'slug'   => $slug,
					'path'   => $path,
					'mime'   => self::sizeMime( $size, $path ),
					'width'  => $width,
					'height' => $height,
				]
			);
		}

		return $targets;
	}

	/**
	 * Append a target unless its (normalized) path was already taken. First
	 * occurrence wins, so 'full'/'original' always pre-empt a colliding size file.
	 *
	 * @param array<int,array{slug:string,path:string,mime:string,width:int,height:int}> $targets
	 * @param array<string,bool>                                                          $seen
	 * @param array{slug:string,path:string,mime:string,width:int,height:int}             $target
	 */
	private static function push( array &$targets, array &$seen, array $target ): void {
		$key = self::normalize( $target['path'] );
		if ( '' === $key || isset( $seen[ $key ] ) ) {
			return;
		}
		$seen[ $key ] = true;
		$targets[]    = $target;
	}

	/**
	 * The enabled intermediate slugs from settings. An empty/missing list means
	 * "all sizes" — returned as the empty array, which the caller reads as no
	 * filtering.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<int,string>
	 */
	private static function enabledSlugs( array $settings ): array {
		$enabled = $settings['sizes']['enabled'] ?? null;
		if ( ! is_array( $enabled ) ) {
			return [];
		}

		$slugs = [];
		foreach ( $enabled as $slug ) {
			if ( is_scalar( $slug ) && '' !== (string) $slug ) {
				$slugs[] = (string) $slug;
			}
		}
		return $slugs;
	}

	/**
	 * The shorter-edge skip threshold for intermediates (0 disables the filter).
	 *
	 * @param array<string,mixed> $settings
	 */
	private static function minDimension( array $settings ): int {
		$value = $settings['sizes']['min_dimension_px'] ?? 0;
		return is_scalar( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * MIME for the full/original file. Prefer the metadata when it records one for
	 * the whole image, else sniff the extension via wp_check_filetype.
	 *
	 * @param string              $path
	 * @param array<string,mixed> $meta
	 */
	private static function mimeForFull( string $path, array $meta ): string {
		// Some installs record the source MIME on the top-level metadata; honor it
		// when present so a WebP-output host keeps the right format.
		foreach ( [ 'mime-type', 'mime_type', 'sizes_mime' ] as $key ) {
			if ( isset( $meta[ $key ] ) && is_string( $meta[ $key ] ) && '' !== $meta[ $key ] ) {
				return strtolower( $meta[ $key ] );
			}
		}

		return self::mimeFromPath( $path );
	}

	/**
	 * MIME for an intermediate size: its own recorded 'mime-type' when present
	 * (the authoritative per-size format), else sniffed from its filename.
	 *
	 * @param array<string,mixed> $size
	 * @param string              $path
	 */
	private static function sizeMime( array $size, string $path ): string {
		if ( isset( $size['mime-type'] ) && is_string( $size['mime-type'] ) && '' !== $size['mime-type'] ) {
			return strtolower( $size['mime-type'] );
		}
		if ( isset( $size['mime_type'] ) && is_string( $size['mime_type'] ) && '' !== $size['mime_type'] ) {
			return strtolower( $size['mime_type'] );
		}
		return self::mimeFromPath( $path );
	}

	/**
	 * Sniff a MIME from a path's extension via wp_check_filetype (no decode). The
	 * engine re-gates the format itself; this just records the recorded format so
	 * the engine never changes a file's extension.
	 */
	private static function mimeFromPath( string $path ): string {
		$info = wp_check_filetype( $path );
		if ( is_array( $info ) && isset( $info['type'] ) && is_string( $info['type'] ) && '' !== $info['type'] ) {
			return strtolower( $info['type'] );
		}
		return '';
	}

	/**
	 * Original-image dimensions, preferred from the recorded
	 * metadata['original_image_*'] keys (some setups carry them); falls back to
	 * the top-level width/height. Used only for reporting in the target row.
	 *
	 * @param array<string,mixed> $meta
	 * @return array{width:int,height:int}
	 */
	private static function originalDimensions( array $meta ): array {
		$w = self::intFrom( $meta, 'original_image_width' );
		$h = self::intFrom( $meta, 'original_image_height' );
		if ( $w > 0 && $h > 0 ) {
			return [
				'width'  => $w,
				'height' => $h,
			];
		}
		return [
			'width'  => self::intFrom( $meta, 'width' ),
			'height' => self::intFrom( $meta, 'height' ),
		];
	}

	/**
	 * Directory containing the attached file, with normalized forward slashes and
	 * no trailing slash. Size 'file' basenames are joined onto this.
	 */
	private static function dirOf( string $path ): string {
		$dir = str_replace( '\\', '/', dirname( $path ) );
		return ( '.' === $dir ) ? '' : rtrim( $dir, '/' );
	}

	/**
	 * Whether two paths refer to the same file. Uses realpath when both resolve
	 * (catches symlinks / `.` segments), else a normalized string compare so the
	 * big-image original != full distinction works even before the files exist.
	 */
	private static function sameFile( string $a, string $b ): bool {
		$ra = realpath( $a );
		$rb = realpath( $b );
		if ( false !== $ra && false !== $rb ) {
			return $ra === $rb;
		}
		return self::normalize( $a ) === self::normalize( $b );
	}

	/** Normalize a path for comparison/de-dup: realpath when available, else slashes. */
	private static function normalize( string $path ): string {
		if ( '' === $path ) {
			return '';
		}
		$real = realpath( $path );
		if ( false !== $real ) {
			return str_replace( '\\', '/', $real );
		}
		return str_replace( '\\', '/', $path );
	}

	/**
	 * Read a non-negative int from metadata by key.
	 *
	 * @param array<string,mixed> $meta
	 */
	private static function intFrom( array $meta, string $key ): int {
		return isset( $meta[ $key ] ) && is_scalar( $meta[ $key ] ) ? max( 0, (int) $meta[ $key ] ) : 0;
	}
}
