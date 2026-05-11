<?php
/**
 * WC_Shipstation_API_Export file.
 *
 * @package WC_ShipStation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WooCommerce\Shipping\ShipStation\Checkout;
use WooCommerce\Shipping\ShipStation\Order_Util;

/**
 * WC_Shipstation_API_Export Class
 */
class WC_Shipstation_API_Export extends WC_Shipstation_API_Request {

	/**
	 * Constructor
	 */
	public function __construct() {
		if ( ! WC_Shipstation_API::authenticated() ) {
			exit;
		}
	}

	/**
	 * Preparing `IN` sql statement using `WPDB::prepare()`.
	 *
	 * @param array $values IN values.
	 */
	private static function prepare_in( $values ) {
		return implode(
			',',
			array_map(
				function ( $value ) {
					global $wpdb;

					// Use the official prepare() function to sanitize the value.
					return $wpdb->prepare( '%s', $value );
				},
				$values
			)
		);
	}

	/**
	 * Parse a ShipStation date string into a Unix timestamp.
	 *
	 * @see https://help.shipstation.com/hc/en-us/articles/360025856192 - Custom Store Development Guide:
	 *      "The start date is UTC time. Format: MM/dd/yyyy HH:mm (24 hour notation)."
	 *
	 * @param string $date_string Date string from ShipStation (UTC).
	 * @return int|false Unix timestamp, or false on failure.
	 */
	private function parse_shipstation_date( $date_string ) {
		if ( empty( $date_string ) ) {
			return false;
		}

		// Handle ShipStation's compact format (MMDDYYYYxHHMM).
		if ( false === strtotime( $date_string ) ) {
			$month       = substr( $date_string, 0, 2 );
			$day         = substr( $date_string, 2, 2 );
			$year        = substr( $date_string, 4, 4 );
			$time        = substr( $date_string, 9, 4 );
			$date_string = sprintf(
				'%s-%s-%s %s:%s:00',
				$year,
				$month,
				$day,
				substr( $time, 0, 2 ),
				substr( $time, 2, 2 )
			);
		}

		try {
			return ( new \DateTime( $date_string, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Do the request
	 */
	public function request() {
		global $wpdb;
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase --- ShipStation provides an object with camelCase properties and method
		// phpcs:disable WordPress.Security.NonceVerification.Recommended --- Using WC_ShipStation_Integration::$auth_key for security verification
		$this->validate_input( array( 'start_date', 'end_date' ) );

		header( 'Content-Type: text/xml' );
		$xml               = new DOMDocument( '1.0', 'utf-8' );
		$xml->formatOutput = true;
		$page              = max( 1, isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1 );
		$exported          = 0;
		$raw_start_date    = isset( $_GET['start_date'] ) ? urldecode( wc_clean( wp_unslash( $_GET['start_date'] ) ) ) : false;
		$raw_end_date      = isset( $_GET['end_date'] ) ? urldecode( wc_clean( wp_unslash( $_GET['end_date'] ) ) ) : false;
		$store_weight_unit = get_option( 'woocommerce_weight_unit' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Parse ShipStation date strings (UTC) into Unix timestamps.
		$start_timestamp = $this->parse_shipstation_date( $raw_start_date );
		$end_timestamp   = $this->parse_shipstation_date( $raw_end_date );

		if ( false === $start_timestamp || false === $end_timestamp ) {
			$this->trigger_error( __( 'Invalid start_date or end_date format.', 'woocommerce-shipstation-integration' ) );
			return;
		}

		$orders_to_export = wc_get_orders(
			array(
				'date_modified' => $start_timestamp . '...' . $end_timestamp,
				'type'          => 'shop_order',
				'status'        => WC_ShipStation_Integration::$export_statuses,
				'return'        => 'ids',
				'orderby'       => 'date_modified',
				'order'         => 'DESC',
				'paged'         => $page,
				'limit'         => WC_SHIPSTATION_EXPORT_LIMIT,
			)
		);

		$total_orders_to_export = wc_get_orders(
			array(
				'type'          => 'shop_order',
				'date_modified' => $start_timestamp . '...' . $end_timestamp,
				'status'        => WC_ShipStation_Integration::$export_statuses,
				'paginate'      => true,
				'return'        => 'ids',
			)
		);

		$max_results = $total_orders_to_export->total;

		$orders_xml = $xml->createElement( 'Orders' );

		/**
		 * Loop through each order ID and process for export.
		 *
		 * @var int $order_id
		 */
		foreach ( $orders_to_export as $order_id ) {
			/**
			 * Allow third party to skip the export of certain order ID.
			 *
			 * @param boolean $flag Flag to skip the export.
			 * @param int     $order_id Order ID.
			 *
			 * @since 4.1.42
			 */
			if ( ! apply_filters( 'woocommerce_shipstation_export_order', true, $order_id ) ) {
				continue;
			}

			/**
			 * Allow third party to change the order object.
			 *
			 * @param WC_Order $order Order object.
			 *
			 * @since 4.1.42
			 */
			$order = apply_filters( 'woocommerce_shipstation_export_get_order', wc_get_order( $order_id ) );

			if ( ! Order_Util::is_wc_order( $order ) ) {
				/* translators: 1: order id */
				$this->log( sprintf( __( 'Order %s can not be found.', 'woocommerce-shipstation-integration' ), $order_id ) );
				continue;
			}

			/**
			 * Currency code and exchange rate filters.
			 *
			 * These two filters allow 3rd parties to modify the currency code
			 * and exchange rate used for the order before exporting to ShipStation.
			 *
			 * This can be necessary in cases where the order currency doesn't match
			 * the ShipStation account currency. ShipStation does not do currency
			 * conversion, so the conversion must be done before the order is exported.
			 *
			 * @param string   $currency_code The currency code to use for the order.
			 * @param WC_Order $order WooCommerce Order object.
			 *
			 * @since 4.3.7
			 */
			$currency_code = apply_filters( 'woocommerce_shipstation_export_currency_code', $order->get_currency(), $order );
			/**
			 * Allow 3rd parties to modify the exchange rate used for the order before exporting to ShipStation.
			 *
			 * @param float    $exchange_rate The exchange rate to use for the order.
			 * @param WC_Order $order Order object.
			 *
			 * @since 4.3.7
			 */
			$exchange_rate = apply_filters( 'woocommerce_shipstation_export_exchange_rate', 1.00, $order );
			/**
			 * Filter whether order discounts should be exported as a separate line item to ShipStation.
			 *
			 * By default (true), discounts are exported as a separate line item. This has been the
			 * behavior since the beginning and is expected by all existing users and integrations.
			 *
			 * If set to false, the discount amount will instead be applied proportionally across the product line items,
			 * and no separate "Discount" line will be included in the export.
			 *
			 * ⚠️ Changing this behavior may break compatibility with external systems or workflows
			 * that rely on the presence of a separate discount line.
			 *
			 * This filter is provided to give developers flexibility in customizing how discounts
			 * are represented in the ShipStation export.
			 *
			 * @see   https://linear.app/a8c/issue/WOOSHIP-748/discounts-are-added-in-separate-line-item-as-total-discount-instead-of
			 * @see   https://github.com/woocommerce/woocommerce-shipstation/issues/85
			 *
			 * @param bool     $export_discounts_as_separate_item Whether to export discounts as a separate ShipStation line item. Default true.
			 * @param WC_Order $order                             The WooCommerce order object.
			 *
			 * @return bool Modified flag to control export behavior for discounts.
			 *
			 * @since 4.5.1
			 */
			$export_discounts_as_separate_item = apply_filters( 'woocommerce_shipstation_export_discounts_as_separate_item', true, $order );

			$order_xml              = $xml->createElement( 'Order' );
			$formatted_order_number = ltrim( $order->get_order_number(), '#' );
			$this->xml_append( $order_xml, 'OrderNumber', $formatted_order_number );
			$this->xml_append( $order_xml, 'OrderID', $order_id );

			// Sequence of date ordering: date paid > date completed > date created.
			$order_date = $order->get_date_paid() ?? $order->get_date_completed() ?? $order->get_date_created();

			if ( null === $order_date ) {
				$this->log( sprintf( 'Order #%d has no date - skipping export.', $order_id ) );
				continue;
			}

			$order_status = ( 'refunded' === $order->get_status() ) ? 'cancelled' : $order->get_status();
			$this->xml_append( $order_xml, 'OrderDate', gmdate( 'm/d/Y H:i', $order_date->getTimestamp() ), false );
			$this->xml_append( $order_xml, 'OrderStatus', $order_status );
			$this->xml_append( $order_xml, 'PaymentMethod', $order->get_payment_method() );
			$this->xml_append( $order_xml, 'OrderPaymentMethodTitle', $order->get_payment_method_title() );
			$last_modified = $order->get_date_modified() ?? $order_date;
			$this->xml_append( $order_xml, 'LastModified', gmdate( 'm/d/Y H:i', $last_modified->getTimestamp() ), false );
			$this->xml_append( $order_xml, 'ShippingMethod', Order_Util::get_shipping_methods( $order ) );

			$this->xml_append( $order_xml, 'CurrencyCode', $currency_code, false );

			$order_total = $order->get_total() - floatval( $order->get_total_refunded() );
			$tax_amount  = wc_round_tax_total( $order->get_total_tax() );

			// Maybe convert the order total and tax amount.
			if ( 1.00 !== $exchange_rate ) {
				$order_total = wc_format_decimal( ( $order_total * $exchange_rate ), wc_get_price_decimals() );
				$tax_amount  = wc_round_tax_total( $order->get_total_tax() * $exchange_rate );
			}

			$this->xml_append( $order_xml, 'OrderTotal', $order_total, false );
			$this->xml_append( $order_xml, 'TaxAmount', $tax_amount, false );

			if ( class_exists( 'WC_COG' ) ) {
				$wc_cog_order_total_cost = floatval( $order->get_meta( '_wc_cog_order_total_cost', true ) );

				// Maybe convert the order total cost of goods.
				if ( 1.00 !== $exchange_rate ) {
					$wc_cog_order_total_cost = $wc_cog_order_total_cost * $exchange_rate;
				}

				$this->xml_append( $order_xml, 'CostOfGoods', wc_format_decimal( $wc_cog_order_total_cost, wc_get_price_decimals() ), false );
			}

			$shipping_total = floatval( $order->get_shipping_total() );

			// Maybe convert the shipping total.
			if ( 1.00 !== $exchange_rate ) {
				$shipping_total = wc_format_decimal( ( $shipping_total * $exchange_rate ), wc_get_price_decimals() );
			}

			$this->xml_append( $order_xml, 'ShippingAmount', $shipping_total, false );
			$this->xml_append( $order_xml, 'CustomerNotes', $order->get_customer_note() );
			$this->xml_append( $order_xml, 'InternalNotes', implode( ' | ', Order_Util::get_order_notes( $order ) ) );

			// Maybe append the gift and gift message XML element.
			if ( class_exists( 'WooCommerce\Shipping\ShipStation\Checkout' ) && $order->get_meta( Checkout::get_block_prefixed_meta_key( 'is_gift' ) ) ) {
				$this->xml_append( $order_xml, 'Gift', 'true', false );

				$gift_message = $order->get_meta( Checkout::get_block_prefixed_meta_key( 'gift_message' ) );

				if ( ! empty( $gift_message ) ) {
					$this->xml_append( $order_xml, 'GiftMessage', wp_specialchars_decode( $gift_message ) );
				}
			}

			// Custom fields - 1 is used for coupon codes.
			$this->xml_append( $order_xml, 'CustomField1', implode( ' | ', $order->get_coupon_codes() ) );

			// Custom fields 2 and 3 can be mapped to a custom field via the following filters.

			/**
			 * Custom fields 2 can be mapped to a custom field via the following filters.
			 *
			 * @since 4.0.1
			 */
			$meta_key = apply_filters( 'woocommerce_shipstation_export_custom_field_2', '' );
			if ( $meta_key ) {
				/**
				 * Allowing third party to modify the custom field 2 value.
				 *
				 * @since 4.1.0
				 */
				$this->xml_append( $order_xml, 'CustomField2', apply_filters( 'woocommerce_shipstation_export_custom_field_2_value', $order->get_meta( $meta_key, true ), $order_id ) );
			}

			/**
			 * Custom fields 3 can be mapped to a custom field via the following filters.
			 *
			 * @since 4.0.1
			 */
			$meta_key = apply_filters( 'woocommerce_shipstation_export_custom_field_3', '' );
			if ( $meta_key ) {
				/**
				 * Allowing third party to modify the custom field 3 value.
				 *
				 * @since 4.1.0
				 */
				$this->xml_append( $order_xml, 'CustomField3', apply_filters( 'woocommerce_shipstation_export_custom_field_3_value', $order->get_meta( $meta_key, true ), $order_id ) );
			}

			// Customer data.
			$customer_xml = $xml->createElement( 'Customer' );
			$this->xml_append( $customer_xml, 'CustomerCode', $order->get_billing_email() );

			$billto_xml = $xml->createElement( 'BillTo' );
			$this->xml_append( $billto_xml, 'Name', $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$this->xml_append( $billto_xml, 'Company', $order->get_billing_company() );
			$this->xml_append( $billto_xml, 'Phone', $order->get_billing_phone() );
			$this->xml_append( $billto_xml, 'Email', $order->get_billing_email() );
			$customer_xml->appendChild( $billto_xml );

			$shipto_xml   = $xml->createElement( 'ShipTo' );
			$address_data = Order_Util::get_address_data( $order );

			$this->xml_append( $shipto_xml, 'Name', $address_data['name'] );
			$this->xml_append( $shipto_xml, 'Company', $address_data['company'] );
			$this->xml_append( $shipto_xml, 'Address1', $address_data['address1'] );
			$this->xml_append( $shipto_xml, 'Address2', $address_data['address2'] );
			$this->xml_append( $shipto_xml, 'City', $address_data['city'] );
			$this->xml_append( $shipto_xml, 'State', $address_data['state'] );
			$this->xml_append( $shipto_xml, 'PostalCode', $address_data['postcode'] );
			$this->xml_append( $shipto_xml, 'Country', $address_data['country'] );
			$this->xml_append( $shipto_xml, 'Phone', $address_data['phone'] );

			$customer_xml->appendChild( $shipto_xml );

			$order_xml->appendChild( $customer_xml );

			// Item data.
			$found_item         = false;
			$product_dimensions = array();
			$items_xml          = $xml->createElement( 'Items' );
			// Merge arrays without loosing indexes.
			$order_items = $order->get_items() + $order->get_items( 'fee' );
			foreach ( $order_items as $item_id => $item ) {
				$product                = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : false;
				$item_needs_no_shipping = ! $product || ! $product->needs_shipping();
				$item_not_a_fee         = 'fee' !== $item->get_type();

				/**
				 * Allow third party to exclude the item for when an item does not need shipping or is a fee.
				 *
				 * @since 4.1.31
				 */
				if ( apply_filters( 'woocommerce_shipstation_no_shipping_item', $item_needs_no_shipping && $item_not_a_fee, $product, $item ) ) {
					continue;
				}

				$found_item = true;
				$item_xml   = $xml->createElement( 'Item' );
				$this->xml_append( $item_xml, 'LineItemID', $item_id );

				if ( 'fee' === $item->get_type() ) {
					$this->xml_append( $item_xml, 'Name', $item->get_name() );
					$this->xml_append( $item_xml, 'Quantity', 1, false );

					$item_total = $order->get_item_total( $item, false, true );

					// Maybe convert fee item total.
					if ( 1.00 !== $exchange_rate ) {
						$item_total = wc_format_decimal( ( $item_total * $exchange_rate ), wc_get_price_decimals() );
					}

					$this->xml_append( $item_xml, 'UnitPrice', $item_total, false );
				}

				// handle product specific data.
				if ( $product && $product->needs_shipping() ) {
					$this->xml_append( $item_xml, 'SKU', $product->get_sku() );
					$this->xml_append( $item_xml, 'Name', $item->get_name() );
					// image data.
					$image_id  = $product->get_image_id();
					$image_src = $image_id ? wp_get_attachment_image_src( $image_id, 'woocommerce_gallery_thumbnail' ) : '';
					$image_url = is_array( $image_src ) ? current( $image_src ) : '';

					$this->xml_append( $item_xml, 'ImageUrl', $image_url );

					if ( 'kg' === $store_weight_unit ) {
						$this->xml_append( $item_xml, 'Weight', wc_get_weight( $product->get_weight(), 'g' ), false );
						$this->xml_append( $item_xml, 'WeightUnits', 'Grams', false );
					} else {
						$this->xml_append( $item_xml, 'Weight', $product->get_weight(), false );
						$this->xml_append( $item_xml, 'WeightUnits', $this->get_shipstation_weight_units( $store_weight_unit ), false );
					}

					// current item quantity - refunded quantity.
					$item_qty = $item->get_quantity() - abs( $order->get_qty_refunded_for_item( $item_id ) );
					$this->xml_append( $item_xml, 'Quantity', $item_qty, false );

					$item_total = $export_discounts_as_separate_item ? $order->get_item_subtotal( $item, false, true ) : $order->get_item_total( $item, false, true );

					// Maybe convert item total.
					if ( 1.00 !== $exchange_rate ) {
						$item_total = wc_format_decimal( ( $item_total * $exchange_rate ), wc_get_price_decimals() );
					}

					$this->xml_append( $item_xml, 'UnitPrice', $item_total, false );

					$product_dimensions[] = array(
						'length' => wc_get_dimension( floatval( $product->get_length() ), 'in' ),
						'width'  => wc_get_dimension( floatval( $product->get_width() ), 'in' ),
						'height' => wc_get_dimension( floatval( $product->get_height() ), 'in' ),
						'qty'    => $item_qty,
					);
				}

				if ( $item->get_meta_data() ) {
					add_filter( 'woocommerce_is_attribute_in_product_name', '__return_false' );
					$formatted_meta = $item->get_formatted_meta_data();

					if ( ! empty( $formatted_meta ) ) {
						$options_xml = $xml->createElement( 'Options' );

						foreach ( $formatted_meta as $meta_key => $meta ) {
							$option_xml = $xml->createElement( 'Option' );
							$this->xml_append( $option_xml, 'Name', html_entity_decode( $meta->display_key, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
							$this->xml_append( $option_xml, 'Value', html_entity_decode( wp_strip_all_tags( $meta->display_value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
							$options_xml->appendChild( $option_xml );
						}

						$item_xml->appendChild( $options_xml );
					}
				}

				$items_xml->appendChild( $item_xml );
			}

			if ( ! $found_item ) {
				continue;
			}

			// Get the first product's dimensions.
			$dimensions = array_shift( $product_dimensions );

			// Make sure the product item is only 1 and the quantity is also 1.
			if ( empty( $product_dimensions ) && ! empty( $dimensions['qty'] ) && 1 === $dimensions['qty'] ) {
				$dimensions_xml = $xml->createElement( 'Dimensions' );

				$this->xml_append( $dimensions_xml, 'Length', $dimensions['length'], false );
				$this->xml_append( $dimensions_xml, 'Width', $dimensions['width'], false );
				$this->xml_append( $dimensions_xml, 'Height', $dimensions['height'], false );
				$this->xml_append( $dimensions_xml, 'DimensionUnits', 'in', false );

				$order_xml->appendChild( $dimensions_xml );
			}

			// Append cart level discount line.
			if ( $export_discounts_as_separate_item && $order->get_total_discount() ) {
				$item_xml = $xml->createElement( 'Item' );
				$this->xml_append( $item_xml, 'SKU', 'total-discount' );
				$this->xml_append( $item_xml, 'Name', __( 'Total Discount', 'woocommerce-shipstation-integration' ) );
				$this->xml_append( $item_xml, 'Adjustment', 'true', false );
				$this->xml_append( $item_xml, 'Quantity', 1, false );

				$order_total_discount = $order->get_total_discount() * -1;

				// Maybe convert order total discount.
				if ( 1.00 !== $exchange_rate ) {
					$order_total_discount = wc_format_decimal( ( $order_total_discount * $exchange_rate ), wc_get_price_decimals() );
				}

				$this->xml_append( $item_xml, 'UnitPrice', $order_total_discount, false );
				$items_xml->appendChild( $item_xml );
			}

			// Append items XML.
			$order_xml->appendChild( $items_xml );

			/**
			 * Allow third party to modify the XML that will be exported.
			 *
			 * @since 4.1.39
			 */
			$orders_xml->appendChild( apply_filters( 'woocommerce_shipstation_export_order_xml', $order_xml ) );

			++$exported;

			// Add order note to indicate it has been exported to Shipstation.
			if ( 'yes' !== $order->get_meta( '_shipstation_exported', true ) ) {
				$order->add_order_note( __( 'Order has been exported to Shipstation', 'woocommerce-shipstation-integration' ) );
				$order->update_meta_data( '_shipstation_exported', 'yes' );
				$order->save_meta_data();
			}
		}

		$orders_xml->setAttribute( 'page', $page );
		$orders_xml->setAttribute( 'pages', ceil( $max_results / WC_SHIPSTATION_EXPORT_LIMIT ) );
		$xml->appendChild( $orders_xml );
		echo $xml->saveXML(); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, we want the output to be XML.

		/* translators: 1: total count */
		$this->log( sprintf( __( 'Exported %s orders', 'woocommerce-shipstation-integration' ), $exported ) );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Append XML as cdata.
	 *
	 * @param DOMElement $append_to XML DOMElement to append to.
	 * @param string     $name      Element name.
	 * @param mixed      $value     Element value.
	 * @param boolean    $cdata     Using cData or not.
	 */
	private function xml_append( $append_to, $name, $value, $cdata = true ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase --- ownerDocument is the correct property.
		$data = $append_to->appendChild( $append_to->ownerDocument->createElement( $name ) );

		if ( $cdata ) {
			$child_node = empty( $append_to->ownerDocument->createCDATASection( $value ) ) ? $append_to->ownerDocument->createCDATASection( '' ) : $append_to->ownerDocument->createCDATASection( $value );
		} else {
			$child_node = empty( $append_to->ownerDocument->createTextNode( $value ) ) ? $append_to->ownerDocument->createTextNode( '' ) : $append_to->ownerDocument->createTextNode( $value );
		}

		$data->appendChild( $child_node );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Convert weight unit abbreviation to Shipstation enum (Pounds, Ounces, Grams).
	 *
	 * @param string $unit_abbreviation Weight unit abbreviation.
	 */
	private function get_shipstation_weight_units( $unit_abbreviation ) {
		switch ( $unit_abbreviation ) {
			case 'lbs':
				return 'Pounds';
			case 'oz':
				return 'Ounces';
			case 'g':
				return 'Grams';
			default:
				return $unit_abbreviation;
		}
	}
}

return new WC_Shipstation_API_Export();
