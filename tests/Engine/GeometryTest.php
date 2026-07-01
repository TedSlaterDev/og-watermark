<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Engine;

use OrchardGrove\OgWatermark\Engine\Geometry;
use OrchardGrove\OgWatermark\Engine\Placement;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive unit tests for the pure placement math. No Brain Monkey needed —
 * Geometry touches no WordPress functions — so this extends PHPUnit's TestCase
 * directly (mirroring AutoloaderTest).
 */
final class GeometryTest extends TestCase {

	/**
	 * Build a grouped settings array matching the Options shape, overriding only
	 * the pixel-affecting fields the tests care about.
	 *
	 * @param array<string,int|string> $over
	 * @return array<string,mixed>
	 */
	private function settings( array $over = [] ): array {
		$d = [
			'position'         => 'bot-right',
			'margin_pct'       => 0,
			'scale_pct'        => 18,
			'min_px'           => 0,
			'max_px'           => 100000,
			'opacity'          => 70,
			'min_dimension_px' => 0,
			'text_scale_pct'   => 4,
		];
		$d = array_merge( $d, $over );

		return [
			'watermark' => [ 'text_scale_pct' => $d['text_scale_pct'] ],
			'placement' => [
				'position'   => $d['position'],
				'margin_pct' => $d['margin_pct'],
			],
			'sizing'    => [
				'scale_pct' => $d['scale_pct'],
				'min_px'    => $d['min_px'],
				'max_px'    => $d['max_px'],
				'opacity'   => $d['opacity'],
			],
			'sizes'     => [ 'min_dimension_px' => $d['min_dimension_px'] ],
		];
	}

	// --- Scale clamping ----------------------------------------------------

	public function testLogoTargetWidthFromScalePercent(): void {
		// 1000px image, 20% scale, square logo → target width 200.
		$p = Geometry::logoPlacement( 1000, 1000, 500, 500, $this->settings( [ 'scale_pct' => 20 ] ) );
		$this->assertInstanceOf( Placement::class, $p );
		$this->assertSame( 200, $p->width );
	}

	public function testLogoWidthClampsUpToMinPx(): void {
		// 1000px image, 5% = 50px raw, but min_px=120 → clamps up to 120.
		$p = Geometry::logoPlacement( 1000, 1000, 100, 100, $this->settings( [ 'scale_pct' => 5, 'min_px' => 120 ] ) );
		$this->assertInstanceOf( Placement::class, $p );
		$this->assertSame( 120, $p->width );
	}

	public function testLogoWidthClampsDownToMaxPx(): void {
		// 1000px image, 50% = 500px raw, but max_px=150 → clamps down to 150.
		$p = Geometry::logoPlacement( 1000, 1000, 100, 100, $this->settings( [ 'scale_pct' => 50, 'max_px' => 150 ] ) );
		$this->assertInstanceOf( Placement::class, $p );
		$this->assertSame( 150, $p->width );
	}

	// --- Aspect preservation ----------------------------------------------

	public function testLogoHeightPreservesAspectRatio(): void {
		// 1000px image, 20% → 200 wide; logo is 400x100 (4:1) → height 50.
		$p = Geometry::logoPlacement( 1000, 1000, 400, 100, $this->settings( [ 'scale_pct' => 20 ] ) );
		$this->assertInstanceOf( Placement::class, $p );
		$this->assertSame( 200, $p->width );
		$this->assertSame( 50, $p->height );
	}

	public function testLogoHeightAspectRounds(): void {
		// 200 wide from a 300x101 logo → 101*200/300 = 67.33 → rounds to 67.
		$p = Geometry::logoPlacement( 1000, 1000, 300, 101, $this->settings( [ 'scale_pct' => 20 ] ) );
		$this->assertInstanceOf( Placement::class, $p );
		$this->assertSame( 67, $p->height );
	}

	// --- Margin on the shorter edge ---------------------------------------

	public function testMarginMeasuredOnShorterEdgeLandscape(): void {
		// Landscape 2000x1000: shorter edge 1000, 5% → margin 50.
		$this->assertSame( 50, Geometry::margin( 2000, 1000, $this->settings( [ 'margin_pct' => 5 ] ) ) );
	}

	public function testMarginMeasuredOnShorterEdgePortrait(): void {
		// Portrait 1000x2000: shorter edge 1000, 5% → margin 50 (NOT 100).
		$this->assertSame( 50, Geometry::margin( 1000, 2000, $this->settings( [ 'margin_pct' => 5 ] ) ) );
	}

	public function testMarginAppliedToTopLeftPosition(): void {
		// top-left with a 50px margin places the box at exactly (50,50).
		$p = Geometry::logoPlacement(
			1000,
			1000,
			100,
			100,
			$this->settings( [ 'position' => 'top-left', 'scale_pct' => 10, 'margin_pct' => 5 ] )
		);
		$this->assertInstanceOf( Placement::class, $p );
		$this->assertSame( 50, $p->x );
		$this->assertSame( 50, $p->y );
	}

