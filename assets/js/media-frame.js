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

	/** Build the injected control's DOM (text set via textContent — never innerHTML). */
	function buildControl( id ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'ogwm-field ogwm-media-detail';
		wrap.setAttribute( 'data-id', String( id ) );

		var label = document.createElement( 'span' );
		label.className = 'ogwm-field-label';
		label.textContent = i18n.title || 'OG Watermark';
		wrap.appendChild( label );

		var badge = document.createElement( 'span' );
		badge.className = 'ogwm-field-badge';
		wrap.appendChild( badge );

		var actions = document.createElement( 'span' );
		actions.className = 'ogwm-field-actions';

		var apply = document.createElement( 'button' );
		apply.type = 'button';
		apply.className = 'button button-small ogwm-apply';
		apply.setAttribute( 'data-id', String( id ) );
		apply.textContent = i18n.watermark || 'Watermark';
		actions.appendChild( apply );
		actions.appendChild( document.createTextNode( ' ' ) );

		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button button-small ogwm-remove';
		remove.setAttribute( 'data-id', String( id ) );
		remove.textContent = i18n.remove || 'Remove';
		actions.appendChild( remove );

		var spinner = document.createElement( 'span' );
		spinner.className = 'spinner ogwm-field-spinner';
		actions.appendChild( spinner );

		wrap.appendChild( actions );
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
			this.el.appendChild( buildControl( id ) );
		}
	} );
}() );
