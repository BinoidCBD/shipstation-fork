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
	 * Whether the WPCOM-brokered transport is enabled. Default off.
	 *
	 * Enabled via any of three additive sources, checked in this order:
	 *  1. the merchant-facing "Enable WordPress.com Transport" settings checkbox,
	 *     stored under `wpcom_transport_enabled` in the integration's
	 *     `woocommerce_shipstation_settings` option (SHIPSTN-133);
	 *  2. the WC_SHIPSTATION_WPCOM_TRANSPORT constant (developer override, retained);
	 *  3. the wc_shipstation_wpcom_transport_enabled filter (the legacy snippet path, retained).
	 *
	 * Any one being on enables the transport: a merchant who set the constant or
	 * filter before the checkbox existed cannot turn the feature off by leaving
	 * the box un-ticked — the override still forces it on (resolution is to remove
	 * the snippet/constant). This is the single gate for the whole transport, so
	 * enabling it also widens the strict ShipStation Basic Auth check in
	 * API_Controller::check_namespace_permission() to proxied requests — which is
	 * why this work was gated behind SHIPSTN-132.
	 *
	 * @since 5.0.3
	 * @since 5.1.0 Added the settings-checkbox source.
	 *
	 * @return bool True when the transport is enabled.
	 */
	public static function is_wpcom_transport_enabled(): bool {
		$settings = get_option( 'woocommerce_shipstation_settings', array() );
		if ( is_array( $settings ) && isset( $settings['wpcom_transport_enabled'] ) && 'yes' === $settings['wpcom_transport_enabled'] ) {
			return true;
		}

		return self::is_wpcom_transport_forced_by_override();
	}

	/**
	 * Whether a developer override — the WC_SHIPSTATION_WPCOM_TRANSPORT constant
	 * or the wc_shipstation_wpcom_transport_enabled filter — forces the transport
	 * on regardless of the settings checkbox.
	 *
	 * The settings UI uses this to render the checkbox checked + disabled (and to
	 * explain which override is in control) when an override is active, since the
	 * checkbox cannot turn the feature off while the override stands.
	 *
	 * @since 5.1.0
	 *
	 * @return bool True when the constant or filter forces the transport on.
	 */
	public static function is_wpcom_transport_forced_by_override(): bool {
		if ( defined( 'WC_SHIPSTATION_WPCOM_TRANSPORT' ) && WC_SHIPSTATION_WPCOM_TRANSPORT ) {
			return true;
		}

		/**
		 * Filters whether the WPCOM-brokered transport is enabled.
		 *
		 * @since 5.0.3
		 * @param bool $enabled Whether the feature is enabled. Default false.
		 */
		return (bool) apply_filters( 'wc_shipstation_wpcom_transport_enabled', false );
	}
}
