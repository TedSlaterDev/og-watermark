<?php
/**
 * PHPUnit bootstrap. Tests use Brain Monkey to stub WordPress functions, so no
 * WordPress install or database is required — run with `composer install &&
 * composer test`.
 *
 * @package OrchardGrove\OgWatermark
 */

declare( strict_types=1 );

// Plugin source files guard on ABSPATH; define it so they load under PHPUnit.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'OGWM_VERSION' ) ) {
	define( 'OGWM_VERSION', 'test' );
}
// The hand-rolled Autoloader resolves paths against OGWM_DIR; define it so the
// AutoloaderTest can exercise the real path-mapping branch.
if ( ! defined( 'OGWM_DIR' ) ) {
	define( 'OGWM_DIR', dirname( __DIR__ ) . '/' );
}
// WordPress's $wpdb->get_row() output-type constant, referenced by the backup
// stats fast path. WP always defines it; mirror it so that path is testable.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

// Minimal stub for the one WordPress class used in type hints across the plugin.
if ( ! class_exists( 'WP_Post' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Post {
		public int $ID             = 0;
		public string $post_status = 'inherit';
		public string $post_type   = 'attachment';
		public string $post_title  = '';

		/** @param array<string,mixed> $props */
		public function __construct( array $props = [] ) {
			foreach ( $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

// Minimal WP_Query stand-in for the bulk-queue 'all'-scope COUNT + keyset pager.
// It delegates to a test-supplied resolver (WpQueryDouble::$resolver) so the
// BulkRunner cursor can be driven from an in-memory library fixture; with no
// resolver set it behaves as an empty query.
if ( ! class_exists( 'WP_Query' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Query {
		/** @var array<int,int> */
		public array $posts = [];

		public int $found_posts = 0;

		/** @param array<string,mixed> $args */
		public function __construct( array $args = [] ) {
			$resolver = \OrchardGrove\OgWatermark\Tests\Queue\WpQueryDouble::$resolver ?? null;
			if ( null === $resolver ) {
				return;
			}

			$result            = $resolver( $args );
			$this->posts       = isset( $result['posts'] ) && is_array( $result['posts'] ) ? $result['posts'] : [];
			$this->found_posts = isset( $result['found_posts'] ) ? (int) $result['found_posts'] : 0;
		}
	}
}
