<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic, owner-token per-key advisory lock.
 *
 * The processor uses one lock per attachment ('ogwm_lock_' . $id) so a manual
 * apply, a bulk-queue worker, and a metadata-regeneration hook can never stamp
 * the same image concurrently and stack watermarks. Correctness here rests on a
 * single property: acquisition is ATOMIC. We NEVER read-then-write to decide if
 * a lock is free — that race is exactly what a lock must prevent.
 *
 * Exactly ONE backend is authoritative per call, chosen by whether a persistent
 * object cache is installed:
 *
 *  1. Persistent object cache present → the cache backend.
 *     {@see wp_cache_add()} is a compare-and-set: it stores the value ONLY if
 *     the key is absent and reports whether it did, so the first caller wins and
 *     every later caller gets false — atomically, with no separate existence
 *     check. It also carries a native TTL. (A NON-persistent object cache is a
 *     per-request array invisible to concurrent PHP workers, so it cannot lock
 *     anything; we fall through to the database for those hosts.)
 *
 *  2. No persistent object cache → the options table.
 *     {@see add_option()} relies on the UNIQUE index on `option_name`: the
 *     INSERT for the second writer fails, so add_option() returns false. That is
 *     an atomic claim that holds even on the default single-server install. The
 *     options backend has no TTL, so we store an expiry alongside the token and
 *     treat a lapsed entry as free (reclaiming it on the next acquire()).
 *
 * Selecting a single backend per call is deliberate: trying the cache and THEN
 * the database would let the same key be "claimed" twice (once per store),
 * defeating the lock. On every install one backend is authoritative and atomic.
 *
 * Release and refresh are owner-guarded: a token is minted per acquisition, and
 * release/refresh act ONLY when the stored token still matches — so a worker
 * that overran its TTL (and whose lock was reclaimed by another) can never
 * delete or extend the new owner's lock.
 *
 * OWNER-GUARD SCOPE: on the cache backend release()/refresh() do a wp_cache_get
 * then a wp_cache_delete/wp_cache_set — a BEST-EFFORT owner check, not an atomic
 * compare-and-swap. A vanishingly small window exists where, AFTER this worker's
 * TTL has lapsed and another worker has reclaimed the key, this worker's get could
 * see the new owner's token (it would not — tokens differ — so the guard still
 * holds) or, more precisely, the get/act pair is not transactional. The real
 * safety property is the ATOMIC acquire (wp_cache_add / add_option store-iff-
 * absent); release/refresh on the cache backend are owner-checked best-effort. The
 * options backend reads a stored expiry so a lapsed entry is already treated as
 * free. In practice the Processor heartbeats well within the TTL, so the lapse
 * window does not open during a normal job.
 */
final class Lock {

	/** Object-cache group for the lock keys. */
	private const GROUP = 'ogwm-lock';

	/** Default lock lifetime in seconds. */
	private const DEFAULT_TTL = 300;

	/**
	 * Attempt to claim the lock for $key. Returns a fresh owner token on success,
	 * or null when the lock is already held by someone else.
	 *
	 * Atomic by construction: the chosen backend's store-iff-absent primitive
	 * (wp_cache_add / add_option) reports whether THIS call created the entry.
	 * There is deliberately NO get-then-set here.
	 *
	 * @param string $key Lock key (e.g. 'ogwm_lock_' . $attachmentId).
	 * @param int    $ttl Lifetime in seconds before the lock may be reclaimed.
	 * @return string|null Owner token to pass to refresh()/release(), or null.
	 */
	public static function acquire( string $key, int $ttl = self::DEFAULT_TTL ): ?string {
		$ttl   = self::normalizeTtl( $ttl );
		$token = self::mintToken();

		if ( self::usesCache() ) {
			// Object-cache claim: add() is atomic store-iff-absent on a persistent
			// backend, and carries its own TTL.
			return wp_cache_add( $key, $token, self::GROUP, $ttl ) ? $token : null;
		}

		// Options-table claim: the UNIQUE option_name index makes add_option() an
		// atomic insert-iff-absent. We carry an explicit expiry (no native TTL).
		if ( self::addOption( $key, $token, $ttl ) ) {
			return $token;
		}

		// Present already. If it is an EXPIRED (or malformed) entry, clear it and
		// retry the atomic insert; a still-live lock stays put and we return null.
		if ( self::reclaimExpiredOption( $key ) && self::addOption( $key, $token, $ttl ) ) {
			return $token;
		}

		return null;
	}

	/**
	 * Heartbeat: extend the lock's lifetime, but ONLY if $token is still the
	 * stored owner. A non-owner (or a lapsed lock since reclaimed) gets false and
	 * MUST treat its lock as lost.
	 *
	 * @param string $key   Lock key.
	 * @param string $token Token returned by acquire().
	 * @param int    $ttl   New lifetime in seconds from now.
	 * @return bool True when this owner's lock was extended.
	 */
	public static function refresh( string $key, string $token, int $ttl = self::DEFAULT_TTL ): bool {
		$ttl = self::normalizeTtl( $ttl );

		if ( self::usesCache() ) {
			$cached = wp_cache_get( $key, self::GROUP );
			// A DIFFERENT, present token is the ONLY genuine "lost lock": another
			// owner reclaimed the key after our TTL lapsed. A MISSING value is NOT a
			// lost lock — some persistent object caches evict (or simply fail to
			// return) what add() stored, mid-request and well within the TTL. Observed
			// in the wild: that produced spurious heartbeat failures that aborted every
			// watermark with reason "lock-lost". A worker that truly overran the TTL
			// would not be calling refresh() at all, so a missing value here means
			// eviction, not expiry — we self-heal by re-asserting our ownership and
			// continue. This never overrides a different live owner (rejected above),
			// and a single apply / a deduped bulk job has no competing writer on the
			// same id, so the immutable backup remains the real correctness gate.
			if ( is_string( $cached ) && '' !== $cached && ! hash_equals( $cached, $token ) ) {
				return false;
			}
			return (bool) wp_cache_set( $key, $token, self::GROUP, $ttl );
		}

		// The options backend stores an explicit expiry, so it keeps strict semantics:
		// a lapsed or reclaimed lock (readOption() === null) cannot be heartbeated.
		$stored = self::readOption( $key );
		if ( null === $stored || ! hash_equals( $stored['token'], $token ) ) {
			return false;
		}

		return self::writeOption( $key, $token, $ttl );
	}

