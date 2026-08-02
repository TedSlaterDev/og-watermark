/**
 * OG Watermark — inject the per-image Watermark/Remove control into the media
 * modal's "Attachment details" sidebar.
 *
 * WordPress renders our attachment_fields_to_edit control in the media LIBRARY
 * modal, but the FEATURED-IMAGE frame (the panel that opens from "Set featured
 * image") does NOT render those compat fields — so the control was missing exactly
 * where you pick a featured image. This extends wp.media's Attachment.Details view
 * to append the same Apply/Remove buttons + badge for any image, in every media
 * modal (featured image included), guarded so it never duplicates the compat field
 * where WordPress already renders it.
 *
 * The buttons carry the same `.ogwm-field` / `.ogwm-apply` / `.ogwm-remove` classes
 * the media library uses, so media.js (already loaded, click-delegated) drives the
 * secured AJAX and swaps the returned badge in — no extra wiring here.
 */
( function () {
	'use strict';

	if ( ! window.wp || ! wp.media || ! wp.media.view
		|| ! wp.media.view.Attachment || ! wp.media.view.Attachment.Details ) {
		return;
	}

	var i18n = ( window.ogwmMedia && window.ogwmMedia.i18n ) || {};
	var Details = wp.media.view.Attachment.Details;

	/**
	 * The image's CURRENT status badge, recovered from the attachment's `compat`
	 * markup (WordPress ships our server-rendered attachment field with every
	 * attachment model, even in frames that never display it — e.g. the
	 * featured-image picker). Parsing it out lets the injected control show
	 * "Watermarked / Queued / Not flagged" immediately, instead of only after a
	 * click. Only the single matched `.ogwm-badge` element (our own wp_kses'd
	 * span) is imported — never the raw compat HTML.
	 *
	 * @return {Node|null} The badge element, or null when unavailable.
	 */
	function badgeFromCompat( model ) {
		var compat = model.get( 'compat' );
		var html   = compat && compat.item ? String( compat.item ) : '';
		if ( ! html || -1 === html.indexOf( 'ogwm-badge' ) ) {
			return null;
		}
		try {
			var doc   = new window.DOMParser().parseFromString( html, 'text/html' );
			var badge = doc.querySelector( '.ogwm-badge' );
			return badge ? document.importNode( badge, true ) : null;
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Apply a map of inline styles to an element.
	 *
	 * The injected control carries its LAYOUT as inline styles on purpose: this
	 * widget is created by JS, and shipping its geometry in admin.css proved
	 * fragile in production — CDN configurations that ignore the `?ver=` query
	 * string in their cache key can serve a STALE stylesheet against new JS,
	 * silently wrecking the layout (observed in the wild). Inline styles keep
	 * markup + layout in ONE versioned file, immune to stylesheet cache skew and
	 * to specificity fights with core's media-sidebar rules. admin.css keeps
	 * only cosmetic extras (badge palette etc.), which degrade gracefully.
	 */
	function css( el, styles ) {
		for ( var k in styles ) {
			if ( Object.prototype.hasOwnProperty.call( styles, k ) ) {
				el.style[ k ] = styles[ k ];
			}
		}
	}

	/**
	 * Build the injected control's DOM (text set via textContent — never innerHTML;
	 * the only imported markup is the single badge span from badgeFromCompat).
	 *
	 * The wrapper mimics a CORE "setting" row: `span.name` label right-aligned in
	 * the ~30% label column, and a value column beside it holding the status badge
	 * + buttons — matching the native label/field geometry (never stacked at the
	 * container's left edge). It clears core's floated rows so it sits at the
	 * BOTTOM of the details section.
	 */
	function buildControl( id, badgeNode ) {
		var wrap = document.createElement( 'span' );
		wrap.className = 'setting ogwm-field ogwm-media-detail';
		wrap.setAttribute( 'data-setting', 'ogwm-watermark' );
		wrap.setAttribute( 'data-id', String( id ) );
		css( wrap, {
			display: 'flex',
			alignItems: 'flex-start',
			cssFloat: 'left',
			clear: 'both',
			width: '100%',
			boxSizing: 'border-box',
			gridColumn: '1 / -1',
			margin: '14px 0 0',
			paddingTop: '12px',
			borderTop: '1px solid #dcdcde'
		} );

		var label = document.createElement( 'span' );
		label.className = 'name';
		label.textContent = i18n.title || 'OG Watermark';
		css( label, {
			flex: '0 0 30%',
			minWidth: '0',
			marginRight: '4%',
			textAlign: 'right',
			paddingTop: '4px',
			boxSizing: 'border-box'
		} );
		wrap.appendChild( label );

		var value = document.createElement( 'span' );
		value.className = 'ogwm-field-value';
		css( value, {
			flex: '1 1 auto',
			minWidth: '0',
			display: 'flex',
			flexDirection: 'column',
			alignItems: 'flex-start',
			gap: '6px'
		} );

		var badge = document.createElement( 'span' );
		badge.className = 'ogwm-field-badge';
		if ( badgeNode ) {
			// Inline the pill's GEOMETRY as well (keep in sync with media.js
			// swapBadge and the .ogwm-badge stylesheet rules): host admin themes
			// have been seen forcing display/padding onto it, ballooning the pill —
			// only the tone COLORS are left to the stylesheet classes.
			css( badgeNode, {
				display: 'inline-flex',
				alignItems: 'center',
				gap: '4px',
				padding: '2px 9px',
				borderRadius: '999px',
				fontSize: '12px',
				lineHeight: '18px',
				fontWeight: '600',
				whiteSpace: 'nowrap'
			} );
			badge.appendChild( badgeNode );
		} else {
			// Hidden while empty so the column's gap doesn't double up; media.js
			// clears this when it swaps a fresh badge in after an action.
			badge.style.display = 'none';
		}
		value.appendChild( badge );

		var actions = document.createElement( 'span' );
		actions.className = 'ogwm-field-actions';
		css( actions, {
			display: 'inline-flex',
			alignItems: 'center',
			gap: '6px',
			flexWrap: 'wrap'
		} );

		var apply = document.createElement( 'button' );
		apply.type = 'button';
		apply.className = 'button button-small ogwm-apply';
		apply.setAttribute( 'data-id', String( id ) );
		apply.textContent = i18n.watermark || 'Watermark';
		actions.appendChild( apply );

		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button button-small ogwm-remove';
		remove.setAttribute( 'data-id', String( id ) );
		remove.textContent = i18n.remove || 'Remove';
		actions.appendChild( remove );

		var spinner = document.createElement( 'span' );
		spinner.className = 'spinner ogwm-field-spinner';
		actions.appendChild( spinner );

		value.appendChild( actions );
		wrap.appendChild( value );
		return wrap;
	}

	wp.media.view.Attachment.Details = Details.extend( {
		render: function () {
			Details.prototype.render.apply( this, arguments );
			try {
				this.ogwmInjectControl();
			} catch ( e ) {
				// Never let our addition break the core details view.
			}
			return this;
		},

		ogwmInjectControl: function () {
			var model = this.model;
			if ( ! model || 'image' !== model.get( 'type' ) ) {
				return;
			}
			var id = model.get( 'id' );
			if ( ! id ) {
				return;
			}
			// Do NOT duplicate the compat field WordPress renders in the library modal.
			if ( this.el.querySelector( '.ogwm-field' ) ) {
				return;
			}
			this.el.appendChild( buildControl( id, badgeFromCompat( model ) ) );
		}
	} );
}() );
