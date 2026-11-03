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
use WC_Order_Refund;
use WC_Order_Item;
use WC_Order_Item_Coupon;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Item_Tax;
use WC_Product;
use WC_Tax;
use WC_ShipStation_Integration;
use WooCommerce\Shipping\ShipStation\Main;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WooCommerce\Shipping\ShipStation\Order_Util;
use WooCommerce\Shipping\ShipStation\GPDT\Prepaid_Duties;
use WooCommerce\Shipping\ShipStation\Checkout;
use Automattic\WooCommerce\Utilities\NumberUtil;
use WooCommerce\Shipping\ShipStation\Enum_Helper;
use WP_Error;
use WooCommerce\Shipping\ShipStation\Logger;

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
	 * Alias of Order_Util::NO_STATUS_MATCH_TRANSIENT. The constant was public
	 * here in 5.3.5, so it stays as a compatibility alias.
	 *
	 * @internal Not a public API: subject to change without a deprecation window.
	 *
	 * @since 5.3.5
	 *
	 * @var string
	 */
	public const NO_STATUS_MATCH_TRANSIENT = Order_Util::NO_STATUS_MATCH_TRANSIENT;

	/**
	 * Order IDs the shrunken-page warning names.
	 *
	 * @var int
	 */
	private const MAX_SKIPPED_IDS_LOGGED = 20;

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
						// Schema documentation only: has_valid_params() skips
						// an arg with no validate_callback, so the sanitize
						// callback below is what actually enforces the shape.
						// Deliberate - a strict validator would answer 400 to
						// requests ShipStation is served today.
						'items'             => array( 'type' => 'string' ),
						'sanitize_callback' => function ( $value ) {
							if ( empty( $value ) ) {
								return array();
							}
							$values = is_array( $value ) ? $value : array( $value );

							// Drop nested arrays; explode() would fatal on them.
							return array_values( array_map( 'strval', array_filter( $values, 'is_scalar' ) ) );
						},
					),
					'order_ids'      => array(
						'description'       => __( 'Return specific orders by ID. When present, page/per_page/modified_after are ignored and orders are returned regardless of status.', 'woocommerce-shipstation-integration' ),
						'type'              => 'array',
						// Schema documentation only; the sanitize callback is
						// the guard. See status_mapping above.
						'items'             => array( 'type' => 'integer' ),
						'validate_callback' => function ( $value ) {
							if ( is_array( $value ) && count( $value ) > 500 ) {
								return new WP_Error(
									'rest_invalid_param',
									__( 'order_ids[] must contain 500 or fewer IDs.', 'woocommerce-shipstation-integration' ),
									array( 'status' => 400 )
								);
							}
							return true;
						},
						'sanitize_callback' => function ( $value ) {
							if ( empty( $value ) || ! is_array( $value ) ) {
								return array();
							}
							return array_values(
								array_filter(
									array_map( 'absint', $value ),
									function ( $id ) {
										return $id > 0;
									}
								)
							);
						},
					),
				),
			)
		);

		// Register the endpoint for updating order shipment data. The full
		// route is duplicated in Main::SHIPMENTS_ROUTE, which classifies the
		// raw request before controllers load; moving this route means
		// updating that constant too.
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
	 * REST API permission callback for GET /orders.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return bool|WP_Error See API_Controller::check_namespace_permission().
	 */
	public function check_get_permission( WP_REST_Request $request ) {
		return $this->check_namespace_permission( $request, 'attributes', 'read' );
	}

	/**
	 * REST API permission callback for POST /orders/shipments.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return bool|WP_Error See API_Controller::check_namespace_permission().
	 */
	public function check_update_permission( WP_REST_Request $request ) {
		return $this->check_namespace_permission( $request, 'attributes', 'create' );
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
	 * Map a WooCommerce order status to its ShipStation `SalesOrderStatus`.
	 *
	 * A status with no mapping falls back to `OnHold`, which holds the order for
	 * review instead of auto-shipping it: an unmapped status's intent is unknown,
	 * and a hold is recoverable while a shipment is not (SHIPSTN-131).
	 *
	 * @param string        $order_status The WC order status to map.
	 * @param WC_Order|null $order        The order being exported, for log context. Optional.
	 *
	 * @return string A ShipStation `SalesOrderStatus` value.
	 */
	public function get_shipstation_status_from_order( string $order_status, ?WC_Order $order = null ): string {
		$status_mapping = $this->get_order_status_mapping();

		foreach ( $status_mapping as $shipstation_status => $wc_statuses ) {
			if ( in_array( $order_status, $wc_statuses, true ) ) {
				return $shipstation_status;
			}
		}

		if ( 'refunded' === $order_status ) {
			return WC_ShipStation_Integration::CANCELLED_STATUS;
		}

		// No mapping for this WC status. Default to OnHold — a valid
		// SalesOrderStatus — so the order still imports but is gated for human
		// review rather than auto-shipped (an unknown status could mean
		// "do-not-ship", and holding is recoverable whereas auto-shipping is not).
		$shipstation_status = WC_ShipStation_Integration::ON_HOLD_STATUS;

		if ( $order instanceof WC_Order ) {
			// translators: 1: order id, 2: WC order status, 3: shipstation order status.
			$this->log( sprintf( __( 'Order %1$s has an unmapped WooCommerce status (%2$s). Defaulting to ShipStation status "%3$s". Please review your status mappings.', 'woocommerce-shipstation-integration' ), $order->get_id(), $order_status, $shipstation_status ) );
		}

		return $shipstation_status;
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
				if ( WC_ShipStation_Integration::CANCELLED_STATUS === $shipstation_status && ! in_array( 'refunded', $wc_statuses, true ) ) {
					$wc_statuses[] = 'refunded';
				}

				return $wc_statuses;
			}
		}

		return array();
	}

	/**
	 * Retrieve the orders data.
	 *
	 * A throwable escaping here used to die mid-dispatch with nothing logged
	 * (SHIPSTN-171); it is answered with a logged 500 instead.
	 *
	 * @since 5.3.5 Uncontained failures return a logged 500.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_orders( WP_REST_Request $request ): WP_REST_Response {
		// The buffer is what makes the 500 below reach ShipStation. Output from
		// a third-party callback during the batch sends the headers, and the
		// status is then already 200: without it ShipStation gets that 200 with
		// an unparseable body and no reason to retry (SHIPSTN-171).
		$buffer_level = ob_get_level();
		$failure      = null;
		$response     = null;
		$stray        = '';

		ob_start();

		try {
			$response = $this->process_get_orders( $request );
		} catch ( \Throwable $e ) {
			$failure = $e;
		} finally {
			$stray = Order_Util::discard_buffers_safely( $buffer_level );
		}

		if ( null !== $failure ) {
			Order_Util::log_uncontained_export_failure( $failure, $stray );

			return new WP_REST_Response( array( 'message' => __( 'Error retrieving orders.', 'woocommerce-shipstation-integration' ) ), 500 );
		}

		$this->log_stray_export_output( $stray );

		return $response;
	}

	/**
	 * Record output discarded from the export buffer.
	 *
	 * Keeping it out of the response is not enough on its own: the plugin that
	 * printed it stays unidentifiable unless the log says so (SHIPSTN-171).
	 *
	 * The write runs outside the boundary and is a handoff of its own -- any
	 * plugin can replace the logger -- so it is isolated: contained against a
	 * handler that throws, buffered against one that prints.
	 *
	 * @since 5.3.5
	 *
	 * @param string $stray Discarded output, already bounded.
	 * @return void
	 */
	private function log_stray_export_output( string $stray ): void {
		if ( '' === trim( $stray ) ) {
			return;
		}

		Order_Util::log_isolated(
			'the discarded-output warning',
			static function () use ( $stray ) {
				Logger::warning(
					sprintf(
						/* translators: %s: the discarded output */
						__( 'Output emitted during the orders export was discarded to keep the response valid. Another plugin is printing during the export: %s', 'woocommerce-shipstation-integration' ),
						$stray
					)
				);
			}
		);
	}

	/**
	 * Record a contained degradation without letting the write fail the page.
	 *
	 * These run inside the export boundary, so a replaced logger that threw
	 * would turn a page that degraded gracefully into a 500, and one that
	 * printed would corrupt the payload (SHIPSTN-171).
	 *
	 * @since 5.3.5
	 *
	 * @param string $message Composed message; foreign text already sanitised.
	 * @return void
	 */
	private static function log_export_degradation( string $message ): void {
		Order_Util::log_isolated(
			'the export degradation log',
			static function () use ( $message ) {
				Logger::error( $message );
			}
		);
	}

	/**
	 * Warn once per page when it carries fewer orders than the query found.
	 *
	 * A page that shrinks below the advertised pagination total is the ticket's
	 * own symptom, and on a default install nothing said so: the skip was a
	 * debug line, and the export_order filter's skip was not logged at all
	 * (SHIPSTN-171). One line per page instead of one per order, because
	 * per_page reaches 500 and a store that legitimately excludes orders would
	 * otherwise write that many entries on every poll, forever.
	 *
	 * Contained: a log write is a handoff, and one broken handler must not fail
	 * the whole page.
	 *
	 * @since 5.3.5
	 *
	 * @param int   $filtered_out Orders the export_order filter declined.
	 * @param int[] $unavailable  Order IDs that produced no exportable order.
	 * @return void
	 */
	private function warn_page_shrank( int $filtered_out, array $unavailable ): void {
		if ( 0 === $filtered_out && empty( $unavailable ) ) {
			return;
		}

		$sample = array_slice( $unavailable, 0, self::MAX_SKIPPED_IDS_LOGGED );

		$message = sprintf(
			/* translators: 1: orders declined by the filter, 2: orders that could not be loaded, 3: comma-separated order IDs, or a dash */
			__( 'This orders page is shorter than the query found: %1$d declined by the woocommerce_shipstation_export_order filter, %2$d could not be loaded (IDs: %3$s).', 'woocommerce-shipstation-integration' ),
			$filtered_out,
			count( $unavailable ),
			empty( $sample ) ? '-' : implode( ', ', $sample )
		);

		Order_Util::log_isolated(
			'the shrunken-page warning',
			static function () use ( $message ) {
				Logger::warning( $message );
			}
		);
	}

	/**
	 * Log when explicitly requested order IDs were not exported.
	 *
	 * The by-IDs path has no query and no page: ShipStation named each ID, so
	 * an ID that cannot be loaded deserves its own wording, not the paged
	 * surface's (an unknown ID is a data problem; a shrunken page is a hook).
	 *
	 * @since 5.3.5
	 *
	 * @param int   $filtered_out Orders declined by the export filter.
	 * @param array $unavailable  Requested IDs that resolved to no exportable order.
	 * @return void
	 */
	private function warn_requested_ids_missing( int $filtered_out, array $unavailable ): void {
		if ( 0 === $filtered_out && empty( $unavailable ) ) {
			return;
		}

		$sample = array_slice( $unavailable, 0, self::MAX_SKIPPED_IDS_LOGGED );

		$message = sprintf(
			/* translators: 1: orders declined by the filter, 2: requested order IDs that could not be loaded, 3: comma-separated order IDs, or a dash */
			__( 'Not every requested order was exported: %1$d declined by the woocommerce_shipstation_export_order filter, %2$d of the order_ids could not be loaded (IDs: %3$s).', 'woocommerce-shipstation-integration' ),
			$filtered_out,
			count( $unavailable ),
			empty( $sample ) ? '-' : implode( ', ', $sample )
		);

		Order_Util::log_isolated(
			'the missing-requested-orders warning',
			static function () use ( $message ) {
				Logger::warning( $message );
			}
		);
	}

	/**
	 * The empty page, in the envelope a zero-result query produces.
	 *
	 * @since 5.3.7
	 *
	 * @param int $page     Requested page number.
	 * @param int $per_page Requested page size.
	 * @return WP_REST_Response
	 */
	private function empty_orders_page( int $page, int $per_page ): WP_REST_Response {
		return new WP_REST_Response( $this->orders_page_data( array(), $page, $per_page, 0, 0 ), 200 );
	}

	/**
	 * The orders page envelope.
	 *
	 * @since 5.3.7
	 *
	 * @param array $sales_orders Order payloads.
	 * @param int   $page         Page number.
	 * @param int   $per_page     Page size.
	 * @param int   $total        Total orders reported.
	 * @param int   $total_pages  Total pages reported.
	 * @return array
	 */
	private function orders_page_data( array $sales_orders, int $page, int $per_page, int $total, int $total_pages ): array {
		return array(
			'sales_orders' => $sales_orders,
			'pagination'   => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total,
				'total_pages' => $total_pages,
				'has_more'    => $page < $total_pages,
			),
		);
	}

	/**
	 * Build the orders export response. Runs inside the get_orders() boundary.
	 *
	 * @since 5.3.5
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	private function process_get_orders( WP_REST_Request $request ): WP_REST_Response {
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

		// When specific IDs are requested, bypass the normal date/pagination query.
		// The sanitize_callback already returns a clean list of positive integer IDs;
		// the cast only guards against a non-array value from a direct/internal caller.
		$order_ids_param = isset( $request_params['order_ids'] )
			? (array) $request_params['order_ids']
			: array();

		if ( ! empty( $order_ids_param ) ) {
			return $this->get_orders_by_id_param( $order_ids_param );
		}

		// Get parameters.
		$modified_after = isset( $request_params['modified_after'] ) ? strtotime( $request_params['modified_after'] ) : null;
		$page           = absint( $request_params['page'] ); // Default to page 1.
		$per_page       = intval( $request_params['per_page'] ); // Default to 100 items per page.
		// In Manual status-mapping mode the merchant owns the export-status list and
		// custom statuses ShipStation never sees must still be honored, so the
		// `status_mapping` request param is ignored — only API mode lets ShipStation
		// dictate the per-request status filter (SHIPSTN-117).
		$status_mapping = ( WC_ShipStation_Integration::STATUS_MODE_API === WC_ShipStation_Integration::$status_mode && isset( $request_params['status_mapping'] ) )
			? $request_params['status_mapping']
			: array();

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

		$export_statuses = (array) WC_ShipStation_Integration::$export_statuses;
		$partition       = Order_Util::partition_export_statuses( $export_statuses );
		$matchable       = $partition['kept'];
		$skipped         = $partition['skipped'];

		// Query only the registered entries; see Order_Util::matchable_export_statuses().
		if ( empty( $matchable ) ) {
			Order_Util::warn_export_statuses_cannot_match( $export_statuses );

			return $this->empty_orders_page( $page, $per_page );
		}

		if ( empty( $order_statuses ) ) {
			Order_Util::warn_skipped_export_statuses( $skipped );

			// No statuses in the request: query the matchable export statuses.
			$order_statuses = $matchable;

			$requested_mapping = array_values( array_filter( $status_mapping, 'is_string' ) );

			if ( empty( $requested_mapping ) ) {
				$this->log( __( 'No order statuses provided in the request. Using default export statuses from the settings.', 'woocommerce-shipstation-integration' ) );
			} else {
				// A supplied mapping that resolves to nothing is a
				// misconfiguration, not an absent parameter: the fallback is
				// the same, but the log must not claim nothing was asked for
				// (SHIPSTN-171).
				// Request data on its way to a log line: statuses_for_log()
				// bounds it so one poll cannot write a large entry, and
				// sanitises it because wc_clean() leaves the non-newline
				// control characters alone.
				sort( $requested_mapping );
				$reported_mapping = Order_Util::statuses_for_log( $requested_mapping );

				// The key seed covers the full sorted set: seeding from the
				// shortened text would let two mappings that agree on their
				// first entries share a throttle and hide the second one.
				Order_Util::warn_export_status_once(
					'unresolved|' . implode( ',', $requested_mapping ),
					sprintf(
						/* translators: 1: status mapping requested by ShipStation, 2: export statuses queried instead */
						__( 'The requested status mapping (%1$s) could not be resolved to any order status. Using the default export statuses (%2$s).', 'woocommerce-shipstation-integration' ),
						$reported_mapping,
						Order_Util::statuses_for_log( $matchable )
					)
				);
			}
		} else {
			// Query only statuses that are both requested and matchable.
			$requested_statuses = array_unique( $order_statuses );
			$order_statuses     = array_intersect( $requested_statuses, $matchable );

			// Nothing requested can be served, so answer without a query.
			if ( empty( $order_statuses ) ) {
				// The one empty page that used to say nothing in the log
				// (SHIPSTN-171). Throttled per requested status set, not
				// globally: a store polled by two connections has two mappings,
				// and a single key reports whichever mismatched first and hides
				// the other. Sorted so the same set always hashes alike.
				$throttle_statuses = $requested_statuses;
				sort( $throttle_statuses );

				// Enabled in the setting but no longer registered, typically a
				// deactivated custom-status plugin: a different cause, so its
				// own line and throttle, and the skipped-status line stays
				// quiet because it would name the same statuses. Only when
				// every requested status is one: otherwise the mismatch line
				// names the request and the statuses that can be queried, and
				// the skipped-status line explains the rest.
				$unregistered = array_intersect( $throttle_statuses, Order_Util::prefixed_export_statuses( $skipped ) );

				if ( ! empty( $unregistered ) && count( $unregistered ) === count( $throttle_statuses ) ) {
					Order_Util::warn_export_status_once(
						'unregistered|' . implode( ',', $unregistered ),
						sprintf(
							/* translators: %s: statuses enabled for export that are no longer registered */
							__( 'The requested status mapping resolves only to export statuses that are no longer registered on this store (%s). Returning an empty orders page. The plugin that added these statuses may be deactivated.', 'woocommerce-shipstation-integration' ),
							Order_Util::statuses_for_log( $unregistered )
						)
					);
				} else {
					Order_Util::warn_skipped_export_statuses( $skipped );
					Order_Util::warn_export_status_once(
						'mismatch|' . implode( ',', $throttle_statuses ),
						sprintf(
							/* translators: 1: statuses requested by ShipStation, 2: export statuses that are enabled and registered */
							__( 'The requested status mapping (%1$s) matches no enabled export status (%2$s). Returning an empty orders page. Review the Export Order Statuses setting.', 'woocommerce-shipstation-integration' ),
							Order_Util::statuses_for_log( $requested_statuses ),
							Order_Util::statuses_for_log( $matchable )
						)
					);
				}

				return $this->empty_orders_page( $page, $per_page );
			}

			Order_Util::warn_skipped_export_statuses( $skipped );
		}

		$args = array(
			// Refund rows are stored as wc-completed; without the pin they eat
			// page slots and inflate the totals (SHIPSTN-171).
			'type'     => 'shop_order',
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
			// The last 500 path that logged nothing (SHIPSTN-171).
			$query_error = sprintf(
				/* translators: %s: error message from the order query */
				__( 'The orders query failed: %s. No orders were returned to ShipStation.', 'woocommerce-shipstation-integration' ),
				// Foreign text: WP_Error here comes from a third-party
				// woocommerce_order_query callback.
				Order_Util::sanitize_for_log( $results->get_error_message() )
			);

			Order_Util::log_isolated(
				'the failed-query error',
				static function () use ( $query_error ) {
					Logger::error( $query_error );
				}
			);

			return new WP_REST_Response( array( 'message' => __( 'Error retrieving orders.', 'woocommerce-shipstation-integration' ) ), 500 );
		}

		$total_orders = $results->total;

		$sales_orders_data = $this->orders_page_data( array(), $page, $per_page, (int) $total_orders, (int) $results->max_num_pages );

		if ( empty( $results->orders ) || empty( $total_orders ) ) {
			// No sales orders found, return an empty response.
			return new WP_REST_Response( $sales_orders_data, 200 );
		}

		$ids_to_fetch = array();
		$filtered_out = 0;
		$unavailable  = array();

		foreach ( $results->orders as $order_id ) {
			/**
			 * Allow third party to skip the export of certain order ID.
			 *
			 * @param boolean $flag Flag to skip the export.
			 * @param int     $order_id Order ID.
			 *
			 * @since 4.1.42
			 */
			if ( apply_filters( 'woocommerce_shipstation_export_order', true, $order_id ) ) {
				$ids_to_fetch[] = (int) $order_id;
				continue;
			}

			++$filtered_out;
		}

		// Must run BEFORE the order hydration below; see the
		// prime_refunds_for_batch() docblock for the rationale (SHIPSTN-164).
		Order_Util::prime_refunds_for_batch( $ids_to_fetch );

		$orders_by_id = $this->get_orders_by_ids( $ids_to_fetch );

		$orders_to_mark = array();

		try {
			// Inside the try: the notes prime seeds the map for every ID before
			// it queries, and its queries run third-party comment-clause
			// callbacks that can throw, which would leave the map populated.
			$this->prime_batch_caches( $ids_to_fetch );
			Order_Util::prime_products_for_batch( $orders_by_id );

			foreach ( $ids_to_fetch as $order_id ) {
				/**
				 * Allow third party to change the order object.
				 *
				 * @param WC_Order $order Order object.
				 *
				 * @since 4.1.42
				 */
				$order = apply_filters(
					'woocommerce_shipstation_export_get_order',
					$orders_by_id[ $order_id ] ?? false
				);

				if ( ! Order_Util::is_wc_order( $order ) ) {
					$unavailable[] = (int) $order_id;
					continue;
				}

				$sales_orders_data['sales_orders'][] = $this->get_order_data( $order );
				$orders_to_mark[]                    = $order;
			}

			Order_Util::mark_orders_exported_bulk( $orders_to_mark );
			$this->warn_page_shrank( $filtered_out, $unavailable );
		} finally {
			// The page's payload pass is over; keep the notes map from
			// accumulating across pages in long-lived processes, even when
			// building one order's payload throws.
			Order_Util::flush_order_notes_cache();
			Order_Util::flush_qty_refund_failure_log();
			Order_Util::flush_degraded_refund_primes();
		}

		return new WP_REST_Response( $sales_orders_data, 200 );
	}

	/**
	 * Fetch and map a specific set of orders by WC order ID.
	 *
	 * Called when the `order_ids` request parameter is present. Bypasses
	 * the normal modified_after/pagination query and returns orders regardless
	 * of their WC status. IDs not found in the database are logged as warnings
	 * and omitted from the response.
	 *
	 * @since 5.1.0
	 *
	 * @param int[] $requested_ids Sanitised list of positive integer order IDs.
	 * @return WP_REST_Response
	 */
	private function get_orders_by_id_param( array $requested_ids ): WP_REST_Response {
		// Must run BEFORE the order hydration below; see the
		// prime_refunds_for_batch() docblock for the rationale (SHIPSTN-164).
		Order_Util::prime_refunds_for_batch( $requested_ids );

		$orders_by_id = $this->get_orders_by_ids( $requested_ids, array( 'status' => 'any' ) );

		$sales_orders   = array();
		$orders_to_mark = array();
		$filtered_out   = 0;
		$unavailable    = array();

		try {
			// Inside the try: the notes prime seeds the map for every ID before
			// it queries, and its queries run third-party comment-clause
			// callbacks that can throw, which would leave the map populated.
			$this->prime_batch_caches( array_keys( $orders_by_id ) );
			Order_Util::prime_products_for_batch( $orders_by_id );

			foreach ( $requested_ids as $order_id ) {
				$order_id = (int) $order_id;

				/**
				 * Allow third party to skip the export of certain order ID.
				 *
				 * @param boolean $flag     Flag to skip the export.
				 * @param int     $order_id Order ID.
				 *
				 * @since 4.1.42
				 */
				if ( ! apply_filters( 'woocommerce_shipstation_export_order', true, $order_id ) ) {
					++$filtered_out;
					continue;
				}

				if ( ! isset( $orders_by_id[ $order_id ] ) ) {
					$unavailable[] = $order_id;
					continue;
				}

				/**
				 * Allow third party to change the order object.
				 *
				 * @param WC_Order $order Order object.
				 *
				 * @since 4.1.42
				 */
				$order = apply_filters(
					'woocommerce_shipstation_export_get_order',
					$orders_by_id[ $order_id ]
				);

				if ( ! Order_Util::is_wc_order( $order ) ) {
					$unavailable[] = $order_id;
					continue;
				}

				$sales_orders[]   = $this->get_order_data( $order );
				$orders_to_mark[] = $order;
			}

			Order_Util::mark_orders_exported_bulk( $orders_to_mark );
			$this->warn_requested_ids_missing( $filtered_out, $unavailable );
		} finally {
			// The page's payload pass is over; keep the notes map from
			// accumulating across pages in long-lived processes, even when
			// building one order's payload throws.
			Order_Util::flush_order_notes_cache();
			Order_Util::flush_qty_refund_failure_log();
			Order_Util::flush_degraded_refund_primes();
		}

		$count = count( $sales_orders );

		return new WP_REST_Response( $this->orders_page_data( $sales_orders, 1, $count, $count, 1 ), 200 );
	}

	/**
	 * Bulk-fetch orders and return them indexed by ID.
	 *
	 * This is intentionally a second query. The upstream `wc_get_orders()` call in
	 * `get_orders()` uses `'return' => 'ids'` so it can retrieve pagination metadata
	 * (`total`, `max_num_pages`) without instantiating full order objects. After that
	 * cheap ID fetch, the `woocommerce_shipstation_export_order` filter runs per-ID and
	 * may remove some orders from the set. This method then bulk-hydrates only the
	 * orders that survived the filter, so no work is done for skipped orders.
	 *
	 * Passing all IDs at once to `wc_get_orders( [ 'post__in' => $ids ] )` is more
	 * efficient than N individual `wc_get_order()` calls on both storage backends:
	 * under HPOS it routes through `OrdersTableDataStore::read_multiple()`, which
	 * batches the orders/addresses/meta table queries for the whole set; under the
	 * legacy CPT backend, the underlying `WP_Query( post__in )` primes the WordPress
	 * post cache for every ID in one round trip so the per-order `read()` calls that
	 * follow are cache hits.
	 *
	 * If the bulk fetch throws (core's refund prime runs inside it and can hit
	 * a corrupted refund row when the batch could not be pre-primed), the
	 * batch degrades to one contained `wc_get_order()` read per order.
	 *
	 * @since 5.0.4
	 * @since 5.1.0 Added the $extra_query_args parameter.
	 * @since 5.3.4 Falls back to per-order reads when the bulk fetch throws.
	 *
	 * @param int[] $order_ids        Order IDs to fetch.
	 * @param array $extra_query_args Optional extra args merged into the wc_get_orders() call.
	 * @return array<int, WC_Order> Order objects indexed by ID.
	 */
	private function get_orders_by_ids( array $order_ids, array $extra_query_args = array() ): array {
		if ( empty( $order_ids ) ) {
			return array();
		}

		try {
			$orders = wc_get_orders(
				array_merge(
					array(
						'type'     => 'shop_order',
						'post__in' => $order_ids,
						'limit'    => -1,
					),
					$extra_query_args
				)
			);
		} catch ( \Throwable $e ) {
			// A batch fetch runs core's refund-cache prime for any order the
			// refund prime could not warm, and a corrupted refund row throws
			// there (SHIPSTN-164). Single reads never run that prime, so fall
			// back to one read per order and let an unreadable order degrade
			// alone. The fallback drops $extra_query_args and the query's
			// 'type' filter: the by-ID path is status-agnostic by design and
			// the paged path pre-filtered its IDs, but wc_get_order() returns
			// whatever the ID resolves to, so the type is re-checked below to
			// keep WC_Order subclasses like subscriptions out of the export.
			self::log_export_degradation(
				sprintf(
					'Bulk order fetch failed while loading the export batch: %s: %s. Retrying per order.',
					get_class( $e ),
					Order_Util::sanitize_for_log( $e->getMessage() )
				)
			);

			$orders = array();
			foreach ( $order_ids as $order_id ) {
				try {
					$order = wc_get_order( $order_id );
					if ( $order instanceof \WC_Order && 'shop_order' === $order->get_type() ) {
						$orders[] = $order;
					}
				} catch ( \Throwable $order_error ) {
					self::log_export_degradation(
						sprintf(
							'Order #%d could not be read and was left out of the export: %s: %s',
							$order_id,
							get_class( $order_error ),
							Order_Util::sanitize_for_log( $order_error->getMessage() )
						)
					);
				}
			}
		}

		$indexed = array();
		foreach ( (array) $orders as $order ) {
			if ( Order_Util::is_wc_order( $order ) ) {
				$indexed[ $order->get_id() ] = $order;
			}
		}

		return $indexed;
	}

	/**
	 * Warm caches for the current batch of orders before the payload pass.
	 *
	 * Primes `wc_order_items` and `wc_order_itemmeta` for every order in the batch
	 * in two queries. HPOS inherits `read_items()` from the CPT store but does not
	 * trigger its bulk priming helper, so without this the first `$order->get_items()`
	 * on each order issues a separate SELECT.
	 *
	 * Refunds are NOT primed here: both callers run
	 * `Order_Util::prime_refunds_for_batch()` before hydrating the batch's
	 * order objects (see its docblock, SHIPSTN-164).
	 *
	 * @since 5.0.4
	 *
	 * @param int[] $order_ids Orders IDs.
	 * @return void
	 */
	private function prime_batch_caches( array $order_ids ): void {
		if ( empty( $order_ids ) ) {
			return;
		}

		Order_Util::prime_order_items_for_batch( $order_ids );
		Order_Util::prime_order_notes_for_batch( $order_ids );
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
		$shipstation_order_status = $this->get_shipstation_status_from_order( $order->get_status(), $order );

		$order_data = array(
			'order_id'               => $order->get_id(),
			'order_number'           => $formatted_order_number,
			'status'                 => $shipstation_order_status,
			'paid_date'              => $paid_date,
			'requested_fulfillments' => $this->get_requested_fulfillments( $order, $extra_args ),
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
			'returns'                => $this->get_returns( $order ),
		);

		$buyer = $this->get_buyer( $order );
		if ( ! empty( $buyer ) ) {
			$order_data['buyer'] = $buyer;
		}

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
		$buyer = array();
		$user  = null;

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $name ) {
			$user = $order->get_user();

			if ( $user ) {
				$name = trim( $user->first_name . ' ' . $user->last_name );
			}
		}

		if ( '' === $name ) {
			$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		}

		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			if ( null === $user ) {
				$user = $order->get_user();
			}

			if ( $user ) {
				$email = $user->user_email;
			}
		}

		$phone = trim( $order->get_billing_phone() );
		if ( '' === $phone ) {
			$phone = trim( $order->get_shipping_phone() );
		}

		if ( '' !== $name ) {
			$buyer['name'] = $name;
		}

		if ( '' !== $phone ) {
			$buyer['phone'] = $phone;
		}

		if ( is_email( $email ) ) {
			$buyer['email']    = $email;
			$buyer['buyer_id'] = $email;
		}

		return $buyer;
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
			case Enum_Helper::status_pending():
				return WC_ShipStation_Integration::AWAITING_PAYMENT_STATUS;
			case Enum_Helper::status_on_hold():
				return WC_ShipStation_Integration::AWAITING_PAYMENT_STATUS;
			case Enum_Helper::status_cancelled():
				return WC_ShipStation_Integration::PAYMENT_CANCELLED_STATUS;
			case Enum_Helper::status_refunded():
				return WC_ShipStation_Integration::PAYMENT_CANCELLED_STATUS;
			case Enum_Helper::status_failed():
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
		$order_items       = $order->get_items( array( 'line_item', 'fee' ) );

		foreach ( $order_items as $item ) {
			// The prepaid duties amount belongs in the `duties` object, never in the items.
			if ( $item instanceof WC_Order_Item_Fee && Prepaid_Duties::is_prepaid_duties_fee( $item ) ) {
				continue;
			}

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

		$shipping_preferences = array(
			'gift'             => $gift['is_gift'],
			'shipping_service' => Order_Util::get_shipping_methods( $order, false ),
		);

		// Surface the customer's selected ShipStation Checkout Rates code so
		// ShipStation can auto-assign the chosen carrier service. Omitted entirely
		// when the order has no ShipStation rate code (e.g. a flat-rate shipment).
		$preplanned_fulfillment_id = Order_Util::get_checkout_rate_code( $order );
		if ( '' !== $preplanned_fulfillment_id ) {
			$shipping_preferences['preplanned_fulfillment_id'] = $preplanned_fulfillment_id;
		}

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
			'shipping_preferences'     => $shipping_preferences,
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
		$item_needs_shipping     = Order_Util::item_needs_shipping( $item, $product );
		$item_needs_no_shipping  = ! $item_needs_shipping;
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
		if ( $is_order_item_a_product && $product && $item_needs_shipping ) {
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
				$quantity = $item->get_quantity() - Order_Util::safe_qty_refunded_for_item( $order, $item_id );
			}

			// With a non-integer quantity (i.e. 0.375) always export the quantity as 1 with an added product details key-value.
			// eg : 3.1m of fabric, its just 1 item in the package.
			if ( floor( (float) $quantity ) !== (float) $quantity ) {
				$item_product['details'][] = array(
					'name'  => 'Amount',
					'value' => (string) $quantity,
				);

				$quantity = 1;
			}

			$unit_price = $this->should_export_discounts_as_separate_item() ? $order->get_item_subtotal( $item, false, true ) : $order->get_item_total( $item, false, true );

			// Maybe convert item total using per-order exchange rate.
			$rate = $this->get_exchange_rate();
			if ( 1.00 !== $rate ) {
				$unit_price = floatval( $unit_price * $rate );
			}
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
				// Loose comparison is intentional: `$unit_price` and `$quantity` arrive as float `0.0` for free items,
				// and `0 === 0.0` is false. We want to keep numeric zero across int/float/string forms.
				// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
				return ! empty( $value ) || ( is_numeric( $value ) && 0 == $value );
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
	 * Get returns info for the order.
	 *
	 * A refund that cannot be read (corrupted row, SHIPSTN-164) degrades this
	 * order to empty returns data instead of fataling the whole export batch.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array
	 */
	public function get_returns( $order ) {
		$returns = array();

		try {
			$refunds = $order->get_refunds();
		} catch ( \Throwable $e ) {
			self::log_export_degradation(
				sprintf(
					'Refunds for order %d could not be read and were left out of the export: %s: %s',
					$order->get_id(),
					get_class( $e ),
					Order_Util::sanitize_for_log( $e->getMessage() )
				)
			);

			return $returns;
		}

		// Per-refund containment: the refunds are hydrated by now, but reading
		// their line items or meta, and the two filters below, can still throw
		// on corrupt data or a broken callback. One bad refund then costs its
		// own return entry, not the order's returns array (SHIPSTN-164).
		foreach ( $refunds as $refund ) {
			try {
				$qty = 0;
				foreach ( $refund->get_items() as $refunded_item ) {
					$qty += $refunded_item->get_quantity();
				}

				/**
				 * Filters the return status for a refund.
				 *
				 * @since 5.0.0
				 *
				 * @param string          $status The return status.
				 * @param WC_Order_Refund $refund The refund object.
				 * @param WC_Order        $order  The order object.
				 */
				$status      = apply_filters( 'woocommerce_shipstation_return_status', ucwords( $refund->get_status() ), $refund, $order );
				$refund_args = $refund->get_meta( '_wc_shipstation_refund_args', true );
				$return_data = array(
					'status'             => $status,
					'created_date_time'  => $this->get_shipstation_date_format( $refund->get_date_created() ),
					'modified_date_time' => $this->get_shipstation_date_format( $refund->get_date_modified() ),
					'total_quantity'     => abs( $qty ),
					'currency'           => $this->get_currency_code(),
					'authorization'      => array(
						/**
						 * Filters whether the return is approved.
						 *
						 * @since 5.0.0
						 *
						 * @param bool            $is_approved Whether the return is approved.
						 * @param WC_Order_Refund $refund      The refund object.
						 * @param WC_Order        $order       The order object.
						 */
						'is_approved' => apply_filters( 'woocommerce_shipstation_return_is_approved', true, $refund, $order ),
					),
					'refunds'            => $this->get_refund_data( $refund ),
				);

				if ( ! empty( $refund_args['restock_items'] ) ) {
					$return_data['return_requested_fulfillments'] = $this->get_return_requested_fulfillments( $refund );
				}
				$returns[] = $return_data;
			} catch ( \Throwable $e ) {
				self::log_export_degradation(
					sprintf(
						'Refund #%d of order #%d could not be serialized and was left out of the export: %s: %s',
						$refund->get_id(),
						$order->get_id(),
						get_class( $e ),
						Order_Util::sanitize_for_log( $e->getMessage() )
					)
				);
				continue;
			}
		}

		return $returns;
	}

	/**
	 * Get return requested fulfillments for the order refund.
	 *
	 * @param WC_Order_Refund $order_refund Order refund object.
	 *
	 * @return array
	 */
	public function get_return_requested_fulfillments( $order_refund ) {
		$return_items  = array();
		$refund_reason = $order_refund->get_reason();
		$rate          = $this->get_exchange_rate();

		foreach ( $order_refund->get_items() as $refund_item ) {
			/**
			 * Handle refunded item data.
			 *
			 * @var WC_Order_Item_Product $refund_item
			 */
			$original_item_id = $refund_item->get_meta( '_refunded_item_id', true );
			$quantity         = absint( $refund_item->get_quantity() );

			$unit_price = abs( $refund_item->get_total() ) / max( 1, $quantity );
			// Maybe convert item total using per-order exchange rate.
			if ( 1.00 !== $rate ) {
				$unit_price = $unit_price * $rate;
			}

			$return_item = array(
				'line_item_id'  => $original_item_id,
				'description'   => $refund_item->get_name(),
				'product'       => array(
					'product_id' => $refund_item->get_product_id(),
					'name'       => $refund_item->get_name(),
				),
				'return_reason' => $refund_reason,
				'quantity'      => $quantity,
				'unit_price'    => $unit_price,
				'currency'      => $this->get_currency_code(),
				'is_active'     => true, // Hardcoded to true assuming all return requested fulfillments are active since WooCommerce does not have a built-in concept of fulfillment activity status.
			);

			$return_items[] = $return_item;
		}

		if ( ! empty( $return_items ) ) {
			return array(
				array(
					'return_items' => $return_items,
				),
			);
		}

		return array();
	}

	/**
	 * Get refunds info for the order refund.
	 *
	 * @param WC_Order_Refund $order_refund Order refund object.
	 *
	 * @return array
	 */
	public function get_refund_data( $order_refund ) {
		$rate           = $this->get_exchange_rate();
		$total_refunded = abs( $order_refund->get_amount() );

		// Maybe convert unit price using per-order exchange rate.
		if ( 1.00 !== $rate ) {
			$total_refunded = $total_refunded * $rate;
		}

		return array(
			array(
				'order_id'            => $order_refund->get_parent_id(),
				'created_date_time'   => $this->get_shipstation_date_format( $order_refund->get_date_created() ),
				'modified_date_time'  => $this->get_shipstation_date_format( $order_refund->get_date_modified() ),
				'total_refunded'      => $total_refunded,
				'currency'            => $this->get_currency_code(),
				'return_item_refunds' => $this->get_return_item_refunds( $order_refund ),
			),
		);
	}

	/**
	 * Get return item refunds for the order refund.
	 *
	 * @param WC_Order_Refund $order_refund Order refund object.
	 *
	 * @return array
	 */
	public function get_return_item_refunds( $order_refund ) {
		$return_item_refunds = array();
		$refunded_shipping   = array();
		$rate                = $this->get_exchange_rate();

		foreach ( $order_refund->get_items() as $refund_item ) {
			/**
			 * Handle refunded item data.
			 *
			 * @var WC_Order_Item_Product $refund_item
			 */
			$quantity          = absint( $refund_item->get_quantity() );
			$unit_price_refund = abs( $refund_item->get_total() ) / max( 1, $quantity );

			// Maybe convert unit price using per-order exchange rate.
			if ( 1.00 !== $rate ) {
				$unit_price_refund = floatval( $unit_price_refund * $rate );
			}

			$return_item_refunds[] = array(
				'refund_quantity'   => $quantity,
				'unit_price_refund' => $unit_price_refund,
				'taxes_refunded'    => $this->get_item_taxes( $refund_item->get_taxes(), true ),
			);
		}

		foreach ( $order_refund->get_items( 'shipping' ) as $refund_shipping ) {
			/**
			 * Handle refunded shipping data.
			 *
			 * @var WC_Order_Item_Shipping $refund_shipping
			 */
			$total = abs( $refund_shipping->get_total() ) + abs( $refund_shipping->get_total_tax() );

			// Maybe convert shipping amount using per-order exchange rate.
			if ( 1.00 !== $rate ) {
				$total = floatval( $total * $rate );
			}

			$refunded_shipping[] = array(
				'amount'      => $total,
				'description' => $refund_shipping->get_name(),
			);
		}

		if ( ! empty( $refunded_shipping ) ) {
			$return_item_refunds[] = array(
				'shipping_charges_refunded' => $refunded_shipping,
			);
		}

		return $return_item_refunds;
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
	 * @param bool  $is_refund  Whether the taxes are for a refund or not, which can be used to adjust the tax data accordingly if needed in the future.
	 *
	 * @return array
	 */
	public function get_item_taxes( $item_taxes, $is_refund = false ) {
		$taxes = array();

		if ( ! is_array( $item_taxes ) || empty( $item_taxes['total'] ) ) {
			return $taxes;
		}

		$rate = $this->get_exchange_rate();

		foreach ( $item_taxes['total'] as $rate_id => $rate_value ) {
			$tax_label  = WC_Tax::get_rate_label( $rate_id );
			$tax_amount = ( ! $is_refund ) ? floatval( $rate_value ) : abs( floatval( $rate_value ) );

			// Maybe convert tax amount using per-order exchange rate.
			if ( 1.00 !== $rate ) {
				$tax_amount = floatval( $tax_amount * $rate );
			}

			$taxes[] = array(
				'amount'      => $tax_amount,
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
				$gift['gift_message'] = html_entity_decode( $gift_message, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
				'text' => html_entity_decode( $order->get_customer_note(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			);
		}

		$gift = $this->get_gift( $order );

		if ( ! empty( $gift['gift_message'] ) ) {
			$notes[] = array(
				'type' => 'GiftMessage',
				'text' => $gift['gift_message'],
			);
		}

		$order_notes = Order_Util::get_order_notes( $order );

		if ( ! empty( $order_notes['private'] ) ) {
			$notes[] = array(
				'type' => 'InternalNotes',
				'text' => implode( ' | ', $order_notes['private'] ),
			);
		}

		if ( ! empty( $order_notes['customer'] ) ) {
			$notes[] = array(
				'type' => 'NotesToBuyer',
				'text' => implode( ' | ', $order_notes['customer'] ),
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
		// Ensure third-party shipnotify filters (e.g. Composite Products) are loaded.
		// Mirrors the same call in get_orders() — see fire_legacy_api_action() for details.
		$this->fire_legacy_api_action();

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

			if ( empty( $notification['order_id'] ) ) {
				$response[] = array(
					'notification_id' => $notification['notification_id'],
					'status'          => 'failure',
					'failure_reason'  => __( 'Empty order ID', 'woocommerce-shipstation-integration' ),
				);

				// translators: %1$s is the notification id.
				$this->log( sprintf( __( 'Notification ID: %1$s doesnt have order ID.', 'woocommerce-shipstation-integration' ), $notification['notification_id'] ) );

				continue; // Skip notification without order ID.
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

			// Normalize items: ShipStation may omit this field or send an empty list
			// when items were replaced before shipping. Fall back to an empty array
			// so process_items() writes the generic tracking note — matching the XML
			// shipnotify handler's behavior for an empty <Items> element, which also
			// writes the note without transitioning the order (the XML handler's
			// "ship entire order" branch only fires on transport-level failures:
			// empty POST body or missing SimpleXML extension, neither of which has
			// a JSON analog since malformed REST bodies are rejected upstream).
			$items_payload = isset( $notification['items'] ) && is_array( $notification['items'] )
				? $notification['items']
				: array();

			foreach ( $items_payload as $item ) {
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
					$saved_item['description'] = $order_item->get_name();
					$saved_item['product_id']  = $order_item->get_product_id();
					$order_item_product        = $order_item->get_product();
					$saved_item['sku']         = $order_item_product instanceof WC_Product
						? $order_item_product->get_sku()
						: '';
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

			$saved_notification['items'] = $saved_items;
			$this->process_items( $saved_items, $order, $saved_notification );

			$response[] = array(
				'notification_id' => $notification['notification_id'],
				'status'          => 'success',
				'order_id'        => $order->get_id(),
			);
		}

		// The item counts above can memoise refund read failures. Flush the
		// memo like the export loops do, so a later notification for the same
		// order in a long-lived process still logs the recurring failure.
		Order_Util::flush_qty_refund_failure_log();
		Order_Util::flush_degraded_refund_primes();

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

		// Idempotency key so ShipStation's hourly retries (SHIPSTN-53) do not
		// re-add the note or re-increment the shipped counter. notification_id is
		// unique per shipment and is expected to be present (update_orders_shipments
		// skips notifications with an empty notification_id); the guard keeps dedup
		// a no-op if it ever is not.
		//
		// The read here and the mark after the note are not atomic, so two
		// concurrent retries can both pass. Accepted: ShipStation retries hourly,
		// and closing the window needs row locking. Do not move the mark ahead of
		// add_order_note() -- a failure between the two must re-add the note
		// rather than silently drop a legitimate one.
		$shipment_key          = ! empty( $notification['notification_id'] ) ? (string) wc_clean( wp_unslash( (string) $notification['notification_id'] ) ) : '';
		$shipment_is_duplicate = Order_Util::shipment_already_processed( $order, $shipment_key );

		if ( $shipment_is_duplicate ) {
			$this->log(
				sprintf(
					/* translators: 1) notification ID 2) order ID */
					__( 'Notification ID: %1$s was already recorded on order %2$s. Skipping the tracking note, the Shipment Tracking write and the shipped-item count.', 'woocommerce-shipstation-integration' ),
					$shipment_key,
					$order->get_id()
				)
			);
		}

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

		// A corrupted refund otherwise costs this order every refund's quantity,
		// not just the unreadable one (SHIPSTN-164).
		Order_Util::prime_refunds_for_batch( array( $order->get_id() ) );

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

			if ( ! $shipment_is_duplicate ) {
				$order->update_meta_data( '_shipstation_shipped_item_count', $current_shipped_items + $shipped_item_count );

				// save_meta_data() fires added_order_meta / updated_order_meta
				// and woocommerce_update_order, so the counter write is a
				// handoff to foreign code like the marker write (SHIPSTN-165).
				$counted = Order_Util::dispatch_safely(
					sprintf( 'the shipped-item counter write for order %d', $order->get_id() ),
					static function () use ( $order ) {
						$order->save_meta_data();
					}
				);

				if ( ! $counted ) {
					Order_Util::discard_pending_meta_safely( $order );
				}
			}
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
		// A tracking note is customer-facing only when the Shipment Tracking
		// extension is not active (it renders tracking itself) and the order is
		// entering the shipped status.
		$has_shipment_tracking = class_exists( 'WC_Shipment_Tracking' );
		$is_customer_note      = $has_shipment_tracking ? false : WC_ShipStation_Integration::$shipped_status !== $current_status;

		// Record the tracking number with the Shipment Tracking extension. Skipped
		// on a duplicate ShipStation retry so the same shipment is not stored twice.
		if ( $has_shipment_tracking && ! $shipment_is_duplicate ) {
			if ( ! function_exists( 'wc_st_add_tracking_number' ) ) {
				$this->log( __( 'You\'re using Shipment Tracking < 1.4.0. Please update!', 'woocommerce-shipstation-integration' ) );
			}

			Order_Util::store_tracking_number_safely( $order, $tracking_number, $carrier, (int) $timestamp );
		}

		$tracking_data = array(
			'tracking_number' => $tracking_number,
			'carrier'         => $carrier,
			'ship_date'       => $timestamp,
			'data'            => $notification,
			'xml'             => '',
		);

		// A filter callback is foreign code, so each shipnotify filter runs
		// contained; a failure keeps the unfiltered value. An uncontained throw
		// here would abort the request between the shipped-item counter above
		// and the processed marker below, and the retry would re-apply the
		// counter (SHIPSTN-165).
		Order_Util::dispatch_safely(
			sprintf( 'the woocommerce_shipstation_shipnotify_tracking_note filter for order %d', $order->get_id() ),
			static function () use ( &$order_note, $order, $tracking_data ) {
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
			}
		);

		if ( ! $shipment_is_duplicate ) {
			$send_to_customer = $is_customer_note;

			Order_Util::dispatch_safely(
				sprintf( 'the woocommerce_shipstation_shipnotify_send_tracking_note filter for order %d', $order->get_id() ),
				static function () use ( &$send_to_customer, $is_customer_note, $order_note, $order, $tracking_data ) {
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
					$send_to_customer = apply_filters(
						'woocommerce_shipstation_shipnotify_send_tracking_note',
						$is_customer_note,
						$order_note,
						$order,
						$tracking_data
					);
				}
			);

			// The note write is contained: everything WooCommerce runs after the
			// comment row is committed -- the customer-note email and every
			// third-party callback on it -- is code this plugin does not control,
			// and a fatal there used to lose the shipment (SHIPSTN-165).
			Order_Util::add_order_note_safely( $order, $order_note, (bool) $send_to_customer );

			// Marked unconditionally, whatever became of the note. The shipped-item
			// counter and the Shipment Tracking write above are not idempotent and
			// have already landed, so a resend that is not recognised as a duplicate
			// would count the same items twice and could complete a partially
			// shipped order. Containment is what makes this safe: there is no
			// longer an uncontained failure between those writes and this marker.
			Order_Util::mark_shipment_processed( $order, $shipment_key );
		}

		Order_Util::dispatch_safely(
			sprintf( 'the woocommerce_shipstation_shipnotify action for order %d', $order->get_id() ),
			static function () use ( $order, $tracking_data ) {
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
			}
		);

		// Update order status. The shipped flag runs contained like the other
		// filters: an uncontained throw here would 500 on this and every retry,
		// since the shipment is already marked processed (SHIPSTN-165).
		Order_Util::dispatch_safely(
			sprintf( 'the woocommerce_shipstation_shipnotify_order_shipped filter for order %d', $order->get_id() ),
			static function () use ( &$order_shipped, $order, $tracking_data ) {
				/**
				 * Allow to override is order shipped flag.
				 *
				 * @param bool     $order_shipped
				 * @param WC_Order $order
				 * @param array    $tracking_data
				 *
				 * @since 4.5.0
				 */
				$order_shipped = apply_filters(
					'woocommerce_shipstation_shipnotify_order_shipped',
					$order_shipped,
					$order,
					$tracking_data
				);
			}
		);

		if ( $order_shipped && WC_ShipStation_Integration::$shipped_status !== $current_status ) {
			// The transition fires the completed-order email, which is the same
			// unbounded tail as the customer-note email (SHIPSTN-165).
			$transitioned = Order_Util::transition_order_status_safely( $order, WC_ShipStation_Integration::$shipped_status );

			if ( $transitioned ) {
				/* translators: 1) order ID 2) shipment status */
				$this->log( sprintf( __( 'Updated order %1$s to status %2$s', 'woocommerce-shipstation-integration' ), $order->get_id(), WC_ShipStation_Integration::$shipped_status ) );

				Order_Util::dispatch_safely(
					sprintf( 'the woocommerce_shipstation_shipnotify_status_updated action for order %d', $order->get_id() ),
					static function () use ( $order, $tracking_data ) {
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
				);
			}
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
	 * woocommerce_order_item_product that reshape order items.
	 *
	 * In the XML API path the action fires naturally via WooCommerce's
	 * legacy API mechanism, but the REST API path never triggers it.
	 * Calling this method at the top of the REST endpoint ensures those
	 * third-party filters are in place before order items are read.
	 *
	 * @return void
	 */
	protected function fire_legacy_api_action(): void {
		if ( did_action( 'woocommerce_api_wc_shipstation' ) ) {
			return;
		}

		$main_instance = Main::instance();
		$removed       = remove_action( 'woocommerce_api_wc_shipstation', array( $main_instance, 'load_api' ) );

		/**
		 * Fires the legacy WooCommerce ShipStation API action.
		 *
		 * Ensures third-party hooks registered on the XML API path are
		 * also available when the REST API is used.
		 *
		 * @since 5.0.0
		 */
		do_action( 'woocommerce_api_wc_shipstation' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Legacy WC API hook; part of the /?wc-api=wc_shipstation routing contract. Renaming breaks Product Bundles and third-party compatibility (commit 3da4326).

		if ( $removed ) {
			add_action( 'woocommerce_api_wc_shipstation', array( $main_instance, 'load_api' ) );
		}
	}
}
