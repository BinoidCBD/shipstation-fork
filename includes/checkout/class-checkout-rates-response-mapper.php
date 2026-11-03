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

		$rates    = array();
		$transits = array();

		foreach ( $quotes as $quote ) {
			if ( ! is_array( $quote ) ) {
				continue;
			}

			$mapped = $this->map_quote( $quote, $quote_id );

			if ( null !== $mapped ) {
				$rates[]    = $mapped;
				$transits[] = isset( $quote['transit_time'] ) && is_array( $quote['transit_time'] ) ? $quote['transit_time'] : array();
			}
		}

		return $this->make_ids_unique( $rates, $transits );
	}

	/**
	 * Get a transit time in days.
	 *
	 * @since 5.3.9
	 *
	 * @param array $transit_time The quote's transit_time entry.
	 *
	 * @return float|null Days, or null when there is no numeric duration.
	 */
	private function transit_days( array $transit_time ): ?float {
		if ( ! isset( $transit_time['duration'] ) || ! is_numeric( $transit_time['duration'] ) ) {
			return null;
		}

		$duration = (float) $transit_time['duration'];

		switch ( $this->transit_units( $transit_time ) ) {
			case 'hour':
			case 'hours':
				return $duration / 24;
			case 'week':
			case 'weeks':
				return $duration * 7;
			default:
				// Days, business days, or a missing or unknown unit.
				return $duration;
		}
	}

	/**
	 * Get a transit time's unit in lowercase, with spaces for underscores.
	 *
	 * @since 5.3.9
	 *
	 * @param array $transit_time The quote's transit_time entry.
	 *
	 * @return string The unit, or an empty string when there is none.
	 */
	private function transit_units( array $transit_time ): string {
		return isset( $transit_time['units'] ) && is_string( $transit_time['units'] )
			? strtolower( str_replace( '_', ' ', trim( $transit_time['units'] ) ) )
			: '';
	}

	/**
	 * Same-name quotes get __2, __3, ordered by cost then transit time.
	 *
	 * @since 5.3.9
	 *
	 * @param array $rates    Mapped rates, with ids from their service names.
	 * @param array $transits Each rate's transit_time entry, by the same index.
	 *
	 * @return array The same rates with unique ids.
	 */
	private function make_ids_unique( array $rates, array $transits ): array {
		$groups = array();
		foreach ( $rates as $index => $rate ) {
			$groups[ $rate['id'] ][] = $index;
		}

		$taken = array_fill_keys( array_keys( $groups ), true );

		foreach ( $groups as $base_id => $indexes ) {
			if ( count( $indexes ) < 2 ) {
				continue;
			}

			// Sort on API values, not translated text, so every locale agrees.
			$keys = array();
			foreach ( $indexes as $index ) {
				$keys[ $index ] = array(
					$rates[ $index ]['cost'],
					// A missing duration sorts last.
					$this->transit_days( $transits[ $index ] ) ?? INF,
					$this->transit_units( $transits[ $index ] ),
					$rates[ $index ]['description'] ?? '',
					// usort() is not stable before PHP 8.
					$index,
				);
			}

			usort(
				$indexes,
				static function ( int $a, int $b ) use ( $keys ): int {
					return $keys[ $a ] <=> $keys[ $b ];
				}
			);

			$suffix = 1;
			foreach ( array_slice( $indexes, 1 ) as $index ) {
				do {
					++$suffix;
					$id = $base_id . '__' . $suffix;
				} while ( isset( $taken[ $id ] ) );

				$taken[ $id ]          = true;
				$rates[ $index ]['id'] = $id;
			}
		}

		return $rates;
	}

	/**
	 * Rate ids come from the service name, so they survive a new quote.
	 *
	 * @since 5.3.9
	 *
	 * @param string $label Quote display name.
	 *
	 * @return string
	 */
	private function rate_id( string $label ): string {
		// Fixed locale and no filters, so a service keeps one id everywhere.
		// Underscores become dashes, so no slug can contain the "__" suffix.
		$key = sanitize_title_with_dashes( remove_accents( $label, 'en_US' ), '', 'save' );
		$key = trim( (string) preg_replace( '/-+/', '-', (string) preg_replace( '/[^a-z0-9-]/', '-', $key ) ), '-' );

		if ( '' === $key ) {
			$key = substr( md5( $label ), 0, 12 );
		}

		return 'shipstation_' . $key;
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
			'id'        => $this->rate_id( $label ),
			'label'     => $label,
			'cost'      => (float) $quote['cost']['amount'],
			'meta_data' => array(
				Checkout_Rates_Options::RATE_CODE_META_KEY => $code,
				Checkout_Rates_Options::QUOTE_ID_META_KEY  => $quote_id,
			),
		);

		// description and delivery_time map onto the native WC_Shipping_Rate fields
		// (WC 9.2+), which the block cart/checkout renders. The shipping method applies
		// them to the rate object after add_rate(). They are deliberately kept off the
		// label so the label (which WooCommerce persists as the order shipping method
		// title) stays the bare carrier name.
		if ( isset( $quote['description'] ) && is_string( $quote['description'] ) && '' !== $quote['description'] ) {
			$rate['description'] = $quote['description'];
		}

		$delivery_time = isset( $quote['transit_time'] ) && is_array( $quote['transit_time'] )
			? $this->format_delivery_time( $quote['transit_time'] )
			: '';

		if ( '' !== $delivery_time ) {
			$rate['delivery_time'] = $delivery_time;
		}

		return $rate;
	}

	/**
	 * Format a ShipStation transit_time block into a human-readable delivery estimate.
	 *
	 * Returns an empty string when the duration is missing, non-numeric, or not positive.
	 * The duration is rounded to the nearest whole unit. Known units (day, business day,
	 * hour, week) are localized with correct singular/plural forms; a missing unit defaults
	 * to days. An unrecognized unit is passed through sanitized of any markup.
	 *
	 * @since 5.2.1
	 *
	 * @param array $transit_time ShipStation transit_time block (duration, units).
	 *
	 * @return string Delivery estimate such as "2 days" or "1 business day", or '' when unavailable.
	 */
	private function format_delivery_time( array $transit_time ): string {
		if ( ! isset( $transit_time['duration'] ) || ! is_numeric( $transit_time['duration'] ) ) {
			return '';
		}

		$duration = (int) round( (float) $transit_time['duration'] );

		if ( $duration < 1 ) {
			return '';
		}

		$units = $this->transit_units( $transit_time );

		switch ( $units ) {
			case '':
			case 'day':
			case 'days':
				/* translators: %d: number of days. */
				return sprintf( _n( '%d day', '%d days', $duration, 'woocommerce-shipstation-integration' ), $duration );
			case 'business day':
			case 'business days':
				/* translators: %d: number of business days. */
				return sprintf( _n( '%d business day', '%d business days', $duration, 'woocommerce-shipstation-integration' ), $duration );
			case 'hour':
			case 'hours':
				/* translators: %d: number of hours. */
				return sprintf( _n( '%d hour', '%d hours', $duration, 'woocommerce-shipstation-integration' ), $duration );
			case 'week':
			case 'weeks':
				/* translators: %d: number of weeks. */
				return sprintf( _n( '%d week', '%d weeks', $duration, 'woocommerce-shipstation-integration' ), $duration );
			default:
				// Unrecognized unit from the API: render defensively, stripped of any markup.
				return $duration . ' ' . sanitize_text_field( $units );
		}
	}
}
