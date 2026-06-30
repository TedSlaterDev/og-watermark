<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case: sets up Brain Monkey and stubs the WP functions used widely.
 */
abstract class TestCase extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults = [] ) => array_merge( (array) $defaults, is_array( $args ) ? $args : [] )
		);
		Functions\when( 'sanitize_key' )->alias(
			static fn( $key ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) )
		);
		Functions\when( 'sanitize_hex_color' )->alias( static fn( $color ) => sanitize_hex_color_fallback( (string) $color ) );
		Functions\when( 'rest_sanitize_boolean' )->alias( static fn( $value ) => rest_sanitize_boolean_fallback( $value ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}

/** Hex-color validator matching WordPress's sanitize_hex_color() semantics. */
function sanitize_hex_color_fallback( string $color ): ?string {
	if ( '' === $color ) {
		return '';
	}
	return ( 1 === preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ) ? $color : null;
}

/** Boolean coercion matching WordPress's rest_sanitize_boolean() semantics. */
function rest_sanitize_boolean_fallback( mixed $value ): bool {
	if ( is_string( $value ) ) {
		$value = strtolower( trim( $value ) );
		if ( in_array( $value, [ 'false', '0', '', 'no', 'off' ], true ) ) {
			return false;
		}
	}
	return (bool) $value;
}
