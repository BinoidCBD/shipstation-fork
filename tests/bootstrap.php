<?php
/**
 * PHPUnit bootstrap for the ShipStation fork.
 *
 * Runs against the standard WordPress PHPUnit test library. WooCommerce is a hard
 * dependency of this plugin, so it is loaded (and installed) before the plugin.
 *
 * Setup:
 *   1. Install the WP test suite (e.g. `bin/install-wp-tests.sh` from a scaffolded
 *      plugin, or wp-env / wp-phpunit). Point WP_TESTS_DIR at it.
 *   2. Make WooCommerce available to the test install. By default this looks for it
 *      one level up from the plugins dir; override with the WC_PLUGIN_DIR env var.
 *   3. From the plugin root: `phpunit` (uses phpunit.xml.dist).
 *
 * @package WC_ShipStation
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php — install the WP test suite and/or set WP_TESTS_DIR.\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Load WooCommerce (dependency) and then this plugin before WP finishes loading.
 */
function _wc_shipstation_manually_load_plugin() {
	$wc_main = getenv( 'WC_PLUGIN_DIR' )
		? rtrim( getenv( 'WC_PLUGIN_DIR' ), '/\\' ) . '/woocommerce.php'
		: dirname( __DIR__, 2 ) . '/woocommerce/woocommerce.php';

	if ( file_exists( $wc_main ) ) {
		require $wc_main;
	}

	require dirname( __DIR__ ) . '/woocommerce-shipstation.php';
}
tests_add_filter( 'muplugins_loaded', '_wc_shipstation_manually_load_plugin' );

/**
 * Install WooCommerce's schema once the test suite is up, so order/product factories work.
 */
tests_add_filter(
	'setup_theme',
	function () {
		if ( class_exists( '\WC_Install' ) ) {
			\WC_Install::install();
		}
	}
);

require "{$_tests_dir}/includes/bootstrap.php";
