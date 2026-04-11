<?php
/**
 * n8nPress Knowledge Graph Visualizer
 *
 * Interactive D3.js force-directed graph showing the complete
 * WordPress/WooCommerce store state: products, categories, languages,
 * SEO coverage, enrichment status, and AI opportunities.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'n8npress' ) );
}

$api_token = get_option( 'n8npress_seo_api_token', '' );
$rest_url  = rest_url( 'n8npress/v1/knowledge-graph' );
?>

<div class="wrap n8npress-kg-wrap">
	<!-- Header -->
	<div class="kg-header">
		<div class="kg-header-left">
			<h1 class="kg-title">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--n8n-primary)" stroke-width="2"><circle cx="12" cy="12" r="3"/><circle cx="4" cy="8" r="2"/><circle cx="20" cy="8" r="2"/><circle cx="4" cy="16" r="2"/><circle cx="20" cy="16" r="2"/><line x1="6" y1="8" x2="9" y2="10"/><line x1="18" y1="8" x2="15" y2="10"/><line x1="6" y1="16" x2="9" y2="14"/><line x1="18" y1="16" x2="15" y2="14"/></svg>
				<?php esc_html_e( 'Knowledge Graph', 'n8npress' ); ?>
			</h1>
			<span class="kg-subtitle"><?php esc_html_e( 'Interactive store intelligence map', 'n8npress' ); ?></span>
		</div>
		<div class="kg-header-right">
			<div class="kg-controls">
				<button type="button" id="kg-refresh" class="kg-btn kg-btn-primary">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
					<?php esc_html_e( 'Refresh', 'n8npress' ); ?>
				</button>
				<div class="kg-filter-group">
					<button type="button" class="kg-filter active" data-filter="all"><?php esc_html_e( 'All', 'n8npress' ); ?></button>
					<button type="button" class="kg-filter" data-filter="product"><?php esc_html_e( 'Products', 'n8npress' ); ?></button>
					<button type="button" class="kg-filter" data-filter="category"><?php esc_html_e( 'Categories', 'n8npress' ); ?></button>
					<button type="button" class="kg-filter" data-filter="language"><?php esc_html_e( 'Languages', 'n8npress' ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<!-- Stats Bar (animated counters) -->
	<div class="kg-stats" id="kg-stats">
		<div class="kg-stat kg-stat-skeleton">
			<div class="kg-stat-value" data-counter="total_products">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Products', 'n8npress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton">
			<div class="kg-stat-value" data-counter="seo_coverage">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'SEO Coverage', 'n8npress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton">
			<div class="kg-stat-value" data-counter="enrichment_coverage">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Enriched', 'n8npress' ); ?></div>
		</div>
		<div class="kg-stat kg-stat-skeleton">
			<div class="kg-stat-value" data-counter="opportunity_score_total">—</div>
			<div class="kg-stat-label"><?php esc_html_e( 'Opportunities', 'n8npress' ); ?></div>
		</div>
	</div>

	<!-- Graph Canvas -->
	<div class="kg-graph-container" id="kg-graph-container">
		<div class="kg-loading" id="kg-loading">
			<div class="kg-spinner"></div>
			<p><?php esc_html_e( 'Building knowledge graph...', 'n8npress' ); ?></p>
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
			<div class="kg-legend-item"><span class="kg-legend-dot" style="background:var(--n8n-primary)"></span> Product</div>
			<div class="kg-legend-item"><span class="kg-legend-dot" style="background:var(--n8n-warning)"></span> Category</div>
			<div class="kg-legend-item"><span class="kg-legend-dot" style="background:var(--n8n-blue)"></span> Language</div>
			<div class="kg-legend-item"><span class="kg-legend-dot" style="background:var(--n8n-success)"></span> SEO Complete</div>
			<div class="kg-legend-item"><span class="kg-legend-dot" style="background:var(--n8n-error)"></span> Needs Work</div>
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

	fetch(CONFIG.apiUrl + '?sections=products,categories,translation,store,opportunities', {
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

	// Products
	(data.product_nodes || []).forEach(function(p) {
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
	(data.category_nodes || []).forEach(function(c) {
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
	(data.language_nodes || []).forEach(function(l) {
		var node = {
			id: 'language:' + l.code,
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
	(data.edges || []).forEach(function(e) {
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
		.on('zoom', function(event) {
			g.attr('transform', event.transform);
		});
	svg.call(zoom);

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

	node.on('mouseover', function(event, d) {
		tooltip.innerHTML = buildTooltip(d);
		tooltip.style.display = 'block';
		tooltip.style.opacity = '1';

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
	.on('mousemove', function(event) {
		tooltip.style.left = (event.pageX + 14) + 'px';
		tooltip.style.top = (event.pageY - 14) + 'px';
	})
	.on('mouseout', function() {
		tooltip.style.opacity = '0';
		setTimeout(function(){ tooltip.style.display = 'none'; }, 200);

		node.transition().duration(300).style('opacity', 1);
		d3.select(this).select('circle')
			.transition().duration(200)
			.attr('r', function(d){ return d.radius; })
			.attr('stroke-width', 2);
		link.transition().duration(300)
			.style('opacity', 1)
			.attr('stroke-width', function(d){ return d.type === 'belongs_to' ? 1.5 : 1; });
	})
	.on('click', function(event, d) {
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
		h += '<h3>' + escHtml(p.name) + '</h3>';
		h += '<div class="kg-detail-badge">#' + p.id + ' · ' + (p.sku || 'No SKU') + '</div>';
		h += '<div class="kg-detail-section"><h4>SEO Status</h4>';
		h += statusBadge(p.seo.has_title, 'Meta Title');
		h += statusBadge(p.seo.has_description, 'Meta Description');
		h += statusBadge(p.seo.has_focus_kw, 'Focus Keyword');
		h += '</div>';
		h += '<div class="kg-detail-section"><h4>AEO Status</h4>';
		h += statusBadge(p.aeo.has_faq, 'FAQ Schema');
		h += statusBadge(p.aeo.has_howto, 'HowTo Schema');
		h += statusBadge(p.aeo.has_schema, 'Product Schema');
		h += '</div>';
		h += '<div class="kg-detail-section"><h4>Enrichment</h4>';
		h += '<div class="kg-detail-enrichment-status kg-es-' + p.enrichment.status + '">' + p.enrichment.status + '</div>';
		h += '</div>';
		h += '<div class="kg-detail-section"><h4>Translations</h4>';
		Object.keys(p.translation || {}).forEach(function(lang) {
			var s = p.translation[lang];
			h += '<div class="kg-detail-lang"><span class="kg-detail-lang-code">' + lang.toUpperCase() + '</span>';
			h += '<span class="kg-detail-lang-status kg-ls-' + s + '">' + s + '</span></div>';
		});
		h += '</div>';
		h += '<div class="kg-detail-section"><h4>Opportunity Score</h4>';
		h += '<div class="kg-detail-score">' + p.opportunity_score + '</div>';
		h += '<ul class="kg-detail-opps">';
		(p.opportunities || []).forEach(function(o) {
			h += '<li>' + o.replace(/_/g, ' ') + '</li>';
		});
		h += '</ul></div>';
		h += '<a href="' + (p.permalink || '/wp-admin/post.php?post=' + p.id + '&action=edit') + '" target="_blank" class="kg-btn kg-btn-primary" style="margin-top:12px;display:inline-block;">Edit Product →</a>';
	} else if (d.type === 'category') {
		var c = d.data;
		h += '<h3>' + escHtml(c.name) + '</h3>';
		h += '<div class="kg-detail-badge">Category · ' + c.product_count + ' products</div>';
		h += '<div class="kg-detail-section"><h4>Coverage</h4>';
		h += progressBar('SEO', c.seo_coverage_pct || 0);
		h += progressBar('Enrichment', c.enrichment_pct || 0);
		Object.keys(c.translation_pct || {}).forEach(function(l) {
			h += progressBar(l.toUpperCase() + ' Translation', c.translation_pct[l]);
		});
		h += '</div>';
	} else if (d.type === 'language') {
		var l = d.data;
		h += '<h3>' + l.code.toUpperCase() + '</h3>';
		h += '<div class="kg-detail-badge">Language</div>';
		h += '<div class="kg-detail-section">';
		h += progressBar('Coverage', l.coverage_pct || 0);
		h += '<div class="kg-detail-row">Translated: <b>' + l.products_translated + '</b></div>';
		h += '<div class="kg-detail-row">Missing: <b>' + l.products_missing + '</b></div>';
		h += '</div>';
	}

	content.innerHTML = h;
	panel.classList.add('open');
}

function statusBadge(ok, label) {
	return '<div class="kg-detail-status ' + (ok ? 'ok' : 'missing') + '">' +
		'<span class="kg-detail-status-icon">' + (ok ? '✓' : '✗') + '</span> ' + label + '</div>';
}

function progressBar(label, pct) {
	pct = Math.min(100, Math.max(0, pct));
	var color = pct >= 80 ? 'var(--n8n-success)' : (pct >= 50 ? 'var(--n8n-warning)' : 'var(--n8n-error)');
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
	var stats = {
		total_products: summary.total_products || 0,
		seo_coverage: summary.seo_coverage || 0,
		enrichment_coverage: summary.enrichment_coverage || 0,
		opportunity_score_total: summary.opportunity_score_total || 0
	};

	document.querySelectorAll('.kg-stat').forEach(function(el) {
		el.classList.remove('kg-stat-skeleton');
		el.classList.add('kg-stat-loaded');
	});

	Object.keys(stats).forEach(function(key) {
		var el = document.querySelector('[data-counter="' + key + '"]');
		if (el) {
			var suffix = (key.indexOf('coverage') !== -1) ? '%' : '';
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
function init() {
	loadD3(function() {
		fetchGraph(function(err, data) {
			if (err || !data) {
				document.getElementById('kg-loading').innerHTML = '<p style="color:var(--n8n-error);">Failed to load knowledge graph. Check connection settings.</p>';
				return;
			}
			updateStats(data);
			buildGraph(data);
		});
	});
}

init();

})();
</script>
</content>
</invoke>