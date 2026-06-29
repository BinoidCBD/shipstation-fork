<?php
/**
 * Data for the settings page file.
 *
 * @package WC_ShipStation
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- File is included inside WC_ShipStation_Integration::init_form_fields() (see class-wc-shipstation-integration.php:467); these variables are method-scoped at runtime, not globals.

use Automattic\WooCommerce\Enums\OrderInternalStatus;
use WooCommerce\Shipping\ShipStation\Order_Util;
use WooCommerce\Shipping\ShipStation\Checkout\Checkout_Rates_Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$statuses = Order_Util::get_all_order_statuses();

$fields = array(
	'export_statuses'                                      => array(
		'title'             => __( 'Export Order Statuses', 'woocommerce-shipstation-integration' ),
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
		'title'       => __( 'Shipped Order Status', 'woocommerce-shipstation-integration' ),
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
			'api'    => __( 'ShipStation', 'woocommerce-shipstation-integration' ),
			'plugin' => __( 'Plugin', 'woocommerce-shipstation-integration' ),
		),
		'description' => sprintf(
			/* translators: 1: <strong>ShipStation</strong>, 2: ShipStation mode explanation, 3: <strong>Plugin</strong>, 4: Plugin mode explanation */
			'%1$s: %2$s<br>%3$s: %4$s',
			'<strong>' . esc_html__( 'ShipStation', 'woocommerce-shipstation-integration' ) . '</strong>',
			esc_html__( 'Mappings are managed externally, in your ShipStation account connection settings.', 'woocommerce-shipstation-integration' ),
			'<strong>' . esc_html__( 'Plugin', 'woocommerce-shipstation-integration' ) . '</strong>',
			esc_html__( 'Mappings are managed here, in the plugin settings below.', 'woocommerce-shipstation-integration' )
		),
		'desc_tip'    => false,
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
		// No desc_tip: the tooltip text only restated the label, and checkbox
		// help belongs below the field (WC 10.8 misaligns checkbox tooltips).
		'description' => __( 'Allow customer to mark their order as a gift and include a personalized message.', 'woocommerce-shipstation-integration' ),
		'default'     => 'no',
	),
	'logging_enabled'                                      => array(
		'title'       => __( 'Logging', 'woocommerce-shipstation-integration' ),
		'label'       => __( 'Enable Logging', 'woocommerce-shipstation-integration' ),
		'type'        => 'checkbox',
		'description' => __( 'Note: this may log personal information. We recommend using this for debugging purposes only and deleting the logs when finished.', 'woocommerce-shipstation-integration' ),
		'default'     => 'yes',
	),
);

if ( Checkout_Rates_Options::should_render_settings_section() ) {
	$fields['checkout_rates'] = array(
		'title'       => __( 'Checkout Rates', 'woocommerce-shipstation-integration' ),
		'type'        => 'title',
		'description' => Checkout_Rates_Options::get_settings_description_html(),
	);
}

// Save-bearing WordPress.com transport opt-in (SHIPSTN-133). Ticking it enables
// the WordPress.com transport — the same gate the WC_SHIPSTATION_WPCOM_TRANSPORT
// constant and the wc_shipstation_wpcom_transport_enabled filter still drive. Off
// by default: no behavior change for existing merchants until they opt in.
//
// As of SHIPSTN-142 reqs 6 & 7 this field stays REGISTERED purely so its checkbox
// POSTs under woocommerce_shipstation_wpcom_transport_enabled and
// validate_wpcom_transport_field() runs — its custom renderer
// generate_wpcom_transport_html() now returns '' (no standalone row). The checkbox
// is hand-rendered inside the unified "ShipStation Connection" section's
// always-visible transport strip (build_wpcom_transport_strip_html()), which also
// owns the help text and the forced-override (disabled + checked + note) handling.
$fields['wpcom_transport_enabled'] = array(
	'title'    => __( 'WordPress.com Connection', 'woocommerce-shipstation-integration' ),
	'label'    => __( 'Enable WordPress.com Transport', 'woocommerce-shipstation-integration' ),
	// Custom type → generate_wpcom_transport_html() (returns '' so WC renders no
	// second row); the checkbox lives in the unified section. Saving behaves
	// exactly like a checkbox (validate_wpcom_transport_field → validate_checkbox_field).
	'type'     => 'wpcom_transport',
	'desc_tip' => false,
	'default'  => 'no',
);

// Unified "ShipStation Connection" section (SHIPSTN-142, reqs 6 & 7). A single
// settings row, rendered by generate_shipstation_credentials_html(): an
// always-visible status pill + conditional verdict banner + the WordPress.com
// transport strip (the relocated transport checkbox + connect/disconnect
// controls), then three collapsible <details> subsections — Credentials (the
// values ShipStation needs, with inline Generate, in place of the former "View …"
// pop-up modals), Connections (where ShipStation has authenticated from), and API
// Keys (the plugin-generated REST key pairs, with per-row delete — key generation
// never deletes old pairs, so this list is the merchant's only cleanup path).
// Registered unconditionally so it shows in direct mode too (the Store URL is then
// the site URL).
//
// Rows for the Connections and API Keys lists are fetched at render time — which
// only happens on this settings tab — so registering this field unconditionally
// adds no query to the init_form_fields() call that runs on every request.
$fields['shipstation_credentials'] = array(
	'title' => __( 'ShipStation Connection', 'woocommerce-shipstation-integration' ),
	'type'  => 'shipstation_credentials',
);

// Surface the WordPress.com connection + ShipStation REST credentials group at
// the top of the tab (SHIPSTN-142): the transport opt-in, the connection
// status/actions, and the inline ShipStation Connection section (credentials,
// connection list, and key list, rendered by
// generate_shipstation_credentials_html()) — the merchant's first task is wiring
// ShipStation up, so it leads. The order-mapping and other settings keep their
// relative order below. Keys absent in the current mode (e.g. wpcom_connection
// when the transport is off) are simply skipped.
$top_keys = array( 'wpcom_transport_enabled', 'shipstation_credentials' );
$ordered  = array();
foreach ( $top_keys as $top_key ) {
	if ( isset( $fields[ $top_key ] ) ) {
		$ordered[ $top_key ] = $fields[ $top_key ];
		unset( $fields[ $top_key ] );
	}
}
$fields = $ordered + $fields;

return $fields;
