<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Admin;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Backup\Manager;
use OrchardGrove\OgWatermark\Queue\BulkRunner;
use OrchardGrove\OgWatermark\Support\Meta;
// MediaColumn provides the bulk-action redirect arg constants (same Admin namespace).

/**
 * The "Apply" panel of the settings screen (formerly the "Bulk Tools" submenu).
 *
 * It is an OGM-styled OBSERVER: a disk-space pre-flight warning, a Start/Resume
 * button, and a progress bar that {@see \bulk.js} fills by polling
 * `wp_ajax_ogwm_bulk_progress`. ALL stamping happens server-side in the M6
 * {@see BulkRunner} background machinery — the browser NEVER does image work and
 * closing the tab does not stop the run.
 *
 * {@see renderPanel()} is called by {@see SettingsPage::render()} on the Apply tab,
 * inside that screen's shared header/tab chrome. This class only RENDERS + enqueues;
 * starting/resuming/observing the run goes through {@see Ajax} (cap manage_options +
 * the ogwm_admin nonce), so it performs no privileged mutation itself.
 */
final class BulkPage {

	/** Legacy slug of the former Bulk Tools submenu (kept for the compat redirect). */
	public const PAGE = 'ogwm-bulk';

	/** The script handle for bulk.js, also the wp_localize_script object root. */
	private const SCRIPT_HANDLE = 'ogwm-bulk';