	/**
	 * Compare-and-delete: release the lock ONLY when $token is the stored owner.
	 * Releasing with the wrong token is a no-op that returns false, so a stale
	 * worker can never free the current owner's lock.
	 *
	 * @param string $key   Lock key.
	 * @param string $token Token returned by acquire().
	 * @return bool True when this owner's lock was deleted.
	 */
	public static function release( string $key, string $token ): bool {
		if ( self::usesCache() ) {
			$cached = wp_cache_get( $key, self::GROUP );
			if ( ! is_string( $cached ) || ! hash_equals( $cached, $token ) ) {
				return false;
			}
			wp_cache_delete( $key, self::GROUP );
			return true;
		}

		$stored = self::readOption( $key );
		if ( null === $stored || ! hash_equals( $stored['token'], $token ) ) {
			return false;
		}

		delete_option( $key );
		return true;
	}

	/**
	 * Whether the lock is currently held by anyone (regardless of owner). An
	 * expired options entry counts as NOT held.
	 *
	 * @param string $key Lock key.
	 * @return bool
	 */
	public static function held( string $key ): bool {
		if ( self::usesCache() ) {
			return false !== wp_cache_get( $key, self::GROUP );
		}

		return null !== self::readOption( $key );
	}

	// ---------------------------------------------------------------------
	// Options-backend helpers (TTL emulated via a stored expiry timestamp).
	// ---------------------------------------------------------------------

	/**
	 * Atomically insert the option lock. add_option() fails (returns false) when
	 * the option already exists, which is the atomic claim we rely on. Autoload
	 * is forced off so transient locks never bloat the always-loaded options.
	 *
	 * @param string $key   Lock key.
	 * @param string $token Owner token.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return bool True only when THIS call created the option.
	 */
	private static function addOption( string $key, string $token, int $ttl ): bool {
		return (bool) add_option( $key, self::encode( $token, $ttl ), '', 'no' );
	}

	/**
	 * Overwrite an option lock we already own (refresh path only).
	 *
	 * @param string $key   Lock key.
	 * @param string $token Owner token.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return bool
	 */
	private static function writeOption( string $key, string $token, int $ttl ): bool {
		return (bool) update_option( $key, self::encode( $token, $ttl ), 'no' );
	}

	/**
	 * Read and validate the stored options lock, honoring its expiry.
	 *
	 * @param string $key Lock key.
	 * @return array{token:string,expires:int}|null Live lock, or null when absent
	 *                                               or expired.
	 */
	private static function readOption( string $key ): ?array {
		$raw = get_option( $key, false );
		if ( false === $raw || ! is_array( $raw ) ) {
			return null;
		}

		$token   = isset( $raw['token'] ) && is_string( $raw['token'] ) ? $raw['token'] : '';
		$expires = isset( $raw['expires'] ) ? (int) $raw['expires'] : 0;

		if ( '' === $token ) {
			return null;
		}

		if ( $expires > 0 && self::now() >= $expires ) {
			return null; // Lapsed: treat as free.
		}

		return [
			'token'   => $token,
			'expires' => $expires,
		];
	}

	/**
	 * Delete a stored options lock IFF it is NOT currently a live, well-formed
	 * lock, so acquire() can reclaim the key. Returns true when the key is free
	 * afterwards (cleared now). A still-live lock returns false, untouched.
	 *
	 * @param string $key Lock key.
	 * @return bool
	 */
	private static function reclaimExpiredOption( string $key ): bool {
		// A live lock must be preserved; anything else present (expired or
		// malformed) is stale and safe to clear.
		if ( null !== self::readOption( $key ) ) {
			return false;
		}

		delete_option( $key );
		return true;
	}

	/**
	 * Encode the options-backend payload: owner token plus an absolute expiry.
	 *
	 * @param string $token Owner token.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return array{token:string,expires:int}
	 */
	private static function encode( string $token, int $ttl ): array {
		return [
			'token'   => $token,
			'expires' => self::now() + $ttl,
		];
	}

	// ---------------------------------------------------------------------
	// Primitives.
	// ---------------------------------------------------------------------

	/**
	 * Whether a PERSISTENT object cache backs this install. A non-persistent
	 * cache is a per-request array, useless for cross-process locking, so those
	 * hosts use the options table instead.
	 */
	private static function usesCache(): bool {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
	}

	/** Unguessable per-acquisition owner token. */
	private static function mintToken(): string {
		if ( function_exists( 'wp_generate_password' ) ) {
			return (string) wp_generate_password( 20, false, false );
		}

		return bin2hex( random_bytes( 10 ) );
	}

	/** Current Unix time; isolated so tests can pin it deterministically. */
	private static function now(): int {
		return time();
	}

	/** Clamp the TTL to a sane positive floor so a lock can never be born expired. */
	private static function normalizeTtl( int $ttl ): int {
		return $ttl > 0 ? $ttl : self::DEFAULT_TTL;
	}
}
