<?php
/**
 * Inline ShipStation connection / credentials section (SHIPSTN-142).
 *
 * Rendered directly on the ShipStation settings tab by
 * WC_ShipStation_Integration::generate_shipstation_credentials_html(). Replaces the
 * former "View Authentication Data" / "View ShipStation connection details"
 * pop-up modals: the values ShipStation needs are shown in place.
 *
 * The full consumer key is unrecoverable once stored (WooCommerce hashes it), so
 * it is only revealed right after a Generate (the JS reveals the hidden field);
 * in the steady "reference" state the key's last 7 characters are shown as a hint
 * and the stored consumer secret, the auth key, and the Store URL are shown for
 * copying. Field order matches ShipStation's connection form so the merchant
 * copies top-to-bottom: Consumer Key -> Consumer Secret -> Authentication Key ->
 * Store URL.
 *
 * @package WC_ShipStation
 *
 * @var string $cred_state      'reference' (keys exist), 'none' (none yet), or 'missing' (option set but row gone).
 * @var string $conn_url       Store URL to paste into ShipStation (proxy URL when WPCOM-connected, else site URL).
 * @var string $auth_key        Legacy XML authentication key.
 * @var string $truncated_key   Last 7 chars of the current consumer key (reference state).
 * @var string $consumer_secret Stored consumer secret (reference state); '' otherwise.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="shipstation-connection-section">
	<div class="shipstation-connection-error notice notice-error inline" style="display: none;">
		<p></p>
	</div>

	<p class="shipstation-connection-success inline notice notice-success" style="display: none;">
		<?php esc_html_e( 'New Consumer Key and Consumer Secret key pair successfully generated.', 'woocommerce-shipstation-integration' ); ?>
	</p>

	<?php if ( 'missing' === $cred_state ) : ?>
		<div class="shipstation-connection-missing notice notice-error inline">
			<p><strong><?php esc_html_e( 'REST API keys missing', 'woocommerce-shipstation-integration' ); ?></strong></p>
			<p><?php esc_html_e( 'The REST API keys previously generated for ShipStation are no longer present in WooCommerce. They may have been deleted, removed by a security plugin, or lost in a backup restore. Generate new keys and update them in ShipStation to reconnect.', 'woocommerce-shipstation-integration' ); ?></p>
		</div>
	<?php elseif ( 'none' === $cred_state ) : ?>
		<p><?php esc_html_e( 'No REST API keys have been generated for ShipStation yet. Generate a pair below, then copy the values into ShipStation to connect your store.', 'woocommerce-shipstation-integration' ); ?></p>
	<?php endif; ?>

	<p class="shipstation-connection-info inline notice notice-info" style="display: none;">
		<strong><?php esc_html_e( 'Save the Consumer Key and Consumer Secret in a password manager or secure vault.', 'woocommerce-shipstation-integration' ); ?></strong>
		<?php
		echo wp_kses(
			sprintf(
				/* translators: %s: link, anchored on "ShipStation connection", to the ShipStation for WooCommerce sign-up documentation. */
				__( 'For security the Consumer Key is shown only once, when it is generated — it is hashed in storage and cannot be retrieved later. If you lose it, your only option is to generate a new pair and perform %s again.', 'woocommerce-shipstation-integration' ),
				'<a href="' . esc_url( 'https://woocommerce.com/document/shipstation-for-woocommerce/#sign-up-with-shipstation' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'ShipStation connection', 'woocommerce-shipstation-integration' ) . '</a>'
			),
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		);
		?>
	</p>

	<?php if ( 'none' !== $cred_state ) : ?>
		<p class="description shipstation-connection-howto">
			<?php esc_html_e( "Copy each value below into the matching field in ShipStation's store setup, then save the connection in ShipStation.", 'woocommerce-shipstation-integration' ); ?>
		</p>
	<?php endif; ?>

	<div class="shipstation-connection-fields">
	<?php
	// Consumer key: hidden until a Generate reveals a fresh pair (the stored key
	// is hashed and cannot be re-shown). In the reference state the "ends in X"
	// hint below stands in for it.
	wc_get_template(
		'partials/credential-field.php',
		array(
			'field_id'      => 'shipstation-conn-consumer-key',
			'label'         => __( 'Consumer Key:', 'woocommerce-shipstation-integration' ),
			'type'          => 'password',
			'show_toggle'   => true,
			'show_copy'     => true,
			'extra_class'   => 'shipstation-connection-key-field',
			'wrapper_style' => 'display: none;',
		),
		'',
		WC_SHIPSTATION_ABSPATH . 'templates/'
	);
	?>

	<?php if ( 'reference' === $cred_state && '' !== $truncated_key ) : ?>
		<div class="shipstation-auth-field shipstation-connection-truncated">
			<label><?php esc_html_e( 'Consumer Key:', 'woocommerce-shipstation-integration' ); ?></label>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: last 7 characters of the consumer key, in a code tag. */
						__( 'Your current consumer key ends in %s. Lost it?', 'woocommerce-shipstation-integration' ),
						'<code>&hellip;' . esc_html( $truncated_key ) . '</code>'
					)
				);
				?>
				<button type="button" class="button shipstation-connection-generate" data-cred-state="reference">
					<?php esc_html_e( 'Generate new keys', 'woocommerce-shipstation-integration' ); ?>
				</button>
			</p>
		</div>
	<?php endif; ?>

	<?php
	wc_get_template(
		'partials/credential-field.php',
		array(
			'field_id'      => 'shipstation-conn-consumer-secret',
			'label'         => __( 'Consumer Secret:', 'woocommerce-shipstation-integration' ),
			'type'          => 'password',
			'show_toggle'   => true,
			'show_copy'     => true,
			'value'         => $consumer_secret,
			'extra_class'   => 'shipstation-connection-secret-field',
			'wrapper_style' => '' !== $consumer_secret ? '' : 'display: none;',
		),
		'',
		WC_SHIPSTATION_ABSPATH . 'templates/'
	);
	?>

	<?php
	wc_get_template(
		'partials/credential-field.php',
		array(
			'field_id'    => 'shipstation-conn-auth-key',
			'label'       => __( 'Authentication Key:', 'woocommerce-shipstation-integration' ),
			'type'        => 'password',
			'show_toggle' => true,
			'show_copy'   => true,
			'value'       => $auth_key,
		),
		'',
		WC_SHIPSTATION_ABSPATH . 'templates/'
	);
	wc_get_template(
		'partials/credential-field.php',
		array(
			'field_id'    => 'shipstation-conn-url',
			'label'       => __( 'Store URL:', 'woocommerce-shipstation-integration' ),
			'type'        => 'text',
			'show_toggle' => false,
			'show_copy'   => true,
			'value'       => $conn_url,
			'description' => __( 'Paste this as the Store URL in ShipStation.', 'woocommerce-shipstation-integration' ),
		),
		'',
		WC_SHIPSTATION_ABSPATH . 'templates/'
	);
	?>
	</div>

	<?php if ( 'reference' !== $cred_state ) : ?>
		<p>
			<button type="button" class="button button-primary shipstation-connection-generate" data-cred-state="<?php echo esc_attr( $cred_state ); ?>">
				<?php esc_html_e( 'Generate REST API keys', 'woocommerce-shipstation-integration' ); ?>
			</button>
		</p>
	<?php endif; ?>

	<p class="description">
		<a href="<?php echo esc_url( 'https://woocommerce.com/document/shipstation-for-woocommerce/#sign-up-with-shipstation' ); ?>" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'See how to set up the ShipStation connection', 'woocommerce-shipstation-integration' ); ?>
		</a>
	</p>
</div>
