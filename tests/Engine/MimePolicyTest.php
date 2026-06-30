<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Engine;

use OrchardGrove\OgWatermark\Engine\MimePolicy;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Pure policy tests — no GD/Imagick, no WordPress beyond the base stubs. Animated
 * GIF detection is exercised on hand-crafted byte samples (and real temp files)
 * so the heuristic is pinned without relying on a binary fixture.
 */
final class MimePolicyTest extends TestCase {

	/** @var string[] Temp files to clean up. */
	private array $tmp = [];

	protected function tearDown(): void {
		foreach ( $this->tmp as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		$this->tmp = [];
		parent::tearDown();
	}

	// --- isStampable -------------------------------------------------------

	/** @dataProvider stampableProvider */
	public function testIsStampable( string $mime, bool $expected ): void {
		$this->assertSame( $expected, MimePolicy::isStampable( $mime ) );
	}

	/** @return array<string,array{0:string,1:bool}> */
	public static function stampableProvider(): array {
		return [
			'jpeg'             => [ 'image/jpeg', true ],
			'png'              => [ 'image/png', true ],
			'webp'             => [ 'image/webp', true ],
			'avif'             => [ 'image/avif', true ],
			'gif'              => [ 'image/gif', true ],
			'svg not raster'   => [ 'image/svg+xml', false ],
			'pdf not image'    => [ 'application/pdf', false ],
			'plain text'       => [ 'text/plain', false ],
			'empty'            => [ '', false ],
			'uppercase normal' => [ 'IMAGE/JPEG', true ],
			'padded'           => [ '  image/png  ', true ],
		];
	}

	// --- needsFlatten ------------------------------------------------------

	public function testNeedsFlattenOnlyForJpeg(): void {
		$this->assertTrue( MimePolicy::needsFlatten( 'image/jpeg' ) );
		$this->assertTrue( MimePolicy::needsFlatten( 'IMAGE/JPEG' ) );

		foreach ( [ 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/svg+xml', '' ] as $mime ) {
			$this->assertFalse( MimePolicy::needsFlatten( $mime ), "$mime should not need flatten" );
		}
	}

	// --- sniffAnimatedGifBytes (pure) --------------------------------------

	public function testSniffDetectsMultiFrameAnimation(): void {
		$this->assertTrue( MimePolicy::sniffAnimatedGifBytes( self::animatedGifBytes() ) );
	}

	public function testSniffRejectsSingleFrameGif89a(): void {
		$this->assertFalse( MimePolicy::sniffAnimatedGifBytes( self::singleFrameGif89aBytes() ) );
	}

	public function testSniffRejectsGif87a(): void {
		// GIF87a predates the Graphic Control Extension and is never animated.
		$bytes = 'GIF87a' . pack( 'v', 4 ) . pack( 'v', 4 ) . str_repeat( "\x00", 32 );
		$this->assertFalse( MimePolicy::sniffAnimatedGifBytes( $bytes ) );
	}

	public function testSniffRejectsNonGif(): void {
		$this->assertFalse( MimePolicy::sniffAnimatedGifBytes( "\xFF\xD8\xFF\xE0 jpeg-ish bytes" ) );
		$this->assertFalse( MimePolicy::sniffAnimatedGifBytes( '' ) );
		$this->assertFalse( MimePolicy::sniffAnimatedGifBytes( 'not an image at all' ) );
	}

	// --- isAnimatedGif (file path) -----------------------------------------

	public function testIsAnimatedGifTrueForAnimatedFile(): void {
		$path = $this->writeTmp( 'anim', self::animatedGifBytes() );
		$this->assertTrue( MimePolicy::isAnimatedGif( $path ) );
	}

	public function testIsAnimatedGifFalseForSingleFrameFile(): void {
		$path = $this->writeTmp( 'still', self::singleFrameGif89aBytes() );
		$this->assertFalse( MimePolicy::isAnimatedGif( $path ) );
	}

	public function testIsAnimatedGifFalseForMissingFile(): void {
		$this->assertFalse( MimePolicy::isAnimatedGif( '/no/such/file.gif' ) );
		$this->assertFalse( MimePolicy::isAnimatedGif( '' ) );
	}

	// --- helpers -----------------------------------------------------------

	/** Minimal valid 2-frame GIF89a (Graphic Control Extension per frame). */
	private static function animatedGifBytes(): string {
		$g  = 'GIF89a';
		$g .= pack( 'v', 2 ) . pack( 'v', 2 );     // 2x2 logical screen.
		$g .= chr( 0x80 ) . chr( 0 ) . chr( 0 );   // GCT present, 2 entries.
		$g .= "\x00\x00\x00\xFF\xFF\xFF";          // Global color table.
		// NETSCAPE2.0 loop block (typical of animations).
		$g .= "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
		// Frame 1.
		$g .= "\x21\xF9\x04\x00\x0A\x00\x00\x00";  // Graphic Control Extension.
		$g .= "\x2C\x00\x00\x00\x00\x02\x00\x02\x00\x00"; // Image descriptor.
		$g .= "\x02\x02\x4C\x01\x00";              // LZW data.
		// Frame 2.
		$g .= "\x21\xF9\x04\x00\x0A\x00\x00\x00";
		$g .= "\x2C\x00\x00\x00\x00\x02\x00\x02\x00\x00";
		$g .= "\x02\x02\x4C\x01\x00";
		$g .= "\x3B";                              // Trailer.
		return $g;
	}

	/** Minimal valid single-frame GIF89a (one Graphic Control Extension). */
	private static function singleFrameGif89aBytes(): string {
		$g  = 'GIF89a';
		$g .= pack( 'v', 2 ) . pack( 'v', 2 );
		$g .= chr( 0x80 ) . chr( 0 ) . chr( 0 );
		$g .= "\x00\x00\x00\xFF\xFF\xFF";
		$g .= "\x21\xF9\x04\x00\x00\x00\x00\x00";
		$g .= "\x2C\x00\x00\x00\x00\x02\x00\x02\x00\x00";
		$g .= "\x02\x02\x4C\x01\x00";
		$g .= "\x3B";
		return $g;
	}

	private function writeTmp( string $tag, string $bytes ): string {
		$path = sys_get_temp_dir() . '/ogwm-mime-' . $tag . '-' . uniqid( '', true ) . '.gif';
		file_put_contents( $path, $bytes );
		$this->tmp[] = $path;
		return $path;
	}
}
