/**
 * LuwiPress Multi-language Taxonomy Editor controller.
 *
 * Renders one accordion row per term group (trid). Inside each row a
 * (5 fields x N languages) cell grid. Click any cell to edit; Enter
 * confirms, Esc reverts. Dirty cells are tracked client-side; "Save
 * all" batches every changed (term_id, field) into one POST to
 * /taxonomy-editor/save.
 *
 * No jQuery dep on purpose — matches faq-editor.js / health-score
 * conventions; the page loads on customers without any extra deps.
 *
 * @since 3.5.6
 */

( function () {
	'use strict';

	if ( typeof window.lwpTaxEditor === 'undefined' ) {
		return;
	}

	var CFG = window.lwpTaxEditor;
	var FIELDS = [
		{ key: 'name',             label: CFG.strings.fieldName,     multiline: false },
		{ key: 'description',      label: CFG.strings.fieldDesc,     multiline: true  },
		{ key: 'rm_title',         label: CFG.strings.fieldSeoTitle, multiline: false },
		{ key: 'rm_description',   label: CFG.strings.fieldSeoDesc,  multiline: true  },
		{ key: 'rm_focus_keyword', label: CFG.strings.fieldFocusKw,  multiline: false },
	];

	// In-memory store keyed by group_id -> cell snapshots + dirty diffs.
	// dirty[ termId ][ fieldKey ] = newValue (original survives in snapshot).
	var state = {
		taxonomy:   null,
		groups:     [],
		snapshot:   {},   // termId -> { name, description, rm_title, rm_description, rm_focus_keyword }
		dirty:      {},   // termId -> { fieldKey: newValue }
		languages:  CFG.languages,
		loading:    false,
		searchTerm: '',
	};

	var el = {
		taxonomy:   document.getElementById( 'lwp-tx-taxonomy' ),
		search:     document.getElementById( 'lwp-tx-search' ),
		saveBtn:    document.getElementById( 'lwp-tx-save-all' ),
		dirtyCount: document.querySelector( '.lwp-tx-dirty-count' ),
		status:     document.getElementById( 'lwp-tx-status' ),
		skeleton:   document.getElementById( 'lwp-tx-skeleton' ),
		empty:      document.getElementById( 'lwp-tx-empty' ),
		list:       document.getElementById( 'lwp-tx-list' ),
	};

	if ( ! el.taxonomy || ! el.list ) {
		return;
	}

	// -------------------- API helpers --------------------

	function api( path, opts ) {
		opts = opts || {};
		var url = CFG.restUrl + path;
		return fetch( url, {
			method:  opts.method  || 'GET',
			headers: Object.assign( {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   CFG.nonce,
			}, opts.headers || {} ),
			body: opts.body ? JSON.stringify( opts.body ) : undefined,
			credentials: 'same-origin',
		} ).then( function ( r ) {
			return r.json().then( function ( body ) {
				if ( ! r.ok ) {
					throw new Error( ( body && body.message ) || ( 'HTTP ' + r.status ) );
				}
				return body;
			} );
		} );
	}

	// -------------------- Loading flow --------------------

	function loadTerms( taxonomy ) {
		state.taxonomy = taxonomy;
		state.loading  = true;
		state.dirty    = {};
		state.snapshot = {};
		updateDirtyUI();
		el.empty.hidden    = true;
		el.list.innerHTML  = '';
		el.skeleton.hidden = false;
		setStatus( '' );

		var params = new URLSearchParams( {
			taxonomy: taxonomy,
			limit:    '500',
		} );
		if ( state.searchTerm ) {
			params.set( 'search', state.searchTerm );
		}

		return api( '/taxonomy-editor/terms?' + params.toString() )
			.then( function ( data ) {
				state.groups    = data.groups || [];
				state.languages = data.languages && data.languages.length ? data.languages : CFG.languages;
				// Snapshot every cell so revert + diff work without re-fetch.
				state.groups.forEach( function ( g ) {
					Object.keys( g.cells ).forEach( function ( code ) {
						var cell = g.cells[ code ];
						if ( cell && cell.exists && cell.term_id ) {
							state.snapshot[ cell.term_id ] = {
								name:             cell.name             || '',
								description:      cell.description      || '',
								rm_title:         cell.rm_title         || '',
								rm_description:   cell.rm_description   || '',
								rm_focus_keyword: cell.rm_focus_keyword || '',
							};
						}
					} );
				} );
				renderList();
			} )
			.catch( function ( err ) {
				setStatus( err.message || 'Load failed', 'error' );
				state.groups = [];
				renderList();
			} )
			.then( function () {
				state.loading      = false;
				el.skeleton.hidden = true;
			} );
	}

	// -------------------- Rendering --------------------

	function renderList() {
		el.list.innerHTML = '';

		if ( ! state.groups.length ) {
			el.empty.hidden = false;
			return;
		}
		el.empty.hidden = true;

		var langCount = state.languages.length;
		var frag      = document.createDocumentFragment();

		state.groups.forEach( function ( g ) {
			frag.appendChild( renderGroupRow( g, langCount ) );
		} );

		el.list.appendChild( frag );

		// Reflect total count in toolbar status (subtle).
		setStatus(
			( CFG.strings.rowsTotal || '%d term groups' ).replace( '%d', state.groups.length ),
			'info'
		);
	}

	function renderGroupRow( group, langCount ) {
		var row = document.createElement( 'details' );
		row.className          = 'lwp-tx-group';
		row.dataset.groupId    = group.group_id;
		row.dataset.anchorName = ( group.anchor_name || '' ).toLowerCase();

		// Summary line (always visible).
		var summary = document.createElement( 'summary' );
		summary.className = 'lwp-tx-group__summary';

		var langChips = state.languages.map( function ( l ) {
			var cell    = group.cells[ l.code ];
			var present = cell && cell.exists;
			return '<span class="lwp-tx-langchip ' + ( present ? 'is-present' : 'is-missing' ) + '" title="' + escapeAttr( l.label ) + '">'
				+ escapeText( l.code )
				+ '</span>';
		} ).join( '' );

		summary.innerHTML =
			'<span class="lwp-tx-group__name">' + escapeText( group.anchor_name || '(unnamed)' ) + '</span>' +
			'<span class="lwp-tx-group__slug"><code>' + escapeText( group.anchor_slug || '' ) + '</code></span>' +
			'<span class="lwp-tx-group__langs">' + langChips + '</span>';

		row.appendChild( summary );

		// Matrix: header row (lang codes) + 5 field rows.
		var grid = document.createElement( 'div' );
		grid.className = 'lwp-tx-grid';
		grid.style.gridTemplateColumns = '160px repeat(' + langCount + ', minmax(180px, 1fr))';

		// Header row.
		var hdr = document.createElement( 'div' );
		hdr.className = 'lwp-tx-grid__head';
		hdr.innerHTML = '<div class="lwp-tx-grid__cell lwp-tx-grid__cell--label">&nbsp;</div>';
		state.languages.forEach( function ( l ) {
			var cell = group.cells[ l.code ] || {};
			var lbl  = '<strong>' + escapeText( l.code.toUpperCase() ) + '</strong>'
				+ ( cell.exists ? ' <span class="lwp-tx-langname">' + escapeText( l.label ) + '</span>' : '' );
			var edit = '';
			if ( cell.exists && cell.edit_url ) {
				edit = ' <a class="lwp-tx-editlink" href="' + escapeAttr( cell.edit_url ) + '" target="_blank" rel="noopener noreferrer" title="' + escapeAttr( CFG.strings.editUrl || '' ) + '">↗</a>';
			}
			var missing = cell.exists ? '' : '<span class="lwp-tx-missing">' + escapeText( CFG.strings.missingSibling || '(no translation)' ) + '</span>';
			hdr.innerHTML += '<div class="lwp-tx-grid__cell lwp-tx-grid__cell--head" data-lang="' + escapeAttr( l.code ) + '">' + lbl + edit + ' ' + missing + '</div>';
		} );
		grid.appendChild( hdr );

		// One row per field.
		FIELDS.forEach( function ( f ) {
			var fr = document.createElement( 'div' );
			fr.className = 'lwp-tx-grid__row';
			fr.innerHTML = '<div class="lwp-tx-grid__cell lwp-tx-grid__cell--label">' + escapeText( f.label ) + '</div>';

			state.languages.forEach( function ( l ) {
				var cell = group.cells[ l.code ];
				if ( ! cell || ! cell.exists ) {
					fr.innerHTML += '<div class="lwp-tx-grid__cell lwp-tx-grid__cell--empty">—</div>';
					return;
				}
				var value     = cell[ f.key ] || '';
				var cellEl    = '<div class="lwp-tx-grid__cell lwp-tx-grid__cell--value" data-term-id="' + cell.term_id + '" data-field="' + f.key + '" data-multiline="' + ( f.multiline ? '1' : '0' ) + '">'
					+ '<div class="lwp-tx-cellbody" tabindex="0">' + escapeText( value ) + '</div>'
					+ '</div>';
				fr.innerHTML += cellEl;
			} );
			grid.appendChild( fr );
		} );

		row.appendChild( grid );
		return row;
	}

	// -------------------- Editing flow --------------------

	function onListClick( e ) {
		var body = e.target.closest( '.lwp-tx-cellbody' );
		if ( ! body ) {
			return;
		}
		var cell = body.parentElement;
		if ( cell.classList.contains( 'is-editing' ) ) {
			return;
		}
		openEditor( cell );
	}

	function openEditor( cell ) {
		var termId    = parseInt( cell.dataset.termId, 10 );
		var field     = cell.dataset.field;
		var multiline = cell.dataset.multiline === '1';
		var current   = cell.querySelector( '.lwp-tx-cellbody' ).textContent;

		var input;
		if ( multiline ) {
			input = document.createElement( 'textarea' );
			input.rows = 4;
		} else {
			input = document.createElement( 'input' );
			input.type = 'text';
		}
		input.className = 'lwp-tx-input';
		input.value = current;

		cell.classList.add( 'is-editing' );
		cell.innerHTML = '';
		cell.appendChild( input );
		input.focus();
		input.select();

		var commit = function () {
			var newVal = input.value;
			cell.classList.remove( 'is-editing' );
			cell.innerHTML = '<div class="lwp-tx-cellbody" tabindex="0">' + escapeText( newVal ) + '</div>';
			updateDirty( termId, field, newVal );
		};
		var revert = function () {
			cell.classList.remove( 'is-editing' );
			cell.innerHTML = '<div class="lwp-tx-cellbody" tabindex="0">' + escapeText( current ) + '</div>';
		};

		input.addEventListener( 'keydown', function ( ev ) {
			if ( ev.key === 'Enter' && ! multiline ) {
				ev.preventDefault();
				commit();
			} else if ( ev.key === 'Enter' && multiline && ( ev.ctrlKey || ev.metaKey ) ) {
				ev.preventDefault();
				commit();
			} else if ( ev.key === 'Escape' ) {
				ev.preventDefault();
				revert();
			}
		} );
		input.addEventListener( 'blur', function () {
			// Tiny defer so click on another cell doesn't double-fire.
			setTimeout( function () {
				if ( cell.classList.contains( 'is-editing' ) ) {
					commit();
				}
			}, 60 );
		} );
	}

	function updateDirty( termId, field, newValue ) {
		var snap = state.snapshot[ termId ];
		if ( ! snap ) {
			return;
		}
		var original = snap[ field ] != null ? snap[ field ] : '';
		state.dirty[ termId ] = state.dirty[ termId ] || {};

		if ( String( newValue ) === String( original ) ) {
			// Revert to clean.
			delete state.dirty[ termId ][ field ];
			if ( ! Object.keys( state.dirty[ termId ] ).length ) {
				delete state.dirty[ termId ];
			}
		} else {
			state.dirty[ termId ][ field ] = newValue;
		}

		// Toggle a visual flag on the cell so the operator sees dirty state.
		var sel = '[data-term-id="' + termId + '"][data-field="' + field + '"]';
		var cell = el.list.querySelector( sel );
		if ( cell ) {
			var isDirty = state.dirty[ termId ] && Object.prototype.hasOwnProperty.call( state.dirty[ termId ], field );
			cell.classList.toggle( 'is-dirty', !! isDirty );
		}

		updateDirtyUI();
	}

	function updateDirtyUI() {
		var count = 0;
		Object.keys( state.dirty ).forEach( function ( termId ) {
			count += Object.keys( state.dirty[ termId ] ).length;
		} );

		if ( count === 0 ) {
			el.dirtyCount.textContent = CFG.strings.noChanges || 'No changes';
			el.saveBtn.disabled = true;
		} else {
			el.dirtyCount.textContent = '⚠ ' + count + ' ' + ( CFG.strings.dirty || 'unsaved' );
			el.saveBtn.disabled = false;
		}
	}

	// -------------------- Save flow --------------------

	function saveAll() {
		if ( el.saveBtn.disabled ) {
			return;
		}
		var rows = [];
		Object.keys( state.dirty ).forEach( function ( termId ) {
			var diff = state.dirty[ termId ];
			var row  = { term_id: parseInt( termId, 10 ) };
			Object.keys( diff ).forEach( function ( field ) {
				row[ field ] = diff[ field ];
			} );
			rows.push( row );
		} );

		if ( ! rows.length ) {
			return;
		}

		el.saveBtn.disabled    = true;
		el.saveBtn.dataset.was = el.saveBtn.textContent;
		el.saveBtn.textContent = CFG.strings.saving || 'Saving…';

		api( '/taxonomy-editor/save', {
			method: 'POST',
			body:   { taxonomy: state.taxonomy, rows: rows },
		} ).then( function ( res ) {
			var applied = res.applied || 0;
			var skipped = res.skipped || 0;
			if ( skipped > 0 || ( res.error_rows && res.error_rows.length ) ) {
				setStatus(
					( CFG.strings.savedPartial || '%1$d saved, %2$d failed.' )
						.replace( '%1$d', applied )
						.replace( '%2$d', skipped ),
					'warn'
				);
			} else {
				setStatus(
					( CFG.strings.savedSuccess || '%d updates saved.' ).replace( '%d', applied ),
					'success'
				);
			}

			// Update snapshots so re-edited cells go clean again.
			rows.forEach( function ( r ) {
				if ( ! state.snapshot[ r.term_id ] ) {
					return;
				}
				Object.keys( r ).forEach( function ( k ) {
					if ( k === 'term_id' ) {
						return;
					}
					state.snapshot[ r.term_id ][ k ] = r[ k ];
				} );
			} );
			state.dirty = {};
			el.list.querySelectorAll( '.is-dirty' ).forEach( function ( c ) {
				c.classList.remove( 'is-dirty' );
			} );
			updateDirtyUI();
		} ).catch( function ( err ) {
			setStatus( ( CFG.strings.savedFailure || 'Save failed.' ) + ' ' + ( err.message || '' ), 'error' );
		} ).then( function () {
			el.saveBtn.disabled    = ! Object.keys( state.dirty ).length;
			el.saveBtn.textContent = el.saveBtn.dataset.was || ( CFG.strings.saveAll || 'Save all changes' );
		} );
	}

	// -------------------- Search --------------------

	function applyClientSearch() {
		var q = ( state.searchTerm || '' ).toLowerCase();
		var groups = el.list.querySelectorAll( '.lwp-tx-group' );
		groups.forEach( function ( g ) {
			if ( ! q ) {
				g.hidden = false;
				return;
			}
			var match = ( g.dataset.anchorName || '' ).indexOf( q ) !== -1;
			g.hidden = ! match;
		} );
	}

	// -------------------- Status banner --------------------

	function setStatus( msg, level ) {
		if ( ! msg ) {
			el.status.innerHTML = '';
			el.status.className = 'lwp-tx-status';
			return;
		}
		el.status.className = 'lwp-tx-status lwp-tx-status--' + ( level || 'info' );
		el.status.textContent = msg;
	}

	// -------------------- Escaping --------------------

	function escapeText( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}
	function escapeAttr( s ) {
		return escapeText( s );
	}

	// -------------------- Wire-up --------------------

	el.taxonomy.addEventListener( 'change', function () {
		state.searchTerm = '';
		el.search.value = '';
		loadTerms( el.taxonomy.value );
	} );

	el.search.addEventListener( 'input', function () {
		state.searchTerm = el.search.value.trim();
		applyClientSearch();
	} );

	el.saveBtn.addEventListener( 'click', saveAll );

	el.list.addEventListener( 'click', onListClick );

	// Save on Cmd/Ctrl+S even when focus is in a cell.
	document.addEventListener( 'keydown', function ( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 's' ) {
			if ( document.querySelector( '.lwp-tx-cellbody' ) ) {
				e.preventDefault();
				saveAll();
			}
		}
	} );

	// Boot.
	loadTerms( el.taxonomy.value );

} )();
