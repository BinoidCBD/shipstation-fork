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
	 * Sentinel returned when the URL is redacted from log output.
	 */
	const REDACTED_TOKEN = '<redacted-rates-url>';

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
		return update_option( self::OPTION_RATES_URL, $url, false );
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
		return delete_option( self::OPTION_RATES_URL );
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
	 * Whether the read-only Checkout Rates section should render: feature flag on
	 * and the current request is a render/save of the ShipStation integration
	 * settings screen (not REST, AJAX, cron, or any other admin screen).
	 *
	 * @since 5.0.9
	 *
	 * @return bool
	 */
	public static function should_render_settings_section(): bool {
		if ( ! Features::is_checkout_rates_enabled() ) {
			return false;
		}

		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
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
	 * Three branches reflect the merchant's actual position:
	 *   - Not configured: no rates URL stored yet.
	 *   - Connected: rates URL stored but no enabled instance on any zone.
	 *   - Active: rates URL stored AND at least one enabled instance.
	 *
	 * The Connected branch further differentiates whether a disabled instance
	 * exists (so the merchant just needs to toggle it on) versus no instance
	 * at all (so they need to add one first). Otherwise the merchant gets
	 * told to "add the method" when they've already added it.
	 *
	 * @since 5.0.9
	 *
	 * @return string HTML safe to embed in the WC settings 'description' slot.
	 */
	public static function get_settings_description_html(): string {
		if ( ! self::is_configured() ) {
			return self::render_not_configured_html();
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
