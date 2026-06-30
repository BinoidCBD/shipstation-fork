<?php
/**
 * Uninstall cleanup for ShipStation for WooCommerce (SHIPSTN-142).
 *
 * Removes only the data this plugin owns: the connection-log table and the
 * SHIPSTN-142 feature options. It intentionally does NOT delete the WooCommerce
 * REST keys in woocommerce_api_keys (core rows that ShipStation may still
 * authenticate with), nor the pre-SHIPSTN-142 plugin options (auth key,
 * settings, activation-notice flag), which historically were left in place on
 * uninstall.
 *
 * @package WC_ShipStation
 * @since 5.2.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Drop the connection-log table and delete the feature options for one blog.
 *
 * Option names mirror the class constants (kept as literals because the plugin
 * is not loaded at uninstall time):
 *  - Connection_Log::TABLE / DB_VERSION_OPTION
 *  - Connection_Change_Notice::SEEN_URL_OPTION / PENDING_OPTION
 *  - Auth_Controller::KEY_META_OPTION / GEN_LOCK_OPTION
 *  - WPCOM_Connection::DISCONNECT_INTENT_OPTION / CONNECTED_URL_OPTION
 *
 * @return void
 */
function woocommerce_shipstation_uninstall_cleanup() {
	global $wpdb;

	$table = $wpdb->prefix . 'wc_shipstation_connections';
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$options = array(
		'woocommerce_shipstation_connlog_db_version',
		'woocommerce_shipstation_conn_url_seen',
		'woocommerce_shipstation_conn_change_pending',
		'woocommerce_shipstation_key_meta',
		'woocommerce_shipstation_generate_keys_lock',
		'woocommerce_shipstation_disconnect_intent',
		'woocommerce_shipstation_connected_url',
		'woocommerce_shipstation_last_success_ts',
		'woocommerce_shipstation_global_banner_dismissed_at',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

/**
 * Run the cleanup for every blog on multisite, or just the current site.
 *
 * Wrapped in a function so the iteration variables stay out of the global scope.
 *
 * @return void
 */
function woocommerce_shipstation_run_uninstall() {
	if ( ! is_multisite() ) {
		woocommerce_shipstation_uninstall_cleanup();
		return;
	}

	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		woocommerce_shipstation_uninstall_cleanup();
		restore_current_blog();
	}
}

woocommerce_shipstation_run_uninstall();
