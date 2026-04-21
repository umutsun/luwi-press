(function() {
'use strict';

var CONFIG = {
	apiUrl:   (window.lpKgConfig && window.lpKgConfig.apiUrl)   || '',
	apiToken: (window.lpKgConfig && window.lpKgConfig.apiToken) || '',
	nonce:    (window.lpKgConfig && window.lpKgConfig.nonce)    || ''
};

// ── D3.js Loader ──
function loadD3(cb) {
	if (window.d3) return cb();
	var s = document.createElement('script');
	s.src = 'https://d3js.org/d3.v7.min.js';
	s.onload = cb;
	document.head.appendChild(s);
}

// ── Animated Counter ──
function animateCounter(el, target, suffix) {
	suffix = suffix || '';
	var start = 0;
	var duration = 1200;
	var startTime = null;
	function step(ts) {
		if (!startTime) startTime = ts;
		var progress = Math.min((ts - startTime) / duration, 1);
		var eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
		var current = Math.round(start + (target - start) * eased);
		el.textContent = current.toLocaleString() + suffix;
		if (progress < 1) requestAnimationFrame(step);
	}
	requestAnimationFrame(step);
}

// ── Fetch Knowledge Graph ──
function fetchGraph(cb, forceFresh) {
	var loading = document.getElementById('kg-loading');
	loading.style.display = 'flex';

	var headers = { 'X-WP-Nonce': CONFIG.nonce };
	if (CONFIG.apiToken) {
		headers['Authorization'] = 'Bearer ' + CONFIG.apiToken;
	}

	// Cache is reliably invalidated on post/meta changes via `maybe_invalidate_on_meta`.
	// Only bypass when the user explicitly clicks Refresh or just fired an action.
	var freshParam = forceFresh ? '&fresh=1' : '';

	fetch(CONFIG.apiUrl + '?sections=products,categories,translation,store,opportunities,design_audit,posts,pages,plugins,order_analytics,taxonomy,crm' + freshParam, {
		headers: headers,
		credentials: 'same-origin'
	})
	.then(function(r) { return r.json(); })
	.then(function(data) {
		loading.style.display = 'none';
		cb(null, data);
	})
	.catch(function(err) {
		loading.style.display = 'none';
		cb(err);
	});
}

// ── Current view state ──
var _kgCurrentView = 'product'; // 'product' | 'post' | 'page'
var _kgCurrentPreset = 'all';   // 'all' | 'needs_seo' | 'not_enriched' | 'thin_content' | 'translation_backlog' | 'high_opportunity'

// ── Layout persistence (P3 #20) ─────────────────────────────────
// User-dragged node positions are stored per view in localStorage so the
// graph reopens in the same arrangement. Key: lpKg.layout.<view>
function kgLayoutKey(view) { return 'lpKg.layout.' + (view || 'product'); }

function kgLayoutLoad(view) {
	try {
		var raw = localStorage.getItem(kgLayoutKey(view));
		return raw ? JSON.parse(raw) : {};
	} catch (e) { return {}; }
}

function kgLayoutSave(view, nodeId, x, y) {
	try {
		var map = kgLayoutLoad(view);
		map[nodeId] = { x: Math.round(x), y: Math.round(y) };
		localStorage.setItem(kgLayoutKey(view), JSON.stringify(map));
	} catch (e) { /* quota full or disabled — silent */ }
}

function kgLayoutReset(view) {
	try { localStorage.removeItem(kgLayoutKey(view)); } catch (e) {}
}

function kgLayoutApply(nodes, view) {
	var map = kgLayoutLoad(view);
	nodes.forEach(function(n) {
		var saved = map[n.id];
		if (saved) {
			n.fx = saved.x;
			n.fy = saved.y;
			n.x = saved.x;
			n.y = saved.y;
		}
	});
}

// Filter products/posts by active preset. Returns a filtered copy; leaves non-content nodes intact.
function applyPreset(productNodes, postNodes, preset) {
	if (preset === 'all' || !preset) return { products: productNodes, posts: postNodes };

	var pFiltered = productNodes.slice();
	var sFiltered = postNodes.slice();

	if (preset === 'needs_seo') {
		pFiltered = productNodes.filter(function(p) { return !p.seo || !p.seo.has_title || !p.seo.has_description; });
		sFiltered = postNodes.filter(function(p) { return !p.seo || !p.seo.has_title || !p.seo.has_description; });
	} else if (preset === 'not_enriched') {
		pFiltered = productNodes.filter(function(p) { return !p.enrichment || p.enrichment.status !== 'completed'; });
		sFiltered = []; // posts don't have enrichment
	} else if (preset === 'thin_content') {
		pFiltered = productNodes.filter(function(p) { return (p.content_length || 0) < 500; });
		sFiltered = postNodes.filter(function(p) { return (p.word_count || 0) < 300; });
	} else if (preset === 'translation_backlog') {
		var hasMissing = function(t) {
			if (!t) return false;
			var keys = Object.keys(t);
			for (var i = 0; i < keys.length; i++) {
				if (t[keys[i]] !== 'completed') return true;
			}
			return false;
		};
		pFiltered = productNodes.filter(function(p) { return hasMissing(p.translation); });
		sFiltered = postNodes.filter(function(p) { return hasMissing(p.translation); });
	} else if (preset === 'high_opportunity') {
		pFiltered = productNodes.filter(function(p) { return (p.opportunity_score || 0) > 30; });
		sFiltered = []; // keep focus on products
	}
	return { products: pFiltered, posts: sFiltered };
}

// ── Build D3 Graph ──
function buildGraph(data, viewFilter) {
	viewFilter = viewFilter || _kgCurrentView || 'product';
	var container = document.getElementById('kg-graph-container');
	var svg = d3.select('#kg-svg');
	var width = container.clientWidth;
	var height = container.clientHeight || 600;

	svg.attr('width', width).attr('height', height);
	svg.selectAll('*').remove();

	var defs = svg.append('defs');

	// Glow filter
	var filter = defs.append('filter').attr('id', 'glow');
	filter.append('feGaussianBlur').attr('stdDeviation', '3').attr('result', 'blur');
	filter.append('feMerge').selectAll('feMergeNode')
		.data(['blur', 'SourceGraphic']).enter()
		.append('feMergeNode').attr('in', function(d){ return d; });

	// Build nodes array
	var nodes = [];
	var nodeMap = {};
	var edges = [];

	// Normalize: API returns data.nodes.products, JS expects flat arrays
	var productNodes  = (data.nodes && data.nodes.products)  ? data.nodes.products  : (data.product_nodes  || []);
	var categoryNodes = (data.nodes && data.nodes.categories) ? data.nodes.categories : (data.category_nodes || []);
	var languageNodes = (data.nodes && data.nodes.languages) ? data.nodes.languages : (data.language_nodes || []);
	var postNodes     = (data.nodes && data.nodes.posts) ? data.nodes.posts : [];
	var pageNodes     = (data.nodes && data.nodes.pages) ? data.nodes.pages : [];
	var segmentNodes  = (data.nodes && data.nodes.customer_segments) ? data.nodes.customer_segments : [];
	var graphEdges    = data.edges || [];

	// Apply preset filter (before view filter so empty views surface as empty)
	var presetFiltered = applyPreset(productNodes, postNodes, _kgCurrentPreset);
	productNodes = presetFiltered.products;
	postNodes    = presetFiltered.posts;

	// Apply view filter — only show one content type at a time
	if (viewFilter === 'product') {
		postNodes = [];
		pageNodes = [];
		segmentNodes = [];
	} else if (viewFilter === 'page') {
		productNodes = [];
		categoryNodes = [];
		postNodes = [];
		languageNodes = [];
		segmentNodes = [];
	} else if (viewFilter === 'customer') {
		productNodes = [];
		categoryNodes = [];
		postNodes = [];
		pageNodes = [];
		languageNodes = [];
	} else if (viewFilter === 'post') {
		productNodes = [];
		categoryNodes = [];
		pageNodes = [];
		segmentNodes = [];
		// Build post-specific language nodes from post translation data
		var postLangStats = {};
		postNodes.forEach(function(p) {
			var trans = p.translation || {};
			Object.keys(trans).forEach(function(lang) {
				if (!postLangStats[lang]) postLangStats[lang] = { translated: 0, missing: 0 };
				if (trans[lang] === 'completed') postLangStats[lang].translated++;
				else postLangStats[lang].missing++;
			});
		});
		// Replace language nodes with post-specific ones
		languageNodes = Object.keys(postLangStats).map(function(lang) {
			var s = postLangStats[lang];
			var total = s.translated + s.missing;
			return {
				id: 'lang_' + lang,
				code: lang,
				name: lang.toUpperCase(),
				coverage_pct: total > 0 ? Math.round(s.translated / total * 100) : 0,
				products_translated: s.translated,
				products_missing: s.missing
			};
		});
	}

	// Products
	(productNodes).forEach(function(p) {
		var node = {
			id: 'product:' + p.id,
			type: 'product',
			label: p.name,
			radius: 6 + Math.min(p.opportunity_score / 5, 14),
			score: p.opportunity_score,
			seo: p.seo,
			enrichment: p.enrichment,
			aeo: p.aeo,
			translation: p.translation,
			reviews: p.reviews,
			data: p
		};
		nodes.push(node);
		nodeMap[node.id] = node;
	});

	// Categories
	(categoryNodes).forEach(function(c) {
		var node = {
			id: 'category:' + c.id,
			type: 'category',
			label: c.name,
			radius: 12 + Math.min(c.product_count * 2, 20),
			productCount: c.product_count,
			data: c
		};
		nodes.push(node);
		nodeMap[node.id] = node;
	});

	// Languages
	(languageNodes).forEach(function(l) {
		var node = {
			id: l.id || ('lang_' + l.code),
			type: 'language',
			label: l.code.toUpperCase(),
			radius: 16,
			coverage: l.coverage_pct,
			data: l
		};
		nodes.push(node);
		nodeMap[node.id] = node;
	});

	// Posts
	(postNodes).forEach(function(p) {
		var score = 0;
		if (!p.seo || !p.seo.has_title) score += 15;
		if (!p.seo || !p.seo.has_description) score += 15;
		if (!p.has_featured_image) score += 10;
		if (p.is_stale) score += 10;
		if (p.word_count < 300) score += 10;
		// Add translation score
		var trans = p.translation || {};
		var missingLangs = [];
		Object.keys(trans).forEach(function(l) { if (trans[l] !== 'completed') { score += 5; missingLangs.push(l); } });

		var node = {
			id: 'post:' + p.id,
			type: 'post',
			label: p.title,
			radius: 5 + Math.min(score / 5, 10),
			score: score,
			seo: p.seo || {},
			translation: trans,
			data: p
		};
		nodes.push(node);
		nodeMap[node.id] = node;

		// Link to post categories
		var catNameMap = {};
		(p.category_names || []).forEach(function(c) { catNameMap[c.id] = c.name; });
		(p.categories || []).forEach(function(catId) {
			var catKey = 'post_category:' + catId;
			if (!nodeMap[catKey]) {
				var catName = catNameMap[catId] || ('Category #' + catId);
				var catNode = { id: catKey, type: 'category', label: catName, radius: 12, productCount: 0, data: { name: catName, id: catId } };
				nodes.push(catNode);
				nodeMap[catKey] = catNode;
			}
			edges.push({ source: node.id, target: catKey, type: 'belongs_to' });
		});

		// Link to language nodes (translation edges)
		Object.keys(trans).forEach(function(lang) {
			var langKey = 'lang_' + lang;
			if (nodeMap[langKey]) {
				edges.push({
					source: node.id,
					target: langKey,
					type: trans[lang] === 'completed' ? 'translated_to' : 'missing_translation'
				});
			}
		});
	});

	// Customer segments (radial cluster by cohort)
	(segmentNodes).forEach(function(s) {
		var count = s.count || 0;
		var r = 14 + Math.min(count / 3, 30); // scale by customer count
		var node = {
			id: s.id || ('segment_' + s.segment),
			type: 'segment',
			label: s.label + ' (' + count + ')',
			radius: r,
			score: count,
			data: s
		};
		nodes.push(node);
		nodeMap[node.id] = node;
	});

	// Pages (parent-child hierarchy)
	(pageNodes).forEach(function(p) {
		var score = 0;
		if (p.content_length < 300) score += 10;
		if (p.template === 'default' || !p.template) score += 2;
		var r = 7;
		if (p.is_front_page)  r = 16;
		else if (p.is_shop_page) r = 14;
		else if (p.is_blog_page) r = 12;
		else if (p.parent_id === 0) r = 10;
		var node = {
			id: 'page:' + p.id,
			type: 'page',
			label: p.title || ('Page #' + p.id),
			radius: r,
			score: score,
			data: p
		};
		nodes.push(node);
		nodeMap[node.id] = node;
	});
	// Page parent-child edges (second pass so both endpoints exist)
	(pageNodes).forEach(function(p) {
		if (p.parent_id && nodeMap['page:' + p.parent_id]) {
			edges.push({ source: 'page:' + p.id, target: 'page:' + p.parent_id, type: 'child_of' });
		}
	});

	// Edges
	(graphEdges).forEach(function(e) {
		if (nodeMap[e.source] && nodeMap[e.target]) {
			edges.push({
				source: e.source,
				target: e.target,
				type: e.type
			});
		}
	});

	// Color scale
	function nodeColor(d) {
		if (d.type === 'category') return '#f59e0b';
		if (d.type === 'language') return '#2563eb';
		if (d.type === 'post') {
			if (d.seo && d.seo.has_title && d.seo.has_description) return '#7c3aed';
			if (d.score > 20) return '#dc2626';
			return '#8b5cf6';
		}
		if (d.type === 'page') {
			if (d.data && d.data.is_front_page) return '#0ea5e9';
			if (d.data && d.data.is_shop_page)  return '#14b8a6';
			if (d.data && d.data.is_blog_page)  return '#0891b2';
			if (d.score > 10) return '#f59e0b';
			return '#06b6d4';
		}
		if (d.type === 'segment') {
			var seg = (d.data && d.data.segment) || '';
			if (seg === 'vip')       return '#a855f7'; // purple — highest value
			if (seg === 'loyal')     return '#16a34a'; // green — healthy recurring
			if (seg === 'active')    return '#22c55e'; // light green
			if (seg === 'new')       return '#3b82f6'; // blue — potential
			if (seg === 'one_time')  return '#f59e0b'; // amber — needs win-back
			if (seg === 'at_risk')   return '#f97316'; // orange — urgent
			if (seg === 'dormant')   return '#ef4444'; // red — lost unless revived
			if (seg === 'lost')      return '#6b7280'; // gray — churned
			return '#8b5cf6';
		}
		if (d.type === 'product') {
			if (d.seo && d.seo.has_title && d.seo.has_description && d.enrichment && d.enrichment.status === 'completed') return '#16a34a';
			if (d.score > 30) return '#dc2626';
			if (d.score > 15) return '#f59e0b';
			return '#6366f1';
		}
		return '#6b7280';
	}

	function edgeColor(d) {
		if (d.type === 'missing_translation') return '#dc262640';
		if (d.type === 'translated_to') return '#16a34a30';
		if (d.type === 'child_of') return '#06b6d440';
		if (d.type === 'upsell')     return '#a855f750';
		if (d.type === 'cross_sell') return '#f59e0b50';
		return '#e5e7eb60';
	}

	// Restore pinned positions from previous sessions (P3 #20)
	kgLayoutApply(nodes, viewFilter);

	// ── Force simulation — tuned for graph size ──
	// Large graphs converge too slowly with the default alphaDecay (0.025), so we
	// step it up as node count grows. Collision padding also shrinks to let the
	// layout compact a bit more when there are many products per category.
	var nodeCount = nodes.length;
	var decay      = nodeCount > 800 ? 0.06 : nodeCount > 400 ? 0.045 : nodeCount > 200 ? 0.035 : 0.025;
	var collPad    = nodeCount > 400 ? 1 : 3;
	var collStr    = nodeCount > 400 ? 0.6 : 0.7;
	var chargeProd = nodeCount > 400 ? -40 : -60;

	var simulation = d3.forceSimulation(nodes)
		.force('link', d3.forceLink(edges).id(function(d){ return d.id; }).distance(function(d){
			if (d.type === 'belongs_to') return 50;
			if (d.type === 'translated_to' || d.type === 'missing_translation') return 90;
			if (d.type === 'child_of') return 70;
			return 60;
		}).strength(function(d){
			if (d.type === 'belongs_to') return 0.6;
			if (d.type === 'child_of') return 0.5;
			return 0.3;
		}))
		.force('charge', d3.forceManyBody().strength(function(d){
			if (d.type === 'category') return -250;
			if (d.type === 'language') return -400;
			if (d.type === 'segment')  return -500;
			return chargeProd;
		}))
		.force('center', d3.forceCenter(width / 2, height / 2))
		.force('collision', d3.forceCollide().radius(function(d){ return d.radius + collPad; }).strength(collStr))
		.alphaDecay(decay);

	// Zoom
	var g = svg.append('g');
	var zoom = d3.zoom()
		.scaleExtent([0.1, 5])
		.filter(function(event) {
			// Don't zoom on node clicks — let click event through
			if (event.type === 'dblclick') return true;
			if (event.type === 'mousedown' && event.target.closest('.kg-node')) return false;
			return !event.ctrlKey && !event.button;
		})
		.on('zoom', function(event) {
			g.attr('transform', event.transform);
		});
	svg.call(zoom)
		.on('dblclick.zoom', null); // disable double-click zoom

	// Links
	var link = g.append('g').attr('class', 'kg-links')
		.selectAll('line')
		.data(edges)
		.enter().append('line')
		.attr('stroke', edgeColor)
		.attr('stroke-width', function(d){ return d.type === 'belongs_to' ? 1.5 : 1; })
		.attr('stroke-dasharray', function(d){ return d.type === 'missing_translation' ? '4,4' : 'none'; })
		.style('opacity', 0)
		.transition().duration(800).delay(function(d,i){ return i * 2; })
		.style('opacity', 1);

	// Re-select without transition for tick
	link = g.selectAll('.kg-links line');

	// Nodes
	var node = g.append('g').attr('class', 'kg-nodes')
		.selectAll('g')
		.data(nodes)
		.enter().append('g')
		.attr('class', function(d){ return 'kg-node kg-node-' + d.type; })
		.attr('tabindex', 0)
		.attr('role', 'button')
		.attr('aria-label', function(d){ return d.type + ': ' + (d.label || d.id); })
		.style('cursor', 'pointer')
		.call(d3.drag()
			.on('start', function(event, d) {
				if (!event.active) simulation.alphaTarget(0.3).restart();
				d.fx = d.x; d.fy = d.y;
			})
			.on('drag', function(event, d) {
				d.fx = event.x; d.fy = event.y;
			})
			.on('end', function(event, d) {
				if (!event.active) simulation.alphaTarget(0);
				// Persist the pinned position for this node (per view)
				kgLayoutSave(viewFilter, d.id, d.fx, d.fy);
				// Keep node fixed where the user dropped it
				// (d.fx / d.fy stay set — user intent is "I placed this here").
			}));

	// Node circles with enter animation
	node.append('circle')
		.attr('r', 0)
		.attr('fill', nodeColor)
		.attr('stroke', '#fff')
		.attr('stroke-width', 2)
		.style('filter', function(d){ return d.type !== 'product' ? 'url(#glow)' : 'none'; })
		.transition().duration(600).delay(function(d,i){ return 300 + i * 10; })
		.attr('r', function(d){ return d.radius; });

	// Pulse animation for high-opportunity nodes
	node.filter(function(d){ return d.score > 25; })
		.append('circle')
		.attr('r', function(d){ return d.radius + 4; })
		.attr('fill', 'none')
		.attr('stroke', '#dc2626')
		.attr('stroke-width', 1.5)
		.attr('class', 'kg-pulse-ring');

	// Labels for categories and languages
	node.filter(function(d){ return d.type !== 'product'; })
		.append('text')
		.attr('dy', function(d){ return d.radius + 14; })
		.attr('text-anchor', 'middle')
		.attr('class', 'kg-label')
		.text(function(d){ return d.label; })
		.style('opacity', 0)
		.transition().duration(400).delay(800)
		.style('opacity', 1);

	// Tooltip
	var tooltip = document.getElementById('kg-tooltip');
	var tooltipTimeout = null;
	var hoveredNode = null;

	function showTooltip(event, d) {
		clearTimeout(tooltipTimeout);
		hoveredNode = d;
		tooltip.innerHTML = buildTooltip(d);
		tooltip.style.display = 'block';
		tooltip.style.opacity = '1';

		// Position near the node
		var rect = svg.node().getBoundingClientRect();
		var tx = event.clientX - rect.left + rect.left + 14;
		var ty = event.clientY - rect.top + rect.top - 8;
		var tw = 260;
		if (tx + tw > window.innerWidth - 20) tx = tx - tw - 28;
		if (ty < 50) ty = ty + 20;
		tooltip.style.left = tx + 'px';
		tooltip.style.top = ty + 'px';
	}

	function hideTooltip() {
		tooltipTimeout = setTimeout(function() {
			tooltip.style.opacity = '0';
			setTimeout(function(){ tooltip.style.display = 'none'; }, 150);
			hoveredNode = null;
			node.transition().duration(300).style('opacity', 1);
			node.selectAll('circle')
				.transition().duration(200)
				.attr('r', function(d){ return d.radius; })
				.attr('stroke-width', 2);
			link.transition().duration(300)
				.style('opacity', 1)
				.attr('stroke-width', function(d){ return d.type === 'belongs_to' ? 1.5 : 1; });
		}, 300);
	}

	// Keep tooltip open when hovering over it
	tooltip.addEventListener('mouseenter', function() { clearTimeout(tooltipTimeout); });
	tooltip.addEventListener('mouseleave', function() { hideTooltip(); });

	node.on('mouseover', function(event, d) {
		showTooltip(event, d);

		d3.select(this).select('circle')
			.transition().duration(200)
			.attr('r', d.radius * 1.3)
			.attr('stroke-width', 3);

		// Highlight connected edges
		link.transition().duration(200)
			.style('opacity', function(l) {
				return (l.source.id === d.id || l.target.id === d.id) ? 1 : 0.1;
			})
			.attr('stroke-width', function(l) {
				return (l.source.id === d.id || l.target.id === d.id) ? 2.5 : 0.5;
			});

		// Dim other nodes
		node.transition().duration(200)
			.style('opacity', function(n) {
				if (n.id === d.id) return 1;
				var connected = edges.some(function(e) {
					return (e.source.id === d.id && e.target.id === n.id) ||
					       (e.target.id === d.id && e.source.id === n.id);
				});
				return connected ? 1 : 0.2;
			});
	})
	.on('mousemove', function(event, d) {
		showTooltip(event, d);
	})
	.on('mouseout', function() {
		hideTooltip();
	})
	.on('click', function(event, d) {
		event.stopPropagation();
		showDetailPanel(d);
	});

	// Tick
	simulation.on('tick', function() {
		link
			.attr('x1', function(d){ return d.source.x; })
			.attr('y1', function(d){ return d.source.y; })
			.attr('x2', function(d){ return d.target.x; })
			.attr('y2', function(d){ return d.target.y; });

		node.attr('transform', function(d){ return 'translate(' + d.x + ',' + d.y + ')'; });
	});

	// Zoom controls
	document.getElementById('kg-zoom-in').onclick = function() {
		svg.transition().duration(300).call(zoom.scaleBy, 1.4);
	};
	document.getElementById('kg-zoom-out').onclick = function() {
		svg.transition().duration(300).call(zoom.scaleBy, 0.7);
	};
	document.getElementById('kg-zoom-fit').onclick = function() {
		svg.transition().duration(500).call(zoom.transform, d3.zoomIdentity);
	};
	var resetBtn = document.getElementById('kg-layout-reset');
	if (resetBtn) {
		resetBtn.onclick = function() {
			kgLayoutReset(viewFilter);
			// Unpin everything and let the simulation re-flow
			nodes.forEach(function(n) { n.fx = null; n.fy = null; });
			simulation.alpha(0.8).restart();
		};
	}

	// View switch is handled outside buildGraph — see initViewSwitch()

	// Store references for resize
	window._kgSimulation = simulation;
	window._kgSvg = svg;
	window._kgZoom = zoom;
}

// ── Tooltip Builder ──
function buildTooltip(d) {
	var h = '<div class="kg-tt-header"><strong>' + escHtml(d.label) + '</strong><span class="kg-tt-type">' + d.type + '</span></div>';
	if (d.type === 'product') {
		var p = d.data;
		h += '<div class="kg-tt-row">Score: <b>' + d.score + '</b></div>';
		h += '<div class="kg-tt-row">SEO: ' + (p.seo.has_title ? '✓' : '✗') + ' title, ' + (p.seo.has_description ? '✓' : '✗') + ' desc</div>';
		h += '<div class="kg-tt-row">Enriched: ' + p.enrichment.status + '</div>';
		if (p.reviews) h += '<div class="kg-tt-row">Reviews: ' + p.reviews.count + ' (★' + (p.reviews.avg_rating || 0).toFixed(1) + ')</div>';
		var langs = Object.keys(p.translation || {});
		if (langs.length) {
			h += '<div class="kg-tt-row">Translation: ';
			langs.forEach(function(l) {
				var st = p.translation[l];
				var icon = st === 'completed' ? '✓' : (st === 'processing' ? '⟳' : '✗');
				h += '<span class="kg-tt-lang ' + st + '">' + l.toUpperCase() + ' ' + icon + '</span> ';
			});
			h += '</div>';
		}
	} else if (d.type === 'category') {
		h += '<div class="kg-tt-row">Products: <b>' + d.productCount + '</b></div>';
		h += '<div class="kg-tt-row">SEO: ' + (d.data.seo_coverage_pct || 0).toFixed(0) + '%</div>';
		h += '<div class="kg-tt-row">Enriched: ' + (d.data.enrichment_pct || 0).toFixed(0) + '%</div>';
	} else if (d.type === 'language') {
		h += '<div class="kg-tt-row">Coverage: <b>' + (d.coverage || 0).toFixed(0) + '%</b></div>';
		h += '<div class="kg-tt-row">Translated: ' + (d.data.products_translated || 0) + '</div>';
		h += '<div class="kg-tt-row">Missing: ' + (d.data.products_missing || 0) + '</div>';
	}
	return h;
}

// ── Detail Panel ──
function showDetailPanel(d) {
	var panel = document.getElementById('kg-detail-panel');
	var content = document.getElementById('kg-detail-content');
	var h = '';

	if (d.type === 'product') {
		var p = d.data;

		// ── Calculate health ──
		var total = 0, done = 0;
		total += 3; done += (p.seo.has_title?1:0) + (p.seo.has_description?1:0) + (p.seo.has_focus_kw?1:0);
		total += 3; done += (p.aeo.has_faq?1:0) + (p.aeo.has_howto?1:0) + (p.aeo.has_schema?1:0);
		total += 1; done += (p.enrichment.status === 'completed'?1:0);
		var langKeys = Object.keys(p.translation || {});
		total += langKeys.length;
		langKeys.forEach(function(l) { if (p.translation[l]==='completed') done++; });
		var healthPct = total > 0 ? Math.round(done/total*100) : 0;
		var healthCls = healthPct >= 80 ? 'good' : healthPct >= 40 ? 'warn' : 'bad';

		// ── Header: Name + Score Bar ──
		h += '<h3 class="kg-p-name">' + escHtml(p.name) + '</h3>';
		h += '<div class="kg-p-meta">#' + p.id + (p.sku ? ' &middot; ' + p.sku : '') + '</div>';
		h += '<div class="kg-p-health kg-h-' + healthCls + '">';
		h += '<div class="kg-p-health-bar" style="width:' + healthPct + '%"></div>';
		h += '<span class="kg-p-health-label">Health ' + healthPct + '%</span></div>';

		// ── Status Row: compact chips ──
		h += '<div class="kg-p-chips">';
		h += statusChip(p.seo.has_title, 'Title');
		h += statusChip(p.seo.has_description, 'Meta Desc');
		h += statusChip(p.seo.has_focus_kw, 'Keyword');
		h += statusChip(p.aeo.has_faq, 'FAQ');
		h += statusChip(p.aeo.has_howto, 'HowTo');
		h += statusChip(p.aeo.has_schema, 'Schema');
		h += statusChip(p.enrichment.status === 'completed', 'Enriched');
		langKeys.forEach(function(lang) {
			h += statusChip(p.translation[lang] === 'completed', lang.toUpperCase());
		});
		h += '</div>';

		// ── Recommendations (only missing items) ──
		var recs = [];
		if (!p.seo.has_title || !p.seo.has_description) {
			var miss = [];
			if (!p.seo.has_title) miss.push('title');
			if (!p.seo.has_description) miss.push('description');
			recs.push({a:'enrich', l:'Optimize SEO', d:'Missing ' + miss.join(' & '), p:'high'});
		} else if (!p.seo.has_focus_kw) {
			recs.push({a:'enrich', l:'Add Focus Keyword', d:'SEO meta OK, keyword missing', p:'medium'});
		}
		if (p.enrichment.status !== 'completed') recs.push({a:'enrich', l:'AI Enrichment', d:'Generate rich product content', p:'high'});
		if (!p.aeo.has_faq) recs.push({a:'faq', l:'Generate FAQ', d:'Rich snippets in search results', p:'medium'});
		if (!p.aeo.has_howto) recs.push({a:'howto', l:'Generate HowTo', d:'How-to rich result cards', p:'low'});
		var missingLangs = [];
		langKeys.forEach(function(l) { if (p.translation[l] !== 'completed') missingLangs.push(l); });
		if (missingLangs.length) recs.push({a:'translate', l:'Translate ' + missingLangs.map(function(x){return x.toUpperCase();}).join(', '), d:missingLangs.length + ' language' + (missingLangs.length>1?'s':''), p:'medium', langs:missingLangs.join(',')});

		// Sort: high > medium > low
		var pri = {high:0,medium:1,low:2};
		recs.sort(function(a,b){ return (pri[a.p]||9)-(pri[b.p]||9); });

		if (recs.length > 0) {
			h += '<div class="kg-p-section"><div class="kg-p-section-title">Recommendations</div>';
			recs.forEach(function(r) {
				var extra = r.langs ? ",'" + r.langs + "'" : '';
				h += '<button class="kg-rec kg-rec-' + r.p + '" onclick="kgAction(\'' + r.a + '\',' + p.id + ',this' + extra + ')">';
				h += '<span class="kg-rec-dot"></span>';
				h += '<span class="kg-rec-body"><strong>' + r.l + '</strong><br><small>' + r.d + '</small></span>';
				h += '</button>';
			});
			h += '</div>';
		} else {
			h += '<div class="kg-p-allgood">All optimizations complete</div>';
		}

		// ── Footer ──
		h += '<div class="kg-p-footer">';
		h += '<a href="/wp-admin/post.php?post=' + p.id + '&action=edit" target="_blank" class="kg-btn kg-btn-primary">Edit Product</a>';
		if (p.permalink) h += '<a href="' + p.permalink + '" target="_blank" class="kg-btn kg-btn-outline">View</a>';
		h += '</div>';

	} else if (d.type === 'post') {
		var p = d.data;
		var trans = p.translation || {};
		var langKeys = Object.keys(trans);

		// Health calc — include translations
		var total = 0, done = 0;
		total += 2; done += (p.seo && p.seo.has_title ? 1 : 0) + (p.seo && p.seo.has_description ? 1 : 0);
		total += 1; done += (p.has_featured_image ? 1 : 0);
		total += 1; done += (p.word_count >= 300 ? 1 : 0);
		total += 1; done += (!p.is_stale ? 1 : 0);
		total += langKeys.length;
		langKeys.forEach(function(l) { if (trans[l] === 'completed') done++; });
		var healthPct = total > 0 ? Math.round(done / total * 100) : 0;
		var healthCls = healthPct >= 80 ? 'good' : healthPct >= 40 ? 'warn' : 'bad';

		h += '<div class="kg-p-type-badge" style="background:#8b5cf6;color:#fff">Blog Post</div>';
		h += '<h3 class="kg-p-name">' + escHtml(p.title) + '</h3>';
		h += '<div class="kg-p-meta">#' + p.id + ' &middot; ' + (p.author_name || '') + ' &middot; ~' + (p.word_count || 0) + ' words</div>';

		h += '<div class="kg-p-health kg-h-' + healthCls + '">';
		h += '<div class="kg-p-health-bar" style="width:' + healthPct + '%"></div>';
		h += '<span class="kg-p-health-label">Health ' + healthPct + '%</span></div>';

		h += '<div class="kg-p-chips">';
		h += statusChip(p.seo && p.seo.has_title, 'Title');
		h += statusChip(p.seo && p.seo.has_description, 'Meta Desc');
		h += statusChip(p.seo && p.seo.has_focus_kw, 'Keyword');
		h += statusChip(p.has_featured_image, 'Image');
		h += statusChip(p.word_count >= 300, 'Content');
		h += statusChip(!p.is_stale, 'Fresh');
		langKeys.forEach(function(lang) {
			h += statusChip(trans[lang] === 'completed', lang.toUpperCase());
		});
		h += '</div>';

		// Recommendations
		var recs = [];
		if (!p.seo || !p.seo.has_title || !p.seo.has_description) {
			var miss = [];
			if (!p.seo || !p.seo.has_title) miss.push('title');
			if (!p.seo || !p.seo.has_description) miss.push('description');
			recs.push({ p: 'high', l: 'Add SEO Meta', d: 'Missing ' + miss.join(' & ') });
		}
		var missingPostLangs = [];
		langKeys.forEach(function(l) { if (trans[l] !== 'completed') missingPostLangs.push(l); });
		if (missingPostLangs.length) {
			recs.push({a:'translate', l:'Translate ' + missingPostLangs.map(function(x){return x.toUpperCase();}).join(', '), d:missingPostLangs.length + ' language' + (missingPostLangs.length>1?'s':''), p:'medium', langs:missingPostLangs.join(',')});
		}
		if (!p.has_featured_image) recs.push({ p: 'medium', l: 'Add Featured Image', d: 'Improves social sharing & SEO' });
		if (p.word_count < 300) recs.push({ p: 'medium', l: 'Expand Content', d: 'Only ~' + p.word_count + ' words — aim for 600+' });
		if (p.is_stale) recs.push({ p: 'low', l: 'Refresh Content', d: 'Last updated ' + p.days_since_modified + ' days ago' });

		var pri = {high:0,medium:1,low:2};
		recs.sort(function(a,b){ return (pri[a.p]||9)-(pri[b.p]||9); });

		if (recs.length > 0) {
			h += '<div class="kg-p-section"><div class="kg-p-section-title">Recommendations</div>';
			recs.forEach(function(r) {
				if (r.a) {
					var extra = r.langs ? ",'" + r.langs + "'" : '';
					h += '<button class="kg-rec kg-rec-' + r.p + '" onclick="kgAction(\'' + r.a + '\',' + p.id + ',this' + extra + ')">';
				} else {
					h += '<div class="kg-rec kg-rec-' + r.p + '">';
				}
				h += '<span class="kg-rec-dot"></span>';
				h += '<span class="kg-rec-body"><strong>' + r.l + '</strong><br><small>' + r.d + '</small></span>';
				h += r.a ? '</button>' : '</div>';
			});
			h += '</div>';
		} else {
			h += '<div class="kg-p-allgood">All optimizations complete</div>';
		}

		h += '<div class="kg-p-footer">';
		h += '<a href="/wp-admin/post.php?post=' + p.id + '&action=edit" target="_blank" class="kg-btn kg-btn-primary">Edit Post</a>';
		h += '</div>';

	} else if (d.type === 'category') {
		var c = d.data;

		// Health calc for category
		var cSeo = c.seo_coverage_pct || 0;
		var cEnrich = c.enrichment_pct || 0;
		var cTrans = 0, cTransCount = 0;
		Object.keys(c.translation_pct || {}).forEach(function(l) { cTrans += (c.translation_pct[l] || 0); cTransCount++; });
		var cAvg = cTransCount > 0 ? Math.round((cSeo + cEnrich + (cTrans / cTransCount)) / 3) : Math.round((cSeo + cEnrich) / 2);
		var cCls = cAvg >= 80 ? 'good' : cAvg >= 40 ? 'warn' : 'bad';

		h += '<div class="kg-p-type-badge kg-type-category">Category</div>';
		h += '<h3 class="kg-p-name">' + escHtml(c.name) + '</h3>';
		h += '<div class="kg-p-meta">' + c.product_count + ' products</div>';

		h += '<div class="kg-p-health kg-h-' + cCls + '">';
		h += '<div class="kg-p-health-bar" style="width:' + cAvg + '%"></div>';
		h += '<span class="kg-p-health-label">Health ' + cAvg + '%</span></div>';

		// SEO & Enrichment chips
		h += '<div class="kg-p-chips">';
		h += statusChip(cSeo >= 80, 'SEO ' + cSeo + '%');
		h += statusChip(cEnrich >= 80, 'Enriched ' + cEnrich + '%');
		Object.keys(c.translation_pct || {}).forEach(function(l) {
			var tp = c.translation_pct[l] || 0;
			h += statusChip(tp >= 95, l.toUpperCase() + ' ' + tp + '%');
		});
		h += '</div>';

		h += '<div class="kg-p-section"><div class="kg-p-section-title">Coverage Breakdown</div>';
		h += progressBar('SEO', cSeo);
		h += progressBar('Enrichment', cEnrich);
		Object.keys(c.translation_pct || {}).forEach(function(l) {
			h += progressBar(l.toUpperCase(), c.translation_pct[l] || 0);
		});
		h += '</div>';

		// ── Category Recommendations ──
		var catRecs = [];
		if (cSeo < 50) {
			catRecs.push({ p: 'high', l: 'Improve SEO Coverage', d: 'Only ' + cSeo + '% of products have SEO meta — enrich products in this category' });
		} else if (cSeo < 80) {
			catRecs.push({ p: 'medium', l: 'Complete SEO Coverage', d: cSeo + '% covered — a few products still missing SEO meta' });
		}
		if (cEnrich < 50) {
			catRecs.push({ p: 'high', l: 'AI Enrich Products', d: 'Only ' + cEnrich + '% enriched — generate descriptions, FAQ, schema' });
		} else if (cEnrich < 80) {
			catRecs.push({ p: 'medium', l: 'Complete Enrichment', d: cEnrich + '% enriched — finish remaining products' });
		}
		Object.keys(c.translation_pct || {}).forEach(function(l) {
			var tp = c.translation_pct[l] || 0;
			if (tp < 80) {
				catRecs.push({ p: 'high', l: 'Translate to ' + l.toUpperCase(), d: 'Only ' + tp + '% translated — ' + Math.round(c.product_count * (100 - tp) / 100) + ' products missing' });
			} else if (tp < 100) {
				catRecs.push({ p: 'medium', l: 'Complete ' + l.toUpperCase() + ' Translation', d: tp + '% done — almost there' });
			}
		});

		if (catRecs.length > 0) {
			var pri = {high:0,medium:1,low:2};
			catRecs.sort(function(a,b){ return (pri[a.p]||9)-(pri[b.p]||9); });
			h += '<div class="kg-p-section"><div class="kg-p-section-title">Recommendations</div>';
			catRecs.forEach(function(r) {
				h += '<div class="kg-rec kg-rec-' + r.p + '">';
				h += '<span class="kg-rec-dot"></span>';
				h += '<span class="kg-rec-body"><strong>' + r.l + '</strong><br><small>' + r.d + '</small></span>';
				h += '</div>';
			});
			h += '</div>';
		} else {
			h += '<div class="kg-p-allgood">All optimizations complete for this category</div>';
		}

		// ── Category Batch Actions ──
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Batch Actions</div>';
		if (cEnrich < 100) {
			h += '<button class="kg-rec kg-rec-medium" onclick="kgAction(\'enrich_category\',' + c.id + ',this)">';
			h += '<span class="kg-rec-dot"></span>';
			h += '<span class="kg-rec-body"><strong>Enrich all products in this category</strong><br><small>Queues every product missing enrichment</small></span>';
			h += '</button>';
		}
		Object.keys(c.translation_pct || {}).forEach(function(l) {
			var tp = c.translation_pct[l] || 0;
			if (tp < 100) {
				h += '<button class="kg-rec kg-rec-medium" onclick="kgAction(\'translate_category\',' + c.id + ',this,\'' + l + '\')">';
				h += '<span class="kg-rec-dot"></span>';
				h += '<span class="kg-rec-body"><strong>Translate category to ' + l.toUpperCase() + '</strong><br><small>Queues every product missing ' + l.toUpperCase() + ' translation</small></span>';
				h += '</button>';
			}
		});
		h += '</div>';

	} else if (d.type === 'page') {
		var pg = d.data;
		var score = d.score || 0;
		var healthPct = Math.max(0, 100 - score * 5);
		var healthCls = healthPct >= 80 ? 'good' : healthPct >= 40 ? 'warn' : 'bad';

		var pageRole = 'Page';
		if (pg.is_front_page) pageRole = 'Home Page';
		else if (pg.is_shop_page) pageRole = 'Shop Page';
		else if (pg.is_blog_page) pageRole = 'Blog Index';
		else if (pg.parent_id === 0) pageRole = 'Top-Level Page';
		else pageRole = 'Child Page';

		h += '<div class="kg-p-type-badge" style="background:#06b6d4;color:#fff">' + pageRole + '</div>';
		h += '<h3 class="kg-p-name">' + escHtml(pg.title || ('Page #' + pg.id)) + '</h3>';
		h += '<div class="kg-p-meta">#' + pg.id;
		if (pg.template && pg.template !== 'default') h += ' &middot; Template: ' + escHtml(pg.template);
		if (pg.menu_order) h += ' &middot; Order: ' + pg.menu_order;
		h += '</div>';

		h += '<div class="kg-p-health kg-h-' + healthCls + '">';
		h += '<div class="kg-p-health-bar" style="width:' + healthPct + '%"></div>';
		h += '<span class="kg-p-health-label">Health ' + healthPct + '%</span></div>';

		h += '<div class="kg-p-chips">';
		h += statusChip(pg.content_length >= 300, 'Content ' + (pg.content_length || 0));
		h += statusChip(pg.is_front_page, 'Homepage');
		h += statusChip(pg.is_shop_page, 'Shop');
		h += statusChip(pg.is_blog_page, 'Blog');
		h += statusChip((pg.children_ids || []).length > 0, (pg.children_ids || []).length + ' child' + ((pg.children_ids || []).length !== 1 ? 'ren' : ''));
		h += '</div>';

		// Recommendations
		var pageRecs = [];
		if ((pg.content_length || 0) < 300 && !pg.is_front_page) {
			pageRecs.push({ p: 'medium', l: 'Expand content', d: 'Only ' + (pg.content_length || 0) + ' chars — aim for 800+' });
		}
		if (pg.parent_id === 0 && (pg.children_ids || []).length === 0 && !pg.is_front_page && !pg.is_shop_page && !pg.is_blog_page) {
			pageRecs.push({ p: 'low', l: 'Orphan top-level page', d: 'Not referenced by other pages — check menu linking' });
		}

		if (pageRecs.length > 0) {
			h += '<div class="kg-p-section"><div class="kg-p-section-title">Recommendations</div>';
			pageRecs.forEach(function(r) {
				h += '<div class="kg-rec kg-rec-' + r.p + '">';
				h += '<span class="kg-rec-dot"></span>';
				h += '<span class="kg-rec-body"><strong>' + r.l + '</strong><br><small>' + r.d + '</small></span>';
				h += '</div>';
			});
			h += '</div>';
		}

		h += '<div class="kg-p-footer">';
		h += '<a href="/wp-admin/post.php?post=' + pg.id + '&action=edit" target="_blank" class="kg-btn kg-btn-primary">Edit Page</a>';
		if (pg.slug) h += '<a href="/?page_id=' + pg.id + '" target="_blank" class="kg-btn kg-btn-outline">View</a>';
		h += '</div>';

	} else if (d.type === 'language') {
		var l = d.data;
		var covPct = l.coverage_pct || 0;
		var covCls = covPct >= 80 ? 'good' : covPct >= 40 ? 'warn' : 'bad';
		var translated = l.products_translated || 0;
		var missing = l.products_missing || 0;
		var total = translated + missing;

		h += '<div class="kg-p-type-badge kg-type-language">Language</div>';
		h += '<h3 class="kg-p-name">' + (l.name || l.code.toUpperCase()) + '</h3>';
		h += '<div class="kg-p-meta">' + total + ' total products</div>';

		h += '<div class="kg-p-health kg-h-' + covCls + '">';
		h += '<div class="kg-p-health-bar" style="width:' + covPct + '%"></div>';
		h += '<span class="kg-p-health-label">Coverage ' + covPct.toFixed(0) + '%</span></div>';

		// Stats
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Translation Status</div>';
		h += '<div class="kg-p-stat-row"><span>Translated</span><strong style="color:var(--lp-success)">' + translated + '</strong></div>';
		h += '<div class="kg-p-stat-row"><span>Missing</span><strong' + (missing > 0 ? ' class="kg-text-error"' : '') + '>' + missing + '</strong></div>';
		h += '<div class="kg-p-stat-row"><span>Total</span><strong>' + total + '</strong></div>';
		h += '</div>';

		// Recommendations
		if (missing > 0) {
			h += '<div class="kg-p-section"><div class="kg-p-section-title">Recommendations</div>';
			h += '<button class="kg-rec kg-rec-high" onclick="kgAction(\'translate_lang\',0,this,\'' + l.code + '\')">';
			h += '<span class="kg-rec-dot"></span>';
			h += '<span class="kg-rec-body"><strong>Translate ' + missing + ' missing products</strong><br><small>Complete ' + l.code.toUpperCase() + ' coverage to 100%</small></span>';
			h += '</button></div>';
		} else {
			h += '<div class="kg-p-allgood">Full coverage — all products translated</div>';
		}
	} else if (d.type === 'segment') {
		var s = d.data;
		var count = s.count || 0;
		var share = s.share_pct || 0;
		var seg = s.segment || '';

		// Segment descriptors + recommendations
		var segInfo = {
			vip:      { desc: 'Your highest-value customers — repeat, high AOV.', health: 'good',  priority: 'Reward them.' },
			loyal:    { desc: 'Regular repeat buyers over time.', health: 'good',  priority: 'Keep them engaged.' },
			active:   { desc: 'Recently active customers, still converting.', health: 'good',  priority: 'Nudge toward loyalty.' },
			new:      { desc: 'First-time buyers still in their initial window.', health: 'warn',  priority: 'Onboard quickly — the window to a 2nd purchase is narrow.' },
			one_time: { desc: 'Bought once, never returned. The biggest retention opportunity.', health: 'warn', priority: 'Win-back campaign — discount, new arrivals, or category-based recommendation.' },
			at_risk:  { desc: 'Loyal customers whose last order is ageing. Re-engage now.', health: 'warn',  priority: 'Targeted reminder before they churn.' },
			dormant:  { desc: 'Long inactive. Likely to stay that way unless reactivated.', health: 'bad',   priority: 'Reactivation campaign or exclude from lists.' },
			lost:     { desc: 'No engagement for a long period. Usually unrecoverable.', health: 'bad',   priority: 'Archive or remove from active lists.' }
		};
		var info = segInfo[seg] || { desc: 'Customer segment.', health: 'warn', priority: 'Review segment definition.' };

		h += '<div class="kg-p-type-badge" style="background:' + (function(){
			if (seg === 'vip') return '#a855f7';
			if (seg === 'loyal' || seg === 'active') return '#16a34a';
			if (seg === 'new') return '#3b82f6';
			if (seg === 'one_time' || seg === 'at_risk') return '#f59e0b';
			if (seg === 'dormant' || seg === 'lost') return '#ef4444';
			return '#8b5cf6';
		})() + ';color:#fff">Customer Segment</div>';
		h += '<h3 class="kg-p-name">' + escHtml(s.label) + '</h3>';
		h += '<div class="kg-p-meta">' + count + ' customer' + (count !== 1 ? 's' : '') + ' &middot; ' + share.toFixed(1) + '% of total</div>';

		h += '<div class="kg-p-health kg-h-' + info.health + '">';
		h += '<div class="kg-p-health-bar" style="width:' + Math.min(100, share) + '%"></div>';
		h += '<span class="kg-p-health-label">' + share.toFixed(1) + '% share</span></div>';

		h += '<div class="kg-p-section"><div class="kg-p-section-title">About this segment</div>';
		h += '<p style="margin:8px 0;color:var(--lp-text);font-size:13px;line-height:1.5;">' + escHtml(info.desc) + '</p>';
		h += '<p style="margin:8px 0;color:var(--lp-text-muted);font-size:12px;font-style:italic;">→ ' + escHtml(info.priority) + '</p>';
		h += '</div>';

		// Segment-specific recommendations
		var segRecs = [];
		if (seg === 'one_time' && count > 10) {
			segRecs.push({ p: 'high', l: 'Launch win-back campaign', d: count + ' customers never returned. A single re-engagement email could lift repeat rate substantially.' });
		} else if (seg === 'new' && count > 0) {
			segRecs.push({ p: 'high', l: 'Send onboarding sequence', d: 'Trigger welcome flow with "What to expect next" + category recommendation.' });
		} else if (seg === 'at_risk' && count > 0) {
			segRecs.push({ p: 'high', l: 'Re-engagement email', d: 'They loved you once. Offer a loyalty perk before they drift to dormant.' });
		} else if (seg === 'dormant' && count > 0) {
			segRecs.push({ p: 'medium', l: 'Exclusive reactivation offer', d: 'One last try — limited discount or new arrivals preview.' });
		} else if (seg === 'vip' && count > 0) {
			segRecs.push({ p: 'medium', l: 'VIP perk program', d: 'Early access, free shipping, personal note — keep them feeling special.' });
		} else if (seg === 'loyal' && count > 0) {
			segRecs.push({ p: 'low', l: 'Cross-sell adjacent categories', d: 'Loyal buyers already trust your brand. Introduce complementary products.' });
		} else if (count === 0) {
			if (seg === 'vip' || seg === 'loyal') {
				segRecs.push({ p: 'medium', l: 'No ' + s.label + ' customers yet', d: 'Build a retention program to cultivate this cohort.' });
			}
		}

		if (segRecs.length > 0) {
			h += '<div class="kg-p-section"><div class="kg-p-section-title">Recommendations</div>';
			segRecs.forEach(function(r) {
				h += '<div class="kg-rec kg-rec-' + r.p + '">';
				h += '<span class="kg-rec-dot"></span>';
				h += '<span class="kg-rec-body"><strong>' + escHtml(r.l) + '</strong><br><small>' + escHtml(r.d) + '</small></span>';
				h += '</div>';
			});
			h += '</div>';
		}

		// Quick integration pointer
		h += '<div class="kg-p-section"><div class="kg-p-section-title">How to reach this segment</div>';
		h += '<p style="margin:6px 0;color:var(--lp-text-muted);font-size:12px;line-height:1.5;">';
		h += 'Pull the customer list via <code>GET /wp-json/luwipress/v1/crm/overview</code> (the segment array includes customer IDs). ';
		h += 'Feed into your email tool of choice — LuwiPress never writes to third-party CRM plugins, so ownership stays with you.';
		h += '</p></div>';
	}

	content.innerHTML = h;
	panel.classList.add('open');
}

// ── Elementor Audit Drill-down (single page, all issues grouped by severity/type) ──
function showElementorAuditDrilldown(page) {
	var panel = document.getElementById('kg-detail-panel');
	var content = document.getElementById('kg-detail-content');
	var h = '';

	var healthCls = page.health_score >= 80 ? 'good' : page.health_score >= 50 ? 'warn' : 'bad';
	var issues = page.issues || [];

	// Group by severity
	var bySev = { critical: [], warning: [], info: [] };
	issues.forEach(function(iss) {
		var sev = iss.severity || 'info';
		if (!bySev[sev]) bySev[sev] = [];
		bySev[sev].push(iss);
	});

	h += '<div class="kg-p-type-badge" style="background:#8b5cf6;color:#fff">Elementor Audit</div>';
	h += '<h3 class="kg-p-name">' + escHtml(page.title || page.page_type) + '</h3>';
	h += '<div class="kg-p-meta">' + escHtml(page.page_type);
	if (page.post_id) h += ' &middot; Post #' + page.post_id;
	h += ' &middot; ' + issues.length + ' issue' + (issues.length !== 1 ? 's' : '') + '</div>';

	h += '<div class="kg-p-health kg-h-' + healthCls + '">';
	h += '<div class="kg-p-health-bar" style="width:' + page.health_score + '%"></div>';
	h += '<span class="kg-p-health-label">Health ' + page.health_score + '%</span></div>';

	// Back to audit summary
	h += '<div style="margin:10px 0 14px;">';
	h += '<button class="kg-btn kg-btn-outline kg-btn-sm" onclick="kgBackToDesignAudit()">← Back to audit summary</button>';
	h += '</div>';

	['critical', 'warning', 'info'].forEach(function(sev) {
		var list = bySev[sev] || [];
		if (!list.length) return;
		var sevColor = sev === 'critical' ? 'var(--lp-error)' : sev === 'warning' ? 'var(--lp-warning)' : 'var(--lp-text-muted)';
		h += '<div class="kg-p-section">';
		h += '<div class="kg-p-section-title" style="display:flex;justify-content:space-between;align-items:center;">';
		h += '<span>' + sev.charAt(0).toUpperCase() + sev.slice(1) + ' <small style="color:var(--lp-text-muted);">(' + list.length + ')</small></span>';
		h += '</div>';

		// Group by issue type within severity
		var byType = {};
		list.forEach(function(iss) {
			var t = iss.type || 'other';
			if (!byType[t]) byType[t] = [];
			byType[t].push(iss);
		});
		Object.keys(byType).forEach(function(type) {
			var group = byType[type];
			var first = group[0];
			h += '<div class="kg-audit-issue">';
			h += '<div class="kg-audit-issue-header">';
			h += '<span class="kg-audit-issue-dot" style="background:' + sevColor + ';"></span>';
			h += '<strong>' + escHtml(first.message || type) + '</strong>';
			if (group.length > 1) h += ' <span class="kg-audit-issue-count">×' + group.length + '</span>';
			h += '</div>';
			if (first.fix) {
				h += '<div class="kg-audit-issue-fix"><strong>Fix:</strong> ' + escHtml(first.fix) + '</div>';
			}
			// Affected elements
			h += '<div class="kg-audit-issue-elements">';
			group.forEach(function(iss) {
				var elId   = iss.element_id   || '—';
				var elType = iss.element_type || '';
				h += '<span class="kg-audit-issue-chip" title="' + escHtml(elType) + '">#' + escHtml(elId) + '</span>';
			});
			h += '</div>';
			h += '</div>';
		});

		h += '</div>';
	});

	// Footer: edit in Elementor + view page
	h += '<div class="kg-p-footer">';
	if (page.post_id) {
		h += '<a href="/wp-admin/post.php?post=' + page.post_id + '&action=elementor" target="_blank" class="kg-btn kg-btn-primary">Open in Elementor</a>';
		h += '<a href="/?p=' + page.post_id + '" target="_blank" class="kg-btn kg-btn-outline">View live</a>';
	}
	h += '</div>';

	content.innerHTML = h;
	panel.classList.add('open');
}

// ── Design Audit Panel ──
function showDesignAuditPanel(auditData) {
	var panel = document.getElementById('kg-detail-panel');
	var content = document.getElementById('kg-detail-content');
	var summary = auditData.summary || {};
	var pages = auditData.page_types || [];
	var kit = auditData.kit || {};
	var h = '';

	var healthCls = summary.overall_health >= 80 ? 'good' : summary.overall_health >= 50 ? 'warn' : 'bad';

	h += '<div class="kg-p-type-badge" style="background:#8b5cf6;color:#fff">Design Audit</div>';
	h += '<h3 class="kg-p-name">Design Health Report</h3>';
	h += '<div class="kg-p-meta">' + summary.pages_audited + ' pages audited</div>';

	h += '<div class="kg-p-health kg-h-' + healthCls + '">';
	h += '<div class="kg-p-health-bar" style="width:' + summary.overall_health + '%"></div>';
	h += '<span class="kg-p-health-label">Design Health ' + summary.overall_health + '%</span></div>';

	// Issue summary chips
	h += '<div class="kg-p-chips">';
	if (summary.critical_issues > 0) h += '<span class="kg-chip kg-chip-miss">' + summary.critical_issues + ' Critical</span>';
	if ((summary.total_issues - summary.critical_issues) > 0) h += '<span class="kg-chip kg-chip-miss">' + (summary.total_issues - summary.critical_issues) + ' Warnings</span>';
	if (summary.total_issues === 0) h += '<span class="kg-chip kg-chip-ok">No Issues</span>';
	h += '</div>';

	// Kit CSS coverage
	if (kit.has_kit) {
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Kit CSS Coverage</div>';
		var scopes = kit.scopes || {};
		Object.keys(scopes).forEach(function(scope) {
			var s = scopes[scope];
			var hasCss = s.has_desktop || s.has_mobile || s.has_tablet;
			var chips = [];
			if (s.has_desktop) chips.push('Desktop');
			if (s.has_mobile) chips.push('Mobile');
			if (s.has_tablet) chips.push('Tablet');
			h += '<div class="kg-p-stat-row"><span>' + scope.charAt(0).toUpperCase() + scope.slice(1) + '</span>';
			h += '<strong style="color:' + (hasCss ? 'var(--lp-success)' : 'var(--lp-error)') + '">' + (chips.length > 0 ? chips.join(', ') : 'None') + '</strong></div>';
		});
		h += '</div>';
	}

	// Per-page results — clickable drill-down when post_id + issues present
	if (pages.length > 0) {
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Page Results</div>';
		// Store full payload for drill-down lookup
		window._kgDesignAuditPages = pages;
		pages.forEach(function(p, idx) {
			var pCls = p.health_score >= 80 ? 'var(--lp-success)' : p.health_score >= 50 ? 'var(--lp-warning)' : 'var(--lp-error)';
			var drillable = p.post_id && (p.issues || []).length > 0;
			var extraClass = drillable ? ' kg-audit-page-clickable' : '';
			var clickAttr  = drillable ? ' onclick="kgOpenAuditDrill(' + idx + ')"' : '';
			h += '<div class="kg-audit-page-row' + extraClass + '" style="margin-bottom:12px;padding:10px;background:var(--lp-bg-hover);border-radius:8px;"' + clickAttr + '>';
			h += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">';
			h += '<strong>' + escHtml(p.title || p.page_type) + '</strong>';
			h += '<span style="color:' + pCls + ';font-weight:600;">' + p.health_score + '%</span></div>';
			h += '<div style="font-size:11px;color:var(--lp-text-muted);">' + p.page_type + (p.post_id ? ' · #' + p.post_id : '') + (drillable ? ' · <span style="color:var(--lp-primary);">Click to drill down →</span>' : '') + '</div>';
			if (p.issues && p.issues.length > 0) {
				h += '<div style="margin-top:6px;font-size:11px;color:var(--lp-text-muted);">' + p.issues.length + ' issue' + (p.issues.length !== 1 ? 's' : '') + ': ';
				var sevCounts = { critical: 0, warning: 0, info: 0 };
				p.issues.forEach(function(issue) { sevCounts[issue.severity || 'info']++; });
				var sevParts = [];
				if (sevCounts.critical) sevParts.push('<span style="color:var(--lp-error);">' + sevCounts.critical + ' critical</span>');
				if (sevCounts.warning)  sevParts.push('<span style="color:var(--lp-warning);">' + sevCounts.warning + ' warning</span>');
				if (sevCounts.info)     sevParts.push('<span>' + sevCounts.info + ' info</span>');
				h += sevParts.join(' &middot; ');
				h += '</div>';
			} else {
				h += '<div style="margin-top:4px;font-size:11px;color:var(--lp-success);">No issues found</div>';
			}
			h += '</div>';
		});
		h += '</div>';
	}

	content.innerHTML = h;
	panel.classList.add('open');
}

function statusBadge(ok, label) {
	return '<div class="kg-detail-status ' + (ok ? 'ok' : 'missing') + '">' +
		'<span class="kg-detail-status-icon">' + (ok ? '✓' : '✗') + '</span> ' + label + '</div>';
}

function statusChip(ok, label) {
	return '<span class="kg-chip kg-chip-' + (ok ? 'ok' : 'miss') + '">' + label + '</span>';
}

function progressBar(label, pct) {
	pct = Math.min(100, Math.max(0, pct));
	var color = pct >= 80 ? 'var(--lp-success)' : (pct >= 50 ? 'var(--lp-warning)' : 'var(--lp-error)');
	return '<div class="kg-detail-progress"><span class="kg-detail-progress-label">' + label + '</span>' +
		'<div class="kg-detail-progress-bar"><div class="kg-detail-progress-fill" style="width:' + pct + '%;background:' + color + '"></div></div>' +
		'<span class="kg-detail-progress-pct">' + pct.toFixed(0) + '%</span></div>';
}

function escHtml(s) {
	return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Update Stats ──
function updateStats(data) {
	var summary = data.summary || {};
	var designAudit = (data.nodes && data.nodes.design_audit) ? data.nodes.design_audit : {};
	var designSummary = designAudit.summary || {};
	var pluginHealth = data.plugins || {};
	var store        = data.store || {};
	var revenue30    = (store.revenue && store.revenue.last_30_days && store.revenue.last_30_days.revenue) || 0;

	// Taxonomy coverage: weighted average across all (term, lang) pairs.
	var taxonomies = (data.nodes && data.nodes.taxonomies) || [];
	var taxTotal = 0, taxDone = 0;
	taxonomies.forEach(function(term) {
		var trans = term.translations || {};
		Object.keys(trans).forEach(function(lang) {
			taxTotal++;
			var st = trans[lang] && trans[lang].status;
			if (st === 'translated' || st === 'completed') taxDone++;
		});
	});
	var taxCoverage = taxTotal > 0 ? Math.round(taxDone / taxTotal * 100) : 0;

	// design_health may be null when Elementor isn't installed — treat that as N/A rather than 0%.
	var designUnavailable = designAudit.elementor_available === false || designSummary.overall_health === null;
	var stats = {
		total_products: summary.total_products || 0,
		total_posts: summary.total_posts || 0,
		seo_coverage: summary.seo_coverage || 0,
		enrichment_coverage: summary.enrichment_coverage || 0,
		opportunity_score_total: summary.opportunity_score_total || 0,
		design_health: designUnavailable ? null : (designSummary.overall_health || 0),
		plugin_readiness: Math.round(pluginHealth.readiness_score || 0),
		revenue_30d: Math.round(revenue30),
		taxonomy_coverage: taxCoverage
	};

	document.querySelectorAll('.kg-stat').forEach(function(el) {
		el.classList.remove('kg-stat-skeleton');
		el.classList.add('kg-stat-loaded');
	});

	Object.keys(stats).forEach(function(key) {
		var el = document.querySelector('[data-counter="' + key + '"]');
		if (!el) return;
		// N/A rendering — null means "this metric doesn't apply" (e.g. design_health without Elementor)
		if (stats[key] === null) {
			el.textContent = 'N/A';
			el.classList.add('kg-stat-na');
			var card = el.closest('.kg-stat');
			if (card) card.classList.add('kg-stat-disabled');
			return;
		}
		el.classList.remove('kg-stat-na');
		var card = el.closest('.kg-stat');
		if (card) card.classList.remove('kg-stat-disabled');

		var suffix = '';
		if (key.indexOf('coverage') !== -1 || key === 'design_health' || key === 'plugin_readiness' || key === 'taxonomy_coverage') suffix = '%';
		else if (key === 'revenue_30d') {
			var curr = (data.store && data.store.currency) || 'EUR';
			var symbol = { EUR: '€', USD: '$', GBP: '£', TRY: '₺' }[curr] || (curr + ' ');
			el.textContent = symbol + Math.round(stats[key]).toLocaleString();
			return;
		}
		animateCounter(el, Math.round(stats[key]), suffix);
	});
}

// ── Detail Panel Close ──
document.getElementById('kg-detail-close').onclick = function() {
	document.getElementById('kg-detail-panel').classList.remove('open');
};

// ── Refresh ──
document.getElementById('kg-refresh').onclick = function() {
	init(true); // explicit user refresh → bypass cache
};

// ── Resize ──
window.addEventListener('resize', function() {
	var container = document.getElementById('kg-graph-container');
	var svg = document.getElementById('kg-svg');
	if (svg && container) {
		svg.setAttribute('width', container.clientWidth);
		svg.setAttribute('height', container.clientHeight || 600);
	}
});

// ── Init ──
var _kgData = null; // Store for click handlers

function init(forceFresh) {
	loadD3(function() {
		fetchGraph(function(err, data) {
			if (err || !data) {
				document.getElementById('kg-loading').innerHTML = '<p style="color:var(--lp-error);">Failed to load knowledge graph. Check connection settings.</p>';
				return;
			}
			_kgData = data;
			updateStats(data);
			buildGraph(data);
			bindDesignHealthClick(data);
			bindPluginHealthClick(data);
			bindRevenueClick(data);
			bindTaxonomyClick(data);
			updateCacheBadge(data);
		}, !!forceFresh);
	});
}

function bindDesignHealthClick(data) {
	var card = document.getElementById('kg-stat-design');
	if (!card) return;
	var audit = (data.nodes && data.nodes.design_audit) ? data.nodes.design_audit : null;
	// Skip the click when Elementor isn't installed — the metric reads "N/A" in that case.
	if (!audit || audit.elementor_available === false) {
		card.onclick = null;
		return;
	}
	// Replace listener fresh on every call (data snapshot is captured in closure)
	card.onclick = function() { showDesignAuditPanel(audit); };
}

function bindPluginHealthClick(data) {
	var card = document.getElementById('kg-stat-plugins');
	if (!card) return;
	card.onclick = function() {
		var plugins = data.plugins || null;
		if (plugins) showPluginHealthPanel(plugins);
	};
}

function bindRevenueClick(data) {
	var card = document.getElementById('kg-stat-revenue');
	if (!card) return;
	card.onclick = function() {
		showRevenuePanel(data.store || {}, (data.nodes && data.nodes.order_analytics) || {});
	};
}

function bindTaxonomyClick(data) {
	var card = document.getElementById('kg-stat-taxonomy');
	if (!card) return;
	card.onclick = function() {
		showTaxonomyHeatmap((data.nodes && data.nodes.taxonomies) || []);
	};
}

function updateCacheBadge(data) {
	var meta = (data && data.meta) || {};
	var badge = document.getElementById('kg-cache-badge');
	if (!badge) return;
	badge.hidden = false;
	if (meta.from_cache) {
		badge.textContent = 'cached';
		badge.className = 'kg-cache-badge kg-cache-hit';
		badge.title = 'Served from cache. Click Refresh to force reload.';
	} else {
		badge.textContent = 'fresh (' + (meta.execution_time_ms || 0) + 'ms)';
		badge.className = 'kg-cache-badge kg-cache-miss';
		badge.title = 'Computed on the server just now.';
	}
}

function showTaxonomyHeatmap(taxonomies) {
	var panel = document.getElementById('kg-detail-panel');
	var content = document.getElementById('kg-detail-content');
	var h = '';

	// Group terms by taxonomy type
	var byType = {};
	taxonomies.forEach(function(term) {
		var tt = term.type || 'unknown';
		if (!byType[tt]) byType[tt] = [];
		byType[tt].push(term);
	});

	// Collect all languages from any term
	var langSet = {};
	taxonomies.forEach(function(term) {
		Object.keys(term.translations || {}).forEach(function(l) { langSet[l] = true; });
	});
	var langs = Object.keys(langSet).sort();

	// Overall stats
	var totalPairs = 0, donePairs = 0, missingPairs = 0;
	taxonomies.forEach(function(term) {
		var trans = term.translations || {};
		Object.keys(trans).forEach(function(l) {
			totalPairs++;
			var st = trans[l] && trans[l].status;
			if (st === 'translated' || st === 'completed') donePairs++;
			else if (st === 'missing') missingPairs++;
		});
	});
	var overallPct = totalPairs > 0 ? Math.round(donePairs / totalPairs * 100) : 0;
	var overallCls = overallPct >= 90 ? 'good' : overallPct >= 60 ? 'warn' : 'bad';

	h += '<div class="kg-p-type-badge" style="background:#a855f7;color:#fff">Taxonomy Coverage</div>';
	h += '<h3 class="kg-p-name">Translation Heatmap</h3>';
	h += '<div class="kg-p-meta">' + taxonomies.length + ' terms &middot; ' + langs.length + ' language' + (langs.length !== 1 ? 's' : '') + ' &middot; ' + missingPairs + ' missing translation' + (missingPairs !== 1 ? 's' : '') + '</div>';

	h += '<div class="kg-p-health kg-h-' + overallCls + '">';
	h += '<div class="kg-p-health-bar" style="width:' + overallPct + '%"></div>';
	h += '<span class="kg-p-health-label">Coverage ' + overallPct + '%</span></div>';

	// ── Heatmap: rows = tax type, cols = lang
	var typeKeys = Object.keys(byType).sort();
	if (langs.length && typeKeys.length) {
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Coverage Matrix</div>';
		h += '<table class="kg-tax-heatmap">';
		h += '<thead><tr><th></th>';
		langs.forEach(function(l) { h += '<th>' + l.toUpperCase() + '</th>'; });
		h += '</tr></thead><tbody>';

		typeKeys.forEach(function(ttype) {
			h += '<tr><th class="kg-tax-row-label">' + escHtml(ttype) + ' <small>(' + byType[ttype].length + ')</small></th>';
			langs.forEach(function(l) {
				var total = 0, done = 0, missing = 0;
				byType[ttype].forEach(function(term) {
					var t = (term.translations || {})[l];
					if (!t) return;
					total++;
					if (t.status === 'translated' || t.status === 'completed') done++;
					else if (t.status === 'missing') missing++;
				});
				var pct = total > 0 ? Math.round(done / total * 100) : 0;
				var cls = pct >= 95 ? 'kg-tax-cell-good' : pct >= 70 ? 'kg-tax-cell-warn' : pct >= 40 ? 'kg-tax-cell-weak' : 'kg-tax-cell-bad';
				var title = done + '/' + total + ' translated (' + missing + ' missing)';
				h += '<td class="kg-tax-cell ' + cls + '" title="' + title + '" data-taxtype="' + escHtml(ttype) + '" data-lang="' + escHtml(l) + '">';
				h += '<span class="kg-tax-cell-pct">' + pct + '%</span>';
				if (missing > 0) h += '<span class="kg-tax-cell-missing">' + missing + ' missing</span>';
				h += '</td>';
			});
			h += '</tr>';
		});
		h += '</tbody></table>';
		h += '<div class="kg-tax-legend">';
		h += '<span><span class="kg-tax-dot kg-tax-cell-good"></span>&ge;95%</span>';
		h += '<span><span class="kg-tax-dot kg-tax-cell-warn"></span>70-94%</span>';
		h += '<span><span class="kg-tax-dot kg-tax-cell-weak"></span>40-69%</span>';
		h += '<span><span class="kg-tax-dot kg-tax-cell-bad"></span>&lt;40%</span>';
		h += '</div>';
		h += '</div>';
	}

	// ── Missing terms by language (actionable)
	var missingByLang = {};
	langs.forEach(function(l) { missingByLang[l] = []; });
	taxonomies.forEach(function(term) {
		var trans = term.translations || {};
		langs.forEach(function(l) {
			var t = trans[l];
			if (t && t.status === 'missing') {
				missingByLang[l].push({ id: term.id, name: term.name || ('#' + term.id), type: term.type });
			}
		});
	});

	var anyMissing = false;
	langs.forEach(function(l) { if (missingByLang[l].length) anyMissing = true; });

	if (anyMissing) {
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Missing Translations</div>';
		langs.forEach(function(l) {
			var items = missingByLang[l];
			if (!items.length) return;
			h += '<div class="kg-tax-missing-group">';
			h += '<div class="kg-tax-missing-header">';
			h += '<strong>' + l.toUpperCase() + '</strong> <small>' + items.length + ' term' + (items.length !== 1 ? 's' : '') + ' missing</small>';
			h += '<button class="kg-btn kg-btn-primary kg-btn-sm" onclick="kgAction(\'translate_taxonomy\',0,this,\'' + l + '\')">Translate all</button>';
			h += '</div>';
			h += '<div class="kg-tax-missing-items">';
			items.slice(0, 20).forEach(function(item) {
				h += '<span class="kg-tax-missing-chip" title="' + escHtml(item.type) + '">' + escHtml(item.name) + '</span>';
			});
			if (items.length > 20) h += '<span class="kg-tax-missing-more">+' + (items.length - 20) + ' more</span>';
			h += '</div></div>';
		});
		h += '</div>';
	} else {
		h += '<div class="kg-p-allgood">All taxonomy terms are fully translated.</div>';
	}

	content.innerHTML = h;
	panel.classList.add('open');
}

function showRevenuePanel(store, analytics) {
	var panel = document.getElementById('kg-detail-panel');
	var content = document.getElementById('kg-detail-content');
	var curr = store.currency || 'EUR';
	var symbol = { EUR: '€', USD: '$', GBP: '£', TRY: '₺' }[curr] || (curr + ' ');
	var fmt = function(n) { return symbol + Math.round(n || 0).toLocaleString(); };
	var h = '';

	h += '<div class="kg-p-type-badge" style="background:#16a34a;color:#fff">Revenue & Orders</div>';
	h += '<h3 class="kg-p-name">Store Analytics</h3>';
	h += '<div class="kg-p-meta">Currency: ' + curr + ' · Lifetime: ' + fmt(store.lifetime_revenue) + ' over ' + (store.lifetime_orders || 0) + ' orders</div>';

	// ── Revenue snapshot
	h += '<div class="kg-p-section"><div class="kg-p-section-title">Revenue Snapshot</div>';
	var r = store.revenue || {};
	h += '<div class="kg-p-stat-row"><span>Today</span><strong>' + fmt(r.today && r.today.revenue) + ' <small style="color:var(--lp-text-muted);">(' + ((r.today && r.today.orders) || 0) + ' orders)</small></strong></div>';
	h += '<div class="kg-p-stat-row"><span>Last 7 days</span><strong>' + fmt(r.last_7_days && r.last_7_days.revenue) + ' <small style="color:var(--lp-text-muted);">(' + ((r.last_7_days && r.last_7_days.orders) || 0) + ' orders)</small></strong></div>';
	h += '<div class="kg-p-stat-row"><span>Last 30 days</span><strong>' + fmt(r.last_30_days && r.last_30_days.revenue) + ' <small style="color:var(--lp-text-muted);">(' + ((r.last_30_days && r.last_30_days.orders) || 0) + ' orders)</small></strong></div>';
	h += '<div class="kg-p-stat-row"><span>Average Order Value</span><strong>' + fmt(store.average_order_value) + '</strong></div>';
	h += '</div>';

	// ── 12-month sparkline (SVG)
	var months = analytics.monthly_revenue_12m || [];
	if (months.length) {
		var maxR = 0;
		months.forEach(function(m){ if ((m.revenue || 0) > maxR) maxR = m.revenue; });
		if (maxR > 0) {
			var w = 300, hgt = 60, pad = 4;
			var step = (w - pad * 2) / Math.max(1, months.length - 1);
			var pts = months.map(function(m, i) {
				var x = pad + i * step;
				var y = hgt - pad - ((m.revenue || 0) / maxR) * (hgt - pad * 2);
				return x + ',' + y;
			}).join(' ');
			h += '<div class="kg-p-section"><div class="kg-p-section-title">12-Month Revenue Trend</div>';
			h += '<div class="kg-sparkline-wrap">';
			h += '<svg viewBox="0 0 ' + w + ' ' + hgt + '" preserveAspectRatio="none" class="kg-sparkline">';
			h += '<polyline points="' + pts + '" fill="none" stroke="#16a34a" stroke-width="2" stroke-linejoin="round"/>';
			// area fill
			h += '<polygon points="' + pad + ',' + (hgt - pad) + ' ' + pts + ' ' + (pad + (months.length - 1) * step) + ',' + (hgt - pad) + '" fill="#16a34a" fill-opacity="0.12"/>';
			h += '</svg>';
			h += '<div class="kg-sparkline-labels"><span>' + (months[0].month || '') + '</span><span>' + (months[months.length - 1].month || '') + '</span></div>';
			h += '</div></div>';
		}
	}

	// ── Customer retention
	var repeatRate = analytics.repeat_customer_rate || 0;
	var retentionCls = repeatRate >= 20 ? 'good' : repeatRate >= 10 ? 'warn' : 'bad';
	h += '<div class="kg-p-section"><div class="kg-p-section-title">Customer Retention</div>';
	h += '<div class="kg-p-health kg-h-' + retentionCls + '" style="margin-top:6px;margin-bottom:10px;">';
	h += '<div class="kg-p-health-bar" style="width:' + Math.min(100, repeatRate * 2) + '%"></div>';
	h += '<span class="kg-p-health-label">Repeat rate ' + repeatRate.toFixed(1) + '%</span></div>';
	h += '<div class="kg-p-stat-row"><span>Total customers</span><strong>' + (analytics.total_customers || 0) + '</strong></div>';
	h += '<div class="kg-p-stat-row"><span>Repeat customers</span><strong>' + (analytics.repeat_customers || 0) + '</strong></div>';
	h += '<div class="kg-p-stat-row"><span>Avg items/order</span><strong>' + (analytics.avg_items_per_order || 0) + '</strong></div>';
	h += '</div>';

	// ── Top sellers
	var top = store.top_sellers || [];
	if (top.length) {
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Top Sellers</div>';
		top.slice(0, 5).forEach(function(p) {
			h += '<div class="kg-p-stat-row"><span>' + escHtml(p.name || ('#' + p.id)) + '</span>';
			h += '<strong>' + fmt(p.revenue || 0) + ' <small style="color:var(--lp-text-muted);">(' + (p.quantity || 0) + ' sold)</small></strong></div>';
		});
		h += '</div>';
	}

	// ── Stock alerts
	var stock = store.stock_alerts || {};
	var stockIssues = (stock.out_of_stock || 0) + (stock.on_backorder || 0) + (stock.no_price || 0);
	if (stockIssues > 0 || stock.on_sale > 0) {
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Inventory Status</div>';
		if (stock.out_of_stock)  h += '<div class="kg-p-stat-row"><span>Out of stock</span><strong class="kg-text-error">' + stock.out_of_stock + '</strong></div>';
		if (stock.on_backorder)  h += '<div class="kg-p-stat-row"><span>On backorder</span><strong style="color:var(--lp-warning);">' + stock.on_backorder + '</strong></div>';
		if (stock.no_price)      h += '<div class="kg-p-stat-row"><span>Missing price</span><strong class="kg-text-error">' + stock.no_price + '</strong></div>';
		if (stock.on_sale)       h += '<div class="kg-p-stat-row"><span>On sale</span><strong style="color:var(--lp-success);">' + stock.on_sale + '</strong></div>';
		h += '</div>';
	}

	// ── Payment methods
	var pm = analytics.payment_methods || {};
	var pmKeys = Object.keys(pm);
	if (pmKeys.length) {
		var pmTotal = 0;
		pmKeys.forEach(function(k){ pmTotal += pm[k]; });
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Payment Methods (last 90d)</div>';
		pmKeys.sort(function(a, b){ return pm[b] - pm[a]; }).slice(0, 5).forEach(function(k) {
			var pct = Math.round((pm[k] / pmTotal) * 100);
			h += '<div class="kg-p-stat-row"><span>' + escHtml(k) + '</span><strong>' + pm[k] + ' <small style="color:var(--lp-text-muted);">(' + pct + '%)</small></strong></div>';
		});
		h += '</div>';
	}

	// ── Refunds
	if (analytics.refund_count_90d) {
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Refunds (last 90d)</div>';
		h += '<div class="kg-p-stat-row"><span>Refund count</span><strong style="color:var(--lp-warning);">' + analytics.refund_count_90d + '</strong></div>';
		h += '<div class="kg-p-stat-row"><span>Refund amount</span><strong style="color:var(--lp-error);">' + fmt(Math.abs(analytics.refund_amount_90d || 0)) + '</strong></div>';
		h += '</div>';
	}

	content.innerHTML = h;
	panel.classList.add('open');
}

function showPluginHealthPanel(plugins) {
	var panel = document.getElementById('kg-detail-panel');
	var content = document.getElementById('kg-detail-content');
	var score = Math.round(plugins.readiness_score || 0);
	var cls = score >= 80 ? 'good' : score >= 50 ? 'warn' : 'bad';
	var h = '';

	h += '<div class="kg-p-type-badge" style="background:#0ea5e9;color:#fff">Plugin Health</div>';
	h += '<h3 class="kg-p-name">Site Readiness</h3>';
	h += '<div class="kg-p-meta">' + (plugins.recommendations ? plugins.recommendations.length : 0) + ' recommendation(s)</div>';

	h += '<div class="kg-p-health kg-h-' + cls + '">';
	h += '<div class="kg-p-health-bar" style="width:' + score + '%"></div>';
	h += '<span class="kg-p-health-label">Readiness ' + score + '%</span></div>';

	// Per-category status
	var cats = ['seo','translation','email','crm','cache','support'];
	h += '<div class="kg-p-section"><div class="kg-p-section-title">Detected Plugins</div>';
	cats.forEach(function(cat) {
		var info = plugins[cat] || {};
		var name = info.plugin && info.plugin !== 'none' ? info.plugin : '—';
		var badge = info.status === 'active' ? 'var(--lp-success)' : (info.status === 'not_installed' ? 'var(--lp-text-muted)' : 'var(--lp-warning)');
		h += '<div class="kg-p-stat-row"><span>' + cat.toUpperCase() + '</span>';
		h += '<strong style="color:' + badge + '">' + escHtml(name) + (info.version ? ' (' + escHtml(info.version) + ')' : '') + '</strong></div>';
	});
	h += '</div>';

	// Recommendations
	var recs = plugins.recommendations || [];
	if (recs.length > 0) {
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Recommendations</div>';
		recs.forEach(function(r) {
			h += '<div class="kg-rec kg-rec-' + (r.priority || 'medium') + '">';
			h += '<span class="kg-rec-dot"></span>';
			h += '<span class="kg-rec-body"><strong>' + escHtml(r.area || 'General') + '</strong><br><small>' + escHtml(r.message || '') + '</small></span>';
			h += '</div>';
		});
		h += '</div>';
	} else {
		h += '<div class="kg-p-allgood">Plugin stack is healthy.</div>';
	}

	content.innerHTML = h;
	panel.classList.add('open');
}

// ── Search ──
function initSearch() {
	var input   = document.getElementById('kg-search-input');
	var clear   = document.getElementById('kg-search-clear');
	var results = document.getElementById('kg-search-results');
	if (!input || !results) return;

	function closeResults() {
		results.hidden = true;
		results.innerHTML = '';
	}

	function focusNodeById(nodeId) {
		if (!window.lpKg || !window._kgSvg || !window._kgZoom) return;
		var svg = window._kgSvg;
		var zoom = window._kgZoom;
		var found = null;
		svg.selectAll('.kg-node').each(function(d) {
			if (d && d.id === nodeId) found = { d: d, el: this };
		});
		if (!found) return;
		var container = document.getElementById('kg-graph-container');
		var w = container.clientWidth;
		var h = container.clientHeight || 600;
		var scale = 2;
		var tx = w / 2 - found.d.x * scale;
		var ty = h / 2 - found.d.y * scale;
		svg.transition().duration(600).call(
			zoom.transform,
			d3.zoomIdentity.translate(tx, ty).scale(scale)
		);
		// Pulse the node
		d3.select(found.el).select('circle')
			.transition().duration(200).attr('stroke', 'var(--lp-primary)').attr('stroke-width', 4)
			.transition().duration(400).attr('stroke', '#fff').attr('stroke-width', 2);
		// Open its detail panel
		showDetailPanel(found.d);
	}

	function runSearch(q) {
		q = (q || '').trim().toLowerCase();
		clear.hidden = !q;
		if (q.length < 2) { closeResults(); return; }
		if (!_kgData || !_kgData.nodes) { closeResults(); return; }

		var hits = [];
		var products = _kgData.nodes.products || [];
		var posts    = _kgData.nodes.posts || [];
		var cats     = _kgData.nodes.categories || [];

		products.forEach(function(p) {
			var label = (p.name || '') + ' ' + (p.sku || '') + ' ' + (p.slug || '');
			if (label.toLowerCase().indexOf(q) !== -1) {
				hits.push({ id: 'product:' + p.id, type: 'product', label: p.name, meta: p.sku || ('#' + p.id) });
			}
		});
		posts.forEach(function(p) {
			if ((p.title || '').toLowerCase().indexOf(q) !== -1) {
				hits.push({ id: 'post:' + p.id, type: 'post', label: p.title, meta: 'Blog post' });
			}
		});
		cats.forEach(function(c) {
			if ((c.name || '').toLowerCase().indexOf(q) !== -1) {
				hits.push({ id: 'category:' + c.id, type: 'category', label: c.name, meta: c.product_count + ' products' });
			}
		});

		hits = hits.slice(0, 20);
		if (!hits.length) {
			results.innerHTML = '<div class="kg-search-empty">No matches.</div>';
			results.hidden = false;
			return;
		}
		var html = '';
		hits.forEach(function(h, i) {
			html += '<button type="button" class="kg-search-item' + (i === 0 ? ' kg-search-item-active' : '') + '" data-node="' + h.id + '">';
			html += '<span class="kg-search-item-type kg-search-item-' + h.type + '">' + h.type + '</span>';
			html += '<span class="kg-search-item-label">' + escHtml(h.label) + '</span>';
			html += '<span class="kg-search-item-meta">' + escHtml(h.meta) + '</span>';
			html += '</button>';
		});
		results.innerHTML = html;
		results.hidden = false;

		results.querySelectorAll('.kg-search-item').forEach(function(btn) {
			btn.addEventListener('mousedown', function(e) { e.preventDefault(); });
			btn.addEventListener('click', function() {
				focusNodeById(btn.getAttribute('data-node'));
				closeResults();
				input.value = '';
				clear.hidden = true;
			});
		});
	}

	var debounce;
	input.addEventListener('input', function() {
		clearTimeout(debounce);
		debounce = setTimeout(function(){ runSearch(input.value); }, 120);
	});
	input.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			input.value = '';
			clear.hidden = true;
			closeResults();
			input.blur();
			return;
		}
		if (e.key === 'Enter') {
			var first = results.querySelector('.kg-search-item-active');
			if (first) { first.click(); }
			return;
		}
		if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
			e.preventDefault();
			var items = Array.prototype.slice.call(results.querySelectorAll('.kg-search-item'));
			if (!items.length) return;
			var idx = items.findIndex(function(el) { return el.classList.contains('kg-search-item-active'); });
			items.forEach(function(el) { el.classList.remove('kg-search-item-active'); });
			idx = idx + (e.key === 'ArrowDown' ? 1 : -1);
			if (idx < 0) idx = items.length - 1;
			if (idx >= items.length) idx = 0;
			items[idx].classList.add('kg-search-item-active');
			items[idx].scrollIntoView({ block: 'nearest' });
		}
	});
	input.addEventListener('blur', function() { setTimeout(closeResults, 150); });
	clear.addEventListener('click', function() {
		input.value = '';
		clear.hidden = true;
		closeResults();
		input.focus();
	});

	// Global `/` shortcut
	document.addEventListener('keydown', function(e) {
		if (e.key === '/' && document.activeElement !== input) {
			var tag = (document.activeElement && document.activeElement.tagName) || '';
			if (tag === 'INPUT' || tag === 'TEXTAREA') return;
			e.preventDefault();
			input.focus();
		}
	});
}

