/* global wc_shipstation_auth_params */

( function () {
	'use strict';

	const $ = ( selector, scope ) => ( scope || document ).querySelector( selector );

	const AuthDisplay = {
		// Transport checkbox id (WC namespaces the field as woocommerce_<plugin>_<key>).
		transportToggleId: 'woocommerce_shipstation_wpcom_transport_enabled',

		// Set while a connect/disconnect link navigation is in flight, so the
		// capture-phase beforeunload guard below suppresses the settings form's
		// "changes may not be saved" prompt for that intentional navigation.
		navigatingViaConnLink: false,

		// The transport checkbox's saved (server-rendered) value, captured on load
		// so the pre-save warning can tell when the box is toggled away from it.
		savedTransport: false,

		init: function () {
			this.bindEvents();
			// Stamp the connection action links with the saved toggle value on load,
			// so a click persists the current state even without a prior toggle.
			const toggle = $( '#' + this.transportToggleId );
			if ( toggle ) {
				this.savedTransport = toggle.checked;
				this.syncTransportLinks( toggle.checked );
			}
			// Capture-phase guard: when leaving via one of our connect/disconnect
			// links, stop the event before WooCommerce's beforeunload handler can
			// arm the "changes may not be saved" prompt. Registered in the capture
			// phase so it runs first regardless of how/when WC bound its listener.
			window.addEventListener( 'beforeunload', function ( event ) {
				if ( AuthDisplay.navigatingViaConnLink ) {
					event.stopImmediatePropagation();
				}
			}, true );
		},

		// Swap the Store URL between the WordPress.com proxy and the direct site
		// URL to track the (possibly unsaved) transport checkbox — the merchant
		// copies the right value without saving first. The proxy URL is only known
		// while connected; when empty we fall back to the direct URL.
		swapStoreUrl: function ( checked ) {
			const storeUrl = $( '#shipstation-conn-url' );
			if ( ! storeUrl ) {
				return;
			}
			const params = window.wc_shipstation_auth_params || {};
			const proxy  = params.conn_url_proxy || '';
			const direct = params.conn_url_direct || '';
			storeUrl.value = ( checked && proxy ) ? proxy : direct;
		},

		// Pre-save live warning: while the transport checkbox differs from its saved
		// value, reveal the inline warning (with the direction-specific copy) and
		// hide the settled-state verdict banner so the two never contradict; revert
		// the checkbox and the verdict banner comes back. Purely a view of the
		// unsaved form field — nothing is persisted until the merchant saves.
		updateTransportWarning: function ( checked ) {
			const warn = $( '.shipstation-transport-warning' );
			if ( ! warn ) {
				return;
			}
			const verdict = $( '.shipstation-connection-banner:not(.shipstation-transport-warning)' );
			const dirty   = checked !== this.savedTransport;
			// An empty message means that direction needs no warning (e.g. turning
			// the transport off while a direct connection is already active), so we
			// leave the settled-state verdict banner in place.
			const message = checked ? warn.dataset.enableMsg : warn.dataset.disableMsg;

			if ( dirty && message ) {
				const body = $( '.shipstation-connection-banner__body', warn );
				if ( body ) {
					body.textContent = message;
				}
				warn.hidden = false;
				if ( verdict ) {
					verdict.hidden = true;
				}
				// Open the Credentials fold so "The values below already reflect this
				// change" points at something the merchant can actually see and copy.
				const credentials = document.getElementById( 'shipstation-credentials-section' );
				if ( credentials ) {
					credentials.open = true;
				}
			} else {
				warn.hidden = true;
				if ( verdict ) {
					verdict.hidden = false;
				}
			}
		},

		// Keep the WordPress.com connect/disconnect action links carrying the
		// current toggle value, so performing one persists the transport setting to
		// match (the controls render regardless of the saved toggle, so the merchant
		// can act on the connection without saving the form first).
		syncTransportLinks: function ( checked ) {
			const section = $( '.shipstation-wpcom-connection' );
			if ( ! section ) {
				return;
			}
			const value = checked ? '1' : '0';
			section.querySelectorAll( 'a[href*="admin-post.php"]' ).forEach( function ( link ) {
				try {
					const url = new URL( link.href, window.location.origin );
					url.searchParams.set( 'wpcom_transport', value );
					link.href = url.toString();
				} catch ( e ) {} // eslint-disable-line no-empty -- Skip un-parseable hrefs.
			} );
		},

		// Drop the WooCommerce settings form's unsaved-changes guard (a jQuery
		// beforeunload handler) so the intentional connect/disconnect navigation
		// does not trigger "Changes you made may not be saved." The toggle is
		// persisted server-side via the link's wpcom_transport param.
		allowSettingsNavigation: function () {
			this.navigatingViaConnLink = true;
			// Belt-and-suspenders for property/jQuery-style bindings; the capture
			// guard in init() handles addEventListener-bound handlers.
			window.onbeforeunload = null;
			if ( window.jQuery ) {
				window.jQuery( window ).off( 'beforeunload' );
			}
		},

		bindEvents: function () {
			// Mirror the (possibly unsaved) transport checkbox into the Store URL
			// and the connect/disconnect action links, without a save round-trip.
			document.addEventListener( 'change', function ( event ) {
				if ( ! event.target || AuthDisplay.transportToggleId !== event.target.id ) {
					return;
				}
				AuthDisplay.swapStoreUrl( event.target.checked );
				AuthDisplay.syncTransportLinks( event.target.checked );
				AuthDisplay.updateTransportWarning( event.target.checked );
			} );

			// Delegated clicks.
			document.addEventListener( 'click', function ( event ) {
				const copyBtn       = event.target.closest( '.shipstation-copy-btn' );
				const toggleBtn     = event.target.closest( '.shipstation-toggle-visibility' );
				const dangerDisconnectBtn = event.target.closest( '.shipstation-wpcom-disconnect-force' );
				// Connect/disconnect links navigate to admin-post.php and persist the
				// toggle server-side: the in-strip controls AND the banner's "Reconnect
				// WordPress.com" link (which sits outside the strip) both drop the
				// unsaved-changes guard for that intentional navigation.
				const connActionLink      = event.target.closest( '.shipstation-wpcom-connection a[href*="admin-post.php"], a.shipstation-banner-action[href*="admin-post.php"]' );
				const bannerActionBtn     = event.target.closest( 'button.shipstation-banner-action' );

				// Inline verdict banner action (SHIPSTN-142): deep-link to the fold /
				// control that fixes the flagged condition. Only the <button> variants
				// carry data-target ('credentials' / 'transport_toggle'); the
				// 'wpcom_connect' action is a real <a href> handled by connActionLink
				// above (or plain navigation), so it is intentionally not caught here.
				if ( bannerActionBtn ) {
					event.preventDefault();
					AuthDisplay.handleBannerAction( bannerActionBtn.dataset ? bannerActionBtn.dataset.target : '' );
					return;
				}

				// "Dangerously disconnect" bypasses the guard that waits for a
				// direct ShipStation connection, so it can cut ShipStation off
				// until the merchant re-points its Store URL. Gate it behind a
				// strong native confirm (SHIPSTN-142). The ordinary "Disconnect"
				// button only records a reversible intent, so it needs no confirm.
				if ( dangerDisconnectBtn ) {
					if ( ! window.confirm( wc_shipstation_auth_params.disconnect_force_confirm ) ) {
						event.preventDefault();
						return;
					}
					AuthDisplay.allowSettingsNavigation();
					return;
				}

				// The connect/disconnect links intentionally navigate to
				// admin-post.php (and persist the toggle server-side), so drop the
				// settings form's "changes may not be saved" guard for them.
				if ( connActionLink ) {
					AuthDisplay.allowSettingsNavigation();
					return;
				}

				const inlineGenBtn = event.target.closest( '.shipstation-connection-generate' );
				if ( inlineGenBtn ) {
					event.preventDefault();
					AuthDisplay.generateInlineKeys( inlineGenBtn );
					return;
				}

				if ( copyBtn ) {
					AuthDisplay.copyToClipboard( event, copyBtn );
					return;
				}

				if ( toggleBtn ) {
					AuthDisplay.toggleVisibility( event, toggleBtn );
				}
			} );
		},

		copyToClipboard: function ( event, buttonEl ) {
			event.preventDefault();

			const dataset = buttonEl && buttonEl.dataset ? buttonEl.dataset : {};

			// Two copy sources share this one handler: credential fields point at a
			// readonly <input> via data-target and copy its value; the route-chain
			// icons carry the URL inline via data-copy-text (there is no field to
			// reference). data-target wins when both are present.
			if ( dataset.target ) {
				const input = document.getElementById( dataset.target );
				if ( ! input ) {
					return;
				}

				AuthDisplay.copyValue( input ).then( function ( copied ) {
					if ( copied ) {
						AuthDisplay.showCopyFeedback( buttonEl );
					}
				} );
				return;
			}

			if ( 'copyText' in dataset ) {
				AuthDisplay.copyText( dataset.copyText ).then( function ( copied ) {
					if ( copied ) {
						AuthDisplay.showCopyFeedback( buttonEl );
					}
				} );
			}
		},

		// Copy a literal string to the clipboard (the route-chain icons hold their
		// URL in a data attribute rather than a form field). Mirrors copyValue():
		// the async Clipboard API when available in a secure context, else a
		// temporary off-screen <textarea> + execCommand fallback so plain-HTTP
		// admin screens still copy.
		copyText: function ( text ) {
			const value = text || '';
			if ( window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText ) {
				return navigator.clipboard.writeText( value ).then(
					function () {
						return true;
					},
					function () {
						return AuthDisplay.legacyCopyText( value );
					}
				);
			}

			return Promise.resolve( AuthDisplay.legacyCopyText( value ) );
		},

		legacyCopyText: function ( value ) {
			const textarea = document.createElement( 'textarea' );
			textarea.value = value;
			// Keep it out of the layout and off-screen so focusing/selecting it
			// does not scroll the page or flash visibly.
			textarea.setAttribute( 'readonly', '' );
			textarea.style.position = 'fixed';
			textarea.style.top = '-9999px';
			textarea.style.left = '-9999px';
			document.body.appendChild( textarea );
			textarea.focus();
			textarea.select();

			let copied = false;
			try {
				copied = document.execCommand( 'copy' );
			} catch ( e ) {
				copied = false;
			}

			document.body.removeChild( textarea );
			return copied;
		},

		// Copy a field's value to the clipboard, resolving to true only on an
		// actual success. The async Clipboard API is used when available, but it
		// is undefined outside a secure context (plain HTTP on any host other
		// than localhost) and can also reject even when present, so both cases
		// fall back to the legacy execCommand path.
		copyValue: function ( input ) {
			if ( window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText ) {
				return navigator.clipboard.writeText( input.value ).then(
					function () {
						return true;
					},
					function () {
						return AuthDisplay.legacyCopy( input );
					}
				);
			}

			return Promise.resolve( AuthDisplay.legacyCopy( input ) );
		},

		// Legacy clipboard fallback using a temporary selection + execCommand.
		// Password fields are switched to text for the copy so the real value is
		// captured rather than the masked display, then restored afterwards.
		legacyCopy: function ( input ) {
			const originalType = input.getAttribute( 'type' );
			if ( 'password' === originalType ) {
				input.setAttribute( 'type', 'text' );
			}

			input.focus();
			input.select();
			if ( input.setSelectionRange ) {
				input.setSelectionRange( 0, input.value.length );
			}

			let copied = false;
			try {
				copied = document.execCommand( 'copy' );
			} catch ( e ) {
				copied = false;
			}

			if ( 'password' === originalType ) {
				input.setAttribute( 'type', 'password' );
			}

			return copied;
		},

		showCopyFeedback: function ( buttonEl ) {
			const iconEl = $( '.dashicons', buttonEl );
			const originalIcon = iconEl ? iconEl.getAttribute( 'class' ) : '';
			const originalTitle = buttonEl.getAttribute( 'title' ) || '';

			if ( iconEl ) {
				iconEl.setAttribute( 'class', 'dashicons dashicons-yes-alt' );
			}
			buttonEl.setAttribute( 'title', wc_shipstation_auth_params.copy_text );
			// SCSS styles the success state under `.is-copied` (the WP/JS naming
			// convention); keep the class in sync so the confirmation renders.
			buttonEl.classList.add( 'is-copied' );

			window.setTimeout( function () {
				if ( iconEl && originalIcon ) {
					iconEl.setAttribute( 'class', originalIcon );
				}
				buttonEl.setAttribute( 'title', originalTitle );
				buttonEl.classList.remove( 'is-copied' );
			}, 2000 );
		},

		toggleVisibility: function ( event, buttonEl ) {
			event.preventDefault();

			const targetId = buttonEl && buttonEl.dataset ? buttonEl.dataset.target : '';
			if ( ! targetId ) {
				return;
			}

			const input = document.getElementById( targetId );
			const icon  = $( '.dashicons', buttonEl );
			if ( ! input ) {
				return;
			}

			if ( input.getAttribute( 'type' ) === 'password' ) {
				input.setAttribute( 'type', 'text' );
				if ( icon ) {
					icon.classList.remove( 'dashicons-visibility' );
					icon.classList.add( 'dashicons-hidden' );
				}
				buttonEl.setAttribute( 'title', wc_shipstation_auth_params.hide_text );
			} else {
				input.setAttribute( 'type', 'password' );
				if ( icon ) {
					icon.classList.remove( 'dashicons-hidden' );
					icon.classList.add( 'dashicons-visibility' );
				}
				buttonEl.setAttribute( 'title', wc_shipstation_auth_params.show_text );
			}
		},

		// Inline credentials section (SHIPSTN-142): mint a fresh pair over the
		// shared generate endpoint and reveal it in place. Rotating an existing
		// pair confirms first (it does not delete the old keys, but the merchant
		// must repaste); the first-time / recovery states skip the confirm since
		// there are no working keys to disrupt.
		generateInlineKeys: function ( button ) {
			if (
				'reference' === ( button.dataset ? button.dataset.credState : '' ) &&
				! window.confirm( wc_shipstation_auth_params.confirm_text )
			) {
				return;
			}

			button.disabled = true;
			AuthDisplay.setConnectionError( '' );

			const body = new FormData();
			body.append( 'action', 'shipstation_generate_new_keys' );
			body.append( 'nonce', wc_shipstation_auth_params.nonce );

			fetch( wc_shipstation_auth_params.ajax_url, {
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
					if ( ! response || ! response.success || ! response.data ) {
						AuthDisplay.setConnectionError(
							( response && response.data && response.data.message ) || wc_shipstation_auth_params.error_text
						);
						return;
					}

					const data = response.data;
					AuthDisplay.fillInput( 'shipstation-conn-consumer-key', data.consumer_key );
					AuthDisplay.fillInput( 'shipstation-conn-consumer-secret', data.consumer_secret );
					AuthDisplay.fillInput( 'shipstation-conn-auth-key', data.auth_key );
					AuthDisplay.fillInput( 'shipstation-conn-url', data.site_url );

					// Reveal the freshly minted key + secret, surface the "copy now"
					// warning and the success notice, and drop the "last key ends
					// in …" hint.
					AuthDisplay.showEl( '.shipstation-connection-key-field' );
					AuthDisplay.showEl( '.shipstation-connection-secret-field' );
					AuthDisplay.showEl( '.shipstation-connection-info' );
					AuthDisplay.showEl( '.shipstation-connection-success' );
					AuthDisplay.hideEl( '.shipstation-connection-truncated' );

					// Refresh the API Keys list in place so the new pair shows up
					// without a reload (which would discard the once-only consumer
					// key the merchant still needs to copy), and reveal the section.
					AuthDisplay.refreshKeyList( data.key_list_html );

					// After the first generate the section is no longer in the
					// first-time/recovery state; future clicks are rotations.
					button.dataset.credState = 'reference';
				} )
				.catch( function () {
					AuthDisplay.setConnectionError( wc_shipstation_auth_params.error_text );
				} )
				.then( function () {
					button.disabled = false;
				} );
		},

		fillInput: function ( id, value ) {
			const input = document.getElementById( id );
			if ( input ) {
				input.value = value || '';
			}
		},

		// Inline verdict banner action (SHIPSTN-142): take the merchant to the
		// fixer the banner points at. 'credentials' opens that <details> fold and
		// focuses it; 'transport_toggle' scrolls to and focuses the (always-visible)
		// transport checkbox. Both smooth-scroll the target into view.
		handleBannerAction: function ( target ) {
			if ( 'credentials' === target ) {
				const fold = document.getElementById( 'shipstation-credentials-section' );
				if ( ! fold ) {
					return;
				}
				fold.open = true;
				AuthDisplay.scrollIntoView( fold );
				// Move focus to the fold so keyboard users land on it; the <summary>
				// is the focusable handle.
				const summary = fold.querySelector( 'summary' );
				AuthDisplay.focusEl( summary || fold );
				return;
			}

			if ( 'transport_toggle' === target ) {
				const toggle = document.getElementById( AuthDisplay.transportToggleId );
				if ( ! toggle ) {
					return;
				}
				AuthDisplay.scrollIntoView( toggle );
				AuthDisplay.focusEl( toggle );
			}
		},

		// Smooth-scroll an element into view, guarding older browsers that lack the
		// options form of scrollIntoView().
		scrollIntoView: function ( el ) {
			try {
				el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			} catch ( e ) {
				el.scrollIntoView();
			}
		},

		// Focus an element without throwing if it is not natively focusable: a
		// <details>/<summary> is focusable, but fall back to a temporary tabindex
		// for anything that is not.
		focusEl: function ( el ) {
			if ( ! el ) {
				return;
			}
			if ( null === el.getAttribute( 'tabindex' ) && 'SUMMARY' !== el.tagName ) {
				el.setAttribute( 'tabindex', '-1' );
			}
			el.focus( { preventScroll: true } );
		},

		// Swap the API Keys list with freshly server-rendered markup (delegated
		// click handlers keep working) and open the section so the merchant sees
		// the new key. No-op when the handler did not return the list.
		refreshKeyList: function ( html ) {
			if ( ! html ) {
				return;
			}
			const list = document.getElementById( 'shipstation-api-key-list' );
			if ( list ) {
				list.innerHTML = html;
			}
			const section = document.getElementById( 'shipstation-api-keys-section' );
			if ( section ) {
				section.open = true;
			}
		},

		showEl: function ( selector ) {
			const el = $( selector );
			if ( el ) {
				el.style.display = '';
			}
		},

		hideEl: function ( selector ) {
			const el = $( selector );
			if ( el ) {
				el.style.display = 'none';
			}
		},

		setConnectionError: function ( message ) {
			const box = $( '.shipstation-connection-error' );
			if ( ! box ) {
				return;
			}
			const p = box.querySelector( 'p' );
			if ( p ) {
				p.textContent = message;
			}
			box.style.display = message ? 'block' : 'none';
		},
	};

	// DOM ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			AuthDisplay.init();
		} );
	} else {
		AuthDisplay.init();
	}
} )();
