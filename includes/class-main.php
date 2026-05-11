<?php
/**
 * Class WC_Shipstation file.
 * Main class of the plugin.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use WooCommerce\Shipping\ShipStation\Checkout\Checkout_Rates_Shipping_Method;
use WooCommerce\Shipping\ShipStation\REST_API_Loader;
use WC_ShipStation_Privacy;
use WC_Shipstation_API;

/**
 * WC_Shipstation Class
 */
class Main {
	/**
	 * Instance to call certain functions globally within the plugin
	 *
	 * @var Main|null
	 */
	protected static ?Main $instance = null;

	/**
	 * Main Websparks People Singleton.
	 *
	 * Ensures only one instance is loaded or can be loaded.
	 *
	 * @static
	 * @return self Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_filter( 'woocommerce_integrations', array( $this, 'load_integration' ) );
		add_action( 'woocommerce_api_wc_shipstation', array( $this, 'load_api' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WC_SHIPSTATION_FILE ), array( $this, 'api_plugin_action_links' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
	}

	/**
	 * WooCommerce fallback notice.
	 *
	 * @since 4.1.26
	 *
	 * @return void
	 */
	public function missing_wc_notice() {
		/* translators: %s WC download URL link. */
		echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'Shipstation requires WooCommerce to be installed and active. You can download %s here.', 'woocommerce-shipstation-integration' ), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
	}

	/**
	 * Include shipstation class.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_wc_notice' ) );
			return;
		}

		if ( ! defined( 'WC_SHIPSTATION_EXPORT_LIMIT' ) ) {
			define( 'WC_SHIPSTATION_EXPORT_LIMIT', 100 );
		}

		$this->load_files();

		add_action( 'before_woocommerce_init', array( $this, 'before_woocommerce_init' ) );
		add_action( 'woocommerce_init', array( $this, 'load_rest_api' ) );

		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_methods' ) );
	}

	/**
	 * Run instances on time.
	 *
	 * @since 4.4.6
	 */
	public function before_woocommerce_init() {
		new WC_ShipStation_Privacy();
	}

	/**
	 * Include needed files.
	 *
	 * @since 4.4.5
	 */
	public function load_files() {
		require_once WC_SHIPSTATION_ABSPATH . 'includes/class-features.php';
		require_once WC_SHIPSTATION_ABSPATH . 'includes/class-order-util.php';
		include_once WC_SHIPSTATION_ABSPATH . 'includes/class-wc-shipstation-integration.php';
		include_once WC_SHIPSTATION_ABSPATH . 'includes/class-auth-controller.php';
		include_once WC_SHIPSTATION_ABSPATH . 'includes/class-logger.php';
		include_once WC_SHIPSTATION_ABSPATH . 'includes/class-wc-shipstation-privacy.php';
		include_once WC_SHIPSTATION_ABSPATH . 'includes/class-wc-shipstation-api.php';

		// Load REST API loader class file.
		require_once WC_SHIPSTATION_ABSPATH . 'includes/class-rest-api-loader.php';

		// Include the Checkout class if WooCommerce version is 9.7.0 or higher.
		// This class is used to handle the gift message feature in the checkout process.
		if ( version_compare( WC()->version, '9.7.0', '>=' ) ) {
			include_once WC_SHIPSTATION_ABSPATH . 'includes/class-checkout.php';
		}
	}
	/**
	 * Initialize REST API.
	 *
	 * @since 4.5.2
	 */
	public function load_rest_api() {
		// Initialize REST API.
		$rest_loader = new REST_API_Loader();
		$rest_loader->init();
	}

	/**
	 * Define integration.
	 *
	 * @since 1.0.0
	 *
	 * @param array $integrations Integrations.
	 *
	 * @return array Integrations.
	 */
	public function load_integration( $integrations ) {
		$integrations[] = 'WC_ShipStation_Integration';

		return $integrations;
	}

	/**
	 * Register ShipStation shipping methods.
	 *
	 * @since 4.9.6
	 *
	 * @param array $methods Registered shipping methods.
	 *
	 * @return array Shipping methods.
	 */
	public function register_shipping_methods( array $methods ): array {
		if ( ! Features::is_checkout_rates_enabled() ) {
			return $methods;
		}

		require_once WC_SHIPSTATION_ABSPATH . 'includes/checkout/class-checkout-rates-shipping-method.php';
		$methods['shipstation_checkout_rates'] = Checkout_Rates_Shipping_Method::class;

		return $methods;
	}

	/**
	 * Listen for API requests.
	 *
	 * @since 1.0.0
	 */
	public function load_api() {
		new WC_Shipstation_API();
	}

	/**
	 * Added ShipStation custom plugin action links.
	 *
	 * @since 4.1.17
	 * @version 4.1.17
	 *
	 * @param array $links Links.
	 *
	 * @return array Links.
	 */
	public function api_plugin_action_links( $links ) {
		$setting_link = admin_url( 'admin.php?page=wc-settings&tab=integration&section=shipstation' );
		$plugin_links = array(
			'<a href="' . esc_url( $setting_link ) . '">' . __( 'Settings', 'woocommerce-shipstation-integration' ) . '</a>',
			'<a href="https://woocommerce.com/my-account/tickets">' . __( 'Support', 'woocommerce-shipstation-integration' ) . '</a>',
			'<a href="https://docs.woocommerce.com/document/shipstation-for-woocommerce/">' . __( 'Docs', 'woocommerce-shipstation-integration' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Declaring HPOS compatibility.
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', WC_SHIPSTATION_FILE, true );
		}
	}
}
