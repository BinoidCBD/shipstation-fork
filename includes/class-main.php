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
use WooCommerce\Shipping\ShipStation\Checkout\Checkout_Rates_Chosen_Rate;
use WooCommerce\Shipping\ShipStation\Checkout\Checkout_Rates_Classic_Label;
use WooCommerce\Shipping\ShipStation\Checkout\Checkout_Rates_Options;
use WooCommerce\Shipping\ShipStation\Checkout\Checkout_Rates_Shipping_Method;
use WooCommerce\Shipping\ShipStation\GPDT\Prepaid_Duties;
use WooCommerce\Shipping\ShipStation\Integrations\PayPal_Payments_Integration;
use WooCommerce\Shipping\ShipStation\REST_API_Loader;
use WC_ShipStation_Privacy;
use WC_Shipstation_API;

/**
 * WC_Shipstation Class
 */
class Main {
	/**
	 * REST route that records a shipment, used to classify the request before
	 * WordPress has routed it.
	 *
	 * Duplicates the route Orders_Controller::register_routes() registers: the
	 * classification runs at init:9, before any controller exists to ask. If
	 * the route moves there, it must move here too; a test pins the two
	 * against the registered route table.
	 *
	 * @since 5.3.3
	 *
	 * @var string
	 */
	private const SHIPMENTS_ROUTE = 'wc-shipstation/v1/orders/shipments';

	/**
	 * Instance to call certain functions globally within the plugin
	 *
	 * @var Main|null
	 */
	protected static ?Main $instance = null;

