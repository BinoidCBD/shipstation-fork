<?php
/**
 * ShipStation REST API Loader file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WooCommerce\Shipping\ShipStation\API\REST\Inventory_Controller;
use WooCommerce\Shipping\ShipStation\API\REST\Orders_Controller;
use WooCommerce\Shipping\ShipStation\API\REST\Diagnostics_Controller;

/**
 * Class REST_API_Loader
 *
 * This class is responsible for loading the REST API routes for the ShipStation integration.
 */
class REST_API_Loader {
	/**
	 * Initialize the REST API routes.
	 */
	public function init() {
		// Include Base REST API class file.
		require_once WC_SHIPSTATION_ABSPATH . 'includes/api/rest/class-api-controller.php';

		// Include Inventory REST API class file.
		require_once WC_SHIPSTATION_ABSPATH . 'includes/api/rest/class-inventory-controller.php';

		// Include Orders REST API class file.
		require_once WC_SHIPSTATION_ABSPATH . 'includes/api/rest/class-orders-controller.php';

		// Include Orders REST API class file.
		require_once WC_SHIPSTATION_ABSPATH . 'includes/api/rest/class-diagnostics-controller.php';

		// Register the REST API routes.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'woocommerce_rest_api_get_rest_namespaces', array( $this, 'register_shipstation_namespaces' ) );
		add_filter( 'woocommerce_rest_is_request_to_rest_api', array( $this, 'allow_plain_permalink_auth' ) );
	}

	/**
	 * Register the REST API routes.
	 */
	public function register_routes() {
		$inventory_controller = new Inventory_Controller();
		$inventory_controller->register_routes();

		$orders_controller = new Orders_Controller();
		$orders_controller->register_routes();

		$diagnostics_controller = new Diagnostics_Controller();
		$diagnostics_controller->register_routes();
	}

	/**
	 * Enable WC consumer key/secret auth on our namespace when using plain permalinks.
	 *
	 * WC's default check only matches request URIs containing `/wp-json/`, so it misses
	 * requests using the `?rest_route=/wc-shipstation/...` fallback. This filter extends
	 * the check to cover our namespace, allowing WC consumer key/secret auth to work
	 * regardless of permalink structure.
	 *
	 * @param bool $is_request_to_rest_api Whether the current request targets the WC REST API.
	 * @return bool
	 */
	public function allow_plain_permalink_auth( $is_request_to_rest_api ) {
		if ( $is_request_to_rest_api ) {
			return $is_request_to_rest_api;
		}

		if ( empty( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $is_request_to_rest_api;
		}

		$rest_route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return 0 === strpos( $rest_route, '/wc-shipstation/' );
	}

	/**
	 * Registers the ShipStation namespaces for the REST API.
	 *
	 * @param array $controllers List of current REST API controllers.
	 *
	 * @return array Updated list of REST API controllers with added ShipStation namespaces.
	 */
	public function register_shipstation_namespaces( array $controllers ): array {
		$controllers['wc-shipstation/v1']['inventory']   = 'WooCommerce\Shipping\ShipStation\API\REST\Inventory_Controller';
		$controllers['wc-shipstation/v1']['orders']      = 'WooCommerce\Shipping\ShipStation\API\REST\Orders_Controller';
		$controllers['wc-shipstation/v1']['diagnostics'] = 'WooCommerce\Shipping\ShipStation\API\REST\Diagnostics_Controller';

		return $controllers;
	}
}
