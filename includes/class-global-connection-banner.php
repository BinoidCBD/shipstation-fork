<?php
/**
 * Global broken-connection admin banner (SHIPSTN-142).
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raises a single, site-wide-dismissable RED banner across the WooCommerce
 * settings screens (every tab EXCEPT the ShipStation tab) when a connection
 * that was previously working has stopped — to pull the merchant's attention
 * back to ShipStation from wherever else in WC settings they happen to be.
 *
 * It fires only for the one break the merchant may NOT already know about:
 * ShipStation has stopped successfully pinging the store while nothing on the
 * store's side changed (an external WordPress.com-side revocation or a
 * ShipStation-side stop both surface as the `inactive` health reason). Every
 * merchant-caused break (a completed local WordPress.com disconnect, the
 * transport switched off, a mid-flow disconnect) is excluded — see
 * {@see Connection_Log::should_show_global_banner()}, which owns the firing
 * rule; this class owns only the render scope and the rendering.
 *
 * The ShipStation tab itself is deliberately excluded: its inline section banner
 * already speaks there, so a second banner would be redundant. The two never
 * show on the same screen.
 *
 * "Previously working" is gated on the success latch
 * ({@see Connection_Log::LAST_SUCCESS_OPTION}), stamped by the REST auth gate on
 * a real successful plugin-key auth. An install whose connection is already
 * broken at upgrade has no latch, so it stays quiet until it pings successfully
 * once and then stops.
 *
 * @since 5.2.0
 */
class Global_Connection_Banner {

	/**
	 * Site option ('time()' epoch when set): the merchant dismissed the banner
	 * site-wide. Admin-only state, so the constant lives here rather than on
	 * Connection_Log. Re-arms automatically: the firing predicate re-fires once a
	 * fresh success lands after the dismiss (last_success_ts > dismissed_at), so
	 * no reset logic is needed here.
	 *
	 * @var string
	 */
	const DISMISS_OPTION = 'woocommerce_shipstation_global_banner_dismissed_at';

	/**
	 * AJAX action that records a dismissal.
	 *
	 * @var string
	 */
	const DISMISS_ACTION = 'shipstation_dismiss_global_banner';

	/**
	 * Nonce action shared with the rest of the settings-tab AJAX surface.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'shipstation_auth_nonce';

	/**
	 * Register the admin-notice render and the dismiss AJAX handler.
	 *
	 * @since 5.2.0
	 *
	 * @return void
	 */
	public function bootstrap(): void {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Render the banner when a previously-working connection has gone silent and
	 * the current screen is a WooCommerce settings tab other than ShipStation.
	 *
	 * Guards run cheap-first: the screen scope and capability gates short-circuit
	 * before any option read, the latch read short-circuits before the disconnect-
	 * intent and (most expensive) health computation, so a never-latched install —
	 * the common case — costs only two get_option() calls and no row scan.
	 *
	 * @since 5.2.0
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		if ( ! $this->is_target_settings_screen() ) {
			return;
		}

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Native WooCommerce capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$last_success_ts = $this->read_epoch_option( Connection_Log::LAST_SUCCESS_OPTION );
		if ( null === $last_success_ts ) {
			// Never observed a real success → the latch is off → the banner can
			// never fire. Skip the health computation entirely.
			return;
		}
		$dismissed_at = $this->read_epoch_option( self::DISMISS_OPTION );

		$connection = Main::instance()->get_wpcom_connection();

		$disconnect_intent = ( null !== $connection && $connection->has_disconnect_intent() );
		if ( $disconnect_intent ) {
			// Local disconnect mid-flow → suppressed regardless of health. Skip the
			// row scan; the predicate would return false anyway.
			return;
		}

		$health_reason = $this->compute_health_reason( $connection );

		if ( ! Connection_Log::should_show_global_banner( $health_reason, $last_success_ts, $dismissed_at, $disconnect_intent ) ) {
			return;
		}

		$this->render_banner();
	}

