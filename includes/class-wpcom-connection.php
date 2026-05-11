<?php
/**
 * WPCOM connection wrapper.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\Jetpack\Config as Jetpack_Config;
use Automattic\Jetpack\Connection\Manager as Jetpack_Connection_Manager;
use Jetpack_Options;

/**
 * Thin facade over Jetpack's Connection Manager.
 */
class WPCOM_Connection {

	/**
	 * Jetpack connection slug used to identify this plugin in the Connection Manager.
	 */
	const SLUG = 'woocommerce-shipstation';

	/**
	 * Human-readable plugin name registered with the Jetpack connection.
	 */
	const NAME = 'ShipStation for WooCommerce';

	/**
	 * Pending flow: merchant clicked "Connect to WordPress.com".
	 */
	const ACTION_CONNECT = 'connect';

	/**
	 * Pending flow: merchant clicked "Disconnect from WordPress.com".
	 */
	const ACTION_DISCONNECT = 'disconnect';

	/**
	 * Per-user transient carrying the pending action that kicked off the current flow.
	 * Cleared when the resulting notice is rendered or the TTL elapses.
	 */
	const ACTION_TRANSIENT = 'shipstation_wpcom_action';

	/**
	 * TTL for the pending-action transient. Short enough to bound the "stale notice
	 * on next admin visit" window, long enough to cover the WPCOM authorize round-trip.
	 */
	const ACTION_TRANSIENT_TTL = 90;

	/**
	 * Cached Jetpack Connection Manager instance.
	 *
	 * @var Jetpack_Connection_Manager|null
	 */
	private ?Jetpack_Connection_Manager $manager = null;

	/**
	 * Guards bootstrap() against double-registration of hooks.
	 *
	 * @var bool
	 */
	private bool $bootstrapped = false;

