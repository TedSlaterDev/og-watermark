<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Admin;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Admin\MediaColumn;
use OrchardGrove\OgWatermark\Pipeline\Outcome;
use OrchardGrove\OgWatermark\Queue\BulkRunner;
use OrchardGrove\OgWatermark\Support\Meta;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * MediaColumn — the Media list-table "Watermark" surface: the status column, its
 * sortable orderby, and the bulk actions.
 *
 * The two load-bearing properties:
 *
 *  1. THE BULK HANDLER NEVER COMPOSITES INLINE. "Apply OG Watermark" ONLY flags
 *     the selected ids (Meta::ENABLED + queued status) and seeds the SERVER-SIDE
 *     queue via the REAL {@see BulkRunner::start()} with scope 'ids', then redirects
 *     to the Bulk Tools page. We prove no inline work by injecting a Processor
 *     double through BulkRunner's seam and asserting it is NEVER called — start()
 *     seeds + schedules, it does not stamp.
 *  2. THE SORTABLE ORDERBY IS WIRED. An `orderby=ogwm` admin main query is
 *     rewritten to a meta-value sort on {@see Meta::STATUS}; any other query is
 *     left untouched.
 *
 * The bulk handler runs against the REAL BulkRunner, with only its WP dependencies
 * (Lock object-cache, cron, disk pre-flight, get_posts) Brain-Monkey-stubbed — the
 * same faithful doubles BulkRunnerTest uses — so "seeds the queue" is a real seed,
 * not a mock expectation.
 */
final class MediaColumnTest extends TestCase {

	/** @var array<int,array<string,mixed>> In-memory post meta keyed by id. */
	private array $meta = [];

	/** @var array<int,bool> Which ids wp_attachment_is_image() reports true for. */
	private array $images = [];

	/** @var array<string,mixed> In-memory options (BulkRunner durable state + queue). */
	private array $options = [];

	/** @var array<string,mixed> Object-cache double backing the global Lock. */
	private array $cache = [];

	/** @var array<int,array{hook:string,time:int}> wp_schedule_single_event spy. */
	private array $scheduled = [];

	/** @var array<int,array{id:int,context:string}> Calls the injected Processor saw. */
	private array $processed = [];

