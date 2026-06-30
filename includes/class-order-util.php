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
	 * Checks whether HPOS data sync (dual-write to both wc_orders_meta and
	 * wp_postmeta) is currently enabled.
	 *
	 * During a HPOS migration, WooCommerce keeps both storage backends in sync
	 * by firing CRUD hooks on every meta change. Direct SQL writes bypass those
	 * hooks, so callers must write to both tables manually when sync is on.
	 *
	 * @return bool
	 */
	public static function data_sync_is_enabled(): bool {
		try {
			$synchronizer = wc_get_container()->get(
				\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class
			);
			return $synchronizer->data_sync_is_enabled();
		} catch ( \Exception $e ) {
			return false;
		}
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
	 * Determine whether an order line item should be treated as shippable for export.
	 *
	 * Normally this is just the product's own needs_shipping() flag. The one
	 * exception is a WooCommerce Product Bundles "container" line item: when a
	 * bundle is configured as "Assembled" the whole bundle ships as a single
	 * physical parcel (the container), and Product Bundles records the immutable
	 * order-time meta `_bundle_weight` on that container item. If the merchant
	 * later switches the bundle to "Unassembled", the live bundle product is
	 * forced virtual and its needs_shipping() returns false, which would
	 * retroactively strip historical Assembled orders of their only shippable
	 * line and leave ShipStation with nothing to ship and no ShipNotify to send
	 * (SHIPSTN-138). The order-time `_bundle_weight` meta lets us keep treating
	 * such a container as shippable, matching how the order was originally
	 * exported.
	 *
	 * @since 5.1.2
	 *
	 * @param \WC_Order_Item         $item    Order line item.
	 * @param \WC_Product|false|null $product Pre-fetched product for the item when
	 *                                        available; pass null to resolve it here.
	 *
	 * @return bool
	 */
	public static function item_needs_shipping( $item, $product = null ): bool {
		if ( null === $product ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : false;
		}

		// Items without a live product (fees, or a since-deleted product) have
		// nothing to export and are never shippable.
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}

		if ( $product->needs_shipping() ) {
			return true;
		}

		// The product exists but reports no shipping. Keep an assembled bundle
		// container shippable based on its immutable order-time state.
		return self::is_assembled_bundle_container( $item );
	}

	/**
	 * Whether the order item is a Product Bundles container that shipped as a
	 * single assembled parcel at order time.
	 *
	 * Product Bundles writes the `_bundle_weight` meta on a container line item
	 * only when the bundle needed shipping at purchase time, and never rewrites
	 * it afterwards, so its presence is a reliable record that the container was
	 * a physical shipment for this order regardless of the live product's
	 * current virtual state.
	 *
	 * @since 5.1.2
	 *
	 * @param \WC_Order_Item $item Order line item.
	 *
	 * @return bool
	 */
	private static function is_assembled_bundle_container( $item ): bool {
		if ( ! is_callable( array( $item, 'get_meta' ) ) ) {
			return false;
		}

		return '' !== (string) $item->get_meta( '_bundle_weight', true );
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
		return self::item_needs_shipping( $order->get_item( $item_id ) );
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

			if ( false === $qty ) {
				continue;
			}

			if ( self::item_needs_shipping( $item, $product ) ) {
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
	 * Get the ShipStation Checkout Rates code stored on the order's shipping item(s).
	 *
	 * Reads the protected meta written when the customer selects a ShipStation rate
	 * at checkout. Returns the first non-empty code found across the order's shipping
	 * items, or '' when none carry one (e.g. a flat-rate shipment). The REST export
	 * maps this to shipping_preferences.preplanned_fulfillment_id.
	 *
	 * @since 5.0.9
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return string Rate code (e.g. 'dos_…'), or '' when absent.
	 */
	public static function get_checkout_rate_code( WC_Order $order ): string {
		foreach ( $order->get_shipping_methods() as $shipping_method ) {
			if ( ! $shipping_method instanceof \WC_Order_Item_Shipping ) {
				continue;
			}

			$rate_code = $shipping_method->get_meta( Checkout\Checkout_Rates_Options::RATE_CODE_META_KEY );

			if ( is_scalar( $rate_code ) && '' !== (string) $rate_code ) {
				return (string) $rate_code;
			}
		}

		return '';
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
	 * Get order notes grouped by visibility.
	 *
	 * Fetches all approved order notes for a given order and separates them into:
	 * - Customer notes (visible to the customer).
	 * - Private notes (internal use only).
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array{
	 * private: string[],
	 * customer: string[]
	 * }
	 */
	public static function get_order_notes( WC_Order $order ): array {
		if ( isset( self::$order_notes_cache[ $order->get_id() ] ) ) {
			return self::$order_notes_cache[ $order->get_id() ];
		}

		$args = array(
			'post_id' => $order->get_id(),
			'approve' => 'approve',
			'type'    => 'order_note',
		);

		remove_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ), 10 );
		$notes = get_comments( $args );
		add_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ), 10, 1 );

		$order_notes = array(
			'private'  => array(),
			'customer' => array(),
		);

		foreach ( $notes as $note ) {
			if ( 'WooCommerce' !== $note->comment_author ) {
				$note_type                   = (bool) get_comment_meta( $note->comment_ID, 'is_customer_note', true ) ? 'customer' : 'private';
				$order_notes[ $note_type ][] = html_entity_decode( $note->comment_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		return $order_notes;
	}

	/**
	 * Mark a batch of orders as exported in a single SQL round-trip for the
	 * `_shipstation_exported` meta marker. Order notes are still inserted
	 * per-order so the buyer-facing audit trail is preserved and existing
	 * comment hooks continue to fire.
	 *
	 * The marker write goes direct-to-SQL rather than through `save_meta_data()`.
	 * That is the point of the bulk path: one query per batch instead of N, and
	 * — as a side effect — HPOS's `after_meta_change` cascade that would have
	 * bumped `wc_orders.date_updated_gmt` (re-triggering ShipStation's
	 * `modified_after` poll) never fires, so no filter dance is needed.
	 *
	 * Already-exported orders are skipped so the call is idempotent. Neither
	 * `wp_postmeta` nor `wc_orders_meta` carries a unique index on
	 * `(object_id, meta_key)` — `wc_orders_meta` indexes that pair with a plain
	 * `KEY`, not a `UNIQUE KEY` — so prior `_shipstation_exported` rows for the
	 * batch are deleted before the bulk `INSERT` on both storage backends to
	 * keep concurrent calls and post-crash retries from accumulating duplicate
	 * meta rows.
	 *
	 * Trade-off: bypassing `save_meta_data()` skips WC's per-meta hooks
	 * (`update_post_meta` / `updated_postmeta`, HPOS `after_meta_change`).
	 * Third-party code listening for those events on `_shipstation_exported`
	 * will not be notified by this path. The marker value, key, and the
	 * "Order has been exported to Shipstation" order note are unchanged.
	 *
	 * @since 5.0.4
	 *
	 * @param WC_Order[] $orders Order objects.
	 * @return void
	 */
	public static function mark_orders_exported_bulk( array $orders ): void {
		if ( empty( $orders ) ) {
			return;
		}

		$to_mark = array();

		foreach ( $orders as $order ) {
			if ( ! self::is_wc_order( $order ) ) {
				continue;
			}

			if ( 'yes' === $order->get_meta( '_shipstation_exported', true ) ) {
				continue;
			}

			$to_mark[ $order->get_id() ] = $order;
		}

		if ( empty( $to_mark ) ) {
			return;
		}

		global $wpdb;
		$ids     = array_keys( $to_mark );
		$hpos    = self::custom_orders_table_usage_is_enabled();
		$sync_on = self::data_sync_is_enabled();

		/*
		 * Build the list of tables to write to. Under HPOS sync mode both
		 * wc_orders_meta and wp_postmeta are live. WC's sync mechanism mirrors
		 * changes by listening to CRUD hooks — which a direct SQL write bypasses
		 * entirely — so we must keep both tables consistent ourselves.
		 *
		 * | HPOS | Sync | Tables written              |
		 * |------|------|-----------------------------|
		 * | off  | off  | wp_postmeta                 |
		 * | on   | off  | wc_orders_meta              |
		 * | on   | on   | wc_orders_meta + postmeta   |
		 * | off  | on   | wp_postmeta + wc_orders_meta|
		 */
		$targets = array();
		if ( $hpos || $sync_on ) {
			$targets[] = array(
				'table' => $wpdb->prefix . 'wc_orders_meta',
				'col'   => 'order_id',
			);
		}
		if ( ! $hpos || $sync_on ) {
			$targets[] = array(
				'table' => $wpdb->postmeta,
				'col'   => 'post_id',
			);
		}

		// Neither `wp_postmeta` nor `wc_orders_meta` has a UNIQUE index on
		// (object_id, meta_key), so clear any prior `_shipstation_exported`
		// rows for the batch before the bulk INSERT to keep concurrent
		// requests and post-crash retries from accumulating duplicates.
		$id_placeholders  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$row_placeholders = implode( ',', array_fill( 0, count( $ids ), "(%d, '_shipstation_exported', 'yes')" ) );
		$all_ok           = true;

		foreach ( $targets as $target ) {
			$table      = $target['table'];
			$object_col = $target['col'];

			$delete_result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table and $object_col are string literals; $id_placeholders is a list of %d tokens.
					"DELETE FROM {$table} WHERE meta_key = '_shipstation_exported' AND {$object_col} IN ({$id_placeholders})",
					...$ids
				)
			);

			$insert_result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table and $object_col are string literals; IDs are %d.
					"INSERT INTO {$table} ({$object_col}, meta_key, meta_value) VALUES {$row_placeholders}",
					...$ids
				)
			);

			if ( false === $delete_result || false === $insert_result ) {
				Logger::error(
					sprintf(
						'mark_orders_exported_bulk SQL failed on %s (delete=%s, insert=%s): %s',
						$table,
						var_export( $delete_result, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
						var_export( $insert_result, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
						$wpdb->last_error
					)
				);
				$all_ok = false;
			}
		}

		// Bail without writing notes if any statement failed — otherwise the
		// next export run would see an empty marker, push these orders back
		// onto the queue, and add a second "exported to ShipStation" note.
		if ( ! $all_ok ) {
			return;
		}

		foreach ( $to_mark as $order ) {
			$order->add_order_note( __( 'Order has been exported to Shipstation', 'woocommerce-shipstation-integration' ) );
		}
	}

	/**
	 * Prime the WooCommerce order-items and order-itemmeta caches for a batch of order IDs.
	 *
	 * WooCommerce's CPT order data store (which HPOS inherits `read_items()` from) caches
	 * items under `'order-items-{order_id}'` in the `'orders'` group. When the CPT store
	 * runs a `wc_get_orders()` query with `type = shop_order`, it calls
	 * `prime_order_item_caches_for_orders()` and bulk-loads the items for the whole batch.
	 * The HPOS data store does not trigger that helper, so `$order->get_items()` fires one
	 * `wc_order_items` SELECT per order.
	 *
	 * This method replicates the same priming behavior with two queries:
	 *   1. `SELECT ... FROM wc_order_items WHERE order_id IN ( ... )` — all items for the batch.
	 *   2. `update_meta_cache( 'order_item', $all_item_ids )` — bulk prime `wc_order_itemmeta`.
	 *
	 * @since 5.0.4
	 *
	 * @param int[] $order_ids Order IDs to prime.
	 * @return void
	 */
	public static function prime_order_items_for_batch( array $order_ids ): void {
		if ( empty( $order_ids ) ) {
			return;
		}

		$order_ids = array_values( array_unique( array_map( 'absint', $order_ids ) ) );

		$cache_keys   = array_map(
			static function ( $id ) {
				return 'order-items-' . $id;
			},
			$order_ids
		);
		$cache_values = wc_cache_get_multiple( $cache_keys, 'orders' );

		$to_prime = array();
		foreach ( $order_ids as $id ) {
			if ( false === $cache_values[ 'order-items-' . $id ] ) {
				$to_prime[] = $id;
			}
		}

		if ( empty( $to_prime ) ) {
			return;
		}

		global $wpdb;
		$ids_sql = implode( ',', $to_prime );
		$items   = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids_sql is an absint-sanitized list above.
			"SELECT order_item_type, order_item_id, order_id, order_item_name FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN ( {$ids_sql} ) ORDER BY order_item_id"
		);

		$grouped = array_fill_keys( $to_prime, array() );
		foreach ( (array) $items as $item ) {
			$grouped[ (int) $item->order_id ][] = $item;
		}

		foreach ( $grouped as $id => $rows ) {
			wp_cache_set( 'order-items-' . $id, $rows, 'orders' );
		}

		if ( empty( $items ) ) {
			return;
		}

		$item_ids = wp_list_pluck( $items, 'order_item_id' );
		update_meta_cache( 'order_item', array_map( 'absint', $item_ids ) );
	}

	/**
	 * Prime the refunds cache for a batch of order IDs.
	 *
	 * `$order->get_refunds()` caches its result under the key
	 * `WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'refunds' . $id` in the
	 * `'orders'` group. On HPOS the CPT store's `prime_refund_caches_for_order()`
	 * helper is not triggered, so without this call the first `get_refunds()` on
	 * each order runs a separate `wc_get_orders( type = shop_order_refund )` query.
	 *
	 * Fetches every refund whose parent is in the batch with a single
	 * `wc_get_orders()` call (HPOS aliases `post_parent__in` to `parent_order_id`),
	 * groups the results by parent, and stashes each order's refunds under the
	 * expected cache key so the per-order `get_refunds()` becomes a memory read.
	 *
	 * @since 5.0.4
	 *
	 * @param int[] $order_ids Order IDs to prime.
	 * @return void
	 */
	public static function prime_refunds_for_batch( array $order_ids ): void {
		if ( empty( $order_ids ) ) {
			return;
		}

		$order_ids = array_values( array_unique( array_map( 'absint', $order_ids ) ) );

		$cache_keys    = array();
		$keys_by_order = array();
		foreach ( $order_ids as $id ) {
			$key                  = \WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'refunds' . $id;
			$cache_keys[]         = $key;
			$keys_by_order[ $id ] = $key;
		}

		$cache_values = wc_cache_get_multiple( $cache_keys, 'orders' );

		$to_prime = array();
		foreach ( $order_ids as $id ) {
			if ( false === $cache_values[ $keys_by_order[ $id ] ] ) {
				$to_prime[] = $id;
			}
		}

		if ( empty( $to_prime ) ) {
			return;
		}

		$refunds = wc_get_orders(
			array(
				'type'            => 'shop_order_refund',
				'post_parent__in' => $to_prime,
				'limit'           => -1,
			)
		);

		$grouped = array_fill_keys( $to_prime, array() );
		foreach ( (array) $refunds as $refund ) {
			if ( ! $refund instanceof \WC_Order_Refund ) {
				continue;
			}
			$parent_id = $refund->get_parent_id();
			if ( isset( $grouped[ $parent_id ] ) ) {
				$grouped[ $parent_id ][] = $refund;
			}
		}

		foreach ( $grouped as $id => $list ) {
			wp_cache_set( $keys_by_order[ $id ], $list, 'orders' );
		}
	}

	/**
	 * Pre-fetched order-notes cache, keyed by order ID.
	 *
	 * @var array<int, array{private: string[], customer: string[]}>
	 */
	private static array $order_notes_cache = array();

	/**
	 * Prime the order-notes cache for a batch of order IDs.
	 *
	 * WordPress's `get_comments()` result cache is keyed by a hash of its arguments,
	 * so a per-order call cannot be served from a batch query's cache. This method
	 * instead bulk-fetches every order note for the batch in a single `get_comments()`,
	 * primes `wp_commentmeta` for all returned comments with one `update_meta_cache()`,
	 * and stores the resolved `{ private, customer }` arrays in a class-level map that
	 * `get_order_notes()` consults before falling back to the per-order path.
	 *
	 * @since 5.0.4
	 *
	 * @param int[] $order_ids Order IDs to prime.
	 * @return void
	 */
	public static function prime_order_notes_for_batch( array $order_ids ): void {
		if ( empty( $order_ids ) ) {
			return;
		}

		$order_ids = array_values( array_unique( array_map( 'absint', $order_ids ) ) );

		$to_prime = array_filter(
			$order_ids,
			static function ( $id ) {
				return ! isset( self::$order_notes_cache[ $id ] );
			}
		);
		if ( empty( $to_prime ) ) {
			return;
		}

		// Seed every order with an empty structure so orders with no notes still
		// short-circuit the per-order query path below.
		foreach ( $to_prime as $id ) {
			self::$order_notes_cache[ $id ] = array(
				'private'  => array(),
				'customer' => array(),
			);
		}

		remove_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ), 10 );
		$notes = get_comments(
			array(
				'post__in' => $to_prime,
				'approve'  => 'approve',
				'type'     => 'order_note',
			)
		);
		add_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ), 10, 1 );

		if ( empty( $notes ) ) {
			return;
		}

		$comment_ids = array_map(
			static function ( $note ) {
				return (int) $note->comment_ID;
			},
			(array) $notes
		);
		update_meta_cache( 'comment', $comment_ids );

		foreach ( (array) $notes as $note ) {
			if ( 'WooCommerce' === $note->comment_author ) {
				continue;
			}
			$order_id = (int) $note->comment_post_ID;
			if ( ! isset( self::$order_notes_cache[ $order_id ] ) ) {
				continue;
			}
			$bucket = get_comment_meta( $note->comment_ID, 'is_customer_note', true ) ? 'customer' : 'private';
			self::$order_notes_cache[ $order_id ][ $bucket ][] = html_entity_decode( $note->comment_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
	}

	/**
	 * Prime the WP post + postmeta caches for every product referenced by a batch of orders.
	 *
	 * `$item->get_product()` routes through `WC_Product_Factory`, which does not cache
	 * instances across calls — every call runs the data-store `read()`, hitting
	 * `wp_posts` and `wp_postmeta` on a cache miss. On a 500-order batch with a few
	 * items each that is ~1,500 uncached round trips per request.
	 *
	 * Priming the post + postmeta caches once for every unique product or variation ID
	 * across the batch turns all subsequent `get_product()` calls into cache hits.
	 * `_prime_post_caches()` internally skips already-cached IDs, so this is safe to
	 * call unconditionally. Call after `prime_order_items_for_batch()` so the
	 * `$order->get_items()` iteration below is itself a cache hit.
	 *
	 * @since 5.0.4
	 *
	 * @param WC_Order[] $orders Hydrated orders whose line-item products should be primed.
	 * @return void
	 */
	public static function prime_products_for_batch( array $orders ): void {
		if ( empty( $orders ) ) {
			return;
		}

		$product_ids = array();
		foreach ( $orders as $order ) {
			if ( ! self::is_wc_order( $order ) ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				if ( $product_id ) {
					$product_ids[] = (int) $product_id;
				}
			}
		}

		if ( empty( $product_ids ) ) {
			return;
		}

		_prime_post_caches( array_values( array_unique( $product_ids ) ), true, true );
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
