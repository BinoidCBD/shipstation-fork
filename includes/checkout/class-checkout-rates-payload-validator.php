<?php
/**
 * Checkout Rates Payload Validator class file.
 *
 * @package WC_ShipStation
 * @since 5.0.9
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

use WooCommerce\Shipping\ShipStation\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates the final checkout rates request payload against ShipStation's wire shape.
 *
 * Runs after all five public checkout-rates filters have applied. Logs each violation
 * with field- and filter-level context, then throws Checkout_Rates_Invalid_Payload_Exception
 * so callers can skip the upstream API request rather than send a payload ShipStation
 * will reject. Address fields the shopper has not entered yet are logged at debug level.
 *
 * @since 5.0.9
 */
final class Checkout_Rates_Payload_Validator {

	/**
	 * Filter execution order: snapshot key => the filter that runs immediately after it.
	 *
	 * Used to attribute a violation to the filter that introduced it by walking the
	 * snapshots in chronological order and identifying the last "clean" snapshot.
	 */
	private const FILTER_AFTER_SNAPSHOT = array(
		'pre_address_type' => 'woocommerce_shipstation_checkout_rates_address_type',
		'pre_destination'  => 'woocommerce_shipstation_checkout_rates_destination',
		'pre_item'         => 'woocommerce_shipstation_checkout_rates_item',
		'pre_items'        => 'woocommerce_shipstation_checkout_rates_items',
		'pre_request'      => 'woocommerce_shipstation_checkout_rates_request',
	);

	/**
	 * Destination fields that come from what the shopper typed.
	 */
	private const SHOPPER_ADDRESS_FIELDS = array( 'country', 'postal_code' );

	/**
	 * Validate the final outbound payload.
	 *
	 * @since 5.0.9
	 *
	 * @param array $payload   Final outbound payload (post all filters).
	 * @param array $snapshots Optional pre-filter snapshots for attribution. Recognised keys:
	 *                         - 'pre_address_type' (string) Default address type before address_type filter.
	 *                         - 'pre_destination'  (array)  Destination array before destination filter.
	 *                         - 'pre_item'         (array)  Items array of line items before per-item filter.
	 *                         - 'pre_items'        (array)  Items array before items filter.
	 *                         - 'pre_request'      (array)  Full payload before request filter.
	 *
	 * @return void
	 *
	 * @throws Checkout_Rates_Invalid_Payload_Exception When the payload contains one or more violations.
	 */
	public static function validate( array $payload, array $snapshots = array() ): void {
		$violations = self::collect_violations( $payload );

		if ( empty( $violations ) ) {
			return;
		}

		$errors         = array();
		$missing_fields = array();

		foreach ( $violations as $violation ) {
			$filter = self::attribute_to_filter( $violation, $snapshots );

			$field = null === $filter ? self::unentered_address_field( $violation, $payload, $snapshots ) : null;

			if ( null !== $field ) {
				$missing_fields[] = $field;
				continue;
			}

			$errors[] = $violation;
			self::log( $violation, $filter );
		}

		if ( ! empty( $missing_fields ) ) {
			Logger::debug(
				sprintf(
					'ShipStation checkout rates: no rates requested, the shipping address has no %s yet.',
					implode( ', ', $missing_fields )
				),
				array( 'fields' => $missing_fields )
			);
		}

		if ( empty( $errors ) ) {
			throw Checkout_Rates_Invalid_Payload_Exception::incomplete_address(
				esc_html(
					sprintf(
						'ShipStation checkout rates payload is missing address fields the shopper has not entered yet (%s).',
						implode( ', ', $missing_fields )
					)
				)
			);
		}

		throw new Checkout_Rates_Invalid_Payload_Exception(
			esc_html(
				sprintf(
					'ShipStation checkout rates payload failed validation (%d violation%s; first: %s).',
					count( $errors ),
					1 === count( $errors ) ? '' : 's',
					$errors[0]['path']
				)
			)
		);
	}

