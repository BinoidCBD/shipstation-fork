<?php
/**
 * Centralized storage and redaction for Checkout Rates options.
 *
 * Option keys, accessors, and a log redaction helper used by the live API
 * client, REST controllers, settings UI, and logger. URL values are treated
 * as opaque per project requirements — sanitization belongs at the REST
 * /configure boundary, not in this storage class.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

use WooCommerce\Shipping\ShipStation\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized accessor for Checkout Rates options.
 */
final class Checkout_Rates_Options {

	/**
	 * Canonical shipping method id. Lives here (not on Checkout_Rates_Shipping_Method)
	 * so zone-method lookups don't require loading the shipping method class file.
	 */
	const SHIPPING_METHOD_ID = 'shipstation_checkout_rates';

	/**
	 * Order shipping item meta key for the selected quote's rate code (the
	 * ShipStation `code`, e.g. `dos_…`). Underscore-prefixed so WooCommerce
	 * treats it as protected and hides it from the admin order screen, the
	 * customer order/email views, and the Store API. Exported as
	 * `shipping_preferences.preplanned_fulfillment_id`.
	 */
	const RATE_CODE_META_KEY = '_shipstation_rate_code';

	/**
	 * Order shipping item meta key for the response-level quote id. Protected
	 * (underscore-prefixed) for the same reasons as RATE_CODE_META_KEY.
	 */
	const QUOTE_ID_META_KEY = '_shipstation_quote_id';

	/**
	 * Option key for the ShipStation-issued rates URL.
	 */
	const OPTION_RATES_URL = 'wc_shipstation_checkout_rates_url';

	/**
	 * Key within the woocommerce_shipstation_settings array for the merchant-side enable flag.
	 */
	const OPTION_ENABLED = 'checkout_rates_enabled';

	/**
	 * Sentinel returned when the URL is redacted from log output.
	 */
	const REDACTED_TOKEN = '<redacted-rates-url>';

	/**
	 * Whether WooCommerce calculated and cached a package rate set during this request.
	 *
	 * @since 5.3.0
	 *
	 * @var bool
	 */
	private static $package_rates_calculated = false;

