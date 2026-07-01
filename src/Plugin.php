<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark;

use OrchardGrove\OgWatermark\Backup\Storage;
use OrchardGrove\OgWatermark\Cron\Cron;
use OrchardGrove\OgWatermark\Integration\Integration;
use OrchardGrove\OgWatermark\Queue\BulkRunner;
use OrchardGrove\OgWatermark\Queue\Scheduler;
use OrchardGrove\OgWatermark\Settings\Options;
use OrchardGrove\OgWatermark\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin container. Boots the foundation in milestone 1 — settings access,
 * lifecycle, the version sentinel, and backup-directory self-healing. Later
 * milestones (M2-M8) wire the rendering engine, processor, admin UI, AJAX,
 * and the bulk queue here.
 */
final class Plugin {

	private static ?self $instance = null;

	private Options $options;
	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->options = new Options();
	}

	public function options(): Options {
		return $this->options;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// WP 6.7+ emits a _doing_it_wrong() notice if a textdomain is loaded
		// before 'init', so defer the load to that hook.
		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain( 'og-watermark', false, dirname( OGWM_BASENAME ) . '/languages' );
			}
		);

		// On version change, refresh the cached capability probe and run any
		// one-time migrations. Gated to back-end/cron/CLI so none of this
		// (DB writes, capability probing) ever fires on a front-end request.
		if ( ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) )
			&& get_option( 'ogwm_version' ) !== OGWM_VERSION ) {
			update_option( 'ogwm_version', OGWM_VERSION );
			Capabilities::refresh();
			$this->migrate();

			// Multisite per-site scheduling. register_activation_hook fires activate()
			// in a SINGLE site context, so on a network-activated install only that one
			// site gets the daily crons. This version-sentinel branch runs on each
			// site's first back-end/cron request, so scheduling here (idempotently —
			// schedule() guards each event with wp_next_scheduled()) ensures every
			// subsite, and any subsite created later, gets the integrity + reaper crons.
			if ( class_exists( Cron::class ) ) {
				Cron::schedule();
			}
		}

		// Re-create the backup directory hardening if it goes missing, and warn
		// when the backup base is web-reachable on a server that ignores our
		// per-directory deny files (e.g. nginx). The notice is dismissible + self-
		// clearing; enqueueNoticeAssets loads its helper only when it will render.
		add_action( 'admin_init', [ Storage::class, 'selfHeal' ] );
		add_action( 'admin_notices', [ Storage::class, 'maybeNotice' ] );
		add_action( 'admin_enqueue_scripts', [ Storage::class, 'enqueueNoticeAssets' ] );

		// The exposure probe runs off the request path via wp-cron, so its handler
		// must be registered unconditionally (a cron request has no admin context).
		add_action( Storage::PROBE_HOOK, [ Storage::class, 'probeExposure' ] );

		// M6: register the background-queue handlers unconditionally so Action
		// Scheduler / wp-cron can always fire the ogwm_process_one + ogwm_bulk_tick
		// actions (a cron callback runs with no admin context, so this can't be gated
		// behind is_admin()). Browser requests only ever observe via these hooks.
		Scheduler::register();
		BulkRunner::register();

		// M8: register the daily integrity/reaper cron action handlers unconditionally
		// for the same reason — wp-cron fires ogwm_integrity_check + ogwm_tmp_reaper
		// with no admin context, so the handlers must be wired on every request.
		// Guarded with class_exists during incremental builds (the Cron class is
		// added by a sibling M8 task); harmless once present.
		if ( class_exists( Cron::class ) ) {
			Cron::register();
		}

		// M5: wire the WP-integration & coexistence seams (the
		// wp_generate_attachment_metadata reprocess listener, the crop/rotate editor
		// bridge, srcset coherence, and the Imagick preference during our own
		// regenerate). Registered unconditionally for the same reason as the queue —
		// these filters fire on front-end, admin, and cron requests alike, and every
		// one is async-only (none composites inline in a web request).
		Integration::register();

		// M7: register the admin UI — the Settings + Bulk Tools pages, the
		// per-image opt-in surfaces, the media-library column, and the secured
		// AJAX layer. Gated to ADMIN CONTEXT only (is_admin()), which is ALSO true on
		// admin-ajax.php requests — so the wp_ajax_ handlers still register and the
		// privileged AJAX actions stay reachable — while the menu/column/media-field
		// hooks never fire pointlessly on a front-end request (they simply no-op on
		// the wrong request type). When the full facade is not yet present, the
		// Settings screen still wires itself so the menu/options round-trip works
		// during incremental builds.
		if ( is_admin() && class_exists( Admin\Admin::class ) ) {
			Admin\Admin::register();
		} elseif ( is_admin() && class_exists( Admin\SettingsPage::class ) ) {
			Admin\SettingsPage::register();
		}

		// M2: register the rendering engine + format policy.
		// M3: register the processor/pipeline + backup create/verify/restore.
		// M8: register integrity/reaper crons, capability notices, WP-CLI.
	}

	/**
	 * One-time data migrations, run when the stored version changes. Each step
	 * is idempotent and no-ops once applied.
	 */
	private function migrate(): void {
		// 1.2.3: the queue-dedup set moved from the monolithic `ogwm_pending` option
		// to per-attachment `_ogwm_pending` meta. Drop the now-orphaned option (the
		// per-id markers are re-seeded naturally on the next enqueue). Idempotent.
		delete_option( 'ogwm_pending' );
	}

	public static function activate(): void {
		( new Options() )->seedDefaults();
		Storage::ensureDir();
		update_option( 'ogwm_version', OGWM_VERSION );

		// M8: schedule the daily backup-integrity sweep + tmp/partial reaper. The
		// scheduler is idempotent, so the per-site lazy-activation path on multisite
		// (driven by the ogwm_version sentinel) schedules each site exactly once.
		if ( class_exists( Cron::class ) ) {
			Cron::schedule();
		}
	}

	public static function deactivate(): void {
		// M8 lifecycle hardening: stop any in-flight bulk run first so its global
		// lock is released and no orphaned tick keeps firing, THEN tear down every
		// scheduled hook the plugin owns (integrity + reaper crons, the bulk-tick
		// chain, pending per-image jobs, and the Action Scheduler group). Settings
		// and pristine backups are deliberately LEFT INTACT — deactivation is not
		// uninstall.
		BulkRunner::cancel();

		if ( class_exists( Cron::class ) ) {
			Cron::unschedule();
		}

		// Clear any queued one-off events the plugin owns (the exposure probe and the
		// reprocess-catch-up drain) so a pending single event can't fire after
		// deactivation.
		wp_clear_scheduled_hook( Storage::PROBE_HOOK );
		wp_clear_scheduled_hook( Integration\MetadataListener::DRAIN_HOOK );
	}
}
