<?php
/**
 * ShipStation REST API Base Controller file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation\API\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WooCommerce\Shipping\ShipStation\Logger;

/**
 * API_Controller class.
 */
class API_Controller {
	/**
	 * Log something.
	 *
	 * @param string $message Log message.
	 */
	public function log( $message ) {
		Logger::debug( $message );
	}
}
