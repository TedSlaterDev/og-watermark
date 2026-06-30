<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Pipeline;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Pipeline\SizeResolver;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * SizeResolver enumeration tests.
 *
 * The resolver is pure "what to stamp" logic over stubbed WordPress metadata, so
 * these tests build a real on-disk uploads tree (so dirname / realpath behave) and
 * stub only the four WP accessors it touches: wp_get_attachment_metadata,
 * get_attached_file, wp_get_original_image_path, and wp_check_filetype.
 *
 * Coverage: full+original+intermediates enumerated; enabled-list filtering;
 * min-dimension skip for intermediates; full/original ALWAYS kept regardless of
 * filters; the big-image original != full case; and path de-duplication.
 */
final class SizeResolverTest extends TestCase {

	private string $monthDir = '';

	/** @var array<int,string> Attachment ID => the attached (full / -scaled) absolute path. */
	private array $attached = [];

	/** @var array<int,string> Attachment ID => the true original absolute path. */
	private array $original = [];

	/** @var array<int,array<string,mixed>> Attachment ID => stubbed metadata. */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$root           = sys_get_temp_dir() . '/ogwm-sizes-' . uniqid( '', true );
		$this->monthDir = $root . '/uploads/2026/06';
		mkdir( $this->monthDir, 0777, true );