// ── Dropdown helper ──
function initDropdown(rootId, triggerId, menuId, onSelect) {
	var root    = document.getElementById(rootId);
	var trigger = document.getElementById(triggerId);
	var menu    = document.getElementById(menuId);
	if (!root || !trigger || !menu) return;

	function close() { menu.hidden = true; trigger.setAttribute('aria-expanded', 'false'); }
	function open()  {
		// Close every other .kg-dropdown-menu so only one is ever open
		document.querySelectorAll('.kg-dropdown-menu').forEach(function(m) {
			if (m !== menu) m.hidden = true;
		});
		menu.hidden = false;
		trigger.setAttribute('aria-expanded', 'true');
	}

	// No stopPropagation — we rely on document click + root.contains() to close the OTHER dropdowns.
	trigger.addEventListener('click', function() {
		if (menu.hidden) open(); else close();
	});
	menu.querySelectorAll('.kg-dropdown-item').forEach(function(item) {
		item.addEventListener('click', function() {
			close();
			if (onSelect) onSelect(item);
		});
	});
	document.addEventListener('click', function(e) {
		if (!root.contains(e.target)) close();
	});
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') close();
	});
}

function initPresets() {
	var labelEl = document.getElementById('kg-preset-label');
	initDropdown('kg-preset-dd', 'kg-preset-trigger', 'kg-preset-menu', function(item) {
		var preset = item.getAttribute('data-preset');
		_kgCurrentPreset = preset || 'all';
		if (labelEl) labelEl.textContent = item.textContent.replace(/\s*\(.+\)\s*$/, '').trim();
		if (_kgData) buildGraph(_kgData);
	});
}

