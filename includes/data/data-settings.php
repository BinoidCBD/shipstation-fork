<?php
/**
 * Data for the settings page file.
 *
 * @package WC_ShipStation
 */

use Automattic\WooCommerce\Enums\OrderInternalStatus;
use WooCommerce\Shipping\ShipStation\Order_Util;
use WooCommerce\Shipping\ShipStation\Auth_Controller;

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
		'default'     => array( OrderInternalStatus::CANCELLED ),
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

return $fields;
