<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Integration;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Integration\CachePurge;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Unit tests for the targeted per-attachment cache purge.
 *
 * The purge is best-effort and self-guarded: it must clear the core post cache,
 * always fire the `ogwm_purged_attachment` extension action with [id, urls],
 * short-circuit when `ogwm_auto_purge` is filtered false, call a host integration
 * ONLY when that host's function/action is present, and swallow a throw from any
 * single integration so the watermark run can never be broken by a cache plugin.
 *
 * Every WP/host function the purge touches is mocked through Brain Monkey, which
 * also makes function_exists()/has_action() see them as present — so defining a
 * mock is how we model "this host plugin is installed" and omitting one models
 * "absent".
 */
final class CachePurgeTest extends TestCase {

	/** @var array<int,array<string,mixed>> Recorded do_action() calls (hook + args). */
	private array $actions = [];

	/** @var array<int,int> Recorded clean_post_cache() ids. */
	private array $cleaned = [];

	/** @var array<string,bool> has_action() answers, keyed by hook name. */
	private array $hasAction = [];

	/** @var array<int,array<string,mixed>> Fake attachment metadata by id. */
	private array $attachmentMeta = [];

	/** @var array<int,string> wp_get_attachment_url() answers by id. */
	private array $attachmentUrl = [];

	protected function setUp(): void {
		parent::setUp();

		$this->actions        = [];
		$this->cleaned        = [];
		$this->hasAction      = [];
		$this->attachmentMeta = [];
		$this->attachmentUrl  = [];

		// A typical attachment: full URL + two registered sizes.
		$this->attachmentUrl[42]  = 'https://example.com/wp-content/uploads/2026/06/photo.jpg';
		$this->attachmentMeta[42] = [
			'file'  => '2026/06/photo.jpg',
			'sizes' => [
				'thumbnail' => [ 'file' => 'photo-150x150.jpg' ],
				'medium'    => [ 'file' => 'photo-300x200.jpg' ],
			],
		];

		// apply_filters defaults to returning the value arg (TestCase already stubs
		// this), so ogwm_auto_purge is "true" unless a test overrides it.

		Functions\when( 'clean_post_cache' )->alias(
			function ( $id ): void {
				$this->cleaned[] = (int) $id;
			}
		);

		Functions\when( 'wp_get_attachment_url' )->alias(
			fn( $id ) => $this->attachmentUrl[ (int) $id ] ?? false
		);
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			fn( $id, $unfiltered = false ) => $this->attachmentMeta[ (int) $id ] ?? false
		);

