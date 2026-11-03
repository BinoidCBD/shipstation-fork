<?php
/**
 * WooCommerce PayPal Payments integration.
 *
 * @package WC_ShipStation
 * @since 5.3.9
 */

namespace WooCommerce\Shipping\ShipStation\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives PayPal Payments checkout requests ShipStation checkout rates.
 *
 * Admits ppc-validate-checkout, and ppc-create-order from the checkout page.
 * The body gives the context until PayPal's create-order action fires.
 * The body only allows a rates request. PayPal checks its own nonce.
 *
 * @since 5.3.9
 */
final class PayPal_Payments_Integration {

	/**
	 * PayPal action fired when a create-order request starts.
	 */
	private const CREATE_ORDER_STARTED_HOOK = 'woocommerce_paypal_payments_create_order_request_started';

	/**
	 * PayPal wc-ajax action that creates the PayPal order.
	 */
	private const CREATE_ORDER_ACTION = 'ppc-create-order';

	/**
	 * PayPal wc-ajax action that validates the checkout form.
	 */
	private const VALIDATE_CHECKOUT_ACTION = 'ppc-validate-checkout';

	/**
	 * Prefix of every PayPal wc-ajax action.
	 */
	private const ACTION_PREFIX = 'ppc-';

	/**
	 * Create-order contexts sent from the checkout page, classic and block.
	 */
	private const CHECKOUT_CONTEXTS = array( 'checkout', 'checkout-block' );

	/**
	 * Whether create-order came from the checkout page. Null until known.
	 *
	 * @var bool|null
	 */
	private static $checkout_create_order = null;

	/**
	 * Request body set by tests. Null reads php://input.
	 *
	 * @var string|null
	 */
	private static $raw_request_body = null;

	/**
	 * Hook PayPal's create-order action and the context filter.
	 *
	 * @since 5.3.9
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::CREATE_ORDER_STARTED_HOOK, array( self::class, 'note_create_order_request' ) );
		add_filter( 'woocommerce_shipstation_checkout_rates_is_checkout_context', array( self::class, 'filter_is_checkout_context' ) );
	}

	/**
	 * Remove the hooks added by register().
	 *
	 * @since 5.3.9
	 *
	 * @return void
	 */
	public static function unregister(): void {
		remove_action( self::CREATE_ORDER_STARTED_HOOK, array( self::class, 'note_create_order_request' ) );
		remove_filter( 'woocommerce_shipstation_checkout_rates_is_checkout_context', array( self::class, 'filter_is_checkout_context' ) );
	}

	/**
	 * Record whether create-order came from the checkout page.
	 *
	 * @since 5.3.9
	 *
	 * @param mixed $data PayPal Payments request data.
	 *
	 * @return void
	 */
	public static function note_create_order_request( $data ): void {
		self::$checkout_create_order = self::is_checkout_context_data( $data );
	}

	/**
	 * Admit PayPal checkout requests through the context filter.
	 *
	 * @since 5.3.9
	 *
	 * @param mixed $is_checkout_context Answer so far.
	 *
	 * @return mixed True for a PayPal checkout request, else the answer unchanged.
	 */
	public static function filter_is_checkout_context( $is_checkout_context ) {
		if ( $is_checkout_context || ! isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PayPal checks its own nonce. Only the action name is read.
			return $is_checkout_context;
		}

		$action = sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 0 !== strpos( $action, self::ACTION_PREFIX ) ) {
			return $is_checkout_context;
		}

		if ( self::VALIDATE_CHECKOUT_ACTION === $action ) {
			return true;
		}

		if ( self::CREATE_ORDER_ACTION === $action && self::is_checkout_create_order() ) {
			return true;
		}

		return $is_checkout_context;
	}

	/**
	 * Whether this create-order request came from the checkout page.
	 *
	 * @return bool
	 */
	private static function is_checkout_create_order(): bool {
		// Shipping can be calculated before PayPal's action fires. Read the body then.
		if ( null === self::$checkout_create_order ) {
			self::$checkout_create_order = self::is_checkout_context_data( json_decode( self::raw_request_body(), true ) );
		}

		return self::$checkout_create_order;
	}

	/**
	 * Whether PayPal request data names a checkout context.
	 *
	 * @param mixed $data Decoded request data.
	 *
	 * @return bool
	 */
	private static function is_checkout_context_data( $data ): bool {
		return is_array( $data ) && in_array( $data['context'] ?? null, self::CHECKOUT_CONTEXTS, true );
	}

	/**
	 * The raw request body.
	 *
	 * @return string
	 */
	private static function raw_request_body(): string {
		if ( null !== self::$raw_request_body ) {
			return self::$raw_request_body;
		}

		$body = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the request body, not a remote URL.

		return is_string( $body ) ? $body : '';
	}

	/**
	 * Forget the recorded create-order context.
	 *
	 * @internal For tests.
	 *
	 * @since 5.3.9
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$checkout_create_order = null;
		self::$raw_request_body      = null;
	}

	/**
	 * Use the given string as the request body instead of php://input.
	 *
	 * @internal For tests.
	 *
	 * @since 5.3.9
	 *
	 * @param string|null $body Request body, or null to read php://input.
	 *
	 * @return void
	 */
	public static function set_raw_request_body( ?string $body ): void {
		self::$raw_request_body = $body;
	}
}
