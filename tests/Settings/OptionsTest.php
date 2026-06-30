<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Settings;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Settings\Options;
use OrchardGrove\OgWatermark\Tests\TestCase;

final class OptionsTest extends TestCase {

	/** @param array<string,mixed> $stored */
	private function options( array $stored = [] ): Options {
		Functions\when( 'get_option' )->justReturn( $stored );
		return new Options();
	}

	public function testReturnsDefaultsWhenUnset(): void {
		$options = $this->options();
		$this->assertSame( 'text', $options->str( 'watermark.type' ) );
		$this->assertSame( '{copy} {year} {site}', $options->str( 'watermark.text' ) );
		$this->assertSame( 'bot-right', $options->str( 'placement.position' ) );
		$this->assertSame( 70, $options->int( 'sizing.opacity' ) );
		$this->assertSame( 300, $options->int( 'sizes.min_dimension_px' ) );
		$this->assertFalse( $options->bool( 'output.srcset_hide_unstamped' ) );
		$this->assertFalse( $options->bool( 'advanced.delete_data_on_uninstall' ) );
		$this->assertFalse( $options->bool( 'advanced.delete_backups_on_uninstall' ) );
	}

	public function testEveryDefaultGroupIsPresent(): void {
		$all = $this->options()->all();
		foreach ( [ 'watermark', 'placement', 'sizing', 'sizes', 'output', 'advanced' ] as $group ) {
			$this->assertArrayHasKey( $group, $all, "Missing group: {$group}" );
			$this->assertIsArray( $all[ $group ] );
		}
		// Spot-check the full key set inside each group.
		$this->assertSame(
			[ 'type', 'logo_id', 'text', 'text_color', 'treatment', 'treatment_color', 'text_scale_pct' ],
			array_keys( $all['watermark'] )
		);
		$this->assertSame( [ 'position', 'margin_pct' ], array_keys( $all['placement'] ) );
		$this->assertSame( [ 'scale_pct', 'min_px', 'max_px', 'opacity' ], array_keys( $all['sizing'] ) );
		$this->assertSame( [ 'enabled', 'min_dimension_px' ], array_keys( $all['sizes'] ) );
		$this->assertSame( [ 'jpeg_bg', 'srcset_hide_unstamped' ], array_keys( $all['output'] ) );
		$this->assertSame(
			[ 'delete_data_on_uninstall', 'delete_backups_on_uninstall' ],
			array_keys( $all['advanced'] )
		);
	}

	public function testStoredValueOverridesDefaultButSiblingsRemain(): void {
		$options = $this->options( [ 'placement' => [ 'position' => 'top-left' ] ] );
		$this->assertSame( 'top-left', $options->str( 'placement.position' ) );
		// Deep merge keeps the untouched sibling in the same group.
		$this->assertSame( 3, $options->int( 'placement.margin_pct' ) );
		// And keeps untouched defaults from other branches.
		$this->assertSame( 70, $options->int( 'sizing.opacity' ) );
	}

	public function testDottedPathAndTypedAccessors(): void {
		$options = $this->options(
			[
				'sizing'    => [ 'opacity' => 55, 'scale_pct' => 22 ],
				'watermark' => [ 'type' => 'logo', 'logo_id' => 42 ],
				'output'    => [ 'srcset_hide_unstamped' => true ],
			]
		);
		$this->assertSame( 55, $options->int( 'sizing.opacity' ) );
		$this->assertSame( 22, $options->int( 'sizing.scale_pct' ) );
		$this->assertSame( 'logo', $options->str( 'watermark.type' ) );
		$this->assertSame( 42, $options->int( 'watermark.logo_id' ) );
		$this->assertTrue( $options->bool( 'output.srcset_hide_unstamped' ) );
	}

	public function testListFieldReplacesRatherThanIndexMerges(): void {
		$options = $this->options( [ 'sizes' => [ 'enabled' => [ 'full', 'large' ] ] ] );
		$this->assertSame( [ 'full', 'large' ], $options->arr( 'sizes.enabled' ) );
	}

	public function testMissingPathReturnsProvidedDefault(): void {
		$this->assertSame( 'fallback', $this->options()->str( 'does.not.exist', 'fallback' ) );
		$this->assertSame( 99, $this->options()->int( 'sizing.nope', 99 ) );
		$this->assertSame( [], $this->options()->arr( 'no.such.list' ) );
	}

	public function testRawReturnsOnlyStoredDataNotDefaults(): void {
		$stored  = [ 'placement' => [ 'position' => 'mid-center' ] ];
		$options = $this->options( $stored );
		$this->assertSame( $stored, $options->raw() );
		// While all() is the merged view.
		$this->assertArrayHasKey( 'watermark', $options->all() );
	}

	public function testNonArrayStoredOptionIsToleratedAsEmpty(): void {
		Functions\when( 'get_option' )->justReturn( 'corrupt-string' );
		$options = new Options();
		$this->assertSame( [], $options->raw() );
		$this->assertSame( 'text', $options->str( 'watermark.type' ) );
	}

	public function testSaveWritesAutoloadedOptionAndRefreshesMergedView(): void {
		$options = $this->options();

		$captured = null;
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $autoload ) use ( &$captured ): bool {
				$captured = [ $name, $value, $autoload ];
				return true;
			}
		);

		$options->save( [ 'sizing' => [ 'opacity' => 10 ] ] );

		// save() ALWAYS sanitizes before persisting, so the typed accessor can
		// never be a bypass of the whitelist/clamp/validation layer. The stored
		// value is therefore a COMPLETE, valid settings array (all six groups),
		// not the partial input.
		$this->assertNotNull( $captured );
		$this->assertSame( Options::OPTION, $captured[0] );
		$this->assertTrue( $captured[2] ); // autoload === true
		$this->assertSame(
			[ 'watermark', 'placement', 'sizing', 'sizes', 'output', 'advanced' ],
			array_keys( $captured[1] )
		);
		$this->assertSame( 10, $captured[1]['sizing']['opacity'] );

		// Merged view is refreshed: the persisted (sanitized) value wins, and the
		// sanitizer-filled siblings keep their defaults.
		$this->assertSame( 10, $options->int( 'sizing.opacity' ) );
		$this->assertSame( 60, $options->int( 'sizing.min_px' ) );
	}

	public function testSeedDefaultsAddsEmptyAutoloadedOptionWhenMissing(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$captured = null;
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value, $deprecated, $autoload ) use ( &$captured ): bool {
				$captured = [ $name, $value, $deprecated, $autoload ];
				return true;
			}
		);

		( new Options() )->seedDefaults();
		$this->assertSame( [ Options::OPTION, [], '', 'yes' ], $captured );
	}

	public function testSeedDefaultsIsNoOpWhenOptionExists(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$called = false;
		Functions\when( 'add_option' )->alias(
			static function () use ( &$called ): bool {
				$called = true;
				return true;
			}
		);

		( new Options() )->seedDefaults();
		$this->assertFalse( $called, 'add_option must not be called when the option already exists.' );
	}
}
