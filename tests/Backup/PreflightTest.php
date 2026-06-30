<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Backup;

use OrchardGrove\OgWatermark\Backup\Preflight;
use OrchardGrove\OgWatermark\Support\Meta;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Exercises the real offload/stub detection against real temp files.
 *
 * Preflight touches no WordPress functions (only the Meta constants), so the
 * base TestCase scaffolding is enough. Every image is written to a real temp
 * file so getimagesize()/filesize()/realpath() actually run and the dimension
 * cross-check — the load-bearing offloaded-stub defense — is genuinely tested.
 */
final class PreflightTest extends TestCase {

	/** Temp files created during a test, removed in tearDown. */
	private array $tmpFiles = [];

	protected function tearDown(): void {
		foreach ( $this->tmpFiles as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- test-only temp cleanup.
			}
		}
		$this->tmpFiles = [];
		parent::tearDown();
	}

	private function requiresGd(): void {
		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'imagejpeg' ) ) {
			$this->markTestSkipped( 'GD extension not available on this host.' );
		}
	}

	private function tmpPath( string $suffix ): string {
		$path             = sys_get_temp_dir() . '/ogwm-preflight-' . uniqid( '', true ) . $suffix;
		$this->tmpFiles[] = $path;
		return $path;
	}

	/** Write a solid-colour JPEG of the given dimensions and return its path. */
	private function makeJpeg( int $w, int $h ): string {
		$img  = imagecreatetruecolor( $w, $h );
		$fill = imagecolorallocate( $img, 200, 180, 160 );
		imagefilledrectangle( $img, 0, 0, $w, $h, $fill );
		$path = $this->tmpPath( '.jpg' );
		imagejpeg( $img, $path, 90 );
		unset( $img );
		return $path;
	}

	// -----------------------------------------------------------------
	// validateLocalOriginal — happy path
	// -----------------------------------------------------------------

	public function testValidImageWithMatchingMetaPasses(): void {
		$this->requiresGd();

		$path = $this->makeJpeg( 800, 600 );
		$meta = [ 'width' => 800, 'height' => 600 ];

		$result = Preflight::validateLocalOriginal( $path, $meta );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( Preflight::STATUS_OK, $result['status'] );
		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( '', $result['reason'] );
	}

	public function testValidImagePassesWhenMetaHasNoDimensions(): void {
		// No width/height in meta => the cross-check is skipped, but a genuine
		// readable raster above the floor still passes.
		$this->requiresGd();

		$path = $this->makeJpeg( 320, 240 );

		$result = Preflight::validateLocalOriginal( $path, [] );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( Preflight::STATUS_OK, $result['status'] );
	}

	public function testTinyRoundingDriftStillPasses(): void {
		// A single-pixel difference is within tolerance — a real original, not a
		// stub. (1px of 600 is far under the 2% allowance.)
		$this->requiresGd();

		$path = $this->makeJpeg( 800, 600 );
		$meta = [ 'width' => 801, 'height' => 599 ];

		$result = Preflight::validateLocalOriginal( $path, $meta );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( Preflight::STATUS_OK, $result['status'] );
	}

	// -----------------------------------------------------------------
	// validateLocalOriginal — skipped_offloaded (ambiguous / stub)
	// -----------------------------------------------------------------

	public function testDimensionMismatchYieldsSkippedOffloaded(): void {
		// The on-disk file is a tiny placeholder while the metadata describes the
		// real, large original — the canonical offload-stub signature.
		$this->requiresGd();

		$path = $this->makeJpeg( 60, 45 );
		$meta = [ 'width' => 4000, 'height' => 3000 ];

		$result = Preflight::validateLocalOriginal( $path, $meta );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( Meta::STATUS_SKIPPED_OFFLOADED, $result['status'] );
		$this->assertNotSame( '', $result['reason'] );
	}

	public function testZeroByteFileYieldsSkippedOffloaded(): void {
		$path = $this->tmpPath( '.jpg' );
		touch( $path );
		$this->assertSame( 0, filesize( $path ) );

		$result = Preflight::validateLocalOriginal( $path, [ 'width' => 800, 'height' => 600 ] );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( Meta::STATUS_SKIPPED_OFFLOADED, $result['status'] );
	}

	public function testNearEmptyFileBelowFloorYieldsSkippedOffloaded(): void {
		// A few bytes can never be a real image — offload stub, not a hard error.
		$path = $this->tmpPath( '.jpg' );
		file_put_contents( $path, 'stub' );

		$result = Preflight::validateLocalOriginal( $path, [ 'width' => 800, 'height' => 600 ] );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( Meta::STATUS_SKIPPED_OFFLOADED, $result['status'] );
	}

	public function testMissingFileYieldsSkippedOffloaded(): void {
		$path = sys_get_temp_dir() . '/ogwm-preflight-does-not-exist-' . uniqid( '', true ) . '.jpg';
		$this->assertFileDoesNotExist( $path );

		$result = Preflight::validateLocalOriginal( $path, [ 'width' => 800, 'height' => 600 ] );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( Meta::STATUS_SKIPPED_OFFLOADED, $result['status'] );
	}

	public function testEmptyPathYieldsSkippedOffloaded(): void {
		$result = Preflight::validateLocalOriginal( '', [ 'width' => 800, 'height' => 600 ] );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( Meta::STATUS_SKIPPED_OFFLOADED, $result['status'] );
	}

	public function testDirectoryPathYieldsSkippedOffloaded(): void {
		// A directory resolves on disk but is not a regular file.
		$dir = sys_get_temp_dir() . '/ogwm-preflight-dir-' . uniqid( '', true );
		mkdir( $dir, 0777, true );
		$this->tmpFiles[] = $dir; // tearDown's unlink no-ops on a dir; clean below.

		$result = Preflight::validateLocalOriginal( $dir, [] );

		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- test cleanup.

		$this->assertFalse( $result['ok'] );
		$this->assertSame( Meta::STATUS_SKIPPED_OFFLOADED, $result['status'] );
	}

	// -----------------------------------------------------------------
	// validateLocalOriginal — failed (present but provably not an image)
	// -----------------------------------------------------------------

	public function testNonImageTextFileYieldsFailed(): void {
		// Present, above the byte floor, but getimagesize() rejects it.
		$path = $this->tmpPath( '.jpg' );
		file_put_contents( $path, str_repeat( 'this is plain text, not an image. ', 50 ) );
		$this->assertGreaterThan( 256, filesize( $path ) );

		$result = Preflight::validateLocalOriginal( $path, [] );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( Meta::STATUS_FAILED, $result['status'] );
		$this->assertNotSame( '', $result['reason'] );
	}

	public function testStatusDistinguishesStubFromCorruptNonImage(): void {
		// The two negative outcomes must be different so the orchestrator can
		// skip an offload but hard-fail a corrupt original.
		$this->requiresGd();

		$stub   = $this->makeJpeg( 50, 50 );
		$stubRC = Preflight::validateLocalOriginal( $stub, [ 'width' => 5000, 'height' => 5000 ] );

		$bad   = $this->tmpPath( '.png' );
		file_put_contents( $bad, str_repeat( 'definitely not a png ', 40 ) );
		$badRC = Preflight::validateLocalOriginal( $bad, [] );

		$this->assertSame( Meta::STATUS_SKIPPED_OFFLOADED, $stubRC['status'] );
		$this->assertSame( Meta::STATUS_FAILED, $badRC['status'] );
		$this->assertNotSame( $stubRC['status'], $badRC['status'] );
	}

	// -----------------------------------------------------------------
	// hasDiskSpaceFor
	// -----------------------------------------------------------------

	public function testHasDiskSpaceForFitsSmallBackup(): void {
		// A 1 KiB backup trivially fits the system temp dir.
		$this->assertTrue( Preflight::hasDiskSpaceFor( 1024, sys_get_temp_dir() ) );
	}

	public function testHasDiskSpaceForRejectsImplausiblyLargeBackup(): void {
		// PHP_INT_MAX bytes cannot fit any real volume; the margin makes this a
		// hard false (and confirms an honest "no" path exists).
		$this->assertFalse( Preflight::hasDiskSpaceFor( PHP_INT_MAX, sys_get_temp_dir() ) );
	}

	public function testHasDiskSpaceForZeroBytesAlwaysTrue(): void {
		$this->assertTrue( Preflight::hasDiskSpaceFor( 0, sys_get_temp_dir() ) );
	}

	public function testHasDiskSpaceForUnmeasurableDirReturnsTrue(): void {
		// disk_free_space() returns false on a non-existent dir on this host;
		// "can't tell" must NOT block the backup.
		$bogus = sys_get_temp_dir() . '/ogwm-no-such-dir-' . uniqid( '', true );
		$this->assertFalse( is_dir( $bogus ) );

		$this->assertTrue( Preflight::hasDiskSpaceFor( 1024, $bogus ) );
	}

	public function testHasDiskSpaceForEnforcesMarginAboveFreeSpace(): void {
		// Ask for just under the actual free space: the margin must push it over the
		// edge, proving the safety margin is really applied.
		$free = disk_free_space( sys_get_temp_dir() );
		if ( false === $free ) {
			$this->markTestSkipped( 'disk_free_space() unavailable on this host.' );
		}

		$justUnderFree = (int) ( $free * 0.95 );
		$this->assertGreaterThan( 0, $justUnderFree );

		$this->assertFalse( Preflight::hasDiskSpaceFor( $justUnderFree, sys_get_temp_dir() ) );
	}

	public function testHasDiskSpaceForReservesWorstCaseDoubleForCrossFilesystem(): void {
		// The transactional write peaks at ~2x the file size on the cross-FS commit
		// path (partial + final dest coexist on the dest volume). Asking for just
		// OVER half the free space must therefore be rejected — proving the
		// reservation is the worst-case 2x, not the old 1.1x (under which a
		// just-over-half request would have wrongly passed).
		$free = disk_free_space( sys_get_temp_dir() );
		if ( false === $free ) {
			$this->markTestSkipped( 'disk_free_space() unavailable on this host.' );
		}

		$justOverHalf = (int) ( $free * 0.51 );
		$this->assertGreaterThan( 0, $justOverHalf );

		$this->assertFalse(
			Preflight::hasDiskSpaceFor( $justOverHalf, sys_get_temp_dir() ),
			'just over half the free space must be rejected: peak usage is ~2x'
		);
	}

	public function testMetadataLessSmallImageIsAcceptedDocumentingTheTradeoff(): void {
		// DOCUMENTED TRADE-OFF: when the attachment metadata carries no width/height,
		// the dimension cross-check (the primary offload-stub defense) is skipped, so
		// a real, decodable small JPEG above the 256-byte floor is accepted as a
		// clean master. This pins the trade-off so a future tightening is intentional
		// rather than an accidental behavior change.
		$this->requiresGd();

		$path = $this->makeJpeg( 40, 40 ); // Small but a genuine raster above the floor.
		$this->assertGreaterThan( 256, filesize( $path ) );

		$result = Preflight::validateLocalOriginal( $path, [] ); // No width/height.

		$this->assertTrue( $result['ok'], 'metadata-less small image is accepted (documented trade-off)' );
		$this->assertSame( Preflight::STATUS_OK, $result['status'] );
	}
}
