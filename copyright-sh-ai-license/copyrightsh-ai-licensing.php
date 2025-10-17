<?php
/**
 * Plugin Name: Copyright.sh – AI License
 * Plugin URI:  https://copyright.sh/
 * Description: Declare, monetise, and enforce AI licence policies (meta tags, /ai-license.txt, crawler enforcement, JWT tokens) for WordPress sites.
 * Version:     2.0.0
 * Requires at least: 6.2
 * Tested up to: 6.8.2
 * Requires PHP: 7.4
 * Author:      Copyright.sh
 * Author URI:  https://copyright.sh
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: copyright-sh-ai-license
 *
 * @package CSH_AI_License
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CSH_AI_LICENSE_FILE', __FILE__ );
define( 'CSH_AI_LICENSE_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSH_AI_LICENSE_URL', plugin_dir_url( __FILE__ ) );
define( 'CSH_AI_LICENSE_VERSION', '2.0.0' );

require_once CSH_AI_LICENSE_DIR . 'includes/Autoloader.php';

CSH\AI_License\Autoloader::register();

register_activation_hook(
	__FILE__,
	static function () {
		CSH\AI_License\Plugin::instance()->activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		CSH\AI_License\Plugin::instance()->deactivate();
	}
);

add_action(
	'plugins_loaded',
	static function () {
		CSH\AI_License\Plugin::instance()->boot();
	}
);
