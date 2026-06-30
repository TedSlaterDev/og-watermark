<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Thin, injectable wrapper around WordPress's subsize generation.
 *
 * The Processor (M4) rebuilds every served size FROM the pristine backup that has
 * been restored to the canonical on-disk path. The single primitive for that is
 * core's wp_create_image_subsizes(), which (re)creates the -scaled derivative and
 * all registered intermediate sizes and returns the fresh attachment metadata.
 *
 * Wrapping it in one tiny class with a single method is purely a testability seam:
 * the Processor type-hints this class and tests inject a fake that writes clean
 * size files at the expected paths, so the whole pipeline can be exercised without
 * a live WordPress image-generation stack. This is the ONLY place the plugin calls
 * wp_create_image_subsizes(), so the dependency lives in exactly one spot.
 */
final class SubsizeRegenerator {

	/**
	 * Optional test-only override. When set, regenerate() delegates to this
	 * callable INSTEAD of touching the WordPress image stack — the seam that lets
	 * the Processor's integration tests inject a fake that writes clean size files
	 * without a live WP. It is null in production, so the real
	 * wp_create_image_subsizes() path (the only place this plugin calls it) is
	 * unchanged. The class stays `final`; this constructor seam replaces the need
	 * to subclass it.
	 *
	 * @var (callable(int,string):(array<string,mixed>|null))|null
	 */
	private $override;

	/**
	 * @param (callable(int,string):(array<string,mixed>|null))|null $override Test-only
	 *        regenerate replacement; leave null in production.
	 */
	public function __construct( ?callable $override = null ) {
		$this->override = $override;
	}

	/**
	 * Regenerate all subsizes for an attachment from $sourceFile (the restored,
	 * pristine on-disk original) and return the fresh metadata.
	 *
	 * Returns NULL (not an empty array) when core could not regenerate — i.e.
	 * wp_create_image_subsizes() returned a non-array, which happens when the image
	 * editor fails to load the source (a WP_Error from wp_get_image_editor on a
	 * corrupt original or an OOM on a very large one). The Processor MUST treat a
	 * null here as a hard failure and NOT call wp_update_attachment_metadata, so a
	 * failed regenerate never clobbers the attachment's existing _wp_attachment_metadata
	 * (sizes / original_image / width / height) with an empty array. A real success
	 * always returns the (non-empty) fresh metadata array.
	 *
	 * @param int    $id         Attachment ID.
	 * @param string $sourceFile Absolute path to the in-place source to regenerate from.
	 * @return array<string,mixed>|null Fresh attachment metadata, or null on failure.
	 */
	public function regenerate( int $id, string $sourceFile ): ?array {
		if ( null !== $this->override ) {
			$meta = ( $this->override )( $id, $sourceFile );
			return is_array( $meta ) ? $meta : null;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$meta = wp_create_image_subsizes( $sourceFile, $id );

		return is_array( $meta ) ? $meta : null;
	}
}