	private int $tokenSeq = 0;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'date_i18n' )->alias( static fn( $fmt, $ts ) => gmdate( (string) $fmt, (int) $ts ) );
		Functions\when( 'wp_kses' )->returnArg( 1 );

		$this->stubMeta();
		$this->stubImages();
		$this->stubBulkRunnerDeps();

		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				$query = http_build_query( $args );
				return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . $query;
			}
		);

		// PROVE no inline compositing: any call into this fake fails the property.
		BulkRunner::$processorFactory = function (): object {
			return new \OrchardGrove\OgWatermark\Tests\Admin\NeverCalledProcessor(
				function ( int $id, string $context ): Outcome {
					$this->processed[] = [ 'id' => $id, 'context' => $context ];
					return Outcome::watermarked( [] );
				}
			);
		};
		// No Action Scheduler → the cron-chain kick path (no jobs, no processor at start()).
		BulkRunner::$forceActionScheduler                               = false;
		\OrchardGrove\OgWatermark\Queue\Scheduler::$forceActionScheduler = false;
	}

	protected function tearDown(): void {
		BulkRunner::$processorFactory                                    = null;
		BulkRunner::$forceActionScheduler                               = null;
		\OrchardGrove\OgWatermark\Queue\Scheduler::$forceActionScheduler = null;
		parent::tearDown();
	}

	// =================================================================
	// Bulk handler: flags + seeds the queue + redirects; NO inline work.
	// =================================================================

	public function testBulkApplyFlagsSeedsQueueRedirectsWithoutCompositing(): void {
		$this->images = [ 5 => true, 6 => true, 7 => true ];

		$redirect = MediaColumn::handleBulkAction(
			'https://example.test/wp-admin/upload.php',
			MediaColumn::ACTION_APPLY,
			[ 5, 6, 7 ]
		);

		// 1) Every selected id is flagged + seeded to the queued status.
		foreach ( [ 5, 6, 7 ] as $id ) {
			$this->assertSame( '1', $this->meta[ $id ][ Meta::ENABLED ] );
			$this->assertSame( Meta::STATUS_QUEUED, $this->meta[ $id ][ Meta::STATUS ] );
		}

		// 2) The SERVER-SIDE queue was genuinely seeded by the real BulkRunner with the
		//    explicit id scope — the run is durable state, not an inline loop.
		$this->assertArrayHasKey( 'ogwm_bulk_state', $this->options );
		$this->assertSame( 'ids', $this->options['ogwm_bulk_queue']['mode'] );
		$this->assertSame( [ 5, 6, 7 ], $this->options['ogwm_bulk_queue']['ids'] );
		$this->assertSame( 3, $this->options['ogwm_bulk_state']['total'] );
		$this->assertTrue( $this->options['ogwm_bulk_state']['running'] );

		// 3) NOTHING was composited in the request — the Processor was never called.
		$this->assertSame( [], $this->processed, 'the bulk handler must NEVER stamp an image inline' );

		// 4) Redirect lands on the Bulk Tools page with the confirmation count.
		$this->assertStringContainsString( 'page=ogwm-bulk', $redirect );
		$this->assertStringContainsString( MediaColumn::NOTICE_ARG . '=3', $redirect );
	}

	public function testBulkApplyFiltersNonImageIdsBeforeSeeding(): void {
		$this->images = [ 5 => true, 6 => false, 7 => true ]; // 6 is not an image.

		MediaColumn::handleBulkAction(
			'https://example.test/wp-admin/upload.php',
			MediaColumn::ACTION_APPLY,
			[ 5, 6, 7, 0, -1 ]
		);

		// Only the real image ids reached the queue + the flag.
		$this->assertSame( [ 5, 7 ], $this->options['ogwm_bulk_queue']['ids'] );
		$this->assertArrayNotHasKey( 6, $this->meta );
		$this->assertSame( [], $this->processed );
	}

	public function testBulkRemoveOnlyUnflagsNoQueueNoCompositing(): void {
		$this->images                       = [ 8 => true, 9 => true ];
		$this->meta[8][ Meta::ENABLED ] = '1';
		$this->meta[9][ Meta::ENABLED ] = '1';

		$redirect = MediaColumn::handleBulkAction(
			'https://example.test/wp-admin/upload.php',
			MediaColumn::ACTION_REMOVE,
			[ 8, 9 ]
		);

		// Flags dropped; NO bulk run started; NO compositing.
		$this->assertArrayNotHasKey( Meta::ENABLED, $this->meta[8] ?? [] );
		$this->assertArrayNotHasKey( Meta::ENABLED, $this->meta[9] ?? [] );
		$this->assertArrayNotHasKey( 'ogwm_bulk_state', $this->options );
		$this->assertSame( [], $this->processed );
		$this->assertStringContainsString( 'page=ogwm-bulk', $redirect );
	}

	public function testBulkHandlerPassesThroughForeignAction(): void {
		$incoming = 'https://example.test/wp-admin/upload.php?some=thing';

		$out = MediaColumn::handleBulkAction( $incoming, 'delete', [ 1, 2 ] );

		// A non-OGWM action is returned untouched so the chain's other handlers run.
		$this->assertSame( $incoming, $out );
		$this->assertArrayNotHasKey( 'ogwm_bulk_state', $this->options );
		$this->assertSame( [], $this->processed );
	}

	public function testBulkHandlerRejectsUserWithoutUploadCap(): void {
		$this->images = [ 5 => true ];
		Functions\when( 'current_user_can' )->justReturn( false );

		$incoming = 'https://example.test/wp-admin/upload.php';
		$out      = MediaColumn::handleBulkAction( $incoming, MediaColumn::ACTION_APPLY, [ 5 ] );

		// Refused: passthrough redirect, no flag, no queue, no compositing.
		$this->assertSame( $incoming, $out );
		$this->assertArrayNotHasKey( 5, $this->meta );
		$this->assertArrayNotHasKey( 'ogwm_bulk_state', $this->options );
		$this->assertSame( [], $this->processed );
	}

	// =================================================================
	// Sortable column: orderby=ogwm → meta-value sort on STATUS.
	// =================================================================

	public function testSortableColumnIsDeclared(): void {
		$columns = MediaColumn::sortableColumn( [ 'title' => 'title' ] );
		$this->assertSame( MediaColumn::COLUMN, $columns[ MediaColumn::COLUMN ] );
	}

	public function testApplySortRewritesOrderbyToStatusMeta(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$query = new FakeQuery( [ 'orderby' => MediaColumn::COLUMN, 'order' => 'asc' ], true );

		MediaColumn::applySort( $query );

		$this->assertSame( Meta::STATUS, $query->get( 'meta_key' ) );
		$this->assertSame( 'meta_value', $query->get( 'orderby' ) );
		$this->assertSame( 'ASC', $query->get( 'order' ) );
	}

	public function testApplySortIgnoresOtherOrderby(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$query = new FakeQuery( [ 'orderby' => 'date' ], true );
		MediaColumn::applySort( $query );

		// Untouched — no meta_key injected.
		$this->assertSame( '', $query->get( 'meta_key' ) );
		$this->assertSame( 'date', $query->get( 'orderby' ) );
	}

	public function testApplySortIgnoresNonMainQuery(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$query = new FakeQuery( [ 'orderby' => MediaColumn::COLUMN ], false );
		MediaColumn::applySort( $query );

		$this->assertSame( '', $query->get( 'meta_key' ) );
	}

	// =================================================================
	// Column render: badge vocabulary from status; non-image → dash.
	// =================================================================

	public function testRenderColumnRendersWatermarkedBadgeWithDate(): void {
		$this->images = [ 3 => true ];
		$this->meta[3][ Meta::STATUS ]      = Meta::STATUS_WATERMARKED;
		$this->meta[3][ Meta::APPLIED_GMT ] = '2026-06-29T00:00:00+00:00';

		ob_start();
		MediaColumn::renderColumn( MediaColumn::COLUMN, 3 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ogwm-badge', $html );
		$this->assertStringContainsString( 'Watermarked', $html );
		$this->assertStringContainsString( '2026-06-29', $html );
		$this->assertStringContainsString( 'data-status="watermarked"', $html );
	}

	public function testRenderColumnUnflaggedReadsNotFlagged(): void {
		$this->images = [ 4 => true ]; // no meta → STATUS_NONE, unflagged.

		ob_start();
		MediaColumn::renderColumn( MediaColumn::COLUMN, 4 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Not flagged', $html );
		$this->assertStringContainsString( 'data-status="none"', $html );
	}

	public function testRenderColumnFailedReadsError(): void {
		$this->images = [ 4 => true ];
		$this->meta[4][ Meta::STATUS ] = Meta::STATUS_FAILED;

		ob_start();
		MediaColumn::renderColumn( MediaColumn::COLUMN, 4 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Error', $html );
		$this->assertStringContainsString( 'ogwm-badge-bad', $html );
	}

	public function testRenderColumnRendersDashForNonImage(): void {
		$this->images = [ 4 => false ];

		ob_start();
		MediaColumn::renderColumn( MediaColumn::COLUMN, 4 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ogwm-col-na', $html );
		$this->assertStringNotContainsString( 'ogwm-badge', $html );
	}

	public function testRenderColumnIgnoresForeignColumn(): void {
		$this->images = [ 4 => true ];

		ob_start();
		MediaColumn::renderColumn( 'title', 4 );
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	public function testAddColumnAppendsWatermarkColumn(): void {
		$columns = MediaColumn::addColumn( [ 'title' => 'Title' ] );
		$this->assertArrayHasKey( MediaColumn::COLUMN, $columns );
		$this->assertSame( 'Watermark', $columns[ MediaColumn::COLUMN ] );
	}

	public function testAddBulkActionsRegistersBothActions(): void {
		$actions = MediaColumn::addBulkActions( [] );
		$this->assertArrayHasKey( MediaColumn::ACTION_APPLY, $actions );
		$this->assertArrayHasKey( MediaColumn::ACTION_REMOVE, $actions );
	}

	// =================================================================
	// media.js enqueue: the per-image AJAX controls are actually wired.
	// =================================================================

	public function testAssetsEnqueueAndLocalizeMediaJsOnUploadScreen(): void {
		if ( ! defined( 'OGWM_URL' ) ) {
			define( 'OGWM_URL', 'https://example.test/wp-content/plugins/og-watermark/' );
		}

		$scripts   = [];
		$localized = null;
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$scripts ): void {
				$scripts[] = $handle;
			}
		);
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $obj, $data ) use ( &$localized ): void {
				$localized = [ 'obj' => $obj, 'data' => $data ];
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );

		MediaColumn::assets( 'upload.php' );

		// The per-image script is enqueued and localized as window.ogwmMedia with the
		// ajaxUrl + the ogwm_admin nonce (so check_ajax_referer passes) + i18n.
		$this->assertContains( 'ogwm-media', $scripts );
		$this->assertSame( 'ogwmMedia', $localized['obj'] );
		$this->assertArrayHasKey( 'ajaxUrl', $localized['data'] );
		$this->assertSame( 'nonce123', $localized['data']['nonce'] );
		$this->assertArrayHasKey( 'i18n', $localized['data'] );
	}

	public function testAssetsDoNotEnqueueMediaJsOnUnrelatedScreen(): void {
		if ( ! defined( 'OGWM_URL' ) ) {
			define( 'OGWM_URL', 'https://example.test/wp-content/plugins/og-watermark/' );
		}

		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_localize_script' )->never();

		MediaColumn::assets( 'options-general.php' );
		$this->addToAssertionCount( 1 );
	}

	// =================================================================
	// Single-source badge: the AJAX swap returns the SAME markup the column
	// originally rendered (no class/data-status/date drift on apply/remove).
	// =================================================================

	public function testStatusBadgeDelegatesToTheColumnSingleSource(): void {
		// StatusBadge::forAttachment (used by the AJAX apply/remove responses) must
		// produce EXACTLY the markup MediaColumn::badgeHtml renders into the column
		// cell — otherwise the AJAX swap would lose the data-status/title/date
		// affordances the initial server render had. Prove byte-for-byte identity for a
		// representative watermarked-with-date status.
		$this->meta[21][ Meta::STATUS ]      = Meta::STATUS_WATERMARKED;
		$this->meta[21][ Meta::APPLIED_GMT ] = '2026-06-29T00:00:00+00:00';

		$column = MediaColumn::badgeHtml( 21 );
		$ajax   = \OrchardGrove\OgWatermark\Admin\StatusBadge::forAttachment( 21 );

		$this->assertSame( $column, $ajax, 'the AJAX badge must equal the column badge for the same status' );
		$this->assertStringContainsString( 'data-status="watermarked"', $ajax );
		$this->assertStringContainsString( '2026-06-29', $ajax );
	}

	// =================================================================
	// Stubs.
	// =================================================================

	private function stubMeta(): void {
		Functions\when( 'get_post_meta' )->alias(
			fn( $id, $key, $single = false ) => $this->meta[ (int) $id ][ $key ] ?? ( $single ? '' : [] )
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
	}

	private function stubImages(): void {
		Functions\when( 'wp_attachment_is_image' )->alias(
			fn( $id ) => ! empty( $this->images[ (int) $id ] )
		);
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	/**
	 * Faithful doubles for the BulkRunner's WP dependencies — the same surfaces
	 * BulkRunnerTest stubs (durable options, Lock object-cache, cron spy, get_posts,
	 * disk pre-flight), so the bulk handler exercises the REAL start() path.
	 */
	private function stubBulkRunnerDeps(): void {
		// Durable option store (state + queue).
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);

		// Object-cache double backing the global Lock (group:key => value).
		Functions\when( 'wp_cache_add' )->alias(
			function ( $key, $value, $group = '', $ttl = 0 ) {
				$k = $group . ':' . $key;
				if ( array_key_exists( $k, $this->cache ) ) {
					return false;
				}
				$this->cache[ $k ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->alias(
			fn( $key, $group = '' ) => array_key_exists( $group . ':' . $key, $this->cache ) ? $this->cache[ $group . ':' . $key ] : false
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
		Functions\when( 'wp_generate_password' )->alias(
			fn() => 'tok-' . str_pad( (string) ( ++$this->tokenSeq ), 4, '0', STR_PAD_LEFT )
		);

		// Cron spy.
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = [] ) {
				$this->scheduled[] = [ 'time' => (int) $timestamp, 'hook' => $hook ];
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook, $args = [] ) {
				foreach ( $this->scheduled as $s ) {
					if ( $s['hook'] === $hook ) {
						return $s['time'];
					}
				}
				return false;
			}
		);
		Functions\when( 'wp_unschedule_event' )->justReturn( true );

		// Disk pre-flight against the REAL Preflight + temp dir (a few-MiB estimate
		// comfortably fits), mirroring BulkRunnerTest's approach.
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) );
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => sys_get_temp_dir() ] );
		$this->options['ogwm_backup_base'] = sys_get_temp_dir();

		// originalPathFor() → a non-existent path forces the per-attachment average.
		Functions\when( 'wp_get_original_image_path' )->alias(
			static fn( $id, $unfiltered = false ) => '/nonexistent/ogwm-' . (int) $id . '.jpg'
		);
	}
}

