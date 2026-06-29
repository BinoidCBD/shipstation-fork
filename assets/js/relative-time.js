/**
 * Frontend time formatting for the ShipStation settings tab.
 *
 * The server surfaces only a machine instant — an ISO-8601 `datetime` attribute
 * (UTC) plus a localized absolute time as the no-JS fallback text. This script
 * renders the human string in the VIEWER's own locale and timezone, so each
 * admin sees their local time and the value ticks without a page reload. No
 * translated strings are passed from PHP; the browser's Intl handles wording.
 *
 *   <time class="shipstation-rel-time" datetime="…">  → "3 minutes ago" (+ absolute on hover)
 *   <time class="shipstation-countdown" data-prefix="auto-deletes in" datetime="…">
 *                                                      → "auto-deletes in 2 days"
 */
( function () {
	'use strict';

	var locale = navigator.language || 'en';

	var rtf = ( 'Intl' in window && Intl.RelativeTimeFormat )
		? new Intl.RelativeTimeFormat( locale, { numeric: 'auto' } )
		: null;

	// Largest sensible unit for a second-delta, as [seconds-per-unit, unit].
	var UNITS = [
		[ 31536000, 'year' ],
		[ 2592000, 'month' ],
		[ 86400, 'day' ],
		[ 3600, 'hour' ],
		[ 60, 'minute' ],
		[ 1, 'second' ]
	];

	// Pick the unit and rounded magnitude for an absolute second count.
	function pick( seconds ) {
		for ( var i = 0; i < UNITS.length; i++ ) {
			if ( seconds >= UNITS[ i ][ 0 ] || 'second' === UNITS[ i ][ 1 ] ) {
				return { value: Math.round( seconds / UNITS[ i ][ 0 ] ), unit: UNITS[ i ][ 1 ] };
			}
		}
		return { value: 0, unit: 'second' };
	}

	// Signed delta (negative = past) → "3 minutes ago" / "in 2 days".
	function relative( deltaSeconds ) {
		if ( ! rtf ) {
			return null;
		}
		var u = pick( Math.abs( deltaSeconds ) );
		return rtf.format( deltaSeconds < 0 ? -u.value : u.value, u.unit );
	}

	// Positive second count → bare duration "2 days" (for the countdown prefix).
	function duration( seconds ) {
		var u = pick( seconds );
		try {
			return new Intl.NumberFormat( locale, { style: 'unit', unit: u.unit, unitDisplay: 'long' } ).format( u.value );
		} catch ( e ) {
			return u.value + ' ' + u.unit + ( 1 === u.value ? '' : 's' );
		}
	}

	function absolute( date ) {
		try {
			return date.toLocaleString( locale );
		} catch ( e ) {
			return date.toString();
		}
	}

	function render() {
		var now = Date.now();

		document.querySelectorAll( 'time.shipstation-rel-time[datetime]' ).forEach( function ( el ) {
			var date = new Date( el.getAttribute( 'datetime' ) );
			if ( isNaN( date.getTime() ) ) {
				return;
			}
			var rel = relative( Math.round( ( date.getTime() - now ) / 1000 ) );
			if ( null !== rel ) {
				el.textContent = rel;
			}
			el.title = absolute( date );
		} );

		document.querySelectorAll( 'time.shipstation-countdown[datetime]' ).forEach( function ( el ) {
			var date = new Date( el.getAttribute( 'datetime' ) );
			if ( isNaN( date.getTime() ) ) {
				return;
			}
			var remaining = Math.round( ( date.getTime() - now ) / 1000 );
			if ( remaining <= 0 ) {
				return; // Past due — leave the server fallback ("auto-deletes on …") in place.
			}
			var prefix = el.getAttribute( 'data-prefix' ) || '';
			var text   = duration( remaining );
			el.textContent = '' !== prefix ? prefix + ' ' + text : text;
			el.title = absolute( date );
		} );
	}

	function init() {
		render();
		// Keep the relative strings fresh without a reload; cheap, one tab.
		window.setInterval( render, 60000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
