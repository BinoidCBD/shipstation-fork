<?php
/**
 * Checkout Rates API Client class file.
 *
 * @package WC_ShipStation
 * @since 5.0.8
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

use WooCommerce\Shipping\ShipStation\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Concrete implementation of the checkout rates API client.
 *
 * Sends JSON POST requests to the configured ShipStation rates URL and
 * returns the decoded response. All failures return an empty array.
 *
 * The cache key is derived from the rates URL plus the JSON-encoded payload
 * built by Checkout_Rates_Request_Builder. Third-party mutations applied
 * later via the `http_request_args` core filter (e.g. injected headers) are
 * intentionally not part of the key — for the checkout hot path the payload
 * fully characterises the request, and including downstream filter output
 * would require a dry-run of `wp_safe_remote_post()` per cache lookup.
 *
 * @since 5.0.8
 */
final class Checkout_Rates_Api_Client implements Checkout_Rates_Api_Client_Interface {

	/**
	 * In-request response cache.
	 *
	 * Keys are md5 hashes of the rates URL + JSON-encoded payload.
	 * Values are the decoded response arrays.
	 *
	 * @var array
	 */
	private static $request_cache = array();

	/**
	 * In-request error cache.
	 *
	 * Keys are md5 hashes of the rates URL + JSON-encoded payload.
	 * Values are boolean true for cached errors.
	 *
	 * @var array
	 */
	private static $error_cache = array();

