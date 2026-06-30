<?php
/**
 * WordPress.com URL-mismatch (identity-crisis) card for the ShipStation settings
 * tab (slice 4, SHIPSTN-142).
 *
 * The connection is up but registered to a different address than the site now
 * uses, so WordPress.com is delivering proxied ShipStation traffic to the old,
 * now-dead domain. Keeping the same blog id across a domain change would need
 * jetpack-sync (declined for footprint), so the fix is to re-establish the
 * connection as a NEW site: WordPress.com mints a new blog id / proxy URL and
 * the merchant updates ShipStation's Store URL afterward. The reconnect tears
 * down only this site's local tokens, so a staging clone won't disturb the
 * original site's connection.
 *
 * Rendered by templates/wpcom-controls.php.
 *
 * @package WC_ShipStation
 *
 * @var \WooCommerce\Shipping\ShipStation\WPCOM_Connection $connection Connection facade.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// wc_get_template() extract()s these vars and include()s this file INSIDE a
// function, so the locals below are function-scoped at runtime, never real
// globals — the prefix sniff just can't see through the include.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$reconnect_url = wp_nonce_url(
	admin_url( 'admin-post.php?action=shipstation_wpcom_connect_fresh' ),
	'shipstation_wpcom_connect_fresh'
);

// Wrap the domains in <code> (matching the proxy Store URL styling) and keep
// each on one line so they never break mid-domain at a hyphen.
$code_open = '<code class="shipstation-domain">';
$intro     = sprintf(
	/* translators: 1: opening <code> tag, 2: the address WordPress.com still delivers to (previous domain), 3: closing </code>, 4: opening <code> tag, 5: the site's current address, 6: closing </code>. */
	esc_html__( 'Your site address changed, so WordPress.com can no longer reach your store — it is still delivering ShipStation traffic to %1$s%2$s%3$s instead of %4$s%5$s%6$s.', 'woocommerce-shipstation-integration' ),
	$code_open,
	esc_html( $connection->get_connected_host() ),
	'</code>',
	$code_open,
	esc_html( $connection->get_current_host() ),
	'</code>'
);
?>
<p>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpcom_status_pill() escapes internally.
	echo WC_ShipStation_Integration::wpcom_status_pill( 'pending', __( 'WordPress.com connection needs repair', 'woocommerce-shipstation-integration' ), 'dashicons-warning' );
	?>
</p>
<p class="description">
	<?php echo wp_kses( $intro, array( 'code' => array( 'class' => array() ) ) ); ?>
</p>
<p class="description">
	<?php esc_html_e( 'Reconnect to fix it. This re-establishes the connection as a new site, so afterward copy the new Store URL shown here into ShipStation.', 'woocommerce-shipstation-integration' ); ?>
</p>
<p>
	<a href="<?php echo esc_url( $reconnect_url ); ?>" class="button button-primary"><?php esc_html_e( 'Reconnect to WordPress.com', 'woocommerce-shipstation-integration' ); ?></a>
</p>