	/**
	 * Wire the asset enqueue for the Apply tab. The bulk UI is no longer a submenu
	 * page — it is a tab on the settings screen ({@see SettingsPage::render()} →
	 * {@see renderPanel()}) — so bulk.js loads on that screen only when the Apply tab
	 * is active. The shared admin CSS is enqueued by SettingsPage::assets().
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
	}

	/**
	 * Enqueue bulk.js on the settings screen's Apply tab only. bulk.js is a READ-ONLY
	 * poller; it is localized with the admin-ajax URL, the ogwm_admin nonce, the
	 * polling action names, and i18n strings.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public static function assets( string $hook ): void {
		if ( 'toplevel_page_' . SettingsPage::PAGE !== $hook ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector; no state mutation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'apply' !== $tab ) {
			return;
		}

		if ( ! defined( 'OGWM_URL' ) || ! defined( 'OGWM_VERSION' ) ) {
			return;
		}

		wp_enqueue_script( self::SCRIPT_HANDLE, OGWM_URL . 'assets/js/bulk.js', [ 'jquery' ], OGWM_VERSION, true );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'ogwmBulk',
			[
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( Ajax::NONCE_ACTION ),
				'startAction'   => Ajax::ACTION_BULK_START,
				'progressAction' => Ajax::ACTION_BULK_PROGRESS,
				'pollMs'        => 2000,
				'i18n'          => [
					'starting'  => __( 'Starting…', 'og-watermark' ),
					'running'   => __( 'Processing…', 'og-watermark' ),
					'done'      => __( 'Done.', 'og-watermark' ),
					/* translators: 1: processed count, 2: total count. */
					'progress'  => __( '%1$d of %2$d processed', 'og-watermark' ),
					/* translators: %d: number of failed images. */
					'failed'    => __( '%d failed', 'og-watermark' ),
					'startError' => __( 'Could not start the run.', 'og-watermark' ),
				],
			]
		);
	}

	/**
	 * Render the "Apply" panel INSIDE the settings screen (called from
	 * {@see SettingsPage::render()} on the Apply tab, within its shared
	 * `.wrap`/header/tab chrome). All work is server-side; this only configures +
	 * observes a run. The shared engine notice is rendered by SettingsPage, so the
	 * caller passes whether an engine is usable to drive the disabled states.
	 *
	 * @param bool $usable Whether a usable image engine (Imagick/GD) is present.
	 */
	public static function renderPanel( bool $usable ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$flaggedCount = self::flaggedCount();
		$progress     = BulkRunner::progress();
		$running      = ! empty( $progress['running'] );
		$resume       = isset( $_GET['ogwm_resume'] ) && '1' === sanitize_key( (string) wp_unslash( $_GET['ogwm_resume'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI hint (auto-starts the run via the nonce-protected AJAX call), no state mutation here.
		$stats        = Manager::stats();

		self::renderBulkActionNotice();
		?>
		<div class="ogwm-layout">
			<div class="ogwm-main">
				<div class="ogwm-card">
					<h2 class="ogwm-card-title"><?php esc_html_e( 'Apply to flagged images', 'og-watermark' ); ?></h2>
					<p class="ogwm-intro"><?php esc_html_e( 'Watermark every image you have flagged (including any that are out of date). It runs in the background on the server — you can leave this page or close the tab, and the progress bar resumes when you return.', 'og-watermark' ); ?></p>

					<form id="ogwm-bulk-form" onsubmit="return false">
						<p class="ogwm-scope-summary">
							<?php
							printf(
								/* translators: %s: number of flagged images. */
								esc_html__( '%s currently flagged for watermarking.', 'og-watermark' ),
								'<strong>' . esc_html(
									sprintf(
										/* translators: %s: formatted count. */
										_n( '%s image', '%s images', $flaggedCount, 'og-watermark' ),
										number_format_i18n( $flaggedCount )
									)
								) . '</strong>'
							);
							?>
						</p>

						<div class="ogwm-preflight notice notice-warning inline">
							<p>
								<span class="dashicons dashicons-database" aria-hidden="true"></span>
								<?php esc_html_e( 'Each image keeps a pristine, immutable backup of its original — so flagging a large library roughly doubles its media storage. The run checks free disk space before each backup and refuses if there is not enough room.', 'og-watermark' ); ?>
							</p>
						</div>

						<p class="ogwm-actions">
							<button type="button" class="button button-primary button-hero" id="ogwm-bulk-start" <?php disabled( $usable, false ); ?>>
								<?php echo $running ? esc_html__( 'Resume run', 'og-watermark' ) : esc_html__( 'Start run', 'og-watermark' ); ?>
							</button>
							<span class="spinner ogwm-spinner" aria-hidden="true"></span>
						</p>

						<div class="ogwm-progress-wrap" id="ogwm-progress-wrap" <?php echo $running ? '' : 'hidden'; ?>>
							<div class="ogwm-progress">
								<span class="ogwm-progress-bar" id="ogwm-progress-fill" style="width:<?php echo esc_attr( (string) self::pct( $progress ) ); ?>%"></span>
							</div>
							<p class="ogwm-progress-meta" id="ogwm-progress-text" aria-live="polite">
								<?php
								printf(
									/* translators: 1: processed count, 2: total count. */
									esc_html__( '%1$s of %2$s processed', 'og-watermark' ),
									esc_html( number_format_i18n( (int) ( $progress['done'] ?? 0 ) + (int) ( $progress['failed'] ?? 0 ) ) ),
									esc_html( number_format_i18n( (int) ( $progress['total'] ?? 0 ) ) )
								);
								?>
							</p>
						</div>
					</form>
				</div>
			</div>

			<aside class="ogwm-sidebar">
				<div class="ogwm-card ogwm-aside">
					<h2><?php esc_html_e( 'Backup storage', 'og-watermark' ); ?></h2>
					<ul class="ogwm-stats">
						<li>
							<span class="ogwm-stat-label"><?php esc_html_e( 'Pristine backups', 'og-watermark' ); ?></span>
							<span class="ogwm-stat-value"><?php echo esc_html( number_format_i18n( (int) ( $stats['count'] ?? 0 ) ) ); ?></span>
						</li>
						<li>
							<span class="ogwm-stat-label"><?php esc_html_e( 'Storage used', 'og-watermark' ); ?></span>
							<span class="ogwm-stat-value"><?php echo esc_html( size_format( (int) ( $stats['bytes'] ?? 0 ), 1 ) ); ?></span>
						</li>
					</ul>
					<p class="ogwm-aside-foot"><?php esc_html_e( 'Backups are the single source of truth — every apply or undo rebuilds from them, so watermarks never stack.', 'og-watermark' ); ?></p>
				</div>
			</aside>
		</div>
		<?php
		if ( $resume ) {
			// A bulk-action redirect lands here with ?ogwm_resume=1; bulk.js reads this
			// and auto-clicks Start (through the nonce-protected AJAX call).
			echo '<script>window.ogwmAutoStart = true;</script>';
		}
	}

	/**
	 * Emit a confirmation notice after a Media-library bulk action redirect. Reads
	 * the read-only GET hints {@see MediaColumn::NOTICE_ARG} (a count) +
	 * {@see MediaColumn::ACTION_ARG} (the kind) and prints an escaped success block.
	 * Display-only — no state is mutated here, so no nonce is required.
	 */
	private static function renderBulkActionNotice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display hint set by the bulk-action redirect; no state change here.
		if ( ! isset( $_GET[ MediaColumn::NOTICE_ARG ], $_GET[ MediaColumn::ACTION_ARG ] ) ) {
			return;
		}

		$count = absint( wp_unslash( $_GET[ MediaColumn::NOTICE_ARG ] ) );
		$kind  = sanitize_key( (string) wp_unslash( $_GET[ MediaColumn::ACTION_ARG ] ) );
		// phpcs:enable

		if ( 'flagged' === $kind ) {
			$message = sprintf(
				/* translators: %s: number of images. */
				_n(
					'%s image flagged for watermarking. The run is starting below.',
					'%s images flagged for watermarking. The run is starting below.',
					$count,
					'og-watermark'
				),
				number_format_i18n( $count )
			);
		} elseif ( 'removed' === $kind ) {
			$message = sprintf(
				/* translators: %s: number of images. */
				_n(
					'%s image unflagged.',
					'%s images unflagged.',
					$count,
					'og-watermark'
				),
				number_format_i18n( $count )
			);
		} else {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible ogwm-notice">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------
	// Counts for the scope summary (read-only).
	// ---------------------------------------------------------------------

	/** Number of flagged (_ogwm_enabled='1') attachments. */
	private static function flaggedCount(): int {
		$query = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => Meta::ENABLED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'             => '1',           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		return (int) $query->found_posts;
	}

	/**
	 * Progress percentage (0–100) from a progress snapshot.
	 *
	 * @param array{total?:int,done?:int,failed?:int} $progress
	 */
	private static function pct( array $progress ): int {
		$total = (int) ( $progress['total'] ?? 0 );
		if ( $total <= 0 ) {
			return 0;
		}
		$seen = (int) ( $progress['done'] ?? 0 ) + (int) ( $progress['failed'] ?? 0 );
		return (int) min( 100, max( 0, (int) round( $seen / $total * 100 ) ) );
	}
}
