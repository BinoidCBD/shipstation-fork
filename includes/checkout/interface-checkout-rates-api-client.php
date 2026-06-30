<?php
/**
 * Checkout Rates API Client Interface file.
 *
 * @package WC_ShipStation
 * @since 4.9.9
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for fetching shipping rates from the ShipStation checkout rates API.
 *
 * @since 4.9.9
 */
interface Checkout_Rates_Api_Client_Interface {

	/**
	 * Fetch shipping rates for the given payload.
	 *
	 * @since 4.9.9
	 *
	 * @param array $payload Rates request payload.
	 *
	 * @return array Rates response data.
	 */
	public function get_rates( array $payload ): array;
}
