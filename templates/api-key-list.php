<?php
/**
 * Plugin-generated API key list for the ShipStation settings tab (SHIPSTN-142).
 *
 * Lists the credential pairs the plugin minted so the merchant can retire the
 * ones they no longer use in ShipStation. Rendered inside the "API Keys"
 * <details> subsection of the "ShipStation Connection" section by
 * WC_ShipStation_Integration::generate_shipstation_credentials_html(), which
 * gathers and sorts the rows (get_sorted_plugin_key_rows()); this template owns
 * only the presentation.
 *
 * @package WC_ShipStation
 *
 * @var array  $rows              Plugin key rows, pre-sorted newest-seen first.
 * @var int    $active_window     Seconds within which a ping counts as active.
 * @var bool   $transport_enabled Whether the WordPress.com transport is on.
 * @var string $date_format       Combined WP date+time format for absolute fallbacks.
 * @var int    $newest_key_id     Key id of the prune-protected newest row.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// wc_get_template() extract()s these vars and include()s this file INSIDE a
// function, so the locals below are function-scoped at runtime, never real
// globals — the prefix sniff just can't see through the include.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
<?php if ( array() === $rows ) : ?>
	<p><?php esc_html_e( 'No plugin-generated API keys yet.', 'woocommerce-shipstation-integration' ); ?></p>
<?php else : ?>
	<table class="widefat striped shipstation-settings-table shipstation-api-key-list">
		<thead>
			<tr>
				<th class="shipstation-api-key-col"><?php esc_html_e( 'Key', 'woocommerce-shipstation-integration' ); ?></th>
				<th class="shipstation-api-key-col"><?php esc_html_e( 'Transport', 'woocommerce-shipstation-integration' ); ?></th>
				<th class="shipstation-api-key-grow"><?php esc_html_e( 'Last sync', 'woocommerce-shipstation-integration' ); ?></th>
				<th class="shipstation-api-key-col"></th>
			</tr>
		</thead>
		<tbody>
			<?php
			// Translated pill labels are state-keyed and identical for every row, so
			// resolve the table once rather than rebuilding it per row.
			$pill_labels = WC_ShipStation_Integration::pill_labels();
			?>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				// last_access (last ShipStation ping on this key row) still drives
				// the Last sync column. It is stored in site time, so normalize to
				// GMT before comparing.
				$last_access = isset( $row['last_access'] ) ? (string) $row['last_access'] : '';
				$has_access  = '' !== $last_access && '0000-00-00 00:00:00' !== $last_access;
				$last_ts     = $has_access ? (int) strtotime( get_gmt_from_date( $last_access ) . ' UTC' ) : 0;

				// Status pill: roll the key's connection rows up so the Keys pill
				// matches the Connections table for the same key (SHIPSTN-142). When
				// the key has connection rows, the rollup is authoritative — it
				// reflects Mismatch / Disconnected / Rejected that bare last_access
				// recency can't see. When it has none, fall back to last_access
				// recency, which also yields the connection-less New / Unused states.
				$rollup_status = isset( $row['rollup_status'] ) ? (string) $row['rollup_status'] : '';
				if ( '' !== $rollup_status ) {
					$pill_modifier = $rollup_status;
					$is_active     = ( 'active' === $rollup_status );
					$is_new        = false;
				} else {
					$is_active = $has_access && ( time() - $last_ts ) <= $active_window;
					$key_state = $is_active ? 'active' : ( $has_access ? 'stale' : 'never' );
					// Never-used splits by recency: the newest (freshly generated,
					// prune-protected) reads "New", older never-used keys read
					// "Unused" (counting down to auto-removal).
					$is_new = 'never' === $key_state && (int) $row['key_id'] === $newest_key_id;
					if ( 'active' === $key_state ) {
						$pill_modifier = 'active';
					} elseif ( 'stale' === $key_state ) {
						$pill_modifier = 'inactive';
					} else {
						$pill_modifier = $is_new ? 'new' : 'unused';
					}
				}
				$pill_label = $pill_labels[ $pill_modifier ];

				// Provisional (dimmed) treatment for everything not active. The
				// delete control is hover-revealed (gray) on Active AND New rows —
				// you don't reach for delete on a key that's working or one you
				// just generated; everything else keeps an always-visible delete
				// (the dimming invites it).
				$row_classes = array( 'shipstation-api-key-row' );
				if ( ! $is_active ) {
					$row_classes[] = 'shipstation-api-key-provisional';
				}
				if ( $is_active || $is_new ) {
					$row_classes[] = 'shipstation-api-key-protect-delete';
				}
				$row_class = implode( ' ', $row_classes );
				?>
				<tr class="<?php echo esc_attr( $row_class ); ?>" data-key-id="<?php echo esc_attr( (string) $row['key_id'] ); ?>">
					<td class="shipstation-api-key-col">
						<code>&hellip;<?php echo esc_html( $row['truncated_key'] ); ?></code>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- key_pill() escapes internally.
						echo WC_ShipStation_Integration::key_pill( $pill_modifier, $pill_label );
						?>
					</td>
					<?php
					// Type reflects the transport(s) the key has actually
					// authenticated over — not its mint prefix, which is
					// meaningless (one key works for both transports).
					$transports = isset( $row['transports'] ) && is_array( $row['transports'] ) ? $row['transports'] : array();

					// Recency-filter the observed set: a transport is a CURRENT
					// route only if ShipStation used it within the active window.
					// This is what lets the column drop "Direct" once that route
					// goes silent while WordPress.com keeps pinging, instead of
					// showing a stale "Both" forever.
					$transport_last_seen = isset( $row['transport_last_seen'] ) && is_array( $row['transport_last_seen'] ) ? $row['transport_last_seen'] : array();
					$active_transports   = array();
					foreach ( $transports as $transport ) {
						// wpcom is only a live route while the transport is enabled —
						// with it off the proxy can't receive new pings, so a recent
						// but frozen wpcom hit is not "active". direct is always a
						// possible route.
						if ( 'wpcom' === $transport && ! $transport_enabled ) {
							continue;
						}
						$seen_at = isset( $transport_last_seen[ $transport ] ) ? (int) strtotime( (string) $transport_last_seen[ $transport ] . ' UTC' ) : 0;
						if ( $seen_at > 0 && ( time() - $seen_at ) <= $active_window ) {
							$active_transports[] = $transport;
						}
					}

					// When a route is currently live, show the live set; when the
					// key has gone fully silent, fall back to the all-time set so
					// the cell isn't blank (the Inactive pill already conveys it).
					$display_transports = array() !== $active_transports ? $active_transports : $transports;
					$has_wpcom          = in_array( 'wpcom', $display_transports, true );
					$has_direct         = in_array( 'direct', $display_transports, true );

					// No mint-prefix guessing: the Type column shows only what
					// ShipStation has actually connected over. A key never used
					// from a live connection shows an em-dash until a real
					// transport is recorded.
					$type_label = '';
					if ( $has_wpcom && $has_direct ) {
						$type_label = __( 'Both', 'woocommerce-shipstation-integration' );
					} elseif ( $has_wpcom ) {
						$type_label = __( 'WordPress.com', 'woocommerce-shipstation-integration' );
					} elseif ( $has_direct ) {
						$type_label = __( 'Direct', 'woocommerce-shipstation-integration' );
					}
					?>
					<td class="shipstation-api-key-col">
						<?php if ( '' !== $type_label ) : ?>
							<?php echo esc_html( $type_label ); ?>
						<?php else : ?>
							<span title="<?php esc_attr_e( 'Not detected yet — shown once ShipStation connects through this key.', 'woocommerce-shipstation-integration' ); ?>">&mdash;</span>
						<?php endif; ?>
					</td>
					<td class="shipstation-api-key-grow">
						<?php
						if ( $has_access ) {
							// Instant only; relative-time.js renders the "… ago" string in
							// the viewer's locale/timezone (absolute time as no-JS fallback).
							printf(
								'<time class="shipstation-rel-time" datetime="%1$s">%2$s</time>',
								esc_attr( gmdate( 'c', $last_ts ) ),
								esc_html( wp_date( $date_format, $last_ts ) )
							);
							if ( 'inactive' === $pill_modifier ) {
								// Only the genuinely-idle case invites deletion. Mismatch /
								// Rejected / Disconnected keys are still in use — their pill
								// already names the fix, so no "delete if unused" nudge.
								echo '<br><span class="description">' . esc_html__( 'Not connecting lately — delete if unused in ShipStation.', 'woocommerce-shipstation-integration' ) . '</span>';
							}
						} else {
							esc_html_e( 'Never', 'woocommerce-shipstation-integration' );
							$prune_deadline = WC_ShipStation_Integration::prune_deadline_ts( (int) $row['key_id'], (int) $row['key_id'] === $newest_key_id );
							if ( $prune_deadline > time() ) {
								// Surface the auto-remove deadline; relative-time.js turns it
								// into "auto-deletes in 2 days". Fallback names the absolute date.
								printf(
									'<br><span class="description"><time class="shipstation-countdown" data-prefix="%1$s" datetime="%2$s">%3$s</time></span>',
									esc_attr( __( 'auto-deletes in', 'woocommerce-shipstation-integration' ) ),
									esc_attr( gmdate( 'c', $prune_deadline ) ),
									esc_html(
										sprintf(
											/* translators: %s: absolute date/time the key auto-deletes. */
											__( 'auto-deletes on %s', 'woocommerce-shipstation-integration' ),
											wp_date( $date_format, $prune_deadline )
										)
									)
								);
							} elseif ( $prune_deadline > 0 ) {
								echo '<br><span class="description">' . esc_html__( 'auto-deletes on the next cleanup.', 'woocommerce-shipstation-integration' ) . '</span>';
							}
						}
						?>
					</td>
					<td class="shipstation-api-key-actions shipstation-api-key-col">
						<button type="button" class="shipstation-delete-api-key" data-key-id="<?php echo esc_attr( (string) $row['key_id'] ); ?>" title="<?php esc_attr_e( 'Delete', 'woocommerce-shipstation-integration' ); ?>" aria-label="<?php esc_attr_e( 'Delete this API key pair', 'woocommerce-shipstation-integration' ); ?>">
							<span class="dashicons dashicons-trash"></span>
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="description">
		<?php esc_html_e( 'Delete keys you no longer use in ShipStation — fewer keys, less risk. A deleted key stops working immediately.', 'woocommerce-shipstation-integration' ); ?>
	</p>
<?php endif; ?>