	/**
	 * The address field the shopper has not entered yet, for a violation.
	 *
	 * Only called for violations no filter introduced. Returns the field only when it
	 * is empty now and was already empty before the filters ran.
	 *
	 * @param array $violation Violation entry.
	 * @param array $payload   Final outbound payload.
	 * @param array $snapshots Pre-filter snapshots.
	 *
	 * @return string|null Destination field name, or null when the violation is an error.
	 */
	private static function unentered_address_field( array $violation, array $payload, array $snapshots ): ?string {
		$field = substr( $violation['path'], strlen( 'rate.destination.' ) );

		if ( 0 !== strpos( $violation['path'], 'rate.destination.' )
			|| ! in_array( $field, self::SHOPPER_ADDRESS_FIELDS, true )
			|| ! is_array( $snapshots['pre_destination'] ?? null ) ) {
			return null;
		}

		$before = $snapshots['pre_destination'][ $field ] ?? null;
		$now    = $payload['rate']['destination'][ $field ] ?? null;

		if ( 'postal_code' === $field && ! self::country_asks_for_postcode( $payload['rate']['destination']['country'] ?? '' ) ) {
			return null;
		}

		return ( null === $before || '' === $before ) && ( null === $now || '' === $now ) ? $field : null;
	}

	/**
	 * Whether the checkout asks the shopper for a postcode in this country.
	 *
	 * Where WooCommerce hides the postcode or makes it optional, an empty one is a
	 * complete address that ShipStation cannot quote, not an address in progress.
	 *
	 * @param mixed $country Destination country code.
	 *
	 * @return bool
	 */
	private static function country_asks_for_postcode( $country ): bool {
		if ( ! is_string( $country ) || '' === $country || ! function_exists( 'WC' ) || ! WC()->countries ) {
			return true;
		}

		$locale   = WC()->countries->get_country_locale();
		$postcode = $locale[ $country ]['postcode'] ?? array();

		return empty( $postcode['hidden'] ) && false !== ( $postcode['required'] ?? true );
	}

	/**
	 * Collect all violations in the payload.
	 *
	 * @param array $payload Payload to inspect.
	 *
	 * @return array<int, array{path: string, message: string}>
	 */
	private static function collect_violations( array $payload ): array {
		$violations = array();

		if ( ! isset( $payload['rate'] ) || ! is_array( $payload['rate'] ) ) {
			$violations[] = array(
				'path'    => 'rate',
				'message' => 'rate key is missing or not an array',
			);
			return $violations;
		}

		$rate = $payload['rate'];

		if ( ! isset( $rate['destination'] ) || ! is_array( $rate['destination'] ) ) {
			$violations[] = array(
				'path'    => 'rate.destination',
				'message' => 'rate.destination is missing or not an array',
			);
		} else {
			foreach ( self::check_destination( $rate['destination'] ) as $v ) {
				$violations[] = $v;
			}
		}

		if ( ! isset( $rate['items'] ) || ! is_array( $rate['items'] ) ) {
			$violations[] = array(
				'path'    => 'rate.items',
				'message' => 'rate.items is missing or not an array',
			);
		} else {
			foreach ( $rate['items'] as $i => $item ) {
				foreach ( self::check_item( $item, (int) $i ) as $v ) {
					$violations[] = $v;
				}
			}
		}

		return $violations;
	}

	/**
	 * Check the destination subtree for missing or malformed required fields.
	 *
	 * @param array $destination Destination array.
	 *
	 * @return array<int, array{path: string, message: string}>
	 */
	private static function check_destination( array $destination ): array {
		$violations = array();
		$required   = array( 'country', 'postal_code', 'address_type' );

		foreach ( $required as $field ) {
			if ( ! isset( $destination[ $field ] ) || ! is_string( $destination[ $field ] ) || '' === $destination[ $field ] ) {
				$violations[] = array(
					'path'    => 'rate.destination.' . $field,
					'message' => sprintf( 'rate.destination.%s is missing or not a non-empty string', $field ),
				);
			}
		}

		// ShipStation quotes without a city, so the key must be sent but may be empty.
		if ( ! isset( $destination['city'] ) || ! is_string( $destination['city'] ) ) {
			$violations[] = array(
				'path'    => 'rate.destination.city',
				'message' => 'rate.destination.city is missing or not a string',
			);
		}

		return $violations;
	}

