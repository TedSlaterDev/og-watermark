<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Engine;

use OrchardGrove\OgWatermark\Engine\ImageException;
use OrchardGrove\OgWatermark\Engine\ImagickDriver;
use OrchardGrove\OgWatermark\Engine\Placement;
use OrchardGrove\OgWatermark\Engine\TextSpec;
use OrchardGrove\OgWatermark\Engine\Watermarker;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Imagick driver tests.
 *
 * The behavioural matrix (load/composite/flatten/encode) only runs where
 * ext-imagick is actually present — on this CI host it is NOT, so those cases
 * markTestSkipped. The parse-time contract checks (class exists, implements the
 * Watermarker interface, exposes the right signatures) run EVERYWHERE without
 * instantiating Imagick, so the driver's shape is still verified here.
 *
 * On a production host with ext-imagick the gated cases exercise the real
 * pipeline end-to-end against generated fixtures, mirroring the GD driver tests.
 */
final class ImagickDriverTest extends TestCase {

	private const FONT = '/assets/fonts/DejaVuSans-Bold.ttf';

	/** @var string[] Paths to clean up in tearDown. */
	private array $tmp = [];

	protected function tearDown(): void {
		foreach ( $this->tmp as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
		}
		$this->tmp = [];
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Contract checks — run on every host, no Imagick needed.
	// -----------------------------------------------------------------

	public function testClassExists(): void {
		$this->assertTrue(
			class_exists( ImagickDriver::class ),
			'ImagickDriver must be autoloadable even when ext-imagick is absent.'
		);
	}

	public function testImplementsWatermarkerInterface(): void {
		$this->assertContains(
			Watermarker::class,
			class_implements( ImagickDriver::class ),
			'ImagickDriver must implement the shared Watermarker contract so it is interchangeable with the GD driver.'
		);
	}

	public function testInterfaceMethodSignaturesMatchContract(): void {
		$ref = new \ReflectionClass( ImagickDriver::class );

		foreach ( [ 'load', 'dimensions', 'paint_logo', 'paint_text', 'flatten', 'save', 'free' ] as $method ) {
			$this->assertTrue( $ref->hasMethod( $method ), "ImagickDriver::{$method}() must exist." );
			$this->assertTrue( $ref->getMethod( $method )->isPublic(), "ImagickDriver::{$method}() must be public." );
		}

		// Spot-check a couple of return types to lock the contract.
		$this->assertSame( 'bool', (string) $ref->getMethod( 'load' )->getReturnType() );
		$this->assertSame( 'array', (string) $ref->getMethod( 'dimensions' )->getReturnType() );
		$this->assertSame( 'void', (string) $ref->getMethod( 'free' )->getReturnType() );
	}

	public function testIsFinal(): void {
		$ref = new \ReflectionClass( ImagickDriver::class );
		$this->assertTrue( $ref->isFinal(), 'ImagickDriver should be final, matching the GD driver.' );
	}

	/**
	 * Without the extension, any operating method must surface a clean
	 * ImageException — never a fatal "class Imagick not found". This proves the
	 * parse-safety guard works and is itself meaningful on this Imagick-less host.
	 */
	public function testLoadThrowsCleanlyWhenExtensionAbsent(): void {
		if ( extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Extension present — the no-extension guard cannot be exercised here.' );
		}

		$driver = new ImagickDriver();
		$this->expectException( ImageException::class );
		$driver->load( '/nonexistent/path.png' );
	}

	public function testDimensionsBeforeLoadThrows(): void {
		// Reachable without the extension (handle() guard runs before any Imagick
		// call) on this host; harmless where the extension is present too.
		$driver = new ImagickDriver();
		$this->expectException( ImageException::class );
		$driver->dimensions();
	}

	// -----------------------------------------------------------------
	// Behavioural matrix — only where ext-imagick is loaded.
	// -----------------------------------------------------------------

	public function testLoadAndDimensions(): void {
		$this->requireImagick();

		$src = $this->makePng( 200, 120 );
		$d   = new ImagickDriver();

		$this->assertTrue( $d->load( $src ) );
		$this->assertSame( [ 200, 120 ], $d->dimensions() );
		$d->free();
	}

	public function testPaintLogoPreservesAlphaNoBlackBox(): void {
		$this->requireImagick();

		$base = $this->makePng( 300, 300, 'white' );
		$logo = $this->makeLogo( 80, 80 ); // Transparent bg, solid red square smaller inside.

		$d = new ImagickDriver();
		$d->load( $base );
		// Place the logo top-left with 60% opacity.
		$d->paint_logo( $logo, new Placement( 0, 0, 80, 80, 60 ) );

		$out = $this->tmpPath( 'png' );
		$this->assertTrue( $d->save( $out, 'image/png', 90 ) );
		$d->free();

		// Where the logo was transparent (a corner pixel of the box that the logo
		// left clear), the base white must show through — i.e. NOT a black box.
		$probe = new \Imagick( $out );
		$px    = $probe->getImagePixelColor( 2, 2 )->getColor();
		$probe->clear();
		$probe->destroy();

		$this->assertGreaterThan( 200, $px['r'], 'Transparent logo area must not become a black box.' );
		$this->assertGreaterThan( 200, $px['g'] );
		$this->assertGreaterThan( 200, $px['b'] );
	}

	public function testFlattenAlphaForJpeg(): void {
		$this->requireImagick();

		$src = $this->makeLogo( 100, 100 ); // Has transparency.
		$d   = new ImagickDriver();
		$d->load( $src );
		$d->flatten( '#ffffff' );

		$out = $this->tmpPath( 'jpg' );
		$this->assertTrue( $d->save( $out, 'image/jpeg', 82 ) );
		$d->free();

		$probe = new \Imagick( $out );
		$hasAlpha = $probe->getImageAlphaChannel();
		// A transparent corner should now read as the white background, not black.
		$px = $probe->getImagePixelColor( 1, 1 )->getColor();
		$probe->clear();
		$probe->destroy();

		$this->assertFalse( (bool) $hasAlpha, 'JPEG output must carry no alpha channel.' );
		$this->assertGreaterThan( 200, $px['r'], 'Flattened transparent area must be the white bg, not black.' );
	}

	public function testSaveHonorsMimeWebpWhenSupported(): void {
		$this->requireImagick();

		$formats = array_map( 'strtoupper', ( new \Imagick() )->queryFormats() );
		if ( ! in_array( 'WEBP', $formats, true ) ) {
			$this->markTestSkipped( 'This Imagick build cannot encode WebP.' );
		}

		$src = $this->makePng( 120, 120 );
		$d   = new ImagickDriver();
		$d->load( $src );

		$out = $this->tmpPath( 'webp' );
		$this->assertTrue( $d->save( $out, 'image/webp', 80 ) );
		$d->free();

		$probe = new \Imagick( $out );
		$fmt   = strtoupper( $probe->getImageFormat() );
		$probe->clear();
		$probe->destroy();
		$this->assertSame( 'WEBP', $fmt, 'save() must honor the requested MIME, not change format.' );
	}

	public function testPaintTextStrokeRendersWhenFreeTypeAvailable(): void {
		$this->requireImagick();

		$font = OGWM_DIR . ltrim( self::FONT, '/' );
		if ( ! is_file( $font ) ) {
			$this->markTestSkipped( 'Bundled font missing in this checkout.' );
		}

		$base = $this->makePng( 400, 120, 'white' );
		$d    = new ImagickDriver();
		$d->load( $base );

		$spec = new TextSpec(
			'© 2026 Test',
			$font,
			28.0,
			'#ffffff',
			'stroke',
			'#000000',
			2,
			0,
			0
		);
		// Box roughly where the text sits; opacity 100.
		$d->paint_text( $spec, new Placement( 10, 40, 300, 40, 100 ) );

		$out = $this->tmpPath( 'png' );
		$this->assertTrue( $d->save( $out, 'image/png', 90 ) );
		$d->free();

		// The stroke is black on a white base — at least some dark pixels must now
		// exist where there were none before.
		$probe   = new \Imagick( $out );
		$probe->setImageColorspace( \Imagick::COLORSPACE_GRAY );
		$mean    = $probe->getImageChannelMean( \Imagick::CHANNEL_GRAY );
		$probe->clear();
		$probe->destroy();

		$this->assertLessThan( 65535, (float) $mean['mean'], 'Text paint must darken the all-white base.' );
	}

	// -----------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------

	private function requireImagick(): void {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'ext-imagick is not loaded on this host; behavioural cases run on production.' );
		}
	}

	private function tmpPath( string $ext ): string {
		$path        = sys_get_temp_dir() . '/ogwm-imagick-' . uniqid( '', true ) . '.' . $ext;
		$this->tmp[] = $path;
		return $path;
	}

	private function makePng( int $w, int $h, string $bg = 'white' ): string {
		$im = new \Imagick();
		$im->newImage( $w, $h, new \ImagickPixel( $bg ) );
		$im->setImageFormat( 'png' );
		$path = $this->tmpPath( 'png' );
		$im->writeImage( $path );
		$im->clear();
		$im->destroy();
		return $path;
	}

	/** Transparent canvas with a solid red square centred (smaller than the canvas). */
	private function makeLogo( int $w, int $h ): string {
		$im = new \Imagick();
		$im->newImage( $w, $h, new \ImagickPixel( 'transparent' ) );
		$im->setImageFormat( 'png' );

		$draw = new \ImagickDraw();
		$draw->setFillColor( new \ImagickPixel( 'red' ) );
		$inset = (int) round( min( $w, $h ) * 0.25 );
		$draw->rectangle( $inset, $inset, $w - $inset, $h - $inset );
		$im->drawImage( $draw );
		$draw->clear();
		$draw->destroy();

		$path = $this->tmpPath( 'png' );
		$im->writeImage( $path );
		$im->clear();
		$im->destroy();
		return $path;
	}
}
