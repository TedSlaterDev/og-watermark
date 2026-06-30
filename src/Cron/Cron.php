<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Cron;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Queue\BulkRunner;
use OrchardGrove\OgWatermark\Queue\Scheduler;

/**
 * The cron facade (SPEC M8: "Crons, hardening, lifecycle").
 *
 * Owns the wiring + lifecycle of the two daily maintenance jobs:
 *
 *  - `ogwm_integrity_check` → {@see Integrity::run()} — the backup-integrity sweep
 *    (cheap existence/byte checks always, sha1 on a rotating subset above a
 *    threshold, lock-aware, chunked).
 *  - `ogwm_tmp_reaper` → {@see Reaper::run()} — the age-gated + lock-aware
 *    tmp/.partial staging-file cleanup.
 *
 * Three responsibilities, one per static method, so the lifecycle (M8) wiring is
 * trivial and the names are pinned for the activation/deactivation seam:
 *
 *  - {@see register()} — add the two action handlers + the custom daily schedule.
 *    Called UNCONDITIONALLY from {@see \OrchardGrove\OgWatermark\Plugin::boot()},
 *    because a cron callback runs with no admin context (exactly like the M5/M6
 *    queue + integration wiring), so it can never be gated behind is_admin().
 *  - {@see schedule()} — on ACTIVATION, register both daily events if absent.
 *    Idempotent, so a per-site multisite activation (run lazily via the version
 *    sentinel) can call it on every site without stacking duplicate events.
 *  - {@see unschedule()} — on DEACTIVATION + UNINSTALL, clear both daily hooks,
 *    any pending one-shot worker/job events the queue left behind, and the whole
 *    Action Scheduler group — so nothing fires after the plugin is turned off.
 *
 * The custom `ogwm_daily` interval is registered via the `cron_schedules` filter
 * rather than reusing core's built-in `daily`, so the cadence is unambiguously
 * ours and a host that has fiddled with the built-in `daily` recurrence can't
 * change how often these maintenance jobs run.
 */
final class Cron {

	/** Daily backup-integrity sweep hook. */
	public const INTEGRITY_HOOK = 'ogwm_integrity_check';

	/** Daily tmp/.partial reaper hook. */
	public const REAPER_HOOK = 'ogwm_tmp_reaper';

	/** Custom recurrence key registered on cron_schedules (a stable 24h cadence). */
	public const SCHEDULE = 'ogwm_daily';

	/**
	 * Action Scheduler group these jobs (and the M6 queue) live in. Mirrors
	 * {@see \OrchardGrove\OgWatermark\Queue\Scheduler}'s private AS_GROUP; kept as a
	 * literal here so unschedule() can sweep the group without reaching into a
	 * private constant on a class it must not modify.
	 */
	private const AS_GROUP = 'og-watermark';

	/** One day in seconds — the recurrence interval for {@see SCHEDULE}. Literal so
	 *  the class loads under PHPUnit, where WP's DAY_IN_SECONDS is undefined. */
	private const DAY_SECONDS = 86400;

	/** Five minutes / fifteen minutes in seconds — first-fire offsets (literals for
	 *  the same reason as DAY_SECONDS). */
	private const FIRST_INTEGRITY_OFFSET = 300;
	private const FIRST_REAPER_OFFSET    = 900;

	/**
	 * Register the cron action handlers + the custom recurrence. Wired
	 * unconditionally on every request from Plugin::boot(), so cron / Action
	 * Scheduler can always reach {@see Integrity::run()} / {@see Reaper::run()}.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', [ self::class, 'addSchedule' ] );
		add_action( self::INTEGRITY_HOOK, [ Integrity::class, 'run' ] );
		add_action( self::REAPER_HOOK, [ Reaper::class, 'run' ] );

		// Wire the chunked-continuation handler HERE, unconditionally on every load —
		// not lazily from inside Integrity::run(). A continuation event is dispatched
		// ~30s later in a FRESH wp-cron process where Integrity::run() has not run, so
		// the handler must already be attached at bootstrap or the deferred sweep fires
		// into no callback and a large library never finishes its integrity coverage.
		Integrity::register();
	}

	/**
	 * Register the daily maintenance events if they are not already scheduled.
	 * Called on activation (per site on multisite, via the version sentinel).
	 * Idempotent: a second call while the events exist is a no-op.
	 *
	 * The first fire is offset a few minutes out so it never piles onto the
	 * activation request itself, and the two jobs are staggered so a large library
	 * is not hit by both sweeps in the same cron tick.
	 */
	public static function schedule(): void {
		// Ensure our custom recurrence is known to wp_schedule_event even when this
		// runs before boot()'s register() on the activation request.
		add_filter( 'cron_schedules', [ self::class, 'addSchedule' ] );

		$now = time();

		if ( ! wp_next_scheduled( self::INTEGRITY_HOOK ) ) {
			wp_schedule_event( $now + self::FIRST_INTEGRITY_OFFSET, self::SCHEDULE, self::INTEGRITY_HOOK );
		}

		if ( ! wp_next_scheduled( self::REAPER_HOOK ) ) {
			wp_schedule_event( $now + self::FIRST_REAPER_OFFSET, self::SCHEDULE, self::REAPER_HOOK );
		}
	}

	/**
	 * Tear down every scheduled thing this plugin owns: the two daily maintenance
	 * hooks, any pending queue one-shots (the bulk-tick chain + per-attachment
	 * process jobs the wp-cron fallback may have left), and the entire Action
	 * Scheduler group when AS is present. Called on deactivation AND defensively
	 * from uninstall, so a turned-off plugin can never fire a stray job.
	 *
	 * Guarded throughout so it is safe in the bare uninstall context (no plugin
	 * autoload guarantees there) and on hosts without Action Scheduler.
	 */
	public static function unschedule(): void {
		// 1) The daily maintenance hooks (clears ALL events for each hook).
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::INTEGRITY_HOOK );
			wp_clear_scheduled_hook( self::REAPER_HOOK );

			// The integrity sweep's deferred continuation one-shot. Without this an
			// in-flight chunked sweep's pending ogwm_integrity_continue event survives
			// teardown and later fires into a missing callback (after uninstall the class
			// is gone entirely) — exactly the stray-event failure teardown exists to prevent.
			wp_clear_scheduled_hook( Integrity::CONTINUE_HOOK );

			// The queue's pending one-shots: the self-rescheduling bulk-tick chain and
			// any per-attachment process job the wp-cron fallback scheduled. Referenced
			// via the Queue constants so the names never drift out of sync.
			if ( class_exists( BulkRunner::class ) ) {
				wp_clear_scheduled_hook( BulkRunner::TICK_HOOK );
			}
			if ( class_exists( Scheduler::class ) ) {
				wp_clear_scheduled_hook( Scheduler::HOOK );
			}
		}

		// 2) The Action Scheduler group, if AS is installed — covers async jobs that
		//    live in AS's own store rather than wp-cron. Guarded so a host without AS
		//    (the common case) simply skips it.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], self::AS_GROUP );
		}
	}

	/**
	 * cron_schedules filter: add our `ogwm_daily` 24-hour recurrence.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function addSchedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = [
				'interval' => self::DAY_SECONDS,
				'display'  => __( 'Once daily (OG Watermark maintenance)', 'og-watermark' ),
			];
		}

		return $schedules;
	}
}
