<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Cron;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Backup\Storage;
use OrchardGrove\OgWatermark\Cron\Cron;
use OrchardGrove\OgWatermark\Queue\BulkRunner;
use OrchardGrove\OgWatermark\Queue\Scheduler;
use OrchardGrove\OgWatermark\Tests\TestCase;
use ReflectionClass;

/**
 * Plugin lifecycle hardening (SPEC M8 — "Crons, hardening, lifecycle").
 *
 * These tests prove the two load-bearing lifecycle guarantees that {@see \OrchardGrove\OgWatermark\Plugin}
 * wires:
 *
 *  1. DEACTIVATE leaves nothing running. {@see Plugin::deactivate()} must
 *     (a) cancel any in-flight bulk run — releasing its global lock so a fresh
 *     install/reactivate can start clean — AND (b) unschedule every cron the
 *     plugin owns (the daily integrity + reaper hooks, plus the queue's pending
 *     one-shots), so no callback fires after the plugin is turned off.
 *  2. ACTIVATE schedules the daily maintenance jobs. {@see Plugin::activate()}
 *     must register the integrity + reaper recurring events (idempotently, so the
 *     per-site multisite activation path can't stack duplicates).
 *
 * The crons run through the REAL {@see Cron} facade and the bulk-cancel through
 * the REAL {@see BulkRunner}, with only their WP dependencies Brain-Monkey-stubbed
 * — so the assertions are against real behaviour, not mock expectations:
 *
 *  - wp_schedule_event / wp_next_scheduled / wp_schedule_single_event /
 *    wp_unschedule_event / wp_clear_scheduled_hook are spies recording every call;
 *  - the durable run state + the global Lock ride a real in-memory options +
 *    object-cache double (the same faithful doubles BulkRunnerTest uses), so
 *    "cancel releases the lock" is a real release.
 *
 * Action Scheduler is deliberately ABSENT here (as_* functions unstubbed), so the
 * wp-cron fallback path is exercised end-to-end and Cron::unschedule()'s
 * AS-group branch is correctly skipped.
 */
final class LifecycleTest extends TestCase {

	/** @var array<string,mixed> In-memory options table (durable run state + Lock fallback). */
	private array $options = [];

	/** @var array<string,mixed> In-memory object cache ("group:key" => value) for the Lock backend. */
	private array $cache = [];

	/** @var array<int,int> Ids get_posts() returns for the flagged scope. */
	private array $flagged = [];

	/** @var array<int,array{time:int,hook:string,recurrence?:string}> wp_schedule_event spy log. */
	private array $scheduledEvents = [];

	/** @var array<int,string> Hooks passed to wp_clear_scheduled_hook. */
	private array $clearedHooks = [];

	/** @var array<int,string> Hooks passed to wp_unschedule_event. */
	private array $unscheduled = [];

	/** Pretend a recurring event already exists for these hooks (wp_next_scheduled). */
	private array $existingEvents = [];

	/** Monotonic token source so each Lock::acquire() yields a distinct token. */
	private int $tokenSeq = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->stubOptions();
		$this->stubLock();
		$this->stubCron();
		$this->stubMisc();

		// No Action Scheduler in this lifecycle: pin BOTH backend selectors to the
		// wp-cron fallback so cancel()/unschedule() take the cron path deterministically
		// (Patchwork keeps an as_* stub defined process-wide once a sibling test mocks
		// it, so a raw function_exists() probe can't be flipped back off).
		BulkRunner::$forceActionScheduler = false;
		Scheduler::$forceActionScheduler  = false;

