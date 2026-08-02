/**
 * OG Watermark — per-image opt-in interactions for the Media library list column
 * and the attachment-details / media-modal field.
 *
 * Strictly a thin client: it flags / applies / removes ONE attachment via the
 * secured AJAX layer and swaps the returned (server-escaped) badge into place. No
 * compositing, no progress polling, no work happens here — the browser only asks
 * the server to act and reflects the result.
 *
 * Server contract (localized as window.ogwmMedia):
 *   { ajaxUrl, nonce, i18n: { applying, removing, error } }
 * Handlers: ogwm_apply_single, ogwm_remove_single — both return
 *   { status, badge_html, message } on success.
 */
( function () {
	'use strict';

	var cfg = window.ogwmMedia || {};
	if ( ! cfg.ajaxUrl || ! cfg.nonce ) {
		return;
	}

	var i18n = cfg.i18n || {};

	/**
	 * POST one action to admin-ajax and hand the JSON back to the callbacks.
	 */
	function post( action, id, onDone, onFail ) {
		var body = new window.URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'id', String( id ) );

		window
			.fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					onDone( json.data || {} );
				} else {
					onFail( json && json.data ? json.data : {} );
				}
			} )
			.catch( function () {
				onFail( {} );
			} );
	}

	/**
	 * The container for an attachment's controls — either an `.ogwm-col` cell (list
	 * column) or an `.ogwm-field` block (the attachment-details field, the classic
	 * edit screen, and the media-modal control injected by media-frame.js). We swap
	 * the badge inside whichever one holds the clicked button.
	 */
	function containerFor( button ) {
		return button.closest( '.ogwm-col, .ogwm-field' );
	}

	/** Replace the badge inside a container with server-rendered (escaped) HTML. */
	function swapBadge( container, html ) {
		if ( ! container || ! html ) {
			return;
		}
		// The list column has the badge as a direct child; the field wraps it in
		// `.ogwm-field-badge`. Target the badge element directly so either works.
		var holder = container.querySelector( '.ogwm-field-badge' );
		var existing = container.querySelector( '.ogwm-badge' );

		if ( holder ) {
			holder.innerHTML = html;
			// The media-frame control hides an EMPTY holder inline; un-hide now that
			// it has a badge. The pill's geometry is inlined too (keep in sync with
			// media-frame.js buildControl): host admin themes have been seen forcing
			// display/padding onto it, and a stale-cached stylesheet can't be relied
			// on — only the tone COLORS are left to the stylesheet classes.
			holder.style.display = '';
			var pill = holder.querySelector( '.ogwm-badge' );
			if ( pill ) {
				var geom = {
					display: 'inline-flex',
					alignItems: 'center',
					gap: '4px',
					padding: '2px 9px',
					borderRadius: '999px',
					fontSize: '12px',
					lineHeight: '18px',
					fontWeight: '600',
					whiteSpace: 'nowrap'
				};
				for ( var k in geom ) {
					if ( Object.prototype.hasOwnProperty.call( geom, k ) ) {
						pill.style[ k ] = geom[ k ];
					}
				}
			}
		} else if ( existing ) {
			existing.outerHTML = html;
		}
	}

	/** Toggle the small busy state on a container while a request is in flight. */
	function setBusy( container, busy ) {
		if ( ! container ) {
			return;
		}
		var spinner = container.querySelector( '.spinner' );
		if ( spinner ) {
			spinner.classList.toggle( 'is-active', !! busy );
		}
		container.classList.toggle( 'ogwm-busy', !! busy );
	}

	function handleClick( event ) {
		var button = event.target.closest( '.ogwm-apply, .ogwm-remove' );
		if ( ! button ) {
			return;
		}

		event.preventDefault();

		var id = parseInt( button.getAttribute( 'data-id' ), 10 );
		if ( ! id ) {
			return;
		}

		var container = containerFor( button );
		var isRemove = button.classList.contains( 'ogwm-remove' );
		var action = isRemove ? 'ogwm_remove_single' : 'ogwm_apply_single';

		setBusy( container, true );

		post(
			action,
			id,
			function ( data ) {
				setBusy( container, false );
				swapBadge( container, data.badge_html );

				// Apply implies the attachment is now flagged; reflect it in the
				// checkbox if one is present (media modal / edit screen).
				if ( container ) {
					var checkbox = container.querySelector( '.ogwm-enabled-checkbox' );
					if ( checkbox ) {
						checkbox.checked = ! isRemove;
					}
				}
			},
			function ( data ) {
				setBusy( container, false );
				var msg = ( data && data.message ) || i18n.error || 'Request failed.';
				window.alert( msg );
			}
		);
	}

	// Delegated so it survives the media modal re-rendering its DOM.
	document.addEventListener( 'click', handleClick );
} )();
