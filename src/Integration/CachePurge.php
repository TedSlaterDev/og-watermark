<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Targeted, per-attachment page/object-cache purge after a (re)stamp or restore.
 *
 * The plugin bakes the watermark INTO the existing file at its existing URL — the
 * URL never changes, so any caching layer keyed on that URL (a CDN, a page cache,
 * the WordPress object cache) keeps serving the STALE pre-watermark bytes. On
 * hosts that ship a long-lived `Cache-Control: max-age=31536000` on uploads (Ted
 * hit exactly this on SiteGround), the freshly-stamped image is invisible until
 * the cache lapses on its own. After a successful (re)stamp or restore the
 * Processor calls {@see purgeAttachment()} to flush JUST that attachment's URLs.
 *
 * Design rules (all load-bearing):
 *
 *  - BEST-EFFORT, NEVER FATAL. The whole body is wrapped in try/catch( \Throwable )
 *    and every host integration runs in its own nested try/catch, so a broken
 *    third-party cache plugin can never throw out of a watermark run or stop the
 *    other integrations from firing. A purge that silently does nothing is always
 *    preferable to a watermark that fails.
 *
 *  - TARGETED, NEVER SITE-WIDE. This runs once per attachment, and during a bulk
 *    run once per attachment across potentially thousands of images. It therefore
 *    only ever purges THIS attachment's own URLs (or fires this attachment's own
 *    post-cache hook). It must NEVER call a "purge everything" / full-cache-flush
 *    API — doing that per attachment during a bulk run would hammer the host.
 *
 *  - OPT-OUT + EXTENSIBLE. The `ogwm_auto_purge` filter short-circuits the whole
 *    thing; the `ogwm_purge_urls` action lets a site wire its own CDN API purge
 *    (e.g. Cloudflare) for the URL set; and the `ogwm_purged_attachment` action
 *    fires LAST so anything can hook custom purging after the built-ins ran.
 */
final class CachePurge {

	/**
	 * Flush the cached URLs for ONE attachment — best effort, never fatal.
	 *
	 * Builds the attachment's full + every-registered-size URL set, clears the
	 * core post cache (which is what the official Cloudflare plugin keys its own
	 * auto-purge on), then fires each present host integration in its own guard.
	 * A `false` return from the `ogwm_auto_purge` filter skips everything.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function purgeAttachment( int $id ): void {
		try {
			if ( $id <= 0 ) {
				return;
			}

			// OPT-OUT: a site can disable auto-purge wholesale or per attachment.
			if ( ! apply_filters( 'ogwm_auto_purge', true, $id ) ) {
				return;
			}

			$urls = self::attachmentUrls( $id );

			// CORE: clear the post's object cache. Cheap, always safe, and the
			// trigger the official Cloudflare plugin hooks to auto-purge the edge.
			self::guard(
				static function () use ( $id ): void {
					if ( function_exists( 'clean_post_cache' ) ) {
						clean_post_cache( $id );
					}
				}
			);

			self::purgeRocket( $id, $urls );
			self::purgeLiteSpeed( $id, $urls );
			self::purgeW3tc( $id );
			self::purgeSiteGround( $urls );
			self::purgeCloudflare( $urls );

			// ALWAYS LAST: a generic extension point carrying the id + url set, so a
			// host/user can wire any custom purger after the built-ins have run.
			self::guard(
				static function () use ( $id, $urls ): void {
					do_action( 'ogwm_purged_attachment', $id, $urls );
				}
			);
		} catch ( \Throwable $e ) {
			// A purge integration must NEVER fail a watermark. Swallow everything —
			// stale cache is recoverable; a fatal mid-(re)stamp is not.
			unset( $e );
		}
	}

	/**
	 * Every cacheable URL for this attachment: the full/original URL plus every
	 * registered intermediate size's URL. Deduplicated. Returns [] when the base
	 * URL can't be resolved (e.g. the attached file is gone).
	 *
	 * @param int $id Attachment ID.
	 * @return array<int,string>
	 */
	private static function attachmentUrls( int $id ): array {
		$urls = [];

		$full = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $id ) : false;
		if ( is_string( $full ) && '' !== $full ) {
			$urls[] = $full;
		}

