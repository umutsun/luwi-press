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

	// Customer segments — lifecycle chain with a central hub. New → Active →
	// Loyal → VIP on the "healthy" left side; At-risk → Dormant → One-time →
	// Lost on the "drift" right side. Empty segments (count=0) are skipped
	// so the visual isn't cluttered with empty buckets; the hub label shows
	// the total population for context.
	var segmentOrder = { new: 0, active: 1, loyal: 2, vip: 3, at_risk: 4, dormant: 5, one_time: 6, lost: 7 };
	var populated = segmentNodes.filter(function(s){ return (s.count || 0) > 0; });
	var hasSegments = populated.length > 0 && viewFilter === 'customer';
	if (hasSegments) {
		var totalCustomers = segmentNodes.reduce(function(acc, s){ return acc + (s.count || 0); }, 0);
		var hubNode = {
			id: 'segment_hub',
			type: 'segment_hub',
			label: 'All customers (' + totalCustomers + ')',
			radius: 28,
			score: totalCustomers,
			data: { total: totalCustomers, segments: segmentNodes },
			_hub: true
		};
		nodes.push(hubNode);
		nodeMap[hubNode.id] = hubNode;
	}
	// Logarithmic-ish scaling so 37-count one_time doesn't dwarf 2-count lost.
	var maxCount = populated.reduce(function(m, s){ return Math.max(m, s.count || 0); }, 1);
	// Sort populated segments by lifecycle order.
	var populatedSorted = populated.slice().sort(function(a, b){
		return (segmentOrder[a.segment] || 0) - (segmentOrder[b.segment] || 0);
	});
	// Radial layout around the hub: each populated segment gets an angle on a
	// circle. This reads as "hub in the middle, lifecycle rotating around it"
	// — far more compact than a horizontal chain and reliably stays inside
	// the viewport regardless of how many cohorts are populated.
	var n = populatedSorted.length;
	var radialRadius = Math.min(width, height) * 0.3; // 30% of the smaller dimension
	var populatedAngles = {};
	populatedSorted.forEach(function(s, i) {
		// Start at top (-90°) and walk clockwise. Evenly spaced around 360°.
		var angle = -Math.PI / 2 + (i / n) * Math.PI * 2;
		populatedAngles[s.segment] = angle;
	});
	(segmentNodes).forEach(function(s) {
		var count = s.count || 0;
		if (viewFilter === 'customer' && count === 0) return;
		var normalized = Math.sqrt(count / maxCount);
		var r = 14 + Math.round(normalized * 14); // 14..28, slightly smaller than before
		var angle = populatedAngles[s.segment];
		var node = {
			id: s.id || ('segment_' + s.segment),
			type: 'segment',
			label: s.label + ' (' + count + ')',
			radius: r,
			score: count,
			data: s,
			_cohortIdx: segmentOrder[s.segment],
			_targetAngle: angle,
			_targetRadius: radialRadius
		};
		nodes.push(node);
		nodeMap[node.id] = node;
		if (hasSegments) {
			edges.push({ source: node.id, target: 'segment_hub', type: 'member_of', _count: count });
		}
	});

	// Pages — the raw WP pages list is mostly orphaned (parent_id=0) so a
	// pure parent-child graph renders as 60+ isolated dots. In the pages
	// view we add a virtual "Site pages" hub so every page has at least one
	// edge and the graph has structure. Special roles (front / shop / blog)
	// get bigger radii + become secondary hubs if they have children.
	var pageHubId = null;
	if (viewFilter === 'page' && pageNodes.length > 0) {
		pageHubId = 'page_hub';
		var hubNode = {
			id: pageHubId,
			type: 'page_hub',
			label: 'Site pages (' + pageNodes.length + ')',
			radius: 20,
			score: 0,
			_hub: true
		};
		nodes.push(hubNode);
		nodeMap[hubNode.id] = hubNode;
	}
	(pageNodes).forEach(function(p) {
		var score = 0;
		if (p.content_length < 300) score += 10;
		if (p.template === 'default' || !p.template) score += 2;
		var r = 6;
		if (p.is_front_page)  r = 16;
		else if (p.is_shop_page) r = 14;
		else if (p.is_blog_page) r = 12;
		else if (p.parent_id === 0 && (p.children_ids || []).length > 0) r = 10;
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
	// Page edges: (1) real parent-child, (2) fallback hub edge for orphans in pages view.
	(pageNodes).forEach(function(p) {
		if (p.parent_id && nodeMap['page:' + p.parent_id]) {
			edges.push({ source: 'page:' + p.id, target: 'page:' + p.parent_id, type: 'child_of' });
		} else if (pageHubId) {
			// Orphan (top-level) page — attach to the virtual hub so the force
			// simulation gives it structure instead of letting it drift alone.
			edges.push({ source: 'page:' + p.id, target: pageHubId, type: 'member_of' });
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
		if (d.type === 'segment_hub') return '#6366f1'; // indigo — the central customer hub
		if (d.type === 'page_hub')    return '#0ea5e9'; // sky — pages hub
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
		// Radial pull toward the center so orphan nodes (no edges, disconnected
		// categories like Tongue Drum with 0 products) don't drift to the
		// canvas edge. Strength raised substantially for non-connected nodes.
		// Customers view uses cohort-order x-bias so segments lay out
		// left-to-right as a lifecycle chain.
		.force('x', d3.forceX(function(d) {
			// Radial layout for customer segments: each populated segment gets
			// a target position on a circle around the hub, computed at build
			// time and stored on the node as _targetAngle + _targetRadius.
			if (viewFilter === 'customer' && d.type === 'segment' && d._targetAngle !== undefined) {
				return width / 2 + Math.cos(d._targetAngle) * d._targetRadius;
			}
			if (d._hub) return width / 2;
			return width / 2;
		}).strength(function(d) {
			if (viewFilter === 'customer' && d.type === 'segment') return 0.6;
			if (d.type === 'category') return 0.15;
			return 0.12;
		}))
		.force('y', d3.forceY(function(d) {
			if (viewFilter === 'customer' && d.type === 'segment' && d._targetAngle !== undefined) {
				return height / 2 + Math.sin(d._targetAngle) * d._targetRadius;
			}
			if (viewFilter === 'customer' && d._hub) return height / 2;
			return height / 2;
		}).strength(function(d) {
			if (viewFilter === 'customer' && d._hub) return 0.8; // hub nailed to center
			if (viewFilter === 'customer' && d.type === 'segment') return 0.6;
			return 0.14;
		}))
		// Hard boundary — nodes that somehow escape the centering force get
		// clamped inside a 40px inner margin on each tick. Eliminates the
		// "single node at the edge of the canvas 1000px away" problem that
		// the force pull alone couldn't solve for truly-isolated nodes.
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

	// Labels — short types (categories, languages, segments) get full names;
	// posts/pages with long titles are trimmed aggressively (full title is
	// still in the hover tooltip). Prevents the "wall of text" look when
	// dozens of blog post titles overlap each other.
	function shortLabel(d) {
		var raw = d.label || '';
		if (d.type === 'category' || d.type === 'language' || d.type === 'segment' || d.type === 'segment_hub') {
			return raw.length > 24 ? raw.slice(0, 23) + '…' : raw;
		}
		// Posts and pages: 22 chars is plenty for a skim — full title on hover.
		if (d.type === 'post' || d.type === 'page') {
			return raw.length > 22 ? raw.slice(0, 20) + '…' : raw;
		}
		return raw;
	}
	function labelClass(d) {
		// Anchors (categories, languages, hubs, segments) stay bigger/bolder
		// and always visible — they're the navigational structure.
		// Posts/pages are the "long tail" — label renders but is hidden unless
		// the node is hovered so the canvas isn't a wall of text.
		var base = 'kg-label';
		if (d.type === 'category' || d.type === 'language' || d.type === 'segment' ||
			d.type === 'segment_hub' || d.type === 'page_hub') {
			return base + ' kg-label-anchor';
		}
		if (d.type === 'post' || d.type === 'page') {
			return base + ' kg-label-hoverable';
		}
		return base;
	}
	node.filter(function(d){ return d.type !== 'product'; })
		.append('text')
		.attr('dy', function(d){ return d.radius + 11; })
		.attr('text-anchor', 'middle')
		.attr('class', labelClass)
		.text(shortLabel)
		.style('opacity', 0)
		.transition().duration(400).delay(800)
		.style('opacity', null); // let CSS drive the final opacity

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
		// Hubs are visual anchors only — clicking them opens nothing (the
		// tooltip already explains what they are).
		if (d.type === 'segment_hub' || d.type === 'page_hub') return;
		showDetailPanel(d);
	});

	// Tick
	simulation.on('tick', function() {
		// Boundary clamp — any node that's drifted outside the inner margin
		// gets nudged back. Prevents the "one orphan node 1500px to the
		// right" problem for unconnected categories.
		var margin = 40;
		nodes.forEach(function(n) {
			var r = n.radius || 10;
			if (n.x < margin + r)         n.x = margin + r;
			if (n.x > width - margin - r) n.x = width - margin - r;
			if (n.y < margin + r)         n.y = margin + r;
			if (n.y > height - margin - r) n.y = height - margin - r;
		});

		link
			.attr('x1', function(d){ return d.source.x; })
			.attr('y1', function(d){ return d.source.y; })
			.attr('x2', function(d){ return d.target.x; })
			.attr('y2', function(d){ return d.target.y; });

		node.attr('transform', function(d){ return 'translate(' + d.x + ',' + d.y + ')'; });
	});

	// Fit simulation to viewport: compute bounding box of all nodes and pan+scale
	// so everything is visible with a margin. Smaller graphs (customers with 8
	// segments) used to leave half the nodes off-screen — this fixes that.
	function fitToViewport(duration) {
		if (!nodes.length) return;
		var xs = nodes.map(function(n){ return n.x || 0; });
		var ys = nodes.map(function(n){ return n.y || 0; });
		var rs = nodes.map(function(n){ return n.radius || 10; });
		var minX = Math.min.apply(null, xs.map(function(x,i){ return x - rs[i] - 30; }));
		var maxX = Math.max.apply(null, xs.map(function(x,i){ return x + rs[i] + 30; }));
		var minY = Math.min.apply(null, ys.map(function(y,i){ return y - rs[i] - 40; }));
		var maxY = Math.max.apply(null, ys.map(function(y,i){ return y + rs[i] + 40; }));
		var bw = Math.max(1, maxX - minX);
		var bh = Math.max(1, maxY - minY);
		var scale = Math.min(width / bw, height / bh, 2.5);
		if (!isFinite(scale) || scale <= 0) return;
		var tx = width  / 2 - ((minX + maxX) / 2) * scale;
		var ty = height / 2 - ((minY + maxY) / 2) * scale;
		svg.transition().duration(duration || 600).call(
			zoom.transform,
			d3.zoomIdentity.translate(tx, ty).scale(scale)
		);
	}

	// Auto-fit once the simulation has cooled (alpha < 0.1) or after 2.5s fallback
	var autoFitDone = false;
	simulation.on('end', function() {
		if (autoFitDone) return;
		autoFitDone = true;
		fitToViewport(800);
	});
	setTimeout(function() {
		if (autoFitDone) return;
		autoFitDone = true;
		fitToViewport(800);
	}, 2500);

	// Zoom controls
	document.getElementById('kg-zoom-in').onclick = function() {
		svg.transition().duration(300).call(zoom.scaleBy, 1.4);
	};
	document.getElementById('kg-zoom-out').onclick = function() {
		svg.transition().duration(300).call(zoom.scaleBy, 0.7);
	};
	document.getElementById('kg-zoom-fit').onclick = function() {
		// "Fit" now means fit-to-nodes, not reset-to-identity. Much more useful.
		fitToViewport(400);
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
	} else if (d.type === 'post') {
		var pp = d.data || {};
		h += '<div class="kg-tt-row">Score: <b>' + (d.score || 0) + '</b></div>';
		h += '<div class="kg-tt-row">Words: ' + (pp.word_count || 0) + '</div>';
		var ps = d.seo || {};
		h += '<div class="kg-tt-row">SEO: ' + (ps.has_title ? '✓' : '✗') + ' title, ' + (ps.has_description ? '✓' : '✗') + ' desc</div>';
		if (pp.author_name) h += '<div class="kg-tt-row">Author: ' + escHtml(pp.author_name) + '</div>';
	} else if (d.type === 'page') {
		var pg = d.data || {};
		var role = pg.is_front_page ? 'Homepage' : (pg.is_shop_page ? 'Shop' : (pg.is_blog_page ? 'Blog' : (pg.parent_id === 0 ? 'Top-level' : 'Child')));
		h += '<div class="kg-tt-row">Role: <b>' + role + '</b></div>';
		h += '<div class="kg-tt-row">Content: ' + (pg.content_length || 0).toLocaleString() + ' chars</div>';
		if (pg.template && pg.template !== 'default') h += '<div class="kg-tt-row">Template: ' + escHtml(pg.template) + '</div>';
	} else if (d.type === 'segment') {
		var sg = d.data || {};
		h += '<div class="kg-tt-row">Customers: <b>' + (sg.count || 0) + '</b></div>';
		h += '<div class="kg-tt-row">Share: ' + (sg.share_pct || 0).toFixed(1) + '%</div>';
		if (sg.segment) h += '<div class="kg-tt-row">Cohort: ' + escHtml(sg.segment) + '</div>';
	} else if (d.type === 'segment_hub') {
		var hd = d.data || {};
		h += '<div class="kg-tt-row">Total customers: <b>' + (hd.total || 0) + '</b></div>';
		h += '<div class="kg-tt-row">Click a segment around the hub for a breakdown.</div>';
	} else if (d.type === 'page_hub') {
		h += '<div class="kg-tt-row">Site pages overview — click a page for its detail panel.</div>';
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

		// Recommendations — every one is actionable (AI action, editor link, or media link)
		var recs = [];
		var editUrl = '/wp-admin/post.php?post=' + p.id + '&action=edit';
		if (!p.seo || !p.seo.has_title || !p.seo.has_description) {
			var miss = [];
			if (!p.seo || !p.seo.has_title) miss.push('title');
			if (!p.seo || !p.seo.has_description) miss.push('description');
			recs.push({ p: 'high', l: 'Add SEO Meta', d: 'Missing ' + miss.join(' & ') + ' — edit post to add', link: editUrl });
		}
		var missingPostLangs = [];
		langKeys.forEach(function(l) { if (trans[l] !== 'completed') missingPostLangs.push(l); });
		if (missingPostLangs.length) {
			recs.push({a:'translate', l:'Translate ' + missingPostLangs.map(function(x){return x.toUpperCase();}).join(', '), d:missingPostLangs.length + ' language' + (missingPostLangs.length>1?'s':''), p:'medium', langs:missingPostLangs.join(',')});
		}
		if (!p.has_featured_image) recs.push({ p: 'medium', l: 'Add Featured Image', d: 'Improves social sharing & SEO', link: editUrl });
		if (p.word_count < 300) recs.push({ p: 'medium', l: 'Expand Content', d: 'Only ~' + p.word_count + ' words — aim for 600+', link: editUrl });
		if (p.is_stale) recs.push({ p: 'low', l: 'Refresh Content', d: 'Last updated ' + p.days_since_modified + ' days ago', link: editUrl });

		var pri = {high:0,medium:1,low:2};
		recs.sort(function(a,b){ return (pri[a.p]||9)-(pri[b.p]||9); });

		if (recs.length > 0) {
			h += '<div class="kg-p-section"><div class="kg-p-section-title">Recommendations</div>';
			recs.forEach(function(r) {
				if (r.a) {
					var extra = r.langs ? ",'" + r.langs + "'" : '';
					h += '<button class="kg-rec kg-rec-' + r.p + '" onclick="kgAction(\'' + r.a + '\',' + p.id + ',this' + extra + ')">';
					h += '<span class="kg-rec-dot"></span>';
					h += '<span class="kg-rec-body"><strong>' + r.l + '</strong><br><small>' + r.d + '</small></span>';
					h += '</button>';
				} else if (r.link) {
					h += '<a class="kg-rec kg-rec-' + r.p + '" href="' + r.link + '" target="_blank" rel="noopener">';
					h += '<span class="kg-rec-dot"></span>';
					h += '<span class="kg-rec-body"><strong>' + r.l + ' →</strong><br><small>' + r.d + '</small></span>';
					h += '</a>';
				} else {
					h += '<div class="kg-rec kg-rec-' + r.p + '">';
					h += '<span class="kg-rec-dot"></span>';
					h += '<span class="kg-rec-body"><strong>' + r.l + '</strong><br><small>' + r.d + '</small></span>';
					h += '</div>';
				}
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

		// ── Category Recommendations (all clickable, fire kgAction) ──
		// Each rec ships with an action ({a: kgAction key, langs?: code}) so
		// the operator can enrich/translate the whole category in one click.
		var catRecs = [];
		if (cSeo < 50) {
			catRecs.push({ p: 'high', l: 'Improve SEO Coverage', d: 'Only ' + cSeo + '% of products have SEO meta — enrich products in this category', a: 'enrich_category' });
		} else if (cSeo < 80) {
			catRecs.push({ p: 'medium', l: 'Complete SEO Coverage', d: cSeo + '% covered — a few products still missing SEO meta', a: 'enrich_category' });
		}
		if (cEnrich < 50) {
			catRecs.push({ p: 'high', l: 'AI Enrich Products', d: 'Only ' + cEnrich + '% enriched — generate descriptions, FAQ, schema', a: 'enrich_category' });
		} else if (cEnrich < 80) {
			catRecs.push({ p: 'medium', l: 'Complete Enrichment', d: cEnrich + '% enriched — finish remaining products', a: 'enrich_category' });
		}
		Object.keys(c.translation_pct || {}).forEach(function(l) {
			var tp = c.translation_pct[l] || 0;
			if (tp < 80) {
				catRecs.push({ p: 'high', l: 'Translate to ' + l.toUpperCase(), d: 'Only ' + tp + '% translated — ' + Math.round(c.product_count * (100 - tp) / 100) + ' products missing', a: 'translate_category', langs: l });
			} else if (tp < 100) {
				catRecs.push({ p: 'medium', l: 'Complete ' + l.toUpperCase() + ' Translation', d: tp + '% done — almost there', a: 'translate_category', langs: l });
			}
		});

		// Dedupe: SEO + Enrichment both map to enrich_category. Keep the
		// highest-priority rec per action/lang combination so operators see
		// one button per concrete operation, not three telling them the same
		// thing. Preserves the top-priority label and description.
		var seenKey = {};
		var pri = {high:0,medium:1,low:2};
		catRecs.sort(function(a,b){ return (pri[a.p]||9)-(pri[b.p]||9); });
		catRecs = catRecs.filter(function(r) {
			var key = r.a + ':' + (r.langs || '');
			if (seenKey[key]) return false;
			seenKey[key] = true;
			return true;
		});

		if (catRecs.length > 0) {
			h += '<div class="kg-p-section"><div class="kg-p-section-title">Recommendations</div>';
			catRecs.forEach(function(r) {
				var extra = r.langs ? ",'" + r.langs + "'" : '';
				h += '<button class="kg-rec kg-rec-' + r.p + '" onclick="kgAction(\'' + r.a + '\',' + c.id + ',this' + extra + ')">';
				h += '<span class="kg-rec-dot"></span>';
				h += '<span class="kg-rec-body"><strong>' + r.l + '</strong><br><small>' + r.d + '</small></span>';
				h += '</button>';
			});
			h += '</div>';
		} else {
			h += '<div class="kg-p-allgood">All optimizations complete for this category</div>';
		}

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

		// Recommendations — pages live or die by Elementor editor, so every rec links there
		var pageRecs = [];
		var pgEditUrl = '/wp-admin/post.php?post=' + pg.id + '&action=edit';
		if ((pg.content_length || 0) < 300 && !pg.is_front_page) {
			pageRecs.push({ p: 'medium', l: 'Expand content', d: 'Only ' + (pg.content_length || 0) + ' chars — aim for 800+', link: pgEditUrl });
		}
		if (pg.parent_id === 0 && (pg.children_ids || []).length === 0 && !pg.is_front_page && !pg.is_shop_page && !pg.is_blog_page) {
			pageRecs.push({ p: 'low', l: 'Orphan top-level page', d: 'Not referenced by other pages — check menu linking', link: '/wp-admin/nav-menus.php' });
		}

		if (pageRecs.length > 0) {
			h += '<div class="kg-p-section"><div class="kg-p-section-title">Recommendations</div>';
			pageRecs.forEach(function(r) {
				if (r.link) {
					h += '<a class="kg-rec kg-rec-' + r.p + '" href="' + r.link + '" target="_blank" rel="noopener">';
					h += '<span class="kg-rec-dot"></span>';
					h += '<span class="kg-rec-body"><strong>' + r.l + ' →</strong><br><small>' + r.d + '</small></span>';
					h += '</a>';
				} else {
					h += '<div class="kg-rec kg-rec-' + r.p + '">';
					h += '<span class="kg-rec-dot"></span>';
					h += '<span class="kg-rec-body"><strong>' + r.l + '</strong><br><small>' + r.d + '</small></span>';
					h += '</div>';
				}
			});
			h += '</div>';
		} else {
			h += '<div class="kg-p-allgood">All optimizations complete</div>';
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
		var isPrimary = !!l.is_primary;
		var displayName = l.name || l.code.toUpperCase();
		var subtitle = (l.english_name && l.english_name !== displayName) ? l.english_name + ' · ' + l.code.toUpperCase() : l.code.toUpperCase();

		h += '<div class="kg-p-type-badge kg-type-language">' + (isPrimary ? 'Source language' : 'Language') + '</div>';
		h += '<h3 class="kg-p-name">' + displayName + '</h3>';
		h += '<div class="kg-p-meta">' + subtitle + ' · ' + total + ' total products</div>';

		h += '<div class="kg-p-health kg-h-' + covCls + '">';
		h += '<div class="kg-p-health-bar" style="width:' + covPct + '%"></div>';
		h += '<span class="kg-p-health-label">Coverage ' + covPct.toFixed(0) + '%</span></div>';

		// Stats
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Translation Status</div>';
		h += '<div class="kg-p-stat-row"><span>Translated</span><strong style="color:var(--lp-success)">' + translated + '</strong></div>';
		h += '<div class="kg-p-stat-row"><span>Missing</span><strong' + (missing > 0 ? ' class="kg-text-error"' : '') + '>' + missing + '</strong></div>';
		h += '<div class="kg-p-stat-row"><span>Total</span><strong>' + total + '</strong></div>';
		h += '</div>';

		// Recommendations — primary language is the source, nothing to translate
		if (isPrimary) {
			h += '<div class="kg-p-allgood">Source language — all products originate here</div>';
		} else if (missing > 0) {
			h += '<div class="kg-p-section"><div class="kg-p-section-title">Recommendations</div>';
			h += '<button class="kg-rec kg-rec-high" onclick="kgAction(\'translate_lang\',0,this,\'' + l.code + '\')">';
			h += '<span class="kg-rec-dot"></span>';
			h += '<span class="kg-rec-body"><strong>Translate ' + missing + ' missing products</strong><br><small>Complete ' + displayName + ' coverage to 100%</small></span>';
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

		// Segment-specific recommendations — each ships with either a CSV export
		// action (concrete, non-destructive) or a link to the WooCommerce customer list
		// filtered for this cohort. LuwiPress never writes to third-party CRM plugins.
		var segRecs = [];
		var customersUrl = '/wp-admin/users.php?role=customer';
		if (seg === 'one_time' && count > 10) {
			segRecs.push({ p: 'high', l: 'Launch win-back campaign', d: count + ' customers never returned. Export list and feed into your email tool.', a: 'export_segment_csv', seg: seg });
		} else if (seg === 'new' && count > 0) {
			segRecs.push({ p: 'high', l: 'Send onboarding sequence', d: 'Trigger welcome flow with "What to expect next" + category recommendation.', a: 'export_segment_csv', seg: seg });
		} else if (seg === 'at_risk' && count > 0) {
			segRecs.push({ p: 'high', l: 'Re-engagement email', d: 'They loved you once. Offer a loyalty perk before they drift to dormant.', a: 'export_segment_csv', seg: seg });
		} else if (seg === 'dormant' && count > 0) {
			segRecs.push({ p: 'medium', l: 'Exclusive reactivation offer', d: 'One last try — limited discount or new arrivals preview.', a: 'export_segment_csv', seg: seg });
		} else if (seg === 'vip' && count > 0) {
			segRecs.push({ p: 'medium', l: 'VIP perk program', d: 'Early access, free shipping, personal note — keep them feeling special.', a: 'export_segment_csv', seg: seg });
		} else if (seg === 'loyal' && count > 0) {
			segRecs.push({ p: 'low', l: 'Cross-sell adjacent categories', d: 'Loyal buyers already trust your brand. Introduce complementary products.', a: 'export_segment_csv', seg: seg });
		} else if (count === 0) {
			if (seg === 'vip' || seg === 'loyal') {
				segRecs.push({ p: 'medium', l: 'No ' + s.label + ' customers yet', d: 'Build a retention program to cultivate this cohort.', link: customersUrl });
			}
		}
		// Every segment gets a "View in admin" rec
		if (count > 0) {
			segRecs.push({ p: 'low', l: 'View customers in admin', d: 'Open WooCommerce users list (filter manually by this cohort).', link: customersUrl });
		}

		if (segRecs.length > 0) {
			h += '<div class="kg-p-section"><div class="kg-p-section-title">Recommendations</div>';
			segRecs.forEach(function(r) {
				if (r.a === 'export_segment_csv') {
					h += '<button class="kg-rec kg-rec-' + r.p + '" onclick="kgExportSegmentCsv(\'' + r.seg + '\',this)">';
					h += '<span class="kg-rec-dot"></span>';
					h += '<span class="kg-rec-body"><strong>' + escHtml(r.l) + '</strong><br><small>' + escHtml(r.d) + '</small></span>';
					h += '</button>';
				} else if (r.link) {
					h += '<a class="kg-rec kg-rec-' + r.p + '" href="' + r.link + '" target="_blank" rel="noopener">';
					h += '<span class="kg-rec-dot"></span>';
					h += '<span class="kg-rec-body"><strong>' + escHtml(r.l) + ' →</strong><br><small>' + escHtml(r.d) + '</small></span>';
					h += '</a>';
				} else {
					h += '<div class="kg-rec kg-rec-' + r.p + '">';
					h += '<span class="kg-rec-dot"></span>';
					h += '<span class="kg-rec-body"><strong>' + escHtml(r.l) + '</strong><br><small>' + escHtml(r.d) + '</small></span>';
					h += '</div>';
				}
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

	// Media health: penalise missing alt text (primary SEO/accessibility metric).
	// null → no images at all → N/A rather than 0%.
	var media = (data.nodes && data.nodes.media_inventory) || null;
	var mediaHealth = null;
	if (media && (media.total_images || 0) > 0) {
		var altCoverage = 100 - ((media.missing_alt_count || 0) / media.total_images * 100);
		mediaHealth = Math.max(0, Math.round(altCoverage));
	}

	var stats = {
		total_products: summary.total_products || 0,
		total_posts: summary.total_posts || 0,
		seo_coverage: summary.seo_coverage || 0,
		enrichment_coverage: summary.enrichment_coverage || 0,
		opportunity_score_total: summary.opportunity_score_total || 0,
		design_health: designUnavailable ? null : (designSummary.overall_health || 0),
		plugin_readiness: Math.round(pluginHealth.readiness_score || 0),
		revenue_30d: Math.round(revenue30),
		taxonomy_coverage: taxCoverage,
		media_health: mediaHealth
	};

	renderStoreHealthHero(stats, summary);
	renderActionQueue(data);
	renderAchievements(stats, summary, data);

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

// ── Store Health Hero ──
// Weighted average of every "health" metric the graph exposes. The goal is
// a single anchor number the operator can come back to: "my store is 71%
// healthy today." Individual metrics are still one click away in the stat
// bar below. Weights reflect what actually moves the SEO/UX needle.
function renderStoreHealthHero(stats, summary) {
	// New DOM (3.1.28): health is a header-embedded pill (#kg-hero-toggle) with
	// inline score + progress bar; details panel (#kg-hero-detail) holds the
	// subtitle, chip breakdown, and achievements and is hidden at rest.
	var pill = document.getElementById('kg-hero-toggle');
	var scoreEl = document.getElementById('kg-hero-score');
	var barEl = document.getElementById('kg-hero-bar');
	var metaEl = document.getElementById('kg-hero-meta');
	var subtitleEl = document.getElementById('kg-hero-subtitle');
	if (!pill || !scoreEl || !barEl) return;

	// Translation coverage: average across target languages only (primary = 100%).
	var transCov = summary.translation_coverage || {};
	var transVals = Object.keys(transCov).map(function(k){ return Number(transCov[k]) || 0; });
	var transAvg = transVals.length > 0 ? transVals.reduce(function(a,b){return a+b;}, 0) / transVals.length : null;

	// Weight table — higher weight = bigger contribution to overall health.
	// null values drop out; remaining weights are renormalised so missing
	// dimensions (e.g. no Elementor) don't artificially punish the score.
	var parts = [
		{ key: 'seo',        label: 'SEO',          value: stats.seo_coverage,        weight: 2 },
		{ key: 'enrichment', label: 'Enrichment',   value: stats.enrichment_coverage, weight: 2 },
		{ key: 'translation',label: 'Translation',  value: transAvg,                  weight: 1 },
		{ key: 'taxonomy',   label: 'Taxonomy',     value: stats.taxonomy_coverage,   weight: 1 },
		{ key: 'design',     label: 'Design',       value: stats.design_health,       weight: 1 },
		{ key: 'media',      label: 'Media',        value: stats.media_health,        weight: 1 },
		{ key: 'plugins',    label: 'Plugins',      value: stats.plugin_readiness,    weight: 1 }
	].filter(function(p) { return p.value !== null && p.value !== undefined && !isNaN(p.value); });

	var totalWeight = parts.reduce(function(a,p){ return a + p.weight; }, 0);
	var weightedSum = parts.reduce(function(a,p){ return a + (p.value * p.weight); }, 0);
	var score = totalWeight > 0 ? Math.round(weightedSum / totalWeight) : 0;

	// Colour band: ≥80 green, ≥50 amber, else red. Matches the kg-h-* system used everywhere.
	scoreEl.classList.remove('kg-h-good','kg-h-warn','kg-h-bad');
	if (score >= 80) scoreEl.classList.add('kg-h-good');
	else if (score >= 50) scoreEl.classList.add('kg-h-warn');
	else scoreEl.classList.add('kg-h-bad');

	scoreEl.textContent = score;
	barEl.style.width = Math.max(2, score) + '%';

	// Qualitative subtitle matching the band, so the operator sees a
	// direction ("you're on track" vs "needs attention"), not just a number.
	if (subtitleEl) {
		var msg = 'Weighted across content, translation, design, and media.';
		if (score >= 80) msg = 'Your store is in solid shape — keep closing the small gaps to hit 100%.';
		else if (score >= 50) msg = 'Decent foundation with room to grow. Target the weakest dimension below first.';
		else msg = 'Significant gaps across several dimensions. Start with the highest-weight weakness (SEO or enrichment).';

		// Trend delta — appended only when we have ≥7-day history. Positive
		// SEO/enrichment movement or dropping opportunity count is progress.
		var trend = summary.trend || {};
		if (trend.points_count && trend.points_count >= 2 && trend.opportunities_delta !== null) {
			var parts = [];
			if (trend.seo_delta !== null && Math.abs(trend.seo_delta) >= 0.1) {
				parts.push((trend.seo_delta > 0 ? '+' : '') + trend.seo_delta + '% SEO');
			}
			if (trend.enrichment_delta !== null && Math.abs(trend.enrichment_delta) >= 0.1) {
				parts.push((trend.enrichment_delta > 0 ? '+' : '') + trend.enrichment_delta + '% enriched');
			}
			if (trend.opportunities_delta !== 0) {
				// Negative delta = fewer opportunities = progress.
				var oppSign = trend.opportunities_delta > 0 ? '+' : '';
				parts.push(oppSign + trend.opportunities_delta + ' opportunity pts');
			}
			if (parts.length > 0) {
				msg += ' · Last 7 days: ' + parts.join(', ') + '.';
			}
		}
		subtitleEl.textContent = msg;
	}

	// Breakdown chips — show the weakest dimensions first so the operator
	// knows where one click gives them the biggest lift. Defensive: drop any
	// chip whose label or value didn't survive the parts[] pipeline (caused
	// "undefined NaN%" chips when a backend stat returned null/undefined and
	// snuck past the value-only filter above).
	if (metaEl) {
		var renderable = parts.filter(function(p) {
			return p && typeof p.label === 'string' && p.label.length > 0
				&& typeof p.value === 'number' && !isNaN(p.value);
		});
		var sorted = renderable.slice().sort(function(a,b){ return a.value - b.value; });
		metaEl.innerHTML = sorted.map(function(p) {
			var cls = p.value >= 80 ? 'kg-h-good' : p.value >= 50 ? 'kg-h-warn' : 'kg-h-bad';
			return '<span class="kg-hero-meta-chip"><strong>' + escHtml(p.label) + '</strong> <span class="' + cls + '">' + Math.round(p.value) + '%</span></span>';
		}).join('');
	}

	pill.hidden = false;
}

// ── Activity Feed ──
// Pulls the latest N entries from /logs and renders a compact list below the
// graph. Auto-polls every 30s while the page is visible; pauses when hidden
// (document.visibilityState === 'hidden') so background tabs don't burn quota.
var _kgActivityPoll = null;
var _kgActivityLastCount = -1;

function initActivityFeed() {
	fetchActivity();
	if (_kgActivityPoll) clearInterval(_kgActivityPoll);
	_kgActivityPoll = setInterval(function() {
		if (document.visibilityState === 'hidden') return; // don't poll when tab hidden
		fetchActivity();
	}, 30000);
}

function fetchActivity() {
	var base = (window.lpKgConfig && window.lpKgConfig.restBase) || '';
	if (!base) return;
	var url = base + 'logs?limit=25';
	var headers = { 'X-WP-Nonce': (window.lpKgConfig && window.lpKgConfig.nonce) || '' };
	var apiToken = (window.lpKgConfig && window.lpKgConfig.apiToken) || '';
	if (apiToken) headers['Authorization'] = 'Bearer ' + apiToken;

	fetch(url, { headers: headers, credentials: 'same-origin' })
		.then(function(r) { return r.ok ? r.json() : null; })
		.then(function(resp) {
			if (!resp || !Array.isArray(resp.logs)) return;
			renderActivityFeed(resp.logs);
		})
		.catch(function(err) {
			// Silent fail — activity feed is non-critical.
			if (window.console) console.warn('[lpKg] activity fetch failed:', err);
		});
}

function renderActivityFeed(logs) {
	var wrap = document.getElementById('kg-activity');
	var list = document.getElementById('kg-activity-list');
	var sub  = document.getElementById('kg-activity-sub');
	if (!wrap || !list) return;

	if (!logs || logs.length === 0) {
		list.innerHTML = '<div class="kg-activity-empty">No recent activity. Fire an enrichment or translation above and watch it land here.</div>';
		if (sub) sub.textContent = '';
		wrap.hidden = false;
		return;
	}

	// Subtle "new activity" hint when the count grows between polls.
	if (_kgActivityLastCount >= 0 && logs.length > _kgActivityLastCount) {
		var delta = logs.length - _kgActivityLastCount;
		if (sub) sub.textContent = '+' + delta + ' new';
		setTimeout(function(){ if (sub) sub.textContent = logs.length + ' entries'; }, 2500);
	} else {
		if (sub) sub.textContent = logs.length + ' entries';
	}
	_kgActivityLastCount = logs.length;

	// Render the 20 most-recent entries (backend returns newest first).
	var html = logs.slice(0, 20).map(function(entry) {
		var ts = entry.timestamp ? formatActivityTime(entry.timestamp) : '';
		var level = (entry.level || 'info').toLowerCase();
		var msg = entry.message || '';
		return '<div class="kg-activity-row">'
			+ '<span class="kg-activity-time" title="' + escHtml(entry.timestamp || '') + '">' + escHtml(ts) + '</span>'
			+ '<span class="kg-activity-level" data-level="' + escHtml(level) + '">' + escHtml(level) + '</span>'
			+ '<span class="kg-activity-msg" title="' + escHtml(msg) + '">' + escHtml(msg) + '</span>'
			+ '</div>';
	}).join('');
	list.innerHTML = html;
	wrap.hidden = false;
}

function formatActivityTime(ts) {
	// Relative time for recent, fallback to HH:MM for older-than-1h.
	var d = new Date(ts.replace(' ', 'T'));
	if (isNaN(d.getTime())) return ts;
	var now = Date.now();
	var diffS = Math.max(0, Math.round((now - d.getTime()) / 1000));
	if (diffS < 60)       return diffS + 's ago';
	if (diffS < 3600)     return Math.floor(diffS / 60) + 'm ago';
	if (diffS < 86400)    return Math.floor(diffS / 3600) + 'h ago';
	if (diffS < 7 * 86400) return Math.floor(diffS / 86400) + 'd ago';
	// Older than a week — fall back to HH:MM.
	var hh = String(d.getHours()).padStart(2, '0');
	var mm = String(d.getMinutes()).padStart(2, '0');
	return d.toLocaleDateString() + ' ' + hh + ':' + mm;
}

// ── Achievements ──
// Lightweight milestone badges derived from current coverage. No persistence:
// badges earned are always displayed, ones not yet earned are hidden. The
// reward here is visual confirmation of a crossed threshold — gamification
// without an ops-heavy achievements database. Tier colours (bronze→platinum)
// reflect difficulty of the milestone.
function renderAchievements(stats, summary, data) {
	var container = document.getElementById('kg-achievements');
	if (!container) return;

	var translations = summary.translation_coverage || {};
	var transVals = Object.keys(translations).map(function(k){ return Number(translations[k]) || 0; });
	var transAvg = transVals.length > 0 ? (transVals.reduce(function(a,b){return a+b;}, 0) / transVals.length) : 0;
	var allLangs100 = transVals.length > 0 && transVals.every(function(v){ return v >= 100; });

	var products = (data && data.nodes && data.nodes.products) || [];
	var enrichedCount = products.filter(function(p){ return p.enrichment && p.enrichment.status === 'completed'; }).length;

	// Achievement catalogue — each rule checks a threshold; earned ones render.
	var rules = [
		{ cond: stats.seo_coverage >= 50,          tier: 'bronze',   icon: '🎯', text: 'SEO > 50%' },
		{ cond: stats.seo_coverage >= 80,          tier: 'silver',   icon: '🎯', text: 'SEO > 80%' },
		{ cond: stats.seo_coverage >= 95,          tier: 'gold',     icon: '🎯', text: 'SEO > 95%' },
		{ cond: stats.enrichment_coverage >= 50,   tier: 'bronze',   icon: '✨', text: 'Enrichment > 50%' },
		{ cond: stats.enrichment_coverage >= 80,   tier: 'silver',   icon: '✨', text: 'Enrichment > 80%' },
		{ cond: stats.enrichment_coverage >= 95,   tier: 'gold',     icon: '✨', text: 'Enrichment > 95%' },
		{ cond: transAvg >= 80 && transVals.length > 0, tier: 'silver',   icon: '🌍', text: 'Translation > 80%' },
		{ cond: allLangs100,                       tier: 'gold',     icon: '🌍', text: 'All languages 100%' },
		{ cond: stats.taxonomy_coverage >= 90,     tier: 'silver',   icon: '🏷️', text: 'Taxonomy > 90%' },
		{ cond: stats.taxonomy_coverage >= 100,    tier: 'gold',     icon: '🏷️', text: 'Taxonomy 100%' },
		{ cond: stats.media_health !== null && stats.media_health >= 80, tier: 'silver', icon: '🖼️', text: 'Alt text > 80%' },
		{ cond: stats.design_health !== null && stats.design_health >= 90, tier: 'silver', icon: '🎨', text: 'Design > 90%' },
		{ cond: stats.plugin_readiness >= 90,      tier: 'silver',   icon: '🔧', text: 'Plugin health > 90%' },
		{ cond: products.length >= 100,            tier: 'bronze',   icon: '📦', text: '100+ products' },
		{ cond: products.length >= 500,            tier: 'silver',   icon: '📦', text: '500+ products' },
		{ cond: products.length >= 1000,           tier: 'gold',     icon: '📦', text: '1,000+ products' },
		{ cond: enrichedCount >= 50,               tier: 'bronze',   icon: '🚀', text: '50+ enriched' },
		{ cond: enrichedCount >= 100,              tier: 'silver',   icon: '🚀', text: '100+ enriched' },
		// Grand-slam: everything >= 95
		{ cond: stats.seo_coverage >= 95 && stats.enrichment_coverage >= 95 && transAvg >= 95 && stats.taxonomy_coverage >= 95, tier: 'platinum', icon: '💎', text: 'Grand slam' }
	];

	var earned = rules.filter(function(r){ return r.cond; });
	if (earned.length === 0) {
		container.innerHTML = '';
		return;
	}

	// Only show the top-tier badge per category (drop bronze if silver earned, etc.)
	// Simple dedupe by `text suffix` — group by the emoji + base label.
	var byPrefix = {};
	earned.forEach(function(r) {
		var prefix = r.icon;
		var tierRank = { bronze: 1, silver: 2, gold: 3, platinum: 4 }[r.tier] || 0;
		if (!byPrefix[prefix] || tierRank > byPrefix[prefix].rank) {
			byPrefix[prefix] = { rule: r, rank: tierRank };
		}
	});
	var deduped = Object.keys(byPrefix).map(function(k){ return byPrefix[k].rule; });

	// Cap at 6 badges so the row doesn't wrap into 3 lines.
	deduped = deduped.slice(0, 6);

	container.innerHTML = deduped.map(function(r) {
		return '<span class="kg-ach" data-tier="' + r.tier + '" title="' + escHtml(r.tier.charAt(0).toUpperCase() + r.tier.slice(1)) + ' achievement"><span class="kg-ach-icon">' + r.icon + '</span>' + escHtml(r.text) + '</span>';
	}).join('');
}

// ── Action Queue (Next Wins) ──
// Turns the KG data into 3 ranked, actionable suggestions. Each candidate
// gets an impact score (affected × weight), an effort estimate (minutes),
// and roi = impact / effort. Top 3 by roi are rendered with one-click CTAs
// that either fire kgAction directly or open the relevant detail panel for
// the operator to review before acting.
function renderActionQueue(data) {
	var wrap = document.getElementById('kg-action-queue');
	var list = document.getElementById('kg-action-queue-list');
	if (!wrap || !list || !data || !data.nodes) return;

	var products = data.nodes.products || [];
	var categories = data.nodes.categories || [];
	var languages  = data.nodes.languages || [];
	var taxonomies = data.nodes.taxonomies || [];
	var media = data.nodes.media_inventory || null;
	var candidates = [];

	// CANDIDATE 1: Worst-covered category for SEO — one batch enrich hits the most products at once.
	// Skip categories with < 3 products (not worth a batch) and those already >= 80% SEO.
	var seoTargets = categories.filter(function(c) {
		return (c.product_count || 0) >= 3 && (c.seo_coverage_pct || 0) < 80;
	}).sort(function(a, b) {
		// Prefer biggest product_count × (100 - coverage) = most missing SEO meta
		var aGap = (a.product_count || 0) * (100 - (a.seo_coverage_pct || 0));
		var bGap = (b.product_count || 0) * (100 - (b.seo_coverage_pct || 0));
		return bGap - aGap;
	});
	if (seoTargets.length > 0) {
		var cat = seoTargets[0];
		var missing = Math.round(cat.product_count * (100 - (cat.seo_coverage_pct || 0)) / 100);
		candidates.push({
			tier: 'high',
			label: 'Enrich "' + cat.name + '" category',
			why: missing + ' of ' + cat.product_count + ' products still missing SEO — batch handles them in one click.',
			meta: ['SEO', '+~' + Math.round(missing / products.length * 100) + '% site coverage', '~' + (missing * 2) + 'min'],
			impact: missing * 5,
			effort: Math.max(1, missing * 2),
			onClick: function(btn) {
				// Open the category detail panel so the operator can hit the action there (richer context)
				if (window.lpKg && window.lpKg.showDetailPanel) {
					window.lpKg.showDetailPanel({ type: 'category', data: cat, label: cat.name, radius: 12 });
				}
			}
		});
	}

	// CANDIDATE 2: Translation backlog on a single language — fastest path to 100% coverage lift.
	// Prefer the target language with the fewest missing products (closest to goal).
	var transTargets = languages.filter(function(l) {
		return !l.is_primary && (l.products_missing || 0) > 0;
	}).sort(function(a, b) {
		return (a.products_missing || 0) - (b.products_missing || 0);
	});
	if (transTargets.length > 0) {
		var lang = transTargets[0];
		var label = lang.name || (lang.code || '').toUpperCase();
		candidates.push({
			tier: lang.products_missing <= 5 ? 'low' : 'medium',
			label: 'Translate ' + lang.products_missing + ' products to ' + label,
			why: 'Finishes ' + label + ' coverage from ' + (lang.coverage_pct || 0).toFixed(0) + '% → 100% in one batch.',
			meta: ['Translation', label, '~' + (lang.products_missing * 3) + 'min'],
			impact: lang.products_missing * 3,
			effort: Math.max(1, lang.products_missing * 3),
			onClick: function() {
				// Fire the translate_lang action directly — the language-level batch
				kgAction('translate_lang', 0, document.getElementById('kg-aq-cta-' + 'lang-' + lang.code), lang.code);
			},
			ctaId: 'kg-aq-cta-lang-' + lang.code,
			ctaLabel: 'Translate all'
		});
	}

	// CANDIDATE 3: Taxonomy missing translations — often a <1 min job per language with outsized SEO impact.
	var taxByLang = {};
	taxonomies.forEach(function(term) {
		var trans = term.translations || {};
		Object.keys(trans).forEach(function(code) {
			var st = trans[code] && trans[code].status;
			if (st === 'missing') {
				taxByLang[code] = (taxByLang[code] || 0) + 1;
			}
		});
	});
	var taxCodes = Object.keys(taxByLang);
	if (taxCodes.length > 0) {
		// Pick the language with the most missing terms (biggest single-click win)
		taxCodes.sort(function(a, b) { return taxByLang[b] - taxByLang[a]; });
		var taxCode = taxCodes[0];
		var taxMissing = taxByLang[taxCode];
		var taxLangNode = languages.filter(function(l){return l.code===taxCode;})[0];
		var taxLangLabel = (taxLangNode && taxLangNode.name) || taxCode.toUpperCase();
		candidates.push({
			tier: 'medium',
			label: 'Translate ' + taxMissing + ' taxonomy terms to ' + taxLangLabel,
			why: 'Category/tag translations feed product hreflang — small job, broad SEO lift across every translated page.',
			meta: ['Taxonomy', taxLangLabel, '~' + Math.max(1, Math.round(taxMissing / 5)) + 'min'],
			impact: taxMissing * 8, // disproportionately high weight — taxonomy pages rank
			effort: Math.max(1, Math.round(taxMissing / 5)),
			onClick: function() {
				if (window.lpKg && window.lpKg.showTaxonomyHeatmap) {
					window.lpKg.showTaxonomyHeatmap(taxonomies);
				}
			}
		});
	}

	// CANDIDATE 4: Media alt text — huge accessibility + SEO win if significant gap.
	if (media && (media.missing_alt_count || 0) >= 10) {
		candidates.push({
			tier: media.missing_alt_count > 100 ? 'high' : 'medium',
			label: 'Add alt text to ' + media.missing_alt_count + ' images',
			why: 'Alt text drives image search + accessibility — currently ' + Math.round((media.missing_alt_count / media.total_images) * 100) + '% of your library is uncovered.',
			meta: ['Media', 'SEO + A11y', 'Manual'],
			impact: Math.min(200, media.missing_alt_count),
			effort: Math.max(30, media.missing_alt_count), // manual, slower than AI batch
			onClick: function() {
				if (window.lpKg && window.lpKg.showMediaInventoryPanel) {
					window.lpKg.showMediaInventoryPanel(media);
				}
			}
		});
	}

	// CANDIDATE 5: AEO schema gap — HowTo / Schema / Speakable on top enriched products.
	// Rich results (HowTo cards, review snippets) only fire when the structured data exists;
	// many stores have FAQ (easy) but never touch the rest. Target the product with the
	// most gaps among already-enriched items — best ratio of prep work to rich-result lift.
	var aeoCov = (data.summary && data.summary.aeo_coverage) || {};
	var missingAeoTypes = [];
	if ((aeoCov.howto || 0)     < 30) missingAeoTypes.push({ key: 'howto',     label: 'HowTo',     action: 'howto',     prio: 'medium' });
	if ((aeoCov.schema || 0)    < 30) missingAeoTypes.push({ key: 'schema',    label: 'Schema',    action: null,        prio: 'low'    });
	if ((aeoCov.speakable || 0) < 30) missingAeoTypes.push({ key: 'speakable', label: 'Speakable', action: null,        prio: 'low'    });
	if (missingAeoTypes.length > 0) {
		// Pick the AEO type with the most actionable potential (howto > schema > speakable).
		var gap = missingAeoTypes[0];
		// Find a product that's enriched (ready for AEO) but missing this AEO field.
		var aeoTarget = products.filter(function(p) {
			var enriched = p.enrichment && p.enrichment.status === 'completed';
			var aeoMap = p.aeo || {};
			var has = aeoMap['has_' + gap.key];
			return enriched && !has;
		}).sort(function(a, b) {
			return (b.opportunity_score || 0) - (a.opportunity_score || 0);
		})[0];
		if (aeoTarget) {
			candidates.push({
				tier: gap.prio,
				label: 'Generate ' + gap.label + ' for "' + ((aeoTarget.name || '').slice(0, 35) + ((aeoTarget.name || '').length > 35 ? '…' : '')) + '"',
				why: 'Only ' + Math.round(aeoCov[gap.key] || 0) + '% of products have ' + gap.label + ' schema — the enriched ones are low-hanging rich-result wins.',
				meta: ['AEO', gap.label, '~1min'],
				impact: 30,
				effort: 1,
				onClick: (function(p, a) {
					return function() {
						if (a === 'howto' || a === 'faq') {
							kgAction(a, p.id, document.createElement('button'));
						} else if (window.lpKg && window.lpKg.showDetailPanel) {
							var fake = { type: 'product', data: p, label: p.name, radius: 8, score: p.opportunity_score, seo: p.seo, enrichment: p.enrichment, aeo: p.aeo, translation: p.translation };
							window.lpKg.showDetailPanel(fake);
						}
					};
				})(aeoTarget, gap.action)
			});
		}
	}

	// CANDIDATE 6: Top-opportunity single product — single high-impact enrichment.
	var thinOpp = products.filter(function(p) {
		return (p.content_length || 0) < 800 && (p.opportunity_score || 0) > 30;
	}).sort(function(a, b) {
		return (b.opportunity_score || 0) - (a.opportunity_score || 0);
	});
	if (thinOpp.length > 0) {
		var p = thinOpp[0];
		candidates.push({
			tier: 'low',
			label: 'Enrich "' + ((p.name || '').slice(0, 40) + ((p.name || '').length > 40 ? '…' : '')) + '"',
			why: 'Highest-opportunity product in the catalogue (score ' + p.opportunity_score + '). Thin content + missing SEO — AI can fix both in ~90s.',
			meta: ['Single product', 'Opportunity ' + p.opportunity_score, '~1.5min'],
			impact: p.opportunity_score,
			effort: 2,
			onClick: function() {
				// Open the product detail panel — gives the operator visibility on what's missing before firing
				if (window.lpKg && window.lpKg.showDetailPanel) {
					var fake = { type: 'product', data: p, label: p.name, radius: 8, score: p.opportunity_score, seo: p.seo, enrichment: p.enrichment, aeo: p.aeo, translation: p.translation };
					window.lpKg.showDetailPanel(fake);
				}
			}
		});
	}

	// Rank by ROI (impact / effort). Take top 3.
	candidates.forEach(function(c) {
		c.roi = c.impact / Math.max(1, c.effort);
	});
	candidates.sort(function(a, b) { return b.roi - a.roi; });
	candidates = candidates.slice(0, 3);

	// Render
	if (candidates.length === 0) {
		list.innerHTML = '<div class="kg-aq-empty">No pressing wins right now — your store is in excellent shape. 🎉</div>';
		wrap.hidden = false;
		return;
	}

	var html = '';
	candidates.forEach(function(c, i) {
		var ctaId = c.ctaId || ('kg-aq-cta-' + i);
		var ctaLabel = c.ctaLabel || 'Review →';
		html += '<div class="kg-aq-card" data-tier="' + c.tier + '" data-idx="' + i + '">';
		html += '<span class="kg-aq-rank">#' + (i + 1) + '</span>';
		html += '<div class="kg-aq-label">' + escHtml(c.label) + '</div>';
		html += '<div class="kg-aq-why">' + escHtml(c.why) + '</div>';
		html += '<div class="kg-aq-meta">';
		c.meta.forEach(function(m) { html += '<span class="kg-aq-meta-chip">' + escHtml(m) + '</span>'; });
		html += '</div>';
		html += '<button type="button" class="kg-aq-cta" id="' + ctaId + '">' + escHtml(ctaLabel) + '</button>';
		html += '</div>';
	});
	list.innerHTML = html;

	// Wire the CTAs and the whole-card click (card click = same as CTA)
	candidates.forEach(function(c, i) {
		var card = list.querySelector('[data-idx="' + i + '"]');
		if (!card) return;
		var cta = card.querySelector('.kg-aq-cta');
		var handler = function(e) {
			if (e) e.stopPropagation();
			if (typeof c.onClick === 'function') c.onClick();
		};
		card.addEventListener('click', handler);
		if (cta) cta.addEventListener('click', handler);
	});

	wrap.hidden = false;
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
			bindMediaClick(data);
			updateCacheBadge(data);
			updatePresetCounts(data);
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

function bindMediaClick(data) {
	var card = document.getElementById('kg-stat-media');
	if (!card) return;
	var media = (data.nodes && data.nodes.media_inventory) || null;
	// Hide the card entirely when there are no images to audit.
	if (!media || (media.total_images || 0) === 0) {
		card.onclick = null;
		card.classList.add('kg-stat-disabled');
		return;
	}
	card.onclick = function() { showMediaInventoryPanel(media); };
}

function showMediaInventoryPanel(media) {
	var panel = document.getElementById('kg-detail-panel');
	var content = document.getElementById('kg-detail-content');
	var total = media.total_images || 0;
	var missingAlt = media.missing_alt_count || 0;
	var orphaned = media.orphaned_count || 0;
	var altCoveragePct = total > 0 ? Math.round((total - missingAlt) / total * 100) : 0;
	var cls = altCoveragePct >= 80 ? 'good' : altCoveragePct >= 50 ? 'warn' : 'bad';
	var h = '';

	h += '<div class="kg-p-type-badge" style="background:#10b981;color:#fff">Media Library</div>';
	h += '<h3 class="kg-p-name">Media Health Report</h3>';
	h += '<div class="kg-p-meta">' + total.toLocaleString() + ' images · ' + (media.total_videos || 0) + ' videos · ' + (media.total_documents || 0) + ' documents</div>';

	h += '<div class="kg-p-health kg-h-' + cls + '">';
	h += '<div class="kg-p-health-bar" style="width:' + altCoveragePct + '%"></div>';
	h += '<span class="kg-p-health-label">Alt text coverage ' + altCoveragePct + '%</span></div>';

	h += '<div class="kg-p-section"><div class="kg-p-section-title">Breakdown</div>';
	h += '<div class="kg-p-stat-row"><span>Images with alt text</span><strong style="color:var(--lp-success)">' + (total - missingAlt).toLocaleString() + '</strong></div>';
	h += '<div class="kg-p-stat-row"><span>Missing alt text</span><strong' + (missingAlt > 0 ? ' class="kg-text-error"' : '') + '>' + missingAlt.toLocaleString() + '</strong></div>';
	h += '<div class="kg-p-stat-row"><span>Orphaned (unused)</span><strong' + (orphaned > 0 ? ' style="color:var(--lp-warning)"' : '') + '>' + orphaned.toLocaleString() + '</strong></div>';
	h += '<div class="kg-p-stat-row"><span>Total library size</span><strong>' + (media.total_media || 0).toLocaleString() + '</strong></div>';
	h += '</div>';

	// Recommendations — link to the filter-friendly Media library views.
	var recs = [];
	if (missingAlt > 0) {
		recs.push({ p: 'high', l: 'Add alt text to ' + missingAlt.toLocaleString() + ' images', d: 'Accessibility + SEO. Use the Media library bulk editor to add descriptions.', link: '/wp-admin/upload.php' });
	}
	if (orphaned > 0) {
		recs.push({ p: 'medium', l: 'Review ' + orphaned.toLocaleString() + ' orphaned files', d: 'Not attached to any post/product — delete to reclaim disk or confirm they\'re still needed.', link: '/wp-admin/upload.php?detached=1' });
	}

	if (recs.length > 0) {
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Recommendations</div>';
		recs.forEach(function(r) {
			h += '<a class="kg-rec kg-rec-' + r.p + '" href="' + r.link + '" target="_blank" rel="noopener">';
			h += '<span class="kg-rec-dot"></span>';
			h += '<span class="kg-rec-body"><strong>' + escHtml(r.l) + ' →</strong><br><small>' + escHtml(r.d) + '</small></span>';
			h += '</a>';
		});
		h += '</div>';
	} else {
		h += '<div class="kg-p-allgood">Media library is in great shape — every image has alt text and nothing is orphaned.</div>';
	}

	// Top 5 largest files — ops insight (page weight, storage cost).
	var largest = (media.largest_files || []).slice(0, 5);
	if (largest.length > 0) {
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Largest files</div>';
		largest.forEach(function(f) {
			var kb = Math.round((f.filesize || 0) / 1024);
			var sizeStr = kb >= 1024 ? (kb / 1024).toFixed(1) + ' MB' : kb + ' KB';
			var title = (f.title || '').slice(0, 60);
			h += '<div class="kg-p-stat-row"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:70%;" title="' + escHtml(f.title || '') + '">' + escHtml(title) + (f.title && f.title.length > 60 ? '…' : '') + '</span>';
			h += '<strong>' + sizeStr + '</strong></div>';
		});
		h += '</div>';
	}

	content.innerHTML = h;
	panel.classList.add('open');
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
		badge.textContent = 'fresh (' + Math.round(meta.execution_time_ms || 0) + 'ms)';
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
		var pages    = _kgData.nodes.pages || [];
		var cats     = _kgData.nodes.categories || [];
		var segments = _kgData.nodes.customer_segments || [];

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
		pages.forEach(function(p) {
			var label = (p.title || '') + ' ' + (p.slug || '');
			if (label.toLowerCase().indexOf(q) !== -1) {
				var meta = p.is_front_page ? 'Homepage' : (p.is_shop_page ? 'Shop page' : (p.is_blog_page ? 'Blog page' : 'Page'));
				hits.push({ id: 'page:' + p.id, type: 'page', label: p.title || ('Page #' + p.id), meta: meta });
			}
		});
		cats.forEach(function(c) {
			if ((c.name || '').toLowerCase().indexOf(q) !== -1) {
				hits.push({ id: 'category:' + c.id, type: 'category', label: c.name, meta: c.product_count + ' products' });
			}
		});
		segments.forEach(function(s) {
			if ((s.label || '').toLowerCase().indexOf(q) !== -1 || (s.segment || '').toLowerCase().indexOf(q) !== -1) {
				hits.push({ id: s.id || ('segment_' + s.segment), type: 'segment', label: s.label, meta: (s.count || 0) + ' customers' });
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
		// Close every other .kg-dropdown so only one is ever open.
		// Hide the menu AND flip the sibling trigger's aria-expanded to false
		// so state + a11y stay in sync.
		document.querySelectorAll('.kg-dropdown').forEach(function(otherRoot) {
			if (otherRoot === root) return;
			var otherMenu    = otherRoot.querySelector('.kg-dropdown-menu');
			var otherTrigger = otherRoot.querySelector('.kg-btn');
			if (otherMenu)    otherMenu.hidden = true;
			if (otherTrigger) otherTrigger.setAttribute('aria-expanded', 'false');
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

// Inject match counts into preset menu items so operators see how many nodes
// each filter selects before committing. Called whenever _kgData refreshes.
function updatePresetCounts(data) {
	if (!data || !data.nodes) return;
	var products = data.nodes.products || [];
	var posts    = data.nodes.posts    || [];
	var counts = {
		all: products.length + posts.length,
		needs_seo: products.filter(function(p){ return !p.seo || !p.seo.has_title || !p.seo.has_description; }).length
			+ posts.filter(function(p){ return !p.seo || !p.seo.has_title || !p.seo.has_description; }).length,
		not_enriched: products.filter(function(p){ return !p.enrichment || p.enrichment.status !== 'completed'; }).length,
		thin_content: products.filter(function(p){ return (p.content_length || 0) < 500; }).length
			+ posts.filter(function(p){ return (p.word_count || 0) < 300; }).length,
		translation_backlog: (function(){
			var hasMissing = function(t){ if (!t) return false; var k = Object.keys(t); for (var i=0;i<k.length;i++) if (t[k[i]] !== 'completed') return true; return false; };
			return products.filter(function(p){ return hasMissing(p.translation); }).length
				+ posts.filter(function(p){ return hasMissing(p.translation); }).length;
		})(),
		high_opportunity: products.filter(function(p){ return (p.opportunity_score || 0) > 30; }).length
	};
	document.querySelectorAll('#kg-preset-menu .kg-dropdown-item').forEach(function(btn) {
		var key = btn.getAttribute('data-preset');
		if (!(key in counts)) return;
		// Strip any existing trailing (N) first so repeated refreshes don't stack parens.
		var base = btn.textContent.replace(/\s*\(\d+\)\s*$/, '');
		btn.textContent = base + ' (' + counts[key] + ')';
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
			showShortcutsHelp();
		}
	});
}

function showShortcutsHelp() {
	var panel = document.getElementById('kg-detail-panel');
	var content = document.getElementById('kg-detail-content');
	if (!panel || !content) return;

	var shortcuts = [
		{ key: '/',   label: 'Search across products, posts, categories, customers' },
		{ key: 'r',   label: 'Refresh graph data (bypass cache)' },
		{ key: '1',   label: 'Products view' },
		{ key: '2',   label: 'Posts view' },
		{ key: '3',   label: 'Pages view' },
		{ key: '4',   label: 'Customers view' },
		{ key: 'Esc', label: 'Close this panel' },
		{ key: '?',   label: 'Show this help' }
	];

	var h = '';
	h += '<div class="kg-p-type-badge" style="background:#6366f1;color:#fff">Help</div>';
	h += '<h3 class="kg-p-name">Keyboard shortcuts</h3>';
	h += '<div class="kg-p-meta">Faster navigation when your hands are on the keyboard</div>';

	h += '<div class="kg-p-section"><div class="kg-p-section-title">Shortcuts</div>';
	shortcuts.forEach(function(s) {
		h += '<div class="kg-p-stat-row" style="align-items:center;">';
		h += '<strong><kbd style="background:var(--lp-bg-hover);padding:2px 8px;border-radius:4px;font-family:ui-monospace,Menlo,monospace;font-size:12px;border:1px solid var(--lp-border);">' + escHtml(s.key) + '</kbd></strong>';
		h += '<span style="color:var(--lp-text);flex:1;margin-left:12px;text-align:left;">' + escHtml(s.label) + '</span>';
		h += '</div>';
	});
	h += '</div>';

	h += '<div class="kg-p-section"><div class="kg-p-section-title">Tips</div>';
	h += '<p style="margin:8px 0;color:var(--lp-text-muted);font-size:12px;line-height:1.5;">Click any stat card in the top bar (Design Health, Plugin Health, Revenue, Taxonomy, Media) to open its detail report. Click a category or language node to see coverage breakdowns with one-click batch actions.</p>';
	h += '</div>';

	content.innerHTML = h;
	panel.classList.add('open');
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
			applyViewAndPreset(btn.dataset.view, null);
		});
	});
}

// Centralised view/preset switcher. Used by:
// - .kg-view-btn clicks (view switch only)
// - .kg-stat-clickable data-view/data-preset cards (both)
// - keyboard shortcuts 1/2/3/4 (via view button clicks)
// Keeps all UI bits in sync: view-btn active class, preset-menu label,
// preset-menu active item, and the graph itself.
function applyViewAndPreset(view, preset) {
	// View switch
	if (view) {
		document.querySelectorAll('.kg-view-btn').forEach(function(b) {
			b.classList.toggle('active', b.dataset.view === view);
		});
		_kgCurrentView = view;
	}
	// Preset switch
	if (preset !== null && preset !== undefined) {
		_kgCurrentPreset = preset || 'all';
		// Update preset dropdown label + active item to match
		var labelEl = document.getElementById('kg-preset-label');
		var menuItems = document.querySelectorAll('#kg-preset-menu .kg-dropdown-item');
		menuItems.forEach(function(item) {
			var match = item.getAttribute('data-preset') === _kgCurrentPreset;
			item.classList.toggle('active', match);
			if (match && labelEl) {
				labelEl.textContent = item.textContent.replace(/\s*\(.+\)\s*$/, '').trim();
			}
		});
	}
	if (_kgData) {
		buildGraph(_kgData, _kgCurrentView);
		flashFilterFeedback(view, preset);
	}
}

// Brief visual confirmation when a filter/view is applied. The graph redraw
// is fast enough that users don't notice it happened — a pulse border on the
// canvas + a transient "N shown" chip makes the state change unmistakable.
function flashFilterFeedback(view, preset) {
	var container = document.getElementById('kg-graph-container');
	if (!container) return;
	// Canvas pulse ring
	container.classList.add('kg-graph-filter-flash');
	setTimeout(function(){ container.classList.remove('kg-graph-filter-flash'); }, 650);

	// Transient "N shown" chip in the top-left corner of the graph
	if (!_kgData || !_kgData.nodes) return;
	var shown = 0;
	if (_kgCurrentView === 'product') {
		shown = _kgData.nodes.products ? _kgData.nodes.products.length : 0;
	} else if (_kgCurrentView === 'post') {
		shown = _kgData.nodes.posts ? _kgData.nodes.posts.length : 0;
	} else if (_kgCurrentView === 'page') {
		shown = _kgData.nodes.pages ? _kgData.nodes.pages.length : 0;
	} else if (_kgCurrentView === 'customer') {
		shown = _kgData.nodes.customer_segments ? _kgData.nodes.customer_segments.length : 0;
	}
	// Apply the preset-filter count if applicable
	if (_kgCurrentPreset && _kgCurrentPreset !== 'all') {
		var filtered = applyPreset(_kgData.nodes.products || [], _kgData.nodes.posts || [], _kgCurrentPreset);
		if (_kgCurrentView === 'product') shown = filtered.products.length;
		if (_kgCurrentView === 'post')    shown = filtered.posts.length;
	}

	var chip = document.getElementById('kg-filter-chip');
	if (!chip) {
		chip = document.createElement('div');
		chip.id = 'kg-filter-chip';
		chip.className = 'kg-filter-chip';
		container.appendChild(chip);
	}
	var viewLabel = { product: 'products', post: 'posts', page: 'pages', customer: 'segments' }[view || _kgCurrentView] || 'nodes';
	var presetLabel = (preset && preset !== 'all') ? ' · ' + preset.replace(/_/g, ' ') : '';
	chip.textContent = shown + ' ' + viewLabel + presetLabel;
	chip.classList.add('kg-filter-chip--visible');
	clearTimeout(chip._fadeTimer);
	chip._fadeTimer = setTimeout(function(){ chip.classList.remove('kg-filter-chip--visible'); }, 2400);
}

function initStatCardClicks() {
	// Top-row stat cards (Products/Posts/SEO/Enriched/Opportunities) drill
	// into the graph by switching view + preset. Fires in addition to
	// any existing onclick handler (Design/Plugin/Revenue/Taxonomy/Media
	// bind their own via bindXClick — those open detail panels instead).
	document.querySelectorAll('.kg-stat-clickable[data-view]').forEach(function(card) {
		card.addEventListener('click', function() {
			var view   = card.getAttribute('data-view');
			var preset = card.getAttribute('data-preset');
			applyViewAndPreset(view, preset);
			// Brief visual feedback that the filter applied
			card.classList.add('kg-stat-pulse');
			setTimeout(function(){ card.classList.remove('kg-stat-pulse'); }, 600);
		});
	});
}

initViewSwitch();
initStatCardClicks();
initSearch();
initPresets();
initExport();
initKeyboardShortcuts();
initActivityFeed();
initHeroToggle();

// Store Health is a header-embedded pill (score + mini bar). Clicking it
// reveals the detail panel (subtitle + per-dimension chips + achievements)
// below the header. Hidden at rest so the graph is immediately visible.
function initHeroToggle() {
	var btn    = document.getElementById('kg-hero-toggle');
	var detail = document.getElementById('kg-hero-detail');
	if (!btn || !detail) return;
	btn.addEventListener('click', function() {
		var expanded = btn.getAttribute('aria-expanded') === 'true';
		var next = !expanded;
		btn.setAttribute('aria-expanded', next ? 'true' : 'false');
		btn.title = next ? 'Hide Store Health details' : 'Store Health — click for breakdown';
		detail.hidden = !next;
	});
}
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
	bindMediaClick: bindMediaClick,
	showMediaInventoryPanel: showMediaInventoryPanel,
	showTaxonomyHeatmap: showTaxonomyHeatmap,
	showElementorAuditDrilldown: showElementorAuditDrilldown,
	showDesignAuditPanel: showDesignAuditPanel,
	updateCacheBadge: updateCacheBadge,
	updatePresetCounts: updatePresetCounts,
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
var _kgBatchLastNodeId = null; // remembers which detail panel (if any) to reopen when batch finishes

function kgStartBatchMonitor(batchId, queuedCount, nodeId) {
	_kgBatchLastNodeId = nodeId || null;
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

			// Fallback: batch enrichment can complete synchronously on small batches
			// (1-2 products), which clears the tracking meta before the first poll
			// lands. Result: total=0 even though the job ran. After 12s of 0-total
			// polls, we assume the work is done and refresh anyway so the operator
			// isn't staring at a stuck progress bar.
			if (total === 0 && elapsed >= 12) {
				stats.textContent = 'Batch complete — refreshing store state…';
				var syncNodeId = _kgBatchLastNodeId || null;
				if (typeof kgRefreshAndReopen === 'function') {
					kgRefreshAndReopen(syncNodeId, null, null);
				}
				kgStopBatchMonitor(false);
				if (typeof fetchActivity === 'function') fetchActivity();
				return;
			}

			// Finished?
			if (total > 0 && completed >= total) {
				var failMsg = statuses.failed ? ' (' + statuses.failed + ' failed)' : '';
				stats.textContent = 'Batch complete — ' + completed + ' processed' + failMsg + ' in ' + elapsed + 's';
				// Refresh everything (graph + stats + panel + card bindings) so
				// the operator sees their work reflected immediately: chips flip
				// red→green, health bar jumps, opportunity counter animates down.
				// Re-opens the same detail panel node if one was active.
				var lastNodeId = _kgBatchLastNodeId || null;
				if (typeof kgRefreshAndReopen === 'function') {
					kgRefreshAndReopen(lastNodeId, null, null);
				} else if (window.lpKg && window.lpKg.fetchGraph) {
					window.lpKg.fetchGraph(function(err, freshData) {
						if (!err && freshData) {
							window.lpKg.setData(freshData);
							window.lpKg.updateStats(freshData);
							window.lpKg.buildGraph(freshData);
						}
					}, true);
				}
				kgStopBatchMonitor(false);
				// Surface the freshly-completed batch in the activity feed right away.
				if (typeof fetchActivity === 'function') fetchActivity();
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
		// Route single-product enrichment through the batch endpoint so the
		// operator gets the same live progress monitor as a category batch.
		// Without this, AI takes 30-90s to complete but the panel says "Queued"
		// then refreshes after 8s showing no change — breaks the gamification loop.
		endpoint = 'product/enrich-batch';
		body = { product_ids: [productId] };
	} else if (action === 'faq') {
		endpoint = 'aeo/generate-faq';
		body = { product_id: productId };
	} else if (action === 'howto') {
		endpoint = 'aeo/generate-howto';
		body = { product_id: productId };
	} else if (action === 'translate') {
		// Route single-product translation through the batch endpoint so the
		// post_ids whitelist scopes it to just this product. Matches the
		// category/global translate flow for consistent progress feedback.
		endpoint = 'translation/batch';
		body = { post_type: 'product', post_ids: [productId], languages: langs ? langs.split(',') : [] };
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
			var successful = results.filter(function(r) { return r.ok; });
			var totalTerms = successful.reduce(function(sum, r) {
				return sum + ((r.data && r.data.terms_sent) || 0);
			}, 0);
			// Real success = backend persisted to WPML/Polylang. terms_sent is "AI calls fired",
			// saved is "rows inserted". When AI returns malformed JSON or save errors silently,
			// terms_sent > 0 but saved = 0 — that's the failure mode users see as "Queued forever".
			var totalSaved = successful.reduce(function(sum, r) {
				return sum + ((r.data && r.data.saved) || 0);
			}, 0);

			if (totalTerms > 0 && totalSaved === 0) {
				btn.innerHTML = '<span style="color:var(--lp-error);">AI ran but 0 saved — retry?</span>';
				setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; btn.classList.remove('kg-action-done'); }, 5000);
				if (typeof fetchActivity === 'function') setTimeout(fetchActivity, 1500);
				return;
			}

			btn.innerHTML = '<span class="kg-action-icon" style="color:var(--lp-success);">&#10003;</span> Saved ' + totalSaved + '/' + totalTerms;
			btn.classList.add('kg-action-done');
			btn.disabled = true;
			if (typeof fetchActivity === 'function') setTimeout(fetchActivity, 1500);
			// Refresh delay scaled to AI volume: ~3s per term + 8s safety, capped at 60s.
			var refreshMs = Math.min(8000 + totalTerms * 3000, 60000);
			setTimeout(function() {
				kgRefreshAndReopen(null, 'taxonomy', targetLang);
			}, refreshMs);
		}).catch(function(err) {
			btn.innerHTML = '<span style="color:var(--lp-error);">Failed — retry?</span>';
			setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; btn.classList.remove('kg-action-done'); }, 3000);
		});
		return;
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
			btn.innerHTML = '<span style="color:var(--lp-error);">' + msg + ' — retry?</span>';
			// Error = retry-safe reset. Success = disabled until panel refresh.
			setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; btn.classList.remove('kg-action-done'); }, 4000);
			if (window.console) console.warn('[lpKg] action failed:', action, res);
			return;
		}
		var data = res.data || {};
		var isQueued = data.status === 'processing' || data.status === 'queued' || data.job_id || data.batch_id;
		btn.innerHTML = '<span class="kg-action-icon" style="color:var(--lp-success);">&#10003;</span> ' + (isQueued ? 'Queued' : 'Done');
		btn.classList.add('kg-action-done');
		// Keep the button locked in its Queued/Done state until the panel
		// refreshes. The refresh re-renders the whole panel from fresh data,
		// which produces a button that reflects the new state (usually the
		// action isn't needed anymore, or is needed for fewer items). Users
		// stop mashing the same button while the backend is still churning.
		btn.disabled = true;

		// If the backend returned a batch_id (enrich-batch), spin up the batch monitor.
		// Category batches (no single productId) just refresh the graph; single-product
		// batches reopen the same detail panel to show the updated state.
		if (data.batch_id && typeof kgStartBatchMonitor === 'function') {
			var reopenId = (action === 'enrich' || action === 'translate') ? productId : null;
			kgStartBatchMonitor(data.batch_id, data.queued || 0, reopenId);
		}

		// Ping activity feed so the "queued" event lands quickly, not on the next poll.
		if (typeof fetchActivity === 'function') {
			setTimeout(fetchActivity, 1500);
		}

		// Batch monitor owns the refresh when batch_id is set.
		if (data.batch_id) return;

			// LuwiPress_Job_Queue path -- when backend returns a job_id, poll real progress.
			if (data.job_id && typeof kgPollJobStatus === 'function') {
				kgPollJobStatus(data.job_id, btn, function() {
					kgRefreshAndReopen(productId, action === 'translate_lang' ? 'language' : null, langs);
				});
				return;
			}

		// Otherwise schedule our own refresh after the queued job is likely done.
		// Translation: 20-40s. AEO single / non-queued: 2s is plenty.
		var refreshDelay = isQueued ? 25000 : 2000;
		setTimeout(function() {
			kgRefreshAndReopen(productId, action === 'translate_lang' ? 'language' : null, langs);
		}, refreshDelay);
	})
	.catch(function(err) {
		// Error path — reset so the user can retry.
		btn.innerHTML = '<span style="color:var(--lp-error);">Network error — retry?</span>';
		setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; btn.classList.remove('kg-action-done'); }, 3000);
		if (window.console) console.error('[lpKg] action error:', action, err);
	});
}

// Polls the generic LuwiPress_Job_Queue REST endpoint until status === 'done' or
// an error stops it. Updates btn.innerHTML with live progress (saved/total) and
// calls onDone() when the job finishes (typically a graph refresh).
function kgPollJobStatus(jobId, btn, onDone) {
	var attempts = 0;
	var originalText = btn ? btn.innerHTML : '';
	var poll = function() {
		attempts++;
		fetch(lpKgRestUrl + 'job/status?job_id=' + encodeURIComponent(jobId), {
			headers: { 'X-WP-Nonce': lpKgNonce },
			credentials: 'same-origin'
		}).then(function(r) {
			return r.json().then(function(data) { return { ok: r.ok, data: data }; });
		}).then(function(res) {
			if (!res.ok) {
				if (attempts > 5) {
					if (btn) {
						btn.innerHTML = '<span style="color:var(--lp-error);">Job lookup failed -- retry?</span>';
						setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; btn.classList.remove('kg-action-done'); }, 4000);
					}
					return;
				}
				setTimeout(poll, 5000);
				return;
			}
			var j = res.data || {};
			if (btn) {
				var pct = j.total_units > 0 ? Math.round((j.done_units / j.total_units) * 100) : 0;
				btn.innerHTML = '<span class="kg-action-icon" style="color:var(--lp-success);">&#10003;</span> ' + j.done_units + '/' + j.total_units + ' chunks (' + j.saved + ' saved)';
			}
			if (j.status === 'done' || j.status === 'cancelled') {
				if (btn) {
					if (j.saved === 0 && j.sent > 0) {
						btn.disabled = false;
						btn.innerHTML = '<span style="color:var(--lp-error);">AI ran but 0 saved -- retry?</span>';
						btn.classList.remove('kg-action-done');
					} else {
						btn.innerHTML = '<span class="kg-action-icon" style="color:var(--lp-success);">&#10003;</span> ' + j.saved + '/' + j.sent + ' done';
					}
				}
				if (typeof onDone === 'function') { setTimeout(onDone, 1500); }
				return;
			}
			setTimeout(poll, 3000);
		}).catch(function() {
			if (attempts > 10) { return; }
			setTimeout(poll, 5000);
		});
	};
	setTimeout(poll, 2000);
}

