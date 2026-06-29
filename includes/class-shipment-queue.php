<?php
/**
 * ShipStation incoming-shipment webhook queue (GH-9).
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WooCommerce\Shipping\ShipStation\API\REST\Orders_Controller;

/**
 * Durable queue + Action Scheduler worker for incoming ShipStation shipment
 * notifications (POST /wc-shipstation/v1/orders/shipments).
 *
 * ShipStation dumps up to ~1,000 shipment-update POSTs per minute at 5–6 AM
 * Pacific. Processing each synchronously inside the request (order meta write +
 * order-note insert + status-change cascade) serializes DB write locks and
 * spikes MySQL queue depth for ~10 min. When enabled, the REST endpoint instead
 * persists the raw notification here and returns 200 immediately; this worker
 * drains the backlog in small, rate-controlled batches, spreading the same work
 * over ~15–30 min.
 *
 * Storage mirrors {@see Connection_Log} (version-gated dbDelta install); the
 * recurring worker mirrors {@see Auth_Controller}'s orphan-key prune (Action
 * Scheduler, which ships with WooCommerce). Disabled by default — see
 * {@see Features::is_shipment_queue_enabled()}.
 *
 * @since 5.2.0-forked
 */
class Shipment_Queue {

	/**
	 * Table name without the WordPress table prefix.
	 *
	 * @var string
	 */
	const TABLE = 'wc_shipstation_shipment_queue';

	/**
	 * Option storing the installed schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'woocommerce_shipstation_shipment_queue_db_version';

	/**
	 * Current schema version. Bump when the table definition changes.
	 *
	 * @var string
	 */
	const DB_VERSION = '1';

	/**
	 * Action Scheduler hook for the drain worker.
	 *
	 * @var string
	 */
	const WORKER_HOOK = 'woocommerce_shipstation_drain_shipment_queue';

	/**
	 * Action Scheduler group (shared with the rest of the plugin's jobs).
	 *
	 * @var string
	 */
	const WORKER_GROUP = 'woocommerce-shipstation';

	/**
	 * Transient that throttles enqueue-time async "kicks" so a burst of hundreds
	 * of POSTs schedules at most one immediate drain per window.
	 *
	 * @var string
	 */
	const KICK_THROTTLE_KEY = 'wcss_shipment_queue_kick';

