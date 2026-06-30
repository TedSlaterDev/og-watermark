<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Queue;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Backup\Preflight;
use OrchardGrove\OgWatermark\Backup\Storage;
use OrchardGrove\OgWatermark\Pipeline\Processor;
use OrchardGrove\OgWatermark\Support\Lock;
use OrchardGrove\OgWatermark\Support\Meta;

/**
 * Resumable, server-side bulk orchestration — the M6 keystone (SPEC "Performance
 * & bulk strategy", "Bulk Tools").
 *
 * The load-bearing property is the SPEC's "the browser only OBSERVES": NO work
 * ever happens in a web request. {@see start()} validates, reserves the global
 * lock, seeds the durable run state, and KICKS the background machinery; it never
 * stamps an image. {@see tick()} is the only INLINE worker (cron path) and the
 * watchdog that keeps the lock alive (AS path). {@see progress()} is a PURE READ
 * of the durable state, safe to call from an admin AJAX poll. {@see cancel()}
 * tears the run down and releases the lock.
 *
 * Two background models, picked by host capability, both funnelled through this
 * one class:
 *
 *   - Action Scheduler present → {@see start()} enqueues one async
 *     `ogwm_process_one` job per attachment of the first page via
 *     {@see Scheduler::enqueue()} (those jobs run in AS's own runner and call back
 *     into {@see recordJobResult()} as they finish). The run carries an
 *     OUTSTANDING-jobs counter: kick() increments it per enqueued job and each
 *     completion decrements it, so the run — and the global lock — stay alive
 *     until the LAST enqueued attachment actually completes, not merely until the
 *     jobs were scheduled. A watchdog {@see tick()} reschedules itself while
 *     outstanding jobs remain (refreshing the lock each pass) and, for the 'all'
 *     scope, pages the cursor to enqueue the next page.
 *   - No Action Scheduler → {@see start()} schedules one `ogwm_bulk_tick`, and the
 *     self-rescheduling cron chain in {@see tick()} processes the whole run INLINE,
 *     a small batch at a time, chaining itself until the queue drains.
 *
 * The 'all' scope is NEVER materialized into a full id array — that would blow up
 * memory on a large library and bloat the (autoload-off) option. Instead a paged
 * CURSOR (last seen post id + page size) is stored in {@see OPTION_QUEUE} and
 * advanced one page per claim via a keyset query (`WHERE ID > cursor`, injected by
 * {@see keysetWhere()} on `posts_where`), so the run streams through the library
 * in stable, bounded pages.
 *
 * The global lock ({@see Lock} keyed {@see LOCK_KEY}) guarantees two bulk runs
 * never overlap. It is HEARTBEAT-refreshed at the top of every {@see tick()}, on
 * every AS-job completion ({@see recordJobResult()}), and between inline
 * attachments; if the heartbeat is lost (the run's TTL lapsed and the key was
 * reclaimed) the tick ABORTS rather than racing a second run. Its lifetime spans
 * the WHOLE run on both backends — never released until the queue is drained AND
 * no outstanding AS jobs remain.
 *
 * RUN IDENTITY: the stored lock token doubles as the run id. Every enqueued AS
 * job carries it; {@see recordJobResult()}, {@see finish()}, and
 * {@see bumpCounters()} re-read the live state and act ONLY when its token still
 * matches the caller's — so a straggler from a finished/cancelled run can never
 * corrupt a fresh run's counters or flip its running flag, and a bare
 * flag/regenerate job (token '') never touches bulk state at all.
 */
final class BulkRunner {

	/** Cron action that drives the self-rescheduling worker / cursor pager / watchdog. */
	public const TICK_HOOK = 'ogwm_bulk_tick';

	/** Global single-run lock key (heartbeat-refreshed across ticks + job completions). */
	private const LOCK_KEY = 'ogwm_bulk_lock';

	/** Durable run state (counters + scope + lock token + outstanding). Autoload OFF. */
	private const OPTION_STATE = 'ogwm_bulk_state';

	/** Durable work queue (id list for flagged/ids, or a cursor for 'all'). Autoload OFF. */
	private const OPTION_QUEUE = 'ogwm_bulk_queue';

	/** Lock lifetime; far longer than a single batch, refreshed every tick/completion. */
	private const LOCK_TTL = 600;

	/** Attachments stamped per tick — small so a tick stays well under any limit. */
	private const BATCH_SIZE = 5;

