/**
 * EventKoi Ticket Importer – Admin JS
 */
(function ($) {
    'use strict';

    var totalAttendees = 0;

    /* ------------------------------------------------------------------ */
    /*  HELPERS                                                           */
    /* ------------------------------------------------------------------ */

    function ajax(action, data, cb) {
        data = data || {};
        data.action = action;
        data.nonce  = EKTI.nonce;
        $.post(EKTI.ajax_url, data, function (resp) {
            cb(resp);
        }).fail(function (xhr) {
            console.error('AJAX error', xhr);
            cb({ success: false, data: 'AJAX request failed' });
        });
    }

    function appendConsole(msg, type) {
        var cls = '';
        if (type === 'error') cls = 'ekti-log-error';
        else if (type === 'warn') cls = 'ekti-log-warn';
        else if (type === 'info') cls = 'ekti-log-info';
        var $c = $('#ekti-console');
        $c.append('<span class="' + cls + '">' + escapeHtml(msg) + '\n</span>');
        $c.scrollTop($c[0].scrollHeight);
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setStatus(el, msg, type) {
        type = type || 'info';
        $(el).html('<div class="notice notice-' + type + '"><p>' + msg + '</p></div>');
    }

    /* ------------------------------------------------------------------ */
    /*  STATS                                                             */
    /* ------------------------------------------------------------------ */

    function loadStats() {
        appendConsole('Loading statistics...', 'info');
        ajax('ekti_get_stats', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Error loading stats: ' + resp.data, 'error');
                return;
            }
            var d = resp.data;
            $('#stat-tec-events').text(d.tec_event_count);
            $('#stat-tec-attendees').text(d.tec_attendee_count);
            $('#stat-ek-events').text(d.ek_event_count);
            $('#stat-mapped').text(d.mapped_event_count);
            $('#stat-unmapped').text(d.unmapped_event_count);
            $('#stat-mapped-attendees').text(d.mapped_attendees);
            $('#stat-wc-orders').text(d.wc_order_count);
            $('#stat-already-imported').text(d.already_imported);
            totalAttendees = d.mapped_attendees;

            // Show/hide WooCommerce warning.
            if (!d.wc_available) {
                $('#ekti-wc-warning').show();
            } else {
                $('#ekti-wc-warning').hide();
            }

            appendConsole('Stats loaded. ' + d.mapped_attendees + ' attendees and ' + d.wc_order_count + ' WC orders ready to import.', 'info');
        });
    }

    /* ------------------------------------------------------------------ */
    /*  EVENT MAPPING                                                     */
    /* ------------------------------------------------------------------ */

    function loadMapping() {
        appendConsole('Loading event mapping...', 'info');
        ajax('ekti_get_event_mapping', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Error loading mapping: ' + resp.data, 'error');
                return;
            }
            var d = resp.data;
            var $tbody = $('#ekti-mapping-tbody').empty();

            // Build EventKoi options.
            var ekOptions = '<option value="">\u2014 Not mapped \u2014</option>';
            $.each(d.ek_events, function (i, ev) {
                var statusLabel = '';
                if (ev.post_status && ev.post_status !== 'publish') {
                    statusLabel = ' [' + ev.post_status + ']';
                }
                ekOptions += '<option value="' + ev.ID + '">' + escapeHtml(ev.post_title) + statusLabel + ' (' + ev.ID + ')</option>';
            });

            $.each(d.rows, function (i, row) {
                var selected = row.ek_event_id ? row.ek_event_id : '';
                var selectHtml = ekOptions.replace(
                    'value="' + selected + '"',
                    'value="' + selected + '" selected'
                );
                var badge = '';
                if (row.is_orphan) {
                    badge = ' <span style="color:#dc2626;font-size:11px;" title="TEC event permanently deleted — attendees will be skipped">&#x26A0; orphan (deleted)</span>';
                } else if (row.match_source === 'source') {
                    badge = ' <span style="color:#16a34a;font-size:11px;" title="Matched via EventKoi import log">&#x2713; import log</span>';
                } else if (row.match_source === 'manual') {
                    badge = ' <span style="color:#d97706;font-size:11px;" title="Matched via title similarity or manual">~ title/fuzzy</span>';
                }
                var tr = '<tr data-tec-id="' + row.tec_id + '">' +
                    '<td>' + row.tec_id + '</td>' +
                    '<td>' + escapeHtml(row.tec_title) + badge + '</td>' +
                    '<td>' + row.attendee_count + '</td>' +
                    '<td><select class="ekti-ek-select" data-tec-id="' + row.tec_id + '">' + selectHtml + '</select></td>' +
                    '</tr>';
                $tbody.append(tr);
            });

            $('#ekti-mapping-table-wrap').show();
            appendConsole('Mapping table loaded. ' + d.rows.length + ' TEC events listed.', 'info');
        });
    }

    function saveMapping() {
        var mapping = {};
        $('.ekti-ek-select').each(function () {
            var tecId = $(this).data('tec-id');
            var ekId  = $(this).val();
            if (ekId && ekId !== '') {
                mapping[tecId] = ekId;
            }
        });
        appendConsole('Saving mapping (' + Object.keys(mapping).length + ' mapped events)...', 'info');
        ajax('ekti_save_mapping', { mapping: JSON.stringify(mapping) }, function (resp) {
            if (!resp.success) {
                appendConsole('Error saving mapping: ' + resp.data, 'error');
                return;
            }
            appendConsole('Mapping saved. ' + resp.data.saved + ' events mapped.', 'info');
            setStatus('#ekti-mapping-status', 'Mapping saved successfully.', 'success');
            loadStats();
        });
    }

    function autoMatch() {
        appendConsole('Running auto-match by title similarity...', 'info');
        setStatus('#ekti-mapping-status', '<span class="ekti-spinner"></span> Auto-matching...', 'info');
        ajax('ekti_auto_match', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Auto-match error: ' + resp.data, 'error');
                setStatus('#ekti-mapping-status', 'Auto-match failed.', 'error');
                return;
            }
            var d = resp.data;
            var orphanMsg = d.orphaned > 0 ? ', <strong style="color:#dc2626;">' + d.orphaned + '</strong> orphaned (deleted TEC events skipped)' : '';
            setStatus('#ekti-mapping-status',
                'Auto-match complete: <strong>' + d.matched + '</strong> matched (' +
                d.source_matched + ' via import log, ' +
                d.title_matched + ' exact title, ' +
                d.fuzzy_matched + ' fuzzy), <strong>' + d.unmatched + '</strong> unmatched' + orphanMsg + '.',
                d.unmatched > 0 ? 'warning' : 'success'
            );
            appendConsole('Auto-match: ' + d.matched + ' matched (' + d.source_matched + ' source, ' + d.title_matched + ' title, ' + d.fuzzy_matched + ' fuzzy), ' + d.unmatched + ' unmatched' + (d.orphaned > 0 ? ', ' + d.orphaned + ' orphaned (deleted)' : '') + '.', d.unmatched > 0 ? 'warn' : 'info');
            loadMapping();
            loadStats();
        });
    }

    /* ------------------------------------------------------------------ */
    /*  MIGRATION                                                         */
    /* ------------------------------------------------------------------ */

    var isRunning = false;

    function startMigration(resume) {
        if (isRunning) return;
        isRunning = true;

        var dryRun = $('#ekti-dry-run').is(':checked');
        var modeLabel = dryRun ? 'Dry Run' : 'LIVE IMPORT';

        if (!resume && !dryRun) {
            if (!confirm('This will write data to the database. Are you sure?')) {
                isRunning = false;
                return;
            }
        }

        appendConsole('=== Starting ' + modeLabel + ' ===', 'info');
        $('#ekti-progress-wrap').show();
        updateProgress(0);

        $('#ekti-start-migration, #ekti-resume-migration').prop('disabled', true);

        runBatch(dryRun, !resume);
    }

    function runBatch(dryRun, reset) {
        ajax('ekti_run_batch', {
            dry_run: dryRun ? 'true' : 'false',
            reset: reset ? 'true' : 'false'
        }, function (resp) {
            if (!resp.success) {
                appendConsole('Batch error: ' + resp.data, 'error');
                isRunning = false;
                $('#ekti-start-migration, #ekti-resume-migration').prop('disabled', false);
                return;
            }

            var d = resp.data;
            var state = d.state;

            // Log batch results.
            var results = d.results || [];
            for (var i = 0; i < results.length; i++) {
                var r = results[i];
                if (r.action === 'created') {
                    appendConsole('✓ Created attendee #' + r.attendee_id + ': ' + (r.name || '') + ' [' + (r.composite_key || '') + ']' + (r.status && r.status !== 'complete' ? ' — ' + r.status + ' (not counted as sold)' : ''));
                } else if (r.action === 'dry_run_create') {
                    appendConsole('[DRY] Would create: ' + (r.name || '') + ' <' + (r.email || '') + '> → EventKoi #' + r.ek_event_id + ' @ $' + (r.price || '0') + ' | key: ' + (r.composite_key || '') + ' | WC#' + (r.wc_order_id || 'none') + ' | ' + (r.status || ''));
                } else if (r.action === 'skipped') {
                    appendConsole('⊘ Skipped #' + r.attendee_id + ': ' + r.reason, 'warn');
                } else if (r.action === 'error') {
                    appendConsole('✗ Error #' + r.attendee_id + ': ' + r.reason, 'error');
                }
            }

            // Update progress.
            var pct = totalAttendees > 0 ? Math.min(100, Math.round((state.processed / totalAttendees) * 100)) : 0;
            updateProgress(pct);
            setStatus('#ekti-migration-status',
                'Processed: <strong>' + state.processed + '</strong> | ' +
                'Attendees: <strong>' + state.attendees_created + '</strong> | ' +
                'Orders: <strong>' + (state.orders_created || 0) + '</strong> | ' +
                'Skipped: <strong>' + state.skipped + '</strong> | ' +
                'Errors: <strong>' + state.errors + '</strong>',
                state.errors > 0 ? 'warning' : 'info'
            );

            if (d.done || state.completed) {
                appendConsole('=== Migration ' + (dryRun ? 'Dry Run ' : '') + 'Complete ===', 'info');
                appendConsole('Total processed: ' + state.processed, 'info');
                appendConsole('Attendees: ' + state.attendees_created + ' | WC orders linked: ' + (state.orders_created || 0) + ' | Ticket types created: ' + (state.tickets_created || 0) + ' | Skipped: ' + state.skipped + ' | Errors: ' + state.errors, 'info');
                updateProgress(100);
                isRunning = false;
                $('#ekti-start-migration, #ekti-resume-migration').prop('disabled', false);
                loadStats();
            } else {
                // Continue with next batch.
                setTimeout(function () {
                    runBatch(dryRun, false);
                }, 200);
            }
        });
    }

    function updateProgress(pct) {
        $('#ekti-progress-fill').css('width', pct + '%');
        $('#ekti-progress-text').text(pct + '%');
    }

    function rollback() {
        if (!confirm('This will revert everything Run Migration wrote (ticket orders, ticket types it created, attendee flags and WC order meta). Event Sync data is not touched. Continue?')) {
            return;
        }
        appendConsole('=== Running Rollback ===', 'warn');
        ajax('ekti_rollback', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Rollback error: ' + resp.data, 'error');
                return;
            }
            var d = resp.data;
            appendConsole('Rollback complete:', 'warn');
            appendConsole('  Ticket orders: ' + d.deleted_orders, 'warn');
            appendConsole('  Ticket types: ' + d.deleted_tickets, 'warn');
            setStatus('#ekti-migration-status', 'Rollback complete.', 'warning');
            updateProgress(0);
            loadStats();
        });
    }

    /* ------------------------------------------------------------------ */
    /*  LOG FILE                                                          */
    /* ------------------------------------------------------------------ */

    function loadLog() {
        ajax('ekti_get_log', { lines: 100 }, function (resp) {
            if (!resp.success) {
                $('#ekti-log-content').text('Error loading log.');
                return;
            }
            $('#ekti-log-content').text(resp.data.log || '(empty)');
        });
    }

    function clearLog() {
        ajax('ekti_clear_log', {}, function () {
            $('#ekti-log-content').text('(cleared)');
            appendConsole('Log file cleared.', 'info');
        });
    }

    /* ------------------------------------------------------------------ */
    /*  PRE-IMPORT CLEANUP                                                */
    /* ------------------------------------------------------------------ */

    var cleanupRunning = false;
    var cleanupTotals  = { stale: 0, groups: 0 };

    function scanCleanup() {
        appendConsole('Scanning for cleanup work...', 'info');
        setStatus('#ekti-cleanup-status', '<span class="ekti-spinner"></span> Scanning...', 'info');
        ajax('ekti_scan_cleanup', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Scan error: ' + resp.data, 'error');
                setStatus('#ekti-cleanup-status', 'Scan failed.', 'error');
                return;
            }
            var d = resp.data;

            // Stale duplicate tickets table.
            var $st = $('#ekti-stale-tbody').empty();
            $.each(d.stale_pairs, function (i, p) {
                $st.append('<tr><td>' + p.event_id + '</td><td>' + escapeHtml(p.title) + '</td>' +
                    '<td>#' + p.keep_id + ' @ $' + p.keep_price + '</td>' +
                    '<td>#' + p.delete_id + ' @ $' + p.delete_price + '</td>' +
                    '<td>' + (p.tec_price === null ? '\u2014' : '$' + p.tec_price) + '</td>' +
                    '<td>' + escapeHtml(p.reason) + '</td></tr>');
            });
            $('#ekti-stale-table-wrap').toggle(d.stale_pairs.length > 0);

            // Manual review table.
            var $rv = $('#ekti-review-tbody').empty();
            $.each(d.review, function (i, r) {
                $rv.append('<tr><td>' + r.event_id + '</td><td>' + escapeHtml(r.title) + '</td><td>' + escapeHtml(r.reason) + '</td></tr>');
            });
            $('#ekti-review-table-wrap').toggle(d.review.length > 0);

            // Duplicate event groups table.
            var $dp = $('#ekti-dups-tbody').empty();
            $.each(d.dup_groups, function (i, g) {
                $.each(g.members, function (j, m) {
                    var role = m.canonical
                        ? '<span style="color:#16a34a;font-size:11px;">&#x2713; canonical</span>'
                        : '<span style="color:#a00;font-size:11px;">merge away</span>';
                    if (g.flagged) {
                        role += ' <span style="color:#d97706;font-size:11px;">&#9873; review</span>';
                    }
                    $dp.append('<tr><td>' + (j === 0 ? g.tec_id : '') + '</td><td>' + role + '</td>' +
                        '<td>' + m.id + '</td><td>' + m.status + '</td>' +
                        '<td>' + m.tickets + '</td><td>' + m.attendees + '</td>' +
                        '<td>' + escapeHtml((m.cals || []).join(', ')) + '</td></tr>');
                });
            });
            $('#ekti-dups-table-wrap').toggle(d.dup_groups.length > 0);

            cleanupTotals = { stale: d.stale_pairs.length, groups: d.dup_groups.length };
            $('#ekti-cleanup-dedupe').prop('disabled', cleanupRunning || d.stale_pairs.length === 0);
            $('#ekti-cleanup-merge').prop('disabled', cleanupRunning || d.dup_groups.length === 0);

            setStatus('#ekti-cleanup-status',
                'Scan complete: <strong>' + d.stale_pairs.length + '</strong> stale ticket pairs, ' +
                '<strong>' + d.dup_groups.length + '</strong> duplicate event groups, ' +
                '<strong>' + d.review.length + '</strong> items needing manual review.',
                d.review.length > 0 ? 'warning' : 'success'
            );
            appendConsole('Cleanup scan: ' + d.stale_pairs.length + ' stale ticket pairs, ' + d.dup_groups.length + ' duplicate groups, ' + d.review.length + ' review items.', 'info');
        });
    }

    function updateCleanupProgress(processed, total) {
        var pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 100;
        $('#ekti-cleanup-progress-fill').css('width', pct + '%');
        $('#ekti-cleanup-progress-text').text(pct + '% (' + processed + '/' + total + ')');
    }

    function loopCleanup(action, total, label) {
        var processed = 0;
        var acted     = 0;
        $('#ekti-cleanup-progress-wrap').show();
        updateCleanupProgress(0, total);

        function step() {
            ajax(action, {}, function (resp) {
                if (!resp.success) {
                    appendConsole(label + ' error: ' + resp.data, 'error');
                    cleanupRunning = false;
                    $('#ekti-cleanup-dedupe, #ekti-cleanup-merge').prop('disabled', false);
                    return;
                }
                var d = resp.data;
                $.each(d.results || [], function (i, r) {
                    if (r.action === 'deleted') {
                        appendConsole('\u2713 Deleted stale ticket #' + r.ticket_id + ' on event ' + r.event_id + ' (kept #' + r.keep_id + ')');
                    } else if (r.action === 'merged') {
                        appendConsole('\u2713 Merged TEC ' + r.tec_id + ' \u2192 canonical #' + r.canonical + ' (trashed ' + (r.losers || []).join(', ') + ')');
                    } else if (r.action === 'flagged') {
                        appendConsole('\u26a0 Flagged ' + (r.event_id || r.tec_id) + ': ' + r.reason, 'warn');
                    } else if (r.action === 'skipped') {
                        appendConsole('\u2298 Skipped ' + (r.event_id || r.tec_id) + ': ' + r.reason, 'warn');
                    } else if (r.action === 'error') {
                        appendConsole('\u2717 Error ' + (r.event_id || r.tec_id) + ': ' + r.reason, 'error');
                    }
                });
                processed += d.processed;
                acted += (d.deleted !== undefined ? d.deleted : (d.merged !== undefined ? d.merged : 0));
                updateCleanupProgress(processed, total);

                if (d.done) {
                    appendConsole('=== ' + label + ' complete: ' + acted + ' changes, ' + processed + ' processed ===', 'info');
                    cleanupRunning = false;
                    updateCleanupProgress(total, total);
                    scanCleanup();
                    loadStats();
                } else {
                    setTimeout(step, 200);
                }
            });
        }
        step();
    }

    function runDedupe() {
        if (cleanupRunning) return;
        if (!confirm('Delete ' + cleanupTotals.stale + ' stale duplicate ticket types? Changes are audited and can be undone.')) {
            return;
        }
        cleanupRunning = true;
        $('#ekti-cleanup-dedupe, #ekti-cleanup-merge').prop('disabled', true);
        appendConsole('=== Starting Ticket Dedupe ===', 'info');
        loopCleanup('ekti_run_ticket_dedupe', cleanupTotals.stale, 'Ticket Dedupe');
    }

    function runMerge() {
        if (cleanupRunning) return;
        if (!confirm('Merge ' + cleanupTotals.groups + ' duplicate event groups? Loser events are trashed (recoverable) and all changes can be undone.')) {
            return;
        }
        cleanupRunning = true;
        $('#ekti-cleanup-dedupe, #ekti-cleanup-merge').prop('disabled', true);
        appendConsole('=== Starting Event Merge ===', 'info');
        loopCleanup('ekti_run_event_merge', cleanupTotals.groups, 'Event Merge');
    }

    function undoCleanup() {
        if (!confirm('Undo the last cleanup run (restores deleted tickets, untrashes events, reverts repointed rows)?')) {
            return;
        }
        appendConsole('=== Undoing Last Cleanup ===', 'warn');
        ajax('ekti_undo_cleanup', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Undo error: ' + resp.data, 'error');
                return;
            }
            var counts = resp.data.undone || {};
            $.each(counts, function (type, n) {
                appendConsole('  ' + type + ': ' + n, 'warn');
            });
            appendConsole('Undo complete.', 'warn');
            scanCleanup();
            loadStats();
        });
    }

    /* ------------------------------------------------------------------ */
    /*  TICKET DETAILS SYNC                                               */
    /* ------------------------------------------------------------------ */

    var syncRunning = false;
    var syncTotals  = { creates: 0, converts: 0, binds: 0, updates: 0, reviews: 0, pending_events: 0 };

    function scanTicketDetails() {
        appendConsole('Scanning ticket details...', 'info');
        setStatus('#ekti-sync-status', '<span class="ekti-spinner"></span> Scanning...', 'info');
        ajax('ekti_scan_ticket_details', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Scan error: ' + resp.data, 'error');
                setStatus('#ekti-sync-status', 'Scan failed.', 'error');
                return;
            }
            var d = resp.data;
            var c = d.counts;

            // Ticket types to create / convert.
            var $ty = $('#ekti-sync-types-tbody').empty();
            var typeRows = 0;
            $.each(d.events, function (i, ev) {
                $.each(ev.items, function (j, it) {
                    if (it.type === 'create') {
                        $ty.append('<tr><td>' + ev.tec_id + '</td><td>' + escapeHtml(ev.title) + '</td>' +
                            '<td><span style="color:#16a34a;font-size:11px;">create</span></td>' +
                            '<td>' + escapeHtml(it.name_display) + '</td>' +
                            '<td>$' + it.price + '</td>' +
                            '<td>' + (it.capacity === null ? '\u221e unlimited' : it.capacity) + '</td>' +
                            '<td>' + escapeHtml(it.sale_start_l || '\u2014') + ' \u2192 ' + escapeHtml(it.sale_end_l || '\u2014') + '</td></tr>');
                        typeRows++;
                    } else if (it.type === 'convert') {
                        $ty.append('<tr><td>' + ev.tec_id + '</td><td>' + escapeHtml(ev.title) + '</td>' +
                            '<td><span style="color:#d97706;font-size:11px;">convert #' + it.ticket_id + '</span></td>' +
                            '<td>' + escapeHtml(it.from_display) + ' \u2192 ' + escapeHtml(it.to_display) + '</td>' +
                            '<td>\u2014</td><td>\u2014</td><td>\u2014</td></tr>');
                        typeRows++;
                    }
                });
            });
            $('#ekti-sync-types-wrap').toggle(typeRows > 0);

            // Field updates table.
            var $up = $('#ekti-sync-updates-tbody').empty();
            var updateRows = 0;
            $.each(d.events, function (i, ev) {
                $.each(ev.items, function (j, it) {
                    if (it.type === 'update') {
                        $up.append('<tr><td>' + ev.tec_id + '</td><td>' + escapeHtml(ev.title) + '</td>' +
                            '<td>#' + it.ticket_id + ' ' + escapeHtml(it.ticket_display) + '</td>' +
                            '<td>' + escapeHtml(it.changes_display) + '</td></tr>');
                        updateRows++;
                    }
                });
            });
            $('#ekti-sync-updates-wrap').toggle(updateRows > 0);

            // Manual review table.
            var $rv = $('#ekti-sync-review-tbody').empty();
            var reviewRows = 0;
            $.each(d.events, function (i, ev) {
                $.each(ev.items, function (j, it) {
                    if (it.type === 'review') {
                        $rv.append('<tr><td>' + ev.tec_id + '</td><td>' + escapeHtml(ev.title) + '</td><td>' + escapeHtml(it.reason) + '</td></tr>');
                        reviewRows++;
                    }
                });
            });
            $('#ekti-sync-review-wrap').toggle(reviewRows > 0);

            syncTotals = c;
            $('#ekti-sync-run').prop('disabled', syncRunning || c.pending_events === 0);

            setStatus('#ekti-sync-status',
                'Scan complete: <strong>' + c.creates + '</strong> ticket types to create, ' +
                '<strong>' + c.converts + '</strong> to convert, ' +
                '<strong>' + c.binds + '</strong> product bindings, ' +
                '<strong>' + c.updates + '</strong> field updates across ' +
                '<strong>' + c.pending_events + '</strong> events; ' +
                '<strong>' + c.reviews + '</strong> items need manual review.',
                c.reviews > 0 ? 'warning' : 'success'
            );
            appendConsole('Ticket details scan: ' + c.creates + ' creates, ' + c.converts + ' converts, ' + c.binds + ' binds, ' + c.updates + ' updates, ' + c.reviews + ' review items (' + c.pending_events + ' events pending).', 'info');
        });
    }

    function updateSyncProgress(processed, total) {
        var pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 100;
        $('#ekti-sync-progress-fill').css('width', pct + '%');
        $('#ekti-sync-progress-text').text(pct + '% (' + processed + '/' + total + ')');
    }

    function loopTicketSync(total) {
        var processed = 0;
        var grand = { created: 0, converted: 0, bound: 0, updated: 0 };
        $('#ekti-sync-progress-wrap').show();
        updateSyncProgress(0, total);

        function step() {
            ajax('ekti_run_ticket_details_sync', {}, function (resp) {
                if (!resp.success) {
                    appendConsole('Sync error: ' + resp.data, 'error');
                    syncRunning = false;
                    $('#ekti-sync-run').prop('disabled', false);
                    return;
                }
                var d = resp.data;
                $.each(d.results || [], function (i, r) {
                    if (r.action === 'synced') {
                        appendConsole('\u2713 Synced ' + r.title + ' (TEC ' + r.tec_id + '): created ' + r.created + ', converted ' + r.converted + ', bound ' + r.bound + ', updated ' + r.updated);
                    } else if (r.action === 'error') {
                        appendConsole('\u2717 Error ' + (r.ek_id || r.tec_id) + ': ' + r.reason, 'error');
                    }
                });
                $.each(d.tally || {}, function (k, v) {
                    grand[k] = (grand[k] || 0) + v;
                });
                processed += d.processed;
                updateSyncProgress(processed, total);

                if (d.done) {
                    appendConsole('=== Ticket Details Sync complete: ' + grand.created + ' created, ' + grand.converted + ' converted, ' + grand.bound + ' bound, ' + grand.updated + ' updated ===', 'info');
                    setStatus('#ekti-sync-status',
                        'Sync complete: <strong>' + grand.created + '</strong> ticket types created, ' +
                        '<strong>' + grand.converted + '</strong> converted, ' +
                        '<strong>' + grand.bound + '</strong> product bindings, ' +
                        '<strong>' + grand.updated + '</strong> field updates.',
                        'success'
                    );
                    syncRunning = false;
                    updateSyncProgress(total, total);
                    scanTicketDetails();
                    loadStats();
                } else {
                    setTimeout(step, 200);
                }
            });
        }
        step();
    }

    function runSync() {
        if (syncRunning) return;
        if (!confirm('Sync ticket details for ' + syncTotals.pending_events + ' events (' + syncTotals.creates + ' creates, ' + syncTotals.converts + ' converts, ' + syncTotals.binds + ' binds, ' + syncTotals.updates + ' updates)? Changes are audited and can be undone.')) {
            return;
        }
        syncRunning = true;
        $('#ekti-sync-run').prop('disabled', true);
        appendConsole('=== Starting Ticket Details Sync ===', 'info');
        loopTicketSync(syncTotals.pending_events);
    }

    function undoSync() {
        if (!confirm('Undo the last ticket details sync (removes created tickets, reverts renames, field updates, and product bindings)?')) {
            return;
        }
        appendConsole('=== Undoing Last Ticket Details Sync ===', 'warn');
        ajax('ekti_undo_sync', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Undo error: ' + resp.data, 'error');
                return;
            }
            var counts = resp.data.undone || {};
            $.each(counts, function (type, n) {
                appendConsole('  ' + type + ': ' + n, 'warn');
            });
            appendConsole('Undo complete.', 'warn');
            scanTicketDetails();
            loadStats();
        });
    }

    /* ------------------------------------------------------------------ */
    /*  CALENDAR SYNC (LOCATIONS)                                         */
    /* ------------------------------------------------------------------ */

    var calSyncRunning = false;
    var calSyncTotals  = { pending: 0, new_calendars: 0 };

    function collectCalMapping() {
        var mapping = {};
        $('.ekti-cal-select').each(function () {
            var key = $(this).data('loc-key');
            var val = $(this).val();
            if (val === 'new') {
                var name = $.trim($('.ekti-cal-new-name[data-loc-key="' + key + '"]').val());
                if (name !== '') {
                    mapping[key] = 'new:' + name;
                }
            } else if (val !== '') {
                mapping[key] = val;
            }
        });
        return mapping;
    }

    function scanCalendarSync() {
        appendConsole('Scanning locations...', 'info');
        setStatus('#ekti-calsync-status', '<span class="ekti-spinner"></span> Scanning...', 'info');
        ajax('ekti_scan_calendar_sync', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Scan error: ' + resp.data, 'error');
                setStatus('#ekti-calsync-status', 'Scan failed.', 'error');
                return;
            }
            var d = resp.data;
            var c = d.counts;

            // Calendar dropdown options (shared across rows).
            var calOptions = '<option value="">\u2014 None \u2014</option>';
            $.each(d.calendars, function (i, cal) {
                calOptions += '<option value="' + cal.term_id + '">' + escapeHtml(cal.name) + ' (' + cal.count + ')</option>';
            });
            calOptions += '<option value="new">\u2795 Create new calendar\u2026</option>';

            var $tb = $('#ekti-calsync-tbody').empty();
            $.each(d.locations, function (i, loc) {
                var source = loc.source === 'venue'
                    ? 'TEC venue #' + loc.venue_id
                    : 'EventKoi location meta';

                var badge;
                if (loc.match_source === 'exact') {
                    badge = '<span style="color:#16a34a;font-size:11px;" title="Exact name match">&#x2713; exact</span>';
                } else if (loc.match_source === 'fuzzy') {
                    badge = '<span style="color:#d97706;font-size:11px;" title="Similar name match">~ fuzzy: ' + escapeHtml(loc.suggested_term_name || '') + '</span>';
                } else {
                    badge = '<span style="color:#646970;font-size:11px;">no match</span>';
                }

                // Preselect the saved mapping or the auto-match suggestion.
                var selected = loc.selected;
                var isNew = selected.indexOf('new:') === 0;
                var selectHtml = calOptions;
                if (isNew) {
                    selectHtml = selectHtml.replace('value="new"', 'value="new" selected');
                } else if (selected !== '') {
                    selectHtml = selectHtml.replace(
                        'value="' + selected + '"',
                        'value="' + selected + '" selected'
                    );
                }
                var newInput = '<input type="text" class="ekti-cal-new-name" data-loc-key="' + loc.key + '" placeholder="New calendar name" value="' + escapeHtml(isNew ? selected.substring(4) : loc.name) + '" style="' + (isNew ? '' : 'display:none;') + 'margin-left:6px;" />';

                $tb.append('<tr>' +
                    '<td><strong>' + escapeHtml(loc.name) + '</strong></td>' +
                    '<td>' + source + '</td>' +
                    '<td>' + loc.event_count + '</td>' +
                    '<td>' + badge + '</td>' +
                    '<td><select class="ekti-cal-select" data-loc-key="' + loc.key + '">' + selectHtml + '</select>' + newInput + '</td>' +
                    '<td>' + loc.pending + '</td>' +
                    '</tr>');
            });

            $('#ekti-calsync-table-wrap').toggle(d.locations.length > 0);
            calSyncTotals = { pending: c.pending, new_calendars: c.new_calendars };
            $('#ekti-calsync-save').prop('disabled', calSyncRunning || d.locations.length === 0);
            $('#ekti-calsync-run').prop('disabled', calSyncRunning || c.pending === 0);

            setStatus('#ekti-calsync-status',
                'Scan complete: <strong>' + c.locations + '</strong> locations across ' +
                '<strong>' + c.mapped_events + '</strong> mapped events; ' +
                '<strong>' + c.pending + '</strong> calendar assignments pending' +
                (c.new_calendars > 0 ? ' (<strong>' + c.new_calendars + '</strong> new calendars to create)' : '') +
                (c.unmapped_locations > 0 ? '; <strong>' + c.unmapped_locations + '</strong> locations unmapped' : '') + '.',
                c.pending > 0 ? 'info' : 'success'
            );
            appendConsole('Calendar scan: ' + c.locations + ' locations, ' + c.pending + ' assignments pending' + (c.new_calendars > 0 ? ', ' + c.new_calendars + ' calendars to create' : '') + '.', 'info');
        });
    }

    function saveCalMapping() {
        var mapping = collectCalMapping();
        appendConsole('Saving location mapping (' + Object.keys(mapping).length + ' locations)...', 'info');
        ajax('ekti_save_location_mapping', { mapping: JSON.stringify(mapping) }, function (resp) {
            if (!resp.success) {
                appendConsole('Error saving location mapping: ' + resp.data, 'error');
                return;
            }
            appendConsole('Location mapping saved. ' + resp.data.saved + ' locations.', 'info');
            setStatus('#ekti-calsync-status', 'Location mapping saved.', 'success');
        });
    }

    function updateCalSyncProgress(processed, total) {
        var pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 100;
        $('#ekti-calsync-progress-fill').css('width', pct + '%');
        $('#ekti-calsync-progress-text').text(pct + '% (' + processed + '/' + total + ')');
    }

    function loopCalSync(mapping, total) {
        var processed = 0;
        var applied   = 0;
        var errors    = 0;
        $('#ekti-calsync-progress-wrap').show();
        updateCalSyncProgress(0, total);

        function step() {
            ajax('ekti_run_calendar_sync', { mapping: JSON.stringify(mapping) }, function (resp) {
                if (!resp.success) {
                    appendConsole('Calendar sync error: ' + resp.data, 'error');
                    calSyncRunning = false;
                    $('#ekti-calsync-run').prop('disabled', false);
                    return;
                }
                var d = resp.data;
                $.each(d.results || [], function (i, r) {
                    if (r.action === 'assigned') {
                        appendConsole('\u2713 EventKoi #' + r.ek_id + ' (TEC ' + r.tec_id + ') \u2192 calendar #' + r.term_id);
                    } else if (r.action === 'error') {
                        appendConsole('\u2717 Error EK #' + r.ek_id + ': ' + r.reason, 'error');
                    }
                });
                processed += d.applied;
                applied += d.applied;
                errors += (d.errors || 0);
                updateCalSyncProgress(processed, total);

                if (d.done) {
                    appendConsole('=== Calendar Sync complete: ' + applied + ' assignments' + (errors > 0 ? ', ' + errors + ' errors' : '') + ' ===', 'info');
                    setStatus('#ekti-calsync-status',
                        'Sync complete: <strong>' + applied + '</strong> calendar assignments applied' +
                        (errors > 0 ? ', <strong>' + errors + '</strong> errors' : '') + '.',
                        errors > 0 ? 'warning' : 'success'
                    );
                    calSyncRunning = false;
                    updateCalSyncProgress(total, total);
                    scanCalendarSync();
                } else if (d.applied === 0) {
                    // No progress on this chunk — stop to avoid an infinite loop.
                    appendConsole('Calendar sync stopped: no progress made with ' + (total - processed) + ' items still pending.', 'error');
                    calSyncRunning = false;
                    $('#ekti-calsync-run').prop('disabled', false);
                } else {
                    setTimeout(step, 200);
                }
            });
        }
        step();
    }

    function runCalSync() {
        if (calSyncRunning) return;
        var mapping = collectCalMapping();
        if (Object.keys(mapping).length === 0) {
            setStatus('#ekti-calsync-status', 'No locations mapped. Choose an EventKoi calendar for at least one location.', 'warning');
            return;
        }
        if (!confirm('Apply ' + calSyncTotals.pending + ' calendar assignments' + (calSyncTotals.new_calendars > 0 ? ' (creating ' + calSyncTotals.new_calendars + ' new calendar(s))' : '') + '? Existing calendars are never removed. Changes are audited and can be undone.')) {
            return;
        }
        calSyncRunning = true;
        $('#ekti-calsync-run').prop('disabled', true);
        appendConsole('=== Starting Calendar Sync ===', 'info');
        loopCalSync(mapping, calSyncTotals.pending);
    }

    function undoCalSync() {
        if (!confirm('Undo the last calendar sync (restores previous calendar assignments and deletes any calendars created by the sync)?')) {
            return;
        }
        appendConsole('=== Undoing Last Calendar Sync ===', 'warn');
        ajax('ekti_undo_calendar_sync', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Undo error: ' + resp.data, 'error');
                return;
            }
            var counts = resp.data.undone || {};
            $.each(counts, function (type, n) {
                appendConsole('  ' + type + ': ' + n, 'warn');
            });
            appendConsole('Undo complete.', 'warn');
            scanCalendarSync();
        });
    }

    /* ------------------------------------------------------------------ */
    /*  TAXONOMY SYNC (LOCATIONS & INSTRUCTORS)                           */
    /* ------------------------------------------------------------------ */

    var taxSyncRunning = { locations: false, instructors: false };
    var taxSyncTotals  = {
        locations:   { pending: 0, new_terms: 0, field_updates: 0, field_conflicts: 0 },
        instructors: { pending: 0, new_terms: 0 }
    };
    var organizerCleanup = { detected: false };

    function collectTaxMapping(type) {
        var mapping = {};
        $('.ekti-tax-select[data-tax-type="' + type + '"]').each(function () {
            var key = $(this).data('source-key');
            var val = $(this).val();
            if (val === 'new') {
                var name = $.trim($('.ekti-tax-new-name[data-tax-type="' + type + '"][data-source-key="' + key + '"]').val());
                if (name !== '') {
                    mapping[key] = 'new:' + name;
                }
            } else if (val !== '') {
                mapping[key] = val;
            }
        });
        return mapping;
    }

    /* Address Data cell for the locations table (Saved Locations). */
    function locationFieldsCell(src, savedLocations) {
        var dim = 'style="color:#646970;font-size:11px;"';
        if (!savedLocations || !savedLocations.active) {
            return '<span ' + dim + '>&mdash;</span>';
        }
        if (!src.source_id) {
            return '<span ' + dim + '>no TEC venue</span>';
        }
        if (!src.fields) {
            return '<span ' + dim + '>no target term</span>';
        }
        var f = src.fields;
        if (!f.pending && !f.conflicts) {
            return '<span style="color:#16a34a;font-size:11px;" title="All mapped address fields match the TEC venue">&#x2713; complete</span>';
        }
        var tip = [];
        $.each(f.slots, function (i, s) {
            tip.push(s.label + ': "' + (s.current === '' ? '(empty)' : s.current) + '" \u2192 "' + s.proposed + '"');
        });
        var parts = [];
        if (f.pending > 0) {
            parts.push(f.pending + ' field' + (f.pending > 1 ? 's' : '') + ' to fill');
        }
        if (f.conflicts > 0) {
            parts.push(f.conflicts + ' conflict' + (f.conflicts > 1 ? 's' : '') + ' \u2014 review');
        }
        var color = f.conflicts > 0 ? '#d97706' : '#2271b1';
        return '<span style="color:' + color + ';font-size:11px;" title="' + escapeHtml(tip.join('\n')) + '">' +
            (f.is_new ? '(new term) ' : '') + escapeHtml(parts.join('; ')) + '</span>';
    }

    function scanTaxonomySync(type) {
        appendConsole('Scanning ' + type + '...', 'info');
        setStatus('#ekti-tax-' + type + '-status', '<span class="ekti-spinner"></span> Scanning...', 'info');
        ajax('ekti_scan_taxonomy_sync', { type: type }, function (resp) {
            if (!resp.success) {
                appendConsole('Scan error (' + type + '): ' + resp.data, 'error');
                setStatus('#ekti-tax-' + type + '-status', 'Scan failed: ' + escapeHtml(resp.data), 'error');
                return;
            }
            var d = resp.data;
            var c = d.counts;

            // Taxonomy not set up yet: point at step 2a instead of a table.
            if (d.provision_needed) {
                $('#ekti-tax-' + type + '-table-wrap').hide();
                $('#ekti-tax-' + type + '-designation').hide();
                $('#ekti-tax-' + type + '-save, #ekti-tax-' + type + '-run').prop('disabled', true);
                setStatus('#ekti-tax-' + type + '-status',
                    'The ' + type + ' taxonomy is not set up yet (' + (c.sources || 0) + ' TEC source(s) waiting). Create it in 2a above, then scan again.',
                    'warning');
                return;
            }

            var termOptions = '<option value="">\u2014 None \u2014</option>';
            $.each(d.terms, function (i, term) {
                termOptions += '<option value="' + term.term_id + '">' + escapeHtml(term.name) + ' (' + term.count + ')</option>';
            });
            termOptions += '<option value="new">\u2795 Create new term\u2026</option>';

            // Saved Locations designation status (locations only).
            if (type === 'locations') {
                var $desig = $('#ekti-tax-locations-designation');
                var sl = d.saved_locations;
                if (sl && sl.active && Object.keys(sl.slots || {}).length > 0) {
                    $desig.show().html(
                        '<div class="notice notice-success" style="margin:0.5em 0;"><p>' +
                        'Saved Locations active on taxonomy <strong>"' + escapeHtml(sl.taxonomy) + '"</strong> (' +
                        Object.keys(sl.slots).length + ' address slot(s) mapped). TEC venue addresses are copied onto terms during the sync.' +
                        '</p></div>'
                    );
                } else if (sl) {
                    $desig.show().html(
                        '<div class="notice notice-warning" style="margin:0.5em 0;"><p>' + escapeHtml(sl.warning) + '</p></div>'
                    );
                } else {
                    $desig.hide();
                }
                if (sl && sl.legacy_meta_events > 0) {
                    $desig.append('<p style="color:#646970;font-size:11px;margin:0.25em 0;">' +
                        sl.legacy_meta_events + ' event(s) still carry legacy built-in location meta. EventKoi keeps it as a manual-mode fallback; term address data takes precedence. No cleanup is performed.</p>');
                }
            }

            var $tb = $('#ekti-tax-' + type + '-tbody').empty();
            $.each(d.sources, function (i, src) {
                var sourceLabel = src.source === 'eventkoi_meta'
                    ? 'EventKoi meta fallback'
                    : (src.source === 'tec_organizer' ? 'TEC organizer #' + src.source_id : 'TEC venue #' + src.source_id);

                var badge;
                if (src.match_source === 'exact') {
                    badge = '<span style="color:#16a34a;font-size:11px;" title="Exact name match">&#x2713; exact</span>';
                } else if (src.match_source === 'fuzzy') {
                    badge = '<span style="color:#d97706;font-size:11px;" title="Similar name match">~ fuzzy: ' + escapeHtml(src.suggested_term_name || '') + '</span>';
                } else {
                    badge = '<span style="color:#646970;font-size:11px;">no match</span>';
                }

                var selected = src.selected;
                var isNew = selected.indexOf('new:') === 0;
                var selectHtml = termOptions;
                if (isNew) {
                    selectHtml = selectHtml.replace('value="new"', 'value="new" selected');
                } else if (selected !== '') {
                    selectHtml = selectHtml.replace(
                        'value="' + selected + '"',
                        'value="' + selected + '" selected'
                    );
                }
                var newInput = '<input type="text" class="ekti-tax-new-name" data-tax-type="' + type + '" data-source-key="' + src.key + '" placeholder="New term name" value="' + escapeHtml(isNew ? selected.substring(4) : src.name) + '" style="' + (isNew ? '' : 'display:none;') + 'margin-left:6px;" />';

                $tb.append('<tr>' +
                    '<td><strong>' + escapeHtml(src.name) + '</strong></td>' +
                    '<td>' + sourceLabel + '</td>' +
                    '<td>' + src.event_count + '</td>' +
                    '<td>' + badge + '</td>' +
                    '<td><select class="ekti-tax-select" data-tax-type="' + type + '" data-source-key="' + src.key + '">' + selectHtml + '</select>' + newInput + '</td>' +
                    (type === 'locations' ? '<td>' + locationFieldsCell(src, d.saved_locations) + '</td>' : '') +
                    '<td>' + src.pending + '</td>' +
                    '</tr>');
            });

            $('#ekti-tax-' + type + '-table-wrap').toggle(d.sources.length > 0);
            taxSyncTotals[type] = {
                pending: c.pending,
                new_terms: c.new_terms,
                field_updates: c.field_updates || 0,
                field_conflicts: c.field_conflicts || 0
            };
            // New terms can be created before any event is imported.
            var canRun = c.pending > 0 || c.new_terms > 0 || (c.field_updates || 0) > 0;
            if (type === 'locations' && $('#ekti-tax-locations-overwrite').prop('checked')) {
                canRun = canRun || (c.field_conflicts || 0) > 0;
            }
            $('#ekti-tax-' + type + '-save').prop('disabled', taxSyncRunning[type] || d.sources.length === 0);
            $('#ekti-tax-' + type + '-run').prop('disabled', taxSyncRunning[type] || !canRun);

            var fieldInfo = '';
            if (type === 'locations' && d.saved_locations && d.saved_locations.active) {
                if ((c.field_updates || 0) > 0) {
                    fieldInfo += '; <strong>' + c.field_updates + '</strong> empty address field(s) to fill';
                }
                if ((c.field_conflicts || 0) > 0) {
                    fieldInfo += '; <strong>' + c.field_conflicts + '</strong> address conflict(s) \u2014 review-only unless overwrite is checked';
                }
            }

            setStatus('#ekti-tax-' + type + '-status',
                'Scan complete: <strong>' + c.sources + '</strong> ' + type + ' sources across ' +
                '<strong>' + c.mapped_events + '</strong> TEC events; ' +
                '<strong>' + c.pending + '</strong> term assignments pending' +
                (c.new_terms > 0 ? ' (<strong>' + c.new_terms + '</strong> new terms to create)' : '') +
                (c.unmapped_sources > 0 ? '; <strong>' + c.unmapped_sources + '</strong> sources unmapped' : '') +
                fieldInfo + '.',
                (c.pending > 0 || (c.field_updates || 0) > 0) ? 'info' : 'success'
            );
            appendConsole('Taxonomy scan (' + type + '): ' + c.sources + ' sources, ' + c.pending + ' assignments pending' + (c.new_terms > 0 ? ', ' + c.new_terms + ' terms to create' : '') +
                (type === 'locations' && (c.field_updates || 0) > 0 ? ', ' + c.field_updates + ' address field(s) to fill' : '') +
                (type === 'locations' && (c.field_conflicts || 0) > 0 ? ', ' + c.field_conflicts + ' conflict(s)' : '') + '.', 'info');
        });
    }

    function saveTaxMapping(type) {
        var mapping = collectTaxMapping(type);
        appendConsole('Saving ' + type + ' mapping (' + Object.keys(mapping).length + ' sources)...', 'info');
        ajax('ekti_save_taxonomy_mapping', { type: type, mapping: JSON.stringify(mapping) }, function (resp) {
            if (!resp.success) {
                appendConsole('Error saving ' + type + ' mapping: ' + resp.data, 'error');
                return;
            }
            appendConsole(type + ' mapping saved. ' + resp.data.saved + ' sources.', 'info');
            setStatus('#ekti-tax-' + type + '-status', type.charAt(0).toUpperCase() + type.slice(1) + ' mapping saved.', 'success');
        });
    }

    function updateTaxSyncProgress(type, processed, total) {
        var pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 100;
        $('#ekti-tax-' + type + '-progress-fill').css('width', pct + '%');
        $('#ekti-tax-' + type + '-progress-text').text(pct + '% (' + processed + '/' + total + ')');
    }

    function loopTaxSync(type, mapping, total, extra) {
        var processed = 0;
        var applied   = 0;
        var errors    = 0;
        var fieldsWritten = 0;
        var firstStep = true;
        $('#ekti-tax-' + type + '-progress-wrap').show();
        updateTaxSyncProgress(type, 0, total);

        function step() {
            var data = { type: type, mapping: JSON.stringify(mapping) };
            if (firstStep && extra) {
                $.extend(data, extra);
            }
            firstStep = false;
            ajax('ekti_run_taxonomy_sync', data, function (resp) {
                if (!resp.success) {
                    appendConsole(type + ' sync error: ' + resp.data, 'error');
                    taxSyncRunning[type] = false;
                    $('#ekti-tax-' + type + '-run').prop('disabled', false);
                    return;
                }
                var d = resp.data;
                $.each(d.results || [], function (i, r) {
                    if (r.action === 'assigned') {
                        appendConsole('\u2713 EventKoi #' + r.ek_id + ' (TEC ' + r.tec_id + ') \u2192 term #' + r.term_id);
                    } else if (r.action === 'fields') {
                        appendConsole('\u2192 term #' + r.term_id + ': wrote ' + r.written + ' address field(s)');
                    } else if (r.action === 'error') {
                        appendConsole('\u2717 Error EK #' + r.ek_id + ': ' + r.reason, 'error');
                    }
                });
                processed += d.applied;
                applied += d.applied;
                errors += (d.errors || 0);
                fieldsWritten += (d.fields_written || 0);
                updateTaxSyncProgress(type, processed, total);

                if (d.done) {
                    appendConsole('=== ' + type + ' sync complete: ' + applied + ' assignments' +
                        (fieldsWritten > 0 ? ', ' + fieldsWritten + ' address field writes' : '') +
                        (errors > 0 ? ', ' + errors + ' errors' : '') + ' ===', 'info');
                    setStatus('#ekti-tax-' + type + '-status',
                        'Sync complete: <strong>' + applied + '</strong> term assignments applied' +
                        (fieldsWritten > 0 ? ', <strong>' + fieldsWritten + '</strong> address field writes' : '') +
                        (errors > 0 ? ', <strong>' + errors + '</strong> errors' : '') + '.',
                        errors > 0 ? 'warning' : 'success'
                    );
                    taxSyncRunning[type] = false;
                    updateTaxSyncProgress(type, total, total);
                    scanTaxonomySync(type);
                    refreshWizard();
                } else if (d.applied === 0) {
                    appendConsole(type + ' sync stopped: no progress made with ' + (total - processed) + ' items still pending.', 'error');
                    taxSyncRunning[type] = false;
                    $('#ekti-tax-' + type + '-run').prop('disabled', false);
                } else {
                    setTimeout(step, 200);
                }
            });
        }
        step();
    }

    function runTaxSync(type) {
        if (taxSyncRunning[type]) return;
        var mapping = collectTaxMapping(type);
        if (Object.keys(mapping).length === 0) {
            setStatus('#ekti-tax-' + type + '-status', 'No ' + type + ' mapped. Choose an EventKoi term for at least one source.', 'warning');
            return;
        }
        var totals = taxSyncTotals[type];
        var msg;
        var extra = null;
        if (type === 'locations') {
            var overwrite = $('#ekti-tax-locations-overwrite').prop('checked');
            extra = { overwrite: overwrite ? 'true' : 'false', sync_fields: 'true' };
            msg = totals.pending > 0
                ? 'Apply ' + totals.pending + ' location term assignment(s)' +
                  (totals.new_terms > 0 ? ' (creating ' + totals.new_terms + ' new term(s) populated with TEC address data)' : '')
                : 'Create ' + totals.new_terms + ' new location term(s) populated with TEC address data';
            if (totals.field_updates > 0) {
                msg += ' and fill ' + totals.field_updates + ' empty term address field(s)';
            }
            if (overwrite && totals.field_conflicts > 0) {
                msg += ' and overwrite ' + totals.field_conflicts + ' conflicting address field(s)';
            }
            msg += '? Empty address fields on mapped terms are filled from the TEC venue' +
                (overwrite ? '; non-empty fields are overwritten with TEC data' : '; non-empty fields are left alone (review conflicts in the table)') +
                '. Existing terms are never removed. Changes are audited and can be undone.';
        } else {
            msg = (totals.pending > 0
                ? 'Apply ' + totals.pending + ' ' + type + ' term assignments' +
                  (totals.new_terms > 0 ? ' (creating ' + totals.new_terms + ' new term(s))' : '')
                : 'Create ' + totals.new_terms + ' new ' + type + ' term(s)') +
                '? Existing terms are never removed. Changes are audited and can be undone.';
        }
        if (!confirm(msg)) {
            return;
        }
        taxSyncRunning[type] = true;
        $('#ekti-tax-' + type + '-run').prop('disabled', true);
        appendConsole('=== Starting ' + type + ' Sync ===', 'info');
        loopTaxSync(type, mapping, totals.pending, extra);
    }

    function undoTaxSync(type) {
        if (!confirm('Undo the last ' + type + ' sync (restores previous term assignments and deletes any terms created by the sync)?')) {
            return;
        }
        appendConsole('=== Undoing Last ' + type + ' Sync ===', 'warn');
        ajax('ekti_undo_taxonomy_sync', { type: type }, function (resp) {
            if (!resp.success) {
                appendConsole('Undo error (' + type + '): ' + resp.data, 'error');
                return;
            }
            var counts = resp.data.undone || {};
            $.each(counts, function (k, n) {
                appendConsole('  ' + k + ': ' + n, 'warn');
            });
            appendConsole('Undo complete.', 'warn');
            scanTaxonomySync(type);
        });
    }

    function scanOrganizerFields() {
        appendConsole('Scanning organizer custom fields...', 'info');
        setStatus('#ekti-tax-instructors-cleanup-status', '<span class="ekti-spinner"></span> Scanning...', 'info');
        ajax('ekti_remove_organizer_fields', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Organizer field scan error: ' + resp.data, 'error');
                setStatus('#ekti-tax-instructors-cleanup-status', 'Scan failed: ' + escapeHtml(resp.data), 'error');
                organizerCleanup.detected = false;
                $('#ekti-tax-instructors-remove-fields').prop('disabled', true);
                return;
            }
            var d = resp.data;
            if (!d.detected) {
                setStatus('#ekti-tax-instructors-cleanup-status', 'No organizer custom field group detected: ' + escapeHtml(d.reason), 'warning');
                organizerCleanup.detected = false;
                $('#ekti-tax-instructors-remove-fields').prop('disabled', true);
                return;
            }
            organizerCleanup.detected = true;
            $('#ekti-tax-instructors-remove-fields').prop('disabled', false);
            setStatus('#ekti-tax-instructors-cleanup-status',
                'Detected <strong>' + escapeHtml(d.group_title || 'organizer field') + '</strong> (meta_key: ' + escapeHtml(d.meta_key) + ', ' +
                d.event_count + ' events with data). Backup will be saved before removal.',
                'info'
            );
        });
    }

    function removeOrganizerFields() {
        if (!organizerCleanup.detected) return;
        if (!confirm('This will permanently delete the Organizer custom field group and all per-event Organizer meta values. A backup is saved to the ekti_organizer_field_backup option. Continue?')) {
            return;
        }
        appendConsole('=== Removing Organizer Custom Fields ===', 'warn');
        ajax('ekti_remove_organizer_fields', { confirm: 'true' }, function (resp) {
            if (!resp.success) {
                appendConsole('Remove organizer fields error: ' + resp.data, 'error');
                setStatus('#ekti-tax-instructors-cleanup-status', 'Removal failed: ' + escapeHtml(resp.data), 'error');
                return;
            }
            var d = resp.data;
            appendConsole('Removed organizer field group: ' + d.group_title + ' (meta_key: ' + d.meta_key + ', ' + d.deleted_meta + ' meta rows deleted).', 'warn');
            setStatus('#ekti-tax-instructors-cleanup-status',
                'Removed <strong>' + escapeHtml(d.group_title) + '</strong>. ' + d.deleted_meta + ' event meta rows deleted. Backup saved.',
                'success'
            );
            $('#ekti-tax-instructors-remove-fields').prop('disabled', true);
            organizerCleanup.detected = false;
        });
    }

    /* ------------------------------------------------------------------ */
    /*  EVENT SYNC (TEC → EVENTKOI EVENTS)                                */
    /* ------------------------------------------------------------------ */

    var esRunning = false;
    var esTotals  = { unimported: 0 };
    var esScan    = null;

    function scanEventSync() {
        appendConsole('Scanning TEC events...', 'info');
        setStatus('#ekti-event-sync-status', '<span class="ekti-spinner"></span> Scanning...', 'info');
        ajax('ekti_scan_event_sync', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Event Sync scan error: ' + resp.data, 'error');
                setStatus('#ekti-event-sync-status', 'Scan failed: ' + escapeHtml(resp.data), 'error');
                return;
            }
            var d = resp.data;
            esScan = d;
            var c = d.counts;

            $('#ekti-event-sync-stats').show();
            $('#ekti-es-total').text(c.tec_total);
            $('#ekti-es-imported').text(c.imported);
            $('#ekti-es-unimported').text(c.unimported);
            $('#ekti-es-tickets').text(c.woo_tickets + c.tc_tickets + c.rsvp_tickets);
            $('#ekti-es-tc').text(c.tc_attendees);
            $('#ekti-es-rsvp').text(c.rsvp_attendees + (c.rsvp_merged ? ' (+' + c.rsvp_merged + ' dup)' : ''));
            $('#ekti-es-woo').text(c.woo_attendees);
            $('#ekti-es-images').text(c.with_images);

            // Review items.
            var $rv = $('#ekti-es-review-tbody').empty();
            $.each(d.review || [], function (i, r) {
                $rv.append('<tr><td>' + r.tec_id + '</td><td>' + escapeHtml(r.title) + '</td>' +
                    '<td>' + escapeHtml(r.reason) + '</td><td>' + escapeHtml(r.detail) + '</td></tr>');
            });
            $('#ekti-es-review-wrap').toggle((d.review || []).length > 0);

            // Native-import adoption status.
            var ad = d.adoption || {};
            var adText = ad.candidate_events > 0
                ? ad.candidate_events + ' natively-imported event(s) carry ' + ad.unflagged_attendees +
                  ' unflagged WooCommerce attendee(s) that Run Migration would import again. Adopting flags them as already imported.'
                : '';
            $('#ekti-es-adoption-text').text(adText);
            $('#ekti-es-adoption-wrap').toggle(adText !== '');
            $('#ekti-event-sync-adopt').prop('disabled', esRunning || ad.candidate_events === 0);

            esTotals = { unimported: c.unimported };
            $('#ekti-event-sync-run').prop('disabled', esRunning || c.unimported === 0);

            var notes = [];
            if (c.recurring > 0) notes.push(c.recurring + ' recurring');
            if (c.all_day > 0) notes.push(c.all_day + ' all-day');
            if (c.virtual > 0) notes.push(c.virtual + ' virtual');
            if (c.custom_field_events > 0) notes.push(c.custom_field_events + ' with custom fields (reported only, not converted)');
            if (d.currency && d.currency.tc_mismatch) {
                notes.push('currency mismatch: Event Tickets ' + d.currency.et + ' vs checkout ' + d.currency.checkout + ' (mismatched prices import as 0)');
            }

            var reviews = (d.review || []).length;
            setStatus('#ekti-event-sync-status',
                'Scan complete: <strong>' + c.unimported + '</strong> of ' + c.tec_total + ' TEC events to import (' +
                c.by_status.publish + ' publish, ' + c.by_status.draft + ' draft); ' +
                '<strong>' + c.rsvp_merged + '</strong> duplicate RSVP row(s) will be merged; ' +
                '<strong>' + reviews + '</strong> review item(s)' +
                (notes.length ? '; ' + notes.join(', ') : '') + '.',
                reviews > 0 ? 'warning' : 'success'
            );
            appendConsole('Event Sync scan: ' + c.unimported + ' unimported, ' + c.imported + ' already imported, ' +
                c.woo_tickets + ' Woo / ' + c.tc_tickets + ' TC / ' + c.rsvp_tickets + ' RSVP ticket type(s), ' +
                c.tc_attendees + ' TC attendees, ' + c.rsvp_attendees + ' RSVP rows (' + c.rsvp_merged + ' duplicates), ' +
                reviews + ' review items.', 'info');
        });
    }

    function updateEsProgress(processed, total) {
        var pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 100;
        $('#ekti-event-sync-progress-fill').css('width', pct + '%');
        $('#ekti-event-sync-progress-text').text(pct + '% (' + processed + '/' + total + ')');
    }

    function loopEventSync(ids) {
        var grand = { imported: 0, tickets: 0, cost_tickets: 0, tc_attendees: 0, rsvp_rows: 0, rsvp_merged: 0, woo_attendees: 0, woo_orders: 0, woo_unpaid: 0, errors: 0, skipped: 0 };
        var total = ids ? ids.split(',').filter(function (v) { return $.trim(v) !== ''; }).length : esTotals.unimported;
        var noProgress = 0;
        var firstChunk = true;
        $('#ekti-event-sync-progress-wrap').show();
        updateEsProgress(0, total);

        function step() {
            var payload = {
                chunk: 25,
                import_images: $('#ekti-event-sync-images').prop('checked') ? '1' : '0',
                ids: ids || ''
            };
            // A fresh full run clears the previous run's failure memory so
            // failed events are retried; targeted runs bypass it server-side.
            if (firstChunk && !ids) {
                payload.reset = 'true';
            }
            firstChunk = false;
            ajax('ekti_run_event_sync', payload, function (resp) {
                if (!resp.success) {
                    appendConsole('Event Sync error: ' + resp.data, 'error');
                    setStatus('#ekti-event-sync-status', 'Event Sync failed: ' + escapeHtml(resp.data), 'error');
                    esRunning = false;
                    $('#ekti-event-sync-run').prop('disabled', false);
                    return;
                }
                var d = resp.data;

                $.each(d.results || [], function (i, r) {
                    if (r.status === 'error') {
                        appendConsole('\u2717 Error TEC ' + r.tec_id + ' (' + escapeHtml(r.title || '?') + '): ' + escapeHtml(r.error) + ' — rolled back', 'error');
                    } else {
                        var bits = ['\u2713 ' + escapeHtml(r.title) + ' (TEC ' + r.tec_id + ' \u2192 EK #' + r.event_id + ')'];
                        if (r.tickets) bits.push(r.tickets + ' ticket type(s)');
                        if (r.woo_attendees) bits.push(r.woo_attendees + ' Woo attendee(s) / ' + r.woo_orders + ' order(s)' + (r.woo_unpaid ? ' (' + r.woo_unpaid + ' refunded/unpaid, not counted as sold)' : ''));
                        if (r.tc_attendees) bits.push(r.tc_attendees + ' TC attendee(s)');
                        if (r.rsvp_rows) bits.push(r.rsvp_rows + ' RSVP row(s)');
                        if (r.rsvp_merged) bits.push(r.rsvp_merged + ' duplicate(s) merged');
                        appendConsole(bits.join(', '));
                    }
                });

                $.each(d.tally || {}, function (k, v) {
                    grand[k] = (grand[k] || 0) + v;
                });

                var processed;
                if (ids) {
                    // Targeted run: only newly handled IDs advance the bar
                    // (already-imported IDs skip silently on later rounds).
                    processed = Math.min(total, (loopEventSync._p || 0) + d.tally.imported + d.tally.errors + (loopEventSync._p ? 0 : d.tally.skipped));
                    loopEventSync._p = processed;
                } else {
                    processed = Math.max(0, total - d.remaining);
                }
                updateEsProgress(processed, total);

                if (d.done) {
                    appendConsole('=== Event Sync complete: ' + grand.imported + ' event(s) imported, ' +
                        (grand.tickets + grand.cost_tickets) + ' ticket type(s), ' +
                        grand.woo_attendees + ' Woo attendee(s) in ' + grand.woo_orders + ' order(s) (' + grand.woo_unpaid + ' refunded/unpaid), ' +
                        grand.tc_attendees + ' TC attendee(s), ' +
                        grand.rsvp_rows + ' RSVP row(s), ' +
                        grand.rsvp_merged + ' duplicate(s) merged, ' +
                        grand.errors + ' error(s) ===', 'info');
                    setStatus('#ekti-event-sync-status',
                        'Event Sync complete: <strong>' + grand.imported + '</strong> event(s) imported' +
                        (grand.rsvp_merged ? ', <strong>' + grand.rsvp_merged + '</strong> duplicate RSVP row(s) merged' : '') +
                        (grand.errors ? ', <strong>' + grand.errors + '</strong> error(s) rolled back — see console' : '') + '.',
                        grand.errors ? 'warning' : 'success'
                    );
                    esRunning = false;
                    loopEventSync._p = 0;
                    updateEsProgress(total, total);
                    scanEventSync();
                    loadStats();
                } else {
                    // No-progress guard: three chunks with nothing processed = stall.
                    if (d.processed === 0) {
                        noProgress++;
                        if (noProgress >= 3) {
                            appendConsole('Event Sync stalled (3 chunks without progress) — stopping.', 'error');
                            setStatus('#ekti-event-sync-status', 'Event Sync stalled — no progress across 3 chunks.', 'error');
                            esRunning = false;
                            loopEventSync._p = 0;
                            $('#ekti-event-sync-run').prop('disabled', false);
                            return;
                        }
                    } else {
                        noProgress = 0;
                    }
                    setTimeout(step, 200);
                }
            });
        }
        loopEventSync._p = 0;
        step();
    }

    function runEventSync() {
        if (esRunning) return;
        var ids = $.trim($('#ekti-event-sync-ids').val());
        var label = ids ? 'the specified TEC event(s) [' + ids + ']' : esTotals.unimported + ' TEC event(s)';
        if (!confirm('Import ' + label + ' into EventKoi? Already-imported events are skipped, per-event failures roll back, and everything is audited and undoable.')) {
            return;
        }
        esRunning = true;
        $('#ekti-event-sync-run, #ekti-event-sync-adopt').prop('disabled', true);
        appendConsole('=== Starting Event Sync' + (ids ? ' (targeted: ' + ids + ')' : '') + ' ===', 'info');
        loopEventSync(ids || '');
    }

    function adoptNativeImports() {
        if (!esScan || !esScan.adoption || !esScan.adoption.candidate_events) return;
        var ad = esScan.adoption;
        if (!confirm('Flag ' + ad.unflagged_attendees + ' WooCommerce attendee(s) on ' + ad.candidate_events +
            ' natively-imported event(s) as already imported, so Run Migration will not duplicate them?')) {
            return;
        }
        appendConsole('=== Adopting Native Imports ===', 'warn');
        ajax('ekti_adopt_native_imports', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Adopt error: ' + resp.data, 'error');
                setStatus('#ekti-event-sync-status', 'Adopt failed: ' + escapeHtml(resp.data), 'error');
                return;
            }
            appendConsole('Adopted ' + resp.data.flagged + ' attendee(s) across ' + resp.data.events + ' event(s).', 'warn');
            setStatus('#ekti-event-sync-status',
                'Adopted <strong>' + resp.data.flagged + '</strong> natively-imported attendee(s) across <strong>' + resp.data.events + '</strong> event(s).',
                'success'
            );
            scanEventSync();
            loadStats();
        });
    }

    function undoEventSync() {
        if (!confirm('Undo the last Event Sync run? Events it created are permanently deleted with their tickets, orders and RSVP rows; adoption flags and other writes are reverted.')) {
            return;
        }
        appendConsole('=== Undoing Last Event Sync ===', 'warn');
        ajax('ekti_undo_event_sync', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Undo error: ' + resp.data, 'error');
                return;
            }
            var counts = resp.data.undone || {};
            $.each(counts, function (type, n) {
                appendConsole('  ' + type + ': ' + n, 'warn');
            });
            appendConsole('Undo complete.', 'warn');
            scanEventSync();
            loadStats();
        });
    }

    /* ------------------------------------------------------------------ */
    /*  IMPORT WIZARD (v1.9.0)                                            */
    /* ------------------------------------------------------------------ */

    var WZ_STEPS = ['preflight', 'taxonomies', 'events', 'tickets', 'orders', 'finish'];
    var WZ_LABELS = { events: 'events', tickets: 'tickets', orders: 'orders' };
    var wz = { summary: null, running: false, verifyOk: {}, opened: false };

    function wzApproved(step) {
        return !!(wz.summary && wz.summary.approved && wz.summary.approved[step]);
    }

    function wzPrev(step) {
        var i = WZ_STEPS.indexOf(step);
        return i > 0 ? WZ_STEPS[i - 1] : null;
    }

    function wzLocked(step) {
        if (step === 'preflight' || step === 'taxonomies') {
            return false; // Taxonomy setup can be reopened at any time.
        }
        var prev = wzPrev(step);
        return prev ? !wzApproved(prev) : false;
    }

    function wzActiveStep() {
        for (var i = 0; i < WZ_STEPS.length - 1; i++) {
            if (!wzApproved(WZ_STEPS[i])) {
                return WZ_STEPS[i];
            }
        }
        return 'finish';
    }

    function wzStage(stage) {
        return (wz.summary && wz.summary.stages && wz.summary.stages[stage]) || { done: 0, remaining: 0, failed: 0, ledger: 0 };
    }

    function wzOpen(step) {
        if (wzLocked(step)) return;
        $('.ekti-step[data-step="' + step + '"]').addClass('is-open');
    }

    function renderWizard() {
        var active = wzActiveStep();
        $.each(WZ_STEPS, function (i, step) {
            var $li = $('#ekti-stepper li[data-step="' + step + '"]');
            var $sec = $('.ekti-step[data-step="' + step + '"]');
            var approved = wzApproved(step);
            var locked = wzLocked(step);
            $li.toggleClass('is-approved', approved).toggleClass('is-active', step === active).toggleClass('is-locked', locked);
            $sec.toggleClass('is-locked', locked);
            if (locked) {
                $sec.removeClass('is-open');
            }

            var $badge = $sec.find('.ekti-step-badge').removeClass('is-approved is-active is-warning');
            if (approved) {
                $badge.addClass('is-approved').text('Approved');
            } else if (locked) {
                $badge.text('Locked');
            } else if (WZ_LABELS[step]) {
                var st = wzStage(step);
                if (st.done > 0 && st.remaining > 0) {
                    $badge.addClass('is-active').text(st.done + ' done, ' + st.remaining + ' to go');
                } else if (st.done > 0) {
                    $badge.addClass(st.failed ? 'is-warning' : 'is-active').text('Imported — review' + (st.failed ? ' (' + st.failed + ' failed)' : ''));
                } else {
                    $badge.addClass('is-active').text('Ready');
                }
            } else if (step === 'finish') {
                $badge.text(active === 'finish' ? 'Done' : '');
            } else {
                $badge.addClass('is-active').text('To do');
            }
        });

        // Stage buttons.
        $.each(['events', 'tickets', 'orders'], function (i, stage) {
            var st = wzStage(stage);
            var prevOk = wzApproved(wzPrev(stage));
            $('#ekti-wz-' + stage + '-run').prop('disabled', wz.running || !prevOk || st.remaining === 0 || wzApproved(stage));
            $('#ekti-wz-' + stage + '-preview').prop('disabled', wz.running || !prevOk);
            $('#ekti-wz-' + stage + '-check').prop('disabled', wz.running || st.done === 0);
            $('#ekti-wz-' + stage + '-approve').prop('disabled', wz.running || wzApproved(stage) || st.remaining > 0 || !wz.verifyOk[stage]);
            $('#ekti-wz-' + stage + '-undo').prop('disabled', wz.running || st.ledger === 0);
        });
        $('#ekti-wz-taxonomies-approve').prop('disabled', wz.running || wzApproved('taxonomies') || !wzApproved('preflight'));
        $('#ekti-wz-undo-all').prop('disabled', wz.running);

        renderSetupCards();
        renderFinish();
        renderAttendeePanel();

        if (!wz.opened) {
            wz.opened = true;
            wzOpen(active);
        }
    }

    function refreshWizard(cb) {
        ajax('ekti_wizard_state', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Wizard state error: ' + resp.data, 'error');
                return;
            }
            wz.summary = resp.data;
            $.each(['events', 'tickets', 'orders'], function (i, stage) {
                if (resp.data.verified && resp.data.verified[stage]) {
                    wz.verifyOk[stage] = !!resp.data.verified[stage].ok;
                }
            });
            renderWizard();
            if (cb) cb();
        });
    }

    function approveStep(step, after) {
        ajax('ekti_wizard_approve', { step: step }, function (resp) {
            if (!resp.success) {
                appendConsole('Approve "' + step + '": ' + resp.data, 'error');
                setStatus('#ekti-wz-' + step + '-status', escapeHtml(resp.data), 'error');
                return;
            }
            appendConsole('Step "' + step + '" approved.', 'info');
            wz.summary = resp.data;
            renderWizard();
            var next = WZ_STEPS[WZ_STEPS.indexOf(step) + 1];
            $('.ekti-step[data-step="' + step + '"]').removeClass('is-open');
            if (next) {
                wzOpen(next);
                $('html, body').animate({ scrollTop: $('.ekti-step[data-step="' + next + '"]').offset().top - 60 }, 200);
            }
            if (after) after();
        });
    }

    /* Step 1: Preflight ------------------------------------------------- */

    function runPreflight() {
        setStatus('#ekti-wz-preflight-status', '<span class="ekti-spinner"></span> Checking...', 'info');
        ajax('ekti_preflight', {}, function (resp) {
            if (!resp.success) {
                setStatus('#ekti-wz-preflight-status', 'Checks failed: ' + escapeHtml(resp.data), 'error');
                return;
            }
            var d = resp.data;
            var $tb = $('#ekti-wz-preflight-table tbody').empty();
            var labels = { ok: 'OK', warn: 'Check', fail: 'Blocked' };
            $.each(d.checks, function (i, c) {
                $tb.append('<tr><td>' + escapeHtml(c.label) + '</td><td><span class="ekti-pill ' + c.status + '">' + labels[c.status] + '</span></td><td>' + escapeHtml(c.detail) + '</td></tr>');
            });
            $('#ekti-wz-preflight-table').show();
            var warns = d.checks.filter(function (c) { return c.status === 'warn'; }).length;
            setStatus('#ekti-wz-preflight-status',
                d.ok ? ('All required checks passed' + (warns ? ' — ' + warns + ' item(s) to look at before continuing.' : '.')) : 'Fix the blocked items, then run the checks again.',
                d.ok ? (warns ? 'warning' : 'success') : 'error');
            $('#ekti-wz-preflight-approve').prop('disabled', !d.ok || wzApproved('preflight'));
        });
    }

    /* Step 2a: taxonomy setup cards ------------------------------------ */

    var SLOT_LABELS = {
        address1: 'Address line 1', address2: 'Address line 2', city: 'City', state: 'State',
        country: 'Country', zip: 'ZIP', latitude: 'Latitude', longitude: 'Longitude'
    };

    function setupStatusLine(st, type) {
        var name = type === 'locations' ? 'Locations' : 'Instructors';
        switch (st.status) {
            case 'ready':
                if (type === 'locations') {
                    return '<span class="ekti-pill ok">Ready</span> Taxonomy <strong>' + escapeHtml(st.target) + '</strong> is the Saved Locations taxonomy, ' +
                        Object.keys(st.map || {}).length + ' of ' + st.slots.length + ' address slots mapped.';
                }
                return '<span class="ekti-pill ok">Ready</span> Taxonomy <strong>' + escapeHtml(st.target) + '</strong> exists.';
            case 'not_designated':
                return '<span class="ekti-pill warn">Not designated</span> Taxonomy <strong>' + escapeHtml(st.target) + '</strong> exists but is not set as Saved Locations' +
                    (st.designated ? ' (currently: ' + escapeHtml(st.designated) + ')' : '') + '.';
            case 'unmapped':
                return '<span class="ekti-pill warn">Slots unmapped</span> Saved Locations is on for <strong>' + escapeHtml(st.target) + '</strong> but only ' +
                    Object.keys(st.map || {}).length + ' of ' + st.slots.length + ' address slots are mapped.';
            case 'unsupported':
                return '<span class="ekti-pill fail">Unavailable</span> ' + escapeHtml(st.message);
            default:
                return '<span class="ekti-pill">Not set up</span> No ' + name + ' taxonomy exists in EventKoi yet.';
        }
    }

    function renderSetupCards() {
        if (!wz.summary || !wz.summary.taxonomies) return;
        $.each(['locations', 'instructors'], function (i, type) {
            var st = wz.summary.taxonomies[type];
            var $card = $('#ekti-setup-' + type);
            // Keep a form the user is editing.
            if ($card.data('editing')) return;
            var title = type === 'locations' ? 'Locations (Saved Locations)' : 'Instructors';
            var html = '<h5>' + title + '</h5><p>' + setupStatusLine(st, type) + '</p>';
            if (st.conflict) {
                html += '<p class="description" style="color:#b32d2e;">' + escapeHtml(st.conflict) + '</p>';
            }
            if (st.status !== 'unsupported') {
                html += '<button type="button" class="button ekti-setup-edit" data-type="' + type + '">' +
                    (st.status === 'ready' ? 'Change…' : 'Set up…') + '</button>';
            }
            $card.html(html);
        });
    }

    function fieldRowsHtml(fields) {
        var html = '';
        $.each(fields, function (i, f) {
            html += '<div class="ekti-field-row" style="display:flex;gap:6px;margin:3px 0;">' +
                '<input type="text" class="ekti-f-key" value="' + escapeHtml(f.key) + '" size="12" title="Field key" />' +
                '<input type="text" class="ekti-f-label" value="' + escapeHtml(f.label) + '" size="20" title="Label" /></div>';
        });
        return html;
    }

    function slotGridHtml(keys, map) {
        var html = '<div class="ekti-slot-grid">';
        $.each(Object.keys(SLOT_LABELS), function (i, slot) {
            var opts = '<option value="">— not mapped —</option>';
            $.each(keys, function (j, k) {
                opts += '<option value="' + escapeHtml(k.key) + '"' + (map[slot] === k.key ? ' selected' : '') + '>' + escapeHtml(k.label + ' (' + k.key + ')') + '</option>';
            });
            html += '<label>' + SLOT_LABELS[slot] + '</label><select class="ekti-slot" data-slot="' + slot + '">' + opts + '</select>';
        });
        return html + '</div>';
    }

    function openSetupForm(type) {
        var st = wz.summary.taxonomies[type];
        var $card = $('#ekti-setup-' + type).data('editing', true);
        var defs = st.definitions || [];
        var hasTarget = st.target !== '';
        var useExisting = hasTarget || (type === 'locations' && defs.length > 0 && st.status !== 'missing');
        var p = st.proposed;

        var defOpts = '';
        $.each(defs, function (i, d) {
            var sel = (st.target === d.slug) ? ' selected' : '';
            defOpts += '<option value="' + escapeHtml(d.slug) + '"' + sel + '>' + escapeHtml(d.plural + ' (' + d.slug + ', ' + d.fields.length + ' fields, ' + d.terms + ' terms)') + '</option>';
        });

        var html = '<h5>' + (type === 'locations' ? 'Locations (Saved Locations)' : 'Instructors') + '</h5>' +
            '<p><label><input type="radio" name="ekti-mode-' + type + '" value="create"' + (useExisting ? '' : ' checked') + ' /> Create a new taxonomy</label><br/>' +
            '<label><input type="radio" name="ekti-mode-' + type + '" value="existing"' + (useExisting ? ' checked' : '') + (defs.length ? '' : ' disabled') + ' /> Use an existing EventKoi taxonomy</label></p>' +
            '<div class="ekti-mode-create"' + (useExisting ? ' style="display:none;"' : '') + '>' +
            '<p><label>Slug <input type="text" class="ekti-new-slug" value="' + escapeHtml(p.slug) + '" size="14" /></label> ' +
            '<label>Singular <input type="text" class="ekti-new-singular" value="' + escapeHtml(p.singular) + '" size="12" /></label> ' +
            '<label>Plural <input type="text" class="ekti-new-plural" value="' + escapeHtml(p.plural) + '" size="12" /></label></p>' +
            (type === 'locations' ? '<p class="description">Term fields (key / label). Each one becomes an address field on every location.</p>' + fieldRowsHtml(p.fields) : '<p class="description">Instructor terms carry the name only.</p>') +
            '</div>' +
            '<div class="ekti-mode-existing"' + (useExisting ? '' : ' style="display:none;"') + '>' +
            '<p><select class="ekti-existing-slug">' + defOpts + '</select></p></div>';
        if (type === 'locations') {
            html += '<p class="description" style="margin-top:8px;">Address mapping — which term field fills each part of an event\'s address:</p><div class="ekti-slot-wrap"></div>';
        }
        html += '<div class="ekti-actions"><button type="button" class="button button-primary ekti-setup-save" data-type="' + type + '">Save Taxonomy</button>' +
            '<button type="button" class="button ekti-setup-cancel" data-type="' + type + '">Cancel</button></div>';
        $card.html(html);
        if (type === 'locations') {
            refreshSlotGrid();
        }
    }

    /* The slot grid follows the chosen field source (new rows or an existing definition). */
    function refreshSlotGrid() {
        var st = wz.summary.taxonomies.locations;
        var $card = $('#ekti-setup-locations');
        var mode = $card.find('input[name="ekti-mode-locations"]:checked').val();
        var keys = [];
        var map;
        if (mode === 'existing') {
            var slug = $card.find('.ekti-existing-slug').val();
            $.each(st.definitions || [], function (i, d) {
                if (d.slug === slug) {
                    keys = d.fields;
                }
            });
            map = (slug === st.target && Object.keys(st.map || {}).length) ? st.map : {};
            if (!Object.keys(map).length) {
                // Suggest by matching field keys to the proposed keys.
                $.each(st.proposed_map, function (slot, key) {
                    $.each(keys, function (i, k) {
                        if (k.key === key) map[slot] = key;
                    });
                });
            }
        } else {
            $card.find('.ekti-field-row').each(function () {
                var k = $.trim($(this).find('.ekti-f-key').val());
                if (k) keys.push({ key: k, label: $.trim($(this).find('.ekti-f-label').val()) || k });
            });
            map = st.proposed_map;
        }
        $card.find('.ekti-slot-wrap').html(slotGridHtml(keys, map));
    }

    function saveSetup(type) {
        var $card = $('#ekti-setup-' + type);
        var mode = $card.find('input[name="ekti-mode-' + type + '"]:checked').val();
        var config = { mode: mode };
        if (mode === 'existing') {
            config.existing_slug = $card.find('.ekti-existing-slug').val();
        } else {
            config.slug = $.trim($card.find('.ekti-new-slug').val());
            config.singular = $.trim($card.find('.ekti-new-singular').val());
            config.plural = $.trim($card.find('.ekti-new-plural').val());
            config.fields = [];
            $card.find('.ekti-field-row').each(function () {
                var k = $.trim($(this).find('.ekti-f-key').val());
                if (k) config.fields.push({ key: k, label: $.trim($(this).find('.ekti-f-label').val()) || k });
            });
        }
        if (type === 'locations') {
            config.map = {};
            $card.find('.ekti-slot').each(function () {
                if ($(this).val()) config.map[$(this).data('slot')] = $(this).val();
            });
        }
        var what = mode === 'existing' ? 'use "' + config.existing_slug + '"' : 'create the "' + config.slug + '" taxonomy';
        if (!confirm('Save: ' + what + (type === 'locations' ? ' and set it as EventKoi\'s Saved Locations taxonomy' : '') + '? This changes EventKoi\'s taxonomy settings and can be undone with "Undo Taxonomy Setup".')) {
            return;
        }
        appendConsole('Setting up ' + type + ' taxonomy...', 'info');
        ajax('ekti_provision_taxonomy', { type: type, config: JSON.stringify(config) }, function (resp) {
            if (!resp.success) {
                appendConsole('Taxonomy setup error (' + type + '): ' + resp.data, 'error');
                setStatus('#ekti-wz-taxonomies-status', 'Setup failed: ' + escapeHtml(resp.data), 'error');
                return;
            }
            var d = resp.data;
            appendConsole('✓ ' + type + ' taxonomy "' + d.slug + '" ' + (d.created ? 'created' : 'ready') +
                (d.added && !d.created ? ' (' + d.added + ' field(s) added)' : '') + '.', 'info');
            $card.data('editing', false);
            refreshWizard(function () {
                scanTaxonomySync(type);
            });
        });
    }

    function undoProvision() {
        if (!confirm('Undo the taxonomy setup? EventKoi\'s taxonomy settings are restored and any taxonomy this wizard created is deleted with its terms. Undo the term matching (2b) first.')) {
            return;
        }
        ajax('ekti_undo_taxonomy_provision', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Undo taxonomy setup: ' + resp.data, 'error');
                setStatus('#ekti-wz-taxonomies-status', escapeHtml(resp.data), 'error');
                return;
            }
            appendConsole('Taxonomy setup undone. ' + JSON.stringify(resp.data.undone), 'warn');
            refreshWizard(function () {
                scanTaxonomySync('locations');
                scanTaxonomySync('instructors');
            });
        });
    }

    function approveTaxonomies() {
        var t = wz.summary.taxonomies;
        var warn = [];
        if (t.locations.status !== 'ready') warn.push('the Locations taxonomy is not ready');
        if (!t.mapped.locations) warn.push('no TEC venues are matched to locations');
        if (t.instructors.status !== 'ready') warn.push('the Instructors taxonomy is not set up');
        if (!t.mapped.instructors) warn.push('no TEC organizers are matched to instructors');
        if (warn.length && !confirm('Continue anyway? ' + warn.join('; ') + '. Events imported now will not get those terms (Taxonomy Sync can add them later).')) {
            return;
        }
        approveStep('taxonomies');
    }

    /* Generic chunk loop ------------------------------------------------ */

    function loopChunks(opts) {
        var noProgress = 0;
        var first = true;
        function step() {
            var data = opts.data(first);
            first = false;
            ajax(opts.action, data, function (resp) {
                if (!resp.success) {
                    opts.onError(resp.data);
                    return;
                }
                var d = resp.data;
                var moved = opts.onChunk(d);
                if (opts.isDone(d)) {
                    opts.onDone();
                    return;
                }
                noProgress = moved ? 0 : noProgress + 1;
                if (noProgress >= 3) {
                    opts.onError('stalled — no progress across 3 requests');
                    return;
                }
                setTimeout(step, 150);
            });
        }
        step();
    }

    function wzProgress(stage, processed, total) {
        var pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 100;
        var $p = $('#ekti-wz-' + stage + '-progress').show();
        $p.find('.ekti-progress-fill').css('width', pct + '%');
        $p.find('.ekti-progress-text').text(pct + '% (' + processed + '/' + total + ')');
    }

    /* Steps 3-5: previews ----------------------------------------------- */

    function tiles(items) {
        var html = '<div class="ekti-stats-grid">';
        $.each(items, function (i, it) {
            html += '<div class="ekti-stat"><span class="ekti-stat-value">' + escapeHtml(String(it[1])) + '</span><span class="ekti-stat-label">' + escapeHtml(it[0]) + '</span></div>';
        });
        return html + '</div>';
    }

    function previewEvents() {
        setStatus('#ekti-wz-events-status', '<span class="ekti-spinner"></span> Scanning TEC events...', 'info');
        ajax('ekti_scan_event_sync', {}, function (resp) {
            if (!resp.success) {
                setStatus('#ekti-wz-events-status', 'Preview failed: ' + escapeHtml(resp.data), 'error');
                return;
            }
            var c = resp.data.counts;
            var html = tiles([
                ['TEC events', c.tec_total], ['To import', c.unimported], ['Already in EventKoi', c.imported],
                ['Published / draft', c.by_status.publish + ' / ' + c.by_status.draft], ['With images', c.with_images],
                ['Ticket types', c.woo_tickets + c.tc_tickets + c.rsvp_tickets], ['Woo attendees', c.woo_attendees]
            ]);
            var review = resp.data.review || [];
            if (review.length) {
                html += '<h4>Review items (' + review.length + ')</h4><div class="ekti-wz-scroll"><table class="wp-list-table widefat fixed striped"><thead><tr><th style="width:10%">TEC ID</th><th>Title</th><th>Reason</th><th>Detail</th></tr></thead><tbody>';
                $.each(review, function (i, r) {
                    html += '<tr><td>' + (r.tec_id || '') + '</td><td>' + escapeHtml(r.title) + '</td><td>' + escapeHtml(r.reason) + '</td><td>' + escapeHtml(r.detail) + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            if (resp.data.adoption && resp.data.adoption.candidate_events) {
                html += '<div class="notice notice-warning inline"><p>' + resp.data.adoption.candidate_events + ' natively imported event(s) already carry attendee history. Run "Adopt Native Imports" under Advanced tools &rarr; Event Sync before the order matching step.</p></div>';
            }
            $('#ekti-wz-events-preview-out').html(html);
            var noStart = review.filter(function (r) { return r.reason === 'No start date'; }).length;
            setStatus('#ekti-wz-events-status', '<strong>' + (c.unimported - noStart) + '</strong> event(s) will be imported' +
                (noStart ? '; <strong>' + noStart + '</strong> without a start date will be reported and skipped' : '') + '.', review.length ? 'warning' : 'success');
            refreshWizard();
        });
    }

    function previewTickets() {
        setStatus('#ekti-wz-tickets-status', '<span class="ekti-spinner"></span> Building the ticket plan...', 'info');
        ajax('ekti_preview_tickets', {}, function (resp) {
            if (!resp.success) {
                setStatus('#ekti-wz-tickets-status', 'Preview failed: ' + escapeHtml(resp.data), 'error');
                return;
            }
            var c = resp.data.counts;
            var html = tiles([
                ['Events', c.events], ['Ticketed events', c.ticketed_events], ['Ticket types', c.tickets],
                ['WooCommerce', c.woo], ['RSVP-only events', c.rsvp_events], ['From cost field', c.cost_tickets],
                ['Unlimited capacity', c.unlimited], ['No tickets', c.no_tickets]
            ]);
            var flags = [];
            if (c.multi_product) flags.push(c.multi_product + ' event(s) with several ticket types');
            if (c.inverted_windows) flags.push(c.inverted_windows + ' ticket(s) whose sale window ends before it starts');
            if (c.unpriced) flags.push(c.unpriced + ' ticket(s) imported without a price (currency mismatch)');
            var rows = 0;
            html += '<div class="ekti-wz-scroll"><table class="wp-list-table widefat striped"><thead><tr><th>Event</th><th>Ticket</th><th>Price</th><th>Capacity</th><th>Sold in TEC</th><th>Available now</th><th>On sale (site time)</th><th>Flags</th></tr></thead><tbody>';
            $.each(resp.data.events, function (i, ev) {
                if (rows > 400) return false;
                if (ev.mode === 'rsvp') {
                    html += '<tr><td>' + escapeHtml(ev.title) + '</td><td colspan="7"><em>RSVP only</em></td></tr>';
                    rows++;
                    return;
                }
                $.each(ev.tickets, function (j, t) {
                    rows++;
                    var cap = t.capacity === null ? 'unlimited' : t.capacity;
                    var avail = t.available === null ? '—' : t.available;
                    var win = (t.sale_start || '—') + ' → ' + (t.sale_end || '—');
                    html += '<tr><td>' + (j === 0 ? escapeHtml(ev.title) : '') + '</td><td>' + escapeHtml(t.name) + ' <span class="ekti-pill">' + t.provider + '</span></td>' +
                        '<td>' + Number(t.price).toFixed(2) + '</td><td>' + cap + '</td><td>' + t.sold + '</td><td>' + avail + '</td><td style="font-size:11px;">' + escapeHtml(win) + '</td>' +
                        '<td style="font-size:11px;color:#8a6d00;">' + (j === 0 ? escapeHtml(ev.flags.join('; ')) : '') + '</td></tr>';
                });
            });
            html += '</tbody></table></div>';
            if (rows > 400) {
                html += '<p class="description">Showing the first 400 rows.</p>';
            }
            html += '<p class="description">"Available now" holds back the seats TEC already sold; each imported paid attendee gives one seat back in the next step, so the final number matches TEC.</p>';
            $('#ekti-wz-tickets-preview-out').html(html);
            setStatus('#ekti-wz-tickets-status', '<strong>' + c.tickets + '</strong> ticket type(s) across <strong>' + c.events + '</strong> event(s).' + (flags.length ? ' Review: ' + escapeHtml(flags.join('; ')) + '.' : ''), flags.length ? 'warning' : 'success');
        });
    }

    function previewOrders() {
        var agg = { events: 0, woo_rows: 0, woo_orders: 0, woo_unpaid: 0, by_status: {}, tc_attendees: 0, rsvp_rows: 0, rsvp_merged: 0, not_linked: 0, multi_person_orders: 0, fields: {} };
        var rows = [];
        var extra = {};
        var offset = 0;
        var total = 0;
        setStatus('#ekti-wz-orders-status', '<span class="ekti-spinner"></span> Dry-running the order matching...', 'info');
        loopChunks({
            action: 'ekti_preview_orders',
            data: function () { return { offset: offset }; },
            onChunk: function (d) {
                total = d.total;
                if (d.offset === 0) {
                    extra = { orphans: d.orphan_attendees, multi: d.multi_event_orders };
                }
                $.each(d.agg.fields || {}, function (k, label) {
                    agg.fields[k] = label;
                });
                $.each(['events', 'woo_rows', 'woo_orders', 'woo_unpaid', 'tc_attendees', 'rsvp_rows', 'rsvp_merged', 'not_linked', 'multi_person_orders'], function (i, k) {
                    agg[k] += d.agg[k];
                });
                $.each(d.agg.by_status, function (s, n) {
                    agg.by_status[s] = (agg.by_status[s] || 0) + n;
                });
                rows = rows.concat(d.rows);
                var moved = d.next > offset;
                offset = d.next;
                wzProgress('orders', offset, total);
                return moved;
            },
            isDone: function (d) { return d.done; },
            onError: function (msg) {
                setStatus('#ekti-wz-orders-status', 'Preview failed: ' + escapeHtml(msg), 'error');
            },
            onDone: function () {
                $('#ekti-wz-orders-progress').hide();
                var statuses = '';
                $.each(agg.by_status, function (s, n) {
                    var cls = (s === 'complete' || s === 'partially_refunded') ? 'ok' : 'warn';
                    statuses += '<span class="ekti-pill ' + cls + '">' + escapeHtml(s) + ': ' + n + '</span>';
                });
                var html = tiles([
                    ['Events', agg.events], ['Woo attendees to link', agg.woo_rows], ['Event \u00d7 order links', agg.woo_orders],
                    ['Not counted as sold', agg.woo_unpaid], ['TC attendees', agg.tc_attendees], ['RSVP rows', agg.rsvp_rows],
                    ['Cannot be linked', agg.not_linked]
                ]);
                html += '<p>Order status mapping: ' + (statuses || '—') + '</p>';
                var fieldLabels = $.map(agg.fields, function (label) { return label; });
                html += '<p>Attendee details: <strong>' + agg.multi_person_orders + '</strong> order(s) list more than one person; registration answers carried: ' +
                    escapeHtml(fieldLabels.length ? fieldLabels.join(', ') : 'none') + '.</p>';
                html += '<p class="description">' + (extra.multi || 0) + ' WooCommerce order(s) span several events (each seat stays on its own event). ' +
                    (extra.orphans || 0) + ' TEC attendee(s) belong to events that no longer exist and are not imported.</p>';
                var noted = rows.filter(function (r) { return r.notes && r.notes.length; });
                var show = noted.concat(rows.filter(function (r) { return !(r.notes && r.notes.length); })).slice(0, 300);
                html += '<div class="ekti-wz-scroll"><table class="wp-list-table widefat striped"><thead><tr><th>Event</th><th>Woo attendees</th><th>Orders</th><th>Statuses</th><th>Other</th><th>Notes</th></tr></thead><tbody>';
                $.each(show, function (i, r) {
                    var st = [];
                    $.each(r.by_status || {}, function (s, n) { st.push(s + ' ' + n); });
                    html += '<tr><td>' + escapeHtml(r.title) + '</td><td>' + r.woo_rows + '</td><td>' + r.orders + '</td><td style="font-size:11px;">' + escapeHtml(st.join(', ')) + '</td>' +
                        '<td style="font-size:11px;">' + escapeHtml(r.other || '') + '</td><td style="font-size:11px;color:#b32d2e;">' + escapeHtml((r.notes || []).join(' ')) + '</td></tr>';
                });
                html += '</tbody></table></div>';
                $('#ekti-wz-orders-preview-out').html(html);
                setStatus('#ekti-wz-orders-status', '<strong>' + agg.woo_rows + '</strong> WooCommerce attendee(s) will be linked to their orders' +
                    (noted.length ? '; ' + noted.length + ' event(s) have notes (listed first).' : '.'), (noted.length || agg.not_linked) ? 'warning' : 'success');
            }
        });
    }

    /* Steps 3-5: run, verify, undo ------------------------------------- */

    /* Plain text: appendConsole() escapes. */
    function stageResultLine(stage, r) {
        if (r.status === 'error') {
            return ['✗ ' + (r.title || '?') + ' (' + (r.tec_id ? 'TEC ' + r.tec_id : 'EK #' + r.event_id) + '): ' + r.error + ' — rolled back', 'error'];
        }
        var bits = ['✓ ' + r.title + ' (TEC ' + r.tec_id + ' → EK #' + r.event_id + ')'];
        if (stage === 'events') {
            var tx = r.taxonomies || {};
            if (tx.locations) bits.push('location');
            if (tx.instructors) bits.push(tx.instructors + ' instructor(s)');
        } else if (stage === 'tickets') {
            bits.push(r.tickets + ' ticket type(s)' + (r.cost_ticket ? ' + cost ticket' : '') + ', ' + r.attendance_mode);
        } else {
            if (r.woo_attendees) bits.push(r.woo_attendees + ' Woo attendee(s) / ' + r.woo_orders + ' order(s)' + (r.woo_unpaid ? ' (' + r.woo_unpaid + ' not sold)' : ''));
            if (r.tc_attendees) bits.push(r.tc_attendees + ' TC attendee(s)');
            if (r.rsvp_rows) bits.push(r.rsvp_rows + ' RSVP row(s)' + (r.rsvp_merged ? ', ' + r.rsvp_merged + ' merged' : ''));
            $.each(r.notes || [], function (i, n) { bits.push(n); });
        }
        return [bits.join(', '), ''];
    }

    function runStage(stage) {
        if (wz.running) return;
        var st = wzStage(stage);
        if (!confirm('Import ' + WZ_LABELS[stage] + ' for ' + st.remaining + ' event(s)? Each event is rolled back on its own if it fails, and the step can be undone.')) {
            return;
        }
        wz.running = true;
        renderWizard();
        $('#ekti-wz-' + stage + '-verify').empty();
        appendConsole('=== Wizard: importing ' + WZ_LABELS[stage] + ' ===', 'info');
        var total = st.remaining;
        var ok = 0;
        var errors = 0;
        wzProgress(stage, 0, total);
        loopChunks({
            action: 'ekti_run_event_stage',
            data: function (first) {
                var data = { stage: stage };
                if (first) data.reset = 'true';
                if (stage === 'events') data.import_images = $('#ekti-wz-events-images').prop('checked') ? '1' : '0';
                return data;
            },
            onChunk: function (d) {
                $.each(d.results || [], function (i, r) {
                    var line = stageResultLine(stage, r);
                    appendConsole(line[0], line[1]);
                });
                ok += d.tally.imported;
                errors += d.tally.errors;
                wzProgress(stage, Math.max(0, total - d.remaining), total);
                return d.processed > 0;
            },
            isDone: function (d) { return d.done; },
            onError: function (msg) {
                appendConsole('Wizard ' + stage + ' stage error: ' + msg, 'error');
                setStatus('#ekti-wz-' + stage + '-status', 'Stopped: ' + escapeHtml(msg) + '. Run it again to resume.', 'error');
                wz.running = false;
                refreshWizard();
            },
            onDone: function () {
                wz.running = false;
                appendConsole('=== ' + WZ_LABELS[stage] + ' stage complete: ' + ok + ' event(s), ' + errors + ' error(s) ===', 'info');
                setStatus('#ekti-wz-' + stage + '-status', 'Done: <strong>' + ok + '</strong> event(s) processed' + (errors ? ', <strong>' + errors + '</strong> failed and rolled back' : '') + '. Review the results below, then approve.', errors ? 'warning' : 'success');
                refreshWizard(function () { verifyStage(stage); });
            }
        });
    }

    function verifyStage(stage) {
        var $out = $('#ekti-wz-' + stage + '-verify').html('<p><span class="ekti-spinner"></span> Checking results...</p>');
        ajax('ekti_verify_stage', { stage: stage }, function (resp) {
            if (!resp.success) {
                $out.html('<div class="notice notice-error inline"><p>' + escapeHtml(resp.data) + '</p></div>');
                return;
            }
            var v = resp.data;
            var ok = v.ok && v.remaining === 0;
            wz.verifyOk[stage] = ok;
            var html = '<h4>Results check</h4><table class="wp-list-table widefat fixed striped"><tbody>';
            html += '<tr><td style="width:45%">Events done</td><td>' + v.done + '</td></tr>';
            html += '<tr><td>Still pending</td><td>' + v.remaining + '</td></tr>';
            html += '<tr><td>Failed (rolled back)</td><td>' + v.failed.length + '</td></tr>';
            if (v.by_status) {
                var bs = [];
                $.each(v.by_status, function (s, n) { bs.push(s + ': ' + n); });
                html += '<tr><td>' + (stage === 'orders' ? 'Attendee rows by status' : 'Events by status') + '</td><td>' + escapeHtml(bs.join(', ')) + '</td></tr>';
            }
            $.each(v.checks, function (i, c) {
                html += '<tr><td>' + escapeHtml(c.label) + '</td><td>' + escapeHtml(String(c.value)) + '</td></tr>';
            });
            html += '</tbody></table>';
            if (v.samples && v.samples.length) {
                html += '<p>Spot-check: ';
                $.each(v.samples, function (i, s) {
                    html += '<a href="' + escapeHtml(s.url) + '" target="_blank" rel="noopener">' + escapeHtml(s.title) + '</a>' + (i < v.samples.length - 1 ? ' · ' : '');
                });
                html += '</p>';
            }
            if (v.failed.length) {
                html += '<h4>Failed</h4><div class="ekti-wz-scroll"><table class="wp-list-table widefat striped"><tbody>';
                $.each(v.failed, function (i, f) {
                    html += '<tr><td style="width:10%">' + f.id + '</td><td>' + escapeHtml(f.title) + '</td><td>' + escapeHtml(f.error) + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            if (v.mismatches.length) {
                html += '<h4 style="color:#b32d2e;">Mismatches (' + v.mismatches.length + ')</h4><div class="ekti-wz-scroll"><table class="wp-list-table widefat striped"><tbody>';
                $.each(v.mismatches, function (i, m) {
                    html += '<tr><td style="width:10%">' + m.id + '</td><td>' + escapeHtml(m.title) + '</td><td>' + escapeHtml(m.detail) + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            html += ok
                ? '<div class="notice notice-success inline"><p>Checks passed' + (v.failed.length ? ' (failed events are listed above and stay out of the import)' : '') + '. Approve to continue.</p></div>'
                : '<div class="notice notice-warning inline"><p>' + (v.remaining ? 'Some events are still pending — run the step again. ' : '') + (v.mismatches.length ? 'Resolve the mismatches (or undo this step) before approving.' : '') + '</p></div>';
            $out.html(html);
            renderWizard();
        });
    }

    function undoStage(stage, quiet, after) {
        if (!quiet && !confirm('Undo the ' + WZ_LABELS[stage] + ' step? Everything it wrote is removed' + (stage === 'events' ? ' (imported events are permanently deleted)' : '') + '.')) {
            return;
        }
        wz.running = true;
        renderWizard();
        appendConsole('=== Undoing ' + WZ_LABELS[stage] + ' step ===', 'warn');
        var undone = 0;
        loopChunks({
            action: 'ekti_undo_event_stage',
            data: function () { return { stage: stage }; },
            onChunk: function (d) {
                undone += d.ops;
                appendConsole('  ' + d.ops + ' op(s) reverted, ' + d.remaining + ' remaining', 'warn');
                return d.ops > 0;
            },
            isDone: function (d) { return d.done; },
            onError: function (msg) {
                wz.running = false;
                appendConsole('Undo ' + stage + ': ' + msg, 'error');
                setStatus('#ekti-wz-' + stage + '-status', escapeHtml(msg), 'error');
                refreshWizard();
                if (after) after(msg);
            },
            onDone: function () {
                wz.running = false;
                wz.verifyOk[stage] = false;
                appendConsole(WZ_LABELS[stage] + ' step undone (' + undone + ' op(s)).', 'warn');
                $('#ekti-wz-' + stage + '-verify, #ekti-wz-' + stage + '-preview-out').empty();
                setStatus('#ekti-wz-' + stage + '-status', 'Undone.', 'info');
                refreshWizard();
                if (after) after();
            }
        });
    }

    function undoEverything() {
        if (!confirm('Undo the entire import? Orders, tickets and events are removed (events permanently deleted), term matching is reverted and the taxonomy setup is restored. This can take a few minutes.')) {
            return;
        }
        var stages = ['orders', 'tickets', 'events'];
        function nextStage() {
            var stage = stages.shift();
            if (!stage) {
                return undoTerms(['instructors', 'locations']);
            }
            if (wzStage(stage).ledger === 0) {
                return nextStage();
            }
            undoStage(stage, true, function (err) {
                if (err) {
                    setStatus('#ekti-wz-finish-status', 'Stopped while undoing ' + stage + ': ' + escapeHtml(err), 'error');
                    return;
                }
                nextStage();
            });
        }
        function undoTerms(types) {
            var type = types.shift();
            if (!type) {
                ajax('ekti_attendee_fields', { op: 'remove' }, function () {});
                ajax('ekti_undo_taxonomy_provision', {}, function () {
                    appendConsole('=== Entire import undone ===', 'warn');
                    setStatus('#ekti-wz-finish-status', 'The import has been undone.', 'success');
                    refreshWizard(function () {
                        scanTaxonomySync('locations');
                        scanTaxonomySync('instructors');
                    });
                });
                return;
            }
            ajax('ekti_undo_taxonomy_sync', { type: type }, function () {
                appendConsole(type + ' term matching reverted.', 'warn');
                undoTerms(types);
            });
        }
        nextStage();
    }


    /* Step 17: attendee details panel ---------------------------------- */

    function renderAttendeePanel() {
        if (!wz.summary || !wz.summary.attendees) return;
        var a = wz.summary.attendees;
        var parts = [];
        parts.push(a.installed ? '<span class="ekti-pill ok">Fields plugin installed</span>' : '<span class="ekti-pill">Fields plugin not installed</span>');
        parts.push(a.fields.length ? 'Fields: ' + escapeHtml(a.fields.join(', ')) : 'No attendee fields recorded yet');
        if (a.repair > 0) {
            parts.push('<span class="ekti-pill warn">' + a.repair + ' imported event(s) need updating</span>');
        }
        $('#ekti-wz-attendees-status').html('<p>' + parts.join(' &middot; ') + '</p>');
        $('#ekti-wz-attendees-repair').prop('disabled', wz.running || a.repair === 0);
        $('#ekti-wz-attendees-install').prop('disabled', wz.running || !a.fields.length).text(a.installed ? 'Reinstall Attendee Fields' : 'Install Attendee Fields');
        $('#ekti-wz-attendees-remove').prop('disabled', wz.running || !a.installed);
    }

    function runAttendeeRepair() {
        var a = wz.summary.attendees;
        if (!confirm('Update ' + a.repair + ' imported event(s): tag attendee rows with EventKoi\'s event instance (so the storefront counts them as sold) and store seat holders + registration answers on the orders? Audited; "Undo Orders" reverts it.')) {
            return;
        }
        wz.running = true;
        renderWizard();
        appendConsole('=== Updating existing attendees ===', 'info');
        var total = a.repair;
        loopChunks({
            action: 'ekti_run_attendee_repair',
            data: function () { return {}; },
            onChunk: function (d) {
                appendConsole('  ' + d.processed + ' event(s), ' + d.rows + ' row(s) re-tagged, ' + d.orders + ' order(s) updated; ' + d.remaining + ' remaining');
                wzProgress('orders', Math.max(0, total - d.remaining), total);
                return d.processed > 0;
            },
            isDone: function (d) { return d.done; },
            onError: function (msg) {
                wz.running = false;
                appendConsole('Attendee update error: ' + msg, 'error');
                refreshWizard();
            },
            onDone: function () {
                wz.running = false;
                appendConsole('Attendee update complete.', 'info');
                refreshWizard(function () { verifyStage('orders'); });
            }
        });
    }

    function attendeeFields(op) {
        if (op === 'remove' && !confirm('Remove the attendee fields plugin? The stored values stay on the orders; they just stop showing in EventKoi.')) {
            return;
        }
        ajax('ekti_attendee_fields', { op: op }, function (resp) {
            if (!resp.success) {
                appendConsole('Attendee fields: ' + resp.data, 'error');
                setStatus('#ekti-wz-attendees-status', escapeHtml(resp.data), 'error');
                return;
            }
            appendConsole('Attendee fields plugin ' + (op === 'install' ? 'installed' : 'removed') + '.', 'info');
            refreshWizard();
        });
    }

    function renderFinish() {
        if (!wz.summary) return;
        var s = wz.summary.stages;
        var all = wzApproved('orders');
        $('#ekti-wz-finish-summary').html(
            '<p>' + (all ? '<span class="ekti-pill ok">Complete</span> ' : '') +
            '<strong>' + s.events.done + '</strong> event(s) imported, <strong>' + s.tickets.done + '</strong> with tickets processed, <strong>' + s.orders.done + '</strong> with orders linked' +
            (s.events.failed ? '; ' + s.events.failed + ' event(s) could not be imported (see the Events step)' : '') + '.</p>'
        );
    }

    function initWizard() {
        $('#ekti-wizard').on('click', '.ekti-step-title', function () {
            var $sec = $(this).closest('.ekti-step');
            if ($sec.hasClass('is-locked')) return;
            $sec.toggleClass('is-open');
        });
        $('#ekti-stepper').on('click', 'li', function () {
            var step = $(this).data('step');
            if (wzLocked(step)) return;
            wzOpen(step);
            $('html, body').animate({ scrollTop: $('.ekti-step[data-step="' + step + '"]').offset().top - 60 }, 200);
        });
        $('#ekti-wz-preflight-run').on('click', runPreflight);
        $('#ekti-wz-preflight-approve').on('click', function () { approveStep('preflight'); });
        $(document).on('click', '.ekti-setup-edit', function () { openSetupForm($(this).data('type')); });
        $(document).on('click', '.ekti-setup-cancel', function () {
            $('#ekti-setup-' + $(this).data('type')).data('editing', false);
            renderSetupCards();
        });
        $(document).on('click', '.ekti-setup-save', function () { saveSetup($(this).data('type')); });
        $(document).on('change', 'input[name^="ekti-mode-"]', function () {
            var $card = $(this).closest('.ekti-setup-card');
            var existing = $(this).val() === 'existing';
            $card.find('.ekti-mode-create').toggle(!existing);
            $card.find('.ekti-mode-existing').toggle(existing);
            if ($card.data('type') === 'locations') refreshSlotGrid();
        });
        $(document).on('change', '#ekti-setup-locations .ekti-existing-slug', refreshSlotGrid);
        $(document).on('change', '#ekti-setup-locations .ekti-f-key', refreshSlotGrid);
        $('#ekti-wz-provision-undo').on('click', undoProvision);
        $('#ekti-wz-taxonomies-approve').on('click', approveTaxonomies);
        $('#ekti-wz-events-preview').on('click', previewEvents);
        $('#ekti-wz-tickets-preview').on('click', previewTickets);
        $('#ekti-wz-orders-preview').on('click', previewOrders);
        $.each(['events', 'tickets', 'orders'], function (i, stage) {
            $('#ekti-wz-' + stage + '-run').on('click', function () { runStage(stage); });
            $('#ekti-wz-' + stage + '-check').on('click', function () { verifyStage(stage); });
            $('#ekti-wz-' + stage + '-approve').on('click', function () { approveStep(stage); });
            $('#ekti-wz-' + stage + '-undo').on('click', function () { undoStage(stage); });
        });
        $('#ekti-wz-undo-all').on('click', undoEverything);
        $('#ekti-wz-attendees-repair').on('click', runAttendeeRepair);
        $('#ekti-wz-attendees-install').on('click', function () { attendeeFields('install'); });
        $('#ekti-wz-attendees-remove').on('click', function () { attendeeFields('remove'); });

        refreshWizard(function () {
            scanTaxonomySync('locations');
            scanTaxonomySync('instructors');
            $.each(['events', 'tickets', 'orders'], function (i, stage) {
                if (wzStage(stage).done > 0 && !wzApproved(stage)) {
                    verifyStage(stage);
                }
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /*  INIT                                                              */
    /* ------------------------------------------------------------------ */

    $(document).ready(function () {
        // Load stats on page load.
        loadStats();

        // Button handlers.
        $('#ekti-refresh-stats').on('click', loadStats);
        $('#ekti-auto-match').on('click', autoMatch);
        $('#ekti-load-mapping').on('click', loadMapping);
        $('#ekti-save-mapping').on('click', saveMapping);
        $('#ekti-start-migration').on('click', function () { startMigration(false); });
        $('#ekti-resume-migration').on('click', function () { startMigration(true); });
        $('#ekti-rollback').on('click', rollback);
        $('#ekti-cleanup-scan').on('click', scanCleanup);
        $('#ekti-cleanup-dedupe').on('click', runDedupe);
        $('#ekti-cleanup-merge').on('click', runMerge);
        $('#ekti-cleanup-undo').on('click', undoCleanup);
        $('#ekti-sync-scan').on('click', scanTicketDetails);
        $('#ekti-sync-run').on('click', runSync);
        $('#ekti-sync-undo').on('click', undoSync);
        $('#ekti-event-sync-scan').on('click', scanEventSync);
        $('#ekti-event-sync-run').on('click', runEventSync);
        $('#ekti-event-sync-adopt').on('click', adoptNativeImports);
        $('#ekti-event-sync-undo').on('click', undoEventSync);
        $('#ekti-calsync-scan').on('click', scanCalendarSync);
        $('#ekti-calsync-save').on('click', saveCalMapping);
        $('#ekti-calsync-run').on('click', runCalSync);
        $('#ekti-calsync-undo').on('click', undoCalSync);
        $('#ekti-tax-locations-scan').on('click', function () { scanTaxonomySync('locations'); });
        $('#ekti-tax-locations-save').on('click', function () { saveTaxMapping('locations'); });
        $('#ekti-tax-locations-run').on('click', function () { runTaxSync('locations'); });
        $('#ekti-tax-locations-undo').on('click', function () { undoTaxSync('locations'); });
        // Toggling overwrite re-evaluates whether a location sync can run
        // (conflicts are only writable with overwrite checked).
        $('#ekti-tax-locations-overwrite').on('change', function () {
            if (taxSyncRunning.locations) return;
            var t = taxSyncTotals.locations;
            var canRun = t.pending > 0 || t.new_terms > 0 || t.field_updates > 0 ||
                ($(this).prop('checked') && t.field_conflicts > 0);
            $('#ekti-tax-locations-run').prop('disabled', !canRun);
        });
        $('#ekti-tax-instructors-scan').on('click', function () { scanTaxonomySync('instructors'); });
        $('#ekti-tax-instructors-save').on('click', function () { saveTaxMapping('instructors'); });
        $('#ekti-tax-instructors-run').on('click', function () { runTaxSync('instructors'); });
        $('#ekti-tax-instructors-undo').on('click', function () { undoTaxSync('instructors'); });
        $('#ekti-tax-instructors-scan-fields').on('click', scanOrganizerFields);
        $('#ekti-tax-instructors-remove-fields').on('click', removeOrganizerFields);
        // Show/hide the create-new-calendar name input.
        $(document).on('change', '.ekti-cal-select', function () {
            var key = $(this).data('loc-key');
            $('.ekti-cal-new-name[data-loc-key="' + key + '"]').toggle($(this).val() === 'new');
        });
        // Show/hide the create-new-taxonomy-term name input.
        $(document).on('change', '.ekti-tax-select', function () {
            var type = $(this).data('tax-type');
            var key  = $(this).data('source-key');
            $('.ekti-tax-new-name[data-tax-type="' + type + '"][data-source-key="' + key + '"]').toggle($(this).val() === 'new');
        });
        $('#ekti-load-log').on('click', loadLog);
        $('#ekti-clear-log').on('click', clearLog);
        initWizard();
    });

})(jQuery);
