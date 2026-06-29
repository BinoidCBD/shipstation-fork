<?php
/**
 * ShipStation incoming-connection log (SHIPSTN-142).
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records and reads incoming ShipStation connections in a dedicated table.
 *
 * Every time ShipStation authenticates against the REST API the permission gate
 * records the route it came in on — which key, which transport (WordPress.com
 * proxy vs direct), and the source URL — with first/last-seen timestamps and a
 * hit counter. One row per (key, URL) pair.
 *
 * Stored in its own table rather than per-key metadata so the record survives
 * the key being deleted (recovery / audit: "this URL was used by a key you've
 * since removed") and so the hot auth path never rewrites a growing option.
 *
 * @since 5.2.0
 */
class Connection_Log {

	/**
	 * Table name without the WordPress table prefix.
	 *
	 * @var string
	 */
	const TABLE = 'wc_shipstation_connections';

	/**
	 * Option storing the installed schema version, so the table is (re)built
	 * exactly once per schema change rather than on every load.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'woocommerce_shipstation_connlog_db_version';

	/**
	 * Current schema version. Bump when the table definition changes.
	 *
	 * @var string
	 */
	const DB_VERSION = '2';

	/**
	 * Minimum seconds between on-disk `last_seen` refreshes for one connection.
	 *
	 * ShipStation polls on an interval; without throttling every poll would
	 * write the row. 60s keeps "last seen" accurate to the minute while
	 * collapsing bursts/retries.
	 *
	 * @var int
	 */
	const THROTTLE_SECONDS = 60;

	/**
	 * Cap on the connection rows surfaced to the settings UI by {@see all()}.
	 *
	 * Comfortably above any realistic install's lifetime route set (a few keys
	 * each over direct/wpcom) while bounding the query and the unpaginated list.
	 * Rows are ordered by `last_seen DESC`, so only the oldest, least-relevant
	 * entries are ever dropped — never an active route.
	 *
	 * @var int
	 */
	const MAX_DISPLAYED = 25;

	/**
	 * Site option holding the UTC epoch of the most recent successful plugin-key
	 * auth observed since this feature shipped (the global banner's "was working"
	 * latch). Written by the REST auth gate ({@see \WooCommerce\Shipping\ShipStation\API\REST\API_Controller})
	 * at its success record() sites, and read by the global-banner renderer.
	 *
	 * Lives on this class — not the admin-only renderer — because the writer is
	 * REST-path code where Connection_Log is already loaded; the renderer must
	 * never make the REST gate depend on an admin-only class's load order.
	 * Deliberately distinct from `last_access` on woocommerce_api_keys, which
	 * carries pre-feature history and would fire on an already-broken connection
	 * at upgrade. Absent on upgrade so a connection already broken at upgrade
	 * stays quiet until it pings successfully once and then stops.
	 *
	 * @var string
	 */
	const LAST_SUCCESS_OPTION = 'woocommerce_shipstation_last_success_ts';

