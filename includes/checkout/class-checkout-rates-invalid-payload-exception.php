<?php
/**
 * Checkout Rates Invalid Payload Exception class file.
 *
 * @package WC_ShipStation
 * @since 5.0.9
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by Checkout_Rates_Payload_Validator when the outbound payload fails
 * validation. Callers should catch this and skip the upstream API request — the
 * validator has already logged each violation with field- and filter-level
 * context.
 *
 * @since 5.0.9
 */
class Checkout_Rates_Invalid_Payload_Exception extends \Exception {
}
