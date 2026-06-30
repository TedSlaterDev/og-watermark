<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Engine;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Engine\Compositor;
use OrchardGrove\OgWatermark\Engine\Placement;
use OrchardGrove\OgWatermark\Engine\Result;
use OrchardGrove\OgWatermark\Engine\TextSpec;
use OrchardGrove\OgWatermark\Engine\Watermarker;
use OrchardGrove\OgWatermark\Support\Capabilities;
use OrchardGrove\OgWatermark\Tests\TestCase;
use ReflectionClass;

/**
 * Orchestration tests for the Compositor facade. A recording stub Watermarker is
 * injected via Compositor::setTestDriver(), so these assert the per-file flow and
 * format policy without touching GD/Imagick encode paths: a too-small image skips
 * via Geometry, an SVG mime skips, a JPEG flattens before save, and text mode is
 * cleanly unavailable without FreeType.
 */
final class CompositorTest extends TestCase {

	private string $tmpDir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->tmpDir = sys_get_temp_dir() . '/ogwm-comp-' . uniqid( '', true );
		mkdir( $this->tmpDir, 0777, true );

		// Token resolution + jpeg-quality filter stubs (apply_filters already
		// returns arg 2 from the base TestCase, i.e. the 82 default).
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'wp_date' )->justReturn( '2026' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( $url, $component = -1 ) => parse_url( (string) $url, $component )
		);

		// Capabilities::hasFreeType() reads this option; default to "FreeType on".
		Functions\when( 'get_option' )->justReturn( $this->capsOption( true ) );
		Functions\when( 'update_option' )->justReturn( true );

		$this->resetCapsMemo();
		Compositor::resetTestDriver();
	}

	protected function tearDown(): void {
		Compositor::resetTestDriver();
		$this->resetCapsMemo();
		$this->rrmdir( $this->tmpDir );
		parent::tearDown();
	}

	// --- format-policy gates ----------------------------------------------

	public function testSvgMimeIsSkipped(): void {
		$driver = $this->stubDriver();
		Compositor::setTestDriver( $driver );

		$src = $this->makePng( 800, 600 );
		$out = Compositor::stamp( $src, $this->dest(), 'image/svg+xml', $this->logoSettings(), $this->makePng( 200, 80 ) );

		$this->assertSame( Result::SKIPPED, $out->status );
		$this->assertStringContainsString( 'unsupported', $out->reason );
		$this->assertFalse( $driver->loaded, 'driver must not be touched for an unsupported mime' );
	}

	public function testNonImageMimeIsSkipped(): void {
		Compositor::setTestDriver( $this->stubDriver() );
		$src = $this->makePng( 800, 600 );
		$out = Compositor::stamp( $src, $this->dest(), 'application/pdf', $this->logoSettings(), null );
		$this->assertSame( Result::SKIPPED, $out->status );
	}

	public function testEqualSrcAndDestFails(): void {
		Compositor::setTestDriver( $this->stubDriver() );
		$src = $this->makePng( 800, 600 );
		$out = Compositor::stamp( $src, $src, 'image/png', $this->logoSettings(), $this->makePng( 200, 80 ) );
		$this->assertSame( Result::FAILED, $out->status );
	}

	// --- Geometry skip ----------------------------------------------------

	public function testTooSmallImageSkippedViaGeometry(): void {
		$driver = $this->stubDriver();
		Compositor::setTestDriver( $driver );

		// 100px shorter edge < min_dimension_px (300) → Geometry returns null.
		$src  = $this->makePng( 120, 100 );
		$logo = $this->makePng( 60, 30 );
		$out  = Compositor::stamp( $src, $this->dest(), 'image/png', $this->logoSettings(), $logo );

		$this->assertSame( Result::SKIPPED, $out->status );
		$this->assertFalse( $driver->painted, 'no paint should occur when Geometry skips the size' );
	}

	// --- happy path: logo -------------------------------------------------

	public function testLogoStampSucceedsAndPaintsLogo(): void {
		$driver = $this->stubDriver();
		Compositor::setTestDriver( $driver );

		$src  = $this->makePng( 1200, 800 );
		$logo = $this->makePng( 240, 120 );
		$out  = Compositor::stamp( $src, $this->dest(), 'image/png', $this->logoSettings(), $logo );

		$this->assertTrue( $out->isOk() );
		$this->assertTrue( $driver->paintedLogo );
		$this->assertFalse( $driver->paintedText );
		$this->assertTrue( $driver->saved );
		$this->assertTrue( $driver->freed );
		$this->assertFalse( $driver->flattened, 'PNG output must not flatten' );
		$this->assertInstanceOf( Placement::class, $driver->logoPlacement );
	}

	public function testLogoModeWithoutLogoPathIsSkipped(): void {
		Compositor::setTestDriver( $this->stubDriver() );
		$src = $this->makePng( 1200, 800 );
		$out = Compositor::stamp( $src, $this->dest(), 'image/png', $this->logoSettings(), null );
		$this->assertSame( Result::SKIPPED, $out->status );
		$this->assertStringContainsString( 'logo', $out->reason );
	}

	// --- JPEG flatten ordering --------------------------------------------

	public function testJpegStampFlattensBeforeSave(): void {
		$driver = $this->stubDriver();
		Compositor::setTestDriver( $driver );

		$src  = $this->makePng( 1200, 800 ); // PNG source, JPEG output mime.
		$logo = $this->makePng( 240, 120 );
		$out  = Compositor::stamp( $src, $this->dest(), 'image/jpeg', $this->logoSettings(), $logo );

		$this->assertTrue( $out->isOk() );
		$this->assertTrue( $driver->flattened, 'JPEG output must flatten alpha' );
		$this->assertSame( '#ffffff', $driver->flattenBg );
		// flatten() must be recorded before save() in the call order.
		$this->assertSame(
			[ 'load', 'dimensions', 'paint_logo', 'flatten', 'save', 'free' ],
			$driver->calls
		);
	}

	public function testJpegQualityComesFromFilterDefault(): void {
		$driver = $this->stubDriver();
		Compositor::setTestDriver( $driver );

		$src  = $this->makePng( 1200, 800 );
		$logo = $this->makePng( 240, 120 );
		Compositor::stamp( $src, $this->dest(), 'image/jpeg', $this->logoSettings(), $logo );

		$this->assertSame( 82, $driver->saveQuality );
		$this->assertSame( 'image/jpeg', $driver->saveMime );
	}

	// --- text mode --------------------------------------------------------

	public function testTextStampSucceedsWithFreeType(): void {
		$driver = $this->stubDriver();
		Compositor::setTestDriver( $driver );

		$src = $this->makePng( 1200, 800 );
		$out = Compositor::stamp( $src, $this->dest(), 'image/png', $this->textSettings(), null );

		$this->assertTrue( $out->isOk() );
		$this->assertTrue( $driver->paintedText );
		$this->assertInstanceOf( TextSpec::class, $driver->textSpec );
		// Tokens resolved: {copy} {year} {site} → "© 2026 Test Site".
		$this->assertStringContainsString( '2026', $driver->textSpec->text );
		$this->assertStringContainsString( 'Test Site', $driver->textSpec->text );
		$this->assertSame( $this->fontPath(), $driver->textSpec->fontPath );
	}

	public function testTextModeWithoutFreeTypeIsSkipped(): void {
		// Re-point Capabilities at a "no FreeType" report and clear its memo.
		Functions\when( 'get_option' )->justReturn( $this->capsOption( false ) );
		$this->resetCapsMemo();

		$driver = $this->stubDriver();
		Compositor::setTestDriver( $driver );

		$src = $this->makePng( 1200, 800 );
		$out = Compositor::stamp( $src, $this->dest(), 'image/png', $this->textSettings(), null );

		$this->assertSame( Result::SKIPPED, $out->status );
		$this->assertStringContainsString( 'FreeType', $out->reason );
		$this->assertFalse( $driver->loaded, 'must skip before loading when text needs FreeType' );
	}

	// --- driver errors → failed -------------------------------------------

	public function testLoadFailureReturnsFailed(): void {
		$driver           = $this->stubDriver();
		$driver->loadOk   = false;
		Compositor::setTestDriver( $driver );

		$src = $this->makePng( 1200, 800 );
		$out = Compositor::stamp( $src, $this->dest(), 'image/png', $this->textSettings(), null );

		$this->assertSame( Result::FAILED, $out->status );
		$this->assertTrue( $driver->freed, 'handle must be freed even on load failure' );
	}

	public function testSaveFailureReturnsFailed(): void {
		$driver         = $this->stubDriver();
		$driver->saveOk = false;
		Compositor::setTestDriver( $driver );

		$src  = $this->makePng( 1200, 800 );
		$logo = $this->makePng( 240, 120 );
		$out  = Compositor::stamp( $src, $this->dest(), 'image/png', $this->logoSettings(), $logo );

		$this->assertSame( Result::FAILED, $out->status );
		$this->assertTrue( $driver->freed );
	}

	public function testMissingSourceReturnsFailed(): void {
		Compositor::setTestDriver( $this->stubDriver() );
		$out = Compositor::stamp( $this->tmpDir . '/nope.png', $this->dest(), 'image/png', $this->logoSettings(), null );
		$this->assertSame( Result::FAILED, $out->status );
	}

	// --- driver() selection -----------------------------------------------

	public function testDriverReturnsInjectedTestDriver(): void {
		$driver = $this->stubDriver();
		Compositor::setTestDriver( $driver );
		$this->assertSame( $driver, Compositor::driver() );
	}

	public function testDriverNoneWhenCapabilitiesReportNone(): void {
		Functions\when( 'get_option' )->justReturn( $this->capsOption( true, 'none' ) );
		$this->resetCapsMemo();
		Compositor::resetTestDriver();
		$this->assertNull( Compositor::driver() );
	}

	// --- helpers ----------------------------------------------------------

	/** @return array<string,mixed> */
	private function logoSettings(): array {
		return [
			'watermark' => [ 'type' => 'logo' ],
			'placement' => [ 'position' => 'bot-right', 'margin_pct' => 3 ],
			'sizing'    => [ 'scale_pct' => 18, 'min_px' => 60, 'max_px' => 600, 'opacity' => 70 ],
			'sizes'     => [ 'enabled' => [], 'min_dimension_px' => 300 ],
			'output'    => [ 'jpeg_bg' => '#ffffff' ],
		];
	}

	/** @return array<string,mixed> */
	private function textSettings(): array {
		$s                       = $this->logoSettings();
		$s['watermark']          = [
			'type'            => 'text',
			'text'            => '{copy} {year} {site}',
			'text_color'      => '#ffffff',
			'treatment'       => 'stroke',
			'treatment_color' => '#000000',
			'text_scale_pct'  => 4,
		];
		return $s;
	}

	private function stubDriver(): StubWatermarker {
		return new StubWatermarker();
	}

	private function makePng( int $w, int $h ): string {
		$path = $this->tmpDir . '/img-' . uniqid( '', true ) . '.png';
		$im   = imagecreatetruecolor( $w, $h );
		imagepng( $im, $path );
		return $path;
	}

	private function dest(): string {
		return $this->tmpDir . '/out-' . uniqid( '', true ) . '.img';
	}

	private function fontPath(): string {
		return OGWM_DIR . 'assets/fonts/DejaVuSans-Bold.ttf';
	}

	/**
	 * A stored capabilities option that drives Capabilities::hasFreeType() /
	 * ::driver() / ::canEncode() to known values.
	 *
	 * @return array<string,mixed>
	 */
	private function capsOption( bool $freetype, string $driver = 'gd' ): array {
		return [
			'driver'     => $driver,
			'imagick'    => false,
			'gd'         => true,
			'freetype'   => $freetype,
			'formats'    => [
				'image/jpeg' => true,
				'image/png'  => true,
				'image/webp' => true,
				'image/avif' => true,
				'image/gif'  => true,
			],
			'probed_gmt' => '2026-06-29T00:00:00+00:00',
		];
	}

	private function resetCapsMemo(): void {
		( new ReflectionClass( Capabilities::class ) )
			->getProperty( 'cache' )
			->setValue( null, null );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}

