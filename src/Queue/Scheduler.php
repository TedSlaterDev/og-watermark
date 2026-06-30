<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Queue;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Pipeline\Processor;
use OrchardGrove\OgWatermark\Support\Meta;
use Throwable;

/**
 * Single-job scheduling + the async job handler (SPEC M6: "Bulk queue &
 * background processing").
 *
 * This is the narrow seam that turns "process attachment N" into ONE safe
 * background job. It never does image work in a web request: enqueue() only
 * schedules, and the actual stamping happens later inside {@see runJob()} when
 * Action Scheduler or wp-cron fires the `ogwm_process_one` action.
 *
 * Two backends, chosen at enqueue time, mirroring the Lock's "one authoritative
 * backend per call" discipline:
 *
 *  1. Action Scheduler present (`as_enqueue_async_action`) → schedule a single
 *     async AS action in the `og-watermark` group.
 *  2. No Action Scheduler → `wp_schedule_single_event` one-shot a second out.
 *
 * DEDUP IS BACKEND-AGNOSTIC. Both backends key on attachment id only (regardless
 * of $context or run identity), via a plain id-set tracked in the `ogwm_pending`
 * option (autoload off). This is deliberate:
 *
 *  - On the cron path wp_next_scheduled() matches the EXACT hook args, so it
 *    cannot see a same-id/different-context duplicate; the id-set fixes that.
 *  - On the Action Scheduler path as_has_scheduled_action() does a FULL exact
 *    args comparison too (a partial args array is NOT a wildcard — AS hashes the
 *    whole serialized array), so it likewise cannot dedup across contexts/run
 *    tokens. The id-set is the one mechanism that dedups correctly on both, so a
 *    thundering herd of flag/regenerate/bulk triggers for the same id collapses
 *    to a single scheduled job on every host.
 *
 * The job handler is hardened so a single bad attachment can NEVER fatal cron /
 * Action Scheduler: the whole body runs in a try/catch( Throwable ), and any
 * thrown error is recorded onto the attachment ( _ogwm_status=failed +
 * _ogwm_last_error ) and SWALLOWED. The Processor it drives is built through a
 * test-seam factory ({@see $processorFactory}) mirroring how Compositor exposes
 * a test driver, so the handler's exception-swallowing and counter wiring are
 * unit-testable without a live image stack.
 *
 * RUN IDENTITY: a bulk job carries the originating run's lock token as a third
 * positional arg, threaded back into {@see BulkRunner::recordJobResult()} so a
 * job's completion advances ONLY the run it belongs to — never a finished run,
 * never a different run that started after a cancel, and never a bare
 * flag/regenerate job that isn't part of any bulk run at all.
 */
final class Scheduler {

	/** The async action hook fired by Action Scheduler or wp-cron. */
	public const HOOK = 'ogwm_process_one';

	/** Action Scheduler group, so our jobs are isolated/inspectable. */
	private const AS_GROUP = 'og-watermark';

	/**
	 * Option holding the dedup id-set: a map of attachment id => true. Autoload is
	 * forced off (it is request-transient queue bookkeeping, not always-loaded
	 * config). Consulted/maintained on BOTH backends — neither wp_next_scheduled()
	 * nor as_has_scheduled_action() can dedup by id alone (both are exact-args).
	 */
	private const PENDING_OPTION = 'ogwm_pending';

	/**
	 * Test seam: a factory that returns the Processor used by {@see runJob()}.
	 * Null in production (a real {@see Processor} is built); a test injects a
	 * fake/throwing processor to exercise the handler's wiring + error-swallowing.
	 * Mirrors {@see \OrchardGrove\OgWatermark\Engine\Compositor::$testDriver}.
	 *
	 * @var callable():object|null
	 */
	public static $processorFactory = null;

