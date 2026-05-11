<?php
/**
 * Class WC_ShipStation_Integration file.
 *
 * @package WC_ShipStation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WooCommerce\Shipping\ShipStation\Order_Util;
use Automattic\WooCommerce\Enums\OrderInternalStatus;
use WooCommerce\Shipping\ShipStation\Logger;
use WooCommerce\Shipping\ShipStation\Auth_Controller;

/**
 * WC_ShipStation_Integration Class
 */
class WC_ShipStation_Integration extends WC_Integration {

	/**
	 * Authorization key for ShipStation API.
	 *
	 * @var string
	 */
	public static $auth_key = null;

	/**
	 * Export statuses.
	 *
	 * @var array
	 */
	public static $export_statuses = array();

	/**
	 * Flag for logging feature.
	 * `true` means log feature is on.
	 *
	 * @var boolean
	 */
	public static $logging_enabled = true;

	/**
	 * Shipment status.
	 *
	 * @var string
	 */
	public static $shipped_status = null;

	/**
	 * Gift enable flag.
	 *
	 * @var boolean
	 */
	public static $gift_enabled = false;

	/**
	 * Status mapping.
	 *
	 * @var array
	 */
	public static $status_mapping = array();

	/**
	 * ShipStation status for awaiting payment.
	 *
	 * @var string
	 */
	public const AWAITING_PAYMENT_STATUS = 'AwaitingPayment';

	/**
	 * ShipStation status for awaiting shipment.
	 *
	 * @var string
	 */
	public const AWAITING_SHIPMENT_STATUS = 'AwaitingShipment';

	/**
	 * ShipStation status for on-hold.
	 *
	 * @var string
	 */
	public const ON_HOLD_STATUS = 'OnHold';

	/**
	 * ShipStation status for completed.
	 *
	 * @var string
	 */
	public const COMPLETED_STATUS = 'Completed';

	/**
	 * ShipStation status for Cancelled.
	 *
	 * @var string
	 */
	public const CANCELLED_STATUS = 'Cancelled';

	/**
	 * ShipStation status for Payment Cancelled.
	 *
	 * @var string
	 */
	public const PAYMENT_CANCELLED_STATUS = 'PaymentCancelled';

	/**
	 * ShipStation status for Payment Failed.
	 *
	 * @var string
	 */
	public const PAYMENT_FAILED_STATUS = 'PaymentFailed';

	/**
	 * ShipStation status for Paid.
	 *
	 * @var string
	 */
	public const PAID_STATUS = 'Paid';

	/**
	 * Order meta keys.
	 *
	 * @var array
	 */
	public static array $order_meta_keys = array(
		'is_gift'      => 'shipstation_is_gift',
		'gift_message' => 'shipstation_gift_message',
	);

	/**
	 * WooCommerce status prefix.
	 *
	 * @var string
	 */
	public static $wc_status_prefix = 'wc-';

	/**
	 * Stores logger class.
	 *
	 * @var WC_Logger
	 */
	private static $log = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id                 = 'shipstation';
		$this->method_title       = __( 'ShipStation', 'woocommerce-shipstation-integration' );
		$this->method_description = __( 'ShipStation allows you to retrieve &amp; manage orders, then print labels &amp; packing slips with ease.', 'woocommerce-shipstation-integration' );

		if ( ! get_option( 'woocommerce_shipstation_auth_key', false ) ) {
			update_option( 'woocommerce_shipstation_auth_key', $this->generate_key() );
		}

		// Initialize auth display functionality.
		$this->init_auth_display();

		// Load admin form.
		$this->init_form_fields();

		// Load settings.
		$this->init_settings();