function initExport() {
	initDropdown('kg-export-dd', 'kg-export-trigger', 'kg-export-menu', function(item) {
		var kind = item.getAttribute('data-export');
		if (kind === 'upload_csv') { triggerCsvUpload(); return; }
		if (!_kgData) return;
		if (kind === 'csv_opportunities') exportCsvOpportunities(_kgData);
		else if (kind === 'csv_missing_seo') exportCsvMissingSeo(_kgData);
		else if (kind === 'json') exportJson(_kgData);
		else if (kind === 'png')  exportPng();
	});

	// Wire the hidden file input that the upload menu item triggers
	var input = document.getElementById('kg-csv-upload-input');
	if (input) {
		input.addEventListener('change', function(e) {
			var file = e.target.files && e.target.files[0];
			if (file) handleCsvFile(file);
			input.value = '';
		});
	}
}

function triggerCsvUpload() {
	var input = document.getElementById('kg-csv-upload-input');
	if (input) input.click();
}

// Minimal RFC-4180 CSV parser (handles quoted fields with embedded commas/newlines).
function parseCsv(text) {
	if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1); // strip BOM
	var rows = [];
	var row = [];
	var field = '';
	var i = 0, inQuotes = false;
	while (i < text.length) {
		var c = text[i];
		if (inQuotes) {
			if (c === '"') {
				if (text[i + 1] === '"') { field += '"'; i += 2; continue; }
				inQuotes = false; i++; continue;
			}
			field += c; i++; continue;
		}
		if (c === '"') { inQuotes = true; i++; continue; }
		if (c === ',') { row.push(field); field = ''; i++; continue; }
		if (c === '\n' || c === '\r') {
			row.push(field); field = '';
			if (row.length > 1 || row[0] !== '') rows.push(row);
			row = [];
			if (c === '\r' && text[i + 1] === '\n') i++;
			i++; continue;
		}
		field += c; i++;
	}
	if (field !== '' || row.length) { row.push(field); rows.push(row); }
	return rows;
}

