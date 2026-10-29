<?php
/**
 * Plugin Name:       WebiSearch
 * Plugin URI:        https://webiloper.ir/
 * Description:       Search analytics for any WordPress site: logs standard, AJAX and live searches, measures result clicks and WooCommerce conversions, finds failed searches and fixes them with synonyms and redirects.
 * Version:           3.3.4
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            webiloper
 * Author URI:        https://webiloper.ir/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       advanced-search-logger
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   10.2
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'ASL_VERSION', '3.3.4' );
define( 'ASL_DB_VERSION', '3.3.2' );
define( 'ASL_PLUGIN_FILE', __FILE__ );
define( 'ASL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ASL_PLUGIN_DIR . 'includes/functions.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-settings.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-normalizer.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-activator.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-db.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-rules.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-logger.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-tracker.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-smart.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-review.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-requests.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-clicks.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-reports.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-shortcodes.php';
require_once ASL_PLUGIN_DIR . 'includes/class-asl-privacy.php';

register_activation_hook( __FILE__, array( 'ASL_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ASL_Activator', 'deactivate' ) );

/**
 * Boot the plugin.
 */
function asl_bootstrap() {
	// Create/upgrade tables when the code is newer than the database (also covers updates
	// uploaded over FTP and network-activated multisite installs, where the activation hook
	// does not run for every site).
	ASL_Activator::maybe_upgrade();

	new ASL_Tracker();
	ASL_Smart::init();
	ASL_Review::init();
	ASL_Requests::init();
	new ASL_Clicks();
	new ASL_Shortcodes();
	new ASL_Privacy();

	add_action( 'asl_daily_cleanup', array( 'ASL_Reports', 'run_daily_cleanup' ) );
	add_action( 'asl_weekly_report', array( 'ASL_Reports', 'send_weekly_report' ) );

	if ( is_admin() ) {
		require_once ASL_PLUGIN_DIR . 'admin/class-asl-export.php';
		require_once ASL_PLUGIN_DIR . 'admin/class-asl-admin.php';
		new ASL_Admin();
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once ASL_PLUGIN_DIR . 'includes/class-asl-cli.php';
		WP_CLI::add_command( 'webisearch', 'ASL_CLI' );
	}
}
add_action( 'plugins_loaded', 'asl_bootstrap' );

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'advanced-search-logger', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	},
	0
);

// Declare compatibility with WooCommerce High-Performance Order Storage and cart/checkout blocks.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

// Multisite: create tables for sites added after network activation.
add_action(
	'wp_initialize_site',
	function ( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( plugin_basename( ASL_PLUGIN_FILE ) ) ) {
			switch_to_blog( $site->blog_id );
			ASL_Activator::install();
			restore_current_blog();
		}
	},
	20
);
