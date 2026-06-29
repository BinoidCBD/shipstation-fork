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
	 * Transient key holding the stale fallback copy of the diagnostics payload.
	 *
	 * Served to late readers while a single worker refreshes the fresh copy, so
	 * a ShipStation catch-up burst on a cold/expired cache cannot stampede the
	 * rebuild (see FORK-REGRESSION-AUDIT-5.2.0.md).
	 *
	 * @since 5.2.0-forked
	 *
	 * @var string
	 */
	public const DETAILS_STALE_TRANSIENT_KEY = 'wc_shipstation_diagnostics_details_stale';

	/**
	 * Object-cache key for the single-flight refresh lock.
	 *
	 * @since 5.2.0-forked
	 *
	 * @var string
	 */
	public const DETAILS_LOCK_KEY = 'wc_shipstation_diagnostics_details_lock';

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
	 * The payload is built from direct PHP/WP/WC reads (no system_status REST
	 * dispatch) and cached. Reads go through a stale-while-revalidate cache with
	 * a single-flight refresh lock so a ShipStation catch-up burst on a cold or
	 * expired cache cannot stampede the rebuild (each rebuild reads every active
	 * plugin's header from disk). The cache is invalidated when plugins are
	 * activated or deactivated; set the TTL filter to 0 to bypass caching.
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
		$filtered = apply_filters( 'woocommerce_shipstation_diagnostics_controller_get_details', $site_info, $request );
		return new WP_REST_Response( $filtered, 200 );
	}

	/**
	 * Read the cached payload, with stale-while-revalidate and a
	 * `wp_cache_add()` single-flight lock to prevent a thundering herd during
	 * ShipStation catch-up bursts (a diagnostics GET is paired with every
	 * shipment webhook — see GH-9 / FORK-REGRESSION-AUDIT-5.2.0.md).
	 *
	 * On hosts with a persistent object cache (e.g. WP Engine + Memcached) the
	 * lock is atomic across PHP workers. Without one it degrades to per-request
	 * scope and the pattern falls back to "one rebuild per TTL boundary" — still
	 * strictly better than an unguarded cache.
	 *
	 * @since 5.2.0-forked Re-applied after the 5.2.0 factory drop reverted PR #7.
	 *
	 * @param int $fresh_ttl TTL for the fresh transient, in seconds.
	 *
	 * @return array
	 */
	private function get_cached_site_info( int $fresh_ttl ): array {
		$fresh = get_transient( self::DETAILS_TRANSIENT_KEY );
		if ( is_array( $fresh ) ) {
			return $fresh;
		}

		$stale = get_transient( self::DETAILS_STALE_TRANSIENT_KEY );

		// Store a unique token as the lock value so we only release the lock if
		// we still own it. If build_site_info() outlives the 30s lock TTL,
		// another worker can acquire a new lock under the same key; without this
		// check we would delete that newer lock and reopen the stampede window.
		$token    = wp_generate_uuid4();
		$won_lock = wp_cache_add( self::DETAILS_LOCK_KEY, $token, '', 30 );

		if ( ! $won_lock && is_array( $stale ) ) {
			return $stale;
		}

		$site_info = $this->build_site_info();

		/**
		 * Filters the stale-fallback TTL (seconds) for the diagnostics response
		 * cache. Late readers see this copy while a single worker refreshes the
		 * fresh transient.
		 *
		 * @since 5.2.0-forked
		 *
		 * @param int $stale_ttl Default HOUR_IN_SECONDS.
		 */
		$stale_ttl = (int) apply_filters( 'woocommerce_shipstation_diagnostics_stale_ttl', HOUR_IN_SECONDS );

		set_transient( self::DETAILS_TRANSIENT_KEY, $site_info, $fresh_ttl );
		set_transient( self::DETAILS_STALE_TRANSIENT_KEY, $site_info, $stale_ttl );

		if ( $won_lock && wp_cache_get( self::DETAILS_LOCK_KEY ) === $token ) {
			wp_cache_delete( self::DETAILS_LOCK_KEY );
		}

		return $site_info;
	}

	/**
	 * Build the diagnostics payload from direct PHP/WP/WC reads.
	 *
	 * Extracted so the cache path and the cache-disabled path share one
	 * implementation. Reads environment values directly (no DB queries and no
	 * system_status REST dispatch); `get_active_plugins_string()` reads each
	 * active plugin's header from disk, which is why the read path is cached.
	 *
	 * @return array
	 */
	private function build_site_info(): array {
		return array(
			'source_details' => array(
				'plugin_version'      => WC_SHIPSTATION_VERSION,
				'woocommerce_version' => defined( 'WC_VERSION' ) ? esc_html( WC_VERSION ) : '',
				'php_version'         => esc_html( phpversion() ),
				'wordpress_version'   => esc_html( $GLOBALS['wp_version'] ?? '' ),
				'memory_limit'        => $this->get_memory_limit(),
				'active_plugins'      => $this->get_active_plugins_string(),
			),
		);
	}

	/**
	 * TTL (seconds) for the fresh diagnostics/details response cache.
	 *
	 * Filter `woocommerce_shipstation_diagnostics_cache_ttl`. Return 0 to
	 * disable caching entirely.
	 *
	 * @since 5.2.0-forked
	 *
	 * @return int
	 */
	private function get_diagnostics_cache_ttl(): int {
		return (int) apply_filters( 'woocommerce_shipstation_diagnostics_cache_ttl', 5 * MINUTE_IN_SECONDS );
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
		delete_transient( self::DETAILS_STALE_TRANSIENT_KEY );
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
