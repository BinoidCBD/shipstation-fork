<?php
/**
 * Inline ShipStation Connection verdict banner (SHIPSTN-142, reqs 6 & 7).
 *
 * The single top-of-section banner that replaces the former
 * `woocommerce_before_settings_integration` notice. Rendered by
 * WC_ShipStation_Integration::generate_shipstation_credentials_html() only when the
 * section verdict (see {@see \WooCommerce\Shipping\ShipStation\Connection_Log::section_verdict()})
 * resolves a non-empty banner state. Its action button/link deep-links to the
 * fold or control that fixes the condition (D11).
 *
 * @package WC_ShipStation
 *
 * @var string $tone          'info' (just finish setup) or 'warn' (action needed).
 * @var string $body          Already-translated banner body string.
 * @var string $action_label  Already-translated action button label ('' = no button).
 * @var string $action_target Deep-link target: 'credentials' | 'transport_toggle' | 'wpcom_connect' | ''.
 * @var string $action_href   Nonced URL for the 'wpcom_connect' link only; '' otherwise.
 */

defined( 'ABSPATH' ) || exit;
?>
<?php // info tone is a passive status update; warn tone is an actionable alert. ?>
<div class="shipstation-connection-banner shipstation-connection-banner--<?php echo esc_attr( $tone ); ?>" role="<?php echo 'warn' === $tone ? 'alert' : 'status'; ?>">
	<p class="shipstation-connection-banner__body"><?php echo esc_html( $body ); ?></p>
	<?php if ( '' !== $action_label ) : ?>
		<p class="shipstation-connection-banner__actions">
			<?php if ( 'wpcom_connect' === $action_target ) : ?>
				<a class="button shipstation-banner-action" href="<?php echo esc_url( $action_href ); ?>"><?php echo esc_html( $action_label ); ?></a>
			<?php else : ?>
				<button type="button" class="button shipstation-banner-action" data-target="<?php echo esc_attr( $action_target ); ?>"><?php echo esc_html( $action_label ); ?></button>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</div>
