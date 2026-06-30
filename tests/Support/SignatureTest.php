<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Support;

use OrchardGrove\OgWatermark\Support\Signature;
use PHPUnit\Framework\TestCase;

/**
 * Signature is pure (no WordPress calls), so this extends PHPUnit's TestCase
 * directly — no Brain Monkey needed.
 */
final class SignatureTest extends TestCase {

	/** A representative, fully-populated settings array. */
	private function settings(): array {
		return [
			'watermark' => [
				'type'           => 'text',
				'logo_id'        => 0,
				'text'           => '{copy} {year} {site}',
				'text_color'     => '#ffffff',
				'treatment'      => 'stroke',
				'treatment_color' => '#000000',
				'text_scale_pct' => 4,
			],
			'placement' => [
				'position'   => 'bot-right',
				'margin_pct' => 3,
			],
			'sizing'    => [
				'scale_pct' => 18,
				'min_px'    => 60,
				'max_px'    => 600,
				'opacity'   => 70,
			],
			'sizes'     => [
				'enabled'          => [],
				'min_dimension_px' => 300,
			],
			'output'    => [
				'jpeg_bg'                => '#ffffff',
				'srcset_hide_unstamped'  => false,
			],
			'advanced'  => [
				'delete_data_on_uninstall'    => false,
				'delete_backups_on_uninstall' => false,
			],
		];
	}

	public function testIsSixteenHexChars(): void {
		$sig = Signature::compute( $this->settings(), null, null );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $sig );
	}

	public function testSameInputProducesSameHash(): void {
		$a = Signature::compute( $this->settings(), null, null );
		$b = Signature::compute( $this->settings(), null, null );
		$this->assertSame( $a, $b );
	}

	public function testKeyOrderDoesNotChangeHash(): void {
		$base     = $this->settings();
		$shuffled = $this->settings();
		// Reverse the key order of a pixel-affecting group.
		$shuffled['sizing'] = array_reverse( $shuffled['sizing'], true );

		$this->assertSame(
			Signature::compute( $base, null, null ),
			Signature::compute( $shuffled, null, null )
		);
	}

	public function testChangingOpacityChangesHash(): void {
		$base       = $this->settings();
		$changed    = $this->settings();
		$changed['sizing']['opacity'] = 50;

		$this->assertNotSame(
			Signature::compute( $base, null, null ),
			Signature::compute( $changed, null, null )
		);
	}

	public function testChangingPositionChangesHash(): void {
		$base    = $this->settings();
		$changed = $this->settings();
		$changed['placement']['position'] = 'top-left';

		$this->assertNotSame(
			Signature::compute( $base, null, null ),
			Signature::compute( $changed, null, null )
		);
	}

	public function testChangingAdvancedDoesNotChangeHash(): void {
		$base    = $this->settings();
		$changed = $this->settings();
		$changed['advanced']['delete_data_on_uninstall'] = true;

		$this->assertSame(
			Signature::compute( $base, null, null ),
			Signature::compute( $changed, null, null )
		);
	}

	public function testChangingSrcsetHideDoesNotChangeHash(): void {
		// srcset_hide_unstamped is a front-end serving toggle — no pixel effect.
		$base    = $this->settings();
		$changed = $this->settings();
		$changed['output']['srcset_hide_unstamped'] = true;

		$this->assertSame(
			Signature::compute( $base, null, null ),
			Signature::compute( $changed, null, null )
		);
	}

	public function testChangingJpegBgChangesHash(): void {
		// jpeg_bg is the alpha→JPEG flatten color: it DOES change baked pixels,
		// so it must invalidate the signature (SPEC).
		$base    = $this->settings();
		$changed = $this->settings();
		$changed['output']['jpeg_bg'] = '#000000';

		$this->assertNotSame(
			Signature::compute( $base, null, null ),
			Signature::compute( $changed, null, null )
		);
	}

	public function testMissingLogoAndFontAreHandled(): void {
		$nullArgs    = Signature::compute( $this->settings(), null, null );
		$emptyArgs   = Signature::compute( $this->settings(), '', '' );
		$missingArgs = Signature::compute( $this->settings(), '/no/such/logo.png', '/no/such/font.ttf' );

		// null, empty string, and unreadable paths all collapse to sha1('') — same hash.
		$this->assertSame( $nullArgs, $emptyArgs );
		$this->assertSame( $nullArgs, $missingArgs );
	}

	public function testLogoContentAffectsHash(): void {
		$logoA = tempnam( sys_get_temp_dir(), 'ogwm_logo_a' );
		$logoB = tempnam( sys_get_temp_dir(), 'ogwm_logo_b' );
		file_put_contents( $logoA, 'AAAA' );
		file_put_contents( $logoB, 'BBBB' );

		try {
			$this->assertNotSame(
				Signature::compute( $this->settings(), $logoA, null ),
				Signature::compute( $this->settings(), $logoB, null )
			);
			// Identical content => identical hash regardless of path.
			file_put_contents( $logoB, 'AAAA' );
			$this->assertSame(
				Signature::compute( $this->settings(), $logoA, null ),
				Signature::compute( $this->settings(), $logoB, null )
			);
		} finally {
			@unlink( $logoA );
			@unlink( $logoB );
		}
	}

	public function testMissingPixelGroupsAreTreatedAsEmptyNotFatal(): void {
		$partial = [ 'watermark' => [ 'type' => 'logo' ] ]; // No placement/sizing/sizes.
		$sig     = Signature::compute( $partial, null, null );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $sig );
	}
}
