<?php
/**
 * Plugin Name: ShipStation for WooCommerce
 * Plugin URI: https://woocommerce.com/products/shipstation-integration/
 * Version: 4.9.8
 * Description: Power your entire shipping operation from one platform.
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 * Text Domain: woocommerce-shipstation-integration
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.8
 * Tested up to: 6.9
 * WC requires at least: 10.4
 * WC tested up to: 10.6
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

define( 'WC_SHIPSTATION_VERSION', '4.9.8' ); // WRCS: DEFINED_VERSION.

require_once WC_SHIPSTATION_ABSPATH . 'includes/class-main.php';

/**
 * Load WooCommerce ShipStation Instance.
 */
function woocommerce_shipstation_instance() {
	return Main::instance();
}

woocommerce_shipstation_instance();
