<?php
/**
 * Class WC_ShipStation\Order_Util file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Internal\CostOfGoodsSold\CostOfGoodsSoldController;
use WC_Data_Store;
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
	 * Maximum number of shipment identifiers kept in the
	 * _shipstation_processed_shipments order meta.
	 *
	 * @var int
	 */
	private const MAX_PROCESSED_SHIPMENTS = 50;

	/**
	 * Default maximum number of order notes exported per order.
	 *
	 * The fetch was unbounded, so orders with a large note history could
	 * exhaust PHP's memory limit (SHIPSTN-161's suspected mechanism,
	 * reproduced on a seeded store). Orders over the bound export their
	 * newest notes.
	 *
	 * @since 5.3.2
	 *
	 * @var int
	 */
	public const DEFAULT_ORDER_NOTES_LIMIT = 50;

	/**
	 * Ceiling for the order-notes limit filter.
	 *
	 * The batch fetch scales as limit times batch size (up to 500 orders per
	 * REST page), so an uncapped filter value could rebuild the unbounded
	 * fetch this limit exists to prevent.
	 *
	 * @since 5.3.2
	 *
	 * @var int
	 */
	public const MAX_ORDER_NOTES_LIMIT = 500;

	/**
	 * Maximum comment rows a single bulk note query may materialise.
	 *
	 * The per-order limit alone does not bound the batch: at the documented
	 * ceilings (500 notes x 500 orders per page) a single query could fetch
	 * 250,000 rows, more than the unbounded fetch this bound replaces
	 * (SHIPSTN-161). The batch is chunked so no query exceeds this many
	 * counted notes; 5,000 matches the configuration the fix was measured
	 * safe at (100 orders x 50 notes, 143 MB full-request peak). It has not
	 * been re-measured at the 500-order page ceiling.
	 *
	 * @var int
	 */
	private const MAX_BATCH_NOTES = 5000;

	/**
	 * Comment author WooCommerce writes on its own system notes.
	 *
	 * @var string
	 */
	private const SYSTEM_NOTE_AUTHOR = 'WooCommerce';

	/**
	 * Characters of foreign text a single log entry may carry.
	 *
	 * @var int
	 */
	private const LOG_TEXT_CHAR_BOUND = 500;

	/**
	 * Bytes of foreign text the sanitising passes will walk.
	 *
	 * Four bytes is the widest UTF-8 character, so this cannot cut anything
	 * that would have survived LOG_TEXT_CHAR_BOUND, and it keeps a dumped
	 * payload from being scanned in full only to be thrown away.
	 *
	 * @var int
	 */
	private const LOG_TEXT_BYTE_BOUND = 2048;

	/**
	 * Bytes of discarded output the drain keeps, per buffer and in total.
	 *
	 * @var int
	 */
	private const STRAY_OUTPUT_BYTE_BOUND = 4096;

	/**
	 * Transient prefix for the throttled export-status warnings.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.7
	 *
	 * @var string
	 */
	public const NO_STATUS_MATCH_TRANSIENT = 'wc_shipstation_no_export_status_match_warned';

	/**
	 * Entries of a status list a warning will name; the list is stored or
	 * request text.
	 *
	 * @var int
	 */
	private const MAX_STATUSES_LOGGED = 20;

	/**
	 * Distinct throttle keys one warning cause may arm within an hour.
	 *
	 * A cause seeded from request data would otherwise mint a transient per
	 * distinct value an authenticated caller sends.
	 *
	 * @var int
	 */
	private const MAX_THROTTLE_KEYS_PER_CAUSE = 20;

	/**
	 * Largest buffer the drain will copy out for logging, in bytes.
	 *
	 * Copying is what costs: ob_get_contents() materialises the whole buffer,
	 * doubling peak memory while the request is already failing, and running
	 * out there is E_ERROR, which nothing can catch (SHIPSTN-171). Only
	 * STRAY_OUTPUT_BYTE_BOUND of the copy survives anyway, so above this the
	 * drain logs the size instead.
	 *
	 * @var int
	 */
	private const STRAY_OUTPUT_CAPTURE_LIMIT = 1048576;

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
				$needs_shipping += ( $qty - self::safe_qty_refunded_for_item( $order, $item_id ) );
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
	 * The entries of an export-status list that name a registered order status.
	 *
	 * Legacy storage drops unregistered statuses, HPOS reads an empty list or
	 * 'any' as every registered status, and 'all' means no filter: each of
	 * those exports orders the setting excludes (SHIPSTN-174). Callers query
	 * only what is kept and answer with an empty page when nothing is.
	 *
	 * Registered means listed by wc_get_order_statuses(). On legacy storage
	 * the status must also be a registered post status: WP_Query builds its
	 * status clause from get_post_stati() and drops the rest, so a status a
	 * plugin adds only through the wc_order_statuses filter would pass the
	 * guard and still select every order.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.7
	 *
	 * @param array $statuses Statuses, with or without the wc- prefix.
	 * @return string[] The registered entries, in the form they are registered under (wc- prefixed for every WooCommerce status).
	 */
	public static function matchable_export_statuses( array $statuses ): array {
		return self::partition_export_statuses( $statuses )['kept'];
	}

	/**
	 * Split an export-status list into registered entries and skipped ones.
	 *
	 * One pass decides both, so a bare slug the stores accept is never
	 * reported as skipped. Callers that need both halves partition once and
	 * hand the skipped half to warn_skipped_export_statuses().
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.7
	 *
	 * @param array $statuses Statuses, with or without the wc- prefix.
	 * @return array{kept: string[], skipped: string[]} Kept entries as registered, skipped entries as stored.
	 */
	public static function partition_export_statuses( array $statuses ): array {
		$registered = wc_get_order_statuses();
		$legacy     = ! self::custom_orders_table_usage_is_enabled();
		$kept       = array();
		$skipped    = array();

		foreach ( self::string_statuses( $statuses ) as $status ) {
			// The stores accept either form; the request side carries the prefix.
			foreach ( array( self::prefixed_status( $status ), $status ) as $candidate ) {
				if ( ! isset( $registered[ $candidate ] ) ) {
					continue;
				}

				if ( $legacy && null === get_post_status_object( $candidate ) ) {
					continue;
				}

				$kept[] = $candidate;
				continue 2;
			}

			$skipped[] = $status;
		}

		return array(
			'kept'    => array_values( array_unique( $kept ) ),
			'skipped' => array_values( array_unique( $skipped ) ),
		);
	}

	/**
	 * The wc- prefixed form of every string entry of a status list,
	 * registered or not, so callers can compare a stored list with the
	 * prefixed statuses a request resolves to.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.7
	 *
	 * @param array $statuses Statuses, with or without the wc- prefix.
	 * @return string[]
	 */
	public static function prefixed_export_statuses( array $statuses ): array {
		return array_values( array_unique( array_map( array( __CLASS__, 'prefixed_status' ), self::string_statuses( $statuses ) ) ) );
	}

	/**
	 * A status in its wc- prefixed form.
	 *
	 * @param string $status Status, with or without the wc- prefix.
	 * @return string
	 */
	private static function prefixed_status( string $status ): string {
		return 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
	}

	/**
	 * Keep the non-empty strings of a status list.
	 *
	 * @param array $statuses Raw list, possibly from a filter or option write.
	 * @return string[]
	 */
	private static function string_statuses( array $statuses ): array {
		return array_values(
			array_filter(
				$statuses,
				static function ( $status ): bool {
					return is_string( $status ) && '' !== $status;
				}
			)
		);
	}

	/**
	 * Warn, throttled, that the enabled export statuses select nothing.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.7
	 *
	 * @param array $statuses The list that cannot match.
	 * @return void
	 */
	public static function warn_export_statuses_cannot_match( array $statuses ): void {
		$statuses = self::string_statuses( $statuses );
		sort( $statuses );

		self::warn_export_status_once(
			'unmatchable|' . implode( ',', $statuses ),
			sprintf(
				/* translators: %s: statuses enabled for export, or a dash when none */
				__( 'The enabled export statuses (%s) cannot match any order on this store. Returning an empty orders page. Review the Export Order Statuses setting.', 'woocommerce-shipstation-integration' ),
				empty( $statuses ) ? '-' : self::statuses_for_log( $statuses )
			)
		);
	}

	/**
	 * Warn, throttled, naming enabled export statuses that are not registered.
	 *
	 * Their orders are left out while the rest of the list exports, so this
	 * line is the merchant's only clue. Call it only when some entries are
	 * kept: an all-skipped list is reported by warn_export_statuses_cannot_match().
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.7
	 *
	 * @param array $skipped The skipped half of partition_export_statuses().
	 * @return void
	 */
	public static function warn_skipped_export_statuses( array $skipped ): void {
		$skipped = self::string_statuses( $skipped );

		if ( empty( $skipped ) ) {
			return;
		}

		sort( $skipped );

		self::warn_export_status_once(
			'skipped|' . implode( ',', $skipped ),
			sprintf(
				/* translators: %s: comma-separated export statuses that are not registered order statuses */
				__( 'Orders in these export statuses are not sent to ShipStation because they are not registered order statuses on this store: %s. If a plugin added them, it may be deactivated. Reactivate it, or remove these statuses from the Export Order Statuses setting.', 'woocommerce-shipstation-integration' ),
				self::statuses_for_log( $skipped )
			)
		);
	}

	/**
	 * A status list as one bounded, single-line log fragment.
	 *
	 * The list is stored or request text this plugin does not control:
	 * unbounded, a poll could write a large entry, and a newline would read
	 * as a second entry (SHIPSTN-171).
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.7
	 *
	 * @param array $statuses Statuses to name.
	 * @return string
	 */
	public static function statuses_for_log( array $statuses ): string {
		return self::sanitize_for_log( implode( ', ', array_slice( self::string_statuses( $statuses ), 0, self::MAX_STATUSES_LOGGED ) ) );
	}

	/**
	 * Log an export-status warning at most once an hour per distinct cause.
	 *
	 * Keys share the export-status prefix, so this is not a general throttle.
	 * The transient arms only after the write lands, so a swallowed warning
	 * retries on the next poll. Every transient call is contained: their
	 * hooks are foreign code inside the export boundary (SHIPSTN-171).
	 *
	 * The seed is "cause|sorted entries". Each cause may arm
	 * MAX_THROTTLE_KEYS_PER_CAUSE distinct keys an hour; past that the cause
	 * shares one key, so a caller varying a request-seeded mapping can neither
	 * mint a transient per value nor write a log line per poll.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.7
	 *
	 * @param string $key_seed Distinct cause, hashed into the transient key.
	 * @param string $message  Warning message.
	 * @return void
	 */
	public static function warn_export_status_once( string $key_seed, string $message ): void {
		$throttle_key = self::NO_STATUS_MATCH_TRANSIENT . '_' . md5( $key_seed );

		if ( self::throttle_is_armed( $throttle_key ) ) {
			return;
		}

		$cause         = strstr( $key_seed, '|', true );
		$use_cause_key = false !== $cause && ! self::throttle_budget_allows( $cause );

		if ( $use_cause_key ) {
			$throttle_key = self::NO_STATUS_MATCH_TRANSIENT . '_' . md5( $cause );

			if ( self::throttle_is_armed( $throttle_key ) ) {
				return;
			}
		}

		$logged = self::log_isolated(
			'the status warning',
			static function () use ( $message ) {
				Logger::warning( $message );
			}
		);

		if ( ! $logged ) {
			return;
		}

		self::dispatch_safely(
			'the status-warning throttle write',
			static function () use ( $throttle_key ) {
				set_transient( $throttle_key, 1, HOUR_IN_SECONDS );
			}
		);

		if ( ! $use_cause_key && false !== $cause ) {
			self::count_throttle_key( $cause );
		}
	}

	/**
	 * Whether a throttle key is armed. A failed read counts as not armed.
	 *
	 * @param string $throttle_key Transient name.
	 * @return bool
	 */
	private static function throttle_is_armed( string $throttle_key ): bool {
		$armed = false;

		self::dispatch_safely(
			'the status-warning throttle read',
			static function () use ( $throttle_key, &$armed ) {
				$armed = false !== get_transient( $throttle_key );
			}
		);

		return $armed;
	}

	/**
	 * Whether a cause may still arm a distinct key this hour.
	 *
	 * @param string $cause Cause prefix of the seed.
	 * @return bool
	 */
	private static function throttle_budget_allows( string $cause ): bool {
		$count = 0;

		self::dispatch_safely(
			'the status-warning key count read',
			static function () use ( $cause, &$count ) {
				$count = (int) get_transient( self::throttle_count_key( $cause ) );
			}
		);

		return $count < self::MAX_THROTTLE_KEYS_PER_CAUSE;
	}

	/**
	 * Record that a cause armed one more distinct key.
	 *
	 * @param string $cause Cause prefix of the seed.
	 * @return void
	 */
	private static function count_throttle_key( string $cause ): void {
		self::dispatch_safely(
			'the status-warning key count write',
			static function () use ( $cause ) {
				$key = self::throttle_count_key( $cause );
				set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
			}
		);
	}

	/**
	 * Transient name holding the distinct-key count of a cause.
	 *
	 * @param string $cause Cause prefix of the seed.
	 * @return string
	 */
	private static function throttle_count_key( string $cause ): string {
		return self::NO_STATUS_MATCH_TRANSIENT . '_keys_' . md5( $cause );
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
	 * Fetches the order's newest notes, up to the order-notes limit (see
	 * get_order_notes_limit()), and separates them into:
	 * - Customer notes (visible to the customer).
	 * - Private notes (internal use only).
	 *
	 * The limit applies to each group on its own: an order over the limit keeps
	 * its newest notes per group, so a run of newer customer notes cannot push
	 * every private note out of the export, or the other way round.
	 *
	 * @since 4.9.0
	 * @since 5.0.2 Grouped by visibility.
	 * @since 5.3.2 Bounded at the order-notes limit, applied per visibility group.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array{
	 * private: string[],
	 * customer: string[]
	 * }
	 */
	public static function get_order_notes( WC_Order $order ): array {
		if ( 0 === $order->get_id() ) {
			// WP_Comment_Query drops the post_id clause for 0, so an unsaved
			// order would fetch other orders' notes.
			return self::empty_note_groups();
		}

		if ( isset( self::$order_notes_cache[ $order->get_id() ] ) ) {
			return self::$order_notes_cache[ $order->get_id() ];
		}

		$limit = self::get_order_notes_limit();

		// Probe one row past the limit, so an order holding exactly the limit
		// fills a window without overflowing it and is served from this one
		// query instead of being refetched per group.
		$fetched = self::fetch_order_notes(
			self::order_note_query_args(
				array( 'post_id' => $order->get_id() ),
				$limit + 1
			)
		);

		if ( null === $fetched ) {
			// The query failed (logged at the fetch). Do not cache, so a later
			// caller can retry.
			return self::empty_note_groups();
		}

		if ( count( $fetched ) <= $limit ) {
			// The order fits in one window, so every note is here and each
			// group is complete. The comment-meta lazyloader primes the whole
			// window in one query on the first meta read in group_order_notes().
			self::$order_notes_cache[ $order->get_id() ] = self::group_order_notes( $fetched );

			return self::$order_notes_cache[ $order->get_id() ];
		}

		// An overfull window means the order has more notes than the limit,
		// and the newest-N-of-any-kind set can starve one visibility group to
		// empty while the other overflows. Refetch with the bound per group.
		$grouped = self::fetch_order_notes_by_group( $order->get_id(), $limit );

		if ( in_array( null, $grouped, true ) ) {
			// Only the failed group degrades (logged at the fetch): the probe
			// window is real data, so that group exports its slice of it,
			// trimmed to the bound. The slice can still be empty when the
			// window holds only the other group's notes; a group whose own
			// query succeeded keeps its exact result either way. Not cached,
			// so a later caller can retry the full path.
			$window = self::group_order_notes( array_slice( $fetched, 0, $limit ) );

			foreach ( $grouped as $group => $notes ) {
				if ( null === $notes ) {
					$grouped[ $group ] = $window[ $group ];
				}
			}

			return $grouped;
		}

		self::$order_notes_cache[ $order->get_id() ] = $grouped;

		return self::$order_notes_cache[ $order->get_id() ];
	}

	/**
	 * Build the get_comments() args shared by every order-note fetch.
	 *
	 * One builder so the live fetch paths cannot drift apart. The raw-SQL
	 * counts mirror these defaults by hand and are the one place that can
	 * still drift; keep them in step when editing this.
	 *
	 * @since 5.3.2
	 *
	 * @param array $target Target clause: array( 'post_id' => $id ) or array( 'post__in' => $ids ).
	 * @param int   $number Maximum rows to fetch.
	 * @return array Arguments for get_comments().
	 */
	private static function order_note_query_args( array $target, int $number ): array {
		return $target + array(
			'approve'      => 'approve',
			'type'         => 'order_note',
			'number'       => $number,
			// Stated, not inherited: with `number` this is what keeps the
			// newest notes.
			'orderby'      => 'comment_date_gmt',
			'order'        => 'DESC',
			// The comment-query cache key hashes query vars only, so the
			// swapped clauses in fetch_order_notes() are invisible to it. A
			// dedicated domain keeps this fetch's narrowed IDs from being
			// served to a third-party query with identical vars, and theirs
			// from being served here.
			'cache_domain' => 'wc_shipstation_order_notes',
		);
	}

	/**
	 * Maximum number of order notes exported per order.
	 *
	 * @since 5.3.2
	 *
	 * @return int Always between 1 and MAX_ORDER_NOTES_LIMIT.
	 */
	public static function get_order_notes_limit(): int {
		/**
		 * Filters the maximum number of order notes exported per order.
		 *
		 * The limit applies to each visibility group (private and customer) on
		 * its own, so an order over it keeps its newest notes per group. A
		 * value below 1 is ignored and a value above 500 is clamped: an
		 * unbounded fetch is the fault this bound exists to prevent.
		 *
		 * Raising the limit raises peak memory: every query is individually
		 * bounded, but the per-request notes map still holds limit x page-size
		 * note strings at once (plus the response holding them again), and the
		 * fix was only measured safe at the defaults (50 notes x 100 orders).
		 * At the ceilings (500 x 500) that map alone can reach hundreds of MB.
		 *
		 * @since 5.3.2
		 *
		 * @param int $limit Maximum notes per order. Default 50, range 1 to 500.
		 */
		$limit = (int) apply_filters( 'woocommerce_shipstation_order_notes_limit', self::DEFAULT_ORDER_NOTES_LIMIT );

		if ( $limit < 1 ) {
			return self::DEFAULT_ORDER_NOTES_LIMIT;
		}

		return min( $limit, self::MAX_ORDER_NOTES_LIMIT );
	}

	/**
	 * Run an order-note comment query with the export's own visibility rules.
	 *
	 * Swaps two clauses for the duration of the query: WooCommerce's
	 * `exclude_order_comments` comes off, and system notes are excluded in SQL.
	 * System notes are dropped from the payload anyway, so fetching them only
	 * builds WP_Comment objects to throw away.
	 *
	 * @since 5.3.2
	 *
	 * @param array $args Arguments for get_comments().
	 * @return array|null Comment objects, or null when the query failed.
	 */
	private static function fetch_order_notes( array $args ): ?array {
		global $wpdb;

		$authors = self::system_note_authors();

		$exclude_system_notes = static function ( $clauses ) use ( $wpdb, $authors ) {
			$template = self::system_author_exclusion_sql( "{$wpdb->comments}.comment_author", $authors );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $template is a caller-built column reference plus %s placeholders the sniff cannot see; the author values bind right here.
			$clauses['where'] .= ' AND ' . $wpdb->prepare( $template, ...$authors );

			return $clauses;
		};

		// Capture whether WooCommerce's exclusion was actually attached, so a
		// context that deliberately removed it does not get it re-added below.
		$had_wc_exclusion = remove_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ), 10 );
		add_filter( 'comments_clauses', $exclude_system_notes );

		// A leftover last_error from an earlier, unrelated query must not read
		// as this fetch failing when get_comments() is served from cache. A
		// failure can only be this fetch's own if a query actually ran (wpdb
		// clears last_error at the start of each query), so gate on the query
		// count instead of $wpdb->flush(), which would also wipe last_result
		// and friends for the whole request.
		$queries_before = $wpdb->num_queries;

		try {
			$notes = get_comments( $args );
		} finally {
			// Restore even when a third-party comment-query callback throws, or
			// the swap leaks into every later comment query in the request.
			remove_filter( 'comments_clauses', $exclude_system_notes );
			if ( $had_wc_exclusion ) {
				add_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ), 10, 1 );
			}
		}

		// get_comments() returns an empty array for a failed query and for a
		// target that genuinely has no matching notes; $wpdb->last_error is the
		// only signal separating the two. The oracle is a heuristic and can miss
		// in one direction: a hooked callback running its own successful query
		// after the failed one clears last_error, and the failure then reads as
		// a genuine empty. The reverse (a callback's failing side query flagging
		// a good fetch) only costs a skipped cache write and a retry, not data.
		if ( $wpdb->num_queries > $queries_before && '' !== $wpdb->last_error ) {
			$target = isset( $args['post_id'] )
				? 'order ' . (int) $args['post_id']
				: count( (array) ( $args['post__in'] ?? array() ) ) . ' order(s)';
			self::log_safely( sprintf( 'Order note query failed for %s: %s', $target, self::sanitize_for_log( (string) $wpdb->last_error ) ) );

			// WP_Comment_Query caches its ID list unconditionally, so the failed
			// query just cached an empty result under the current last-changed
			// salt. Bump the salt or every retry is served that poisoned empty,
			// indistinguishable from a genuine no-notes order.
			//
			// Every failure bumps, not just the first of a request. Bumping
			// once would leave a second failing query with identical args
			// reading the first failure's cached empty: no query runs, so the
			// num_queries gate above sees nothing, and the failure is
			// reclassified as a genuine no-notes order. The salt is site-wide
			// across every comment consumer, so a page of over-limit orders
			// against a persistently broken query does bump repeatedly; that
			// cost is accepted because the alternative silently exports empty.
			wp_cache_set_last_changed( 'comment' );

			return null;
		}

		return (array) $notes;
	}

	/**
	 * The empty grouped-notes structure every note path starts from.
	 *
	 * @since 5.3.2
	 *
	 * @return array{private: string[], customer: string[]}
	 */
	private static function empty_note_groups(): array {
		return array(
			'private'  => array(),
			'customer' => array(),
		);
	}

	/**
	 * Comment authors WooCommerce writes on its own system notes.
	 *
	 * WC stores the author as the translatable __( 'WooCommerce', 'woocommerce' )
	 * (WC_Order::add_order_note()) and compares against the translated string
	 * when classifying notes (wc_get_order_notes()), so on a translated locale
	 * the stored author is the translation, not the literal. Both are excluded
	 * so the plugin and core agree on what a system note is; undetected system
	 * notes would consume cap slots and can evict every merchant note.
	 *
	 * @since 5.3.2
	 *
	 * @return string[] One or two author strings, literal first.
	 */
	private static function system_note_authors(): array {
		$authors = array( self::SYSTEM_NOTE_AUTHOR );

		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- deliberately WC core's domain: this reads the exact translation WooCommerce stamps on its own system notes.
		$translated = __( 'WooCommerce', 'woocommerce' );
		if ( self::SYSTEM_NOTE_AUTHOR !== $translated ) {
			$authors[] = $translated;
		}

		return $authors;
	}

	/**
	 * Placeholder SQL template excluding system notes, byte-exact on the author.
	 *
	 * CAST AS BINARY keeps the comparison byte-exact, matching the strict
	 * in_array() in export_note_content(); the column collation would otherwise
	 * also drop authors differing only in case or trailing spaces. One builder
	 * for the live fetch's clause filter and the raw count query, so the two
	 * comparisons cannot drift apart.
	 *
	 * The template is unprepared on purpose: the caller passes the same
	 * $authors array through its own prepare(), so no prepared fragment is ever
	 * re-scanned by a second prepare().
	 *
	 * The authors are a parameter rather than a second system_note_authors()
	 * call so the placeholder count and the bound values cannot disagree: one
	 * array sizes the template and supplies the arguments. Deriving them
	 * separately would leave prepare() to fail on a count mismatch, and a
	 * failed prepare() returns an empty string, degrading the WHERE fragment
	 * into malformed SQL.
	 *
	 * @since 5.3.2
	 *
	 * @param string   $column  Comment-author column reference, caller-built
	 *                          from wpdb table names, never from input.
	 * @param string[] $authors System authors, from system_note_authors(); the
	 *                          same array must be bound by the caller.
	 * @return string SQL fragment with one %s per system author.
	 */
	private static function system_author_exclusion_sql( string $column, array $authors ): string {
		$placeholders = implode( ',', array_fill( 0, count( $authors ), '%s' ) );

		return "CAST({$column} AS BINARY) NOT IN ({$placeholders})";
	}

	/**
	 * Decode one note's exportable content, or null for a system note.
	 *
	 * System notes are already excluded in SQL; a third-party clause could put
	 * them back, so the byte-exact author guard runs here too.
	 *
	 * The authors are a parameter because this runs once per note: resolving
	 * them here would put a __() call and its two filter dispatches on the
	 * per-note path, thousands of times on a full page. Callers resolve once
	 * per loop.
	 *
	 * @since 5.3.2
	 *
	 * @param object   $note    Comment object.
	 * @param string[] $authors System authors, from system_note_authors().
	 * @return string|null Decoded content, or null when the note is not exported.
	 */
	private static function export_note_content( object $note, array $authors ): ?string {
		if ( in_array( $note->comment_author, $authors, true ) ) {
			return null;
		}

		return html_entity_decode( $note->comment_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Split order-note comments into the customer-visible and private groups.
	 *
	 * @since 5.3.2
	 *
	 * @param array $notes Comment objects for a single order.
	 * @return array{private: string[], customer: string[]}
	 */
	private static function group_order_notes( array $notes ): array {
		$order_notes = self::empty_note_groups();
		$authors     = self::system_note_authors();

		foreach ( $notes as $note ) {
			$content = self::export_note_content( $note, $authors );
			if ( null === $content ) {
				continue;
			}

			$note_type                   = (bool) get_comment_meta( $note->comment_ID, 'is_customer_note', true ) ? 'customer' : 'private';
			$order_notes[ $note_type ][] = $content;
		}

		return $order_notes;
	}

	/**
	 * Fetch an over-limit order's notes with the bound applied per visibility group.
	 *
	 * One bounded query per group, selected in SQL on the `is_customer_note`
	 * meta, so each group keeps its own newest notes. A single newest-N query
	 * cannot do that: whichever group dominates the newest N evicts the other.
	 * The group is known from the query itself, so no note needs a meta read.
	 *
	 * @since 5.3.2
	 *
	 * @param int $order_id Order ID.
	 * @param int $limit    Maximum notes per group.
	 * @return array{private: string[]|null, customer: string[]|null} Grouped
	 *                     note contents; a group is null when its own query
	 *                     failed, so the caller can degrade just that group.
	 */
	private static function fetch_order_notes_by_group( int $order_id, int $limit ): array {
		$grouped = self::empty_note_groups();

		foreach ( array_keys( $grouped ) as $group ) {
			$notes = self::fetch_group_notes( array( 'post_id' => $order_id ), $group, $limit );

			$grouped[ $group ] = ( null === $notes ) ? null : self::collect_note_contents( $notes );
		}

		return $grouped;
	}

	/**
	 * Run one bounded order-note query narrowed to a single visibility group.
	 *
	 * @since 5.3.2
	 *
	 * @param array  $target Target clause: array( 'post_id' => $id ) or array( 'post__in' => $ids ).
	 * @param string $group  'private' or 'customer'.
	 * @param int    $number Maximum rows to fetch.
	 * @return array|null Comment objects, or null when the query failed.
	 */
	private static function fetch_group_notes( array $target, string $group, int $number ): ?array {
		$args               = self::order_note_query_args( $target, $number );
		$args['meta_query'] = self::group_meta_query( $group ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded query, keyed meta, no alternative for per-group selection.

		return self::fetch_order_notes( $args );
	}

	/**
	 * Reduce comment objects to their exportable contents, in query order.
	 *
	 * @since 5.3.2
	 *
	 * @param array $notes Comment objects.
	 * @return string[] Decoded contents, system notes dropped.
	 */
	private static function collect_note_contents( array $notes ): array {
		$contents = array();
		$authors  = self::system_note_authors();

		foreach ( $notes as $note ) {
			$content = self::export_note_content( $note, $authors );
			if ( null !== $content ) {
				$contents[] = $content;
			}
		}

		return $contents;
	}

	/**
	 * Meta query selecting one visibility group of order notes.
	 *
	 * Follows group_order_notes(): a note is customer-visible when its
	 * `is_customer_note` meta is truthy, private when the meta is absent or
	 * falsy ('' or '0'). The match is exact for the values WooCommerce
	 * writes; shapes it never produces can diverge, such as a
	 * whitespace-padded value under a PAD SPACE collation, or duplicate meta
	 * rows, where SQL matches any row but PHP reads a single one.
	 *
	 * @since 5.3.2
	 *
	 * @param string $group 'private' or 'customer'.
	 * @return array Meta query clauses.
	 */
	private static function group_meta_query( string $group ): array {
		if ( 'customer' === $group ) {
			return array(
				array(
					'key'     => 'is_customer_note',
					'value'   => array( '', '0' ),
					'compare' => 'NOT IN',
				),
			);
		}

		return array(
			'relation' => 'OR',
			array(
				'key'     => 'is_customer_note',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => 'is_customer_note',
				'value'   => array( '', '0' ),
				'compare' => 'IN',
			),
		);
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

		// Collect valid WC orders keyed by ID.
		$candidates = array();
		foreach ( $orders as $order ) {
			if ( ! self::is_wc_order( $order ) ) {
				continue;
			}
			$candidates[ $order->get_id() ] = $order;
		}

		if ( empty( $candidates ) ) {
			return;
		}

		global $wpdb;
		$hpos    = self::custom_orders_table_usage_is_enabled();
		$sync_on = self::data_sync_is_enabled();

		/*
		 * Idempotency guard (SHIPSTN-139): read the marker straight from the DB
		 * rather than $order->get_meta(). The raw marker write below bypasses WC's
		 * data store and never invalidates the order/meta object cache, so on
		 * stores with a persistent object cache the cached order keeps reporting
		 * the pre-marker state; a get_meta() guard would miss and append a
		 * duplicate "exported" note on every poll. One SELECT covers the batch,
		 * against the authoritative read table for the active storage backend.
		 */
		$read_table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$read_col   = $hpos ? 'order_id' : 'post_id';

		$candidate_ids          = array_keys( $candidates );
		$candidate_placeholders = implode( ',', array_fill( 0, count( $candidate_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reads the exported flag for a batch of orders from whichever meta table the active storage backend uses; core has no batched equivalent. The table and column are literals chosen above and the IDs are bound as %d. Not cached: the value is the guard against re-exporting, so a stale read would duplicate notes.
		$already_marked = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $read_table and $read_col are string literals; IDs are %d.
				"SELECT DISTINCT {$read_col} FROM {$read_table} WHERE meta_key = '_shipstation_exported' AND meta_value = 'yes' AND {$read_col} IN ({$candidate_placeholders})",
				...$candidate_ids
			)
		);

		// If the guard read itself failed, bail. Treating a failed SELECT (which
		// yields an empty result) as "nothing is marked yet" would send every
		// candidate down the write path and append a duplicate "exported" note to
		// orders that already have the marker — the exact symptom this fix targets
		// (SHIPSTN-139). Skipping this batch is safe: the next poll retries.
		if ( '' !== $wpdb->last_error ) {
			self::log_safely(
				sprintf(
					'mark_orders_exported_bulk guard SELECT failed for %d order(s) (%s): %s',
					count( $candidate_ids ),
					implode( ',', array_slice( $candidate_ids, 0, 20 ) ),
					self::sanitize_for_log( (string) $wpdb->last_error )
				)
			);
			return;
		}

		// Flipped to an ID-keyed lookup: a batch is up to 500 orders, so a linear
		// scan per candidate would be quadratic on the poll hot path.
		$already_marked = array_flip( array_map( 'absint', (array) $already_marked ) );

		$to_mark = array();
		foreach ( $candidates as $id => $order ) {
			if ( isset( $already_marked[ $id ] ) ) {
				continue;
			}
			$to_mark[ $id ] = $order;
		}

		if ( empty( $to_mark ) ) {
			return;
		}

		$ids = array_keys( $to_mark );

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
		 * | on   | on   | postmeta + wc_orders_meta   |
		 * | off  | on   | wc_orders_meta + wp_postmeta|
		 *
		 * The guard's read table is written LAST and any failure aborts the rest:
		 * a half-applied write then leaves no visible marker, so the next poll
		 * retries the whole thing instead of skipping an order it never noted.
		 */
		$hpos_target = array(
			'table' => $wpdb->prefix . 'wc_orders_meta',
			'col'   => 'order_id',
		);
		$cpt_target  = array(
			'table' => $wpdb->postmeta,
			'col'   => 'post_id',
		);

		$targets = array();
		if ( $hpos ) {
			if ( $sync_on ) {
				$targets[] = $cpt_target;
			}
			$targets[] = $hpos_target;
		} else {
			if ( $sync_on ) {
				$targets[] = $hpos_target;
			}
			$targets[] = $cpt_target;
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Clears the exported flag for a batch in one statement; core would need one delete_post_meta() per order. A write has nothing to cache.
			$delete_result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table and $object_col are string literals; $id_placeholders is a list of %d tokens.
					"DELETE FROM {$table} WHERE meta_key = '_shipstation_exported' AND {$object_col} IN ({$id_placeholders})",
					...$ids
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes the exported flag for a batch in one statement, paired with the delete above. A write has nothing to cache.
			$insert_result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table and $object_col are string literals; IDs are %d.
					"INSERT INTO {$table} ({$object_col}, meta_key, meta_value) VALUES {$row_placeholders}",
					...$ids
				)
			);

			if ( false === $delete_result || false === $insert_result ) {
				self::log_safely(
					sprintf(
						'mark_orders_exported_bulk SQL failed on %s (delete=%s, insert=%s): %s',
						$table,
						var_export( $delete_result, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
						var_export( $insert_result, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
						self::sanitize_for_log( (string) $wpdb->last_error )
					)
				);
				$all_ok = false;
				// Stop before the read table, so no marker becomes visible.
				break;
			}
		}

		// Bail without writing notes if any statement failed. The read table is
		// written last, so the guard still sees no marker, the orders stay on the
		// queue, and the next poll retries the whole write.
		if ( ! $all_ok ) {
			return;
		}

		// The raw writes above bypass WC's data store, so nothing invalidates
		// the order/meta object cache automatically. Clear the per-order caches
		// for the written IDs so the next poll's guard read — and any other
		// get_meta( '_shipstation_exported' ) reader (e.g. the privacy
		// exporter) — sees the marker instead of stale, pre-marker meta
		// (SHIPSTN-139).
		self::invalidate_exported_marker_cache( $ids, $hpos, $sync_on );

		// Residual race (SHIPSTN-139): the guard SELECT and the marker write are
		// not one atomic transaction, so two ShipStation polls overlapping on the
		// same order can both pass the guard and each add a note. The direct-DB
		// guard closes the common cache-staleness cause and shrinks the window to
		// truly concurrent polls; fully closing it would need row locking
		// (GET_LOCK / SELECT ... FOR UPDATE), a heavier change deferred for now.
		// Contained per order: add_order_note() hands control to third-party
		// callbacks on woocommerce_order_note_added, and the markers above are
		// already written for the whole batch. An uncontained throwable on one
		// order therefore costs every later order its note permanently, because
		// the next poll's guard sees the marker and skips the batch (SHIPSTN-171).
		foreach ( $to_mark as $order ) {
			self::add_order_note_safely( $order, __( 'Order has been exported to Shipstation', 'woocommerce-shipstation-integration' ), false );
		}
	}

	/**
	 * Invalidate the per-order object/meta caches for the `_shipstation_exported`
	 * marker after a raw-SQL write bypassed WC's data store.
	 *
	 * Mirrors the invalidation WooCommerce performs itself when CRUD is bypassed
	 * for performance (see OrdersTableDataStore::clear_cached_data() and
	 * get_post_orders_for_ids()). Without this, a persistent object cache keeps
	 * serving the pre-marker order/meta, so the idempotency guard and other
	 * get_meta() readers miss the marker (SHIPSTN-139).
	 *
	 * @since 5.3.1
	 *
	 * @param int[] $ids     Order IDs whose marker was just written.
	 * @param bool  $hpos    Whether HPOS is the active storage backend.
	 * @param bool  $sync_on Whether HPOS<->posts sync is enabled.
	 * @return void
	 */
	private static function invalidate_exported_marker_cache( array $ids, bool $hpos, bool $sync_on ): void {
		if ( empty( $ids ) ) {
			return;
		}

		// $order->get_meta() reads the 'orders'-group meta cache on BOTH backends
		// (WC_Abstract_Order::$cache_group) before the DB, and the raw write never
		// busts it — so clear it for every backend, not just HPOS. Mirrors
		// OrdersTableDataStore::get_post_orders_for_ids(). Only the delete is
		// batched: generate_meta_cache_key() still does a wp_cache_get per ID.
		wp_cache_delete_multiple(
			array_map(
				static function ( $id ) {
					return WC_Order::generate_meta_cache_key( $id, 'orders' );
				},
				$ids
			),
			'orders'
		);

		if ( $hpos ) {
			// Both wc_get_container()->get() and WC_Data_Store::load() can throw,
			// and they run after the marker is committed but before the note. An
			// unguarded throw loses the note permanently — the direct-DB guard
			// skips the order on every later poll. Invalidation is best-effort.
			try {
				// wc_get_order() consults OrderCache before the data store, and
				// clear_cached_data() does not touch OrderCache — clear both, as
				// OrdersTableDataStore::delete_order_data_from_custom_order_tables() does.
				$order_cache_class = 'Automattic\\WooCommerce\\Caches\\OrderCache';
				if ( class_exists( $order_cache_class ) && function_exists( 'wc_get_container' ) ) {
					$order_cache = wc_get_container()->get( $order_cache_class );
					foreach ( $ids as $id ) {
						$order_cache->remove( $id );
					}
				}

				// Only the HPOS data store implements clear_cached_data(), and it is
				// @internal — method_exists() guards removal, not a signature change.
				$store           = WC_Data_Store::load( 'order' );
				$store_classname = $store->get_current_class_name();
				if ( method_exists( $store_classname, 'clear_cached_data' ) ) {
					// call_user_func: WC_Data_Store proxies this through __call(),
					// which the WC stubs do not model, so a direct call trips PHPStan.
					call_user_func( array( $store, 'clear_cached_data' ), $ids );
				}
			} catch ( \Throwable $e ) {
				// \Throwable, not \Exception: a broken service definition surfaces
				// as \Error, which would otherwise fatal and lose the note.
				self::log_safely(
					sprintf( 'mark_orders_exported_bulk HPOS cache invalidation failed: %s', self::throwable_message( $e ) )
				);
			}
		}

		if ( ! $hpos || $sync_on ) {
			// Clear the CPT postmeta cache (authoritative read source when HPOS
			// is off; kept consistent under sync). Batched for the same hot-path
			// reason as the 'orders' delete above. No-op if unused.
			wp_cache_delete_multiple( $ids, 'post_meta' );
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

		// A drop-in object cache may return something other than the expected
		// map; treat that as a total miss rather than indexing into it, the way
		// core's own batch primes do.
		$to_prime = array();
		if ( ! is_array( $cache_values ) ) {
			$to_prime = $order_ids;
		} else {
			foreach ( $order_ids as $id ) {
				if ( false === $cache_values[ 'order-items-' . $id ] ) {
					$to_prime[] = $id;
				}
			}
		}

		if ( empty( $to_prime ) ) {
			return;
		}

		global $wpdb;
		$id_placeholders = implode( ',', array_fill( 0, count( $to_prime ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Primes the order-items cache in one read; this is the cache warm itself, so there is nothing to read it from.
		$items = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $id_placeholders is a generated run of %d tokens; the IDs themselves are bound below.
				"SELECT order_item_type, order_item_id, order_id, order_item_name FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN ( {$id_placeholders} ) ORDER BY order_item_id",
				...array_map( 'intval', $to_prime )
			)
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
	 * Prime the refund cache for a batch of order IDs.
	 *
	 * `$order->get_refunds()` caches each order's refunds in the `'orders'`
	 * group, but WooCommerce changed the key and the payload in 10.8.0:
	 *
	 * - WC 10.8+  reads `WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'refund_ids' . $id`, holding refund IDs.
	 * - WC 10.7.x reads `WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'refunds' . $id`, holding refund objects.
	 *
	 * Core's own batch helper, `prime_refund_caches_for_orders()`, checks
	 * whichever key its version reads and skips every order already cached; it
	 * exists from WC 10.7 only. Only the key the running version reads is
	 * primed and checked for warmth here: hydrated refund objects are
	 * expensive to serialize into a persistent cache group and nothing reads
	 * their key on 10.8+, while the `refund_ids` key costs one small array and
	 * is written on every version. Checking the running version's key alone
	 * also lets a batch that core primed moments earlier skip cleanly, since
	 * core writes only that key. The plugin declares `WC requires at least:
	 * 10.8`, but WordPress does not enforce that header, so a 10.7 store can
	 * update the plugin and land in this method: there the objects key is
	 * primed too, because core's prime would otherwise re-fetch and fatal on
	 * the corrupted row exactly as it does without this method.
	 *
	 * The `'orders'` group can be persistent, so a degraded entry written here
	 * stays visible to every later reader - other requests included - until an
	 * order write rotates the group prefix (`wc_delete_shop_order_transients()`).
	 * The keys written on the degraded path are recorded so
	 * flush_degraded_refund_primes() can drop them at the end of the request.
	 *
	 * Fetches every refund whose parent is in the batch with a single
	 * `wc_get_orders()` call (HPOS aliases `post_parent__in` to
	 * `parent_order_id`), groups the refunds by parent, and stashes each
	 * parent's IDs and objects under the two keys above.
	 *
	 * A refund that cannot be hydrated (corrupted row, SHIPSTN-164) is logged
	 * and skipped instead of fataling the batch: its parent order is primed
	 * with its readable refunds only, so downstream `get_refunds()` callers
	 * (and core's own prime, which would otherwise re-fetch and hit the same
	 * fatal) do not touch the bad row for the rest of the request;
	 * flush_degraded_refund_primes() then leaves the next reader to query
	 * again. Call this BEFORE hydrating the batch's order objects: a
	 * `wc_get_orders()` fetch of shop_orders runs core's refund prime
	 * internally, and only a warm cache keeps it from throwing.
	 *
	 * A fetch that returns zero refunds for the whole batch writes nothing:
	 * that result cannot be told apart from a query failure that HPOS
	 * swallows into an empty set, and an empty entry would stop core's prime
	 * from ever re-checking. The batch is left cold for core's prime instead.
	 * On a page with no refunds at all this forfeits the prime's saving (the
	 * XML export has no core batch prime, so its orders resolve refunds one
	 * by one); that costs one query and is accepted over trusting an
	 * ambiguous empty answer, since the batch query and the per-order queries
	 * do not necessarily fail together.
	 *
	 * @since 5.0.4
	 * @since 5.3.4 Primes the key the running WooCommerce reads (plus the
	 *              pre-10.8 `refunds` key below 10.8), falls back to
	 *              per-refund reads when the bulk fetch throws, and skips the
	 *              write entirely when the fetch reports no refunds for the
	 *              batch.
	 * @since 5.3.9 Records the keys written on the degraded path for
	 *              flush_degraded_refund_primes().
	 *
	 * @see \WC_Order::get_refunds() Reads the cache keys primed here.
	 * @see \Abstract_WC_Order_Data_Store_CPT::prime_refund_caches_for_orders() Core's
	 *      batch prime; runs inside shop_order hydration and skips warm keys.
	 *
	 * @param int[] $order_ids Order IDs to prime.
	 * @return void
	 */
	public static function prime_refunds_for_batch( array $order_ids ): void {
		if ( empty( $order_ids ) ) {
			return;
		}

		$order_ids = array_values( array_unique( array_map( 'absint', $order_ids ) ) );

		$prime_legacy = self::needs_legacy_refund_cache_key();

		$prefix      = \WC_Cache_Helper::get_cache_prefix( 'orders' );
		$read_keys   = array();
		$id_keys     = array();
		$object_keys = array();
		foreach ( $order_ids as $id ) {
			$id_keys[ $id ] = $prefix . 'refund_ids' . $id;
			if ( $prime_legacy ) {
				$object_keys[ $id ] = $prefix . 'refunds' . $id;
			}
			$read_keys[ $id ] = $prime_legacy ? $object_keys[ $id ] : $id_keys[ $id ];
		}

		$cache_values = wc_cache_get_multiple( array_values( $read_keys ), 'orders' );

		// Matches core's guard in prime_refund_caches_for_orders(): a drop-in
		// object cache that answers with anything but the expected map means
		// nothing is known to be warm.
		//
		// An order is skipped when the key the running WooCommerce reads is
		// warm. Core's own prime writes only that key, so requiring more would
		// re-prime every batch core already primed.
		$to_prime = array();
		if ( ! is_array( $cache_values ) ) {
			$to_prime = $order_ids;
		} else {
			foreach ( $order_ids as $id ) {
				if ( false === $cache_values[ $read_keys[ $id ] ] ) {
					$to_prime[] = $id;
				}
			}
		}

		if ( empty( $to_prime ) ) {
			return;
		}

		$degraded = false;

		try {
			$refunds = wc_get_orders(
				array(
					'type'            => 'shop_order_refund',
					'post_parent__in' => $to_prime,
					'limit'           => -1,
				)
			);

			if ( empty( $refunds ) ) {
				// Zero refunds for the whole batch is indistinguishable from a
				// silently failed query: OrdersTableDataStore::query() swallows
				// an \Exception from the query build into an empty result. An
				// empty entry is authoritative for core's own prime, so writing
				// it here would export the page without refunds and poison the
				// shared 'orders' group. Write nothing and let core's prime
				// fill the keys. A result holding at least one refund proves
				// the query ran (the swallow replaces the whole result, never
				// part of it), so per-parent empty sets are safe below.
				return;
			}
		} catch ( \Throwable $e ) {
			// A corrupted refund row can make WooCommerce core throw while
			// hydrating the refund object (SHIPSTN-164). Retry with one
			// non-hydrating ID listing plus per-refund reads so a single bad
			// row degrades its own order instead of fataling the whole batch.
			self::log_safely(
				sprintf(
					'Bulk refund fetch failed while priming refunds for export: %s: %s. Retrying per refund.',
					get_class( $e ),
					self::throwable_message( $e )
				)
			);

			$fallback = self::get_readable_refunds( $to_prime );
			$refunds  = $fallback['refunds'];

			// An order whose refund IDs could not even be listed must not be
			// primed: an empty prime would hide refunds that may be readable.
			$to_prime = array_values( array_diff( $to_prime, $fallback['skip_parents'] ) );

			if ( empty( $to_prime ) ) {
				return;
			}

			$degraded = true;
		}

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
			$refund_ids = array();
			foreach ( $list as $refund ) {
				$refund_ids[] = $refund->get_id();
			}

			wp_cache_set( $id_keys[ $id ], $refund_ids, 'orders' );
			if ( $degraded ) {
				self::record_degraded_refund_key( $id_keys[ $id ] );
			}
			if ( $prime_legacy ) {
				wp_cache_set( $object_keys[ $id ], $list, 'orders' );
				if ( $degraded ) {
					self::record_degraded_refund_key( $object_keys[ $id ] );
				}
			}
		}
	}

	/**
	 * Cache keys written by prime_refunds_for_batch() on its degraded path.
	 *
	 * @var string[]
	 */
	private static array $degraded_refund_keys = array();

	/**
	 * Remember a key written on the degraded path.
	 *
	 * The first key also arms a shutdown flush, so an uncaught throw or a
	 * fatal before the surface's own flush call cannot leave the key behind.
	 * The exact key is kept rather than the order ID because an order save
	 * mid-batch rotates the group prefix.
	 *
	 * @since 5.3.9
	 *
	 * @param string $key Cache key in the 'orders' group.
	 * @return void
	 */
	private static function record_degraded_refund_key( string $key ): void {
		if ( empty( self::$degraded_refund_keys ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_degraded_refund_primes' ) );
		}

		self::$degraded_refund_keys[] = $key;
	}

	/**
	 * Drop the refund cache entries written on the degraded prime path.
	 *
	 * A list primed after a hydration failure leaves out the refunds that could
	 * not be read, under the key `WC_Order::get_refunds()` reads in the shared,
	 * possibly persistent `'orders'` group. Every other reader would see the
	 * order with fewer refunds than it has until an order save rotates the
	 * prefix (SHIPSTN-168). Called at the end of every export page and
	 * shipnotify request, and once more at shutdown when a degraded prime ran;
	 * a clean prime records no keys, so it costs nothing there. A concurrent
	 * request can still read the list before this runs. Each delete is
	 * contained on its own: a throwing object cache must not turn a built
	 * page into a 500 from inside a finally, nor keep the other keys.
	 *
	 * @since 5.3.9
	 *
	 * @return void
	 */
	public static function flush_degraded_refund_primes(): void {
		$keys                       = array_unique( self::$degraded_refund_keys );
		self::$degraded_refund_keys = array();
		remove_action( 'shutdown', array( __CLASS__, 'flush_degraded_refund_primes' ) );

		if ( empty( $keys ) ) {
			return;
		}

		// One dispatch per key, so a delete that throws does not keep the rest.
		foreach ( $keys as $key ) {
			self::dispatch_safely(
				'the degraded refund cache flush',
				static function () use ( $key ) {
					wp_cache_delete( $key, 'orders' );
				}
			);
		}
	}

	/**
	 * Overrides the WC_VERSION check in needs_legacy_refund_cache_key().
	 *
	 * Test seam only: WC_VERSION is a constant and cannot vary per test.
	 * Null derives the answer from the running WooCommerce.
	 *
	 * @var bool|null
	 */
	private static ?bool $needs_legacy_refund_cache_key = null;

	/**
	 * Whether the pre-10.8 refund objects cache key must be primed.
	 *
	 * `WC_Order::get_refunds()` reads `refund_ids{id}` (IDs) from WC 10.8 and
	 * `refunds{id}` (hydrated objects) before that. Nothing reads the objects
	 * key on 10.8+, and hydrated refund objects are expensive to store in a
	 * persistent cache group, so it is primed only where it is read.
	 *
	 * @since 5.3.4
	 *
	 * @return bool
	 */
	private static function needs_legacy_refund_cache_key(): bool {
		if ( null !== self::$needs_legacy_refund_cache_key ) {
			return self::$needs_legacy_refund_cache_key;
		}

		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '10.8', '<' );
	}

	/**
	 * Fetch the refunds for a set of parent orders, skipping any refund that
	 * cannot be hydrated.
	 *
	 * Fallback for prime_refunds_for_batch() when the bulk fetch throws. One
	 * ID-only query lists the batch's refunds - `'return' => 'ids'` never
	 * hydrates a refund object, so a corrupted row (SHIPSTN-164) cannot throw
	 * there - and each refund is then read individually. An unreadable refund
	 * is logged and left out while every readable refund is still returned.
	 *
	 * A listing that returns zero IDs marks every parent as unprimable: the
	 * bulk fetch only throws while hydrating a refund, so the batch
	 * demonstrably holds at least one refund row, and an empty listing can
	 * only mean the query failed silently (HPOS swallows a query-build
	 * exception into an empty result). Trusting it would prime every order
	 * in the batch as refund-less and hide real refunds from the shared
	 * cache. A listing that returns IDs proves the query ran, so parents
	 * whose refunds all turn out unreadable are still safely primed with an
	 * empty, filtered set.
	 *
	 * Cost is one query plus one read per refund, independent of how many
	 * orders the page holds.
	 *
	 * @since 5.3.4
	 *
	 * @param int[] $parent_ids Parent order IDs whose refunds to fetch.
	 * @return array{refunds: \WC_Order_Refund[], skip_parents: int[]} Readable
	 *               refunds, plus the parents whose refund IDs could not be
	 *               listed (callers must not prime those orders).
	 */
	private static function get_readable_refunds( array $parent_ids ): array {
		$readable = array();

		try {
			$refund_ids = wc_get_orders(
				array(
					'type'            => 'shop_order_refund',
					'post_parent__in' => $parent_ids,
					'limit'           => -1,
					'return'          => 'ids',
				)
			);
		} catch ( \Throwable $e ) {
			self::log_safely(
				sprintf(
					'Refund IDs for the export batch could not be listed while priming refunds for export; its orders are left unprimed: %s: %s',
					get_class( $e ),
					self::throwable_message( $e )
				)
			);

			return array(
				'refunds'      => array(),
				'skip_parents' => $parent_ids,
			);
		}

		if ( empty( $refund_ids ) ) {
			self::log_safely(
				'Refund IDs for the export batch came back empty after a refund hydration failure; its orders are left unprimed.'
			);

			return array(
				'refunds'      => array(),
				'skip_parents' => $parent_ids,
			);
		}

		foreach ( (array) $refund_ids as $refund_id ) {
			try {
				$refund = wc_get_order( $refund_id );
			} catch ( \Throwable $e ) {
				self::log_safely(
					sprintf(
						'Refund #%d could not be read and was left out of the export: %s: %s',
						$refund_id,
						get_class( $e ),
						self::throwable_message( $e )
					)
				);
				continue;
			}

			if ( $refund instanceof \WC_Order_Refund ) {
				$readable[] = $refund;
			} else {
				self::log_safely(
					sprintf( 'Refund #%d could not be loaded and was left out of the export.', $refund_id )
				);
			}
		}

		return array(
			'refunds'      => $readable,
			'skip_parents' => array(),
		);
	}

	/**
	 * Orders whose refund read failure was already logged this request.
	 *
	 * @var array<int, bool>
	 */
	private static array $qty_refund_failure_logged = array();

	/**
	 * Read the refunded quantity for a line item, tolerating unreadable refunds.
	 *
	 * `WC_Order::get_qty_refunded_for_item()` iterates `$order->get_refunds()`,
	 * which re-queries and hydrates the order's refunds whenever the refund
	 * cache is cold - after an eviction on a persistent object cache, or when
	 * the prime's fallback could not determine the order's refund IDs. A
	 * corrupted refund row (SHIPSTN-164) then throws at the consumption site
	 * even though prime_refunds_for_batch() already ran. Degrade to zero
	 * refunded quantity so the export continues; the order then exports its
	 * full, un-refunded item quantities, matching the batch-prime degradation.
	 *
	 * Every shippable item of the order fails the same way, so the failure is
	 * logged once per order per export page rather than once per item. The
	 * memo is flushed with flush_qty_refund_failure_log() at the end of each
	 * export page and shipnotify request, so a long-lived process (WP-CLI,
	 * cron) keeps logging a failure that recurs later.
	 *
	 * @since 5.3.4
	 *
	 * @param WC_Order $order   Order being exported.
	 * @param int      $item_id Line item ID.
	 * @return int|float Absolute refunded quantity, or 0 when the order's refunds cannot be read.
	 */
	public static function safe_qty_refunded_for_item( WC_Order $order, int $item_id ) {
		try {
			return abs( $order->get_qty_refunded_for_item( $item_id ) );
		} catch ( \Throwable $e ) {
			$order_id = $order->get_id();

			if ( ! isset( self::$qty_refund_failure_logged[ $order_id ] ) ) {
				self::$qty_refund_failure_logged[ $order_id ] = true;

				self::log_safely(
					sprintf(
						'Refunded quantity for item #%d of order #%d could not be read; exporting the full quantities: %s: %s',
						$item_id,
						$order_id,
						get_class( $e ),
						self::throwable_message( $e )
					)
				);
			}

			return 0;
		}
	}

	/**
	 * Reset the once-per-order memo of logged refund read failures.
	 *
	 * Called at the end of every export page (alongside the notes-cache flush)
	 * and of every shipnotify request, so the memo cannot grow across pages in
	 * a long-lived process or silence a recurring failure.
	 *
	 * @since 5.3.4
	 *
	 * @return void
	 */
	public static function flush_qty_refund_failure_log(): void {
		self::$qty_refund_failure_logged = array();
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
	 * instead bulk-fetches the at-or-under-limit orders' notes in chunks whose
	 * counted notes fit the batch budget, primes `wp_commentmeta` per chunk with
	 * one `update_meta_cache()`, and stores the resolved `{ private, customer }`
	 * arrays in a class-level map that `get_order_notes()` consults before
	 * falling back to the per-order path.
	 *
	 * Each order is bounded on its own: an aggregate counts the notes per order
	 * first, and over-limit orders are routed per visibility group (see
	 * prime_over_limit_orders()) so one order cannot consume the whole batch's
	 * fetch window and neither group starves the other (SHIPSTN-161).
	 *
	 * @since 5.0.4
	 * @since 5.3.2 Bounded per order.
	 *
	 * @param int[] $order_ids Order IDs to prime.
	 * @return void
	 */
	public static function prime_order_notes_for_batch( array $order_ids ): void {
		if ( empty( $order_ids ) ) {
			return;
		}

		$order_ids = array_values( array_unique( array_map( 'absint', $order_ids ) ) );

		$to_prime = array_values(
			array_filter(
				$order_ids,
				static function ( $id ) {
					return ! isset( self::$order_notes_cache[ $id ] );
				}
			)
		);
		if ( empty( $to_prime ) ) {
			return;
		}

		// Seed every order with an empty structure so orders with no notes still
		// short-circuit the per-order query path below.
		foreach ( $to_prime as $id ) {
			self::$order_notes_cache[ $id ] = self::empty_note_groups();
		}

		$limit = self::get_order_notes_limit();

		// One aggregate count serves both routing decisions: the per-group
		// split is a strict superset of the plain total, so counting per group
		// up front spares the over-limit path a second scan of the same rows.
		$group_counts = self::count_order_notes_by_group( $to_prime );
		if ( null === $group_counts ) {
			// A failed count must not leave the batch cached as noteless. Unseed
			// so get_order_notes() retries each order instead.
			self::unseed_order_notes_cache( $to_prime );
			return;
		}

		// Orders absent from the count keep the empty structure seeded above.
		$bulk = array();
		$over = array();
		foreach ( $group_counts as $id => $groups ) {
			$total = $groups['private'] + $groups['customer'];

			if ( $total > $limit ) {
				$over[ $id ] = $groups;
			} else {
				$bulk[ $id ] = $total;
			}
		}

		if ( ! empty( $over ) ) {
			self::prime_over_limit_orders( $over, $limit );
		}

		if ( empty( $bulk ) ) {
			return;
		}

		// Each chunk's counted notes stay under MAX_BATCH_NOTES, so the fetch
		// and the meta prime below hold a bounded number of rows at a time no
		// matter the page size or the filtered limit.
		foreach ( self::chunk_by_note_count( $bulk ) as $chunk ) {
			self::prime_bulk_chunk( $chunk, $limit );
		}
	}

	/**
	 * Prime the notes of orders whose total count exceeds the per-order limit.
	 *
	 * On the store population this bound targets, most of a page can be over
	 * the limit, and a per-order refetch (two meta-joined queries each) would
	 * trade the memory failure for a request-time one. Instead the orders are
	 * counted per visibility group and each group is routed on its own:
	 *
	 * - A group at or under the limit is complete inside a shared window, so
	 *   it shares one bounded `post__in` query with other orders. The window
	 *   is the chunk's exact group-count sum: it covers every row of every
	 *   order's group, so a neighbour's newer notes cannot clip an order.
	 * - A group over the limit needs its own newest-N query; only that group
	 *   pays the per-order cost. Measured slope: two queries per over-limit
	 *   group per order, so a page where every order's private group is
	 *   bloated still pays one to two queries per order. That residue is
	 *   close to inherent (newest-N-per-order cannot be batched without
	 *   window functions); healthy pages stay flat regardless of page size.
	 * - An empty group issues no query at all.
	 *
	 * Failures unseed the affected orders (logged at the fetch), so
	 * get_order_notes() can retry them instead of exporting empty notes.
	 *
	 * @since 5.3.2
	 *
	 * @param array<int, array{private: int, customer: int}> $group_counts Per-group
	 *                   note counts keyed by over-limit order ID, already seeded.
	 * @param int                                            $limit Per-group note limit.
	 * @return void
	 */
	private static function prime_over_limit_orders( array $group_counts, int $limit ): void {
		$order_ids = array_keys( $group_counts );

		$results = array_fill_keys( $order_ids, self::empty_note_groups() );
		$authors = self::system_note_authors();

		foreach ( array( 'private', 'customer' ) as $group ) {
			$bulkable = array();

			foreach ( $order_ids as $id ) {
				if ( ! isset( $results[ $id ] ) ) {
					continue; // Dropped by an earlier group's failed query.
				}

				$count = $group_counts[ $id ][ $group ] ?? 0;

				if ( 0 === $count ) {
					continue;
				}

				if ( $count <= $limit ) {
					$bulkable[ $id ] = $count;
					continue;
				}

				$notes = self::fetch_group_notes( array( 'post_id' => $id ), $group, $limit );

				if ( null === $notes ) {
					self::unseed_order_notes_cache( array( $id ) );
					unset( $results[ $id ] );
					continue;
				}

				$results[ $id ][ $group ] = self::collect_note_contents( $notes );
			}

			foreach ( self::chunk_by_note_count( $bulkable ) as $chunk_ids ) {
				$window = 0;
				foreach ( $chunk_ids as $id ) {
					$window += $bulkable[ $id ];
				}

				$notes = self::fetch_group_notes(
					array( 'post__in' => $chunk_ids ),
					$group,
					min( $window, self::MAX_BATCH_NOTES )
				);

				if ( null === $notes ) {
					self::unseed_order_notes_cache( $chunk_ids );
					foreach ( $chunk_ids as $id ) {
						unset( $results[ $id ] );
					}
					continue;
				}

				foreach ( $notes as $note ) {
					$content = self::export_note_content( $note, $authors );
					if ( null !== $content && isset( $results[ (int) $note->comment_post_ID ] ) ) {
						$results[ (int) $note->comment_post_ID ][ $group ][] = $content;
					}
				}
			}
		}

		foreach ( $results as $id => $grouped ) {
			self::$order_notes_cache[ $id ] = $grouped;
		}
	}

	/**
	 * Split a counted batch into chunks whose note totals fit the batch budget.
	 *
	 * @since 5.3.2
	 *
	 * @param array<int, int> $counts Note count keyed by order ID; every count
	 *                                is at most MAX_ORDER_NOTES_LIMIT, so any
	 *                                single order fits a chunk.
	 * @return int[][] Lists of order IDs.
	 */
	private static function chunk_by_note_count( array $counts ): array {
		$chunks  = array();
		$current = array();
		$sum     = 0;

		foreach ( $counts as $order_id => $total ) {
			if ( ! empty( $current ) && ( $sum + $total ) > self::MAX_BATCH_NOTES ) {
				$chunks[] = $current;
				$current  = array();
				$sum      = 0;
			}

			$current[] = $order_id;
			$sum      += $total;
		}

		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Fetch and cache the notes for one chunk of at-or-under-limit orders.
	 *
	 * @since 5.3.2
	 *
	 * @param int[] $order_ids Order IDs whose counted notes fit the batch budget.
	 * @param int   $limit     Per-order note limit.
	 * @return void
	 */
	private static function prime_bulk_chunk( array $order_ids, int $limit ): void {
		// The number is not reached in practice, since every order here counted
		// at or under the limit. Backstop against the count and the query
		// disagreeing, so this can never be unbounded again; the batch budget
		// caps it in absolute terms.
		$notes = self::fetch_order_notes(
			self::order_note_query_args(
				array( 'post__in' => $order_ids ),
				min( $limit * count( $order_ids ), self::MAX_BATCH_NOTES )
			)
		);

		// Unseed on failure only, so nothing exports empty notes for orders that
		// have them. An empty result with no DB error is genuine (a third-party
		// clause may narrow every order out) and the empty seeds stand.
		if ( null === $notes ) {
			self::log_safely(
				sprintf(
					'prime_order_notes_for_batch bulk fetch failed for %d order(s) (%s).',
					count( $order_ids ),
					implode( ',', array_slice( $order_ids, 0, 20 ) )
				)
			);
			self::unseed_order_notes_cache( $order_ids );
			return;
		}

		if ( empty( $notes ) ) {
			return;
		}

		$comment_ids = array_map(
			static function ( $note ) {
				return (int) $note->comment_ID;
			},
			$notes
		);
		update_meta_cache( 'comment', $comment_ids );

		// The meta cache above makes the per-note meta reads in
		// group_order_notes() free.
		$notes_by_order = array();
		foreach ( $notes as $note ) {
			$notes_by_order[ (int) $note->comment_post_ID ][] = $note;
		}

		foreach ( $notes_by_order as $order_id => $order_notes ) {
			if ( ! isset( self::$order_notes_cache[ $order_id ] ) ) {
				continue;
			}
			self::$order_notes_cache[ $order_id ] = self::group_order_notes( $order_notes );
		}
	}

	/**
	 * Count each order's exportable notes per visibility group.
	 *
	 * Mirrors the WHERE that `get_comments()` builds above. Note that
	 * `'approve' => 'approve'` is not a `WP_Comment_Query` argument (`approve`
	 * is a value of `status`), so the live query leaves `status` at its default
	 * and matches held notes too. This count does the same so the two agree.
	 *
	 * The mirror is of the default clauses only: this count is raw SQL, so it
	 * bypasses the comment-query filter chain (`comments_clauses`,
	 * `pre_get_comments`) that the live fetch runs. A plugin narrowing
	 * order-note visibility desynchronises the two on every request, not just
	 * under a race. It only routes orders between bounded paths, so a
	 * disagreement cannot make the fetch unbounded: the bulk query carries its
	 * own ceiling. A stale-low or filter-skewed count can still let that
	 * ceiling clip a neighbouring order's notes for the one request.
	 *
	 * The group split mirrors group_meta_query(): a note is customer-visible
	 * when a truthy `is_customer_note` meta row exists, private otherwise. The
	 * meta table joins once per note on the keyed row and both counts are
	 * DISTINCT over comment IDs, so a note carrying duplicate meta rows is
	 * still counted once (as customer when any row is truthy) while both fetch
	 * arms match it; that degenerate shape can undersize a shared window by
	 * the duplicate, nothing more.
	 *
	 * @since 5.3.2
	 *
	 * @param int[] $order_ids Order IDs, already sanitised to integers.
	 * @return array<int, array{private: int, customer: int}>|null Counts keyed
	 *                       by order ID, omitting orders with no notes. Null
	 *                       when the query failed.
	 */
	private static function count_order_notes_by_group( array $order_ids ): ?array {
		if ( empty( $order_ids ) ) {
			return array();
		}

		global $wpdb;

		$placeholders     = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
		$authors          = self::system_note_authors();
		$author_exclusion = self::system_author_exclusion_sql( "{$wpdb->comments}.comment_author", $authors );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the sniff cannot see that $placeholders and $author_exclusion are implode()-built lists of %d / %s tokens consumed by prepare(); every live value still goes through prepare().
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- false positives: the sniff cannot count the spread against the interpolated placeholder lists, nor see the %d / %s tokens inside them. The suppression also silences real mismatches here, so recount by hand when editing this SQL.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Counts order notes per order in one pass; core exposes no aggregate over comments. Every interpolated part is a generated list of %d / %s tokens consumed by prepare(), as the suppressions below record.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$wpdb->comments}.comment_post_ID AS order_id,
					COUNT( DISTINCT {$wpdb->comments}.comment_ID ) AS total,
					COUNT( DISTINCT CASE WHEN {$wpdb->commentmeta}.meta_value NOT IN ( '', '0' )
						THEN {$wpdb->comments}.comment_ID END ) AS customer_total
				FROM {$wpdb->comments}
				LEFT JOIN {$wpdb->commentmeta}
					ON {$wpdb->commentmeta}.comment_id = {$wpdb->comments}.comment_ID
					AND {$wpdb->commentmeta}.meta_key = 'is_customer_note'
				WHERE {$wpdb->comments}.comment_type = 'order_note'
					AND {$wpdb->comments}.comment_approved IN ( '0', '1' )
					AND {$author_exclusion}
					AND {$wpdb->comments}.comment_post_ID IN ({$placeholders})
				GROUP BY {$wpdb->comments}.comment_post_ID",
				...array_merge( $authors, $order_ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( null === $rows || '' !== $wpdb->last_error ) {
			self::log_safely(
				sprintf(
					'count_order_notes_by_group failed for %d order(s) (%s): %s',
					count( $order_ids ),
					implode( ',', array_slice( $order_ids, 0, 20 ) ),
					self::sanitize_for_log( (string) $wpdb->last_error )
				)
			);
			return null;
		}

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$customer = (int) $row->customer_total;

			$counts[ (int) $row->order_id ] = array(
				'private'  => (int) $row->total - $customer,
				'customer' => $customer,
			);
		}

		return $counts;
	}

	/**
	 * Flush the pre-fetched order-notes cache.
	 *
	 * The cache is scoped to one export page by design. Both export surfaces
	 * flush after each page's payload pass (the REST controller in a finally,
	 * so a throwing payload cannot skip it; the XML export after its page is
	 * built); long-lived processes (WP-CLI, Action Scheduler) exporting many
	 * pages would otherwise grow the map without bound. Tests use it to reset
	 * state between cases.
	 *
	 * @since 5.3.2
	 *
	 * @return void
	 */
	public static function flush_order_notes_cache(): void {
		self::$order_notes_cache = array();
	}

	/**
	 * Drop cache entries so get_order_notes() can query those orders itself.
	 *
	 * @since 5.3.2
	 *
	 * @param int[] $order_ids Order IDs to unseed.
	 * @return void
	 */
	private static function unseed_order_notes_cache( array $order_ids ): void {
		foreach ( $order_ids as $id ) {
			unset( self::$order_notes_cache[ $id ] );
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

	/**
	 * Whether a ShipStation shipment has already been recorded on an order.
	 *
	 * Backs the shipnotify idempotency guard (SHIPSTN-53) so ShipStation's
	 * hourly retries do not re-add the tracking note or re-increment the
	 * shipped-item counter. An empty key disables dedup (the caller has no
	 * stable identifier for this shipment).
	 *
	 * @since 5.3.1
	 *
	 * @param WC_Order $order Order to check.
	 * @param string   $key   Stable shipment identifier (REST: notification_id; XML: "tracking|carrier").
	 * @return bool True when the key is non-empty and already recorded.
	 */
	public static function shipment_already_processed( WC_Order $order, string $key ): bool {
		if ( '' === $key ) {
			return false;
		}

		$processed = $order->get_meta( '_shipstation_processed_shipments', true );
		if ( ! is_array( $processed ) ) {
			return false;
		}

		return in_array( $key, $processed, true );
	}

	/**
	 * Record a ShipStation shipment identifier on an order.
	 *
	 * Later retries carrying the same key are then recognized as duplicates by
	 * self::shipment_already_processed(). No-op on an empty key or a key that is
	 * already recorded. (SHIPSTN-53)
	 *
	 * @since 5.3.1
	 *
	 * @param WC_Order $order Order to update.
	 * @param string   $key   Stable shipment identifier.
	 * @return void
	 */
	public static function mark_shipment_processed( WC_Order $order, string $key ): void {
		if ( '' === $key ) {
			return;
		}

		$processed = $order->get_meta( '_shipstation_processed_shipments', true );
		if ( ! is_array( $processed ) ) {
			$processed = array();
		}

		if ( in_array( $key, $processed, true ) ) {
			return;
		}

		$processed[] = $key;

		// Orders carry a handful of shipments in practice. The cap only stops a
		// misbehaving retry loop from growing the serialized meta row without
		// limit; the oldest identifiers are dropped first.
		if ( count( $processed ) > self::MAX_PROCESSED_SHIPMENTS ) {
			$processed = array_slice( $processed, -self::MAX_PROCESSED_SHIPMENTS );
		}

		$order->update_meta_data( '_shipstation_processed_shipments', $processed );

		// save_meta_data() fires added_order_meta / updated_order_meta, and on
		// the order data store woocommerce_update_order, so the marker write is
		// itself a handoff to foreign code and needs the same containment as
		// the rest of the tail (SHIPSTN-165).
		$saved = self::dispatch_safely(
			sprintf( 'the processed-shipment marker write for order %d', $order->get_id() ),
			static function () use ( $order ) {
				$order->save_meta_data();
			}
		);

		if ( ! $saved ) {
			self::discard_pending_meta_safely( $order );
		}
	}

	/**
	 * Record a tracking number with the Shipment Tracking extension, containing
	 * any failure, with an order-meta fallback when the write did not land.
	 *
	 * The writer hands control to the Shipment Tracking extension and
	 * everything hooked into its write, so it runs contained (SHIPSTN-165).
	 * When the writer is unavailable (Shipment Tracking older than 1.4.0) or
	 * threw and was contained, the tracking number falls back to the
	 * _tracking_provider / _tracking_number / _date_shipped order meta: the
	 * shipment is marked processed either way, so without the fallback the
	 * number would be lost for good. The fallback save fires foreign-hookable
	 * meta hooks of its own, so it runs contained as well; when even that
	 * fails, the containment log entries are what remains of the number.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.3
	 *
	 * @param WC_Order      $order           Order the shipment belongs to.
	 * @param string        $tracking_number Tracking number.
	 * @param string        $carrier         Carrier name; stored lower-case.
	 * @param int           $timestamp       Shipped date as a Unix timestamp.
	 * @param callable|null $writer          Tracking writer, receiving order ID,
	 *                                       tracking number, lower-cased carrier
	 *                                       and timestamp. Defaults to
	 *                                       wc_st_add_tracking_number() when the
	 *                                       extension exposes it; overridable so
	 *                                       tests can drive the fallback paths.
	 * @return bool True when the extension write landed, false when the meta
	 *              fallback ran (whether or not it succeeded).
	 */
	public static function store_tracking_number_safely( WC_Order $order, string $tracking_number, string $carrier, int $timestamp, ?callable $writer = null ): bool {
		$order_id = $order->get_id();

		if ( null === $writer && function_exists( 'wc_st_add_tracking_number' ) ) {
			$writer = 'wc_st_add_tracking_number';
		}

		if ( null !== $writer ) {
			$stored = self::dispatch_safely(
				sprintf( 'the Shipment Tracking write for order %d', $order_id ),
				static function () use ( $writer, $order_id, $tracking_number, $carrier, $timestamp ) {
					$writer( $order_id, $tracking_number, strtolower( $carrier ), $timestamp );
				}
			);

			if ( $stored ) {
				return true;
			}
		}

		$fell_back = self::dispatch_safely(
			sprintf( 'the tracking-meta fallback for order %d', $order_id ),
			static function () use ( $order, $tracking_number, $carrier, $timestamp ) {
				$order->update_meta_data( '_tracking_provider', strtolower( $carrier ) );
				$order->update_meta_data( '_tracking_number', $tracking_number );
				$order->update_meta_data( '_date_shipped', $timestamp );
				$order->save_meta_data();
			}
		);

		if ( ! $fell_back ) {
			self::discard_pending_meta_safely( $order );
		}

		return false;
	}

	/**
	 * Add an order note, containing any failure in the tail that follows it.
	 *
	 * WC_Order::add_order_note() writes the comment row first, then hands control
	 * to everyone else: woocommerce_new_customer_note, where WooCommerce renders
	 * and sends the customer-note email, and woocommerce_order_note_added, which
	 * fires for private notes too. A fatal anywhere in there used to turn an
	 * already-recorded shipment into a 500 (SHIPSTN-165).
	 *
	 * The note ID is captured from wp_insert_comment as well as taken from the
	 * return value: the row lands before the tail runs, and the return value is
	 * unreachable when the tail throws. Callers do not consume the ID today; it
	 * exists so the log entry and the return contract can report the note that
	 * actually landed.
	 *
	 * Memory exhaustion in the tail is an E_ERROR and is not catchable here.
	 * Main::maybe_defer_shipment_emails() is what covers that.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.3
	 *
	 * @param WC_Order $order            Order to annotate.
	 * @param mixed    $note             Note content, normally a string. Untyped on
	 *                                   purpose: it arrives from the public
	 *                                   woocommerce_shipstation_shipnotify_tracking_note
	 *                                   filter, and a type hint would fatal at the call
	 *                                   site, outside this method's own containment.
	 * @param bool     $is_customer_note Whether the note is customer-facing.
	 * @return int Comment ID, or 0 when no note was written.
	 */
	public static function add_order_note_safely( WC_Order $order, $note, bool $is_customer_note ): int {
		$order_id = $order->get_id();
		$note_id  = 0;
		$caught   = false;

		$capture = static function ( $comment_id, $comment ) use ( &$note_id, $order_id ) {
			if ( 0 !== $note_id || ! is_object( $comment ) ) {
				return;
			}

			if ( 'order_note' !== $comment->comment_type || (int) $comment->comment_post_ID !== $order_id ) {
				return;
			}

			$note_id = (int) $comment_id;
		};

		// Lowest reachable priority so the ID is recorded before any callback
		// that might throw on the same hook.
		add_action( 'wp_insert_comment', $capture, PHP_INT_MIN, 2 );

		try {
			$returned = (int) $order->add_order_note( $note, $is_customer_note );

			if ( $returned > 0 ) {
				$note_id = $returned;
			}
		} catch ( \Throwable $e ) {
			$caught = true;

			// \Throwable, not \Exception: the reported crashes are Error and
			// TypeError, which \Exception would not catch.
			self::log_safely(
				sprintf(
					/* translators: 1) order ID 2) throwable class 3) throwable message 4) file path 5) line number */
					__( 'A callback on the order note for order %1$d failed: %2$s: %3$s in %4$s:%5$d.', 'woocommerce-shipstation-integration' ),
					$order_id,
					get_class( $e ),
					self::throwable_message( $e ),
					$e->getFile(),
					$e->getLine()
				) . ' ' . (
					$note_id > 0
						? __( 'The note was written and the request was not aborted.', 'woocommerce-shipstation-integration' )
						: __( 'The note was not written and the request was not aborted.', 'woocommerce-shipstation-integration' )
				)
			);
		} finally {
			remove_action( 'wp_insert_comment', $capture, PHP_INT_MIN );
		}

		// add_order_note() can also fail without throwing (a failed comment
		// insert, a filter blanking the content). The shipment is recorded and
		// marked processed regardless, so without a log line the missing note
		// would leave no trace at all.
		if ( 0 === $note_id && ! $caught ) {
			// Neutral wording: this helper writes the shipnotify tracking note
			// and the exported-order note, so the message must not claim either.
			self::log_safely(
				/* translators: %d: order ID */
				sprintf( __( 'No order note was recorded for order %d: add_order_note() reported no comment ID.', 'woocommerce-shipstation-integration' ), $order_id ),
				'warning'
			);
		}

		return $note_id;
	}

	/**
	 * Move an order to a new status, containing any failure in the tail.
	 *
	 * The transition sends the shipped-status emails and runs every callback
	 * attached to them, so it needs the same containment as the note
	 * (SHIPSTN-165).
	 *
	 * Whether it landed cannot be read from the call. WC_Order::save() writes the
	 * row and only then runs status_transition(), whose try/catch misses Error --
	 * so an Error from a transition callback escapes after the order is saved,
	 * while an Exception thrown before the write is swallowed and returns false.
	 * The woocommerce_after_order_object_save capture is a fast path, but it can
	 * be skipped without anything visible here (the hook fires after save_items(),
	 * so a throw from woocommerce_update_order lands in between), so the answer
	 * of record is a re-read of the persisted status.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.3
	 *
	 * @param WC_Order    $order      Order to transition.
	 * @param string|null $new_status Target status, with or without the wc- prefix. Untyped
	 *                                for the same reason as $note above: callers pass
	 *                                WC_ShipStation_Integration::$shipped_status, which is
	 *                                null until plugins_loaded populates it.
	 * @return bool True when the order reached the new status.
	 */
	public static function transition_order_status_safely( WC_Order $order, $new_status ): bool {
		$order_id = $order->get_id();
		$target   = OrderUtil::remove_status_prefix( (string) $new_status );
		$landed   = false;

		// An empty target can only mean the integration statics are not
		// populated yet (null before plugins_loaded). WooCommerce would not
		// fail on it: update_status() coerces an unknown status to the default
		// and reports success, silently moving the order to pending.
		if ( '' === $target ) {
			return false;
		}

		$capture = static function ( $saved ) use ( &$landed, $order_id, $target ) {
			if ( ! $saved instanceof WC_Order || $saved->get_id() !== $order_id ) {
				return;
			}

			if ( OrderUtil::remove_status_prefix( (string) $saved->get_status() ) === $target ) {
				$landed = true;
			}
		};

		// The capture misses a throw raised between the row write and the hook
		// -- woocommerce_update_order fires from inside the data store's
		// update() -- and update_status()'s return value cannot stand in for
		// it, because save() swallows an Exception raised before the write and
		// still reports success. Re-reading the persisted status is the only
		// answer that holds in both directions.
		$persisted = static function () use ( $order_id, $target ): bool {
			$fresh = wc_get_order( $order_id );

			return $fresh instanceof WC_Order
				&& OrderUtil::remove_status_prefix( (string) $fresh->get_status() ) === $target;
		};

		// Lowest reachable priority so the write is recorded before any callback
		// on the same hook can throw.
		add_action( 'woocommerce_after_order_object_save', $capture, PHP_INT_MIN );

		try {
			$order->update_status( $new_status );
		} catch ( \Throwable $e ) {
			$landed = $landed || $persisted();

			self::log_safely(
				sprintf(
					/* translators: 1) order ID 2) throwable class 3) throwable message 4) file path 5) line number */
					__( 'A callback on the status transition for order %1$d failed: %2$s: %3$s in %4$s:%5$d.', 'woocommerce-shipstation-integration' ),
					$order_id,
					get_class( $e ),
					self::throwable_message( $e ),
					$e->getFile(),
					$e->getLine()
				) . ' ' . (
					$landed
						/* translators: %s: order status */
						? sprintf( __( 'The order reached %s.', 'woocommerce-shipstation-integration' ), $new_status )
						/* translators: %s: order status */
						: sprintf( __( 'The order did not reach %s.', 'woocommerce-shipstation-integration' ), $new_status )
				)
			);
		} finally {
			remove_action( 'woocommerce_after_order_object_save', $capture, PHP_INT_MIN );
		}

		// The healthy path needs the same authoritative read: the capture can
		// be skipped without anything throwing.
		return $landed || $persisted();
	}

	/**
	 * Log an uncontained export failure without letting the logging throw.
	 *
	 * One format for both export surfaces' boundaries (SHIPSTN-171); the
	 * message is bounded and the write goes through log_safely().
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.5
	 *
	 * @param \Throwable $e            The uncontained throwable.
	 * @param string     $stray_output Output captured before the failure, if any.
	 * @return void
	 */
	public static function log_uncontained_export_failure( \Throwable $e, string $stray_output = '' ): void {
		$message = sprintf(
			/* translators: 1) throwable class 2) throwable message 3) file path 4) line number */
			__( 'The orders export failed before a response could be built: %1$s: %2$s in %3$s:%4$d. No orders were returned to ShipStation.', 'woocommerce-shipstation-integration' ),
			get_class( $e ),
			self::throwable_message( $e ),
			$e->getFile(),
			$e->getLine()
		);

		// Whatever the failing code printed on its way out often names it more
		// precisely than the throwable does.
		if ( '' !== trim( $stray_output ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: output captured before the failure */
				__( 'Output captured before the failure: %s', 'woocommerce-shipstation-integration' ),
				$stray_output
			);
		}

		self::log_safely( $message );
	}

	/**
	 * Discard the output buffers opened above $level and return what they held.
	 *
	 * Both export surfaces buffer their payload phase, because third-party
	 * output emitted mid-batch commits the response before the endpoint can
	 * answer: ShipStation then receives a 200 with an unparseable body instead
	 * of an error it would retry (SHIPSTN-171).
	 *
	 * Stops at a buffer PHP cannot pop. One opened without
	 * PHP_OUTPUT_HANDLER_REMOVABLE keeps its level after ob_end_clean() fails,
	 * so looping on it never terminates, and the notice that call raises
	 * becomes a throwable under an error-to-exception handler.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.5
	 *
	 * @param int $level Buffer level to drain back down to.
	 * @return string The discarded output, bounded for logging.
	 */
	public static function discard_buffers_safely( int $level ): string {
		$stray = '';

		// The drain runs in the boundary's finally while the caller may hold
		// the original export failure: nothing raised here may outrank it, so
		// any throwable (an ob_* notice under an error-to-exception handler)
		// ends the drain with what was captured so far.
		try {
			while ( ob_get_level() > $level ) {
				$status = ob_get_status( true );
				$top    = end( $status );

				// Prepended: buffers pop innermost-first, and the log should
				// read in the order the output was emitted. Sized before
				// copying: ob_get_contents() materialises the whole buffer,
				// and running out of memory here is E_ERROR, which no catch
				// can contain - so a huge dump is reported, never copied.
				$size = ob_get_length();

				if ( false !== $size && $size <= self::STRAY_OUTPUT_CAPTURE_LIMIT ) {
					$stray = self::bound_bytes( (string) ob_get_contents() ) . $stray;
				} else {
					$stray = sprintf( '[%d bytes of output discarded, too large to capture] ', (int) $size ) . $stray;
				}

				$stray = self::bound_bytes( $stray );

				// Unknown flags count as non-removable: leaving a buffer standing
				// costs an ordering quirk, looping on one costs the request.
				if ( ! isset( $top['flags'] ) || ! ( $top['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE ) || ! ob_end_clean() ) {
					// Only a cleanable buffer can be emptied in place; on any
					// other, ob_clean() raises the same notice ob_end_clean()
					// just proved this buffer raises.
					if ( isset( $top['flags'] ) && ( $top['flags'] & PHP_OUTPUT_HANDLER_CLEANABLE ) ) {
						ob_clean();
					}
					break;
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		// Keep the markup: it is the evidence naming the plugin that printed it.
		return self::sanitize_for_log( $stray );
	}

	/**
	 * Log from inside a containment path without letting the logging throw.
	 *
	 * Logger::error() runs apply_filters( 'woocommerce_logger_log_message' )
	 * plus every registered log handler - more foreign code - so the catch
	 * blocks that must not throw route through this instead. Buffered for the
	 * same reason log_isolated() is: a replaced logger can print, and this runs
	 * where a response is half built.
	 *
	 * @since 5.3.3
	 *
	 * @param string $message Message to log.
	 * @param string $level   'error' or 'warning'.
	 * @return void
	 */
	private static function log_safely( string $message, string $level = 'error' ): void {
		$buffer_level = ob_get_level();

		ob_start();

		try {
			if ( 'warning' === $level ) {
				Logger::warning( $message );
			} else {
				Logger::error( $message );
			}
		} catch ( \Throwable $e ) {
			// The logging pipeline itself failed; there is nothing safer left
			// to report through.
			unset( $e );
		} finally {
			// A replaced logger can print as well as throw, and this often runs
			// with a response half built.
			self::discard_buffers_safely( $buffer_level );
		}
	}

	/**
	 * A contained throwable's message, bounded for logging.
	 *
	 * A foreign exception can carry an arbitrarily large payload in its
	 * message (a dumped request body, a DSN with credentials). 500 characters
	 * keeps the entry useful without letting the log become the dump; the
	 * class and file:line logged alongside stay intact.
	 *
	 * @since 5.3.3
	 *
	 * @param \Throwable $e Contained throwable.
	 * @return string
	 */
	private static function throwable_message( \Throwable $e ): string {
		return self::sanitize_for_log( (string) $e->getMessage() );
	}

	/**
	 * Bound and flatten foreign text for a single log entry.
	 *
	 * Control characters are collapsed because a newline ends our entry and
	 * begins what reads as a separate, genuine one -- timestamp and level
	 * included, and carrying whatever tail of our own sentence followed the
	 * interpolation. Foreign text is not guaranteed to be UTF-8, so that pass
	 * runs without /u -- with it, one stray byte makes preg_replace() return
	 * null and the entry loses the evidence entirely -- and only the sequences
	 * that are actually invalid are replaced, because a log handler writing to
	 * a utf8mb4 column drops the whole row on them while a store's non-Latin
	 * text still has to stay readable. The cap is character-based, not
	 * byte-based: a byte cut can split a UTF-8 sequence mid-character.
	 *
	 * Every value that reaches a log line from outside this plugin goes through
	 * here: throwable messages, discarded output, and WP_Error messages from
	 * third-party query filters. It is called from paths with no containment
	 * above them, so it never throws.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.5
	 *
	 * @param string $text Foreign text.
	 * @return string
	 */
	public static function sanitize_for_log( string $text ): string {
		try {
			$bounded = strlen( $text ) > self::LOG_TEXT_BYTE_BOUND;
			$text    = self::bound_bytes( $text, self::LOG_TEXT_BYTE_BOUND );

			$text = (string) preg_replace( '/[\r\n\t\x00-\x1F\x7F]+/', ' ', $text );

			// NEL, LINE SEPARATOR and PARAGRAPH SEPARATOR, matched as bytes
			// because the text is not known to be valid UTF-8 yet. A JSON or
			// JS based log viewer renders them as the break the pass above
			// exists to close.
			$text = (string) preg_replace( '/\xC2\x85|\xE2\x80[\xA8\xA9]/', ' ', $text );

			if ( 1 !== preg_match( '//u', $text ) ) {
				$text = self::replace_invalid_utf8( $text );
			}

			if ( mb_strlen( $text ) > self::LOG_TEXT_CHAR_BOUND ) {
				return mb_substr( $text, 0, self::LOG_TEXT_CHAR_BOUND ) . '...';
			}

			return $bounded ? $text . '...' : $text;
		} catch ( \Throwable $e ) {
			// This runs outside every containment path the boundary has, so it
			// must not be the reason an entry is lost: fall back to the ASCII
			// skeleton, which still names the plugin and the file.
			return substr( (string) preg_replace( '/[^\x20-\x7E]/', '?', $text ), 0, self::LOG_TEXT_CHAR_BOUND );
		}
	}

	/**
	 * Replace the invalid sequences in foreign text, keeping the valid ones.
	 *
	 * A log handler writing to a utf8mb4 column drops the whole row on an
	 * invalid byte, so they have to go. Replacing only what is actually
	 * invalid keeps the evidence readable on a store whose plugins speak
	 * Japanese, Cyrillic or Greek, where blanking every high byte would
	 * reduce the entry to question marks.
	 *
	 * @param string $text Foreign text that failed the UTF-8 check.
	 * @return string
	 */
	private static function replace_invalid_utf8( string $text ): string {
		// WordPress polyfills mb_strlen() and mb_substr(), but not the
		// converter, so a host without mbstring falls back to the blunt pass.
		if ( ! function_exists( 'mb_convert_encoding' ) || ! function_exists( 'mb_substitute_character' ) ) {
			return (string) preg_replace( '/[\x80-\xFF]/', '?', $text );
		}

		$previous = mb_substitute_character();
		mb_substitute_character( 0x3F );

		try {
			return (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		} finally {
			mb_substitute_character( $previous );
		}
	}

	/**
	 * Cut foreign text down to a byte bound, keeping the head.
	 *
	 * The head is what names the plugin: the drain prepends outer buffers, and
	 * a throwable's message leads with its own text.
	 *
	 * @param string   $text  Foreign text.
	 * @param int|null $bound Byte bound; defaults to the drain's.
	 * @return string
	 */
	private static function bound_bytes( string $text, ?int $bound = null ): string {
		$bound = $bound ?? self::STRAY_OUTPUT_BYTE_BOUND;

		return strlen( $text ) > $bound ? substr( $text, 0, $bound ) : $text;
	}

	/**
	 * Run a log write with its output kept out of the response.
	 *
	 * Containment covers a logger that throws; the buffer here covers one that
	 * prints. A write that runs before or after an export boundary puts that
	 * output in front of or behind the payload, and ShipStation then gets a
	 * 200 carrying a body no parser accepts - the SHIPSTN-171 shape, from the
	 * code meant to report it. What the handler printed is dropped rather than
	 * logged: reporting it means writing through the logger that just
	 * misbehaved.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.5
	 *
	 * @param string   $context  Description of the handoff, used in the log entry.
	 * @param callable $callback Log write to run.
	 * @return bool True when the write completed, false when it threw.
	 */
	public static function log_isolated( string $context, callable $callback ): bool {
		$buffer_level = ob_get_level();

		ob_start();

		try {
			return self::dispatch_safely( $context, $callback );
		} finally {
			self::discard_buffers_safely( $buffer_level );
		}
	}

	/**
	 * Run a handoff to code outside this plugin, containing any failure.
	 *
	 * Used where the shipnotify flow gives control away: the Shipment Tracking
	 * write, the plugin's own public actions, and its public filters (whose
	 * callers keep the unfiltered value when a callback throws). Failures in
	 * this plugin's own export and shipnotify logic are deliberately not routed
	 * through here -- those should still surface (SHIPSTN-165).
	 *
	 * The export surfaces also route their log writes through it (SHIPSTN-171).
	 * A log write reads as this plugin's own code but is not: WooCommerce lets
	 * any plugin replace the logger through `woocommerce_logging_class`, so the
	 * write lands in foreign code that can throw. On the XML summary log the
	 * page has already been echoed by then, so a broken handler must not
	 * disturb a response that has gone out. Order_Util's own contained logging
	 * uses log_safely() instead, which is private to this class.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.3
	 *
	 * @param string   $context  Description of the handoff, used in the log entry.
	 * @param callable $callback Handoff to run.
	 * @return bool True when the callback completed, false when it threw.
	 */
	public static function dispatch_safely( string $context, callable $callback ): bool {
		try {
			$callback();

			return true;
		} catch ( \Throwable $e ) {
			self::log_safely(
				sprintf(
					/* translators: 1) description of the contained handoff 2) throwable class 3) throwable message 4) file path 5) line number */
					__( 'A failure in %1$s was contained: %2$s: %3$s in %4$s:%5$d.', 'woocommerce-shipstation-integration' ),
					$context,
					get_class( $e ),
					self::throwable_message( $e ),
					$e->getFile(),
					$e->getLine()
				)
			);

			return false;
		}
	}

	/**
	 * Drop an order's pending meta after a contained meta write failed.
	 *
	 * A throwable from the meta hooks leaves the change pending on the order
	 * object: WC_Data::save_meta_data() never reaches the meta's
	 * apply_changes(). The next save of that order then retries the same write
	 * and throws again -- and on the posts-storage data store that retry is the
	 * first statement of update(), ahead of the post row, so the shipped-status
	 * transition is lost along with it. HPOS writes the order row first and
	 * hides the problem.
	 *
	 * Re-reading discards the pending changes. The meta whose hooks threw was
	 * already committed before they ran; anything later in the same batch is
	 * dropped, which the retry would have thrown on again anyway.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.3
	 *
	 * @param WC_Order $order Order whose pending meta is dropped.
	 * @return void
	 */
	public static function discard_pending_meta_safely( WC_Order $order ): void {
		self::dispatch_safely(
			sprintf( 'the meta reload after a failed write for order %d', $order->get_id() ),
			static function () use ( $order ) {
				$order->read_meta_data( true );
			}
		);
	}
}