		self::$auth_key        = get_option( 'woocommerce_shipstation_auth_key', false );
		self::$export_statuses = $this->get_option( 'export_statuses', array( OrderInternalStatus::PROCESSING, OrderInternalStatus::ON_HOLD, OrderInternalStatus::COMPLETED, OrderInternalStatus::CANCELLED ) );
		self::$logging_enabled = 'yes' === $this->get_option( 'logging_enabled', 'yes' );
		self::$shipped_status  = $this->get_option( 'shipped_status', OrderInternalStatus::COMPLETED );
		self::$gift_enabled    = 'yes' === $this->get_option( 'gift_enabled', 'no' );
		self::$status_mapping  = array(
			self::AWAITING_PAYMENT_STATUS  => $this->get_option( self::AWAITING_PAYMENT_STATUS . '_status' ),
			self::AWAITING_SHIPMENT_STATUS => $this->get_option( self::AWAITING_SHIPMENT_STATUS . '_status' ),
			self::ON_HOLD_STATUS           => $this->get_option( self::ON_HOLD_STATUS . '_status' ),
			self::COMPLETED_STATUS         => $this->get_option( self::COMPLETED_STATUS . '_status' ),
			self::CANCELLED_STATUS         => $this->get_option( self::CANCELLED_STATUS . '_status' ),
		);

		// Force saved .
		$this->settings['auth_key'] = self::$auth_key;

		// Hooks.
		add_action( 'woocommerce_update_options_integration_shipstation', array( $this, 'update_shipstation_options' ) );
		add_filter( 'woocommerce_subscriptions_renewal_order_meta_query', array( $this, 'subscriptions_renewal_order_meta_query' ), 10, 4 );
		add_action( 'wp_loaded', array( $this, 'hide_notices' ) );
		add_filter( 'woocommerce_translations_updates_for_woocommerce_shipstation_integration', '__return_true' );
		add_action( 'woocommerce_shipstation_get_orders_before_process_request', array( $this, 'maybe_update_api_mode' ), 10, 1 );
		add_action( 'woocommerce_shipstation_get_orders_before_process_request', array( $this, 'maybe_save_status_mapping' ), 15, 1 );

		$hide_notice               = get_option( 'wc_shipstation_hide_activate_notice', '' );
		$settings_notice_dismissed = get_user_meta( get_current_user_id(), 'dismissed_shipstation-setup_notice', true );

