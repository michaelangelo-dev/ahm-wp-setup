/**
 * AHM Core — Admin JavaScript
 *
 * Quality slider + bulk conversion AJAX queue.
 *
 * @package AHM_Core
 */

(function ($) {
    'use strict';

    /* ============================================================
       Quality Slider
       ============================================================ */

    var $slider = $('#ahm_quality');
    var $output = $('#ahm_quality_output');

    if ($slider.length) {
        $slider.on('input', function () {
            $output.text(this.value);
        });
    }

    /* ============================================================
       Bulk Conversion
       ============================================================ */

    var $btn          = $('#ahm-btn-bulk');
    var $progressWrap = $('#ahm-bulk-progress');
    var $progressBar  = $('#ahm-progress-bar');
    var $progressText = $('#ahm-progress-text');
    var $progressLog  = $('#ahm-progress-log');

    if (!$btn.length || typeof ahmAdmin === 'undefined') {
        return;
    }

    var isRunning = false;

    $btn.on('click', function () {
        if (isRunning) return;
        if (!confirm(ahmAdmin.i18n.confirm)) return;
        startBulk();
    });

    function startBulk() {
        isRunning = true;
        $btn.prop('disabled', true).text(ahmAdmin.i18n.scanning);
        $progressWrap.show();
        $progressBar.css('width', '0%').text('0%');
        $progressText.text(ahmAdmin.i18n.scanning);
        $progressLog.empty().removeClass('has-entries');

        $.post(ahmAdmin.ajaxUrl, {
            action: 'ahm_get_unconverted',
            nonce:  ahmAdmin.nonce
        }, function (res) {
            if (!res.success || !res.data.ids.length) {
                $progressText.text(ahmAdmin.i18n.noImages);
                finish();
                return;
            }
            processQueue(res.data.ids, 0, res.data.total);
        }).fail(function () {
            $progressText.text(ahmAdmin.i18n.error);
            finish();
        });
    }

    function processQueue(ids, idx, total) {
        if (idx >= ids.length) {
            $progressBar.css('width', '100%').text('100%');
            $progressText.text(ahmAdmin.i18n.complete);
            finish();
            return;
        }

        var pct = Math.round((idx / total) * 100);
        $progressBar.css('width', pct + '%').text(pct + '%');
        $progressText.text(
            ahmAdmin.i18n.converting + ' ' + (idx + 1) + ' ' + ahmAdmin.i18n.of + ' ' + total + '…'
        );

        $.post(ahmAdmin.ajaxUrl, {
            action:        'ahm_bulk_convert',
            nonce:         ahmAdmin.nonce,
            attachment_id: ids[idx]
        }, function (res) {
            var d     = res.data || {};
            var title = d.title || 'ID ' + ids[idx];
            if (res.success) {
                log('✓ ' + title + ' — ' + d.message, 'success');
            } else {
                log('✗ ' + title + ' — ' + d.message, 'error');
            }
        }).fail(function () {
            log('✗ ID ' + ids[idx] + ' — Network error', 'error');
        }).always(function () {
            processQueue(ids, idx + 1, total);
        });
    }

    function log(text, type) {
        $progressLog.addClass('has-entries');
        $progressLog.append(
            $('<div/>').addClass('ahm-log-entry--' + type).text(text)
        );
        $progressLog.scrollTop($progressLog[0].scrollHeight);
    }

    function finish() {
        isRunning = false;
        $btn.prop('disabled', false).text('Start Bulk Conversion');
    }

})(jQuery);
