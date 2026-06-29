<?php
/**
 * ShipStation Shipping Method class file.
 *
 * @package WC_ShipStation
 * @since 4.9.6
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

use WooCommerce\Shipping\ShipStation\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ShipStation checkout rates shipping method.
 *
 * @since 4.9.6
 */
class Checkout_Rates_Shipping_Method extends \WC_Shipping_Method {

	/**
	 * API client instance.
	 *
	 * @var Checkout_Rates_Api_Client_Interface|null
	 */
	private $api_client = null;

	/**
	 * Constructor.
	 *
	 * @since 4.9.6
	 *
	 * @param int $instance_id Shipping method instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		parent::__construct( $instance_id );

		$this->id                 = Checkout_Rates_Options::SHIPPING_METHOD_ID;
		$this->method_title       = __( 'ShipStation Rates', 'woocommerce-shipstation-integration' );
		$this->method_description = __( 'Provide real-time shipping rates from ShipStation during checkout.', 'woocommerce-shipstation-integration' );
		$this->supports           = array( 'shipping-zones', 'instance-settings' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option( 'title', $this->method_title );
	}

	/**
	 * Define instance form fields.
	 *
	 * @since 4.9.6
	 */
	public function init_form_fields() {
		$this->instance_form_fields = array(
			'title' => array(
				'title'   => __( 'Title', 'woocommerce-shipstation-integration' ),
				'type'    => 'text',
				'default' => __( 'ShipStation Rates', 'woocommerce-shipstation-integration' ),
			),
		);
	}

	/**
	 * Set the API client instance.
	 *
	 * @since 5.0.8
	 *
	 * @param Checkout_Rates_Api_Client_Interface $client API client.
	 *
	 * @return void
	 */
	public function set_api_client( Checkout_Rates_Api_Client_Interface $client ): void {
		$this->api_client = $client;
	}

	/**
	 * Calculate shipping rates.
	 *
	 * @since 4.9.6
	 *
	 * @param array $package Shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
		try {
			if ( ! $this->is_checkout_context() ) {
				return;
			}

			if ( ! Checkout_Rates_Options::is_configured() ) {
				return;
			}

			// Builder and mapper are pure-function helpers with no external dependencies,
			// so they are instantiated inline. Only the API client — which performs the
			// outbound HTTP call — exposes a setter seam (set_api_client) for tests.
			$builder = new Checkout_Rates_Request_Builder();
			$payload = $builder->build( $package );

			$client   = $this->api_client ? $this->api_client : new Checkout_Rates_Api_Client();
			$response = $client->get_rates( $payload );

			if ( empty( $response ) ) {
				return;
			}

			$mapper = new Checkout_Rates_Response_Mapper();
			$rates  = $mapper->map( $response );

			foreach ( $rates as $rate ) {
				$this->add_rate( $rate );
			}
		} catch ( \Throwable $e ) {
			Logger::error(
				'Checkout rates: unexpected error during rate calculation. ' . Checkout_Rates_Options::redact( $e->getMessage() )
			);
		}
	}

	/**
	 * Whether the current request is a checkout render or a checkout-driven AJAX/Store API call.
	 *
	 * @since 5.0.8
	 *
	 * @return bool
	 */
	protected function is_checkout_context(): bool {
		// Whitelists the WC surfaces that actually present shipping rates to the customer
		// (cart and checkout, classic or block) so background callers like add-to-cart
		// fragment refreshes and unrelated AJAX hits don't trigger outbound rate requests.
		// Classic Cart / Checkout page render — has_block() below additionally catches the
		// block-based equivalents when embedded on a custom-slug page.
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( function_exists( 'has_block' ) && ( has_block( 'woocommerce/cart' ) || has_block( 'woocommerce/checkout' ) ) ) {
			return true;
		}

		// Classic AJAX endpoints:
		// - update_order_review: address / shipping / payment changes at classic checkout.
		// - update_shipping_method: shipping option change (classic checkout + classic cart shipping calculator).
		// - checkout: the form submission itself.
		if ( isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WC handles its own nonce; we only branch on the action name.
			$action = sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $action, array( 'update_order_review', 'update_shipping_method', 'checkout' ), true ) ) {
				return true;
			}
		}

		// Block Cart / Checkout dispatch through the Store API. The Checkout block batches
		// multiple cart mutations (including address changes) through /wc/store/v*/batch,
		// so the batch route must be whitelisted alongside /cart and /checkout.
		// Mirrors WC_Connect_Functions::is_store_api_call() in woocommerce-shipping.
		$rest_route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
		if ( '' === $rest_route && isset( $_SERVER['REQUEST_URI'] ) ) {
			// Match against the path component only — a literal `/wc/store/v1/cart`
			// appearing inside a query-string value should not flip the gate.
			$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
			$rest_route  = is_string( $path ) ? $path : '';
		}
		if ( '' !== $rest_route && preg_match( '#(?:^|/)wc/store/v[0-9]+/(?:batch|cart|checkout)(?:/|$)#', $rest_route ) ) {
			return true;
		}

		return false;
	}
}
