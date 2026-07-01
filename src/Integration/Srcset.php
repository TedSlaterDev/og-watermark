<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Integration;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Pipeline\SizeResolver;
use OrchardGrove\OgWatermark\Settings\Options;
use OrchardGrove\OgWatermark\Support\Meta;

/**
 * srcset coherence (SPEC M5 / Hook table `wp_calculate_image_srcset` @ 10).
 *
 * During the brief partial-stamp window — one job stamps every enabled size, but
 * the completion signature is written only after the last commit — an
 * attachment's served set can be a mix of stamped and not-yet-stamped files. The
 * browser is free to pick ANY candidate from a srcset, so it could fetch a size
 * that hasn't been watermarked yet. This filter optionally drops those still-
 * unstamped candidates so the browser can only ever choose a stamped one while a
 * watermark run is in flight.
 *
 * It is a belt-and-suspenders measure, OFF by default (Heirloom ethos: zero
 * front-end overhead unless asked). The whole callback FAST-EXITS — before any
 * meta read — unless `output.srcset_hide_unstamped` is on; a fully-stamped
 * attachment then also returns immediately, so a settled library pays nothing.
 *
 * When it does act, it never empties the srcset: at least one source is always
 * kept (the highest-resolution stamped one, falling back to the original set when
 * — impossibly — nothing is recorded as stamped) so the image still renders.
 */
final class Srcset {

	/**
	 * Register the srcset filter — but ONLY when the opt-in
	 * `output.srcset_hide_unstamped` toggle is on. The default is off, so a normal
	 * install adds NO front-end hook at all: `wp_calculate_image_srcset` fires for
	 * every responsive image on every page, and at 3M+ pageviews we must not attach
	 * to (and rebuild Options in) that hot path unless the feature is actually in
	 * use. register() runs once per request at boot, so toggling the setting takes
	 * effect on the next request; the filter keeps its own fast-exit as defense.
	 */
	public static function register(): void {
		if ( ( new Options() )->bool( 'output.srcset_hide_unstamped' ) ) {
			add_filter( 'wp_calculate_image_srcset', [ self::class, 'filter' ], 10, 5 );
		}
	}

	/**
	 * Drop still-unstamped srcset candidates during the processing window.
	 *
	 * @param array<int,array{url:string,descriptor:string,value:int}> $sources      Candidate sources keyed by width.
	 * @param array<int,int>                                           $sizeArray    [width, height] of the requested size.
	 * @param string                                                   $imageSrc     The 'src' URL of the requested size.
	 * @param array<string,mixed>                                      $imageMeta    The attachment metadata.
	 * @param int                                                      $attachmentId The attachment id.
	 * @return array<int,array{url:string,descriptor:string,value:int}>
	 */
	public static function filter(
		array $sources,
		array $sizeArray,
		string $imageSrc,
		array $imageMeta,
		int $attachmentId
	): array {
		unset( $sizeArray, $imageSrc );

		// 1) FAST EXIT — the common case. Zero work (no meta read) when the toggle
		//    is off, which is the default. A fully-stamped library therefore never
		//    pays for this filter at all.
		if ( ! ( new Options() )->bool( 'output.srcset_hide_unstamped' ) ) {
			return $sources;
		}

		// 2) Only flagged attachments are ever in a partial-stamp state; a
		//    non-flagged image's files are all clean and must pass through.
		if ( '1' !== (string) get_post_meta( $attachmentId, Meta::ENABLED, true ) ) {
			return $sources;
		}

		// 3) A fully-stamped attachment has nothing to hide — return unchanged. The
		//    attachment is "settled" only when its status is `watermarked` AND every
		//    currently-served size slug is recorded in the sizes-done map (a status
		//    of watermarked with a freshly-registered, not-yet-covered size is still
		//    a partial window for that size).
		$done = self::sizesDone( $attachmentId );
		if ( self::isFullyStamped( $attachmentId, $imageMeta, $done ) ) {
			return $sources;
		}

		// 4) Mid-processing window: drop the sources whose files are not yet
		//    recorded as stamped, but never empty the set — keep at least one
		//    (highest-resolution) source so the image still renders.
		return self::keepStampedSources( $sources, $imageMeta, $done );
	}