/**
 * Recording stub driver: fulfils the Watermarker contract, captures every call
 * and its arguments, and can be told to fail load/save so the Compositor's error
 * paths are exercised. No real image work — these tests assert orchestration.
 */
final class StubWatermarker implements Watermarker {

	public bool $loadOk       = true;
	public bool $saveOk       = true;
	public bool $loaded       = false;
	public bool $freed        = false;
	public bool $saved        = false;
	public bool $flattened    = false;
	public bool $painted      = false;
	public bool $paintedLogo  = false;
	public bool $paintedText  = false;
	public string $flattenBg  = '';
	public string $saveMime   = '';
	public int $saveQuality   = 0;
	public int $w             = 1200;
	public int $h             = 800;
	public ?Placement $logoPlacement = null;
	public ?TextSpec $textSpec       = null;

	/** @var string[] Ordered method-call log. */
	public array $calls = [];

	public function load( string $path ): bool {
		$this->calls[] = 'load';
		$this->loaded  = true;
		// Mirror the real source dimensions so dimensions() is authoritative and
		// Geometry sees the true size (e.g. a too-small image is actually small).
		$info = @getimagesize( $path );
		if ( is_array( $info ) && isset( $info[0], $info[1] ) ) {
			$this->w = (int) $info[0];
			$this->h = (int) $info[1];
		}
		return $this->loadOk;
	}

	public function dimensions(): array {
		$this->calls[] = 'dimensions';
		return [ $this->w, $this->h ];
	}

	public function paint_logo( string $logoPath, Placement $p ): void {
		$this->calls[]       = 'paint_logo';
		$this->painted       = true;
		$this->paintedLogo   = true;
		$this->logoPlacement = $p;
	}

	public function paint_text( TextSpec $t, Placement $p ): void {
		$this->calls[]     = 'paint_text';
		$this->painted     = true;
		$this->paintedText = true;
		$this->textSpec    = $t;
	}

	public function flatten( string $hexBg ): void {
		$this->calls[]   = 'flatten';
		$this->flattened = true;
		$this->flattenBg = $hexBg;
	}

	public function save( string $dest, string $mime, int $quality ): bool {
		$this->calls[]     = 'save';
		$this->saved       = $this->saveOk;
		$this->saveMime    = $mime;
		$this->saveQuality = $quality;
		return $this->saveOk;
	}

	public function free(): void {
		$this->calls[] = 'free';
		$this->freed   = true;
	}
}
