<?php
/**
 * Shipstation Connection Details Modal.
 *
 * @package WC_ShipStation
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="shipstation-auth-modal" class="shipstation-modal" style="display: none;">
	<div class="shipstation-modal-backdrop"></div>
	<div class="shipstation-modal-content">
		<div class="shipstation-modal-header">
			<h2><?php esc_html_e( 'ShipStation Connection Details', 'woocommerce-shipstation-integration' ); ?></h2>
			<button type="button" class="shipstation-modal-close" aria-label="<?php esc_attr_e( 'Close', 'woocommerce-shipstation-integration' ); ?>">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</div>
		<div class="shipstation-modal-body">
			<div class="shipstation-loading-overlay">
				<div class="shipstation-loading">
					<span class="spinner is-active"></span> <?php esc_html_e( 'Loading...', 'woocommerce-shipstation-integration' ); ?>
				</div>
			</div>
			<!-- First-time view content -->
			<div id="shipstation-first-view" style="display: none;">
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
						/* translators: 1: Opening <strong> tag, 2: Closing </strong> tag. */
							__( 'For security reasons, REST API keys are only shown once. %1$sPlease copy and store them securely now. Once you refresh this page, they cannot be viewed again.%2$s If needed, you can generate new keys later, which will automatically invalidate the old ones.', 'woocommerce-shipstation-integration' ),
							'<strong>',
							'</strong>'
						)
					);
					?>
				</p>

				<div class="shipstation-auth-field">
					<label for="shipstation-consumer-key"><?php esc_html_e( 'Consumer Key:', 'woocommerce-shipstation-integration' ); ?></label>
					<div class="shipstation-field-wrapper">
						<input type="password" id="shipstation-consumer-key" readonly />
						<button type="button" class="shipstation-toggle-visibility" data-target="shipstation-consumer-key" title="<?php esc_attr_e( 'Show', 'woocommerce-shipstation-integration' ); ?>">
							<span class="dashicons dashicons-visibility"></span>
						</button>
						<button type="button" class="shipstation-copy-btn" data-target="shipstation-consumer-key" title="<?php esc_attr_e( 'Copy', 'woocommerce-shipstation-integration' ); ?>">
							<span class="dashicons dashicons-admin-page"></span>
						</button>
					</div>
				</div>

				<div class="shipstation-auth-field">
					<label for="shipstation-consumer-secret"><?php esc_html_e( 'Consumer Secret:', 'woocommerce-shipstation-integration' ); ?></label>
					<div class="shipstation-field-wrapper">
						<input type="password" id="shipstation-consumer-secret" readonly />
						<button type="button" class="shipstation-toggle-visibility" data-target="shipstation-consumer-secret" title="<?php esc_attr_e( 'Show', 'woocommerce-shipstation-integration' ); ?>">
							<span class="dashicons dashicons-visibility"></span>
						</button>
						<button type="button" class="shipstation-copy-btn" data-target="shipstation-consumer-secret" title="<?php esc_attr_e( 'Copy', 'woocommerce-shipstation-integration' ); ?>">
							<span class="dashicons dashicons-admin-page"></span>
						</button>
					</div>
				</div>
			</div>
			<!-- After-first-view content -->
			<div id="shipstation-after-view" style="display: none;">
				<p><?php esc_html_e( 'For security, REST API keys are now hidden. To connect a new selling channel in ShipStation, generate new keys. This will invalidate the current ones.', 'woocommerce-shipstation-integration' ); ?></p>
			</div>

			<div class="shipstation-auth-field">
				<label for="shipstation-auth-key"><?php esc_html_e( 'ShipStation Authentication Key:', 'woocommerce-shipstation-integration' ); ?></label>
				<div class="shipstation-field-wrapper">
					<input type="password" id="shipstation-auth-key" readonly />
					<button type="button" class="shipstation-toggle-visibility" data-target="shipstation-auth-key" title="<?php esc_attr_e( 'Show', 'woocommerce-shipstation-integration' ); ?>">
						<span class="dashicons dashicons-visibility"></span>
					</button>
					<button type="button" class="shipstation-copy-btn" data-target="shipstation-auth-key" title="<?php esc_attr_e( 'Copy', 'woocommerce-shipstation-integration' ); ?>">
						<span class="dashicons dashicons-admin-page"></span>
					</button>
				</div>
			</div>

			<div class="shipstation-auth-field">
				<label for="shipstation-site-url"><?php esc_html_e( 'Site URL:', 'woocommerce-shipstation-integration' ); ?></label>
				<div class="shipstation-field-wrapper">
					<input type="text" id="shipstation-site-url" readonly />
					<button type="button" class="shipstation-copy-btn" data-target="shipstation-site-url" title="<?php esc_attr_e( 'Copy', 'woocommerce-shipstation-integration' ); ?>">
						<span class="dashicons dashicons-admin-page"></span>
					</button>
				</div>
			</div>
		</div>
		<div class="shipstation-modal-footer">
			<button type="button" class="button button-primary button-danger" id="shipstation-generate-new-keys">
				<?php esc_html_e( 'Generate new REST-API keys', 'woocommerce-shipstation-integration' ); ?>
			</button>
			<button type="button" class="button button-secondary shipstation-modal-close">
				<?php esc_html_e( 'Close', 'woocommerce-shipstation-integration' ); ?>
			</button>
		</div>
	</div>
</div>