	// --- All nine positions ------------------------------------------------

	/**
	 * @dataProvider providePositions
	 */
	public function testNinePositionCoordinates( string $position, int $expectedX, int $expectedY ): void {
		// 1000x1000 image, 100x100 box (scale 10%), margin 30px (3% of 1000).
		$p = Geometry::logoPlacement(
			1000,
			1000,
			100,
			100,
			$this->settings( [ 'position' => $position, 'scale_pct' => 10, 'margin_pct' => 3 ] )
		);
		$this->assertInstanceOf( Placement::class, $p );
		$this->assertSame( $expectedX, $p->x, "x for {$position}" );
		$this->assertSame( $expectedY, $p->y, "y for {$position}" );
	}

	/** @return array<string,array{0:string,1:int,2:int}> */
	public static function providePositions(): array {
		// box=100, img=1000, margin=30. center = (1000-100)/2 = 450.
		// right/bot = 1000-100-30 = 870.
		return [
			'top-left'   => [ 'top-left', 30, 30 ],
			'top-center' => [ 'top-center', 450, 30 ],
			'top-right'  => [ 'top-right', 870, 30 ],
			'mid-left'   => [ 'mid-left', 30, 450 ],
			'mid-center' => [ 'mid-center', 450, 450 ],
			'mid-right'  => [ 'mid-right', 870, 450 ],
			'bot-left'   => [ 'bot-left', 30, 870 ],
			'bot-center' => [ 'bot-center', 450, 870 ],
			'bot-right'  => [ 'bot-right', 870, 870 ],
		];
	}

	public function testUnknownPositionFallsBackToBottomRight(): void {
		$p = Geometry::logoPlacement(
			1000,
			1000,
			100,
			100,
			$this->settings( [ 'position' => 'nonsense', 'scale_pct' => 10, 'margin_pct' => 3 ] )
		);
		$this->assertInstanceOf( Placement::class, $p );
		$this->assertSame( 870, $p->x );
		$this->assertSame( 870, $p->y );
	}

	// --- Clamp into bounds -------------------------------------------------

	public function testCoordinatesClampIntoBoundsWhenMarginTooLarge(): void {
		// A 90px box in a 100px image with a 40px margin would push top-left to
		// (40,40) but bot-right to 100-90-40 = -30 → clamps to 0.
		$p = Geometry::logoPlacement(
			100,
			100,
			90,
			90,
			$this->settings(
				[
					'position'         => 'bot-right',
					'scale_pct'        => 90,
					'margin_pct'       => 40,
					'min_dimension_px' => 0,
					'max_px'           => 90,
				]
			)
		);
		$this->assertInstanceOf( Placement::class, $p );
		$this->assertGreaterThanOrEqual( 0, $p->x );
		$this->assertGreaterThanOrEqual( 0, $p->y );
		$this->assertLessThanOrEqual( 100 - $p->width, $p->x );
		$this->assertLessThanOrEqual( 100 - $p->height, $p->y );
	}

	public function testCoordinatesNeverNegativeForCenterOnTinyImage(): void {
		// Box nearly the image size (within the 90%-width gate) → center math
		// rounds to a tiny inset and never goes negative.
		$p = Geometry::textPlacement( 100, 100, 88, 100, $this->settings( [ 'position' => 'mid-center' ] ) );
		$this->assertInstanceOf( Placement::class, $p );
		$this->assertGreaterThanOrEqual( 0, $p->x );
		$this->assertGreaterThanOrEqual( 0, $p->y );
		$this->assertSame( 6, $p->x ); // (100-88)/2 = 6.
		$this->assertSame( 0, $p->y ); // (100-100)/2 = 0.
	}

	// --- Skip rules --------------------------------------------------------

	public function testSkipWhenBelowMinDimension(): void {
		// Shorter edge 250 < min_dimension 300 → null.
		$p = Geometry::logoPlacement( 800, 250, 100, 100, $this->settings( [ 'min_dimension_px' => 300 ] ) );
		$this->assertNull( $p );
	}

	public function testNotSkippedWhenExactlyAtMinDimension(): void {
		// Shorter edge 300 == min_dimension 300 → NOT skipped (strict <).
		$p = Geometry::logoPlacement( 800, 300, 100, 100, $this->settings( [ 'min_dimension_px' => 300, 'scale_pct' => 10 ] ) );
		$this->assertInstanceOf( Placement::class, $p );
	}

	public function testSkipWhenLogoWiderThanNinetyPercent(): void {
		// scale 95% with a generous max → target > 90% of width → null.
		$p = Geometry::logoPlacement( 1000, 1000, 100, 100, $this->settings( [ 'scale_pct' => 95, 'max_px' => 100000 ] ) );
		$this->assertNull( $p );
	}

