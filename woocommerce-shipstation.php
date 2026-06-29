<?php
/**
 * Plugin Name: ShipStation for WooCommerce
 * Plugin URI: https://woocommerce.com/products/shipstation-integration/
 * Version: 5.2.0
 * Description: Power your entire shipping operation from one platform.
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 * Text Domain: woocommerce-shipstation-integration
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.9
 * Tested up to: 7.0
 * WC requires at least: 10.7
 * WC tested up to: 10.9
 *
 * Copyright: © 2026 WooCommerce
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package WC_ShipStation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WooCommerce\Shipping\ShipStation\Main;

define( 'WC_SHIPSTATION_FILE', __FILE__ );
define( 'WC_SHIPSTATION_ABSPATH', trailingslashit( __DIR__ ) );

if ( ! defined( 'WC_SHIPSTATION_PLUGIN_DIR' ) ) {
	define( 'WC_SHIPSTATION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WC_SHIPSTATION_PLUGIN_URL' ) ) {
	define( 'WC_SHIPSTATION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

define( 'WC_SHIPSTATION_VERSION', '5.2.0' ); // WRCS: DEFINED_VERSION.

// Composer + Jetpack autoloader. Ships with the production zip; may be absent in
// dev checkouts where `composer install` has not been run.
if ( file_exists( WC_SHIPSTATION_ABSPATH . 'vendor/autoload_packages.php' ) ) {
	require_once WC_SHIPSTATION_ABSPATH . 'vendor/autoload_packages.php';
}

require_once WC_SHIPSTATION_ABSPATH . 'includes/class-main.php';

/**
 * Load WooCommerce ShipStation Instance.
 */
function woocommerce_shipstation_instance() {
	return Main::instance();
}

woocommerce_shipstation_instance();

/**
 * Clean up scheduled background jobs on deactivation.
 *
 * Cancels the daily Action Scheduler orphan-key prune (SHIPSTN-142). Guarded by
 * class_exists because the class is only loaded once WooCommerce is active.
 *
 * @return void
 */
function woocommerce_shipstation_deactivate() {
	if ( class_exists( '\WooCommerce\Shipping\ShipStation\Auth_Controller' ) ) {
		\WooCommerce\Shipping\ShipStation\Auth_Controller::unschedule_orphan_prune();
	}
}

register_deactivation_hook( __FILE__, 'woocommerce_shipstation_deactivate' );

/**
 * Create the ShipStation connection-log table on activation (SHIPSTN-142).
 *
 * The class is loaded explicitly here because activation fires before this
 * plugin's plugins_loaded bootstrap has run load_files(). Existing installs get
 * the table via Connection_Log::maybe_install() on the next load, so this only
 * fast-tracks a fresh activation.
 *
 * @return void
 */
function woocommerce_shipstation_activate() {
	// Connection_Log::install() logs via Logger on its failure branch, and this
	// activation hook runs before plugins_loaded fires load_files(), so neither
	// class is loaded yet. Load both explicitly to avoid a fatal that would mask
	// the underlying DB error on a locked-down (no ALTER/CREATE) database.
	require_once WC_SHIPSTATION_ABSPATH . 'includes/class-logger.php';
	require_once WC_SHIPSTATION_ABSPATH . 'includes/class-connection-log.php';
	\WooCommerce\Shipping\ShipStation\Connection_Log::install();
}

register_activation_hook( __FILE__, 'woocommerce_shipstation_activate' );
