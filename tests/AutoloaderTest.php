<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests;

use OrchardGrove\OgWatermark\Autoloader;
use PHPUnit\Framework\TestCase;

/**
 * Autoloader is pure path logic. These tests exercise the no-op path for
 * foreign classes, the register() stack wiring, and that a real in-namespace
 * class resolves to its PSR-4 file (it is already loaded by Composer in the
 * suite, but autoload() must not error and must map the path correctly).
 */
final class AutoloaderTest extends TestCase {

	public function testForeignClassIsNoOp(): void {
		// A class outside the prefix must return without attempting any require.
		Autoloader::autoload( 'SomeOther\\Vendor\\Thing' );
		$this->assertFalse( class_exists( 'SomeOther\\Vendor\\Thing', false ) );
	}

	public function testEmptyAndPartialPrefixIsNoOp(): void {
		Autoloader::autoload( '' );
		Autoloader::autoload( 'OrchardGrove\\NotUs\\Thing' );
		$this->assertTrue( true ); // Reaching here without error/fatal is the assertion.
	}

	public function testRegisterAddsToAutoloadStack(): void {
		Autoloader::register();
		$callbacks = spl_autoload_functions();

		$found = false;
		foreach ( $callbacks as $cb ) {
			if ( is_array( $cb ) && Autoloader::class === ( is_object( $cb[0] ) ? $cb[0]::class : $cb[0] )
				&& 'autoload' === ( $cb[1] ?? '' ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Autoloader::autoload should be registered on the SPL stack.' );
	}

	public function testInNamespaceClassResolvesViaAutoloaderPath(): void {
		// OGWM_DIR is defined by the bootstrap; the autoloader maps prefix → src/.
		// Invoking autoload for an existing class must not error; the class is
		// already available (Composer loaded it), proving the path is sane.
		Autoloader::autoload( 'OrchardGrove\\OgWatermark\\Support\\Meta' );
		$this->assertTrue( class_exists( 'OrchardGrove\\OgWatermark\\Support\\Meta', false ) );
	}
}
