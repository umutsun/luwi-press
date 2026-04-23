/**
 * LuwiPress Live Layer
 * --------------------
 * Reusable client-side "make admin UIs feel alive" primitive. Used by the
 * Usage page today; designed so Dashboard, Scheduler, KG etc. can plug in
 * later without duplicating polling / count-up / sparkline code.
 *
 * Public API exposed on window.LuwiLive:
 *   poll(id, endpoint, intervalMs, onData)    -> start a polling loop
 *   stop(id)                                  -> cancel a polling loop
 *   countUp(el, target, opts)                 -> animate a number into an element
 *   sparkline(svgEl, values, opts)            -> render a values-array as inline SVG
 *   barMeter(el, items, opts)                 -> render horizontal bar chart (DOM)
 *   highlight(el)                             -> brief visual "this changed" pulse
 *   prependItem(container, html, opts)        -> slide-in a new item at top, trim tail
 *   on / emit                                 -> tiny event bus for cross-component sync
 *
 * Design notes:
 *   - Zero dependencies (no jQuery), uses fetch + WP REST nonce.
 *   - Visibility-aware: polling pauses when the tab is hidden to save API cost.
 *   - Error-tolerant: a failed poll doesn't stop the loop; logs to console once per id.
 *   - Duration tokens mirror admin.css CSS variables where possible.
 */
