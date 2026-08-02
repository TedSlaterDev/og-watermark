<?php
/**
 * Plugin Name:       OG Watermark
 * Plugin URI:        https://orchardgrove.com/
 * Description:       Pixel-bakes watermarks into image files — text or logo, with safe pristine backups you can restore.
 * Version:           1.2.9
 * Requires PHP:      8.1
 * Requires at least: 6.0
 * Author:            Orchard Grove Media, LLC
 * Author URI:        https://orchardgrove.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       og-watermark
 * Domain Path:       /languages
 *
 * @package OrchardGrove\OgWatermark
 */

declare( strict_types=1 );

namespace OrchardGrove\OgWatermark;

defined( 'ABSPATH' ) || exit;

define( 'OGWM_VERSION', '1.2.9' );
define( 'OGWM_FILE', __FILE__ );
define( 'OGWM_DIR', plugin_dir_path( __FILE__ ) );
define( 'OGWM_URL', plugin_dir_url( __FILE__ ) );
define( 'OGWM_BASENAME', plugin_basename( __FILE__ ) );

require_once OGWM_DIR . 'src/Autoloader.php';
Autoloader::register();

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