	/**
	 * Fully-qualified table name.
	 *
	 * @since 5.2.0
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or update the table when the stored schema version is out of date.
	 *
	 * Cheap to call on every load — it short-circuits on an option compare and
	 * only runs dbDelta after an install or a schema bump.
	 *
	 * @since 5.2.0
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
	 * Create/upgrade the connection table via dbDelta and stamp the version.
	 *
	 * @since 5.2.0
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// url capped at 191 chars so it stays within utf8mb4 index limits if an
		// index is ever added; proxy and site URLs are well under that.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			key_id bigint(20) unsigned NOT NULL DEFAULT 0,
			truncated_key varchar(7) NOT NULL DEFAULT '',
			transport varchar(16) NOT NULL DEFAULT '',
			url varchar(191) NOT NULL DEFAULT '',
			target_url varchar(191) NOT NULL DEFAULT '',
			first_seen datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			last_seen datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY key_id (key_id),
			KEY last_seen (last_seen)
		) {$charset_collate};";

		dbDelta( $sql );

		// Only stamp the version once the schema actually matches. dbDelta can
		// occasionally no-op an ALTER while still "succeeding"; stamping anyway
		// would lock the feature into querying a column that does not exist
		// (every SELECT errors → an empty list). Re-check and let maybe_install()
		// retry on the next load if the column is missing.
		$has_target = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'target_url' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $has_target ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		} else {
			// The version stays unstamped so maybe_install() retries next load, but
			// without a breadcrumb the connection UI and disconnect-safety guard go
			// dark silently while every SELECT errors on the missing column.
			Logger::error( 'ShipStation connection-log install did not produce the expected target_url column; will retry next load. DB error: ' . (string) $wpdb->last_error );
		}
	}

	/**
	 * Record (or refresh) an incoming ShipStation connection.
	 *
	 * One row per (key, source URL). A repeat hit refreshes `last_seen` and bumps
	 * `hits`, throttled to {@see THROTTLE_SECONDS} so a polling store does not
	 * write on every request. Telemetry only — never affects authentication.
	 *
	 * @since 5.2.0
	 *
	 * @param int    $key_id        Plugin key row id.
	 * @param string $truncated_key Last-7 of the consumer key (legible after deletion).
	 * @param string $transport     'wpcom' or 'direct'.
	 * @param string $url           Store URL ShipStation connects to (the WordPress.com proxy URL for wpcom, the site URL for direct); '' falls back to the transport.
	 * @param string $target_url    The site URL the request actually resolved to (home_url() at request time). For wpcom this is the final hop after the proxy; equals $url for direct.
	 *
	 * @return void
	 */
	public static function record( int $key_id, string $truncated_key, string $transport, string $url = '', string $target_url = '' ): void {
		if ( $key_id <= 0 || ! in_array( $transport, array( 'wpcom', 'direct' ), true ) ) {
			return;
		}

		global $wpdb;

		$table    = self::table_name();
		$conn_url = '' !== $url ? $url : $transport;
		$now_gmt  = current_time( 'mysql', true );
		$now_ts   = time();

		// Key on (key, Store URL). The Store URL determines the route: for wpcom
		// it encodes the blog_id, which Jetpack binds 1:1 to a site URL, so a
		// changed resolution comes with a new proxy URL (a new row) anyway.
		// target_url is therefore an attribute of the row, refreshed to the
		// latest resolution rather than part of the identity.
		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, last_seen FROM {$table} WHERE key_id = %d AND url = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key_id,
				$conn_url
			)
		);

		if ( $existing ) {
			$last_ts = (int) strtotime( (string) $existing->last_seen . ' UTC' );
			// Treat an unparseable or zero last_seen (e.g. the 1970 schema default)
			// as stale so it can never accidentally satisfy the throttle window.
			if ( $last_ts > 0 && ( $now_ts - $last_ts ) < self::THROTTLE_SECONDS ) {
				return;
			}

			$refreshed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$table} SET last_seen = %s, transport = %s, truncated_key = %s, target_url = %s, hits = hits + 1 WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$now_gmt,
					$transport,
					$truncated_key,
					$target_url,
					(int) $existing->id
				)
			);

			if ( false === $refreshed ) {
				Logger::error( 'Failed to refresh ShipStation connection row. DB error: ' . (string) $wpdb->last_error );
			}

			return;
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'key_id'        => $key_id,
				'truncated_key' => $truncated_key,
				'transport'     => $transport,
				'url'           => $conn_url,
				'target_url'    => $target_url,
				'first_seen'    => $now_gmt,
				'last_seen'     => $now_gmt,
				'hits'          => 1,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			// Telemetry only, so a failure must not break the request — but a
			// persistently failing insert leaves the connection list mysteriously
			// empty, so record why in debug.log.
			Logger::error( 'Failed to record ShipStation connection. DB error: ' . (string) $wpdb->last_error );
		}
	}

	/**
	 * The most-recent connection rows (capped at {@see MAX_DISPLAYED}), newest
	 * first, as raw table rows — WITHOUT the `key_exists` annotation.
	 *
	 * The health/banner path ({@see health_from_rows()}) reads only last_seen, url,
	 * target_url and transport, so it consumes these rows directly and skips the
	 * key-row lookup {@see all()} performs purely to stamp `key_exists`. Keeping the
	 * shared SELECT here lets that path run one query instead of three on every
	 * non-ShipStation WC settings screen. {@see all()} layers the annotation on top.
	 *
	 * @since 5.2.0
	 *
	 * @return array[] Each: ['id','key_id','truncated_key','transport','url','target_url','first_seen','last_seen','hits'].
	 */
	public static function recent_rows(): array {
		global $wpdb;

		$table = self::table_name();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, key_id, truncated_key, transport, url, target_url, first_seen, last_seen, hits FROM {$table} ORDER BY last_seen DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::MAX_DISPLAYED
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The most-recent connections (capped at {@see MAX_DISPLAYED}), newest first,
	 * mapped onto the live key rows.
	 *
	 * Each record is annotated with `key_exists` — false when the key it was
	 * recorded against has since been deleted, so the merchant-facing list can
	 * still surface "this URL was used by a key you've removed" (recovery). The
	 * health/banner path does not need this and reads {@see recent_rows()} directly.
	 *
	 * @since 5.2.0
	 *
	 * @return array[] Each: ['id','key_id','truncated_key','transport','url','target_url','first_seen','last_seen','hits','key_exists'].
	 */
	public static function all(): array {
		$rows = self::recent_rows();

		if ( empty( $rows ) ) {
			return array();
		}

		$live_key_ids = array();
		foreach ( Auth_Controller::get_plugin_key_rows() as $key_row ) {
			$live_key_ids[ (int) $key_row['key_id'] ] = true;
		}

		$result = array();
		foreach ( $rows as $row ) {
			$key_id   = (int) $row['key_id'];
			$result[] = array(
				'id'            => (int) $row['id'],
				'key_id'        => $key_id,
				'truncated_key' => (string) $row['truncated_key'],
				'transport'     => (string) $row['transport'],
				'url'           => (string) $row['url'],
				'target_url'    => (string) $row['target_url'],
				'first_seen'    => (string) $row['first_seen'],
				'last_seen'     => (string) $row['last_seen'],
				'hits'          => (int) $row['hits'],
				'key_exists'    => isset( $live_key_ids[ $key_id ] ),
			);
		}

		return $result;
	}

	/**
	 * Classify one connection row's route status.
	 *
	 * The single source of truth for both the per-row pill in the connections
	 * table and the aggregate {@see health()}. Pure: no I/O.
	 *
	 * @since 5.2.0
	 *
	 * @param array  $conn               One row from {@see all()} (keys: last_seen, url, target_url, transport, key_exists).
	 * @param int    $window             Seconds within which a ping counts as recent.
	 * @param bool   $transport_enabled  Whether the WordPress.com transport is on.
	 * @param string $current_host       Host of the current home_url().
	 * @param bool   $is_wpcom_connected Whether the site currently has a live Jetpack/WordPress.com connection. Defaults to true so existing callers keep working; the live value is supplied by the settings render. Only consulted for wpcom rows.
	 *
	 * @return string One of 'deleted', 'mismatch', 'disconnected', 'inactive', 'rejected', 'active'.
	 */
	public static function connection_status( array $conn, int $window, bool $transport_enabled, string $current_host, bool $is_wpcom_connected = true ): string {
		// A deleted key trumps every other state: with no key row left in
		// woocommerce_api_keys, the credential is gone and the route can never
		// authenticate again, however recently it last synced. Only rows from
		// {@see all()} carry key_exists; the health/banner path reads recent_rows()
		// directly (no key_exists), so the isset() guard scopes this to the
		// merchant-facing connections table and leaves the banner unchanged.
		if ( isset( $conn['key_exists'] ) && ! $conn['key_exists'] ) {
			return 'deleted';
		}

		$last_ts   = strtotime( (string) $conn['last_seen'] . ' UTC' );
		$age       = $last_ts ? ( time() - $last_ts ) : PHP_INT_MAX;
		$is_recent = $last_ts && $age <= $window;

		$target_url  = '' !== $conn['target_url'] ? $conn['target_url'] : $conn['url'];
		$target_host = strtolower( (string) wp_parse_url( $target_url, PHP_URL_HOST ) );
		$mismatch    = '' !== $target_host && '' !== $current_host && $target_host !== $current_host;
		$is_wpcom    = 'wpcom' === $conn['transport'];

		// Priority: deleted > mismatch > disconnected > inactive > rejected > active.
		// A domain mismatch wins over everything below — the route points at an
		// address that no longer reaches this store, so its transport health is moot.
		if ( $mismatch ) {
			return 'mismatch';
		}
		// A wpcom (proxy) route while Jetpack is disconnected is dead now, before
		// the recency window even lapses: the proxy hop has no live link to relay
		// over, so the route cannot work regardless of how recently it last synced.
		// Direct routes are unaffected by the Jetpack link.
		if ( $is_wpcom && ! $is_wpcom_connected ) {
			return 'disconnected';
		}
		if ( ! $is_recent ) {
			return 'inactive';
		}
		if ( $is_wpcom && ! $transport_enabled ) {
			return 'rejected';
		}
		return 'active';
	}

	/**
	 * Roll a key's connection rows up into a single status for the API Keys list,
	 * so the Keys pill and the Connections pills can never disagree about the same
	 * key (SHIPSTN-142).
	 *
	 * A key can authenticate over several connections at once, so the pill answers
	 * "is this credential working, and if not, what's its latest state": if ANY of
	 * the key's connections is {@see connection_status() active}, the key is
	 * active; otherwise it reports the status of the most-recently-seen connection.
	 * Equivalent to sorting the rows by (active-first, then last_seen desc) and
	 * taking the top one — so the returned status always equals a real row in the
	 * Connections table, which the merchant can scroll to for the detail.
	 *
	 * Pure: no I/O. Returns '' when the key has no connection rows, leaving the
	 * caller to fall back to its bare last_access recency (the New/Unused states).
	 *
	 * @since 5.2.0
	 *
	 * @param array  $conn_rows          The key's rows from {@see all()} (already filtered to one key_id).
	 * @param int    $window             Seconds within which a ping counts as recent.
	 * @param bool   $transport_enabled  Whether the WordPress.com transport is on.
	 * @param string $current_host       Host of the current home_url().
	 * @param bool   $is_wpcom_connected Whether the site currently has a live Jetpack/WordPress.com connection.
	 *
	 * @return string A {@see connection_status()} result, or '' when there are no rows.
	 */
	public static function key_rollup_status( array $conn_rows, int $window, bool $transport_enabled, string $current_host, bool $is_wpcom_connected = true ): string {
		$best_status = '';
		$best_active = false;
		$best_ts     = -1;

		foreach ( $conn_rows as $conn ) {
			$status    = self::connection_status( $conn, $window, $transport_enabled, $current_host, $is_wpcom_connected );
			$is_active = ( 'active' === $status );
			$last_ts   = (int) strtotime( (string) $conn['last_seen'] . ' UTC' );

			// Prefer an active row over any non-active one; among rows of the same
			// active-ness, prefer the most-recently-seen.
			$wins = '' === $best_status
				|| ( $is_active && ! $best_active )
				|| ( $is_active === $best_active && $last_ts > $best_ts );

			if ( $wins ) {
				$best_status = $status;
				$best_active = $is_active;
				$best_ts     = $last_ts;
			}
		}

		return $best_status;
	}

	/**
	 * Whether any connection of a given transport currently resolves to a given
	 * {@see connection_status()} (SHIPSTN-142).
	 *
	 * Drives both directions of the pre-save transport warning, which it suppresses
	 * when toggling needs no ShipStation reconfiguration:
	 *  - OFF: a direct connection is already `active`, so the proxy can go away with
	 *    no change — ShipStation already reaches the store directly.
	 *  - ON: a wpcom connection is `rejected` (recent, WordPress.com-connected,
	 *    matching host — turned away only because the transport is off), so flipping
	 *    the toggle on makes it `active` on ShipStation's next pull, no change.
	 *
	 * Pure: no I/O.
	 *
	 * @since 5.2.0
	 *
	 * @param array  $rows               Rows from {@see all()}.
	 * @param string $transport          Transport to match: 'direct' or 'wpcom'.
	 * @param string $status             Target {@see connection_status()} result.
	 * @param int    $window             Seconds within which a ping counts as recent.
	 * @param bool   $transport_enabled  Whether the WordPress.com transport is on.
	 * @param string $current_host       Host of the current home_url().
	 * @param bool   $is_wpcom_connected Whether the site currently has a live Jetpack/WordPress.com connection.
	 *
	 * @return bool True when at least one matching connection resolves to $status.
	 */
	public static function has_connection_with_status( array $rows, string $transport, string $status, int $window, bool $transport_enabled, string $current_host, bool $is_wpcom_connected = true ): bool {
		foreach ( $rows as $conn ) {
			if ( $transport !== $conn['transport'] ) {
				continue;
			}
			if ( self::connection_status( $conn, $window, $transport_enabled, $current_host, $is_wpcom_connected ) === $status ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Per-hop glyph states for a connection's route cell, derived from its
	 * resolved {@see connection_status()} (D3, SHIPSTN-142).
	 *
	 * A route renders as up to two hops: g1 is the first hop (ShipStation ->
	 * next), g2 the second (proxy -> store, proxied rows only). Each hop is one
	 * of four states the template maps to a coloured glyph: 'arrow' (working,
	 * green ->), 'uncertain' (idle/unknown, gray ?), 'down' (broken, red x) and
	 * 'reject' (broken because the transport is off, amber x).
	 *
	 * The break always lands on the store-edge hop (proxied g2 / direct g1); a
	 * proxied first hop stays an arrow unless the whole route is idle. A direct
	 * row has no second hop, so g2's store-edge state is folded onto g1 (the
	 * single rendered glyph). Any unrecognised status renders healthy, matching
	 * the prior inline fall-through.
	 *
	 * @since 5.2.0
	 *
	 * @param string $status     A {@see connection_status()} result ('deleted'|'mismatch'|'disconnected'|'inactive'|'rejected'|'active').
	 * @param bool   $is_proxied Whether the row routes through the WordPress.com proxy (a second hop exists).
	 *
	 * @return array Keyed 'g1' (first hop) and 'g2' (store-edge hop); each one of 'arrow'|'uncertain'|'down'|'reject'.
	 */
	public static function route_glyph_states( string $status, bool $is_proxied ): array {
		switch ( $status ) {
			case 'inactive':
				$g1 = 'uncertain';
				$g2 = 'uncertain';
				break;
			case 'deleted':
				// The key is gone, so no hop can authenticate — the whole route is
				// dead, not just the store edge. Break both glyphs.
				$g1 = 'down';
				$g2 = 'down';
				break;
			case 'mismatch':
				$g1 = $is_proxied ? 'arrow' : 'down';
				$g2 = 'down';
				break;
			case 'disconnected':
				$g1 = 'arrow';
				$g2 = 'down';
				break;
			case 'rejected':
				$g1 = 'arrow';
				$g2 = 'reject';
				break;
			default: // active (and any unrecognised status renders healthy).
				$g1 = 'arrow';
				$g2 = 'arrow';
				break;
		}

		// On a direct row there is no second hop; fold g2's store-edge state
		// onto the single rendered glyph so it carries the break.
		if ( ! $is_proxied ) {
			$g1 = $g2;
		}

		return array(
			'g1' => $g1,
			'g2' => $g2,
		);
	}

	/**
	 * Whether the global, WC-Settings-wide broken-connection banner should fire
	 * (SHIPSTN-142). Pure and static, mirroring {@see section_verdict()} /
	 * {@see route_glyph_states()} — no I/O — so the firing rule is unit-pinned in
	 * isolation before any renderer is wired to it.
	 *
	 * The banner exists to surface a break the merchant does NOT already know
	 * about, so it fires only for `inactive` — ShipStation stopped successfully
	 * pinging us while nothing on our side changed (an external WordPress.com-side
	 * revocation or a ShipStation-side stop both land here). Every merchant-caused
	 * break is excluded for free by the {@see health_from_rows()} reason priority
	 * (`disconnected`/`rejected` outrank or replace `inactive`), and a local
	 * disconnect mid-flow is excluded explicitly via $disconnect_intent.
	 *
	 * All four conditions must hold to fire:
	 *  1. no local disconnect in progress ($disconnect_intent false);
	 *  2. the health reason is exactly 'inactive';
	 *  3. the latch is set ($last_success_ts non-null) — we observed a real
	 *     successful sync since this feature shipped, so the connection was
	 *     genuinely working and is now broken (not merely never set up);
	 *  4. not dismissed for this episode — either never dismissed, or a fresh
	 *     success has landed since the dismiss ($last_success_ts > $dismissed_at),
	 *     which re-arms after a genuine recover-then-stop.
	 *
	 * The render-scope guard — show on WC settings screens but NOT the ShipStation
	 * tab, where the inline section banner already speaks — lives in the renderer,
	 * NOT here. This helper is concerned only with the firing rule.
	 *
	 * @since 5.2.0
	 *
	 * @param string   $health_reason     Reason from {@see health_from_rows()}: ''|'none'|'inactive'|'mismatch'|'disconnected'|'rejected'.
	 * @param int|null $last_success_ts   Epoch of the most recent post-feature successful plugin-key auth; null = no real success observed (latch off).
	 * @param int|null $dismissed_at      Epoch the merchant last dismissed the banner site-wide; null = never dismissed.
	 * @param bool     $disconnect_intent Whether a local WordPress.com disconnect is mid-flow.
	 *
	 * @return bool True only when a previously-working connection has stopped and the merchant should be pulled to the ShipStation tab.
	 */
	public static function should_show_global_banner(
		string $health_reason,
		?int $last_success_ts,
		?int $dismissed_at,
		bool $disconnect_intent
	): bool {
		if ( $disconnect_intent ) {
			return false;
		}
		if ( 'inactive' !== $health_reason ) {
			return false;
		}
		if ( null === $last_success_ts ) {
			return false;
		}
		if ( null !== $dismissed_at && $last_success_ts <= $dismissed_at ) {
			return false;
		}
		return true;
	}

	/**
	 * Derive the unified ShipStation Connection section verdict from the two
	 * independent state axes the settings render already computes.
	 *
	 * Merging the WordPress.com transport row and the credentials row into one
	 * section (SHIPSTN-142 reqs 6 & 7) needs a single source of truth for: the
	 * section status pill (D10), whether to show the inline top banner and which
	 * copy (D11/D13), which folds auto-open on load (D12), and what the banner's
	 * recoverable-action button does. This pure map produces all four as symbolic
	 * tokens; the renderer maps them to translated copy and markup, exactly as the
	 * template maps {@see route_glyph_states()} to coloured glyphs.
	 *
	 * Credential state dominates (D11): with no usable key pair the only honest
	 * message is "finish setup", regardless of any stale telemetry row -- so when
	 * $cred_state is 'none' or 'missing' the $health_reason is ignored and the
	 * auto-opened Credentials fold's own setup copy speaks (no banner). Only when
	 * a key pair exists ('reference') does the banner defer to health.
	 *
	 * @since 5.2.0
	 *
	 * @param string $cred_state    Credential state from {@see Auth_Controller::get_connection_section_data()}: 'none'|'missing'|'reference'.
	 * @param string $health_reason Health reason from {@see health_from_rows()}: ''|'none'|'inactive'|'mismatch'|'disconnected'|'rejected'.
	 *
	 * @return array Keyed: 'pill' ('connected'|'action'|'pending'), 'banner' ('' = none, else 'never_synced'|'inactive'|'mismatch'|'disconnected'|'rejected'), 'open_credentials' (bool), 'action' ('' = no button, else 'check_credentials'|'show_store_url'|'reconnect_wpcom'|'review_transport'), 'action_target' ('' | 'credentials'|'wpcom_connect'|'transport_toggle').
	 */
	public static function section_verdict( string $cred_state, string $health_reason ): array {
		// D11: no usable credentials dominates. The auto-opened Credentials fold
		// carries the setup copy itself, so no banner is shown here.
		if ( 'reference' !== $cred_state ) {
			return array(
				'pill'             => 'pending',
				'banner'           => '',
				'open_credentials' => true,
				'action'           => '',
				'action_target'    => '',
			);
		}

		// $cred_state is 'reference': a key pair exists; defer to health (D12).
		switch ( $health_reason ) {
			case 'none': // Keys exist but ShipStation has never synced.
				return array(
					'pill'             => 'pending',
					'banner'           => 'never_synced',
					'open_credentials' => true,
					'action'           => '',
					'action_target'    => '',
				);
			case 'inactive': // No sync within the recency window (24h).
				return array(
					'pill'             => 'action',
					'banner'           => 'inactive',
					'open_credentials' => true,
					'action'           => 'check_credentials',
					'action_target'    => 'credentials',
				);
			case 'mismatch': // Site address changed; the saved route no longer reaches the store.
				return array(
					'pill'             => 'action',
					'banner'           => 'mismatch',
					'open_credentials' => true,
					'action'           => 'show_store_url',
					'action_target'    => 'credentials',
				);
			case 'disconnected': // WordPress.com link is down; the fix lives in the always-visible strip.
				return array(
					'pill'             => 'action',
					'banner'           => 'disconnected',
					'open_credentials' => false,
					'action'           => 'reconnect_wpcom',
					'action_target'    => 'wpcom_connect',
				);
			case 'rejected': // Transport off, but a proxied row is still being turned away.
				return array(
					'pill'             => 'action',
					'banner'           => 'rejected',
					'open_credentials' => false,
					'action'           => 'review_transport',
					'action_target'    => 'transport_toggle',
				);
			default: // '' -- at least one active route; syncing normally.
				return array(
					'pill'             => 'connected',
					'banner'           => '',
					'open_credentials' => false,
					'action'           => '',
					'action_target'    => '',
				);
		}
	}

	/**
	 * Aggregate connection health for the section-level warning banner.
	 *
	 * 'ok' when at least one route is active within $stale_after. Otherwise
	 * 'error': no route has reached this store within the window (or a domain
	 * mismatch / rejected proxy means none can, or ShipStation has never
	 * connected), so the merchant must take action. `reason` carries the
	 * dominant cause so the banner copy can be specific.
	 *
	 * @since 5.2.0
	 *
	 * @param int    $stale_after        Seconds; a route with no sync newer than this is no longer active.
	 * @param bool   $transport_enabled  Whether the WordPress.com transport is on.
	 * @param string $current_host       Host of the current home_url().
	 * @param bool   $is_wpcom_connected Whether the site currently has a live Jetpack/WordPress.com connection. Defaults to true; the live value is supplied by the settings render.
	 *
	 * @return array Keyed: 'level' ('ok'|'error'), 'reason' ('' when level is 'ok'; otherwise 'mismatch'|'disconnected'|'inactive'|'rejected'|'none'), 'last_sync_ts' (int|null).
	 */
	public static function health( int $stale_after, bool $transport_enabled, string $current_host, bool $is_wpcom_connected = true ): array {
		// Feed recent_rows() (not all()): the banner deliberately does NOT
		// special-case deleted-key rows (reverted in a992bd1), so it must classify
		// them by recency/transport like any other. recent_rows() omits key_exists,
		// which keeps connection_status()'s 'deleted' branch — a table-only concern —
		// out of the aggregate. Same row set as all() (both capped at MAX_DISPLAYED,
		// newest first), just without the merchant-facing key_exists annotation.
		return self::health_from_rows( self::recent_rows(), $stale_after, $transport_enabled, $current_host, $is_wpcom_connected );
	}

	/**
	 * {@see health()} for already-fetched rows, so a caller that has loaded the
	 * connection list (e.g. the settings tab) need not query {@see all()} twice.
	 *
	 * @since 5.2.0
	 *
	 * @param array[] $rows               Rows as returned by {@see all()}.
	 * @param int     $stale_after        Seconds; a route with no sync newer than this is no longer active.
	 * @param bool    $transport_enabled  Whether the WordPress.com transport is on.
	 * @param string  $current_host       Host of the current home_url().
	 * @param bool    $is_wpcom_connected Whether the site currently has a live Jetpack/WordPress.com connection. Defaults to true; the live value is supplied by the settings render.
	 *
	 * @return array Keyed: 'level' ('ok'|'error'), 'reason' ('' when level is 'ok'; otherwise 'mismatch'|'disconnected'|'rejected'|'inactive'|'none'), 'last_sync_ts' (int|null).
	 */
	public static function health_from_rows( array $rows, int $stale_after, bool $transport_enabled, string $current_host, bool $is_wpcom_connected = true ): array {
		$has_active       = false;
		$has_rejected     = false;
		$has_disconnected = false;
		$last_sync_ts     = null;

		foreach ( $rows as $conn ) {
			$status = self::connection_status( $conn, $stale_after, $transport_enabled, $current_host, $is_wpcom_connected );

			if ( 'active' === $status ) {
				$has_active = true;
			}
			if ( 'rejected' === $status ) {
				$has_rejected = true;
			}
			if ( 'disconnected' === $status ) {
				$has_disconnected = true;
			}
			// Track the most recent sync that actually reached this store. A
			// mismatch row never reached *this* store (wrong domain); a
			// disconnected row did sync successfully in the past — the Jetpack
			// link only dropped afterwards — so it still counts toward "last sync".
			if ( 'mismatch' !== $status ) {
				$ts = strtotime( (string) $conn['last_seen'] . ' UTC' );
				if ( $ts && ( null === $last_sync_ts || $ts > $last_sync_ts ) ) {
					$last_sync_ts = $ts;
				}
			}
		}

		if ( $has_active ) {
			return array(
				'level'        => 'ok',
				'reason'       => '',
				'last_sync_ts' => $last_sync_ts,
			);
		}

		if ( empty( $rows ) ) {
			$reason = 'none';
		} elseif ( $has_disconnected ) {
			// WordPress.com is disconnected, so every proxy route is dead now. This
			// is the dominant, directly-fixable cause (reconnect WordPress.com) and
			// outranks rejected/inactive. It does NOT outrank an all-mismatch state:
			// when every row is a mismatch no row is 'disconnected' (mismatch wins
			// per row), so $has_disconnected is false and the mismatch fallback
			// below still fires — keeping the same precedence vs mismatch as the
			// per-row status.
			$reason = 'disconnected';
		} elseif ( $has_rejected ) {
			$reason = 'rejected';
		} elseif ( null !== $last_sync_ts ) {
			$reason = 'inactive';
		} else {
			// Rows exist but every one is a domain mismatch.
			$reason = 'mismatch';
		}

		return array(
			'level'        => 'error',
			'reason'       => $reason,
			'last_sync_ts' => $last_sync_ts,
		);
	}

	/**
	 * Whether it is safe to disconnect WordPress.com — a DIRECT route is live AND
	 * keeping pace with the WordPress.com proxy (SHIPSTN-142). Two conditions,
	 * both required:
	 *
	 *  1. A direct hit within $window_seconds — the route has been seen recently
	 *     at all.
	 *  2. The latest direct hit is no more than $max_lag_seconds behind the latest
	 *     proxy hit. ShipStation polls every route on essentially the same
	 *     cadence, so once the direct hit falls well behind the proxy that route
	 *     has stopped while the proxy keeps going — ShipStation is now reaching
	 *     the store only over WordPress.com and disconnecting would cut it off.
	 *     (A direct hit that is level with or ahead of the proxy is fine.)
	 *
	 * When no proxy hit has ever been recorded there is nothing to fall behind, so
	 * only condition 1 applies. A missing/erroring table returns false — the safe
	 * direction (warn rather than risk locking ShipStation out).
	 *
	 * @since 5.2.0
	 *
	 * @param int $window_seconds  Recency window for the direct hit.
	 * @param int $max_lag_seconds Allowed lag of the latest direct hit behind the latest proxy hit.
	 *
	 * @return bool
	 */
	public static function is_direct_connection_safe( int $window_seconds, int $max_lag_seconds ): bool {
		global $wpdb;

		$table = self::table_name();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX( CASE WHEN transport = %s THEN last_seen END ) AS direct_last, MAX( CASE WHEN transport = %s THEN last_seen END ) AS wpcom_last FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'direct',
				'wpcom'
			)
		);

		if ( ! is_object( $row ) || null === $row->direct_last ) {
			return false;
		}

		$direct_ts = (int) strtotime( (string) $row->direct_last . ' UTC' );

		// Condition 1: the direct route must have been seen within the window.
		if ( $direct_ts <= 0 || ( time() - $direct_ts ) > max( 0, $window_seconds ) ) {
			return false;
		}

		// Condition 2: when the proxy is also in play, the direct hit must not lag
		// it by more than the tolerance. Level-or-ahead (negative lag) is fine.
		if ( null !== $row->wpcom_last ) {
			$wpcom_ts = (int) strtotime( (string) $row->wpcom_last . ' UTC' );
			if ( $wpcom_ts > 0 && ( $wpcom_ts - $direct_ts ) > max( 0, $max_lag_seconds ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Distinct transports a key has authenticated over, in first-seen order.
	 * Powers the key list's "Type" column.
	 *
	 * @since 5.2.0
	 *
	 * @param int $key_id Row id.
	 *
	 * @return string[] Subset of ['wpcom', 'direct']; empty when never observed.
	 */
	public static function transports_for_key( int $key_id ): array {
		if ( $key_id <= 0 ) {
			return array();
		}

		global $wpdb;

		$table = self::table_name();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// Tie-break on the auto-increment id so rows sharing a first_seen
				// second (datetime granularity) keep a stable insertion order.
				"SELECT transport FROM {$table} WHERE key_id = %d ORDER BY first_seen ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$transports = array();
		foreach ( $rows as $row ) {
			$transport = (string) $row['transport'];
			if ( '' !== $transport && ! in_array( $transport, $transports, true ) ) {
				$transports[] = $transport;
			}
		}

		return $transports;
	}

	/**
	 * Most-recent recorded source URL for a key over a given transport.
	 *
	 * Lets a caller refresh the row ShipStation already used over a transport when
	 * the live URL can't be resolved — e.g. recording a rejected proxy attempt
	 * while the WordPress.com transport is off (the connection facade, and thus the
	 * proxy URL, is gated by the toggle), so the existing wpcom row is reused
	 * rather than spawning a urless duplicate.
	 *
	 * @since 5.2.0
	 *
	 * @param int    $key_id    Row id.
	 * @param string $transport 'wpcom' or 'direct'.
	 *
	 * @return string The stored url, or '' when none recorded.
	 */
	public static function latest_url_for_transport( int $key_id, string $transport ): string {
		if ( $key_id <= 0 || ! in_array( $transport, array( 'wpcom', 'direct' ), true ) ) {
			return '';
		}

		global $wpdb;

		$table = self::table_name();

		$url = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT url FROM {$table} WHERE key_id = %d AND transport = %s ORDER BY last_seen DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key_id,
				$transport
			)
		);

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Batched transport summary for many keys at once: the distinct transports
	 * each key has authenticated over (first-seen order) and the latest `last_seen`
	 * per transport, gathered in a single grouped query so the settings key list
	 * does not fan out to a per-row query for each key.
	 *
	 * @since 5.2.0
	 *
	 * @param int[] $key_ids Plugin key row ids.
	 *
	 * @return array<int,array<string,mixed>> Keyed by key_id; each value has
	 *               'transports' (string[]) and 'transport_last_seen' (array<string,string>).
	 *               Only ids with recorded connections are present.
	 */
	public static function transports_summary_for_keys( array $key_ids ): array {
		$key_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $key_ids ),
					function ( $id ) {
						return $id > 0;
					}
				)
			)
		);

		if ( empty( $key_ids ) ) {
			return array();
		}

		global $wpdb;

		$table        = self::table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $key_ids ), '%d' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// Tie-break on MIN(id) so transports first seen in the same second
				// (datetime granularity) keep a stable insertion order, matching
				// the per-key transports_for_key() ordering.
				"SELECT key_id, transport, MIN(first_seen) AS first_seen, MAX(last_seen) AS last_seen FROM {$table} WHERE key_id IN ( {$placeholders} ) AND transport <> '' GROUP BY key_id, transport ORDER BY key_id ASC, first_seen ASC, MIN(id) ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$key_ids
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$summary = array();
		foreach ( $rows as $row ) {
			$key_id    = (int) $row['key_id'];
			$transport = (string) $row['transport'];

			if ( ! isset( $summary[ $key_id ] ) ) {
				$summary[ $key_id ] = array(
					'transports'          => array(),
					'transport_last_seen' => array(),
				);
			}

			$summary[ $key_id ]['transports'][]                      = $transport;
			$summary[ $key_id ]['transport_last_seen'][ $transport ] = (string) $row['last_seen'];
		}

		return $summary;
	}
}
