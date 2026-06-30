/**
 * OG Watermark — Settings page interactions.
 *
 * - Toggles the logo / text panels off the type radio.
 * - Mirrors each range slider's value into its <output>.
 * - Keeps the 3x3 position grid's active cell highlighted.
 * - Opens the WP media frame for the logo picker (PNG only) and stores the id.
 * - Debounces a live preview that POSTs the UNSAVED form to ogwm_preview; the
 *   server sanitizes the input, renders with the real engine, and returns a
 *   data/temp URL. The browser only requests and displays — no settings write.
 *
 * Vanilla DOM (jQuery is a dependency only for the wp.media frame ecosystem);
 * everything is null-guarded so a partial page never throws.
 */
( function () {
	'use strict';

	var cfg = window.ogwmSettings || {};
	var i18n = cfg.i18n || {};
	var form = document.getElementById( 'ogwm-settings-form' );
	if ( ! form ) {
		return;
	}

	// ---- Type-switch panels -------------------------------------------------

	function syncPanels() {
		var selected = form.querySelector( 'input[data-ogwm-type]:checked' );
		var type = selected ? selected.value : 'text';
		var panels = form.querySelectorAll( '[data-ogwm-panel]' );
		Array.prototype.forEach.call( panels, function ( panel ) {
			panel.hidden = panel.getAttribute( 'data-ogwm-panel' ) !== type;
		} );
	}

	Array.prototype.forEach.call(
		form.querySelectorAll( 'input[data-ogwm-type]' ),
		function ( radio ) {
			radio.addEventListener( 'change', function () {
				syncPanels();
				schedulePreview();
			} );
		}
	);
	syncPanels();

	// ---- Slider <output> mirrors -------------------------------------------

	Array.prototype.forEach.call(
		form.querySelectorAll( '.ogwm-range' ),
		function ( range ) {
			var output = form.querySelector( 'output[for="' + range.id + '"]' );
			if ( ! output ) {
				return;
			}
			range.addEventListener( 'input', function () {
				output.value = range.value;
			} );
		}
	);

	// ---- 3x3 position grid active cell -------------------------------------

	Array.prototype.forEach.call(
		form.querySelectorAll( '.ogwm-posgrid input[type="radio"]' ),
		function ( radio ) {
			radio.addEventListener( 'change', function () {
				Array.prototype.forEach.call(
					form.querySelectorAll( '.ogwm-poscell' ),
					function ( cell ) {
						cell.classList.remove( 'is-active' );
					}
				);
				var cell = radio.closest( '.ogwm-poscell' );
				if ( cell ) {
					cell.classList.add( 'is-active' );
				}
			} );
		}
	);

	// ---- Logo picker (wp.media) --------------------------------------------

	var logoFrame = null;
	var logoInput = document.getElementById( 'ogwm_logo_id' );
	var logoSelect = document.getElementById( 'ogwm-logo-select' );
	var logoClear = document.getElementById( 'ogwm-logo-clear' );
	var logoPreview = form.querySelector( '.ogwm-logo-preview' );

	function setLogo( id, url ) {
		if ( logoInput ) {
			logoInput.value = id ? String( id ) : '0';
		}
		if ( logoPreview ) {
			if ( url ) {
				logoPreview.innerHTML = '<img alt="" />';
				logoPreview.querySelector( 'img' ).src = url;
				logoPreview.hidden = false;
			} else {
				logoPreview.innerHTML = '';
				logoPreview.hidden = true;
			}
		}
		if ( logoClear ) {
			logoClear.hidden = ! id;
		}
		schedulePreview();
	}

	if ( logoSelect && window.wp && window.wp.media ) {
		logoSelect.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( ! logoFrame ) {
				logoFrame = window.wp.media( {
					title: i18n.selectLogo || 'Select watermark logo',
					button: { text: i18n.useImage || 'Use this image' },
					library: { type: 'image/png' },
					multiple: false
				} );
				logoFrame.on( 'select', function () {
					var sel = logoFrame.state().get( 'selection' ).first();
					if ( ! sel ) {
						return;
					}
					var att = sel.toJSON();
					if ( att.mime && att.mime !== 'image/png' ) {
						window.alert( i18n.pngOnly || 'The logo must be a PNG.' );
						return;
					}
					var url = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
					setLogo( att.id, url );
				} );
			}
			logoFrame.open();
		} );
	}

	if ( logoClear ) {
		logoClear.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			setLogo( 0, '' );
		} );
	}

	// ---- Live preview (debounced; server-side rate-limited too) ------------

	var preview = document.getElementById( 'ogwm-preview' );
	var previewTimer = null;
	var previewInflight = false;

	function schedulePreview() {
		if ( ! preview || ! cfg.ajaxUrl || ! cfg.nonce ) {
			return;
		}
		if ( previewTimer ) {
			window.clearTimeout( previewTimer );
		}
		previewTimer = window.setTimeout( runPreview, 400 );
	}

	function runPreview() {
		if ( ! preview || previewInflight ) {
			return;
		}
		previewInflight = true;
		preview.classList.add( 'is-loading' );

		var data = new FormData( form );
		data.append( 'action', 'ogwm_preview' );
		data.append( 'nonce', cfg.nonce );

		window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} ).then( function ( res ) {
			return res.json();
		} ).then( function ( json ) {
			if ( json && json.success && json.data && json.data.img_url ) {
				preview.innerHTML = '<img alt="" />';
				preview.querySelector( 'img' ).src = json.data.img_url;
			} else {
				showPreviewMessage( i18n.previewError || 'Preview unavailable.' );
			}
		} ).catch( function () {
			showPreviewMessage( i18n.previewError || 'Preview unavailable.' );
		} ).then( function () {
			previewInflight = false;
			preview.classList.remove( 'is-loading' );
		} );
	}

	function showPreviewMessage( msg ) {
		preview.innerHTML = '<span class="ogwm-preview-placeholder"></span>';
		preview.querySelector( '.ogwm-preview-placeholder' ).textContent = msg;
	}

	// Re-render the preview when any preview-affecting input changes.
	Array.prototype.forEach.call(
		form.querySelectorAll( '.ogwm-preview-input' ),
		function ( input ) {
			var evt = ( 'range' === input.type || 'color' === input.type || 'text' === input.type )
				? 'input'
				: 'change';
			input.addEventListener( evt, schedulePreview );
		}
	);
}() );
