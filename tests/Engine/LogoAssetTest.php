<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Engine;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Engine\LogoAsset;
use OrchardGrove\OgWatermark\Tests\TestCase;

final class LogoAssetTest extends TestCase {

	private string $dir = '';
	private string $pngPath = '';
	private string $jpegPath = '';

	protected function setUp(): void {
		parent::setUp();

		$this->dir = sys_get_temp_dir() . '/ogwm-logo-' . uniqid( '', true );
		mkdir( $this->dir, 0777, true );

		// A real 2x2 PNG so getimagesize() reports IMAGETYPE_PNG genuinely.
		$this->pngPath = $this->dir . '/logo.png';
		file_put_contents(
			$this->pngPath,
			base64_decode(
				'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAEklEQVR4nGP8z8Dwn4EIwDiqEAAk6gMRYUjUYwAAAABJRU5ErkJggg=='
			)
		);

		// A real tiny JPEG so the non-PNG branch is exercised with genuine bytes.
		$this->jpegPath = $this->dir . '/logo.jpg';
		file_put_contents(
			$this->jpegPath,
			base64_decode(
				'/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////'
				. '////////////////////////////////////////2wBDAf//////////////////////////////////'
				. '////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEB'
				. 'AxEB/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/xAAUAQEAAAAAAAAAAA'
				. 'AAAAAAAAAA/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AvwA//9k='
			)
		);
	}

	protected function tearDown(): void {
		foreach ( [ $this->pngPath, $this->jpegPath ] as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		parent::tearDown();
	}

	public function testReturnsRealpathForValidPngAttachment(): void {
		Functions\when( 'get_attached_file' )->justReturn( $this->pngPath );
		$this->assertSame( realpath( $this->pngPath ), LogoAsset::resolvePath( 7 ) );
	}

	public function testReturnsNullForNonPngAttachment(): void {
		Functions\when( 'get_attached_file' )->justReturn( $this->jpegPath );
		$this->assertNull( LogoAsset::resolvePath( 8 ) );
	}

	public function testReturnsNullForMissingFile(): void {
		Functions\when( 'get_attached_file' )->justReturn( $this->dir . '/does-not-exist.png' );
		$this->assertNull( LogoAsset::resolvePath( 9 ) );
	}

	public function testReturnsNullWhenGetAttachedFileReturnsFalse(): void {
		Functions\when( 'get_attached_file' )->justReturn( false );
		$this->assertNull( LogoAsset::resolvePath( 10 ) );
	}

	public function testReturnsNullWhenGetAttachedFileReturnsEmptyString(): void {
		Functions\when( 'get_attached_file' )->justReturn( '' );
		$this->assertNull( LogoAsset::resolvePath( 11 ) );
	}

	public function testReturnsNullForZeroId(): void {
		// get_attached_file must not even be consulted for a non-positive id.
		Functions\expect( 'get_attached_file' )->never();
		$this->assertNull( LogoAsset::resolvePath( 0 ) );
	}

	public function testReturnsNullForNegativeId(): void {
		Functions\expect( 'get_attached_file' )->never();
		$this->assertNull( LogoAsset::resolvePath( -5 ) );
	}
}
