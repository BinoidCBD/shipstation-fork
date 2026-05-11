<?php
/**
 * ShipStation Authentication Controller Class.
 *
 * Handles the display and management of authentication credentials.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

use WC_ShipStation_Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auth_Controller Class
 *
 * Manages authentication data display and WooCommerce API key generation.
 */
class Auth_Controller {

	/**
	 * Option name for storing WooCommerce API key ID.
	 *
	 * @var string
	 */
	const API_KEY_ID_OPTION = 'woocommerce_shipstation_api_key_id';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'wp_ajax_shipstation_get_auth_data', array( $this, 'ajax_get_auth_data' ) );
		add_action( 'wp_ajax_shipstation_generate_new_keys', array( $this, 'ajax_generate_new_keys' ) );

		if ( ! is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.WP.Capabilities.Unknown --- It's native capability from WooCommerce.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- No action taken, just checking tab.
		$is_setting_page           = ! empty( $_GET['tab'] ) && 'integration' === $_GET['tab'];
		$hide_notice               = get_option( 'wc_shipstation_hide_activate_notice', '' );
		$settings_notice_dismissed = get_user_meta( get_current_user_id(), 'dismissed_shipstation-setup_notice', true );

		if ( ! $is_setting_page && ( 'yes' === $hide_notice && $settings_notice_dismissed ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_footer', array( $this, 'render_auth_modal' ) );
	}

	/**
	 * Enqueue scripts and styles for auth display.
	 */
	public function enqueue_scripts() {
		wp_enqueue_style( 'shipstation-auth-display', plugins_url( 'assets/css/auth-display.css', WC_SHIPSTATION_FILE ), array(), WC_SHIPSTATION_VERSION );

		wp_enqueue_script(
			'shipstation-auth-display',
			WC_SHIPSTATION_PLUGIN_URL . 'assets/js/auth-display.js',
			array(),
			WC_SHIPSTATION_VERSION,
			true
		);

		wp_localize_script(
			'shipstation-auth-display',
			'wc_shipstation_auth_params',
			array(
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'shipstation_auth_nonce' ),
				'copy_text'    => esc_html__( 'Copied!', 'woocommerce-shipstation-integration' ),
				'show_text'    => esc_html__( 'Show', 'woocommerce-shipstation-integration' ),
				'hide_text'    => esc_html__( 'Hide', 'woocommerce-shipstation-integration' ),
				'error_text'   => esc_html__( 'Error loading authentication data', 'woocommerce-shipstation-integration' ),
				'confirm_text' => esc_html__( 'Generating new REST API keys will disable your old keys. Connections using them will stop working until you update ShipStation. Continue?', 'woocommerce-shipstation-integration' ),
			)
		);
	}

	/**
	 * Generate or retrieve WooCommerce REST API credentials.
	 *
	 * @return array API credentials.
	 */
	private function maybe_create_api_credentials(): array {
		$api_key_id = get_option( self::API_KEY_ID_OPTION, false );

		if ( $api_key_id && $this->api_key_exists( $api_key_id ) ) {
			// If a valid API key pair already exists, return an empty array.
			// The consumer_key is stored as a hash and cannot be displayed.
			return array();
		}

		return $this->generate_api_credentials();
	}

	/**
	 * Check if API key exists by ID.
	 *
	 * @param int $api_key_id The API key ID.
	 *
	 * @return bool True if API key exists, false otherwise.
	 */
	private function api_key_exists( int $api_key_id ): bool {
		global $wpdb;

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT key_id FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d", $api_key_id ) );
		return ! empty( $exists );
	}

	/**
	 * Return the first admin user ID to assign as the API key owner.
	 *
	 * WooCommerce REST auth runs every request under the key owner's capabilities.
	 * Tying the key to the triggering user is unsafe: that user may later lose their
	 * role or be deleted, causing every REST endpoint to return rest_forbidden.
	 * Using the first administrator keeps the key stable.
	 *
	 * @return int
	 */
	private function get_admin_user_id(): int {
		// Try site-level administrators first (works on single site and multisite).
		$admins = get_users(
			array(
				'role'        => 'administrator',
				'number'      => 1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'fields'      => 'ID',
				'count_total' => false,
			)
		);

		if ( ! empty( $admins ) ) {
			return (int) $admins[0];
		}

		// On multisite, fall back to the first network super admin.
		// get_users() only queries site-level roles, so super admins who haven't
		// been explicitly added to this site won't appear in the query above.
		if ( is_multisite() ) {
			$super_admins = get_super_admins();
			$user         = ! empty( $super_admins ) ? get_user_by( 'login', $super_admins[0] ) : false;

			if ( $user ) {
				return $user->ID;
			}
		}

		return get_current_user_id();
	}

	/**
	 * Generate new WooCommerce REST API credentials.
	 *
	 * @return array API credentials.
	 */
	private function generate_api_credentials(): array {
		global $wpdb;

		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();
		$table_name      = $wpdb->prefix . 'woocommerce_api_keys';

		$data = array(
			'user_id'         => $this->get_admin_user_id(),
			'description'     => __( 'ShipStation Integration', 'woocommerce-shipstation-integration' ),
			'permissions'     => 'read_write',
			'consumer_key'    => wc_api_hash( $consumer_key ),
			'consumer_secret' => $consumer_secret,
			'truncated_key'   => substr( $consumer_key, -7 ),
		);

		$result = $wpdb->insert(
			$table_name,
			$data,
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $result || $wpdb->insert_id <= 0 ) {
			Logger::error( 'Failed to insert API key into database. DB error: ' . $wpdb->last_error );
			return array();
		}

		$api_key_id = $wpdb->insert_id;
		update_option( self::API_KEY_ID_OPTION, $api_key_id );

		return array(
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
			'api_key_id'      => $api_key_id,
		);
	}

	/**
	 * Get all authentication data for display.
	 *
	 * @return array Authentication data array.
	 */
	private function get_auth_data(): array {
		$api_credentials = $this->maybe_create_api_credentials();

		$data = array(
			'site_url' => home_url(),
			'auth_key' => WC_ShipStation_Integration::$auth_key,
		);

		if ( isset( $api_credentials['consumer_secret'] ) && isset( $api_credentials['consumer_key'] ) ) {
			$data['consumer_key']    = $api_credentials['consumer_key'];
			$data['consumer_secret'] = $api_credentials['consumer_secret'];
		}

		return $data;
	}

	/**
	 * Generate new API keys, invalidating the old ones.
	 *
	 * @return array New API credentials.
	 */
	private function generate_new_keys(): array {
		$old_api_key_id = get_option( self::API_KEY_ID_OPTION, false );

		// Generate new credentials first. Only delete the old key if the DB write
		// succeeds — a failure must not leave the user without any valid credentials.
		$new_credentials = $this->generate_api_credentials();

		if ( ! empty( $new_credentials['api_key_id'] ) && $old_api_key_id ) {
			$this->delete_api_credentials_by_id( (int) $old_api_key_id );
		}

		return $new_credentials;
	}

	/**
	 * Delete API credentials from WooCommerce API keys table by ID.
	 *
	 * @param int $api_key_id The API key ID to delete.
	 */
	private function delete_api_credentials_by_id( int $api_key_id ): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'woocommerce_api_keys';

		$result = $wpdb->delete(
			$table_name,
			array( 'key_id' => $api_key_id ),
			array( '%d' )
		);

		if ( false === $result ) {
			Logger::error( 'Failed to delete API key ID ' . $api_key_id . ' from database. DB error: ' . $wpdb->last_error );
		}
	}

	/**
	 * AJAX handler for getting authentication data.
	 */
	public function ajax_get_auth_data() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'shipstation_auth_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed', 'woocommerce-shipstation-integration' ) );
		}

		// phpcs:ignore WordPress.WP.Capabilities.Unknown --- It's native capability from WooCommerce.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'woocommerce-shipstation-integration' ) );
		}

		$auth_data = $this->get_auth_data();

		// If no new credentials were returned, check whether it's because they already
		// exist (normal: credentials are only shown once) or because the DB write failed.
		if ( ! isset( $auth_data['consumer_key'] ) ) {
			$api_key_id = get_option( self::API_KEY_ID_OPTION, false );
			if ( ! $api_key_id || ! $this->api_key_exists( (int) $api_key_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Failed to generate API credentials. Please try again or contact your hosting provider if the issue persists.', 'woocommerce-shipstation-integration' ) ) );
				return;
			}
		}

		wp_send_json_success( $auth_data );
	}

	/**
	 * AJAX handler for generating new API keys.
	 */
	public function ajax_generate_new_keys() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'shipstation_auth_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed', 'woocommerce-shipstation-integration' ) );
		}

		// phpcs:ignore WordPress.WP.Capabilities.Unknown --- It's native capability from WooCommerce.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'woocommerce-shipstation-integration' ) );
		}

		$new_credentials = $this->generate_new_keys();

		if ( empty( $new_credentials ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to generate API credentials. Please try again or contact your hosting provider if the issue persists.', 'woocommerce-shipstation-integration' ) ) );
			return;
		}

		$auth_data = array(
			'consumer_key'    => $new_credentials['consumer_key'],
			'consumer_secret' => $new_credentials['consumer_secret'],
			'auth_key'        => WC_ShipStation_Integration::$auth_key,
			'site_url'        => home_url(),
		);

		wp_send_json_success( $auth_data );
	}

	/**
	 * Render the authentication modal HTML.
	 */
	public function render_auth_modal() {
		wc_get_template(
			'auth-modal.php',
			array(),
			'',
			WC_SHIPSTATION_ABSPATH . 'templates/'
		);
	}

	/**
	 * Get the "View Authentication Data" button HTML.
	 *
	 * @return string Button HTML.
	 */
	public static function get_auth_button_html(): string {
		return sprintf(
			'<button type="button" id="shipstation-view-auth" class="button button-primary">%s</button>',
			esc_html__( 'View Authentication Data', 'woocommerce-shipstation-integration' )
		);
	}
}