		Functions\when( 'get_attached_file' )->alias(
			fn( $id ) => $this->attached[ (int) $id ] ?? false
		);
		Functions\when( 'wp_get_original_image_path' )->alias(
			fn( $id ) => $this->original[ (int) $id ] ?? false
		);
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			fn( $id ) => $this->meta[ (int) $id ] ?? false
		);

		// Extension-sniff MIME, matching wp_check_filetype's shape for our formats.
		Functions\when( 'wp_check_filetype' )->alias(
			static function ( $filename ) {
				$ext  = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
				$map  = [
					'jpg'  => 'image/jpeg',
					'jpeg' => 'image/jpeg',
					'png'  => 'image/png',
					'webp' => 'image/webp',
					'gif'  => 'image/gif',
				];
				$type = $map[ $ext ] ?? '';
				return [
					'ext'  => '' === $type ? false : $ext,
					'type' => '' === $type ? false : $type,
				];
			}
		);
	}

	protected function tearDown(): void {
		$this->rrmdir( dirname( dirname( dirname( $this->monthDir ) ) ) );
		$this->attached = [];
		$this->original = [];
		$this->meta     = [];
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Full + all intermediates (small image: original == full).
	// -----------------------------------------------------------------

	public function testEnumeratesFullPlusAllIntermediatesWhenEnabledIsEmpty(): void {
		$id = 10;
		$this->stubSmallImage(
			$id,
			'photo.jpg',
			1200,
			800,
			[
				'medium'    => [ 'photo-300x200.jpg', 300, 200, 'image/jpeg' ],
				'large'     => [ 'photo-1024x683.jpg', 1024, 683, 'image/jpeg' ],
				'thumbnail' => [ 'photo-150x150.jpg', 150, 150, 'image/jpeg' ],
			]
		);

		// Empty enabled list => ALL sizes; min-dim 0 => no skip.
		$targets = SizeResolver::targets( $id, $this->settings( [], 0 ) );

		$slugs = array_column( $targets, 'slug' );
		$this->assertSame( [ 'full', 'medium', 'large', 'thumbnail' ], $slugs );

		// No separate 'original' for a small image (full IS the original).
		$this->assertNotContains( 'original', $slugs );

		// The full target carries the attached path, top-level dims, and the
		// sniffed MIME.
		$full = $this->bySlug( $targets, 'full' );
		$this->assertSame( $this->attached[ $id ], $full['path'] );
		$this->assertSame( 1200, $full['width'] );
		$this->assertSame( 800, $full['height'] );
		$this->assertSame( 'image/jpeg', $full['mime'] );

		// An intermediate carries the uploads-basedir + its recorded basename, dims,
		// and its own recorded mime-type.
		$medium = $this->bySlug( $targets, 'medium' );
		$this->assertSame( $this->monthDir . '/photo-300x200.jpg', $medium['path'] );
		$this->assertSame( 300, $medium['width'] );
		$this->assertSame( 200, $medium['height'] );
		$this->assertSame( 'image/jpeg', $medium['mime'] );
	}

	// -----------------------------------------------------------------
	// Enabled-list filtering of intermediates.
	// -----------------------------------------------------------------

	public function testEnabledListFiltersIntermediatesButAlwaysKeepsFull(): void {
		$id = 11;
		$this->stubSmallImage(
			$id,
			'photo.jpg',
			1200,
			800,
			[
				'medium'    => [ 'photo-300x200.jpg', 300, 200, 'image/jpeg' ],
				'large'     => [ 'photo-1024x683.jpg', 1024, 683, 'image/jpeg' ],
				'thumbnail' => [ 'photo-150x150.jpg', 150, 150, 'image/jpeg' ],
			]
		);

		// Only 'large' enabled — medium + thumbnail are excluded, full is forced in.
		$targets = SizeResolver::targets( $id, $this->settings( [ 'large' ], 0 ) );
		$slugs   = array_column( $targets, 'slug' );

		$this->assertSame( [ 'full', 'large' ], $slugs );
		$this->assertContains( 'full', $slugs, 'full is always stamped regardless of the enabled list' );
		$this->assertNotContains( 'medium', $slugs );
		$this->assertNotContains( 'thumbnail', $slugs );
	}

	// -----------------------------------------------------------------
	// Min-dimension skip — intermediates only.
	// -----------------------------------------------------------------

	public function testMinDimensionSkipsSmallIntermediatesOnly(): void {
		$id = 12;
		$this->stubSmallImage(
			$id,
			'photo.jpg',
			1200,
			800,
			[
				'medium'    => [ 'photo-300x200.jpg', 300, 200, 'image/jpeg' ], // shorter edge 200 < 300 => skip
				'large'     => [ 'photo-1024x683.jpg', 1024, 683, 'image/jpeg' ], // 683 >= 300 => keep
				'thumbnail' => [ 'photo-150x150.jpg', 150, 150, 'image/jpeg' ], // 150 < 300 => skip
			]
		);

		// min_dimension_px = 300; empty enabled => all-but-too-small.
		$targets = SizeResolver::targets( $id, $this->settings( [], 300 ) );
		$slugs   = array_column( $targets, 'slug' );

		$this->assertSame( [ 'full', 'large' ], $slugs );
		$this->assertNotContains( 'medium', $slugs, 'medium 300x200 has a shorter edge below 300' );
		$this->assertNotContains( 'thumbnail', $slugs );
	}

	public function testMinDimensionNeverSkipsTheFull(): void {
		// A full smaller than the threshold (e.g. a 250px wide upload) must STILL be
		// stamped — the anti-theft target is never gated by min-dimension.
		$id = 13;
		$this->stubSmallImage( $id, 'tiny.png', 250, 180, [] );

		$targets = SizeResolver::targets( $id, $this->settings( [], 300 ) );
		$slugs   = array_column( $targets, 'slug' );

		$this->assertSame( [ 'full' ], $slugs );
		$full = $this->bySlug( $targets, 'full' );
		$this->assertSame( 250, $full['width'] );
		$this->assertSame( 'image/png', $full['mime'] );
	}

	// -----------------------------------------------------------------
	// Big-image: original != full, both always present.
	// -----------------------------------------------------------------

	public function testBigImageEnumeratesOriginalAndFullAndBothBypassFilters(): void {
		$id = 20;

		// -scaled is the attached file; the true original is a separate, larger file.
		$attached = $this->monthDir . '/big-scaled.jpg';
		$original = $this->monthDir . '/big.jpg';
		file_put_contents( $attached, 'scaled-bytes' );
		file_put_contents( $original, 'original-bytes' );

		$this->attached[ $id ] = $attached;
		$this->original[ $id ] = $original;
		$this->meta[ $id ]     = [
			'file'                  => '2026/06/big-scaled.jpg',
			'width'                 => 2560,
			'height'                => 1707,
			'original_image_width'  => 6000,
			'original_image_height' => 4000,
			'sizes'                 => [
				// A below-threshold intermediate that WOULD be skipped.
				'thumbnail' => $this->sizeMeta( 'big-150x150.jpg', 150, 150, 'image/jpeg' ),
				'large'     => $this->sizeMeta( 'big-1024x683.jpg', 1024, 683, 'image/jpeg' ),
			],
		];

		// Enable ONLY 'large' AND set a high min-dim — proves full+original bypass
		// both the enabled list and the threshold, while the intermediate obeys them.
		$targets = SizeResolver::targets( $id, $this->settings( [ 'large' ], 300 ) );
		$slugs   = array_column( $targets, 'slug' );

		$this->assertSame( [ 'full', 'original', 'large' ], $slugs );

		$full = $this->bySlug( $targets, 'full' );
		$this->assertSame( $attached, $full['path'] );
		$this->assertSame( 2560, $full['width'] );

		$orig = $this->bySlug( $targets, 'original' );
		$this->assertSame( $original, $orig['path'] );
		$this->assertSame( 6000, $orig['width'], 'original carries the true original dimensions' );
		$this->assertSame( 4000, $orig['height'] );
		$this->assertSame( 'image/jpeg', $orig['mime'] );

		// thumbnail (150x150) was excluded BOTH by the enabled list and the min-dim;
		// large survived both.
		$this->assertNotContains( 'thumbnail', $slugs );
		$this->assertContains( 'large', $slugs );
	}

	public function testOriginalOmittedWhenItEqualsTheFull(): void {
		// Some big-image-restored attachments report wp_get_original_image_path() ==
		// the attached file. The resolver must NOT emit a duplicate 'original'.
		$id = 21;
		$this->stubSmallImage( $id, 'same.jpg', 1500, 1000, [] );

		// Make the resolver return the SAME path as get_attached_file().
		$this->original[ $id ] = $this->attached[ $id ];

		$targets = SizeResolver::targets( $id, $this->settings( [], 0 ) );
		$slugs   = array_column( $targets, 'slug' );

		$this->assertSame( [ 'full' ], $slugs );
		$this->assertNotContains( 'original', $slugs );
	}

	// -----------------------------------------------------------------
	// Path de-duplication: a size file colliding with the full is dropped.
	// -----------------------------------------------------------------

	public function testDeduplicatesIdenticalPaths(): void {
		$id = 30;

		$attached = $this->monthDir . '/dup.jpg';
		file_put_contents( $attached, 'bytes' );

		$this->attached[ $id ] = $attached;
		$this->original[ $id ] = $attached; // same as full
		$this->meta[ $id ]     = [
			'file'   => '2026/06/dup.jpg',
			'width'  => 900,
			'height' => 600,
			'sizes'  => [
				// A pathological size whose 'file' resolves to the SAME path as full.
				'self'   => $this->sizeMeta( 'dup.jpg', 900, 600, 'image/jpeg' ),
				'medium' => $this->sizeMeta( 'dup-300x200.jpg', 300, 200, 'image/jpeg' ),
			],
		];

		$targets = SizeResolver::targets( $id, $this->settings( [], 0 ) );
		$slugs   = array_column( $targets, 'slug' );

		// 'full' wins the collision; the duplicate 'self' size is dropped; 'medium'
		// (a distinct path) survives. No path appears twice.
		$this->assertSame( [ 'full', 'medium' ], $slugs );
		$paths = array_column( $targets, 'path' );
		$this->assertSame( $paths, array_values( array_unique( $paths ) ), 'no path may appear twice' );
	}

	// -----------------------------------------------------------------
	// Per-size recorded MIME is honored (WebP-output host).
	// -----------------------------------------------------------------

	public function testHonorsRecordedPerSizeMime(): void {
		$id = 40;
		$this->stubSmallImage(
			$id,
			'shot.jpg',
			1200,
			800,
			[
				// Host converted this size to WebP; the recorded mime-type must win
				// over the .webp extension sniff (here they agree, but the recorded
				// value is authoritative).
				'large' => [ 'shot-1024x683.webp', 1024, 683, 'image/webp' ],
			]
		);

		$targets = SizeResolver::targets( $id, $this->settings( [], 0 ) );
		$large   = $this->bySlug( $targets, 'large' );

		$this->assertSame( 'image/webp', $large['mime'] );
		$this->assertSame( $this->monthDir . '/shot-1024x683.webp', $large['path'] );
	}

	// -----------------------------------------------------------------
	// Degenerate input: no attached file => empty target list.
	// -----------------------------------------------------------------

	public function testReturnsEmptyWhenNoAttachedFile(): void {
		$id                    = 50;
		$this->attached[ $id ] = false;
		$this->meta[ $id ]     = [
			'width'  => 100,
			'height' => 100,
		];

		$this->assertSame( [], SizeResolver::targets( $id, $this->settings( [], 0 ) ) );
	}

	public function testHandlesMissingSizesArrayGracefully(): void {
		// Metadata with no 'sizes' key at all (e.g. a freshly imported image) must
		// still yield the full target without warnings.
		$id = 51;
		$this->stubSmallImage( $id, 'bare.png', 800, 600, [] );
		unset( $this->meta[ $id ]['sizes'] );

		$targets = SizeResolver::targets( $id, $this->settings( [], 0 ) );
		$this->assertSame( [ 'full' ], array_column( $targets, 'slug' ) );
	}

	// -----------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------

	/**
	 * Stub a small image where the attached file IS the original (no -scaled).
	 *
	 * @param int                                        $id     Attachment ID.
	 * @param string                                     $name   Basename of the full file.
	 * @param int                                        $w      Full width.
	 * @param int                                        $h      Full height.
	 * @param array<string,array{0:string,1:int,2:int,3:string}> $sizes slug => [file, w, h, mime].
	 */
	private function stubSmallImage( int $id, string $name, int $w, int $h, array $sizes ): void {
		$path = $this->monthDir . '/' . $name;
		file_put_contents( $path, 'image-bytes-' . $name );

		$this->attached[ $id ] = $path;
		$this->original[ $id ] = $path; // small image: full IS the original.

		$sizeMeta = [];
		foreach ( $sizes as $slug => $tuple ) {
			$sizeMeta[ $slug ] = $this->sizeMeta( $tuple[0], $tuple[1], $tuple[2], $tuple[3] );
		}

		$this->meta[ $id ] = [
			'file'   => '2026/06/' . $name,
			'width'  => $w,
			'height' => $h,
			'sizes'  => $sizeMeta,
		];
	}

	/** Build one $meta['sizes'][slug] entry. */
	private function sizeMeta( string $file, int $w, int $h, string $mime ): array {
		return [
			'file'      => $file,
			'width'     => $w,
			'height'    => $h,
			'mime-type' => $mime,
		];
	}

	/**
	 * Grouped settings carrying only the keys SizeResolver reads.
	 *
	 * @param array<int,string> $enabled
	 * @return array<string,mixed>
	 */
	private function settings( array $enabled, int $minDim ): array {
		return [
			'sizes' => [
				'enabled'          => $enabled,
				'min_dimension_px' => $minDim,
			],
		];
	}

	/**
	 * Find the target row for a slug.
	 *
	 * @param array<int,array{slug:string,path:string,mime:string,width:int,height:int}> $targets
	 * @return array{slug:string,path:string,mime:string,width:int,height:int}
	 */
	private function bySlug( array $targets, string $slug ): array {
		foreach ( $targets as $target ) {
			if ( $slug === $target['slug'] ) {
				return $target;
			}
		}
		$this->fail( "no target with slug '{$slug}'" );
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
			is_dir( $path ) ? $this->rrmdir( $path ) : @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