		// Derive each size URL from the metadata + uploads baseurl. We sit the size
		// file basename next to the full URL rather than calling
		// wp_get_attachment_image_src() per size (which would re-resolve the base
		// every time); this keeps the work to one metadata read + a string swap.
		$meta = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $id ) : false;
		if ( is_array( $meta ) && '' !== $full && is_string( $full ) ) {
			$base  = self::dirUrl( $full );
			$sizes = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : [];
			foreach ( $sizes as $size ) {
				if ( ! is_array( $size ) ) {
					continue;
				}
				$file = isset( $size['file'] ) && is_string( $size['file'] ) ? $size['file'] : '';
				if ( '' !== $file && '' !== $base ) {
					$urls[] = $base . '/' . $file;
				}
			}
		}

		// Dedup, preserving order (full first).
		return array_values( array_unique( $urls ) );
	}

	/** The directory portion of a URL (everything before the final '/'), or ''. */
	private static function dirUrl( string $url ): string {
		$pos = strrpos( $url, '/' );
		return false === $pos ? '' : substr( $url, 0, $pos );
	}

	/**
	 * WP Rocket: purge this post's cached files (and, when the granular
	 * file-clean helper exists, this attachment's specific URLs).
	 *
	 * rocket_clean_files() is a plain FUNCTION in WP Rocket, NOT an action hook, so
	 * it must be CALLED (function_exists), never dispatched via do_action — a
	 * has_action('rocket_clean_files') gate would always be false and the per-URL
	 * path would silently do nothing.
	 *
	 * @param int               $id   Attachment ID.
	 * @param array<int,string> $urls Attachment URL set.
	 */
	private static function purgeRocket( int $id, array $urls ): void {
		self::guard(
			static function () use ( $id, $urls ): void {
				if ( function_exists( 'rocket_clean_post' ) ) {
					rocket_clean_post( $id );
				}
				if ( [] !== $urls && function_exists( 'rocket_clean_files' ) ) {
					rocket_clean_files( $urls );
				}
			}
		);
	}

	/**
	 * LiteSpeed Cache: purge this post and each of its URLs via the documented
	 * action API (no hard dependency on the plugin's classes).
	 *
	 * @param int               $id   Attachment ID.
	 * @param array<int,string> $urls Attachment URL set.
	 */
	private static function purgeLiteSpeed( int $id, array $urls ): void {
		self::guard(
			static function () use ( $id, $urls ): void {
				if ( ! function_exists( 'has_action' ) ) {
					return;
				}
				if ( has_action( 'litespeed_purge_post' ) ) {
					do_action( 'litespeed_purge_post', $id );
				}
				if ( has_action( 'litespeed_purge_url' ) ) {
					foreach ( $urls as $url ) {
						do_action( 'litespeed_purge_url', $url );
					}
				}
			}
		);
	}

	/**
	 * W3 Total Cache: flush this single post's caches.
	 *
	 * @param int $id Attachment ID.
	 */
	private static function purgeW3tc( int $id ): void {
		self::guard(
			static function () use ( $id ): void {
				if ( function_exists( 'w3tc_flush_post' ) ) {
					w3tc_flush_post( $id );
				}
			}
		);
	}

	/**
	 * SiteGround Optimizer: purge each attachment URL from SG's dynamic cache via
	 * the plugin's documented per-URL action. We deliberately do NOT call SG's
	 * "purge everything" helper — this runs per attachment (and per attachment in
	 * a bulk run), so only the affected URLs are flushed.
	 *
	 * @param array<int,string> $urls Attachment URL set.
	 */
	private static function purgeSiteGround( array $urls ): void {
		self::guard(
			static function () use ( $urls ): void {
				if ( [] === $urls || ! function_exists( 'has_action' ) || ! has_action( 'sg_cachepress_purge_cache' ) ) {
					return;
				}
				foreach ( $urls as $url ) {
					do_action( 'sg_cachepress_purge_cache', $url );
				}
			}
		);
	}

	/**
	 * Cloudflare: the official CF plugin already auto-purges off clean_post_cache
	 * (fired in purgeAttachment), so there is nothing host-specific to call here.
	 * We additionally fire `ogwm_purge_urls` with the URL set so a site can wire
	 * its own CF API purge (or any edge purger) for exactly these URLs.
	 *
	 * @param array<int,string> $urls Attachment URL set.
	 */
	private static function purgeCloudflare( array $urls ): void {
		self::guard(
			static function () use ( $urls ): void {
				if ( [] !== $urls ) {
					do_action( 'ogwm_purge_urls', $urls );
				}
			}
		);
	}

	/**
	 * Run one integration in isolation: a throw from a single broken cache plugin
	 * is swallowed so the remaining integrations (and the watermark run) proceed.
	 *
	 * @param callable():void $fn The integration to run.
	 */
	private static function guard( callable $fn ): void {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
