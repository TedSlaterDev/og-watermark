<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Integration;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Integration\MetadataListener;
use OrchardGrove\OgWatermark\Queue\Scheduler;
use OrchardGrove\OgWatermark\Support\Meta;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Unit tests for the wp_generate_attachment_metadata coexistence listener.
 *
 * The crux is the async-only + isProcessing-bail discipline:
 *
 *  - register() hooks the filter at 9999/2 (after core + 3rd-party regenerate);
 *  - onGenerate ALWAYS returns the metadata unchanged (we never stamp inline);
 *  - it schedules exactly ONE deduped 'regenerate' job for a FLAGGED attachment
 *    when our Processor is NOT mid-(re)generate;
 *  - it schedules NOTHING while Processor::isProcessing() is true (no recursion
 *    into our own regenerate);
 *  - it schedules NOTHING for a non-flagged attachment (a brand-new upload does
 *    nothing — it is not yet opted in).
 *
 * The Scheduler is a real static class; its enqueue() is exercised through its
 * own backend (the cron fallback here, since Action Scheduler is absent), and the
 * scheduling side-effect is asserted via the dedup id-set it writes into the
 * ogwm_pending option. Processor::isProcessing() is driven by toggling the real
 * Processor through a public seam where available, else asserted via the
 * observable scheduling outcome.
 */
final class MetadataListenerTest extends TestCase {

	/** @var array<string,mixed> In-memory options table (holds ogwm_pending). */
	private array $options = [];

	/** @var array<int,array<string,mixed>> In-memory post meta, keyed by id. */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( $id, $key, $single = false ) => $this->meta[ (int) $id ][ $key ] ?? ''
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

		// Pin the wp-cron fallback so enqueue() is deterministic (Action Scheduler is
		// genuinely absent in this environment).
		Scheduler::$forceActionScheduler = false;

