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
		$cache_ttl = $this->get_diagnostics_cache_ttl();
		$site_info = $cache_ttl > 0
			? $this->get_cached_site_info( $cache_ttl )
			: $this->build_site_info();

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
	 * Read the cached site_info, with stale-while-revalidate and a
	 * `wp_cache_add()` single-flight lock to prevent a thundering herd
	 * during ShipStation catch-up bursts on /wc/v3/system_status.
	 *
	 * On hosts with a persistent object cache (e.g. WP Engine + Memcached)
	 * the lock is atomic across PHP workers. Without one, it degrades to
	 * per-request scope and the pattern falls back to "one stampede per
	 * TTL boundary" — still strictly better than no cache.
	 *
	 * @param int $fresh_ttl TTL for the fresh transient, in seconds.
	 *
	 * @return array
	 */
	private function get_cached_site_info( int $fresh_ttl ): array {
		$fresh_key = 'wcss_diagnostics_details_fresh_v1';
		$stale_key = 'wcss_diagnostics_details_stale_v1';
		$lock_key  = 'wcss_diagnostics_lock_v1';

		$fresh = get_transient( $fresh_key );
		if ( is_array( $fresh ) ) {
			return $fresh;
		}

		$stale = get_transient( $stale_key );

		// Store a unique token as the lock value so we only release the
		// lock if we still own it. If build_site_info() outlives the 30s
		// lock TTL, another worker can acquire a new lock under the same
		// key; without this check we would delete that newer lock and
		// reopen the stampede window.
		$token    = wp_generate_uuid4();
		$won_lock = wp_cache_add( $lock_key, $token, '', 30 );

		if ( ! $won_lock && is_array( $stale ) ) {
			return $stale;
		}

		$site_info = $this->build_site_info();

		/**
		 * Filters the stale-fallback TTL (seconds) for the diagnostics
		 * response cache. Late readers see this copy while a single
		 * worker refreshes the fresh transient.
		 *
		 * @since 5.0.4-forked
		 *
		 * @param int $stale_ttl Default HOUR_IN_SECONDS.
		 */
		$stale_ttl = (int) apply_filters( 'woocommerce_shipstation_diagnostics_stale_ttl', HOUR_IN_SECONDS );

		set_transient( $fresh_key, $site_info, $fresh_ttl );
		set_transient( $stale_key, $site_info, $stale_ttl );

		if ( $won_lock && wp_cache_get( $lock_key ) === $token ) {
			wp_cache_delete( $lock_key );
		}

		return $site_info;
	}

	/**
	 * Build the base site_info payload from /wc/v3/system_status.
	 *
	 * Extracted so the cache path and the cache-disabled path share
	 * one implementation.
	 *
	 * @return array
	 */
	private function build_site_info(): array {
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

		return array(
			'source_details' => array(
				'plugin_version'      => WC_SHIPSTATION_VERSION,
				'woocommerce_version' => isset( $environment['version'] ) ? esc_html( $environment['version'] ) : '',
				'php_version'         => isset( $environment['php_version'] ) ? esc_html( $environment['php_version'] ) : '',
				'wordpress_version'   => isset( $environment['wp_version'] ) ? esc_html( $environment['wp_version'] ) : '',
				'memory_limit'        => isset( $environment['wp_memory_limit'] ) ? esc_html( size_format( $environment['wp_memory_limit'] ) ) : '',
				'active_plugins'      => implode( ', ', $active_plugins ),
			),
		);
	}

	/**
	 * TTL (seconds) for the fresh diagnostics/details response cache.
	 *
	 * Filter `woocommerce_shipstation_diagnostics_cache_ttl`. Return 0
	 * to disable caching entirely.
	 *
	 * @since 5.0.4-forked
	 *
	 * @return int
	 */
	private function get_diagnostics_cache_ttl(): int {
		return (int) apply_filters( 'woocommerce_shipstation_diagnostics_cache_ttl', 5 * MINUTE_IN_SECONDS );
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