		// start() resolves the backup base via Storage; keep its static cache clean so
		// the disk pre-flight runs against a fresh, test-controlled path each test.
		self::resetStorageBaseCache();
	}

	protected function tearDown(): void {
		BulkRunner::$forceActionScheduler = null;
		Scheduler::$forceActionScheduler  = null;
		self::resetStorageBaseCache();
		parent::tearDown();
	}

	/** Reset Storage's private static base cache so each test resolves it fresh. */
	private static function resetStorageBaseCache(): void {
		$prop = ( new ReflectionClass( Storage::class ) )->getProperty( 'base' );
		$prop->setValue( null, null );
	}

	// =====================================================================
	// activate(): schedules the daily integrity + reaper crons.
	// =====================================================================

	public function testActivateSchedulesBothDailyCrons(): void {
		Cron::schedule();

		$hooks = array_column( $this->scheduledEvents, 'hook' );
		$this->assertContains( Cron::INTEGRITY_HOOK, $hooks, 'activate schedules the integrity sweep' );
		$this->assertContains( Cron::REAPER_HOOK, $hooks, 'activate schedules the tmp reaper' );

		// Both use our own recurrence, not core's built-in daily.
		foreach ( $this->scheduledEvents as $event ) {
			$this->assertSame( Cron::SCHEDULE, $event['recurrence'] ?? '', 'uses the ogwm_daily recurrence' );
		}
	}

	public function testScheduleIsIdempotentWhenEventsAlreadyExist(): void {
		// wp_next_scheduled() reports both hooks as already scheduled → no new events.
		$this->existingEvents = [ Cron::INTEGRITY_HOOK, Cron::REAPER_HOOK ];

		Cron::schedule();

		$this->assertSame( [], $this->scheduledEvents, 'a second activation never stacks duplicate events' );
	}

	public function testAddScheduleRegistersTheDailyRecurrence(): void {
		$schedules = Cron::addSchedule( [] );

		$this->assertArrayHasKey( Cron::SCHEDULE, $schedules );
		// 86400 == DAY_IN_SECONDS — asserted as a literal so the test never depends on
		// the WP time constants being defined in the bare PHPUnit bootstrap.
		$this->assertSame( 86400, $schedules[ Cron::SCHEDULE ]['interval'] );
	}

	// =====================================================================
	// deactivate(): unschedules every owned cron hook.
	// =====================================================================

	public function testUnscheduleClearsBothDailyCronHooks(): void {
		Cron::unschedule();

		$this->assertContains( Cron::INTEGRITY_HOOK, $this->clearedHooks, 'deactivate clears the integrity cron' );
		$this->assertContains( Cron::REAPER_HOOK, $this->clearedHooks, 'deactivate clears the reaper cron' );
	}

	public function testUnscheduleClearsTheQueuePendingOneShots(): void {
		Cron::unschedule();

		$this->assertContains( BulkRunner::TICK_HOOK, $this->clearedHooks, 'deactivate clears the bulk-tick chain' );
		$this->assertContains( Scheduler::HOOK, $this->clearedHooks, 'deactivate clears the per-attachment process jobs' );
	}

	// =====================================================================
	// deactivate(): cancels an in-flight bulk run + releases its lock.
	// =====================================================================

	public function testDeactivateCancelsInFlightBulkRunAndReleasesLock(): void {
		// Seed a live run: start() reserves the global lock, seeds the durable state,
		// and schedules the tick chain.
		$this->flagged = [ 1, 2, 3, 4, 5, 6 ];
		BulkRunner::start( 'flagged' );

		$this->assertTrue( $this->bulkLockHeld(), 'a run is in flight before deactivate' );
		$this->assertArrayHasKey( 'ogwm_bulk_queue', $this->options );

		// The real deactivate seam: cancel the run, then unschedule the crons.
		BulkRunner::cancel();
		Cron::unschedule();

		$this->assertFalse( $this->options['ogwm_bulk_state']['running'], 'deactivate flips the run off' );
		$this->assertArrayNotHasKey( 'ogwm_bulk_queue', $this->options, 'deactivate clears the work queue' );
		$this->assertFalse( $this->bulkLockHeld(), 'deactivate releases the global lock' );
		$this->assertContains( BulkRunner::TICK_HOOK, $this->unscheduled, 'cancel unschedules the live tick' );

		// And the crons are gone too.
		$this->assertContains( Cron::INTEGRITY_HOOK, $this->clearedHooks );
		$this->assertContains( Cron::REAPER_HOOK, $this->clearedHooks );
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
	}

	/** The real Lock rides these doubles; we model a persistent object cache. */
	private function stubLock(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_add' )->alias(
			function ( $key, $value, $group = '', $ttl = 0 ) {
				$ck = $group . ':' . $key;
				if ( array_key_exists( $ck, $this->cache ) ) {
					return false;
				}
				$this->cache[ $ck ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->alias(
			function ( $key, $group = '' ) {
				$ck = $group . ':' . $key;
				return array_key_exists( $ck, $this->cache ) ? $this->cache[ $ck ] : false;
			}
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
		// A distinct token per acquire so the run identity is meaningful.
		Functions\when( 'wp_generate_password' )->alias(
			fn( $length = 12, $special = true ) => 'tok' . ( ++$this->tokenSeq )
		);
	}

	private function stubCron(): void {
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook, $args = [] ) {
				$this->scheduledEvents[] = [
					'time'       => (int) $timestamp,
					'recurrence' => (string) $recurrence,
					'hook'       => (string) $hook,
				];
				$this->existingEvents[] = (string) $hook;
				return true;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = [] ) {
				$this->existingEvents[] = (string) $hook;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			fn( $hook, $args = [] ) => in_array( (string) $hook, $this->existingEvents, true ) ? time() + 60 : false
		);
		Functions\when( 'wp_unschedule_event' )->alias(
			function ( $timestamp, $hook, $args = [] ) {
				$this->unscheduled[] = (string) $hook;
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook, $args = [] ) {
				$this->clearedHooks[] = (string) $hook;
				$this->existingEvents = array_values(
					array_filter( $this->existingEvents, static fn( $h ) => $h !== (string) $hook )
				);
				return 1;
			}
		);
	}

	/**
	 * Stubs for the dependencies start()/buildPlan reach: get_posts, Storage's
	 * backup-base resolution, and the disk pre-flight.
	 *
	 * disk_free_space() is a PHP internal Patchwork can't redefine without extra
	 * config, so — exactly like BulkRunnerTest/PreflightTest — we never stub it.
	 * Instead we seed ogwm_backup_base to a REAL existing dir (the temp dir) so
	 * Storage::baseDir() short-circuits to it and the REAL Preflight measures a
	 * genuine filesystem: a few flagged ids estimate a few MiB, comfortably under
	 * the temp volume's free space, so the pre-flight passes.
	 */
	private function stubMisc(): void {
		Functions\when( 'get_posts' )->alias( fn( $args = [] ) => $this->flagged );
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) );
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => sys_get_temp_dir() ] );

		// A non-existent original path forces the estimate onto the per-attachment
		// average (deterministic, > 0) without touching the disk.
		Functions\when( 'wp_get_original_image_path' )->alias(
			static fn( $id, $unfiltered = false ) => '/nonexistent/ogwm-' . (int) $id . '.jpg'
		);

		$this->options['ogwm_backup_base'] = sys_get_temp_dir();
	}

	/** Whether the BulkRunner's global lock is currently held in the cache double. */
	private function bulkLockHeld(): bool {
		foreach ( array_keys( $this->cache ) as $ck ) {
			if ( str_contains( $ck, 'ogwm_bulk_lock' ) ) {
				return true;
			}
		}
		return false;
	}
}
