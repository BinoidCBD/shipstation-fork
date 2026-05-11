<?php
/**
 * ShipStation Shipping Method class file.
 *
 * @package WC_ShipStation
 * @since 4.9.6
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ShipStation checkout rates shipping method.
 *
 * @since 4.9.6
 */
class Checkout_Rates_Shipping_Method extends \WC_Shipping_Method {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Shipping method instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		parent::__construct( $instance_id );

		$this->id                 = 'shipstation_checkout_rates';
		$this->method_title       = __( 'ShipStation Rates', 'woocommerce-shipstation-integration' );
		$this->method_description = __( 'Provide real-time shipping rates from ShipStation during checkout.', 'woocommerce-shipstation-integration' );
		$this->supports           = array( 'shipping-zones', 'instance-settings' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option( 'title', $this->method_title );
	}

	/**
	 * Define instance form fields.
	 */
	public function init_form_fields() {
		$this->instance_form_fields = array(
			'title' => array(
				'title'   => __( 'Title', 'woocommerce-shipstation-integration' ),
				'type'    => 'text',
				'default' => __( 'ShipStation Rates', 'woocommerce-shipstation-integration' ),
			),
		);
	}

	/**
	 * Calculate shipping rates.
	 *
	 * @param array $package Shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
	}
}
