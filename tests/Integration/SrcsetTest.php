<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Integration;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Integration\Srcset;
use OrchardGrove\OgWatermark\Pipeline\SizeResolver;
use OrchardGrove\OgWatermark\Settings\Options;
use OrchardGrove\OgWatermark\Support\Meta;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Unit tests for the srcset coherence filter.
 *
 * The crux is the FAST EXIT (zero work unless `output.srcset_hide_unstamped` is
 * on) and the partial-window behaviour: drop candidates whose files are not yet
 * recorded in `_ogwm_sizes_done`, keep the stamped ones, and NEVER hand back an
 * empty srcset.
 *
 * `Options` and `Srcset` are exercised for real (they are pure over the WP funcs
 * they call); only the WP functions — `get_option` and `get_post_meta` — are
 * stubbed via Brain Monkey from a small in-memory table, so the assertions are
 * over observable behaviour rather than internal seams.
 */
final class SrcsetTest extends TestCase {

	private const ATTACHMENT_ID = 77;

	/** @var array<string,mixed> In-memory option table. */
	private array $options = [];

	/** @var array<int,array<string,mixed>> In-memory post meta, keyed by id. */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( $id, $key, $single = false ) => $this->meta[ (int) $id ][ $key ] ?? ''
		);
	}

	protected function tearDown(): void {
		$this->options = [];
		$this->meta    = [];
		parent::tearDown();
	}

	// =====================================================================
	// register() wires the documented filter.
	// =====================================================================

	public function testRegisterAddsTheSrcsetFilter(): void {
		$captured = [];
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $cb, $priority = 10, $args = 1 ) use ( &$captured ): void {
				$captured = [ $hook, $cb, $priority, $args ];
			}
		);

		Srcset::register();

		$this->assertSame(
			[ 'wp_calculate_image_srcset', [ Srcset::class, 'filter' ], 10, 5 ],
			$captured
		);
	}

	// =====================================================================
	// FAST EXIT — pass-through when the toggle is OFF.
	// =====================================================================

	public function testPassesThroughUnchangedWhenToggleOff(): void {
		// Toggle defaults to false; even a flagged, mid-processing attachment is a
		// pure pass-through so a settled library pays nothing.
		$this->flag( self::ATTACHMENT_ID );
		$this->setStatus( self::ATTACHMENT_ID, Meta::STATUS_QUEUED );
		$this->setSizesDone( self::ATTACHMENT_ID, [ SizeResolver::SLUG_FULL => 100 ] );

		$sources = $this->sources();

		$result = Srcset::filter( $sources, [ 1024, 768 ], 'https://x/img.jpg', $this->imageMeta(), self::ATTACHMENT_ID );

		$this->assertSame( $sources, $result );
	}

	public function testFastExitDoesNotReadMetaWhenToggleOff(): void {
		// Prove the fast exit happens BEFORE any meta read: get_post_meta must never
		// be called when the toggle is off.
		Functions\expect( 'get_post_meta' )->never();

		$sources = $this->sources();

		$result = Srcset::filter( $sources, [ 1024, 768 ], 'https://x/img.jpg', $this->imageMeta(), self::ATTACHMENT_ID );

		$this->assertSame( $sources, $result );
	}

	// =====================================================================
	// Pass-through when ON but the attachment is not in a partial window.
	// =====================================================================

	public function testPassesThroughForNonFlaggedAttachmentWhenOn(): void {
		$this->enableToggle();
		// Not flagged: every file is clean, nothing to hide.
		$sources = $this->sources();

		$result = Srcset::filter( $sources, [ 1024, 768 ], 'https://x/img.jpg', $this->imageMeta(), self::ATTACHMENT_ID );

		$this->assertSame( $sources, $result );
	}

	public function testPassesThroughForFullyStampedAttachmentWhenOn(): void {
		$this->enableToggle();
		$this->flag( self::ATTACHMENT_ID );
		$this->setStatus( self::ATTACHMENT_ID, Meta::STATUS_WATERMARKED );
		// Every served slug (full + medium + large) recorded as done → fully stamped.
		$this->setSizesDone(
			self::ATTACHMENT_ID,
			[
				SizeResolver::SLUG_FULL => 100,
				'medium'                => 100,
				'large'                 => 100,
			]
		);

		$sources = $this->sources();

		$result = Srcset::filter( $sources, [ 1024, 768 ], 'https://x/img.jpg', $this->imageMeta(), self::ATTACHMENT_ID );

		$this->assertSame( $sources, $result );
	}

	public function testWatermarkedButMissingSizeIsTreatedAsPartialWindow(): void {
		// Status is watermarked, but the freshly-registered 'large' size is NOT yet
		// recorded — that is still a partial window for 'large', so its unstamped
		// source must be dropped.
		$this->enableToggle();
		$this->flag( self::ATTACHMENT_ID );
		$this->setStatus( self::ATTACHMENT_ID, Meta::STATUS_WATERMARKED );
		$this->setSizesDone(
			self::ATTACHMENT_ID,
			[
				SizeResolver::SLUG_FULL => 100,
				'medium'                => 100,
			]
		);

		$result = Srcset::filter( $this->sources(), [ 1024, 768 ], 'https://x/img.jpg', $this->imageMeta(), self::ATTACHMENT_ID );

		$this->assertArrayHasKey( 1024, $result, 'full stays' );
		$this->assertArrayHasKey( 300, $result, 'stamped medium stays' );
		$this->assertArrayNotHasKey( 768, $result, 'unstamped large dropped' );
	}

	// =====================================================================
	// Mid-processing window — drop unstamped, keep stamped.
	// =====================================================================

	public function testDropsUnstampedSourcesAndKeepsStampedWhenMidProcessing(): void {
		$this->enableToggle();
		$this->flag( self::ATTACHMENT_ID );
		$this->setStatus( self::ATTACHMENT_ID, Meta::STATUS_QUEUED );
		// Only the full + medium have stamped so far; large has not.
		$this->setSizesDone(
			self::ATTACHMENT_ID,
			[
				SizeResolver::SLUG_FULL => 100,
				'medium'                => 100,
			]
		);

		$result = Srcset::filter( $this->sources(), [ 1024, 768 ], 'https://x/img.jpg', $this->imageMeta(), self::ATTACHMENT_ID );

		// full (1024) + medium (300) kept; large (768) — recorded nowhere — dropped.
		$this->assertSame( [ 1024, 300 ], array_keys( $result ) );
		$this->assertSame( 'https://x/img.jpg', $result[1024]['url'] );
		$this->assertSame( 'https://x/img-300x225.jpg', $result[300]['url'] );
	}

	public function testNeverReturnsAnEmptySrcset(): void {
		// Nothing recorded as stamped yet: filtering would empty the set, so the
		// original sources are returned untouched rather than handing the browser an
		// empty srcset.
		$this->enableToggle();
		$this->flag( self::ATTACHMENT_ID );
		$this->setStatus( self::ATTACHMENT_ID, Meta::STATUS_QUEUED );
		$this->setSizesDone( self::ATTACHMENT_ID, [] );

		$sources = $this->sources();

		$result = Srcset::filter( $sources, [ 1024, 768 ], 'https://x/img.jpg', $this->imageMeta(), self::ATTACHMENT_ID );

		$this->assertSame( $sources, $result );
	}

	public function testKeepsUnknownCandidatesNotInThisAttachmentsMeta(): void {
		// A candidate file that is NOT part of this attachment's recorded set (e.g.
		// injected by another plugin) is conservatively kept — we only DROP files we
		// can prove are unstamped.
		$this->enableToggle();
		$this->flag( self::ATTACHMENT_ID );
		$this->setStatus( self::ATTACHMENT_ID, Meta::STATUS_QUEUED );
		$this->setSizesDone( self::ATTACHMENT_ID, [ SizeResolver::SLUG_FULL => 100 ] );

		$sources = $this->sources();
		$sources[999] = [
			'url'        => 'https://x/foreign-999x999.jpg',
			'descriptor' => 'w',
			'value'      => 999,
		];

		$result = Srcset::filter( $sources, [ 1024, 768 ], 'https://x/img.jpg', $this->imageMeta(), self::ATTACHMENT_ID );

		$this->assertArrayHasKey( 1024, $result, 'stamped full kept' );
		$this->assertArrayHasKey( 999, $result, 'unknown foreign candidate kept' );
		$this->assertArrayNotHasKey( 300, $result, 'unstamped medium dropped' );
		$this->assertArrayNotHasKey( 768, $result, 'unstamped large dropped' );
	}

	// =====================================================================
	// Fixtures / helpers.
	// =====================================================================

	/**
	 * A srcset keyed by width: the full (1024), a stamped intermediate medium
	 * (300), and an unstamped intermediate large (768).
	 *
	 * @return array<int,array{url:string,descriptor:string,value:int}>
	 */
	private function sources(): array {
		return [
			1024 => [
				'url'        => 'https://x/img.jpg',
				'descriptor' => 'w',
				'value'      => 1024,
			],
			300  => [
				'url'        => 'https://x/img-300x225.jpg',
				'descriptor' => 'w',
				'value'      => 300,
			],
			768  => [
				'url'        => 'https://x/img-768x576.jpg',
				'descriptor' => 'w',
				'value'      => 768,
			],
		];
	}

	/**
	 * Attachment metadata mapping the full file + the medium/large intermediates,
	 * mirroring the shape WordPress passes to the srcset filter.
	 *
	 * @return array<string,mixed>
	 */
	private function imageMeta(): array {
		return [
			'file'  => '2026/06/img.jpg',
			'width' => 1024,
			'sizes' => [
				'medium' => [
					'file'   => 'img-300x225.jpg',
					'width'  => 300,
					'height' => 225,
				],
				'large'  => [
					'file'   => 'img-768x576.jpg',
					'width'  => 768,
					'height' => 576,
				],
			],
		];
	}

	private function enableToggle(): void {
		$this->options[ Options::OPTION ] = [ 'output' => [ 'srcset_hide_unstamped' => true ] ];
	}

	private function flag( int $id ): void {
		$this->meta[ $id ][ Meta::ENABLED ] = '1';
	}

	private function setStatus( int $id, string $status ): void {
		$this->meta[ $id ][ Meta::STATUS ] = $status;
	}

	/** @param array<string,int> $done */
	private function setSizesDone( int $id, array $done ): void {
		$this->meta[ $id ][ Meta::SIZES_DONE ] = $done;
	}
}
