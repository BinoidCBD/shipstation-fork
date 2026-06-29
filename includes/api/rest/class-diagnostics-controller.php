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

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Diagnostics_Controller class.
 */
class Diagnostics_Controller extends API_Controller {

	/**
	 * Transient key under which the diagnostics details payload is cached.
	 *
	 * Public so tests can reference the key instead of duplicating the literal.
	 *
	 * @since 5.1.0
	 *
	 * @var string
	 */
	public const DETAILS_TRANSIENT_KEY = 'wc_shipstation_diagnostics_details';

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
	 * REST API permission callback for GET /diagnostics/details.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return bool|WP_Error See API_Controller::check_namespace_permission().
	 */
	public function check_get_permission( WP_REST_Request $request ) {
		return $this->check_namespace_permission( $request, 'system_status', 'read' );
	}

	/**
	 * REST API permission callback for POST /diagnostics/validate.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return bool|WP_Error See API_Controller::check_namespace_permission().
	 */
	public function check_creatable_permission( WP_REST_Request $request ) {
		return $this->check_namespace_permission( $request, 'system_status', 'create' );
	}

	/**
	 * Retrieve the site information.
	 *
	 * Reads environment values directly from PHP/WP/WC constants and options to
	 * avoid dispatching the expensive WooCommerce system_status REST request on
	 * every call. The raw response is cached in a transient for 5 minutes and
	 * invalidated when plugins are activated or deactivated.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_details( WP_REST_Request $request ): WP_REST_Response {
		$cached = get_transient( self::DETAILS_TRANSIENT_KEY );

		if ( is_array( $cached ) ) {
			$site_info = $cached;
		} else {
			// Prepare the response data from direct PHP/WP/WC reads — no DB queries.
			$site_info = array(
				'source_details' => array(
					'plugin_version'      => WC_SHIPSTATION_VERSION,
					'woocommerce_version' => defined( 'WC_VERSION' ) ? esc_html( WC_VERSION ) : '',
					'php_version'         => esc_html( phpversion() ),
					'wordpress_version'   => esc_html( $GLOBALS['wp_version'] ?? '' ),
					'memory_limit'        => $this->get_memory_limit(),
					'active_plugins'      => $this->get_active_plugins_string(),
				),
			);

			set_transient( self::DETAILS_TRANSIENT_KEY, $site_info, 5 * MINUTE_IN_SECONDS );
		}

		/**
		 * Filters the site information.
		 *
		 * @param array           $site_info The site information.
		 * @param WP_REST_Request $request   The request object.
		 *
		 * @since 4.8.0
		 */
		$filtered = apply_filters( 'woocommerce_shipstation_diagnostics_controller_get_details', $site_info, $request );
		return new WP_REST_Response( $filtered, 200 );
	}

	/**
	 * Build the human-readable memory limit string.
	 *
	 * Mirrors WooCommerce's system_status semantics: the effective limit is the
	 * greater of WP_MEMORY_LIMIT and PHP's ini memory_limit, since WordPress
	 * raises the runtime limit to WP_MEMORY_LIMIT but never lowers it below the
	 * ini value. A non-positive result (e.g. ini memory_limit of -1) means
	 * unlimited and is reported as an empty string, matching the prior behavior.
	 *
	 * @since 5.1.0
	 *
	 * @return string
	 */
	private function get_memory_limit(): string {
		$wp_limit  = defined( 'WP_MEMORY_LIMIT' ) ? wp_convert_hr_to_bytes( (string) WP_MEMORY_LIMIT ) : 0;
		$ini_limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		$memory_bytes = max( $wp_limit, $ini_limit );

		return $memory_bytes > 0 ? esc_html( size_format( $memory_bytes ) ) : '';
	}

	/**
	 * Build a comma-separated "Name Version" string for all active plugins.
	 *
	 * Reads plugin file headers from disk — no DB queries.
	 * On multisite, network-active plugins are included.
	 *
	 * @since 5.1.0
	 *
	 * @return string
	 */
	private function get_active_plugins_string(): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_files = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network_active = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
			$plugin_files   = array_unique( array_merge( $plugin_files, $network_active ) );
		}

		$plugins = array();

		foreach ( $plugin_files as $plugin_file ) {
			// Skip invalid or stale entries (e.g. plugins deleted from disk but
			// still listed in the option) — mirrors core's
			// wp_get_active_and_valid_plugins() guard and prevents
			// file_get_contents() warnings from corrupting the JSON response.
			if ( 0 !== validate_file( $plugin_file ) || ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				continue;
			}

			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
			$info = array_filter( array( $data['Name'], $data['Version'] ) );

			if ( ! empty( $info ) ) {
				$plugins[] = implode( ' ', $info );
			}
		}

		return implode( ', ', $plugins );
	}

	/**
	 * Register cache-invalidation hooks.
	 *
	 * Called from REST_API_Loader::init() so hooks are active on admin requests
	 * (plugin activation/deactivation) as well as REST requests.
	 *
	 * Note: plugin updates fire neither activated_plugin nor deactivated_plugin,
	 * so updated version strings rely on the 5-minute transient TTL — this
	 * staleness window is accepted.
	 *
	 * @since 5.1.0
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'activated_plugin', array( __CLASS__, 'clear_cache' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'clear_cache' ) );
	}

	/**
	 * Delete the diagnostics transient cache.
	 *
	 * @since 5.1.0
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		delete_transient( self::DETAILS_TRANSIENT_KEY );
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