	/** Page size for the 'all'-scope keyset cursor (ids fetched per page). */
	private const PAGE_SIZE = 50;

	/** Average backup bytes assumed per attachment when real sizes can't be summed. */
	private const AVG_BACKUP_BYTES = 5242880; // 5 MiB.

	/**
	 * Request-scoped keyset cursor for {@see keysetWhere()}. Null when no keyset
	 * query is in flight, so the posts_where filter only ever rewrites OUR pager
	 * query and never leaks into another query on the request.
	 */
	private static ?int $keysetAfter = null;

	/**
	 * Test seam mirroring {@see Scheduler::$processorFactory}: lets a test inject a
	 * fake Processor so {@see tick()} can be exercised without the real M2/M3 stack.
	 * Defaults to a real {@see Processor}. Typed `object` (not `Processor`) precisely
	 * because Processor is `final` and cannot be subclassed — a fake duck-types
	 * `process(): Outcome` and is consumed via the same `isOk()` check the real
	 * Outcome exposes.
	 *
	 * @var callable():object|null
	 */
	public static $processorFactory = null;

	/**
	 * Test seam for the background-backend selection, mirroring
	 * {@see Scheduler::$forceActionScheduler}. Null in production, where the real
	 * function_exists() probe decides. A test pins it true/false to exercise the
	 * Action-Scheduler vs wp-cron kick deterministically — once Brain Monkey/
	 * Patchwork defines an `as_*` stub it stays defined process-wide, so
	 * function_exists() alone can't be flipped back off for a sibling cron-path test.
	 */
	public static ?bool $forceActionScheduler = null;

	/** Register the cron-chain worker so cron/AS can fire ogwm_bulk_tick. */
	public static function register(): void {
		add_action( self::TICK_HOOK, [ self::class, 'tick' ] );
	}

	/**
	 * Begin a bulk run over $scope. Validates, reserves the global lock, seeds the
	 * durable state + queue, and kicks the background worker. Does NOT stamp.
	 *
	 * @param string                                  $scope One of flagged|all|ids.
	 * @param array{ids?:array<int,int|string>}       $opts  Extra inputs; 'ids' for the 'ids' scope.
	 * @return array{ok:bool,reason?:string,total?:int,estimate?:int}
	 */
	public static function start( string $scope, array $opts = [] ): array {
		$scope = self::normalizeScope( $scope );

		// 1) Refuse a second concurrent run — the global lock is the single gate.
		if ( Lock::held( self::LOCK_KEY ) ) {
			return [
				'ok'     => false,
				'reason' => 'already-running',
			];
		}

		// 2) Build the work plan (id list or a paged cursor) and a disk estimate.
		$plan = self::buildPlan( $scope, $opts );
		if ( 0 === $plan['total'] ) {
			return [
				'ok'    => true,
				'total' => 0,
			];
		}

		// 3) DISK PRE-FLIGHT — refuse before reserving anything if the backups for
		//    this scope can't fit (each flagged image roughly doubles its master).
		if ( ! Preflight::hasDiskSpaceFor( $plan['estimate'], Storage::baseDir() ) ) {
			return [
				'ok'       => false,
				'reason'   => 'insufficient-disk',
				'estimate' => $plan['estimate'],
			];
		}

		// 4) Reserve the global lock. A null token means a racing start() grabbed it
		//    between the held() check and here — treat exactly as already-running.
		$token = Lock::acquire( self::LOCK_KEY, self::LOCK_TTL );
		if ( null === $token ) {
			return [
				'ok'     => false,
				'reason' => 'already-running',
			];
		}

		$usesAs = self::actionSchedulerAvailable();

		// 5) Seed the durable queue + run state (both autoload OFF).
		update_option( self::OPTION_QUEUE, $plan['queue'], false );
		update_option(
			self::OPTION_STATE,
			[
				'running'     => true,
				'scope'       => $scope,
				'total'       => $plan['total'],
				'done'        => 0,
				'failed'      => 0,
				'outstanding' => 0,
				'as'          => $usesAs,
				'started_gmt' => gmdate( 'c' ),
				'lock'        => $token,
			],
			false
		);

		// 6) Kick the background worker. With Action Scheduler we enqueue the first
		//    page as async per-id jobs (tracking them in the outstanding counter) and
		//    schedule a watchdog tick; otherwise we schedule the cron chain.
		self::kick( $scope, $plan, $token, $usesAs );

		return [
			'ok'    => true,
			'total' => $plan['total'],
		];
	}