function handleCsvFile(file) {
	var reader = new FileReader();
	reader.onload = function(e) {
		var text = e.target.result;
		var rows = parseCsv(text);
		if (rows.length < 2) {
			alert('CSV looks empty or has no data rows.');
			return;
		}
		var headers = rows[0].map(function(h) { return String(h).trim().toLowerCase(); });
		// Flexible column detection: our exports use "ID", "Name", "SEO Title", etc.
		// Accept any of: id | post_id | product_id / seo title | meta title | title / meta desc | description / focus keyword | keyword
		var colId   = headers.findIndex(function(h) { return h === 'id' || h === 'post_id' || h === 'product_id'; });
		var colTitle = headers.findIndex(function(h) { return h === 'seo title' || h === 'meta title' || h === 'title'; });
		var colDesc  = headers.findIndex(function(h) { return h === 'meta desc' || h === 'description' || h === 'seo description' || h === 'meta description'; });
		var colKw    = headers.findIndex(function(h) { return h === 'focus keyword' || h === 'keyword' || h === 'focus kw'; });

		if (colId === -1) {
			alert('Could not find an ID column. Expected "ID", "post_id", or "product_id" as a column header.');
			return;
		}
		if (colTitle === -1 && colDesc === -1 && colKw === -1) {
			alert('Could not find any SEO columns. Expected at least one of: "SEO Title", "Meta Desc", "Focus Keyword".');
			return;
		}

		var payload = [];
		for (var r = 1; r < rows.length; r++) {
			var rr = rows[r];
			var id = parseInt(rr[colId], 10);
			if (!id) continue;
			var entry = { post_id: id };
			if (colTitle !== -1 && rr[colTitle]) entry.title         = rr[colTitle];
			if (colDesc  !== -1 && rr[colDesc])  entry.description   = rr[colDesc];
			if (colKw    !== -1 && rr[colKw])    entry.focus_keyword = rr[colKw];
			// Only include rows that actually carry writable values
			if (entry.title || entry.description || entry.focus_keyword) payload.push(entry);
		}

		if (!payload.length) {
			alert('CSV parsed but no rows had writable SEO fields. Make sure "SEO Title" / "Meta Desc" / "Focus Keyword" columns contain values.');
			return;
		}

		var confirmMsg = 'Apply SEO meta to ' + payload.length + ' product' + (payload.length !== 1 ? 's' : '') + '?\n\nColumns detected:'
			+ (colTitle !== -1 ? '\n  · ' + headers[colTitle] : '')
			+ (colDesc  !== -1 ? '\n  · ' + headers[colDesc]  : '')
			+ (colKw    !== -1 ? '\n  · ' + headers[colKw]    : '')
			+ '\n\nThis writes directly to your SEO plugin (Rank Math / Yoast / AIOSEO / SEOPress). No snapshot is taken.';
		if (!confirm(confirmMsg)) return;

		submitBulkSeoMeta(payload);
	};
	reader.onerror = function() { alert('Could not read the CSV file.'); };
	reader.readAsText(file, 'utf-8');
}

