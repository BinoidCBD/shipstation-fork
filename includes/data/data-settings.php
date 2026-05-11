<?php
/**
 * Data for the settings page file.
 *
 * @package WC_ShipStation
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- File is included inside WC_ShipStation_Integration::init_form_fields() (see class-wc-shipstation-integration.php:467); these variables are method-scoped at runtime, not globals.

use Automattic\WooCommerce\Enums\OrderInternalStatus;
use WooCommerce\Shipping\ShipStation\Order_Util;
use WooCommerce\Shipping\ShipStation\Auth_Controller;
use WooCommerce\Shipping\ShipStation\Features;
use WooCommerce\Shipping\ShipStation\Main;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$statuses = Order_Util::get_all_order_statuses();

$fields = array(
	'auth_key'                                             => array(
		'title'             => __( 'Authentication Key', 'woocommerce-shipstation-integration' ),
		'description'       => Auth_Controller::get_auth_button_html(),
		'default'           => '',
		'type'              => 'text',
		'desc_tip'          => __( 'This is the <code>Auth Key</code> you set in ShipStation and allows ShipStation to communicate with your store.', 'woocommerce-shipstation-integration' ),
		'custom_attributes' => array(
			'readonly' => 'readonly',
			'hidden'   => 'hidden',
		),
		'value'             => WC_ShipStation_Integration::$auth_key,
	),
	'export_statuses'                                      => array(
		'title'             => __( 'Export Order Statuses&hellip;', 'woocommerce-shipstation-integration' ),
		'type'              => 'multiselect',
		'options'           => $statuses,
		'class'             => 'chosen_select',
		'css'               => 'width: 450px;',
		'description'       => __( 'Define the order statuses you wish to export to ShipStation.', 'woocommerce-shipstation-integration' ),
		'desc_tip'          => true,
		'custom_attributes' => array(
			'data-placeholder' => __( 'Select Order Statuses', 'woocommerce-shipstation-integration' ),
		),
	),
	'shipped_status'                                       => array(
		'title'       => __( 'Shipped Order Status&hellip;', 'woocommerce-shipstation-integration' ),
		'type'        => 'select',
		'options'     => $statuses,
		'description' => __( 'Define the order status you wish to update to once an order has been shipping via ShipStation. By default this is "Completed".', 'woocommerce-shipstation-integration' ),
		'desc_tip'    => true,
		'default'     => OrderInternalStatus::COMPLETED,
	),
	'api_mode'                                             => array(
		'title'             => __( 'API Mode', 'woocommerce-shipstation-integration' ),
		'type'              => 'text',
		'description'       => __( 'Current API mode.', 'woocommerce-shipstation-integration' ),
		'desc_tip'          => true,
		'custom_attributes' => array(
			'readonly' => 'readonly',
		),
		'default'           => 'XML',
	),
	'status_mode'                                          => array(
		'title'       => __( 'Status Mapping Mode', 'woocommerce-shipstation-integration' ),
		'type'        => 'select',
		'options'     => array(
			'api'    => __( 'API', 'woocommerce-shipstation-integration' ),
			'plugin' => __( 'Plugin', 'woocommerce-shipstation-integration' ),
		),
		'description' => __( 'Define how the order status will be mapped.', 'woocommerce-shipstation-integration' ),
		'desc_tip'    => true,
		'default'     => '',
	),
	WC_ShipStation_Integration::AWAITING_PAYMENT_STATUS . '_status' => array(
		'title'       => __( 'Awaiting Payment', 'woocommerce-shipstation-integration' ),
		'type'        => 'multiselect',
		'class'       => 'chosen_select',
		'options'     => $statuses,
		'description' => __( 'Define the order status you wish to map for ShipStation "AwaitingPayment" status. By default this is "pending".', 'woocommerce-shipstation-integration' ),
		'desc_tip'    => true,
		'default'     => array( OrderInternalStatus::PENDING ),
	),
	WC_ShipStation_Integration::AWAITING_SHIPMENT_STATUS . '_status' => array(
		'title'       => __( 'Awaiting Shipment', 'woocommerce-shipstation-integration' ),
		'type'        => 'multiselect',
		'class'       => 'chosen_select',
		'options'     => $statuses,
		'description' => __( 'Define the order status you wish to map for ShipStation "AwaitingShipment" status. By default this is "processing".', 'woocommerce-shipstation-integration' ),
		'desc_tip'    => true,
		'default'     => array( OrderInternalStatus::PROCESSING ),
	),
	WC_ShipStation_Integration::ON_HOLD_STATUS . '_status' => array(
		'title'       => __( 'OnHold', 'woocommerce-shipstation-integration' ),
		'type'        => 'multiselect',
		'class'       => 'chosen_select',
		'options'     => $statuses,
		'description' => __( 'Define the order status you wish to map for ShipStation "OnHold" status. By default this is "on-hold".', 'woocommerce-shipstation-integration' ),
		'desc_tip'    => true,
		'default'     => array( OrderInternalStatus::ON_HOLD ),
	),
	WC_ShipStation_Integration::COMPLETED_STATUS . '_status' => array(
		'title'       => __( 'Completed', 'woocommerce-shipstation-integration' ),
		'type'        => 'multiselect',
		'class'       => 'chosen_select',
		'options'     => $statuses,
		'description' => __( 'Define the order status you wish to map for ShipStation "Completed" status. By default this is "completed".', 'woocommerce-shipstation-integration' ),
		'desc_tip'    => true,
		'default'     => array( OrderInternalStatus::COMPLETED ),
	),
	WC_ShipStation_Integration::CANCELLED_STATUS . '_status' => array(
		'title'       => __( 'Cancelled', 'woocommerce-shipstation-integration' ),
		'type'        => 'multiselect',
		'class'       => 'chosen_select',
		'options'     => $statuses,
		'description' => __( 'Define the order status you wish to map for ShipStation "Cancelled" status. By default this is "cancelled".', 'woocommerce-shipstation-integration' ),
		'desc_tip'    => true,
		'default'     => array( OrderInternalStatus::CANCELLED, OrderInternalStatus::REFUNDED ),
	),
	'gift_enabled'                                         => array(
		'title'       => __( 'Gift', 'woocommerce-shipstation-integration' ),
		'label'       => __( 'Enable Gift options at checkout page', 'woocommerce-shipstation-integration' ),
		'type'        => 'checkbox',
		'description' => __( 'Allow customer to mark their order as a gift and include a personalized message.', 'woocommerce-shipstation-integration' ),
		'desc_tip'    => __( 'Enable gift fields on the checkout page.', 'woocommerce-shipstation-integration' ),
		'default'     => 'no',
	),
	'logging_enabled'                                      => array(
		'title'       => __( 'Logging', 'woocommerce-shipstation-integration' ),
		'label'       => __( 'Enable Logging', 'woocommerce-shipstation-integration' ),
		'type'        => 'checkbox',
		'description' => __( 'Note: this may log personal information. We recommend using this for debugging purposes only and deleting the logs when finished.', 'woocommerce-shipstation-integration' ),
		'desc_tip'    => __( 'Log all API interactions.', 'woocommerce-shipstation-integration' ),
		'default'     => 'yes',
	),
);

if ( Features::is_wpcom_transport_enabled() ) {
	$connection = Main::instance()->get_wpcom_connection();

	if ( null === $connection ) {
		$button_html = '<p>' . esc_html__( 'Jetpack connection package is unavailable. Reinstall plugin dependencies to enable this feature.', 'woocommerce-shipstation-integration' ) . '</p>';
	} elseif ( $connection->is_connected() ) {
		$wpcom_blog_id = $connection->get_blog_id();
		$action_url    = wp_nonce_url(
			admin_url( 'admin-post.php?action=shipstation_wpcom_disconnect' ),
			'shipstation_wpcom_disconnect'
		);
		$button_html   = sprintf(
			'<p><strong>%s</strong> %s</p><p><a href="%s" class="button">%s</a></p>',
			esc_html__( 'Connected to WordPress.com.', 'woocommerce-shipstation-integration' ),
			$wpcom_blog_id ? esc_html( sprintf( /* translators: %d: WordPress.com blog id */ __( 'Blog ID: %d', 'woocommerce-shipstation-integration' ), $wpcom_blog_id ) ) : '',
			esc_url( $action_url ),
			esc_html__( 'Disconnect from WordPress.com', 'woocommerce-shipstation-integration' )
		);
	} else {
		$action_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=shipstation_wpcom_connect' ),
			'shipstation_wpcom_connect'
		);
		$button_html = sprintf(
			'<p><a href="%s" class="button button-primary">%s</a></p>',
			esc_url( $action_url ),
			esc_html__( 'Connect to WordPress.com', 'woocommerce-shipstation-integration' )
		);
	}

	$fields['wpcom_connection'] = array(
		'title'       => __( 'WordPress.com Connection', 'woocommerce-shipstation-integration' ),
		'type'        => 'title',
		'description' => $button_html . '<p class="description">' . esc_html__( 'Connecting to WordPress.com lets ShipStation reach your store through the Jetpack channel, bypassing firewall and security-plugin blocks that can intercept direct requests. (Experimental.)', 'woocommerce-shipstation-integration' ) . '</p>',
	);
}

return $fields;
