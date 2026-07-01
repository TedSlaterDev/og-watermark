<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Support;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Support\Lock;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Lock is an atomic, owner-token advisory lock with two interchangeable
 * backends, exactly one of which is authoritative per call (object cache when a
 * persistent cache is installed, the options table otherwise). The whole point
 * is that acquisition is ATOMIC, so the in-memory doubles here FAITHFULLY model
 * the atomic store-iff-absent primitives the real backends provide:
 *
 *  - wp_cache_add(): first add for a key succeeds; a second add for the same key
 *    returns false. (Compare-and-set on a persistent backend.)
 *  - add_option(): insert-iff-absent backed by the UNIQUE option_name index.
 *    First add succeeds; second returns false.
 *
 * Both backends are exercised: a persistent-cache group (wp_using_ext_object_cache
 * → true) drives the cache path, and a no-persistent-cache group (→ false) drives
 * the options fallback that every default single-server install actually uses.
 *
 * Expiry is tested by mutating the stored options payload's `expires` field into
 * the past rather than patching time(), which Patchwork refuses to redefine
 * without extra config — the assertion (a lapsed lock reads as free and is
 * reclaimable) is identical either way.
 */
final class LockTest extends TestCase {

	private const KEY = 'ogwm_lock_42';

	/** @var array<string,mixed> In-memory object-cache store: "group:key" => value. */
	private array $cache = [];

	/** @var array<string,mixed> In-memory options store: name => value. */
	private array $options = [];

	/** Whether the modeled install has a persistent object cache. */
	private bool $persistentCache = true;

	/** Sequential token source so each acquire() gets a distinct, assertable token. */
	private int $tokenSeq = 0;