function submitBulkSeoMeta(rows) {
	var headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': lpKgNonce };
	var apiToken = (window.lpKgConfig && window.lpKgConfig.apiToken) || '';
	if (apiToken) headers['Authorization'] = 'Bearer ' + apiToken;

	fetch(lpKgRestUrl + 'seo/meta-bulk', {
		method: 'POST',
		headers: headers,
		credentials: 'same-origin',
		body: JSON.stringify({ rows: rows })
	})
	.then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
	.then(function(res) {
		if (!res.ok) {
			alert('Bulk update failed: ' + (res.data && (res.data.message || res.data.code) || 'unknown error'));
			return;
		}
		var d = res.data;
		var msg = 'SEO meta updated: ' + d.applied + ' applied, ' + d.skipped + ' skipped (of ' + d.total + ' rows).';
		if (d.error_rows && d.error_rows.length) {
			msg += '\n\nFirst few errors:\n' + d.error_rows.map(function(e) { return '  · row ' + e.row + ' (post ' + e.post_id + '): ' + e.error; }).join('\n');
		}
		alert(msg);
		// Refresh graph to reflect new coverage
		if (window.lpKg && window.lpKg.fetchGraph) {
			window.lpKg.fetchGraph(function(err, fresh) {
				if (!err && fresh) {
					window.lpKg.setData(fresh);
					window.lpKg.updateStats(fresh);
					window.lpKg.buildGraph(fresh);
				}
			}, true);
		}
	})
	.catch(function(err) { alert('Network error: ' + err); });
}

