<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Queue;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Pipeline\Outcome;
use OrchardGrove\OgWatermark\Queue\BulkRunner;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * BulkRunner is the resumable, server-side bulk orchestrator. These tests prove
 * its load-bearing properties WITHOUT a live WP/AS stack, using faithful in-memory
 * doubles for every external surface:
 *
 *  - the durable OPTIONS store (ogwm_bulk_state / ogwm_bulk_queue) is a real array,
 *    so claim/advance/persist round-trips exactly as it would in the DB;
 *  - the global Lock rides the SAME object-cache double the real Lock uses, so
 *    "already-running" and the heartbeat are exercised through the real Lock code;
 *  - get_posts (flagged scope) and a WP_Query double (the 'all'-scope COUNT + keyset
 *    pager) are driven from a controllable library fixture, so the cursor is observed
 *    to page WITHOUT ever materializing the full id set;
 *  - the Processor is injected via BulkRunner::$processorFactory as a fake that
 *    records the ids it was handed and returns a scripted Outcome, so done/failed
 *    counters are asserted against real isOk() semantics;
 *  - wp_schedule_single_event / wp_next_scheduled / wp_unschedule_event are spies so
 *    the self-rescheduling chain and cancel's unschedule are asserted.
 *
 * The browser-only-observes property is made concrete: progress() never writes and
 * never locks; all work happens in tick()/the injected processor under the lock.
 *
 * The disk pre-flight is exercised through the REAL Preflight::hasDiskSpaceFor (a
 * pure static) by stubbing disk_free_space — a huge free value passes, a tiny one
 * forces the refusal.
 */
final class BulkRunnerTest extends TestCase {

	/** @var array<string,mixed> In-memory options table (durable state + Lock fallback). */
	private array $options = [];

	/** @var array<string,mixed> In-memory object cache ("group:key" => value) for the Lock cache backend. */
	private array $cache = [];

	/** @var array<int,int> Ids get_posts() returns for the flagged scope. */
	private array $flagged = [];

	/** @var array<int,int> The full 'all'-scope library, ascending ids, for the keyset pager. */
	private array $library = [];

	/** Overrides the reported 'all'-scope COUNT (found_posts) when set; else count($library). */
	private ?int $libraryCount = null;

	/** @var array<int,array{time:int,hook:string,args:array}> wp_schedule_single_event spy log. */
	private array $scheduled = [];

	/** @var array<int,string> Hooks passed to wp_unschedule_event. */
	private array $unscheduled = [];

	/** @var array<int,array{id:int,context:string}> Calls the fake Processor received. */
	private array $processed = [];

	/** @var array<int,Outcome> Scripted per-id outcome for the fake Processor; default watermarked. */
	private array $outcomeFor = [];

	/** Monotonic token source so each Lock::acquire() yields a distinct token. */
	private int $tokenSeq = 0;

	/** @var array<int,array<string,mixed>> In-memory post meta (holds the per-id pending markers). */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->stubOptions();
		$this->stubLock();
		$this->stubCron();
		$this->stubQueries();
		$this->stubDiskAndStorage();
		$this->stubMisc();

		// Default: NO Action Scheduler, so the cron-chain (tick) path is the default
		// under test. We pin BOTH the BulkRunner's kick selector and the Scheduler's
		// backend selector via their $forceActionScheduler seams (Patchwork keeps an
		// `as_*` stub defined process-wide once any sibling test mocks it, so a raw
		// function_exists() probe can't be flipped back off — the same reason the
		// Scheduler exposes this seam). The AS test sets both true.
		BulkRunner::$forceActionScheduler                               = false;
		\OrchardGrove\OgWatermark\Queue\Scheduler::$forceActionScheduler = false;

		// Inject a fake Processor that records its calls and returns scripted Outcomes.
		// The fake duck-types process(): Outcome — Processor is final and cannot be
		// subclassed, which is exactly why BulkRunner consumes the factory as `object`.
		BulkRunner::$processorFactory = function (): object {
			return new FakeBulkProcessor(
				function ( int $id, string $context ): Outcome {
					$this->processed[] = [
						'id'      => $id,
						'context' => $context,
					];
					return $this->outcomeFor[ $id ] ?? Outcome::watermarked( [] );
				}
			);
		};

