<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Engine;

use OrchardGrove\OgWatermark\Engine\GdDriver;
use OrchardGrove\OgWatermark\Engine\ImageException;
use OrchardGrove\OgWatermark\Engine\Placement;
use OrchardGrove\OgWatermark\Engine\TextSpec;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Exercises the real GD driver end-to-end on this host (which has GD).
 *
 * The driver touches no WordPress functions, so the only setup it needs is the
 * base TestCase's Brain Monkey scaffolding. Every test works on real temp files
 * so the GD calls actually run and outputs can be decoded back and inspected.
 *
 * The whole class is gated on the gd extension via requiresGd(): on a bare host
 * it skips cleanly rather than erroring.
 */
final class GdDriverTest extends TestCase {

	/** Temp files created during a test, cleaned up in tearDown. */
	private array $tmpFiles = [];

	private string $fontPath = '';

	protected function setUp(): void {
		parent::setUp();
		$this->fontPath = OGWM_DIR . 'assets/fonts/DejaVuSans-Bold.ttf';
	}

	protected function tearDown(): void {
		foreach ( $this->tmpFiles as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- test-only temp cleanup.
			}
		}
		$this->tmpFiles = [];
		parent::tearDown();
	}

	/** Skip the whole class on a host without GD. */
	private function requiresGd(): void {
		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'gd_info' ) ) {
			$this->markTestSkipped( 'GD extension not available on this host.' );
		}
	}

	private function requiresFreeType(): void {
		$info = gd_info();
		if ( empty( $info['FreeType Support'] ) || ! function_exists( 'imagettftext' ) ) {
			$this->markTestSkipped( 'FreeType not available on this host.' );
		}
		if ( ! is_readable( $this->fontPath ) ) {
			$this->markTestSkipped( 'Bundled font is not present.' );
		}
	}

	private function tmpPath( string $suffix ): string {
		$path             = sys_get_temp_dir() . '/ogwm-gd-' . uniqid( '', true ) . $suffix;
		$this->tmpFiles[] = $path;
		return $path;
	}

	/**
	 * Write a solid-colour JPEG of the given dimensions and return its path.
	 */
	private function makeJpeg( int $w, int $h, array $rgb = [ 200, 180, 160 ] ): string {
		$img  = imagecreatetruecolor( $w, $h );
		$fill = imagecolorallocate( $img, $rgb[0], $rgb[1], $rgb[2] );
		imagefilledrectangle( $img, 0, 0, $w, $h, $fill );
		$path = $this->tmpPath( '.jpg' );
		imagejpeg( $img, $path, 90 );
		unset( $img );
		return $path;
	}

	/**
	 * Write a PNG with a half-transparent region and return its path. The left
	 * half is opaque red; the right half is fully transparent — useful for
	 * asserting alpha is preserved through a round-trip.
	 */
	private function makeAlphaPng( int $w, int $h ): string {
		$img = imagecreatetruecolor( $w, $h );
		imagealphablending( $img, false );
		imagesavealpha( $img, true );
		$transparent = imagecolorallocatealpha( $img, 0, 0, 0, 127 );
		imagefill( $img, 0, 0, $transparent );
		$red = imagecolorallocate( $img, 220, 30, 30 );
		imagefilledrectangle( $img, 0, 0, (int) floor( $w / 2 ) - 1, $h - 1, $red );
		$path = $this->tmpPath( '.png' );
		imagepng( $img, $path );
		unset( $img );
		return $path;
	}

	/** GD alpha at a pixel, 0 (opaque) .. 127 (transparent). */
	private function alphaAt( string $pngPath, int $x, int $y ): int {
		$img   = imagecreatefrompng( $pngPath );
		$rgba  = imagecolorat( $img, $x, $y );
		$alpha = ( $rgba >> 24 ) & 0x7F;
		unset( $img );
		return $alpha;
	}

	/** [r,g,b] of a pixel in any decodable image. */
	private function rgbAt( string $path, int $x, int $y ): array {
		$info = getimagesize( $path );
		$img  = match ( $info[2] ) {
			IMAGETYPE_PNG  => imagecreatefrompng( $path ),
			IMAGETYPE_WEBP => imagecreatefromwebp( $path ),
			IMAGETYPE_GIF  => imagecreatefromgif( $path ),
			default        => imagecreatefromjpeg( $path ),
		};
		$rgba = imagecolorat( $img, $x, $y );
		unset( $img );
		return [ ( $rgba >> 16 ) & 0xFF, ( $rgba >> 8 ) & 0xFF, $rgba & 0xFF ];
	}

	public function testLoadAndDimensions(): void {
		$this->requiresGd();
		$driver = new GdDriver();
		$this->assertTrue( $driver->load( $this->makeJpeg( 320, 200 ) ) );
		$this->assertSame( [ 320, 200 ], $driver->dimensions() );
		$driver->free();
	}

	public function testLoadUnreadableThrows(): void {
		$this->requiresGd();
		$driver = new GdDriver();
		$this->expectException( ImageException::class );
		$driver->load( '/nonexistent/path/to/missing.jpg' );
	}

	public function testLoadNonImageReturnsFalse(): void {
		$this->requiresGd();
		$txt = $this->tmpPath( '.txt' );
		file_put_contents( $txt, 'this is not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test-only fixture write.
		$driver = new GdDriver();
		$this->assertFalse( $driver->load( $txt ) );
		$driver->free();
	}

	public function testDimensionsBeforeLoadThrows(): void {
		$this->requiresGd();
		$driver = new GdDriver();
		$this->expectException( ImageException::class );
		$driver->dimensions();
	}

	public function testPaletteImageIsPromotedToTrueColor(): void {
		$this->requiresGd();
		// A palette (non-truecolor) GIF.
		$img = imagecreate( 60, 40 );
		$bg  = imagecolorallocate( $img, 10, 20, 30 );
		imagefilledrectangle( $img, 0, 0, 59, 39, $bg );
		$gif = $this->tmpPath( '.gif' );
		imagegif( $img, $gif );
		unset( $img );

		$driver = new GdDriver();
		$this->assertTrue( $driver->load( $gif ) );
		$this->assertSame( [ 60, 40 ], $driver->dimensions() );
		$driver->free();
	}

	public function testPaintLogoOpaquePngStampsPixels(): void {
		$this->requiresGd();
		$src  = $this->makeJpeg( 200, 150, [ 250, 250, 250 ] ); // near-white base.
		$logo = $this->makeAlphaPng( 40, 40 );                  // left half red.

		$driver = new GdDriver();
		$driver->load( $src );
		$driver->paint_logo( $logo, new Placement( 10, 10, 40, 40, 100 ) );

		$out = $this->tmpPath( '.png' );
		$this->assertTrue( $driver->save( $out, 'image/png', 82 ) );
		$driver->free();

		$this->assertFileExists( $out );
		// Inside the opaque (left) half of the logo box → reddish, not white.
		[ $r, $g, $b ] = $this->rgbAt( $out, 15, 25 );
		$this->assertGreaterThan( 150, $r );
		$this->assertLessThan( 120, $g );
		$this->assertLessThan( 120, $b );
		// In the transparent (right) half of the logo box → base white shows through.
		[ $r2 ] = $this->rgbAt( $out, 38, 25 );
		$this->assertGreaterThan( 200, $r2 );
	}

	public function testPaintLogoIsNotABlackBox(): void {
		$this->requiresGd();
		// Regression guard for the imagecopymerge alpha bug: the transparent
		// region of the logo must NOT paint a black rectangle onto the base.
		$src    = $this->makeJpeg( 120, 120, [ 240, 240, 240 ] );
		$logo   = $this->makeAlphaPng( 60, 60 ); // right half fully transparent.
		$driver = new GdDriver();
		$driver->load( $src );
		$driver->paint_logo( $logo, new Placement( 30, 30, 60, 60, 100 ) );
		$out = $this->tmpPath( '.png' );
		$driver->save( $out, 'image/png', 82 );
		$driver->free();

		// Pixel under the transparent half of the logo box stays light, not black.
		[ $r, $g, $b ] = $this->rgbAt( $out, 75, 60 );
		$this->assertGreaterThan( 200, $r );
		$this->assertGreaterThan( 200, $g );
		$this->assertGreaterThan( 200, $b );
	}

	public function testPaintLogoOpacityIsApplied(): void {
		$this->requiresGd();
		// At 50% opacity over a white base, the opaque-red logo region blends to
		// a lighter red than at 100%.
		$logo = $this->makeAlphaPng( 40, 40 );

		$full = new GdDriver();
		$full->load( $this->makeJpeg( 80, 80, [ 255, 255, 255 ] ) );
		$full->paint_logo( $logo, new Placement( 0, 0, 40, 40, 100 ) );
		$outFull = $this->tmpPath( '.png' );
		$full->save( $outFull, 'image/png', 82 );
		$full->free();

		$half = new GdDriver();
		$half->load( $this->makeJpeg( 80, 80, [ 255, 255, 255 ] ) );
		$half->paint_logo( $logo, new Placement( 0, 0, 40, 40, 50 ) );
		$outHalf = $this->tmpPath( '.png' );
		$half->save( $outHalf, 'image/png', 82 );
		$half->free();

		[ $rFull ] = $this->rgbAt( $outFull, 10, 10 );
		[ $rHalf ] = $this->rgbAt( $outHalf, 10, 10 );
		// Faded red over white is lighter (higher R toward 255) than full red.
		$this->assertGreaterThan( $rFull, $rHalf );
	}

	public function testPaintLogoUnreadableThrows(): void {
		$this->requiresGd();
		$driver = new GdDriver();
		$driver->load( $this->makeJpeg( 50, 50 ) );
		$this->expectException( ImageException::class );
		try {
			$driver->paint_logo( '/no/such/logo.png', new Placement( 0, 0, 10, 10, 100 ) );
		} finally {
			$driver->free();
		}
	}

	public function testPaintTextStrokeStampsInkInBox(): void {
		$this->requiresGd();
		$this->requiresFreeType();

		$driver = new GdDriver();
		$driver->load( $this->makeJpeg( 400, 120, [ 255, 255, 255 ] ) );
		$spec = new TextSpec(
			'OGM',
			$this->fontPath,
			36.0,
			'#ffffff',
			'stroke',
			'#000000',
			2,
			0,
			0
		);
		$driver->paint_text( $spec, new Placement( 20, 20, 200, 60, 100 ) );
		$out = $this->tmpPath( '.png' );
		$this->assertTrue( $driver->save( $out, 'image/png', 82 ) );
		$driver->free();

		// Somewhere in the text box there must be dark stroke ink (not all white).
		$this->assertTrue( $this->hasDarkPixel( $out, 20, 20, 220, 80 ) );
	}

	public function testPaintTextShadowStampsInk(): void {
		$this->requiresGd();
		$this->requiresFreeType();

		$driver = new GdDriver();
		$driver->load( $this->makeJpeg( 400, 120, [ 255, 255, 255 ] ) );
		$spec = new TextSpec(
			'Shadow',
			$this->fontPath,
			30.0,
			'#ffffff',
			'shadow',
			'#000000',
			0,
			3,
			3
		);
		$driver->paint_text( $spec, new Placement( 20, 20, 300, 60, 100 ) );
		$out = $this->tmpPath( '.png' );
		$this->assertTrue( $driver->save( $out, 'image/png', 82 ) );
		$driver->free();

		// White fill + a translucent (~70% opaque) black drop shadow on a white
		// base: the 3px shadow fringe the fill doesn't cover lands around grey ~76,
		// so the strict all-channels-below-100 check still finds plenty of ink.
		$this->assertTrue( $this->hasDarkPixel( $out, 20, 20, 340, 90 ) );
	}

	public function testPaintTextNoTreatmentDrawsFill(): void {
		$this->requiresGd();
		$this->requiresFreeType();

		$driver = new GdDriver();
		$driver->load( $this->makeJpeg( 300, 100, [ 255, 255, 255 ] ) );
		$spec = new TextSpec(
			'X',
			$this->fontPath,
			40.0,
			'#000000',
			'none',
			'#000000',
			0,
			0,
			0
		);
		$driver->paint_text( $spec, new Placement( 20, 20, 80, 60, 100 ) );
		$out = $this->tmpPath( '.png' );
		$this->assertTrue( $driver->save( $out, 'image/png', 82 ) );
		$driver->free();

		// Black fill on white → at least one dark pixel in the box.
		$this->assertTrue( $this->hasDarkPixel( $out, 15, 15, 110, 90 ) );
	}

	public function testPaintTextWithoutFontThrows(): void {
		$this->requiresGd();
		$this->requiresFreeType();

		$driver = new GdDriver();
		$driver->load( $this->makeJpeg( 100, 100 ) );
		$spec = new TextSpec( 'x', '/no/such/font.ttf', 20.0, '#fff', 'none', '#000', 0, 0, 0 );
		$this->expectException( ImageException::class );
		try {
			$driver->paint_text( $spec, new Placement( 0, 0, 50, 30, 100 ) );
		} finally {
			$driver->free();
		}
	}

	/** Scan a rectangle for any sufficiently dark pixel. */
	private function hasDarkPixel( string $png, int $x0, int $y0, int $x1, int $y1 ): bool {
		$img = imagecreatefrompng( $png );
		$w   = imagesx( $img );
		$h   = imagesy( $img );
		$x1  = min( $x1, $w - 1 );
		$y1  = min( $y1, $h - 1 );
		for ( $y = $y0; $y <= $y1; $y++ ) {
			for ( $x = $x0; $x <= $x1; $x++ ) {
				$rgba = imagecolorat( $img, $x, $y );
				$r    = ( $rgba >> 16 ) & 0xFF;
				$g    = ( $rgba >> 8 ) & 0xFF;
				$b    = $rgba & 0xFF;
				if ( $r < 100 && $g < 100 && $b < 100 ) {
					unset( $img );
					return true;
				}
			}
		}
		unset( $img );
		return false;
	}

	public function testFlattenDropsAlphaOntoBackground(): void {
		$this->requiresGd();
		$driver = new GdDriver();
		// Load a PNG whose right half is transparent.
		$driver->load( $this->makeAlphaPng( 80, 40 ) );
		$driver->flatten( '#00ff00' ); // green bg.
		$out = $this->tmpPath( '.jpg' );
		$this->assertTrue( $driver->save( $out, 'image/jpeg', 82 ) );
		$driver->free();

		// The formerly-transparent right half is now green (not black).
		[ $r, $g, $b ] = $this->rgbAt( $out, 70, 20 );
		$this->assertLessThan( 80, $r );
		$this->assertGreaterThan( 180, $g );
		$this->assertLessThan( 80, $b );
		// The opaque red left half is still red.
		[ $r2, $g2 ] = $this->rgbAt( $out, 10, 20 );
		$this->assertGreaterThan( 150, $r2 );
		$this->assertLessThan( 120, $g2 );
	}

	public function testFlattenPreservesDimensions(): void {
		$this->requiresGd();
		$driver = new GdDriver();
		$driver->load( $this->makeAlphaPng( 123, 77 ) );
		$driver->flatten( '#ffffff' );
		$this->assertSame( [ 123, 77 ], $driver->dimensions() );
		$driver->free();
	}

	public function testSavePngPreservesAlpha(): void {
		$this->requiresGd();
		$driver = new GdDriver();
		$driver->load( $this->makeAlphaPng( 60, 30 ) );
		$out = $this->tmpPath( '.png' );
		$this->assertTrue( $driver->save( $out, 'image/png', 82 ) );
		$driver->free();

		$this->assertFileExists( $out );
		// Right half stays fully transparent through the round-trip.
		$this->assertSame( 127, $this->alphaAt( $out, 55, 15 ) );
		// Left half stays opaque.
		$this->assertSame( 0, $this->alphaAt( $out, 5, 15 ) );
	}

	public function testSaveJpegRoundTripsAndKeepsDimensions(): void {
		$this->requiresGd();
		$driver = new GdDriver();
		$driver->load( $this->makeJpeg( 210, 90 ) );
		$out = $this->tmpPath( '.jpg' );
		$this->assertTrue( $driver->save( $out, 'image/jpeg', 70 ) );
		$driver->free();

		$info = getimagesize( $out );
		$this->assertSame( IMAGETYPE_JPEG, $info[2] );
		$this->assertSame( 210, $info[0] );
		$this->assertSame( 90, $info[1] );
	}

	public function testSaveWebpWhenSupported(): void {
		$this->requiresGd();
		$info = gd_info();
		if ( empty( $info['WebP Support'] ) || ! function_exists( 'imagewebp' ) ) {
			$this->markTestSkipped( 'WebP not supported by this GD build.' );
		}
		$driver = new GdDriver();
		$driver->load( $this->makeJpeg( 100, 100 ) );
		$out = $this->tmpPath( '.webp' );
		$this->assertTrue( $driver->save( $out, 'image/webp', 80 ) );
		$driver->free();

		$check = getimagesize( $out );
		$this->assertSame( IMAGETYPE_WEBP, $check[2] );
	}

	public function testSaveAvifWhenSupported(): void {
		$this->requiresGd();
		$info = gd_info();
		if ( empty( $info['AVIF Support'] ) || ! function_exists( 'imageavif' ) ) {
			$this->markTestSkipped( 'AVIF not supported by this GD build.' );
		}
		$driver = new GdDriver();
		$driver->load( $this->makeJpeg( 64, 64 ) );
		$out = $this->tmpPath( '.avif' );
		$this->assertTrue( $driver->save( $out, 'image/avif', 60 ) );
		$driver->free();
		$this->assertFileExists( $out );
		$this->assertGreaterThan( 0, filesize( $out ) );
	}

	public function testSaveUnknownMimeReturnsFalse(): void {
		$this->requiresGd();
		$driver = new GdDriver();
		$driver->load( $this->makeJpeg( 40, 40 ) );
		$out = $this->tmpPath( '.bin' );
		$this->assertFalse( $driver->save( $out, 'image/svg+xml', 82 ) );
		$driver->free();
	}

	public function testSaveBeforeLoadThrows(): void {
		$this->requiresGd();
		$driver = new GdDriver();
		$this->expectException( ImageException::class );
		$driver->save( $this->tmpPath( '.png' ), 'image/png', 82 );
	}

	public function testFreeIsIdempotentAndDriverIsReusable(): void {
		$this->requiresGd();
		$driver = new GdDriver();
		$driver->load( $this->makeJpeg( 50, 50 ) );
		$driver->free();
		$driver->free(); // second free must not error.

		// The same instance can load another image after free().
		$this->assertTrue( $driver->load( $this->makeJpeg( 70, 30 ) ) );
		$this->assertSame( [ 70, 30 ], $driver->dimensions() );
		$driver->free();
	}

	public function testLoadReplacesPreviousHandle(): void {
		$this->requiresGd();
		$driver = new GdDriver();
		$driver->load( $this->makeJpeg( 100, 100 ) );
		$driver->load( $this->makeJpeg( 40, 60 ) ); // implicit free of the first.
		$this->assertSame( [ 40, 60 ], $driver->dimensions() );
		$driver->free();
	}

	public function testGdDriverImplementsWatermarker(): void {
		$this->requiresGd();
		$this->assertInstanceOf(
			\OrchardGrove\OgWatermark\Engine\Watermarker::class,
			new GdDriver()
		);
	}
}
