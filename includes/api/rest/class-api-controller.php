<?php
/**
 * ShipStation REST API Base Controller file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation\API\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WooCommerce\Shipping\ShipStation\Auth_Controller;
use WooCommerce\Shipping\ShipStation\Connection_Log;
use WooCommerce\Shipping\ShipStation\Features;
use WooCommerce\Shipping\ShipStation\Logger;
use WooCommerce\Shipping\ShipStation\Main;
use WP_Error;
use WP_REST_Request;

/**
 * API_Controller class.
 */
class API_Controller {

	/**
	 * Stable error code returned when ShipStation Basic Auth fails. Surfaced so
	 * the WPCOM proxy and direct callers see one consistent failure shape.
	 */
	const REST_INVALID_CREDENTIALS = 'rest_shipstation_invalid_credentials';

	/**
	 * Minimum seconds between persisted writes of the last-success latch
	 * ({@see Connection_Log::LAST_SUCCESS_OPTION}). The latch only answers "did a
	 * real sync land recently?" for the global broken-connection banner, which
	 * itself arms only after the 24h inactivity window — second-level precision is
	 * needless. Throttling the write keeps the hot REST auth path from issuing a
	 * `wp_options` UPDATE on every request of a paginated sync. Mirrors
	 * {@see Connection_Log::THROTTLE_SECONDS}.
	 */
	const LAST_SUCCESS_THROTTLE_SECONDS = 300;

	/**
	 * Per-request memoisation of looked-up woocommerce_api_keys rows, keyed by
	 * the `wc_api_hash()` digest of the inbound consumer_key. Repeated
	 * permission_callback invocations during a single dispatch reuse the row
	 * fetched on the first call.
	 *
	 * @var array<string, \stdClass|null>
	 */
	private static array $api_key_row_cache = array();

	/**
	 * Log something.
	 *
	 * @param string $message Log message.
	 */
	public function log( $message ) {
		Logger::debug( $message );
	}

