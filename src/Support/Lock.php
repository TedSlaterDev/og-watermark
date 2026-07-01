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
 * OWNER-GUARD SCOPE: release()/refresh() do a read-then-act (wp_cache_get / a
 * get_option ownerState classify, then a set/delete) — a BEST-EFFORT owner check,
 * not an atomic compare-and-swap. The real safety property is the ATOMIC acquire
 * (wp_cache_add / add_option store-iff-absent); release/refresh are owner-checked
 * best-effort. Critically, the heartbeat must fail ONLY when it can POSITIVELY read
 * a DIFFERENT live owner — never merely because it cannot read its OWN row:
 *
 *  - Cache backend: a persistent object cache may evict (or simply fail to return)
 *    what add() stored, mid-request and well within the TTL.
 *  - Options backend: a host that wraps the options table in its own read cache
 *    (e.g. WordKeeper's "Speed > WP Options") can return a STALE MISS for the
 *    non-autoloaded lock row we just wrote.
 *
 * Either of those, treated as "lock lost", aborted every watermark with reason
 * "lock-lost" on real hosts. So both backends SELF-HEAL: a missing/own read
 * re-asserts ownership and continues; only a positively-read different owner (or,
 * on the options backend, a genuinely lapsed stored expiry) denies the heartbeat.
 * A single apply / a deduped bulk job has no competing writer on the same id, and
 * the immutable backup remains the real correctness gate, so re-asserting is safe.
 * In practice the Processor heartbeats well within the TTL, so the lapse window
 * does not open during a normal job.
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

			// A value is PRESENT: ours → heartbeat; a different token → genuinely lost
			// (another owner reclaimed the key after our TTL lapsed).
			if ( is_string( $cached ) && '' !== $cached ) {
				if ( ! hash_equals( $cached, $token ) ) {
					return false;
				}
				// Extend; do NOT gate on wp_cache_set()'s return — some drop-ins return
				// false spuriously (e.g. an unchanged value), which is not a lost lock.
				wp_cache_set( $key, $token, self::GROUP, $ttl );
				return true;
			}

			// MISSING (evicted, or a stale read). We must NOT blind-set here: if the key
			// truly lapsed, a concurrent worker's atomic acquire() may have taken it in
			// the gap, and a blind wp_cache_set() would stomp that new owner — leaving
			// TWO workers each believing they hold the lock, stamping+renaming the same
			// files concurrently. Re-claim with the store-iff-absent primitive instead
			// (the same compare-and-swap acquire() uses): a success means the key really
			// was free and we hold it again; a failure means someone else took it, so we
			// hold it ONLY if the present token is now ours.
			if ( wp_cache_add( $key, $token, self::GROUP, $ttl ) ) {
				return true;
			}
			$current = wp_cache_get( $key, self::GROUP );
			return is_string( $current ) && '' !== $current && hash_equals( $current, $token );
		}

		// Options backend. This host has no persistent object cache, but some managed
		// hosts wrap the OPTIONS table in their own read cache (e.g. WordKeeper's
		// "Speed > WP Options") that returns a STALE MISS for the non-autoloaded lock
		// row we just wrote — making it read as absent even though we still hold it. So
		// we fail ONLY on a positively-read different/expired owner.
		$state = self::ownerState( $key, $token );
		if ( 'own' === $state ) {
			self::writeOption( $key, $token, $ttl ); // heartbeat (ignore false-on-unchanged).
			return true;
		}
		if ( 'foreign' === $state || 'expired' === $state ) {
			// A different live owner (or a wrong-token caller), or a genuinely lapsed
			// TTL — a stalled worker must not resurrect a lock another may have taken.
			return false;
		}

		// 'absent'. Re-claim ATOMICALLY first — add_option()'s UNIQUE index is a
		// store-iff-absent, so a SUCCESS means the row was genuinely free and we now
		// re-hold it (no competitor can have raced in). A FAILURE means a row physically
		// exists in the DB; and because that atomic insert is IMMUNE to the host's stale
		// read cache, no competitor could have inserted over a present row — so a present
		// row during our OWN heartbeat is our own lock that the option cache merely
		// failed to return (the WordKeeper stale-miss), which we self-heal.
		if ( self::addOption( $key, $token, $ttl ) ) {
			return true;
		}
		self::writeOption( $key, $token, $ttl );
		return true;
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

		// Mirror refresh()'s tolerance: a host options-read cache can return a stale
		// MISS for our own row, which must NOT strand the lock. Refuse only when a
		// DIFFERENT live owner holds it; otherwise ensure the row is gone so the next
		// apply on this id is never blocked by a lingering lock.
		if ( 'foreign' === self::ownerState( $key, $token ) ) {
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
	 * Classify the options-backend lock for $token WITHOUT mutating it. Used by the
	 * owner-guarded refresh()/release() so a host options-read cache that returns a
	 * stale MISS for our own non-autoloaded row is not mistaken for a lost lock.
	 *
	 *  - 'own'     — a live row whose stored token is ours.
	 *  - 'foreign' — a live row owned by a DIFFERENT token (a genuine takeover, or a
	 *                wrong-token caller); the ONLY state that must deny refresh and
	 *                release.
	 *  - 'expired' — a row present but past its stored expiry (a true TTL lapse).
	 *  - 'absent'  — no readable row. On most hosts that means gone, but on a host
	 *                that caches option reads it can also be a STALE MISS of a row we
	 *                just wrote and still own, so the heartbeat treats it as "ours".
	 *
	 * @param string $key   Lock key.
	 * @param string $token Owner token to compare against.
	 * @return 'own'|'foreign'|'expired'|'absent'
	 */
	private static function ownerState( string $key, string $token ): string {
		$raw = get_option( $key, false );
		if ( false === $raw || ! is_array( $raw ) ) {
			return 'absent';
		}

		$stored  = isset( $raw['token'] ) && is_string( $raw['token'] ) ? $raw['token'] : '';
		$expires = isset( $raw['expires'] ) ? (int) $raw['expires'] : 0;

		if ( '' === $stored ) {
			return 'absent';
		}

		if ( $expires > 0 && self::now() >= $expires ) {
			return 'expired';
		}

		return hash_equals( $stored, $token ) ? 'own' : 'foreign';
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