/**
 * Processor double for the BulkRunner seam. start() never calls it; if it ever
 * does, the recorder logs the call so the "no inline compositing" assertion fails.
 * Not a subclass — Processor is final; the seam duck-types process()/isOk().
 */
final class NeverCalledProcessor {

	/** @var \Closure(int,string):Outcome */
	private \Closure $recorder;

	public function __construct( \Closure $recorder ) {
		$this->recorder = $recorder;
	}

	public function process( int $id, string $context ): Outcome {
		return ( $this->recorder )( $id, $context );
	}
}

/**
 * Minimal WP_Query stand-in for the pre_get_posts sort test: a get/set var bag
 * plus a fixed is_main_query() verdict. Extends the bootstrap WP_Query shim so it
 * satisfies the production WP_Query type hint, but overrides the constructor to
 * skip the shim's resolver path (we only need the query-var accessors here).
 *
 * @var array<string,mixed> $vars
 */
final class FakeQuery extends \WP_Query {

	/** @var array<string,mixed> */
	private array $vars;

	private bool $isMain;

	/** @param array<string,mixed> $vars */
	public function __construct( array $vars, bool $isMain ) {
		$this->vars   = $vars;
		$this->isMain = $isMain;
		// Deliberately NOT calling parent::__construct — the shim's ctor runs the
		// keyset resolver, which is irrelevant to the orderby-rewrite under test.
	}

	public function get( $key, $default = '' ) {
		return $this->vars[ $key ] ?? $default;
	}

	public function set( $key, $value ): void {
		$this->vars[ $key ] = $value;
	}

	public function is_main_query(): bool {
		return $this->isMain;
	}
}