		// phpcs:ignore WordPress.WP.Capabilities.Unknown --- It's native capability from WooCommerce
		if ( current_user_can( 'manage_woocommerce' ) && ( 'yes' !== $hide_notice && ! $settings_notice_dismissed ) ) {
			if ( ! isset( $_GET['wc-shipstation-hide-notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended --- No need to use nonce as no DB operation
				add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
				add_action( 'admin_notices', array( $this, 'settings_notice' ) );
			}
		}

		add_filter( 'woocommerce_order_query_args', array( $this, 'add_custom_query_vars_for_hpos' ), 10, 1 );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'add_custom_query_vars_for_cpt' ), 10, 2 );
		add_filter( 'woocommerce_shipstation_diagnostics_controller_get_details', array( $this, 'add_more_diagnostics_details' ), 10 );
	}

	/**
	 * Refresh status mapping after being updated.
	 */
	public function refresh_status_mapping() {
		self::$status_mapping = array(
			self::AWAITING_PAYMENT_STATUS  => $this->get_option( self::AWAITING_PAYMENT_STATUS . '_status' ),
			self::AWAITING_SHIPMENT_STATUS => $this->get_option( self::AWAITING_SHIPMENT_STATUS . '_status' ),
			self::ON_HOLD_STATUS           => $this->get_option( self::ON_HOLD_STATUS . '_status' ),
			self::COMPLETED_STATUS         => $this->get_option( self::COMPLETED_STATUS . '_status' ),
			self::CANCELLED_STATUS         => $this->get_option( self::CANCELLED_STATUS . '_status' ),
		);
	}

	/**
	 * Get REST API setting fields.
	 * These fields will be available only if the REST API is being used instead of XML API.
	 *
	 * @return array
	 */
	public function get_rest_api_setting_fields() {
		return array(
			'api_mode',
			'status_mode',
			self::AWAITING_PAYMENT_STATUS . '_status',
			self::AWAITING_SHIPMENT_STATUS . '_status',
			self::ON_HOLD_STATUS . '_status',
			self::COMPLETED_STATUS . '_status',
			self::CANCELLED_STATUS . '_status',
		);
	}

	/**
	 * Update options for ShipStation settings.
	 * This method is needed for `woocommerce_update_options_integration_shipstation` action hook.
	 * `WC_Integration::process_admin_options()` cannot be used directly to that action hook as it return value and PHPStan won't allow it.
	 */
	public function update_shipstation_options() {
		$this->process_admin_options();
	}

	/**
	 * Initialize the authentication display functionality.
	 */
	private function init_auth_display() {
		if ( is_admin() && class_exists( 'WooCommerce\Shipping\ShipStation\Auth_Controller' ) ) {
			new Auth_Controller();
		}
	}

	/**
	 * Update the status mode and the status mapping fields if the plugin is a fresh installed.
	 *
	 * @param array $request_params Request parameters.
	 */
	public function maybe_update_status_mode( $request_params ) {
		// Need to separate the condition for optimization as `check_shipstation_has_exported_orders()` has more complex database calling.
		if ( ! empty( $this->get_option( 'status_mode', '' ) ) ) {
			return;
		}

		if ( $this->check_shipstation_has_exported_orders() && ! empty( $request_params['status_mapping'] ) ) {
			return;
		}

		$log_info = array();
		$this->update_option( 'status_mode', 'plugin' );
		$log_info['status_mode'] = 'plugin';

		$shipstation_statuses = array_keys( self::$status_mapping );

		foreach ( $shipstation_statuses as $status ) {
			$this->update_option( $status . '_status', $this->form_fields[ $status . '_status' ]['default'] );
			$log_info[ $status . '_status' ] = $this->form_fields[ $status . '_status' ]['default'];
		}

		$this->refresh_status_mapping();

		Logger::debug( 'Mapping the status for fresh install', $log_info );
	}

	/**
	 * Check if the plugin has been used to export the orders.
	 *
	 * @return bool
	 */
	public function check_shipstation_has_exported_orders() {
		$orders = wc_get_orders(
			array(
				'shipstation_exported' => 1,
				'limit'                => 1,
				'orderby'              => 'modified',
				'order'                => 'DESC',
				'return'               => 'ids',
			)
		);

		return ( ! empty( $orders ) && is_array( $orders ) );
	}

	/**
	 * Update API Mode.
	 *
	 * @param array $request_params Request parameters.
	 */
	public function maybe_update_api_mode( $request_params ) {
		$api_mode = $this->get_option( 'api_mode', '' );

		if ( 'REST' === $api_mode ) {
			return;
		}

		$this->update_option( 'api_mode', 'REST' );
		$this->init_form_fields();
		$this->maybe_update_status_mode( $request_params );
	}

	/**
	 * Save status mapping.
	 *
	 * @param array $request_params Request parameter.
	 */
	public function maybe_save_status_mapping( $request_params ) {
		if ( empty( $request_params['status_mapping'] ) ) {
			return;
		}

		$mapping_mode = $this->get_option( 'status_mode', '' );

		if ( 'plugin' === $mapping_mode ) {
			return;
		}

		$log_info       = array();
		$status_mapping = is_array( $request_params['status_mapping'] ) ? $request_params['status_mapping'] : array( $request_params['status_mapping'] );

		foreach ( $status_mapping as $status_parameter ) {
			$statuses = explode( ':', $status_parameter );

			if ( 2 !== count( $statuses ) ) {
				continue;
			}

			$wc_statuses = explode( ',', strtolower( $statuses[0] ) );
			$ss_status   = $statuses[1];

			if ( ! isset( $this->form_fields[ $ss_status . '_status' ] ) ) {
				continue;
			}

			$wc_statuses = array_map(
				function ( $status ) {
					return self::$wc_status_prefix . $status;
				},
				$wc_statuses
			);

			$this->update_option( $ss_status . '_status', $wc_statuses );
			$log_info[ $ss_status . '_status' ] = $wc_statuses;
		}

		// Update the status mode only if the status_mode still empty.
		if ( empty( $mapping_mode ) ) {
			$this->update_option( 'status_mode', 'plugin' );
			$log_info['status_mode'] = 'plugin';
		}

		$this->refresh_status_mapping();

		Logger::debug( 'Status has been mapped', $log_info );
	}

	/**
	 * Handle a custom variable query var to get orders with the 'order_number' meta for HPOS.
	 *
	 * @param array $query_vars - Query vars from WC_Order_Query.
	 *
	 * @return array modified $query_vars
	 */
	public function add_custom_query_vars_for_hpos( $query_vars ) {
		if ( ! Order_Util::custom_orders_table_usage_is_enabled() ) {
			return $query_vars;
		}

		if ( ! empty( $query_vars['wt_order_number'] ) ) {
			$query_vars['meta_query'][] = array(
				'key'   => '_order_number',
				'value' => esc_attr( $query_vars['wt_order_number'] ),
			);
		}

		if ( ! empty( $query_vars['shipstation_exported'] ) ) {
			$query_vars['meta_query'][] = array(
				'key'     => '_shipstation_exported',
				'compare' => 'EXISTS',
			);
		}

		return $query_vars;
	}

	/**
	 * Handle a custom variable query var to get orders with the 'order_number' meta for order post type.
	 *
	 * @param array $query      Main query of WC_Order_Query.
	 * @param array $query_vars Query vars from WC_Order_Query.
	 *
	 * @return array modified $query.
	 */
	public function add_custom_query_vars_for_cpt( $query, $query_vars ) {
		if ( ! empty( $query_vars['wt_order_number'] ) ) {
			$query['meta_query'][] = array(
				'key'   => '_order_number',
				'value' => esc_attr( $query_vars['wt_order_number'] ),
			);
		}

		if ( ! empty( $query_vars['shipstation_exported'] ) ) {
			$query['meta_query'][] = array(
				'key'     => '_shipstation_exported',
				'compare' => 'EXISTS',
			);
		}

		return $query;
	}

	/**
	 * Add more information into diagnostic API results.
	 *
	 * @param array $site_info Site informations.
	 *
	 * @return array
	 */
	public function add_more_diagnostics_details( $site_info ) {
		$site_info['source_details']['status_mapping']      = self::$status_mapping;
		$site_info['source_details']['status_mapping_mode'] = $this->get_option( 'status_mode', 'api' );

		return $site_info;
	}

	/**
	 * Enqueue admin scripts/styles
	 */
	public function enqueue_scripts() {
		wp_enqueue_style( 'shipstation-admin', plugins_url( 'assets/css/admin.css', WC_SHIPSTATION_FILE ), array(), WC_SHIPSTATION_VERSION );
	}

	/**
	 * Generate a key.
	 *
	 * @return string
	 */
	public function generate_key() {
		$to_hash = get_current_user_id() . wp_date( 'U' ) . wp_rand();
		return 'WCSS-' . hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );
	}

	/**
	 * Init integration form fields
	 */
	public function init_form_fields() {
		$this->form_fields = include WC_SHIPSTATION_ABSPATH . 'includes/data/data-settings.php';
		$api_mode          = $this->get_option( 'api_mode', 'XML' );

		if ( 'REST' !== $api_mode ) {
			$rest_api_fields = $this->get_rest_api_setting_fields();

			foreach ( $rest_api_fields as $field ) {
				if ( isset( $this->form_fields[ $field ] ) ) {
					unset( $this->form_fields[ $field ] );
				}
			}
		}
		// If Checkout class does not exist, disable the gift option.
		if ( ! class_exists( 'WooCommerce\Shipping\ShipStation\Checkout' ) ) {
			$this->form_fields['gift_enabled']['custom_attributes'] = array( 'disabled' => 'disabled' );
			$this->form_fields['gift_enabled']['description']       = __( 'This feature requires WooCommerce 9.7.0 or higher.', 'woocommerce-shipstation-integration' );
		}
	}

	/**
	 * Prevents WooCommerce Subscriptions from copying across certain meta keys to renewal orders.
	 *
	 * @param string $order_meta_query Order meta query.
	 * @param int    $original_order_id Original order ID.
	 * @param int    $renewal_order_id Order ID after being renewed.
	 * @param string $new_order_role New order role.
	 *
	 * @return array
	 */
	public function subscriptions_renewal_order_meta_query( $order_meta_query, $original_order_id, $renewal_order_id, $new_order_role ) {
		if ( 'parent' === $new_order_role ) {
			$order_meta_query .= ' AND `meta_key` NOT IN ('
							. "'_tracking_provider', "
							. "'_tracking_number', "
							. "'_date_shipped', "
							. "'_order_custtrackurl', "
							. "'_order_custcompname', "
							. "'_order_trackno', "
							. "'_order_trackurl' )";
		}
		return $order_meta_query;
	}

	/**
	 * Hides any admin notices.
	 *
	 * @since 4.1.37
	 * @return void
	 */
	public function hide_notices() {
		if ( isset( $_GET['wc-shipstation-hide-notice'] ) && isset( $_GET['_wc_shipstation_notice_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_key( $_GET['_wc_shipstation_notice_nonce'] ), 'wc_shipstation_hide_notices_nonce' ) ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, nonce is unslashed and verified.
				wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'woocommerce-shipstation-integration' ) );
			}

			// phpcs:ignore WordPress.WP.Capabilities.Unknown --- It's native capability from WooCommerce
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Cheatin&#8217; huh?', 'woocommerce-shipstation-integration' ) );
			}

			update_option( 'wc_shipstation_hide_activate_notice', 'yes' );
		}
	}

	/**
	 * Settings prompt
	 */
	public function settings_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended --- No need to use nonce as no DB operation
		if ( ! empty( $_GET['tab'] ) && 'integration' === $_GET['tab'] ) {
			return;
		}

		$current_screen = get_current_screen();

		if ( $current_screen instanceof WP_Screen && 'users' === $current_screen->id ) {
			return;
		}

		$logo_title = __( 'ShipStation logo', 'woocommerce-shipstation-integration' );
		?>
		<div class="notice notice-warning">
			<img class="shipstation-logo" alt="<?php echo esc_attr( $logo_title ); ?>" title="<?php echo esc_attr( $logo_title ); ?>" src="<?php echo esc_url( plugins_url( 'assets/images/shipstation-logo.svg', __DIR__ ) ); ?>" />
			<a class="woocommerce-message-close notice-dismiss woocommerce-shipstation-activation-notice-dismiss" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wc-shipstation-hide-notice', '' ), 'wc_shipstation_hide_notices_nonce', '_wc_shipstation_notice_nonce' ) ); ?>"></a>
			<p>
				<?php
				printf(
					wp_kses(
						/* translators: %s: ShipStation URL */
						__( 'To begin printing shipping labels with ShipStation head over to <a class="shipstation-external-link" href="%s" target="_blank">ShipStation.com</a> and log in or create a new account.', 'woocommerce-shipstation-integration' ),
						array(
							'a' => array(
								'class'  => array(),
								'href'   => array(),
								'target' => array(),
							),
						)
					),
					'https://www.shipstation.com/partners/woocommerce/?ref=partner-woocommerce'
				);
				?>
			</p>
			<p>
				<?php
				esc_html_e( 'After logging in, add WooCommerce as a selling channel in ShipStation. Use your store\'s Auth Key and REST API credentials to connect. Once connected you\'re good to go!', 'woocommerce-shipstation-integration' );
				?>
			</p>
			<p>
				<?php
				if ( class_exists( 'WooCommerce\Shipping\ShipStation\Auth_Controller' ) ) :
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo Auth_Controller::get_auth_button_html();
				endif;
				?>
			</p>
			<hr />
			<p>
				<?php
				printf(
					wp_kses(
						/* translators: %1$s: ShipStation plugin settings URL, %2$s: ShipStation documentation URL */
						__( 'You can find other settings for this extension <a href="%1$s">here</a> and view the documentation <a href="%2$s">here</a>.', 'woocommerce-shipstation-integration' ),
						array(
							'a' => array(
								'href' => array(),
							),
						)
					),
					esc_url( admin_url( 'admin.php?page=wc-settings&tab=integration&section=shipstation' ) ),
					'https://docs.woocommerce.com/document/shipstation-for-woocommerce/'
				);
				?>
			</p>
		</div>
		<?php
	}
}
