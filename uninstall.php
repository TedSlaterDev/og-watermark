<?php
/**
 * Uninstall handler. Only deletes data when the user opted in via the
 * "Delete all plugin data when the plugin is deleted" setting. Pristine
 * backups are NEVER removed unless the user ALSO opted into deleting backups.
 *
 * @package OrchardGrove\OgWatermark
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Defensive cron teardown — Plugin::deactivate() normally unschedules these, but
// a plugin can be deleted while inactive (or deactivation failed mid-flight), so
// clear every scheduled hook the plugin owns BEFORE any gated data deletion. We
// cannot autoload the Cron class here (uninstall runs in a bare context), so the
// hook names are inlined. as_unschedule_all_actions() runs only when Action
// Scheduler is present. This is unconditional: leftover cron events would fail
// noisily once the plugin's callbacks are gone, whether or not data is kept.
foreach ( [ 'ogwm_integrity_check', 'ogwm_tmp_reaper', 'ogwm_integrity_continue', 'ogwm_bulk_tick', 'ogwm_process_one' ] as $ogwm_hook ) {
	wp_clear_scheduled_hook( $ogwm_hook );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', [], 'og-watermark' );
}

$ogwm_settings = get_option( 'ogwm_settings', [] );

if ( ! is_array( $ogwm_settings ) || empty( $ogwm_settings['advanced']['delete_data_on_uninstall'] ) ) {
	return;
}

// Remember whether backups were also opted in before we drop the settings row.
$ogwm_delete_backups = ! empty( $ogwm_settings['advanced']['delete_backups_on_uninstall'] );

// Capture the persisted backup location + token BEFORE dropping options, so we
// can target exactly the plugin's own directory (never a sibling) below.
$ogwm_backup_base  = get_option( 'ogwm_backup_base', '' );
$ogwm_backup_token = get_option( 'ogwm_backup_token', '' );

// Remove the plugin options. The LIKE sweep also catches any future ogwm_ options.
delete_option( 'ogwm_settings' );
delete_option( 'ogwm_version' );
delete_option( 'ogwm_capabilities' );
delete_option( 'ogwm_backup_base' );
delete_option( 'ogwm_backup_token' );

global $wpdb;

$ogwm_option_like = $wpdb->esc_like( 'ogwm_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ogwm_option_like ) ); // phpcs:ignore WordPress.DB

$ogwm_meta_like = $wpdb->esc_like( '_ogwm_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $ogwm_meta_like ) ); // phpcs:ignore WordPress.DB

// Pristine backups are the crown-jewel asset — only remove them when the user
// explicitly opted in. Default: leave the directory untouched.
if ( ! $ogwm_delete_backups ) {
	return;
}

// Target ONLY the plugin's own backup directory. We cannot load Backup\Storage
// here (uninstall runs in a bare context), so we rely on the persisted location
// and token that Storage recorded. We never glob a bare prefix, so an admin's
// manual sibling copy (e.g. 'og-watermark-originals-backup-2026') can't be swept.
$ogwm_dirs = [];

// 1) The exact persisted absolute base, if we have it (the authoritative path).
if ( is_string( $ogwm_backup_base ) && '' !== $ogwm_backup_base ) {
	$ogwm_dirs[] = untrailingslashit( $ogwm_backup_base );
}

// 2) Token-targeted fallback in case the base option was missing but the token
//    is known: reconstruct the exact "<root>/og-watermark-originals-<token>".
if ( is_string( $ogwm_backup_token ) && '' !== $ogwm_backup_token ) {
	$ogwm_suffix  = 'og-watermark-originals-' . $ogwm_backup_token;
	$ogwm_content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
	$ogwm_dirs[]  = trailingslashit( $ogwm_content ) . $ogwm_suffix;

	$ogwm_uploads = wp_upload_dir();
	if ( is_array( $ogwm_uploads ) && empty( $ogwm_uploads['error'] ) && ! empty( $ogwm_uploads['basedir'] ) ) {
		$ogwm_dirs[] = trailingslashit( $ogwm_uploads['basedir'] ) . $ogwm_suffix;
	}
}

/**
 * Recursively delete a directory tree. Kept inline so uninstall has no
 * dependency on the plugin's autoloaded classes.
 *
 * @param string $dir Absolute directory path.
 */
$ogwm_rmtree = static function ( string $dir ) use ( &$ogwm_rmtree ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: [] as $ogwm_entry ) {
		if ( '.' === $ogwm_entry || '..' === $ogwm_entry ) {
			continue;
		}
		$ogwm_path = $dir . DIRECTORY_SEPARATOR . $ogwm_entry;
		if ( is_dir( $ogwm_path ) && ! is_link( $ogwm_path ) ) {
			$ogwm_rmtree( $ogwm_path );
		} else {
			@unlink( $ogwm_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
};

foreach ( array_unique( $ogwm_dirs ) as $ogwm_dir ) {
	$ogwm_rmtree( $ogwm_dir );
}