	/**
	 * The self-rescheduling worker (cron path), the 'all'-scope cursor pager, AND
	 * the watchdog that keeps the lock alive while AS jobs drain. Runs under the
	 * global lock; on the cron path it processes one bounded batch inline; on the AS
	 * path it pages the next set of jobs and reschedules while jobs remain
	 * outstanding. Releases the lock and marks the run finished only when ALL work
	 * is drained (queue empty AND no outstanding AS jobs).
	 */
	public static function tick(): void {
		$state = self::readState();
		if ( null === $state || empty( $state['running'] ) ) {
			return; // No active run (already finished or cancelled) — nothing to do.
		}

		$token = self::tokenOf( $state );

		// HEARTBEAT: prove we still own the run before doing any work. A lost lock
		// means our TTL lapsed and the key was reclaimed by another run — ABORT
		// rather than race a second worker over the same attachments.
		if ( '' === $token || ! Lock::refresh( self::LOCK_KEY, $token, self::LOCK_TTL ) ) {
			return;
		}

		$usesAs = ! empty( $state['as'] );

		if ( $usesAs ) {
			self::tickActionScheduler( $token );
			return;
		}

		self::tickInline( $state, $token );
	}

	/**
	 * AS-path watchdog tick. Enqueues the next page (for 'all', advancing the
	 * cursor), counting each new job into the outstanding gate, then reschedules
	 * itself while either work remains in the queue OR jobs are still outstanding —
	 * refreshing the lock each pass so a long backlog can't let the TTL lapse. When
	 * the queue is drained AND outstanding hits zero, finishes the run.
	 */
	private static function tickActionScheduler( string $token ): void {
		// Page the next batch (mostly relevant to the 'all' cursor; for a finite set
		// the queue was fully consumed at kick time, so this claims nothing).
		$claim = self::claimBatch( self::scopeOf() );
		$ids   = $claim['ids'];

		if ( [] !== $ids ) {
			$enqueued = 0;
			foreach ( $ids as $id ) {
				if ( Scheduler::enqueue( $id, 'bulk', $token ) ) {
					++$enqueued;
				}
			}
			self::addOutstanding( $token, $enqueued );
		}

		$state = self::readState();
		if ( null === $state || self::tokenOf( $state ) !== $token ) {
			return; // Run finished/cancelled/superseded under us.
		}

		$outstanding = isset( $state['outstanding'] ) ? (int) $state['outstanding'] : 0;

		// Keep the watchdog (and thus the lock heartbeat) alive while jobs are in
		// flight or more pages remain to enqueue.
		if ( $outstanding > 0 || self::hasWork() ) {
			self::scheduleTick();
			return;
		}

		self::finish( $token );
	}

	/**
	 * Cron-path inline tick. Claims a bounded batch and stamps each attachment via
	 * the M4 Processor, heartbeating between files, then either chains another tick
	 * while work remains or finishes the drained run. Includes a belt-and-braces
	 * terminator so a misbehaving keyset query can never spin the chain forever.
	 *
	 * @param array<string,mixed> $state The active run state (already heartbeated).
	 */
	private static function tickInline( array $state, string $token ): void {
		$cursorBefore = self::currentCursor();

		$claim = self::claimBatch( $state['scope'] ?? '' );
		$ids   = $claim['ids'];

		if ( [] !== $ids ) {
			$processor = self::processor();
			$done      = 0;
			$failed    = 0;

			foreach ( $ids as $id ) {
				$outcome = $processor->process( $id, 'bulk' );
				if ( self::outcomeIsOk( $outcome ) ) {
					++$done;
				} else {
					++$failed;
				}

				// Heartbeat between attachments so a slow big-image stamp can't let the
				// run's lock lapse mid-batch under a concurrent start(), and free the
				// per-image peak so a multi-image batch stays bounded.
				Lock::refresh( self::LOCK_KEY, $token, self::LOCK_TTL );
				if ( function_exists( 'gc_collect_cycles' ) ) {
					gc_collect_cycles();
				}
			}

			self::bumpCounters( $token, $done, $failed );
		}

		// TERMINATION SAFEGUARD: if everything is accounted for, or a cursor run
		// failed to make forward progress (a misbehaving keyset query that ignores
		// the bound AND the defensive filter), finish rather than chain forever.
		if ( self::runComplete( $token ) || self::cursorStalled( $cursorBefore ) ) {
			self::finish( $token );
			return;
		}

		// Re-evaluate "work remains" against the post-batch queue, then chain.
		if ( self::hasWork() ) {
			self::scheduleTick();
			return;
		}

		self::finish( $token );
	}

