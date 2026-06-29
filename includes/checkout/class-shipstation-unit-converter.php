<?php
/**
 * ShipStation Unit Converter class file.
 *
 * Converts WooCommerce store weight and dimension units into the set accepted by
 * the ShipStation Checkout Rates API (weight: oz, lbs, g, kg; dimensions: in, cm).
 * Uses integer-ratio tables for exact arithmetic — WC's native wc_get_weight /
 * wc_get_dimension are unsuitable because they silently misconvert unknown units
 * and accumulate floating-point drift on known ones.
 *
 * @package WC_ShipStation
 * @since 5.0.9
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless converter mapping WooCommerce units onto ShipStation-accepted ones.
 *
 * @since 5.0.9
 */
final class ShipStation_Unit_Converter {

	/**
	 * Dimension units accepted by the ShipStation Checkout Rates API.
	 *
	 * @var array<int, string>
	 */
	private const ACCEPTED_DIMENSION_UNITS = array( 'in', 'cm' );

	/**
	 * Weight units accepted by the ShipStation Checkout Rates API.
	 *
	 * @var array<int, string>
	 */
	private const ACCEPTED_WEIGHT_UNITS = array( 'oz', 'lbs', 'g', 'kg' );

	/**
	 * Convert a dimension value from the given store unit into a ShipStation-accepted unit.
	 *
	 * @since 5.0.9
	 *
	 * @param mixed  $value      Numeric value (int, float, or numeric string).
	 * @param string $store_unit Source unit identifier (e.g. 'mm', 'in').
	 *
	 * @return array{value: float, unit: string}|null Converted value and target unit, or null if the
	 *                                                input is non-numeric or the unit is unknown.
	 */
	public static function convert_dimension( $value, string $store_unit ): ?array {
		return self::convert( $value, $store_unit, self::dimension_units(), self::ACCEPTED_DIMENSION_UNITS );
	}

	/**
	 * Convert a weight value from the given store unit into a ShipStation-accepted unit.
	 *
	 * @since 5.0.9
	 *
	 * @param mixed  $value      Numeric value (int, float, or numeric string).
	 * @param string $store_unit Source unit identifier (e.g. 'mg', 'kg').
	 *
	 * @return array{value: float, unit: string}|null Converted value and target unit, or null if the
	 *                                                input is non-numeric or the unit is unknown.
	 */
	public static function convert_weight( $value, string $store_unit ): ?array {
		return self::convert( $value, $store_unit, self::weight_units(), self::ACCEPTED_WEIGHT_UNITS );
	}

