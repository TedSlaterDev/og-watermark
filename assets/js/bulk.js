/**
 * OG Watermark — Bulk Tools progress observer.
 *
 * This script is a READ-ONLY observer of a SERVER-SIDE run (SPEC M7: "the browser
 * only observes"). It can:
 *
 *   1. KICK a run once, by POSTing ogwm_bulk_start (cap manage_options + the
 *      ogwm_admin nonce are enforced server-side). That call only seeds the queue
 *      and starts the background worker — no image work happens in the browser.
 *   2. POLL ogwm_bulk_progress on an interval and paint the bar.
 *
 * Closing the tab does NOT stop the run: the work lives in Action Scheduler / the
 * wp-cron chain on the server. Reopening the page resumes polling an already-
 * running job. Nothing here mutates state except the single start click.
 */
( function () {
	'use strict';

	var cfg = window.ogwmBulk || null;
	if ( ! cfg ) {
		return;
	}

	var startBtn = document.getElementById( 'ogwm-bulk-start' );
	var progress = document.getElementById( 'ogwm-progress-wrap' );
	var fill = document.getElementById( 'ogwm-progress-fill' );
	var text = document.getElementById( 'ogwm-progress-text' );
	var spinner = document.querySelector( '.ogwm-spinner' );
	var pollTimer = null;

	var i18n = cfg.i18n || {};

	function sprintf( template, args ) {
		var i = 0;
		return String( template ).replace( /%\d*\$?[ds]/g, function () {
			return args[ i++ ];
		} );
	}

	function selectedScope() {
		var checked = document.querySelector( 'input[name="ogwm_scope"]:checked' );
		return checked ? checked.value : 'flagged';
	}

	function setSpinner( on ) {
		if ( spinner ) {
			spinner.classList.toggle( 'is-active', !! on );
		}
	}

	function showProgress() {
		if ( progress ) {
			progress.hidden = false;
		}
	}

	function paint( done, failed, total ) {
		var seen = ( done || 0 ) + ( failed || 0 );
		var pct = total > 0 ? Math.min( 100, Math.round( ( seen / total ) * 100 ) ) : 0;
		if ( fill ) {
			fill.style.width = pct + '%';
		}
		if ( text ) {
			var line = sprintf( i18n.progress || '%d of %d', [ seen, total ] );
			if ( failed > 0 ) {
				line += ' — ' + sprintf( i18n.failed || '%d failed', [ failed ] );
			}
			text.textContent = line;
		}
	}

	function post( action, extra, onDone ) {
		var body = new window.URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) {
				body.append( k, extra[ k ] );
			} );
		}

		window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( json ) {
			onDone( json && json.success, json && json.data ? json.data : {} );
		} ).catch( function () {
			onDone( false, {} );
		} );
	}

	function poll() {
		post( cfg.progressAction, null, function ( ok, data ) {
			if ( ok ) {
				paint( data.done, data.failed, data.total );
			}

			if ( ok && ! data.running ) {
				// Run finished — paint the final state and stop polling.
				stopPolling();
				if ( text && i18n.done ) {
					// Keep the count line but append a "Done." marker.
					text.textContent = text.textContent + ' — ' + i18n.done;
				}
				setSpinner( false );
				if ( startBtn ) {
					startBtn.disabled = false;
				}
			}
		} );
	}

	function startPolling() {
		if ( pollTimer ) {
			return;
		}
		showProgress();
		poll();
		pollTimer = window.setInterval( poll, cfg.pollMs || 2000 );
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	function start() {
		if ( startBtn ) {
			startBtn.disabled = true;
		}
		setSpinner( true );
		if ( text ) {
			text.textContent = i18n.starting || '';
		}

		post( cfg.startAction, { scope: selectedScope() }, function ( ok, data ) {
			if ( ! ok ) {
				setSpinner( false );
				if ( startBtn ) {
					startBtn.disabled = false;
				}
				if ( text ) {
					text.textContent = ( data && data.message ) ? data.message : ( i18n.startError || '' );
					showProgress();
				}
				return;
			}
			// Seeded — begin observing the server-side run.
			startPolling();
		} );
	}

	if ( startBtn ) {
		startBtn.addEventListener( 'click', start );
	}

	// If a run is already in progress when the page loads, resume observing it.
	// Either the server rendered the progress block visible, or a bulk-action
	// redirect set window.ogwmAutoStart to kick a fresh run.
	if ( window.ogwmAutoStart ) {
		start();
	} else if ( progress && ! progress.hidden ) {
		startPolling();
	}
} )();
