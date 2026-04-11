/**
 * n8nPress Admin Scripts
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // ========================================
        // Dashboard v2 — AJAX loader
        // ========================================
        if ($('#n8np-hero').length) {
            n8npLoadDashboard();
            // Auto-refresh activity every 30s
            setInterval(function() { n8npRefreshActivity(); }, 30000);
        }

        function n8npLoadDashboard() {
            $.post(n8npress.ajax_url, {
                action: 'n8npress_dashboard_data',
                nonce: n8npress.nonce
            }, function(res) {
                if (!res.success) return;
                var d = res.data;

                // Hero counters (animated)
                n8npAnimateNum('[data-key="products"] .n8np-hero-num', d.products);
                n8npAnimateNum('[data-key="revenue"] .n8np-hero-num', d.currency + n8npFormatNum(d.revenue));
                n8npAnimateNum('[data-key="ai_calls"] .n8np-hero-num', d.ai_calls);
                n8npAnimateNum('[data-key="budget"] .n8np-hero-num', d.budget_pct + '%');

                // Budget bar
                var $bf = $('[data-key="budget_pct"]');
                $bf.css('width', Math.min(100, d.budget_pct) + '%');
                if (d.budget_pct >= 90) $bf.addClass('budget-crit');
                else if (d.budget_pct >= 70) $bf.addClass('budget-warn');

                // 7-day chart
                n8npRenderChart(d.daily_costs);

                // Content health ring
                n8npRenderHealthRing(d.health_pct, d.health_thin, d.health_seo, d.products);

                // Opportunities
                if (d.opportunities) {
                    $.each(d.opportunities, function(key, val) {
                        var $row = $('[data-opp="' + key + '"]');
                        $row.find('.n8np-opp-count').removeClass('n8np-skeleton').text(val);
                    });
                }

                // Activity
                n8npRenderActivity(d.logs);

                // Translation coverage
                if (d.trans_coverage) {
                    $.each(d.trans_coverage, function(lang, pct) {
                        var $row = $('[data-lang="' + lang + '"]');
                        $row.find('.n8np-trans-fill').removeClass('n8np-skeleton').css('width', pct + '%');
                        if (pct >= 80) $row.find('.n8np-trans-fill').css('background', 'var(--n8n-success)');
                        else if (pct >= 40) $row.find('.n8np-trans-fill').css('background', 'var(--n8n-warning)');
                        else $row.find('.n8np-trans-fill').css('background', 'var(--n8n-error)');
                        $row.find('.n8np-trans-pct').removeClass('n8np-skeleton').text(pct + '%');
                    });
                }
            });
        }

        function n8npAnimateNum(sel, target) {
            var $el = $(sel);
            $el.removeClass('n8np-skeleton').css('animation', 'n8np-count-in 0.4s ease');
            if (typeof target === 'string') {
                $el.text(target);
                return;
            }
            var start = 0, end = parseInt(target, 10) || 0, dur = 800;
            if (end === 0) { $el.text('0'); return; }
            var startTime = null;
            function step(ts) {
                if (!startTime) startTime = ts;
                var p = Math.min((ts - startTime) / dur, 1);
                p = 1 - Math.pow(1 - p, 3); // ease-out cubic
                $el.text(Math.floor(p * end).toLocaleString());
                if (p < 1) requestAnimationFrame(step);
            }
            requestAnimationFrame(step);
        }

        function n8npFormatNum(n) {
            if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
            if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
            return n.toFixed(2);
        }

        function n8npRenderChart(days) {
            if (!days || !days.length) return;
            var $chart = $('#n8np-cost-chart');
            var maxCost = Math.max.apply(null, days.map(function(d) { return d.cost; })) || 0.01;
            var total = 0;
            var html = '';
            days.forEach(function(d) {
                total += d.cost;
                var h = Math.max(4, (d.cost / maxCost) * 100);
                html += '<div class="n8np-chart-bar" style="height:' + h + '%">' +
                    '<span class="bar-tooltip">$' + d.cost.toFixed(4) + '</span></div>';
            });
            $chart.html(html);

            // Labels
            var labels = '<div class="n8np-chart-labels">';
            days.forEach(function(d) { labels += '<span>' + d.day + '</span>'; });
            labels += '</div>';
            $chart.after(labels);

            // Footer
            $('#n8np-cost-footer .n8np-chart-total').removeClass('n8np-skeleton').text('$' + total.toFixed(4));
        }

        function n8npRenderHealthRing(pct, thin, seo, total) {
            var $wrap = $('#n8np-health');
            var thinPct = total > 0 ? Math.round((thin / total) * 100) : 0;
            var seoPct = total > 0 ? Math.round((seo / total) * 100) : 0;
            var goodPct = Math.max(0, 100 - thinPct - seoPct);

            var gradient = 'conic-gradient(' +
                'var(--n8n-success) 0% ' + goodPct + '%, ' +
                '#ea580c ' + goodPct + '% ' + (goodPct + thinPct) + '%, ' +
                'var(--n8n-error) ' + (goodPct + thinPct) + '% 100%)';

            $wrap.html(
                '<div class="n8np-health-ring" style="background:' + gradient + '">' +
                    '<div class="n8np-health-ring-inner">' +
                        '<span class="n8np-health-pct">' + pct + '%</span>' +
                        '<span class="n8np-health-sub">Optimized</span>' +
                    '</div>' +
                '</div>'
            );

            $('#n8np-health-legend').html(
                '<span class="n8np-health-legend-item"><span class="n8np-health-legend-dot" style="background:var(--n8n-success)"></span> Optimized (' + goodPct + '%)</span>' +
                '<span class="n8np-health-legend-item"><span class="n8np-health-legend-dot" style="background:#ea580c"></span> Thin (' + thinPct + '%)</span>' +
                '<span class="n8np-health-legend-item"><span class="n8np-health-legend-dot" style="background:var(--n8n-error)"></span> Missing SEO (' + seoPct + '%)</span>'
            );
        }

        function n8npRenderActivity(logs) {
            var $feed = $('#n8np-activity');
            if (!logs || !logs.length) {
                $feed.html('<div class="n8np-empty">No recent activity.</div>');
                return;
            }
            var html = '';
            logs.forEach(function(log) {
                html += '<div class="n8np-activity-item">' +
                    '<span class="n8np-activity-dot dot-' + log.level + '"></span>' +
                    '<span class="n8np-activity-msg">' + $('<span>').text(log.message).html() + '</span>' +
                    '<span class="n8np-activity-time">' + log.time + ' ago</span>' +
                '</div>';
            });
            $feed.html(html);
        }

        function n8npRefreshActivity() {
            $.post(n8npress.ajax_url, {
                action: 'n8npress_dashboard_data',
                nonce: n8npress.nonce
            }, function(res) {
                if (res.success) n8npRenderActivity(res.data.logs);
            });
        }

        // Scan button
        $('#n8np-scan-btn').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).find('.dashicons').addClass('spin');
            n8npLoadDashboard();
            setTimeout(function() { $btn.prop('disabled', false).find('.dashicons').removeClass('spin'); }, 2000);
        });

        // Bulk enrich
        $('#n8np-bulk-enrich').on('click', function() {
            if (!confirm('Start bulk AI enrichment for thin content products?')) return;
            var $btn = $(this);
            $btn.prop('disabled', true).text('Processing...');
            $.post(n8npress.ajax_url, {
                action: 'n8npress_get_thin_products',
                nonce: n8npress.nonce
            }, function(res) {
                if (res.success && res.data.product_ids && res.data.product_ids.length > 0) {
                    $.post(n8npress.ajax_url, {
                        action: 'n8npress_batch_enrich',
                        nonce: n8npress.nonce,
                        product_ids: res.data.product_ids
                    }, function() {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-edit-large"></span> Bulk Enrich');
                        window.n8npressToast && window.n8npressToast('Bulk enrichment started for ' + res.data.product_ids.length + ' products', 'success');
                    });
                } else {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-edit-large"></span> Bulk Enrich');
                    window.n8npressToast && window.n8npressToast('No thin content products found', 'info');
                }
            });
        });

        // ========================================
        // Password toggle
        // ========================================
        $('.n8npress-toggle-password').on('click', function() {
            var targetId = $(this).data('target');
            var $input = $('#' + targetId);
            var $icon = $(this).find('.dashicons');

            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
            } else {
                $input.attr('type', 'password');
                $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            }
        });

        // ========================================
        // Connection test
        // ========================================
        $('#n8npress-test-connection').on('click', function() {
            var $btn = $(this);
            var $result = $('#n8npress-connection-result');
            var webhookUrl = $('#n8npress_webhook_url').val();

            if (!webhookUrl) {
                $result.html('<span style="color:#dc2626;">Please enter a webhook URL first.</span>');
                return;
            }

            $btn.prop('disabled', true).find('.dashicons').addClass('spin');
            $result.html('<span style="color:#6b7280;">Testing...</span>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'n8npress_test_connection',
                    webhook_url: webhookUrl,
                    _wpnonce: $('input[name="_wpnonce"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<span style="color:#16a34a;">&#10003; Connection successful!</span>');
                    } else {
                        $result.html('<span style="color:#dc2626;">&#10007; ' + (response.data || 'Connection failed') + '</span>');
                    }
                },
                error: function() {
                    $result.html('<span style="color:#dc2626;">&#10007; Request failed</span>');
                },
                complete: function() {
                    $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                }
            });
        });

        // ========================================
        // Open Claw test connection
        // ========================================
        $('#n8npress-test-openclaw').on('click', function() {
            var $btn = $(this);
            var $status = $('#n8npress-openclaw-status');
            var clawUrl = $('#n8npress_openclaw_url').val();

            if (!clawUrl) {
                $status.html('<span style="color:#dc2626;">Please enter an Open Claw URL first.</span>');
                return;
            }

            $btn.prop('disabled', true).find('.dashicons').addClass('spin');
            $status.html('<span style="color:#6b7280;">Testing connection...</span>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'n8npress_claw_test_connection',
                    nonce: typeof n8npress !== 'undefined' && n8npress.claw_nonce ? n8npress.claw_nonce : $('input[name="_wpnonce"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        $status.html('<span style="color:#16a34a;">&#10003; ' + response.data.message + '</span>');
                    } else {
                        $status.html('<span style="color:#dc2626;">&#10007; ' + (response.data || 'Connection failed') + '</span>');
                    }
                },
                error: function() {
                    $status.html('<span style="color:#dc2626;">&#10007; Request failed. Save settings first.</span>');
                },
                complete: function() {
                    $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                }
            });
        });

        // ========================================
        // Chatwoot test connection
        // ========================================
        $('#n8npress-test-chatwoot').on('click', function() {
            var $btn = $(this);
            var $result = $('#n8npress-chatwoot-test-result');

            $btn.prop('disabled', true).find('.dashicons').addClass('spin');
            $result.html('<span style="color:#6b7280;">Testing...</span>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'n8npress_chatwoot_test',
                    _wpnonce: $('input[name="_wpnonce"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<span style="color:#16a34a;">&#10003; ' + response.data.message + '</span>');
                    } else {
                        $result.html('<span style="color:#dc2626;">&#10007; ' + (response.data || 'Connection failed') + '</span>');
                    }
                },
                error: function() {
                    $result.html('<span style="color:#dc2626;">&#10007; Request failed. Save settings first.</span>');
                },
                complete: function() {
                    $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                }
            });
        });

        // ========================================
        // Log context modal
        // ========================================
        $('.n8npress-toggle-context').on('click', function() {
            var contextData = $(this).data('context');
            var formatted;

            try {
                if (typeof contextData === 'string') {
                    contextData = JSON.parse(contextData);
                }
                formatted = JSON.stringify(contextData, null, 2);
            } catch (e) {
                formatted = String(contextData);
            }

            $('#n8npress-context-data').text(formatted);
            $('#n8npress-context-modal').show();
        });

        $('.n8npress-modal-close, .n8npress-modal').on('click', function(e) {
            if (e.target === this) {
                $('#n8npress-context-modal').hide();
            }
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                $('#n8npress-context-modal').hide();
            }
        });

        // ========================================
        // Content Opportunities Scan
        // ========================================
        function scanOpportunities() {
            var ids = ['opp-missing-seo', 'opp-missing-translations', 'opp-stale-content', 'opp-thin-content', 'opp-missing-alt'];
            ids.forEach(function(id) {
                $('#' + id).text('...').css('opacity', 0.5);
            });

            $.ajax({
                url: n8npress.ajax_url,
                type: 'POST',
                data: {
                    action: 'n8npress_scan_opportunities',
                    nonce: n8npress.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var d = response.data;
                        $('#opp-missing-seo').text(d.missing_seo_meta).css('opacity', 1);
                        $('#opp-missing-translations').text(d.missing_translations).css('opacity', 1);
                        $('#opp-stale-content').text(d.stale_content).css('opacity', 1);
                        $('#opp-thin-content').text(d.thin_content).css('opacity', 1);
                        $('#opp-missing-alt').text(d.missing_alt_text).css('opacity', 1);
                    }
                },
                error: function() {
                    ids.forEach(function(id) {
                        $('#' + id).text('?').css('opacity', 1);
                    });
                }
            });
        }

        // Auto-scan on dashboard load
        if ($('#n8npress-opportunities').length) {
            scanOpportunities();
        }

        // Manual scan button
        $('#n8npress-refresh-opportunities').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).find('.dashicons').addClass('spin');
            scanOpportunities();
            setTimeout(function() {
                $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
            }, 2000);
        });

        // ========================================
        // Bulk Enrich Thin Content
        // ========================================
        $('#n8npress-bulk-enrich-thin').on('click', function() {
            var $btn = $(this);
            var thinCount = parseInt($('#opp-thin-content').text(), 10);

            if (isNaN(thinCount) || thinCount === 0) {
                alert('No thin content found to enrich. Run a scan first.');
                return;
            }

            if (!confirm('Send up to 50 thin content products for AI enrichment? This will trigger n8n workflows.')) {
                return;
            }

            $btn.prop('disabled', true).find('.dashicons').addClass('spin');

            // First fetch thin product IDs, then send for enrichment
            $.ajax({
                url: n8npress.ajax_url,
                type: 'POST',
                data: {
                    action: 'n8npress_get_thin_products',
                    nonce: n8npress.nonce
                },
                success: function(idsResponse) {
                    if (!idsResponse.success || !idsResponse.data.product_ids.length) {
                        alert('No thin products found.');
                        $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                        return;
                    }

                    $.ajax({
                        url: n8npress.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'n8npress_batch_enrich',
                            nonce: n8npress.nonce,
                            product_ids: idsResponse.data.product_ids
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Batch enrichment started! ' + response.data.queued + ' products queued.\nBatch ID: ' + response.data.batch_id);
                            } else {
                                alert('Error: ' + (response.data || 'Batch enrichment failed'));
                            }
                        },
                        error: function() {
                            alert('Request failed');
                        },
                        complete: function() {
                            $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                        }
                    });
                },
                error: function() {
                    alert('Failed to fetch thin products');
                    $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                }
            });
        });
        // ========================================
        // Quick Actions (Dashboard)
        // ========================================
        $('#n8npress-qa-scan').on('click', function() {
            $(this).prop('disabled', true).find('.dashicons').addClass('spin');
            scanOpportunities();
            var $btn = $(this);
            setTimeout(function() {
                $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                n8npressToast('Scan complete', 'success');
            }, 2000);
        });

        $('#n8npress-qa-enrich').on('click', function() {
            $('#n8npress-bulk-enrich-thin').trigger('click');
        });

        $('#n8npress-qa-content').on('click', function() {
            window.location.href = (typeof ajaxurl !== 'undefined' ? ajaxurl.replace('/admin-ajax.php', '') : '') + '/admin.php?page=n8npress-scheduler';
        });

        $('#n8npress-qa-translate').on('click', function() {
            window.location.href = (typeof ajaxurl !== 'undefined' ? ajaxurl.replace('/admin-ajax.php', '') : '') + '/admin.php?page=n8npress-translations';
        });

        // Opportunity card action links
        $('#opp-enrich-thin-link').on('click', function(e) {
            e.preventDefault();
            $('#n8npress-bulk-enrich-thin').trigger('click');
        });

        $('#opp-enrich-stale-link').on('click', function(e) {
            e.preventDefault();
            if (!confirm('Refresh stale content via AI enrichment?')) return;
            var $link = $(this).text('Processing...');
            $.ajax({
                url: n8npress.ajax_url,
                type: 'POST',
                data: {
                    action: 'n8npress_claw_execute',
                    nonce: n8npress.claw_nonce || n8npress.nonce,
                    execute_action: 'enrich_stale',
                    params: '{}',
                    conversation_id: ''
                },
                success: function(response) {
                    if (response.success) {
                        n8npressToast(response.data.message || 'Stale content refresh started', 'success');
                    } else {
                        n8npressToast('Error: ' + (response.data || 'Failed'), 'error');
                    }
                },
                error: function() {
                    n8npressToast('Request failed', 'error');
                },
                complete: function() {
                    $link.text('Refresh Now →');
                }
            });
        });

        // ========================================
        // Activity Feed Filters
        // ========================================
        $('.activity-filter').on('click', function() {
            var filter = $(this).data('filter');
            $('.activity-filter').removeClass('active');
            $(this).addClass('active');

            if (filter === 'all') {
                $('.activity-item').show();
            } else {
                $('.activity-item').hide();
                $('.activity-item.activity-' + filter).show();
            }
        });

        // Activity details button (reuses context modal)
        $(document).on('click', '.activity-details-btn', function() {
            var contextData = $(this).data('context');
            var formatted;
            try {
                if (typeof contextData === 'string') {
                    contextData = JSON.parse(contextData);
                }
                formatted = JSON.stringify(contextData, null, 2);
            } catch (e) {
                formatted = String(contextData);
            }
            $('#n8npress-context-data').text(formatted);
            $('#n8npress-context-modal').show();
        });

        // ========================================
        // Toast Notification System
        // ========================================
        function n8npressToast(message, type) {
            type = type || 'info';
            var iconMap = {
                success: 'dashicons-yes-alt',
                error: 'dashicons-dismiss',
                warning: 'dashicons-warning',
                info: 'dashicons-info-outline'
            };
            var $toast = $('<div class="n8npress-toast n8npress-toast-' + type + '">' +
                '<span class="dashicons ' + (iconMap[type] || iconMap.info) + '"></span>' +
                '<span class="toast-message">' + message + '</span>' +
                '<button type="button" class="toast-close">&times;</button>' +
                '</div>');

            if (!$('#n8npress-toast-container').length) {
                $('body').append('<div id="n8npress-toast-container"></div>');
            }
            $('#n8npress-toast-container').append($toast);

            setTimeout(function() { $toast.addClass('toast-visible'); }, 10);

            var autoHide = setTimeout(function() {
                $toast.removeClass('toast-visible');
                setTimeout(function() { $toast.remove(); }, 300);
            }, 5000);

            $toast.find('.toast-close').on('click', function() {
                clearTimeout(autoHide);
                $toast.removeClass('toast-visible');
                setTimeout(function() { $toast.remove(); }, 300);
            });
        }

        // Make toast available globally
        window.n8npressToast = n8npressToast;

        // ========================================
        // Open Claw — AI Chat Interface
        // ========================================
        if ($('#claw-messages').length) {
            var clawConversationId = '';
            var clawMessageCount = 0;
            var clawIsProcessing = false;

            function clawGenerateId() {
                return 'claw_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);
            }

            function clawAutoResize() {
                var el = document.getElementById('claw-input');
                if (!el) return;
                el.style.height = 'auto';
                el.style.height = Math.min(el.scrollHeight, 120) + 'px';
            }

            function clawScrollToBottom() {
                var container = document.getElementById('claw-messages');
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            }

            function clawToggleSendBtn() {
                var val = $('#claw-input').val().trim();
                $('#claw-send').prop('disabled', !val || clawIsProcessing);
            }

            function clawAddMessage(role, content, extra) {
                // Remove welcome screen on first message
                $('.claw-welcome').remove();

                var avatarHtml;
                if (role === 'user') {
                    var initials = (n8npress.user_initial || 'U');
                    avatarHtml = '<div class="claw-avatar">' + initials + '</div>';
                } else {
                    avatarHtml = '<div class="claw-avatar"><span class="dashicons dashicons-superhero-alt"></span></div>';
                }

                var bubbleHtml = '<div class="claw-bubble">' + clawFormatContent(content) + '</div>';

                // Error message with retry button
                if (extra && extra.error) {
                    bubbleHtml = '<div class="claw-bubble claw-bubble-error">';
                    bubbleHtml += '<span class="dashicons dashicons-warning" style="color:#dc2626;margin-right:6px;"></span>';
                    bubbleHtml += '<span>' + (extra.errorMessage || 'An error occurred') + '</span>';
                    if (extra.retryMessage) {
                        bubbleHtml += '<div class="claw-retry-wrap"><button type="button" class="claw-retry-btn" data-message="' + $('<span>').text(extra.retryMessage).html().replace(/"/g, '&quot;') + '"><span class="dashicons dashicons-update"></span> Retry</button></div>';
                    }
                    bubbleHtml += '</div>';
                }
                // Append action buttons if present
                else if (extra && extra.action) {
                    bubbleHtml = '<div class="claw-bubble">' + clawFormatContent(content);
                    bubbleHtml += '<div class="claw-action-buttons">';
                    bubbleHtml += '<button class="claw-confirm-btn" data-action="' + extra.action + '" data-params=\'' + JSON.stringify(extra.params || {}) + '\'>Confirm</button>';
                    bubbleHtml += '<button class="claw-cancel-btn">Cancel</button>';
                    bubbleHtml += '</div></div>';
                }

                var msgHtml = '<div class="claw-message claw-' + role + '">' + avatarHtml + bubbleHtml + '</div>';
                $('#claw-messages').append(msgHtml);

                clawMessageCount++;
                clawScrollToBottom();
                clawUpdateConvInfo();
            }

            function clawAddTyping() {
                var html = '<div class="claw-message claw-assistant" id="claw-typing">';
                html += '<div class="claw-avatar"><span class="dashicons dashicons-superhero-alt"></span></div>';
                html += '<div class="claw-bubble claw-typing">';
                html += '<span class="claw-typing-dot"></span>';
                html += '<span class="claw-typing-dot"></span>';
                html += '<span class="claw-typing-dot"></span>';
                html += '</div></div>';
                $('#claw-messages').append(html);
                clawScrollToBottom();
            }

            function clawRemoveTyping() {
                $('#claw-typing').remove();
            }

            function clawFormatContent(text) {
                if (!text) return '';
                // Basic markdown-like formatting
                text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                text = text.replace(/`(.+?)`/g, '<code>$1</code>');
                text = text.replace(/\n/g, '<br>');
                return text;
            }

            function clawUpdateConvInfo() {
                if (clawConversationId) {
                    $('#claw-conversation-info').show();
                    $('#claw-conv-id').text(clawConversationId);
                    $('#claw-conv-count').text(clawMessageCount + ' messages');
                }
            }

            var clawLastAction = null; // Track last pending action

            function clawSendMessage(message) {
                if (!message || clawIsProcessing) return;

                // Check if user is confirming a pending action
                var confirmWords = /^(yes|confirm|ok|do it|go ahead|approve|sure)$/i;
                if (confirmWords.test(message.trim()) && clawLastAction) {
                    // Auto-click the confirm button
                    var $btn = $('.claw-confirm-btn').last();
                    if ($btn.length) {
                        clawAddMessage('user', message);
                        $btn.click();
                        clawLastAction = null;
                        return;
                    }
                }

                if (!clawConversationId) {
                    clawConversationId = clawGenerateId();
                }

                clawIsProcessing = true;
                clawToggleSendBtn();
                clawAddMessage('user', message);
                clawAddTyping();

                $.ajax({
                    url: n8npress.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'n8npress_claw_send',
                        nonce: n8npress.claw_nonce,
                        message: message,
                        conversation_id: clawConversationId
                    },
                    success: function(response) {
                        clawRemoveTyping();

                        if (response.success && response.data) {
                            var d = response.data;
                            var actions = d.actions || [];

                            if (actions.length > 0) {
                                var act = actions[0];
                                clawLastAction = { action: act.type, params: act.data || {} };
                                clawAddMessage('assistant', d.response, {
                                    action: act.type,
                                    params: act.data || {}
                                });
                            } else if (d.status === 'processing') {
                                clawAddMessage('assistant', d.response || 'Processing your request... I\'ll respond shortly.');
                            } else {
                                clawAddMessage('assistant', d.response || d.message || 'Done.');
                            }
                        } else {
                            var errMsg = response.data || 'Something went wrong';
                            clawAddMessage('assistant', '', { error: true, errorMessage: errMsg, retryMessage: message });
                        }
                    },
                    error: function(xhr) {
                        clawRemoveTyping();
                        var errDetail = 'Connection error';
                        if (xhr.status) errDetail += ' (HTTP ' + xhr.status + ')';
                        clawAddMessage('assistant', '', { error: true, errorMessage: errDetail, retryMessage: message });
                    },
                    complete: function() {
                        clawIsProcessing = false;
                        clawToggleSendBtn();
                    }
                });
            }

            // Slash command autocomplete
            var clawCommands = [
                { cmd: '/scan', desc: 'Content opportunity scan' },
                { cmd: '/seo', desc: 'Missing SEO meta' },
                { cmd: '/translate', desc: 'Start translation' },
                { cmd: '/enrich', desc: 'Batch AI enrichment' },
                { cmd: '/thin', desc: 'Thin content products' },
                { cmd: '/stale', desc: 'Stale content list' },
                { cmd: '/generate', desc: 'Generate blog post' },
                { cmd: '/aeo', desc: 'AEO schema coverage' },
                { cmd: '/reviews', desc: 'Review overview' },
                { cmd: '/plugins', desc: 'Plugin environment' },
                { cmd: '/crm', desc: 'Customer segments' },
                { cmd: '/revenue', desc: 'Sales & revenue' },
                { cmd: '/products', desc: 'Product count' },
                { cmd: '/help', desc: 'Show all commands' }
            ];

            function clawShowCommandHints(filter) {
                $('#claw-cmd-hints').remove();
                var matches = clawCommands.filter(function(c) { return c.cmd.indexOf(filter) === 0; });
                if (!matches.length || filter === '/') matches = clawCommands;
                var html = '<div id="claw-cmd-hints" style="position:absolute;bottom:100%;left:0;right:0;background:#fff;border:1px solid #e5e7eb;border-radius:8px;max-height:200px;overflow-y:auto;box-shadow:0 -4px 12px rgba(0,0,0,0.1);z-index:10;">';
                matches.forEach(function(c) {
                    html += '<div class="claw-cmd-hint" data-cmd="' + c.cmd + '" style="padding:8px 12px;cursor:pointer;display:flex;justify-content:space-between;border-bottom:1px solid #f3f4f6;">';
                    html += '<code style="color:#6366f1;font-weight:600;">' + c.cmd + '</code>';
                    html += '<span style="color:#6b7280;font-size:12px;">' + c.desc + '</span></div>';
                });
                html += '</div>';
                $('#claw-input').closest('.claw-input-row').css('position', 'relative').append(html);
            }

            $(document).on('click', '.claw-cmd-hint', function() {
                var cmd = $(this).data('cmd');
                $('#claw-input').val(cmd + ' ').focus();
                $('#claw-cmd-hints').remove();
                clawAutoResize();
                clawToggleSendBtn();
            });

            // Input handling
            $('#claw-input').on('input', function() {
                clawAutoResize();
                clawToggleSendBtn();
                var val = $(this).val();
                if (val.charAt(0) === '/' && val.indexOf(' ') === -1) {
                    clawShowCommandHints(val);
                } else {
                    $('#claw-cmd-hints').remove();
                }
            });

            $('#claw-input').on('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    var msg = $(this).val().trim();
                    if (msg && !clawIsProcessing) {
                        $(this).val('');
                        clawAutoResize();
                        clawToggleSendBtn();
                        clawSendMessage(msg);
                    }
                }
            });

            $('#claw-send').on('click', function() {
                var msg = $('#claw-input').val().trim();
                if (msg && !clawIsProcessing) {
                    $('#claw-input').val('');
                    clawAutoResize();
                    clawToggleSendBtn();
                    clawSendMessage(msg);
                }
            });

            // Suggestion buttons
            $(document).on('click', '.claw-suggestion, .claw-action-btn', function() {
                var msg = $(this).data('message');
                if (msg && !clawIsProcessing) {
                    clawSendMessage(msg);
                }
            });

            // Retry button handler
            $(document).on('click', '.claw-retry-btn', function() {
                var msg = $(this).data('message');
                if (msg && !clawIsProcessing) {
                    $(this).closest('.claw-message').remove();
                    clawMessageCount--;
                    clawSendMessage(msg);
                }
            });

            // New chat (with server-side history clear)
            $('#claw-new-chat').on('click', function() {
                var oldConvId = clawConversationId;
                clawConversationId = '';
                clawMessageCount = 0;
                $('#claw-messages').html(
                    '<div class="claw-welcome">' +
                    '<div class="claw-welcome-icon"><span class="dashicons dashicons-superhero-alt"></span></div>' +
                    '<h3>New conversation started</h3>' +
                    '<p>Ask me anything about your store.</p>' +
                    '<div class="claw-suggestions">' +
                    '<button type="button" class="claw-suggestion" data-message="How many products have thin content?"><span class="dashicons dashicons-editor-paste-text"></span> Thin content report</button>' +
                    '<button type="button" class="claw-suggestion" data-message="Show me products that need translation"><span class="dashicons dashicons-translation"></span> Missing translations</button>' +
                    '<button type="button" class="claw-suggestion" data-message="What\'s the AEO coverage for my store?"><span class="dashicons dashicons-chart-bar"></span> AEO coverage</button>' +
                    '</div></div>'
                );
                $('#claw-conversation-info').hide();

                // Clear server-side conversation history
                if (oldConvId) {
                    $.ajax({
                        url: n8npress.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'n8npress_claw_clear_history',
                            nonce: n8npress.claw_nonce,
                            conversation_id: oldConvId
                        }
                    });
                }
            });

            // Action confirm/cancel
            $(document).on('click', '.claw-confirm-btn', function() {
                clawLastAction = null;
                var actionName = $(this).data('action');
                var params = $(this).data('params') || {};
                var $btns = $(this).closest('.claw-action-buttons');
                $btns.html('<span style="color:#16a34a;font-size:12px;">Executing...</span>');

                $.ajax({
                    url: n8npress.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'n8npress_claw_execute',
                        nonce: n8npress.claw_nonce,
                        execute_action: actionName,
                        params: JSON.stringify(params),
                        conversation_id: clawConversationId
                    },
                    success: function(response) {
                        if (response.success) {
                            $btns.html('<span style="color:#16a34a;font-size:12px;">Done!</span>');
                            if (response.data && response.data.message) {
                                clawAddMessage('assistant', response.data.message);
                            }
                        } else {
                            $btns.html('<span style="color:#dc2626;font-size:12px;">Failed: ' + (response.data || 'Unknown error') + '</span>');
                        }
                    },
                    error: function() {
                        $btns.html('<span style="color:#dc2626;font-size:12px;">Request failed</span>');
                    }
                });
            });

            $(document).on('click', '.claw-cancel-btn', function() {
                clawLastAction = null;
                $(this).closest('.claw-action-buttons').html('<span style="color:#6b7280;font-size:12px;">Cancelled</span>');
            });
        }

    });

})(jQuery);