	/**
	 * READ-ONLY snapshot of the run, safe to call from an admin AJAX poll. Never
	 * acquires a lock, never writes — the browser only observes.
	 *
	 * @return array{running:bool,scope:string,total:int,done:int,failed:int,started_gmt:string}
	 */
	public static function progress(): array {
		$state = self::readState();
		if ( null === $state ) {
			return self::idleProgress();
		}

		return [
			'running'     => ! empty( $state['running'] ),
			'scope'       => isset( $state['scope'] ) ? (string) $state['scope'] : '',
			'total'       => isset( $state['total'] ) ? (int) $state['total'] : 0,
			'done'        => isset( $state['done'] ) ? (int) $state['done'] : 0,
			'failed'      => isset( $state['failed'] ) ? (int) $state['failed'] : 0,
			'started_gmt' => isset( $state['started_gmt'] ) ? (string) $state['started_gmt'] : '',
		];
	}

	/**
	 * Counter callback for the Action-Scheduler path: {@see Scheduler::runJob()}
	 * calls this after each async `ogwm_process_one` job finishes so the run's
	 * done/failed counters advance even though that job ran OUTSIDE {@see tick()}.
	 *
	 * IDENTITY-GUARDED: counts ONLY when the live state's lock token still equals
	 * $token AND the run is still running. A straggler from a finished/cancelled run
	 * (or a job tagged with a different/empty run) is ignored, so it can never
	 * inflate a finished run's counters past total or corrupt a freshly-started
	 * run. Each accepted completion decrements the outstanding-jobs gate, refreshes
	 * the lock (so a long AS backlog keeps the TTL alive), and — when this was the
	 * last outstanding job and no queued pages remain — finishes the run, releasing
	 * the global lock at the true end of the work.
	 *
	 * @param bool   $ok    Whether the finished job's outcome was a success.
	 * @param string $token The originating run's lock token.
	 */
	public static function recordJobResult( bool $ok, string $token ): void {
		if ( '' === $token ) {
			return;
		}

		$state = self::readState();
		if ( null === $state || empty( $state['running'] ) || self::tokenOf( $state ) !== $token ) {
			return; // Not the active run — ignore the straggler.
		}

		$state['done']        = ( isset( $state['done'] ) ? (int) $state['done'] : 0 ) + ( $ok ? 1 : 0 );
		$state['failed']      = ( isset( $state['failed'] ) ? (int) $state['failed'] : 0 ) + ( $ok ? 0 : 1 );
		$state['outstanding'] = max( 0, ( isset( $state['outstanding'] ) ? (int) $state['outstanding'] : 0 ) - 1 );
		update_option( self::OPTION_STATE, $state, false );

		// Keep the lock alive as the backlog drains.
		Lock::refresh( self::LOCK_KEY, $token, self::LOCK_TTL );

		// Last job in, nothing left to page → finish + release at the true end.
		if ( 0 === $state['outstanding'] && ! self::hasWork() ) {
			self::finish( $token );
		}
	}

	/**
	 * Stop the active run: clear the queue, flip running off, unschedule the cron
	 * chain, and release the global lock so a fresh run can start immediately.
	 */
	public static function cancel(): void {
		$state = self::readState();
		$token = ( null !== $state ) ? self::tokenOf( $state ) : '';

		delete_option( self::OPTION_QUEUE );

		if ( null !== $state ) {
			$state['running']     = false;
			$state['outstanding'] = 0;
			update_option( self::OPTION_STATE, $state, false );
		}

		self::unscheduleTick();

		if ( '' !== $token ) {
			Lock::release( self::LOCK_KEY, $token );
		}
	}

	// ---------------------------------------------------------------------
	// Work-plan construction.
	// ---------------------------------------------------------------------

