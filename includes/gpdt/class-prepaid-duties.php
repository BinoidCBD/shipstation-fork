<?php
/**
 * Class WooCommerce\Shipping\ShipStation\GPDT\Prepaid_Duties file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation\GPDT;

use WC_Order;
use WC_Order_Item_Fee;
use WooCommerce\Shipping\ShipStation\Logger;
use WooCommerce\Shipping\ShipStation\Order_Util;

defined( 'ABSPATH' ) || exit;

/**
 * Class Prepaid_Duties
 *
 * Identifies the prepaid duties fee (ShipStation GPDT) on an order and reads
 * its charged and refunded amounts. The fee is recognised by hidden item meta,
 * never by its label, which merchants and translations can change.
 */
class Prepaid_Duties {

	/**
	 * Order fee item meta key that tags a fee as prepaid duties.
	 *
	 * Underscore-prefixed so WooCommerce keeps it out of the storefront and
	 * emails. The Store API leaves it out because its fee schema reads no item
	 * meta, and wp-admin because fee rows print no item meta. It is not private:
	 * the WooCommerce REST API (wc/v3 `fee_lines` `meta_data`) and order webhooks
	 * carry it. Main::hide_internal_order_item_meta() lists the key, which on
	 * WooCommerce 11.0+ reserves it in admin item saves so it cannot be added or
	 * edited as custom meta there. The value is a single array:
	 *
	 * - `kind`      `combined`: one figure covering duty, import tax and the carrier's disbursement fee.
	 * - `amount`    Decimal string.
	 * - `currency`  ISO 4217 code.
	 * - `provider`  `shipstation`.
	 * - `reference` The GPDT rate ID.
	 *
	 * The shape stays open to a breakdown: a later version may add an optional
	 * `components` list beside these keys, with `amount` remaining the total,
	 * so existing tags stay valid without a migration.
	 *
	 * @since 5.4.0
	 */
	const META_KEY = '_shipstation_prepaid_duties';

	/**
	 * Whether a fee item is tagged as prepaid duties.
	 *
	 * @since 5.4.0
	 *
	 * @param WC_Order_Item_Fee $item Fee item.
	 * @return bool
	 */
	public static function is_prepaid_duties_fee( WC_Order_Item_Fee $item ): bool {
		$tag = $item->get_meta( self::META_KEY, true );

		return is_array( $tag ) && ! empty( $tag );
	}

	/**
	 * Total charged for prepaid duties on an order.
	 *
	 * Sums the totals of the tagged fee items, excluding tax, which is what the
	 * customer was charged for the fee; the tag's own `amount` records the quote
	 * and is not read here.
	 *
	 * @since 5.4.0
	 *
	 * @param WC_Order $order Order.
	 * @return float Charged total excluding tax, in the order's currency ($order->get_currency()), rounded to the store's price decimals, or 0.0 when the order has no tagged fee.
	 */
	public static function get_prepaid_duties( WC_Order $order ): float {
		$total = 0.0;

		foreach ( self::get_tagged_fees( $order ) as $item ) {
			$total += (float) $item->get_total();
		}

		return round( $total, wc_get_price_decimals() );
	}

	/**
	 * Total refunded for prepaid duties across the order's refunds.
	 *
	 * Only refunds that carry a line for the tagged fee count. A refund made by
	 * setting the order to Refunded, or an amount-only refund, has no fee line
	 * and is not included, even when it covers the duties.
	 *
	 * Reading refunds hydrates them, which throws on a corrupted refund row
	 * (SHIPSTN-164). The amount then degrades to 0.0 and the failure is
	 * logged on every call, so an export reading it is not broken by it. A
	 * caller that reads it more than once per order should memo the result.
	 *
	 * That fallback only runs when the refunds are read from the store. After
	 * Order_Util::prime_refunds_for_batch() has skipped a refund it could not
	 * hydrate, WC_Order::get_refunds() returns the primed list without that
	 * refund and does not throw, so the amount is the total of the readable
	 * refunds and can be lower than what was refunded. The prime logs that
	 * failure itself; this method does not.
	 *
	 * @since 5.4.0
	 *
	 * @param WC_Order $order Order.
	 * @return float Refunded total excluding tax, in the order's currency ($order->get_currency()), as a positive number rounded to the store's price decimals, or 0.0 when nothing was refunded or the refunds cannot be read.
	 */
	public static function get_prepaid_duties_refunded( WC_Order $order ): float {
		$fees = self::get_tagged_fees( $order );
		if ( empty( $fees ) ) {
			return 0.0;
		}

		try {
			$total = 0.0;

			foreach ( $fees as $item ) {
				$item_id = $item->get_id();

				// An unsaved fee has ID 0, which would match every refund row
				// that carries no _refunded_item_id.
				if ( 0 === $item_id ) {
					continue;
				}

				$total += abs( (float) $order->get_total_refunded_for_item( $item_id, 'fee' ) );
			}

			return round( $total, wc_get_price_decimals() );
		} catch ( \Throwable $e ) {
			$message = sprintf(
				'Refunded prepaid duties for order #%d could not be read; reporting none: %s: %s',
				$order->get_id(),
				get_class( $e ),
				Order_Util::sanitize_for_log( (string) $e->getMessage() )
			);

			Order_Util::log_isolated(
				'the prepaid duties refund log',
				static function () use ( $message ) {
					Logger::error( $message );
				}
			);

			return 0.0;
		}
	}

	/**
	 * The order's tagged fee items, keyed as WC_Order::get_fees() keys them
	 * (an unsaved item's key is not its ID).
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Order_Item_Fee[]
	 */
	private static function get_tagged_fees( WC_Order $order ): array {
		return array_filter(
			$order->get_fees(),
			static function ( $item ): bool {
				return $item instanceof WC_Order_Item_Fee && self::is_prepaid_duties_fee( $item );
			}
		);
	}
}