function downloadFile(filename, content, mime) {
	var blob = new Blob([content], { type: mime || 'text/plain' });
	var url  = URL.createObjectURL(blob);
	var a    = document.createElement('a');
	a.href = url;
	a.download = filename;
	document.body.appendChild(a);
	a.click();
	setTimeout(function() {
		document.body.removeChild(a);
		URL.revokeObjectURL(url);
	}, 100);
}

function csvEscape(v) {
	if (v === null || v === undefined) return '';
	var s = String(v);
	if (s.indexOf(',') !== -1 || s.indexOf('"') !== -1 || s.indexOf('\n') !== -1) {
		return '"' + s.replace(/"/g, '""') + '"';
	}
	return s;
}

function exportCsvOpportunities(data) {
	var products = (data.nodes && data.nodes.products) || [];
	var rows = [['ID','Name','SKU','Opportunity Score','SEO Title','SEO Desc','Enriched','Content Length','Reviews','Missing Translations']];
	var sorted = products.slice().sort(function(a, b){ return (b.opportunity_score || 0) - (a.opportunity_score || 0); });
	sorted.forEach(function(p) {
		var missing = [];
		Object.keys(p.translation || {}).forEach(function(l) { if (p.translation[l] !== 'completed') missing.push(l.toUpperCase()); });
		rows.push([
			p.id,
			p.name,
			p.sku || '',
			p.opportunity_score || 0,
			p.seo && p.seo.has_title ? 'Y' : 'N',
			p.seo && p.seo.has_description ? 'Y' : 'N',
			p.enrichment && p.enrichment.status === 'completed' ? 'Y' : 'N',
			p.content_length || 0,
			(p.reviews && p.reviews.count) || 0,
			missing.join(',')
		]);
	});
	var csv = rows.map(function(r) { return r.map(csvEscape).join(','); }).join('\n');
	var stamp = new Date().toISOString().slice(0,10);
	downloadFile('luwipress-opportunities-' + stamp + '.csv', '\ufeff' + csv, 'text/csv;charset=utf-8');
}

function exportCsvMissingSeo(data) {
	var products = (data.nodes && data.nodes.products) || [];
	var rows = [['ID','Name','SKU','Missing Title','Missing Description','Missing Focus Keyword','Edit URL']];
	products.forEach(function(p) {
		var mt = !p.seo || !p.seo.has_title;
		var md = !p.seo || !p.seo.has_description;
		var mk = !p.seo || !p.seo.has_focus_kw;
		if (!mt && !md && !mk) return;
		rows.push([
			p.id,
			p.name,
			p.sku || '',
			mt ? 'Y' : '',
			md ? 'Y' : '',
			mk ? 'Y' : '',
			window.location.origin + '/wp-admin/post.php?post=' + p.id + '&action=edit'
		]);
	});
	var csv = rows.map(function(r) { return r.map(csvEscape).join(','); }).join('\n');
	var stamp = new Date().toISOString().slice(0,10);
	downloadFile('luwipress-missing-seo-' + stamp + '.csv', '\ufeff' + csv, 'text/csv;charset=utf-8');
}

function exportJson(data) {
	var stamp = new Date().toISOString().slice(0,10);
	downloadFile('luwipress-knowledge-graph-' + stamp + '.json', JSON.stringify(data, null, 2), 'application/json');
}

function initKeyboardShortcuts() {
	document.addEventListener('keydown', function(e) {
		var tag = (document.activeElement && document.activeElement.tagName) || '';
		if (tag === 'INPUT' || tag === 'TEXTAREA') return;
		if (e.metaKey || e.ctrlKey || e.altKey) return;

		if (e.key === 'r' || e.key === 'R') {
			e.preventDefault();
			var btn = document.getElementById('kg-refresh');
			if (btn) btn.click();
		} else if (e.key === '1' || e.key === '2' || e.key === '3' || e.key === '4') {
			e.preventDefault();
			var idx = parseInt(e.key, 10) - 1;
			var btns = document.querySelectorAll('.kg-view-btn');
			if (btns[idx]) btns[idx].click();
		} else if (e.key === 'Escape') {
			var panel = document.getElementById('kg-detail-panel');
			if (panel && panel.classList.contains('open')) {
				panel.classList.remove('open');
			}
		} else if (e.key === '?') {
			e.preventDefault();
			alert('Knowledge Graph shortcuts:\n/  Search\nr  Refresh\n1  Products view\n2  Posts view\n3  Pages view\n4  Customers view\nEsc  Close panel\n?  This help');
		}
	});
}

function exportPng() {
	var svgEl = document.getElementById('kg-svg');
	if (!svgEl) return;
	var rect   = svgEl.getBoundingClientRect();
	var width  = Math.round(rect.width);
	var height = Math.round(rect.height);
	// Clone + inline size
	var clone = svgEl.cloneNode(true);
	clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
	clone.setAttribute('width', width);
	clone.setAttribute('height', height);
	var xml = new XMLSerializer().serializeToString(clone);
	var svg64 = btoa(unescape(encodeURIComponent(xml)));
	var img = new Image();
	img.onload = function() {
		var canvas = document.createElement('canvas');
		canvas.width  = width;
		canvas.height = height;
		var ctx = canvas.getContext('2d');
		ctx.fillStyle = '#ffffff';
		ctx.fillRect(0, 0, width, height);
		ctx.drawImage(img, 0, 0);
		canvas.toBlob(function(blob) {
			var url = URL.createObjectURL(blob);
			var a   = document.createElement('a');
			var stamp = new Date().toISOString().slice(0,10);
			a.href = url;
			a.download = 'luwipress-knowledge-graph-' + stamp + '.png';
			document.body.appendChild(a);
			a.click();
			setTimeout(function() { document.body.removeChild(a); URL.revokeObjectURL(url); }, 100);
		}, 'image/png');
	};
	img.onerror = function() { alert('Could not export PNG — your browser blocked SVG rendering.'); };
	img.src = 'data:image/svg+xml;base64,' + svg64;
}

// ── View Switch ──
function initViewSwitch() {
	document.querySelectorAll('.kg-view-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			document.querySelectorAll('.kg-view-btn').forEach(function(b){ b.classList.remove('active'); });
			btn.classList.add('active');
			_kgCurrentView = btn.dataset.view;
			if (_kgData) {
				buildGraph(_kgData, _kgCurrentView);
			}
		});
	});
}

