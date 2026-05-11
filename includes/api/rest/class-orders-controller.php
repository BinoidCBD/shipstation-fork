<?php
/**
 * ShipStation REST API Orders Controller file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation\API\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WC_DateTime;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Coupon;
use WC_Order_Item_Product;
use WC_Order_Item_Tax;
use WC_Tax;
use WC_ShipStation_Integration;
use WooCommerce\Shipping\ShipStation\Main;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WooCommerce\Shipping\ShipStation\Order_Util;
use WooCommerce\Shipping\ShipStation\Checkout;
use Automattic\WooCommerce\Utilities\NumberUtil;
use Automattic\WooCommerce\Enums\OrderStatus;

/**
 * Orders_Controller class.
 */
class Orders_Controller extends API_Controller {

	/**
	 * Namespace for the REST API
	 *
	 * @var string
	 */
	protected string $namespace = 'wc-shipstation/v1';

	/**
	 * REST base for the controller.
	 *
	 * @var string
	 */
	protected string $rest_base = 'orders';

	/**
	 * Per-order currency code used for export context.
	 *
	 * @var string
	 */
	private string $currency_code = '';

	/**
	 * Per-order exchange rate used to convert monetary values for ShipStation.
	 *
	 * @var float
	 */
	private float $exchange_rate = 1.00;

	/**
	 * Whether to export discounts as adjustment entries in the payment info.
	 *
	 * @var bool
	 */
	private bool $export_discounts_as_separate_item = true;

	/**
	 * Initialize per-order context values using filters.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	private function set_order_context( WC_Order $order ): void {
		// Reset per-order context to defaults, in case it was previously set.
		$this->currency_code = '';
		$this->exchange_rate = 1.00;

		/**
		 * Currency code can be filtered by 3rd parties.
		 *
		 * @param string   $currency_code Currency code.
		 * @param WC_Order $order         Order object.
		 *
		 * @since 4.8.0
		 */
		$this->currency_code = apply_filters( 'woocommerce_shipstation_export_currency_code', $order->get_currency(), $order );
		/**
		 * Exchange rate can be filtered by 3rd parties.
		 *
		 * @param float    $exchange_rate Exchange rate.
		 * @param WC_Order $order         Order object.
		 *
		 * @since 4.8.0
		 */
		$this->exchange_rate = (float) apply_filters( 'woocommerce_shipstation_export_exchange_rate', 1.00, $order );

