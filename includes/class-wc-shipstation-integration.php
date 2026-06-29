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
use WooCommerce\Shipping\ShipStation\Connection_Log;
use WooCommerce\Shipping\ShipStation\Features;

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
	 * Status mapping mode: 'api' (ShipStation-driven) or 'plugin' (merchant-managed).
	 *
	 * @since 5.0.8
	 * @var string
	 */
	public static $status_mode = 'api';

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
	 * Status mapping mode: ShipStation owns the mappings and overwrites plugin-side values on every poll.
	 *
	 * @since 5.0.8
	 * @var string
	 */
	public const STATUS_MODE_API = 'api';

	/**
	 * Status mapping mode: plugin-side mappings are authoritative and ShipStation polls do not overwrite them.
	 *
	 * @since 5.0.8
	 * @var string
	 */
	public const STATUS_MODE_PLUGIN = 'plugin';

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
	 * WooCommerce core order statuses (prefixed with `wc-`).
	 *
	 * Used by maybe_save_status_mapping() to distinguish merchant-added custom
	 * statuses (which ShipStation's account-side mapping UI does not know about)
	 * from WC core statuses (which it does). Custom statuses are preserved across
	 * API-mode overwrites so a merchant's custom mapping is not silently dropped
	 * on the next ShipStation poll.
	 *
	 * @var string[]
	 */
	private const WC_CORE_ORDER_STATUSES = array(
		OrderInternalStatus::PENDING,
		OrderInternalStatus::PROCESSING,
		OrderInternalStatus::ON_HOLD,
		OrderInternalStatus::COMPLETED,
		OrderInternalStatus::CANCELLED,
		OrderInternalStatus::REFUNDED,
		OrderInternalStatus::FAILED,
	);

	/**
	 * Stores logger class.
	 *
	 * @var WC_Logger
	 */
	private static $log = null;

	/**
	 * Auth controller instance, created in init_auth_display() and reused by the
	 * inline connection/credentials section renderer (SHIPSTN-142). Null on
	 * front-end / non-admin contexts where the section never renders.
	 *
	 * @var Auth_Controller|null
	 */
	private $auth_controller = null;

	/**
	 * Per-request memo of the connection-log rows (SHIPSTN-142) so the settings
	 * tab reads {@see Connection_Log::all()} once, shared by both the
	 * connection-changed warning and the connections list.
	 *
	 * @var array[]|null
	 */
	private $connection_rows = null;

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
		self::$status_mode     = $this->get_option( 'status_mode', self::STATUS_MODE_API );

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

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_settings_scripts' ) );
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
	 * Update the status_mode option and keep the static cache in sync.
	 *
	 * @since 5.0.8
	 *
	 * @param string $value New status mode value. Must be one of self::STATUS_MODE_API or self::STATUS_MODE_PLUGIN.
	 */
	public function update_status_mode( $value ) {
		if ( ! in_array( $value, array( self::STATUS_MODE_API, self::STATUS_MODE_PLUGIN ), true ) ) {
			Logger::debug(
				'update_status_mode ignored invalid value',
				array( 'value' => (string) $value )
			);
			return;
		}
		$this->update_option( 'status_mode', $value );
		self::$status_mode = $value;
	}

	/**
	 * Build the description string for the export_statuses settings field.
	 *
	 * Renders two spans that the settings-page JS keeps in sync as the merchant
	 * toggles checkboxes:
	 *
	 *   #shipstation-excluded-statuses — informational list of statuses that
	 *                                    will NOT be exported (always shown).
	 *   #shipstation-unmapped-warning  — error notice listing statuses that ARE
	 *                                    selected for export but are missing
	 *                                    from every ShipStation→WC mapping slot.
	 *                                    Hidden when the list is empty.
	 *
	 * @return string HTML description (safe to pass to WC_Settings_API field — rendered via wp_kses_post).
	 */
	private function get_export_statuses_description(): string {
		$export_statuses = (array) $this->get_option( 'export_statuses', array() );

		$prefix     = self::$wc_status_prefix;
		$prefix_len = strlen( $prefix );
		$strip      = function ( $s ) use ( $prefix, $prefix_len ) {
			return ( 0 === strpos( $s, $prefix ) ) ? substr( $s, $prefix_len ) : $s;
		};

		$export_slugs = array_map( $strip, $export_statuses );

		$excluded_names = array();
		foreach ( wc_get_order_statuses() as $prefixed_slug => $label ) {
			$label_slug = $strip( $prefixed_slug );
			if ( ! in_array( $label_slug, $export_slugs, true ) ) {
				$excluded_names[] = esc_html( $label_slug );
			}
		}

		$base = esc_html__( 'Choose which order statuses to export to ShipStation.', 'woocommerce-shipstation-integration' )
			. ' ' . esc_html__( 'Each selected status must also be mapped to a ShipStation status.', 'woocommerce-shipstation-integration' )
			. ' ' . esc_html__( 'Mappings are managed below or in your ShipStation account (depending on your Status Mapping Mode).', 'woocommerce-shipstation-integration' );

		if ( empty( $excluded_names ) ) {
			$span_content = esc_html__( 'All statuses are selected for export.', 'woocommerce-shipstation-integration' );
		} else {
			$span_content = esc_html__( 'Excluded from export:', 'woocommerce-shipstation-integration' )
				. ' <strong>' . implode( ', ', $excluded_names ) . '</strong>';
		}

		$description  = '<i id="shipstation-excluded-statuses" class="shipstation-setting-note">' . $span_content . '</i>';
		$description .= $base . '<br>';

		$unmapped         = $this->get_unmapped_export_statuses();
		$unmapped_visible = ! empty( $unmapped );
		$unmapped_html    = $unmapped_visible
			? sprintf(
				/* translators: %s: comma-separated list of WC order status names */
				esc_html__( 'Selected for export but not mapped to a ShipStation status: %s. Map them below so their orders are exported correctly.', 'woocommerce-shipstation-integration' ),
				'<strong>' . esc_html( implode( ', ', $unmapped ) ) . '</strong>'
			)
			: '';

		$description .= '<span id="shipstation-unmapped-warning" class="shipstation-unmapped-warning notice notice-error inline'
			. ( $unmapped_visible ? ' is-visible' : '' )
			. '">' . $unmapped_html . '</span>';

		return $description;
	}

	/**
	 * Return display names of export_statuses entries missing from every mapping slot.
	 *
	 * Returns an empty array when:
	 *  - api_mode is XML (the mapping UI is hidden in legacy mode), or
	 *  - status_mode is 'api' (ShipStation owns the mappings — the merchant can't
	 *    resolve the mismatch from this screen, so surfacing it would be noise), or
	 *  - every selected export status appears in at least one mapping slot.
	 *
	 * Reads option values directly so it works both at field-render time (GET) and
	 * post-save time (after process_admin_options() has persisted new values).
	 *
	 * @return string[] Display-name labels (e.g. "Custom A"), or slugs as fallback.
	 */
	private function get_unmapped_export_statuses(): array {
		if ( 'REST' !== $this->get_option( 'api_mode', 'XML' ) ) {
			return array();
		}

		if ( self::STATUS_MODE_API === $this->get_option( 'status_mode', self::STATUS_MODE_API ) ) {
			return array();
		}

		$export_statuses = (array) $this->get_option( 'export_statuses', array() );
		if ( array() === $export_statuses ) {
			return array();
		}

		$prefix     = self::$wc_status_prefix;
		$prefix_len = strlen( $prefix );
		$strip      = function ( $s ) use ( $prefix, $prefix_len ) {
			return ( 0 === strpos( $s, $prefix ) ) ? substr( $s, $prefix_len ) : $s;
		};

		$mapped_slugs = array();
		foreach ( array(
			self::AWAITING_PAYMENT_STATUS,
			self::AWAITING_SHIPMENT_STATUS,
			self::ON_HOLD_STATUS,
			self::COMPLETED_STATUS,
			self::CANCELLED_STATUS,
		) as $ss_status ) {
			foreach ( (array) $this->get_option( $ss_status . '_status', array() ) as $s ) {
				$mapped_slugs[] = $strip( $s );
			}
		}

		$all_statuses   = wc_get_order_statuses();
		$unmapped_names = array();
		foreach ( $export_statuses as $raw ) {
			$slug = $strip( $raw );
			if ( in_array( $slug, $mapped_slugs, true ) ) {
				continue;
			}
			$prefixed         = $prefix . $slug;
			$unmapped_names[] = isset( $all_statuses[ $prefixed ] ) ? $all_statuses[ $prefixed ] : $slug;
		}

		return $unmapped_names;
	}

	/**
	 * Check that every status in export_statuses is covered by at least one mapping slot.
	 *
	 * Only runs in REST mode. Calls WC_Admin_Settings::add_error() when unmapped
	 * statuses are found so WooCommerce shows a red error box on the settings page.
	 * Because WC_Admin_Settings::show_messages() uses an `elseif` between errors and
	 * messages, adding an error here also suppresses the default "Your settings have
	 * been saved." success notice — no extra work needed.
	 *
	 * @return void
	 */
	private function validate_export_statuses_mapping(): void {
		$unmapped_names = $this->get_unmapped_export_statuses();
		if ( empty( $unmapped_names ) ) {
			return;
		}

		\WC_Admin_Settings::add_error(
			__( 'Settings saved, but some selected export statuses are not mapped to a ShipStation status. Please review the settings below.', 'woocommerce-shipstation-integration' )
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
	 *
	 * `WC_Integration::process_admin_options()` cannot be hooked directly because it
	 * returns a value and PHPStan rejects that for action callbacks.
	 *
	 * When Status Mapping Mode is "API" (`status_mode === 'api'`), the five status
	 * mapping <select> elements are HTML-disabled in the browser and therefore do
	 * not arrive in `$_POST`. WC's validate_*_field() helpers turn an absent key
	 * into `''`, so without intervention `process_admin_options()` would clear the
	 * stored mappings on every save. Pre-fill the missing keys from `get_option()`
	 * so the values round-trip unchanged, matching the merchant's expectation that
	 * saving while ShipStation owns the mappings is a no-op for those fields.
	 *
	 * The WPCOM-transport checkbox gets the same pre-fill when a constant or
	 * filter override renders it disabled, and the form fields are rebuilt after
	 * the save so the conditional WordPress.com connection section reflects the
	 * new opt-in value on the same request (SHIPSTN-141).
	 */
	public function update_shipstation_options() {
		$post_data = $this->get_post_data();
		$mode_key  = $this->get_field_key( 'status_mode' );
		$new_mode  = isset( $post_data[ $mode_key ] )
			? sanitize_key( wp_unslash( $post_data[ $mode_key ] ) )
			: $this->get_option( 'status_mode' );

		if ( self::STATUS_MODE_API === $new_mode ) {
			$status_field_keys = array(
				self::AWAITING_PAYMENT_STATUS . '_status',
				self::AWAITING_SHIPMENT_STATUS . '_status',
				self::ON_HOLD_STATUS . '_status',
				self::COMPLETED_STATUS . '_status',
				self::CANCELLED_STATUS . '_status',
			);

			foreach ( $status_field_keys as $key ) {
				$field_key = $this->get_field_key( $key );
				if ( isset( $post_data[ $field_key ] ) ) {
					continue;
				}
				$stored = $this->get_option( $key );
				if ( ! empty( $stored ) ) {
					$post_data[ $field_key ] = $stored;
				}
			}

			$this->set_post_data( $post_data );
		}

		// The WPCOM-transport checkbox renders disabled while a constant or
		// filter override forces the transport on (see data-settings.php), so
		// it is absent from the POST payload and validate_checkbox_field()
		// would coerce the gap to `no`, clearing a stored opt-in. Round-trip
		// the stored value instead; with no override active an absent key is a
		// genuine untick and must keep clearing the option.
		$wpcom_field_key = $this->get_field_key( 'wpcom_transport_enabled' );
		if (
			! isset( $post_data[ $wpcom_field_key ] )
			&& 'yes' === $this->get_option( 'wpcom_transport_enabled' )
			&& Features::is_wpcom_transport_forced_by_override()
		) {
			$post_data[ $wpcom_field_key ] = '1';
			$this->set_post_data( $post_data );
		}

		$this->process_admin_options();

		// Rebuild the form fields from the just-persisted options so the
		// same-request render is current: the conditional WordPress.com
		// connection section appears/disappears with the new opt-in value and
		// the export_statuses description reflects the new selections.
		$this->init_form_fields();

		$this->validate_export_statuses_mapping();
	}

	/**
	 * Initialize the authentication display functionality.
	 */
	private function init_auth_display() {
		if ( is_admin() && class_exists( 'WooCommerce\Shipping\ShipStation\Auth_Controller' ) ) {
			$connection            = \WooCommerce\Shipping\ShipStation\Main::instance()->get_wpcom_connection();
			$this->auth_controller = new Auth_Controller( $connection );
			$this->init_global_connection_banner();
		}
	}

	/**
	 * Bootstrap the global broken-connection banner — the site-wide RED notice
	 * that fires on WooCommerce settings screens (other than the ShipStation tab)
	 * when a previously-working connection has gone silent (SHIPSTN-142). Guarded
	 * so a partial load never fatals.
	 *
	 * @return void
	 */
	private function init_global_connection_banner() {
		if ( ! class_exists( 'WooCommerce\Shipping\ShipStation\Global_Connection_Banner' ) ) {
			return;
		}

		$banner = new \WooCommerce\Shipping\ShipStation\Global_Connection_Banner();
		$banner->bootstrap();
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
		$this->update_status_mode( self::STATUS_MODE_PLUGIN );
		$log_info['status_mode'] = self::STATUS_MODE_PLUGIN;

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

		if ( self::STATUS_MODE_PLUGIN === $mapping_mode ) {
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

			// Preserve merchant-configured custom (non-core) statuses that ShipStation's
			// payload omits. ShipStation's account-side mapping UI only knows about WC
			// core statuses, so any non-core status in the existing plugin-side option
			// was added by the merchant via this UI and must not be silently dropped on
			// every poll (SHIPSTN-122).
			$existing_wc_statuses = (array) $this->get_option( $ss_status . '_status', array() );
			$preserved_custom     = array_values(
				array_diff( $existing_wc_statuses, self::WC_CORE_ORDER_STATUSES, $wc_statuses )
			);
			$wc_statuses          = array_values( array_unique( array_merge( $wc_statuses, $preserved_custom ) ) );

			$this->update_option( $ss_status . '_status', $wc_statuses );
			$log_info[ $ss_status . '_status' ] = $wc_statuses;
			if ( ! empty( $preserved_custom ) ) {
				$log_info[ $ss_status . '_status_preserved_custom' ] = $preserved_custom;
			}
		}

		// Update the status mode only if the status_mode still empty.
		if ( empty( $mapping_mode ) ) {
			$this->update_status_mode( self::STATUS_MODE_PLUGIN );
			$log_info['status_mode'] = self::STATUS_MODE_PLUGIN;
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
		$site_info['source_details']['status_mapping_mode'] = $this->get_option( 'status_mode', self::STATUS_MODE_API );

		return $site_info;
	}

	/**
	 * Enqueue admin scripts/styles
	 */
	public function enqueue_scripts() {
		wp_enqueue_style( 'shipstation-admin', plugins_url( 'assets/css/admin.css', WC_SHIPSTATION_FILE ), array(), WC_SHIPSTATION_VERSION );
	}

	/**
	 * Enqueue scripts and styles for the ShipStation integration settings page.
	 *
	 * @since 5.0.8
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_settings_scripts( $hook_suffix ) {
		if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab/section detection, no action taken.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab/section detection, no action taken.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		if ( 'integration' !== $tab || 'shipstation' !== $section ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script(
			'shipstation-admin-settings',
			WC_SHIPSTATION_PLUGIN_URL . 'assets/js/admin-settings' . $suffix . '.js',
			array( 'jquery' ),
			WC_SHIPSTATION_VERSION,
			true
		);

		wp_enqueue_style(
			'shipstation-admin',
			WC_SHIPSTATION_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WC_SHIPSTATION_VERSION
		);

		wp_localize_script(
			'shipstation-admin-settings',
			'wcShipStationSettings',
			array(
				'statusModeFieldId'     => 'woocommerce_shipstation_status_mode',
				'apiMode'               => self::STATUS_MODE_API,
				'apiModeFieldId'        => 'woocommerce_shipstation_api_mode',
				'restApiModeValue'      => 'REST',
				'exportStatusesFieldId' => 'woocommerce_shipstation_export_statuses',
				'unmappedWarningId'     => 'shipstation-unmapped-warning',
				'statusFieldIds'        => array(
					'woocommerce_shipstation_' . self::AWAITING_PAYMENT_STATUS . '_status',
					'woocommerce_shipstation_' . self::AWAITING_SHIPMENT_STATUS . '_status',
					'woocommerce_shipstation_' . self::ON_HOLD_STATUS . '_status',
					'woocommerce_shipstation_' . self::COMPLETED_STATUS . '_status',
					'woocommerce_shipstation_' . self::CANCELLED_STATUS . '_status',
				),
				'excludedStatusesLabel' => __( 'Excluded from export:', 'woocommerce-shipstation-integration' ),
				'allStatusesExported'   => __( 'All statuses are selected for export.', 'woocommerce-shipstation-integration' ),
				'unmappedWarningPrefix' => __( 'Selected for export but not mapped to a ShipStation status:', 'woocommerce-shipstation-integration' ),
				'unmappedWarningSuffix' => __( 'Map them below so their orders are exported correctly.', 'woocommerce-shipstation-integration' ),
				'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
				'deleteKeyNonce'        => wp_create_nonce( 'shipstation_auth_nonce' ),
				'deleteKeyConfirm'      => __( 'Delete this API key pair? Any ShipStation connection still using it will stop authenticating immediately. This cannot be undone.', 'woocommerce-shipstation-integration' ),
				'deleteKeyError'        => __( 'Something went wrong. Please try again.', 'woocommerce-shipstation-integration' ),
			)
		);
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

		$this->form_fields['export_statuses']['desc_tip']    = false;
		$this->form_fields['export_statuses']['description'] = $this->get_export_statuses_description();
	}

	/**
	 * Render no standalone row for the WordPress.com transport field (SHIPSTN-142,
	 * reqs 6 & 7). The save-bearing `wpcom_transport_enabled` field stays registered
	 * in data-settings so its checkbox POSTs under
	 * `woocommerce_shipstation_wpcom_transport_enabled` and
	 * {@see validate_wpcom_transport_field()} runs — but the checkbox itself is now
	 * hand-rendered inside the unified "ShipStation Connection" section's
	 * always-visible transport strip by {@see generate_shipstation_credentials_html()},
	 * so this WC-dispatched renderer emits nothing to avoid a duplicate row.
	 *
	 * @since 5.2.0
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 *
	 * @return string Empty string (the field is rendered inside the unified section).
	 */
	public function generate_wpcom_transport_html( $key, $data ) {
		return '';
	}

	/**
	 * Render the always-visible WordPress.com transport strip for the unified
	 * "ShipStation Connection" section (SHIPSTN-142, reqs 6 & 7): the relocated
	 * "Enable WordPress.com Transport" checkbox followed by the connect / connected +
	 * guarded-disconnect controls.
	 *
	 * The checkbox is hand-rendered with the exact WC field name and id so it POSTs
	 * to the registered `wpcom_transport_enabled` field and so the page JS can focus
	 * it (`#woocommerce_shipstation_wpcom_transport_enabled`). When a constant or
	 * filter override forces the transport on
	 * ({@see Features::is_wpcom_transport_forced_by_override()}), the checkbox renders
	 * disabled + checked with the override note — matching the prior data-settings
	 * behaviour; the disabled-checked POST is round-tripped in
	 * {@see update_shipstation_options()} so the stored opt-in is preserved.
	 *
	 * @since 5.2.0
	 *
	 * @param bool $transport_enabled Whether the saved/effective transport toggle is on.
	 *
	 * @return string Transport strip HTML.
	 */
	private function build_wpcom_transport_strip_html( bool $transport_enabled ): string {
		$field_name = 'woocommerce_shipstation_wpcom_transport_enabled';
		$forced     = Features::is_wpcom_transport_forced_by_override();
		// A forced override always renders checked regardless of the stored value.
		$checked  = $transport_enabled || $forced;
		$controls = $this->build_wpcom_controls_html();

		ob_start();
		?>
		<div class="shipstation-wpcom-connection">
			<p class="shipstation-transport-toggle">
				<label for="<?php echo esc_attr( $field_name ); ?>">
					<input
						type="checkbox"
						name="<?php echo esc_attr( $field_name ); ?>"
						id="<?php echo esc_attr( $field_name ); ?>"
						value="1"
						<?php checked( $checked ); ?>
						<?php disabled( $forced ); ?>
					/>
					<?php esc_html_e( 'Enable WordPress.com Transport', 'woocommerce-shipstation-integration' ); ?>
				</label>
			</p>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped markup (esc_html / esc_attr inside).
			echo $this->wpcom_transport_description_html( $forced );

			if ( '' !== $controls ) {
				// The connect/connected/disconnect/repair controls live in their own
				// wrapper so the CSS can hide just them when the checkbox is unticked,
				// while the checkbox + help text above stay visible. The controls
				// render regardless of the saved toggle (the connection is always
				// initialized in the admin), and CSS gates their visibility.
				?>
				<div class="shipstation-wpcom-controls">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Controls are built from escaped template output.
					echo $controls;
					?>
				</div>
				<?php
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The help text shown beneath the relocated transport checkbox: the standard
	 * "what this does" copy, prefixed with the locked-by-override note when a
	 * constant or filter is forcing the transport on (SHIPSTN-142, reqs 6 & 7).
	 *
	 * Mirrors the description previously assembled in data-settings.php so the
	 * relocated checkbox keeps the same guidance and the same forced-override note.
	 *
	 * @since 5.2.0
	 *
	 * @param bool $forced Whether a constant/filter override is forcing the transport on.
	 *
	 * @return string Escaped description HTML.
	 */
	private function wpcom_transport_description_html( bool $forced ): string {
		$base = esc_html__( 'Routes ShipStation traffic to your store through WordPress.com, providing a more stable connection that bypasses firewall and security-plugin blocks that can intercept direct requests.', 'woocommerce-shipstation-integration' );

		if ( ! $forced ) {
			return '<p class="description">' . $base . '</p>';
		}

		$override_source = ( defined( 'WC_SHIPSTATION_WPCOM_TRANSPORT' ) && WC_SHIPSTATION_WPCOM_TRANSPORT )
			? '<code>WC_SHIPSTATION_WPCOM_TRANSPORT</code>'
			: '<code>wc_shipstation_wpcom_transport_enabled</code>';

		$note = '<i class="shipstation-setting-note">' . sprintf(
			/* translators: %s: the constant or filter name (wrapped in a <code> tag) that is forcing the setting on. */
			esc_html__( 'This option is locked on by %s constant in your site configuration and cannot be changed here. Remove it to control the setting with the checkbox.', 'woocommerce-shipstation-integration' ),
			$override_source
		) . '</i>';

		return '<p class="description">' . $note . $base . '</p>';
	}

	/**
	 * Save the WordPress.com transport toggle exactly like a checkbox; the custom
	 * field type only changes rendering, not persistence.
	 *
	 * @since 5.2.0
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 *
	 * @return string 'yes' or 'no'.
	 */
	public function validate_wpcom_transport_field( $key, $value ) {
		return $this->validate_checkbox_field( $key, $value );
	}

	/**
	 * Whether the site currently has a live Jetpack/WordPress.com connection,
	 * resolved null-safely for the connection-status logic (SHIPSTN-142).
	 *
	 * Threaded into {@see Connection_Log::connection_status()} and
	 * {@see Connection_Log::health_from_rows()} so a proxy (wpcom) route reads as
	 * "Disconnected" the moment Jetpack drops, instead of staying "Active" until
	 * the recency window lapses. When the WordPress.com package is unavailable the
	 * facade is null — there is no live link, so this is `false` (a proxy row is
	 * genuinely disconnected), never a misleading `true`.
	 *
	 * {@see WPCOM_Connection::is_connected()} is synchronous and render-time safe.
	 *
	 * @since 5.2.0
	 *
	 * @return bool
	 */
	private function is_wpcom_connected(): bool {
		$connection = \WooCommerce\Shipping\ShipStation\Main::instance()->get_wpcom_connection();
		return null !== $connection && $connection->is_connected();
	}

	/**
	 * Connection-log rows for the current settings render, fetched once.
	 *
	 * {@see generate_shipstation_credentials_html()} reads these rows twice on the
	 * same render — once for the section verdict's health computation and once for
	 * the Connections fold's list — so memoising avoids a second
	 * {@see Connection_Log::all()} (and its key-row fan-out) on the same request.
	 *
	 * @since 5.2.0
	 *
	 * @return array[] Rows as returned by {@see Connection_Log::all()}.
	 */
	private function get_connection_rows(): array {
		if ( null === $this->connection_rows ) {
			$this->connection_rows = Connection_Log::all();
		}

		return $this->connection_rows;
	}

	/**
	 * Render the unified "ShipStation Connection" section row (SHIPSTN-142, reqs 6
	 * & 7). One settings row whose body, top to bottom, is:
	 *   - an always-visible header: a section status pill (the overall sync verdict
	 *     — Connected / Action needed / Not connected yet) (D10);
	 *   - a conditional inline banner driven by the same verdict (D11/D13);
	 *   - the always-visible WordPress.com transport strip: the "Enable WordPress.com
	 *     Transport" checkbox + connect/connected/disconnect/repair controls (D9);
	 *   - three collapsible <details> subsections, collapsed by default unless the
	 *     verdict auto-opens Credentials (D12):
	 *       - Credentials: the values ShipStation needs (Consumer Key, Consumer
	 *         Secret, Authentication Key, Store URL) with inline Generate. In direct
	 *         mode the Store URL is the site URL.
	 *       - Connections: where ShipStation has authenticated from.
	 *       - API Keys: the plugin-generated REST key pairs, with per-row delete.
	 *
	 * @since 5.2.0
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data (title).
	 *
	 * @return string Row HTML.
	 */
	public function generate_shipstation_credentials_html( $key, $data ) {
		$data = wp_parse_args(
			(array) $data,
			array( 'title' => '' )
		);

		// Credential state (key reference / stored secret / auth key / Store URL)
		// comes from the auth controller; default to the "no keys" state when it
		// is unavailable (it always exists on the admin settings tab).
		$section = array(
			'cred_state'      => 'none',
			'conn_url'        => home_url(),
			'auth_key'        => (string) self::$auth_key,
			'truncated_key'   => '',
			'consumer_secret' => '',
		);

		if ( null !== $this->auth_controller ) {
			$section = $this->auth_controller->get_connection_section_data();
		}

		// Incoming-connection list (SHIPSTN-142), shown in the "Connections"
		// subsection below. Reads the connection-log table at render time, so it
		// only queries on this settings tab.
		$connections       = $this->get_connection_rows();
		$date_format       = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$active_window     = Auth_Controller::active_window_seconds();
		$transport_enabled = Features::is_wpcom_transport_enabled();
		// Live Jetpack/WordPress.com link state, threaded into the per-row status so
		// a proxy route reads "Disconnected" the instant Jetpack drops rather than
		// staying "Active" until the recency window lapses (SHIPSTN-142, D1).
		$is_wpcom_connected = $this->is_wpcom_connected();
		// The store URL each connection reaches must be this site. If a row's
		// store host differs from the current home_url() host, the site address
		// changed and that connection can no longer reach the store — it is
		// permanently broken (not merely idle), so flag it distinctly below.
		$current_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		// The section verdict (D10–D13): the single source of truth for the status
		// pill, the inline banner, which folds auto-open, and the banner's action.
		// Credential state dominates; otherwise it defers to connection health over
		// the SAME recency window the Connections table's per-row pill uses
		// ($active_window, 24h), so the banner can never warn "stopped syncing" while
		// a route's pill still reads Active.
		$health  = Connection_Log::health_from_rows( $connections, $active_window, $transport_enabled, $current_host, $is_wpcom_connected );
		$verdict = Connection_Log::section_verdict( $section['cred_state'], $health['reason'] );

		// A pending WordPress.com disconnect with no safe direct fallback renders
		// the "Dangerously disconnect" screen in the controls above, whose copy
		// tells the merchant to open this Credentials section — so open it for them
		// rather than leaving that instruction pointing at a collapsed section.
		// Mirrors the unsafe-pending branch in wpcom-controls.php (intent leads so
		// the common no-disconnect render short-circuits before any DB read).
		$wpcom_connection          = \WooCommerce\Shipping\ShipStation\Main::instance()->get_wpcom_connection();
		$unsafe_disconnect_pending = null !== $wpcom_connection
			&& $wpcom_connection->has_disconnect_intent()
			&& $wpcom_connection->is_connected()
			&& ! $wpcom_connection->has_url_mismatch()
			&& ! Connection_Log::is_direct_connection_safe( $active_window, Auth_Controller::direct_lag_tolerance_seconds() );

		// Credentials fold opens when the verdict says so (first-run/recovery, or a
		// health state whose fix lives there), OR when an unsafe disconnect is
		// pending (its copy points the merchant here).
		$credentials_open = $verdict['open_credentials'] || $unsafe_disconnect_pending;

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<div class="shipstation-connection">
					<div class="shipstation-connection__header">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- section_status_pill() escapes internally.
						echo self::section_status_pill( $verdict['pill'] );
						?>
					</div>

					<?php
					if ( '' !== $verdict['banner'] ) {
						$copy = self::connection_banner_copy( $verdict['banner'] );
						// never_synced reads as info (just finish setup); every other
						// state is a warning the merchant should act on (D11).
						$tone = 'never_synced' === $verdict['banner'] ? 'info' : 'warn';

						wc_get_template(
							'partials/connection-banner.php',
							array(
								'tone'          => $tone,
								'body'          => $copy['body'],
								'action_label'  => $copy['action_label'],
								'action_target' => $verdict['action_target'],
								'action_href'   => 'wpcom_connect' === $verdict['action_target'] ? self::wpcom_connect_url() : '',
							),
							'',
							WC_SHIPSTATION_ABSPATH . 'templates/'
						);
					}

					// Pre-save live warning (SHIPSTN-142): hidden until the merchant
					// toggles the transport checkbox away from its saved value.
					// auth-display.js reveals it — and hides the settled-state verdict
					// banner above so the two never contradict — while the toggle is
					// dirty, restoring on revert. The Store URL field below already
					// swaps live, so the values are correct to copy before saving. Both
					// direction messages ride on data-* so the JS needs no i18n plumbing;
					// an empty message tells the JS that direction needs no warning.
					//
					// The two directions differ in severity, not in whether a banner
					// shows. Turning ON without a ready wpcom connection — and turning
					// OFF without an active direct one — needs full ShipStation
					// reconfiguration (set the Store URL + re-enter credentials), so it
					// carries the strong message. Turning ON is a genuine no-op when a
					// wpcom connection is already "rejected" (set up, refused only
					// because the transport is off — it activates on the next pull with
					// no change), so that direction alone stays suppressed.
					//
					// Turning OFF with a direct connection already "active" is NOT a
					// safe no-op: that status is recency-based (last_seen within the
					// window), so it can keep reading "active" for up to the window
					// after the merchant changed that connection's Store URL/credentials
					// in ShipStation — at which point flipping the proxy off leaves NO
					// working connection. So instead of suppressing the banner we still
					// show one, with softer "confirm it's still configured" wording.
					$direct_already_active = Connection_Log::has_connection_with_status( $connections, 'direct', 'active', $active_window, $transport_enabled, $current_host, $is_wpcom_connected );
					$wpcom_ready_to_enable = Connection_Log::has_connection_with_status( $connections, 'wpcom', 'rejected', $active_window, $transport_enabled, $current_host, $is_wpcom_connected );
					$enable_msg            = $wpcom_ready_to_enable
						? ''
						: __( "You're turning on WordPress.com Transport. After you save, update your store connection in ShipStation: set the Store URL to the WordPress.com connection URL and re-enter your credentials. The values below already reflect this change.", 'woocommerce-shipstation-integration' );
					$disable_msg           = $direct_already_active
						? __( "You're turning off WordPress.com Transport. ShipStation has a recent direct connection to your store, so it should keep syncing over it — but that connection reads as active for up to a day after ShipStation last reached it, so it may not reflect a change you've since made in ShipStation. Before you save, open ShipStation and confirm that store's connection still uses your site address as the Store URL with current credentials.", 'woocommerce-shipstation-integration' )
						: __( "You're turning off WordPress.com Transport. After you save, update your store connection in ShipStation: set the Store URL back to your site address and re-enter your credentials. The values below already reflect this change.", 'woocommerce-shipstation-integration' );
					printf(
						'<div class="shipstation-connection-banner shipstation-connection-banner--warn shipstation-transport-warning" role="alert" hidden data-enable-msg="%1$s" data-disable-msg="%2$s"><p class="shipstation-connection-banner__body"></p></div>',
						esc_attr( $enable_msg ),
						esc_attr( $disable_msg )
					);

					// Always-visible transport strip (D9): the relocated checkbox +
					// the connect/connected/disconnect/repair controls.
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Strip is built from escaped markup/template output.
					echo $this->build_wpcom_transport_strip_html( $transport_enabled );
					?>

					<details class="shipstation-section" id="shipstation-credentials-section"<?php echo $credentials_open ? ' open' : ''; ?>>
						<summary class="shipstation-section__summary"><?php esc_html_e( 'Credentials', 'woocommerce-shipstation-integration' ); ?></summary>
						<div class="shipstation-section__body">
							<?php
							wc_get_template(
								'connection-section.php',
								$section,
								'',
								WC_SHIPSTATION_ABSPATH . 'templates/'
							);
							?>
						</div>
					</details>

					<details class="shipstation-section" id="shipstation-connections-section">
						<summary class="shipstation-section__summary"><?php esc_html_e( 'Connections', 'woocommerce-shipstation-integration' ); ?></summary>
						<div class="shipstation-section__body">
							<?php
							wc_get_template(
								'observed-connections.php',
								array(
									'connections'        => $connections,
									'date_format'        => $date_format,
									'active_window'      => $active_window,
									'transport_enabled'  => $transport_enabled,
									'current_host'       => $current_host,
									'is_wpcom_connected' => $is_wpcom_connected,
								),
								'',
								WC_SHIPSTATION_ABSPATH . 'templates/'
							);
							?>
						</div>
					</details>

					<details class="shipstation-section" id="shipstation-api-keys-section">
						<summary class="shipstation-section__summary"><?php esc_html_e( 'API Keys', 'woocommerce-shipstation-integration' ); ?></summary>
						<div class="shipstation-section__body" id="shipstation-api-key-list">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output is escaped internally.
							echo self::render_api_key_list_html();
							?>
						</div>
					</details>
				</div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * The nonced WordPress.com connect URL the inline banner's "Reconnect
	 * WordPress.com" action links to (SHIPSTN-142, reqs 6 & 7). Reuses the exact
	 * same admin-post action + nonce the wpcom-controls.php Connect button builds,
	 * so the relay/handler path is identical — no second nonce is introduced.
	 *
	 * @since 5.2.0
	 *
	 * @return string Nonced admin-post URL.
	 */
	private static function wpcom_connect_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=shipstation_wpcom_connect' ),
			'shipstation_wpcom_connect'
		);
	}

	/**
	 * Build the section-level status pill for the unified "ShipStation Connection"
	 * section (SHIPSTN-142, D10): the OVERALL ShipStation sync verdict, distinct
	 * from {@see wpcom_status_pill()} (which reflects only the Jetpack link state)
	 * and carrying no WordPress.com logo. Colour-coded by the verdict pill state.
	 *
	 * @internal Template render helper; not part of the public plugin API.
	 *
	 * @since 5.2.0
	 *
	 * @param string $pill One of 'connected', 'action', 'pending' (from {@see Connection_Log::section_verdict()}).
	 *
	 * @return string Pill HTML.
	 */
	public static function section_status_pill( string $pill ): string {
		switch ( $pill ) {
			case 'connected':
				$label = __( 'Connected', 'woocommerce-shipstation-integration' );
				break;
			case 'action':
				$label = __( 'Action needed', 'woocommerce-shipstation-integration' );
				break;
			default: // 'pending'.
				$label = __( 'Not connected yet', 'woocommerce-shipstation-integration' );
				break;
		}

		return sprintf(
			'<span class="shipstation-conn-pill shipstation-conn-pill--%1$s"><span class="shipstation-conn-pill__label">%2$s</span></span>',
			esc_attr( $pill ),
			esc_html( $label )
		);
	}

	/**
	 * The inline banner copy for a verdict's symbolic banner state (SHIPSTN-142,
	 * D13). Maps the symbolic `banner` token (never_synced|inactive|mismatch|
	 * disconnected|rejected) onto the merchant-facing body string and the action
	 * button label. The input is the symbolic verdict state — not a health reason —
	 * because {@see Connection_Log::section_verdict()} already resolved credential
	 * dominance and the never-synced/setup split.
	 *
	 * @since 5.2.0
	 *
	 * @param string $banner_state One of 'never_synced', 'inactive', 'mismatch', 'disconnected', 'rejected'.
	 *
	 * @return array{body: string, action_label: string} The banner body, and the action button label ('' when no button).
	 */
	private static function connection_banner_copy( string $banner_state ): array {
		switch ( $banner_state ) {
			case 'never_synced':
				return array(
					'body'         => __( "ShipStation hasn't connected yet. Copy the values below into ShipStation to finish connecting your store.", 'woocommerce-shipstation-integration' ),
					'action_label' => '',
				);
			case 'inactive':
				return array(
					'body'         => __( "ShipStation hasn't synced in over a day, so new orders may not be importing. Check your ShipStation account dashboard for connection status. If you no longer have your API keys, generate a fresh pair in the Credentials section below.", 'woocommerce-shipstation-integration' ),
					'action_label' => '',
				);
			case 'mismatch':
				return array(
					'body'         => __( "Your store's web address changed, so the route ShipStation saved no longer reaches it. Update the Store URL in ShipStation to the value below — or repair the WordPress.com connection.", 'woocommerce-shipstation-integration' ),
					'action_label' => __( 'Show the Store URL', 'woocommerce-shipstation-integration' ),
				);
			case 'disconnected':
				return array(
					'body'         => __( "WordPress.com is disconnected, so ShipStation can't reach your store through it. Reconnect WordPress.com to resume syncing — or switch ShipStation to your store's direct URL.", 'woocommerce-shipstation-integration' ),
					'action_label' => __( 'Reconnect WordPress.com', 'woocommerce-shipstation-integration' ),
				);
			case 'rejected':
				return array(
					'body'         => __( 'WordPress.com transport is off, so your store is turning away the proxied connection ShipStation still uses. Turn transport back on — or point ShipStation at your store\'s direct URL.', 'woocommerce-shipstation-integration' ),
					'action_label' => __( 'Review transport setting', 'woocommerce-shipstation-integration' ),
				);
			default:
				return array(
					'body'         => '',
					'action_label' => '',
				);
		}
	}

	/**
	 * The credentials section carries no value; keep saves from persisting a
	 * junk setting for it.
	 *
	 * @since 5.2.0
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value (always absent).
	 *
	 * @return string Empty string.
	 */
	public function validate_shipstation_credentials_field( $key, $value ) {
		return '';
	}

	/**
	 * Build the WordPress.com connection controls shown atop the inline section:
	 * the connect button, the connected state with the guarded disconnect intent
	 * flow (pending → confirm when a direct fallback is detected; blocked +
	 * "Dangerously disconnect" otherwise). In the admin these always render (the
	 * connection is initialized regardless of the transport toggle), and the CSS
	 * hides the block until the checkbox is ticked; '' only when the WordPress.com
	 * package is unavailable outside the admin. Mirrors the guarded disconnect
	 * flow in WPCOM_Connection (SHIPSTN-142).
	 *
	 * @since 5.2.0
	 *
	 * @return string Controls HTML.
	 */
	private function build_wpcom_controls_html(): string {
		$connection = \WooCommerce\Shipping\ShipStation\Main::instance()->get_wpcom_connection();

		if ( null === $connection ) {
			// In the admin the connection is always initialized, so a null facade
			// means the WordPress.com package is genuinely missing — surface the
			// hint. Outside the admin (transport off) there are simply no controls.
			return is_admin()
				? '<p>' . esc_html__( 'The WordPress.com connection package is unavailable. Reinstall plugin dependencies to enable this feature.', 'woocommerce-shipstation-integration' ) . '</p>'
				: '';
		}

		// All connection-state branching (connect / connected + guarded disconnect /
		// URL-mismatch repair) lives in the template; it is reached only with a
		// non-null connection.
		ob_start();
		wc_get_template(
			'wpcom-controls.php',
			array(
				'connection'         => $connection,
				// The same bool the connections table reads, so the WordPress.com
				// section's connect/connected gate and the table stay coherent.
				'is_wpcom_connected' => $this->is_wpcom_connected(),
			),
			'',
			WC_SHIPSTATION_ABSPATH . 'templates/'
		);
		return (string) ob_get_clean();
	}

	/**
	 * Build a WordPress.com connection-status pill: the WordPress.com icon, an
	 * optional check mark, and a label, colour-coded by state via a modifier
	 * class (connected = green, pending = amber, disconnected = gray).
	 *
	 * Static so the WordPress.com controls template can render pills without an
	 * instance handle.
	 *
	 * @internal Template render helper; not part of the public plugin API.
	 *
	 * @since 5.2.0
	 *
	 * @param string $modifier State modifier: 'connected', 'pending', or 'disconnected'.
	 * @param string $label    Pill label text.
	 * @param string $dashicon Optional dashicon class to prepend (e.g. 'dashicons-yes-alt' for connected, 'dashicons-ellipsis' for pending).
	 *
	 * @return string Pill HTML.
	 */
	public static function wpcom_status_pill( $modifier, $label, $dashicon = '' ) {
		$icon = '' !== $dashicon
			? '<span class="dashicons ' . esc_attr( $dashicon ) . '" aria-hidden="true"></span>'
			: '';

		return sprintf(
			'<span class="shipstation-conn-pill shipstation-conn-pill--%1$s"><img class="shipstation-conn-pill__icon" src="%2$s" alt="" width="18" height="18" />%3$s<span class="shipstation-conn-pill__label">%4$s</span></span>',
			esc_attr( $modifier ),
			esc_url( WC_SHIPSTATION_PLUGIN_URL . 'assets/images/wpcom-logo.png' ),
			$icon,
			esc_html( $label )
		);
	}

	/**
	 * Translated labels for every status-pill state, shared by the API key list and
	 * the observed-connection list so the two never drift. The key list uses
	 * active/inactive/new/unused; the connection list active/inactive/rejected/
	 * disconnected/mismatch/deleted — the union lives here.
	 *
	 * Static so the observed-connections template can render pills without an
	 * instance handle.
	 *
	 * @return array<string,string> State modifier => translated label.
	 */
	public static function pill_labels(): array {
		return array(
			'active'       => __( 'Active', 'woocommerce-shipstation-integration' ),
			'inactive'     => __( 'Inactive', 'woocommerce-shipstation-integration' ),
			'rejected'     => __( 'Rejected', 'woocommerce-shipstation-integration' ),
			'disconnected' => __( 'Disconnected', 'woocommerce-shipstation-integration' ),
			'mismatch'     => __( 'Mismatch', 'woocommerce-shipstation-integration' ),
			'deleted'      => __( 'Deleted', 'woocommerce-shipstation-integration' ),
			'new'          => __( 'New', 'woocommerce-shipstation-integration' ),
			'unused'       => __( 'Unused', 'woocommerce-shipstation-integration' ),
		);
	}

	/**
	 * Render an inline status pill. Both the API key list and the observed-
	 * connection list share this `shipstation-key-pill` markup so a markup or
	 * state change lands in one place.
	 *
	 * @param string $modifier Pill state modifier (see {@see pill_labels()}).
	 * @param string $label    Already-translated pill label.
	 *
	 * @return string Span HTML (internally escaped).
	 */
	public static function key_pill( string $modifier, string $label ): string {
		return sprintf(
			'<span class="shipstation-key-pill shipstation-key-pill--%1$s">%2$s</span>',
			esc_attr( $modifier ),
			esc_html( $label )
		);
	}

	/**
	 * Gather the plugin-generated API key rows for the "API Keys" subsection,
	 * sorted for display (SHIPSTN-142).
	 *
	 * Key generation is non-destructive everywhere — any older pair may be the
	 * one currently configured in ShipStation — so this list is where the
	 * merchant retires pairs they no longer use. Rows are fetched at render
	 * time so the table reflects keys minted earlier in the same request.
	 *
	 * @since 5.2.0
	 *
	 * @return array{0: array, 1: int} The sorted rows and the newest key id.
	 */
	private static function get_sorted_plugin_key_rows(): array {
		// Ensure every plugin key has a mint baseline (legacy keys predate it) so
		// the never-used countdown and the prune have an age to work from.
		Auth_Controller::backfill_missing_minted_at();

		$rows = Auth_Controller::get_plugin_key_rows();

		// Rows are newest-first; the newest plugin row is the one the prune always
		// protects and the most likely live credential, so it alone is flagged
		// "(newest)" and never carries an auto-remove countdown.
		$newest_key_id = isset( $rows[0]['key_id'] ) ? (int) $rows[0]['key_id'] : 0;

		// Order by "Last seen": the freshly-generated New key is always pinned to
		// the top (it is prune-protected and the one you just created); everyone
		// else falls out by last_access descending, so Active (recent) → Inactive
		// (stale) → Unused/Never (no access — an infinitely-old "last seen") in that
		// order. Ties break on key_id (newest first).
		$sort_value = function ( $row ) use ( $newest_key_id ) {
			$last_access = isset( $row['last_access'] ) ? (string) $row['last_access'] : '';
			$has_access  = '' !== $last_access && '0000-00-00 00:00:00' !== $last_access;
			if ( ! $has_access ) {
				return (int) $row['key_id'] === $newest_key_id ? PHP_INT_MAX : 0;
			}
			return (int) strtotime( get_gmt_from_date( $last_access ) . ' UTC' );
		};
		usort(
			$rows,
			function ( $a, $b ) use ( $sort_value ) {
				$value_a = $sort_value( $a );
				$value_b = $sort_value( $b );
				if ( $value_a !== $value_b ) {
					return $value_b <=> $value_a; // Most-recently-seen first.
				}
				return (int) $b['key_id'] - (int) $a['key_id'];
			}
		);

		return array( $rows, $newest_key_id );
	}

	/**
	 * Render the "API Keys" subsection table to a string.
	 *
	 * Shared by the settings-tab render and the generate-keys AJAX response so the
	 * just-minted key appears in the list without a page reload, using the exact
	 * same server-authored markup (no client-side row building to drift).
	 *
	 * @since 5.2.0
	 *
	 * @return string Rendered api-key-list.php output.
	 */
	public static function render_api_key_list_html(): string {
		// Buffer first so nothing the data gathering might emit (e.g. a DB notice)
		// can leak into the surrounding response and corrupt it.
		ob_start();

		list( $key_rows, $newest_key_id ) = self::get_sorted_plugin_key_rows();

		$active_window     = Auth_Controller::active_window_seconds();
		$transport_enabled = Features::is_wpcom_transport_enabled();

		// Roll each key's connection rows up into a single status so the Keys pill
		// agrees with the Connections table for the same key (SHIPSTN-142). Uses the
		// same recency window, host, and live WordPress.com link state the
		// Connections list uses, so both sides classify identically.
		$current_host       = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$wpcom_connection   = \WooCommerce\Shipping\ShipStation\Main::instance()->get_wpcom_connection();
		$is_wpcom_connected = ( null !== $wpcom_connection && $wpcom_connection->is_connected() );

		$connections_by_key = array();
		foreach ( Connection_Log::all() as $connection_row ) {
			$connections_by_key[ (int) $connection_row['key_id'] ][] = $connection_row;
		}
		foreach ( $key_rows as $index => $key_row ) {
			$rows_for_key                        = isset( $connections_by_key[ (int) $key_row['key_id'] ] ) ? $connections_by_key[ (int) $key_row['key_id'] ] : array();
			$key_rows[ $index ]['rollup_status'] = Connection_Log::key_rollup_status( $rows_for_key, $active_window, $transport_enabled, $current_host, $is_wpcom_connected );
		}

		wc_get_template(
			'api-key-list.php',
			array(
				'rows'              => $key_rows,
				'active_window'     => $active_window,
				'transport_enabled' => $transport_enabled,
				'date_format'       => get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				'newest_key_id'     => $newest_key_id,
			),
			'',
			WC_SHIPSTATION_ABSPATH . 'templates/'
		);

		return (string) ob_get_clean();
	}

	/**
	 * The Unix timestamp at which a never-used key will be auto-removed by the
	 * orphan prune, or 0 when it carries no countdown.
	 *
	 * No countdown when the key cannot actually be pruned: the newest plugin row
	 * is always protected, and a row with no recorded mint time has an unknowable
	 * age. The deadline is surfaced as a machine instant; relative-time.js renders
	 * the live "auto-deletes in …" countdown in the viewer's locale.
	 *
	 * Static so the API key list template can compute the deadline without an
	 * instance handle.
	 *
	 * @internal Template render helper; not part of the public plugin API.
	 *
	 * @since 5.2.0
	 *
	 * @param int  $key_id    Key row id.
	 * @param bool $is_newest Whether this is the prune-protected newest row.
	 *
	 * @return int Deadline Unix timestamp, or 0 when no countdown applies.
	 */
	public static function prune_deadline_ts( int $key_id, bool $is_newest ): int {
		// The newest key is never auto-removed (it's the one you likely just
		// generated and are about to paste into ShipStation).
		if ( $is_newest ) {
			return 0;
		}

		$minted_at = Auth_Controller::get_key_minted_at( $key_id );
		if ( null === $minted_at ) {
			return 0;
		}

		return $minted_at + Auth_Controller::orphan_ttl_seconds();
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
