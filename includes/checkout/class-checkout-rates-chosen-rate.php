<?php
/**
 * Checkout Rates chosen rate class file.
 *
 * @package WC_ShipStation
 * @since 5.3.9
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the customer's rate when a package with ShipStation rates is reordered.
 *
 * @since 5.3.9
 */
final class Checkout_Rates_Chosen_Rate {

	/**
	 * Per package: whether the new rate list is the old one reordered.
	 *
	 * @var array<int|string, array{reordered: bool}>
	 */
	private static $snapshots = array();

	/**
	 * Hook the snapshot and the restore.
	 *
	 * @since 5.3.9
	 *
	 * @return void
	 */
	public static function register(): void {
		// Runs last so it sees the list WooCommerce compares.
		add_filter( 'woocommerce_shipping_packages', array( self::class, 'snapshot_rate_lists' ), PHP_INT_MAX );
		add_filter( 'woocommerce_shipping_chosen_method', array( self::class, 'restore_choice' ), 5, 3 );
	}

	/**
	 * Remove the hooks added by register().
	 *
	 * @since 5.3.9
	 *
	 * @return void
	 */
	public static function unregister(): void {
		remove_filter( 'woocommerce_shipping_packages', array( self::class, 'snapshot_rate_lists' ), PHP_INT_MAX );
		remove_filter( 'woocommerce_shipping_chosen_method', array( self::class, 'restore_choice' ), 5 );
	}

	/**
	 * Record whether each package's rate list was only reordered.
	 *
	 * @since 5.3.9
	 *
	 * @param mixed $packages Calculated packages.
	 *
	 * @return mixed The packages, unchanged.
	 */
	public static function snapshot_rate_lists( $packages ) {
		// WooCommerce replaces its previous list after each lookup, so start fresh.
		self::$snapshots = array();

		if ( ! is_array( $packages ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return $packages;
		}

		$previous_lists = WC()->session->get( 'previous_shipping_methods' );

		foreach ( $packages as $key => $package ) {
			$rate_ids = isset( $package['rates'] ) && is_array( $package['rates'] ) ? array_keys( $package['rates'] ) : array();
			$previous = is_array( $previous_lists ) && isset( $previous_lists[ $key ] ) && is_array( $previous_lists[ $key ] )
				? array_values( $previous_lists[ $key ] )
				: null;

			self::$snapshots[ $key ] = array(
				'reordered' => null !== $previous && $previous !== $rate_ids && self::same_set( $previous, $rate_ids ),
			);
		}

		return $packages;
	}

	/**
	 * Keep the customer's rate when only the order of the rates changed.
	 *
	 * @since 5.3.9
	 *
	 * @param mixed $default_rate  WooCommerce's default rate id.
	 * @param mixed $rates         The package's rates.
	 * @param mixed $chosen_method The customer's previous choice.
	 *
	 * @return mixed
	 */
	public static function restore_choice( $default_rate, $rates, $chosen_method ) {
		// An empty default is WooCommerce waiting for a full address.
		if ( ! is_string( $default_rate ) || '' === $default_rate || ! is_string( $chosen_method ) || $chosen_method === $default_rate ) {
			return $default_rate;
		}

		if ( ! is_array( $rates ) || ! isset( $rates[ $chosen_method ] ) || ! $rates[ $chosen_method ] instanceof \WC_Shipping_Rate ) {
			return $default_rate;
		}

		// Without ShipStation rates, the order is WooCommerce's business.
		if ( ! self::has_shipstation_rate( $rates ) ) {
			return $default_rate;
		}

		return self::was_reordered( $rates ) ? $chosen_method : $default_rate;
	}

	/**
	 * Whether the package offers a ShipStation rate.
	 *
	 * @param array $rates The package's rates.
	 *
	 * @return bool
	 */
	private static function has_shipstation_rate( array $rates ): bool {
		foreach ( $rates as $rate ) {
			if ( $rate instanceof \WC_Shipping_Rate && Checkout_Rates_Options::SHIPPING_METHOD_ID === $rate->get_method_id() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether these rates' package was only reordered since the last lookup.
	 *
	 * @param array $rates The package's rates.
	 *
	 * @return bool
	 */
	private static function was_reordered( array $rates ): bool {
		// Match rate objects, not ids. Packages can share rate ids.
		foreach ( WC()->shipping()->get_packages() as $key => $package ) {
			if ( isset( $package['rates'] ) && $package['rates'] === $rates ) {
				return ! empty( self::$snapshots[ $key ]['reordered'] );
			}
		}

		return false;
	}

	/**
	 * Whether two rate id lists hold the same ids.
	 *
	 * @param array $a Rate ids.
	 * @param array $b Rate ids.
	 *
	 * @return bool
	 */
	private static function same_set( array $a, array $b ): bool {
		if ( count( $a ) !== count( $b ) ) {
			return false;
		}

		sort( $a );
		sort( $b );

		return $a === $b;
	}

	/**
	 * Forget the recorded rate lists.
	 *
	 * @internal For tests.
	 *
	 * @since 5.3.9
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$snapshots = array();
	}
}