		/**
		 * Filter whether order discounts should be exported as adjustment entries to ShipStation.
		 *
		 * By default (true), discounts are exported as `Charge` objects within `payment.adjustments[]`
		 * in the JSON Order Source API payload. Each coupon is added as its own individual adjustment
		 * entry (e.g. "Discount from coupon: SAVE10"), and any non-coupon discounts (such as manual
		 * admin discounts not tied to a coupon code) are added as an additional "Additional Discount"
		 * entry. Coupon codes are also mapped to `payment.coupon_code` or `payment.coupon_codes`
		 * depending on how many coupons are present.
		 *
		 * This filter is provided to give developers flexibility in customizing how discounts
		 * are represented in the ShipStation REST API export.
		 *
		 * @see   https://linear.app/a8c/issue/SHIPSTN-57/orders-api-discounts-added-as-line-items#comment-a192e532
		 *
		 * @param bool     $export_discounts_as_separate_item Whether to export discounts as `payment.adjustments[]`
		 *                                                    Charge objects in the ShipStation REST API payload. Default true.
		 * @param WC_Order $order                             The WooCommerce order object.
		 *
		 * @since 4.5.1
		 */
		$this->export_discounts_as_separate_item = (bool) apply_filters( 'woocommerce_shipstation_export_discounts_as_separate_item', true, $order );
	}

	/**
	 * Get the per-order currency code.
	 */
	private function get_currency_code(): string {
		return $this->currency_code;
	}

	/**
	 * Get the per-order exchange rate.
	 */
	private function get_exchange_rate(): float {
		return $this->exchange_rate;
	}

	/**
	 * Whether to export discounts as a separate adjustment item.
	 */
	private function should_export_discounts_as_separate_item(): bool {
		return $this->export_discounts_as_separate_item;
	}

	/**
	 * Register the routes for the controller.
	 */
	public function register_routes(): void {
		// Register the endpoint for retrieving order data.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_orders' ),
				'permission_callback' => array( $this, 'check_get_permission' ),
				'args'                => array(
					'modified_after' => array(
						'description'       => __( 'Return orders modified after this date/time (ISO8601, UTC). Leave empty to ignore.', 'woocommerce-shipstation-integration' ),
						'type'              => 'string',
						'validate_callback' => function ( $param ) {
							if ( empty( $param ) ) {
								return true;
							}
							return false !== strtotime( $param );
						},
					),
					'page'           => array(
						'description'       => __( 'Page number of the results to return.', 'woocommerce-shipstation-integration' ),
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => function ( $value ) {
							return max( 1, absint( $value ) );
						},
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
					'per_page'       => array(
						'description'       => __( 'Maximum number of items to return per page (1–500).', 'woocommerce-shipstation-integration' ),
						'type'              => 'integer',
						'default'           => 100,
						'sanitize_callback' => function ( $value ) {
							return min( max( 1, absint( $value ) ), 500 ); // Limit between 1 and 500.
						},
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
					'status_mapping' => array(
						'description'       => __( 'Mapping of WooCommerce order statuses to ShipStation statuses. Format: wc_status:ShipStationStatus (e.g., processing:AwaitingShipment). Accepts multiple values.', 'woocommerce-shipstation-integration' ),
						'type'              => 'array',
						'sanitize_callback' => function ( $value ) {
							if ( empty( $value ) ) {
								return array();
							}
							return is_array( $value ) ? $value : array( $value );
						},
					),
				),
			)
		);

		// Register the endpoint for updating order shipment data.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/shipments',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_orders_shipments' ),
				'permission_callback' => array( $this, 'check_update_permission' ),
			)
		);
	}

	/**
	 * REST API permission callback.
	 *
	 * @return boolean
	 */
	public function check_get_permission(): bool {
		/**
		 * Filters whether the current user has permissions to manage WooCommerce.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $can_manage_wc Whether the user can manage WooCommerce.
		 */
		return apply_filters( 'wc_shipstation_user_can_manage_wc', wc_rest_check_manager_permissions( 'attributes', 'read' ) );
	}

	/**
	 * REST API permission callback.
	 *
	 * @return boolean
	 */
	public function check_update_permission(): bool {
		/**
		 * Filters whether the current user has permissions to manage WooCommerce.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $can_manage_wc Whether the user can manage WooCommerce.
		 */
		return apply_filters( 'wc_shipstation_user_can_manage_wc', wc_rest_check_manager_permissions( 'attributes', 'create' ) );
	}

	/**
	 * Get mapped WC order status with ShipStation status.
	 *
	 * @return array
	 */
	public function get_order_status_mapping(): array {
		$mapped_status = array();
		foreach ( WC_ShipStation_Integration::$status_mapping as $shipstation_status => $wc_statuses ) {
			if ( ! is_array( $wc_statuses ) ) {
				continue;
			}

			$mapped_status[ $shipstation_status ] = array_map(
				function ( $status ) {
					return str_replace( WC_ShipStation_Integration::$wc_status_prefix, '', $status );
				},
				$wc_statuses
			);
		}

		return $mapped_status;
	}

	/**
	 * Get the shipstation status from WC order status.
	 *
	 * @param string $order_status The order status to map.
	 *
	 * @return string
	 */
	public function get_shipstation_status_from_order( string $order_status ): string {
		$status_mapping = $this->get_order_status_mapping();

		foreach ( $status_mapping as $shipstation_status => $wc_statuses ) {
			if ( in_array( $order_status, $wc_statuses, true ) ) {
				return $shipstation_status;
			}
		}

		return 'Unknown';
	}

	/**
	 * Get the order status mapping.
	 *
	 * @param string $status The ShipStation status to map.
	 *
	 * @return array
	 */
	public function get_order_status_from_shipstation( string $status ): array {
		$status_mapping = $this->get_order_status_mapping();

		foreach ( $status_mapping as $shipstation_status => $wc_statuses ) {
			if ( $status === $shipstation_status ) {
				return $wc_statuses;
			}
		}

		return array();
	}

	/**
	 * Retrieve the orders data.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_orders( WP_REST_Request $request ): WP_REST_Response {
		$request_params = $request->get_params();

		/**
		* Action hook before the `/orders` REST API endpoint process the request parameter.
		*
		* @param array $request_params
		*
		* @since 4.8.0
		*/
		do_action( 'woocommerce_shipstation_get_orders_before_process_request', $request_params );

		// Ensure third-party export filters (e.g. Product Bundles) are loaded.
		$this->fire_legacy_api_action();

		// Get parameters.
		$modified_after = isset( $request_params['modified_after'] ) ? strtotime( $request_params['modified_after'] ) : null;
		$page           = absint( $request_params['page'] ); // Default to page 1.
		$per_page       = intval( $request_params['per_page'] ); // Default to 100 items per page.
		$status_mapping = isset( $request_params['status_mapping'] ) ? $request_params['status_mapping'] : array();

		$status_mapping = is_array( $status_mapping ) ? wc_clean( $status_mapping ) : array( wc_clean( $status_mapping ) );
		$order_statuses = array();

		foreach ( $status_mapping as $status ) {
			$parts = explode( ':', $status );

			if ( ! is_array( $parts ) || count( $parts ) !== 2 || empty( $parts[0] ) || empty( $parts[1] ) ) {
				continue;
			}

			$wc_statuses = $this->get_order_status_from_shipstation( $parts[1] );

			if ( empty( $wc_statuses ) ) {
				continue;
			}

			foreach ( $wc_statuses as $wc_status ) {
				$order_statuses[] = WC_ShipStation_Integration::$wc_status_prefix . strtolower( $wc_status );
			}
		}

		if ( empty( $order_statuses ) ) {
			// If no statuses are provided, use the default export statuses.
			// This is to ensure that we always have some statuses to query.
			// Default export statuses can be defined in the integration class.
			$order_statuses = WC_ShipStation_Integration::$export_statuses;
			$this->log( __( 'No order statuses provided in the request. Using default export statuses from the settings.', 'woocommerce-shipstation-integration' ) );
		} else {
			// Only use the order status that has been set from the ShipStation plugin settings.
			$order_statuses = array_unique( $order_statuses );
			$order_statuses = array_intersect(
				$order_statuses,
				WC_ShipStation_Integration::$export_statuses
			);

			// If no valid order statuses,
			// use unregistered/invalid order statuses to make sure the WC Order query return 0 order.
			if ( empty( $order_statuses ) ) {
				$order_statuses = array( 'wc-shipstation-unknown' );
			}
		}

		$args = array(
			'status'   => $order_statuses,
			'limit'    => $per_page,
			'paged'    => $page,
			'paginate' => true,
			'return'   => 'ids',
			'orderby'  => 'modified',
			'order'    => 'DESC',
		);

		if ( ! empty( $modified_after ) ) {
			$args['date_modified'] = '>=' . $modified_after;
		}

		$results = wc_get_orders( $args );

		if ( is_wp_error( $results ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Error retrieving orders.', 'woocommerce-shipstation-integration' ) ), 500 );
		}

		$total_orders = $results->total;

		// Calculate pagination information.
		$total_pages = $results->max_num_pages;
		$has_more    = $page < $total_pages;

		// Prepare the response data.
		$sales_orders_data = array(
			'sales_orders' => array(),
			'pagination'   => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total_orders,
				'total_pages' => $total_pages,
				'has_more'    => $has_more,
			),
		);

		if ( empty( $results->orders ) || empty( $total_orders ) ) {
			// No sales orders found, return an empty response.
			return new WP_REST_Response( $sales_orders_data, 200 );
		}

		foreach ( $results->orders as $order_id ) {
			/**
			 * Allow third party to skip the export of certain order ID.
			 *
			 * @param boolean $flag Flag to skip the export.
			 * @param int     $order_id Order ID.
			 *
			 * @since 4.1.42
			 */
			if ( ! apply_filters( 'woocommerce_shipstation_export_order', true, $order_id ) ) {
				continue;
			}

			/**
			 * Allow third party to change the order object.
			 *
			 * @param WC_Order $order Order object.
			 *
			 * @since 4.1.42
			 */
			$order = apply_filters( 'woocommerce_shipstation_export_get_order', wc_get_order( $order_id ) );

			if ( ! Order_Util::is_wc_order( $order ) ) {
				/* translators: 1: order id */
				$this->log( sprintf( __( 'Order %s can not be found.', 'woocommerce-shipstation-integration' ), $order_id ) );
				continue;
			}

			$sales_orders_data['sales_orders'][] = $this->get_order_data( $order );

			// Add order note to indicate it has been exported to Shipstation.
			if ( 'yes' !== $order->get_meta( '_shipstation_exported', true ) ) {
				$order->add_order_note( __( 'Order has been exported to Shipstation', 'woocommerce-shipstation-integration' ) );
				$order->update_meta_data( '_shipstation_exported', 'yes' );
				$order->save_meta_data();
			}
		}

		return new WP_REST_Response( $sales_orders_data, 200 );
	}

	/**
	 * Get order data for the response.
	 *
	 * @param WC_Order $order The order object.
	 *
	 * @return array
	 */
	public function get_order_data( WC_Order $order ): array {
		$extra_args = array();
		// Initialize per-order export context.
		$this->set_order_context( $order );

		$currency_code = $this->get_currency_code();
		$paid_date     = $this->get_shipstation_date_format( $order->get_date_paid() );

		$formatted_order_number   = ltrim( $order->get_order_number(), '#' );
		$shipstation_order_status = $this->get_shipstation_status_from_order( $order->get_status() );

		if ( 'Unknown' === $shipstation_order_status ) {
			// translators: 1: order id, 2: WC order status, 3: shipstation order status.
			$this->log( sprintf( __( 'Order %1$s has an unmapped WooCommerce status (%2$s). Defaulting to ShipStation status "%3$s". Please review your status mappings.', 'woocommerce-shipstation-integration' ), $order->get_id(), $order->get_status(), $shipstation_order_status ) );
		}

		$order_data = array(
			'order_id'               => $order->get_id(),
			'order_number'           => $formatted_order_number,
			'status'                 => $shipstation_order_status,
			'paid_date'              => $paid_date,
			'requested_fulfillments' => $this->get_requested_fulfillments( $order, $extra_args ),
			'buyer'                  => $this->get_buyer( $order ),
			'bill_to'                => array(
				'email'          => $order->get_billing_email(),
				'name'           => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'phone'          => $order->get_billing_phone(),
				'company'        => $order->get_billing_company(),
				'address_line_1' => $order->get_billing_address_1(),
				'address_line_2' => $order->get_billing_address_2(),
				'city'           => $order->get_billing_city(),
				'state_province' => $order->get_billing_state(),
				'postal_code'    => $order->get_billing_postcode(),
				'country_code'   => $order->get_billing_country(),
			),
			'currency'               => $currency_code,
			'payment'                => $this->get_payment_info( $order, $extra_args ),
			'ship_from'              => array(
				'name'           => '', // TO-DO later.
				'company'        => '', // TO-DO later.
				'phone'          => '', // TO-DO later.
				'address_line_1' => WC()->countries->get_base_address(),
				'address_line_2' => WC()->countries->get_base_address_2(),
				'address_line_3' => '',
				'city'           => WC()->countries->get_base_city(),
				'state_province' => WC()->countries->get_base_state(),
				'postal_code'    => WC()->countries->get_base_postcode(),
				'country_code'   => WC()->countries->get_base_country(),
			),
			'order_url'              => $order->get_checkout_order_received_url(),
			'notes'                  => $this->get_notes( $order ),
			'created_date_time'      => $this->get_shipstation_date_format( $order->get_date_created() ),
			'modified_date_time'     => $this->get_shipstation_date_format( $order->get_date_modified() ),
		);

		/**
		 * Filter to allow modification of the order data before it is returned.
		 *
		 * This filter is useful for adding more information to the order data such as :
		 * - `tax_identifier`
		 * - `original_order_source`
		 * - `fulfilled_date`
		 *
		 * For more information on all available parameter,
		 * please refer to this ShipStation API documentation : https://ddnmn7gngv.apidog.io/orders-18958392e0.
		 *
		 * @param array    $order_data The order data to be returned.
		 * @param WC_Order $order      The order object.
		 *
		 * @since 4.7.6
		 */
		return apply_filters( 'woocommerce_shipstation_orders_controller_get_order_data', $order_data, $order );
	}

	/**
	 * Get buyer information from order.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array
	 */
	public function get_buyer( WC_Order $order ): array {
		$buyer = $order->get_user();

		if ( false !== $buyer ) {
			return array(
				'buyer_id' => $buyer->user_login,
				'name'     => $buyer->user_firstname . ' ' . $buyer->user_lastname,
				'email'    => $buyer->user_email,
				'phone'    => $order->get_billing_phone(),
			);
		}

		return array(
			'name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'email' => $order->get_billing_email(),
			'phone' => $order->get_billing_phone(),
		);
	}

	/**
	 * Get Payment info for the order data.
	 *
	 * @param WC_Order $order      Order object.
	 * @param array    $extra_args Extra args.
	 *
	 * @return array
	 */
	public function get_payment_info( WC_Order $order, array $extra_args ): array {
		$order_total = $order->get_total() - floatval( $order->get_total_refunded() );

		// Maybe convert the order total using per-order exchange rate.
		$rate = $this->get_exchange_rate();
		if ( 1.00 !== $rate ) {
			$order_total = floatval( $order_total * $rate );
		}

		$payment_info = array(
			'payment_status'   => $this->get_payment_status( $order->get_status() ),
			'taxes'            => $this->get_taxes( $order->get_taxes(), $extra_args ),
			'shipping_charges' => $this->get_shipping_charges( $order->get_shipping_methods(), $extra_args ),
			'amount_paid'      => $order_total,
			'payment_method'   => $order->get_payment_method(),
		);

		if ( $this->should_export_discounts_as_separate_item() ) {
			$payment_info = $this->set_payment_adjustments( $payment_info, $order );
		}

		return $payment_info;
	}

	/**
	 * Populates payment adjustment entries for order discounts in the ShipStation REST API payload.
	 *
	 * Coupon codes are also mapped to `payment.coupon_code` (single coupon) or
	 * `payment.coupon_codes` (multiple coupons) in the payload.
	 *
	 * @param array    $payment_info The payment info array being built for the ShipStation API payload.
	 * @param WC_Order $order        The order object.
	 *
	 * @return array The modified payment info array with adjustment entries populated.
	 */
	private function set_payment_adjustments( array $payment_info, WC_Order $order ): array {
		$coupon_discount_total = 0.0;
		$collected_codes       = array();
		$rate                  = $this->get_exchange_rate();

		if ( ! empty( $order->get_coupons() ) && is_array( $order->get_coupons() ) ) {
			foreach ( $order->get_coupons() as $coupon ) {
				if ( ! $coupon instanceof WC_Order_Item_Coupon ) {
					continue;
				}

				$coupon_amount          = floatval( $coupon->get_discount() );
				$coupon_discount_total += $coupon_amount;
				$collected_codes[]      = $coupon->get_code();

				// Maybe convert coupon amount using per-order exchange rate.
				if ( 1.00 !== $rate ) {
					$coupon_amount = floatval( $coupon_amount * $rate );
				}

				$payment_info['adjustments'][] = array(
					// translators: %1$s is a coupon code.
					'description' => sprintf( __( 'Discount from coupon: %1$s', 'woocommerce-shipstation-integration' ), $coupon->get_code() ),
					'amount'      => -NumberUtil::round( $coupon_amount, wc_get_price_decimals() ),
				);
			}
		}

		if ( 1 === count( $collected_codes ) ) {
			$payment_info['coupon_code'] = $collected_codes[0];
		} elseif ( count( $collected_codes ) > 1 ) {
			$payment_info['coupon_codes'] = $collected_codes;
		}

		// Handle non-coupon discounts (e.g. manual admin discounts not tied to a coupon code).
		$non_coupon_discount = NumberUtil::round(
			floatval( $order->get_total_discount() ) - $coupon_discount_total,
			wc_get_price_decimals()
		);

		if ( $non_coupon_discount > 0 ) {
			if ( 1.00 !== $rate ) {
				$non_coupon_discount = floatval( $non_coupon_discount * $rate );
			}

			$payment_info['adjustments'][] = array(
				'description' => __( 'Additional Discount', 'woocommerce-shipstation-integration' ),
				'amount'      => -$non_coupon_discount,
			);
		}

		return $payment_info;
	}

	/**
	 * Get the payment status for ShipStation based on WooCommerce order status.
	 *
	 * @param string $order_status The WooCommerce order status.
	 *
	 * @return string
	 */
	public function get_payment_status( string $order_status ): string {
		// Map WooCommerce order status to ShipStation payment status.
		switch ( $order_status ) {
			case OrderStatus::PENDING:
				return WC_ShipStation_Integration::AWAITING_PAYMENT_STATUS;
			case OrderStatus::ON_HOLD:
				return WC_ShipStation_Integration::AWAITING_PAYMENT_STATUS;
			case OrderStatus::CANCELLED:
				return WC_ShipStation_Integration::PAYMENT_CANCELLED_STATUS;
			case OrderStatus::REFUNDED:
				return WC_ShipStation_Integration::PAYMENT_CANCELLED_STATUS;
			case OrderStatus::FAILED:
				return WC_ShipStation_Integration::PAYMENT_FAILED_STATUS;
			default:
				return WC_ShipStation_Integration::PAID_STATUS;
		}
	}

	/**
	 * Get the taxes for the order.
	 *
	 * @param array $order_taxes Order taxes.
	 * @param array $extra_args  Extra args.
	 *
	 * @return array
	 */
	public function get_taxes( array $order_taxes, array $extra_args ): array {
		$taxes = array();

		foreach ( $order_taxes as $tax ) {
			if ( ! $tax instanceof WC_Order_Item_Tax ) {
				continue; // Skip if not a valid order item tax.
			}

			$tax_amount = floatval( $tax->get_tax_total() ) + floatval( $tax->get_shipping_tax_total() );

			// Maybe convert the tax amount using per-order exchange rate.
			$rate = $this->get_exchange_rate();
			if ( 1.00 !== $rate ) {
				$tax_amount = floatval( $tax_amount * $rate );
			}

			$taxes[] = array(
				'amount'      => $tax_amount,
				'description' => $tax->get_name(),
			);
		}

		return $taxes;
	}

	/**
	 * Get the shipping charges for the order.
	 *
	 * @param array $order_shipping_methods Order shipping methods.
	 * @param array $extra_args Extra args.
	 *
	 * @return array
	 */
	public function get_shipping_charges( $order_shipping_methods, $extra_args ): array {
		$shipping_charges = array();

		foreach ( $order_shipping_methods as $shipping_method ) {
			if ( ! $shipping_method instanceof \WC_Order_Item_Shipping ) {
				continue; // Skip if not a valid order item shipping.
			}

			$shipping_amount = floatval( $shipping_method->get_total() );

			// Maybe convert the shipping amount using per-order exchange rate.
			$rate = $this->get_exchange_rate();
			if ( 1.00 !== $rate ) {
				$shipping_amount = floatval( $shipping_amount * $rate );
			}

			$shipping_charges[] = array(
				'amount'      => $shipping_amount,
				'description' => $shipping_method->get_method_title(),
			);
		}

		return $shipping_charges;
	}

	/**
	 * Format a WC_DateTime as a ShipStation-compatible ISO 8601 UTC string.
	 *
	 * @param WC_DateTime|null $date The date object to format.
	 *
	 * @return string ISO 8601 UTC string (e.g., '2025-01-15T14:30:00.000Z'), or empty string if null.
	 */
	public function get_shipstation_date_format( ?WC_DateTime $date ): string {
		if ( is_null( $date ) ) {
			return '';
		}

		$utc_date = clone $date;
		$utc_date->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $utc_date->format( 'Y-m-d\TH:i:s' ) . '.000Z';
	}

	/**
	 * Get requested fulfillments for the order.
	 *
	 * @param WC_Order $order      The order object.
	 * @param array    $extra_args Extra args.
	 *
	 * @return array
	 */
	public function get_requested_fulfillments( WC_Order $order, array $extra_args ): array {
		$fulfillments      = array();
		$fulfillment_items = array();
		$order_items       = $order->get_items() + $order->get_items( 'fee' );

		foreach ( $order_items as $item ) {
			$fulfillment_item = $this->get_fulfillment_item( $item, $order, 0, $extra_args );

			if ( empty( $fulfillment_item ) ) {
				continue;
			}

			$fulfillment_items[] = $fulfillment_item;
		}

		if ( empty( $fulfillment_items ) ) {
			return $fulfillments;
		}

		$fulfillments[] = $this->get_fulfillment( $fulfillment_items, $order );

		return $fulfillments;
	}

	/**
	 * Get fulfillment info.
	 *
	 * @param array    $fulfillment_items Fulfillment items.
	 * @param WC_Order $order             Order object.
	 * @param string   $fulfillment_id    WC Fulfillment ID.
	 *
	 * @return array
	 */
	public function get_fulfillment( array $fulfillment_items, WC_Order $order, string $fulfillment_id = '' ): array {

		$gift         = $this->get_gift( $order );
		$address_data = Order_Util::get_address_data( $order );

		return array(
			'requested_fulfillment_id' => $fulfillment_id,
			'ship_to'                  => array(
				'name'           => $address_data['name'],
				'company'        => $address_data['company'],
				'phone'          => $address_data['phone'],
				'address_line_1' => $address_data['address1'],
				'address_line_2' => $address_data['address2'],
				'city'           => $address_data['city'],
				'state_province' => $address_data['state'],
				'postal_code'    => $address_data['postcode'],
				'country_code'   => $address_data['country'],
			),
			'items'                    => $fulfillment_items,
			'extensions'               => $this->get_custom_fields( $order ),
			'shipping_preferences'     => array(
				'gift'             => $gift['is_gift'],
				'shipping_service' => Order_Util::get_shipping_methods( $order, false ),
			),
		);
	}

	/**
	 * Get fulfillment item from WC Fulfillment.
	 *
	 * @param WC_Order_Item $item       Order item object.
	 * @param WC_Order      $order      Order object.
	 * @param int           $quantity   Item quantity.
	 * @param array         $extra_args Extra args.
	 */
	public function get_fulfillment_item( $item, $order, $quantity = 0, $extra_args = array() ) {
		$fulfillment_item = array();

		if ( ! $item instanceof WC_Order_Item ) {
			return $fulfillment_item;
		}

		$item_id                 = $item->get_id();
		$is_order_item_a_product = $item instanceof WC_Order_Item_Product;
		$product                 = $is_order_item_a_product ? $item->get_product() : false;
		$item_needs_no_shipping  = ! $product || ! $product->needs_shipping();
		$item_not_a_fee          = 'fee' !== $item->get_type();

		/**
		 * Allow third party to exclude the item for when an item does not need shipping or is a fee.
		 *
		 * @since 4.1.31
		 */
		if ( apply_filters( 'woocommerce_shipstation_no_shipping_item', $item_needs_no_shipping && $item_not_a_fee, $product, $item ) ) {
			return $fulfillment_item;
		}

		$unit_price   = 0;
		$item_url     = '';
		$item_product = array();
		$item_taxes   = array();

		if ( 'fee' === $item->get_type() ) {
			$quantity   = 1;
			$unit_price = $order->get_item_total( $item, false, true );

			// Maybe convert fee item total using per-order exchange rate.
			$rate = $this->get_exchange_rate();
			if ( 1.00 !== $rate ) {
				$unit_price = floatval( $unit_price * $rate );
			}
		}

		// handle product specific data.
		if ( $is_order_item_a_product && $product && $product->needs_shipping() ) {
			/**
			 * Handle product specific data.
			 *
			 * @var WC_Order_Item_Product $item
			 */
			$product_id   = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$item_url     = $product->get_permalink();
			$unit_cost    = $this->get_item_cogs_value( $item );
			$item_product = array(
				'product_id'  => $product_id,
				'name'        => $item->get_name(),
				'description' => $product->get_description(),
				'identifiers' => array(
					'sku'             => $product->get_sku(),
					'isbn'            => $product->get_global_unique_id(),
					'fulfillment_sku' => isset( $extra_args['fulfillment_id'] ) ? $extra_args['fulfillment_id'] : '',
				),
				'details'     => $this->get_item_details( $item ),
				'unit_cost'   => $unit_cost,
				'weight'      => $this->get_item_weight( $product ),
				'dimensions'  => $this->get_item_dimensions( $product ),
				'urls'        => array(
					'image_url'     => $this->get_image_src_url( $product->get_image_id(), 'full' ),
					'product_url'   => $product->get_permalink(),
					'thumbnail_url' => $this->get_image_src_url( $product->get_image_id(), 'woocommerce_thumbnail' ),
				),
			);

			$item_taxes = $item->get_taxes();

			if ( 0 === $quantity ) {
				$quantity = $item->get_quantity() - abs( $order->get_qty_refunded_for_item( $item_id ) );
			}

			$unit_price = $this->should_export_discounts_as_separate_item() ? $order->get_item_subtotal( $item, false, true ) : $order->get_item_total( $item, false, true );

			// Maybe convert item total using per-order exchange rate.
			$rate = $this->get_exchange_rate();
			if ( 1.00 !== $rate ) {
				$unit_price        = floatval( $unit_price * $rate );
			}
		}

		if ( 0 === $quantity ) {
			return $fulfillment_item;
		}

		$fulfillment_item = array_filter(
			array(
				'line_item_id'       => $item_id,
				'description'        => $item->get_name(),
				'product'            => $item_product,
				'quantity'           => $quantity,
				'unit_price'         => $unit_price,
				'taxes'              => $this->get_item_taxes( $item_taxes ),
				'item_url'           => $item_url,
				'modified_date_time' => $this->get_shipstation_date_format( $order->get_date_modified() ),
			),
			function ( $value ) {
				return ! empty( $value );
			}
		);

		/**
		 * Filters the fulfillment item data before it is returned.
		 *
		 * This filter allows third-party plugins to modify the fulfillment item data
		 * that will be sent to ShipStation.
		 *
		 * @since 4.8.0
		 *
		 * @param array         $fulfillment_item The fulfillment item data.
		 * @param WC_Order      $order            The order object.
		 * @param WC_Order_Item $item             The order item data.
		 * @param array         $extra_args       Extra arguments passed to the method.
		 */
		return apply_filters( 'woocommerce_shipstation_fulfillment_item', $fulfillment_item, $order, $item, $extra_args );
	}

	/**
	 * Get item weight.
	 *
	 * @param \WC_Product $product WooCommerce Product Object.
	 *
	 * @return array
	 */
	public function get_item_weight( $product ) {
		$weight_unit    = strtolower( get_option( 'woocommerce_weight_unit' ) );
		$ss_weight_unit = 'Kilogram';

		switch ( $weight_unit ) {
			case 'g':
				$ss_weight_unit = 'Gram';
				break;
			case 'lbs':
				$ss_weight_unit = 'Pound';
				break;
			case 'oz':
				$ss_weight_unit = 'Ounce';
				break;
			default:
				$ss_weight_unit = 'Kilogram';
				$weight_unit    = 'kg'; // Make sure the weight unit is consistent.
				break;
		}

		return array(
			'unit'  => $ss_weight_unit,
			'value' => wc_get_weight( floatval( $product->get_weight() ), $weight_unit ),
		);
	}

	/**
	 * Get the COGS value for an order item.
	 *
	 * Caches the result of the feature flag check in a static local variable
	 * to avoid repeated container lookups across items.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @return float
	 */
	private function get_item_cogs_value( WC_Order_Item_Product $item ): float {
		static $enabled = null;

		if ( null === $enabled ) {
			$enabled = Order_Util::is_cogs_enabled();
		}

		return ( $enabled && is_callable( array( $item, 'get_cogs_value' ) ) ) ? $item->get_cogs_value() : 0;
	}

	/**
	 * Get item dimensions.
	 *
	 * @param \WC_Product $product WooCommerce Product Object.
	 *
	 * @return array
	 */
	public function get_item_dimensions( $product ) {
		$dimension_unit = strtolower( get_option( 'woocommerce_dimension_unit' ) );

		switch ( $dimension_unit ) {
			case 'in':
				$ss_dimension_unit = 'Inch';
				break;
			default:
				$ss_dimension_unit = 'Centimeter';
				$dimension_unit    = 'cm';
				break;
		}

		return array(
			'length' => wc_get_dimension( floatval( $product->get_length() ), $dimension_unit ),
			'width'  => wc_get_dimension( floatval( $product->get_width() ), $dimension_unit ),
			'height' => wc_get_dimension( floatval( $product->get_height() ), $dimension_unit ),
			'unit'   => $ss_dimension_unit,
		);
	}

	/**
	 * Get taxes information from the item.
	 *
	 * @param array $item_taxes Item Taxes.
	 *
	 * @return array
	 */
	public function get_item_taxes( $item_taxes ) {
		$taxes = array();

		if ( ! is_array( $item_taxes ) || empty( $item_taxes['total'] ) ) {
			return $taxes;
		}

		foreach ( $item_taxes['total'] as $rate_id => $rate_value ) {
			$tax_label = WC_Tax::get_rate_label( $rate_id );

			$taxes[] = array(
				'amount'      => floatval( $rate_value ),
				'description' => ! empty( $tax_label ) ? $tax_label : __( 'Tax', 'woocommerce-shipstation-integration' ),
			);
		}

		return $taxes;
	}

	/**
	 * Get gift info.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array
	 */
	public function get_gift( $order ) {
		$gift = array(
			'is_gift'      => false,
			'gift_message' => '',
		);

		// Maybe append the gift and gift message XML element.
		if ( class_exists( 'WooCommerce\Shipping\ShipStation\Checkout' ) && $order->get_meta( Checkout::get_block_prefixed_meta_key( 'is_gift' ) ) ) {
			$gift['is_gift'] = true;

			$gift_message = $order->get_meta( Checkout::get_block_prefixed_meta_key( 'gift_message' ) );

			if ( ! empty( $gift_message ) ) {
				$gift['gift_message'] = wp_specialchars_decode( $gift_message );
			}
		}

		return $gift;
	}

	/**
	 * Get a list of notes.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array
	 */
	public function get_notes( WC_Order $order ): array {

		$notes = array();

		if ( ! empty( $order->get_customer_note() ) ) {
			$notes[] = array(
				'type' => 'NotesFromBuyer',
				'text' => $order->get_customer_note(),
			);
		}

		$gift = $this->get_gift( $order );

		if ( ! empty( $gift['gift_message'] ) ) {
			$notes[] = array(
				'type' => 'GiftMessage',
				'text' => $gift['gift_message'],
			);
		}

		$internal_notes = Order_Util::get_order_notes( $order );

		if ( ! empty( $internal_notes ) ) {
			$notes[] = array(
				'type' => 'InternalNotes',
				'text' => implode( ' | ', $internal_notes ),
			);
		}

		return $notes;
	}
	/**
	 * Get custom fields
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array
	 */
	public function get_custom_fields( $order ) {
		$custom_fields = array();

		// Custom fields - 1 is used for coupon codes.
		$custom_fields['custom_field_1'] = implode( ' | ', $order->get_coupon_codes() );

		// Custom fields 2 and 3 can be mapped to a custom field via the following filters.

		/**
		 * Custom fields 2 can be mapped to a custom field via the following filters.
		 *
		 * @since 4.0.1
		 */
		$meta_key = apply_filters( 'woocommerce_shipstation_export_custom_field_2', '' );
		if ( $meta_key ) {
			/**
			 * Allowing third party to modify the custom field 2 value.
			 *
			 * @since 4.1.0
			 */
			$custom_fields['custom_field_2'] = apply_filters( 'woocommerce_shipstation_export_custom_field_2_value', $order->get_meta( $meta_key, true ), $order->get_id() );
		}

		/**
		 * Custom fields 3 can be mapped to a custom field via the following filters.
		 *
		 * @since 4.0.1
		 */
		$meta_key = apply_filters( 'woocommerce_shipstation_export_custom_field_3', '' );
		if ( $meta_key ) {
			/**
			 * Allowing third party to modify the custom field 3 value.
			 *
			 * @since 4.1.0
			 */
			$custom_fields['custom_field_3'] = apply_filters( 'woocommerce_shipstation_export_custom_field_3_value', $order->get_meta( $meta_key, true ), $order->get_id() );
		}

		return $custom_fields;
	}

	/**
	 * Get item details for the order.
	 *
	 * @param WC_Order_Item $item Order item.
	 *
	 * @return array
	 */
	public function get_item_details( $item ): array {
		$item_details = array();

		add_filter( 'woocommerce_is_attribute_in_product_name', '__return_false' );
		$formatted_meta = $item->get_formatted_meta_data();

		if ( empty( $formatted_meta ) ) {
			return $item_details;
		}

		foreach ( $formatted_meta as $meta_key => $meta ) {
			$item_details[] = array(
				'name'  => html_entity_decode( $meta->display_key, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'value' => html_entity_decode( wp_strip_all_tags( $meta->display_value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			);
		}

		return $item_details;
	}

	/**
	 * Get the image source URL for a given image ID and size.
	 *
	 * @param int    $image_id Image ID.
	 * @param string $size     Image size (default is 'full').
	 *
	 * @return string
	 */
	public function get_image_src_url( $image_id, $size = 'full' ): string {
		if ( ! $image_id ) {
			return '';
		}

		$image_src = wp_get_attachment_image_src( $image_id, $size );

		if ( ! is_array( $image_src ) || empty( $image_src[0] ) ) {
			return '';
		}

		return esc_url( $image_src[0] );
	}

	/**
	 * Update orders shipments for specified SKUs (both products and variations).
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function update_orders_shipments( WP_REST_Request $request ): WP_REST_Response {
		$request_params = $request->get_json_params();
		$notifications  = isset( $request_params['notifications'] ) && is_array( $request_params['notifications'] ) ? $request_params['notifications'] : array();

		if ( empty( $notifications ) || ! is_array( $notifications ) ) {
			return new WP_REST_Response( 'Invalid request format.', 400 );
		}

		$response = array();

		foreach ( $notifications as $notification ) {
			$saved_notification = array(
				'notification_id'  => '',
				'tracking_number'  => '',
				'tracking_url'     => '',
				'carrier_code'     => '',
				'ext_locatin_id'   => '',
				'items'            => array(),
				'ship_to'          => array(),
				'ship_from'        => array(),
				'return_address'   => array(),
				'ship_date'        => '',
				'currency'         => '',
				'fulfillment_cost' => 0.0,
				'insurance_cost'   => 0.0,
				'notify_buyer'     => false,
				'notes'            => array(),
			);

			if ( empty( $notification['notification_id'] ) ) {
				$this->log( __( 'Notification ID is empty for this notification: ', 'woocommerce-shipstation-integration' ) . print_r( $notification, true ) );// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r --- Its needed for logging
				continue; // Skip if notification ID is not set.
			}

			if ( empty( $notification['order_id'] ) || empty( $notification['items'] ) || ! is_array( $notification['items'] ) ) {
				$response[] = array(
					'notification_id' => $notification['notification_id'],
					'status'          => 'failure',
					'failure_reason'  => __( 'Empty order ID or items', 'woocommerce-shipstation-integration' ),
				);

				// translators: %1$s is the notification id.
				$this->log( sprintf( __( 'Notification ID: %1$s doesnt have order ID or items.', 'woocommerce-shipstation-integration' ), $notification['notification_id'] ) );

				continue; // Skip invalid items.
			}

			if ( ! is_numeric( $notification['order_id'] ) ) {
				$response[] = array(
					'notification_id' => $notification['notification_id'],
					'status'          => 'failure',
					'failure_reason'  => __( 'Order ID is not numeric', 'woocommerce-shipstation-integration' ),
				);

				// translators: %1$s is the order id, %2$d is the notification id.
				$this->log( sprintf( __( 'Order ID: %1$d from notification ID: %2$s is not numeric.', 'woocommerce-shipstation-integration' ), $notification['order_id'], $notification['notification_id'] ) );

				continue; // Skip if product_id is not numeric.
			}

			$order_id     = absint( $notification['order_id'] );
			$order_number = '';
			$order        = wc_get_order( $order_id );

			// Fallback: try order number if order ID lookup failed.
			if ( ! $order instanceof WC_Order ) {
				$order_number = isset( $notification['order_number'] ) ? (string) $notification['order_number'] : '';
				$order        = wc_get_order( Order_Util::get_order_id_from_order_number( $order_number ) );
			}

			// Skip if order still not found.
			if ( ! $order instanceof WC_Order ) {
				$response[] = array(
					'notification_id' => $notification['notification_id'],
					'status'          => 'failure',
					'failure_reason'  => __( 'Order not found', 'woocommerce-shipstation-integration' ),
				);

				$this->log(
					sprintf(
					// translators: %1$d is the order ID, %2$s is the order number.
						__( 'Order ID: %1$d or Order number: %2$s cannot be found.', 'woocommerce-shipstation-integration' ),
						$order_id,
						$order_number
					)
				);

				continue;
			}

			$saved_notification = wp_parse_args( $notification, $saved_notification );

			$saved_items = array();

			foreach ( $notification['items'] as $item ) {
				if ( empty( $item['description'] ) && empty( $item['quantity'] ) ) {
					$this->log( __( 'Skipped this item because doesnt have description and quantity: ', 'woocommerce-shipstation-integration' ) . print_r( $item, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r --- Its needed for logging
					continue; // Skip if required fields are not set.
				}

				$saved_item = array(
					'description'  => '',
					'quantity'     => '',
					'line_item_id' => '',
					'sku'          => '',
					'product_id'   => '',
				);

				$saved_item             = wp_parse_args( $item, $saved_item );
				$saved_item['quantity'] = absint( $saved_item['quantity'] );
				$order_item             = $order->get_item( $saved_item['line_item_id'] );

				if ( $order_item instanceof WC_Order_Item_Product ) {
					$order_item_product        = $order_item->get_product();
					$saved_item['description'] = $order_item->get_name();
					$saved_item['sku']         = $order_item_product->get_sku();
					$saved_item['product_id']  = $order_item->get_id();
				}

				$saved_items[] = $saved_item;

				$this->log( __( 'ShipNotify Item: ', 'woocommerce-shipstation-integration' ) . print_r( $saved_item, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r --- Its needed for logging
			}

			if ( ! empty( $notification['ship_to'] ) ) {
				$saved_notification['ship_to'] = $this->parse_address_info( $notification['ship_to'] );
			}

			if ( ! empty( $notification['ship_from'] ) ) {
				$saved_notification['ship_from'] = $this->parse_address_info( $notification['ship_from'] );
			}

			if ( ! empty( $notification['return_address'] ) ) {
				$saved_notification['return_address'] = $this->parse_address_info( $notification['return_address'] );
			}

			if ( ! empty( $notification['ship_date'] ) && strtotime( $notification['ship_date'] ) ) {
				$saved_notification['ship_date'] = gmdate( 'Y-m-d H:i:s', strtotime( $notification['ship_date'] ) );
			}

			if ( ! empty( $notification['fulfillment_cost'] ) ) {
				$saved_notification['fulfillment_cost'] = floatval( $notification['fulfillment_cost'] );
			}

			if ( ! empty( $notification['insurance_cost'] ) ) {
				$saved_notification['insurance_cost'] = floatval( $notification['insurance_cost'] );
			}

			if ( ! empty( $notification['notify_buyer'] ) ) {
				$saved_notification['notify_buyer'] = filter_var( $notification['notify_buyer'], FILTER_VALIDATE_BOOLEAN );
			}

			if ( ! empty( $notification['notes'] ) ) {
				$saved_notification['notes'] = $this->parse_notes( $notification['notes'] );
			}

			if ( ! empty( $saved_items ) ) {
				$saved_notification['items'] = $saved_items;
				$this->process_items( $saved_items, $order, $saved_notification );
			}

			$response[] = array(
				'notification_id' => $notification['notification_id'],
				'status'          => 'success',
				'order_id'        => $order->get_id(),
			);
		}

		return new WP_REST_Response(
			array(
				'notification_results' => $response,
			),
			200
		);
	}

	/**
	 * Process items from the ShipStation notification.
	 *
	 * @param array    $items        Items from the notification.
	 * @param WC_Order $order        The order object.
	 * @param array    $notification The notification data.
	 *
	 * @return void
	 */
	public function process_items( array $items, WC_Order $order, array $notification ) {
		$shipped_items      = array();
		$shipped_item_count = 0;
		$order_shipped      = false;
		$timestamp          = false !== strtotime( $notification['ship_date'] ) ? strtotime( $notification['ship_date'] ) : wp_date( 'U' );
		$tracking_number    = ! empty( $notification['tracking_number'] ) ? wc_clean( wp_unslash( $notification['tracking_number'] ) ) : '';
		$tracking_url       = ! empty( $notification['tracking_url'] ) ? wc_clean( wp_unslash( $notification['tracking_url'] ) ) : '';
		$carrier            = ! empty( $notification['carrier_code'] ) ? wc_clean( wp_unslash( $notification['carrier_code'] ) ) : '';

		foreach ( $items as $item ) {
			$item_sku    = wc_clean( (string) $item['sku'] );
			$item_name   = wc_clean( (string) $item['description'] );
			$qty_shipped = absint( $item['quantity'] );

			if ( $item_sku ) {
				$item_sku = ' (' . $item_sku . ')';
			}

			$item_id = absint( $item['line_item_id'] );
			if ( ! Order_Util::is_shippable_item( $order, $item_id ) ) {
				/* translators: 1: item name */
				$this->log( sprintf( __( 'Item %s is not shippable product. Skipping.', 'woocommerce-shipstation-integration' ), $item_name ) );
				continue;
			}

			$shipped_item_count += $qty_shipped;
			$shipped_items[]     = $item_name . $item_sku . ' x ' . $qty_shipped;
		}

		// Number of items in WC order.
		$total_item_count = Order_Util::order_items_to_ship_count( $order );

		// If we have a list of shipped items, we can customise the note + see
		// if the order is not yet complete.
		if ( count( $shipped_items ) > 0 ) {
			$order_note = sprintf(
				/* translators: 1) shipped items 2) carrier's name 3) shipped date, 4) tracking number */
				__( '%1$s shipped via %2$s on %3$s with tracking number %4$s.', 'woocommerce-shipstation-integration' ),
				esc_html( implode( ', ', $shipped_items ) ),
				esc_html( $carrier ),
				date_i18n( get_option( 'date_format' ), $timestamp ),
				$tracking_number
			);

			$current_shipped_items = max( (int) $order->get_meta( '_shipstation_shipped_item_count', true ), 0 );

			if ( ( $current_shipped_items + $shipped_item_count ) >= $total_item_count ) {
				$order_shipped = true;
			}

			$this->log(
				sprintf(
					/* translators: 1) number of shipped items 2) total shipped items 3) order ID */
					__( 'Shipped %1$d out of %2$d items in order %3$s', 'woocommerce-shipstation-integration' ),
					$shipped_item_count,
					$total_item_count,
					$order->get_id()
				)
			);

			$order->update_meta_data( '_shipstation_shipped_item_count', $current_shipped_items + $shipped_item_count );
			$order->save_meta_data();
		} else {
			// If we don't have items from SS and order items in WC.
			$order_shipped = 0 === $total_item_count;

			$order_note = sprintf(
				/* translators: 1) carrier's name 2) shipped date, 3) tracking number */
				__( 'Items shipped via %1$s on %2$s with tracking number %3$s (Shipstation).', 'woocommerce-shipstation-integration' ),
				esc_html( $carrier ),
				date_i18n( get_option( 'date_format' ), $timestamp ),
				$tracking_number
			);

			/* translators: 1: order id */
			$this->log( sprintf( __( 'No items found - shipping entire order %d.', 'woocommerce-shipstation-integration' ), $order->get_id() ) );
		}

		$current_status = 'wc-' . $order->get_status();

		// Tracking information - WC Shipment Tracking extension.
		if ( class_exists( 'WC_Shipment_Tracking' ) ) {
			if ( function_exists( 'wc_st_add_tracking_number' ) ) {
				wc_st_add_tracking_number( $order->get_id(), $tracking_number, strtolower( $carrier ), $timestamp );
			} else {
				$order->update_meta_data( '_tracking_provider', strtolower( $carrier ) );
				$order->update_meta_data( '_tracking_number', $tracking_number );
				$order->update_meta_data( '_date_shipped', $timestamp );
				$order->save_meta_data();
				$this->log( __( 'You\'re using Shipment Tracking < 1.4.0. Please update!', 'woocommerce-shipstation-integration' ) );
			}

			$is_customer_note = false;
		} else {
			$is_customer_note = WC_ShipStation_Integration::$shipped_status !== $current_status;
		}

		$tracking_data = array(
			'tracking_number' => $tracking_number,
			'carrier'         => $carrier,
			'ship_date'       => $timestamp,
			'data'            => $notification,
			'xml'             => '',
		);

		/**
		* Allow to override tracking note.
		*
		* @param string   $order_note
		* @param WC_Order $order
		* @param array    $tracking_data
		*
		* @since 4.5.0
		*/
		$order_note = apply_filters(
			'woocommerce_shipstation_shipnotify_tracking_note',
			$order_note,
			$order,
			$tracking_data
		);

		$order->add_order_note(
			$order_note,
			/**
			* Allow to override should tracking note be sent to customer.
			*
			* @param bool     $is_customer_note
			* @param string   $order_note
			* @param WC_Order $order
			* @param array    $tracking_data
			*
			* @since 4.5.0
			*/
			apply_filters(
				'woocommerce_shipstation_shipnotify_send_tracking_note',
				$is_customer_note,
				$order_note,
				$order,
				$tracking_data
			)
		);

		/**
		 * Trigger action for other integrations.
		 *
		 * @param WC_Order $order         Order object.
		 * @param array    $tracking_data Tracking data.
		 *
		 * @since 4.0.1
		 */
		do_action(
			'woocommerce_shipstation_shipnotify',
			$order,
			$tracking_data
		);

		// Update order status.
		if (
			/**
			* Allow to override is order shipped flag.
			*
			* @param bool     $order_shipped
			* @param WC_Order $order
			* @param array    $tracking_data
			*
			* @since 4.5.0
			*/
			apply_filters(
				'woocommerce_shipstation_shipnotify_order_shipped',
				$order_shipped,
				$order,
				$tracking_data
			)
			&& WC_ShipStation_Integration::$shipped_status !== $current_status
		) {
			$order->update_status( WC_ShipStation_Integration::$shipped_status );

			/* translators: 1) order ID 2) shipment status */
			$this->log( sprintf( __( 'Updated order %1$s to status %2$s', 'woocommerce-shipstation-integration' ), $order->get_id(), WC_ShipStation_Integration::$shipped_status ) );

			/**
			 * Trigger action after the order status is changed for other integrations.
			 *
			 * @param WC_Order $order         Order object.
			 * @param array    $tracking_data Tracking data.
			 *
			 * @since 4.5.2
			 */
			do_action(
				'woocommerce_shipstation_shipnotify_status_updated',
				$order,
				$tracking_data
			);
		}
	}

	/**
	 * Parse notes and return a standardized array.
	 *
	 * @param array $notes Notes data, can be an array of objects.
	 *
	 * @return array
	 */
	public function parse_notes( $notes ): array {
		$parsed_notes = array();

		if ( ! is_array( $notes ) || empty( $notes ) ) {
			return $parsed_notes;
		}

		foreach ( $notes as $note ) {
			if ( is_object( $note ) ) {
				$note = (array) $note;
			}

			if ( ! isset( $note['type'], $note['text'] ) ) {
				continue; // Skip if note is not valid.
			}

			$parsed_notes[] = array(
				'type' => $note['type'],
				'text' => $note['text'],
			);
		}

		return $parsed_notes;
	}

	/**
	 * Parse address information and return a standardized array.
	 *
	 * @param mixed $address Address data, can be an object or array.
	 *
	 * @return array
	 */
	public function parse_address_info( $address ): array {
		$address         = json_decode( wp_json_encode( $address ), true );
		$default_address = array(
			'name'                  => '',
			'company'               => '',
			'phone'                 => '',
			'address_line_1'        => '',
			'address_line_2'        => '',
			'address_line_3'        => '',
			'city'                  => '',
			'state_province'        => '',
			'postal_code'           => '',
			'country_code'          => '',
			'is_verified'           => false,
			'residential_indicator' => '',
			'pickup_location'       => array(
				'carrier_id' => '',
				'relay_id'   => '',
			),
		);

		return wp_parse_args( $address, $default_address );
	}

	/**
	 * Fire the legacy woocommerce_api_wc_shipstation action if it hasn't
	 * already been fired in this request.
	 *
	 * Plugins like WooCommerce Product Bundles hook into this
	 * action to register filters on woocommerce_order_get_items and
	 * woocommerce_order_item_product that reshape order items for export.
	 *
	 * In the XML API path the action fires naturally via WooCommerce's
	 * legacy API mechanism, but the REST API path never triggers it.
	 * Calling this method at the top of the REST endpoint ensures those
	 * third-party filters are in place before any order items are retrieved.
	 *
	 * @return void
	 */
	protected function fire_legacy_api_action(): void {
		if ( did_action( 'woocommerce_api_wc_shipstation' ) ) {
			return;
		}

		$main_instance = Main::instance();
		$removed       = remove_action( 'woocommerce_api_wc_shipstation', array( $main_instance, 'load_api' ) );

		do_action( 'woocommerce_api_wc_shipstation' );

		if ( $removed ) {
			add_action( 'woocommerce_api_wc_shipstation', array( $main_instance, 'load_api' ) );
		}
	}
}
