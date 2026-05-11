<?php
/**
 * Logger class.
 *
 * Centralized logger for the ShipStation integration.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

use WC_Logger;
use WC_ShipStation_Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Logger class.
 */
class Logger {
	/**
	 * WC Logger instance.
	 *
	 * @var WC_Logger|null
	 */
	private static ?WC_Logger $logger = null;

	/**
	 * Is debug enabled.
	 *
	 * @var bool
	 */
	private static bool $is_debug_enabled = false;

	/**
	 * Constructor.
	 */
	private function __construct() {
		// No need to instantiate this class.
	}

	/**
	 * Initialize the logger.
	 *
	 * @return void
	 */
	private static function init(): void {
		if ( is_null( self::$logger ) ) {
			self::$logger = wc_get_logger();

			self::$is_debug_enabled = WC_ShipStation_Integration::$logging_enabled;
		}
	}

	/**
	 * Merge default data with provided data.
	 *
	 * @param array $data Data to merge.
	 *
	 * @return array
	 */
	private static function get_merged_data( array $data ): array {
		$default_data = array( 'source' => 'shipstation' );

		return wp_parse_args( $data, $default_data );
	}

	/**
	 * Add a debug log entry if logging is enabled.
	 *
	 * @param string $message Message to log.
	 * @param array  $data    Additional contextual data (e.g., array( 'source' => 'shipstation' ) ).
	 *
	 * @return void
	 */
	public static function debug( string $message, array $data = array() ): void {
		self::init();

		if ( ! self::$is_debug_enabled ) {
			return;
		}

		self::$logger->debug( $message, self::get_merged_data( $data ) );
	}

	/**
	 * Handles error logging or reporting with a message and optional associated data.
	 *
	 * @param string $message The error message to log or report.
	 * @param array  $data    Optional additional data relevant to the error.
	 *
	 * @return void
	 */
	public static function error( string $message, array $data = array() ): void {
		self::init();

		self::$logger->error( $message, self::get_merged_data( $data ) );
	}
}