	/**
	 * Fetch shipping rates for the given payload.
	 *
	 * @since 5.0.8
	 *
	 * @param array $payload Rates request payload.
	 *
	 * @return array Rates response data.
	 */
	public function get_rates( array $payload ): array {
		$url = Checkout_Rates_Options::get_rates_url();

		// Defensive early-exit. Production callers gate on Checkout_Rates_Options::is_configured()
		// before reaching this method, but a direct test fixture or future caller that skips that
		// gate would otherwise build a cache key on an empty URL and pollute the error cache with
		// a wp_safe_remote_post('') WP_Error.
		if ( '' === $url ) {
			return array();
		}

		$json_body = wp_json_encode( $payload );

		if ( false === $json_body ) {
			Logger::error(
				'Checkout rates: failed to JSON-encode payload. ' . json_last_error_msg()
			);
			return array();
		}

		$cache_key = md5( $url . '|' . $json_body );

		if ( array_key_exists( $cache_key, self::$error_cache ) ) {
			Logger::debug( 'Checkout rates: returning in-request cached error.' );
			return array();
		}

		if ( array_key_exists( $cache_key, self::$request_cache ) ) {
			Logger::debug( 'Checkout rates: returning in-request cached response.' );
			return self::$request_cache[ $cache_key ];
		}

		$transient_key = 'wc_shipstation_checkout_rates_' . $cache_key;
		$cached        = get_transient( $transient_key );

		if ( false !== $cached && is_array( $cached ) ) {
			Logger::debug( 'Checkout rates: returning transient cached response.' );
			self::$request_cache[ $cache_key ] = $cached;
			return $cached;
		}

		$redacted_url = Checkout_Rates_Options::redact( $url );

		Logger::debug(
			'Checkout rates: requesting rates from ' . $redacted_url,
			array( 'payload' => self::redact_payload_for_log( $payload ) )
		);

		/**
		 * Filter the timeout for checkout rates API requests.
		 *
		 * Defaults to 8 seconds to match ShipStation's server-side timeout for
		 * rate provider calls — waiting longer is dead time. Hosts may set a
		 * shorter value via this filter for faster fallback to other methods.
		 *
		 * @since 5.0.8
		 *
		 * @param int $timeout Timeout in seconds. Default 8. Clamped to a 1s
		 *                    minimum — WP HTTP treats 0 as "no timeout", which
		 *                    would hang checkout indefinitely on a stalled host.
		 */
		$timeout = max( 1, (int) apply_filters( 'woocommerce_shipstation_checkout_rates_timeout', 8 ) );

		// Use wp_safe_remote_post() + redirection => 0 to block SSRF to private
		// IPs and metadata endpoints, matching WC_Webhook::deliver()'s pattern
		// for the analogous "merchant-stored URL we POST to" case.
		$response = wp_safe_remote_post(
			$url,
			array(
				'body'        => $json_body,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'timeout'     => $timeout,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			Logger::error(
				'Checkout rates: request failed. ' . Checkout_Rates_Options::redact( $error_message ),
				array(
					'redacted_url' => $redacted_url,
					'error_code'   => $response->get_error_code(),
				)
			);
			self::$error_cache[ $cache_key ] = true;
			return array();
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== (int) $response_code ) {
			Logger::error(
				'Checkout rates: unexpected response code ' . $response_code . ' from ' . $redacted_url,
				array( 'redacted_url' => $redacted_url )
			);
			self::$error_cache[ $cache_key ] = true;
			return array();
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			Logger::error(
				'Checkout rates: empty response body from ' . $redacted_url,
				array( 'redacted_url' => $redacted_url )
			);
			self::$error_cache[ $cache_key ] = true;
			return array();
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			Logger::error(
				'Checkout rates: invalid JSON response from ' . $redacted_url,
				array( 'redacted_url' => $redacted_url )
			);
			self::$error_cache[ $cache_key ] = true;
			return array();
		}

		Logger::debug(
			'Checkout rates: received response from ' . $redacted_url,
			array( 'response' => self::redact_response_for_log( $data ) )
		);

		/**
		 * Filter the transient cache TTL for checkout rates API responses.
		 *
		 * A TTL of 0 disables transient caching.
		 *
		 * @since 5.0.8
		 *
		 * @param int $ttl TTL in seconds. Default 30.
		 */
		$ttl = (int) apply_filters( 'woocommerce_shipstation_checkout_rates_cache_ttl', 30 );

		if ( $ttl > 0 ) {
			set_transient( $transient_key, $data, $ttl );
		}

		self::$request_cache[ $cache_key ] = $data;
		return $data;
	}

	/**
	 * Strip customer PII from a payload before logging.
	 *
	 * @param array $payload The rates request payload.
	 *
	 * @return array Payload safe to log.
	 */
	private static function redact_payload_for_log( array $payload ): array {
		// Destination keeps only country/postal_code/province/city/address_type;
		// line items keep weight/quantity/sku but lose names.
		if ( ! isset( $payload['rate'] ) || ! is_array( $payload['rate'] ) ) {
			return $payload;
		}

		$rate = $payload['rate'];

		if ( isset( $rate['destination'] ) && is_array( $rate['destination'] ) ) {
			$destination         = $rate['destination'];
			$rate['destination'] = array(
				'country'      => $destination['country'] ?? '',
				'postal_code'  => $destination['postal_code'] ?? '',
				'province'     => $destination['province'] ?? '',
				'city'         => $destination['city'] ?? '',
				'address_type' => $destination['address_type'] ?? '',
			);
		}

		if ( isset( $rate['items'] ) && is_array( $rate['items'] ) ) {
			$rate['items'] = array_map(
				static function ( $item ) {
					if ( ! is_array( $item ) ) {
						return $item;
					}
					unset( $item['name'] );
					return $item;
				},
				$rate['items']
			);
		}

		$payload['rate'] = $rate;
		return $payload;
	}

	/**
	 * Strip sensitive data from an inbound response before logging.
	 *
	 * The current ShipStation rates response shape carries no customer PII
	 * (quote_id, carrier codes, display names, and amounts only) but may echo
	 * back the GUID-tail rates URL on some error envelopes. Walks every string
	 * value through Checkout_Rates_Options::redact() so any future field that
	 * embeds the URL or a GUID is automatically scrubbed without re-auditing.
	 *
	 * @param array $data Decoded response body.
	 *
	 * @return array Response safe to log.
	 */
	private static function redact_response_for_log( array $data ): array {
		array_walk_recursive(
			$data,
			static function ( &$value ) {
				if ( is_string( $value ) ) {
					$value = Checkout_Rates_Options::redact( $value );
				}
			}
		);
		return $data;
	}
}
