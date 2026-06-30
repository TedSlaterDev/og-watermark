<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Support\Capabilities;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Capabilities reads/writes the `ogwm_capabilities` option, so WP functions are
 * stubbed with Brain Monkey. The library-specific assertions are gated on the
 * host's actual Imagick/GD availability so the suite passes anywhere.
 */
final class CapabilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->resetMemo();

		// No persisted report by default; capture writes so get() can be re-driven.
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
	}

	protected function tearDown(): void {
		$this->resetMemo();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Clear the private in-request memo between tests. */
	private function resetMemo(): void {
		// PHP 8.1+ reflection reads/writes private members without setAccessible();
		// setAccessible() is a no-op since 8.1 and emits a deprecation in 8.5.
		( new ReflectionClass( Capabilities::class ) )
			->getProperty( 'cache' )
			->setValue( null, null );
	}

	public function testReportHasExpectedShape(): void {
		$caps = Capabilities::get();

		foreach ( [ 'driver', 'imagick', 'gd', 'freetype', 'formats', 'probed_gmt' ] as $key ) {
			$this->assertArrayHasKey( $key, $caps );
		}

		$this->assertIsString( $caps['driver'] );
		$this->assertIsBool( $caps['imagick'] );
		$this->assertIsBool( $caps['gd'] );
		$this->assertIsBool( $caps['freetype'] );
		$this->assertIsArray( $caps['formats'] );
		$this->assertIsString( $caps['probed_gmt'] );
	}

	public function testDriverIsOneOfKnownValues(): void {
		$this->assertContains( Capabilities::driver(), [ 'imagick', 'gd', 'none' ] );
	}

	public function testFormatsCoverTheKnownMimeTypes(): void {
		$formats = Capabilities::get()['formats'];
		foreach ( [ 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif' ] as $mime ) {
			$this->assertArrayHasKey( $mime, $formats );
			$this->assertIsBool( $formats[ $mime ] );
		}
	}

	public function testCanEncodeReturnsBoolAndIsFalseForUnknownMime(): void {
		$this->assertIsBool( Capabilities::canEncode( 'image/jpeg' ) );
		$this->assertFalse( Capabilities::canEncode( 'image/svg+xml' ) );
	}

	public function testHasFreeTypeAndIsUsableReturnBools(): void {
		$this->assertIsBool( Capabilities::hasFreeType() );
		$this->assertIsBool( Capabilities::isUsable() );
	}

	public function testIsUsableTracksDriver(): void {
		$usable = Capabilities::isUsable();
		if ( 'none' === Capabilities::driver() ) {
			$this->assertFalse( $usable );
		} else {
			$this->assertTrue( $usable );
		}
	}

	public function testNeverFatalsWhenNoLibraryPresent(): void {
		// Even on a bare host the call chain must complete and report 'none'-safe values.
		$caps = Capabilities::refresh();
		$this->assertContains( $caps['driver'], [ 'imagick', 'gd', 'none' ] );
		if ( 'none' === $caps['driver'] ) {
			foreach ( $caps['formats'] as $can ) {
				$this->assertFalse( $can );
			}
			$this->assertFalse( $caps['freetype'] );
		}
	}

	public function testValidStoredReportIsReusedWithoutReprobing(): void {
		$stored = [
			'driver'     => 'gd',
			'imagick'    => false,
			'gd'         => true,
			'freetype'   => true,
			'formats'    => [
				'image/jpeg' => true,
				'image/png'  => true,
				'image/webp' => false,
				'image/avif' => false,
				'image/gif'  => true,
			],
			'probed_gmt' => '2026-01-01T00:00:00+00:00',
		];
		$this->resetMemo();
		Functions\when( 'get_option' )->justReturn( $stored );

		$caps = Capabilities::get();
		$this->assertSame( 'gd', $caps['driver'] );
		$this->assertTrue( Capabilities::canEncode( 'image/jpeg' ) );
		$this->assertFalse( Capabilities::canEncode( 'image/webp' ) );
	}

	public function testMalformedStoredReportIsDiscardedAndReprobed(): void {
		$this->resetMemo();
		Functions\when( 'get_option' )->justReturn( [ 'garbage' => true ] );

		$caps = Capabilities::get();
		// Discarded → fresh probe → valid shape.
		$this->assertArrayHasKey( 'driver', $caps );
		$this->assertContains( $caps['driver'], [ 'imagick', 'gd', 'none' ] );
	}

	public function testImagickEncodeMatchesProbeWhenPresent(): void {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\Imagick' ) ) {
			$this->markTestSkipped( 'Imagick not available on this host.' );
		}
		// When Imagick is the chosen driver, JPEG/PNG encode should be reported.
		if ( 'imagick' === Capabilities::driver() ) {
			$this->assertTrue( Capabilities::canEncode( 'image/jpeg' ) );
		}
		$this->assertTrue( true );
	}

	public function testGdEncodeMatchesProbeWhenPresent(): void {
		if ( ! function_exists( 'gd_info' ) ) {
			$this->markTestSkipped( 'GD not available on this host.' );
		}
		$info = gd_info();
		if ( 'gd' === Capabilities::driver() && ! empty( $info['JPEG Support'] ) ) {
			$this->assertTrue( Capabilities::canEncode( 'image/jpeg' ) );
		}
		$this->assertTrue( true );
	}
}
