/**
 * LuwiPress Usage & Logs — Live Layer
 * -----------------------------------
 * Progressive-enhancement glue between usage-page.php and luwi-live.js.
 * The PHP page is fully functional without JS; this file layers on:
 *
 *   1. Polling /token-stats every 10s → count-up stat cards + sparkline + workflow bars
 *   2. Polling /logs every 15s (when the Logs tab is open AND auto-refresh is on)
 *      → slide-in newest entries at the top
 *   3. Client-side log filter: typing in the search box filters instantly (no form submit)
 *   4. Budget-critical visual: >=90% pulses the daily-budget card
 *
 * Dependencies: luwi-live.js (must be enqueued first).
 */
( function () {
	'use strict';

	if ( ! window.LuwiLive ) {
		console.warn( '[usage-live] LuwiLive primitive missing — skipping.' );
		return;
	}

	var root = document.querySelector( '[data-luwi-usage-root]' );
	if ( ! root ) {
		return; // Not on the usage page.
	}

	var Live = window.LuwiLive;
	var activeTab = root.getAttribute( 'data-tab' ) || 'overview';

	/* ──────────────────────────── Stats polling ────────────────────────── */

	function renderStats( data ) {
		if ( ! data ) {
			return;
		}

		// Cards — count-up each numeric value.
		var today = data.today || {};
		var month = data.month || {};
		var pct = Number( data.limit_used || 0 );
		var limit = Number( data.daily_limit || 0 );

		var todayCost = Number( today.cost || 0 );
		var monthCost = Number( month.cost || 0 );
		var todayCalls = Number( today.calls || 0 );
		var monthCalls = Number( month.calls || 0 );

		var $ = function ( sel ) {
			return root.querySelector( sel );
		};

		Live.countUp( $( '[data-live-stat="today-cost"]' ), todayCost, { prefix: '$', decimals: 4 } );
		Live.countUp( $( '[data-live-stat="today-calls"]' ), todayCalls );
		Live.countUp( $( '[data-live-stat="month-cost"]' ), monthCost, { prefix: '$', decimals: 4 } );
		Live.countUp( $( '[data-live-stat="month-calls"]' ), monthCalls );

		var pctEl = $( '[data-live-stat="limit-pct"]' );
		if ( pctEl ) {
			if ( limit > 0 ) {
				Live.countUp( pctEl, pct, { suffix: '%' } );
			} else {
				pctEl.textContent = '--';
			}
		}

		var budgetSub = $( '[data-live-stat="budget-sub"]' );
		if ( budgetSub ) {
			if ( limit > 0 ) {
				budgetSub.textContent = '$' + todayCost.toFixed( 2 ) + ' / $' + limit.toFixed( 2 );
			}
		}

		// Progress bar colour + pulse.
		var bar = $( '[data-live-stat="budget-bar"]' );
		var card = $( '[data-live-card="budget"]' );
		if ( bar ) {
			var fill = limit > 0 ? Math.min( 100, pct ) : 0;
			bar.style.width = fill + '%';
			bar.classList.toggle( 'lp-bar-crit', pct >= 90 );
			bar.classList.toggle( 'lp-bar-warn', pct >= 70 && pct < 90 );
		}
		if ( card ) {
			card.classList.toggle( 'lp-card-pulse', pct >= 90 );
		}

		// Workflow count card (4th stat).
		var wfCountEl = $( '[data-live-stat="workflow-count"]' );
		if ( wfCountEl ) {
			Live.countUp( wfCountEl, ( data.by_workflow || [] ).length );
		}

		// Sparkline — last 30 days of daily cost (reverse to chronological).
		// Uses viewBox so the SVG scales to fill whatever width the card gives it.
		var sparkEl = root.querySelector( '[data-live-sparkline]' );
		if ( sparkEl ) {
			var daily = ( data.daily || [] ).slice().reverse();
			if ( daily.length > 1 ) {
				var values = daily.map( function ( d ) {
					return Number( d.cost || 0 );
				} );
				var total = values.reduce( function ( a, b ) {
					return a + b;
				}, 0 );
				if ( total > 0 ) {
					Live.sparkline( sparkEl, values, {
						width: 200,
						height: 48,
						stroke: 'var(--lp-primary)',
						fill: 'var(--lp-primary)',
						strokeWidth: 2,
					} );
					// Let the card control actual rendered size — our CSS sets width:100%.
					sparkEl.removeAttribute( 'width' );
					sparkEl.removeAttribute( 'height' );
					sparkEl.setAttribute( 'preserveAspectRatio', 'none' );
					sparkEl.classList.remove( 'is-empty' );
				} else {
					sparkEl.classList.add( 'is-empty' );
				}
			}
		}

		// Workflow horizontal bar chart.
		var wfEl = root.querySelector( '[data-live-workflows]' );
		if ( wfEl && Array.isArray( data.by_workflow ) && data.by_workflow.length ) {
			var palette = [
				'var(--lp-primary)',
				'var(--lp-success)',
				'var(--lp-warning)',
				'var(--lp-error)',
				'var(--lp-blue)',
				'#a855f7',
				'#ec4899',
			];
			Live.barMeter(
				wfEl,
				data.by_workflow.slice( 0, 8 ).map( function ( wf, i ) {
					var name = String( wf.workflow || '' ).replace( /[-_]/g, ' ' );
					name = name.replace( /\b\w/g, function ( c ) {
						return c.toUpperCase();
					} );
					return {
						label: name,
						value: Number( wf.cost || 0 ),
						sub: Number( wf.calls || 0 ) + ' calls · ' + Number( wf.tokens || 0 ).toLocaleString() + ' tokens',
						color: palette[ i % palette.length ],
					};
				} ),
				{
					format: function ( v ) {
						return '$' + Number( v ).toFixed( 4 );
					},
				}
			);
		}
	}

	Live.poll( 'usage-stats', 'token-stats', 10000, renderStats );

	/* ──────────────────────────── Client-side log filter ───────────────── */

	var searchInput = root.querySelector( '[data-live-log-search]' );
	var logGroups = root.querySelectorAll( '.usage-log-group' );
	var emptyState = root.querySelector( '[data-live-log-empty]' );

	if ( searchInput && logGroups.length ) {
		var debounce = null;
		var runFilter = function () {
			var q = searchInput.value.trim().toLowerCase();
			var anyVisible = false;
			logGroups.forEach( function ( group ) {
				var entries = group.querySelectorAll( '.usage-log-entry' );
				var visibleInGroup = 0;
				entries.forEach( function ( entry ) {
					var msg = entry.querySelector( '.usage-log-msg' );
					var hay = msg ? msg.textContent.toLowerCase() : '';
					var match = ! q || hay.indexOf( q ) >= 0;
					entry.style.display = match ? '' : 'none';
					if ( match ) {
						visibleInGroup++;
					}
				} );
				group.style.display = visibleInGroup ? '' : 'none';
				if ( visibleInGroup ) {
					anyVisible = true;
				}
			} );
			if ( emptyState ) {
				emptyState.style.display = anyVisible ? 'none' : '';
			}
		};
		searchInput.addEventListener( 'input', function () {
			clearTimeout( debounce );
			debounce = setTimeout( runFilter, 180 );
		} );
	}

	/* ──────────────────────────── Auto-refresh logs toggle ─────────────── */

	var autoToggle = root.querySelector( '[data-live-autorefresh]' );
	var autoLabel = root.querySelector( '[data-live-autorefresh-label]' );
	var logStreamContainer = root.querySelector( '[data-live-log-stream]' );
	var logLevelFilter = ( new URLSearchParams( window.location.search ) ).get( 'level' ) || '';

	function renderLogEntry( log ) {
		var lvl = log.level || 'info';
		var time = log.timestamp ? String( log.timestamp ).substring( 11, 19 ) : '';
		var ctxAttr = '';
		if ( log.context && log.context !== '{}' && log.context !== 'null' ) {
			ctxAttr = ' data-context="' + Live.escapeHtml( log.context ) + '"';
		}
		var ctxBtn = ctxAttr
			? '<button type="button" class="usage-log-detail luwipress-show-context"' + ctxAttr + '><span class="dashicons dashicons-info-outline"></span></button>'
			: '';
		return (
			'<div class="usage-log-entry log-' + Live.escapeHtml( lvl ) + '" data-live-log-id="' + Live.escapeHtml( log.id || '' ) + '">' +
				'<span class="usage-log-time">' + Live.escapeHtml( time ) + '</span>' +
				'<span class="usage-log-level">' + Live.escapeHtml( lvl.toUpperCase() ) + '</span>' +
				'<span class="usage-log-msg">' + Live.escapeHtml( log.message || '' ) + '</span>' +
				ctxBtn +
			'</div>'
		);
	}

	var knownLogIds = new Set();
	// Seed with currently rendered IDs so we don't duplicate.
	root.querySelectorAll( '[data-live-log-id]' ).forEach( function ( el ) {
		var id = el.getAttribute( 'data-live-log-id' );
		if ( id ) {
			knownLogIds.add( id );
		}
	} );

	function pollLogs( payload ) {
		// Endpoint returns { count, logs: [...] }; accept raw array too for forward-compat.
		var items = Array.isArray( payload ) ? payload : ( payload && Array.isArray( payload.logs ) ? payload.logs : null );
		if ( ! items || ! logStreamContainer ) {
			return;
		}
		payload = items;
		// Append-on-top newest first. Server returns newest-first, so iterate reverse
		// to insert oldest-unseen first → newest ends up at top after all inserts.
		var fresh = [];
		payload.forEach( function ( log ) {
			var id = String( log.id || '' );
			if ( ! id || knownLogIds.has( id ) ) {
				return;
			}
			if ( logLevelFilter && log.level !== logLevelFilter ) {
				return;
			}
			fresh.unshift( log );
			knownLogIds.add( id );
		} );
		fresh.forEach( function ( log ) {
			Live.prependItem( logStreamContainer, renderLogEntry( log ), { limit: 200 } );
		} );
	}

	function setAutoRefresh( on ) {
		if ( on ) {
			Live.poll( 'usage-logs', 'logs?limit=30', 15000, pollLogs );
			if ( autoLabel ) {
				autoLabel.textContent = autoLabel.getAttribute( 'data-on' ) || 'Live';
			}
			root.classList.add( 'lp-auto-on' );
		} else {
			Live.stop( 'usage-logs' );
			if ( autoLabel ) {
				autoLabel.textContent = autoLabel.getAttribute( 'data-off' ) || 'Paused';
			}
			root.classList.remove( 'lp-auto-on' );
		}
	}

	if ( autoToggle ) {
		autoToggle.addEventListener( 'change', function () {
			setAutoRefresh( autoToggle.checked );
			try {
				localStorage.setItem( 'luwipress:usage:autorefresh', autoToggle.checked ? '1' : '0' );
			} catch ( e ) {}
		} );

		var stored = null;
		try {
			stored = localStorage.getItem( 'luwipress:usage:autorefresh' );
		} catch ( e ) {}
		var shouldBeOn = stored === null ? activeTab === 'logs' : stored === '1';
		autoToggle.checked = shouldBeOn;
		setAutoRefresh( shouldBeOn );
	}
} )();