( function ( window, document ) {
	'use strict';

	if ( window.LuwiLive ) {
		return; // Already loaded.
	}

	var cfg = window.luwipressLive || {};
	var restBase = cfg.rest_base || '/wp-json/luwipress/v1/';
	var restNonce = cfg.rest_nonce || '';

	/* ──────────────────────────── Polling registry ─────────────────────── */

	var pollers = {};
	var errorCount = {};

	function startPoll( id, endpoint, intervalMs, onData ) {
		stopPoll( id );

		var tick = function () {
			if ( document.hidden ) {
				return; // Skip — will resume when tab returns to foreground.
			}
			var url = /^https?:/.test( endpoint ) ? endpoint : restBase + endpoint.replace( /^\//, '' );
			fetch( url, {
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': restNonce,
					Accept: 'application/json',
				},
			} )
				.then( function ( r ) {
					if ( ! r.ok ) {
						throw new Error( 'HTTP ' + r.status );
					}
					return r.json();
				} )
				.then( function ( data ) {
					errorCount[ id ] = 0;
					try {
						onData( data );
					} catch ( e ) {
						console.error( '[LuwiLive:' + id + '] handler threw', e );
					}
				} )
				.catch( function ( e ) {
					errorCount[ id ] = ( errorCount[ id ] || 0 ) + 1;
					if ( errorCount[ id ] === 1 || errorCount[ id ] % 10 === 0 ) {
						console.warn( '[LuwiLive:' + id + '] poll failed', e );
					}
				} );
		};

		pollers[ id ] = setInterval( tick, intervalMs );
		// Fire once immediately so the UI warms up without waiting the full interval.
		tick();
	}

	function stopPoll( id ) {
		if ( pollers[ id ] ) {
			clearInterval( pollers[ id ] );
			delete pollers[ id ];
		}
	}

	// When the page regains focus, fire an immediate tick for every active poller
	// so the UI catches up instead of waiting for the next interval.
	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			return;
		}
		bus.emit( 'luwi-live:resume' );
	} );

	/* ──────────────────────────── Event bus ────────────────────────────── */

	var listeners = {};
	var bus = {
		on: function ( event, fn ) {
			( listeners[ event ] = listeners[ event ] || [] ).push( fn );
		},
		emit: function ( event, payload ) {
			( listeners[ event ] || [] ).forEach( function ( fn ) {
				try {
					fn( payload );
				} catch ( e ) {
					console.error( e );
				}
			} );
		},
	};

	/* ──────────────────────────── Count-up animation ───────────────────── */

	function countUp( el, target, opts ) {
		if ( ! el ) {
			return;
		}
		opts = opts || {};
		var duration = opts.duration || 600;
		var decimals = opts.decimals != null ? opts.decimals : 0;
		var prefix = opts.prefix || '';
		var suffix = opts.suffix || '';
		var parseStored = function ( raw ) {
			if ( raw == null || raw === '' ) {
				return 0;
			}
			var n = parseFloat( String( raw ).replace( /[^0-9.\-]/g, '' ) );
			return isFinite( n ) ? n : 0;
		};

		var from = parseStored( el.dataset.lpLiveValue );
		var to = Number( target );
		if ( ! isFinite( to ) ) {
			return;
		}

		// No change → just refresh the display string (formatting may still differ).
		if ( Math.abs( to - from ) < Math.pow( 10, -( decimals + 1 ) ) && el.dataset.lpLiveValue != null ) {
			return;
		}

		var start = performance.now();
		var easeOutCubic = function ( t ) {
			return 1 - Math.pow( 1 - t, 3 );
		};

		var frame = function ( now ) {
			var t = Math.min( 1, ( now - start ) / duration );
			var v = from + ( to - from ) * easeOutCubic( t );
			el.textContent = prefix + v.toFixed( decimals ) + suffix;
			if ( t < 1 ) {
				requestAnimationFrame( frame );
			} else {
				el.textContent = prefix + to.toFixed( decimals ) + suffix;
				el.dataset.lpLiveValue = String( to );
				// Visual nudge when value actually moved.
				if ( to !== from ) {
					highlight( el );
				}
			}
		};
		requestAnimationFrame( frame );
	}

	/* ──────────────────────────── Highlight pulse ──────────────────────── */

	function highlight( el ) {
		if ( ! el ) {
			return;
		}
		el.classList.remove( 'lp-live-ping' );
		// Force reflow so re-adding the class restarts the animation.
		void el.offsetWidth;
		el.classList.add( 'lp-live-ping' );
	}

	/* ──────────────────────────── Sparkline SVG ────────────────────────── */

	function sparkline( svg, values, opts ) {
		if ( ! svg || ! values || ! values.length ) {
			return;
		}
		opts = opts || {};
		var w = opts.width || 120;
		var h = opts.height || 32;
		var pad = opts.padding != null ? opts.padding : 2;
		var stroke = opts.stroke || 'currentColor';
		var fill = opts.fill || 'none';
		var strokeWidth = opts.strokeWidth || 1.5;

		svg.setAttribute( 'viewBox', '0 0 ' + w + ' ' + h );
		svg.setAttribute( 'width', w );
		svg.setAttribute( 'height', h );

		var max = Math.max.apply( null, values );
		var min = Math.min.apply( null, values );
		var range = max - min || 1;
		var step = values.length > 1 ? ( w - pad * 2 ) / ( values.length - 1 ) : 0;

		var pts = values.map( function ( v, i ) {
			var x = pad + i * step;
			var y = h - pad - ( ( v - min ) / range ) * ( h - pad * 2 );
			return x.toFixed( 2 ) + ',' + y.toFixed( 2 );
		} );

		// Build path: line stroke + optional area fill underneath.
		var areaPath = '';
		if ( fill !== 'none' ) {
			areaPath =
				'<path d="M' + pts.join( ' L' ) + ' L' + ( w - pad ).toFixed( 2 ) + ',' + ( h - pad ).toFixed( 2 ) +
				' L' + pad.toFixed( 2 ) + ',' + ( h - pad ).toFixed( 2 ) + ' Z" fill="' + fill + '" opacity="0.18"/>';
		}
		var linePath =
			'<path d="M' + pts.join( ' L' ) + '" fill="none" stroke="' + stroke + '" stroke-width="' + strokeWidth +
			'" stroke-linecap="round" stroke-linejoin="round"/>';
		// Last-point dot for anchor.
		var last = pts[ pts.length - 1 ].split( ',' );
		var dot = '<circle cx="' + last[ 0 ] + '" cy="' + last[ 1 ] + '" r="2.2" fill="' + stroke + '"/>';

		svg.innerHTML = areaPath + linePath + dot;
	}

	/* ──────────────────────────── Horizontal bar meter ─────────────────── */

	/**
	 * Render an items list of { label, value, sub, color } as horizontal bars.
	 * Items are sorted desc by value. Container is replaced with the new markup.
	 */
	function barMeter( container, items, opts ) {
		if ( ! container || ! Array.isArray( items ) ) {
			return;
		}
		opts = opts || {};
		var fmt = opts.format || function ( v ) {
			return String( v );
		};
		var max = items.reduce( function ( m, it ) {
			return Math.max( m, Number( it.value ) || 0 );
		}, 0 ) || 1;

		var html = items.map( function ( it ) {
			var pct = Math.max( 2, ( ( Number( it.value ) || 0 ) / max ) * 100 );
			var color = it.color || 'var(--lp-primary)';
			return (
				'<div class="lp-bar-row">' +
					'<div class="lp-bar-head">' +
						'<span class="lp-bar-label">' + escapeHtml( it.label ) + '</span>' +
						'<span class="lp-bar-value">' + escapeHtml( fmt( it.value, it ) ) + '</span>' +
					'</div>' +
					'<div class="lp-bar-track">' +
						'<div class="lp-bar-fill" style="width:' + pct.toFixed( 1 ) + '%;background:' + color + ';"></div>' +
					'</div>' +
					( it.sub ? '<div class="lp-bar-sub">' + escapeHtml( it.sub ) + '</div>' : '' ) +
				'</div>'
			);
		} ).join( '' );

		container.innerHTML = html;
	}

	/* ──────────────────────────── Prepend item (live feed) ─────────────── */

	function prependItem( container, html, opts ) {
		if ( ! container ) {
			return;
		}
		opts = opts || {};
		var limit = opts.limit || 20;

		var wrapper = document.createElement( 'div' );
		wrapper.innerHTML = html;
		var node = wrapper.firstElementChild;
		if ( ! node ) {
			return;
		}
		node.classList.add( 'lp-live-enter' );

		container.insertBefore( node, container.firstChild );

		// Trim tail beyond limit.
		while ( container.children.length > limit ) {
			container.removeChild( container.lastElementChild );
		}

		// Remove enter class after animation so DOM stays clean.
		setTimeout( function () {
			node.classList.remove( 'lp-live-enter' );
		}, 500 );
	}

	/* ──────────────────────────── Helpers ──────────────────────────────── */

	function escapeHtml( s ) {
		if ( s == null ) {
			return '';
		}
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	/* ──────────────────────────── Public API ───────────────────────────── */

	window.LuwiLive = {
		poll: startPoll,
		stop: stopPoll,
		countUp: countUp,
		sparkline: sparkline,
		barMeter: barMeter,
		highlight: highlight,
		prependItem: prependItem,
		on: bus.on,
		emit: bus.emit,
		escapeHtml: escapeHtml,
	};
} )( window, document );