	public function testSkipWhenMinPxClampPushesPastNinetyPercent(): void {
		// Small image, min_px clamp forces target width above 90% → null.
		$p = Geometry::logoPlacement( 200, 200, 100, 100, $this->settings( [ 'scale_pct' => 10, 'min_px' => 190 ] ) );
		$this->assertNull( $p );
	}

	public function testTextSkipWhenBelowMinDimension(): void {
		$p = Geometry::textPlacement( 800, 250, 200, 40, $this->settings( [ 'min_dimension_px' => 300 ] ) );
		$this->assertNull( $p );
	}

	public function testTextSkipWhenWiderThanNinetyPercent(): void {
		// Measured text box 950 wide in a 1000px image → > 900 → null.
		$p = Geometry::textPlacement( 1000, 1000, 950, 60, $this->settings() );
		$this->assertNull( $p );
	}

	public function testMinDimensionBypassedForThePrimaryTarget(): void {
		// Below min_dimension, but with enforcement OFF (the primary/full target),
		// a placement is still returned — min_dimension only gates thumbnail sizes.
		$text = Geometry::textPlacement( 800, 250, 200, 40, $this->settings( [ 'min_dimension_px' => 300 ] ), false );
		$this->assertNotNull( $text );

		$logo = Geometry::logoPlacement( 800, 250, 100, 100, $this->settings( [ 'min_dimension_px' => 300, 'scale_pct' => 10 ] ), false );
		$this->assertNotNull( $logo );

		// The 90%-width guard still applies even when min_dimension is bypassed.
		$tooWide = Geometry::textPlacement( 1000, 250, 950, 40, $this->settings( [ 'min_dimension_px' => 300 ] ), false );
		$this->assertNull( $tooWide );
	}

	public function testZeroOrNegativeDimensionsReturnNull(): void {
		$this->assertNull( Geometry::logoPlacement( 0, 100, 10, 10, $this->settings() ) );
		$this->assertNull( Geometry::logoPlacement( 100, 100, 0, 10, $this->settings() ) );
		$this->assertNull( Geometry::textPlacement( 100, 0, 10, 10, $this->settings() ) );
		$this->assertNull( Geometry::textPlacement( 100, 100, 10, 0, $this->settings() ) );
	}

	// --- Opacity passthrough ----------------------------------------------

	public function testOpacityIsCarriedOntoPlacement(): void {
		$p = Geometry::logoPlacement( 1000, 1000, 100, 100, $this->settings( [ 'scale_pct' => 10, 'opacity' => 55 ] ) );
		$this->assertInstanceOf( Placement::class, $p );
		$this->assertSame( 55, $p->opacity );
	}

	public function testOpacityClampedIntoZeroToHundred(): void {
		$over = Geometry::logoPlacement( 1000, 1000, 100, 100, $this->settings( [ 'scale_pct' => 10, 'opacity' => 250 ] ) );
		$this->assertInstanceOf( Placement::class, $over );
		$this->assertSame( 100, $over->opacity );

		$under = Geometry::logoPlacement( 1000, 1000, 100, 100, $this->settings( [ 'scale_pct' => 10, 'opacity' => -10 ] ) );
		$this->assertInstanceOf( Placement::class, $under );
		$this->assertSame( 0, $under->opacity );
	}

	// --- Point-size helper -------------------------------------------------

	public function testPointSizeFromImageWidth(): void {
		// 1000px * 4% = 40pt, within [MIN_PT, MAX_PT].
		$this->assertSame( 40.0, Geometry::pointSize( 1000, $this->settings( [ 'text_scale_pct' => 4 ] ) ) );
	}

	public function testPointSizeClampsToMin(): void {
		// 100px * 4% = 4pt, below MIN_PT (8) → clamps up to 8.
		$this->assertSame( Geometry::MIN_PT, Geometry::pointSize( 100, $this->settings( [ 'text_scale_pct' => 4 ] ) ) );
	}

	public function testPointSizeClampsToMax(): void {
		// 10000px * 30% = 3000pt, above MAX_PT (240) → clamps down to 240.
		$this->assertSame( Geometry::MAX_PT, Geometry::pointSize( 10000, $this->settings( [ 'text_scale_pct' => 30 ] ) ) );
	}

	// --- Defaults tolerance ------------------------------------------------

	public function testMissingSettingsUseSaneDefaults(): void {
		// An empty settings array must not error and must still place a mark.
		$p = Geometry::logoPlacement( 1000, 1000, 200, 200, [] );
		$this->assertInstanceOf( Placement::class, $p );
		// Default opacity fallback is 100 when unset.
		$this->assertSame( 100, $p->opacity );
	}
}
