<?php
/**
 * Features class file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized feature flags for the ShipStation integration.
 */
final class Features {

	/**
	 * Whether the checkout-rates feature is enabled.
	 *
	 * @return bool
	 */
	public static function is_checkout_rates_enabled(): bool {
		/**
		 * Filters whether the Checkout Rates feature is enabled.
		 *
		 * @since 4.9.6
		 * @param bool $enabled Whether the feature is enabled. Default false.
		 */
		return (bool) apply_filters( 'wc_shipstation_checkout_rates_enabled', false );
	}
}
