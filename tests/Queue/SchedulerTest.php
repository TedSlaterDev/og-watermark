<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Queue;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Pipeline\Outcome;
use OrchardGrove\OgWatermark\Queue\Scheduler;
use OrchardGrove\OgWatermark\Support\Meta;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Unit tests for the single-job scheduler + the async job handler.
 *
 * The two scheduling backends and the handler's hardening are the crux:
 *
 *  - enqueue() DEDUPS: a pending id schedules nothing and returns false.
 *  - the backend choice is asserted directly — as_enqueue_async_action() when
 *    Action Scheduler is present, wp_schedule_single_event() when it is not.
 *  - runJob() drives the injected processor factory with the right args, marks
 *    the bulk run's counters, AND swallows a thrown Throwable while recording a
 *    failed status + last-error (a single bad attachment must never fatal the
 *    worker).
 *
 * Action Scheduler is NOT installed in this environment, so the cron-fallback
 * path is exercised for real; the AS path is exercised by stubbing the AS
 * functions (which also makes function_exists() report them present).
 */
final class SchedulerTest extends TestCase {

	/** @var array<string,mixed> In-memory options table. */
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
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( $id, $key, $single = false ) => $this->meta[ (int) $id ][ $key ] ?? ''
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ (int) $id ][ $key ] );
				return true;
			}
		);

		Scheduler::$processorFactory      = null;
		Scheduler::$forceActionScheduler = null;
	}

	protected function tearDown(): void {
		Scheduler::$processorFactory      = null;
		Scheduler::$forceActionScheduler = null;
		$this->options = [];
		$this->meta    = [];
		parent::tearDown();
	}

	// =====================================================================
	// register() wires the handler.
	// =====================================================================

	public function testRegisterAddsTheProcessOneAction(): void {
		$captured = [];
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $cb, $priority = 10, $args = 1 ) use ( &$captured ): void {
				$captured = [ $hook, $cb, $priority, $args ];
			}
		);

		Scheduler::register();

		$this->assertSame( [ Scheduler::HOOK, [ Scheduler::class, 'runJob' ], 10, 3 ], $captured );
	}

	// =====================================================================
	// enqueue() — Action Scheduler backend.
	// =====================================================================

	public function testEnqueueUsesActionSchedulerWhenAvailable(): void {
		// Pin the AS backend. (Action Scheduler is genuinely absent in this
		// environment; the override deterministically selects its path, and stubbing
		// the as_* functions also makes function_exists() report them present.)
		Scheduler::$forceActionScheduler = true;
		// One async job carrying [ id, context, runToken ]. The third arg is the
		// originating bulk run's lock token (here a non-bulk default of '').
		// Returns a truthy action id on success — enqueue() now checks this and rolls
		// the pending marker back on a falsy (0) return, so the stub must return truthy.
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Scheduler::HOOK, [ 77, 'bulk', '' ], 'og-watermark' )
			->andReturn( 123 );
		// The cron one-shot must NOT be used when AS is present.
		Functions\expect( 'wp_schedule_single_event' )->never();

		$this->assertTrue( Scheduler::enqueue( 77, 'bulk' ) );

		// The per-id marker was seeded even on the AS path — it is the dedup mechanism
		// on BOTH backends (as_has_scheduled_action is exact-args and can't match by
		// id alone, so it is not relied on for dedup).
		$this->assertSame( '1', $this->meta[77][ Meta::PENDING ] ?? null );
		$this->assertTrue( Scheduler::isPending( 77 ) );
	}

	public function testEnqueueThreadsTheRunTokenThroughToTheAsJob(): void {
		Scheduler::$forceActionScheduler = true;
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Scheduler::HOOK, [ 77, 'bulk', 'run-tok-9' ], 'og-watermark' )
			->andReturn( 123 );

		$this->assertTrue( Scheduler::enqueue( 77, 'bulk', 'run-tok-9' ) );
	}

	public function testEnqueueRollsBackPendingMarkerWhenSchedulingIsRefused(): void {
		// Action Scheduler degraded → 0 return. enqueue() must NOT leave the id stranded
		// "pending"; it rolls the marker back and reports false so a later enqueue for
		// the id is admitted (otherwise the image would silently never (re)watermark).
		Scheduler::$forceActionScheduler = true;
		Functions\when( 'as_enqueue_async_action' )->justReturn( 0 );

		$this->assertFalse( Scheduler::enqueue( 77, 'bulk' ) );
		$this->assertFalse( Scheduler::isPending( 77 ), 'a refused schedule must not strand the id as pending' );
		$this->assertArrayNotHasKey( Meta::PENDING, $this->meta[77] ?? [] );

		// Cron path: a pre_schedule_event short-circuit returns false → same rollback.
		Scheduler::$forceActionScheduler = false;
		Functions\when( 'wp_schedule_single_event' )->justReturn( false );

		$this->assertFalse( Scheduler::enqueue( 88, 'bulk' ) );
		$this->assertFalse( Scheduler::isPending( 88 ) );
	}

	public function testEnqueueDedupsByIdAcrossContextsOnActionSchedulerPath(): void {
		Scheduler::$forceActionScheduler = true;
		// First enqueue (context "bulk") schedules + marks the id pending.
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
		$this->assertTrue( Scheduler::enqueue( 77, 'bulk' ) );

		// A second enqueue for the SAME id but a DIFFERENT context must be refused
		// purely by the id-set — as_has_scheduled_action is exact-args (a partial args
		// array is NOT a wildcard), so it can't dedup across contexts; the id-set can.
		Functions\expect( 'as_enqueue_async_action' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();

		$this->assertFalse(
			Scheduler::enqueue( 77, 'regenerate' ),
			'an already-pending id must not enqueue a second job on the AS path'
		);
	}

	// =====================================================================
	// enqueue() — wp-cron fallback backend (AS absent here for real).
	// =====================================================================

	public function testEnqueueUsesCronSingleEventWhenActionSchedulerAbsent(): void {
		Scheduler::$forceActionScheduler = false;
		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->with(
				\Mockery::on( static fn( $ts ) => is_int( $ts ) && $ts > time() ),
				Scheduler::HOOK,
				[ 88, 'bulk', '' ]
			);

		$this->assertTrue( Scheduler::enqueue( 88, 'bulk' ) );

		// The cron-path dedup marker was seeded so a same-id/other-context enqueue
		// can be refused.
		$this->assertSame( '1', $this->meta[88][ Meta::PENDING ] ?? null );
		$this->assertTrue( Scheduler::isPending( 88 ) );
	}

	public function testEnqueueDedupsViaPendingSetAcrossContextsOnCronPath(): void {
		Scheduler::$forceActionScheduler = false;
		// First enqueue (context "bulk") schedules + marks pending.
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		$this->assertTrue( Scheduler::enqueue( 88, 'bulk' ) );

		// A second enqueue for the SAME id but a DIFFERENT context must be refused —
		// wp_next_scheduled() can't see this (exact-args), but the pending id-set can.
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->assertFalse(
			Scheduler::enqueue( 88, 'regenerate' ),
			'the ogwm_pending id-set must dedup across contexts on the cron path'
		);
	}

	public function testIsPendingFalseForUnknownIdOnCronPath(): void {
		Scheduler::$forceActionScheduler = false;
		$this->assertFalse( Scheduler::isPending( 999 ) );
	}

	public function testIsPendingFalseWhenNoMarkerMeta(): void {
		Scheduler::$forceActionScheduler = false;
		// No _ogwm_pending meta for this id → not pending.
		$this->assertFalse( Scheduler::isPending( 5 ) );
	}

	// =====================================================================
	// runJob() — drives the injected processor with the right args.
	// =====================================================================

	public function testRunJobInvokesProcessorFactoryWithIdAndContext(): void {
		$seen = [];
		$fake = new class( $seen ) {
			/** @var array<string,mixed> */
			public array $seen;
			public function __construct( array &$seen ) {
				$this->seen = &$seen;
			}
			public function process( int $id, string $context ): Outcome {
				$this->seen['id']      = $id;
				$this->seen['context'] = $context;
				return Outcome::watermarked( [ 'full' => time() ] );
			}
		};

		Scheduler::$processorFactory = static fn() => $fake;

		Scheduler::runJob( 123, 'bulk' );

		$this->assertSame( 123, $fake->seen['id'], 'runJob must pass the attachment id through' );
		$this->assertSame( 'bulk', $fake->seen['context'], 'runJob must pass the context through' );
	}

	public function testRunJobClearsPendingMarkerOnSuccess(): void {
		// Seed a per-id pending marker (as enqueue() would have).
		$this->meta[123][ Meta::PENDING ] = '1';

		Scheduler::$processorFactory = static fn() => new class() {
			public function process( int $id, string $context ): Outcome {
				return Outcome::watermarked( [] );
			}
		};

		Scheduler::runJob( 123, 'bulk' );

		$this->assertArrayNotHasKey( Meta::PENDING, $this->meta[123] ?? [], 'a finished job must clear its dedup marker' );
		$this->assertFalse( Scheduler::isPending( 123 ) );
	}

	// =====================================================================
	// runJob() — a thrown Throwable is swallowed + recorded as failed.
	// =====================================================================

	public function testRunJobSwallowsThrowableAndRecordsFailure(): void {
		// Seed a pending marker so we can prove it's cleared in finally even on a throw.
		$this->meta[200][ Meta::PENDING ] = '1';

		Scheduler::$processorFactory = static fn() => new class() {
			public function process( int $id, string $context ): Outcome {
				throw new \RuntimeException( 'engine exploded mid-job' );
			}
		};

		// The crux: a throwing processor must NOT propagate out of runJob — a single
		// bad attachment can never be allowed to fatal cron / Action Scheduler. Make
		// non-propagation an EXPLICIT assertion rather than relying on PHPUnit erroring
		// if it escaped (so a future re-throw-after-recording refactor is caught).
		try {
			Scheduler::runJob( 200, 'bulk' );
		} catch ( \Throwable $e ) {
			$this->fail( 'runJob must swallow the processor throwable: ' . $e->getMessage() );
		}

		// The failure was recorded onto the attachment for the Media-column UI — as a
		// FIXED reason code, NOT the raw throwable message (which routinely embeds
		// absolute server paths and is shown in the media-list title to any user who
		// can see the row).
		$this->assertSame( Meta::STATUS_FAILED, $this->meta[200][ Meta::STATUS ] );
		$this->assertSame( 'processing-exception', $this->meta[200][ Meta::LAST_ERROR ] );
		$this->assertStringNotContainsString( 'engine exploded', $this->meta[200][ Meta::LAST_ERROR ], 'the raw exception message must not be persisted' );

		// The dedup marker was still cleared (finally), so a re-enqueue is admitted.
		$this->assertArrayNotHasKey( Meta::PENDING, $this->meta[200] ?? [] );
	}

	public function testRunJobSwallowsThrowableFromTheFactoryItself(): void {
		// Even a factory that throws before producing a processor must not escape.
		Scheduler::$processorFactory = static function () {
			throw new \LogicException( 'factory blew up' );
		};

		Scheduler::runJob( 201, 'bulk' );

		$this->assertSame( Meta::STATUS_FAILED, $this->meta[201][ Meta::STATUS ] );
		$this->assertSame( 'processing-exception', $this->meta[201][ Meta::LAST_ERROR ] );
	}

	public function testRunJobOnSuccessDoesNotRecordAFailure(): void {
		Scheduler::$processorFactory = static fn() => new class() {
			public function process( int $id, string $context ): Outcome {
				return Outcome::watermarked( [ 'full' => 1 ] );
			}
		};

		Scheduler::runJob( 300, 'bulk' );

		$this->assertArrayNotHasKey(
			Meta::STATUS,
			$this->meta[300] ?? [],
			'a successful job must not write a failed status (the Processor owns success meta)'
		);
	}

	// =====================================================================
	// runJob() → BulkRunner::recordJobResult() counter seam (AS-path progress).
	// =====================================================================

	public function testRunJobAdvancesTheActiveBulkRunDoneCounter(): void {
		$this->seedActiveBulkRun( 'run-tok-A', outstanding: 2 );

		Scheduler::$processorFactory = static fn() => new class() {
			public function process( int $id, string $context ): Outcome {
				return Outcome::watermarked( [ 'full' => 1 ] );
			}
		};

		// A bulk job carrying the active run's token must advance done by 1 (and
		// decrement outstanding) through the recordJobResult seam.
		Scheduler::runJob( 401, 'bulk', 'run-tok-A' );

		$state = $this->options['ogwm_bulk_state'];
		$this->assertSame( 1, $state['done'], 'a successful bulk job must increment done' );
		$this->assertSame( 0, $state['failed'] );
		$this->assertSame( 1, $state['outstanding'], 'a completion must decrement the outstanding gate' );
		$this->assertTrue( $state['running'], 'one job remains outstanding → run stays open' );
	}

	public function testRunJobAdvancesTheActiveBulkRunFailedCounter(): void {
		$this->seedActiveBulkRun( 'run-tok-B', outstanding: 2 );

		Scheduler::$processorFactory = static fn() => new class() {
			public function process( int $id, string $context ): Outcome {
				throw new \RuntimeException( 'kaboom' );
			}
		};

		Scheduler::runJob( 402, 'bulk', 'run-tok-B' );

		$state = $this->options['ogwm_bulk_state'];
		$this->assertSame( 0, $state['done'] );
		$this->assertSame( 1, $state['failed'], 'a failed bulk job must increment failed' );
		$this->assertSame( 1, $state['outstanding'] );
	}

	public function testRunJobWithMismatchedTokenDoesNotTouchBulkCounters(): void {
		$this->seedActiveBulkRun( 'run-tok-C', outstanding: 1 );

		Scheduler::$processorFactory = static fn() => new class() {
			public function process( int $id, string $context ): Outcome {
				return Outcome::watermarked( [] );
			}
		};

		// A job tagged with a DIFFERENT run token (e.g. a straggler from a prior run)
		// must NOT advance the active run's counters.
		Scheduler::runJob( 403, 'bulk', 'some-other-run' );

		$state = $this->options['ogwm_bulk_state'];
		$this->assertSame( 0, $state['done'] );
		$this->assertSame( 0, $state['failed'] );
		$this->assertSame( 1, $state['outstanding'], 'a foreign-token job must not touch outstanding' );
	}

	/**
	 * Seed a live bulk run + the cache-backed Lock the recordJobResult callback
	 * heartbeats, so the Scheduler→BulkRunner counter seam can be exercised end to
	 * end. outstanding is kept > 1 so the callback does not finish/release the run.
	 */
	private function seedActiveBulkRun( string $token, int $outstanding ): void {
		// A persistent object cache backs the Lock; pre-seed the bulk lock with this
		// run's token so the heartbeat refresh inside recordJobResult succeeds.
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		$cache = [ 'ogwm-lock:ogwm_bulk_lock' => $token ];
		Functions\when( 'wp_cache_get' )->alias(
			static fn( $key, $group = '' ) => array_key_exists( $group . ':' . $key, $cache ) ? $cache[ $group . ':' . $key ] : false
		);
		Functions\when( 'wp_cache_set' )->alias(
			static function ( $key, $value, $group = '', $ttl = 0 ) use ( &$cache ) {
				$cache[ $group . ':' . $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			static function ( $key, $group = '' ) use ( &$cache ) {
				unset( $cache[ $group . ':' . $key ] );
				return true;
			}
		);

		$this->options['ogwm_bulk_state'] = [
			'running'     => true,
			'scope'       => 'flagged',
			'total'       => 10,
			'done'        => 0,
			'failed'      => 0,
			'outstanding' => $outstanding,
			'as'          => true,
			'started_gmt' => '2026-06-29T00:00:00+00:00',
			'lock'        => $token,
		];
		// No queue → hasWork() is false, but outstanding > 0 keeps the run open.
	}
}
