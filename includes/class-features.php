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

	/**
	 * Whether the WPCOM-brokered transport spike is enabled. Default off.
	 *
	 * Enabled via either the WC_SHIPSTATION_WPCOM_TRANSPORT constant (checked first)
	 * or the wc_shipstation_wpcom_transport_enabled filter.
	 *
	 * @since 5.0.3
	 *
	 * @return bool True when the spike is enabled.
	 */
	public static function is_wpcom_transport_enabled(): bool {
		if ( defined( 'WC_SHIPSTATION_WPCOM_TRANSPORT' ) && WC_SHIPSTATION_WPCOM_TRANSPORT ) {
			return true;
		}

		/**
		 * Filters whether the WPCOM-brokered transport spike is enabled.
		 *
		 * @since 5.0.3
		 * @param bool $enabled Whether the feature is enabled. Default false.
		 */
		return (bool) apply_filters( 'wc_shipstation_wpcom_transport_enabled', false );
	}
}