function kgRefreshAndReopen(nodeId, nodeType, langCode) {
	if (!window.lpKg || !window.lpKg.buildGraph) {
		return;
	}
	var headers = { 'X-WP-Nonce': lpKgNonce };
	var apiToken = (window.lpKgConfig && window.lpKgConfig.apiToken) || '';
	if (apiToken) headers['Authorization'] = 'Bearer ' + apiToken;

	fetch(((window.lpKgConfig && window.lpKgConfig.apiUrl) || '') + '?sections=products,categories,translation,store,opportunities,design_audit,posts,pages,plugins,order_analytics,taxonomy,crm,media_inventory&fresh=1', {
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
		if (window.lpKg.bindMediaClick) window.lpKg.bindMediaClick(data);
		if (window.lpKg.updateCacheBadge) window.lpKg.updateCacheBadge(data);
		if (window.lpKg.updatePresetCounts) window.lpKg.updatePresetCounts(data);

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

// Export a customer segment as CSV — pulls from /crm/segment/{segKey} which
// returns the rich customer record (name, email, order_count, total_spent,
// last_order, days_since) up to 100 customers. LuwiPress never pushes to
// external CRM plugins, so this is the operator's handoff point: export,
// import into email tool, send campaign.
function kgExportSegmentCsv(segKey, btn) {
	var originalText = btn.innerHTML;
	btn.disabled = true;
	btn.innerHTML = '<span class="kg-action-spinner"></span> Exporting...';

	var headers = { 'X-WP-Nonce': lpKgNonce };
	var apiToken = (window.lpKgConfig && window.lpKgConfig.apiToken) || '';
	if (apiToken) headers['Authorization'] = 'Bearer ' + apiToken;

	fetch(lpKgRestUrl + 'crm/segment/' + encodeURIComponent(segKey) + '?limit=100', { headers: headers, credentials: 'same-origin' })
		.then(function(r) { return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
		.then(function(res) {
			if (!res.ok) throw new Error('Segment fetch failed');
			var customers = (res.data && res.data.customers) || [];
			var label = (res.data && res.data.label) || segKey;
			if (!customers.length) {
				btn.innerHTML = '<span style="color:var(--lp-warning);">No customers</span>';
				setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; }, 3000);
				return;
			}
			// Build CSV (BOM + CRLF so Excel/Numbers parse it)
			var rows = [['customer_id', 'name', 'email', 'order_count', 'total_spent', 'last_order', 'days_since_last_order', 'segment', 'segment_label']];
			customers.forEach(function(c) {
				rows.push([c.id || '', c.name || '', c.email || '', c.order_count || 0, c.total_spent || 0, c.last_order || '', c.days_since || '', segKey, label]);
			});
			var csv = '﻿' + rows.map(function(row) {
				return row.map(function(cell) {
					var s = String(cell == null ? '' : cell);
					return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
				}).join(',');
			}).join('\r\n');
			var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
			var url = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url;
			a.download = 'luwipress-segment-' + segKey + '-' + new Date().toISOString().slice(0, 10) + '.csv';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			setTimeout(function(){ URL.revokeObjectURL(url); }, 1000);
			btn.innerHTML = '<span class="kg-action-icon" style="color:var(--lp-success);">&#10003;</span> Downloaded (' + customers.length + ')';
			btn.classList.add('kg-action-done');
			setTimeout(function(){
				btn.disabled = false;
				btn.innerHTML = originalText;
				btn.classList.remove('kg-action-done');
			}, 4000);
		})
		.catch(function(err) {
			btn.innerHTML = '<span style="color:var(--lp-error);">Export failed</span>';
			setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; }, 3000);
			if (window.console) console.error('[lpKg] segment export error:', err);
		});
}