initViewSwitch();
initSearch();
initPresets();
initExport();
initKeyboardShortcuts();
init();

// Expose helpers so onclick handlers (outside IIFE) can trigger refresh
window.lpKg = {
	updateStats: updateStats,
	buildGraph: buildGraph,
	showDetailPanel: showDetailPanel,
	bindDesignHealthClick: bindDesignHealthClick,
	bindPluginHealthClick: bindPluginHealthClick,
	bindRevenueClick: bindRevenueClick,
	bindTaxonomyClick: bindTaxonomyClick,
	showTaxonomyHeatmap: showTaxonomyHeatmap,
	showElementorAuditDrilldown: showElementorAuditDrilldown,
	showDesignAuditPanel: showDesignAuditPanel,
	updateCacheBadge: updateCacheBadge,
	fetchGraph: fetchGraph,
	getData: function() { return _kgData; },
	setData: function(d) { _kgData = d; }
};

})();

// Quick Action handler (outside IIFE so onclick attributes can reach it)
var lpKgRestUrl = (window.lpKgConfig && window.lpKgConfig.restBase) || '';
var lpKgNonce = (window.lpKgConfig && window.lpKgConfig.nonce) || '';

function kgOpenAuditDrill(idx) {
	var pages = window._kgDesignAuditPages || [];
	var page = pages[idx];
	if (!page) return;
	if (window.lpKg && window.lpKg.showElementorAuditDrilldown) {
		window.lpKg.showElementorAuditDrilldown(page);
	}
}

