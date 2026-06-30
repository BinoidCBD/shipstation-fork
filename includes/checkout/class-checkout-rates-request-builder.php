<?php
/**
 * Checkout Rates Request Builder class file.
 *
 * @package WC_ShipStation
 * @since 4.9.8
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

use WC_Product;
use WooCommerce\Shipping\ShipStation\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the ShipStation checkout rates API request payload from a WC shipping package.
 *
 * @since 4.9.8
 */
final class Checkout_Rates_Request_Builder {

	/**
	 * Pre-filter payload snapshots captured during the current build() call.
	 *
	 * Used by Checkout_Rates_Payload_Validator to attribute a violation to the
	 * public filter that introduced it. Reset on every build() call. Recognised
	 * keys: 'pre_address_type', 'pre_destination', 'pre_item', 'pre_items',
	 * 'pre_request'.
	 *
	 * @var array<string, mixed>
	 */
	private array $filter_snapshots = array();

	/**
	 * Build the rates request payload from a WooCommerce shipping package.
	 *
	 * Returns an array under a `rate` key containing `destination` and `items`.
	 * The `connection_key` is not included — it is injected by the caller.
	 *
	 * @since 4.9.8
	 *
	 * @param array $package WooCommerce shipping package.
	 *
	 * @return array Rates request payload.
	 *
	 * @throws Checkout_Rates_Invalid_Payload_Exception When the final payload fails validation;
	 *                                                  callers should treat this as "do not send the request".
	 */
	public function build( array $package ) {
		$destination = isset( $package['destination'] ) ? $package['destination'] : array();

		$this->filter_snapshots = array();

		$built_destination = $this->build_destination( $destination );
		$built_items       = $this->build_items( $package );

		$payload = array(
			'rate' => array(
				'destination' => $built_destination,
				'items'       => $built_items,
			),
		);

		$this->filter_snapshots['pre_request'] = $payload;

		/**
		 * Filters the final checkout rates request payload before it is returned.
		 *
		 * @since 5.0.4
		 *
		 * @param array $payload The outbound checkout rates payload.
		 * @param array $package The WooCommerce shipping package.
		 */
		$payload = apply_filters( 'woocommerce_shipstation_checkout_rates_request', $payload, $package );

		Checkout_Rates_Payload_Validator::validate( $payload, $this->filter_snapshots );

		return $payload;
	}

	/**
	 * Build the destination portion of the payload.
	 *
	 * @param array $destination WC package destination array.
	 *
	 * @return array
	 */
	private function build_destination( array $destination ) {
		$this->filter_snapshots['pre_address_type'] = 'residential';

		/**
		 * Filters the checkout rates address type before it is set.
		 *
		 * @since 5.0.4
		 *
		 * @param string $address_type Default address type ('residential').
		 * @param array  $destination  The raw WC package destination array.
		 */
		$address_type = apply_filters(
			'woocommerce_shipstation_checkout_rates_address_type',
			'residential',
			$destination
		);

		$name  = $this->get_customer_name();
		$phone = $this->get_customer_phone();
		$email = $this->get_customer_email();

		$built_destination = array(
			'country'      => $destination['country'] ?? '',
			'postal_code'  => $destination['postcode'] ?? '',
			'province'     => $destination['state'] ?? '',
			'city'         => $destination['city'] ?? '',
			'address1'     => $destination['address'] ?? '',
			'address2'     => $destination['address_2'] ?? '',
			'address_type' => $address_type,
		);

		$address3 = isset( $destination['address_3'] ) && '' !== $destination['address_3']
			? $destination['address_3']
			: '';

		if ( '' !== $address3 ) {
			$built_destination['address3'] = $address3;
		}

		if ( '' !== $name ) {
			$built_destination['name'] = $name;
		}

		if ( '' !== $phone ) {
			$built_destination['phone'] = $phone;
		}

		if ( '' !== $email ) {
			$built_destination['email'] = $email;
		}

		$this->filter_snapshots['pre_destination'] = $built_destination;

		/**
		 * Filters the checkout rates destination data before it is returned.
		 *
		 * @since 5.0.4
		 *
		 * @param array $built_destination The destination data.
		 * @param array $destination       The raw WC package destination array.
		 */
		return apply_filters( 'woocommerce_shipstation_checkout_rates_destination', $built_destination, $destination );
	}

