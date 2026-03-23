/**
 * Transparent Edge Cache — Admin JS.
 */
(function ($) {
    'use strict';

    // Verify localized data is available.
    if (typeof window.flavorEdge === 'undefined') {
        console.error('[TE Cache] flavorEdge localization not found. Plugin scripts may not have been enqueued correctly.');
        return;
    }

    var FE = window.flavorEdge;

    console.log('[TE Cache] Admin JS loaded. AJAX URL:', FE.ajaxUrl);

    // -------------------------------------------------------------------------
    // Tabs.
    // -------------------------------------------------------------------------
    $(document).on('click', '.te-tab', function (e) {
        e.preventDefault();
        var tab = $(this).data('tab');

        $('.te-tab').removeClass('active');
        $(this).addClass('active');

        $('.te-panel').removeClass('active');
        $('.te-panel[data-panel="' + tab + '"]').addClass('active');
    });

    // -------------------------------------------------------------------------
    // Test Connection.
    // -------------------------------------------------------------------------
    $(document).on('click', '#te-test-connection', function () {
        var $btn    = $(this);
        var $status = $('#te-connection-status');

        $btn.prop('disabled', true);
        $status.text(FE.strings.testing).removeClass('te-status-success te-status-error');

        var data = {
            action:        'flavor_edge_test_connection',
            nonce:         FE.nonce,
            company_id:    $('#company_id').val(),
            client_id:     $('#client_id').val(),
            client_secret: $('#client_secret').val()
        };

        console.log('[TE Cache] Testing connection...', { company_id: data.company_id, client_id: data.client_id });

        $.post(FE.ajaxUrl, data, function (res) {
            console.log('[TE Cache] Test connection response:', res);
            $btn.prop('disabled', false);
            if (res && res.success) {
                $status.text('✓ ' + res.message).addClass('te-status-success');
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                $status.text('✗ ' + (res && res.message ? res.message : FE.strings.error)).addClass('te-status-error');
            }
        }).fail(function (xhr, status, error) {
            console.error('[TE Cache] Test connection AJAX error:', status, error, xhr.responseText);
            $btn.prop('disabled', false);
            $status.text('✗ AJAX Error: ' + error).addClass('te-status-error');
        });
    });

    // -------------------------------------------------------------------------
    // Save Settings.
    // -------------------------------------------------------------------------
    $(document).on('submit', '#te-settings-form', function (e) {
        e.preventDefault();

        var $btn    = $('#te-save-settings');
        var $status = $('#te-save-status');
        var formData = {};

        // Gather all form inputs.
        $(this).find('input, select, textarea').each(function () {
            var $el  = $(this);
            var name = $el.attr('name');
            if (!name) return;

            if ($el.is(':checkbox')) {
                formData[name] = $el.is(':checked') ? '1' : '';
            } else {
                formData[name] = $el.val();
            }
        });

        console.log('[TE Cache] Saving settings:', Object.keys(formData).length, 'fields', Object.keys(formData));

        $btn.prop('disabled', true);
        $status.text(FE.strings.saving).removeClass('te-status-success te-status-error');

        $.post(FE.ajaxUrl, {
            action:   'flavor_edge_save_settings',
            nonce:    FE.nonce,
            settings: formData
        }, function (res) {
            console.log('[TE Cache] Save response:', res);
            $btn.prop('disabled', false);
            if (res && res.success) {
                $status.text('✓ ' + FE.strings.saved).addClass('te-status-success');
                setTimeout(function () { $status.text(''); }, 3000);
            } else {
                var errMsg = (res && res.data) ? res.data : FE.strings.error;
                $status.text('✗ ' + errMsg).addClass('te-status-error');
            }
        }).fail(function (xhr, status, error) {
            console.error('[TE Cache] Save AJAX error:', status, error, xhr.responseText);
            $btn.prop('disabled', false);
            $status.text('✗ AJAX Error: ' + error + ' — check browser console').addClass('te-status-error');
        });
    });

    // -------------------------------------------------------------------------
    // Purge All.
    // -------------------------------------------------------------------------
    $(document).on('click', '#te-purge-all', function () {
        if (!confirm(FE.strings.confirmPurge)) return;

        var $btn = $(this);
        var originalText = $btn.text();
        $btn.prop('disabled', true).text(FE.strings.purging);

        $.post(FE.ajaxUrl, {
            action: 'flavor_edge_purge_all',
            nonce:  FE.nonce
        }, function (res) {
            console.log('[TE Cache] Purge all response:', res);
            $btn.prop('disabled', false);
            if (res && res.success) {
                $btn.text('✓ ' + FE.strings.purged);
            } else {
                $btn.text('✗ ' + (res && res.message ? res.message : FE.strings.error));
            }
            setTimeout(function () { $btn.text(originalText); }, 3000);
        }).fail(function (xhr, status, error) {
            console.error('[TE Cache] Purge AJAX error:', status, error);
            $btn.prop('disabled', false).text('✗ Error: ' + error);
            setTimeout(function () { $btn.text(originalText); }, 3000);
        });
    });

    // -------------------------------------------------------------------------
    // Utility functions.
    // -------------------------------------------------------------------------
    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escAttr(str) {
        return escHtml(str).replace(/'/g, '&#39;');
    }

    // -------------------------------------------------------------------------
    // Copy VCL snippet (generic — works for all copy buttons).
    // -------------------------------------------------------------------------
    $(document).on('click', '#te-copy-vcl, .te-copy-btn', function () {
        var $btn = $(this);
        var targetId = $btn.data('target') || 'te-vcl-code';
        var code = document.getElementById(targetId);
        if (!code) return;

        var text = code.textContent || code.innerText;
        var originalText = $btn.text();

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                $btn.text('✓ Copied!');
                setTimeout(function () { $btn.text(originalText); }, 2000);
            });
        } else {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            $btn.text('✓ Copied!');
            setTimeout(function () { $btn.text(originalText); }, 2000);
        }
    });

    // -------------------------------------------------------------------------
    // Setup Wizard.
    // -------------------------------------------------------------------------
    $(document).on('click', '.te-wizard-type', function () {
        $('.te-wizard-type').removeClass('selected');
        $(this).addClass('selected');
        $(this).find('input[type=radio]').prop('checked', true);
    });

    $(document).on('click', '#te-wizard-apply', function () {
        var $btn    = $(this);
        var $status = $('#te-wizard-status');
        var type    = $('input[name="wizard_type"]:checked').val() || 'corporate';

        var data = {
            action:        'flavor_edge_wizard_apply',
            nonce:         FE.nonce,
            site_type:     type,
            multilingual:  $('.te-detected-multilingual').length > 0 ? '1' : '',
            company_id:    $('#wizard_company_id').val(),
            client_id:     $('#wizard_client_id').val(),
            client_secret: $('#wizard_client_secret').val()
        };

        if (!data.company_id || !data.client_id || !data.client_secret) {
            $status.text('Please fill in all credentials.').addClass('te-status-error');
            return;
        }

        $btn.prop('disabled', true).text('Connecting...');
        $status.text('').removeClass('te-status-error te-status-success');

        $.post(FE.ajaxUrl, data, function (res) {
            console.log('[TE Cache] Wizard response:', res);
            $btn.prop('disabled', false);
            if (res && res.success) {
                $status.text('✓ ' + (res.data || 'Done!')).addClass('te-status-success');
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                $status.text('✗ ' + (res && res.data ? res.data : 'Error')).addClass('te-status-error');
                $btn.text('Connect & Configure');
            }
        }).fail(function (xhr, status, error) {
            console.error('[TE Cache] Wizard error:', error);
            $btn.prop('disabled', false).text('Connect & Configure');
            $status.text('✗ ' + error).addClass('te-status-error');
        });
    });

    $(document).on('click', '#te-wizard-skip', function () {
        $('#te-wizard').slideUp();
        $.post(FE.ajaxUrl, {
            action: 'flavor_edge_save_settings',
            nonce:  FE.nonce,
            settings: {}  // Empty save just to dismiss.
        });
    });

    // -------------------------------------------------------------------------
    // Preload Cache (non-blocking with progress polling).
    // -------------------------------------------------------------------------
    var preloadTimer = null;

    $(document).on('click', '#te-preload-start', function () {
        var $btn    = $(this);
        var $stop   = $('#te-preload-stop');
        var $status = $('#te-preload-status');

        $btn.prop('disabled', true).text('Queuing URLs...');
        $status.text('');

        // Step 1: Queue URLs (returns immediately).
        $.post(FE.ajaxUrl, {
            action: 'flavor_edge_preload_start',
            nonce:  FE.nonce
        }, function (res) {
            console.log('[TE Cache] Preload queued:', res);
            if (res && res.success) {
                $btn.text('Warming cache...').hide();
                $stop.show();
                $status.text(res.message).css('color', '#155724');
                startPreloadPolling($btn, $stop, $status);
            } else {
                $btn.prop('disabled', false).text('🔄 Preload Cache via Sitemap');
                $status.text('✗ ' + (res && res.message ? res.message : 'Error')).css('color', '#721c24');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('🔄 Preload Cache via Sitemap');
            $status.text('✗ Request failed').css('color', '#721c24');
        });
    });

    // Stop preload.
    $(document).on('click', '#te-preload-stop', function () {
        var $btn    = $('#te-preload-start');
        var $stop   = $(this);
        var $status = $('#te-preload-status');

        $stop.prop('disabled', true).text('Stopping...');

        $.post(FE.ajaxUrl, {
            action: 'flavor_edge_preload_stop',
            nonce:  FE.nonce
        }, function () {
            if (preloadTimer) { clearInterval(preloadTimer); preloadTimer = null; }
            $stop.hide().prop('disabled', false).text('⏹ Stop Preload');
            $btn.show().prop('disabled', false).text('🔄 Preload Cache via Sitemap');
            $status.text('Preload stopped.').css('color', '#856404');
            setTimeout(function () { $status.text(''); }, 5000);
        });
    });

    function startPreloadPolling($btn, $stop, $status) {
        if (preloadTimer) clearInterval(preloadTimer);

        preloadTimer = setInterval(function () {
            $.post(FE.ajaxUrl, {
                action: 'flavor_edge_preload_tick',
                nonce:  FE.nonce
            }, function (res) {
                if (!res || !res.success || !res.data) return;

                var s = res.data;
                var pct = s.total > 0 ? Math.round((s.processed / s.total) * 100) : 0;

                if (s.status === 'running') {
                    $status.text('Warming: ' + s.processed + '/' + s.total + ' (' + pct + '%)' + (s.errors > 0 ? ' — ' + s.errors + ' errors' : '')).css('color', '#555');
                } else if (s.status === 'completed') {
                    clearInterval(preloadTimer);
                    preloadTimer = null;
                    var elapsed = s.elapsed ? ' in ' + s.elapsed + 's' : '';
                    $stop.hide();
                    $btn.show().prop('disabled', false).text('🔄 Preload Cache via Sitemap');
                    $status.text('✓ Completed: ' + s.processed + ' URLs warmed' + elapsed + (s.errors > 0 ? ' (' + s.errors + ' errors)' : '')).css('color', '#155724');
                    setTimeout(function () { $status.text(''); }, 10000);
                } else {
                    // Stopped or unknown.
                    clearInterval(preloadTimer);
                    preloadTimer = null;
                    $stop.hide();
                    $btn.show().prop('disabled', false).text('🔄 Preload Cache via Sitemap');
                    $status.text('Preload ' + s.status).css('color', '#856404');
                }
            });
        }, 3000);
    }

    // -------------------------------------------------------------------------
    // Object Cache (enable / disable / flush).
    // -------------------------------------------------------------------------
    $(document).on('click', '.te-enable-object-cache', function () {
        var $btn = $(this);
        var backend = $btn.data('backend');
        if (!confirm('Enable ' + backend + ' Object Cache? This will create wp-content/object-cache.php.')) return;

        $btn.prop('disabled', true).text('Enabling...');
        $.post(FE.ajaxUrl, {
            action: 'flavor_edge_enable_object_cache',
            nonce:  FE.nonce,
            backend: backend
        }, function (res) {
            $btn.prop('disabled', false);
            if (res && res.success) {
                location.reload();
            } else {
                alert(res && res.message ? res.message : 'Error enabling Object Cache');
                $btn.text('Enable ' + backend.charAt(0).toUpperCase() + backend.slice(1));
            }
        });
    });

    $(document).on('click', '.te-disable-object-cache', function () {
        if (!confirm('Disable Object Cache? This will remove wp-content/object-cache.php.')) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('Disabling...');
        $.post(FE.ajaxUrl, {
            action: 'flavor_edge_disable_object_cache',
            nonce:  FE.nonce
        }, function (res) {
            if (res && res.success) {
                location.reload();
            } else {
                $btn.prop('disabled', false).text('Disable');
                alert(res && res.message ? res.message : 'Error');
            }
        });
    });

    $(document).on('click', '.te-flush-object-cache', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Flushing...');
        $.post(FE.ajaxUrl, {
            action: 'flavor_edge_flush_object_cache',
            nonce:  FE.nonce
        }, function (res) {
            $btn.prop('disabled', false).text('Flush');
            if (res && res.success) {
                $btn.text('✓ Flushed');
                setTimeout(function () { $btn.text('Flush'); }, 2000);
            }
        });
    });

    // -------------------------------------------------------------------------
    // Clear Minify Cache.
    // -------------------------------------------------------------------------
    $(document).on('click', '.te-clear-minify-cache', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Clearing...');
        $.post(FE.ajaxUrl, {
            action: 'flavor_edge_clear_minify_cache',
            nonce:  FE.nonce
        }, function (res) {
            $btn.prop('disabled', false);
            if (res && res.success && res.data) {
                $btn.text('✓ Cleared ' + res.data.files_deleted + ' files');
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                $btn.text('Clear Minify Cache');
            }
        });
    });

})(jQuery);