	/**
	 * Get the configured rates URL.
	 *
	 * @since 5.0.9
	 *
	 * @return string Empty string when unset.
	 */
	public static function get_rates_url(): string {
		$value = get_option( self::OPTION_RATES_URL, '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Store the rates URL.
	 *
	 * @since 5.0.9
	 *
	 * @param string $url Opaque ShipStation rates URL.
	 *
	 * @return bool True when the value was stored, or when the new value already
	 *              matches the stored one (no update_option() call is issued in
	 *              that case, so pre_update_option_* filters do not fire).
	 */
	public static function set_rates_url( string $url ): bool {
		if ( self::get_rates_url() === $url ) {
			return true;
		}
		$stored = update_option( self::OPTION_RATES_URL, $url, false );
		if ( $stored ) {
			// Provisioning (or changing) the URL changes whether rates can be
			// served, so any cached package rates are now stale.
			self::flush_shipping_rate_cache();
		}
		return $stored;
	}

	/**
	 * Delete the stored rates URL.
	 *
	 * @since 5.0.9
	 *
	 * @return bool True when the option was removed or was already absent; false only when the delete operation itself failed.
	 */
	public static function clear_rates_url(): bool {
		if ( ! self::is_configured() ) {
			return true;
		}
		$deleted = delete_option( self::OPTION_RATES_URL );
		if ( $deleted ) {
			// Removing the URL disables the feature, so cached package rates are stale.
			self::flush_shipping_rate_cache();
		}
		return $deleted;
	}

	/**
	 * Whether a rates URL is currently stored.
	 *
	 * @since 5.0.9
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::get_rates_url();
	}

	/**
	 * Whether the merchant-side enable flag is on.
	 *
	 * Reads from woocommerce_shipstation_settings['checkout_rates_enabled'], which
	 * is written by WC integration settings on save — same pattern as WPCOM transport.
	 *
	 * @since 5.2.0
	 *
	 * @return bool
	 */
	public static function get_enabled(): bool {
		$settings = get_option( 'woocommerce_shipstation_settings', array() );
		return is_array( $settings )
			&& isset( $settings[ self::OPTION_ENABLED ] )
			&& 'yes' === $settings[ self::OPTION_ENABLED ];
	}

	/**
	 * Set the merchant-side enable flag.
	 *
	 * Updates woocommerce_shipstation_settings['checkout_rates_enabled'].
	 * Returns true when the stored value already matches $enabled (no-op).
	 *
	 * @since 5.2.0
	 *
	 * @param bool $enabled Desired state.
	 *
	 * @return bool True when stored or when the new value already matches.
	 */
	public static function set_enabled( bool $enabled ): bool {
		if ( self::get_enabled() === $enabled ) {
			return true;
		}
		$settings = get_option( 'woocommerce_shipstation_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ self::OPTION_ENABLED ] = $enabled ? 'yes' : 'no';
		return (bool) update_option( 'woocommerce_shipstation_settings', $settings );
	}

	/**
	 * Force WooCommerce to recompute shipping rates on the next request.
	 *
	 * WC caches calculated package rates in the customer session keyed by a hash
	 * that includes WC_Cache_Helper::get_transient_version( 'shipping' ) (see
	 * WC_Shipping::calculate_shipping_for_package()). Regenerating that version
	 * invalidates every cached package, so the next checkout re-runs the shipping
	 * methods. Without it, switching the toggle off keeps serving the ShipStation
	 * rates WC cached while the feature was on.
	 *
	 * @since 5.2.0
	 *
	 * @return void
	 */
	public static function flush_shipping_rate_cache(): void {
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	/**
	 * Invalidate the shipping cache when the merchant enable flag changes.
	 *
	 * Hooked to update_option_woocommerce_shipstation_settings so it covers both the
	 * WC settings-form save and programmatic set_enabled() writes. Only a change to
	 * the checkout_rates_enabled sub-key flushes; other settings changes are ignored.
	 *
	 * @since 5.2.0
	 *
	 * @param mixed $old_value Previous woocommerce_shipstation_settings value.
	 * @param mixed $new_value New woocommerce_shipstation_settings value.
	 *
	 * @return void
	 */
	public static function flush_shipping_cache_on_settings_change( $old_value, $new_value ): void {
		$old = ( is_array( $old_value ) && isset( $old_value[ self::OPTION_ENABLED ] ) ) ? $old_value[ self::OPTION_ENABLED ] : '';
		$new = ( is_array( $new_value ) && isset( $new_value[ self::OPTION_ENABLED ] ) ) ? $new_value[ self::OPTION_ENABLED ] : '';

		if ( $old !== $new ) {
			self::flush_shipping_rate_cache();
		}
	}

	/**
	 * Invalidate the shipping cache when the store base country changes.
	 *
	 * The supported-country gate (SHIPSTN-155) is a second off-switch alongside the
	 * merchant toggle, but no core hook regenerates the shipping transient version
	 * when woocommerce_default_country changes. Without this, a session that cached
	 * ShipStation rates while the store was US keeps being served them after the
	 * merchant moves the store to an unsupported country.
	 *
	 * Only a change to the country segment flushes — 'US:CA' to 'US:NY' cannot move
	 * the store across the supported-country boundary.
	 *
	 * @since 5.3.0
	 *
	 * @param mixed $old_value Previous woocommerce_default_country value (e.g. 'US:CA').
	 * @param mixed $new_value New woocommerce_default_country value.
	 *
	 * @return void
	 */
	public static function flush_shipping_cache_on_base_country_change( $old_value, $new_value ): void {
		$old = wc_format_country_state_string( (string) $old_value );
		$new = wc_format_country_state_string( (string) $new_value );

		if ( $old['country'] !== $new['country'] ) {
			self::flush_shipping_rate_cache();
		}
	}

	/**
	 * Clear the current customer session's cached package shipping rates.
	 *
	 * WC caches each package's calculated rate set in the customer session under a
	 * `shipping_for_package_{key}` key, where {key} is the package's index in
	 * WC_Cart::get_shipping_packages() (see WC_Shipping::calculate_shipping_for_package()).
	 * Unlike flush_shipping_rate_cache(), which regenerates the global shipping
	 * transient version and so invalidates every customer's cache, this clears only
	 * the current session's keys — the right scope for a per-customer cart mutation.
	 * Setting a session value to null unsets it (WC_Session::set()).
	 *
	 * The keys are derived from the live cart packages rather than from
	 * WC()->session->get_session_data(), which re-reads the persisted DB row and so
	 * would miss the rate set the current request cached in memory during
	 * calculate_totals() — the exact set that poisons checkout (SHIPSTN-157). A stale
	 * higher-index key left by a cart that has since shed a package is harmless: with
	 * fewer packages, checkout never reads it.
	 *
	 * @since 5.3.0
	 *
	 * @return void
	 */
	public static function clear_session_shipping_rate_cache(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			return;
		}

		foreach ( array_keys( WC()->cart->get_shipping_packages() ) as $package_key ) {
			WC()->session->set( 'shipping_for_package_' . $package_key, null );
		}
	}

	/**
	 * Record that WooCommerce calculated — and therefore cached — a package rate set.
	 *
	 * Hooked to woocommerce_package_rates, which WC_Shipping::calculate_shipping_for_package()
	 * applies only inside its recalculation branch, immediately before it writes the rate set
	 * to the customer session. That makes it an exact signal of "a rate set was just cached",
	 * which is what discard_non_checkout_rate_cache() needs to distinguish a set this request
	 * produced from one it merely read back.
	 *
	 * @since 5.3.0
	 *
	 * @param mixed $rates Calculated package rates. Returned unmodified.
	 *
	 * @return mixed
	 */
	public static function note_package_rates_calculated( $rates ) {
		self::$package_rates_calculated = true;
		return $rates;
	}

	/**
	 * Drop package rates WooCommerce cached outside a checkout context.
	 *
	 * Outside a checkout context the ShipStation Rates method contributes nothing
	 * (Checkout_Rates_Shipping_Method::is_checkout_context()), so WC caches a
	 * ShipStation-less package rate set that a later checkout reuses via the matching
	 * package hash, showing "no shipping options" (SHIPSTN-157).
	 *
	 * Runs on shutdown, before WC_Session_Handler saves the session (priority 20), so it
	 * fires after every rate-caching path in the request: a checkout-context set is kept,
	 * anything else is discarded before it can be persisted and replayed. Guarding at
	 * persistence time is what makes it robust against the requests that trail an
	 * add-to-cart — notably wc-ajax=get_refreshed_fragments, which is not a checkout
	 * context and would re-poison a cache cleared on a cart-mutation hook.
	 *
	 * Only a rate set this request calculated is eligible for discarding. A request that
	 * merely replayed a cached set leaves it alone: that set was either produced in a
	 * checkout context and is good, or produced outside one and was already discarded at
	 * that request's own shutdown. Without this condition, every non-checkout page view
	 * would throw away a valid rate set — including the live rates of any other carrier
	 * sharing the package.
	 *
	 * No rate request is fired here. Gated to when Checkout Rates is active.
	 *
	 * @since 5.3.0
	 *
	 * @return void
	 */
	public static function discard_non_checkout_rate_cache(): void {
		// Cheapest guard first: short-circuits every request that cached nothing.
		if ( ! self::$package_rates_calculated ) {
			return;
		}

		if ( ! Features::is_checkout_rates_enabled() || ! self::is_configured() ) {
			return;
		}

		// The shipping method class loads lazily, in the woocommerce_shipping_methods filter
		// that runs during the very calculation which set the flag above — so with the
		// feature active it is loaded by now. $autoload = false keeps this off the
		// autoloader regardless, and a missing class means no ShipStation rate could have
		// been contributed, so the set is discarded.
		if ( class_exists( Checkout_Rates_Shipping_Method::class, false )
			&& Checkout_Rates_Shipping_Method::is_checkout_context() ) {
			return;
		}

		self::clear_session_shipping_rate_cache();
	}

	/**
	 * Whether the Checkout Rates settings section should render on the current request.
	 *
	 * Returns true only when the store base country supports Checkout Rates and
	 * the current request is a render/save of the ShipStation integration
	 * settings screen (not REST, AJAX, cron, or any other admin screen).
	 * Intentionally does not gate on Features::is_checkout_rates_enabled() — the
	 * section must always be visible on supported stores so merchants can find
	 * the enable toggle. The country gate is the exception (SHIPSTN-155): on
	 * unsupported stores the toggle could never do anything, so the whole
	 * section stays hidden.
	 *
	 * @since 5.0.9
	 * @since 5.2.0 Removed the feature-flag gate to prevent merchant deadlock.
	 * @since 5.3.0 Added the supported-country gate.
	 *
	 * @return bool
	 */
	public static function should_render_settings_section(): bool {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( ! Features::is_checkout_rates_supported_country() ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only signal of which screen is being rendered; nonces are enforced by WC at save time.
		$page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'wc-settings' === $page
			&& 'integration' === $tab
			&& 'shipstation' === $section;
	}

	/**
	 * Whether a ShipStation Rates shipping method instance is attached to any
	 * shipping zone (including the "Locations not covered" zone 0), regardless
	 * of whether the instance is currently enabled.
	 *
	 * Pairs with is_shipping_method_enabled() — together they distinguish the
	 * three real-world states a merchant can land in: no instance, instance
	 * present but disabled, or instance present and enabled.
	 *
	 * @since 5.0.9
	 *
	 * @return bool
	 */
	public static function is_shipping_method_added(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single indexed EXISTS check; running on the ShipStation settings screen only, and wp_cache_* would just push invalidation complexity onto every zone-method admin action with no measurable win.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				"SELECT 1 FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = %s LIMIT 1",
				self::SHIPPING_METHOD_ID
			)
		);

		return null !== $found;
	}

	/**
	 * Whether at least one ShipStation Rates shipping method instance exists
	 * AND is currently enabled (is_enabled = 1) on any shipping zone.
	 *
	 * This is what the merchant cares about for the "is checkout actually
	 * serving live rates right now" answer.
	 *
	 * @since 5.0.9
	 *
	 * @return bool
	 */
	public static function is_shipping_method_enabled(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single indexed EXISTS check; running on the ShipStation settings screen only, and wp_cache_* would just push invalidation complexity onto every zone-method admin action with no measurable win.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				"SELECT 1 FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = %s AND is_enabled = 1 LIMIT 1",
				self::SHIPPING_METHOD_ID
			)
		);

		return null !== $found;
	}

