<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Admin;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Admin\CapabilityNotice;
use OrchardGrove\OgWatermark\Support\Capabilities;
use OrchardGrove\OgWatermark\Tests\TestCase;
use ReflectionClass;

/**
 * The site-wide "no usable image driver → watermarking disabled" notice (SPEC M8).
 *
 * The two load-bearing properties:
 *
 *  1. IT ONLY SHOWS WHEN UNUSABLE. {@see CapabilityNotice::render()} prints the
 *     banner ONLY when {@see Capabilities::isUsable()} is false (driver 'none').
 *     A healthy host (GD or Imagick present) renders NOTHING, so a working site
 *     is never nagged.
 *  2. IT IS ESCAPED + DISMISSIBLE. When shown it is a single escaped
 *     `notice-error is-dismissible` block — the WP-standard dismissible error
 *     style — and the message text is run through esc_html().
 *
 * Capabilities reads a shape-validated `ogwm_capabilities` option, so the driver
 * is pinned per test by stubbing get_option (after clearing the in-request memo).
 */
final class CapabilityNoticeTest extends TestCase {

	protected function tearDown(): void {
		self::resetCapabilitiesCache();
		parent::tearDown();
	}

	public function testRendersErrorNoticeWhenNoUsableDriver(): void {
		$this->pinDriver( 'none' );

		$html = $this->capture();

		$this->assertNotSame( '', $html, 'the notice renders when no driver is usable' );
		$this->assertStringContainsString( 'notice-error', $html, 'it is a WP error notice' );
		$this->assertStringContainsString( 'is-dismissible', $html, 'it is dismissible' );
		$this->assertStringContainsString( 'Imagick', $html );
		$this->assertStringContainsString( 'GD', $html );
	}

	public function testRendersNothingWhenGdIsUsable(): void {
		$this->pinDriver( 'gd' );

		$this->assertSame( '', $this->capture(), 'a GD host shows no notice' );
	}

	public function testRendersNothingWhenImagickIsUsable(): void {
		$this->pinDriver( 'imagick' );

		$this->assertSame( '', $this->capture(), 'an Imagick host shows no notice' );
	}

	public function testMessageIsEscaped(): void {
		$this->pinDriver( 'none' );

		// esc_html is a spy here: assert it was the function that produced the body.
		$escaped = false;
		Functions\when( 'esc_html' )->alias(
			static function ( $text ) use ( &$escaped ) {
				$escaped = true;
				return $text;
			}
		);

		$this->capture();

		$this->assertTrue( $escaped, 'the message is passed through esc_html()' );
	}

	// =====================================================================
	// Helpers.
	// =====================================================================

	/** Pin the capability probe to a given driver via the shape-validated option. */
	private function pinDriver( string $driver ): void {
		self::resetCapabilitiesCache();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $driver ) {
				if ( Capabilities::OPTION === $name ) {
					return [
						'driver'   => $driver,
						'imagick'  => 'imagick' === $driver,
						'gd'       => 'gd' === $driver,
						'freetype' => 'none' !== $driver,
						'formats'  => [ 'image/jpeg' => 'none' !== $driver ],
					];
				}
				return $default;
			}
		);
	}

	/** Capture whatever render() prints. */
	private function capture(): string {
		ob_start();
		CapabilityNotice::render();
		return (string) ob_get_clean();
	}

	/** Clear Capabilities' in-request memo so the pinned option is re-read. */
	private static function resetCapabilitiesCache(): void {
		$prop = ( new ReflectionClass( Capabilities::class ) )->getProperty( 'cache' );
		$prop->setValue( null, null );
	}
}