	/**
	 * Test seam for the backend selection. Null in production, where the real
	 * function_exists() probe decides. A test pins it true/false to exercise the
	 * Action-Scheduler vs wp-cron path deterministically — Brain Monkey/Patchwork
	 * defines a stubbed function PROCESS-WIDE the first time it is mocked, so
	 * function_exists() alone cannot be flipped back off for a sibling cron-path
	 * test. Mirrors the Compositor test-driver override.
	 */
	public static ?bool $forceActionScheduler = null;

	/**
	 * Register the async job handler. Always wired (on every request) so cron /
	 * Action Scheduler can fire `ogwm_process_one` and reach {@see runJob()}.
	 */
	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'runJob' ], 10, 3 );
	}

	/**
	 * Enqueue exactly ONE async job for $id, de-duplicated by id.
	 *
	 * If a job for $id is already pending (regardless of its context OR run), this
	 * is a no-op that returns false — so a thundering herd of regenerate/settings/
	 * bulk triggers for the same attachment collapses to a single scheduled job.
	 *
	 * @param int    $id       Attachment id to process.
	 * @param string $context  One of flag|bulk|regenerate|settings-change|cli.
	 * @param string $runToken Originating bulk run's lock token ('' for a non-bulk job).
	 * @return bool True when a NEW job was scheduled; false when already pending.
	 */
	public static function enqueue( int $id, string $context = 'bulk', string $runToken = '' ): bool {
		if ( self::isPending( $id ) ) {
			return false;
		}

		// Seed the id-set marker on BOTH paths so a same-id/other-context (or
		// other-run) enqueue is refused regardless of backend — the only mechanism
		// that dedups by id alone (both scheduler lookups are exact-args).
		self::markPending( $id );

		if ( self::usesActionScheduler() ) {
			as_enqueue_async_action( self::HOOK, [ $id, $context, $runToken ], self::AS_GROUP );
			return true;
		}

		// wp-cron fallback: a one-shot event a second out (so it never runs inside
		// THIS request).
		wp_schedule_single_event( time() + 1, self::HOOK, [ $id, $context, $runToken ] );
		return true;
	}

	/**
	 * Whether a job for $id is already scheduled. Keyed on attachment id ONLY (any
	 * context, any run) via the {@see PENDING_OPTION} id-set on both backends — the
	 * one dedup mechanism that holds when wp_next_scheduled()/as_has_scheduled_action()
	 * are both exact-args.
	 *
	 * @param int $id Attachment id.
	 */
	public static function isPending( int $id ): bool {
		return isset( self::pendingSet()[ $id ] );
	}

	/**
	 * THE async handler invoked by Action Scheduler / wp-cron for `ogwm_process_one`.
	 *
	 * Hardened so one bad attachment never fatals the worker: the entire body is
	 * wrapped in try/catch( Throwable ). On a thrown error we record it on the
	 * attachment ( failed status + last-error ) and SWALLOW it. The pending-dedup
	 * marker for $id is always cleared (success OR failure) so a later re-enqueue
	 * is admitted. When the job belongs to an active bulk run ($runToken matches),
	 * that run's done/failed counters are advanced through BulkRunner.
	 *
	 * @param int    $id       Attachment id.
	 * @param string $context  Processing context passed through to the Processor.
	 * @param string $runToken Originating bulk run's lock token ('' for non-bulk).
	 */
	public static function runJob( int $id, string $context = 'bulk', string $runToken = '' ): void {
		self::hardenEnvironment();

		try {
			$processor = self::makeProcessor();
			$outcome   = $processor->process( $id, $context );

			$ok = is_object( $outcome ) && method_exists( $outcome, 'isOk' ) && $outcome->isOk();
			self::recordBulkResult( $ok, $runToken );
		} catch ( Throwable $e ) {
			// A single bad attachment must NEVER bring down cron / Action Scheduler.
			// Persist the failure for the Media-column UI and swallow the throwable.
			update_post_meta( $id, Meta::LAST_ERROR, $e->getMessage() );
			update_post_meta( $id, Meta::STATUS, Meta::STATUS_FAILED );
			self::recordBulkResult( false, $runToken );
		} finally {
			// Clear the dedup marker regardless of backend/outcome so a later
			// re-enqueue for this id is admitted.
			self::clearPending( $id );
		}
	}

	// ---------------------------------------------------------------------
	// Per-job environment hardening (SPEC M6).
	// ---------------------------------------------------------------------

	/**
	 * Best-effort per-job environment hardening: keep the job alive if the client
	 * disconnects (an AS runner triggered via admin-ajax, or the loopback wp-cron
	 * request), and lift the time/memory ceilings a big -scaled original can hit.
	 * Every call is guarded so a host that disables these functions is unaffected.
	 */
	private static function hardenEnvironment(): void {
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
	}

	// ---------------------------------------------------------------------
	// Processor construction (test seam).
	// ---------------------------------------------------------------------

	/**
	 * Build the Processor for this job, via the injectable factory in tests or a
	 * real {@see Processor} in production.
	 */
	private static function makeProcessor(): object {
		$factory = self::$processorFactory;
		if ( is_callable( $factory ) ) {
			return $factory();
		}

		return new Processor();
	}

	// ---------------------------------------------------------------------
	// Bulk-run counter wiring (decoupled from BulkRunner's internals).
	// ---------------------------------------------------------------------

	/**
	 * Notify the active bulk run of a finished job so it can advance its
	 * done/failed counters AND its outstanding-jobs gate. The run token is passed
	 * through so BulkRunner can confirm this job belongs to the CURRENTLY-active
	 * run before counting — a bare flag/regenerate job (token '') or a straggler
	 * from a finished/cancelled run is ignored. Guarded by class/method existence
	 * so the Scheduler stays usable on its own and never hard-depends on
	 * BulkRunner being loaded.
	 *
	 * @param bool   $ok       Whether the job's outcome was a success.
	 * @param string $runToken The originating run's lock token ('' = not a bulk job).
	 */
	private static function recordBulkResult( bool $ok, string $runToken ): void {
		if ( '' === $runToken ) {
			return; // Not part of a bulk run — nothing to count.
		}

		if ( method_exists( BulkRunner::class, 'recordJobResult' ) ) {
			BulkRunner::recordJobResult( $ok, $runToken );
		}
	}

	// ---------------------------------------------------------------------
	// Dedup id-set (ogwm_pending option).
	// ---------------------------------------------------------------------

	/** Add $id to the pending id-set. */
	private static function markPending( int $id ): void {
		$set        = self::pendingSet();
		$set[ $id ] = true;
		update_option( self::PENDING_OPTION, $set, false );
	}

	/** Remove $id from the pending id-set (no write when absent). */
	private static function clearPending( int $id ): void {
		$set = self::pendingSet();
		if ( ! isset( $set[ $id ] ) ) {
			return;
		}
		unset( $set[ $id ] );
		update_option( self::PENDING_OPTION, $set, false );
	}

	/**
	 * The pending id-set: a map of attachment id => true. Always an array (a
	 * corrupt/absent option yields []).
	 *
	 * @return array<int,bool>
	 */
	private static function pendingSet(): array {
		$set = get_option( self::PENDING_OPTION, [] );
		if ( ! is_array( $set ) ) {
			return [];
		}

		$clean = [];
		foreach ( $set as $key => $value ) {
			$clean[ (int) $key ] = (bool) $value;
		}
		return $clean;
	}

	/**
	 * Whether Action Scheduler's async-enqueue API is available. When present we
	 * prefer it (a host-provided, persistent, admin-visible queue); otherwise the
	 * self-rescheduling wp-cron chain is the guaranteed fallback. The test override
	 * short-circuits the probe (see {@see $forceActionScheduler}).
	 */
	private static function usesActionScheduler(): bool {
		if ( null !== self::$forceActionScheduler ) {
			return self::$forceActionScheduler;
		}

		return function_exists( 'as_enqueue_async_action' );
	}
}