	/**
	 * Check a single item entry for missing or malformed required fields.
	 *
	 * @param mixed $item  Item entry (expected array).
	 * @param int   $index Zero-based index for error path formatting.
	 *
	 * @return array<int, array{path: string, message: string}>
	 */
	private static function check_item( $item, int $index ): array {
		$violations = array();

		if ( ! is_array( $item ) ) {
			$violations[] = array(
				'path'    => sprintf( 'rate.items[%d]', $index ),
				'message' => sprintf( 'rate.items[%d] is not an array', $index ),
			);
			return $violations;
		}

		if ( ! isset( $item['quantity'] ) || ! is_int( $item['quantity'] ) || $item['quantity'] < 1 ) {
			$violations[] = array(
				'path'    => sprintf( 'rate.items[%d].quantity', $index ),
				'message' => sprintf( 'rate.items[%d].quantity must be an integer >= 1', $index ),
			);
		}

		if ( ! isset( $item['weight']['value'] ) || ! is_string( $item['weight']['value'] ) || ! is_numeric( $item['weight']['value'] ) ) {
			$violations[] = array(
				'path'    => sprintf( 'rate.items[%d].weight.value', $index ),
				'message' => sprintf( 'rate.items[%d].weight.value must be a numeric string', $index ),
			);
		}

		if ( ! isset( $item['weight']['unit'] ) || ! is_string( $item['weight']['unit'] ) || '' === $item['weight']['unit'] ) {
			$violations[] = array(
				'path'    => sprintf( 'rate.items[%d].weight.unit', $index ),
				'message' => sprintf( 'rate.items[%d].weight.unit must be a non-empty string', $index ),
			);
		}

		if ( ! isset( $item['price']['amount'] ) || ! is_string( $item['price']['amount'] ) || ! is_numeric( $item['price']['amount'] ) ) {
			$violations[] = array(
				'path'    => sprintf( 'rate.items[%d].price.amount', $index ),
				'message' => sprintf( 'rate.items[%d].price.amount must be a numeric string', $index ),
			);
		}

		if ( ! isset( $item['price']['currency'] ) || ! is_string( $item['price']['currency'] ) || '' === $item['price']['currency'] ) {
			$violations[] = array(
				'path'    => sprintf( 'rate.items[%d].price.currency', $index ),
				'message' => sprintf( 'rate.items[%d].price.currency must be a non-empty string', $index ),
			);
		}

		return $violations;
	}

