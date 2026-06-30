<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Admin;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Admin\MediaField;
use OrchardGrove\OgWatermark\Support\Meta;
use OrchardGrove\OgWatermark\Tests\TestCase;
use WP_Post;

/**
 * MediaField — the per-image opt-in control that rides core's
 * `attachment_fields_to_edit` / `attachment_fields_to_save` filters.
 *
 * The load-bearing properties under test:
 *
 *  1. A NO-JS save persists the opt-in flag ({@see Meta::ENABLED} = '1') AND seeds
 *     {@see Meta::STATUS_QUEUED} so the next bulk run picks the attachment up — and
 *     it composites NOTHING (no Processor, no image work, just meta).
 *  2. Clearing the checkbox removes the flag.
 *  3. A non-image attachment renders NOTHING — the field is never injected and a
 *     save against it is a no-op (the guard is wp_attachment_is_image).
 *  4. The seeded-queued status never stomps a committed lifecycle state.
 *
 * Every WP surface is Brain-Monkey-stubbed; the in-memory meta store round-trips
 * update/get/delete exactly as the DB would, so the persistence assertions are real.
 */
final class MediaFieldTest extends TestCase {

	/** @var array<int,array<string,mixed>> In-memory post meta keyed by attachment id. */
	private array $meta = [];

	/** @var array<int,bool> Which ids wp_attachment_is_image() reports true for. */
	private array $images = [];

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'checked' )->alias(
			static fn( $a, $b = true, $echo = true ) => ( $a === $b || ( (bool) $a === (bool) $b ) ) ? ' checked="checked"' : ''
		);

		$this->stubMeta();

		Functions\when( 'wp_attachment_is_image' )->alias(
			fn( $id ) => ! empty( $this->images[ (int) $id ] )
		);
	}

	/** In-memory get/update/delete_post_meta backing every persistence assertion. */
	private function stubMeta(): void {
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = false ) {
				$value = $this->meta[ (int) $id ][ $key ] ?? ( $single ? '' : [] );
				return $value;
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ (int) $id ][ $key ] );
				return true;
			}
		);
	}

	/** Build an attachment WP_Post double. */
	private function attachment( int $id ): WP_Post {
		return new WP_Post( [ 'ID' => $id, 'post_type' => 'attachment' ] );
	}

	// -----------------------------------------------------------------
	// Render guard: non-image renders nothing.
	// -----------------------------------------------------------------

	public function testAddFieldInjectsControlForImage(): void {
		$this->images[7] = true;

		$fields = MediaField::addField( [], $this->attachment( 7 ) );

		$this->assertArrayHasKey( 'ogwm_enabled', $fields );
		$this->assertSame( 'html', $fields['ogwm_enabled']['input'] );
		$this->assertStringContainsString( 'Watermark this image', $fields['ogwm_enabled']['html'] );
		$this->assertStringContainsString( 'ogwm-apply', $fields['ogwm_enabled']['html'] );
		$this->assertStringContainsString( 'ogwm-badge', $fields['ogwm_enabled']['html'] );
	}

	public function testAddFieldRendersNothingForNonImage(): void {
		$this->images[9] = false; // e.g. a PDF / audio attachment.

		$fields = MediaField::addField( [ 'existing' => [ 'label' => 'x' ] ], $this->attachment( 9 ) );

		// The fields come back EXACTLY as passed in — our control is never added.
		$this->assertSame( [ 'existing' => [ 'label' => 'x' ] ], $fields );
		$this->assertArrayNotHasKey( 'ogwm_enabled', $fields );
	}

	// -----------------------------------------------------------------
	// No-JS save persists the flag + seeds the queue, composites nothing.
	// -----------------------------------------------------------------

	public function testSaveFlagsAndSeedsQueuedStatus(): void {
		$this->images[12] = true;

		$post = MediaField::save(
			[ 'ID' => 12 ],
			[ 'ogwm_enabled' => '1' ]
		);

		$this->assertSame( '1', $this->meta[12][ Meta::ENABLED ] );
		$this->assertSame( Meta::STATUS_QUEUED, $this->meta[12][ Meta::STATUS ] );
		// The filter returns $post unchanged (consumed for its side effect only).
		$this->assertSame( [ 'ID' => 12 ], $post );
	}

	public function testSaveUntickedBoxRemovesFlag(): void {
		$this->images[12]            = true;
		$this->meta[12][ Meta::ENABLED ] = '1';

		// Box present but unchecked → the field key exists but is empty/absent value.
		MediaField::save( [ 'ID' => 12 ], [ 'ogwm_enabled' => '' ] );

		$this->assertArrayNotHasKey( Meta::ENABLED, $this->meta[12] ?? [] );
	}

	public function testSaveDoesNothingWhenFieldAbsent(): void {
		$this->images[12]            = true;
		$this->meta[12][ Meta::ENABLED ] = '1';

		// A save from a screen that never rendered our field must not clear the flag.
		MediaField::save( [ 'ID' => 12 ], [ 'post_title' => 'unrelated' ] );

		$this->assertSame( '1', $this->meta[12][ Meta::ENABLED ] );
	}

	public function testSaveIsNoopForNonImage(): void {
		$this->images[13] = false;

		MediaField::save( [ 'ID' => 13 ], [ 'ogwm_enabled' => '1' ] );

		$this->assertArrayNotHasKey( 13, $this->meta );
	}

	public function testSaveDoesNotStompWatermarkedStatus(): void {
		$this->images[14]            = true;
		$this->meta[14][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		MediaField::save( [ 'ID' => 14 ], [ 'ogwm_enabled' => '1' ] );

		// Flag is (re)set, but a committed 'watermarked' state is preserved.
		$this->assertSame( '1', $this->meta[14][ Meta::ENABLED ] );
		$this->assertSame( Meta::STATUS_WATERMARKED, $this->meta[14][ Meta::STATUS ] );
	}
}
