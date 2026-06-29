<?php
/**
 * Checkout Rates Response Mapper class file.
 *
 * @package WC_ShipStation
 * @since 5.0.5
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

use WooCommerce\Shipping\ShipStation\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps raw ShipStation checkout rates API responses into WooCommerce-compatible rate arrays.
 *
 * @since 5.0.5
 */
final class Checkout_Rates_Response_Mapper {

	/**
	 * Map a ShipStation rates response to an array of WC rate arrays.
	 *
	 * @since 5.0.5
	 *
	 * @param array $response Raw ShipStation API response.
	 *
	 * @return array List of WC-compatible rate arrays.
	 */
	public function map( array $response ): array {
		$quotes = isset( $response['quotes'] ) && is_array( $response['quotes'] )
			? $response['quotes']
			: array();

		if ( empty( $quotes ) ) {
			return array();
		}

		$quote_id = isset( $response['quote_id'] )
			? (string) $response['quote_id']
			: '';

		$rates = array();

		foreach ( $quotes as $quote ) {
			if ( ! is_array( $quote ) ) {
				continue;
			}

			$mapped = $this->map_quote( $quote, $quote_id );

			if ( null !== $mapped ) {
				$rates[] = $mapped;
			}
		}

		return $rates;
	}

	/**
	 * Map a single quote entry to a WC rate array.
	 *
	 * Returns null if the entry should be skipped (missing or empty code, or missing cost).
	 *
	 * @since 5.0.5
	 *
	 * @param array  $quote    Single quote entry.
	 * @param string $quote_id Top-level quote ID from the response.
	 *
	 * @return array|null WC rate array or null when quote should be skipped.
	 */
	private function map_quote( array $quote, string $quote_id ): ?array {
		if ( ! isset( $quote['code'] ) || ! is_scalar( $quote['code'] ) || '' === (string) $quote['code'] ) {
			Logger::debug(
				'ShipStation checkout rate quote skipped: missing or invalid "code" field.',
				array( 'quote_id' => $quote_id )
			);
			return null;
		}

		if ( ! isset( $quote['cost']['amount'] ) || ! is_numeric( $quote['cost']['amount'] ) ) {
			Logger::debug(
				'ShipStation checkout rate quote skipped: missing or non-numeric "cost.amount" field.',
				array(
					'quote_id' => $quote_id,
					'code'     => (string) $quote['code'],
				)
			);
			return null;
		}

		$code  = (string) $quote['code'];
		$label = isset( $quote['display_name'] ) && is_string( $quote['display_name'] )
			? $quote['display_name']
			: '';

		if ( '' === $label ) {
			Logger::debug(
				'ShipStation checkout rate quote skipped: missing or empty "display_name" field.',
				array(
					'quote_id' => $quote_id,
					'code'     => $code,
				)
			);
			return null;
		}

		$rate = array(
			'id'        => 'shipstation_' . sanitize_key( $code ),
			'label'     => $label,
			'cost'      => (float) $quote['cost']['amount'],
			'meta_data' => array(
				Checkout_Rates_Options::RATE_CODE_META_KEY => $code,
				Checkout_Rates_Options::QUOTE_ID_META_KEY  => $quote_id,
			),
		);

		if ( isset( $quote['description'] ) && is_string( $quote['description'] ) && '' !== $quote['description'] ) {
			$rate['meta_data']['description'] = $quote['description'];
		}

		if ( isset( $quote['transit_time']['duration'] ) && is_numeric( $quote['transit_time']['duration'] ) ) {
			$rate['meta_data']['transit_time'] = (int) $quote['transit_time']['duration'];
		}

		return $rate;
	}
}
