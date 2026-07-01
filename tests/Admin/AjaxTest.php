<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Admin;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Admin\Ajax;
use OrchardGrove\OgWatermark\Pipeline\Outcome;
use OrchardGrove\OgWatermark\Settings\Options;
use OrchardGrove\OgWatermark\Support\Capabilities;
use OrchardGrove\OgWatermark\Support\Meta;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Security-first unit tests for the admin-AJAX layer.
 *
 * The crux (per the SPEC's "Security checklist" + the milestone's TEST QUALITY
 * BAR): EVERY handler enforces validate-id → capability → nonce IN THAT ORDER.
 * The tests prove each gate independently AND its ordering:
 *
 *  - a wrong/missing nonce is rejected (even for an authorized user);
 *  - a user without edit_post on the SUBMITTED id (id 0 / another author's
 *    attachment) is rejected = the IDOR guard;
 *  - the manage_options-gated handlers (bulk start/progress, preview) reject a
 *    non-admin;
 *  - ogwm_apply_single actually invokes the Processor and returns an ESCAPED
 *    badge fragment;
 *  - ogwm_preview runs the REAL Sanitizer on the POST, rate-limits a second rapid
 *    call, and produces a non-empty image (GD is present) WITHOUT leaking a
 *    server path on error.
 *
 * wp_send_json_success/error normally die(); we stub them to THROW a typed
 * carrier ({@see JsonResponse}) so each handler's terminal branch is observable
 * and the die-semantics (no code runs after a guard fails) are preserved.
 */
final class AjaxTest extends TestCase {

	/** @var array<string,mixed> In-memory options table. */
	private array $options = [];

	/** @var array<int,array<string,mixed>> In-memory post meta, keyed by id. */
	private array $meta = [];

	/** @var array<string,mixed> In-memory transients (preview rate-limit). */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();