	/**
	 * Build the durable queue payload + total + disk estimate for $scope.
	 *
	 * 'flagged'/'ids' resolve to a concrete id list (small, finite). 'all' stores a
	 * CURSOR only — never the full id array — and counts the total via a cheap
	 * COUNT query so progress has a denominator without materializing ids.
	 *
	 * @param array{ids?:array<int,int|string>} $opts
	 * @return array{queue:array<string,mixed>,total:int,estimate:int}
	 */
	private static function buildPlan( string $scope, array $opts ): array {
		if ( 'all' === $scope ) {
			$total = self::countAllImages();
			return [
				'queue'    => [
					'mode'   => 'cursor',
					'cursor' => 0, // Keyset start: WHERE ID > 0.
					'page'   => self::PAGE_SIZE,
				],
				'total'    => $total,
				'estimate' => self::estimateForCount( $total ),
			];
		}

		$ids = ( 'ids' === $scope )
			? self::sanitizeIds( $opts['ids'] ?? [] )
			: self::flaggedIds();

		return [
			'queue'    => [
				'mode' => 'ids',
				'ids'  => array_values( $ids ),
			],
			'total'    => count( $ids ),
			'estimate' => self::estimateForIds( $ids ),
		];
	}

	/**
	 * Kick the background machinery after the state is seeded. AS path: enqueue the
	 * first page as async per-id jobs (each tagged with the run token + counted into
	 * the outstanding gate so the run stays open until they actually complete), plus
	 * one watchdog tick. Cron path: schedule the inline tick chain.
	 *
	 * @param array{queue:array<string,mixed>,total:int,estimate:int} $plan
	 */
	private static function kick( string $scope, array $plan, string $token, bool $usesAs ): void {
		if ( ! $usesAs ) {
			self::scheduleTick();
			return;
		}

		// Action Scheduler: hand the first page to per-id async jobs. For 'ids'/
		// 'flagged' that is the whole (finite) set; for 'all' it is the first page
		// and the watchdog tick pages the rest. The enqueued ids are consumed from
		// the queue so the watchdog never re-claims them.
		$firstPage = self::claimBatchForKick( $scope, $plan );

		$enqueued = 0;
		foreach ( $firstPage as $id ) {
			if ( Scheduler::enqueue( $id, 'bulk', $token ) ) {
				++$enqueued;
			}
		}
		self::addOutstanding( $token, $enqueued );

		// A watchdog tick keeps the lock alive and (for 'all') pages the cursor; it
		// finishes the run only once outstanding hits zero and no pages remain.
		self::scheduleTick();
	}

	// ---------------------------------------------------------------------
	// Batch claiming (queue mutation).
	// ---------------------------------------------------------------------

	/**
	 * Claim up to BATCH_SIZE ids from the queue and PERSIST the shrunken queue, so
	 * a crash/resume never re-stamps a claimed id. Handles both the id-list and the
	 * keyset-cursor ('all') shapes.
	 *
	 * @return array{ids:array<int,int>}
	 */
	private static function claimBatch( string $scope ): array {
		return self::claim( $scope, self::BATCH_SIZE );
	}

	/**
	 * The kick-time first page (AS path): one page worth of ids, removed from the
	 * queue so the watchdog continues from there.
	 *
	 * @param array{queue:array<string,mixed>,total:int,estimate:int} $plan
	 * @return array<int,int>
	 */
	private static function claimBatchForKick( string $scope, array $plan ): array {
		$size = ( 'all' === $scope ) ? self::PAGE_SIZE : count( $plan['queue']['ids'] ?? [] );
		$size = max( 1, $size );

		return self::claim( $scope, $size )['ids'];
	}

