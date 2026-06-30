<?php
/**
 * Unit tests for the GH-9 shipment queue internals: enqueue dedupe, claim/attempt
 * accounting, the M1 poison-row quarantine, and the M2 failed-row count.
 *
 * These exercise the queue's own logic and do not require WooCommerce orders; the
 * end-to-end parity test lives in test-shipment-notification-parity.php.
 *
 * @package WC_ShipStation
 */

use WooCommerce\Shipping\ShipStation\Shipment_Queue;

/**
 * @group shipment-queue
 */
class Test_Shipment_Queue extends WP_UnitTestCase {

	/**
	 * Create the custom table once, before the per-test transaction starts (a
	 * CREATE TABLE inside the transaction would force an implicit commit).
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		Shipment_Queue::install();
	}

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Shipment_Queue::table_name() ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Insert a raw queue row, returning its id.
	 *
	 * @param array $overrides Column overrides.
	 * @return int
	 */
	private function insert_row( array $overrides = array() ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$row = array_merge(
			array(
				'notification_id' => 'n-' . wp_generate_uuid4(),
				'order_ref'       => '1',
				'payload'         => '{}',
				'status'          => 'pending',
				'attempts'        => 0,
				'locked_by'       => '',
				'locked_at'       => null,
				'available_at'    => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			$overrides
		);
		$wpdb->insert( Shipment_Queue::table_name(), $row ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->insert_id;
	}

	private function status_of( int $id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Shipment_Queue::table_name() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
	}

	private function attempts_of( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attempts FROM ' . Shipment_Queue::table_name() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
	}

	/** enqueue() collapses a repeated notification_id to a single row (idempotent retry). */
	public function test_enqueue_deduplicates_on_notification_id(): void {
		Shipment_Queue::enqueue( 'dup-1', '123', wp_json_encode( array( 'notification_id' => 'dup-1' ) ) );
		Shipment_Queue::enqueue( 'dup-1', '123', wp_json_encode( array( 'notification_id' => 'dup-1' ) ) );

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Shipment_Queue::table_name() . ' WHERE notification_id = %s', 'dup-1' ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 1, $count );
	}

	/** claim_batch() claims a due row without pre-charging its attempt (M1: charge is per-row). */
	public function test_claim_batch_claims_without_incrementing_attempts(): void {
		$id   = $this->insert_row( array( 'attempts' => 0 ) );
		$rows = Shipment_Queue::claim_batch( 10, 'tok-1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'processing', $this->status_of( $id ) );
		$this->assertSame( 0, $this->attempts_of( $id ), 'claim_batch() must not increment attempts.' );
	}

	/** begin_attempt() charges only the target row, not its batch-mates (the M1 premise). */
	public function test_begin_attempt_increments_only_the_target_row(): void {
		$a = $this->insert_row( array( 'status' => 'processing' ) );
		$b = $this->insert_row( array( 'status' => 'processing' ) );

		Shipment_Queue::begin_attempt( $a );

		$this->assertSame( 1, $this->attempts_of( $a ) );
		$this->assertSame( 0, $this->attempts_of( $b ), 'A batch-mate that was not begun must keep its count.' );
	}

	/** M1: a stale claim that has reached the attempt cap is quarantined as failed, not retried. */
	public function test_reclaim_stale_quarantines_rows_at_the_attempt_cap(): void {
		$stale  = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$poison = $this->insert_row( array( 'status' => 'processing', 'attempts' => 5, 'locked_by' => 'dead', 'locked_at' => $stale ) );
		$ok     = $this->insert_row( array( 'status' => 'processing', 'attempts' => 1, 'locked_by' => 'dead', 'locked_at' => $stale ) );

		Shipment_Queue::reclaim_stale( 60, 5 );

		$this->assertSame( 'failed', $this->status_of( $poison ), 'A stale claim at the cap should be quarantined.' );
		$this->assertSame( 'pending', $this->status_of( $ok ), 'A stale claim under the cap should be retried.' );
	}

	/** Without a cap argument, reclaim_stale() only returns rows to pending (no quarantine). */
	public function test_reclaim_stale_without_cap_only_requeues(): void {
		$stale = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$id    = $this->insert_row( array( 'status' => 'processing', 'attempts' => 99, 'locked_by' => 'dead', 'locked_at' => $stale ) );

		Shipment_Queue::reclaim_stale( 60 );

		$this->assertSame( 'pending', $this->status_of( $id ) );
	}

	/** A fresh (non-stale) claim is left alone by reclaim_stale(). */
	public function test_reclaim_stale_ignores_fresh_claims(): void {
		$fresh = current_time( 'mysql', true );
		$id    = $this->insert_row( array( 'status' => 'processing', 'attempts' => 9, 'locked_by' => 'live', 'locked_at' => $fresh ) );

		Shipment_Queue::reclaim_stale( 300, 5 );

		$this->assertSame( 'processing', $this->status_of( $id ), 'A fresh claim must not be reclaimed or quarantined.' );
	}

	/** M2: failed_depth() counts only failed rows; depth() counts only pending. */
	public function test_depth_counts(): void {
		$this->insert_row( array( 'status' => 'failed' ) );
		$this->insert_row( array( 'status' => 'failed' ) );
		$this->insert_row( array( 'status' => 'pending' ) );
		$this->insert_row( array( 'status' => 'done' ) );

		$this->assertSame( 2, Shipment_Queue::failed_depth() );
		$this->assertSame( 1, Shipment_Queue::depth() );
	}
}
