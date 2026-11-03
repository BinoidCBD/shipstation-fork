<?php
/**
 * WC_Shipstation_API_Request file.
 *
 * @package WC_ShipStation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WooCommerce\Shipping\ShipStation\Logger;
use WooCommerce\Shipping\ShipStation\Order_Util;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Legacy public class name retained for backward compatibility.
/**
 * WC_Shipstation_API_Request Class
 */
abstract class WC_Shipstation_API_Request {
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

	/**
	 * Stores logger class.
	 *
	 * @var WC_Logger
	 */
	private $log = null;

	/**
	 * Log something.
	 *
	 * Isolated because a log write is a handoff: woocommerce_logging_class lets
	 * any plugin replace the logger, and the dispatcher writes here before the
	 * export opens its own boundary. A handler that printed put its output in
	 * front of the XML declaration - a 200 no parser accepts, which is the
	 * SHIPSTN-171 shape.
	 *
	 * @param string $message Log message.
	 */
	public function log( $message ) {
		Order_Util::log_isolated(
			'the request log write',
			static function () use ( $message ) {
				Logger::debug( (string) $message );
			}
		);
	}

	/**
	 * Run the request
	 */
	public function request() {}

	/**
	 * Validate data.
	 *
	 * @param array $required_fields fields to look for.
	 */
	protected function validate_input( $required_fields ) {
		foreach ( $required_fields as $required ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended --- Using WC_ShipStation_Integration::$auth_key for security verification
			if ( empty( $_GET[ $required ] ) ) {
				/* translators: 1: field name */
				$this->trigger_error( sprintf( esc_html__( 'Missing required param: %s', 'woocommerce-shipstation-integration' ), esc_html( $required ) ) );
			}
		}
	}

	/**
	 * Trigger and log an error.
	 *
	 * @param string $message Error message.
	 * @param int    $status_code Error status code.
	 */
	public function trigger_error( $message, $status_code = 400 ) { //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, false positive on function parameter.
		$this->log( $message );
		wp_send_json_error( $message, $status_code );
	}
}
