<?php
/**
 * Class WC_ShipStation\Order_Util file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Internal\CostOfGoodsSold\CostOfGoodsSoldController;
use WC_Order;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Class Order_Util
 *
 * A proxy-style class that centralizes order-related utilities for ShipStation.
 * It abstracts away WooCommerce internals, normalizes differences between legacy
 * and HPOS order storage, and provides convenience methods for common order tasks.
 */
class Order_Util {
	/**
	 * Constant variable for admin screen name.
	 *
	 * @var string $legacy_order_admin_screen.
	 */
	public static string $legacy_order_admin_screen = 'shop_order';

	/**
	 * Checks whether the OrderUtil class exists
	 *
	 * @return bool
	 */
	public static function wc_order_util_class_exists(): bool {
		return class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' );
	}

	/**
	 * Checks whether the OrderUtil class and the given method exist
	 *
	 * @param String $method_name Class method name.
	 *
	 * @return bool
	 */
	public static function wc_order_util_method_exists( string $method_name ): bool {
		if ( ! self::wc_order_util_class_exists() ) {
			return false;
		}

		if ( ! method_exists( 'Automattic\WooCommerce\Utilities\OrderUtil', $method_name ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks whether we are using custom order tables.
	 *
	 * @return bool
	 */
	public static function custom_orders_table_usage_is_enabled(): bool {
		if ( ! self::wc_order_util_method_exists( 'custom_orders_table_usage_is_enabled' ) ) {
			return false;
		}

		return OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Returns the relevant order screen depending on whether
	 * custom order tables are being used.
	 *
	 * @return string
	 */
	public static function get_order_admin_screen(): string {
		if ( ! self::wc_order_util_method_exists( 'get_order_admin_screen' ) ) {
			return self::$legacy_order_admin_screen;
		}

		return OrderUtil::get_order_admin_screen();
	}

	/**
	 * Check if the object is WC_Order object.
	 *
	 * @param Mixed $post_object Either Post object or Order object.
	 *
	 * @return Boolean
	 */
	public static function is_wc_order( $post_object ): bool {
		return ( $post_object instanceof WC_Order );
	}

	/**
	 * Returns the WC_Order object from the object passed to
	 * the add_meta_box callback function.
	 *
	 * @param WC_Order|WP_Post $post_or_order_object Either Post object or Order object.
	 *
	 * @return WC_Order
	 */
	public static function init_theorder_object( $post_or_order_object ): WC_Order {
		if ( ! self::wc_order_util_method_exists( 'init_theorder_object' ) ) {
			return wc_get_order( $post_or_order_object->ID );
		}

		return OrderUtil::init_theorder_object( $post_or_order_object );
	}

	/**
	 * Returns the order ID from the order number.
	 *
	 * @param string $order_number Order number.
	 *
	 * @return int Order ID.
	 */
	public static function get_order_id_from_order_number( string $order_number ): int {
		// Try to match an order number in brackets.
		preg_match( '/\((.*?)\)/', $order_number, $matches );
		if ( is_array( $matches ) && isset( $matches[1] ) ) {
			$order_id = $matches[1];

		} elseif ( function_exists( 'wc_sequential_order_numbers' ) ) {
			// Try to convert number for Sequential Order Number.
			$order_id = wc_sequential_order_numbers()->find_order_by_order_number( $order_number );

		} elseif ( function_exists( 'wc_seq_order_number_pro' ) ) {
			// Try to convert number for Sequential Order Number Pro.
			$order_id = wc_seq_order_number_pro()->find_order_by_order_number( $order_number );

		} elseif ( function_exists( 'run_wt_advanced_order_number' ) ) {
			// Try to convert order number for Sequential Order Number for WooCommerce by WebToffee.
			// This plugin does not have any function or method that we can use to convert the number.
			// So need to do it manually.
			$orders = wc_get_orders(
				array(
					'wt_order_number' => $order_number,
					'limit'           => 1,
					'return'          => 'ids',
				)
			);

			$order_id = ( is_array( $orders ) && ! empty( $orders ) ) ? array_shift( $orders ) : 0;
		} else {
			// Default to not converting order number.
			$order_id = $order_number;
		}

		if ( 0 === $order_id ) {
			$order_id = $order_number;
		}

		/**
		 * This order number can be adjusted by using a filter which is done by the
		 * Sequential Order Numbers / Sequential Order Numbers Pro plugins. However
		 * there are also many other plugins which offer this functionality.
		 *
		 * When the ShipNotify request is received the "real" order number is
		 * needed to be able to update the correct order. The plugin uses the
		 * function get_order_id. This function has specific compatibility for both
		 * Sequential Order Numbers & Sequential Order Numbers Pro. However there
		 * is no additional filter for plugins to modify this order ID if needed.
		 *
		 * @param int        $order_id Order ID.
		 * @param string|int $order_number Order number.
		 *
		 * @since 4.7.6
		 */
		return absint( apply_filters( 'woocommerce_shipstation_get_order_id_from_order_number', $order_id, $order_number ) );
	}

	/**
	 * Check whether a given item ID is a shippable item.
	 *
	 * @since 4.7.6
	 * @version 4.7.6
	 *
	 * @param WC_Order $order   Order object.
	 * @param int      $item_id Item ID.
	 *
	 * @return bool Returns true if item is shippable product.
	 */
	public static function is_shippable_item( WC_Order $order, int $item_id ): bool {
		$item    = $order->get_item( $item_id );
		$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : false;

		return $product ? $product->needs_shipping() : false;
	}

	/**
	 * See how many items in the order need shipping.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return int
	 */
	public static function order_items_to_ship_count( WC_Order $order ): int {
		$needs_shipping = 0;

		foreach ( $order->get_items() as $item_id => $item ) {

			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : false;
			$qty     = is_callable( array( $item, 'get_quantity' ) ) ? $item->get_quantity() : false;

			if ( ! $product instanceof \WC_Product || false === $qty ) {
				continue;
			}

			if ( $product->needs_shipping() ) {
				$needs_shipping += ( $qty - abs( $order->get_qty_refunded_for_item( $item_id ) ) );
			}
		}

		return $needs_shipping;
	}

	/**
	 * Get address data from Order.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @result array.
	 */
	public static function get_address_data( WC_Order $order ) {
		$shipping_country = $order->get_shipping_country();
		$shipping_address = $order->get_shipping_address_1();

		$address = array();

		if ( empty( $shipping_country ) && empty( $shipping_address ) ) {
			$name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

			$address['name']     = $name;
			$address['company']  = $order->get_billing_company();
			$address['address1'] = $order->get_billing_address_1();
			$address['address2'] = $order->get_billing_address_2();
			$address['city']     = $order->get_billing_city();
			$address['state']    = $order->get_billing_state();
			$address['postcode'] = $order->get_billing_postcode();
			$address['country']  = $order->get_billing_country();
			$address['phone']    = $order->get_billing_phone();
		} else {
			$name = $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();

			$address['name']     = $name;
			$address['company']  = $order->get_shipping_company();
			$address['address1'] = $order->get_shipping_address_1();
			$address['address2'] = $order->get_shipping_address_2();
			$address['city']     = $order->get_shipping_city();
			$address['state']    = $order->get_shipping_state();
			$address['postcode'] = $order->get_shipping_postcode();
			$address['country']  = $order->get_shipping_country();
			$address['phone']    = $order->get_billing_phone();
		}

		/**
		 * Allow third party to modify the address data.
		 *
		 * @param array    $address Address data.
		 * @param WC_Order $order Order object.
		 * @param boolean  $is_export_address Flag to export address data or not.
		 *
		 * @since 4.2.0
		 */
		return apply_filters( 'woocommerce_shipstation_export_address_data', $address, $order, true );
	}

	/**
	 * Get shipping method names from the order joined with " | ".
	 *
	 * @param WC_Order $order Order object.
	 * @param boolean  $strip_chars Flag to strip non-alphanumeric characters from method names.
	 *
	 * @return string Shipping method names, or empty string if none.
	 */
	public static function get_shipping_methods( WC_Order $order, bool $strip_chars = true ): string {
		$shipping_methods      = $order->get_shipping_methods();
		$shipping_method_names = array();

		foreach ( $shipping_methods as $shipping_method ) {
			$method_name = html_entity_decode( $shipping_method->get_name(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			if ( $strip_chars ) {
				// Replace non-AlNum characters with space.
				$method_name = preg_replace( '/[^A-Za-z0-9 \-\.\_,]/', '', $method_name );
			}

			$shipping_method_names[] = $method_name;
		}

		return implode( ' | ', $shipping_method_names );
	}

	/**
	 * Get all WooCommerce order statuses.
	 *
	 * @return array
	 */
	public static function get_all_order_statuses(): array {
		$statuses = wc_get_order_statuses();

		// When integration loaded custom statuses is not loaded yet, so we need to
		// merge it manually.
		if ( function_exists( 'wc_order_status_manager' ) ) {
			$result = get_posts(
				array(
					'post_type'        => 'wc_order_status',
					'post_status'      => 'publish',
					'posts_per_page'   => -1,
					'suppress_filters' => 1,
					'orderby'          => 'menu_order',
					'order'            => 'ASC',
				)
			);

			$filtered_statuses = array();
			foreach ( $result as $post_status ) {
				$filtered_statuses[ 'wc-' . $post_status->post_name ] = $post_status->post_title;
			}
			$statuses = array_merge( $statuses, $filtered_statuses );
		}

		foreach ( $statuses as $key => $value ) {
			$statuses[ $key ] = str_replace( 'wc-', '', $key );
		}

		return $statuses;
	}

	/**
	 * Get internal order notes (non-customer) for an order.
	 *
	 * Mirrors previous logic from WC_Shipstation_API_Export::get_order_notes().
	 * Returns a flat array of note strings suitable for export or further formatting.
	 *
	 * @param WC_Order $order Order object.
	 * @return array Array of internal note strings.
	 */
	public static function get_order_notes( WC_Order $order ): array {
		$args = array(
			'post_id' => $order->get_id(),
			'approve' => 'approve',
			'type'    => 'order_note',
		);

		remove_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ), 10 );
		$notes = get_comments( $args );
		add_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ), 10, 1 );

		$order_notes = array();

		foreach ( $notes as $note ) {
			if ( 'WooCommerce' !== $note->comment_author ) {
				$order_notes[] = $note->comment_content;
			}
		}

		return $order_notes;
	}

	/**
	 * Checks whether the WooCommerce Cost of Goods Sold feature is enabled.
	 *
	 * @return bool
	 */
	public static function is_cogs_enabled(): bool {
		try {
			return wc_get_container()->get( CostOfGoodsSoldController::class )->feature_is_enabled();
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