		$_POST = [];

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ] = $value;
				return true;
			}
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
		Functions\when( 'get_post_meta' )->alias(
			fn( $id, $key, $single = false ) => $this->meta[ (int) $id ][ $key ] ?? ''
		);
		Functions\when( 'get_transient' )->alias(
			fn( $key ) => $this->transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl = 0 ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'wp_kses' )->alias( static fn( $html, $allowed = [] ) => $html );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( (string) $format, (int) ( $ts ?? time() ) ) );

		// wp_send_json_* die() in WP; throw a typed carrier so the handler's terminal
		// branch + die-semantics are observable in a test.
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ): void {
				throw new JsonResponse( true, is_array( $data ) ? $data : [] );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null ): void {
				throw new JsonResponse( false, is_array( $data ) ? $data : [] );
			}
		);

		Ajax::$processorFactory = null;
		$this->resetCapabilitiesCache();
	}

	protected function tearDown(): void {
		Ajax::$processorFactory = null;
		$this->options          = [];
		$this->meta             = [];
		$this->transients       = [];
		$_POST                  = [];
		$this->resetCapabilitiesCache();
		parent::tearDown();
	}

	// =====================================================================
	// register() wires every wp_ajax_ handler.
	// =====================================================================

	public function testRegisterAddsEveryAjaxHandler(): void {
		$hooks = [];
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $cb ) use ( &$hooks ): void {
				$hooks[ $hook ] = $cb;
			}
		);

		Ajax::register();

		$this->assertArrayHasKey( 'wp_ajax_ogwm_apply_single', $hooks );
		$this->assertArrayHasKey( 'wp_ajax_ogwm_remove_single', $hooks );
		$this->assertArrayHasKey( 'wp_ajax_ogwm_bulk_start', $hooks );
		$this->assertArrayHasKey( 'wp_ajax_ogwm_bulk_progress', $hooks );
		$this->assertArrayHasKey( 'wp_ajax_ogwm_preview', $hooks );
		$this->assertArrayHasKey( 'wp_ajax_ogwm_redetect_engine', $hooks );
	}

	// =====================================================================
	// ORDERING + IDOR: apply_single validates id → edit_post($id) → nonce.
	// =====================================================================

	public function testApplySingleRejectsInvalidIdBeforeAnythingElse(): void {
		// id 0: not a real attachment. The id-validation gate must fire first, and
		// NEITHER current_user_can NOR check_ajax_referer should be reached.
		$_POST = [ 'id' => 0 ];

		Functions\when( 'get_post_type' )->justReturn( false );
		Functions\when( 'wp_attachment_is_image' )->justReturn( false );
		Functions\expect( 'current_user_can' )->never();
		Functions\expect( 'check_ajax_referer' )->never();

		$resp = $this->capture( [ Ajax::class, 'applySingle' ] );

		$this->assertFalse( $resp->success );
		$this->assertSame( 'invalid-attachment', $resp->data['reason'] );
	}

	public function testApplySingleIdorRejectsAnothersAttachmentAtTheCapGate(): void {
		// A VALID image id this user does NOT own. Id validation passes; the per-object
		// edit_post($id) cap must reject — this is the IDOR guard. The nonce check must
		// NOT be reached (cap is checked before nonce).
		$_POST = [ 'id' => 999 ];

		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'edit_post', 999 )
			->andReturn( false );
		Functions\expect( 'check_ajax_referer' )->never();

		$resp = $this->capture( [ Ajax::class, 'applySingle' ] );

		$this->assertFalse( $resp->success );
		$this->assertSame( 'forbidden', $resp->data['reason'] );
	}

	public function testApplySingleRejectsBadNonceEvenForAnAuthorizedUser(): void {
		// Authorized user (edit_post true), but a wrong/missing nonce → rejected. The
		// nonce gate is the LAST gate and must still hard-fail.
		$_POST = [ 'id' => 42 ];

		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'ogwm_admin', 'nonce', false )
			->andReturn( false );

		// The Processor must NEVER run when the nonce is bad.
		Ajax::$processorFactory = function () {
			$this->fail( 'the Processor must not run when the nonce check fails' );
		};

		$resp = $this->capture( [ Ajax::class, 'applySingle' ] );

		$this->assertFalse( $resp->success );
		$this->assertSame( 'bad-nonce', $resp->data['reason'] );
	}

	// =====================================================================
	// apply_single happy path: flags, invokes the Processor, escaped badge.
	// =====================================================================

	public function testApplySingleFlagsInvokesProcessorAndReturnsEscapedBadge(): void {
		$_POST = [ 'id' => 42 ];

		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		$seen = (object) [ 'id' => 0, 'context' => '' ];
		Ajax::$processorFactory = function () use ( $seen ) {
			return new class( $seen, $this ) {
				private object $seen;
				private object $test;
				public function __construct( object $seen, object $test ) {
					$this->seen = $seen;
					$this->test = $test;
				}
				public function process( int $id, string $context ): Outcome {
					$this->seen->id      = $id;
					$this->seen->context = $context;
					// The handler must set the opt-in flag BEFORE running the Processor.
					$this->test->assertEnabledFlagWasSet( $id );
					return Outcome::watermarked( [ 'full' => time() ] );
				}
			};
		};

		// Make StatusBadge resolve to a watermarked pill (status set by our fake too).
		$this->meta[42][ Meta::STATUS ]      = Meta::STATUS_WATERMARKED;
		$this->meta[42][ Meta::APPLIED_GMT ] = '2026-06-29T00:00:00+00:00';

		$resp = $this->capture( [ Ajax::class, 'applySingle' ] );

		$this->assertTrue( $resp->success );
		$this->assertSame( 42, $seen->id, 'the Processor must be invoked with the submitted id' );
		$this->assertSame( 'flag', $seen->context, 'apply_single runs in the flag context' );

		// The badge is a single <span> fragment (escaped, wp_kses-bounded).
		$this->assertStringContainsString( '<span class="ogwm-badge', $resp->data['badge_html'] );
		$this->assertStringNotContainsString( '<script', $resp->data['badge_html'] );
		$this->assertArrayHasKey( 'message', $resp->data );
		$this->assertSame( Outcome::WATERMARKED, $resp->data['status'] );
	}

	/** Helper asserted from inside the fake Processor: the opt-in flag is set first. */
	public function assertEnabledFlagWasSet( int $id ): void {
		$this->assertSame( '1', $this->meta[ $id ][ Meta::ENABLED ] ?? null, 'apply_single must set _ogwm_enabled before processing' );
	}

	// =====================================================================
	// remove_single: same gate order; invokes restore().
	// =====================================================================

	public function testRemoveSingleIdorRejectsAnothersAttachment(): void {
		$_POST = [ 'id' => 555 ];

		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'edit_post', 555 )->andReturn( false );
		Functions\expect( 'check_ajax_referer' )->never();

		Ajax::$processorFactory = function () {
			$this->fail( 'restore must not run for an unauthorized id' );
		};

		$resp = $this->capture( [ Ajax::class, 'removeSingle' ] );

		$this->assertFalse( $resp->success );
		$this->assertSame( 'forbidden', $resp->data['reason'] );
	}

	public function testRemoveSingleInvokesRestoreAndReturnsBadge(): void {
		$_POST = [ 'id' => 42 ];

		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		$called = (object) [ 'restore' => false ];
		Ajax::$processorFactory = static function () use ( $called ) {
			return new class( $called ) {
				private object $called;
				public function __construct( object $called ) {
					$this->called = $called;
				}
				public function restore( int $id ): Outcome {
					$this->called->restore = true;
					return Outcome::restored();
				}
			};
		};

		$resp = $this->capture( [ Ajax::class, 'removeSingle' ] );

		$this->assertTrue( $resp->success );
		$this->assertTrue( $called->restore, 'remove_single must invoke Processor::restore' );
		$this->assertStringContainsString( '<span class="ogwm-badge', $resp->data['badge_html'] );
		$this->assertSame( Outcome::RESTORED, $resp->data['status'] );
	}

	// =====================================================================
	// manage_options gate: bulk start/progress + preview reject a non-admin.
	// =====================================================================

	public function testBulkStartRejectsNonAdminBeforeNonce(): void {
		$_POST = [ 'scope' => 'flagged' ];

		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		Functions\expect( 'check_ajax_referer' )->never();

		$resp = $this->capture( [ Ajax::class, 'bulkStart' ] );

		$this->assertFalse( $resp->success );
		$this->assertSame( 'forbidden', $resp->data['reason'] );
	}

	public function testBulkProgressRejectsNonAdmin(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		Functions\expect( 'check_ajax_referer' )->never();

		$resp = $this->capture( [ Ajax::class, 'bulkProgress' ] );

		$this->assertFalse( $resp->success );
		$this->assertSame( 'forbidden', $resp->data['reason'] );
	}

	public function testPreviewRejectsNonAdminBeforeNonce(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		Functions\expect( 'check_ajax_referer' )->never();

		$resp = $this->capture( [ Ajax::class, 'preview' ] );

		$this->assertFalse( $resp->success );
		$this->assertSame( 'forbidden', $resp->data['reason'] );
	}

	public function testBulkProgressIsReadOnlyAndReturnsCounters(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		// A live run snapshot in the bulk-state option; progress() is a pure read.
		$this->options['ogwm_bulk_state'] = [
			'running'     => true,
			'scope'       => 'flagged',
			'total'       => 10,
			'done'        => 3,
			'failed'      => 1,
			'started_gmt' => '2026-06-29T00:00:00+00:00',
		];

		$resp = $this->capture( [ Ajax::class, 'bulkProgress' ] );

		$this->assertTrue( $resp->success );
		$this->assertTrue( $resp->data['running'] );
		$this->assertSame( 10, $resp->data['total'] );
		$this->assertSame( 3, $resp->data['done'] );
		$this->assertSame( 1, $resp->data['failed'] );
	}

	// =====================================================================
	// preview: real Sanitizer on the POST, rate-limit, real GD image, no path leak.
	// =====================================================================

	public function testPreviewRunsRealEngineProducesNonEmptyImage(): void {
		$this->primeAdminPreviewEnv();

		// A text-mode preview (GD + FreeType present in this environment). The POST is
		// the unsaved settings shape; Preview::render runs it through Sanitizer first.
		$_POST = [
			'settings' => [
				'watermark' => [
					'type'           => 'text',
					'text'           => '{copy} {year} Example',
					'text_color'     => '#ffffff',
					'treatment'      => 'stroke',
					'treatment_color' => '#000000',
					'text_scale_pct' => 6,
				],
				'placement' => [ 'position' => 'bot-right', 'margin_pct' => 4 ],
				'sizing'    => [ 'opacity' => 80 ],
			],
		];

		$resp = $this->capture( [ Ajax::class, 'preview' ] );

		$this->assertTrue( $resp->success, 'a real GD render should succeed in this environment' );
		$this->assertArrayHasKey( 'img_url', $resp->data );
		$this->assertStringStartsWith( 'data:image/png;base64,', $resp->data['img_url'] );

		// Decode the data URL and prove it is a non-empty, real PNG.
		$b64   = substr( $resp->data['img_url'], strlen( 'data:image/png;base64,' ) );
		$bytes = base64_decode( $b64, true );
		$this->assertNotFalse( $bytes );
		$this->assertNotEmpty( $bytes );
		$info = getimagesizefromstring( (string) $bytes );
		$this->assertIsArray( $info, 'the preview output must be a decodable image' );
		$this->assertSame( IMAGETYPE_PNG, $info[2] );
	}

	public function testPreviewSanitizesAHostilePostThroughTheRealSanitizer(): void {
		$this->primeAdminPreviewEnv();

		// A hostile/out-of-range POST: an XSS-y color, a junk treatment, an absurd
		// scale, a path-traversal position, and a negative margin. The REAL Sanitizer
		// must clamp/whitelist EVERY one of these before the engine sees them. If the
		// raw values reached the engine, an invalid color/position would error out (or
		// worse). That the render SUCCEEDS and emits a real PNG proves the production
		// sanitize callback ran on the POST first and coerced everything to a valid,
		// renderable shape. The text stays short so the only variable under test is
		// the non-text field sanitization.
		$_POST = [
			'settings' => [
				'watermark' => [
					'type'           => 'text',
					'text'           => 'Hi',
					'text_color'     => 'javascript:alert(1)', // Invalid → default #ffffff.
					'treatment'      => 'not-a-treatment',      // Invalid → default stroke.
					'text_scale_pct' => 9999,                   // Out of range → clamped to 30.
				],
				'placement' => [ 'position' => '../../etc', 'margin_pct' => -50 ], // → bot-right, 0.
			],
		];

		$resp = $this->capture( [ Ajax::class, 'preview' ] );

		// The hostile input did not crash the engine — it was sanitized to a valid,
		// renderable settings array, proving Sanitizer ran on the POST first.
		$this->assertTrue( $resp->success );
		$this->assertStringStartsWith( 'data:image/png;base64,', $resp->data['img_url'] );
	}

	public function testPreviewCapsAGlyphBombWithoutCrashing(): void {
		$this->primeAdminPreviewEnv();

		// A megabyte of glyphs MUST NOT pin the CPU or fatal: Preview hard-caps the
		// text length before the engine measures it. The oversized string simply
		// renders as a skipped placement (too big for the small sample) rather than
		// crashing — so the handler returns a clean, path-free error, never a 500.
		$_POST = [
			'settings' => [
				'watermark' => [ 'type' => 'text', 'text' => str_repeat( 'A', 50000 ), 'text_scale_pct' => 6 ],
			],
		];

		$resp = $this->capture( [ Ajax::class, 'preview' ] );

		// Either a render (if it still fits) or a clean failure — but NEVER an
		// uncaught error, and never a leaked path.
		if ( ! $resp->success ) {
			$this->assertStringNotContainsString( '/', (string) $resp->data['reason'] );
		} else {
			$this->assertStringStartsWith( 'data:image/png;base64,', $resp->data['img_url'] );
		}
	}

	public function testPreviewReadsTheNativeOgwmSettingsFormKey(): void {
		$this->primeAdminPreviewEnv();

		// The settings page submits the whole form, so the unsaved values arrive under
		// $_POST['ogwm_settings'] (Options::OPTION), NOT a bare 'settings' key. The
		// handler must read that native key — otherwise the live preview would silently
		// ignore the user's in-form values.
		$_POST = [
			Options::OPTION => [
				'watermark' => [ 'type' => 'text', 'text' => 'From the form', 'text_scale_pct' => 6 ],
			],
		];

		$resp = $this->capture( [ Ajax::class, 'preview' ] );

		$this->assertTrue( $resp->success, 'preview must read the native ogwm_settings POST key' );
		$this->assertStringStartsWith( 'data:image/png;base64,', $resp->data['img_url'] );
	}

	public function testPreviewRateLimitsASecondRapidCall(): void {
		$this->primeAdminPreviewEnv();

		$_POST = [
			'settings' => [
				'watermark' => [ 'type' => 'text', 'text' => 'Hi', 'text_scale_pct' => 6 ],
			],
		];

		// First call renders + stamps the per-user rate-limit transient.
		$first = $this->capture( [ Ajax::class, 'preview' ] );
		$this->assertTrue( $first->success );

		// Second immediate call must be refused by the server-side throttle, with NO
		// render attempted.
		$second = $this->capture( [ Ajax::class, 'preview' ] );
		$this->assertFalse( $second->success );
		$this->assertSame( 'rate-limited', $second->data['reason'] );
	}

	public function testPreviewErrorNeverLeaksAServerPath(): void {
		$this->primeAdminPreviewEnv( freetype: false );

		// Text mode with FreeType unavailable → an honest error. The reason code must
		// be a fixed token and must NOT contain any filesystem path.
		$_POST = [
			'settings' => [
				'watermark' => [ 'type' => 'text', 'text' => 'Hi' ],
			],
		];

		$resp = $this->capture( [ Ajax::class, 'preview' ] );

		$this->assertFalse( $resp->success );
		$this->assertArrayHasKey( 'reason', $resp->data );
		$reason = (string) $resp->data['reason'];
		$this->assertStringNotContainsString( '/', $reason, 'the failure reason must not contain a path separator' );
		$this->assertStringNotContainsString( '.tmp', $reason );
		$this->assertStringNotContainsString( DIRECTORY_SEPARATOR === '/' ? sys_get_temp_dir() : '\\', $reason );
		// And no message field leaks a path either.
		$this->assertStringNotContainsString( sys_get_temp_dir(), (string) ( $resp->data['message'] ?? '' ) );
	}

	public function testPreviewRenderFailureBranchNeverLeaksAServerPath(): void {
		// This exercises the branch where the engine actually RUNS and returns a
		// non-stamp (vs. the freetype-token short-circuit above): logo mode with no
		// resolvable logo → Compositor::stamp returns SKIPPED → Preview maps it to the
		// fixed token 'render-skipped'. The reason is COMPOSED from runtime state
		// (Result::status via safeStatus), so this is the branch that could regress and
		// leak a path if the token vocabulary ever changed. Assert the reason is a fixed
		// 'render-*' token with NO path material in it.
		$this->primeAdminPreviewEnv();

		$_POST = [
			'settings' => [
				'watermark' => [
					'type'    => 'logo',
					'logo_id' => 0, // No real local PNG → LogoAsset::resolvePath() returns null.
				],
			],
		];

		$resp = $this->capture( [ Ajax::class, 'preview' ] );

		$this->assertFalse( $resp->success, 'logo mode with no logo must fail the preview render' );
		$reason = (string) $resp->data['reason'];

		// The reason is the engine-driven render branch, not the freetype token.
		$this->assertStringStartsWith( 'render-', $reason );
		// And it is path-free: no separators, no temp dir, no .png leaking through.
		$this->assertStringNotContainsString( '/', $reason );
		$this->assertStringNotContainsString( '\\', $reason );
		$this->assertStringNotContainsString( '.png', $reason );
		$this->assertStringNotContainsString( sys_get_temp_dir(), $reason );
		// One of the fixed render tokens the safeStatus() vocabulary can produce.
		$this->assertContains(
			$reason,
			[ 'render-skipped', 'render-too_large', 'render-too_small', 'render-failed', 'render-stamped', 'render-readback-failed' ],
			'the render-failure reason must be a fixed, path-free token'
		);
	}

	public function testPreviewRateLimitIsStampedOnAdmissionNotOnSuccess(): void {
		// The glyph-bomb-then-fail defense: the per-user window must be stamped BEFORE
		// the render so a FAILING/expensive render still consumes the window (otherwise a
		// direct-POST loop that always fails could pin the CPU unthrottled). Force the
		// first call to FAIL (freetype absent → text mode error) and assert the
		// immediately-following second call is STILL refused as rate-limited — proving the
		// stamp happened on admission, not on a successful render.
		$this->primeAdminPreviewEnv( freetype: false );

		$_POST = [
			'settings' => [
				'watermark' => [ 'type' => 'text', 'text' => 'Hi' ],
			],
		];

		$first = $this->capture( [ Ajax::class, 'preview' ] );
		$this->assertFalse( $first->success, 'the first call fails (text mode without FreeType)' );
		$this->assertSame( 'text-mode-needs-freetype', $first->data['reason'] );

		// Even though the first render FAILED, the throttle window must already be set.
		$second = $this->capture( [ Ajax::class, 'preview' ] );
		$this->assertFalse( $second->success );
		$this->assertSame( 'rate-limited', $second->data['reason'], 'a failing render must still consume the rate-limit window' );
	}

	// =====================================================================
	// FEATURE 1: the live-preview data: URL is passed through (NOT esc_url_raw'd).
	// =====================================================================

	public function testPreviewReturnsANonEmptyDataImageUrl(): void {
		// The regression Feature 1 fixes: the rendered image is returned as a base64
		// data: URL that previously got stripped by esc_url_raw (the 'data' scheme is
		// not an allowed WP protocol) so img_url was ALWAYS empty and the preview never
		// displayed. With the safeDataUrl pass-through the URL must arrive intact and
		// non-empty, beginning 'data:image/'. To prove the handler does NOT lean on
		// esc_url_raw, make esc_url_raw STRIP every data: URL the way real WordPress
		// does — the preview must still return the URL.
		Functions\when( 'esc_url_raw' )->alias(
			static fn( $url ) => 0 === strpos( (string) $url, 'data:' ) ? '' : $url
		);

		$this->primeAdminPreviewEnv();
		$_POST = [
			'settings' => [
				'watermark' => [ 'type' => 'text', 'text' => 'Hi', 'text_scale_pct' => 6 ],
			],
		];

		$resp = $this->capture( [ Ajax::class, 'preview' ] );

		$this->assertTrue( $resp->success );
		$this->assertNotEmpty( $resp->data['img_url'], 'the preview data URL must NOT be empty (the Feature 1 bug)' );
		$this->assertStringStartsWith( 'data:image/', $resp->data['img_url'] );
	}

	public function testSafeDataUrlAcceptsAValidBase64ImageAndRejectsAnythingElse(): void {
		$safe = new \ReflectionMethod( Ajax::class, 'safeDataUrl' );

		// A real plugin-shaped data URL (base64 of a 1x1 PNG) passes through verbatim.
		$valid = 'data:image/png;base64,' . base64_encode( 'pretend-png-bytes' );
		$this->assertSame( $valid, $safe->invoke( null, $valid ) );

		// JPEG/WEBP variants (and the jpg alias) pass too — the engine can emit any
		// of these MIMEs, so the whole whitelist must be exercised on the accept side.
		$jpeg = 'data:image/jpeg;base64,' . base64_encode( 'x' );
		$this->assertSame( $jpeg, $safe->invoke( null, $jpeg ) );
		$jpg = 'data:image/jpg;base64,' . base64_encode( 'x' );
		$this->assertSame( $jpg, $safe->invoke( null, $jpg ) );
		$webp = 'data:image/webp;base64,' . base64_encode( 'x' );
		$this->assertSame( $webp, $safe->invoke( null, $webp ) );

		// Everything that is NOT a clean base64 image data URI is dropped to ''.
		$rejects = [
			'',
			'not a url',
			'https://example.com/x.png',
			'javascript:alert(1)',
			'data:text/html;base64,PHNjcmlwdD4=',                         // wrong MIME family.
			'data:image/png;base64,abc"><script>alert(1)</script>',       // stray markup after base64.
			'data:image/svg+xml;base64,PHN2Zz4=',                         // svg is not whitelisted.
			'data:image/png,plain',                                       // not base64.
			"data:image/png;base64,YWJj\n",                               // a trailing newline must not straddle the anchor.
		];
		foreach ( $rejects as $bad ) {
			$this->assertSame( '', $safe->invoke( null, $bad ), "must reject: $bad" );
		}
	}

	// =====================================================================
	// FEATURE 3: redetectEngine enforces manage_options → nonce, then refreshes.
	// =====================================================================

	public function testRedetectEngineRejectsNonAdminBeforeNonce(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		Functions\expect( 'check_ajax_referer' )->never();

		$resp = $this->capture( [ Ajax::class, 'redetectEngine' ] );

		$this->assertFalse( $resp->success );
		$this->assertSame( 'forbidden', $resp->data['reason'] );
	}

	public function testRedetectEngineRejectsBadNonceForAnAdmin(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'ogwm_admin', 'nonce', false )
			->andReturn( false );

		$resp = $this->capture( [ Ajax::class, 'redetectEngine' ] );

		$this->assertFalse( $resp->success );
		$this->assertSame( 'bad-nonce', $resp->data['reason'] );
	}

	public function testRedetectEngineRefreshesAndReturnsTheEscapedStatusShape(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		// Capabilities::refresh() re-probes the REAL host and PERSISTS via update_option.
		// Both engines are installed in this environment, so the fresh report is a real,
		// usable driver — we assert the SHAPE the handler escapes/returns, and that the
		// refreshed report was persisted to the capabilities option (proving refresh ran,
		// not a stale read).
		$resp = $this->capture( [ Ajax::class, 'redetectEngine' ] );

		$this->assertTrue( $resp->success );
		// The exact status shape the JS consumes.
		$this->assertArrayHasKey( 'driver', $resp->data );
		$this->assertArrayHasKey( 'freetype', $resp->data );
		$this->assertArrayHasKey( 'usable', $resp->data );
		$this->assertArrayHasKey( 'text_mode', $resp->data );
		$this->assertIsString( $resp->data['driver'] );
		$this->assertIsBool( $resp->data['freetype'] );
		$this->assertIsBool( $resp->data['usable'] );
		$this->assertIsBool( $resp->data['text_mode'] );
		$this->assertContains( $resp->data['driver'], [ 'imagick', 'gd', 'none' ] );
		// usable is true exactly when the driver is not 'none'.
		$this->assertSame( 'none' !== $resp->data['driver'], $resp->data['usable'] );

		// freetype and text_mode are emitted from the same probe value; lock the
		// invariant so the two keys can never silently diverge.
		$this->assertSame( $resp->data['freetype'], $resp->data['text_mode'], 'freetype and text_mode must mirror the same probe value' );

		// refresh() persisted a fresh, real-shaped report to the option (the re-probe ran).
		$this->assertArrayHasKey( Capabilities::OPTION, $this->options );
		$persisted = $this->options[ Capabilities::OPTION ];
		$this->assertSame( $persisted['driver'], $resp->data['driver'] );
		$this->assertArrayHasKey( 'probed_gmt', $persisted );
	}

	public function testRedetectEngineEscapesTheDriverThroughEscHtml(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		// Override the TestCase's returnArg(1) esc_html stub with a marking alias so
		// we can PROVE the returned driver actually passed through esc_html() (the
		// handler's docblock promises an ESCAPED status). Without this the value
		// would be indistinguishable from an unescaped pass-through.
		Functions\when( 'esc_html' )->alias( static fn( $s ) => 'ESC:' . (string) $s );

		$resp = $this->capture( [ Ajax::class, 'redetectEngine' ] );

		$this->assertTrue( $resp->success );
		$this->assertStringStartsWith( 'ESC:', (string) $resp->data['driver'], 'the driver must be escaped via esc_html()' );
	}

	// =====================================================================
	// Helpers.
	// =====================================================================

	/**
	 * Invoke a handler and capture its terminal wp_send_json_* payload. Fails if a
	 * handler returns WITHOUT a terminal JSON response (every gate/branch must end
	 * in one).
	 *
	 * @param callable $handler
	 */
	private function capture( callable $handler ): JsonResponse {
		try {
			$handler();
		} catch ( JsonResponse $r ) {
			return $r;
		}

		$this->fail( 'the handler did not emit a terminal JSON response' );
	}

	/**
	 * Prime the manage_options + nonce gates and a real GD-backed capability probe so
	 * a preview render can run. FreeType can be toggled to exercise the text-mode
	 * unavailable error path.
	 */
	private function primeAdminPreviewEnv( bool $freetype = true ): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'get_temp_dir' )->justReturn( sys_get_temp_dir() . '/' );
		Functions\when( 'wp_generate_password' )->alias(
			static fn( $len = 12 ) => substr( bin2hex( random_bytes( 16 ) ), 0, (int) $len )
		);
		// The text-mode token resolver (Compositor → TokenResolver) reads the site
		// identity at render time; stub the WP getters it calls so a real render works.
		Functions\when( 'get_bloginfo' )->justReturn( 'Example' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( (string) $format, (int) ( $ts ?? time() ) ) );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( $url, $component = -1 ) => parse_url( (string) $url, $component )
		);

		// Seed a valid, real-shaped capabilities report so Capabilities::get() reads it
		// instead of re-probing (driver=gd, freetype as requested).
		$this->options[ Capabilities::OPTION ] = [
			'driver'     => 'gd',
			'imagick'    => false,
			'gd'         => true,
			'freetype'   => $freetype,
			'formats'    => [
				'image/jpeg' => true,
				'image/png'  => true,
				'image/webp' => false,
				'image/avif' => false,
				'image/gif'  => true,
			],
			'probed_gmt' => '2026-06-29T00:00:00+00:00',
		];
		$this->resetCapabilitiesCache();

		// Options::all() (used nowhere in preview directly, but Sanitizer needs the
		// defaults) reads ogwm_settings; leave it empty so defaults apply.
		$this->options[ Options::OPTION ] = [];
	}

	/** Clear Capabilities' in-request static memo between tests. */
	private function resetCapabilitiesCache(): void {
		// PHP 8.1+ reflection reads/writes a private static without setAccessible()
		// (which is a deprecated no-op on 8.5 and would trip failOnWarning).
		$prop = new \ReflectionProperty( Capabilities::class, 'cache' );
		$prop->setValue( null, null );
	}
}

/**
 * Typed carrier thrown by the stubbed wp_send_json_* so a handler's terminal
 * branch is catchable in a test (the real functions die()).
 */
final class JsonResponse extends \RuntimeException {

	public bool $success;

	/** @var array<string,mixed> */
	public array $data;

	/** @param array<string,mixed> $data */
	public function __construct( bool $success, array $data ) {
		parent::__construct( 'ogwm-json-response' );
		$this->success = $success;
		$this->data    = $data;
	}
}
