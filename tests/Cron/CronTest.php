<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Cron;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Cron\Cron;
use OrchardGrove\OgWatermark\Cron\Integrity;
use OrchardGrove\OgWatermark\Cron\Reaper;
use OrchardGrove\OgWatermark\Queue\BulkRunner;
use OrchardGrove\OgWatermark\Queue\Scheduler;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Cron facade tests — the register()/schedule()/unschedule() lifecycle seam.
 *
 * These pin the hook names the activation/deactivation agent depends on and prove
 * the three operations do exactly what the lifecycle contract requires: register()
 * wires both action handlers + the custom recurrence; schedule() registers both
 * daily events idempotently; unschedule() clears the maintenance hooks AND the
 * queue's pending one-shots AND the Action Scheduler group.
 */
final class CronTest extends TestCase {

	protected function tearDown(): void {
		BulkRunner::$forceActionScheduler = null;
		parent::tearDown();
	}

	// =====================================================================
	// register() wires the action handlers + the custom schedule filter.
	// =====================================================================

	public function testRegisterWiresBothHandlersAndTheScheduleFilter(): void {
		$actions = [];
		$filters = [];

		Functions\when( 'add_action' )->alias(
			static function ( $hook, $cb ) use ( &$actions ): void {
				$actions[ $hook ] = $cb;
			}
		);
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $cb ) use ( &$filters ): void {
				$filters[ $hook ] = $cb;
			}
		);

		Cron::register();

		$this->assertSame( [ Integrity::class, 'run' ], $actions[ Cron::INTEGRITY_HOOK ] ?? null );
		$this->assertSame( [ Reaper::class, 'run' ], $actions[ Cron::REAPER_HOOK ] ?? null );
		$this->assertSame( [ Cron::class, 'addSchedule' ], $filters['cron_schedules'] ?? null );

		// The chunked-continuation handler MUST be wired by the production register()
		// path — not lazily inside Integrity::run(). The continuation event fires in a
		// fresh wp-cron process where run() has not executed, so without this the
		// deferred sweep dispatches to no callback and a large library never finishes.
		$this->assertSame(
			[ Integrity::class, 'run' ],
			$actions[ Integrity::CONTINUE_HOOK ] ?? null,
			'the integrity-continuation hook must be wired at bootstrap, not lazily'
		);
	}

	// =====================================================================
	// addSchedule() contributes a 24h recurrence under our own key.
	// =====================================================================

	public function testAddScheduleRegistersADailyInterval(): void {
		$out = Cron::addSchedule( [] );

		$this->assertArrayHasKey( Cron::SCHEDULE, $out );
		$this->assertSame( 86400, $out[ Cron::SCHEDULE ]['interval'] );
		$this->assertArrayHasKey( 'display', $out[ Cron::SCHEDULE ] );
	}

	public function testAddScheduleDoesNotClobberAnExistingEntry(): void {
		$existing = [ Cron::SCHEDULE => [ 'interval' => 1, 'display' => 'pinned' ] ];

		$out = Cron::addSchedule( $existing );

		$this->assertSame( 1, $out[ Cron::SCHEDULE ]['interval'], 'an existing same-key schedule is preserved' );
	}

	// =====================================================================
	// schedule() registers both events, idempotently.
	// =====================================================================

	public function testScheduleRegistersBothDailyEventsWhenAbsent(): void {
		$scheduled = [];

		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( $timestamp, $recurrence, $hook ) use ( &$scheduled ): bool {
				$scheduled[ $hook ] = $recurrence;
				return true;
			}
		);

		Cron::schedule();

		$this->assertSame( Cron::SCHEDULE, $scheduled[ Cron::INTEGRITY_HOOK ] ?? null );
		$this->assertSame( Cron::SCHEDULE, $scheduled[ Cron::REAPER_HOOK ] ?? null );
	}

	public function testScheduleIsIdempotentWhenEventsAlreadyExist(): void {
		$calls = 0;

		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( 1234567890 ); // already scheduled
		Functions\when( 'wp_schedule_event' )->alias(
			static function () use ( &$calls ): bool {
				++$calls;
				return true;
			}
		);

		Cron::schedule();

		$this->assertSame( 0, $calls, 'schedule() must not stack duplicate events (multisite per-site safe)' );
	}

	// =====================================================================
	// unschedule() clears the maintenance hooks, the queue one-shots, and AS.
	// =====================================================================

	public function testUnscheduleClearsEveryOwnedHook(): void {
		$cleared = [];

		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( $hook ) use ( &$cleared ): void {
				$cleared[] = $hook;
			}
		);

		// No Action Scheduler in this run (as_unschedule_all_actions undefined).
		BulkRunner::$forceActionScheduler = false;

		Cron::unschedule();

		$this->assertContains( Cron::INTEGRITY_HOOK, $cleared );
		$this->assertContains( Cron::REAPER_HOOK, $cleared );
		$this->assertContains( Integrity::CONTINUE_HOOK, $cleared, 'a pending integrity-continuation one-shot must be cleared on teardown' );
		$this->assertContains( BulkRunner::TICK_HOOK, $cleared, 'the bulk-tick chain must be unscheduled' );
		$this->assertContains( Scheduler::HOOK, $cleared, 'pending per-attachment jobs must be unscheduled' );
	}

	/**
	 * Defining as_unschedule_all_actions through Brain Monkey/Patchwork keeps it
	 * defined PROCESS-WIDE (function_exists() then reports it present for every
	 * later test), which would break sibling tests — notably LifecycleTest — that
	 * deliberately exercise the no-Action-Scheduler branch. Run this one in an
	 * isolated process so the stub never leaks.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testUnscheduleSweepsTheActionSchedulerGroupWhenPresent(): void {
		$asGroup = null;

		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 1 );
		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( $hook, $args, $group ) use ( &$asGroup ): int {
				$asGroup = $group;
				return 0;
			}
		);

		Cron::unschedule();

		$this->assertSame( 'og-watermark', $asGroup, 'the og-watermark AS group is swept' );
	}
}
