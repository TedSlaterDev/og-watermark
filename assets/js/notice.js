/**
 * OG Watermark — backup-exposure admin notice helper.
 *
 * Two jobs, both against the shared secured AJAX layer (manage_options + nonce):
 *   1. Persist a dismissal when the operator clicks the core "X" so the warning
 *      stays gone across reloads (WordPress's is-dismissible only hides it for the
 *      current page load).
 *   2. Re-measure exposure on demand ("Re-check now") after the operator adds a
 *      server-level deny rule, and remove the notice if it is now protected.
 *
 * Localized config (window.ogwmNotice):
 *   { ajaxUrl, nonce, dismissAction, recheckAction, i18n: { checking, stillOpen } }
 */
( function () {
	'use strict';

	var cfg = window.ogwmNotice;
	if ( ! cfg || ! cfg.ajaxUrl || ! cfg.nonce ) {
		return;
	}

	var SELECTOR = '[data-ogwm-notice="backup-exposure"]';

	/** POST one action to admin-ajax; hand the parsed JSON (or null) to onDone. */
	function post( action, onDone ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: body
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) { if ( onDone ) { onDone( j ); } } )
			.catch( function () { if ( onDone ) { onDone( null ); } } );
	}

	document.addEventListener( 'click', function ( event ) {
		var notice = event.target.closest( SELECTOR );
		if ( ! notice ) {
			return;
		}

		// The core dismiss "X" — persist the dismissal (fire and forget); WordPress
		// removes the notice from the DOM itself.
		if ( event.target.closest( '.notice-dismiss' ) ) {
			post( cfg.dismissAction );
			return;
		}

		// The "Re-check now" button — re-probe and remove the notice if protected.
		var recheck = event.target.closest( '.ogwm-recheck' );
		if ( recheck ) {
			event.preventDefault();

			// Capture the pristine call-to-action label ONCE, so repeated clicks never
			// lose it (a per-click capture would otherwise latch a transient label).
			if ( ! recheck.dataset.ogwmLabel ) {
				recheck.dataset.ogwmLabel = recheck.textContent;
			}
			var label = recheck.dataset.ogwmLabel;

			recheck.disabled = true;
			recheck.textContent = cfg.i18n && cfg.i18n.checking ? cfg.i18n.checking : 'Re-checking…';

			post( cfg.recheckAction, function ( res ) {
				if ( res && res.success && res.data && ! res.data.show ) {
					// Confirmed protected — drop the notice.
					if ( notice.parentNode ) {
						notice.parentNode.removeChild( notice );
					}
					return;
				}

				recheck.disabled = false;
				if ( res && res.success ) {
					// The probe ran but the folder is still reachable.
					recheck.textContent = cfg.i18n && cfg.i18n.stillOpen ? cfg.i18n.stillOpen : label;
				} else {
					// Transport error or a rejected request — invite another try.
					recheck.textContent = label;
				}
			} );
		}
	} );
}() );
