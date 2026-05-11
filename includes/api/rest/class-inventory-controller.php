<?php
/**
 * ShipStation REST API Inventory Controller file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation\API\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WC_Product;
use WP_REST_Request;
use WP_REST_Response;
use Automattic\WooCommerce\Enums\ProductType;
use WP_REST_Server;

/**
 * Inventory_Controller class.
 */
class Inventory_Controller extends API_Controller {

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
	protected string $rest_base = 'inventory';

	/**
	 * Register the routes for the controller.
	 */
	public function register_routes(): void {

		// Register the endpoint for retrieving stock data.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_inventory' ),
				'permission_callback' => array( $this, 'check_get_permission' ),
				'args'                => array(
					'page'     => array(
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
					'per_page' => array(
						'description'       => __( 'Maximum number of items to return per page (1–500).', 'woocommerce-shipstation-integration' ),
						'type'              => 'integer',
						'default'           => 100,
						'sanitize_callback' => function ( $value ) {
							return min( max( - 1, absint( $value ) ), 500 ); // Limit between 1 and 500.
						},
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);

		// Register the endpoint for retrieving stock data by product ID.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<product_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_inventory_by_id' ),
				'permission_callback' => array( $this, 'check_get_permission' ),
				'args'                => array(
					'product_id' => array(
						'description'       => __( 'ID of the product to retrieve stock data for.', 'woocommerce-shipstation-integration' ),
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);

		// Register the endpoint for updating stock data by SKU.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sku/(?P<sku>[\w-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_inventory_by_sku' ),
				'permission_callback' => array( $this, 'check_get_permission' ),
				'args'                => array(
					'sku' => array(
						'description'       => __( 'SKU of the product to retrieve stock data for.', 'woocommerce-shipstation-integration' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							return is_string( $param );
						},
					),
				),
			)
		);

		// Register the endpoint for updating stock data.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_inventory' ),
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
	 * Get product data for API response.
	 *
	 * @param WC_Product $product Product object.
	 *
	 * @return array Product data.
	 */
	private function get_product_data( WC_Product $product ): array {
		$product_data = array(
			'product_id'     => $product->get_id(),
			'sku'            => $product->get_sku(),
			'name'           => $product->get_name(),
			'stock_quantity' => $product->get_stock_quantity(),
			'stock_status'   => $product->get_stock_status(),
			'manage_stock'   => $product->get_manage_stock(),
			'backorders'     => $product->get_backorders(),
		);

		// Add the parent_id when relevant.
		$parent_id = $product->get_parent_id();
		if ( $parent_id ) {
			$product_data['parent_id'] = $parent_id;
		}

		return $product_data;
	}

	/**
	 * Get product data by product ID.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return array
	 */
	public function get_product_data_by_id( int $product_id ): array {
		if ( $product_id <= 0 ) {
			return array();
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return array();
		}

		return $this->get_product_data( $product );
	}

	/**
	 * Get a REST response with the provided product data.
	 *
	 * @param array $product_data Product data array.
	 *
	 * @return WP_REST_Response
	 */
	private function get_product_response( array $product_data ): WP_REST_Response {
		if ( empty( $product_data ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Product not found.', 'woocommerce-shipstation-integration' ) ), 404 );
		}

		return new WP_REST_Response( $product_data, 200 );
	}

	/**
	 * Retrieve the inventory stock data for a specific product by ID.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_inventory_by_id( WP_REST_Request $request ): WP_REST_Response {
		// Get the product ID from the request.
		$product_id   = (int) $request->get_param( 'product_id' );
		$product_data = $this->get_product_data_by_id( $product_id );

		return $this->get_product_response( $product_data );
	}

	/**
	 * Retrieve the inventory stock data for a specific product by SKU.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_inventory_by_sku( WP_REST_Request $request ): WP_REST_Response {
		// Get the SKU from the request.
		$sku          = (string) $request->get_param( 'sku' );
		$product_id   = wc_get_product_id_by_sku( wc_clean( wp_unslash( $sku ) ) );
		$product_data = $this->get_product_data_by_id( $product_id );

		return $this->get_product_response( $product_data );
	}

	/**
	 * Retrieve the inventory stock data for all products and variations.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_inventory( WP_REST_Request $request ): WP_REST_Response {
		$request_params = $request->get_params();

		// Get pagination parameters.
		$page     = absint( $request_params['page'] ); // Default to page 1.
		$per_page = intval( $request_params['per_page'] ); // Default to 100 items per page.

		$args = array(
			'type'     => array( ProductType::SIMPLE, ProductType::VARIABLE, ProductType::GROUPED, ProductType::EXTERNAL, ProductType::VARIATION ),
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
		);

		$results = wc_get_products( $args );

		if ( is_wp_error( $results ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Error retrieving products.', 'woocommerce-shipstation-integration' ) ), 500 );
		}

		$total_products = $results->total;

		// Calculate pagination information.
		$total_pages = $results->max_num_pages;
		$has_more    = $page < $total_pages;

		// Prepare the response data.
		$inventory_data = array(
			'products'   => array(),
			'pagination' => array(
				'page'           => $page,
				'per_page'       => $per_page,
				'total_products' => $total_products,
				'total_pages'    => $total_pages,
				'has_more'       => $has_more,
			),
		);

		if ( empty( $results->products ) || empty( $results->total ) ) {
			// No products found, return an empty response.
			return new WP_REST_Response( $inventory_data, 200 );
		}

		foreach ( $results->products as $product ) {
			$inventory_data['products'][] = $this->get_product_data( $product );
		}

		return new WP_REST_Response( $inventory_data, 200 );
	}

	/**
	 * Update inventory stock for specified SKUs (both products and variations).
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function update_inventory( WP_REST_Request $request ): WP_REST_Response {
		$items = $request->get_json_params();

		if ( empty( $items ) || ! is_array( $items ) ) {
			return new WP_REST_Response( 'Invalid request format.', 400 );
		}

		$updated = array();
		$errors  = array();

		foreach ( $items as $item ) {
			if ( ( empty( $item['product_id'] ) && empty( $item['sku'] ) ) || ! isset( $item['stock_quantity'] ) ) {
				$errors[] = array(
					'item'    => $item,
					'message' => __( 'Invalid item', 'woocommerce-shipstation-integration' ),
				);
				continue; // Skip invalid items.
			}

			if ( ! empty( $item['product_id'] ) && ! is_numeric( $item['product_id'] ) ) {
				$errors[] = array(
					'item'    => $item,
					'message' => __( 'Product ID is not numeric', 'woocommerce-shipstation-integration' ),
				);
				continue; // Skip if product_id is not numeric.
			}

			if ( ! isset( $item['stock_quantity'] ) || ! is_numeric( $item['stock_quantity'] ) ) {
				$errors[] = array(
					'item'    => $item,
					'message' => __( 'Stock quantity is not set or not numeric', 'woocommerce-shipstation-integration' ),
				);
				continue; // Skip if stock_quantity is not set or not numeric.
			}

			if ( ! empty( $item['product_id'] ) ) {
				$product = wc_get_product( absint( $item['product_id'] ) );
			} else {
				$product = wc_get_product( wc_get_product_id_by_sku( wc_clean( wp_unslash( $item['sku'] ) ) ) );
			}

			if ( ! $product ) {
				$errors[] = array(
					'item'    => $item,
					'message' => __( 'Product not found', 'woocommerce-shipstation-integration' ),
				);
				continue; // Skip if product does not exist.
			}

			$stock_qty = (int) $item['stock_quantity'];

			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock_qty );
			$product->set_stock_status( $stock_qty > 0 ? 'instock' : 'outofstock' );
			$product->save();

			$updated[] = array(
				'sku'        => $product->get_sku(),
				'product_id' => $product->get_id(),
				'stock'      => $stock_qty,
			);
		}

		$message = __( 'Inventory updated successfully.', 'woocommerce-shipstation-integration' );

		if ( count( $errors ) > 0 && count( $updated ) === 0 ) {
			// If there are errors and no successful updates, return the errors.
			$message = __( 'No inventory updated due to errors.', 'woocommerce-shipstation-integration' );
		}

		if ( count( $errors ) > 0 && count( $updated ) > 0 ) {
			// If there are errors but some updates were successful, return both.
			$message = __( 'Inventory updated with some errors.', 'woocommerce-shipstation-integration' );
		}

		if ( count( $errors ) === 0 && count( $updated ) === 0 ) {
			// If there are no errors and no updates, return a message indicating no changes.
			$message = __( 'No inventory changes made.', 'woocommerce-shipstation-integration' );
		}

		return new WP_REST_Response(
			array(
				'message'       => $message,
				'updated'       => $updated,
				'updated_count' => count( $updated ),
				'errors'        => $errors,
				'error_count'   => count( $errors ),
			),
			200
		);
	}
}
