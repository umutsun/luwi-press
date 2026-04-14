<?php
/**
 * LuwiPress Knowledge Graph Visualizer
 *
 * Interactive D3.js force-directed graph showing the complete
 * WordPress/WooCommerce store state: products, categories, languages,
 * SEO coverage, enrichment status, and AI opportunities.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luwipress' ) );
}

$api_token = get_option( 'luwipress_seo_api_token', '' );
$rest_url  = rest_url( 'luwipress/v1/knowledge-graph' );
?>

<div class="wrap luwipress-kg-wrap">
	<!-- Header -->
	<div class="kg-header">
		<div class="kg-header-left">
			<h1 class="kg-title">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--lp-primary)" stroke-width="2"><circle cx="12" cy="12" r="3"/><circle cx="4" cy="8" r="2"/><circle cx="20" cy="8" r="2"/><circle cx="4" cy="16" r="2"/><circle cx="20" cy="16" r="2"/><line x1="6" y1="8" x2="9" y2="10"/><line x1="18" y1="8" x2="15" y2="10"/><line x1="6" y1="16" x2="9" y2="14"/><line x1="18" y1="16" x2="15" y2="14"/></svg>
				<?php esc_html_e( 'Knowledge Graph', 'luwipress' ); ?>
			</h1>
			<span class="kg-subtitle"><?php esc_html_e( 'Interactive store intelligence map', 'luwipress' ); ?></span>
		</div>
		<div class="kg-header-right">
			<div class="kg-controls">
				<button type="button" id="kg-refresh" class="kg-btn kg-btn-primary">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
					<?php esc_html_e( 'Refresh', 'luwipress' ); ?>
				</button>
				<div class="kg-filter-group">
					<button type="button" class="kg-filter active" data-filter="all"><?php esc_html_e( 'All', 'luwipress' ); ?></button>
					<button type="button" class="kg-filter" data-filter="product"><?php esc_html_e( 'Products', 'luwipress' ); ?></button>
					<button type="button" class="kg-filter" data-filter="category"><?php esc_html_e( 'Categories', 'luwipress' ); ?></button>
					<button type="button" class="kg-filter" data-filter="language"><?php esc_html_e( 'Languages', 'luwipress' ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<!-- Stats Bar (animated counters) -->
	<div class="kg-stats" id="kg-stats">
		<div class="kg-stat kg-stat-skeleton">
			<div class="kg-stat-value" data-counter="total_products">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Products', 'luwipress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton">
			<div class="kg-stat-value" data-counter="seo_coverage">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'SEO Coverage', 'luwipress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton">
			<div class="kg-stat-value" data-counter="enrichment_coverage">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Enriched', 'luwipress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton">
			<div class="kg-stat-value" data-counter="opportunity_score_total">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Opportunities', 'luwipress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton">
			<div class="kg-stat-value" data-counter="design_health">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Design Health', 'luwipress' ); ?></div>
		</div>
	</div>

	<!-- Graph Canvas -->
	<div class="kg-graph-container" id="kg-graph-container">
		<div class="kg-loading" id="kg-loading">
			<div class="kg-spinner"></div>
			<p><?php esc_html_e( 'Building knowledge graph...', 'luwipress' ); ?></p>
		</div>
		<svg id="kg-svg"></svg>
		<!-- Tooltip -->
		<div class="kg-tooltip" id="kg-tooltip"></div>
		<!-- Zoom controls -->
		<div class="kg-zoom-controls">
			<button type="button" id="kg-zoom-in" class="kg-zoom-btn" title="Zoom in">+</button>
			<button type="button" id="kg-zoom-out" class="kg-zoom-btn" title="Zoom out">−</button>
			<button type="button" id="kg-zoom-fit" class="kg-zoom-btn" title="Fit to screen">⊡</button>
		</div>
		<!-- Legend -->
		<div class="kg-legend">
			<div class="kg-legend-item"><span class="kg-legend-dot" style="background:var(--lp-primary)"></span> Product</div>
			<div class="kg-legend-item"><span class="kg-legend-dot" style="background:var(--lp-warning)"></span> Category</div>
			<div class="kg-legend-item"><span class="kg-legend-dot" style="background:var(--lp-blue)"></span> Language</div>
			<div class="kg-legend-item"><span class="kg-legend-dot" style="background:var(--lp-success)"></span> SEO Complete</div>
			<div class="kg-legend-item"><span class="kg-legend-dot" style="background:var(--lp-error)"></span> Needs Work</div>
		</div>
	</div>

	<!-- Detail Panel (slides in on node click) -->
	<div class="kg-detail-panel" id="kg-detail-panel">
		<button type="button" class="kg-detail-close" id="kg-detail-close">&times;</button>
		<div id="kg-detail-content"></div>
	</div>
</div>

<script>
(function() {
'use strict';

var CONFIG = {
	apiUrl: <?php echo wp_json_encode( $rest_url ); ?>,
	apiToken: <?php echo wp_json_encode( $api_token ); ?>,
	nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>
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
function fetchGraph(cb) {
	var loading = document.getElementById('kg-loading');
	loading.style.display = 'flex';

	var headers = { 'X-WP-Nonce': CONFIG.nonce };
	if (CONFIG.apiToken) {
		headers['Authorization'] = 'Bearer ' + CONFIG.apiToken;
	}

	fetch(CONFIG.apiUrl + '?sections=products,categories,translation,store,opportunities,design_audit', {
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

// ── Build D3 Graph ──
function buildGraph(data) {
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
	var graphEdges    = data.edges || [];

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
		return '#e5e7eb60';
	}

	// Force simulation
	var simulation = d3.forceSimulation(nodes)
		.force('link', d3.forceLink(edges).id(function(d){ return d.id; }).distance(function(d){
			if (d.type === 'belongs_to') return 60;
			if (d.type === 'translated_to' || d.type === 'missing_translation') return 120;
			return 80;
		}))
		.force('charge', d3.forceManyBody().strength(function(d){
			if (d.type === 'category') return -300;
			if (d.type === 'language') return -500;
			return -80;
		}))
		.force('center', d3.forceCenter(width / 2, height / 2))
		.force('collision', d3.forceCollide().radius(function(d){ return d.radius + 4; }))
		.alphaDecay(0.02);

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
				d.fx = null; d.fy = null;
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

	// Filter buttons
	document.querySelectorAll('.kg-filter').forEach(function(btn) {
		btn.addEventListener('click', function() {
			document.querySelectorAll('.kg-filter').forEach(function(b){ b.classList.remove('active'); });
			btn.classList.add('active');
			var filterType = btn.dataset.filter;

			node.transition().duration(300)
				.style('opacity', function(d) {
					return (filterType === 'all' || d.type === filterType) ? 1 : 0.08;
				});
			link.transition().duration(300)
				.style('opacity', function(d) {
					if (filterType === 'all') return 1;
					return (d.source.type === filterType || d.target.type === filterType) ? 0.6 : 0.05;
				});
		});
	});

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
	}

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

	// Per-page results
	if (pages.length > 0) {
		h += '<div class="kg-p-section"><div class="kg-p-section-title">Page Results</div>';
		pages.forEach(function(p) {
			var pCls = p.health_score >= 80 ? 'var(--lp-success)' : p.health_score >= 50 ? 'var(--lp-warning)' : 'var(--lp-error)';
			h += '<div style="margin-bottom:12px;padding:10px;background:var(--lp-bg-hover);border-radius:8px;">';
			h += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">';
			h += '<strong>' + escHtml(p.title || p.page_type) + '</strong>';
			h += '<span style="color:' + pCls + ';font-weight:600;">' + p.health_score + '%</span></div>';
			h += '<div style="font-size:11px;color:var(--lp-text-muted);">' + p.page_type + '</div>';
			if (p.issues && p.issues.length > 0) {
				h += '<div style="margin-top:6px;">';
				p.issues.forEach(function(issue) {
					var iColor = issue.severity === 'critical' ? 'var(--lp-error)' : issue.severity === 'warning' ? 'var(--lp-warning)' : 'var(--lp-text-muted)';
					h += '<div style="font-size:11px;padding:3px 0;border-bottom:1px solid var(--lp-border);">';
					h += '<span style="color:' + iColor + ';font-weight:600;">' + issue.severity.toUpperCase() + '</span> ';
					h += issue.message;
					if (issue.fix) h += '<div style="color:var(--lp-text-muted);font-size:10px;">Fix: ' + issue.fix + '</div>';
					h += '</div>';
				});
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
	var stats = {
		total_products: summary.total_products || 0,
		seo_coverage: summary.seo_coverage || 0,
		enrichment_coverage: summary.enrichment_coverage || 0,
		opportunity_score_total: summary.opportunity_score_total || 0,
		design_health: designSummary.overall_health || 0
	};

	document.querySelectorAll('.kg-stat').forEach(function(el) {
		el.classList.remove('kg-stat-skeleton');
		el.classList.add('kg-stat-loaded');
	});

	Object.keys(stats).forEach(function(key) {
		var el = document.querySelector('[data-counter="' + key + '"]');
		if (el) {
			var suffix = (key.indexOf('coverage') !== -1 || key === 'design_health') ? '%' : '';
			animateCounter(el, Math.round(stats[key]), suffix);
		}
	});
}

// ── Detail Panel Close ──
document.getElementById('kg-detail-close').onclick = function() {
	document.getElementById('kg-detail-panel').classList.remove('open');
};

// ── Refresh ──
document.getElementById('kg-refresh').onclick = function() {
	init();
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

function init() {
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
		});
	});
}

function bindDesignHealthClick(data) {
	var el = document.querySelector('[data-counter="design_health"]');
	if (!el) return;
	var card = el.closest('.kg-stat');
	if (!card) return;
	card.style.cursor = 'pointer';
	card.title = 'Click to view Design Audit details';
	card.addEventListener('click', function() {
		var audit = (data.nodes && data.nodes.design_audit) ? data.nodes.design_audit : null;
		if (audit) showDesignAuditPanel(audit);
	});
}

init();

})();

// Quick Action handler (outside IIFE so onclick attributes can reach it)
var lpKgRestUrl = <?php echo wp_json_encode( rest_url( 'luwipress/v1/' ) ); ?>;
var lpKgNonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

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
		body = { product_id: productId, target_languages: langs ? langs.split(',') : [] };
	}

	fetch(lpKgRestUrl + endpoint, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': lpKgNonce },
		body: JSON.stringify(body),
		credentials: 'same-origin'
	})
	.then(function(r) { return r.json(); })
	.then(function(data) {
		btn.innerHTML = '<span class="kg-action-icon" style="color:var(--lp-success);">&#10003;</span> Done';
		btn.classList.add('kg-action-done');
		setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; btn.classList.remove('kg-action-done'); }, 3000);
	})
	.catch(function(err) {
		btn.innerHTML = '<span style="color:var(--lp-error);">Failed</span>';
		setTimeout(function(){ btn.disabled = false; btn.innerHTML = originalText; }, 3000);
	});
}
</script>
</content>
</invoke>