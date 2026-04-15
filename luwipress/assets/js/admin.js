/**
 * LuwiPress Admin Scripts
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // ========================================
        // Dashboard v2 — AJAX loader
        // ========================================
        if ($('#lp-hero').length) {
            lpLoadDashboard();
            // Auto-refresh activity every 30s
            setInterval(function() { lpRefreshActivity(); }, 30000);
        }

        function lpLoadDashboard() {
            $.post(luwipress.ajax_url, {
                action: 'luwipress_dashboard_data',
                nonce: luwipress.nonce
            }, function(res) {
                if (!res.success) return;
                var d = res.data;

                // Hero counters (animated)
                lpAnimateNum('[data-key="products"] .lp-hero-num', d.products);
                lpAnimateNum('[data-key="revenue"] .lp-hero-num', d.currency + lpFormatNum(d.revenue));
                lpAnimateNum('[data-key="ai_calls"] .lp-hero-num', d.ai_calls);
                lpAnimateNum('[data-key="budget"] .lp-hero-num', d.budget_pct + '%');

                // Budget bar
                var $bf = $('[data-key="budget_pct"]');
                $bf.css('width', Math.min(100, d.budget_pct) + '%');
                if (d.budget_pct >= 90) $bf.addClass('budget-crit');
                else if (d.budget_pct >= 70) $bf.addClass('budget-warn');

                // 7-day chart
                lpRenderChart(d.daily_costs);

                // Content health ring
                lpRenderHealthRing(d.health_pct, d.health_thin, d.health_seo, d.products);

                // Opportunities
                if (d.opportunities) {
                    $.each(d.opportunities, function(key, val) {
                        var $row = $('[data-opp="' + key + '"]');
                        $row.find('.lp-opp-count').removeClass('lp-skeleton').text(val);
                    });
                }

                // Activity
                lpRenderActivity(d.logs);

                // Workflow & Model breakdown tables
                if (d.workflow_breakdown && d.workflow_breakdown.length) {
                    $('#lp-breakdown-section').show();
                    var wfHtml = '';
                    d.workflow_breakdown.forEach(function(w) {
                        wfHtml += '<tr><td><code>' + w.workflow + '</code></td><td>' + w.calls.toLocaleString() + '</td><td>' + w.input_tokens.toLocaleString() + '</td><td>' + w.output_tokens.toLocaleString() + '</td><td><strong>$' + w.cost.toFixed(4) + '</strong></td></tr>';
                    });
                    $('#lp-workflow-table tbody').html(wfHtml);
                }
                if (d.model_breakdown && d.model_breakdown.length) {
                    var mHtml = '';
                    d.model_breakdown.forEach(function(m) {
                        mHtml += '<tr><td><code>' + m.model + '</code></td><td>' + m.provider + '</td><td>' + m.calls.toLocaleString() + '</td><td>' + (m.input_tokens + m.output_tokens).toLocaleString() + '</td><td><strong>$' + m.cost.toFixed(4) + '</strong></td></tr>';
                    });
                    $('#lp-model-table tbody').html(mHtml);
                }

                // Translation coverage
                if (d.trans_coverage) {
                    $.each(d.trans_coverage, function(lang, pct) {
                        var $row = $('[data-lang="' + lang + '"]');
                        $row.find('.lp-trans-fill').removeClass('lp-skeleton').css('width', pct + '%');
                        if (pct >= 80) $row.find('.lp-trans-fill').css('background', 'var(--lp-success)');
                        else if (pct >= 40) $row.find('.lp-trans-fill').css('background', 'var(--lp-warning)');
                        else $row.find('.lp-trans-fill').css('background', 'var(--lp-error)');
                        $row.find('.lp-trans-pct').removeClass('lp-skeleton').text(pct + '%');
                    });
                }
            });
        }

        function lpAnimateNum(sel, target) {
            var $el = $(sel);
            $el.removeClass('lp-skeleton').css('animation', 'lp-count-in 0.4s ease');
            if (typeof target === 'string') {
                $el.html(target);
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

        function lpFormatNum(n) {
            if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
            if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
            return n.toFixed(2);
        }

        function lpRenderChart(days) {
            if (!days || !days.length) return;
            var $chart = $('#lp-cost-chart');
            var maxCost = Math.max.apply(null, days.map(function(d) { return d.cost; })) || 0.01;
            var total = 0;
            var html = '';
            days.forEach(function(d) {
                total += d.cost;
                var h = Math.max(4, (d.cost / maxCost) * 100);
                html += '<div class="lp-chart-bar" style="height:' + h + '%">' +
                    '<span class="bar-tooltip">$' + d.cost.toFixed(4) + '</span></div>';
            });
            $chart.html(html);

            // Labels
            var labels = '<div class="lp-chart-labels">';
            days.forEach(function(d) { labels += '<span>' + d.day + '</span>'; });
            labels += '</div>';
            $chart.after(labels);

            // Footer
            $('#lp-cost-footer .lp-chart-total').removeClass('lp-skeleton').text('$' + total.toFixed(4));
        }

        function lpRenderHealthRing(pct, thin, seo, total) {
            var $wrap = $('#lp-health');
            var thinPct = total > 0 ? Math.round((thin / total) * 100) : 0;
            var seoPct = total > 0 ? Math.round((seo / total) * 100) : 0;
            var goodPct = Math.max(0, 100 - thinPct - seoPct);

            var gradient = 'conic-gradient(' +
                'var(--lp-success) 0% ' + goodPct + '%, ' +
                '#ea580c ' + goodPct + '% ' + (goodPct + thinPct) + '%, ' +
                'var(--lp-error) ' + (goodPct + thinPct) + '% 100%)';

            $wrap.html(
                '<div class="lp-health-ring" style="background:' + gradient + '">' +
                    '<div class="lp-health-ring-inner">' +
                        '<span class="lp-health-pct">' + pct + '%</span>' +
                        '<span class="lp-health-sub">Optimized</span>' +
                    '</div>' +
                '</div>'
            );

            $('#lp-health-legend').html(
                '<span class="lp-health-legend-item"><span class="lp-health-legend-dot" style="background:var(--lp-success)"></span> Optimized (' + goodPct + '%)</span>' +
                '<span class="lp-health-legend-item"><span class="lp-health-legend-dot" style="background:#ea580c"></span> Thin (' + thinPct + '%)</span>' +
                '<span class="lp-health-legend-item"><span class="lp-health-legend-dot" style="background:var(--lp-error)"></span> Missing SEO (' + seoPct + '%)</span>'
            );
        }

        function lpRenderActivity(logs) {
            var $feed = $('#lp-activity');
            if (!logs || !logs.length) {
                $feed.html('<div class="lp-empty">No recent activity.</div>');
                return;
            }
            var html = '';
            logs.forEach(function(log) {
                html += '<div class="lp-activity-item">' +
                    '<span class="lp-activity-dot dot-' + log.level + '"></span>' +
                    '<span class="lp-activity-msg">' + $('<span>').text(log.message).html() + '</span>' +
                    '<span class="lp-activity-time">' + log.time + ' ago</span>' +
                '</div>';
            });
            $feed.html(html);
        }

        function lpRefreshActivity() {
            $.post(luwipress.ajax_url, {
                action: 'luwipress_dashboard_data',
                nonce: luwipress.nonce
            }, function(res) {
                if (res.success) lpRenderActivity(res.data.logs);
            });
        }

        // Scan button
        $('#lp-scan-btn').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).find('.dashicons').addClass('spin');
            lpLoadDashboard();
            setTimeout(function() { $btn.prop('disabled', false).find('.dashicons').removeClass('spin'); }, 2000);
        });

        // Bulk enrich
        $('#lp-bulk-enrich').on('click', function() {
            if (!confirm('Start bulk AI enrichment for thin content products?')) return;
            var $btn = $(this);
            $btn.prop('disabled', true).text('Processing...');
            $.post(luwipress.ajax_url, {
                action: 'luwipress_get_thin_products',
                nonce: luwipress.nonce
            }, function(res) {
                if (res.success && res.data.product_ids && res.data.product_ids.length > 0) {
                    $.post(luwipress.ajax_url, {
                        action: 'luwipress_batch_enrich',
                        nonce: luwipress.nonce,
                        product_ids: res.data.product_ids
                    }, function() {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-edit-large"></span> Bulk Enrich');
                        window.luwipressToast && window.luwipressToast('Bulk enrichment started for ' + res.data.product_ids.length + ' products', 'success');
                    });
                } else {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-edit-large"></span> Bulk Enrich');
                    window.luwipressToast && window.luwipressToast('No thin content products found', 'info');
                }
            });
        });

        // ========================================
        // Content Scheduler
        // ========================================
        if ($('#sched-form').length) {
            // Submit form
            $('#sched-form').on('submit', function(e) {
                e.preventDefault();
                var $form = $(this);
                var $btn = $form.find('.sched-submit');
                var $result = $('#sched-result');

                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Generating...');
                $result.html('');

                $.ajax({
                    url: luwipress.ajax_url,
                    type: 'POST',
                    data: $form.serialize() + '&action=luwipress_schedule_content',
                    success: function(res) {
                        if (res.success) {
                            $result.html('<div class="notice notice-success" style="border-radius:6px;"><p>Content generation started! Refreshing...</p></div>');
                            setTimeout(function() { location.reload(); }, 1500);
                        } else {
                            $result.html('<div class="notice notice-error" style="border-radius:6px;"><p>' + (res.data || 'Error') + '</p></div>');
                        }
                    },
                    error: function() {
                        $result.html('<div class="notice notice-error" style="border-radius:6px;"><p>Request failed</p></div>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> Generate & Schedule');
                    }
                });
            });

            // Delete item
            $(document).on('click', '.sched-delete', function() {
                if (!confirm('Delete this scheduled item?')) return;
                var $item = $(this).closest('.sched-item');
                var id = $(this).data('id');
                $.post(luwipress.ajax_url, {
                    action: 'luwipress_delete_schedule',
                    schedule_id: id,
                    _wpnonce: $('#sched-form input[name="_wpnonce"]').val()
                }, function(res) {
                    if (res.success) {
                        $item.css({ opacity: 0, transform: 'translateX(20px)' });
                        setTimeout(function() { $item.remove(); }, 300);
                    }
                });
            });

            // Poll generating items every 10s
            var $generating = $('.sched-item[data-status="generating"]');
            if ($generating.length) {
                setInterval(function() {
                    location.reload();
                }, 15000);
            }
        }

        // ========================================
        // Password toggle
        // ========================================
        $('.luwipress-toggle-password').on('click', function() {
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
        // Generate random API token
        // ========================================
        $('#luwipress-generate-token').on('click', function() {
            var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            var token = 'lp_';
            var arr = new Uint8Array(40);
            crypto.getRandomValues(arr);
            for (var i = 0; i < 40; i++) {
                token += chars.charAt(arr[i] % chars.length);
            }
            $('#luwipress_api_token').attr('type', 'text').val(token);
            window.luwipress_toast && window.luwipress_toast('Token generated — save settings to apply.', 'info');
        });

        // ========================================
        // Connection test
        // ========================================
        $('#luwipress-test-connection').on('click', function() {
            var $btn = $(this);
            var $result = $('#luwipress-connection-result');
            var webhookUrl = $('#luwipress_webhook_url').val();

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
                    action: 'luwipress_test_connection',
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
        $('#luwipress-test-openclaw').on('click', function() {
            var $btn = $(this);
            var $status = $('#luwipress-openclaw-status');
            var clawUrl = $('#luwipress_openclaw_url').val();

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
                    action: 'luwipress_claw_test_connection',
                    nonce: typeof luwipress !== 'undefined' && luwipress.claw_nonce ? luwipress.claw_nonce : $('input[name="_wpnonce"]').val()
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
        // Log context modal
        // ========================================
        $('.luwipress-toggle-context').on('click', function() {
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

            $('#luwipress-context-data').text(formatted);
            $('#luwipress-context-modal').show();
        });

        $('.luwipress-modal-close, .luwipress-modal').on('click', function(e) {
            if (e.target === this) {
                $('#luwipress-context-modal').hide();
            }
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                $('#luwipress-context-modal').hide();
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
                url: luwipress.ajax_url,
                type: 'POST',
                data: {
                    action: 'luwipress_scan_opportunities',
                    nonce: luwipress.nonce
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
        if ($('#luwipress-opportunities').length) {
            scanOpportunities();
        }

        // Manual scan button
        $('#luwipress-refresh-opportunities').on('click', function() {
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
        $('#luwipress-bulk-enrich-thin').on('click', function() {
            var $btn = $(this);
            var thinCount = parseInt($('#opp-thin-content').text(), 10);

            if (isNaN(thinCount) || thinCount === 0) {
                alert('No thin content found to enrich. Run a scan first.');
                return;
            }

            if (!confirm('Send up to 50 thin content products for AI enrichment? This will trigger AI enrichment.')) {
                return;
            }

            $btn.prop('disabled', true).find('.dashicons').addClass('spin');

            // First fetch thin product IDs, then send for enrichment
            $.ajax({
                url: luwipress.ajax_url,
                type: 'POST',
                data: {
                    action: 'luwipress_get_thin_products',
                    nonce: luwipress.nonce
                },
                success: function(idsResponse) {
                    if (!idsResponse.success || !idsResponse.data.product_ids.length) {
                        alert('No thin products found.');
                        $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                        return;
                    }

                    $.ajax({
                        url: luwipress.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'luwipress_batch_enrich',
                            nonce: luwipress.nonce,
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
        $('#luwipress-qa-scan').on('click', function() {
            $(this).prop('disabled', true).find('.dashicons').addClass('spin');
            scanOpportunities();
            var $btn = $(this);
            setTimeout(function() {
                $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                luwipressToast('Scan complete', 'success');
            }, 2000);
        });

        $('#luwipress-qa-enrich').on('click', function() {
            $('#luwipress-bulk-enrich-thin').trigger('click');
        });

        $('#luwipress-qa-content').on('click', function() {
            window.location.href = (typeof ajaxurl !== 'undefined' ? ajaxurl.replace('/admin-ajax.php', '') : '') + '/admin.php?page=luwipress-scheduler';
        });

        $('#luwipress-qa-translate').on('click', function() {
            window.location.href = (typeof ajaxurl !== 'undefined' ? ajaxurl.replace('/admin-ajax.php', '') : '') + '/admin.php?page=luwipress-translations';
        });

        // Opportunity card action links
        $('#opp-enrich-thin-link').on('click', function(e) {
            e.preventDefault();
            $('#luwipress-bulk-enrich-thin').trigger('click');
        });

        $('#opp-enrich-stale-link').on('click', function(e) {
            e.preventDefault();
            if (!confirm('Refresh stale content via AI enrichment?')) return;
            var $link = $(this).text('Processing...');
            $.ajax({
                url: luwipress.ajax_url,
                type: 'POST',
                data: {
                    action: 'luwipress_claw_execute',
                    nonce: luwipress.claw_nonce || luwipress.nonce,
                    execute_action: 'enrich_stale',
                    params: '{}',
                    conversation_id: ''
                },
                success: function(response) {
                    if (response.success) {
                        luwipressToast(response.data.message || 'Stale content refresh started', 'success');
                    } else {
                        luwipressToast('Error: ' + (response.data || 'Failed'), 'error');
                    }
                },
                error: function() {
                    luwipressToast('Request failed', 'error');
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
            $('#luwipress-context-data').text(formatted);
            $('#luwipress-context-modal').show();
        });

        // ========================================
        // Toast Notification System
        // ========================================
        function luwipressToast(message, type) {
            type = type || 'info';
            var iconMap = {
                success: 'dashicons-yes-alt',
                error: 'dashicons-dismiss',
                warning: 'dashicons-warning',
                info: 'dashicons-info-outline'
            };
            var $toast = $('<div class="luwipress-toast luwipress-toast-' + type + '">' +
                '<span class="dashicons ' + (iconMap[type] || iconMap.info) + '"></span>' +
                '<span class="toast-message">' + message + '</span>' +
                '<button type="button" class="toast-close">&times;</button>' +
                '</div>');

            if (!$('#luwipress-toast-container').length) {
                $('body').append('<div id="luwipress-toast-container"></div>');
            }
            $('#luwipress-toast-container').append($toast);

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
        window.luwipressToast = luwipressToast;

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
                    var initials = (luwipress.user_initial || 'U');
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
                    url: luwipress.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'luwipress_claw_send',
                        nonce: luwipress.claw_nonce,
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
                        url: luwipress.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'luwipress_claw_clear_history',
                            nonce: luwipress.claw_nonce,
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
                    url: luwipress.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'luwipress_claw_execute',
                        nonce: luwipress.claw_nonce,
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