		Functions\when( 'has_action' )->alias(
			fn( $hook, $cb = false ) => $this->hasAction[ (string) $hook ] ?? false
		);
		Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ): void {
				$this->actions[] = [
					'hook' => (string) $hook,
					'args' => $args,
				];
			}
		);
	}

	// =====================================================================
	// Core purge + the extension action.
	// =====================================================================

	public function testClearsCorePostCacheForTheAttachment(): void {
		CachePurge::purgeAttachment( 42 );

		$this->assertSame( [ 42 ], $this->cleaned, 'clean_post_cache must be called once with the attachment id' );
	}

	public function testFiresPurgedAttachmentActionWithIdAndUrlSet(): void {
		CachePurge::purgeAttachment( 42 );

		$purged = $this->actionArgs( 'ogwm_purged_attachment' );
		$this->assertNotNull( $purged, 'the generic ogwm_purged_attachment action must fire' );

		[ $id, $urls ] = $purged;
		$this->assertSame( 42, $id, 'the action must carry the attachment id' );
		$this->assertSame(
			[
				'https://example.com/wp-content/uploads/2026/06/photo.jpg',
				'https://example.com/wp-content/uploads/2026/06/photo-150x150.jpg',
				'https://example.com/wp-content/uploads/2026/06/photo-300x200.jpg',
			],
			$urls,
			'the action must carry the full + every-size URL set (full first), deduped'
		);
	}

	public function testPurgedAttachmentActionFiresLastAfterTheBuiltins(): void {
		// SiteGround present so a built-in per-url action also fires; the generic
		// extension action must be the LAST recorded do_action.
		$this->hasAction['sg_cachepress_purge_cache'] = true;

		CachePurge::purgeAttachment( 42 );

		$last = end( $this->actions );
		$this->assertIsArray( $last );
		$this->assertSame(
			'ogwm_purged_attachment',
			$last['hook'],
			'ogwm_purged_attachment must fire LAST, after every built-in host purge'
		);
	}

	public function testFiresGenericPurgeUrlsActionForEdgePurgers(): void {
		CachePurge::purgeAttachment( 42 );

		$urls = $this->actionArgs( 'ogwm_purge_urls' );
		$this->assertNotNull( $urls, 'ogwm_purge_urls must fire so a site can wire its own CDN purge' );
		$this->assertSame(
			[
				'https://example.com/wp-content/uploads/2026/06/photo.jpg',
				'https://example.com/wp-content/uploads/2026/06/photo-150x150.jpg',
				'https://example.com/wp-content/uploads/2026/06/photo-300x200.jpg',
			],
			$urls[0],
			'ogwm_purge_urls must carry the attachment URL set'
		);
	}

	// =====================================================================
	// Opt-out filter.
	// =====================================================================

	public function testAutoPurgeFilterFalseShortCircuitsEverything(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value, ...$rest ) => 'ogwm_auto_purge' === $hook ? false : $value
		);

		CachePurge::purgeAttachment( 42 );

		$this->assertSame( [], $this->cleaned, 'a false ogwm_auto_purge must skip the core purge' );
		$this->assertSame( [], $this->actions, 'a false ogwm_auto_purge must fire NO actions at all' );
	}

	// =====================================================================
	// Host integrations fire ONLY when present.
	// =====================================================================

	public function testCallsWpRocketOnlyWhenPresent(): void {
		$calls = [];
		Functions\when( 'rocket_clean_post' )->alias(
			static function ( $id ) use ( &$calls ): void {
				$calls[] = (int) $id;
			}
		);

		CachePurge::purgeAttachment( 42 );

		$this->assertSame( [ 42 ], $calls, 'rocket_clean_post must be called with the id when WP Rocket is present' );
	}

	public function testCallsWpRocketPerUrlCleanWhenThatFunctionIsPresent(): void {
		// rocket_clean_files() is a plain FUNCTION (not an action), so the per-URL
		// path must CALL it with the attachment URL set — function_exists-gated.
		$rocketPost  = [];
		$rocketFiles = [];
		Functions\when( 'rocket_clean_post' )->alias(
			static function ( $id ) use ( &$rocketPost ): void {
				$rocketPost[] = (int) $id;
			}
		);
		Functions\when( 'rocket_clean_files' )->alias(
			static function ( $urls ) use ( &$rocketFiles ): void {
				$rocketFiles[] = $urls;
			}
		);

		CachePurge::purgeAttachment( 42 );

		$this->assertSame( [ 42 ], $rocketPost, 'rocket_clean_post must fire with the id' );
		$this->assertCount( 1, $rocketFiles, 'rocket_clean_files must be called once with the URL set' );
		$this->assertSame(
			[
				'https://example.com/wp-content/uploads/2026/06/photo.jpg',
				'https://example.com/wp-content/uploads/2026/06/photo-150x150.jpg',
				'https://example.com/wp-content/uploads/2026/06/photo-300x200.jpg',
			],
			$rocketFiles[0],
			'rocket_clean_files must receive the full + every-size URL set'
		);
	}

	public function testDoesNotCallWpRocketWhenAbsent(): void {
		// rocket_clean_post is NOT defined → function_exists() is false. The run must
		// still complete (core purge + extension action fire) without fataling.
		CachePurge::purgeAttachment( 42 );

		$this->assertSame( [ 42 ], $this->cleaned );
		$this->assertNotNull( $this->actionArgs( 'ogwm_purged_attachment' ), 'the run must finish even with no host plugins present' );
	}

	public function testCallsW3tcOnlyWhenPresent(): void {
		$calls = [];
		Functions\when( 'w3tc_flush_post' )->alias(
			static function ( $id ) use ( &$calls ): void {
				$calls[] = (int) $id;
			}
		);

		CachePurge::purgeAttachment( 42 );

		$this->assertSame( [ 42 ], $calls, 'w3tc_flush_post must be called with the id when W3TC is present' );
	}

	public function testCallsLiteSpeedPerPostAndPerUrlWhenItsActionsArePresent(): void {
		$this->hasAction['litespeed_purge_post'] = true;
		$this->hasAction['litespeed_purge_url']  = true;

		CachePurge::purgeAttachment( 42 );

		$post = $this->actionArgs( 'litespeed_purge_post' );
		$this->assertSame( [ 42 ], $post, 'litespeed_purge_post must fire with the attachment id' );

		$perUrl = $this->allActionArgs( 'litespeed_purge_url' );
		$this->assertCount( 3, $perUrl, 'litespeed_purge_url must fire once per attachment URL (full + 2 sizes)' );
		$this->assertSame( 'https://example.com/wp-content/uploads/2026/06/photo.jpg', $perUrl[0][0] );
	}

	public function testDoesNotFireLiteSpeedActionsWhenAbsent(): void {
		// has_action returns false for the litespeed hooks (default), so neither
		// litespeed_purge_post nor litespeed_purge_url should be dispatched.
		CachePurge::purgeAttachment( 42 );

		$this->assertNull( $this->actionArgs( 'litespeed_purge_post' ) );
		$this->assertNull( $this->actionArgs( 'litespeed_purge_url' ) );
	}

	public function testCallsSiteGroundPerUrlOnlyWhenItsActionIsPresent(): void {
		$this->hasAction['sg_cachepress_purge_cache'] = true;

		CachePurge::purgeAttachment( 42 );

		$sg = $this->allActionArgs( 'sg_cachepress_purge_cache' );
		$this->assertCount( 3, $sg, 'SiteGround purge must fire once per attachment URL — never a site-wide flush' );
		$this->assertSame( 'https://example.com/wp-content/uploads/2026/06/photo.jpg', $sg[0][0] );
	}

	public function testDoesNotFireSiteGroundActionWhenAbsent(): void {
		CachePurge::purgeAttachment( 42 );

		$this->assertNull( $this->actionArgs( 'sg_cachepress_purge_cache' ) );
	}

	// =====================================================================
	// Robustness — a throwing integration is swallowed.
	// =====================================================================

	public function testAThrowingIntegrationDoesNotPropagateAndOthersStillRun(): void {
		// A broken WP Rocket whose clean throws. The purge must swallow it AND still
		// run the remaining integrations (core purge + the generic extension action).
		Functions\when( 'rocket_clean_post' )->alias(
			static function (): void {
				throw new \RuntimeException( 'rocket exploded' );
			}
		);

		CachePurge::purgeAttachment( 42 );

		$this->assertSame( [ 42 ], $this->cleaned, 'core purge must still run after a broken host plugin throws' );
		$this->assertNotNull(
			$this->actionArgs( 'ogwm_purged_attachment' ),
			'the generic extension action must still fire after a host integration throws'
		);
	}

	public function testThrowingCorePurgeIsSwallowed(): void {
		Functions\when( 'clean_post_cache' )->alias(
			static function (): void {
				throw new \RuntimeException( 'core cache exploded' );
			}
		);

		// Must not throw out of purgeAttachment — a watermark must never fail here.
		CachePurge::purgeAttachment( 42 );

		$this->assertNotNull(
			$this->actionArgs( 'ogwm_purged_attachment' ),
			'a throwing core purge must be isolated so the rest still runs'
		);
	}

	public function testNonPositiveIdIsANoOp(): void {
		CachePurge::purgeAttachment( 0 );

		$this->assertSame( [], $this->cleaned );
		$this->assertSame( [], $this->actions );
	}

	// =====================================================================
	// URL set degrades gracefully when the base URL can't be resolved.
	// =====================================================================

	public function testEmptyUrlSetStillPurgesCoreAndSkipsPerUrlHosts(): void {
		// No URL for id 99 → wp_get_attachment_url returns false. Core purge + the
		// id-only hosts still run; the per-URL actions must NOT fire on an empty set.
		$this->hasAction['sg_cachepress_purge_cache'] = true;
		$this->hasAction['litespeed_purge_post']      = true;
		$this->hasAction['litespeed_purge_url']       = true;

		CachePurge::purgeAttachment( 99 );

		$this->assertSame( [ 99 ], $this->cleaned, 'core purge runs even with no resolvable URLs' );
		$this->assertNull( $this->actionArgs( 'sg_cachepress_purge_cache' ), 'no per-url SiteGround purge on an empty url set' );
		$this->assertNull( $this->actionArgs( 'litespeed_purge_url' ), 'no per-url LiteSpeed purge on an empty url set' );
		$this->assertNull( $this->actionArgs( 'ogwm_purge_urls' ), 'no ogwm_purge_urls on an empty url set' );

		// The id-carrying LiteSpeed post purge still fires (it does not need URLs).
		$this->assertSame( [ 99 ], $this->actionArgs( 'litespeed_purge_post' ) );
	}

	// =====================================================================
	// Helpers.
	// =====================================================================

	/**
	 * The args of the FIRST recorded do_action() for $hook, or null if it never
	 * fired.
	 *
	 * @return array<int,mixed>|null
	 */
	private function actionArgs( string $hook ): ?array {
		foreach ( $this->actions as $action ) {
			if ( $hook === $action['hook'] ) {
				return $action['args'];
			}
		}
		return null;
	}

	/**
	 * The args of EVERY recorded do_action() for $hook, in fire order.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function allActionArgs( string $hook ): array {
		$out = [];
		foreach ( $this->actions as $action ) {
			if ( $hook === $action['hook'] ) {
				$out[] = $action['args'];
			}
		}
		return $out;
	}
}