	/**
	 * Register the connection package with Jetpack and wire admin hooks.
	 * Idempotent: guarded by $bootstrapped so repeat calls don't double-register hooks.
	 */
	public function bootstrap(): void {
		if ( $this->bootstrapped || ! $this->is_jetpack_autoloader_ready() ) {
			return;
		}
		$this->bootstrapped = true;

		$config = new Jetpack_Config();
		$config->ensure(
			'connection',
			array(
				'slug' => self::SLUG,
				'name' => self::NAME,
			)
		);

		add_action( 'admin_post_shipstation_wpcom_connect', array( $this, 'handle_connect_action' ) );
		add_action( 'admin_post_shipstation_wpcom_disconnect', array( $this, 'handle_disconnect_action' ) );
		// Surgical: only intercept the Calypso post-authorize landing on
		// `?page=jetpack`. Other denied admin pages keep their normal
		// "Sorry, you are not allowed to access this page." wp_die.
		add_action( 'admin_page_access_denied', array( $this, 'maybe_redirect_post_authorize_landing' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
		add_action( 'jetpack_client_authorize_error', array( $this, 'log_authorize_error' ) );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_wpcom_redirect_hosts' ) );
	}

	/**
	 * Bounce the merchant from `?page=jetpack` (the Calypso post-authorize
	 * landing) back to the ShipStation settings tab when our connect/disconnect
	 * action transient is set.
	 *
	 * Hooked on `admin_page_access_denied` so we run immediately before WP would
	 * otherwise wp_die with "Sorry, you are not allowed to access this page."
	 * (the Jetpack admin menu requires a cap that's only present when the
	 * Jetpack core plugin is installed).
	 *
	 * Tightened compared to maybe_redirect_to_settings(): only intercepts the
	 * specific `?page=jetpack` URL — any other denied admin page that happens
	 * to fire this hook during our 90s transient window is left to its normal
	 * wp_die so we don't yank merchants away from unrelated permission denials.
	 * (Network admin context is skipped inside maybe_redirect_to_settings(),
	 * so both this hook and the admin_init hook share that protection.)
	 */
	public function maybe_redirect_post_authorize_landing(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'jetpack' !== $page ) {
			return;
		}

		$this->maybe_redirect_to_settings();
	}

	/**
	 * Append the Jetpack/WPCOM hosts to allowed_redirect_hosts so wp_safe_redirect()
	 * accepts our outbound jump from handle_connect_action() to jetpack.wordpress.com.
	 *
	 * Mirrors the host list Jetpack itself adds inside Webhooks\Authorize_Redirect::handle().
	 *
	 * @param array $hosts Existing allowed redirect hosts.
	 * @return array Hosts with Jetpack/WPCOM/Calypso entries appended.
	 */
	public function allow_wpcom_redirect_hosts( $hosts ): array {
		$hosts   = is_array( $hosts ) ? $hosts : array();
		$hosts[] = 'jetpack.com';
		$hosts[] = 'jetpack.wordpress.com';
		$hosts[] = 'wordpress.com';
		$hosts[] = 'calypso.localhost';
		$hosts[] = 'wpcalypso.wordpress.com';
		$hosts[] = 'horizon.wordpress.com';

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Whether the site has a Jetpack connection to WPCOM.
	 *
	 * Requires both site-level (`is_connected`) and a connected owner
	 * (`has_connected_owner`) — matches WooCommerce Shipping's definition in
	 * classes/class-wc-connect-jetpack.php so we don't treat a half-completed
	 * registration (site token written, no user-token yet) as connected.
	 *
	 * @return bool True when Jetpack reports both a site connection and an owner.
	 */
	public function is_connected(): bool {
		$manager = $this->get_manager();
		return null !== $manager && $manager->is_connected() && $manager->has_connected_owner();
	}

	/**
	 * Return the site's WPCOM blog id, or null if the site isn't registered.
	 *
	 * @return int|null WPCOM blog id when known, otherwise null.
	 */
	public function get_blog_id(): ?int {
		if ( ! class_exists( Jetpack_Options::class ) ) {
			return null;
		}
		$id = Jetpack_Options::get_option( 'id' );
		return $id ? (int) $id : null;
	}

	/**
	 * Build the Jetpack site-level authorize URL.
	 *
	 * Uses the default `auth_type=calypso` flow for the smoothest WPCOM-side UI
	 * and appends a `from=woocommerce-shipstation` query arg so WordPress.com
	 * recognizes the originating plugin and renders the standalone-plugin
	 * authorize UI rather than landing the merchant on the generic Jetpack admin
	 * page. Same pattern WooCommerce Shipping uses in
	 * classes/class-wc-connect-jetpack.php.
	 *
	 * @param string $return_url Where to redirect the merchant after the flow completes. Defaults to the ShipStation settings tab.
	 * @return string|null Null if the Connection package is unavailable.
	 */
	public function get_authorize_url( string $return_url = '' ): ?string {
		$manager = $this->get_manager();
		if ( null === $manager ) {
			return null;
		}
		if ( '' === $return_url ) {
			$return_url = self::settings_url();
		}
		return add_query_arg(
			array( 'from' => self::SLUG ),
			$manager->get_authorization_url( null, $return_url )
		);
	}

	/**
	 * Disconnect the site from WPCOM.
	 *
	 * Manager::disconnect_site() returns null on success and false when other
	 * plugins still hold the connection, so we verify by re-checking state.
	 *
	 * @return bool True if the site ended up disconnected.
	 */
	public function disconnect(): bool {
		$manager = $this->get_manager();
		if ( null === $manager ) {
			return false;
		}
		$manager->disconnect_site();
		return ! $manager->is_connected();
	}

	/**
	 * Admin-post handler: register the site if needed and kick off the Jetpack
	 * authorize flow. Records the action so the return-trip lands on the settings tab.
	 */
	public function handle_connect_action(): void {
		$this->assert_admin_action_allowed( 'shipstation_wpcom_connect' );
		$this->set_action( self::ACTION_CONNECT );

		$manager = $this->get_manager();
		if ( null === $manager ) {
			Logger::error( 'WPCOM connect: Jetpack Connection Manager unavailable (autoloader not ready).' );
			$this->redirect_to_settings();
			return;
		}

		if ( ! $manager->is_connected() ) {
			$result = $manager->try_registration();
			if ( is_wp_error( $result ) ) {
				Logger::error( 'WPCOM connect: site registration failed: ' . $result->get_error_message() );
				$this->redirect_to_settings();
				return;
			}
		}

		$url = $this->get_authorize_url();
		if ( null === $url ) {
			Logger::error( 'WPCOM connect: could not build the authorize URL.' );
			$this->redirect_to_settings();
			return;
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Admin-post handler: disconnect the site from WPCOM and return to the settings tab.
	 */
	public function handle_disconnect_action(): void {
		$this->assert_admin_action_allowed( 'shipstation_wpcom_disconnect' );
		if ( ! $this->disconnect() ) {
			Logger::error( 'WPCOM disconnect: site is still connected after disconnect_site() call.' );
		} elseif ( ! wp_next_scheduled( 'jetpack_v2_heartbeat' ) ) {
			/**
			 * Re-schedule the Jetpack heartbeat cron event immediately after disconnect.
			 *
			 * Manager::disconnect_site() unschedules `jetpack_v2_heartbeat`. On the
			 * *next* page load Jetpack's plugins_loaded callback re-instantiates Heartbeat
			 * and re-schedules the event — but plugins_loaded fires before
			 * after_setup_theme, so the wp_schedule_event() call triggers WC's
			 * cron_schedules filter callback (WC_Install::cron_schedules) which calls
			 * __('Monthly', 'woocommerce') before WP 6.7's textdomain timing check passes.
			 * That emits a "_doing_it_wrong" notice, the notice text echoes into the
			 * response body, and the settings page's admin-header.php then fails with
			 * "Cannot modify header information — headers already sent."
			 *
			 * Re-scheduling here (post-init, with after_setup_theme already fired) is
			 * safe and means Jetpack's next plugins_loaded run finds an existing schedule
			 * and skips the problematic wp_schedule_event() call.
			 */
			wp_schedule_event( time(), 'daily', 'jetpack_v2_heartbeat' );
		}
		$this->set_action( self::ACTION_DISCONNECT );
		$this->redirect_to_settings();
	}

	/**
	 * Log the WP_Error surfaced by Jetpack's webhook when authorize() fails.
	 *
	 * Unconditionally logs, so errors that fire outside our originating flow (other
	 * Jetpack consumers, user-context-less webhook callbacks) are still captured.
	 *
	 * @param mixed $error WP_Error from Jetpack; loosely typed because the action is shared.
	 */
	public function log_authorize_error( $error ): void {
		$detail = is_wp_error( $error ) ? $error->get_error_message() : 'unknown error';
		$scope  = '' === $this->get_action() ? 'external flow' : 'ShipStation flow';
		Logger::error( sprintf( 'WPCOM authorize failed (%s): %s', $scope, $detail ) );
	}

	/**
	 * If the merchant landed on any admin page while a connect/disconnect flow
	 * is still pending, bounce them to the ShipStation settings tab so the
	 * notice renders in context.
	 */
	public function maybe_redirect_to_settings(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || is_network_admin() ) {
			return;
		}
		if ( '' === $this->get_action() ) {
			return;
		}
		if ( $this->is_shipstation_settings_screen() ) {
			return;
		}

		wp_safe_redirect( self::settings_url() );
		exit;
	}

	/**
	 * Render a success/error admin notice on the ShipStation settings tab based on
	 * the action transient and the resulting connection state, then clear the action.
	 */
	public function render_admin_notice(): void {
		if ( ! $this->is_shipstation_settings_screen() ) {
			return;
		}

		$action = $this->get_action();
		if ( '' === $action ) {
			return;
		}
		$this->clear_action();

		$connected = $this->is_connected();
		$success   = ( self::ACTION_CONNECT === $action && $connected ) || ( self::ACTION_DISCONNECT === $action && ! $connected );
		$message   = $this->notice_message_for( $action, $success );

		printf(
			'<div class="%s is-dismissible"><p>%s</p></div>',
			esc_attr( $success ? 'notice notice-success' : 'notice notice-error' ),
			esc_html( $message )
		);
	}

	/**
	 * Return the translated notice message for the given action/outcome pair.
	 *
	 * @param string $action  One of self::ACTION_CONNECT or self::ACTION_DISCONNECT.
	 * @param bool   $success Whether the action achieved the desired end state.
	 * @return string Translated, user-facing message text.
	 */
	private function notice_message_for( string $action, bool $success ): string {
		if ( self::ACTION_CONNECT === $action ) {
			return $success
				? __( 'Successfully connected to WordPress.com.', 'woocommerce-shipstation-integration' )
				: __( 'Could not connect to WordPress.com. Please try again.', 'woocommerce-shipstation-integration' );
		}

		return $success
			? __( 'Disconnected from WordPress.com.', 'woocommerce-shipstation-integration' )
			: __( 'Could not disconnect from WordPress.com. Please try again.', 'woocommerce-shipstation-integration' );
	}

	/**
	 * Store the current flow's action for the current user.
	 *
	 * Rejects unknown action identifiers so typos fail loudly rather than rendering
	 * the wrong notice branch.
	 *
	 * @param string $action One of self::ACTION_CONNECT or self::ACTION_DISCONNECT.
	 */
	private function set_action( string $action ): void {
		if ( self::ACTION_CONNECT !== $action && self::ACTION_DISCONNECT !== $action ) {
			Logger::error( 'WPCOM: refusing to set unknown pending action: ' . $action );
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		set_transient( self::ACTION_TRANSIENT . '_' . $user_id, $action, self::ACTION_TRANSIENT_TTL );
	}

	/**
	 * Read the current user's pending action, or '' if none is queued or the value
	 * is not one of the known action constants.
	 *
	 * @return string Stored action (self::ACTION_CONNECT or self::ACTION_DISCONNECT),
	 *                or '' when nothing valid is pending.
	 */
	private function get_action(): string {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return '';
		}
		$action = get_transient( self::ACTION_TRANSIENT . '_' . $user_id );
		if ( self::ACTION_CONNECT === $action || self::ACTION_DISCONNECT === $action ) {
			return (string) $action;
		}
		return '';
	}

	/**
	 * Clear the current user's pending action.
	 */
	private function clear_action(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		delete_transient( self::ACTION_TRANSIENT . '_' . $user_id );
	}

	/**
	 * Redirect to the ShipStation settings tab and end the request.
	 */
	private function redirect_to_settings(): void {
		wp_safe_redirect( self::settings_url() );
		exit;
	}

	/**
	 * Absolute URL of the ShipStation settings tab.
	 *
	 * @return string Fully qualified admin URL for the ShipStation integration tab.
	 */
	private static function settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=integration&section=shipstation' );
	}

	/**
	 * Whether the current admin request is for the ShipStation settings tab.
	 *
	 * @return bool True when page/tab/section query args match the ShipStation integration tab.
	 */
	private function is_shipstation_settings_screen(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only page context.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable

		return 'wc-settings' === $page && 'integration' === $tab && 'shipstation' === $section;
	}

	/**
	 * Verify the nonce and capability for a settings-page action. wp_die()s on failure.
	 *
	 * @param string $nonce_action Nonce action name expected on the incoming request.
	 */
	private function assert_admin_action_allowed( string $nonce_action ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately below.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'woocommerce-shipstation-integration' ) );
		}
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'woocommerce-shipstation-integration' ) );
		}
	}

	/**
	 * Lazy-instantiate and cache the Jetpack Connection Manager.
	 *
	 * @return Jetpack_Connection_Manager|null Manager instance, or null when the Connection package is unavailable.
	 */
	private function get_manager(): ?Jetpack_Connection_Manager {
		if ( null !== $this->manager ) {
			return $this->manager;
		}
		if ( ! $this->is_jetpack_autoloader_ready() ) {
			return null;
		}
		$this->manager = new Jetpack_Connection_Manager( self::SLUG );
		return $this->manager;
	}

	/**
	 * Whether Jetpack's Composer autoloader has loaded the Connection package.
	 *
	 * @return bool True when the Manager class is available to instantiate.
	 */
	private function is_jetpack_autoloader_ready(): bool {
		return class_exists( Jetpack_Connection_Manager::class );
	}
}