	/**
	 * Shared conversion driver.
	 *
	 * @param mixed                                              $value          Numeric value.
	 * @param string                                             $store_unit     Source unit.
	 * @param array<string, array{family: string, to_base: int}> $units          Validated unit table.
	 * @param array<int, string>                                 $accepted_units ShipStation-accepted units.
	 *
	 * @return array{value: float, unit: string}|null
	 */
	private static function convert( $value, string $store_unit, array $units, array $accepted_units ): ?array {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		if ( ! isset( $units[ $store_unit ] ) ) {
			return null;
		}

		if ( in_array( $store_unit, $accepted_units, true ) ) {
			return array(
				'value' => (float) $value,
				'unit'  => $store_unit,
			);
		}

		$family    = $units[ $store_unit ]['family'];
		$source_tb = $units[ $store_unit ]['to_base'];

		$candidates = array();
		foreach ( $accepted_units as $accepted ) {
			if ( isset( $units[ $accepted ] ) && $units[ $accepted ]['family'] === $family ) {
				$candidates[ $accepted ] = $units[ $accepted ]['to_base'];
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		$target_unit = null;
		$target_tb   = null;
		foreach ( $candidates as $unit => $tb ) {
			if ( $tb <= $source_tb && ( null === $target_tb || $tb > $target_tb ) ) {
				$target_unit = $unit;
				$target_tb   = $tb;
			}
		}

		if ( null === $target_unit ) {
			// Fall back to the smallest accepted unit in the family.
			$target_unit = (string) array_keys( $candidates, min( $candidates ), true )[0];
			$target_tb   = $candidates[ $target_unit ];
		}

		return array(
			'value' => ( (float) $value ) * $source_tb / $target_tb,
			'unit'  => $target_unit,
		);
	}

	/**
	 * Built-in dimension table (extensible via filter).
	 *
	 * Imperial base = inch. Metric base = millimetre.
	 *
	 * @return array<string, array{family: string, to_base: int}>
	 */
	private static function dimension_units(): array {
		$units = array(
			'in' => array(
				'family'  => 'imperial',
				'to_base' => 1,
			),
			'ft' => array(
				'family'  => 'imperial',
				'to_base' => 12,
			),
			'yd' => array(
				'family'  => 'imperial',
				'to_base' => 36,
			),
			'mm' => array(
				'family'  => 'metric',
				'to_base' => 1,
			),
			'cm' => array(
				'family'  => 'metric',
				'to_base' => 10,
			),
			'dm' => array(
				'family'  => 'metric',
				'to_base' => 100,
			),
			'm'  => array(
				'family'  => 'metric',
				'to_base' => 1000,
			),
		);

		/**
		 * Filters the dimension unit table used by the ShipStation checkout-rates converter.
		 *
		 * Each entry is `array( 'family' => 'imperial'|'metric', 'to_base' => int )`,
		 * where `to_base` is the integer ratio to the family base (imperial: inch;
		 * metric: millimetre). Use this to register custom units; entries missing
		 * keys, with non-integer `to_base`, or an unknown family are silently dropped.
		 *
		 * @since 5.0.9
		 *
		 * @param array<string, array{family: string, to_base: int}> $units Dimension unit table.
		 */
		$units = apply_filters( 'woocommerce_shipstation_dimension_units', $units );

		return self::sanitize_units( $units );
	}

	/**
	 * Built-in weight table (extensible via filter).
	 *
	 * Imperial base = ounce. Metric base = milligram.
	 *
	 * @return array<string, array{family: string, to_base: int}>
	 */
	private static function weight_units(): array {
		$units = array(
			'oz'  => array(
				'family'  => 'imperial',
				'to_base' => 1,
			),
			'lbs' => array(
				'family'  => 'imperial',
				'to_base' => 16,
			),
			'st'  => array(
				'family'  => 'imperial',
				'to_base' => 224,
			),
			'mg'  => array(
				'family'  => 'metric',
				'to_base' => 1,
			),
			'g'   => array(
				'family'  => 'metric',
				'to_base' => 1000,
			),
			'kg'  => array(
				'family'  => 'metric',
				'to_base' => 1000000,
			),
			't'   => array(
				'family'  => 'metric',
				'to_base' => 1000000000,
			),
		);

		/**
		 * Filters the weight unit table used by the ShipStation checkout-rates converter.
		 *
		 * Each entry is `array( 'family' => 'imperial'|'metric', 'to_base' => int )`,
		 * where `to_base` is the integer ratio to the family base (imperial: ounce;
		 * metric: milligram). Use this to register custom units; entries missing
		 * keys, with non-integer `to_base`, or an unknown family are silently dropped.
		 *
		 * @since 5.0.9
		 *
		 * @param array<string, array{family: string, to_base: int}> $units Weight unit table.
		 */
		$units = apply_filters( 'woocommerce_shipstation_weight_units', $units );

		return self::sanitize_units( $units );
	}

	/**
	 * Drop malformed table entries so third-party filters cannot break checkout rates.
	 *
	 * @param mixed $units Possibly-mutated unit table from the filter pipeline.
	 *
	 * @return array<string, array{family: string, to_base: int}>
	 */
	private static function sanitize_units( $units ): array {
		if ( ! is_array( $units ) ) {
			return array();
		}

		$valid = array();
		foreach ( $units as $unit => $entry ) {
			if ( ! is_string( $unit ) || '' === $unit ) {
				continue;
			}
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( ! isset( $entry['family'], $entry['to_base'] ) ) {
				continue;
			}
			if ( ! in_array( $entry['family'], array( 'imperial', 'metric' ), true ) ) {
				continue;
			}
			if ( ! is_int( $entry['to_base'] ) || $entry['to_base'] <= 0 ) {
				continue;
			}
			$valid[ $unit ] = array(
				'family'  => $entry['family'],
				'to_base' => $entry['to_base'],
			);
		}

		return $valid;
	}
}
