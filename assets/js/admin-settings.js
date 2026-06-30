( function () {
	'use strict';

	var settings    = window.wcShipStationSettings || {};
	var fieldIds    = Array.isArray( settings.statusFieldIds ) ? settings.statusFieldIds : [];
	var modeFieldId = settings.statusModeFieldId || '';
	var apiMode     = settings.apiMode || 'api';

	function toggleStatusFields( disabled ) {
		fieldIds.forEach( function ( id ) {
			var select = document.getElementById( id );
			if ( ! select ) {
				return;
			}

			// Disabled <select> elements are skipped by the browser on submit, so their
			// values do not arrive in $_POST. update_shipstation_options() pre-fills the
			// missing keys from the stored option before WC's process_admin_options() runs,
			// so the merchant's mappings round-trip across saves while ShipStation owns them.
			select.disabled = disabled;
		} );
	}

	function init() {
		if ( ! modeFieldId || 0 === fieldIds.length ) {
			return;
		}

		var statusModeSelect = document.getElementById( modeFieldId );
		if ( ! statusModeSelect ) {
			return;
		}

		toggleStatusFields( apiMode === statusModeSelect.value );

		statusModeSelect.addEventListener( 'change', function () {
			toggleStatusFields( apiMode === this.value );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

( function ( $ ) {
	'use strict';

	var settings = window.wcShipStationSettings || {};

	function updateExcludedStatuses() {
		var select = document.getElementById( settings.exportStatusesFieldId );
		var span   = document.getElementById( 'shipstation-excluded-statuses' );
		if ( ! select || ! span ) {
			return;
		}
		var excluded = [];
		for ( var i = 0; i < select.options.length; i++ ) {
			if ( ! select.options[ i ].selected ) {
				excluded.push( select.options[ i ].text );
			}
		}
		if ( excluded.length === 0 ) {
			span.innerHTML = settings.allStatusesExported || '';
		} else {
			span.innerHTML = ( settings.excludedStatusesLabel || '' )
				+ ' <strong>' + escapeHtml( excluded.join( ', ' ) ) + '</strong>';
		}
	}

	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function stripWcPrefix( value ) {
		return value.indexOf( 'wc-' ) === 0 ? value.substring( 3 ) : value;
	}

	function getSelectedSlugs( id ) {
		var el = document.getElementById( id );
		if ( ! el || ! el.options ) {
			return [];
		}
		var slugs = [];
		for ( var i = 0; i < el.options.length; i++ ) {
			if ( el.options[ i ].selected ) {
				slugs.push( stripWcPrefix( el.options[ i ].value ) );
			}
		}
		return slugs;
	}

	function isRestMode() {
		var apiModeSelect = document.getElementById( settings.apiModeFieldId );
		if ( ! apiModeSelect ) {
			return false;
		}
		return apiModeSelect.value === ( settings.restApiModeValue || 'REST' );
	}

	function isShipStationOwnedMapping() {
		var statusModeSelect = document.getElementById( settings.statusModeFieldId );
		if ( ! statusModeSelect ) {
			return false;
		}
		return statusModeSelect.value === ( settings.apiMode || 'api' );
	}

	function computeUnmappedLabels() {
		if ( ! isRestMode() ) {
			return [];
		}
		if ( isShipStationOwnedMapping() ) {
			return [];
		}
		var mappingFieldIds = settings.statusFieldIds || [];
		var mappedSlugs     = [];
		mappingFieldIds.forEach( function ( id ) {
			mappedSlugs = mappedSlugs.concat( getSelectedSlugs( id ) );
		} );

		var exportSelect = document.getElementById( settings.exportStatusesFieldId );
		if ( ! exportSelect ) {
			return [];
		}

		var unmappedLabels = [];
		for ( var i = 0; i < exportSelect.options.length; i++ ) {
			var opt = exportSelect.options[ i ];
			if ( ! opt.selected ) {
				continue;
			}
			var slug = stripWcPrefix( opt.value );
			if ( mappedSlugs.indexOf( slug ) === -1 ) {
				unmappedLabels.push( opt.text );
			}
		}
		return unmappedLabels;
	}

	function refreshUnmappedWarning() {
		var span = document.getElementById( settings.unmappedWarningId );
		if ( ! span ) {
			return;
		}
		var unmapped = computeUnmappedLabels();
		if ( unmapped.length === 0 ) {
			span.classList.remove( 'is-visible' );
			span.innerHTML = '';
			return;
		}
		span.innerHTML = ( settings.unmappedWarningPrefix || '' )
			+ ' <strong>' + escapeHtml( unmapped.join( ', ' ) ) + '</strong>. '
			+ ( settings.unmappedWarningSuffix || '' );
		span.classList.add( 'is-visible' );
	}

	function bindUnmappedListeners() {
		// api_mode is a readonly text input written only server-side, so it
		// cannot fire `change` and is intentionally omitted from watchIds.
		var watchIds = [
			settings.exportStatusesFieldId,
			settings.statusModeFieldId,
		].concat( settings.statusFieldIds || [] ).filter( Boolean );

		watchIds.forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( ! el ) {
				return;
			}
			$( el ).on( 'change', refreshUnmappedWarning );
		} );
	}

	function deleteApiKey( buttonEl ) {
		var keyId = buttonEl.getAttribute( 'data-key-id' );
		if ( ! keyId || ! settings.ajaxUrl || ! settings.deleteKeyNonce ) {
			return;
		}

		// Native confirm() on purpose: WC core uses the same pattern for
		// destructive admin actions, and the browser dialog cannot be spoofed.
		if ( ! window.confirm( settings.deleteKeyConfirm || '' ) ) {
			return;
		}

		buttonEl.disabled = true;

		var body = new FormData();
		body.append( 'action', 'shipstation_delete_api_key' );
		body.append( 'nonce', settings.deleteKeyNonce );
		body.append( 'key_id', keyId );

		fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'HTTP ' + res.status );
				}
				return res.json();
			} )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					buttonEl.disabled = false;
					window.alert( ( response && response.data && response.data.message ) || settings.deleteKeyError || '' );
					return;
				}

				var row   = buttonEl.closest( 'tr' );
				var table = buttonEl.closest( 'table' );
				if ( row && row.parentNode ) {
					row.parentNode.removeChild( row );
				}
				// Hide an emptied table outright rather than leaving headers
				// over zero rows; the field disappears on the next page load.
				if ( table && ! table.querySelector( 'tbody tr' ) ) {
					table.style.display = 'none';
				}
			} )
			.catch( function () {
				buttonEl.disabled = false;
				window.alert( settings.deleteKeyError || '' );
			} );
	}

	$( function () {
		$( '#' + settings.exportStatusesFieldId ).on( 'change', updateExcludedStatuses );
		bindUnmappedListeners();
		refreshUnmappedWarning();

		document.addEventListener( 'click', function ( event ) {
			var deleteBtn = event.target.closest( '.shipstation-delete-api-key' );
			if ( deleteBtn ) {
				event.preventDefault();
				deleteApiKey( deleteBtn );
			}
		} );
	} );
} )( jQuery );