	/**
	 * Build the read-only HTML for the Checkout Rates settings section.
	 *
	 * Four branches reflect the merchant's actual position:
	 *   - Not configured: no rates URL stored yet.
	 *   - Disabled: rates URL stored but the merchant toggle (get_enabled()) is off.
	 *   - Connected: toggle on, rates URL stored, but no enabled instance on a zone.
	 *   - Active: toggle on, rates URL stored, AND at least one enabled instance.
	 *
	 * Keyed on the merchant checkbox (get_enabled()), so the section can never
	 * read "Connected" or "Active" while the merchant has the feature switched
	 * off — it never claims rates are being served when the toggle is off.
	 *
	 * The Connected branch further differentiates whether a disabled instance
	 * exists (so the merchant just needs to toggle it on) versus no instance
	 * at all (so they need to add one first). Otherwise the merchant gets
	 * told to "add the method" when they've already added it.
	 *
	 * @since 5.0.9
	 * @since 5.2.0 Added the Disabled branch so the status tracks the merchant toggle.
	 *
	 * @return string HTML safe to embed in the WC settings 'description' slot.
	 */
	public static function get_settings_description_html(): string {
		if ( ! self::is_configured() ) {
			return self::render_not_configured_html();
		}

		if ( ! self::get_enabled() ) {
			return self::render_disabled_html();
		}

		if ( self::is_shipping_method_enabled() ) {
			return self::render_active_html();
		}

		return self::render_connected_html( self::is_shipping_method_added() );
	}