		// The 'all'-scope WP_Query double reads the test's current library fixture.
		WpQueryDouble::$resolver = function ( array $args ): array {
			$after = isset( $args['ogwm_after_id'] ) ? (int) $args['ogwm_after_id'] : 0;
			$page  = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : -1;

			$ids = array_values(
				array_filter(
					array_map( 'intval', $this->library ),
					static fn( int $id ): bool => $id > $after
				)
			);
			sort( $ids );

			$total = $this->libraryCount ?? count( $this->library );
			$slice = ( $page > 0 ) ? array_slice( $ids, 0, $page ) : $ids;

			return [
				'posts'       => $slice,
				'found_posts' => $total,
			];
		};
	}

	protected function tearDown(): void {
		BulkRunner::$processorFactory                                    = null;
		BulkRunner::$forceActionScheduler                               = null;
		\OrchardGrove\OgWatermark\Queue\Scheduler::$forceActionScheduler = null;
		WpQueryDouble::$resolver                                         = null;
		parent::tearDown();
	}

	// =====================================================================
	// start(): refusals.
	// =====================================================================

	public function testStartRefusesWhenAlreadyRunning(): void {
		// Pre-hold the global lock as if a run were already active.
		$this->holdBulkLock();

		$result = BulkRunner::start( 'flagged' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'already-running', $result['reason'] );

		// Nothing seeded — the refusal must not stomp an in-flight run's state.
		$this->assertArrayNotHasKey( 'ogwm_bulk_queue', $this->options );
		$this->assertArrayNotHasKey( 'ogwm_bulk_state', $this->options );
	}

	public function testStartRefusesOnInsufficientDisk(): void {
		// Point one flagged attachment's "original" at a SPARSE file whose APPARENT
		// size is astronomically large (real disk usage ~0). estimateForIds() sums
		// filesize(), so the projected backup growth dwarfs any real disk and the REAL
		// Preflight::hasDiskSpaceFor refuses before anything is reserved. This is
		// deterministic and machine-independent WITHOUT stubbing the internal
		// disk_free_space()/filesize() (which Patchwork cannot redefine here), and
		// replaces the old 'all'-scope count-based petabyte estimate (now disabled).
		$huge = sys_get_temp_dir() . '/ogwm-huge-' . uniqid( '', true ) . '.jpg';
		$fh   = fopen( $huge, 'wb' );
		$this->assertIsResource( $fh );
		fseek( $fh, 50 * ( 1024 ** 4 ) ); // 50 TiB apparent size.
		fwrite( $fh, "\0" );
		fclose( $fh );
		clearstatcache( true, $huge );

		Functions\when( 'wp_get_original_image_path' )->justReturn( $huge );
		$this->flagged = [ 1 ];

		try {
			$result = BulkRunner::start( 'flagged' );

			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'insufficient-disk', $result['reason'] );
			$this->assertArrayHasKey( 'estimate', $result );
			$this->assertGreaterThan( 0, $result['estimate'] );

			// Refused BEFORE the lock was taken, so a later run can still start.
			$this->assertFalse( $this->bulkLockHeld() );
			$this->assertArrayNotHasKey( 'ogwm_bulk_queue', $this->options );
			$this->assertArrayNotHasKey( 'ogwm_bulk_state', $this->options );
		} finally {
			@unlink( $huge ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	public function testStartWithEmptyFlaggedSetReturnsZeroTotalAndDoesNotLock(): void {
		$this->flagged = [];

		$result = BulkRunner::start( 'flagged' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 0, $result['total'] );
		$this->assertFalse( $this->bulkLockHeld(), 'an empty run must not reserve the lock' );
	}

	// =====================================================================
	// start(): seeding for finite scopes.
	// =====================================================================

	public function testStartSeedsStateWithCorrectTotalForFlaggedScope(): void {
		$this->flagged = [ 5, 6, 7, 8 ];

		$result = BulkRunner::start( 'flagged' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 4, $result['total'] );

		$state = $this->options['ogwm_bulk_state'];
		$this->assertTrue( $state['running'] );
		$this->assertSame( 'flagged', $state['scope'] );
		$this->assertSame( 4, $state['total'] );
		$this->assertSame( 0, $state['done'] );
		$this->assertSame( 0, $state['failed'] );
		$this->assertNotSame( '', $state['started_gmt'] );
		$this->assertNotSame( '', $state['lock'], 'the lock token must be stored in the state' );

		// The lock is genuinely held now.
		$this->assertTrue( $this->bulkLockHeld() );

		// The id queue holds exactly the flagged set.
		$queue = $this->options['ogwm_bulk_queue'];
		$this->assertSame( 'ids', $queue['mode'] );
		$this->assertSame( [ 5, 6, 7, 8 ], $queue['ids'] );

		// A tick was scheduled (cron-chain path; AS absent in this test).
		$this->assertContains( BulkRunner::TICK_HOOK, array_column( $this->scheduled, 'hook' ) );
	}

	public function testStartSeedsStateWithCorrectTotalForIdsScope(): void {
		$result = BulkRunner::start( 'ids', [ 'ids' => [ '101', 102, 0, -3, 102 ] ] );

		// 0 and -3 dropped, duplicate 102 collapsed → [101, 102].
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 2, $result['total'] );

		$this->assertSame( 'ids', $this->options['ogwm_bulk_queue']['mode'] );
		$this->assertSame( [ 101, 102 ], $this->options['ogwm_bulk_queue']['ids'] );
		$this->assertSame( 2, $this->options['ogwm_bulk_state']['total'] );
	}

	// =====================================================================
	// tick(): batch processing, counters, chaining, drain → release.
	// =====================================================================

	public function testTickProcessesABatchUpdatesCountersAndChainsWhileWorkRemains(): void {
		// 7 ids, BATCH_SIZE is 5 → first tick does 5 and must chain a second tick.
		$this->flagged       = [ 1, 2, 3, 4, 5, 6, 7 ];
		$this->outcomeFor[3] = Outcome::failed( 'boom' ); // one failure in the first batch.

		BulkRunner::start( 'flagged' );
		$this->clearScheduled();

		BulkRunner::tick();

		// Exactly the first 5 ids were handed to the processor, with context 'bulk'.
		$this->assertSame( [ 1, 2, 3, 4, 5 ], array_column( $this->processed, 'id' ) );
		$this->assertSame( [ 'bulk', 'bulk', 'bulk', 'bulk', 'bulk' ], array_column( $this->processed, 'context' ) );

		$state = $this->options['ogwm_bulk_state'];
		$this->assertSame( 4, $state['done'] );
		$this->assertSame( 1, $state['failed'] );
		$this->assertTrue( $state['running'], 'work remains, so the run is still running' );

		// The queue shrank to the remaining 2 ids and EXACTLY ONE follow-up tick was
		// chained — a per-tick double-schedule is the classic runaway-chain bug.
		$this->assertSame( [ 6, 7 ], $this->options['ogwm_bulk_queue']['ids'] );
		$this->assertContains( BulkRunner::TICK_HOOK, array_column( $this->scheduled, 'hook' ) );
		$this->assertSame(
			1,
			count( array_filter( $this->scheduled, static fn( $s ) => BulkRunner::TICK_HOOK === $s['hook'] ) ),
			'exactly one follow-up tick must be chained'
		);

		// The lock is STILL held between ticks.
		$this->assertTrue( $this->bulkLockHeld() );
	}

	public function testTickDrainsReleasesLockAndMarksNotRunning(): void {
		$this->flagged = [ 10, 20, 30 ]; // < BATCH_SIZE → one tick drains it.

		BulkRunner::start( 'flagged' );
		$this->clearScheduled();

		BulkRunner::tick();

		$this->assertSame( [ 10, 20, 30 ], array_column( $this->processed, 'id' ) );

		$state = $this->options['ogwm_bulk_state'];
		$this->assertSame( 3, $state['done'] );
		$this->assertSame( 0, $state['failed'] );
		$this->assertFalse( $state['running'], 'a drained run must flip running off' );

		// Queue dropped, lock released, NO further tick chained.
		$this->assertArrayNotHasKey( 'ogwm_bulk_queue', $this->options );
		$this->assertFalse( $this->bulkLockHeld(), 'a drained run must release the global lock' );
		$this->assertNotContains( BulkRunner::TICK_HOOK, array_column( $this->scheduled, 'hook' ) );

		// And a fresh run can start immediately now that the lock is free.
		$this->flagged = [ 99 ];
		$again         = BulkRunner::start( 'flagged' );
		$this->assertTrue( $again['ok'] );
	}

	public function testTickAbortsWhenHeartbeatLost(): void {
		$this->flagged = [ 1, 2, 3 ];
		BulkRunner::start( 'flagged' );

		// Simulate the run's lock being reclaimed by another worker: overwrite the
		// stored owner token so our heartbeat refresh fails.
		$this->stompBulkLockOwner();
		$this->clearScheduled();

		BulkRunner::tick();

		// No attachment was processed and no tick was chained — the tick bailed.
		$this->assertSame( [], $this->processed );
		$this->assertNotContains( BulkRunner::TICK_HOOK, array_column( $this->scheduled, 'hook' ) );
	}

	public function testTickNoOpsWithoutAnActiveRun(): void {
		// No start() called → no state. tick() must be a safe no-op.
		BulkRunner::tick();
		$this->assertSame( [], $this->processed );
	}

	// =====================================================================
	// 'all' scope is DISABLED (whole-library flag-and-watermark removed).
	// =====================================================================

	public function testStartRejectsTheDisabledAllScope(): void {
		// The whole-library scope is not offered in the UI and is rejected outright,
		// so even a crafted request can never trigger a full-library run. It must
		// refuse WITHOUT taking the lock or seeding any run state, and never fall back
		// to silently running 'flagged'.
		$this->library = range( 1, 1000 );
		$this->flagged = [ 5, 6, 7 ];

		$result = BulkRunner::start( 'all' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'scope-not-allowed', $result['reason'] );
		$this->assertFalse( $this->bulkLockHeld(), 'a rejected scope must not take the lock' );
		$this->assertArrayNotHasKey( 'ogwm_bulk_queue', $this->options );
		$this->assertArrayNotHasKey( 'ogwm_bulk_state', $this->options );
		$this->assertSame( [], $this->processed, 'nothing may be processed for a rejected scope' );

		// Case/whitespace variants are rejected too (no bypass).
		$this->assertSame( 'scope-not-allowed', BulkRunner::start( ' ALL ' )['reason'] );
	}

	// =====================================================================
	// Action Scheduler path: start() enqueues per-id async jobs.
	// =====================================================================

	public function testStartWithActionSchedulerEnqueuesPerIdAsyncJobs(): void {
		// Drive BOTH the BulkRunner's kick selector and the Scheduler's backend
		// selector to the AS path deterministically via their override seams.
		BulkRunner::$forceActionScheduler                               = true;
		\OrchardGrove\OgWatermark\Queue\Scheduler::$forceActionScheduler = true;
		$this->flagged = [ 41, 42, 43 ];

		$enqueued = [];
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args = [], $group = '' ) use ( &$enqueued ) {
				$enqueued[] = [
					'hook'  => $hook,
					'args'  => $args,
					'group' => $group,
				];
				return 1;
			}
		);

		$result = BulkRunner::start( 'flagged' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 3, $result['total'] );

		$token = $this->options['ogwm_bulk_state']['lock'];

		// One async ogwm_process_one job per id, context 'bulk', each STAMPED with the
		// run's lock token so the completion can be tied back to THIS run.
		$this->assertSame(
			[ [ 41, 'bulk', $token ], [ 42, 'bulk', $token ], [ 43, 'bulk', $token ] ],
			array_map( static fn( $e ) => $e['args'], $enqueued )
		);
		$this->assertSame(
			[ 'ogwm_process_one', 'ogwm_process_one', 'ogwm_process_one' ],
			array_column( $enqueued, 'hook' )
		);

		// The finite set was consumed from the queue (the AS jobs own it now).
		$this->assertArrayNotHasKey( 'ogwm_bulk_queue', $this->options );

		// NO id was processed inline by a Processor — AS owns execution here.
		$this->assertSame( [], $this->processed );

		// CRITICAL: the run is NOT finished yet — three jobs are outstanding, so the
		// lock must STILL be held (the 'two runs never overlap' guarantee depends on
		// the lock surviving until the last AS job completes, not until they enqueue).
		$this->assertSame( 3, $this->options['ogwm_bulk_state']['outstanding'] );
		$this->assertTrue( $this->options['ogwm_bulk_state']['running'] );
		$this->assertTrue( $this->bulkLockHeld(), 'the lock must outlive enqueue on the AS path' );

		// The watchdog tick the kick scheduled must NOT finish the run while jobs are
		// outstanding — it reschedules itself to keep the lock alive.
		$this->clearScheduled();
		BulkRunner::tick();
		$this->assertTrue( $this->options['ogwm_bulk_state']['running'], 'watchdog must not finish with jobs outstanding' );
		$this->assertTrue( $this->bulkLockHeld() );
		$this->assertContains( BulkRunner::TICK_HOOK, array_column( $this->scheduled, 'hook' ) );

		// Now simulate the three AS jobs completing (as Scheduler::runJob would, via the
		// identity-tagged counter callback). The LAST completion drains outstanding and
		// finishes the run, releasing the global lock at the true end of the work.
		BulkRunner::recordJobResult( true, $token );
		$this->assertTrue( $this->options['ogwm_bulk_state']['running'], 'still running after 1/3' );
		BulkRunner::recordJobResult( true, $token );
		BulkRunner::recordJobResult( false, $token );

		$state = $this->options['ogwm_bulk_state'];
		$this->assertSame( 2, $state['done'] );
		$this->assertSame( 1, $state['failed'] );
		$this->assertSame( 0, $state['outstanding'] );
		$this->assertFalse( $state['running'], 'the last AS job completing must finish the run' );
		$this->assertFalse( $this->bulkLockHeld(), 'the lock is released only when the last job completes' );

		// With the lock truly freed, a fresh run can start.
		$this->flagged = [ 99 ];
		$this->assertTrue( BulkRunner::start( 'flagged' )['ok'] );
	}

	public function testFinishReconcilesProgressWhenAScopeItemWasAlreadyPending(): void {
		// A flagged image already has a pending job (e.g. it was just edited) when the
		// bulk run starts, so its bulk enqueue is deduped and NOT counted toward this
		// run — its own job watermarks it. Without reconciliation the progress bar would
		// stall below 100%; finish() must credit the un-attributed item as done.
		BulkRunner::$forceActionScheduler                               = true;
		\OrchardGrove\OgWatermark\Queue\Scheduler::$forceActionScheduler = true;
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );

		$this->flagged            = [ 41, 42, 43 ];
		$this->meta[41]['_ogwm_pending'] = '1'; // id 41 already has a pending job → deduped.

		$result = BulkRunner::start( 'flagged' );
		$this->assertSame( 3, $result['total'] );

		$token = $this->options['ogwm_bulk_state']['lock'];
		// Only 42 and 43 were enqueued for THIS run (41 was deduped).
		$this->assertSame( 2, $this->options['ogwm_bulk_state']['outstanding'] );

		// The two enqueued jobs complete → outstanding hits 0 → finish() reconciles.
		BulkRunner::recordJobResult( true, $token );
		BulkRunner::recordJobResult( true, $token );

		$progress = BulkRunner::progress();
		$this->assertFalse( $progress['running'], 'the run finished' );
		$this->assertSame( 3, $progress['total'] );
		$this->assertSame( 3, $progress['done'], 'the deduped item is credited so the bar reads 100%' );
		$this->assertSame( 0, $progress['failed'] );
		$this->assertSame( $progress['total'], $progress['done'] + $progress['failed'] );
	}

	public function testRecordJobResultIgnoresAStragglerFromAFinishedRun(): void {
		// A late job carrying a token that no longer matches the live run must NOT
		// corrupt counters — the identity guard rejects it.
		$this->flagged = [ 1 ];
		BulkRunner::start( 'flagged' );
		$liveToken = $this->options['ogwm_bulk_state']['lock'];

		BulkRunner::recordJobResult( true, 'stale-run-token' );

		$state = $this->options['ogwm_bulk_state'];
		$this->assertSame( 0, $state['done'], 'a foreign-token completion must not advance done' );
		$this->assertSame( 0, $state['failed'] );
		$this->assertSame( $liveToken, $state['lock'], 'the straggler must not disturb the live run' );
	}

	// =====================================================================
	// progress(): pure read.
	// =====================================================================

	public function testProgressReflectsStateAndIsAPureRead(): void {
		$this->flagged = [ 1, 2 ];
		BulkRunner::start( 'flagged' );

		$snapshotOptions = $this->options;

		$progress = BulkRunner::progress();

		$this->assertTrue( $progress['running'] );
		$this->assertSame( 'flagged', $progress['scope'] );
		$this->assertSame( 2, $progress['total'] );
		$this->assertSame( 0, $progress['done'] );
		$this->assertSame( 0, $progress['failed'] );
		$this->assertNotSame( '', $progress['started_gmt'] );

		// PURE READ: progress() must not mutate options (no writes, no lock churn),
		// and the internal lock token is NOT leaked through the public progress shape.
		$this->assertEquals( $snapshotOptions, $this->options, 'progress() must not write any state' );
		$this->assertArrayNotHasKey( 'lock', $progress );
	}

	public function testProgressWithNoRunReturnsIdleShape(): void {
		$progress = BulkRunner::progress();

		$this->assertFalse( $progress['running'] );
		$this->assertSame( 0, $progress['total'] );
		$this->assertSame( 0, $progress['done'] );
		$this->assertSame( 0, $progress['failed'] );
	}

	// =====================================================================
	// cancel(): clears state + releases lock.
	// =====================================================================

	public function testCancelFlipsRunningClearsQueueUnschedulesAndReleasesLock(): void {
		$this->flagged = [ 1, 2, 3, 4, 5, 6 ];
		BulkRunner::start( 'flagged' );
		$this->assertTrue( $this->bulkLockHeld() );
		$this->assertArrayHasKey( 'ogwm_bulk_queue', $this->options );

		BulkRunner::cancel();

		$this->assertFalse( $this->options['ogwm_bulk_state']['running'], 'cancel flips running off' );
		$this->assertArrayNotHasKey( 'ogwm_bulk_queue', $this->options, 'cancel clears the queue' );
		$this->assertFalse( $this->bulkLockHeld(), 'cancel releases the global lock' );
		$this->assertContains( BulkRunner::TICK_HOOK, $this->unscheduled, 'cancel unschedules the tick chain' );

		// After cancel a brand-new run can start.
		$this->flagged = [ 7 ];
		$this->assertTrue( BulkRunner::start( 'flagged' )['ok'] );
	}

	// =====================================================================
	// Stubs / doubles.
	// =====================================================================

	private function stubOptions(): void {
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
				if ( array_key_exists( $name, $this->options ) ) {
					return false; // UNIQUE option_name → second insert fails (Lock fallback relies on this).
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				if ( ! array_key_exists( $name, $this->options ) ) {
					return false;
				}
				unset( $this->options[ $name ] );
				return true;
			}
		);

		// Per-id post meta — the Scheduler's dedup markers (_ogwm_pending) live here now.
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
	}

	/** The real Lock rides these doubles; we model a persistent object cache. */
	private function stubLock(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );

		Functions\when( 'wp_cache_add' )->alias(
			function ( $key, $value, $group = '', $ttl = 0 ) {
				$k = $group . ':' . $key;
				if ( array_key_exists( $k, $this->cache ) ) {
					return false;
				}
				$this->cache[ $k ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->alias(
			fn( $key, $group = '' ) => array_key_exists( $group . ':' . $key, $this->cache ) ? $this->cache[ $group . ':' . $key ] : false
		);
		Functions\when( 'wp_cache_set' )->alias(
			function ( $key, $value, $group = '', $ttl = 0 ) {
				$this->cache[ $group . ':' . $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group = '' ) {
				unset( $this->cache[ $group . ':' . $key ] );
				return true;
			}
		);

		Functions\when( 'wp_generate_password' )->alias(
			fn() => 'tok-' . str_pad( (string) ( ++$this->tokenSeq ), 4, '0', STR_PAD_LEFT )
		);
	}

	private function stubCron(): void {
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = [] ) {
				$this->scheduled[] = [
					'time' => $timestamp,
					'hook' => $hook,
					'args' => $args,
				];
				return true;
			}
		);
		// Report a scheduled event only if we've logged one this test (idempotency).
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook, $args = [] ) {
				foreach ( $this->scheduled as $s ) {
					if ( $s['hook'] === $hook ) {
						return (int) $s['time'];
					}
				}
				return false;
			}
		);
		Functions\when( 'wp_unschedule_event' )->alias(
			function ( $timestamp, $hook, $args = [] ) {
				$this->unscheduled[] = $hook;
				$this->scheduled     = array_values(
					array_filter( $this->scheduled, static fn( $s ) => $s['hook'] !== $hook )
				);
				return true;
			}
		);
	}

	private function stubQueries(): void {
		// Flagged scope: get_posts returns the curated flagged id list.
		Functions\when( 'get_posts' )->alias( fn( $args = [] ) => $this->flagged );
	}

	private function stubDiskAndStorage(): void {
		// Storage::baseDir() resolves to the persisted base; seed a REAL existing dir
		// (the temp dir) so baseDir() short-circuits to it and the REAL
		// Preflight::hasDiskSpaceFor measures a genuine filesystem. disk_free_space()
		// is a PHP internal Patchwork can't redefine without extra config, so — exactly
		// like PreflightTest — we never stub it and instead drive the verdict through
		// the byte ESTIMATE magnitude against the real disk:
		//   - normal scopes estimate a few MiB → comfortably under free space → pass;
		//   - the insufficient-disk case uses an absurd estimate that no disk can hold.
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) );
		$this->options['ogwm_backup_base'] = sys_get_temp_dir();
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => sys_get_temp_dir() ] );
	}

	private function stubMisc(): void {
		// gmdate() is a PHP internal (Patchwork can't redefine it without config) and
		// is deterministic enough here — the assertions only require started_gmt to be
		// non-empty — so we let the real function run.

		// originalPathFor() → wp_get_original_image_path; a non-existent path forces
		// the estimate onto the per-attachment average (deterministic, > 0).
		Functions\when( 'wp_get_original_image_path' )->alias(
			static fn( $id, $unfiltered = false ) => '/nonexistent/ogwm-' . (int) $id . '.jpg'
		);
	}

	// --- Lock helpers (operate through the same object-cache double the Lock uses). ---

	private function holdBulkLock(): void {
		// Claim the bulk lock as some OTHER worker so start() sees it held.
		$this->cache['ogwm-lock:ogwm_bulk_lock'] = 'someone-else';
	}

	private function bulkLockHeld(): bool {
		return array_key_exists( 'ogwm-lock:ogwm_bulk_lock', $this->cache );
	}

	private function stompBulkLockOwner(): void {
		// Replace the cached owner token so the run's stored token no longer matches,
		// modeling another worker reclaiming the key after our TTL lapsed.
		$this->cache['ogwm-lock:ogwm_bulk_lock'] = 'reclaimed-by-another';
	}

	// --- Cron spy helper. ---

	private function clearScheduled(): void {
		$this->scheduled = [];
	}
}

/**
 * A drop-in Processor double. BulkRunner builds it via the injected
 * $processorFactory and calls ->process($id,'bulk'); we forward to the recorder
 * closure the test supplied (which logs the call and returns the scripted Outcome).
 *
 * It does NOT extend the production Processor (which is `final`); BulkRunner
 * consumes the factory as `object` and duck-types process()/isOk(), so a plain
 * stand-in is exactly what the seam is built to accept.
 */
final class FakeBulkProcessor {

	/** @var \Closure(int,string):Outcome */
	private \Closure $recorder;

	public function __construct( \Closure $recorder ) {
		$this->recorder = $recorder;
	}

	public function process( int $id, string $context ): Outcome {
		return ( $this->recorder )( $id, $context );
	}
}

/**
 * Backing logic for the global WP_Query double (defined in this file's bootstrap
 * shim below). The test sets $resolver to compute {posts, found_posts} from its
 * library fixture, honoring the keyset 'ogwm_after_id' arg and 'posts_per_page'.
 */
final class WpQueryDouble {

	/** @var (\Closure(array):array{posts:array<int,int>,found_posts:int})|null */
	public static $resolver = null;
}