	/**
	 * Permission gate shared by every /wc-shipstation/v1/* route.
	 *
	 * Behaviour depends on the WPCOM transport flag AND whether the request
	 * actually arrived through the WPCOM proxy, signalled by the presence of the
	 * `X-ShipStation-Authorization` relay header:
	 *
	 *  - Flag ON + proxied (relay header present): the request must carry the
	 *    ShipStation-issued Basic Auth credential. On match, the current user is
	 *    set to that key's owner so the downstream wc_rest_check_manager_permissions
	 *    check sees an authenticated identity even on `?rest_route=` URLs (which
	 *    bypass WC_REST_Authentication's `/wp-json/wc-*` prefix test). The
	 *    `wc_shipstation_user_can_manage_wc` filter is then applied as the
	 *    second layer.
	 *  - Flag ON + direct (no relay header): the strict step is skipped. WC core's
	 *    `WC_REST_Authentication` remains the auth authority, which preserves the
	 *    query-string consumer_key/secret fallback that misconfigured hosts
	 *    (CGI/FastCGI, header-stripping WAFs) depend on. Scoping the strict gate to
	 *    proxied requests is what keeps that fallback working once the flag is on
	 *    (SHIPSTN-132).
	 *  - Flag OFF: the strict step is skipped. The public
	 *    `wc_shipstation_user_can_manage_wc` filter is the sole authority,
	 *    preserving trunk semantics for sites that have not opted in.
	 *
	 * @since 5.0.5
	 * @since 5.0.9 Strict gate scoped to proxied requests (relay header present).
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @param string          $context wc_rest_check_manager_permissions context, e.g. 'attributes'.
	 * @param string          $action  wc_rest_check_manager_permissions action, 'read' or 'create'.
	 * @return bool|WP_Error `true` when authorized; `false` when the capability
	 *                       layer (`wc_rest_check_manager_permissions` and the
	 *                       `wc_shipstation_user_can_manage_wc` filter) rejects.
	 *                       `WP_Error(401, rest_shipstation_invalid_credentials)`
	 *                       only on Basic Auth failure (credential missing or
	 *                       wrong, or the key owner cannot be resolved).
	 */
	protected function check_namespace_permission( WP_REST_Request $request, string $context, string $action ) {
		// The WPCOM proxy relays the merchant credential on X-ShipStation-Authorization;
		// its presence is the only signal that the request actually came through the
		// proxy. Scope the strict gate to proxied requests so direct calls keep WC
		// core's auth (including the query-string credential fallback) as the
		// authority even when the transport flag is on (SHIPSTN-132).
		$is_proxied = '' !== (string) $request->get_header( 'x_shipstation_authorization' );

		// Diagnostic breadcrumb: record that a request reached a ShipStation
		// route at all, and how it arrived. This is the first checkpoint when a
		// merchant reports "ShipStation can't connect" — it proves whether the
		// request is even arriving, and over which transport. Debug-gated, so it
		// only writes once the merchant enables logging to reproduce.
		Logger::debug(
			sprintf(
				'ShipStation REST request: route=%s method=%s transport=%s',
				$request->get_route(),
				$request->get_method(),
				$is_proxied ? 'wpcom' : 'direct'
			)
		);

		if ( ! $is_proxied ) {
			// Record every direct request for the key list's "Type" column and the
			// connection log — independent of the transport toggle, since
			// ShipStation connects directly with the REST keys whether or not the
			// WordPress.com transport is enabled. Pure telemetry — WC core remains
			// the auth authority on this path (SHIPSTN-132).
			$this->record_direct_connection( $request );
		}
		if ( $is_proxied && ! Features::is_wpcom_transport_enabled() ) {
			// Transport off: ShipStation is still reaching the WordPress.com proxy,
			// but the store rejects it (the strict gate below does not run, so the
			// request fails downstream). Record the attempt so the connection list
			// shows the disabled, store-rejected route as live. Read-only telemetry.
			$this->record_rejected_proxy_connection( $request );
		}
		if ( Features::is_wpcom_transport_enabled() && $is_proxied ) {
			$row = $this->resolve_authenticated_api_key_row( $request );
			if ( null === $row ) {
				return $this->invalid_credentials_error();
			}
			// Mirror what WC_REST_Authentication does for /wp-json/wc-* URLs so
			// the capability check below works on `?rest_route=` URLs (the
			// proxy's outbound form), which bypass WC's user-resolution. Fail
			// closed when the owner cannot be resolved so a deleted owner
			// cannot leak the request to a pre-existing user identity.
			$user_id = (int) $row->user_id;
			if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
				Logger::debug( 'ShipStation Basic Auth rejected: api_key_owner_missing' );
				return $this->invalid_credentials_error();
			}
			if ( get_current_user_id() !== $user_id ) {
				wp_set_current_user( $user_id );
			}

			// Audit trail for support: which row authenticated this call. The
			// truncated_key is the same suffix WC shows in its admin listing,
			// so a support engineer can cross-reference without needing the
			// plaintext key. Gated on the existing Logger flag — no cost when
			// logging is disabled.
			Logger::debug(
				sprintf(
					'ShipStation Basic Auth accepted: key_id=%d truncated_key=%s',
					(int) $row->key_id,
					(string) $row->truncated_key
				)
			);

			// Parity with WC_REST_Authentication, which stamps last_access on
			// every direct authenticated request. WPCOM-set keys authenticate
			// exclusively through this gate, so without the stamp the settings
			// key list would report them as never used (SHIPSTN-142).
			$this->update_api_key_last_access( (int) $row->key_id );

			// Record the proxied connection (key list "Type" + connection log) and
			// leave a diagnostic breadcrumb. Scoped to our own keys; telemetry only.
			if ( Auth_Controller::is_plugin_secret( (string) $row->consumer_secret ) ) {
				$proxy_url = $this->wpcom_proxy_url();
				Connection_Log::record( (int) $row->key_id, (string) $row->truncated_key, 'wpcom', $proxy_url, home_url() );
				$this->log_connection( 'wpcom', (int) $row->key_id, (string) $row->truncated_key, $proxy_url, $request );
				// Latch a real, post-feature successful sync so the global broken-
				// connection banner can tell "was working, now silent" from "never
				// set up" (SHIPSTN-142). Proxied success — site A.
				$this->stamp_last_success();
			}
		}

