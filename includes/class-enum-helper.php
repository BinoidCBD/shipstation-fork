<?php
/**
 * Enum compatibility helper.
 *
 * @package WC_ShipStation
 */

declare( strict_types=1 );

namespace WooCommerce\Shipping\ShipStation;

use Automattic\WooCommerce\Enums\OrderInternalStatus;
use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Enums\ProductType;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Resolves WooCommerce order-status and product-type enum values, falling back
 * to the equivalent slug when the enum class is not available.
 *
 * The `Automattic\WooCommerce\Enums` namespace was added to WooCommerce core
 * over several releases (`OrderInternalStatus` and `OrderStatus` in 9.5.0,
 * `ProductType` in 9.7.0). WooCommerce constructs the integration during its own
 * `init`, so on an older WooCommerce that does not ship a referenced enum the
 * dereference is a fatal error that takes down every page of the site, not just
 * the integration (SHIPSTN-152).
 *
 * Each enum value is the exact status/type slug the plugin used before adopting
 * the enums (for example `OrderInternalStatus::COMPLETED === 'wc-completed'`), so
 * returning the slug when the class is absent is behavior-identical while keeping
 * the plugin on core's enums wherever they exist. `class_exists()` autoloads by
 * default and the Jetpack Autoloader returns false for a class it cannot resolve
 * rather than fataling, so the lookup is safe on old WooCommerce.
 */
class Enum_Helper {

	/**
	 * Per-request memo of resolved enum values, keyed by `Class::CASE`. A string
	 * is the resolved enum value; null means the enum is unavailable on this
	 * WooCommerce. Whether an enum class exists cannot change within a request, so
	 * this caches both outcomes and, on old WooCommerce, avoids re-invoking the
	 * autoloader on every call (class_exists() re-runs it for an absent class).
	 *
	 * @var array<string, string|null>
	 */
	private static $resolved = array();

	/**
	 * Resolve an enum constant value, falling back to a slug when the enum class
	 * or the constant is unavailable on the active WooCommerce version.
	 *
	 * @param string $enum_class Fully-qualified enum class name.
	 * @param string $enum_case  Constant name on the enum, e.g. 'COMPLETED'.
	 * @param string $fallback   Slug to return when the enum is unavailable.
	 * @return string
	 */
	public static function value( string $enum_class, string $enum_case, string $fallback ): string {
		$const = $enum_class . '::' . $enum_case;

		if ( ! array_key_exists( $const, self::$resolved ) ) {
			$resolved = null;

			if ( class_exists( $enum_class ) && defined( $const ) ) {
				$candidate = constant( $const );

				// WooCommerce declares these as string-valued class constants, so
				// this holds today. Guard the type anyway: this helper exists to
				// never fatal while resolving an enum, and a non-string value (a
				// native enum case, say, if WooCommerce ever converts) would throw
				// on a string cast.
				if ( is_string( $candidate ) ) {
					$resolved = $candidate;
				}
			}

			self::$resolved[ $const ] = $resolved;
		}

		// The fallback is applied per call, never cached, so callers may pass
		// different fallbacks for the same absent enum.
		return null === self::$resolved[ $const ] ? $fallback : self::$resolved[ $const ];
	}

	/**
	 * OrderInternalStatus::PENDING, or its 'wc-pending' slug.
	 *
	 * @return string
	 */
	public static function internal_pending(): string {
		return self::value( OrderInternalStatus::class, 'PENDING', 'wc-pending' );
	}

	/**
	 * OrderInternalStatus::PROCESSING, or its 'wc-processing' slug.
	 *
	 * @return string
	 */
	public static function internal_processing(): string {
		return self::value( OrderInternalStatus::class, 'PROCESSING', 'wc-processing' );
	}

	/**
	 * OrderInternalStatus::ON_HOLD, or its 'wc-on-hold' slug.
	 *
	 * @return string
	 */
	public static function internal_on_hold(): string {
		return self::value( OrderInternalStatus::class, 'ON_HOLD', 'wc-on-hold' );
	}

	/**
	 * OrderInternalStatus::COMPLETED, or its 'wc-completed' slug.
	 *
	 * @return string
	 */
	public static function internal_completed(): string {
		return self::value( OrderInternalStatus::class, 'COMPLETED', 'wc-completed' );
	}

	/**
	 * OrderInternalStatus::CANCELLED, or its 'wc-cancelled' slug.
	 *
	 * @return string
	 */
	public static function internal_cancelled(): string {
		return self::value( OrderInternalStatus::class, 'CANCELLED', 'wc-cancelled' );
	}

	/**
	 * OrderInternalStatus::REFUNDED, or its 'wc-refunded' slug.
	 *
	 * @return string
	 */
	public static function internal_refunded(): string {
		return self::value( OrderInternalStatus::class, 'REFUNDED', 'wc-refunded' );
	}

	/**
	 * OrderInternalStatus::FAILED, or its 'wc-failed' slug.
	 *
	 * @return string
	 */
	public static function internal_failed(): string {
		return self::value( OrderInternalStatus::class, 'FAILED', 'wc-failed' );
	}

	/**
	 * OrderStatus::PENDING, or its 'pending' slug.
	 *
	 * @return string
	 */
	public static function status_pending(): string {
		return self::value( OrderStatus::class, 'PENDING', 'pending' );
	}

	/**
	 * OrderStatus::ON_HOLD, or its 'on-hold' slug.
	 *
	 * @return string
	 */
	public static function status_on_hold(): string {
		return self::value( OrderStatus::class, 'ON_HOLD', 'on-hold' );
	}

	/**
	 * OrderStatus::CANCELLED, or its 'cancelled' slug.
	 *
	 * @return string
	 */
	public static function status_cancelled(): string {
		return self::value( OrderStatus::class, 'CANCELLED', 'cancelled' );
	}

	/**
	 * OrderStatus::REFUNDED, or its 'refunded' slug.
	 *
	 * @return string
	 */
	public static function status_refunded(): string {
		return self::value( OrderStatus::class, 'REFUNDED', 'refunded' );
	}

	/**
	 * OrderStatus::FAILED, or its 'failed' slug.
	 *
	 * @return string
	 */
	public static function status_failed(): string {
		return self::value( OrderStatus::class, 'FAILED', 'failed' );
	}

	/**
	 * ProductType::SIMPLE, or its 'simple' slug.
	 *
	 * @return string
	 */
	public static function product_simple(): string {
		return self::value( ProductType::class, 'SIMPLE', 'simple' );
	}

	/**
	 * ProductType::VARIABLE, or its 'variable' slug.
	 *
	 * @return string
	 */
	public static function product_variable(): string {
		return self::value( ProductType::class, 'VARIABLE', 'variable' );
	}

	/**
	 * ProductType::GROUPED, or its 'grouped' slug.
	 *
	 * @return string
	 */
	public static function product_grouped(): string {
		return self::value( ProductType::class, 'GROUPED', 'grouped' );
	}

	/**
	 * ProductType::EXTERNAL, or its 'external' slug.
	 *
	 * @return string
	 */
	public static function product_external(): string {
		return self::value( ProductType::class, 'EXTERNAL', 'external' );
	}

	/**
	 * ProductType::VARIATION, or its 'variation' slug.
	 *
	 * @return string
	 */
	public static function product_variation(): string {
		return self::value( ProductType::class, 'VARIATION', 'variation' );
	}
}
