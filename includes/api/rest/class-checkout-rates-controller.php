<?php
/**
 * ShipStation REST API Checkout Rates Controller file.
 *
 * Provides endpoints for ShipStation to register and deregister Checkout Rates
 * configuration: configure (store rates_url) and deactivate (clear rates_url).
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation\API\REST;

use WooCommerce\Shipping\ShipStation\Checkout\Checkout_Rates_Options;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Checkout_Rates_Controller class.
 */
class Checkout_Rates_Controller extends API_Controller {

	/**
	 * Namespace for the REST API
	 *
	 * @var string
	 */
	protected string $namespace = 'wc-shipstation/v1';

	/**
	 * REST base for the controller.
	 *
	 * @var string
	 */
	protected string $rest_base = 'checkout-rates';

	/**
	 * Register the routes for the controller.
	 *
	 * @since 5.0.9
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/configure',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'configure' ),
				'permission_callback' => array( $this, 'check_update_permission' ),
				'args'                => array(
					'rates_url' => array(
						'description'       => __( 'ShipStation-issued rates URL.', 'woocommerce-shipstation-integration' ),
						'type'              => 'string',
						'sanitize_callback' => static function ( $value ) {
							// Pass non-strings through unchanged so the handler's
							// is_string check returns invalid_rates_url. Without
							// this guard, esc_url_raw() reaches ltrim() and throws
							// a TypeError on arrays/objects under PHP 8.
							return is_string( $value ) ? esc_url_raw( $value ) : $value;
						},
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/deactivate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deactivate' ),
				'permission_callback' => array( $this, 'check_update_permission' ),
			)
		);
	}

	/**
	 * REST API permission callback for GET routes.
	 *
	 * Checkout_Rates_Controller has no GET routes; this exists so shared
	 * Permission_Test_Trait tests can exercise the namespace gate.
	 *
	 * @since 5.0.9
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return bool|WP_Error See API_Controller::check_namespace_permission().
	 */
	public function check_get_permission( WP_REST_Request $request ) {
		return $this->check_namespace_permission( $request, 'system_status', 'read' );
	}

	/**
	 * REST API permission callback for POST /checkout-rates/* routes.
	 *
	 * @since 5.0.9
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return bool|WP_Error See API_Controller::check_namespace_permission().
	 */
	public function check_update_permission( WP_REST_Request $request ) {
		return $this->check_namespace_permission( $request, 'system_status', 'create' );
	}

	/**
	 * Configure Checkout Rates by storing the ShipStation-issued rates URL.
	 *
	 * Validates the URL, rejects SSRF targets, stores via
	 * Checkout_Rates_Options::set_rates_url().
	 *
	 * @since 5.0.9
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function configure( WP_REST_Request $request ): WP_REST_Response {
		// Read via get_param() so the route's declared sanitize_callback
		// (esc_url_raw) and 'type' => 'string' coercion both fire.
		$raw = $request->get_param( 'rates_url' );

		if ( null === $raw ) {
			return $this->build_error_response( 'missing_rates_url', 400 );
		}

		if ( ! is_string( $raw ) ) {
			return $this->build_error_response( 'invalid_rates_url', 400 );
		}

		$url = trim( $raw );

		if ( '' === $url ) {
			return $this->build_error_response( 'missing_rates_url', 400 );
		}

		$validation = $this->validate_rates_url( $url );
		if ( is_wp_error( $validation ) ) {
			return $this->build_error_response( 'invalid_rates_url', 400 );
		}

		$result = Checkout_Rates_Options::set_rates_url( $url );

		if ( ! $result ) {
			return $this->build_error_response( 'storage_failure', 500 );
		}

		return $this->build_success_response();
	}

	/**
	 * Deactivate Checkout Rates by clearing the stored rates URL.
	 *
	 * Idempotent: always returns success even if no URL was stored.
	 *
	 * @since 5.0.9
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function deactivate( WP_REST_Request $request ): WP_REST_Response {
		$result = Checkout_Rates_Options::clear_rates_url();

		if ( ! $result ) {
			return $this->build_error_response( 'storage_failure', 500 );
		}

		return $this->build_success_response();
	}

	/**
	 * Validate a rates URL for safety.
	 *
	 * Rejects:
	 *  - Invalid URLs
	 *  - Non-HTTPS URLs
	 *  - localhost / loopback / private IP ranges (SSRF prevention)
	 *
	 * @param string $url Raw URL to validate.
	 * @return true|WP_Error True when valid; WP_Error otherwise.
	 */
	private function validate_rates_url( string $url ) {
		// FILTER_VALIDATE_URL catches malformed strings and wrong schemes.
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_rates_url' );
		}

		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return new WP_Error( 'invalid_rates_url' );
		}

		// Scheme must be HTTPS.
		if ( 'https' !== strtolower( $parsed['scheme'] ) ) {
			return new WP_Error( 'invalid_rates_url' );
		}

		$host = strtolower( $parsed['host'] );

		// Reject localhost names.
		if ( 'localhost' === $host || substr( $host, -10 ) === '.localhost' ) {
			return new WP_Error( 'invalid_rates_url' );
		}

		// Reject loopback, private and reserved IP ranges. For hostnames, resolve
		// and apply the same range check to every resolved IPv4 address — closes
		// the gap where wp_http_validate_url() does not cover 169.254.0.0/16
		// (cloud-instance metadata endpoints).
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : gethostbynamel( $host );
		if ( ! $ips ) {
			return new WP_Error( 'invalid_rates_url' );
		}
		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return new WP_Error( 'invalid_rates_url' );
			}
		}

		// Defense-in-depth via wp_http_validate_url (scheme, length, and additional checks).
		if ( false === wp_http_validate_url( $url ) ) {
			return new WP_Error( 'invalid_rates_url' );
		}

		return true;
	}

	/**
	 * Build a standard success response.
	 *
	 * @return WP_REST_Response
	 */
	private function build_success_response(): WP_REST_Response {
		return new WP_REST_Response(
			array( 'success' => true ),
			200
		);
	}

	/**
	 * Build a standard error response.
	 *
	 * @param string $message Error code/message.
	 * @param int    $status HTTP status code.
	 * @return WP_REST_Response
	 */
	private function build_error_response( string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => $message,
			),
			$status
		);
	}
}