		/**
		 * Filters whether the current user has permissions to manage WooCommerce
		 * for ShipStation routes.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $can_manage_wc Whether the user can manage WooCommerce.
		 */
		return apply_filters( 'wc_shipstation_user_can_manage_wc', wc_rest_check_manager_permissions( $context, $action ) );
	}

	/**
	 * Build the standard 401 response returned by every ShipStation REST
	 * permission callback when the Basic Auth credential check fails.
	 *
	 * Centralised so the error code, message and status stay consistent
	 * across controllers.
	 *
	 * @since 5.0.5
	 *
	 * @return WP_Error
	 */
	protected function invalid_credentials_error(): WP_Error {
		return new WP_Error(
			self::REST_INVALID_CREDENTIALS,
			__( 'Invalid ShipStation credentials.', 'woocommerce-shipstation-integration' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Validate the inbound Basic Auth credential and return the matching
	 * woocommerce_api_keys row on success, or null on any failure. The strict
	 * gate uses the returned row's user_id to authenticate the request without
	 * a second DB lookup.
	 *
	 * The credential is read exclusively from the `X-ShipStation-Authorization`
	 * relay header: the only caller is the strict gate in
	 * check_namespace_permission(), which runs only for proxied requests (relay
	 * header present). Direct (non-proxied) calls are authenticated upstream by
	 * WC core's WC_REST_Authentication, not here.
	 *
	 * The lookup hashes the inbound consumer_key and queries the row by that
	 * hash. We deliberately do not pin to `woocommerce_shipstation_api_key_id`:
	 * stores that issued ShipStation keys before the auth modal landed (plugin
	 * versions before 5.0.3) never wrote that option, and pinning would 401
	 * their existing valid credentials. The relaxation matches WC core's own
	 * `WC_REST_Authentication` model for `/wp-json/wc-*` URLs; the second-layer
	 * `wc_rest_check_manager_permissions` capability check still gates access.
	 *
	 * @since 5.0.5
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return \stdClass|null woocommerce_api_keys row (user_id, consumer_key,
	 *                       consumer_secret) on success; null on rejection.
	 */
	private function resolve_authenticated_api_key_row( WP_REST_Request $request ): ?\stdClass {
		list( $consumer_key, $consumer_secret ) = $this->parse_basic_auth_payload(
			(string) $request->get_header( 'x_shipstation_authorization' )
		);
		if ( '' === $consumer_key || '' === $consumer_secret ) {
			Logger::debug( 'ShipStation Basic Auth rejected: malformed_authorization' );
			return null;
		}

		$hashed_key = wc_api_hash( $consumer_key );
		$row        = $this->fetch_api_key_row_by_hash( $hashed_key );
		if ( null === $row || empty( $row->consumer_secret ) ) {
			Logger::debug( 'ShipStation Basic Auth rejected: consumer_key_mismatch' );
			return null;
		}

		if ( ! hash_equals( (string) $row->consumer_secret, $consumer_secret ) ) {
			Logger::debug( 'ShipStation Basic Auth rejected: consumer_secret_mismatch' );
			return null;
		}

		return $row;
	}

	/**
	 * Fetch the woocommerce_api_keys row whose consumer_key column matches the
	 * given `wc_api_hash()` digest, memoised for the request lifetime so
	 * repeated permission_callback invocations during a single dispatch do
	 * not re-hit the database.
	 *
	 * @since 5.0.5
	 *
	 * @param string $hashed_consumer_key `wc_api_hash()` of the inbound key.
	 * @return \stdClass|null DB row, or null when no row matches.
	 */
	private function fetch_api_key_row_by_hash( string $hashed_consumer_key ): ?\stdClass {
		if ( array_key_exists( $hashed_consumer_key, self::$api_key_row_cache ) ) {
			return self::$api_key_row_cache[ $hashed_consumer_key ];
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- hash-equality lookup, no CRUD equivalent
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- result memoised in self::$api_key_row_cache for the request lifetime
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT key_id, user_id, consumer_key, consumer_secret, truncated_key FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
				$hashed_consumer_key
			)
		);

		self::$api_key_row_cache[ $hashed_consumer_key ] = $row instanceof \stdClass ? $row : null;
		return self::$api_key_row_cache[ $hashed_consumer_key ];
	}

	/**
	 * Stamp last_access on the authenticated key row, once per request.
	 *
	 * Mirrors WC_REST_Authentication::update_last_access(); the per-request
	 * guard keeps repeated permission_callback invocations during a single
	 * dispatch from re-writing the row.
	 *
	 * @since 5.2.0
	 *
	 * @param int $key_id Authenticated woocommerce_api_keys row id.
	 */
	private function update_api_key_last_access( int $key_id ): void {
		static $stamped = array();

		if ( isset( $stamped[ $key_id ] ) ) {
			return;
		}
		$stamped[ $key_id ] = true;

		global $wpdb;
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- same table/operation WC core uses for this column.
			$wpdb->prefix . 'woocommerce_api_keys',
			array( 'last_access' => current_time( 'mysql' ) ),
			array( 'key_id' => $key_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			// A swallowed failure here re-reports a live key as "never used" in the
			// settings list (the exact bug SHIPSTN-142 fixes), so leave a breadcrumb.
			Logger::error( 'Failed to stamp last_access on ShipStation API key row. DB error: ' . (string) $wpdb->last_error );
		}
	}

	/**
	 * Record 'direct' as an observed transport for the plugin key that
	 * authenticated this direct (non-proxied) request.
	 *
	 * Telemetry for the key list's "Type" column and the connection log — it
	 * never affects the auth decision (WC core authenticated the request
	 * upstream). Scoped to our own keys: a credential that does not resolve to a
	 * plugin-prefixed row is ignored. The connection log throttles repeat writes,
	 * so a steadily polling store adds no per-request overhead beyond one lookup.
	 *
	 * @since 5.2.0
	 *
	 * @param WP_REST_Request $request Current REST request.
	 *
	 * @return void
	 */
	private function record_direct_connection( WP_REST_Request $request ): void {
		$consumer_key = $this->read_direct_consumer_key( $request );
		if ( '' === $consumer_key ) {
			return;
		}

		$row = $this->fetch_api_key_row_by_hash( wc_api_hash( $consumer_key ) );
		if ( null === $row || empty( $row->consumer_secret ) ) {
			return;
		}

		if ( ! Auth_Controller::is_plugin_secret( (string) $row->consumer_secret ) ) {
			return;
		}

		// Stamp last_access so the key list's "Last seen" / Active state tracks
		// direct syncs. WC core's WC_REST_Authentication does not stamp it on every
		// ShipStation request form, so without this a steadily-polling direct store
		// shows a live connection-log row while the key itself reads stale. Gated on
		// the request actually authenticating as this key's owner (WC core set the
		// current user upstream), so a probe that merely presents a known consumer
		// key — without the matching secret — cannot keep the key "Active".
		if ( (int) $row->user_id > 0 && get_current_user_id() === (int) $row->user_id ) {
			$this->update_api_key_last_access( (int) $row->key_id );
		}

		$url = home_url();
		// For direct, the Store URL ShipStation hits IS the site URL, so the
		// resolved target equals it (no proxy hop).
		Connection_Log::record( (int) $row->key_id, (string) $row->truncated_key, 'direct', $url, $url );
		$this->log_connection( 'direct', (int) $row->key_id, (string) $row->truncated_key, $url, $request );
		// Latch a real, post-feature successful sync (see site A). Direct success
		// — site B. NOT stamped on the rejected-proxy path, which is not a success.
		$this->stamp_last_success();
	}

	/**
	 * Record a rejected WordPress.com proxy attempt as a 'wpcom' connection.
	 *
	 * With the transport off, ShipStation may still be pointed at the proxy Store
	 * URL and keep polling; the store rejects those requests (the strict gate never
	 * sets a user while the toggle is off), but recording the attempt lets the
	 * connection list surface the disabled, store-rejected route as live rather
	 * than frozen. The credential is verified, so only a genuine ShipStation
	 * attempt is recorded — a bare probe is ignored. Never sets a user; telemetry.
	 *
	 * The live proxy URL can't be resolved while the transport is off (the
	 * connection facade is gated by the toggle), so the key's existing wpcom row
	 * URL is reused rather than spawning a urless duplicate row.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 *
	 * @return void
	 */
	private function record_rejected_proxy_connection( WP_REST_Request $request ): void {
		$row = $this->resolve_authenticated_api_key_row( $request );
		if ( null === $row ) {
			return;
		}

		if ( ! Auth_Controller::is_plugin_secret( (string) $row->consumer_secret ) ) {
			return;
		}

		$proxy_url = Connection_Log::latest_url_for_transport( (int) $row->key_id, 'wpcom' );
		if ( '' === $proxy_url ) {
			return;
		}

		Connection_Log::record( (int) $row->key_id, (string) $row->truncated_key, 'wpcom', $proxy_url, home_url() );
		$this->log_connection( 'wpcom', (int) $row->key_id, (string) $row->truncated_key, $proxy_url, $request );
	}

	/**
	 * Stamp the UTC epoch of a real, post-feature successful plugin-key auth so
	 * the global broken-connection banner can latch "was working" (SHIPSTN-142).
	 *
	 * Called from the two SUCCESS record sites only — the proxied gate and the
	 * direct path, both already scoped to plugin keys — never from the rejected-
	 * proxy path (a turned-away request is not a success). The option name is a
	 * constant on Connection_Log (already loaded in REST context); the renderer
	 * reads the same constant. Non-autoloaded — read only on the WC settings
	 * admin render, never on a front-end page load.
	 *
	 * The write is throttled to a {@see LAST_SUCCESS_THROTTLE_SECONDS} window so the
	 * latch costs nothing on the hot path: the `wp_options` UPDATE is skipped across
	 * the many requests of a paginated sync (and across repeated permission_callback
	 * invocations within a single dispatch, where the second read is served from the
	 * option cache). A genuinely broken connection has no successful sync for >24h,
	 * so the stored value is far outside the window when recovery lands — the latch
	 * always advances on the first real success (and on the first-ever success,
	 * where the stored value is 0).
	 *
	 * @since 5.2.0
	 *
	 * @return void
	 */
	private function stamp_last_success(): void {
		$now  = time();
		$last = (int) get_option( Connection_Log::LAST_SUCCESS_OPTION, 0 );

		if ( ( $now - $last ) < self::LAST_SUCCESS_THROTTLE_SECONDS ) {
			return;
		}

		update_option( Connection_Log::LAST_SUCCESS_OPTION, $now, false );
	}

	/**
	 * The WordPress.com proxy Store URL for this site, or '' when it cannot be
	 * resolved (no connection facade / not connected). Used as the source URL of
	 * a proxied connection record.
	 *
	 * @since 5.2.0
	 *
	 * @return string
	 */
	private function wpcom_proxy_url(): string {
		$connection = Main::instance()->get_wpcom_connection();
		if ( null === $connection ) {
			return '';
		}

		$url = $connection->get_proxy_url();

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Emit a diagnostic breadcrumb for a recorded ShipStation connection so a
	 * support engineer can see which key + URL + transport ShipStation is using
	 * when a merchant reports a connection problem. Debug-gated.
	 *
	 * @since 5.2.0
	 *
	 * @param string          $transport     'wpcom' or 'direct'.
	 * @param int             $key_id        Authenticated key row id.
	 * @param string          $truncated_key Last-7 of the consumer key.
	 * @param string          $url           Source URL ShipStation connected on.
	 * @param WP_REST_Request $request       Current REST request.
	 *
	 * @return void
	 */
	private function log_connection( string $transport, int $key_id, string $truncated_key, string $url, WP_REST_Request $request ): void {
		Logger::debug(
			sprintf(
				'ShipStation connection: transport=%s key_id=%d truncated_key=%s url=%s route=%s',
				$transport,
				$key_id,
				$truncated_key,
				'' !== $url ? $url : '(unknown)',
				$request->get_route()
			)
		);
	}

	/**
	 * Read the consumer key a direct request presented, from the standard
	 * Authorization Basic header or the query-string `consumer_key` fallback
	 * (the same two places WC core's WC_REST_Authentication looks). Only the
	 * key is needed — to identify the row — not the secret; WC core already
	 * validated the credential on this path.
	 *
	 * @since 5.2.0
	 *
	 * @param WP_REST_Request $request Current REST request.
	 *
	 * @return string Plain consumer key, or '' when none is present.
	 */
	private function read_direct_consumer_key( WP_REST_Request $request ): string {
		list( $key ) = $this->parse_basic_auth_payload( (string) $request->get_header( 'authorization' ) );
		if ( '' !== $key ) {
			return $key;
		}

		$param = $request->get_param( 'consumer_key' );

		return is_string( $param ) ? $param : '';
	}

	/**
	 * Decode an `Authorization: Basic …` header value into a consumer key / secret pair.
	 *
	 * @param string $authorization Raw header value.
	 * @return array{0: string, 1: string} Key and secret, both '' on any failure.
	 */
	private function parse_basic_auth_payload( string $authorization ): array {
		if ( '' === $authorization || 0 !== stripos( $authorization, 'Basic ' ) ) {
			return array( '', '' );
		}

		$decoded = base64_decode( substr( $authorization, 6 ), true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return array( '', '' );
		}

		list( $key, $secret ) = explode( ':', $decoded, 2 );
		return array( (string) $key, (string) $secret );
	}
}