	/**
	 * Pull up to $size ids off the queue, advancing/persisting the queue state.
	 *
	 * @return array{ids:array<int,int>}
	 */
	private static function claim( string $scope, int $size ): array {
		$queue = self::readQueue();
		if ( null === $queue ) {
			return [ 'ids' => [] ];
		}

		if ( 'cursor' === ( $queue['mode'] ?? '' ) ) {
			$cursor = isset( $queue['cursor'] ) ? (int) $queue['cursor'] : 0;
			$page   = isset( $queue['page'] ) ? (int) $queue['page'] : self::PAGE_SIZE;
			$page   = min( $page, max( 1, $size ) );

			$ids = self::pageAllImages( $cursor, $page );
			if ( [] === $ids ) {
				// Cursor exhausted — the 'all' stream is drained. Drop the queue.
				delete_option( self::OPTION_QUEUE );
				return [ 'ids' => [] ];
			}

			// Advance the keyset cursor PAST the last id we just claimed, then persist
			// so the next tick (or a resumed run) continues without re-claiming.
			$queue['cursor'] = (int) end( $ids );
			update_option( self::OPTION_QUEUE, $queue, false );

			return [ 'ids' => $ids ];
		}

		// id-list mode: shift the next $size ids off the front and persist the rest.
		$ids       = array_map( 'intval', array_values( (array) ( $queue['ids'] ?? [] ) ) );
		$claimed   = array_slice( $ids, 0, $size );
		$remaining = array_slice( $ids, $size );

		if ( [] === $remaining ) {
			delete_option( self::OPTION_QUEUE );
		} else {
			$queue['ids'] = $remaining;
			update_option( self::OPTION_QUEUE, $queue, false );
		}

		return [ 'ids' => $claimed ];
	}

	/**
	 * Whether the queue still has claimable work. For the cursor mode this PEEKS one
	 * id past the current cursor without consuming it; for the id list it checks for
	 * a non-empty list. Used to decide chain-vs-finish.
	 */
	private static function hasWork(): bool {
		$queue = self::readQueue();
		if ( null === $queue ) {
			return false;
		}

		if ( 'cursor' === ( $queue['mode'] ?? '' ) ) {
			$cursor = isset( $queue['cursor'] ) ? (int) $queue['cursor'] : 0;
			return [] !== self::pageAllImages( $cursor, 1 );
		}

		return [] !== array_filter( (array) ( $queue['ids'] ?? [] ) );
	}

	/** The current keyset cursor (cursor mode), or null for an id-list / absent queue. */
	private static function currentCursor(): ?int {
		$queue = self::readQueue();
		if ( null === $queue || 'cursor' !== ( $queue['mode'] ?? '' ) ) {
			return null;
		}
		return isset( $queue['cursor'] ) ? (int) $queue['cursor'] : 0;
	}

	/**
	 * Whether a cursor run failed to advance after claiming a page — the signature
	 * of a host keyset query that ignores BOTH the WHERE bound and the defensive
	 * filter. An id-list run (cursor null both before/after) is never "stalled".
	 */
	private static function cursorStalled( ?int $before ): bool {
		if ( null === $before ) {
			return false;
		}
		$after = self::currentCursor();
		if ( null === $after ) {
			return false; // Cursor was consumed/drained — that is progress, not a stall.
		}
		return $after <= $before;
	}

	/** Whether the counters show every attachment accounted for (done + failed ≥ total). */
	private static function runComplete( string $token ): bool {
		$state = self::readState();
		if ( null === $state || self::tokenOf( $state ) !== $token ) {
			return false;
		}
		$total = isset( $state['total'] ) ? (int) $state['total'] : 0;
		if ( $total <= 0 ) {
			return false;
		}
		$seen = ( isset( $state['done'] ) ? (int) $state['done'] : 0 )
			+ ( isset( $state['failed'] ) ? (int) $state['failed'] : 0 );
		return $seen >= $total;
	}

	// ---------------------------------------------------------------------
	// Counters / lifecycle (all identity-guarded against the run token).
	// ---------------------------------------------------------------------

	/**
	 * Add to the run's done/failed counters — but ONLY when the live state still
	 * belongs to $token. A no-op if the run finished/cancelled or was superseded.
	 */
	private static function bumpCounters( string $token, int $done, int $failed ): void {
		$state = self::readState();
		if ( null === $state || self::tokenOf( $state ) !== $token ) {
			return;
		}

		$state['done']   = ( isset( $state['done'] ) ? (int) $state['done'] : 0 ) + $done;
		$state['failed'] = ( isset( $state['failed'] ) ? (int) $state['failed'] : 0 ) + $failed;

		update_option( self::OPTION_STATE, $state, false );
	}

	/** Add $delta to the outstanding-jobs gate, identity-guarded against $token. */
	private static function addOutstanding( string $token, int $delta ): void {
		if ( 0 === $delta ) {
			return;
		}
		$state = self::readState();
		if ( null === $state || self::tokenOf( $state ) !== $token ) {
			return;
		}

		$state['outstanding'] = max( 0, ( isset( $state['outstanding'] ) ? (int) $state['outstanding'] : 0 ) + $delta );
		update_option( self::OPTION_STATE, $state, false );
	}

