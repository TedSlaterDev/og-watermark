<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The admin-layer facade (SPEC M7). One call wires every admin surface so
 * {@see \OrchardGrove\OgWatermark\Plugin::boot()} stays minimal and consistent
 * with the M5/M6 wiring it already does.
 *
 * REGISTRATION CONTEXT (the subtle part): admin-ajax.php requests are technically
 * "admin" (is_admin() is true there), so registering everything under a single
 * is_admin() gate already covers the wp_ajax_ handlers. But the menu/columns/
 * media-field registrations want the FULL admin chrome, while the AJAX handlers
 * only need their wp_ajax_ hooks added during an admin-ajax request. Plugin::boot()
 * calls this only when is_admin() is true (which includes admin-ajax), and we add
 * EVERY surface's hooks unconditionally from there — the individual hooks
 * (admin_menu, manage_media_columns, wp_ajax_*) simply don't fire on the wrong
 * request type, so there is no cost to registering them all. The Ajax handlers
 * being registered on an admin-ajax request is exactly what makes the wp_ajax_
 * actions reachable.
 */
final class Admin {

	/**
	 * Wire every admin surface: the settings page, the per-image media field, the
	 * media-library column + bulk actions, the Bulk Tools page, and the secured
	 * AJAX layer. Call ONLY in an admin context (Plugin::boot gates on is_admin(),
	 * which is true on admin-ajax requests too, so the wp_ajax_ handlers register).
	 */
	public static function register(): void {
		SettingsPage::register();
		MediaField::register();
		MediaColumn::register();
		BulkPage::register();
		Ajax::register();

		// M8: the site-wide "no usable image driver → watermarking disabled" notice.
		CapabilityNotice::register();
	}
}