	/**
	 * Identify the filter most likely responsible for a given violation.
	 *
	 * Walks the supplied snapshots in chronological order. For each snapshot that
	 * the violation's path could touch, asks "is this snapshot clean for this
	 * violation?". The last clean snapshot points to the filter that ran immediately
	 * after — that filter introduced the violation.
	 *
	 * Returns null when the violation is present in every applicable snapshot
	 * (pre-existing, not introduced by a filter), or when no snapshots were supplied.
	 *
	 * @param array $violation Violation entry.
	 * @param array $snapshots Pre-filter snapshots.
	 *
	 * @return string|null Filter hook name, or null if not attributable.
	 */
	private static function attribute_to_filter( array $violation, array $snapshots ): ?string {
		$path = $violation['path'];

		$item_index   = null;
		$is_item_path = false;
		if ( preg_match( '/^rate\.items\[(\d+)\]/', $path, $matches ) ) {
			$item_index   = (int) $matches[1];
			$is_item_path = true;
		}

		$is_address_type = ( 'rate.destination.address_type' === $path );
		$is_destination  = ( 0 === strpos( $path, 'rate.destination' ) );
		$is_items_root   = ( 'rate.items' === $path );

		$last_clean_filter = null;

		if ( $is_address_type && array_key_exists( 'pre_address_type', $snapshots ) ) {
			$snap = $snapshots['pre_address_type'];
			if ( is_string( $snap ) && '' !== $snap ) {
				$last_clean_filter = self::FILTER_AFTER_SNAPSHOT['pre_address_type'];
			}
		}

		if ( $is_destination && array_key_exists( 'pre_destination', $snapshots ) ) {
			$snap = $snapshots['pre_destination'];
			if ( is_array( $snap ) && self::path_clean_in_destination( $snap, $path ) ) {
				$last_clean_filter = self::FILTER_AFTER_SNAPSHOT['pre_destination'];
			}
		}

		if ( $is_item_path && array_key_exists( 'pre_item', $snapshots ) ) {
			$snap = $snapshots['pre_item'];
			if ( is_array( $snap ) && self::path_clean_in_items( $snap, $item_index, $path ) ) {
				$last_clean_filter = self::FILTER_AFTER_SNAPSHOT['pre_item'];
			}
		}

		if ( ( $is_item_path || $is_items_root ) && array_key_exists( 'pre_items', $snapshots ) ) {
			$snap = $snapshots['pre_items'];
			if ( is_array( $snap ) && self::path_clean_in_items( $snap, $item_index, $path ) ) {
				$last_clean_filter = self::FILTER_AFTER_SNAPSHOT['pre_items'];
			}
		}

		if ( array_key_exists( 'pre_request', $snapshots ) ) {
			$snap = $snapshots['pre_request'];
			if ( is_array( $snap ) && self::path_clean_in_payload( $snap, $path ) ) {
				$last_clean_filter = self::FILTER_AFTER_SNAPSHOT['pre_request'];
			}
		}

		return $last_clean_filter;
	}

	/**
	 * Whether a destination snapshot is clean for a given destination-field path.
	 *
	 * @param array  $destination Destination array.
	 * @param string $path        Violation path (e.g. "rate.destination.country").
	 *
	 * @return bool
	 */
	private static function path_clean_in_destination( array $destination, string $path ): bool {
		foreach ( self::check_destination( $destination ) as $v ) {
			if ( $v['path'] === $path ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether an items-array snapshot is clean for a given item-path.
	 *
	 * @param array    $items      Items array (numerically indexed).
	 * @param int|null $item_index Item index extracted from the path, if any.
	 * @param string   $path       Violation path.
	 *
	 * @return bool
	 */
	private static function path_clean_in_items( array $items, ?int $item_index, string $path ): bool {
		if ( null === $item_index ) {
			return true;
		}
		if ( ! isset( $items[ $item_index ] ) ) {
			return true;
		}
		foreach ( self::check_item( $items[ $item_index ], $item_index ) as $v ) {
			if ( $v['path'] === $path ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a full-payload snapshot is clean for a given path.
	 *
	 * @param array  $payload Payload snapshot.
	 * @param string $path    Violation path.
	 *
	 * @return bool
	 */
	private static function path_clean_in_payload( array $payload, string $path ): bool {
		foreach ( self::collect_violations( $payload ) as $v ) {
			if ( $v['path'] === $path ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Log a violation as a single Logger::error call.
	 *
	 * @param array       $violation Violation entry.
	 * @param string|null $filter    Filter hook name, or null if not attributable.
	 */
	private static function log( array $violation, ?string $filter ): void {
		$message = sprintf(
			'ShipStation checkout rates payload validation failed: %s.',
			$violation['message']
		);
		if ( null !== $filter ) {
			$message .= sprintf( ' Likely cause: filter "%s".', $filter );
		}

		Logger::error(
			$message,
			array(
				'field'  => $violation['path'],
				'filter' => $filter,
			)
		);
	}
}