		// Reset the per-process burst counter (a static that would otherwise leak
		// across tests and skew the mass-regenerate cap assertions).
		( new \ReflectionClass( MetadataListener::class ) )->getProperty( 'burst' )->setValue( null, 0 );
	}

	protected function tearDown(): void {
		Scheduler::$forceActionScheduler = null;
		$this->options = [];
		$this->meta    = [];
		parent::tearDown();
	}

	/** Mark an attachment as opted-in (the string '1', as the metabox writes it). */
	private function flag( int $id ): void {
		$this->meta[ $id ][ Meta::ENABLED ] = '1';
	}

	// =====================================================================
	// register() wires the filter at the documented priority/args.
	// =====================================================================

	public function testRegisterHooksGenerateMetadataAt9999(): void {
		$captured = [];
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $cb, $priority = 10, $args = 1 ) use ( &$captured ): void {
				$captured = [ $hook, $cb, $priority, $args ];
			}
		);

		MetadataListener::register();

		$this->assertSame(
			[ 'wp_generate_attachment_metadata', [ MetadataListener::class, 'onGenerate' ], 9999, 3 ],
			$captured,
			'the listener must hook wp_generate_attachment_metadata at priority 9999 with 3 args (incl. $context)'
		);
	}

	// =====================================================================
	// Flagged + NOT processing → schedule exactly one 'regenerate' job,
	// metadata returned unchanged.
	// =====================================================================

	public function testSchedulesOneRegenerateJobForFlaggedAttachmentWhenNotProcessing(): void {
		$this->flag( 77 );

		$cronCalls = 0;
		$cronArgs  = null;
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $ts, $hook, $args ) use ( &$cronCalls, &$cronArgs ) {
				++$cronCalls;
				$cronArgs = $args;
				return true;
			}
		);

		$meta = [ 'file' => '2026/06/photo.jpg', 'sizes' => [ 'medium' => [] ] ];

		$returned = MetadataListener::onGenerate( $meta, 77, 'update' );

		// Exactly one async job was scheduled (the cron one-shot fired once)...
		$this->assertSame( 1, $cronCalls, 'a flagged attachment must schedule exactly one reprocess job' );

		// ...in the 'regenerate' context (a wrong default 'bulk' would route the job
		// down the wrong pipeline; the dedup id-set cannot prove this on its own).
		$this->assertSame(
			[ 77, 'regenerate', '' ],
			$cronArgs,
			'the reprocess must be enqueued in the regenerate context with no bulk run token'
		);
		// ...and it is recorded in the per-id dedup marker for this attachment.
		$this->assertSame( '1', $this->meta[77][ Meta::PENDING ] ?? null );
		$this->assertTrue( Scheduler::isPending( 77 ) );

		// The metadata is returned verbatim — we NEVER stamp/mutate inline here.
		$this->assertSame( $meta, $returned, 'onGenerate must return the metadata unchanged' );
	}

	public function testEnqueuesExactlyOnceEvenWhenFiredTwiceForSameAttachment(): void {
		$this->flag( 77 );

		$cronCalls = 0;
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $ts, $hook, $args ) use ( &$cronCalls ) {
				++$cronCalls;
				return true;
			}
		);

		$meta = [ 'file' => '2026/06/photo.jpg' ];

		// A foreign regenerate can fire the filter more than once in a request; the
		// Scheduler's id-set must collapse it to a single job.
		MetadataListener::onGenerate( $meta, 77 );
		MetadataListener::onGenerate( $meta, 77 );

		$this->assertSame( 1, $cronCalls, 'a second fire for the same id must be deduped to zero new jobs' );
	}

	// =====================================================================
	// Processor::isProcessing() true → schedule NOTHING (no recursion),
	// metadata still unchanged.
	// =====================================================================

	public function testSchedulesNothingWhileOurProcessorIsRegenerating(): void {
		$this->flag( 88 );

		// The cron one-shot must NEVER be reached while we are mid-(re)generate.
		Functions\expect( 'wp_schedule_single_event' )->never();

		$meta = [ 'file' => '2026/06/photo.jpg' ];

		// Drive the real Processor's reentrancy flag to true for the duration of the
		// call, then guarantee it is reset. The flag is what isProcessing() reads.
		$returned = self::withProcessingFlag(
			true,
			static fn() => MetadataListener::onGenerate( $meta, 88 )
		);

		// Nothing was marked pending — the listener bailed at the top.
		$this->assertFalse( Scheduler::isPending( 88 ), 'no job may be scheduled during our own regenerate' );
		$this->assertArrayNotHasKey( 'ogwm_pending', $this->options );

		// Metadata still returned verbatim.
		$this->assertSame( $meta, $returned, 'onGenerate must return the metadata unchanged even when bailing' );
	}

	// =====================================================================
	// Non-flagged attachment → schedule NOTHING (a brand-new upload is inert).
	// =====================================================================

	public function testSchedulesNothingForNonFlaggedAttachment(): void {
		// No flag() call — id 99 is a brand-new, not-yet-opted-in upload.
		Functions\expect( 'wp_schedule_single_event' )->never();

		$meta = [ 'file' => '2026/06/fresh-upload.jpg' ];

		$returned = MetadataListener::onGenerate( $meta, 99 );

		$this->assertFalse( Scheduler::isPending( 99 ), 'a non-flagged attachment must never enqueue' );
		$this->assertArrayNotHasKey( 'ogwm_pending', $this->options );
		$this->assertSame( $meta, $returned, 'onGenerate must return the metadata unchanged' );
	}

	public function testTreatsEmptyEnabledMetaAsNotFlagged(): void {
		// ENABLED meta absent → get_post_meta returns '' → not flagged.
		Functions\expect( 'wp_schedule_single_event' )->never();

		MetadataListener::onGenerate( [ 'file' => 'x.jpg' ], 100 );

		$this->assertFalse( Scheduler::isPending( 100 ) );
	}

	// =====================================================================
	// Defensive: a non-array $meta from a buggy upstream filter is passed back
	// verbatim (no TypeError at the hook boundary), nothing scheduled.
	// =====================================================================

	public function testReturnsNonArrayMetaUnchangedAndSchedulesNothing(): void {
		// Running at 9999, an earlier (buggy/hostile) filter could return a non-array.
		// The loose `mixed` signature must pass it back verbatim rather than fatal.
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->flag( 101 );

		$returned = MetadataListener::onGenerate( false, 101, 'update' );

		$this->assertFalse( $returned, 'a non-array meta must be returned verbatim' );
		$this->assertFalse( Scheduler::isPending( 101 ), 'no job may be scheduled for a non-array meta payload' );
	}

	public function testMassRegenerateBurstDefersOverflowToAReprocessMarker(): void {
		$cap = ( new \ReflectionClass( MetadataListener::class ) )->getConstant( 'BURST_CAP' );
		$this->assertIsInt( $cap );

		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$meta = [ 'file' => 'x.jpg', 'sizes' => [] ];

		// The first $cap flagged regenerates enqueue immediately (per-id pending marker).
		for ( $id = 1; $id <= $cap; $id++ ) {
			$this->flag( $id );
			MetadataListener::onGenerate( $meta, $id, 'update' );
			$this->assertTrue( Scheduler::isPending( $id ), "id $id must enqueue immediately under the cap" );
		}

		// The NEXT flagged regenerate crosses the cap → DEFERRED: marked for the drain,
		// NOT enqueued immediately (so a mass regenerate cannot flood the queue).
		$over = $cap + 1;
		$this->flag( $over );
		MetadataListener::onGenerate( $meta, $over, 'update' );

		$this->assertSame( '1', $this->meta[ $over ][ Meta::REPROCESS ] ?? null, 'the overflow attachment is marked for the drain' );
		$this->assertFalse( Scheduler::isPending( $over ), 'the overflow attachment is NOT enqueued immediately' );
	}

	public function testDrainEnqueuesReprocessMarkedAttachmentsAndClearsTheirMarker(): void {
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$dirty = [ 11, 22, 33 ];
		foreach ( $dirty as $id ) {
			$this->meta[ $id ][ Meta::REPROCESS ] = '1';
		}
		Functions\when( 'get_posts' )->justReturn( $dirty );

		MetadataListener::drain();

		foreach ( $dirty as $id ) {
			$this->assertTrue( Scheduler::isPending( $id ), "id $id enqueued by the drain" );
			$this->assertArrayNotHasKey( Meta::REPROCESS, $this->meta[ $id ] ?? [], "id $id's reprocess marker cleared" );
		}
	}

	public function testDrainKeepsTheMarkerWhenSchedulingIsRefused(): void {
		// The reprocess could not be scheduled AND no job is pending (a cron-replacement
		// short-circuit): the drain must LEAVE the marker so a later pass retries it,
		// rather than dropping it and stranding the image un-watermarked.
		Functions\when( 'wp_schedule_single_event' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$this->meta[44][ Meta::REPROCESS ] = '1';
		Functions\when( 'get_posts' )->justReturn( [ 44 ] );

		MetadataListener::drain();

		$this->assertSame( '1', $this->meta[44][ Meta::REPROCESS ] ?? null, 'marker kept when the reprocess could not be scheduled' );
		$this->assertFalse( Scheduler::isPending( 44 ), 'no phantom pending marker is left either' );
	}

	// =====================================================================
	// Helpers.
	// =====================================================================

	/**
	 * Run $callback with Processor::$processing forced to $state, restoring the
	 * private static flag afterwards via reflection. The Processor exposes only the
	 * read seam isProcessing(); the private flag is the single source of truth the
	 * listener reads, so we flip it directly for the duration of the call.
	 *
	 * @template T
	 * @param bool          $state    Value to force the reentrancy flag to.
	 * @param callable():T $callback The code to run while the flag is forced.
	 * @return T
	 */
	private static function withProcessingFlag( bool $state, callable $callback ): mixed {
		$ref  = new \ReflectionProperty( \OrchardGrove\OgWatermark\Pipeline\Processor::class, 'processing' );
		$prev = $ref->getValue();
		$ref->setValue( null, $state );
		try {
			return $callback();
		} finally {
			$ref->setValue( null, $prev );
		}
	}
}