	/**
	 * Build the items array from the package contents.
	 *
	 * @param array $package WooCommerce shipping package.
	 *
	 * @return array
	 */
	private function build_items( array $package ) {
		$contents = isset( $package['contents'] ) ? $package['contents'] : array();

		if ( empty( $contents ) ) {
			return array();
		}

		// Hoist store-wide values out of the loop — they don't vary per line item.
		$weight_unit_raw = get_option( 'woocommerce_weight_unit' );
		$currency        = get_woocommerce_currency();
		$price_decimals  = wc_get_price_decimals();

		$items          = array();
		$pre_item_items = array();
		foreach ( $contents as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			if ( ! $product->needs_shipping() ) {
				continue;
			}

			$weight = $product->get_weight();

			if ( '' === $weight || ! is_numeric( $weight ) ) {
				$weight_value = '0';
				$weight_unit  = $weight_unit_raw;
			} else {
				$converted = ShipStation_Unit_Converter::convert_weight( $weight, (string) $weight_unit_raw );
				if ( null === $converted ) {
					Logger::error(
						sprintf(
							'Checkout rates: WooCommerce weight unit "%s" is not recognised by the ShipStation unit converter. Accepted units are: oz, lbs, g, kg. Update woocommerce_weight_unit or extend the converter via the woocommerce_shipstation_weight_units filter. Passing raw value to ShipStation — expect a 4xx response.',
							(string) $weight_unit_raw
						)
					);
					$weight_value = (string) $weight;
					$weight_unit  = $weight_unit_raw;
				} else {
					$weight_value = wc_format_decimal( $converted['value'], 4 );
					$weight_unit  = $converted['unit'];
				}
			}

			// PRD wire shape requires integer quantity; fractional WC quantities
			// (e.g. 0.5 yards of fabric) are floored to a minimum of 1. Unit price
			// is compensated by dividing line_total by the *raw* fractional
			// quantity, so per-unit price stays accurate. Weight is intentionally
			// NOT compensated — it is sent as the per-unit value and ShipStation
			// multiplies it by the integer quantity. This means a 0.5-yard cart
			// line ships as quantity=1 with full per-unit weight, slightly
			// overstating shipping weight for fractional items. Acceptable per
			// PRD, since fractional quantities are uncommon and the alternative
			// (inflating weight to match the truncated quantity ratio) violates
			// the per-unit weight contract carriers expect.
			$raw_quantity = isset( $item['quantity'] ) && is_numeric( $item['quantity'] )
				? (float) $item['quantity']
				: 0.0;
			$quantity     = max( 1, (int) $raw_quantity );

			$line_total = isset( $item['line_total'] ) && is_numeric( $item['line_total'] )
				? (float) $item['line_total']
				: 0.0;

			$unit_price = 0.0 < $raw_quantity
				? wc_format_decimal( $line_total / $raw_quantity, $price_decimals )
				: wc_format_decimal( 0, $price_decimals );

			$line_item = array(
				'name'     => $product->get_name(),
				'sku'      => $product->get_sku(),
				'quantity' => $quantity,
				'weight'   => array(
					'value' => $weight_value,
					'unit'  => $weight_unit,
				),
				'price'    => array(
					'amount'   => $unit_price,
					'currency' => $currency,
				),
			);

			$dimensions = $this->build_item_dimensions( $product );
			if ( ! empty( $dimensions ) ) {
				$line_item['dimensions'] = $dimensions;
			}

			$pre_item_items[] = $line_item;

			/**
			 * Filters an individual checkout rates line item before it is added to the items array.
			 *
			 * @since 5.0.4
			 *
			 * @param array      $line_item The line item data.
			 * @param array      $package   The WooCommerce shipping package.
			 * @param WC_Product $product   The product object.
			 * @param array      $item      The raw cart item.
			 */
			$items[] = apply_filters( 'woocommerce_shipstation_checkout_rates_item', $line_item, $package, $product, $item );
		}

		$this->filter_snapshots['pre_item']  = $pre_item_items;
		$this->filter_snapshots['pre_items'] = $items;

		/**
		 * Filters the checkout rates items array before it is returned.
		 *
		 * @since 5.0.4
		 *
		 * @param array $items   The items array.
		 * @param array $package The WooCommerce shipping package.
		 */
		return apply_filters( 'woocommerce_shipstation_checkout_rates_items', $items, $package );
	}

