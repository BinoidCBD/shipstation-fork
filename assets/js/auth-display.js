/* global wc_shipstation_auth_params */

( function () {
	'use strict';

	const $ = ( selector, scope ) => ( scope || document ).querySelector( selector );

	const AuthDisplay = {
		requestMade: false, // Track if request has been made to avoid unnecessary requests.

		init: function () {
			this.bindEvents();
		},

		bindEvents: function () {
			// Delegated clicks.
			document.addEventListener( 'click', function ( event ) {
				const openBtn   = event.target.closest( '#shipstation-view-auth' );
				const closeBtn  = event.target.closest( '.shipstation-modal-close' );
				const backdrop  = event.target.closest( '.shipstation-modal-backdrop' );
				const copyBtn   = event.target.closest( '.shipstation-copy-btn' );
				const toggleBtn = event.target.closest( '.shipstation-toggle-visibility' );
				const genBtn    = event.target.closest( '#shipstation-generate-new-keys' );

				if ( openBtn ) {
					AuthDisplay.showModal( event );
					return;
				}

				if ( closeBtn || backdrop ) {
					AuthDisplay.hideModal( event );
					return;
				}

				if ( copyBtn ) {
					AuthDisplay.copyToClipboard( event, copyBtn );
					return;
				}

				if ( toggleBtn ) {
					AuthDisplay.toggleVisibility( event, toggleBtn );
					return;
				}

				if ( genBtn ) {
					AuthDisplay.generateNewKeys( event );
				}
			} );
		},

		showModal: function ( event ) {
			event.preventDefault();

			const modal = $( '#shipstation-auth-modal' );
			if ( ! modal ) {
				return;
			}

			modal.style.display = 'block';

			// Focus first close button for accessibility.
			const closeBtn = $( '.shipstation-modal-close', modal );
			if ( closeBtn ) {
				closeBtn.focus();
			}

			// Only make request if not already made.
			if ( ! AuthDisplay.requestMade ) {
				const clsOverlay = $( '.shipstation-loading-overlay' );
				if ( clsOverlay ) {
					clsOverlay.style.display = 'flex';
				}
				AuthDisplay.loadAuthData();
			}
		},

		hideModal: function ( event ) {
			// Allow only if click is on backdrop or the explicit close button.
			const target = event.target;
			if (
				! target.classList.contains( 'shipstation-modal-close' ) &&
				! target.classList.contains( 'shipstation-modal-backdrop' )
			) {
				return;
			}

			const modal = $( '#shipstation-auth-modal' );
			if ( modal ) {
				modal.style.display = 'none';
			}
		},

		loadAuthData: function () {
			const body = new FormData();
			body.append( 'action', 'shipstation_get_auth_data' );
			body.append( 'nonce', wc_shipstation_auth_params.nonce );

			fetch( wc_shipstation_auth_params.ajax_url, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( response ) {
					if ( response && response.success ) {
						AuthDisplay.populateModal( response.data );
					} else {
						AuthDisplay.showError( ( response && response.data && response.data.message ) || wc_shipstation_auth_params.error_text );
					}
				} )
				.catch( function () {
					AuthDisplay.showError( wc_shipstation_auth_params.error_text );
				} );
		},

		populateModal: function ( data ) {
			// Hide any loading overlays (both id and class variants, mirroring original).
			const clsOverlay = $( '.shipstation-loading-overlay' );
			if ( clsOverlay ) {
				clsOverlay.style.display = 'none';
			}

			// Mark that request has been made.
			AuthDisplay.requestMade = true;

			const firstView = $( '#shipstation-first-view' );
			const afterView = $( '#shipstation-after-view' );

			// If consumer_secret is missing, show the "after" view, otherwise the first-time view.
			if ( ! data.consumer_secret ) {
				if ( firstView ) {
					firstView.style.display = 'none';
				}
				if ( afterView ) {
					afterView.style.display = 'block';
				}
			} else {
				if ( afterView ) {
					afterView.style.display = 'none';
				}
				if ( firstView ) {
					firstView.style.display = 'block';
				}
				const consumerKey    = $( '#shipstation-consumer-key' );
				const consumerSecret = $( '#shipstation-consumer-secret' );
				if ( consumerKey ) {
					consumerKey.value = data.consumer_key || '';
				}
				if ( consumerSecret ) {
					consumerSecret.value = data.consumer_secret || '';
				}
			}

			const authKey = $( '#shipstation-auth-key' );
			const siteUrl = $( '#shipstation-site-url' );

			if ( authKey ) {
				authKey.value = data.auth_key || '';
			}
			if ( siteUrl ) {
				siteUrl.value = data.site_url || '';
			}
		},

		showError: function ( message ) {
			// Hide loading overlays if present.
			const clsOverlay = $( '.shipstation-loading-overlay' );
			if ( clsOverlay ) {
				clsOverlay.style.display = 'none';
			}

			const modal = $( '#shipstation-auth-modal' );
			if ( ! modal ) {
				return;
			}
			const body = $( '.shipstation-modal-body', modal );
			if ( ! body ) {
				return;
			}

			if ( ! $( '.shipstation-error-overlay', body ) ) {
				const wrapper = document.createElement( 'div' );
				wrapper.className = 'shipstation-error-overlay';

				const notice = document.createElement( 'div' );
				notice.className = 'shipstation-error notice notice-error';

				const p = document.createElement( 'p' );
				p.textContent = message;

				notice.appendChild( p );
				wrapper.appendChild( notice );
				body.insertBefore( wrapper, body.firstChild );
			}
		},

		copyToClipboard: function ( event, buttonEl ) {
			event.preventDefault();

			const targetId = buttonEl && buttonEl.dataset ? buttonEl.dataset.target : '';
			if ( ! targetId ) {
				return;
			}

			const input = document.getElementById( targetId );
			if ( ! input ) {
				return;
			}

			navigator.clipboard.writeText( input.value ).then( function () {
				AuthDisplay.showCopyFeedback( buttonEl );
			} );
		},

		showCopyFeedback: function ( buttonEl ) {
			const iconEl = $( '.dashicons', buttonEl );
			const originalIcon = iconEl ? iconEl.getAttribute( 'class' ) : '';
			const originalTitle = buttonEl.getAttribute( 'title' ) || '';

			if ( iconEl ) {
				iconEl.setAttribute( 'class', 'dashicons dashicons-yes-alt' );
			}
			buttonEl.setAttribute( 'title', wc_shipstation_auth_params.copy_text );
			buttonEl.classList.add( 'copied' );

			window.setTimeout( function () {
				if ( iconEl && originalIcon ) {
					iconEl.setAttribute( 'class', originalIcon );
				}
				buttonEl.setAttribute( 'title', originalTitle );
				buttonEl.classList.remove( 'copied' );
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

		generateNewKeys: function ( event ) {
			if ( ! window.confirm( wc_shipstation_auth_params.confirm_text ) ) {
				return;
			}

			event.preventDefault();

			const clsOverlay = $( '.shipstation-loading-overlay' );
			if ( clsOverlay ) {
				clsOverlay.style.display = 'flex';
			}

			const body = new FormData();
			body.append( 'action', 'shipstation_generate_new_keys' );
			body.append( 'nonce', wc_shipstation_auth_params.nonce );

			fetch( wc_shipstation_auth_params.ajax_url, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( response ) {
					if ( response && response.success ) {
						AuthDisplay.populateModal( response.data );
					} else {
						AuthDisplay.showError( ( response && response.data && response.data.message ) || wc_shipstation_auth_params.error_text );
					}
				} )
				.catch( function () {
					AuthDisplay.showError( wc_shipstation_auth_params.error_text );
				} );
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
