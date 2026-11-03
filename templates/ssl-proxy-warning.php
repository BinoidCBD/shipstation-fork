<?php
/**
 * Reverse-proxy TLS termination warning for the ShipStation settings tab,
 * rendered when Features::is_ssl_terminated_upstream() returns true
 * (SHIPSTN-166). Deliberately not a .shipstation-connection-banner:
 * auth-display.js hides the first such banner while the transport checkbox is
 * dirty, and this warning is independent of that toggle.
 *
 * @package WC_ShipStation
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="shipstation-ssl-warning" role="alert">
	<p class="shipstation-ssl-warning__body">
		<?php esc_html_e( 'Your store address uses HTTPS, but requests are reaching WordPress as plain HTTP. WooCommerce only accepts the Consumer Key and Consumer Secret over HTTPS, so ShipStation is being turned away with a 401 error even when those credentials are correct. A store that connects using the Authentication Key below is not affected.', 'woocommerce-shipstation-integration' ); ?>
	</p>
	<p class="shipstation-ssl-warning__body">
		<?php esc_html_e( 'This happens when a service in front of your store handles HTTPS and forwards plain HTTP to it. If you use Cloudflare, change the SSL/TLS encryption mode from Flexible to Full (strict). Otherwise, ask your host to forward the original protocol to PHP.', 'woocommerce-shipstation-integration' ); ?>
	</p>
</div>
