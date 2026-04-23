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
        // Content Scheduler — hybrid tab layout (3.1.15)
        // ========================================
        if ($('.sched-shell').length) {

            // ──────── i18n + toast helpers ────────
            var I18N = (luwipress && luwipress.i18n) || {};
            function t(key, fallback) { return I18N[key] || fallback || ''; }
            function tf(key, fallback) {
                // sprintf-lite for %d and %s replacements
                var template = I18N[key] || fallback || '';
                var args = Array.prototype.slice.call(arguments, 2);
                var i = 0;
                return template.replace(/%(\d+\$)?[ds]/g, function() { return args[i++]; });
            }
            function notify(message, type) {
                type = type || 'info';
                if (window.luwipress_toast) {
                    window.luwipress_toast(message, type);
                } else {
                    if (type === 'error') alert(message);
                }
            }
            function escapeHtml(s) {
                return (s + '').replace(/[&<>"']/g, function(c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            // ──────── Confirm modal (replaces native window.confirm) ────────
            var $confirmModal = $('#sched-confirm-modal');
            var $confirmTitle = $('#sched-confirm-title');
            var $confirmMessage = $('#sched-confirm-message');
            var $confirmIcon = $('#sched-confirm-icon');
            var $confirmOk = $('#sched-confirm-ok');
            var $confirmCancel = $('#sched-confirm-cancel');
            var confirmPrevFocus = null;
            var confirmResolver = null;

            function schedConfirm(opts) {
                opts = opts || {};
                var msg      = opts.message || '';
                var title    = opts.title || t('err_generic', 'Are you sure?');
                var variant  = opts.variant || 'warning'; // warning | danger | info
                var okLabel  = opts.okLabel || (variant === 'danger' ? t('lbl_confirm_delete', 'Delete') : t('lbl_confirm', 'Confirm'));
                var cancelLb = opts.cancelLabel || t('lbl_cancel', 'Cancel');
                var iconCls  = variant === 'danger' ? 'dashicons-trash' : (variant === 'info' ? 'dashicons-info-outline' : 'dashicons-warning');

                $confirmModal.removeClass('is-danger is-info is-warning').addClass('is-' + variant);
                $confirmTitle.text(title);
                $confirmMessage.text(msg);
                $confirmIcon.find('.dashicons').attr('class', 'dashicons ' + iconCls);
                $confirmOk.text(okLabel);
                $confirmCancel.text(cancelLb);

                confirmPrevFocus = document.activeElement;
                $confirmModal.prop('hidden', false).attr('aria-hidden', 'false');
                focusTrapActivate($confirmModal);
                setTimeout(function() { $confirmOk.focus(); }, 20);

                return new Promise(function(resolve) {
                    confirmResolver = resolve;
                });
            }

            function confirmClose(result) {
                $confirmModal.prop('hidden', true).attr('aria-hidden', 'true');
                focusTrapRelease();
                if (confirmPrevFocus && confirmPrevFocus.focus) {
                    try { confirmPrevFocus.focus(); } catch (e) {}
                }
                if (confirmResolver) {
                    var r = confirmResolver;
                    confirmResolver = null;
                    r(result === true);
                }
            }

            $confirmOk.on('click', function() { confirmClose(true); });
            $confirmCancel.on('click', function() { confirmClose(false); });
            $confirmModal.on('click', '[data-close="1"]', function() { confirmClose(false); });

            // Back-compat shim — keeps old call sites working during migration
            function confirmed(message) { return window.confirm(message); }

            // ──────── Focus trap (shared by all scheduler modals) ────────
            var focusTrapState = { $modal: null, handler: null };
            function focusableIn($modal) {
                return $modal.find(
                    'a[href], button:not([disabled]), textarea:not([disabled]), ' +
                    'input:not([disabled]):not([type="hidden"]), select:not([disabled]), ' +
                    '[tabindex]:not([tabindex="-1"])'
                ).filter(':visible');
            }
            function focusTrapActivate($modal) {
                focusTrapRelease();
                var handler = function(e) {
                    if (e.key !== 'Tab') return;
                    var $f = focusableIn($modal);
                    if (!$f.length) { e.preventDefault(); return; }
                    var first = $f[0], last = $f[$f.length - 1];
                    if (e.shiftKey && document.activeElement === first) {
                        last.focus(); e.preventDefault();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        first.focus(); e.preventDefault();
                    }
                };
                document.addEventListener('keydown', handler);
                focusTrapState = { $modal: $modal, handler: handler };
            }
            function focusTrapRelease() {
                if (focusTrapState.handler) {
                    document.removeEventListener('keydown', focusTrapState.handler);
                }
                focusTrapState = { $modal: null, handler: null };
            }

            // ──────── Main tab switching ────────
            var $tabs = $('.sched-tab');
            var $panels = $('.sched-tabpanel');
            var defaultTab = $('.sched-shell').data('default-tab') || 'queue';

            function activateTab(name, pushHash) {
                var matched = false;
                $tabs.each(function() {
                    var is = $(this).data('tab') === name;
                    $(this).attr('aria-selected', is ? 'true' : 'false');
                    if (is) matched = true;
                });
                if (!matched) { name = defaultTab; }
                $panels.each(function() {
                    var is = $(this).attr('id') === 'sched-tabpanel-' + name;
                    $(this).prop('hidden', !is);
                });
                if (pushHash !== false && window.history && window.history.replaceState) {
                    try { window.history.replaceState(null, '', '#' + name); } catch (e) {}
                }
            }

            $tabs.on('click', function() { activateTab($(this).data('tab')); });
            $(document).on('click', '[data-goto-tab]', function() { activateTab($(this).data('goto-tab')); });

            // Initial tab from hash or default
            var hashTab = (location.hash || '').replace('#', '');
            activateTab(['queue', 'plans', 'create'].indexOf(hashTab) !== -1 ? hashTab : defaultTab, false);

            // ──────── Wizard ────────
            if ($('#sched-wizard-form').length) {
                var $wizForm     = $('#sched-wizard-form');
                var $wizSteps    = $('.sched-wiz-step');
                var $wizPanels   = $('.sched-wiz-panel');
                var $wizBack     = $('.sched-wiz-back');
                var $wizNext     = $('.sched-wiz-next');
                var $wizSubmit   = $('.sched-wiz-submit');
                var $wizProgress = $('.sched-wiz-progress-bar');
                var $wizTopics   = $('#sched-wiz-topics');
                var $wizCount    = $('#sched-wiz-count');
                var $wizBulkOnly = $('.sched-bulk-only');
                var $wizSummary  = $('#sched-wiz-summary');
                var $wizResult   = $('#sched-wiz-result');
                var totalSteps   = 4;
                var currentStep  = 1;

                function countTopics() {
                    return ($wizTopics.val() || '').split('\n').filter(function(l) { return l.trim().length > 0; }).length;
                }
                function updateTopicCount() {
                    var n = countTopics();
                    $wizCount.text(n + ' / 50');
                    $wizCount.toggleClass('over-limit', n > 50);
                    $wizBulkOnly.prop('hidden', n <= 1);
                }

                function clearFieldError($field) { $field.closest('.sched-field').removeClass('has-error'); }
                function markFieldError($field) {
                    var $f = $field.closest('.sched-field');
                    $f.addClass('has-error');
                    $field.one('input change', function() { clearFieldError($(this)); });
                }

                function showStep(n) {
                    n = Math.max(1, Math.min(totalSteps, n));
                    currentStep = n;
                    $wizPanels.each(function() {
                        var p = parseInt($(this).attr('data-panel'), 10);
                        $(this).prop('hidden', p !== n).toggleClass('is-active', p === n);
                    });
                    $wizSteps.each(function() {
                        var s = parseInt($(this).attr('data-step'), 10);
                        $(this).toggleClass('is-active', s === n).toggleClass('is-done', s < n);
                    });
                    $wizProgress.css('width', (n / totalSteps * 100) + '%');
                    $wizBack.prop('disabled', n === 1);
                    if (n === totalSteps) {
                        $wizNext.prop('hidden', true);
                        $wizSubmit.prop('hidden', false);
                        renderSummary();
                        fetchBudgetPreview();
                    } else {
                        $wizNext.prop('hidden', false);
                        $wizSubmit.prop('hidden', true);
                    }
                }

                function fetchBudgetPreview() {
                    var $box = $('#sched-wiz-budget');
                    var $body = $('#sched-wiz-budget-body');
                    var $provider = $('#sched-wiz-budget-provider');
                    var topicCount = countTopics();
                    var depth = $wizForm.find('input[name="depth"]:checked').val() || 'standard';
                    var words = parseInt($wizForm.find('input[name="word_count"]').val(), 10) || 1500;
                    var generateImage = $wizForm.find('input[name="generate_image"]').is(':checked') ? 1 : 0;
                    var extraLangs = $wizForm.find('input[name="additional_languages[]"]:checked').length;
                    var langMultiplier = 1 + extraLangs;
                    var effectiveTopicCount = topicCount * langMultiplier;

                    $box.prop('hidden', false);
                    $body.html('<span class="sched-wiz-budget-loading">' + escapeHtml(t('loading_calc', 'Calculating…')) + '</span>');

                    $.post(luwipress.ajax_url, {
                        action: 'luwipress_estimate_batch_cost',
                        _wpnonce: $wizForm.find('input[name="_wpnonce"]').val(),
                        topic_count: effectiveTopicCount,
                        word_count: words,
                        depth: depth,
                        generate_image: generateImage
                    }, function(res) {
                        if (!res || !res.success) {
                            $body.html('<span class="sched-wiz-budget-err">' + escapeHtml(t('err_estimate', 'Unable to estimate.')) + '</span>');
                            return;
                        }
                        var d = res.data;
                        $provider.text(d.provider + ' · ' + d.model);
                        var parts = [
                            '<div class="sched-wiz-budget-total">$' + d.grand_total.toFixed(2) + '</div>',
                            '<div class="sched-wiz-budget-breakdown">' +
                                '<span>' + d.topic_count + ' × $' + d.per_topic.toFixed(4) + ' text</span>' +
                                (d.image_total > 0 ? '<span>+ $' + d.image_total.toFixed(2) + ' images</span>' : '') +
                            '</div>'
                        ];
                        $body.html(parts.join(''));
                    }).fail(function() {
                        $body.html('<span class="sched-wiz-budget-err">' + escapeHtml(t('err_estimate', 'Unable to estimate.')) + '</span>');
                    });
                }

                function flashStepError(n) {
                    var $step = $wizSteps.filter('[data-step="' + n + '"]');
                    $step.addClass('has-error');
                    setTimeout(function() { $step.removeClass('has-error'); }, 1200);
                }

                function validateStep(n) {
                    if (n === 1) {
                        var c = countTopics();
                        if (c === 0) {
                            markFieldError($wizTopics);
                            flashStepError(n);
                            notify(t('err_need_topic', 'Add at least one topic to continue.'), 'error');
                            $wizTopics.focus();
                            return false;
                        }
                        if (c > 50) {
                            markFieldError($wizTopics);
                            flashStepError(n);
                            notify(t('err_topic_limit', 'Maximum 50 topics per batch.'), 'error');
                            return false;
                        }
                        clearFieldError($wizTopics);
                    }
                    if (n === 3) {
                        var $date = $wizForm.find('input[name="start_date"]');
                        if (!$date.val()) {
                            markFieldError($date);
                            flashStepError(n);
                            notify(t('err_need_date', 'Pick a start date.'), 'error');
                            $date.focus();
                            return false;
                        }
                        clearFieldError($date);
                    }
                    return true;
                }

                function formatDate(dateStr, timeStr) {
                    if (!dateStr) return '';
                    try {
                        var dt = new Date(dateStr + 'T' + (timeStr || '09:00'));
                        if (isNaN(dt.getTime())) return dateStr + (timeStr ? ' ' + timeStr : '');
                        return dt.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                    } catch (e) { return dateStr; }
                }

                function renderSummary() {
                    var topics        = ($wizTopics.val() || '').split('\n').map(function(l) { return l.trim(); }).filter(Boolean);
                    var depth         = $wizForm.find('input[name="depth"]:checked').val() || 'standard';
                    var toneLabel     = $wizForm.find('select[name="tone"] option:selected').text();
                    var words         = $wizForm.find('input[name="word_count"]').val();
                    var langLabel     = $wizForm.find('select[name="language"] option:selected').text();
                    var postType      = $wizForm.find('select[name="target_post_type"] option:selected').text();
                    var generateImage = $wizForm.find('input[name="generate_image"]').is(':checked');
                    var startDate     = $wizForm.find('input[name="start_date"]').val();
                    var startTime     = $wizForm.find('input[name="start_time"]').val() || '09:00';
                    var intervalVal   = parseInt($wizForm.find('input[name="interval_value"]').val(), 10) || 1;
                    var intervalUnit  = $wizForm.find('select[name="interval_unit"]').val() || 'day';
                    var stagger       = parseInt($wizForm.find('input[name="generate_offset"]').val(), 10) || 0;

                    var isBulk = topics.length > 1;
                    var spacingMin = intervalVal * (intervalUnit === 'day' ? 1440 : 60);
                    var lastOffsetMin = (topics.length - 1) * spacingMin;
                    var endStr = '';
                    if (isBulk && startDate) {
                        var startDT = new Date(startDate + 'T' + startTime);
                        if (!isNaN(startDT.getTime())) {
                            var endDT = new Date(startDT.getTime() + lastOffsetMin * 60000);
                            endStr = endDT.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
                        }
                    }

                    var topicsPreview = topics.slice(0, 6).map(function(t) {
                        return '<li>' + escapeHtml(t) + '</li>';
                    }).join('');
                    if (topics.length > 6) {
                        topicsPreview += '<li class="sched-sum-more">+ ' + (topics.length - 6) + ' more</li>';
                    }

                    var publishMode = $wizForm.find('input[name="publish_mode"]:checked').val() || 'draft';
                    var modeLabel = publishMode === 'draft'
                        ? 'Save as draft for review'
                        : 'Auto-publish on schedule';

                    var extraLangsArr = $wizForm.find('input[name="additional_languages[]"]:checked').map(function() { return ($(this).val() || '').toUpperCase(); }).get();
                    var langSummary = langLabel;
                    if (extraLangsArr.length) {
                        langSummary = langLabel + ' + ' + extraLangsArr.join(', ') + ' (' + (1 + extraLangsArr.length) + '× per topic)';
                    }

                    var rows = [
                        ['Depth',            depth.charAt(0).toUpperCase() + depth.slice(1)],
                        ['Tone',             toneLabel],
                        ['Target length',    words + ' words'],
                        ['Language',         langSummary],
                        ['Post type',        postType],
                        ['Featured image',   generateImage ? 'Generated per post' : 'No'],
                        ['After generation', modeLabel],
                        ['First publish',    formatDate(startDate, startTime)]
                    ];
                    if (isBulk) {
                        rows.push(['Cadence', 'Every ' + intervalVal + ' ' + intervalUnit + (intervalVal === 1 ? '' : 's')]);
                        rows.push(['AI stagger', stagger + ' min between runs']);
                        if (endStr) rows.push(['Last publish', endStr]);
                    }
                    var rowsHtml = rows.map(function(r) {
                        return '<div class="sched-sum-row"><strong>' + escapeHtml(r[0]) + '</strong><span>' + escapeHtml(r[1]) + '</span></div>';
                    }).join('');

                    $wizSummary.html(
                        '<div class="sched-sum-top">' +
                            '<div class="sched-sum-num">' + topics.length + '</div>' +
                            '<div class="sched-sum-lbl">topic' + (topics.length !== 1 ? 's' : '') + ' to queue</div>' +
                        '</div>' +
                        '<div class="sched-sum-grid">' + rowsHtml + '</div>' +
                        '<div class="sched-sum-topics"><strong>Topics</strong><ol>' + topicsPreview + '</ol></div>'
                    );
                }

                $wizTopics.on('input', function() { clearFieldError($(this)); updateTopicCount(); });
                updateTopicCount();

                // Multilingual chips: hide primary language so it can't be picked twice
                function syncI18nChips() {
                    var primary = ($wizForm.find('select[name="language"]').val() || '').toLowerCase();
                    $('#sched-i18n-chips .sched-i18n-chip').each(function() {
                        var $chip = $(this);
                        var code = ($chip.data('lang') || '').toString().toLowerCase();
                        if (code === primary) {
                            $chip.prop('hidden', true);
                            $chip.find('input').prop('checked', false);
                        } else {
                            $chip.prop('hidden', false);
                        }
                    });
                }
                $wizForm.find('select[name="language"]').on('change', syncI18nChips);
                syncI18nChips();

                $wizNext.on('click', function() {
                    if (!validateStep(currentStep)) return;
                    showStep(currentStep + 1);
                });
                $wizBack.on('click', function() { showStep(currentStep - 1); });

                $wizSteps.on('click keydown', function(e) {
                    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
                    if (e.type === 'keydown') e.preventDefault();
                    var target = parseInt($(this).attr('data-step'), 10);
                    if (target < currentStep) { showStep(target); return; }
                    if (target > currentStep) {
                        var ok = true;
                        for (var s = currentStep; s < target; s++) {
                            if (!validateStep(s)) { ok = false; break; }
                        }
                        if (ok) showStep(target);
                    }
                });

                $wizForm.on('submit', function(e) {
                    e.preventDefault();
                    if (!validateStep(1)) { showStep(1); return; }

                    $wizSubmit.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + escapeHtml(t('loading_queuing', 'Queuing…')));
                    $wizResult.empty();

                    $.ajax({
                        url: luwipress.ajax_url,
                        type: 'POST',
                        data: $wizForm.serialize(),
                        success: function(res) {
                            if (res && res.success) {
                                var d = res.data || {};
                                var queued = d.queued || 0;
                                var msg = d.skipped
                                    ? tf('ok_queued_with_skip', 'Queued %1$d topic(s) · %2$d skipped.', queued, d.skipped)
                                    : tf('ok_queued', 'Queued %d topic(s).', queued);
                                notify(msg + ' ' + t('ok_refreshing', 'Refreshing…'), 'success');
                                setTimeout(function() { location.reload(); }, 1400);
                            } else {
                                var errMsg = (res && res.data) ? res.data : t('err_generic', 'Error');
                                notify(errMsg, 'error');
                                $wizSubmit.prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> ' + escapeHtml(t('lbl_queue_all', 'Queue all topics')));
                            }
                        },
                        error: function() {
                            notify(t('err_request_failed', 'Request failed.'), 'error');
                            $wizSubmit.prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> ' + escapeHtml(t('lbl_queue_all', 'Queue all topics')));
                        }
                    });
                });

                // ──────── Brainstorm ────────
                var $brainToggle = $('#sched-brainstorm-toggle');
                var $brainPanel  = $('#sched-brainstorm');
                var $brainRun    = $('#sched-brainstorm-run');
                var $brainResults= $('#sched-brainstorm-results');

                $brainToggle.on('click', function() {
                    var open = $brainPanel.is(':visible');
                    $brainPanel.prop('hidden', open).toggle(!open);
                    if (!open) $('#sched-brainstorm-theme').focus();
                });

                $brainRun.on('click', function() {
                    var theme = ($('#sched-brainstorm-theme').val() || '').trim();
                    var count = parseInt($('#sched-brainstorm-count').val(), 10) || 10;
                    var style = ($('#sched-brainstorm-style').val() || '').trim();
                    var lang  = $wizForm.find('select[name="language"]').val() || 'en';
                    if (!theme) {
                        markFieldError($('#sched-brainstorm-theme'));
                        notify(t('err_need_theme', 'Please enter a theme.'), 'error');
                        return;
                    }
                    clearFieldError($('#sched-brainstorm-theme'));

                    $brainRun.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + escapeHtml(t('loading_thinking', 'Thinking…')));
                    $brainResults.html('<div class="sched-brainstorm-loading"><span class="dashicons dashicons-update spin"></span> ' + escapeHtml(t('loading_ideas', 'Generating ideas…')) + '</div>');

                    $.post(luwipress.ajax_url, {
                        action: 'luwipress_brainstorm_topics',
                        _wpnonce: $wizForm.find('input[name="_wpnonce"]').val(),
                        theme: theme, count: count, style_hint: style, language: lang
                    }, function(res) {
                        $brainRun.prop('disabled', false).html('<span class="dashicons dashicons-admin-customizer"></span> ' + escapeHtml(t('lbl_generate_ideas', 'Generate ideas')));
                        if (!res || !res.success) {
                            $brainResults.html('<div class="sched-brainstorm-err">' + escapeHtml((res && res.data) || t('err_brainstorm', 'Brainstorm failed.')) + '</div>');
                            return;
                        }
                        var topics = (res.data && res.data.topics) || [];
                        if (!topics.length) {
                            $brainResults.html('<div class="sched-brainstorm-err">' + escapeHtml(t('err_brainstorm_empty', 'No topics returned.')) + '</div>');
                            return;
                        }
                        var html = '<div class="sched-brainstorm-picks-head">'
                                 + '<label><input type="checkbox" class="sched-brainstorm-all" checked> ' + escapeHtml(tf('lbl_select_all', 'Select all (%d)', topics.length)) + '</label>'
                                 + '<button type="button" class="button button-primary sched-brainstorm-add"><span class="dashicons dashicons-plus-alt"></span> ' + escapeHtml(t('lbl_add_picked', 'Add picked to queue')) + '</button>'
                                 + '</div><ul class="sched-brainstorm-picks">';
                        topics.forEach(function(t) {
                            var depthTag = t.depth ? '<span class="sched-brainstorm-depth">' + escapeHtml(t.depth) + '</span>' : '';
                            var pipe = t.depth ? (' | depth=' + t.depth) : '';
                            html += '<li>'
                                 +   '<label>'
                                 +     '<input type="checkbox" class="sched-brainstorm-pick" checked data-title="' + escapeHtml(t.title) + '" data-pipe="' + escapeHtml(pipe) + '">'
                                 +     '<span class="sched-brainstorm-title">' + escapeHtml(t.title) + '</span>'
                                 +     depthTag
                                 +     (t.angle ? '<span class="sched-brainstorm-angle">' + escapeHtml(t.angle) + '</span>' : '')
                                 +   '</label>'
                                 + '</li>';
                        });
                        html += '</ul>';
                        $brainResults.html(html);
                    }).fail(function() {
                        $brainRun.prop('disabled', false).html('<span class="dashicons dashicons-admin-customizer"></span> ' + escapeHtml(t('lbl_generate_ideas', 'Generate ideas')));
                        $brainResults.html('<div class="sched-brainstorm-err">' + escapeHtml(t('err_request_failed', 'Request failed.')) + '</div>');
                    });
                });

                $brainResults.on('change', '.sched-brainstorm-all', function() {
                    $brainResults.find('.sched-brainstorm-pick').prop('checked', $(this).is(':checked'));
                });
                $brainResults.on('click', '.sched-brainstorm-add', function() {
                    var picked = $brainResults.find('.sched-brainstorm-pick:checked');
                    if (!picked.length) { notify(t('err_pick_topic', 'Pick at least one topic.'), 'error'); return; }
                    var lines = picked.map(function() {
                        return ($(this).data('title') || '') + ($(this).data('pipe') || '');
                    }).get();
                    var existing = ($wizTopics.val() || '').trim();
                    var merged = (existing ? existing + '\n' : '') + lines.join('\n');
                    $wizTopics.val(merged).trigger('input');
                    $brainResults.empty();
                    $brainPanel.hide().prop('hidden', true);
                    try { $wizTopics[0].scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
                });

                showStep(1);
            }

            // ──────── Queue list interactions ────────
            var $bulkToolbar = $('#sched-bulk-toolbar');
            var $bulkCount = $('#sched-bulk-toolbar-count');
            var $bulkAll = $('#sched-bulk-all');
            var nonce = $('#sched-wizard-form input[name="_wpnonce"]').val() || '';

            function refreshBulkToolbar() {
                var $picked = $('.sched-item-check:checked');
                var n = $picked.length;
                $bulkToolbar.prop('hidden', n === 0);
                $bulkCount.text(n + ' selected');
                var hasPublishable = false;
                $picked.each(function() {
                    var $row = $(this).closest('.sched-item');
                    if ($row.attr('data-post-status') === 'draft') hasPublishable = true;
                });
                $('.sched-bulk-run[data-bulk="publish"]').prop('disabled', !hasPublishable);
                var total = $('.sched-item-check:visible').length;
                $bulkAll.prop('checked', total > 0 && n === total).prop('indeterminate', n > 0 && n < total);
            }

            $(document).on('change', '.sched-item-check', refreshBulkToolbar);
            $bulkAll.on('change', function() {
                $('.sched-item-check:visible').prop('checked', $(this).is(':checked'));
                refreshBulkToolbar();
            });

            // Status sub-tabs (single combined handler — no duplicate)
            $('#sched-queue-filters .sched-subtab').on('click', function() {
                var f = $(this).attr('data-filter');
                $('#sched-queue-filters .sched-subtab').removeClass('is-active').attr('aria-selected', 'false');
                $(this).addClass('is-active').attr('aria-selected', 'true');
                $('#sched-list .sched-item').each(function() {
                    var s = $(this).attr('data-status');
                    $(this).toggle(f === 'all' || s === f);
                });
                refreshBulkToolbar();
            });

            // Bulk actions
            $('.sched-bulk-run').on('click', function() {
                var $btn = $(this);
                var op = $btn.data('bulk');
                var ids = $('.sched-item-check:checked').map(function() { return $(this).data('id'); }).get();
                if (!ids.length) return;

                var msg = {
                    publish: tf('confirm_bulk_publish', 'Publish %d selected draft(s)?', ids.length),
                    retry:   tf('confirm_bulk_retry', 'Retry %d selected item(s)?', ids.length),
                    'delete':tf('confirm_bulk_delete', 'Delete %d selected item(s)?', ids.length)
                }[op];
                var titleKey = { publish: 'title_confirm_bulk_pub', retry: 'title_confirm_bulk_retry', 'delete': 'title_confirm_bulk_del' }[op];
                var variant = op === 'delete' ? 'danger' : 'info';
                schedConfirm({
                    message: msg,
                    title: t(titleKey, 'Are you sure?'),
                    variant: variant,
                    okLabel: op === 'delete' ? t('lbl_confirm_delete', 'Delete') : t('lbl_confirm', 'Confirm')
                }).then(function(ok) {
                    if (!ok) return;

                    $('.sched-bulk-run').prop('disabled', true);
                    $btn.html('<span class="dashicons dashicons-update spin"></span>');

                    $.post(luwipress.ajax_url, {
                        action: 'luwipress_bulk_schedule_action',
                        bulk_action: op, ids: ids, _wpnonce: nonce
                    }, function(res) {
                        if (res && res.success) {
                            location.reload();
                        } else {
                            notify((res && res.data) ? res.data : t('err_bulk_failed', 'Bulk action failed.'), 'error');
                            $('.sched-bulk-run').prop('disabled', false);
                        }
                    });
                });
            });

            // Delete queue item
            $(document).on('click', '.sched-delete', function() {
                var $btn = $(this);
                schedConfirm({
                    message: t('confirm_delete_item', 'Delete this scheduled item?'),
                    title: t('title_confirm_delete', 'Delete item'),
                    variant: 'danger',
                    okLabel: t('lbl_confirm_delete', 'Delete')
                }).then(function(ok) {
                    if (!ok) return;
                    var $item = $btn.closest('.sched-item');
                    var id = $btn.data('id');
                    $.post(luwipress.ajax_url, {
                        action: 'luwipress_delete_schedule', schedule_id: id, _wpnonce: nonce
                    }, function(res) {
                        if (res && res.success) {
                            $item.css({ opacity: 0, transform: 'translateX(20px)' });
                            setTimeout(function() { $item.remove(); refreshBulkToolbar(); }, 300);
                        } else {
                            notify((res && res.data) ? res.data : t('err_generic', 'Error'), 'error');
                        }
                    });
                });
            });

            // Enrich draft
            $(document).on('click', '.sched-enrich', function() {
                var $btn = $(this);
                var id = $btn.data('id');
                var $item = $btn.closest('.sched-item');
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');
                $.post(luwipress.ajax_url, {
                    action: 'luwipress_enrich_schedule_draft', schedule_id: id, _wpnonce: nonce
                }, function(res) {
                    if (res && res.success) {
                        var summary = res.data.summary || 'Enrichment complete';
                        $btn.html('<span class="dashicons dashicons-yes"></span>');
                        $btn.attr('title', summary);
                        var $hint = $('<div class="sched-enrich-toast">' + escapeHtml(summary) + '</div>');
                        $item.append($hint);
                        setTimeout(function() {
                            $hint.addClass('is-hiding');
                            setTimeout(function() { $hint.remove(); }, 400);
                        }, 3500);
                        setTimeout(function() {
                            $btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-customizer"></span> ' + escapeHtml(t('lbl_enrich', 'Enrich')));
                        }, 1000);
                    } else {
                        notify((res && res.data) ? res.data : t('err_enrich_failed', 'Enrich failed.'), 'error');
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-customizer"></span> ' + escapeHtml(t('lbl_enrich', 'Enrich')));
                    }
                }).fail(function() {
                    notify(t('err_request_failed', 'Request failed.'), 'error');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-customizer"></span> ' + escapeHtml(t('lbl_enrich', 'Enrich')));
                });
            });

            // Retry failed queue item
            $(document).on('click', '.sched-retry', function() {
                var $btn = $(this);
                var id = $btn.data('id');
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');
                $.post(luwipress.ajax_url, {
                    action: 'luwipress_retry_schedule', schedule_id: id, _wpnonce: nonce
                }, function(res) {
                    if (res && res.success) {
                        location.reload();
                    } else {
                        notify((res && res.data) ? res.data : t('err_retry_failed', 'Retry failed.'), 'error');
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> ' + escapeHtml(t('lbl_retry', 'Retry')));
                    }
                });
            });

            // Run pending now
            $('#sched-run-now').on('click', function() {
                var $btn = $(this);
                schedConfirm({
                    message: t('confirm_run_pending', 'Run up to 10 pending items now?'),
                    title: t('title_confirm_run_now', 'Run pending now'),
                    variant: 'info',
                    okLabel: t('lbl_run_pending', 'Run pending now')
                }).then(function(ok) {
                    if (!ok) return;
                    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + escapeHtml(t('loading_running', 'Running…')));
                    $.post(luwipress.ajax_url, {
                        action: 'luwipress_run_pending_now', _wpnonce: nonce
                    }, function(res) {
                        if (res && res.success) {
                            notify(tf('ok_processed', 'Processed %d item(s).', res.data.processed) + ' ' + t('ok_refreshing', 'Refreshing…'), 'success');
                            setTimeout(function() { location.reload(); }, 1200);
                        } else {
                            notify((res && res.data) ? res.data : t('err_generic', 'Error'), 'error');
                            $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> ' + escapeHtml(t('lbl_run_pending', 'Run pending now')));
                        }
                    });
                });
            });

            // ──────── Delta polling (in-place row updates, no reload) ────────
            var $outlineModal = $('#sched-outline-modal');
            var pollTimer = null;
            var pollInterval = 20000;
            var pollSince = Math.floor(Date.now() / 1000) - 60;
            var statusConfig = {
                pending:         { accent: 'muted',   icon: 'clock',                lbl: 'Pending' },
                generating:      { accent: 'warning', icon: 'update',               lbl: 'Generating' },
                outline_pending: { accent: 'info',    icon: 'welcome-write-blog',   lbl: 'Outline review' },
                ready:           { accent: 'info',    icon: 'yes-alt',              lbl: 'Ready' },
                published:       { accent: 'success', icon: 'admin-post',           lbl: 'Published' },
                failed:          { accent: 'error',   icon: 'dismiss',              lbl: 'Failed' }
            };

            function shouldPoll() {
                if (document.hidden) return false;
                if ($outlineModal.is(':visible') && !$outlineModal.prop('hidden')) return false;
                if ($('#sched-plan-modal').is(':visible') && !$('#sched-plan-modal').prop('hidden')) return false;
                if (!$confirmModal.prop('hidden')) return false;
                return $('.sched-item[data-status="generating"], .sched-item[data-status="outline_pending"]').length > 0;
            }

            function applyDelta(items) {
                if (!items || !items.length) return;
                var changedStatusToTerminal = false;
                items.forEach(function(row) {
                    var $row = $('.sched-item[data-id="' + row.id + '"]');
                    if (!$row.length) return;
                    var oldStatus = $row.attr('data-status');
                    if (oldStatus === row.status && $row.attr('data-post-status') === (row.post_status || '')) return;

                    $row.attr('data-status', row.status);
                    $row.attr('data-post-status', row.is_draft ? 'draft' : (row.published_id ? 'publish' : ''));
                    $row.toggleClass('sched-pulse', row.status === 'generating');

                    // Update status badge icon + accent
                    var cfg = statusConfig[row.status] || statusConfig.pending;
                    var $badge = $row.find('.sched-item-status');
                    $badge.removeClass('sched-item-status--muted sched-item-status--warning sched-item-status--info sched-item-status--success sched-item-status--error')
                          .addClass('sched-item-status--' + cfg.accent)
                          .attr('title', cfg.lbl);
                    $badge.find('.dashicons').attr('class', 'dashicons dashicons-' + cfg.icon);

                    // Error band
                    if (row.error) {
                        var $err = $row.find('.sched-error');
                        if (!$err.length) {
                            $row.find('.sched-item-body').append(
                                '<div class="sched-error"><span class="dashicons dashicons-warning"></span> ' + escapeHtml(row.error) + '</div>'
                            );
                        } else {
                            $err.find(':not(.dashicons)').text(' ' + row.error);
                        }
                    } else {
                        $row.find('.sched-error').remove();
                    }

                    // Terminal status change → user-visible notification once
                    if (['ready', 'published', 'outline_pending', 'failed'].indexOf(row.status) !== -1) {
                        changedStatusToTerminal = true;
                    }
                });
                if (changedStatusToTerminal) {
                    // Refresh sub-tab counts + surface new actions by doing a soft reload only when nothing is being edited.
                    // Simpler: just surface a toast prompting the user to refresh for fresh action buttons if they care.
                    // For now, action buttons require a reload to re-render server-side — flag it subtly.
                    if (!$('.sched-item-check:checked').length) {
                        // No selections mid-flight — safe to reload in background to pick up new action buttons.
                        // But we avoid hijacking scroll: only reload if user is at/near top.
                        if (window.scrollY < 200) location.reload();
                    }
                }
            }

            function tick() {
                if (!shouldPoll()) { scheduleNext(); return; }
                var activeIds = $('.sched-item[data-status="generating"], .sched-item[data-status="outline_pending"]')
                    .map(function() { return $(this).data('id'); }).get();
                $.ajax({
                    url: (luwipress.rest_base || '/wp-json/luwipress/v1/') + 'schedule/delta',
                    method: 'GET',
                    data: { since: pollSince, ids: activeIds.join(',') },
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', luwipress.nonce_rest || '');
                    },
                    success: function(res) {
                        if (res && res.items) applyDelta(res.items);
                        if (res && res.now) pollSince = res.now;
                    },
                    complete: scheduleNext
                });
            }

            function scheduleNext() {
                if (pollTimer) clearTimeout(pollTimer);
                pollTimer = setTimeout(tick, pollInterval);
            }

            if ($('.sched-item[data-status="generating"], .sched-item[data-status="outline_pending"]').length) {
                scheduleNext();
                document.addEventListener('visibilitychange', function() {
                    if (!document.hidden) scheduleNext();
                });
            }

            // ──────── Outline modal ────────
            var $outlineBody  = $('#sched-outline-modal-body');
            var $outlineTopic = $('#sched-outline-modal-topic');
            var currentOutlineId = 0;

            var outlinePrevFocus = null;
            function outlineClose() {
                $outlineModal.prop('hidden', true).attr('aria-hidden', 'true');
                focusTrapRelease();
                if (outlinePrevFocus && outlinePrevFocus.focus) {
                    try { outlinePrevFocus.focus(); } catch (e) {}
                }
                currentOutlineId = 0;
            }

            function outlineSectionHtml(sec, i) {
                var heading = (sec && sec.heading) || '';
                var points = (sec && sec.points) ? sec.points.join('\n') : '';
                return '<li class="sched-outline-section" data-idx="' + i + '">'
                     +   '<div class="sched-outline-section-bar">'
                     +     '<span class="sched-outline-section-num">' + (i + 1) + '</span>'
                     +     '<input type="text" class="sched-outline-heading" value="' + escapeHtml(heading) + '" placeholder="H2 heading — specific, advances the topic">'
                     +     '<button type="button" class="sched-outline-section-del" aria-label="Remove section"><span class="dashicons dashicons-trash"></span></button>'
                     +   '</div>'
                     +   '<textarea class="sched-outline-points" rows="3" placeholder="One concrete point per line.">' + escapeHtml(points) + '</textarea>'
                     + '</li>';
            }

            function outlineRender(outline) {
                if (!outline || typeof outline !== 'object') outline = {};
                var sections = (outline.sections && outline.sections.length) ? outline.sections : [{ heading: '', points: [''] }];
                var faq = outline.faq && outline.faq.length ? outline.faq : [];

                var html = '<div class="sched-outline-grid">'
                         + '<div class="sched-outline-field sched-outline-field--full">'
                         +   '<label>Title</label>'
                         +   '<input type="text" class="sched-outline-title" value="' + escapeHtml(outline.title || '') + '" placeholder="45-65 char, specific">'
                         + '</div>'
                         + '<div class="sched-outline-field sched-outline-field--full">'
                         +   '<label>Hook (opening idea)</label>'
                         +   '<textarea class="sched-outline-hook" rows="2" placeholder="1-2 sentence opening idea">' + escapeHtml(outline.hook || '') + '</textarea>'
                         + '</div>'
                         + '</div>'
                         + '<div class="sched-outline-sections-wrap">'
                         +   '<div class="sched-outline-sections-head"><h4>Sections</h4><button type="button" class="button button-small sched-outline-section-add"><span class="dashicons dashicons-plus-alt"></span> Add section</button></div>'
                         +   '<ol class="sched-outline-sections" id="sched-outline-sections">';
                sections.forEach(function(sec, i) { html += outlineSectionHtml(sec, i); });
                html += '  </ol></div>'
                     + '<div class="sched-outline-field sched-outline-field--full">'
                     +   '<label>FAQ questions (one per line — leave blank to skip FAQ)</label>'
                     +   '<textarea class="sched-outline-faq" rows="4">' + escapeHtml(faq.join('\n')) + '</textarea>'
                     + '</div>'
                     + '<div class="sched-outline-field sched-outline-field--full">'
                     +   '<label>Closing approach</label>'
                     +   '<textarea class="sched-outline-closing" rows="2">' + escapeHtml(outline.closing_approach || '') + '</textarea>'
                     + '</div>';
                $outlineBody.html(html);
            }

            function outlineCollect() {
                var sections = [];
                $('#sched-outline-sections .sched-outline-section').each(function() {
                    var heading = ($(this).find('.sched-outline-heading').val() || '').trim();
                    if (!heading) return;
                    var points = ($(this).find('.sched-outline-points').val() || '')
                        .split('\n').map(function(s) { return s.trim(); }).filter(Boolean);
                    sections.push({ heading: heading, points: points });
                });
                var faq = ($outlineBody.find('.sched-outline-faq').val() || '')
                    .split('\n').map(function(s) { return s.trim(); }).filter(Boolean);
                return {
                    title:            ($outlineBody.find('.sched-outline-title').val() || '').trim(),
                    hook:             ($outlineBody.find('.sched-outline-hook').val() || '').trim(),
                    sections:         sections,
                    faq:              faq,
                    closing_approach: ($outlineBody.find('.sched-outline-closing').val() || '').trim()
                };
            }

            $(document).on('click', '.sched-outline-open', function() {
                var id = $(this).data('id');
                currentOutlineId = id;
                outlinePrevFocus = document.activeElement;
                $outlineModal.prop('hidden', false).attr('aria-hidden', 'false');
                focusTrapActivate($outlineModal);
                $outlineBody.html('<div class="sched-outline-loading"><span class="dashicons dashicons-update spin"></span> ' + escapeHtml(t('loading_outline', 'Loading outline…')) + '</div>');
                $outlineTopic.text('');

                $.post(luwipress.ajax_url, {
                    action: 'luwipress_get_outline', _wpnonce: nonce, schedule_id: id
                }, function(res) {
                    if (!res || !res.success) {
                        $outlineBody.html('<div class="sched-outline-err">' + escapeHtml((res && res.data) || t('err_outline_load', 'Could not load outline.')) + '</div>');
                        return;
                    }
                    $outlineTopic.text(res.data.topic + '  ·  ' + (res.data.depth || '').toUpperCase() + '  ·  ~' + (res.data.word_count || 0) + ' words');
                    outlineRender(res.data.outline);
                }).fail(function() {
                    $outlineBody.html('<div class="sched-outline-err">' + escapeHtml(t('err_request_failed', 'Request failed.')) + '</div>');
                });
            });

            $outlineModal.on('click', '[data-close="1"]', outlineClose);
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    // Topmost-first: confirm > outline > plan
                    if (!$confirmModal.prop('hidden')) { confirmClose(false); return; }
                    if ($outlineModal.is(':visible') && !$outlineModal.prop('hidden')) outlineClose();
                    if ($('#sched-plan-modal').is(':visible') && !$('#sched-plan-modal').prop('hidden')) planModalClose();
                }
            });

            $outlineBody.on('click', '.sched-outline-section-add', function() {
                var nextIdx = $('#sched-outline-sections .sched-outline-section').length;
                $('#sched-outline-sections').append(outlineSectionHtml({ heading: '', points: [''] }, nextIdx));
            });

            $outlineBody.on('click', '.sched-outline-section-del', function() {
                if ($('#sched-outline-sections .sched-outline-section').length <= 1) {
                    notify(t('err_keep_one_section', 'Keep at least one section.'), 'warning');
                    return;
                }
                $(this).closest('.sched-outline-section').remove();
                $('#sched-outline-sections .sched-outline-section').each(function(i) {
                    $(this).find('.sched-outline-section-num').text(i + 1);
                });
            });

            $outlineModal.on('click', '.sched-outline-approve', function() {
                var outline = outlineCollect();
                if (!outline.title) { notify(t('err_need_title', 'Give the article a title first.'), 'error'); return; }
                if (!outline.sections.length) { notify(t('err_need_section', 'Outline needs at least one section.'), 'error'); return; }

                var $btn = $(this);
                var $regen = $outlineModal.find('.sched-outline-regen');
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + escapeHtml(t('loading_queuing', 'Queuing…')));
                $regen.prop('disabled', true);

                $.post(luwipress.ajax_url, {
                    action: 'luwipress_save_outline', _wpnonce: nonce,
                    schedule_id: currentOutlineId, outline: JSON.stringify(outline)
                }, function(res) {
                    if (res && res.success) {
                        outlineClose();
                        location.reload();
                    } else {
                        notify((res && res.data) ? res.data : t('err_save_failed', 'Save failed.'), 'error');
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> ' + escapeHtml(t('lbl_approve_generate', 'Approve & generate article')));
                        $regen.prop('disabled', false);
                    }
                }).fail(function() {
                    notify(t('err_request_failed', 'Request failed.'), 'error');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> ' + escapeHtml(t('lbl_approve_generate', 'Approve & generate article')));
                    $regen.prop('disabled', false);
                });
            });

            $outlineModal.on('click', '.sched-outline-regen', function() {
                var $btn = $(this);
                var $approve = $outlineModal.find('.sched-outline-approve');
                schedConfirm({
                    message: t('confirm_regen_outline', 'Discard this outline and regenerate?'),
                    title: t('title_confirm_regen', 'Regenerate outline'),
                    variant: 'warning'
                }).then(function(ok) {
                    if (!ok) return;
                    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + escapeHtml(t('loading_regen', 'Regenerating…')));
                    $approve.prop('disabled', true);
                    $.post(luwipress.ajax_url, {
                        action: 'luwipress_regenerate_outline', _wpnonce: nonce, schedule_id: currentOutlineId
                    }, function(res) {
                        if (res && res.success) {
                            outlineClose();
                            location.reload();
                        } else {
                            notify((res && res.data) ? res.data : t('err_regen_failed', 'Regenerate failed.'), 'error');
                            $btn.prop('disabled', false).html('<span class="dashicons dashicons-image-rotate"></span> ' + escapeHtml(t('lbl_regen_outline', 'Regenerate outline')));
                            $approve.prop('disabled', false);
                        }
                    });
                });
            });

            // ──────── Recurring plans ────────
            var $planModal = $('#sched-plan-modal');
            var $planForm  = $('#sched-plan-form');

            function planModalOpen(plan) {
                $planForm[0].reset();
                $planForm.find('input[name="plan_id"]').val('');
                if (plan && typeof plan === 'object') {
                    $planForm.find('input[name="plan_id"]').val(plan.id || '');
                    $planForm.find('input[name="name"]').val(plan.name || '');
                    $planForm.find('input[name="theme"]').val(plan.theme || '');
                    $planForm.find('input[name="style_hint"]').val(plan.style_hint || '');
                    $planForm.find('select[name="cadence"]').val(plan.cadence || 'weekly');
                    $planForm.find('input[name="count"]').val(plan.count || 3);
                    $planForm.find('select[name="depth"]').val(plan.depth || 'standard');
                    $planForm.find('input[name="word_count"]').val(plan.word_count || 1500);
                    $planForm.find('select[name="tone"]').val(plan.tone || 'informative');
                    $planForm.find('select[name="language"]').val(plan.language || 'en');
                    $planForm.find('select[name="target_post_type"]').val(plan.target_post_type || 'post');
                    $planForm.find('select[name="publish_mode"]').val(plan.publish_mode || 'draft');
                    $planForm.find('input[name="generate_image"]').prop('checked', !!plan.generate_image);
                    $('#sched-plan-modal-title').text('Edit recurring plan');
                } else {
                    $('#sched-plan-modal-title').text('New recurring plan');
                }
                planPrevFocus = document.activeElement;
                $planModal.prop('hidden', false).attr('aria-hidden', 'false');
                focusTrapActivate($planModal);
                setTimeout(function() { $planForm.find('input[name="name"]').focus(); }, 50);
            }
            var planPrevFocus = null;
            function planModalClose() {
                $planModal.prop('hidden', true).attr('aria-hidden', 'true');
                focusTrapRelease();
                if (planPrevFocus && planPrevFocus.focus) {
                    try { planPrevFocus.focus(); } catch (e) {}
                }
            }

            $('#sched-plan-new, #sched-plan-new-empty').on('click', function() { planModalOpen(null); });
            $planModal.on('click', '[data-close="1"]', planModalClose);
            $(document).on('click', '.sched-plan-edit', function() {
                var raw = $(this).attr('data-plan') || '{}';
                var plan;
                try { plan = JSON.parse(raw); } catch (e) { plan = null; }
                planModalOpen(plan);
            });

            $planModal.on('click', '.sched-plan-save', function() {
                var $btn = $(this);
                var name = ($planForm.find('input[name="name"]').val() || '').trim();
                var theme = ($planForm.find('input[name="theme"]').val() || '').trim();
                if (!name) {
                    markFieldError($planForm.find('input[name="name"]'));
                    notify(t('err_plan_required', 'Name and theme are required.'), 'error');
                    return;
                }
                if (!theme) {
                    markFieldError($planForm.find('input[name="theme"]'));
                    notify(t('err_plan_required', 'Name and theme are required.'), 'error');
                    return;
                }
                var data = $planForm.serializeArray();
                data.push({ name: 'action', value: 'luwipress_save_recurring_plan' });
                data.push({ name: '_wpnonce', value: nonce });

                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + escapeHtml(t('loading_saving', 'Saving…')));
                $.post(luwipress.ajax_url, data, function(res) {
                    if (res && res.success) {
                        planModalClose();
                        location.reload();
                    } else {
                        notify((res && res.data) ? res.data : t('err_save_failed', 'Save failed.'), 'error');
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> ' + escapeHtml(t('lbl_save_plan', 'Save plan')));
                    }
                });
            });

            function markFieldError($f) {
                var $w = $f.closest('.sched-field'); $w.addClass('has-error');
                $f.one('input change', function() { $w.removeClass('has-error'); });
            }

            $(document).on('click', '.sched-plan-toggle', function() {
                var id = $(this).data('id');
                $.post(luwipress.ajax_url, {
                    action: 'luwipress_toggle_recurring_plan', plan_id: id, _wpnonce: nonce
                }, function(res) {
                    if (res && res.success) location.reload();
                });
            });
            $(document).on('click', '.sched-plan-delete', function() {
                var id = $(this).data('id');
                schedConfirm({
                    message: t('confirm_delete_plan', 'Delete this recurring plan?'),
                    title: t('title_confirm_plan_del', 'Delete recurring plan'),
                    variant: 'danger',
                    okLabel: t('lbl_confirm_delete', 'Delete')
                }).then(function(ok) {
                    if (!ok) return;
                    $.post(luwipress.ajax_url, {
                        action: 'luwipress_delete_recurring_plan', plan_id: id, _wpnonce: nonce
                    }, function(res) {
                        if (res && res.success) location.reload();
                    });
                });
            });
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
        // Copy-to-clipboard helper (endpoint URLs)
        // ========================================
        $(document).on('click', '.luwipress-copy-btn', function() {
            var text = $(this).data('copy');
            if (!text) return;
            var $btn = $(this);
            var original = $btn.html();
            var done = function() {
                $btn.html('<span class="dashicons dashicons-yes"></span> Copied');
                setTimeout(function(){ $btn.html(original); }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                var $t = $('<textarea>').val(text).appendTo('body').select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                $t.remove();
            }
        });

        // ========================================
        // REST API health check (hits /health with current token)
        // ========================================
        $('#luwipress-rest-health-check').on('click', function() {
            var $btn = $(this);
            var $result = $('#luwipress-rest-health-result');
            var token = $('#luwipress_api_token').val();
            if (!token) {
                $result.html('<span style="color:#dc2626;">Save a token first, then re-test.</span>');
                return;
            }

            $btn.prop('disabled', true).find('.dashicons').addClass('spin');
            $result.html('<span style="color:#6b7280;">Pinging /health...</span>');

            var restBase = (window.luwipress && window.luwipress.rest_base) || '/wp-json/luwipress/v1/';
            fetch(restBase + 'health', {
                headers: {
                    'Authorization': 'Bearer ' + token,
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            }).then(function(r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            }).then(function(data) {
                var ok = data && (data.status === 'ok' || data.status === 'healthy' || data.success === true || data.plugin);
                if (ok) {
                    $result.html('<span style="color:#16a34a;">&#10003; Healthy &mdash; ' + (data.plugin || 'LuwiPress') + ' ' + (data.version || '') + '</span>');
                } else {
                    $result.html('<span style="color:#dc2626;">&#10007; Unexpected response</span>');
                }
            }).catch(function(err) {
                $result.html('<span style="color:#dc2626;">&#10007; ' + err.message + '</span>');
            }).finally(function() {
                $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
            });
        });

        // ========================================
        // MCP tools/list ping (verifies WebMCP companion)
        // ========================================
        $('#luwipress-mcp-ping').on('click', function() {
            var $btn = $(this);
            var $result = $('#luwipress-mcp-ping-result');
            var token = $('#luwipress_api_token').val();
            if (!token) {
                $result.html('<span style="color:#dc2626;">Save a token first, then re-test.</span>');
                return;
            }

            $btn.prop('disabled', true).find('.dashicons').addClass('spin');
            $result.html('<span style="color:#6b7280;">Calling tools/list...</span>');

            var restBase = (window.luwipress && window.luwipress.rest_base) || '/wp-json/luwipress/v1/';
            var endpoint = restBase + 'mcp';

            var countTools = function(cursor, total) {
                return fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json, text/event-stream'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        jsonrpc: '2.0',
                        id: 'ping-' + Date.now(),
                        method: 'tools/list',
                        params: cursor ? { cursor: cursor } : {}
                    })
                }).then(function(r) {
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.json();
                }).then(function(data) {
                    if (data.error) { throw new Error(data.error.message || 'MCP error'); }
                    var tools = (data.result && data.result.tools) || [];
                    var next  = data.result && data.result.nextCursor;
                    var sum   = total + tools.length;
                    if (next) { return countTools(next, sum); }
                    return sum;
                });
            };

            countTools(null, 0).then(function(total) {
                $result.html('<span style="color:#16a34a;">&#10003; ' + total + ' tools registered</span>');
            }).catch(function(err) {
                $result.html('<span style="color:#dc2626;">&#10007; ' + err.message + '</span>');
            }).finally(function() {
                $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
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
