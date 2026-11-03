<?php
/**
 * ShipStation Shipping Method class file.
 *
 * @package WC_ShipStation
 * @since 4.9.6
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

use WooCommerce\Shipping\ShipStation\Features;
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
			// static:: so a subclass that narrows the context definition still governs
			// its own rate calculation; self:: would silently bind to this class.
			if ( ! static::is_checkout_context() ) {
				return;
			}

			// Mirror the registration gate in Main::register_shipping_methods(): the toggle
			// only suppresses registration there, so without this runtime guard an instance
			// that survives on a zone (third-party registration, stale cache) would keep
			// serving rates after the merchant switched the feature off.
			if ( ! Features::is_checkout_rates_enabled() ) {
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
				$rates_before = count( $this->rates );
				$this->add_rate( $rate );

				// add_rate() appends exactly one rate to $this->rates, or none if it
				// rejects the args for an empty id/label. Apply the display fields to the
				// rate it actually inserted rather than re-looking it up by our mapped id,
				// which can diverge from the stored key when a third party rewrites it
				// through the woocommerce_shipping_method_add_rate_args filter.
				if ( count( $this->rates ) > $rates_before ) {
					$added_rate = $this->rates[ array_key_last( $this->rates ) ];
					$this->apply_rate_display_fields( $added_rate, $rate );
				}
			}
		} catch ( Checkout_Rates_Invalid_Payload_Exception $e ) {
			// The validator has already logged an incomplete address at debug level.
			if ( $e->is_incomplete_address() ) {
				return;
			}

			Logger::error(
				'Checkout rates: unexpected error during rate calculation. ' . Checkout_Rates_Options::redact( $e->getMessage() )
			);
		} catch ( \Throwable $e ) {
			Logger::error(
				'Checkout rates: unexpected error during rate calculation. ' . Checkout_Rates_Options::redact( $e->getMessage() )
			);
		}
	}

	/**
	 * Apply the mapped description and delivery time to the WC_Shipping_Rate object.
	 *
	 * The add_rate() method only maps id/label/cost/taxes/meta_data, so the native description
	 * and delivery_time fields (WC 9.2+) that the block cart/checkout renders must be set on the
	 * rate object after it is created. The classic checkout, which renders only the label, gets
	 * these details appended at display time by Checkout_Rates_Classic_Label::append_details().
	 *
	 * @since 5.2.1
	 *
	 * @param \WC_Shipping_Rate $wc_rate The rate object add_rate() inserted into $this->rates.
	 * @param array             $rate    Mapped rate array from Checkout_Rates_Response_Mapper.
	 *
	 * @return void
	 */
	private function apply_rate_display_fields( \WC_Shipping_Rate $wc_rate, array $rate ): void {
		if ( isset( $rate['description'] ) ) {
			$wc_rate->set_description( (string) $rate['description'] );
		}

		if ( isset( $rate['delivery_time'] ) ) {
			$wc_rate->set_delivery_time( (string) $rate['delivery_time'] );
		}
	}

	/**
	 * Whether the current request is a checkout render or a checkout-driven AJAX/Store API call.
	 *
	 * Static and public so the cache guard in Checkout_Rates_Options can reuse the exact
	 * same context definition when deciding whether a just-calculated package rate set was
	 * produced in a context where this method could contribute rates (SHIPSTN-157). Reads
	 * only request globals, never instance state.
	 *
	 * A subclass overriding this must declare it static too. PHP fatals at class-declaration
	 * time on a non-static override, and the error is not catchable.
	 *
	 * @since 5.0.8
	 * @since 5.3.0 Promoted to public static for reuse by the rate-cache guard.
	 * @since 5.3.9 Filterable.
	 *
	 * @return bool
	 */
	public static function is_checkout_context(): bool {
		/**
		 * Filters whether this request may fetch ShipStation checkout rates.
		 *
		 * The answer also decides whether the request's rates stay in the session.
		 *
		 * @since 5.3.9
		 *
		 * @param bool $is_checkout_context Whether this is a cart or checkout request.
		 */
		return (bool) apply_filters( 'woocommerce_shipstation_checkout_rates_is_checkout_context', self::request_is_checkout_context() );
	}

	/**
	 * Whether the request is a cart or checkout request, before the filter.
	 *
	 * @since 5.3.9
	 *
	 * @return bool
	 */
	private static function request_is_checkout_context(): bool {
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
