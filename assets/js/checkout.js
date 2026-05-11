/**
 * ShipStation Checkout JS
 *
 * Handles persisting gift field values on the classic checkout using session storage.
 */
( function () {
	'use strict';

	// Get field IDs from localized script.
	const field_ids = wc_shipstation_checkout_params.field_ids || {};

	const is_gift_field_id      = field_ids.is_gift;
	const gift_message_field_id = field_ids.gift_message;

	const is_gift_field      = document.getElementById( is_gift_field_id );
	const gift_message_field = document.getElementById( gift_message_field_id );

	if ( ! is_gift_field || ! gift_message_field ) {
		return;
	}


	/**
	 * Save field value to session via AJAX.
	 *
	 * @param {string} field_id The field ID.
	 * @param {*} value The field value.
	 */
	function save_field_value_to_session( field_id, value ) {
		const data = new FormData();
		data.append( 'action', 'shipstation_save_field_value' );
		data.append( 'field_id', field_id );
		data.append( 'value', value );
		data.append( 'security', wc_checkout_params.update_order_review_nonce );

		fetch( wc_checkout_params.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} ).catch( error => {
			console.error( 'Error saving field value to session:', error );
		} );
	}

	/**
	 * Load field values from session via AJAX.
	 */
	function load_field_values_from_session() {
		const data = new FormData();
		data.append( 'action', 'shipstation_load_field_values' );
		data.append( 'security', wc_checkout_params.update_order_review_nonce );

		fetch( wc_checkout_params.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} )
			.then( response => response.json() )
			.then( data => {
				if ( data.success ) {
					// Set field values from session.
					if ( data.data.hasOwnProperty( is_gift_field_id ) ) {
						is_gift_field.checked = '1' === data.data[ is_gift_field_id ];
					}

					if ( data.data.hasOwnProperty( gift_message_field_id ) ) {
						gift_message_field.value = data.data[ gift_message_field_id ];
					}
				}
			} )
			.catch( error => {
				console.error( 'Error loading field values from session:', error );
			} );
	}

	// Add event listeners.
	is_gift_field.addEventListener( 'change', function () {
		save_field_value_to_session( is_gift_field_id, this.checked ? '1' : '0' );
	} );

	gift_message_field.addEventListener( 'change', function () {
		save_field_value_to_session( gift_message_field_id, this.value );
	} );

	let timeout;
	gift_message_field.addEventListener( 'input', function () {
		// Debounce for performance.
		clearTimeout( timeout );
		timeout = setTimeout( () => {
			save_field_value_to_session( gift_message_field_id, this.value );
		}, 500 );
	} );

	// Load field values from session when page loads.
	document.addEventListener( 'DOMContentLoaded', load_field_values_from_session );
} )();