	/**
	 * "Not configured" branch — no rates URL stored.
	 *
	 * @return string
	 */
	private static function render_not_configured_html(): string {
		$status_label = sprintf(
			'<strong class="wc-shipstation-status wc-shipstation-status--inactive">%s</strong>',
			esc_html__( 'Not configured', 'woocommerce-shipstation-integration' )
		);

		$status_line = sprintf(
			/* translators: %s: status label HTML (already escaped). */
			esc_html__( '%s — Checkout Rates is not yet enabled for this store.', 'woocommerce-shipstation-integration' ),
			$status_label
		);

		$instructions = sprintf(
			/* translators: %1$s: name of the Checkout Rates tab in ShipStation (already wrapped in <strong>). %2$s: name of the Connect to Store button in ShipStation (already wrapped in <strong>). */
			esc_html__( 'Open your ShipStation account, go to the %1$s tab, and click %2$s. Once provisioned, this section will switch to Connected automatically.', 'woocommerce-shipstation-integration' ),
			'<strong>' . esc_html__( 'Checkout Rates', 'woocommerce-shipstation-integration' ) . '</strong>',
			'<strong>' . esc_html__( 'Connect to Store', 'woocommerce-shipstation-integration' ) . '</strong>'
		);

		return '<p>' . $status_line . '</p><p class="description">' . $instructions . '</p>';
	}

