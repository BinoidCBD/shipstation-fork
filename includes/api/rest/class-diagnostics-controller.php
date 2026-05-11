<?php
/**
 * ShipStation REST API Diagnostics Controller file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation\API\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Automattic\WooCommerce\Utilities\RestApiUtil;

/**
 * Diagnostics_Controller class.
 */
class Diagnostics_Controller extends API_Controller {

	/**
	 * Namespace for the REST API
	 *
	 * @var string
	 */
	protected string $namespace = 'wc-shipstation/v1';

	/**
	 * REST base for the controller.
	 *
	 * @var string
	 */
	protected string $rest_base = 'diagnostics';

	/**
	 * Register the routes for the controller.
	 */
	public function register_routes(): void {

		// Register the endpoint for retrieving site details.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/details',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_details' ),
				'permission_callback' => array( $this, 'check_get_permission' ),
			)
		);

		// Register the endpoint for site validation.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_site' ),
				'permission_callback' => array( $this, 'check_creatable_permission' ),
			)
		);
	}

	/**
	 * REST API permission callback.
	 *
	 * @return boolean
	 */
	public function check_get_permission(): bool {
		/**
		 * Filters whether the current user has permissions to manage WooCommerce.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $can_manage_wc Whether the user can manage WooCommerce.
		 */
		return apply_filters( 'wc_shipstation_user_can_manage_wc', wc_rest_check_manager_permissions( 'system_status', 'read' ) );
	}

	/**
	 * REST API permission callback.
	 *
	 * @return boolean
	 */
	public function check_creatable_permission(): bool {
		/**
		 * Filters whether the current user has permissions to manage WooCommerce.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $can_manage_wc Whether the user can manage WooCommerce.
		 */
		return apply_filters( 'wc_shipstation_user_can_manage_wc', wc_rest_check_manager_permissions( 'system_status', 'create' ) );
	}

	/**
	 * Retrieve the site information.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_details( WP_REST_Request $request ): WP_REST_Response {
		$report      = wc_get_container()->get( RestApiUtil::class )->get_endpoint_data( '/wc/v3/system_status' );
		$environment = isset( $report['environment'] ) && is_array( $report['environment'] ) ? $report['environment'] : array();

		if ( ! empty( $report['active_plugins'] ) && is_array( $report['active_plugins'] ) ) {
			$active_plugins = array_map(
				function ( $plugin_info ) {

					$info = array();

					if ( ! empty( $plugin_info['name'] ) ) {
						$info[] = $plugin_info['name'];
					}

					if ( ! empty( $plugin_info['version'] ) ) {
						$info[] = $plugin_info['version'];
					}

					return implode( ' ', $info );
				},
				$report['active_plugins']
			);
		} else {
			$active_plugins = array();
		}

		// Prepare the response data.
		$site_info = array(
			'source_details' => array(
				'plugin_version'      => WC_SHIPSTATION_VERSION,
				'woocommerce_version' => isset( $environment['version'] ) ? esc_html( $environment['version'] ) : '',
				'php_version'         => isset( $environment['php_version'] ) ? esc_html( $environment['php_version'] ) : '',
				'wordpress_version'   => isset( $environment['wp_version'] ) ? esc_html( $environment['wp_version'] ) : '',
				'memory_limit'        => isset( $environment['wp_memory_limit'] ) ? esc_html( size_format( $environment['wp_memory_limit'] ) ) : '',
				'active_plugins'      => implode( ', ', $active_plugins ),
			),
		);

		/**
		 * Filters the site information.
		 *
		 * @param array           $site_info The site information.
		 * @param WP_REST_Request $request   The request object.
		 *
		 * @since 4.8.0
		 */
		return new WP_REST_Response( apply_filters( 'woocommerce_shipstation_diagnostics_controller_get_details', $site_info, $request ), 200 );
	}

	/**
	 * Validating the site.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function validate_site( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'valid' => true,
			),
			200
		);
	}
}
