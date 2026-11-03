<?php
/**
 * Checkout Rates Classic Label class file.
 *
 * @package WC_ShipStation
 * @since 5.2.1
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appends a ShipStation checkout rate's delivery estimate and description to its
 * classic (shortcode) cart/checkout shipping label.
 *
 * The block cart/checkout renders a rate's native delivery_time and description fields
 * directly, but the classic cart/checkout renders only the label, so those details are
 * appended here at display time rather than baked into the stored rate label (which
 * WooCommerce would otherwise persist as the order shipping method title).
 *
 * @since 5.2.1
 */
final class Checkout_Rates_Classic_Label {

	/**
	 * Hook the classic-label filter.
	 *
	 * Registered unconditionally (the callback no-ops for non-ShipStation rates) because the
	 * checkout-rates feature flag may be enabled after this runs - e.g. from a theme's
	 * functions.php on `after_setup_theme` - just as Main::register_shipping_methods() is
	 * always hooked and checks the flag lazily. Gating registration on the flag here would
	 * silently drop the filter on those sites while the rate itself still appears.
	 *
	 * @since 5.2.1
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'woocommerce_cart_shipping_method_full_label', array( self::class, 'append_details' ), 10, 2 );
	}

	/**
	 * Append the rate's delivery estimate and description to its classic-checkout label.
	 *
	 * @since 5.2.1
	 *
	 * @param string $label  Full shipping method label (already includes the price).
	 * @param mixed  $method Shipping rate being rendered (WC_Shipping_Rate).
	 *
	 * @return string
	 */
	public static function append_details( $label, $method ) {
		if ( ! $method instanceof \WC_Shipping_Rate
			|| Checkout_Rates_Options::SHIPPING_METHOD_ID !== $method->get_method_id() ) {
			return $label;
		}

		$details = array();

		$delivery_time = $method->get_delivery_time();
		if ( is_string( $delivery_time ) && '' !== $delivery_time ) {
			$details[] = $delivery_time;
		}

		$description = $method->get_description();
		if ( is_string( $description ) && '' !== $description ) {
			$details[] = $description;
		}

		if ( empty( $details ) ) {
			return $label;
		}

		return sprintf(
			/* translators: 1: shipping method label, 2: delivery estimate and/or description, e.g. "2 days - Ground". */
			_x( '%1$s (%2$s)', 'classic checkout shipping label with delivery details', 'woocommerce-shipstation-integration' ),
			$label,
			esc_html( implode( ' - ', $details ) )
		);
	}
}