	/**
	 * WPCOM connection facade. Null until the feature flag enables it.
	 *
	 * @var WPCOM_Connection|null
	 */
	protected ?WPCOM_Connection $wpcom_connection = null;

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
		add_action( 'woocommerce_refund_created', array( $this, 'save_refund_meta_data' ), 10, 2 );
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
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Legacy public constant name retained for backward compatibility.
			define( 'WC_SHIPSTATION_EXPORT_LIMIT', 100 );
		}

		$this->load_files();
		$this->maybe_init_wpcom_connection();

		// Must land before init:10, where WooCommerce reads
		// woocommerce_defer_transactional_emails once and wires the transactional
		// email actions accordingly. Deferred to init:9 rather than run here:
		// this is plugins_loaded:10, which is before themes and most plugins have
		// registered anything, so the opt-out filter would be unreachable from
		// the places merchants actually hook - including their own init
		// callbacks, which init:9 leaves priorities 1-8 for (SHIPSTN-165).
		add_action( 'init', array( __CLASS__, 'maybe_defer_shipment_emails' ), 9 );

		// Create/upgrade the ShipStation connection-log table when needed
		// (version-gated; a no-op once installed).
		Connection_Log::maybe_install();

		// Wire the daily Action Scheduler job that prunes never-used plugin API
		// keys (SHIPSTN-142 credentials redesign). Registers its handler and
		// schedules the recurring action on `init`.
		Auth_Controller::register_orphan_prune();

		add_action( 'before_woocommerce_init', array( $this, 'before_woocommerce_init' ) );
		add_action( 'woocommerce_init', array( $this, 'load_rest_api' ) );

		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_methods' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_internal_order_item_meta' ) );

		// Toggling Checkout Rates on or off must invalidate WC's cached package
		// rates, otherwise checkout keeps serving rates calculated while the
		// feature was on (or omits them after it is turned back on).
		add_action(
			'update_option_woocommerce_shipstation_settings',
			array( Checkout_Rates_Options::class, 'flush_shipping_cache_on_settings_change' ),
			10,
			2
		);

		// Moving the store across the supported-country boundary turns Checkout Rates
		// on or off just as the toggle does, and WC regenerates no shipping cache of
		// its own when the base country changes.
		add_action(
			'update_option_woocommerce_default_country',
			array( Checkout_Rates_Options::class, 'flush_shipping_cache_on_base_country_change' ),
			10,
			2
		);

		// Any shipping calculation that runs outside a checkout context (a classic
		// add-to-cart page render, the wc-ajax=get_refreshed_fragments call that trails an
		// AJAX add-to-cart, an unrelated front-end hit that mutates the cart) makes WC cache
		// a package rate set with no ShipStation rate, which checkout then reuses via the
		// matching package hash (SHIPSTN-157). Note when WC caches a set, then discard it on
		// shutdown — before WC_Session_Handler persists the session (its save runs on
		// shutdown at priority 20) — unless it was produced in a checkout context. Shutdown
		// is the one point that runs after every rate-caching path in the request, so a
		// poisoned set can never reach the database and be replayed at checkout.
		add_filter(
			'woocommerce_package_rates',
			array( Checkout_Rates_Options::class, 'note_package_rates_calculated' )
		);
		add_action(
			'shutdown',
			array( Checkout_Rates_Options::class, 'discard_non_checkout_rate_cache' ),
			5
		);

		// Surface each ShipStation checkout rate's delivery estimate and description on the
		// classic (shortcode) cart/checkout label. Registered unconditionally so it is not
		// dropped on sites that enable the feature flag after this runs (e.g. a theme's
		// functions.php); the callback no-ops for non-ShipStation rates.
		Checkout_Rates_Classic_Label::register();

		// Keep the customer's rate when a new quote only reorders the rates.
		Checkout_Rates_Chosen_Rate::register();

		// Give PayPal Payments checkout requests ShipStation rates.
		// Always registered: its callbacks do nothing outside PayPal requests.
		PayPal_Payments_Integration::register();
	}

	/**
	 * Whether the current request is a ShipStation shipment notification write.
	 *
	 * Covers the legacy XML shipnotify endpoint and the REST
	 * `POST /wc-shipstation/v1/orders/shipments` route, in every URL form each
	 * one is served under.
	 *
	 * It reads the raw request because it has to answer before `init`, long
	 * before `rest_api_init` has matched a route -- the same signal
	 * `wc_is_rest_api_request()` uses. `$_GET` is preferred where available
	 * because PHP has already decoded it and the URI has not.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.3
	 *
	 * @return bool
	 */
	public static function is_shipment_notification_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended --- Read-only request classification; both endpoints carry their own authentication.
		$wc_api     = isset( $_GET['wc-api'] ) ? sanitize_text_field( wp_unslash( $_GET['wc-api'] ) ) : '';
		$action     = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
		$rest_route = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Decoded before sanitizing: sanitize_text_field() strips percent-encoded
		// octets outright, so decoding afterwards would find nothing left to
		// decode and an encoded path could never match the route.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --- sanitize_text_field() wraps the whole expression; the sniff cannot see through the rawurldecode() between it and wp_unslash().

		// Only the path may carry the route; a query string that merely mentions
		// it (`?next=/wc-api/wc_shipstation`) must not classify.
		$path = untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );

		// XML surface. WooCommerce serves both `?wc-api=wc_shipstation` and the
		// `/wc-api/wc_shipstation/` rewrite, and only the first populates $_GET.
		// Case-insensitive on both forms: WooCommerce's legacy API dispatcher
		// lowercases the wc-api value before matching, so `WC_ShipStation` is
		// served by the handler and must classify the same.
		if ( 'shipnotify' === $action ) {
			if ( 0 === strcasecmp( 'wc_shipstation', $wc_api ) ) {
				return true;
			}

			// Anchored on the right only: the segment must end the path or be
			// followed by `/`, so a sibling endpoint like
			// `/wc-api/wc_shipstation_pro/` does not classify. The left stays
			// open because a subdirectory install serves the endpoint at
			// `/shop/wc-api/wc_shipstation/`.
			if ( '' !== $path && 1 === preg_match( '#/wc-api/wc_shipstation(/|$)#i', $path ) ) {
				return true;
			}
		}

		// REST surface, POST only: the shipments route registers no other
		// method. The method gate and the anchored matches below keep a request
		// that merely mentions the route (a query string, a docs page path)
		// from flipping its own email delivery to deferred.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			return false;
		}

		$route = '/' . self::SHIPMENTS_ROUTE;

		// $_GET covers both `?rest_route=` forms, including the percent-encoded
		// one a re-encoding proxy produces (PHP has already decoded it).
		if ( '' !== $rest_route && untrailingslashit( '/' . ltrim( $rest_route, '/' ) ) === $route ) {
			return true;
		}

		// Pretty-permalink form: the decoded path must end with the route.
		return strlen( $path ) >= strlen( $route ) && substr( $path, -strlen( $route ) ) === $route;
	}

	/**
	 * Keep the emails a shipment notification triggers out of the request.
	 *
	 * Recording a shipment writes a note and transitions the order, and
	 * WooCommerce sends the customer-note and completed-order emails inline off
	 * the back of that. Rendering an email is an unbounded amount of core and
	 * third-party work -- template hooks, the bundled CSS inliner, the mail
	 * transport -- and a fatal anywhere in it took the response down (SHIPSTN-165).
	 *
	 * The Order_Util helpers contain the failures that can be caught; this covers
	 * the ones that cannot. Memory exhaustion in the CSS inliner is an E_ERROR,
	 * so the only defence is to not render the email in this request at all.
	 * WooCommerce's own deferral hands the mail to Action Scheduler, which sends
	 * it moments later with per-email failure isolation, so nothing is lost.
	 *
	 * That last sentence is only true from WooCommerce 10.8, where
	 * `DeferredEmailQueue` was introduced. Before it, the same filter built a
	 * `WC_Background_Emailer`, and that object's constructors are the only thing
	 * that register the queue's two drain handlers -- the
	 * `wp_ajax_nopriv_wp_{blog_id}_wc_emailer` loopback worker and the
	 * `wp_{blog_id}_wc_emailer_cron` healthcheck. Because the filter is turned on
	 * for shipment notifications alone, the object exists only inside those
	 * requests, and the loopback the queue posts to itself
	 * (`admin-ajax.php?action=wp_{blog_id}_wc_emailer`) is not one of them. Nothing
	 * would ever drain the queue: the mail would never be sent and a
	 * `wp_{blog_id}_wc_emailer_batch_*` options row would leak per notification.
	 * Silently dropping the customer's shipment email is worse than the 500 this
	 * is fixing, so below 10.8 the deferral is skipped and delivery stays as it is
	 * today -- the Order_Util containment still covers the note-hook and
	 * email-render clusters there, leaving only the uncatchable OOM cluster,
	 * which is the current behaviour on those versions anyway. The probe is the
	 * `deferred_transactional_emails` registration in the public
	 * `FeaturesUtil::get_features()`, which shipped with the queue in 10.8; the
	 * queue class itself is off limits, like everything in WooCommerce's
	 * Internal namespace. Presence, not `feature_is_enabled()`: the toggle only
	 * sets the filter's default, and defaults to off. If the registration ever
	 * disappears, this fails closed onto today's behaviour.
	 *
	 * Two priorities matter here:
	 *  - `init:9` -- late enough for themes and plugins to have registered, and
	 *    for a merchant's own `init` callback at priorities 1-8 to reach the
	 *    opt-out filter, while WooCommerce has not yet read its own filter at
	 *    `init:10`.
	 *  - `99` on the deferral itself, so an ordinary site-wide opt-out at the
	 *    default priority does not quietly reinstate the inline render. That is
	 *    precedence, not a guarantee: a callback at 100 or higher still wins,
	 *    which is why the plugin ships its own opt-out filter.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.3
	 *
	 * @return void
	 */
	public static function maybe_defer_shipment_emails(): void {
		if ( ! self::is_shipment_notification_request() ) {
			return;
		}

		// The feature registration arrived with the Action Scheduler queue in
		// WooCommerce 10.8. Below that the filter routes to WC_Background_Emailer,
		// and nothing drains its queue -- see the docblock.
		if ( ! array_key_exists( 'deferred_transactional_emails', FeaturesUtil::get_features() ) ) {
			return;
		}

		/**
		 * Filters whether the emails triggered by a ShipStation shipment
		 * notification are deferred to Action Scheduler instead of sent inline.
		 *
		 * Return false to restore inline sending, at the cost of letting a fatal
		 * in the email render fail the whole shipment notification.
		 *
		 * @since 5.3.3
		 *
		 * @param bool $defer Whether to defer. Default true.
		 */
		if ( ! apply_filters( 'woocommerce_shipstation_defer_shipment_emails', true ) ) {
			return;
		}

		add_filter( 'woocommerce_defer_transactional_emails', '__return_true', 99 );
	}

	/**
	 * Bootstrap the WPCOM/Jetpack connection when the feature flag is on.
	 *
	 * @return void
	 */
	protected function maybe_init_wpcom_connection(): void {
		// The transport toggle gates whether ShipStation requests are *routed*
		// through WordPress.com. In the admin, though, always make the connection
		// available so the settings tab can render — and operate — the
		// connect/disconnect controls regardless of the toggle (the controls are
		// CSS-hidden until the checkbox is ticked, so the status is "already there"
		// when it is enabled). The bootstrap's admin hooks all no-op without a
		// pending connect/disconnect action, so this is safe on any admin page.
		// Frontend and REST stay gated by the toggle, preserving request routing.
		if ( ! Features::is_wpcom_transport_enabled() && ! is_admin() ) {
			return;
		}

		// get_wpcom_connection() re-runs this lazily, so a caller can reach it
		// before load_files() has required the class (early boot or a partial
		// install). Stay at "no facade" rather than fataling.
		if ( ! class_exists( WPCOM_Connection::class ) ) {
			return;
		}

		$this->wpcom_connection = new WPCOM_Connection();
		$this->wpcom_connection->bootstrap();
	}

	/**
	 * WPCOM connection facade accessor.
	 *
	 * Re-runs the gated init when the facade is missing: the settings-checkbox
	 * source of the feature flag can turn on after plugins_loaded (the settings
	 * save persists mid-request), in which case maybe_init_wpcom_connection()
	 * already skipped. Late bootstrap still wires this request's admin hooks;
	 * Jetpack's own plugins_loaded-time configuration completes on the next load.
	 *
	 * @return WPCOM_Connection|null Null when the feature flag is off.
	 */
	public function get_wpcom_connection(): ?WPCOM_Connection {
		if ( null === $this->wpcom_connection ) {
			$this->maybe_init_wpcom_connection();
		}

		return $this->wpcom_connection;
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
		// Loaded first: the integration constructed on `init` and the settings
		// data dereference WooCommerce enums through this helper, which falls back
		// to slugs on WooCommerce versions that predate those enums (SHIPSTN-152).
		require_once WC_SHIPSTATION_ABSPATH . 'includes/class-enum-helper.php';
		require_once WC_SHIPSTATION_ABSPATH . 'includes/class-features.php';
		require_once WC_SHIPSTATION_ABSPATH . 'includes/class-order-util.php';
		require_once WC_SHIPSTATION_ABSPATH . 'includes/gpdt/class-prepaid-duties.php';
		require_once WC_SHIPSTATION_ABSPATH . 'includes/class-connection-log.php';
		require_once WC_SHIPSTATION_ABSPATH . 'includes/class-wpcom-connection.php';
		include_once WC_SHIPSTATION_ABSPATH . 'includes/class-wc-shipstation-integration.php';
		include_once WC_SHIPSTATION_ABSPATH . 'includes/class-auth-controller.php';
		include_once WC_SHIPSTATION_ABSPATH . 'includes/class-global-connection-banner.php';
		include_once WC_SHIPSTATION_ABSPATH . 'includes/class-logger.php';

		// Options class is side-effect-free and is reached from data-settings.php
		// regardless of the feature flag's timing, so load it unconditionally.
		// It owns SHIPPING_METHOD_ID, so the settings gate and zone-method
		// lookups need nothing from the shipping method class. The shipping
		// method and the rest of the Checkout Rates infrastructure (validator,
		// builder, mapper, API client) load lazily in register_shipping_methods()
		// because they're only needed when actually calculating rates at checkout.
		include_once WC_SHIPSTATION_ABSPATH . 'includes/checkout/class-checkout-rates-options.php';

		// Classic-checkout label formatter. Like the options class it is lightweight and
		// side-effect-free (no WC_Shipping_Method parent), so it loads unconditionally and
		// its filter callback stays resolvable no matter when the feature flag is toggled.
		include_once WC_SHIPSTATION_ABSPATH . 'includes/checkout/class-checkout-rates-classic-label.php';

		// Keeps the chosen rate across reordered quotes. Inert until registered.
		include_once WC_SHIPSTATION_ABSPATH . 'includes/checkout/class-checkout-rates-chosen-rate.php';

		// Third-party plugin integrations. Inert until registered.
		include_once WC_SHIPSTATION_ABSPATH . 'includes/integrations/class-paypal-payments-integration.php';

		include_once WC_SHIPSTATION_ABSPATH . 'includes/class-wc-shipstation-privacy.php';

		// Guarded because the test bootstrap declares a minimal WC_Shipstation_API
		// stub (the full plugin is never loaded there), and including the real
		// class over it is a fatal. No-op in production: nothing else declares it.
		// The probe must not autoload: the Jetpack autoloader's manifest maps this
		// symbol to tests/bootstrap.php (its generator scans autoload-dev paths
		// even under --no-dev), and loading that file fatals every request.
		if ( ! class_exists( 'WC_Shipstation_API', false ) ) {
			include_once WC_SHIPSTATION_ABSPATH . 'includes/class-wc-shipstation-api.php';
		}

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

		// Checkout Rates can only be provisioned over the REST API — ShipStation pushes
		// the rates URL via a REST endpoint and there is no XML path. Without a stored
		// rates URL the method can never return rates, so don't offer it in the shipping
		// zone's method list. This and the enabled check above mirror the runtime gates in
		// Checkout_Rates_Shipping_Method::calculate_shipping().
		if ( ! Checkout_Rates_Options::is_configured() ) {
			return $methods;
		}

		require_once WC_SHIPSTATION_ABSPATH . 'includes/checkout/interface-checkout-rates-api-client.php';
		require_once WC_SHIPSTATION_ABSPATH . 'includes/checkout/class-shipstation-unit-converter.php';
		require_once WC_SHIPSTATION_ABSPATH . 'includes/checkout/class-checkout-rates-invalid-payload-exception.php';
		require_once WC_SHIPSTATION_ABSPATH . 'includes/checkout/class-checkout-rates-payload-validator.php';
		require_once WC_SHIPSTATION_ABSPATH . 'includes/checkout/class-checkout-rates-request-builder.php';
		require_once WC_SHIPSTATION_ABSPATH . 'includes/checkout/class-checkout-rates-response-mapper.php';
		require_once WC_SHIPSTATION_ABSPATH . 'includes/checkout/class-checkout-rates-api-client.php';
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
	 * Save refund meta data.
	 *
	 * @since 4.9.5
	 *
	 * @param int   $refund_id Refund ID.
	 * @param array $args Refund arguments.
	 */
	public function save_refund_meta_data( $refund_id, $args ) {
		$refund = wc_get_order( $refund_id );

		if ( ! $refund || ! $refund->get_parent_id() ) {
			return;
		}

		$refund->update_meta_data( '_wc_shipstation_refund_args', $args );
		$refund->save_meta_data();
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

	/**
	 * Hide ShipStation Checkout Rates internal shipping-item meta from the admin
	 * order screen and orders list table. The underscore prefix already hides
	 * these from the storefront, emails, and the Store API; wp-admin renders item
	 * meta with an empty hide-prefix, so it needs the allow-list filter instead.
	 *
	 * Fee rows on the admin order screen print no item meta, so the prepaid
	 * duties fee tag never shows there. Its entry here reserves the key instead:
	 * on WooCommerce 11.0+ admin item saves skip the keys on this list, so the
	 * tag cannot be added or edited as custom meta from wp-admin.
	 *
	 * @since 5.0.9
	 * @since 5.4.0 Also reserves the prepaid duties fee tag key.
	 *
	 * @param array $hidden Hidden order item meta keys.
	 *
	 * @return array
	 */
	public function hide_internal_order_item_meta( array $hidden ): array {
		$hidden[] = Checkout_Rates_Options::RATE_CODE_META_KEY;
		$hidden[] = Checkout_Rates_Options::QUOTE_ID_META_KEY;
		$hidden[] = Prepaid_Duties::META_KEY;

		return $hidden;
	}
}
