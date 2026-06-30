<?php
/**
 * WordPress.com connection controls for the ShipStation settings tab
 * (SHIPSTN-142).
 *
 * Sits atop the inline credentials section, under the transport toggle: the
 * connect button when disconnected; the connected state with the guarded
 * disconnect-intent flow (pending → confirm when a direct fallback is detected;
 * blocked + "Dangerously disconnect" otherwise); and the URL-mismatch repair card
 * (delegated to wpcom-mismatch.php). The connection is always initialized in the
 * admin regardless of the transport toggle, and the CSS hides the block until the
 * checkbox is ticked. Rendered by
 * WC_ShipStation_Integration::build_wpcom_controls_html(), which guarantees a
 * non-null connection before including this template.
 *
 * @package WC_ShipStation
 *
 * @var \WooCommerce\Shipping\ShipStation\WPCOM_Connection $connection         Connection facade.
 * @var bool                                               $is_wpcom_connected Whether the site has a live WordPress.com link (the same value the connections table reads).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// wc_get_template() extract()s these vars and include()s this file INSIDE a
// function, so the locals below are function-scoped at runtime, never real
// globals — the prefix sniff just can't see through the include.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! $is_wpcom_connected ) :
	$action_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=shipstation_wpcom_connect' ),
		'shipstation_wpcom_connect'
	);
	?>
	<p>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpcom_status_pill() escapes internally.
		echo WC_ShipStation_Integration::wpcom_status_pill( 'disconnected', __( 'Not connected to WordPress.com', 'woocommerce-shipstation-integration' ), 'dashicons-dismiss' );
		?>
	</p>
	<p class="description"><?php esc_html_e( 'Connect your store to WordPress.com to route ShipStation traffic through it.', 'woocommerce-shipstation-integration' ); ?></p>
	<p><a href="<?php echo esc_url( $action_url ); ?>" class="button button-primary"><?php esc_html_e( 'Connect to WordPress.com', 'woocommerce-shipstation-integration' ); ?></a></p>
	<?php
	return;
endif;

// Slice 4 — identity crisis: connected, but registered to a different site URL
// than the site now uses, so WordPress.com is still delivering proxied
// ShipStation traffic to the old address while is_connected() (local tokens
// only) still reports connected. Surface it as a proper state change — not a
// green "Connected" — and offer a Repair that re-registers on the current URL.
// backfill_connected_url() first so pre-slice-4 connections start tracking from
// now. This precedes the disconnect-intent branches: a stale connection should
// be repaired, not disconnected.
$connection->backfill_connected_url();
if ( $connection->has_url_mismatch() ) {
	wc_get_template(
		'wpcom-mismatch.php',
		array( 'connection' => $connection ),
		'',
		WC_SHIPSTATION_ABSPATH . 'templates/'
	);
	return;
}

if ( ! $connection->has_disconnect_intent() ) :
	if ( $connection->has_other_connection_consumers() ) :
		// When another Jetpack-powered plugin (Jetpack, WooCommerce Shipping &
		// Tax, Jetpack Backup, …) also holds the WordPress.com connection, a full
		// disconnect would break it for them too — so don't offer a Disconnect
		// button. Explain it and point at the transport toggle, the real lever for
		// ShipStation. WooCommerce core is not counted (it registers the
		// connection on every store). Mirrors the ACTION_DISCONNECT_SHARED notice
		// in WPCOM_Connection.
		?>
		<p>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpcom_status_pill() escapes internally.
			echo WC_ShipStation_Integration::wpcom_status_pill( 'connected', __( 'Connected to WordPress.com', 'woocommerce-shipstation-integration' ), 'dashicons-yes-alt' );
			?>
		</p>
		<p class="description"><?php esc_html_e( 'Another plugin on this site also uses the WordPress.com connection, so it cannot be disconnected from here. To stop routing ShipStation through WordPress.com, turn off "Enable WordPress.com Transport" above.', 'woocommerce-shipstation-integration' ); ?></p>
		<?php
	else :
		$disconnect_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=shipstation_wpcom_disconnect' ),
			'shipstation_wpcom_disconnect'
		);
		?>
		<p>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpcom_status_pill() escapes internally.
			echo WC_ShipStation_Integration::wpcom_status_pill( 'connected', __( 'Connected to WordPress.com', 'woocommerce-shipstation-integration' ), 'dashicons-yes-alt' );
			?>
			<a href="<?php echo esc_url( $disconnect_url ); ?>" class="button-link button-link-delete"><?php esc_html_e( 'Disconnect', 'woocommerce-shipstation-integration' ); ?></a>
		</p>
		<?php
	endif;
	return;
endif;

// A disconnect is pending — guarded until a direct fallback is detected.
$cancel_url = wp_nonce_url(
	admin_url( 'admin-post.php?action=shipstation_wpcom_disconnect_cancel' ),
	'shipstation_wpcom_disconnect_cancel'
);

$active_window = \WooCommerce\Shipping\ShipStation\Auth_Controller::active_window_seconds();
$lag_tolerance = \WooCommerce\Shipping\ShipStation\Auth_Controller::direct_lag_tolerance_seconds();
if ( \WooCommerce\Shipping\ShipStation\Connection_Log::is_direct_connection_safe( $active_window, $lag_tolerance ) ) :
	$complete_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=shipstation_wpcom_disconnect_complete' ),
		'shipstation_wpcom_disconnect_complete'
	);
	?>
	<p>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpcom_status_pill() escapes internally.
		echo WC_ShipStation_Integration::wpcom_status_pill( 'pending', __( 'Disconnect pending', 'woocommerce-shipstation-integration' ), 'dashicons-ellipsis' );
		?>
	</p>
	<p class="description">
		<?php
		echo wp_kses(
			sprintf(
				/* translators: 1: opening <strong>, 2: closing </strong> (wraps "direct ShipStation connection was detected"); 3: opening <strong>, 4: closing </strong> (wraps "safe to disconnect from WordPress.com"). */
				esc_html__( 'A %1$sdirect ShipStation connection was detected%2$s, so it is %3$ssafe to disconnect from WordPress.com%4$s now. Disconnecting routes ShipStation traffic directly again.', 'woocommerce-shipstation-integration' ),
				'<strong>',
				'</strong>',
				'<strong>',
				'</strong>'
			),
			array( 'strong' => array() )
		);
		?>
	</p>
	<p>
		<a href="<?php echo esc_url( $complete_url ); ?>" class="button button-primary"><?php esc_html_e( 'Safe disconnect from WordPress.com', 'woocommerce-shipstation-integration' ); ?></a>
		<a href="<?php echo esc_url( $cancel_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'woocommerce-shipstation-integration' ); ?></a>
	</p>
	<?php
	return;