	/**
	 * Build the dimensions array for a product.
	 *
	 * Returns an empty array if any dimension is empty, non-numeric, zero, or negative.
	 *
	 * @param WC_Product $product WooCommerce product.
	 *
	 * @return array
	 */
	private function build_item_dimensions( WC_Product $product ) {
		$length = $product->get_length();
		$width  = $product->get_width();
		$height = $product->get_height();

		if ( '' === $length || '' === $width || '' === $height ) {
			return array();
		}

		if ( ! is_numeric( $length ) || ! is_numeric( $width ) || ! is_numeric( $height ) ) {
			return array();
		}

		if ( 0.0 >= (float) $length || 0.0 >= (float) $width || 0.0 >= (float) $height ) {
			return array();
		}

		$dim_unit_raw = (string) get_option( 'woocommerce_dimension_unit' );

		$converted_l = ShipStation_Unit_Converter::convert_dimension( $length, $dim_unit_raw );
		$converted_w = ShipStation_Unit_Converter::convert_dimension( $width, $dim_unit_raw );
		$converted_h = ShipStation_Unit_Converter::convert_dimension( $height, $dim_unit_raw );

		if ( null === $converted_l || null === $converted_w || null === $converted_h ) {
			Logger::error(
				sprintf(
					'Checkout rates: WooCommerce dimension unit "%s" is not recognised by the ShipStation unit converter. Accepted units are: in, cm. Update woocommerce_dimension_unit or extend the converter via the woocommerce_shipstation_dimension_units filter. Passing raw values to ShipStation — expect a 4xx response.',
					$dim_unit_raw
				)
			);
			return array(
				'length' => wc_format_decimal( $length, 2 ),
				'width'  => wc_format_decimal( $width, 2 ),
				'height' => wc_format_decimal( $height, 2 ),
				'unit'   => $dim_unit_raw,
			);
		}

		return array(
			'length' => wc_format_decimal( $converted_l['value'], 2 ),
			'width'  => wc_format_decimal( $converted_w['value'], 2 ),
			'height' => wc_format_decimal( $converted_h['value'], 2 ),
			'unit'   => $converted_l['unit'],
		);
	}

	/**
	 * Get the current WooCommerce customer object, if available.
	 *
	 * @return \WC_Customer|null
	 */
	private function get_customer() {
		if ( function_exists( 'WC' ) && WC() && WC()->customer ) {
			return WC()->customer;
		}

		return null;
	}

	/**
	 * Get customer name from the customer object.
	 *
	 * Name is an optional API field, so absence is expected behavior and not
	 * an actionable condition.
	 *
	 * @since 4.9.8
	 *
	 * @return string
	 */
	private function get_customer_name(): string {
		$customer = $this->get_customer();

		if ( $customer ) {
			$name = trim( $customer->get_shipping_first_name() . ' ' . $customer->get_shipping_last_name() );
			if ( '' !== $name ) {
				return $name;
			}

			$name = trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() );
			if ( '' !== $name ) {
				return $name;
			}
		}

		Logger::debug(
			'Checkout rates: no customer name is available; the rates request will omit the name field.'
		);

		return '';
	}

	/**
	 * Get customer phone from customer object.
	 *
	 * @since 4.9.8
	 *
	 * @return string
	 */
	private function get_customer_phone(): string {
		$phone    = '';
		$customer = $this->get_customer();

		if ( $customer ) {
			$phone = trim( $customer->get_billing_phone() );
		}

		return $phone;
	}

	/**
	 * Get customer email from the customer object.
	 *
	 * @since 4.9.8
	 *
	 * @return string
	 */
	private function get_customer_email(): string {
		// Prefer billing email so a logged-in user who edits the checkout email field
		// gets the just-typed value forwarded (not the older account email on the WP
		// user record), and guests with no account still get the form-entered email
		// through. Account email is the fallback. Email is optional, so the empty
		// path is silent — only a non-empty value that fails validation warns.
		$customer = $this->get_customer();

		if ( ! $customer ) {
			return '';
		}

		$billing_email = sanitize_email( (string) $customer->get_billing_email() );

		if ( is_email( $billing_email ) ) {
			return $billing_email;
		}

		$account_email = sanitize_email( (string) $customer->get_email() );

		if ( is_email( $account_email ) ) {
			return $account_email;
		}

		if ( '' !== $billing_email || '' !== $account_email ) {
			Logger::warning(
				'Checkout rates: customer email is set but does not validate; the rates request will omit the email field.'
			);
		}

		return '';
	}
}
