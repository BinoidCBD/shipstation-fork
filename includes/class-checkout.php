<?php
/**
 * Class WC_ShipStation_Checkout file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Exception;
use WC_Cart;
use WC_Order;
use WP_Error;
use WP_HTML_Tag_Processor;
use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use WC_ShipStation_Integration;

/**
 * Checkout Class
 */
class Checkout {

	/**
	 * The namespace for our checkout fields.
	 *
	 * @var string
	 */
	const FIELD_NAMESPACE = 'woocommerce_shipstation';

	/**
	 * Maximum length of the gift message.
	 *
	 * @var int
	 */
	public int $gift_message_max_length;

	/**
	 * Constructor method.
	 */
	public function __construct() {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'add_gift_fields_to_classic_checkout' ) );
		add_filter( 'woocommerce_form_field_checkbox', array( $this, 'modify_optional_label' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_classic_checkout_gift_fields' ) );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_gift_field_values_to_classic_checkout_order_meta' ), 10, 1 );
		add_filter( 'woocommerce_admin_shipping_fields', array( $this, 'maybe_display_gift_data_below_admin_shipping_fields' ), 15, 3 );
		add_action( 'woocommerce_thankyou', array( $this, 'maybe_display_order_gift_data_for_customers' ) );
		add_action( 'woocommerce_init', array( $this, 'add_gift_fields_to_block_checkout' ) );
		add_filter( 'woocommerce_filter_fields_for_order_confirmation', array( $this, 'filter_fields_for_order_confirmation' ), 10, 2 );
		add_action( 'woocommerce_shipping_init', array( $this, 'maybe_deregister_fields' ) );
		add_action( 'woocommerce_blocks_validate_location_order_fields', array( $this, 'validate_block_checkout_gift_fields' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'maybe_delete_order_gift_field_meta_data' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'delete_sessions' ), 15 );
		add_action( 'woocommerce_email_customer_details', array( $this, 'display_gift_fields_in_order_email' ), 40, 3 );

		// AJAX handlers for persisting gift field values.
		add_action( 'wp_ajax_shipstation_save_field_value', array( $this, 'ajax_save_field_value' ) );
		add_action( 'wp_ajax_nopriv_shipstation_save_field_value', array( $this, 'ajax_save_field_value' ) );
		add_action( 'wp_ajax_shipstation_load_field_values', array( $this, 'ajax_load_field_values' ) );
		add_action( 'wp_ajax_nopriv_shipstation_load_field_values', array( $this, 'ajax_load_field_values' ) );

		/**
		 * We'll set the default gift message max length to 255 characters, allowing merchants to override it, while
		 * enforcing a minimum of 1 and a maximum of 1000 characters.
		 *
		 * ShipStation allows a maximum of 1000 characters for the gift message. 1 is the shortest "usable" length.
		 *
		 * @since 4.7.0
		 */
		$this->gift_message_max_length = min( max( intval( apply_filters( 'woocommerce_shipstation_gift_message_max_length', 255 ) ), 1 ), 1000 );
	}

	/**
	 * Enqueue checkout scripts and styles.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		if ( ! WC_ShipStation_Integration::$gift_enabled ) {
			return;
		}

		if ( ! self::cart_needs_shipping() ) {
			return;
		}

		if ( self::is_block_checkout() ) {
			wp_enqueue_style( 'shipstation-block-checkout', WC_SHIPSTATION_PLUGIN_URL . 'assets/css/block-checkout.css', array(), WC_SHIPSTATION_VERSION );
			wp_add_inline_style( 'shipstation-block-checkout', $this->display_gift_field_descriptions_on_block_checkout() );
		}

		if ( self::is_classic_checkout() ) {
			wp_enqueue_style( 'shipstation-classic-checkout', WC_SHIPSTATION_PLUGIN_URL . 'assets/css/classic-checkout.css', array(), WC_SHIPSTATION_VERSION );

			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			wp_enqueue_script( 'wc-shipstation-checkout', WC_SHIPSTATION_PLUGIN_URL . 'assets/js/checkout' . $suffix . '.js', array( 'wc-checkout' ), WC_SHIPSTATION_VERSION, true );

			wp_localize_script(
				'wc-shipstation-checkout',
				'wc_shipstation_checkout_params',
				array(
					'field_ids' => array(
						'is_gift'      => WC_ShipStation_Integration::$order_meta_keys['is_gift'],
						'gift_message' => WC_ShipStation_Integration::$order_meta_keys['gift_message'],
					),
				)
			);
		}
	}

	/**
	 * Get a list of gift fields.
	 *
	 * @return array
	 */
	protected function get_gift_fields(): array {
		return array(
			array(
				'id'                         => WC_ShipStation_Integration::$order_meta_keys['is_gift'],
				'type'                       => 'checkbox',
				'class'                      => array( 'woocommerce-shipstation-gift', 'shipstation-is-gift' ),
				'label'                      => __( 'Send as gift', 'woocommerce-shipstation-integration' ),
				'optionalLabel'              => __( 'Mark order as a gift', 'woocommerce-shipstation-integration' ),
				'description'                => __( 'Gift orders include an optional personal message on the packing slip.', 'woocommerce-shipstation-integration' ),
				'truncated_value'            => false,
				'required'                   => false,
				'show_in_order_confirmation' => true,
				'attributes'                 => array(
					// translators: Aria-label for the gift message checkbox.
					'aria-label' => __( 'Mark order as a gift. When enabled order will include an optional personal message on the packing slip.', 'woocommerce-shipstation-integration' ),
				),
			),
			array(
				'id'                         => WC_ShipStation_Integration::$order_meta_keys['gift_message'],
				'type'                       => 'text',
				'class'                      => array( 'woocommerce-shipstation-gift', 'shipstation-gift-message' ),
				'label'                      => __( 'Gift message', 'woocommerce-shipstation-integration' ),
				'optionalLabel'              => __( 'Add gift message', 'woocommerce-shipstation-integration' ),
				// translators: %1$d is the maximum length of the gift message.
				'description'                => sprintf( __( 'Use the space above to enter your gift message. Approx., %1$d characters.', 'woocommerce-shipstation-integration' ), $this->gift_message_max_length ),
				'truncated_value'            => true,
				'placeholder'                => esc_attr__( 'Message for the gift.', 'woocommerce-shipstation-integration' ),
				'attributes'                 => array(
					'maxLength'  => $this->gift_message_max_length,
					// translators: %1$d is the maximum length of the gift message.
					'aria-label' => sprintf( __( 'Gift message, optional. Use this field to enter your gift message. Approximately %1$d characters.', 'woocommerce-shipstation-integration' ), $this->gift_message_max_length ),
				),
				'required'                   => false,
				'sanitize_callback'          => function ( $value ) {
					return sanitize_textarea_field( $value );
				},
				'show_in_order_confirmation' => true,
				'desc_tip'                   => true,
			),
		);
	}

	/**
	 * Add gift fields to the classic checkout form.
	 *
	 * @param array $checkout_fields List of checkout fields.
	 *
	 * @return array Modified list of checkout fields including gift fields if enabled.
	 */
	public function add_gift_fields_to_classic_checkout( array $checkout_fields ): array {
		if ( ! WC_ShipStation_Integration::$gift_enabled || ! self::cart_needs_shipping() ) {
			return $checkout_fields;
		}

		foreach ( $this->get_gift_fields() as $gift_field ) {
			$checkout_fields['order'][ $gift_field['id'] ]          = $gift_field;
			$checkout_fields['order'][ $gift_field['id'] ]['label'] = $gift_field['optionalLabel'];
			if ( ! empty( $gift_field['attributes']['maxLength'] ) ) {
				$checkout_fields['order'][ $gift_field['id'] ]['maxlength'] = $gift_field['attributes']['maxLength'];
			}
		}

		return $checkout_fields;
	}

	/**
	 * Hide (optional) text in checkbox label.
	 *
	 * @param string $field Field HTML.
	 * @param string $key   Field key.
	 *
	 * @return string
	 */
	public function modify_optional_label( string $field, string $key ): string {
		if ( 'shipstation_is_gift' !== $key ) {
			return $field;
		}

		$p = new WP_HTML_Tag_Processor( $field );

		while ( $p->next_tag( 'span' ) ) {
			if ( $p->get_attribute( 'class' ) === 'optional' ) {
				$p->add_class( 'screen-reader-text' );
				break;
			}
		}

		return $p->get_updated_html();
	}

	/**
	 * Add gift fields to the block checkout form.
	 *
	 * @return void
	 */
	public function add_gift_fields_to_block_checkout(): void {
		if ( ! WC_ShipStation_Integration::$gift_enabled ) {
			return;
		}

		foreach ( $this->get_gift_fields() as $gift_field ) {
			try {
				$label = ( 'checkbox' !== $gift_field['type'] && false === $gift_field['required'] ) ? $gift_field['optionalLabel'] . ' (' . __( 'optional', 'woocommerce-shipstation-integration' ) . ')' : $gift_field['optionalLabel'];
				woocommerce_register_additional_checkout_field(
					array_merge(
						$gift_field,
						array(
							'id'            => self::get_namespaced_field_key( $gift_field['id'] ),
							'optionalLabel' => $label,
							'location'      => 'order',
							'placeholder'   => '',
						)
					)
				);
			} catch ( Exception $e ) {
				// Log error silently.
				Logger::error( $e->getMessage() );
			}
		}
	}

	/**
	 * Deregister gift fields when they are not needed.
	 *
	 * This method removes gift fields from the block checkout in the following cases:
	 * - When the cart doesn't need shipping
	 * - On order-received page when the order is not a gift
	 * - On view-order page when the order is not a gift
	 *
	 * @return void
	 */
	public function maybe_deregister_fields() {
		// Early return if the deregister function doesn't exist.
		if ( ! function_exists( '__internal_woocommerce_blocks_deregister_checkout_field' ) ) {
			return;
		}

		// Don't deregister fields in admin or on irrelevant frontend pages.
		if ( is_admin() || ! self::is_checkout() ) {
			return;
		}

		// Keep fields if we're on a regular checkout page and the cart needs shipping.
		if ( self::cart_needs_shipping() ) {
			return;
		}

		// Handle relevant Woo endpoints.
		if ( is_wc_endpoint_url( 'order-received' ) ) {
			$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended --- Nonce is not required here.
			if ( ! empty( $order_key ) ) {
				$order_id = wc_get_order_id_by_order_key( $order_key );
				if ( ! empty( $order_id ) && $this->is_order_a_gift( $order_id ) ) {
					return;
				}
			}
		} elseif ( is_wc_endpoint_url( 'view-order' ) ) {
			$order_id = absint( get_query_var( 'view-order' ) );
			if ( ! empty( $order_id ) && $this->is_order_a_gift( $order_id ) ) {
				return;
			}
		}

		// If we've reached this point, deregister all gift fields.
		try {
			foreach ( $this->get_gift_fields() as $gift_field ) {
				__internal_woocommerce_blocks_deregister_checkout_field(
					self::get_namespaced_field_key( $gift_field['id'] )
				);
			}
		} catch ( Exception $e ) {
			Logger::error(
				sprintf( 'Error deregistering gift fields: %s', $e->getMessage() )
			);
		}
	}

	/**
	 * Is the order a gift?
	 *
	 * Checks if the order is marked as a gift.
	 *
	 * @param int $order_id The order ID.
	 *
	 * @return bool
	 */
	private function is_order_a_gift( int $order_id ): bool {
		$order = wc_get_order( $order_id );
		if ( empty( $order ) ) {
			return false;
		}

		return (bool) $order->get_meta( self::get_block_prefixed_meta_key( 'is_gift' ) );
	}

	/**
	 * Display gift field descriptions on the block checkout form by injecting CSS styles.
	 *
	 * Generates CSS rules that use custom properties to display description text
	 * below each gift-related field in the block checkout form. This allows the
	 * descriptions to be styled and positioned consistently while maintaining
	 * proper spacing.
	 *
	 * @return string CSS rules for displaying gift field descriptions, with each rule
	 *                targeting a specific gift field class and setting its description text.
	 */
	private function display_gift_field_descriptions_on_block_checkout(): string {
		ob_start();
		foreach ( $this->get_gift_fields() as $gift_field ) {
			$css_class   = self::get_css_class_field_key( $gift_field['id'] );
			$description = isset( $gift_field['description'] ) ? $gift_field['description'] : '';
			echo '
			.wc-block-components-address-form__', esc_attr( $css_class ), ' {
				--description-text: "', esc_attr( $description ), '";
			}';
		}

		return ob_get_clean();
	}

	/**
	 * Validates the gift fields during checkout.
	 *
	 * Checks if the gift message length is within allowed limits when an order is marked as a gift.
	 * Returns validation error details if the message exceeds the maximum characters.
	 *
	 * @param boolean $is_gift      Whether the order is marked as a gift.
	 * @param string  $gift_message Optional gift message text.
	 *
	 * @return array Empty array if validation passes, or array with error details.
	 */
	protected function validate_gift_fields( bool $is_gift, string $gift_message ): array {
		if ( $is_gift && strlen( $gift_message ) > $this->gift_message_max_length ) {
			return array(
				'id'   => 'shipstation_exceeded_gift_message',
				'text' => sprintf(
					// translators: %1$d is the maximum length of the gift message.
					__( 'Please ensure the gift message does not exceed %d characters.', 'woocommerce-shipstation-integration' ),
					$this->gift_message_max_length
				),
			);
		}

		return array();
	}

	/**
	 * Validates gift fields during block checkout validation.
	 *
	 * @param WP_Error $errors WordPress error object to add validation errors.
	 * @param array    $fields List of checkout fields.
	 *
	 * @return void
	 */
	public function validate_block_checkout_gift_fields( WP_Error $errors, array $fields ): void {
		if ( ! WC_ShipStation_Integration::$gift_enabled || ! self::cart_needs_shipping() ) {
			return;
		}

		$is_gift_field_id      = self::get_namespaced_field_key( 'is_gift' );
		$gift_message_field_id = self::get_namespaced_field_key( 'gift_message' );

		$is_gift_value      = isset( $fields[ $is_gift_field_id ] ) ? boolval( $fields[ $is_gift_field_id ] ) : false;
		$gift_message_value = isset( $fields[ $gift_message_field_id ] ) ? $fields[ $gift_message_field_id ] : '';

		$validation_results = $this->validate_gift_fields( $is_gift_value, $gift_message_value );

		if ( ! empty( $validation_results ) ) {
			$errors->add( $validation_results['id'], $validation_results['text'] );
		}
	}

	/**
	 * Deletes gift-related metadata from an order if the order is not marked as a gift.
	 * This helps keep the order metadata clean by removing unnecessary gift fields when they are not being used.
	 *
	 * @param WC_Order $order WooCommerce Order object to check and potentially clean gift metadata from.
	 *
	 * @return void
	 */
	public function maybe_delete_order_gift_field_meta_data( WC_Order $order ): void {
		$is_gift_meta_key = self::get_block_prefixed_meta_key( 'is_gift' );
		$is_gift_value    = $order->get_meta( $is_gift_meta_key );

		if ( empty( $is_gift_value ) || ! self::cart_needs_shipping() ) {
			$this->delete_order_gift_field_meta_data( $order );
		}
	}

	/**
	 * Deletes gift-related metadata from an order.
	 *
	 * @param WC_Order $order WooCommerce Order object.
	 *
	 * @return void
	 */
	public function delete_order_gift_field_meta_data( WC_Order $order ): void {
		$is_gift_meta_key      = self::get_block_prefixed_meta_key( 'is_gift' );
		$gift_message_meta_key = self::get_block_prefixed_meta_key( 'gift_message' );

		$order->delete_meta_data( $is_gift_meta_key );
		$order->delete_meta_data( $gift_message_meta_key );
	}

	/**
	 * Validates gift fields during classic checkout validation.
	 *
	 * @return void
	 */
	public function validate_classic_checkout_gift_fields(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing --- Nonce is not required here.
		$is_gift      = isset( $_POST[ WC_ShipStation_Integration::$order_meta_keys['is_gift'] ] ) ? (bool) $_POST[ WC_ShipStation_Integration::$order_meta_keys['is_gift'] ] : false; //phpcs:ignore
		$gift_message = isset( $_POST[ WC_ShipStation_Integration::$order_meta_keys['gift_message'] ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ WC_ShipStation_Integration::$order_meta_keys['gift_message'] ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$validation_results = $this->validate_gift_fields( $is_gift, $gift_message );
		if ( ! empty( $validation_results ) ) {
			wc_add_notice( $validation_results['text'], 'error' );
		}
	}

	/**
	 * Saves gift-related field values submitted during classic checkout to order meta data.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return void
	 */
	public function save_gift_field_values_to_classic_checkout_order_meta( int $order_id ): void {
		if ( ! WC_ShipStation_Integration::$gift_enabled || ! self::cart_needs_shipping() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing --- Nonce is not required here.
		$is_gift      = isset( $_POST[ WC_ShipStation_Integration::$order_meta_keys['is_gift'] ] ) ? (bool) sanitize_text_field( wp_unslash( $_POST[ WC_ShipStation_Integration::$order_meta_keys['is_gift'] ] ) ) : false;
		$gift_message = isset( $_POST[ WC_ShipStation_Integration::$order_meta_keys['gift_message'] ] ) ? $this->sanitize_gift_message( wp_unslash( $_POST[ WC_ShipStation_Integration::$order_meta_keys['gift_message'] ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $is_gift ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order->update_meta_data( self::get_block_prefixed_meta_key( 'is_gift' ), true );

		if ( ! empty( $gift_message ) ) {
			$order->update_meta_data( self::get_block_prefixed_meta_key( 'gift_message' ), substr( $gift_message, 0, $this->gift_message_max_length ) );
		}

		$order->save_meta_data();

		// Clear the session data after saving to the order.
		$this->delete_sessions();
	}

	/**
	 * Gets formatted gift data from an order.
	 *
	 * Retrieves and formats gift-related data from order meta, including
	 * - Gift status (whether order is marked as gift)
	 * - Gift message if present
	 * - Formatted values appropriate for admin or frontend display
	 *
	 * @param WC_Order $order   WooCommerce order object to get gift data from.
	 *
	 * @return array{
	 *     is_gift: bool,
	 *     field_data: array,
	 * } Array of gift field data.
	 */
	public function get_order_gift_data( WC_Order $order ): array {
		$data = array(
			'is_gift'    => false, // Whether the order is marked as a gift.
			'field_data' => array(), // List of gift field data.
		);

		$is_gift_meta_key = self::get_block_prefixed_meta_key( 'is_gift' );

		foreach ( $this->get_gift_fields() as $gift_field ) {
			$meta_key        = self::get_block_prefixed_meta_key( $gift_field['id'] );
			$field_value     = $order->get_meta( $meta_key );
			$formatted_value = $field_value;

			// If this is not a checkbox field, skip if the field value is empty.
			if ( empty( $field_value ) && 'checkbox' !== $gift_field['type'] ) {
				continue;
			}

			if ( 'checkbox' === $gift_field['type'] ) {
				$is_checked      = ! empty( $field_value );
				$formatted_value = $is_checked ? __( 'Yes', 'woocommerce-shipstation-integration' ) : __( 'No', 'woocommerce-shipstation-integration' );

				// If the order is marked as a gift, set the checkbox value to true.
				if ( $is_checked && $is_gift_meta_key === $meta_key ) {
					$data['is_gift'] = true;
				}
			} elseif ( 'text' === $gift_field['type'] ) {
				// Truncate the value if it is too long for the gift message field.
				$formatted_value = wp_trim_words( $field_value, 13, '...' );
			}

			$data['field_data'][ self::get_namespaced_field_key( $gift_field['id'] ) ] = array(
				'id'      => $meta_key,
				'label'   => $gift_field['label'],
				'value'   => $formatted_value,
				'cbvalue' => true, // It's needed for the checkbox field.
				'type'    => $gift_field['type'],
			);
		}

		return $data;
	}

	/**
	 * Displays gift fields for the customer order detail views if conditions are met.
	 * Shows gift details only if:
	 * - Order was created via checkout OR using a block theme
	 * - Gift fields data exists
	 * - Order is marked as a gift
	 *
	 * @param WC_Order|int $order Order object or order ID.
	 *
	 * @return void
	 */
	public function maybe_display_order_gift_data_for_customers( $order ): void {
		$order = wc_get_order( $order );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$created_via_checkout = 'checkout' === $order->get_created_via();
		if ( ! $created_via_checkout ) {
			return;
		}

		$gift_data       = $this->get_order_gift_data( $order );
		$is_gift         = $gift_data['is_gift'];
		$gift_field_data = $gift_data['field_data'];

		if ( ! $is_gift || empty( $gift_field_data ) ) {
			return;
		}

		$rendered_data = array_map(
			function ( $gift_data ) {
				return sprintf( '<dt>%1$s</dt><dd>%2$s</dd>', $gift_data['label'], $gift_data['value'] );
			},
			$gift_field_data
		);

		echo '<section class="wc-block-order-confirmation-additional-fields-wrapper wc-shipstation">';
		echo '<h2>' . esc_html__( 'Additional information', 'woocommerce-shipstation-integration' ) . '</h2>';
		echo '<dl class="wc-block-components-additional-fields-list">' . implode( '', $rendered_data ) . '</dl>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';
	}

	/**
	 * Show or hide gift fields based on conditions.
	 *
	 * @param bool  $show_field Should field be shown flag.
	 * @param array $field       Field data.
	 *
	 * @return bool
	 */
	public function filter_fields_for_order_confirmation( $show_field, $field ): bool {
		if ( ! isset( $field['id'] ) ) {
			return $show_field;
		}

		if ( self::get_namespaced_field_key( 'is_gift' ) === $field['id'] ) {
			return isset( $field['value'] ) && 'Yes' === $field['value'] ? true : false;
		}

		if ( self::get_namespaced_field_key( 'gift_message' ) === $field['id'] ) {
			return ! empty( $field['value'] ) ? true : false;
		}

		return $show_field;
	}

	/**
	 * Displays or modifies gift data fields shown below shipping fields in the admin order screen.
	 * When context is 'edit', removes gift fields from shipping fields to prevent editing the fields.
	 * When context is not 'edit', adds gift data to shipping fields if the order is marked as gift.
	 *
	 * @param array          $shipping_fields List of shipping fields to display in admin.
	 * @param WC_Order|false $order           WooCommerce order object containing gift data.
	 * @param string         $context         Context of display - 'edit' for editing, other for viewing.
	 *
	 * @return array Modified list of shipping fields including gift data if applicable.
	 */
	public function maybe_display_gift_data_below_admin_shipping_fields( $shipping_fields, $order, $context ): array {

		// Hide fields in edit context, don't allow to edit values.
		if ( 'edit' === $context ) {
			foreach ( $this->get_gift_fields() as $gift_field ) {
				unset( $shipping_fields[ self::get_namespaced_field_key( $gift_field['id'] ) ] );
			}

			return $shipping_fields;
		}

		if ( ! $order instanceof WC_Order ) {
			return $shipping_fields;
		}

		$gift_data       = $this->get_order_gift_data( $order );
		$is_gift         = $gift_data['is_gift'];
		$gift_field_data = $gift_data['field_data'];

		if ( ! $is_gift || empty( $gift_field_data ) ) {
			return $shipping_fields;
		}

		return array_merge( $shipping_fields, $gift_field_data );
	}

	/**
	 * Set session.
	 *
	 * @param string $name  Session name.
	 * @param mixed  $value Session value.
	 *
	 * @return void
	 */
	public static function session_set( string $name, $value ): void {
		unset( WC()->session->$name );
		WC()->session->$name = $value;
	}

	/**
	 * Get Session.
	 *
	 * @param string $name Session name.
	 *
	 * @return mixed Session.
	 */
	public static function session_get( string $name ) {
		if ( isset( WC()->session->$name ) ) {
			return WC()->session->$name;
		}

		return null;
	}

	/**
	 * Delete session variables.
	 *
	 * @return void
	 */
	public function delete_sessions(): void {
		self::session_delete( WC_ShipStation_Integration::$order_meta_keys['is_gift'] );
		self::session_delete( WC_ShipStation_Integration::$order_meta_keys['gift_message'] );
	}

	/**
	 * Delete session.
	 *
	 * @param string $name Session name.
	 *
	 * @return void
	 */
	public static function session_delete( string $name ): void {
		unset( WC()->session->$name );
	}

	/**
	 * Get the fully prefixed block checkout meta key for a given meta key.
	 *
	 * @param string $meta_key The meta key.
	 *
	 * @return string The fully prefixed block checkout meta key.
	 */
	public static function get_block_prefixed_meta_key( string $meta_key ): string {
		return CheckoutFields::OTHER_FIELDS_PREFIX . self::get_namespaced_field_key( $meta_key );
	}

	/**
	 * Get the namespaced field key.
	 *
	 * @param string $field_key The field key to be namespaced.
	 *
	 * @return string The namespaced field key.
	 */
	public static function get_namespaced_field_key( string $field_key ): string {
		$field_key = WC_ShipStation_Integration::$order_meta_keys[ $field_key ] ?? $field_key;

		return self::FIELD_NAMESPACE . '/' . $field_key;
	}

	/**
	 * Get the CSS class field key.
	 *
	 * @param string $field_key The field key to be namespaced.
	 *
	 * @return string The CSS class.
	 */
	public static function get_css_class_field_key( string $field_key ): string {
		$field_key = WC_ShipStation_Integration::$order_meta_keys[ $field_key ] ?? $field_key;

		return self::FIELD_NAMESPACE . '-' . $field_key;
	}

	/**
	 * Check if the cart needs shipping in a safe way.
	 *
	 * @return bool
	 */
	private static function cart_needs_shipping(): bool {
		if ( ! WC()->cart instanceof WC_Cart || ! wc_shipping_enabled() ) {
			return false;
		}

		foreach ( WC()->cart->get_cart_contents() as $values ) {
			if ( ! isset( $values['data'] ) || ! method_exists( $values['data'], 'needs_shipping' ) ) {
				continue;
			}

			if ( $values['data']->needs_shipping() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if the page contains checkout.
	 *
	 * @return bool
	 */
	private static function is_checkout(): bool {
		return self::is_block_checkout() || self::is_classic_checkout();
	}

	/**
	 * Check if the page contains block checkout.
	 *
	 * @return bool
	 */
	private static function is_block_checkout(): bool {
		if (
			! function_exists( 'is_checkout' )
			|| ! function_exists( 'has_block' )
		) {
			return false;
		}
		return is_checkout() && has_block( 'woocommerce/checkout' );
	}

	/**
	 * Check if page contains classic checkout.
	 *
	 * @return bool
	 */
	private static function is_classic_checkout(): bool {
		if (
			! function_exists( 'is_checkout' )
			|| ! function_exists( 'wc_post_content_has_shortcode' )
			|| ! function_exists( 'has_block' )
		) {
			return false;
		}
		return is_checkout() && ( wc_post_content_has_shortcode( 'woocommerce_checkout' ) || has_block( 'woocommerce/classic-shortcode' ) );
	}

	/**
	 * AJAX handler for saving field value to session.
	 *
	 * @return void
	 */
	public function ajax_save_field_value(): void {
		check_ajax_referer( 'update-order-review', 'security' );

		if ( ! isset( $_POST['field_id'] ) || ! isset( $_POST['value'] ) ) {
			wp_send_json_error( 'Missing required parameters' );
		}

		$field_id = sanitize_text_field( wp_unslash( $_POST['field_id'] ) );

		// Only allow saving specific fields.
		$allowed_fields = array(
			WC_ShipStation_Integration::$order_meta_keys['is_gift'],
			WC_ShipStation_Integration::$order_meta_keys['gift_message'],
		);

		if ( ! in_array( $field_id, $allowed_fields, true ) ) {
			wp_send_json_error( 'Invalid field ID' );
		}

		$value = wp_unslash( $_POST['value'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --- Input is sanitized later.

		// Apply appropriate sanitization based on the field type.
		if ( WC_ShipStation_Integration::$order_meta_keys['is_gift'] === $field_id ) {
			$value = sanitize_text_field( $value );
		} elseif ( WC_ShipStation_Integration::$order_meta_keys['gift_message'] === $field_id ) {
			$value = sanitize_textarea_field( $value );
		}

		self::session_set( $field_id, $value );
		wp_send_json_success();
	}

	/**
	 * Sanitize the gift message field value.
	 *
	 * @param string $gift_message Gift message.
	 *
	 * @return string Sanitized gift message.
	 */
	private function sanitize_gift_message( string $gift_message ): string {
		return sanitize_textarea_field( wp_encode_emoji( $gift_message ) );
	}

	/**
	 * AJAX handler for loading field values from session.
	 *
	 * @return void
	 */
	public function ajax_load_field_values(): void {
		check_ajax_referer( 'update-order-review', 'security' );

		$is_gift_field_id      = WC_ShipStation_Integration::$order_meta_keys['is_gift'];
		$gift_message_field_id = WC_ShipStation_Integration::$order_meta_keys['gift_message'];

		$field_values = array(
			$is_gift_field_id      => self::session_get( $is_gift_field_id ),
			$gift_message_field_id => self::session_get( $gift_message_field_id ),
		);

		// Filter out null values.
		$field_values = array_filter(
			$field_values,
			function ( $value ) {
				return null !== $value;
			}
		);

		wp_send_json_success( $field_values );
	}

	/**
	 * Display gift fields in order confirmation email beneath customer details.
	 *
	 * @param WC_Order $order         Order object.
	 * @param bool     $sent_to_admin Whether the email is being sent to admin.
	 * @param bool     $plain_text    Whether the email is plain text.
	 *
	 * @return void
	 */
	public function display_gift_fields_in_order_email( $order, $sent_to_admin = false, $plain_text = false ): void {
		if ( ! WC_ShipStation_Integration::$gift_enabled || ! $order instanceof WC_Order ) {
			return;
		}

		// Block Checkout (Store API) already includes gift field data in emails, so skip.
		if ( 'store-api' === $order->get_created_via() ) {
			return;
		}

		// Get gift data from the order.
		$gift_data = $this->get_order_gift_data( $order );

		// Only proceed if this is a gift order and we have gift field data.
		if ( ! $gift_data['is_gift'] || empty( $gift_data['field_data'] ) ) {
			return;
		}

		if ( true === $plain_text ) {
			echo "\n" . esc_html( wc_strtoupper( __( 'Additional information', 'woocommerce-shipstation-integration' ) ) ) . "\n\n";
			foreach ( $gift_data['field_data'] as $field ) {
				printf( "%s: %s\n", wp_kses_post( $field['label'] ), wp_kses_post( $field['value'] ) );
			}
		} else {
			echo '<h2>' . esc_html__( 'Additional information', 'woocommerce-shipstation-integration' ) . '</h2>';
			echo '<ul class="additional-fields" style="margin-bottom: 40px;">';
			foreach ( $gift_data['field_data'] as $field ) {
				printf( '<li><strong>%s</strong>: %s</li>', wp_kses_post( $field['label'] ), wp_kses_post( $field['value'] ) );
			}
			echo '</ul>';
		}
	}
}

new Checkout();