	/**
	 * "Disabled" branch — rates URL stored but the merchant toggle is off.
	 *
	 * @since 5.2.0
	 *
	 * @return string
	 */
	private static function render_disabled_html(): string {
		$status_label = sprintf(
			'<strong class="wc-shipstation-status wc-shipstation-status--inactive">%s</strong>',
			esc_html__( 'Disabled', 'woocommerce-shipstation-integration' )
		);

		$status_line = sprintf(
			/* translators: %s: status label HTML (already escaped). */
			esc_html__( '%s — ShipStation is connected, but Checkout Rates is switched off. Tick the box below to start serving live rates at checkout.', 'woocommerce-shipstation-integration' ),
			$status_label
		);

		return '<p>' . $status_line . '</p>';
	}

	/**
	 * "Connected" branch — rates URL stored, no enabled instance on any zone.
	 *
	 * @param bool $instance_exists Whether a (disabled) instance is already on a zone.
	 *
	 * @return string
	 */
	private static function render_connected_html( bool $instance_exists ): string {
		$status_label = sprintf(
			'<strong class="wc-shipstation-status wc-shipstation-status--pending">%s</strong>',
			esc_html__( 'Connected', 'woocommerce-shipstation-integration' )
		);

		$method_name = '<em>' . esc_html__( 'ShipStation Rates', 'woocommerce-shipstation-integration' ) . '</em>';

		if ( $instance_exists ) {
			$status_line = sprintf(
				/* translators: %1$s: status label HTML (already escaped). %2$s: method name (already wrapped in <em>). */
				esc_html__( '%1$s — ShipStation is connected to your store, but %2$s is currently disabled.', 'woocommerce-shipstation-integration' ),
				$status_label,
				$method_name
			);
			$notice = sprintf(
				/* translators: %s: method name (already wrapped in <em>). */
				esc_html__( 'Enable the %s method on the zone where you added it to start serving live rates at checkout.', 'woocommerce-shipstation-integration' ),
				$method_name
			);
		} else {
			$status_line = sprintf(
				/* translators: %s: status label HTML (already escaped). */
				esc_html__( '%s — ShipStation is connected to your store, but no zone is using Checkout Rates.', 'woocommerce-shipstation-integration' ),
				$status_label
			);
			$notice = sprintf(
				/* translators: %s: method name (already wrapped in <em>). */
				esc_html__( 'Add the %s shipping method to a shipping zone to start serving live rates at checkout.', 'woocommerce-shipstation-integration' ),
				$method_name
			);
		}

		return '<p>' . $status_line . '</p><p class="description">' . $notice . '</p>';
	}

	/**
	 * "Active" branch — rates URL stored AND an enabled instance on a zone.
	 *
	 * @return string
	 */
	private static function render_active_html(): string {
		$status_label = sprintf(
			'<strong class="wc-shipstation-status wc-shipstation-status--active">%s</strong>',
			esc_html__( 'Active', 'woocommerce-shipstation-integration' )
		);

		$status_line = sprintf(
			/* translators: %s: status label HTML (already escaped). */
			esc_html__( '%s — Checkout Rates is active. ShipStation will provide live shipping rates at checkout.', 'woocommerce-shipstation-integration' ),
			$status_label
		);

		return '<p>' . $status_line . '</p>';
	}

	/**
	 * Redact the configured rates URL (and any GUID-tail HTTPS URL) from a log message.
	 *
	 * @since 5.0.9
	 *
	 * @param string $message Log message that may contain the URL.
	 *
	 * @return string Message with the URL replaced by REDACTED_TOKEN.
	 */
	public static function redact( string $message ): string {
		$url = self::get_rates_url();
		if ( '' !== $url ) {
			$message = str_replace( $url, self::REDACTED_TOKEN, $message );
		}
		$result = preg_replace(
			'#https://[^\s"\']+?/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\b#i',
			self::REDACTED_TOKEN,
			$message
		);
		return is_string( $result ) ? $result : $message;
	}
}
