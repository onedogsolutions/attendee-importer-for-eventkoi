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
            $('#stat-already-imported').text(d.already_imported);
            totalAttendees = d.mapped_attendees;
            appendConsole('Stats loaded. ' + d.mapped_attendees + ' attendees ready to import.', 'info');
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
                    appendConsole('✓ Created attendee #' + r.attendee_id + ': ' + (r.name || ''));
                } else if (r.action === 'dry_run_create') {
                    appendConsole('[DRY] Would create: ' + (r.name || '') + ' <' + (r.email || '') + '> → EventKoi #' + r.ek_event_id + ' @ $' + (r.price || '0'));
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
                'Created: <strong>' + state.attendees_created + '</strong> | ' +
                'Skipped: <strong>' + state.skipped + '</strong> | ' +
                'Errors: <strong>' + state.errors + '</strong>',
                state.errors > 0 ? 'warning' : 'info'
            );

            if (d.done || state.completed) {
                appendConsole('=== Migration ' + (dryRun ? 'Dry Run ' : '') + 'Complete ===', 'info');
                appendConsole('Total processed: ' + state.processed, 'info');
                appendConsole('Created: ' + state.attendees_created + ' | Skipped: ' + state.skipped + ' | Errors: ' + state.errors, 'info');
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
        if (!confirm('This will DELETE all imported ticket orders and ticket types created by this importer. Continue?')) {
            return;
        }
        appendConsole('=== Running Rollback ===', 'warn');
        ajax('ekti_rollback', {}, function (resp) {
            if (!resp.success) {
                appendConsole('Rollback error: ' + resp.data, 'error');
                return;
            }
            var d = resp.data;
            appendConsole('Rollback complete. Deleted ' + d.deleted_orders + ' ticket orders and ' + d.deleted_tickets + ' ticket types.', 'warn');
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
        $('#ekti-load-log').on('click', loadLog);
        $('#ekti-clear-log').on('click', clearLog);
    });

})(jQuery);
