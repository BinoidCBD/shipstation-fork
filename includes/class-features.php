<?php
/**
 * Features class file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

use WooCommerce\Shipping\ShipStation\Checkout\Checkout_Rates_Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized feature flags for the ShipStation integration.
 */
final class Features {

	/**
	 * Store base countries Checkout Rates is available for. ShipStation limits
	 * the feature to US accounts at launch (SHIPSTN-155); add 'CA' here once
	 * ShipStation expands support to Canada.
	 *
	 * WooCommerce lists the US territories as separate base countries, but
	 * ShipStation confirmed they are in scope for the US launch, so they are
	 * allowed alongside 'US': Puerto Rico, Guam, U.S. Virgin Islands, American
	 * Samoa, Northern Mariana Islands, and US Minor Outlying Islands.
	 *
	 * @var string[]
	 */
	private const CHECKOUT_RATES_SUPPORTED_COUNTRIES = array( 'US', 'PR', 'GU', 'VI', 'AS', 'MP', 'UM' );

	/**
	 * Whether the checkout-rates feature is enabled.
	 *
	 * Requires a supported store base country plus the merchant toggle (or the
	 * enable filter): the country restriction is absolute, so neither the
	 * toggle nor the filter can surface the feature where ShipStation will not
	 * return rates.
	 *
	 * @return bool
	 */
	public static function is_checkout_rates_enabled(): bool {
		if ( ! self::is_checkout_rates_supported_country() ) {
			return false;
		}

		$stored = Checkout_Rates_Options::get_enabled();
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public hook name retained for backward compatibility.
		/**
		 * Filters whether the Checkout Rates feature is enabled.
		 *
		 * Only consulted when the store base country supports Checkout Rates, which
		 * callers can test with Features::is_checkout_rates_supported_country(). A
		 * callback returning true cannot surface the feature on an unsupported store.
		 *
		 * @since 4.9.6
		 * @param bool $enabled Whether the feature is enabled. Default: value of the
		 *                      merchant-facing checkbox (woocommerce_shipstation_settings['checkout_rates_enabled']).
		 */
		return (bool) apply_filters( 'wc_shipstation_checkout_rates_enabled', $stored );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Whether the store base country is one Checkout Rates supports.
	 *
	 * Reads the base location option directly (wc_get_base_location()) rather
	 * than WC()->countries so the check is safe before the countries instance
	 * exists.
	 *
	 * The country is upper-cased before the match. The Country / State selector
	 * always stores an uppercase ISO code, but an import or a callback on
	 * woocommerce_get_base_location can leave a lowercase one behind, and a
	 * supported store should not lose rates over letter case.
	 *
	 * @since 5.3.0
	 *
	 * @return bool
	 */
	public static function is_checkout_rates_supported_country(): bool {
		$base_location = wc_get_base_location();
		$country       = strtoupper( (string) ( $base_location['country'] ?? '' ) );

		return in_array( $country, self::CHECKOUT_RATES_SUPPORTED_COUNTRIES, true );
	}

	/**
	 * Whether the prepaid duties and taxes feature is enabled. Default off.
	 *
	 * Always false while Checkout Rates is disabled, since the feature builds on it.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public static function is_gpdt_enabled(): bool {
		if ( ! self::is_checkout_rates_enabled() ) {
			return false;
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Matches the existing wc_shipstation_* hook family.
		/**
		 * Filters whether the prepaid duties and taxes feature is enabled.
		 *
		 * @since 5.4.0
		 * @param bool $enabled Whether the feature is enabled. Default false.
		 */
		return (bool) apply_filters( 'wc_shipstation_gpdt_enabled', false );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Whether TLS was terminated before the request reached this origin: the store
	 * is served over HTTPS but this request arrived at PHP as plain HTTP, which is
	 * what a proxy such as Cloudflare "Flexible" looks like from here.
	 *
	 * WooCommerce accepts Basic Auth REST key credentials only over HTTPS, and
	 * ShipStation sends Basic Auth, so such a store returns 401 for every
	 * ShipStation request whatever the credentials (SHIPSTN-166).
	 *
	 * Neither input can be spoofed by another request: is_ssl() reflects only the
	 * connection PHP received, home_url() reads the `home` option. Never
	 * X-Forwarded-Proto or CF-Visitor, which any client can send. site_url() is
	 * unusable here because it rewrites its scheme to http while is_ssl() is false.
	 *
	 * @since 5.3.3
	 *
	 * @return bool
	 */
	public static function is_ssl_terminated_upstream(): bool {
		if ( is_ssl() ) {
			return false;
		}

		return wp_is_home_url_using_https();
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

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public hook name retained for backward compatibility.
		/**
		 * Filters whether the WPCOM-brokered transport is enabled.
		 *
		 * @since 5.0.3
		 * @param bool $enabled Whether the feature is enabled. Default false.
		 */
		return (bool) apply_filters( 'wc_shipstation_wpcom_transport_enabled', false );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}
}