	/**
	 * Filter $sources to only those whose underlying file is recorded as stamped.
	 * Guarantees a non-empty result: if filtering would remove everything (e.g.
	 * the done map can't be mapped onto any candidate), the original set is
	 * returned untouched rather than handing the browser an empty srcset.
	 *
	 * @param array<int,array{url:string,descriptor:string,value:int}> $sources
	 * @param array<string,mixed>                                      $imageMeta
	 * @param array<string,int>                                        $done
	 * @return array<int,array{url:string,descriptor:string,value:int}>
	 */
	private static function keepStampedSources( array $sources, array $imageMeta, array $done ): array {
		$fileToSlug = self::fileToSlugMap( $imageMeta );

		$kept = [];
		foreach ( $sources as $width => $source ) {
			$url  = isset( $source['url'] ) && is_string( $source['url'] ) ? $source['url'] : '';
			$file = '' === $url ? '' : self::basename( $url );

			// Map the candidate file back to its size slug. The full-size file maps
			// to the SLUG_FULL pseudo-slug; an intermediate maps to its own slug.
			$slug = $fileToSlug[ $file ] ?? null;
			if ( null === $slug ) {
				// Unknown file (not in this attachment's recorded set) — be
				// conservative and keep it; we only ever DROP files we can prove are
				// unstamped.
				$kept[ $width ] = $source;
				continue;
			}

			if ( array_key_exists( $slug, $done ) ) {
				$kept[ $width ] = $source;
			}
		}

		// NEVER hand back an empty srcset: if nothing could be proven stamped, fall
		// back to the unfiltered set so the image still renders during the window.
		return [] === $kept ? $sources : $kept;
	}

	/**
	 * Whether the attachment is fully stamped (no partial-stamp window open).
	 *
	 * Requires the `watermarked` status AND that every currently-served size slug
	 * (the full file + every intermediate in metadata) is present in the
	 * sizes-done map. A `watermarked` status alone is not enough — a size
	 * registered after the last apply would be served unstamped until the next
	 * rebuild.
	 *
	 * @param array<string,mixed> $imageMeta
	 * @param array<string,int>   $done
	 */
	private static function isFullyStamped( int $attachmentId, array $imageMeta, array $done ): bool {
		if ( Meta::STATUS_WATERMARKED !== (string) get_post_meta( $attachmentId, Meta::STATUS, true ) ) {
			return false;
		}

		// The full file is always a served candidate; require it recorded.
		if ( ! array_key_exists( SizeResolver::SLUG_FULL, $done ) ) {
			return false;
		}

		$sizes = isset( $imageMeta['sizes'] ) && is_array( $imageMeta['sizes'] ) ? $imageMeta['sizes'] : [];
		foreach ( array_keys( $sizes ) as $slug ) {
			if ( ! array_key_exists( (string) $slug, $done ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Map every served file basename to its size slug for this attachment. The
	 * top-level `file` is the full/`-scaled` candidate and maps to SLUG_FULL; each
	 * entry in `sizes` maps its own `file` to its slug.
	 *
	 * @param array<string,mixed> $imageMeta
	 * @return array<string,string> basename => slug
	 */
	private static function fileToSlugMap( array $imageMeta ): array {
		$map = [];

		if ( isset( $imageMeta['file'] ) && is_string( $imageMeta['file'] ) && '' !== $imageMeta['file'] ) {
			$map[ self::basename( $imageMeta['file'] ) ] = SizeResolver::SLUG_FULL;
		}

		$sizes = isset( $imageMeta['sizes'] ) && is_array( $imageMeta['sizes'] ) ? $imageMeta['sizes'] : [];
		foreach ( $sizes as $slug => $size ) {
			if ( ! is_array( $size ) ) {
				continue;
			}
			$file = isset( $size['file'] ) && is_string( $size['file'] ) ? $size['file'] : '';
			if ( '' !== $file ) {
				$map[ self::basename( $file ) ] = (string) $slug;
			}
		}

		return $map;
	}

	/**
	 * The recorded sizes-done map (slug => timestamp) for an attachment, always an
	 * array (a corrupt/absent value yields []).
	 *
	 * @return array<string,int>
	 */
	private static function sizesDone( int $attachmentId ): array {
		$done = get_post_meta( $attachmentId, Meta::SIZES_DONE, true );
		if ( ! is_array( $done ) ) {
			return [];
		}

		$clean = [];
		foreach ( $done as $slug => $value ) {
			$clean[ (string) $slug ] = (int) $value;
		}
		return $clean;
	}

	/** Basename of a file path or URL (the part WordPress matches in srcset). */
	private static function basename( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$pos  = strrpos( $path, '/' );
		return false === $pos ? $path : substr( $path, $pos + 1 );
	}
}