	/**
	 * AJAX handler: record a site-wide dismissal so the banner stays gone for
	 * every admin until a genuine recover-then-stop re-arms it.
	 *
	 * @since 5.2.0
	 *
	 * @return void
	 */
	public function ajax_dismiss(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed', 'woocommerce-shipstation-integration' ) );
		}

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Native WooCommerce capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'woocommerce-shipstation-integration' ) );
		}

		update_option( self::DISMISS_OPTION, time(), false );

		wp_send_json_success();
	}

	/**
	 * Whether the current admin request is a WooCommerce settings tab OTHER than
	 * the ShipStation tab.
	 *
	 * True for WC settings (any tab) AND NOT (tab=integration AND
	 * section=shipstation). The ShipStation tab is excluded because its inline
	 * section banner already speaks there. Reads $_GET directly with the same
	 * sanitize and nonce-suppression the rest of the settings screen detection
	 * uses (read-only page context).
	 *
	 * @return bool
	 */
	private function is_target_settings_screen(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only page context.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable

		if ( 'wc-settings' !== $page ) {
			return false;
		}

		$is_shipstation_tab = ( 'integration' === $tab && 'shipstation' === $section );

		return ! $is_shipstation_tab;
	}

	/**
	 * Read a site option that stores a UTC epoch, coercing WordPress's absent
	 * sentinel (false) and any empty value to null and everything else to int.
	 *
	 * @param string $option_name Option key.
	 * @return int|null Stored epoch, or null when absent/empty.
	 */
	private function read_epoch_option( string $option_name ): ?int {
		$value = get_option( $option_name, false );

		if ( false === $value || '' === $value ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Compute the connection health reason the SAME way the inline ShipStation
	 * section does, so the global banner's notion of "broken" is identical to the
	 * inline one (one source of truth).
	 *
	 * Mirrors the health block in
	 * {@see \WC_ShipStation_Integration::generate_shipstation_credentials_html()}
	 * exactly, including the same recency window — {@see Auth_Controller::active_window_seconds()},
	 * 24h — that the section verdict and the Connections table's per-row pill use, so
	 * the banner can never warn while a route's pill still reads Active.
	 *
	 * @param WPCOM_Connection|null $connection The WordPress.com connection facade, or null.
	 * @return string Health reason from {@see Connection_Log::health_from_rows()}.
	 */
	private function compute_health_reason( $connection ): string {
		$rows               = Connection_Log::recent_rows();
		$transport_enabled  = Features::is_wpcom_transport_enabled();
		$is_wpcom_connected = ( null !== $connection && $connection->is_connected() );
		$current_host       = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		$health = Connection_Log::health_from_rows( $rows, Auth_Controller::active_window_seconds(), $transport_enabled, $current_host, $is_wpcom_connected );

		return (string) $health['reason'];
	}

	/**
	 * Echo the broken-connection banner markup and the inline dismiss-persistence
	 * script.
	 *
	 * The copy stays general ("stopped syncing"): we can prove the connection went
	 * silent and that nothing local changed, but not whether WordPress.com revoked
	 * the link or ShipStation stopped on its side — so it never claims a specific
	 * cause. The CTA deep-links to the ShipStation tab, whose inline section
	 * carries the recoverable action.
	 *
	 * @return void
	 */
	private function render_banner(): void {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=integration&section=shipstation' );
		?>
		<div class="notice notice-error is-dismissible shipstation-global-connection-notice">
			<p>
				<strong><?php esc_html_e( 'ShipStation has stopped syncing your store', 'woocommerce-shipstation-integration' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( "We haven't received a sync from ShipStation in over a day. Open the ShipStation settings to check your connection and resume order sync.", 'woocommerce-shipstation-integration' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Go to ShipStation settings', 'woocommerce-shipstation-integration' ); ?>
				</a>
			</p>
		</div>
		<?php
		$this->render_dismiss_script();
	}

	/**
	 * Print the small inline script that persists a dismissal to the AJAX action.
	 *
	 * WordPress core's `is-dismissible` only hides the notice client-side; it does
	 * not persist. This handler POSTs the nonce to {@see DISMISS_ACTION} when the
	 * core dismiss button is clicked, so the dismissal survives a reload. An inline
	 * script is used rather than an enqueued file because the banner renders on WC
	 * settings pages where the plugin's settings JS (auth-display.js) is not
	 * enqueued, and the snippet is tiny, single-purpose, and only printed when the
	 * banner actually shows (no separate scope-guarded enqueue plumbing, and no
	 * risk of referencing a missing built `.min.js`).
	 *
	 * @return void
	 */
	private function render_dismiss_script(): void {
		$payload = wp_json_encode(
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::DISMISS_ACTION,
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			)
		);
		?>
		<script>
		( function () {
			var config = <?php echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() emits a safe JS literal. ?>;
			document.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '.shipstation-global-connection-notice .notice-dismiss' );
				if ( ! button ) {
					return;
				}
				var body = new URLSearchParams();
				body.append( 'action', config.action );
				body.append( 'nonce', config.nonce );
				window.fetch( config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} );
			} );
		} )();
		</script>
		<?php
	}
}