	const STATUS_PENDING    = 'pending';
	const STATUS_PROCESSING = 'processing';
	const STATUS_DONE       = 'done';
	const STATUS_FAILED     = 'failed';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create/upgrade the table when the stored schema version is out of date.
	 *
	 * Cheap to call on every load — short-circuits on an option compare.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Create/upgrade the queue table via dbDelta and stamp the version.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// notification_id is ShipStation's idempotency key; UNIQUE makes a retried
		// notification a no-op (see enqueue()). payload holds the raw notification
		// JSON only — never parsed order data — so the table stays lightweight.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			notification_id varchar(191) NOT NULL DEFAULT '',
			order_ref varchar(64) NOT NULL DEFAULT '',
			payload longtext NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			locked_by varchar(40) NOT NULL DEFAULT '',
			locked_at datetime DEFAULT NULL,
			available_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			last_error text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY notification_id (notification_id),
			KEY status_available (status, available_at),
			KEY locked (status, locked_at)
		) {$charset_collate};";

		dbDelta( $sql );

		// Only stamp the version once the schema actually exists; otherwise let
		// maybe_install() retry next load rather than locking the feature into
		// querying a table that was never created (every query would error).
		$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $exists ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		} else {
			Logger::error( 'ShipStation shipment-queue install did not create the table; will retry next load. DB error: ' . (string) $wpdb->last_error );
		}
	}

	/**
	 * Persist one raw notification, deduplicated on notification_id.
	 *
	 * A repeat of an already-queued (or already-processed) notification_id is a
	 * no-op via ON DUPLICATE KEY, so a ShipStation retry never double-processes.
	 * An empty id is synthesized from the payload so the UNIQUE column is always
	 * populated and identical re-sends still collapse to one row.
	 *
	 * @param string $notification_id ShipStation notification id.
	 * @param string $order_ref       Order id/number, for logs and the ack body.
	 * @param string $payload_json    Raw notification encoded as JSON.
	 *
	 * @return bool True if the row was inserted or already present.
	 */
	public static function enqueue( string $notification_id, string $order_ref, string $payload_json ): bool {
		global $wpdb;

		if ( '' === $notification_id ) {
			$notification_id = 'syn_' . sha1( $order_ref . '|' . $payload_json );
		}

		$now   = current_time( 'mysql', true );
		$table = self::table_name();

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT INTO {$table} (notification_id, order_ref, payload, status, attempts, available_at, created_at, updated_at)
				VALUES (%s, %s, %s, %s, 0, %s, %s, %s)
				ON DUPLICATE KEY UPDATE id = id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$notification_id,
				$order_ref,
				$payload_json,
				self::STATUS_PENDING,
				$now,
				$now,
				$now
			)
		);

		return false !== $result;
	}

	/**
	 * Atomically claim up to $limit due pending rows for this worker.
	 *
	 * The conditional UPDATE is the single-flight primitive: it stamps the rows
	 * with this run's unique token under a row lock, so two overlapping workers
	 * can never claim the same row. attempts is incremented here so a row that
	 * repeatedly crashes a worker eventually exhausts its retries.
	 *
	 * @param int    $limit Max rows to claim.
	 * @param string $token Unique per-run claim token.
	 *
	 * @return array<int,array<string,mixed>> Claimed rows (associative).
	 */
	public static function claim_batch( int $limit, string $token ): array {
		global $wpdb;

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = %s, locked_by = %s, locked_at = %s, attempts = attempts + 1, updated_at = %s
				WHERE status = %s AND available_at <= %s
				ORDER BY id ASC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_PROCESSING,
				$token,
				$now,
				$now,
				self::STATUS_PENDING,
				$now,
				$limit
			)
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE locked_by = %s AND status = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token,
				self::STATUS_PROCESSING
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark a claimed row processed.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public static function mark_done( int $id ): void {
		global $wpdb;

		$table = self::table_name();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, locked_by = '', locked_at = NULL, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_DONE,
				current_time( 'mysql', true ),
				$id
			)
		);
	}

	/**
	 * Requeue a failed row with exponential backoff, or mark it failed once the
	 * attempt cap is reached.
	 *
	 * Because ShipStation already received its 200, retries are our responsibility
	 * — a permanently failed row is logged loudly rather than silently dropped.
	 *
	 * @param int    $id           Row id.
	 * @param int    $attempts     Attempts already recorded (post-increment from claim).
	 * @param string $error        Failure message.
	 * @param int    $max_attempts Attempt cap.
	 *
	 * @return void
	 */
	public static function mark_failed_or_retry( int $id, int $attempts, string $error, int $max_attempts ): void {
		global $wpdb;

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		if ( $attempts >= $max_attempts ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s, locked_by = '', locked_at = NULL, last_error = %s, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::STATUS_FAILED,
					$error,
					$now,
					$id
				)
			);

			return;
		}

		// Exponential backoff: 2^attempts minutes, capped at one hour.
		$delay        = min( (int) pow( 2, $attempts ) * MINUTE_IN_SECONDS, HOUR_IN_SECONDS );
		$available_at = gmdate( 'Y-m-d H:i:s', time() + $delay );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, locked_by = '', locked_at = NULL, available_at = %s, last_error = %s, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_PENDING,
				$available_at,
				$error,
				$now,
				$id
			)
		);
	}

	/**
	 * Return rows stuck in `processing` (a worker died mid-batch) to `pending`.
	 *
	 * @param int $older_than_seconds Reclaim claims older than this.
	 *
	 * @return int Rows reclaimed.
	 */
	public static function reclaim_stale( int $older_than_seconds ): int {
		global $wpdb;

		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $older_than_seconds );

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, locked_by = '', locked_at = NULL, updated_at = %s
				WHERE status = %s AND locked_at IS NOT NULL AND locked_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_PENDING,
				current_time( 'mysql', true ),
				self::STATUS_PROCESSING,
				$cutoff
			)
		);
	}

	/**
	 * Delete processed rows older than the retention window.
	 *
	 * @param int $older_than_days Retention window in days.
	 *
	 * @return int Rows pruned.
	 */
	public static function prune_done( int $older_than_days ): int {
		global $wpdb;

		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $older_than_days * DAY_IN_SECONDS );

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = %s AND updated_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_DONE,
				$cutoff
			)
		);
	}

	/**
	 * Count rows still waiting to be processed (for observability/monitoring).
	 *
	 * @return int
	 */
	public static function depth(): int {
		global $wpdb;

		$table = self::table_name();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_PENDING
			)
		);
	}

	/**
	 * Wire the drain worker: register the action handler and (re)evaluate the
	 * recurring schedule on init. Called once from {@see Main::init()}.
	 *
	 * @return void
	 */
	public static function register_worker(): void {
		add_action( self::WORKER_HOOK, array( __CLASS__, 'run_batch' ) );
		add_action( 'init', array( __CLASS__, 'schedule_worker' ) );
	}

	/**
	 * Keep the recurring heartbeat in sync with the feature flag: schedule it
	 * when enabled, cancel it when disabled. The heartbeat is the safety net that
	 * also picks up rows requeued for a future retry.
	 *
	 * @return void
	 */
	public static function schedule_worker(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$enabled   = Features::is_shipment_queue_enabled();
		$scheduled = as_has_scheduled_action( self::WORKER_HOOK, array(), self::WORKER_GROUP );

		if ( $enabled && ! $scheduled && function_exists( 'as_schedule_recurring_action' ) ) {
			$interval = max( 30, (int) apply_filters( 'wc_shipstation_shipment_queue_interval', MINUTE_IN_SECONDS ) );
			as_schedule_recurring_action( time() + $interval, $interval, self::WORKER_HOOK, array(), self::WORKER_GROUP );
		} elseif ( ! $enabled && $scheduled ) {
			self::unschedule_worker();
		}
	}

	/**
	 * Cancel the recurring worker. Called on plugin deactivation.
	 *
	 * @return void
	 */
	public static function unschedule_worker(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::WORKER_HOOK, array(), self::WORKER_GROUP );
		}
	}

	/**
	 * Start draining promptly after an enqueue without waiting for the next
	 * heartbeat — important because the 5–6 AM burst lands in a low-traffic
	 * window where traffic-driven WP-Cron may lag. Throttled so a burst of
	 * hundreds of POSTs enqueues at most one async drain per window.
	 *
	 * @return void
	 */
	public static function kick(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( false !== get_transient( self::KICK_THROTTLE_KEY ) ) {
			return;
		}

		set_transient( self::KICK_THROTTLE_KEY, 1, 15 );
		as_enqueue_async_action( self::WORKER_HOOK, array(), self::WORKER_GROUP );
	}

	/**
	 * Drain one batch: reclaim stale claims, claim up to the batch size, process
	 * each through the shared Orders_Controller logic, then chain another async
	 * run while a backlog remains (or prune processed rows once drained).
	 *
	 * Batch size and interval are filterable so an external load guard can shrink
	 * them under DB pressure (the issue's `query_guard` integration point).
	 *
	 * @return void
	 */
	public static function run_batch(): void {
		if ( ! Features::is_shipment_queue_enabled() ) {
			return;
		}

		self::reclaim_stale( max( 60, (int) apply_filters( 'wc_shipstation_shipment_queue_stale_seconds', 5 * MINUTE_IN_SECONDS ) ) );

		$batch_size   = max( 1, (int) apply_filters( 'wc_shipstation_shipment_queue_batch_size', 25 ) );
		$max_attempts = max( 1, (int) apply_filters( 'wc_shipstation_shipment_queue_max_attempts', 5 ) );

		$token = wp_generate_uuid4();
		$rows  = self::claim_batch( $batch_size, $token );

		if ( empty( $rows ) ) {
			return;
		}

		$controller = self::get_orders_controller();

		// Load third-party shipnotify filters (Product Bundles, Composite Products)
		// once for the batch — the synchronous REST path does this too, so queued
		// processing produces identical order state.
		if ( $controller ) {
			$controller->fire_legacy_api_action();
		}

		foreach ( $rows as $row ) {
			$id           = (int) $row['id'];
			$attempts     = (int) $row['attempts'];
			$notification = json_decode( (string) $row['payload'], true );

			if ( ! is_array( $notification ) ) {
				self::mark_failed_or_retry( $id, $attempts, 'Unreadable payload JSON', $max_attempts );
				continue;
			}

			try {
				if ( ! $controller ) {
					throw new \RuntimeException( 'Orders_Controller unavailable' );
				}

				$result = $controller->process_single_notification( $notification );

				// process_single_notification() reports business-level failures
				// (e.g. "order not found", which can be transient while an order is
				// still replicating across HPOS tables) by returning a 'failure' row
				// rather than throwing. Route those through retry/backoff instead of
				// silently marking them done — ShipStation has already been ACKed, so
				// dropping them here would lose the shipment.
				if ( is_array( $result ) && isset( $result['status'] ) && 'failure' === $result['status'] ) {
					$reason = isset( $result['failure_reason'] ) ? (string) $result['failure_reason'] : 'Processing failed';
					self::mark_failed_or_retry( $id, $attempts, $reason, $max_attempts );
				} else {
					self::mark_done( $id );
				}
			} catch ( \Throwable $e ) {
				Logger::error( 'ShipStation shipment-queue processing failed for notification ' . (string) $row['notification_id'] . ': ' . $e->getMessage() );
				self::mark_failed_or_retry( $id, $attempts, $e->getMessage(), $max_attempts );
			}
		}

		if ( self::depth() > 0 ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::WORKER_HOOK, array(), self::WORKER_GROUP );
			}
		} else {
			self::prune_done( max( 1, (int) apply_filters( 'wc_shipstation_shipment_queue_retention_days', 7 ) ) );
		}
	}

	/**
	 * Resolve an Orders_Controller instance for the worker (cron/async context),
	 * loading the class if the REST stack has not been required this request.
	 *
	 * @return Orders_Controller|null
	 */
	private static function get_orders_controller(): ?Orders_Controller {
		if ( ! class_exists( Orders_Controller::class ) ) {
			$file = WC_SHIPSTATION_ABSPATH . 'includes/api/rest/class-orders-controller.php';
			if ( is_readable( $file ) ) {
				require_once WC_SHIPSTATION_ABSPATH . 'includes/api/rest/class-api-controller.php';
				require_once $file;
			}
		}

		return class_exists( Orders_Controller::class ) ? new Orders_Controller() : null;
	}
}