endif;

$force_url = wp_nonce_url(
	admin_url( 'admin-post.php?action=shipstation_wpcom_disconnect_complete&force=1' ),
	'shipstation_wpcom_disconnect_complete'
);
?>
<p>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpcom_status_pill() escapes internally.
	echo WC_ShipStation_Integration::wpcom_status_pill( 'pending', __( 'Disconnect pending — waiting for a direct connection', 'woocommerce-shipstation-integration' ), 'dashicons-ellipsis' );
	?>
</p>
<p class="description">
	<?php
	echo wp_kses(
		sprintf(
			/* translators: 1: opening <strong>, 2: closing </strong> (wraps "No direct ShipStation connection was detected"); 3: opening <strong>, 4: closing </strong> (wraps "you cannot safely disconnect from WordPress.com"). */
			esc_html__( '%1$sNo direct ShipStation connection was detected%2$s, so %3$syou cannot safely disconnect from WordPress.com%4$s yet. First switch ShipStation to a direct connection: in ShipStation, change your store\'s URL to the Store URL shown in the Credentials section below. Once ShipStation syncs directly, it is safe to disconnect.', 'woocommerce-shipstation-integration' ),
			'<strong>',
			'</strong>',
			'<strong>',
			'</strong>'
		),
		array(
			'strong' => array(),
		)
	);
	?>
</p>
<p>
	<a href="<?php echo esc_url( $force_url ); ?>" class="button button-primary shipstation-wpcom-disconnect-force"><?php esc_html_e( 'Dangerously disconnect anyway', 'woocommerce-shipstation-integration' ); ?></a>
	<a href="<?php echo esc_url( $cancel_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'woocommerce-shipstation-integration' ); ?></a>
</p>