	protected function setUp(): void {
		parent::setUp();

		// Distinct, predictable tokens per acquisition.
		Functions\when( 'wp_generate_password' )->alias(
			function () {
				return 'tok-' . str_pad( (string) ( ++$this->tokenSeq ), 4, '0', STR_PAD_LEFT );
			}
		);

		// Backend selection toggle.
		Functions\when( 'wp_using_ext_object_cache' )->alias( fn() => $this->persistentCache );

		// --- Object cache: atomic add (store-iff-absent), get/set/delete. ---
		Functions\when( 'wp_cache_add' )->alias(
			function ( $key, $value, $group = '', $ttl = 0 ) {
				$k = $group . ':' . $key;
				if ( array_key_exists( $k, $this->cache ) ) {
					return false; // Already present → atomic claim fails.
				}
				$this->cache[ $k ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->alias(
			function ( $key, $group = '' ) {
				$k = $group . ':' . $key;
				return array_key_exists( $k, $this->cache ) ? $this->cache[ $k ] : false;
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

		// --- Options table: atomic add (insert-iff-absent via UNIQUE index). ---
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
				if ( array_key_exists( $name, $this->options ) ) {
					return false; // UNIQUE option_name → second insert fails.
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
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

	// =====================================================================
	// Object-cache backend (persistent cache present).
	// =====================================================================

	public function testAcquireReturnsTokenThenContentionReturnsNull(): void {
		$token = Lock::acquire( self::KEY );
		$this->assertIsString( $token );
		$this->assertNotSame( '', $token );

		// Second acquire while held → null (atomic add fails).
		$this->assertNull( Lock::acquire( self::KEY ), 'a held lock must refuse a second acquirer' );

		// A DIFFERENT key is independent and still acquirable.
		$other = Lock::acquire( 'ogwm_lock_7' );
		$this->assertIsString( $other );
		$this->assertNotSame( $token, $other );
	}

	public function testHeldTracksAcquireAndRelease(): void {
		$this->assertFalse( Lock::held( self::KEY ) );

		$token = Lock::acquire( self::KEY );
		$this->assertTrue( Lock::held( self::KEY ) );

		$this->assertTrue( Lock::release( self::KEY, $token ) );
		$this->assertFalse( Lock::held( self::KEY ) );
	}

	public function testReleaseWithWrongTokenFailsAndKeepsLockHeld(): void {
		$token = Lock::acquire( self::KEY );
		$this->assertIsString( $token );

		$this->assertFalse( Lock::release( self::KEY, 'not-the-owner' ), 'wrong token must not release' );
		$this->assertTrue( Lock::held( self::KEY ), 'the lock must remain held after a wrong-token release' );

		// And a fresh acquirer is STILL refused, proving the lock truly survived.
		$this->assertNull( Lock::acquire( self::KEY ) );

		// The real owner can still release.
		$this->assertTrue( Lock::release( self::KEY, $token ) );
		$this->assertFalse( Lock::held( self::KEY ) );
	}

	public function testReleaseWithRightTokenSucceedsThenLockIsReacquirable(): void {
		$token = Lock::acquire( self::KEY );
		$this->assertTrue( Lock::release( self::KEY, $token ) );

		// Releasing again (now absent) is a no-op false.
		$this->assertFalse( Lock::release( self::KEY, $token ) );

		// After release the key is free to be claimed anew, with a NEW token.
		$token2 = Lock::acquire( self::KEY );
		$this->assertIsString( $token2 );
		$this->assertNotSame( $token, $token2 );
	}

	public function testRefreshExtendsOnlyWithMatchingToken(): void {
		$token = Lock::acquire( self::KEY, 300 );
		$this->assertIsString( $token );

		// A wrong token (while WE are the stored owner) cannot refresh.
		$this->assertFalse( Lock::refresh( self::KEY, 'someone-else', 300 ) );

		// Right token refreshes.
		$this->assertTrue( Lock::refresh( self::KEY, $token, 300 ) );
		// (Cache-backend self-heal when our value is evicted is its own test below.)
	}

	public function testCacheRefreshSelfHealsWhenTheBackendDroppedOurValue(): void {
		// Persistent-cache backend (the default for this group of tests).
		$token = Lock::acquire( self::KEY, 300 );
		$this->assertIsString( $token );

		// A flaky/evicting persistent object cache drops our value mid-request, well
		// within the TTL — the exact failure that aborted every watermark on a real
		// host with reason "lock-lost". With no competing owner, the heartbeat must
		// self-heal (re-assert our lock), NOT report a lost lock.
		$this->cache = [];
		$this->assertTrue( Lock::refresh( self::KEY, $token, 300 ), 'eviction with no competing owner must self-heal, not fail' );
		$this->assertTrue( Lock::held( self::KEY ), 'the re-asserted lock is held again' );

		// But a genuinely reclaimed key (a DIFFERENT live owner) must STILL fail the
		// original owner's heartbeat — the self-heal never steals another owner's lock.
		$this->cache = [];
		$other = Lock::acquire( self::KEY, 300 );
		$this->assertIsString( $other );
		$this->assertNotSame( $token, $other );
		$this->assertFalse( Lock::refresh( self::KEY, $token, 300 ), 'a different live owner means the lock is genuinely lost' );
	}

	public function testCacheRefreshDoesNotStompAConcurrentAcquirerAfterEviction(): void {
		$token = Lock::acquire( self::KEY, 300 );
		$this->assertIsString( $token );

		// The backend drops our value AND a concurrent worker atomically acquires the
		// now-free key in the gap. Our heartbeat must NOT blind-re-assert over the new
		// owner (the two-holders race that would let both stamp the same files) — it
		// must report the lock lost and leave the new owner intact.
		$this->cache = [];
		$other       = Lock::acquire( self::KEY, 300 );
		$this->assertIsString( $other );
		$this->assertNotSame( $token, $other );

		$this->assertFalse( Lock::refresh( self::KEY, $token, 300 ), 'must not stomp a concurrent acquirer' );
		$this->assertTrue( Lock::held( self::KEY ) );
		$this->assertTrue( Lock::refresh( self::KEY, $other, 300 ), 'the real owner still heartbeats' );
	}

	// =====================================================================
	// Options-table fallback (no persistent object cache).
	// =====================================================================

	public function testAcquireContentionReleaseRefreshOnOptionsFallback(): void {
		$this->persistentCache = false; // Force the add_option() claim path.

		$token = Lock::acquire( self::KEY, 300 );
		$this->assertIsString( $token );
		$this->assertArrayHasKey( self::KEY, $this->options, 'the lock must be claimed in the options table' );

		// Atomic options claim: the UNIQUE-index second insert fails → null.
		$this->assertNull( Lock::acquire( self::KEY, 300 ) );
		$this->assertTrue( Lock::held( self::KEY ) );

		// Owner-guarded refresh/release on the options backend.
		$this->assertFalse( Lock::refresh( self::KEY, 'wrong', 300 ) );
		$this->assertTrue( Lock::refresh( self::KEY, $token, 300 ) );

		$this->assertFalse( Lock::release( self::KEY, 'wrong' ) );
		$this->assertTrue( Lock::held( self::KEY ), 'wrong-token release must not free the options lock' );

		$this->assertTrue( Lock::release( self::KEY, $token ) );
		$this->assertFalse( Lock::held( self::KEY ) );
		$this->assertArrayNotHasKey( self::KEY, $this->options );
	}

	public function testCacheBackendNeverTouchesTheOptionsTable(): void {
		// With a persistent cache, the lock must live ONLY in the cache — the
		// options table must stay empty, or a key could be double-claimed.
		$token = Lock::acquire( self::KEY );
		$this->assertIsString( $token );
		$this->assertSame( [], $this->options, 'the cache backend must not write to options' );

		Lock::refresh( self::KEY, $token );
		Lock::release( self::KEY, $token );
		$this->assertSame( [], $this->options );
	}

	public function testExpiredOptionsLockIsReclaimedByANewAcquirer(): void {
		$this->persistentCache = false;

		$token = Lock::acquire( self::KEY, 60 );
		$this->assertIsString( $token );
		$this->assertTrue( Lock::held( self::KEY ) );

		// Force the stored lock into the past (TTL emulation lives in the payload).
		$this->expireOption( self::KEY );

		$this->assertFalse( Lock::held( self::KEY ), 'an expired options lock must read as not held' );

		// A new acquirer reclaims the lapsed key and gets a fresh, distinct token.
		$token2 = Lock::acquire( self::KEY, 60 );
		$this->assertIsString( $token2 );
		$this->assertNotSame( $token, $token2 );

		// The stale original owner can no longer release or refresh the new lock.
		$this->assertFalse( Lock::release( self::KEY, $token ) );
		$this->assertFalse( Lock::refresh( self::KEY, $token, 60 ) );
		$this->assertTrue( Lock::held( self::KEY ) );

		// The new owner controls it.
		$this->assertTrue( Lock::release( self::KEY, $token2 ) );
		$this->assertFalse( Lock::held( self::KEY ) );
	}

	public function testRefreshOnExpiredOptionsLockFailsForOriginalOwner(): void {
		$this->persistentCache = false;

		$token = Lock::acquire( self::KEY, 30 );
		$this->assertIsString( $token );

		$this->expireOption( self::KEY );
		$this->assertFalse( Lock::refresh( self::KEY, $token, 30 ), 'a lapsed lock cannot be heartbeated' );
	}

	public function testOptionsRefreshSelfHealsWhenHostOptionCacheReturnsAStaleMiss(): void {
		$this->persistentCache = false;

		$token = Lock::acquire( self::KEY, 300 );
		$this->assertIsString( $token );

		// Model a host options-read cache (e.g. WordKeeper's "Speed > WP Options")
		// returning a STALE MISS for the non-autoloaded lock row we just wrote: the
		// row reads as absent even though the TTL has not lapsed and we still hold it.
		// The strict pre-1.1.2 options heartbeat aborted here with "lock-lost"; the
		// self-healing heartbeat must re-assert ownership and continue instead.
		$this->options = [];
		$this->assertTrue( Lock::refresh( self::KEY, $token, 300 ), 'a stale options-read miss must self-heal, not fail' );
		$this->assertTrue( Lock::held( self::KEY ), 'the re-asserted options lock is held again' );

		// A genuinely different live owner must STILL lose the original owner's
		// heartbeat — the self-heal never steals another worker's lock.
		$this->options = [];
		$other = Lock::acquire( self::KEY, 300 );
		$this->assertIsString( $other );
		$this->assertNotSame( $token, $other );
		$this->assertFalse( Lock::refresh( self::KEY, $token, 300 ), 'a different live owner is a genuine loss' );
	}

	public function testOptionsRefreshDoesNotStompAConcurrentAcquirerAfterLapse(): void {
		$this->persistentCache = false;

		$token = Lock::acquire( self::KEY, 300 );
		$this->assertIsString( $token );

		// The row genuinely lapses/disappears and a competitor reclaims it. Our
		// heartbeat's atomic re-claim must FAIL (the row is now the competitor's), so
		// we report lost rather than overwriting their token.
		$this->options = [];
		$other         = Lock::acquire( self::KEY, 300 );
		$this->assertIsString( $other );
		$this->assertNotSame( $token, $other );

		$this->assertFalse( Lock::refresh( self::KEY, $token, 300 ), 'must not stomp the new owner' );
		$this->assertTrue( Lock::refresh( self::KEY, $other, 300 ) );
	}

	public function testOptionsReleaseClearsTheLockDespiteAStaleMiss(): void {
		$this->persistentCache = false;

		$token = Lock::acquire( self::KEY, 300 );
		$this->assertIsString( $token );

		// The host's options-read cache hides our own row (stale miss). release() must
		// still report success and guarantee the key is free, so a re-apply within the
		// TTL is never blocked by a lingering lock.
		$this->options = [];
		$this->assertTrue( Lock::release( self::KEY, $token ), 'release must tolerate a stale-miss of our own row' );
		$this->assertFalse( Lock::held( self::KEY ) );

		// But release must NEVER free a DIFFERENT live owner's lock.
		$token2 = Lock::acquire( self::KEY, 300 );
		$this->assertIsString( $token2 );
		$this->assertFalse( Lock::release( self::KEY, 'stale-other' ), 'a non-owner must not release a live lock' );
		$this->assertTrue( Lock::held( self::KEY ) );
	}

	public function testMalformedOptionsEntryIsTreatedAsFreeAndReclaimed(): void {
		$this->persistentCache = false;

		// A junk value sitting under the lock key (e.g. a manual edit) must not
		// wedge the lock forever: acquire() clears it and claims cleanly.
		$this->options[ self::KEY ] = 'not-a-lock-array';
		$this->assertFalse( Lock::held( self::KEY ) );

		$token = Lock::acquire( self::KEY, 60 );
		$this->assertIsString( $token );
		$this->assertTrue( Lock::held( self::KEY ) );
		$this->assertTrue( Lock::release( self::KEY, $token ) );
	}

	// =====================================================================
	// Helpers.
	// =====================================================================

	/** Rewrite the stored options lock so its expiry is in the past. */
	private function expireOption( string $key ): void {
		$raw = $this->options[ $key ] ?? null;
		$this->assertIsArray( $raw, 'expected a stored options lock to expire' );
		$raw['expires']        = time() - 1;
		$this->options[ $key ] = $raw;
	}
}
