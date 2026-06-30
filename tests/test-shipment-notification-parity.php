<?php
/**
 * Parity test (GH-9 L5): the background queue worker and the synchronous REST path
 * must produce identical order state, because both call process_single_notification().
 *
 * The refactor that extracted process_single_notification() runs on the synchronous
 * path too, so this guards against a regression there even while the queue flag is
 * off in production.
 *
 * @package WC_ShipStation
 */

use WooCommerce\Shipping\ShipStation\Shipment_Queue;
use WooCommerce\Shipping\ShipStation\API\REST\Orders_Controller;

/**
 * @group shipment-queue
 * @group parity
 */
class Test_Shipment_Notification_Parity extends WP_UnitTestCase {

	public static function set_up_before_class() {
		parent::set_up_before_class();
		Shipment_Queue::install();
	}

	public function set_up() {
		parent::set_up();
		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce is required for the parity test.' );
		}
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Shipment_Queue::table_name() ); // phpcs:ignore WordPress.DB
	}

	private function make_product() {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Parity Test Product' );
		$product->set_sku( 'PARITY-SKU-' . wp_generate_password( 6, false ) );
		$product->set_regular_price( '10' );
		$product->save();
		return $product;
	}

	private function make_order( $product, int $qty ) {
		$order = wc_create_order();
		$order->add_product( $product, $qty );
		$order->set_status( 'processing' );
		$order->save();
		return $order;
	}

	private function notification_for( $order, $product ): array {
		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$items[] = array(
				'line_item_id' => $item_id,
				'quantity'     => $item->get_quantity(),
				'description'  => $item->get_name(),
				'sku'          => $product->get_sku(),
				'product_id'   => $product->get_id(),
			);
		}

		return array(
			'notification_id' => 'parity-' . $order->get_id(),
			'order_id'        => $order->get_id(),
			'tracking_number' => 'TRACK123',
			'carrier_code'    => 'usps',
			'ship_date'       => '2026-06-29 10:00:00',
			'items'           => $items,
		);
	}

	private function note_count( $order ): int {
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		return is_array( $notes ) ? count( $notes ) : 0;
	}

	private function queued_row_status( string $notification_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Shipment_Queue::table_name() . ' WHERE notification_id = %s', $notification_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Two identical orders, the same notification: one processed synchronously, one
	 * drained through the queue worker. The resulting order state must be identical.
	 */
	public function test_queued_processing_matches_synchronous(): void {
		$product = $this->make_product();
		$sync    = $this->make_order( $product, 2 );
		$queued  = $this->make_order( $product, 2 );

		// Synchronous path.
		$controller = new Orders_Controller();
		$controller->process_single_notification( $this->notification_for( $sync, $product ) );

		// Queued path: enqueue and drain through the worker.
		add_filter( 'wc_shipstation_shipment_queue_enabled', '__return_true' );
		$notification = $this->notification_for( $queued, $product );
		Shipment_Queue::enqueue( (string) $notification['notification_id'], (string) $notification['order_id'], wp_json_encode( $notification ) );
		Shipment_Queue::run_batch();
		remove_filter( 'wc_shipstation_shipment_queue_enabled', '__return_true' );

		$a = wc_get_order( $sync->get_id() );
		$b = wc_get_order( $queued->get_id() );

		$this->assertSame(
			(int) $a->get_meta( '_shipstation_shipped_item_count', true ),
			(int) $b->get_meta( '_shipstation_shipped_item_count', true ),
			'Shipped-item-count meta should be identical between the synchronous and queued paths.'
		);
		$this->assertSame( $a->get_status(), $b->get_status(), 'Resulting order status should be identical.' );
		$this->assertSame(
			$this->note_count( $a ),
			$this->note_count( $b ),
			'The number of order notes should be identical.'
		);
		$this->assertSame(
			'done',
			$this->queued_row_status( (string) $notification['notification_id'] ),
			'The queued row should be marked done after a successful drain.'
		);
	}
}
