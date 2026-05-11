/**
 * LuwiPress Theme Tools — admin UI glue.
 *
 * Wires <details class="luwipress-tool"> blocks rendered by admin/theme-page.php
 * to the bridge's AJAX endpoints (luwipress_theme_tools_scan/run/restore/backups).
 * Settings tab uses the same nonce + admin-ajax for batch save.
 *
 * Vanilla JS — no jQuery dependency.
 */
( function () {
	'use strict';

	if ( typeof window.luwipressThemeTools === 'undefined' ) { return; }

	var cfg  = window.luwipressThemeTools;
	var i18n = cfg.i18n || {};

	function ajax( action, payload ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( payload || {} ).forEach( function ( k ) {
			var v = payload[ k ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( item ) { body.append( k + '[]', item ); } );
			} else if ( v && typeof v === 'object' ) {
				Object.keys( v ).forEach( function ( ik ) {
					body.append( k + '[' + ik + ']', v[ ik ] );
				} );
			} else if ( v !== undefined && v !== null ) {
				body.append( k, v );
			}
		} );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) { return r.json(); } );
	}

	function escapeHtml( str ) {
		return String( str == null ? '' : str ).replace( /[&<>"']/g, function ( c ) {
			return ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ];
		} );
	}

	function setStatus( toolEl, msg, kind ) {
		var s = toolEl.querySelector( '.luwipress-tool__status' );
		if ( ! s ) { return; }
		s.textContent = msg || '';
		s.classList.remove( 'luwipress-tool__status--ok', 'luwipress-tool__status--err' );
		if ( kind === 'ok' ) { s.classList.add( 'luwipress-tool__status--ok' ); }
		if ( kind === 'err' ) { s.classList.add( 'luwipress-tool__status--err' ); }
	}

	function renderCandidates( container, candidates, hasExecute ) {
		container.hidden = false;
		if ( ! candidates || ! candidates.length ) {
			container.innerHTML = '<p>' + escapeHtml( i18n.no_candidates ) + '</p>';
			return [];
		}

		var rows = candidates.map( function ( c, idx ) {
			var id    = c.id || c.post_id || c.ID || idx;
			var title = c.title || c.name || c.label || c.slug || ( '#' + id );
			var meta  = c.meta || c.reason || c.note || '';
			return '<tr>'
				+ '<td><input type="checkbox" class="js-candidate" data-id="' + escapeHtml( id ) + '"' + ( hasExecute ? ' checked' : ' disabled' ) + ' /></td>'
				+ '<td>' + escapeHtml( id ) + '</td>'
				+ '<td>' + escapeHtml( title ) + '</td>'
				+ '<td>' + escapeHtml( meta ) + '</td>'
				+ '</tr>';
		} ).join( '' );

		container.innerHTML = '<table>'
			+ '<thead><tr>'
			+ '<th><input type="checkbox" class="js-candidate-all" ' + ( hasExecute ? 'checked' : 'disabled' ) + ' /></th>'
			+ '<th>ID</th><th>Title</th><th>Notes</th>'
			+ '</tr></thead>'
			+ '<tbody>' + rows + '</tbody>'
			+ '</table>'
			+ '<p class="luwipress-candidate-count">' + candidates.length + ' candidate(s).</p>';

		var allCb = container.querySelector( '.js-candidate-all' );
		if ( allCb ) {
			allCb.addEventListener( 'change', function () {
				container.querySelectorAll( '.js-candidate' ).forEach( function ( cb ) {
					if ( ! cb.disabled ) { cb.checked = allCb.checked; }
				} );
			} );
		}

		return Array.from( container.querySelectorAll( '.js-candidate' ) ).map( function ( cb ) {
			return cb.getAttribute( 'data-id' );
		} );
	}

	function selectedIds( container ) {
		return Array.from( container.querySelectorAll( '.js-candidate:checked' ) )
			.map( function ( cb ) { return cb.getAttribute( 'data-id' ); } );
	}

	function onScan( toolEl ) {
		var toolId  = toolEl.dataset.toolId;
		var results = toolEl.querySelector( '.luwipress-tool__results' );
		var execBtn = toolEl.querySelector( '.js-tool-execute' );
		setStatus( toolEl, i18n.scanning );
		if ( execBtn ) { execBtn.disabled = true; }

		ajax( 'luwipress_theme_tools_scan', { tool_id: toolId } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					setStatus( toolEl, ( i18n.error || 'Error: ' ) + ( ( res && res.data && res.data.message ) || 'unknown' ), 'err' );
					return;
				}
				var data = res.data || {};
				var candidates = data.candidates || [];
				renderCandidates( results, candidates, !! execBtn );
				setStatus( toolEl, candidates.length + ' candidate(s).', 'ok' );
				if ( execBtn ) { execBtn.disabled = candidates.length === 0; }
			} )
			.catch( function ( e ) { setStatus( toolEl, ( i18n.error || 'Error: ' ) + e.message, 'err' ); } );
	}

	function onExecute( toolEl ) {
		var toolId  = toolEl.dataset.toolId;
		var results = toolEl.querySelector( '.luwipress-tool__results' );
		var ids = selectedIds( results );
		if ( ! ids.length ) {
			setStatus( toolEl, i18n.select_to_run || '', 'err' );
			return;
		}
		var destructive = toolEl.dataset.destructive === '1';
		var wpml        = toolEl.dataset.wpml === '1';
		if ( destructive ) {
			var msg = ( i18n.confirm_destruct || 'Mutate %d posts?' ).replace( '%d', ids.length );
			if ( wpml ) { msg += '\n' + ( i18n.confirm_wpml || '' ); }
			if ( ! window.confirm( msg ) ) { return; }
		}
		setStatus( toolEl, i18n.executing );

		ajax( 'luwipress_theme_tools_run', { tool_id: toolId, post_ids: ids } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					setStatus( toolEl, ( i18n.error || 'Error: ' ) + ( ( res && res.data && res.data.message ) || 'unknown' ), 'err' );
					return;
				}
				var d = res.data || {};
				var summary = ( d.mutated != null ? d.mutated + ' mutated' : 'Done' )
					+ ( d.backup_id ? ' · backup ' + d.backup_id.substr( 0, 8 ) : '' );
				setStatus( toolEl, summary, 'ok' );
				// Re-scan to refresh the candidate list.
				setTimeout( function () { onScan( toolEl ); }, 400 );
			} )
			.catch( function ( e ) { setStatus( toolEl, ( i18n.error || 'Error: ' ) + e.message, 'err' ); } );
	}

	function onToggleRestore( toolEl ) {
		var toolId   = toolEl.dataset.toolId;
		var restore  = toolEl.querySelector( '.luwipress-tool__restore' );
		if ( restore.hidden === false ) {
			restore.hidden = true;
			return;
		}
		restore.hidden = false;
		restore.innerHTML = '<p>' + escapeHtml( i18n.scanning || 'Loading…' ) + '</p>';
		var url = cfg.ajaxUrl + '?action=luwipress_theme_tools_backups&nonce=' + encodeURIComponent( cfg.nonce ) + '&tool_id=' + encodeURIComponent( toolId );
		fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					restore.innerHTML = '<p>' + escapeHtml( ( i18n.error || 'Error: ' ) + 'failed to load' ) + '</p>';
					return;
				}
				var backups = ( res.data && res.data.backups ) || [];
				if ( ! backups.length ) {
					restore.innerHTML = '<p>' + escapeHtml( i18n.no_backups ) + '</p>';
					return;
				}
				var rows = backups.map( function ( b ) {
					return '<tr>'
						+ '<td><code>' + escapeHtml( b.id.substr( 0, 8 ) ) + '</code></td>'
						+ '<td>' + escapeHtml( b.date ) + '</td>'
						+ '<td>' + escapeHtml( b.post_count ) + '</td>'
						+ '<td><button type="button" class="button button-small js-restore-row" data-backup-id="' + escapeHtml( b.id ) + '">Restore</button></td>'
						+ '</tr>';
				} ).join( '' );
				restore.innerHTML = '<table>'
					+ '<thead><tr><th>ID</th><th>Date</th><th>Posts</th><th></th></tr></thead>'
					+ '<tbody>' + rows + '</tbody></table>';
				restore.querySelectorAll( '.js-restore-row' ).forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						if ( ! window.confirm( 'Restore from this backup?' ) ) { return; }
						setStatus( toolEl, i18n.restoring );
						ajax( 'luwipress_theme_tools_restore', { tool_id: toolId, backup_id: btn.dataset.backupId } )
							.then( function ( r ) {
								if ( ! r || ! r.success ) {
									setStatus( toolEl, ( i18n.error || 'Error: ' ) + ( ( r && r.data && r.data.message ) || 'unknown' ), 'err' );
									return;
								}
								setStatus( toolEl, i18n.restored, 'ok' );
								restore.hidden = true;
							} )
							.catch( function ( e ) { setStatus( toolEl, ( i18n.error || 'Error: ' ) + e.message, 'err' ); } );
					} );
				} );
			} )
			.catch( function ( e ) {
				restore.innerHTML = '<p>' + escapeHtml( ( i18n.error || 'Error: ' ) + e.message ) + '</p>';
			} );
	}

	function bindToolPanel( toolEl ) {
		var scanBtn    = toolEl.querySelector( '.js-tool-scan' );
		var execBtn    = toolEl.querySelector( '.js-tool-execute' );
		var restoreBtn = toolEl.querySelector( '.js-tool-toggle-restore' );
		if ( scanBtn ) { scanBtn.addEventListener( 'click', function () { onScan( toolEl ); } ); }
		if ( execBtn ) { execBtn.addEventListener( 'click', function () { onExecute( toolEl ); } ); }
		if ( restoreBtn ) { restoreBtn.addEventListener( 'click', function () { onToggleRestore( toolEl ); } ); }
	}

	function bindSettingsForm( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var values = {};
			form.querySelectorAll( 'input[name], select[name], textarea[name]' ).forEach( function ( el ) {
				var name = el.getAttribute( 'name' );
				if ( ! name ) { return; }
				if ( el.type === 'checkbox' ) {
					values[ name ] = el.checked ? '1' : '';
				} else {
					values[ name ] = el.value;
				}
			} );
			var status = form.querySelector( '.luwipress-settings-status' );
			if ( status ) { status.textContent = i18n.saving || 'Saving…'; }
			ajax( 'luwipress_theme_settings_save', { values: values } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						if ( status ) {
							status.textContent = ( i18n.error || 'Error: ' ) + ( ( res && res.data && res.data.message ) || 'unknown' );
						}
						return;
					}
					if ( status ) { status.textContent = i18n.saved || 'Saved.'; }
				} )
				.catch( function ( e ) {
					if ( status ) { status.textContent = ( i18n.error || 'Error: ' ) + e.message; }
				} );
		} );
	}

	function readOnlyToolIds() {
		// A tool is read-only when it has NO destructive flag (data-destructive="0").
		var ids = [];
		document.querySelectorAll( '.luwipress-tool[data-destructive="0"]' ).forEach( function ( el ) {
			ids.push( el.dataset.toolId );
		} );
		return ids;
	}

	function onRunAllAudits() {
		var btn      = document.querySelector( '.js-run-all-audits' );
		var status   = document.querySelector( '.luwipress-runall-status' );
		var summary  = document.querySelector( '.luwipress-runall-summary' );
		if ( ! btn || ! status || ! summary ) { return; }

		var ids = readOnlyToolIds();
		if ( ! ids.length ) {
			status.textContent = 'No read-only tools registered.';
			return;
		}

		btn.disabled = true;
		status.textContent = 'Running ' + ids.length + ' audits…';
		summary.hidden = false;
		summary.innerHTML = '<table><thead><tr><th>Tool</th><th>Findings</th><th>Status</th></tr></thead><tbody></tbody></table>';
		var tbody = summary.querySelector( 'tbody' );

		var i = 0;
		function next() {
			if ( i >= ids.length ) {
				btn.disabled = false;
				status.textContent = 'Done. ' + ids.length + ' audits complete.';
				return;
			}
			var id = ids[ i++ ];
			var row = document.createElement( 'tr' );
			row.innerHTML = '<td>' + escapeHtml( id ) + '</td><td>—</td><td>scanning…</td>';
			tbody.appendChild( row );
			ajax( 'luwipress_theme_tools_scan', { tool_id: id } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						row.children[ 2 ].innerHTML = '<span style="color:#b91c1c">' + escapeHtml( ( res && res.data && res.data.message ) || 'error' ) + '</span>';
					} else {
						var d = res.data || {};
						var n = ( d.candidates && d.candidates.length ) || d.count || 0;
						row.children[ 1 ].textContent = n;
						row.children[ 2 ].innerHTML = n
							? '<span style="color:#b45309">⚑ findings</span>'
							: '<span style="color:#059669">✓ clean</span>';
					}
					next();
				} )
				.catch( function ( e ) {
					row.children[ 2 ].innerHTML = '<span style="color:#b91c1c">' + escapeHtml( e.message ) + '</span>';
					next();
				} );
		}
		next();
	}

	function bindResetButtons() {
		document.querySelectorAll( '.js-reset-group' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var group = btn.dataset.group;
				if ( ! window.confirm( 'Reset every setting in the "' + group + '" group to its default?' ) ) { return; }
				var status = document.querySelector( '.luwipress-settings-status' );
				if ( status ) { status.textContent = i18n.saving || 'Resetting…'; }
				ajax( 'luwipress_theme_settings_reset', { group: group } )
					.then( function ( res ) {
						if ( ! res || ! res.success ) {
							if ( status ) {
								status.textContent = ( i18n.error || 'Error: ' ) + ( ( res && res.data && res.data.message ) || 'unknown' );
							}
							return;
						}
						if ( status ) { status.textContent = 'Group reset. Reload to see defaults.'; }
						window.setTimeout( function () { window.location.reload(); }, 800 );
					} )
					.catch( function ( e ) {
						if ( status ) { status.textContent = ( i18n.error || 'Error: ' ) + e.message; }
					} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.luwipress-tool' ).forEach( bindToolPanel );
		document.querySelectorAll( '.luwipress-theme-settings-form' ).forEach( bindSettingsForm );
		bindResetButtons();
		var runAll = document.querySelector( '.js-run-all-audits' );
		if ( runAll ) {
			runAll.addEventListener( 'click', onRunAllAudits );
		}
	} );
} )();
