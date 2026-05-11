<?php
/**
 * Checkout Rates Request Builder class file.
 *
 * @package WC_ShipStation
 * @since 4.9.8
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

use WC_Product;

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
	 */
	public function build( array $package ) {
		$destination = isset( $package['destination'] ) ? $package['destination'] : array();

		return array(
			'rate' => array(
				'destination' => $this->build_destination( $destination ),
				'items'       => $this->build_items( $package ),
			),
		);
	}

	/**
	 * Build the destination portion of the payload.
	 *
	 * @param array $destination WC package destination array.
	 *
	 * @return array
	 */
	private function build_destination( array $destination ) {
		$address3 = isset( $destination['address_3'] ) && '' !== $destination['address_3']
			? $destination['address_3']
			: null;

		return array(
			'country'      => isset( $destination['country'] ) ? $destination['country'] : '',
			'postal_code'  => isset( $destination['postcode'] ) ? $destination['postcode'] : '',
			'province'     => isset( $destination['state'] ) ? $destination['state'] : '',
			'city'         => isset( $destination['city'] ) ? $destination['city'] : '',
			'name'         => null,
			'address1'     => isset( $destination['address'] ) ? $destination['address'] : '',
			'address2'     => isset( $destination['address_2'] ) ? $destination['address_2'] : '',
			'address3'     => $address3,
			'phone'        => null,
			'email'        => null,
			'address_type' => 'residential',
		);
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

		$items = array();
		foreach ( $contents as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			if ( ! $product->needs_shipping() ) {
				continue;
			}

			$weight   = $product->get_weight();
			$quantity = (int) $item['quantity'];

			if ( '' === $weight || ! is_numeric( $weight ) ) {
				$weight = '0';
			} else {
				$weight = (string) $weight;
			}

			$unit_price = 0 < $quantity
				? number_format( (float) $item['line_total'] / $quantity, 2, '.', '' )
				: '0.00';

			$items[] = array(
				'name'     => $product->get_name(),
				'sku'      => $product->get_sku(),
				'quantity' => $quantity,
				'weight'   => array(
					'value' => $weight,
					'unit'  => get_option( 'woocommerce_weight_unit' ),
				),
				'price'    => array(
					'amount'   => $unit_price,
					'currency' => get_woocommerce_currency(),
				),
			);
		}

		return $items;
	}
}
