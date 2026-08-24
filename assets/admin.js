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
                if (row.match_source === 'source') {
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
            setStatus('#ekti-mapping-status',
                'Auto-match complete: <strong>' + d.matched + '</strong> matched (' +
                d.source_matched + ' via import log, ' +
                d.title_matched + ' exact title, ' +
                d.fuzzy_matched + ' fuzzy), <strong>' + d.unmatched + '</strong> unmatched.',
                d.unmatched > 0 ? 'warning' : 'success'
            );
            appendConsole('Auto-match: ' + d.matched + ' matched (' + d.source_matched + ' source, ' + d.title_matched + ' title, ' + d.fuzzy_matched + ' fuzzy), ' + d.unmatched + ' unmatched.', d.unmatched > 0 ? 'warn' : 'info');
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
                    appendConsole('✓ Created attendee #' + r.attendee_id + ': ' + (r.name || '') + ' [' + (r.composite_key || '') + ']');
                } else if (r.action === 'dry_run_create') {
                    appendConsole('[DRY] Would create: ' + (r.name || '') + ' <' + (r.email || '') + '> → EventKoi #' + r.ek_event_id + ' @ $' + (r.price || '0') + ' | key: ' + (r.composite_key || '') + ' | WC#' + (r.wc_order_id || 'none'));
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
                appendConsole('Attendees: ' + state.attendees_created + ' | Orders: ' + (state.orders_created || 0) + ' | Charges: ' + (state.charges_created || 0) + ' | Skipped: ' + state.skipped + ' | Errors: ' + state.errors, 'info');
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
        if (!confirm('This will DELETE all imported ticket orders, parent orders, charges, notes, and WC order meta created by this importer. Continue?')) {
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
            appendConsole('  Parent orders: ' + d.deleted_parent_orders, 'warn');
            appendConsole('  Charges: ' + d.deleted_charges, 'warn');
            appendConsole('  Notes: ' + d.deleted_notes, 'warn');
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
        $('#ekti-load-log').on('click', loadLog);
        $('#ekti-clear-log').on('click', clearLog);
    });

})(jQuery);