function kgBackToDesignAudit() {
	if (!window.lpKg || !window.lpKg.showDesignAuditPanel || !window.lpKg.getData) return;
	var data = window.lpKg.getData();
	var audit = (data && data.nodes && data.nodes.design_audit) || null;
	if (audit) window.lpKg.showDesignAuditPanel(audit);
}

// ── Batch monitor ───────────────────────────────────────────────
// Polls /product/enrich-batch/status every few seconds and updates a floating
// progress panel until the batch finishes (or the user dismisses it).
var _kgBatchPoll = null;
var _kgBatchStart = 0;

function kgStartBatchMonitor(batchId, queuedCount) {
	if (!batchId) return;
	var monitor = document.getElementById('kg-batch-monitor');
	var bar     = document.getElementById('kg-batch-monitor-bar');
	var stats   = document.getElementById('kg-batch-monitor-stats');
	var detail  = document.getElementById('kg-batch-monitor-detail');
	if (!monitor || !bar || !stats || !detail) return;

	_kgBatchStart = Date.now();
	monitor.hidden = false;
	monitor.classList.add('kg-batch-monitor-running');
	bar.style.width = '0%';
	stats.textContent = 'Queued ' + (queuedCount || 0) + ' product' + ((queuedCount || 0) !== 1 ? 's' : '') + '…';
	detail.innerHTML = '<span style="color:var(--lp-text-muted);">Starting…</span>';

	// Close button
	var closeBtn = document.getElementById('kg-batch-monitor-close');
	if (closeBtn) closeBtn.onclick = function() { kgStopBatchMonitor(false); };

	if (_kgBatchPoll) clearInterval(_kgBatchPoll);
	var pollFn = function() { kgPollBatchOnce(batchId); };
	pollFn(); // immediate
	_kgBatchPoll = setInterval(pollFn, 3000);
}

function kgStopBatchMonitor(hideImmediately) {
	if (_kgBatchPoll) { clearInterval(_kgBatchPoll); _kgBatchPoll = null; }
	var monitor = document.getElementById('kg-batch-monitor');
	if (!monitor) return;
	monitor.classList.remove('kg-batch-monitor-running');
	if (hideImmediately) {
		monitor.hidden = true;
	} else {
		// Fade out after 6s so user can see final state
		setTimeout(function() { monitor.hidden = true; }, 6000);
	}
}

function kgPollBatchOnce(batchId) {
	var url = lpKgRestUrl + 'product/enrich-batch/status?batch_id=' + encodeURIComponent(batchId);
	var headers = { 'X-WP-Nonce': lpKgNonce };
	var apiToken = (window.lpKgConfig && window.lpKgConfig.apiToken) || '';
	if (apiToken) headers['Authorization'] = 'Bearer ' + apiToken;

	fetch(url, { headers: headers, credentials: 'same-origin' })
		.then(function(r) { return r.ok ? r.json() : null; })
		.then(function(data) {
			if (!data || !data.batch_id) return;
			var bar    = document.getElementById('kg-batch-monitor-bar');
			var stats  = document.getElementById('kg-batch-monitor-stats');
			var detail = document.getElementById('kg-batch-monitor-detail');
			if (!bar || !stats || !detail) return;

			var total     = data.total || 0;
			var completed = data.completed || 0;
			var progress  = data.progress || 0;
			var statuses  = data.statuses || {};

			bar.style.width = progress + '%';
			bar.setAttribute('aria-valuenow', progress);

			var elapsed = Math.round((Date.now() - _kgBatchStart) / 1000);
			stats.textContent = completed + ' / ' + total + ' done (' + progress + '%) · ' + elapsed + 's elapsed';

			// Per-status chips
			var chips = [];
			if (statuses.queued)     chips.push('<span class="kg-batch-chip kg-batch-chip-queued">' + statuses.queued + ' queued</span>');
			if (statuses.processing) chips.push('<span class="kg-batch-chip kg-batch-chip-processing">' + statuses.processing + ' running</span>');
			if (statuses.completed)  chips.push('<span class="kg-batch-chip kg-batch-chip-completed">' + statuses.completed + ' done</span>');
			if (statuses.failed)     chips.push('<span class="kg-batch-chip kg-batch-chip-failed">' + statuses.failed + ' failed</span>');
			detail.innerHTML = chips.join(' ');

			// Finished?
			if (total > 0 && completed >= total) {
				var failMsg = statuses.failed ? ' (' + statuses.failed + ' failed)' : '';
				stats.textContent = 'Batch complete — ' + completed + ' processed' + failMsg + ' in ' + elapsed + 's';
				// Invalidate graph + re-render
				if (window.lpKg && window.lpKg.fetchGraph) {
					window.lpKg.fetchGraph(function(err, freshData) {
						if (!err && freshData) {
							window.lpKg.setData(freshData);
							window.lpKg.updateStats(freshData);
							window.lpKg.buildGraph(freshData);
						}
					}, true);
				}
				kgStopBatchMonitor(false);
			}
		})
		.catch(function(err) {
			if (window.console) console.warn('[lpKg] batch poll failed:', err);
		});
}

function collectProductIdsByCategory(catId) {
	if (!window.lpKg || !window.lpKg.getData) return [];
	var data = window.lpKg.getData();
	if (!data || !data.nodes || !data.nodes.products) return [];
	catId = parseInt(catId, 10);
	var ids = [];
	data.nodes.products.forEach(function(p) {
		if (Array.isArray(p.categories) && p.categories.indexOf(catId) !== -1) {
			ids.push(p.id);
		}
	});
	return ids;
}

function kgAction(action, productId, btn, langs) {
	var originalText = btn.innerHTML;
	btn.disabled = true;
	btn.innerHTML = '<span class="kg-action-spinner"></span> Working...';

	var endpoint, body;
	if (action === 'enrich') {
		endpoint = 'product/enrich';
		body = { product_id: productId };
	} else if (action === 'faq') {
		endpoint = 'aeo/generate-faq';
		body = { product_id: productId };
	} else if (action === 'howto') {
		endpoint = 'aeo/generate-howto';
		body = { product_id: productId };
	} else if (action === 'translate') {
		endpoint = 'translation/request';
		body = { post_id: productId, target_languages: langs ? langs.split(',') : [] };
	} else if (action === 'translate_lang') {
		endpoint = 'translation/batch';
		body = { post_type: 'product', languages: langs ? [langs] : [], limit: 50 };
	} else if (action === 'enrich_category') {
		// productId carries the category ID here. Collect product IDs in this category
		// from the cached KG data — backend expects product_ids array.
		var catIdsE = collectProductIdsByCategory(productId).slice(0, 50);
		if (!catIdsE.length) {
			btn.innerHTML = '<span style="color:var(--lp-error);">No products in category</span>';
			setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; }, 3000);
			return;
		}
		endpoint = 'product/enrich-batch';
		body = { product_ids: catIdsE };
	} else if (action === 'translate_category') {
		var catIdsT = collectProductIdsByCategory(productId).slice(0, 50);
		if (!catIdsT.length) {
			btn.innerHTML = '<span style="color:var(--lp-error);">No products in category</span>';
			setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; }, 3000);
			return;
		}
		endpoint = 'translation/batch';
		body = { post_type: 'product', post_ids: catIdsT, languages: langs ? [langs] : [] };
	} else if (action === 'translate_taxonomy') {
		// langs carries the target language code. Find which taxonomy types have missing terms
		// and dispatch one request per type (backend endpoint is taxonomy-scoped).
		var targetLang = langs;
		var taxonomies = (window.lpKg && window.lpKg.getData && window.lpKg.getData().nodes.taxonomies) || [];
		var typesNeeded = {};
		taxonomies.forEach(function(term) {
			var t = (term.translations || {})[targetLang];
			if (t && t.status === 'missing' && term.type) typesNeeded[term.type] = true;
		});
		var types = Object.keys(typesNeeded);
		if (!types.length) {
			btn.innerHTML = '<span style="color:var(--lp-success);">Nothing to translate</span>';
			setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; }, 3000);
			return;
		}
		// Fire one request per taxonomy type, in parallel.
		var promises = types.map(function(tax) {
			return fetch(lpKgRestUrl + 'translation/taxonomy', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': lpKgNonce },
				body: JSON.stringify({ taxonomy: tax, target_languages: [targetLang], limit: 50 }),
				credentials: 'same-origin'
			}).then(function(r) { return r.json().then(function(d){ return { ok: r.ok, data: d, tax: tax }; }); });
		});
		Promise.all(promises).then(function(results) {
			var queued = results.filter(function(r) { return r.ok; }).length;
			btn.innerHTML = '<span class="kg-action-icon" style="color:var(--lp-success);">&#10003;</span> Queued (' + queued + '/' + types.length + ')';
			btn.classList.add('kg-action-done');
			setTimeout(function() {
				btn.disabled = false;
				btn.innerHTML = originalText;
				btn.classList.remove('kg-action-done');
				kgRefreshAndReopen(null, 'taxonomy', targetLang);
			}, 8000);
		}).catch(function(err) {
			btn.innerHTML = '<span style="color:var(--lp-error);">Failed</span>';
			setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; }, 3000);
		});
		return; // skip the default dispatch path
	}

	fetch(lpKgRestUrl + endpoint, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': lpKgNonce },
		body: JSON.stringify(body),
		credentials: 'same-origin'
	})
	.then(function(r) {
		return r.json().then(function(data) { return { ok: r.ok, status: r.status, data: data }; });
	})
	.then(function(res) {
		if (!res.ok) {
			var msg = (res.data && (res.data.message || res.data.code)) || ('HTTP ' + res.status);
			btn.innerHTML = '<span style="color:var(--lp-error);">' + msg + '</span>';
			setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; }, 4000);
			if (window.console) console.warn('[lpKg] action failed:', action, res);
			return;
		}
		var data = res.data || {};
		var isQueued = data.status === 'processing' || data.status === 'queued' || data.job_id || data.batch_id;
		btn.innerHTML = '<span class="kg-action-icon" style="color:var(--lp-success);">&#10003;</span> ' + (isQueued ? 'Queued' : 'Done');
		btn.classList.add('kg-action-done');

		// If the backend returned a batch_id (enrich-batch), spin up the batch monitor.
		if (data.batch_id && typeof kgStartBatchMonitor === 'function') {
			kgStartBatchMonitor(data.batch_id, data.queued || 0);
		}

		// Refresh the panel after a delay to show updated state
		var refreshDelay = isQueued ? 8000 : 2000;
		setTimeout(function() {
			btn.disabled = false;
			btn.innerHTML = originalText;
			btn.classList.remove('kg-action-done');
			kgRefreshAndReopen(productId, action === 'translate_lang' ? 'language' : null, langs);
		}, refreshDelay);
	})
	.catch(function(err) {
		btn.innerHTML = '<span style="color:var(--lp-error);">Network error</span>';
		setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; }, 3000);
		if (window.console) console.error('[lpKg] action error:', action, err);
	});
}

function kgRefreshAndReopen(nodeId, nodeType, langCode) {
	if (!window.lpKg || !window.lpKg.buildGraph) {
		return;
	}
	var headers = { 'X-WP-Nonce': lpKgNonce };
	var apiToken = (window.lpKgConfig && window.lpKgConfig.apiToken) || '';
	if (apiToken) headers['Authorization'] = 'Bearer ' + apiToken;

	fetch(((window.lpKgConfig && window.lpKgConfig.apiUrl) || '') + '?sections=products,categories,translation,store,opportunities,design_audit,posts,pages,plugins,order_analytics,taxonomy,crm&fresh=1', {
		headers: headers,
		credentials: 'same-origin'
	})
	.then(function(r) { return r.json(); })
	.then(function(data) {
		if (!data || !data.nodes) return;
		window.lpKg.setData(data);
		window.lpKg.updateStats(data);
		window.lpKg.buildGraph(data);
		window.lpKg.bindDesignHealthClick(data);
		if (window.lpKg.bindPluginHealthClick) window.lpKg.bindPluginHealthClick(data);
		if (window.lpKg.bindRevenueClick) window.lpKg.bindRevenueClick(data);
		if (window.lpKg.bindTaxonomyClick) window.lpKg.bindTaxonomyClick(data);
		if (window.lpKg.updateCacheBadge) window.lpKg.updateCacheBadge(data);

		// Re-open the detail panel for the same node
		if (nodeType === 'taxonomy') {
			// Re-open the heatmap
			var tax = (data.nodes && data.nodes.taxonomies) || [];
			if (typeof showTaxonomyHeatmap === 'function') {
				showTaxonomyHeatmap(tax);
			} else if (window.lpKg && window.lpKg.showTaxonomyHeatmap) {
				window.lpKg.showTaxonomyHeatmap(tax);
			}
			return;
		}
		if (nodeType === 'language' && langCode) {
			var lNodes = data.nodes.languages || [];
			lNodes.forEach(function(l) {
				if (l.code === langCode) {
					window.lpKg.showDetailPanel({ type: 'language', id: l.id || ('lang_' + l.code), label: l.code.toUpperCase(), coverage: l.coverage_pct, data: l, radius: 16 });
				}
			});
		} else if (nodeId) {
			var items = (data.nodes.products || []).concat(data.nodes.posts || []);
			for (var i = 0; i < items.length; i++) {
				if (items[i].id === nodeId) {
					var p = items[i];
					var type = p.type || (p.word_count !== undefined ? 'post' : 'product');
					var fakeNode = buildNodeFromData(p, type);
					window.lpKg.showDetailPanel(fakeNode);
					break;
				}
			}
		}
	})
	.catch(function(err) {
		if (window.console) console.error('[lpKg] refresh error:', err);
	});
}

function buildNodeFromData(p, type) {
	if (type === 'post') {
		var score = 0;
		if (!p.seo || !p.seo.has_title) score += 15;
		if (!p.seo || !p.seo.has_description) score += 15;
		if (!p.has_featured_image) score += 10;
		if (p.is_stale) score += 10;
		return { id: 'post:' + p.id, type: 'post', label: p.title, score: score, seo: p.seo || {}, data: p, radius: 5 };
	}
	// product
	return {
		id: 'product:' + p.id, type: 'product', label: p.name, radius: 8,
		score: p.opportunity_score || 0, seo: p.seo, enrichment: p.enrichment,
		aeo: p.aeo, translation: p.translation, reviews: p.reviews, data: p
	};
}
