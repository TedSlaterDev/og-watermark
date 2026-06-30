<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Integration;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Integration\ImageEditors;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Unit tests for the wp_image_editors Imagick-preference seam.
 *
 * The single property under test: Imagick is moved to the FRONT only while our
 * own regenerate runs ({@see Processor::isProcessing()} true) — so the
 * clean-resize step prefers Imagick over GD even on hosts that force GD — and the
 * editor list is left UNTOUCHED at every other time and when Imagick is absent.
 *
 * Processor::isProcessing() is driven by reflecting the real Processor's private
 * reentrancy flag (the same flag the production seam reads), so we exercise the
 * real guard rather than a stub.
 */
final class ImageEditorsTest extends TestCase {

	private const IMAGICK = 'WP_Image_Editor_Imagick';
	private const GD      = 'WP_Image_Editor_GD';

	// =====================================================================
	// register() hooks at PHP_INT_MAX.
	// =====================================================================

	public function testRegisterHooksImageEditorsAtMaxPriority(): void {
		$captured = [];
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $cb, $priority = 10, $args = 1 ) use ( &$captured ): void {
				$captured = [ $hook, $cb, $priority ];
			}
		);

		ImageEditors::register();

		$this->assertSame(
			[ 'wp_image_editors', [ ImageEditors::class, 'prefer' ], PHP_INT_MAX ],
			$captured,
			'the seam must hook wp_image_editors at PHP_INT_MAX so it wins over a GD-forcing host filter'
		);
	}

	// =====================================================================
	// While processing → Imagick first.
	// =====================================================================

	public function testMovesImagickToFrontWhileProcessing(): void {
		// A host that forces GD first.
		$editors = [ self::GD, self::IMAGICK ];

		$result = self::withProcessingFlag(
			true,
			static fn() => ImageEditors::prefer( $editors )
		);

		$this->assertSame(
			[ self::IMAGICK, self::GD ],
			$result,
			'during our regenerate Imagick must be preferred over a GD-forced order'
		);
	}

	public function testPreservesRelativeOrderOfOtherEditorsWhileProcessing(): void {
		$other   = 'My_Custom_Editor';
		$editors = [ self::GD, $other, self::IMAGICK ];

		$result = self::withProcessingFlag(
			true,
			static fn() => ImageEditors::prefer( $editors )
		);

		// Imagick is unshifted to the front; the remaining editors keep their order.
		$this->assertSame( [ self::IMAGICK, self::GD, $other ], $result );
	}

	public function testReturnsContiguousZeroIndexedListWhileProcessing(): void {
		$editors = [ self::GD, self::IMAGICK ];

		$result = self::withProcessingFlag(
			true,
			static fn() => ImageEditors::prefer( $editors )
		);

		// The list WP iterates must be a clean 0..n list (no holes from the unset).
		$this->assertSame( array_keys( $result ), range( 0, count( $result ) - 1 ) );
	}

	// =====================================================================
	// Not processing → untouched.
	// =====================================================================

	public function testLeavesEditorsUntouchedWhenNotProcessing(): void {
		$editors = [ self::GD, self::IMAGICK ];

		// Default flag is false (not processing).
		$result = self::withProcessingFlag(
			false,
			static fn() => ImageEditors::prefer( $editors )
		);

		$this->assertSame(
			$editors,
			$result,
			'outside our regenerate the host editor order must be left exactly as configured'
		);
	}

	// =====================================================================
	// Imagick absent → no-op even while processing.
	// =====================================================================

	public function testNoOpWhenImagickAbsentEvenWhileProcessing(): void {
		// This host has no Imagick editor at all (GD only) — nothing to prefer.
		$editors = [ self::GD ];

		$result = self::withProcessingFlag(
			true,
			static fn() => ImageEditors::prefer( $editors )
		);

		$this->assertSame( $editors, $result, 'with no Imagick editor present the list must be returned unchanged' );
	}

	// =====================================================================
	// Helpers.
	// =====================================================================

	/**
	 * Run $callback with Processor::$processing forced to $state (and restored
	 * after), reflecting the real private reentrancy flag the seam reads.
	 *
	 * @template T
	 * @param bool          $state    Value to force the reentrancy flag to.
	 * @param callable():T $callback The code to run while the flag is forced.
	 * @return T
	 */
	private static function withProcessingFlag( bool $state, callable $callback ): mixed {
		$ref  = new \ReflectionProperty( \OrchardGrove\OgWatermark\Pipeline\Processor::class, 'processing' );
		$prev = $ref->getValue();
		$ref->setValue( null, $state );
		try {
			return $callback();
		} finally {
			$ref->setValue( null, $prev );
		}
	}
}