	/**
	 * Finalize a drained run: flip running off, release the lock, drop the queue —
	 * but ONLY when the live state still belongs to $token. This re-read-and-verify
	 * makes a straggler tick from a cancelled-then-restarted run a no-op: it cannot
	 * flip a fresh run's running flag or free its lock with a stale token.
	 */
	private static function finish( string $token ): void {
		$state = self::readState();
		if ( null === $state || self::tokenOf( $state ) !== $token ) {
			return;
		}

		$state['running']     = false;
		$state['outstanding'] = 0;
		update_option( self::OPTION_STATE, $state, false );

		delete_option( self::OPTION_QUEUE );

		Lock::release( self::LOCK_KEY, $token );
	}

	// ---------------------------------------------------------------------
	// Cron scheduling helpers.
	// ---------------------------------------------------------------------

	/** Schedule one near-future tick to continue/finish the run. */
	private static function scheduleTick(): void {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_single_event( time() + 1, self::TICK_HOOK );
		}
	}

	/** Remove any pending tick (cancel path). */
	private static function unscheduleTick(): void {
		$next = wp_next_scheduled( self::TICK_HOOK );
		if ( false !== $next ) {
			wp_unschedule_event( $next, self::TICK_HOOK );
		}
	}

	// ---------------------------------------------------------------------
	// Work-set queries (id resolution; the 'all' scope NEVER materializes ids).
	// ---------------------------------------------------------------------

	/**
	 * Ids of every attachment carrying the opt-in flag (_ogwm_enabled = '1'). This
	 * is a finite, user-curated set, so a flat id list is fine.
	 *
	 * @return array<int,int>
	 */
	private static function flaggedIds(): array {
		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => Meta::ENABLED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1',           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
				'cache_results'  => false,
			]
		);

		return self::sanitizeIds( is_array( $ids ) ? $ids : [] );
	}

	/** Total image attachments, for the 'all'-scope denominator (no id list built). */
	private static function countAllImages(): int {
		$query = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'image',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		return (int) $query->found_posts;
	}

	/**
	 * One keyset page of image-attachment ids with ID > $afterId, ordered by ID
	 * ascending. This is the 'all'-scope pager: it streams the library in stable,
	 * bounded pages without ever loading the whole id set.
	 *
	 * The keyset bound is injected into the SQL by {@see keysetWhere()} on
	 * `posts_where`, added immediately around THIS query and removed in finally so
	 * it never leaks into another query. A defensive id>cursor filter still runs on
	 * the result as a second line of defence against a host that drops the filter.
	 *
	 * @return array<int,int>
	 */
	private static function pageAllImages( int $afterId, int $page ): array {
		self::$keysetAfter = $afterId;
		add_filter( 'posts_where', [ self::class, 'keysetWhere' ], 10, 1 );

		try {
			$query = new \WP_Query(
				[
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'post_mime_type'         => 'image',
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'posts_per_page'         => max( 1, $page ),
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'ogwm_after_id'          => $afterId, // Carried for the filter + diagnostics.
				]
			);

			$ids = is_array( $query->posts ) ? $query->posts : [];
		} finally {
			remove_filter( 'posts_where', [ self::class, 'keysetWhere' ], 10 );
			self::$keysetAfter = null;
		}

		// Defensive keyset enforcement: even if the host/test ignores the WHERE bound,
		// only return ids strictly greater than the cursor so we never re-claim.
		$ids = array_filter(
			array_map( 'intval', $ids ),
			static fn( int $id ): bool => $id > $afterId
		);

		return array_values( $ids );
	}

	/**
	 * `posts_where` filter that appends the keyset bound for the in-flight pager
	 * query. Active ONLY while {@see $keysetAfter} is set (i.e. between the
	 * add_filter/remove_filter pair in {@see pageAllImages()}), so it never touches
	 * any other query. $afterId is cast through absint(), so it is injection-safe.
	 *
	 * @param string $where The accumulated WHERE clause.
	 */
	public static function keysetWhere( string $where ): string {
		if ( null === self::$keysetAfter ) {
			return $where;
		}

		global $wpdb;
		$after = (int) self::$keysetAfter;
		$table = ( isset( $wpdb ) && isset( $wpdb->posts ) ) ? (string) $wpdb->posts : 'wp_posts';

		return $where . ' AND ' . $table . '.ID > ' . $after . ' ';
	}

	// ---------------------------------------------------------------------
	// Disk estimate.
	// ---------------------------------------------------------------------

	/**
	 * Estimate the added backup bytes for a concrete id set: sum the real on-disk
	 * size of each would-be-backed-up original, falling back to the per-attachment
	 * average when a file can't be measured.
	 *
	 * @param array<int,int> $ids
	 */
	private static function estimateForIds( array $ids ): int {
		$total = 0;
		foreach ( $ids as $id ) {
			$path = self::originalPathFor( $id );
			$size = ( '' !== $path && is_file( $path ) ) ? @filesize( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$total += ( false !== $size && $size > 0 ) ? (int) $size : self::AVG_BACKUP_BYTES;
		}

		return $total;
	}

	/** Estimate added backup bytes for a count (the 'all' scope) via the average. */
	private static function estimateForCount( int $count ): int {
		return max( 0, $count ) * self::AVG_BACKUP_BYTES;
	}

	/** Resolve the true original path for an id (the would-be backup source). */
	private static function originalPathFor( int $id ): string {
		$path = wp_get_original_image_path( $id, true );
		return is_string( $path ) ? $path : '';
	}

	// ---------------------------------------------------------------------
	// State / queue I/O.
	// ---------------------------------------------------------------------

	/**
	 * Read the durable run state, or null when absent/malformed.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function readState(): ?array {
		$raw = get_option( self::OPTION_STATE, null );
		return is_array( $raw ) ? $raw : null;
	}

	/**
	 * Read the durable queue, or null when absent/malformed.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function readQueue(): ?array {
		$raw = get_option( self::OPTION_QUEUE, null );
		return is_array( $raw ) ? $raw : null;
	}

	/** The run's lock token (its run identity), or '' when absent. */
	private static function tokenOf( array $state ): string {
		return ( isset( $state['lock'] ) && is_string( $state['lock'] ) ) ? $state['lock'] : '';
	}

	/** The active run's scope, or '' when no run is on record. */
	private static function scopeOf(): string {
		$state = self::readState();
		return ( null !== $state && isset( $state['scope'] ) ) ? (string) $state['scope'] : '';
	}

	/** The progress() shape for "no run on record". */
	private static function idleProgress(): array {
		return [
			'running'     => false,
			'scope'       => '',
			'total'       => 0,
			'done'        => 0,
			'failed'      => 0,
			'started_gmt' => '',
		];
	}

	// ---------------------------------------------------------------------
	// Small utilities.
	// ---------------------------------------------------------------------

	/**
	 * Build the run's Processor via the test seam (real {@see Processor} by
	 * default). Returns `object` so a test can inject a duck-typed fake — Processor
	 * is `final` and cannot be subclassed.
	 */
	private static function processor(): object {
		$factory = self::$processorFactory;
		if ( is_callable( $factory ) ) {
			return $factory();
		}

		return new Processor();
	}

	/**
	 * Whether a Processor outcome counts as a success. Duck-typed via isOk() so the
	 * real {@see Outcome} and an injected fake are treated identically.
	 *
	 * @param mixed $outcome The value returned by Processor::process().
	 */
	private static function outcomeIsOk( mixed $outcome ): bool {
		return is_object( $outcome ) && method_exists( $outcome, 'isOk' ) && $outcome->isOk();
	}

	/**
	 * Whether a host provides Action Scheduler's async-enqueue API. The test
	 * override ({@see $forceActionScheduler}) short-circuits the probe so the kick
	 * path is deterministic across sibling tests.
	 */
	private static function actionSchedulerAvailable(): bool {
		if ( null !== self::$forceActionScheduler ) {
			return self::$forceActionScheduler;
		}

		return function_exists( 'as_enqueue_async_action' );
	}

	/** Clamp a free-form scope to the supported set; default to 'flagged'. */
	private static function normalizeScope( string $scope ): string {
		return in_array( $scope, [ 'flagged', 'all', 'ids' ], true ) ? $scope : 'flagged';
	}

	/**
	 * Coerce a raw id list to positive, de-duplicated ints.
	 *
	 * @param array<int,int|string> $ids
	 * @return array<int,int>
	 */
	private static function sanitizeIds( array $ids ): array {
		$clean = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}

		return array_values( $clean );
	}
}
