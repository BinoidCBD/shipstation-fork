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

	/**
	 * Whether the only problem is that the shopper has not entered a full address yet.
	 *
	 * @var bool
	 */
	private $incomplete_address = false;

	/**
	 * Create an exception for an address the shopper has not finished entering.
	 *
	 * @since 5.4.0
	 *
	 * @param string $message Exception message.
	 *
	 * @return self
	 */
	public static function incomplete_address( string $message ): self {
		$exception                     = new self( $message );
		$exception->incomplete_address = true;

		return $exception;
	}

	/**
	 * Whether the only problem is that the shopper has not entered a full address yet.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public function is_incomplete_address(): bool {
		return $this->incomplete_address;
	}
}
