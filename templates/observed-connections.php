<?php
/**
 * "Connections" list for the ShipStation settings tab (SHIPSTN-142).
 *
 * Lists where ShipStation has authenticated from. Rendered inside the
 * "Connections" <details> subsection of the "ShipStation Connection" section by
 * WC_ShipStation_Integration::generate_shipstation_credentials_html(); the
 * subsection's <summary> supplies the heading.
 *
 * @package WC_ShipStation
 *
 * @var array  $connections        Rows from Connection_Log::all().
 * @var string $date_format        Combined WP date+time format for absolute fallbacks.
 * @var int    $active_window       Seconds within which a ping counts as active.
 * @var bool   $transport_enabled  Whether the WordPress.com transport is on.
 * @var string $current_host       Host of the current home_url(), for mismatch detection.
 * @var bool   $is_wpcom_connected Live Jetpack/WordPress.com link state at render time.
 */

use WooCommerce\Shipping\ShipStation\Auth_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// wc_get_template() extract()s these vars and include()s this file INSIDE a
// function, so the locals below are function-scoped at runtime, never real
// globals — the prefix sniff just can't see through the include.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
//
// $is_wpcom_connected is the live Jetpack/WordPress.com link state and is always
// supplied by generate_shipstation_credentials_html() (defaulting to true when
// the connection facade is unavailable), so a proxy row is only ever rendered
// "disconnected" when WordPress.com is genuinely unlinked.
?>
<?php if ( array() === $connections ) : ?>
	<p><?php esc_html_e( "ShipStation hasn't connected yet. Once it does, the route it used and the time appear here.", 'woocommerce-shipstation-integration' ); ?></p>
