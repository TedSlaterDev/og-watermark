<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Settings;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Settings\Sanitizer;
use OrchardGrove\OgWatermark\Tests\TestCase;

final class SanitizerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Options::defaults() reads no stored data here, but its ctor calls get_option.
		Functions\when( 'get_option' )->justReturn( [] );
	}

	public function testReturnsCompleteArrayForEmptyInput(): void {
		$out = Sanitizer::sanitize( [] );
		foreach ( [ 'watermark', 'placement', 'sizing', 'sizes', 'output', 'advanced' ] as $group ) {
			$this->assertArrayHasKey( $group, $out );
			$this->assertIsArray( $out[ $group ] );
		}
		// Defaults flow through for unspecified values.
		$this->assertSame( 'text', $out['watermark']['type'] );
		$this->assertSame( 'bot-right', $out['placement']['position'] );
		$this->assertSame( 70, $out['sizing']['opacity'] );
	}

	public function testNonArrayInputYieldsCompleteDefaults(): void {
		$out = Sanitizer::sanitize( 'totally-not-an-array' );
		$this->assertSame( 'text', $out['watermark']['type'] );
		$this->assertSame( 300, $out['sizes']['min_dimension_px'] );
		$this->assertSame( [], $out['sizes']['enabled'] );
	}

	public function testOpacityAboveRangeClampsToHundred(): void {
		$out = Sanitizer::sanitize( [ 'sizing' => [ 'opacity' => 250 ] ] );
		$this->assertSame( 100, $out['sizing']['opacity'] );
	}

	public function testOpacityBelowRangeClampsToZero(): void {
		$out = Sanitizer::sanitize( [ 'sizing' => [ 'opacity' => -40 ] ] );
		$this->assertSame( 0, $out['sizing']['opacity'] );
	}

	public function testMarginClampsToTwentyFive(): void {
		$out = Sanitizer::sanitize( [ 'placement' => [ 'margin_pct' => 999 ] ] );
		$this->assertSame( 25, $out['placement']['margin_pct'] );
	}

	public function testInvalidPositionFallsBackToDefault(): void {
		$out = Sanitizer::sanitize( [ 'placement' => [ 'position' => 'middle-of-nowhere' ] ] );
		$this->assertSame( 'bot-right', $out['placement']['position'] );
	}

	public function testValidPositionIsKept(): void {
		$out = Sanitizer::sanitize( [ 'placement' => [ 'position' => 'top-left' ] ] );
		$this->assertSame( 'top-left', $out['placement']['position'] );
	}

	public function testInvalidTypeAndTreatmentFallBack(): void {
		$out = Sanitizer::sanitize(
			[
				'watermark' => [ 'type' => 'hologram', 'treatment' => 'glow' ],
			]
		);
		$this->assertSame( 'text', $out['watermark']['type'] );
		$this->assertSame( 'stroke', $out['watermark']['treatment'] );
	}

	public function testValidEnumsAreKept(): void {
		$out = Sanitizer::sanitize(
			[
				'watermark' => [ 'type' => 'logo', 'treatment' => 'shadow' ],
			]
		);
		$this->assertSame( 'logo', $out['watermark']['type'] );
		$this->assertSame( 'shadow', $out['watermark']['treatment'] );
	}

	public function testNonHexColorFallsBackToDefault(): void {
		$out = Sanitizer::sanitize(
			[
				'watermark' => [ 'text_color' => 'javascript:alert(1)', 'treatment_color' => 'rgb(0,0,0)' ],
				'output'    => [ 'jpeg_bg' => 'not-a-color' ],
			]
		);
		$this->assertSame( '#ffffff', $out['watermark']['text_color'] );
		$this->assertSame( '#000000', $out['watermark']['treatment_color'] );
		$this->assertSame( '#ffffff', $out['output']['jpeg_bg'] );
	}

	public function testValidHexColorsAreKept(): void {
		$out = Sanitizer::sanitize(
			[
				'watermark' => [ 'text_color' => '#abc', 'treatment_color' => '#123456' ],
			]
		);
		$this->assertSame( '#abc', $out['watermark']['text_color'] );
		$this->assertSame( '#123456', $out['watermark']['treatment_color'] );
	}

	public function testMinGreaterThanMaxIsCorrectedBySwapping(): void {
		$out = Sanitizer::sanitize( [ 'sizing' => [ 'min_px' => 800, 'max_px' => 100 ] ] );
		$this->assertSame( 100, $out['sizing']['min_px'] );
		$this->assertSame( 800, $out['sizing']['max_px'] );
		$this->assertLessThanOrEqual( $out['sizing']['max_px'], $out['sizing']['min_px'] );
	}

	public function testPixelValuesAreAbsinted(): void {
		$out = Sanitizer::sanitize(
			[
				'sizing' => [ 'min_px' => '-60', 'max_px' => '600px' ],
				'sizes'  => [ 'min_dimension_px' => '-300' ],
			]
		);
		$this->assertSame( 60, $out['sizing']['min_px'] );
		$this->assertSame( 600, $out['sizing']['max_px'] );
		$this->assertSame( 300, $out['sizes']['min_dimension_px'] );
	}

	public function testLogoIdIsAbsinted(): void {
		$out = Sanitizer::sanitize( [ 'watermark' => [ 'logo_id' => '-42' ] ] );
		$this->assertSame( 42, $out['watermark']['logo_id'] );
	}

	public function testTextScaleClampsToRange(): void {
		$this->assertSame( 30, Sanitizer::sanitize( [ 'watermark' => [ 'text_scale_pct' => 5000 ] ] )['watermark']['text_scale_pct'] );
		$this->assertSame( 1, Sanitizer::sanitize( [ 'watermark' => [ 'text_scale_pct' => 0 ] ] )['watermark']['text_scale_pct'] );
	}

	public function testScalePctClampsToRange(): void {
		$this->assertSame( 100, Sanitizer::sanitize( [ 'sizing' => [ 'scale_pct' => 250 ] ] )['sizing']['scale_pct'] );
		$this->assertSame( 1, Sanitizer::sanitize( [ 'sizing' => [ 'scale_pct' => -3 ] ] )['sizing']['scale_pct'] );
	}

	public function testSizesEnabledIsSanitizedToSlugArray(): void {
		// Real registered-size slugs are already lowercase; assert junk-stripping,
		// de-duplication, empty-dropping and re-indexing on those.
		$out = Sanitizer::sanitize(
			[
				'sizes' => [ 'enabled' => [ 'full', 'large size!', 'full', '', 'medium' ] ],
			]
		);
		$this->assertSame( [ 'full', 'largesize', 'medium' ], array_values( $out['sizes']['enabled'] ) );
	}

	public function testSizesEnabledNonArrayBecomesEmptyArray(): void {
		$out = Sanitizer::sanitize( [ 'sizes' => [ 'enabled' => 'full' ] ] );
		$this->assertSame( [], $out['sizes']['enabled'] );
	}

	public function testBooleansAreCoerced(): void {
		$out = Sanitizer::sanitize(
			[
				'output'   => [ 'srcset_hide_unstamped' => 'on' ],
				'advanced' => [
					'delete_data_on_uninstall'    => '1',
					'delete_backups_on_uninstall' => 'false',
				],
			]
		);
		$this->assertTrue( $out['output']['srcset_hide_unstamped'] );
		$this->assertTrue( $out['advanced']['delete_data_on_uninstall'] );
		$this->assertFalse( $out['advanced']['delete_backups_on_uninstall'] );
		$this->assertIsBool( $out['output']['srcset_hide_unstamped'] );
		$this->assertIsBool( $out['advanced']['delete_data_on_uninstall'] );
	}

	public function testMissingBooleansDefaultToFalse(): void {
		$out = Sanitizer::sanitize( [] );
		$this->assertFalse( $out['output']['srcset_hide_unstamped'] );
		$this->assertFalse( $out['advanced']['delete_data_on_uninstall'] );
		$this->assertFalse( $out['advanced']['delete_backups_on_uninstall'] );
	}

	public function testHostilePartialInputYieldsCompleteValidArray(): void {
		$hostile = [
			'watermark' => [
				'type'       => [ 'nested', 'array', 'attack' ],
				'logo_id'    => '99999999999999999999',
				'text'       => "<script>alert('xss')</script>",
				'text_color' => 12345,
			],
			'placement' => 'not-an-array',
			'sizing'    => [ 'opacity' => 'NaN' ],
			'sizes'     => [ 'enabled' => [ '<b>', null, 0 ] ],
			'junk_key'  => 'should be dropped',
		];

		$out = Sanitizer::sanitize( $hostile );

		// Output shape is exactly the 6 known groups — no junk_key leaks through.
		$this->assertSame(
			[ 'watermark', 'placement', 'sizing', 'sizes', 'output', 'advanced' ],
			array_keys( $out )
		);
		// Hostile enum/non-scalar falls back.
		$this->assertSame( 'text', $out['watermark']['type'] );
		// Non-numeric opacity falls back to the default.
		$this->assertSame( 70, $out['sizing']['opacity'] );
		// Non-array placement group still produces full defaults.
		$this->assertSame( 'bot-right', $out['placement']['position'] );
		$this->assertSame( 3, $out['placement']['margin_pct'] );
		// Non-scalar color falls back.
		$this->assertSame( '#ffffff', $out['watermark']['text_color'] );
		// Text run through sanitize_text_field (stubbed returnArg) stays a string.
		$this->assertIsString( $out['watermark']['text'] );
		// Enabled list survives as a clean slug array.
		$this->assertIsArray( $out['sizes']['enabled'] );
	}
}