<?php else : ?>
	<?php
	// Translated pill labels are state-keyed and identical for every row, so
	// resolve the table once rather than rebuilding it per row.
	$pill_labels = WC_ShipStation_Integration::pill_labels();

	// Per-status pill tooltip copy (D5). Keyed by the same status strings
	// connection_status() returns, so the pill and its hover explanation never
	// drift apart.
	$pill_titles = array(
		'active'       => __( 'ShipStation is connected and syncing through this route.', 'woocommerce-shipstation-integration' ),
		'inactive'     => __( "ShipStation hasn't synced on this route lately — it may have stopped, or just be idle.", 'woocommerce-shipstation-integration' ),
		'disconnected' => __( "WordPress.com is disconnected, so ShipStation can't reach your store through it. Reconnect WordPress.com.", 'woocommerce-shipstation-integration' ),
		'mismatch'     => __( 'Your store URL changed and no longer matches this connection. Update the Store URL in ShipStation.', 'woocommerce-shipstation-integration' ),
		'rejected'     => __( 'WordPress.com transport is off, so your store is refusing this proxied connection.', 'woocommerce-shipstation-integration' ),
		'deleted'      => __( 'The API key for this connection was deleted, so ShipStation can no longer authenticate over it. Generate a new key and reconfigure ShipStation.', 'woocommerce-shipstation-integration' ),
	);

	// Resolve each row's status once, up front. The same string drives the pill,
	// the per-hop route glyphs, the Active/All filter's `is-active` row class, and
	// the server-side counts in the filter labels — computing it here keeps every
	// consumer in lockstep and avoids re-deriving it inside the render loop.
	$conn_statuses = array();
	$active_count  = 0;
	foreach ( $connections as $conn_index => $conn ) {
		$conn_loop_status             = WooCommerce\Shipping\ShipStation\Connection_Log::connection_status( $conn, $active_window, $transport_enabled, $current_host, $is_wpcom_connected );
		$conn_statuses[ $conn_index ] = $conn_loop_status;
		if ( 'active' === $conn_loop_status ) {
			++$active_count;
		}
	}
	$total_count = count( $connections );
	?>
	<?php
	// The Active/All toggle is a purely front-end view filter (the rows are
	// shown/hidden by CSS) and saves nothing. The `wc-settings-prevent-change-event`
	// class opts its radios out of WooCommerce's settings change-detector
	// (woocommerce/assets/js/admin/settings.js) so flipping it does not arm the
	// "Changes you made may not be saved." navigation prompt.
	//
	// Default the checked radio to the view actually rendered: "Active only" hides
	// the non-active rows via the sibling-table CSS rule, but that rule is guarded
	// by :has(tr.is-active) and falls through to showing ALL rows when nothing is
	// active — so with zero active rows the table really shows everything. Selecting
	// "All" by default in that case keeps the radio honest about what's on screen.
	$has_active     = $active_count > 0;
	$active_checked = $has_active ? 'checked' : '';
	$all_checked    = $has_active ? '' : 'checked';
	?>
	<div class="shipstation-conn-filter wc-settings-prevent-change-event">
		<?php
		printf(
			'<label class="shipstation-conn-filter__option"><input type="radio" name="shipstation-conn-filter" class="shipstation-conn-filter__active" %1$s /> %2$s</label>',
			esc_attr( $active_checked ),
			sprintf(
				/* translators: %s: number of active connections. */
				esc_html__( 'Active only (%s)', 'woocommerce-shipstation-integration' ),
				esc_html( number_format_i18n( $active_count ) )
			)
		);
		printf(
			'<label class="shipstation-conn-filter__option"><input type="radio" name="shipstation-conn-filter" class="shipstation-conn-filter__all" %1$s /> %2$s</label>',
			esc_attr( $all_checked ),
			sprintf(
				/* translators: %s: total number of connections. */
				esc_html__( 'All (%s)', 'woocommerce-shipstation-integration' ),
				esc_html( number_format_i18n( $total_count ) )
			)
		);
		?>
	</div>
	<table class="widefat striped shipstation-settings-table shipstation-connection-list">
		<thead>
			<tr>
				<th class="shipstation-connection-col"><?php esc_html_e( 'Transport', 'woocommerce-shipstation-integration' ); ?></th>
				<th class="shipstation-connection-col"><?php esc_html_e( 'Key', 'woocommerce-shipstation-integration' ); ?></th>
				<th class="shipstation-connection-url"><?php esc_html_e( 'Connection route', 'woocommerce-shipstation-integration' ); ?></th>
				<th class="shipstation-connection-col"><?php esc_html_e( 'Last sync', 'woocommerce-shipstation-integration' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $connections as $conn_index => $conn ) :
				// Per-row freshness from this connection's own last_seen, tiered to
				// the 24h recency window: green < 12h, orange 12–24h, red beyond —
				// so the red "old" tier coincides with the pill flipping to Inactive.
				$conn_last_ts = strtotime( $conn['last_seen'] . ' UTC' );
				$conn_age     = $conn_last_ts ? ( time() - $conn_last_ts ) : PHP_INT_MAX;
				// Single source of truth, resolved in the pre-pass above and shared
				// with the section-level health banner so the per-row pill, the route
				// glyphs, and the aggregate never diverge. The route display derives
				// every per-hop glyph from this status string (see the glyph map in
				// the route cell below), so the template no longer re-derives the
				// recency / domain-mismatch predicates separately.
				$conn_status     = $conn_statuses[ $conn_index ];
				$conn_pill_label = $pill_labels[ $conn_status ];
				$conn_pill_title = isset( $pill_titles[ $conn_status ] ) ? $pill_titles[ $conn_status ] : '';
				$conn_is_active  = ( 'active' === $conn_status );
				if ( $conn_age < Auth_Controller::ACTIVE_WINDOW_SECONDS / 2 ) {
					$conn_age_class = 'shipstation-conn-age--fresh';
				} elseif ( $conn_age < Auth_Controller::ACTIVE_WINDOW_SECONDS ) {
					$conn_age_class = 'shipstation-conn-age--stale';
				} else {
					$conn_age_class = 'shipstation-conn-age--old';
				}
				?>
				<tr<?php echo $conn_is_active ? ' class="is-active"' : ''; ?>>
					<td class="shipstation-connection-col">
						<?php
						'wpcom' === $conn['transport']
							? esc_html_e( 'WordPress.com', 'woocommerce-shipstation-integration' )
							: esc_html_e( 'Direct', 'woocommerce-shipstation-integration' );
						?>
					</td>
					<td class="shipstation-connection-col">
						<code>&hellip;<?php echo esc_html( $conn['truncated_key'] ); ?></code>
						<?php
						// key_pill() has no title slot, so wrap it to carry the
						// per-status tooltip (D5). Every per-row connection status has
						// copy in $pill_titles; the empty-title branch is defensive.
						?>
						<span class="shipstation-pill-wrap"<?php echo '' !== $conn_pill_title ? ' title="' . esc_attr( $conn_pill_title ) . '"' : ''; ?>>
							<?php
							// A deleted key resolves the pill itself to "Deleted"
							// (connection_status() top priority), so the credential's
							// absence reads from the status — no separate "(deleted)"
							// annotation is needed alongside it.
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- key_pill() escapes internally.
							echo WC_ShipStation_Integration::key_pill( $conn_status, $conn_pill_label );
							?>
						</span>
					</td>
					<td class="shipstation-connection-url">
						<?php
						// The route ShipStation's request takes to reach the store,
						// rendered as a chain of destination icons (each icon's URL
						// lives on its hover/copy, not as visible text). Direct row:
						// ShipStation -> Woo. Proxied row: ShipStation -> WordPress.com
						// -> Woo. A health glyph sits between each pair of icons:
						// -> working, ? uncertain/idle, x broken. A proxied row carries
						// two glyphs (g1 before WordPress.com, g2 before the store); a
						// direct row carries one (the store-edge glyph). Built into one
						// string here, then echoed once, so the markup stays readable
						// and every piece is escaped at the point it is assembled.
						$conn_is_proxied = '' !== $conn['target_url'] && $conn['target_url'] !== $conn['url'];
						// The store URL the Woo icon copies: for a proxied row the
						// store behind the proxy (target_url), for a direct row the
						// REST/Store URL ShipStation reaches directly.
						$conn_store_url = '' !== $conn['target_url'] ? $conn['target_url'] : $conn['url'];
						// The WordPress.com proxy endpoint the middle icon copies on a
						// proxied row (the address ShipStation actually calls).
						$conn_proxy_url = $conn['url'];

						// Per-hop glyph state from the resolved status (D3); the
						// status -> glyph matrix and the direct-row fold live in
						// Connection_Log::route_glyph_states(). g1 is the first hop
						// (ShipStation -> next), g2 the store-edge hop.
						$conn_glyph_states = WooCommerce\Shipping\ShipStation\Connection_Log::route_glyph_states( $conn_status, $conn_is_proxied );
						$conn_g1           = $conn_glyph_states['g1'];
						$conn_g2           = $conn_glyph_states['g2'];

						// Glyph presentation: class drives the colour (see
						// wpcom-credentials.scss); symbol is a fixed HTML entity.
						$conn_glyph_meta = array(
							'arrow'     => array(
								'class'  => 'shipstation-route-arrow',
								'symbol' => '&rarr;',
							),
							'uncertain' => array(
								'class'  => 'shipstation-route-uncertain',
								'symbol' => '?',
							),
							'down'      => array(
								'class'  => 'shipstation-route-down',
								'symbol' => '&times;',
							),
							'reject'    => array(
								'class'  => 'shipstation-route-reject',
								'symbol' => '&times;',
							),
						);
						// Per-hop tooltips. The store-edge hop's title explains the
						// specific failure; the proxy-side arrow is generic.
						$conn_title_arrow       = __( 'This hop is connecting normally.', 'woocommerce-shipstation-integration' );
						$conn_title_uncertain   = __( "ShipStation hasn't synced over this route lately, so its current state is uncertain.", 'woocommerce-shipstation-integration' );
						$conn_store_edge_titles = array(
							'mismatch'     => __( "This site's address changed, so this connection points at a domain that no longer reaches your store. It cannot work until the connection is repaired.", 'woocommerce-shipstation-integration' ),
							'disconnected' => __( "WordPress.com is disconnected, so requests can't reach your store through the proxy. Reconnect WordPress.com.", 'woocommerce-shipstation-integration' ),
							'rejected'     => __( 'The store is turning this proxy connection away — the WordPress.com transport is off. Re-enable it to accept proxied requests.', 'woocommerce-shipstation-integration' ),
							'inactive'     => $conn_title_uncertain,
						);
						$conn_store_edge_title  = isset( $conn_store_edge_titles[ $conn_status ] ) ? $conn_store_edge_titles[ $conn_status ] : $conn_title_arrow;

						// One glyph span: state key + tooltip -> escaped markup.
						$conn_glyph_html = function ( $state, $title ) use ( $conn_glyph_meta ) {
							$meta = $conn_glyph_meta[ $state ];
							return sprintf(
								'<span class="shipstation-route-glyph %1$s" title="%2$s">%3$s</span>',
								esc_attr( $meta['class'] ),
								esc_attr( $title ),
								// Symbols are fixed entity strings from the map above.
								wp_kses( $meta['symbol'], array() )
							);
						};
						// One copyable icon node (WordPress.com proxy or Woo store):
						// a button carrying its URL inline for the shared copy handler.
						$conn_copy_node_html = function ( $url, $aria_label, $img_src, $img_alt ) {
							return sprintf(
								'<button type="button" class="shipstation-route-node shipstation-route-copy shipstation-copy-btn" data-copy-text="%1$s" title="%1$s" aria-label="%2$s"><img class="shipstation-route-icon" src="%3$s" alt="%4$s" width="16" height="16" /></button>',
								esc_attr( $url ),
								esc_attr( $aria_label ),
								esc_url( $img_src ),
								esc_attr( $img_alt )
							);
						};

						// ShipStation origin icon — label only, no URL, not copyable.
						$conn_route_html = sprintf(
							'<span class="shipstation-route-node"><img class="shipstation-route-icon" src="%1$s" alt="%2$s" title="%2$s" width="16" height="16" /></span>',
							esc_url( WC_SHIPSTATION_PLUGIN_URL . 'assets/images/shipstation-icon.png' ),
							esc_attr__( 'ShipStation', 'woocommerce-shipstation-integration' )
						);

						if ( $conn_is_proxied ) {
							// g1 (ShipStation -> WordPress.com): generic arrow/uncertain.
							$conn_g1_title    = ( 'uncertain' === $conn_g1 ) ? $conn_title_uncertain : $conn_title_arrow;
							$conn_route_html .= $conn_glyph_html( $conn_g1, $conn_g1_title );
							/* translators: %s: the WordPress.com proxy endpoint URL. */
							$conn_wpcom_label = sprintf( __( 'Copy WordPress.com proxy URL (%s) to clipboard', 'woocommerce-shipstation-integration' ), $conn_proxy_url );
							$conn_route_html .= $conn_copy_node_html(
								$conn_proxy_url,
								$conn_wpcom_label,
								WC_SHIPSTATION_PLUGIN_URL . 'assets/images/wpcom-logo.png',
								__( 'WordPress.com', 'woocommerce-shipstation-integration' )
							);
							// g2 (WordPress.com -> store): the store-edge glyph.
							$conn_route_html .= $conn_glyph_html( $conn_g2, $conn_store_edge_title );
						} else {
							// Direct: the single glyph is the store-edge glyph (g1).
							$conn_route_html .= $conn_glyph_html( $conn_g1, $conn_store_edge_title );
						}

						// Woo store icon — copyable, shows the store URL this route reaches.
						/* translators: %s: the store URL. */
						$conn_store_label = sprintf( __( 'Copy store URL (%s) to clipboard', 'woocommerce-shipstation-integration' ), $conn_store_url );
						$conn_route_html .= $conn_copy_node_html(
							$conn_store_url,
							$conn_store_label,
							WC_SHIPSTATION_PLUGIN_URL . 'assets/images/woo-logo.png',
							__( 'WooCommerce', 'woocommerce-shipstation-integration' )
						);

						// $conn_route_html is assembled entirely from the escaped
						// fragments above; the wrapper is the only literal markup added.
						echo '<span class="shipstation-route-chain">' . $conn_route_html . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</td>
					<td class="shipstation-connection-col">
						<?php
						if ( $conn_last_ts ) {
							// Surface the instant only; relative-time.js renders it in the
							// viewer's locale/timezone, with this localized absolute time as
							// the no-JS fallback.
							printf(
								'<time class="shipstation-rel-time %1$s" datetime="%2$s">%3$s</time>',
								esc_attr( $conn_age_class ),
								esc_attr( gmdate( 'c', $conn_last_ts ) ),
								esc_html( wp_date( $date_format, $conn_last_ts ) )
							);
						} else {
							esc_html_e( 'Unknown', 'woocommerce-shipstation-integration' );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="description">
		<?php esc_html_e( 'Where ShipStation has authenticated from. If a route you expect is missing, ShipStation has not connected through it yet.', 'woocommerce-shipstation-integration' ); ?>
	</p>
<?php endif; ?>
