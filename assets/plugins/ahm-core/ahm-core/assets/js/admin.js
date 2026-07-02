/**
 * AHM Core — Admin JavaScript
 *
 * Quality slider, bulk conversion queue, and cache manager.
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

    if ($btn.length && typeof ahmAdmin !== 'undefined') {

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
                    bulkFinish();
                    return;
                }
                processQueue(res.data.ids, 0, res.data.total);
            }).fail(function () {
                $progressText.text(ahmAdmin.i18n.error);
                bulkFinish();
            });
        }

        function processQueue(ids, idx, total) {
            if (idx >= ids.length) {
                $progressBar.css('width', '100%').text('100%');
                $progressText.text('Updating Elementor CSS files…');

                // After all images are converted, rewrite Elementor CSS files.
                $.post(ahmAdmin.ajaxUrl, {
                    action: 'ahm_rewrite_elementor_css',
                    nonce:  ahmAdmin.nonce
                }, function (res) {
                    if (res.success && res.data.count > 0) {
                        bulkLog('✓ ' + res.data.message, 'success');
                    }
                }).always(function () {
                    $progressText.text(ahmAdmin.i18n.complete);
                    bulkFinish();
                });
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
                    bulkLog('✓ ' + title + ' — ' + d.message, 'success');
                } else {
                    bulkLog('✗ ' + title + ' — ' + d.message, 'error');
                }
            }).fail(function () {
                bulkLog('✗ ID ' + ids[idx] + ' — Network error', 'error');
            }).always(function () {
                processQueue(ids, idx + 1, total);
            });
        }

        function bulkLog(text, type) {
            $progressLog.addClass('has-entries');
            $progressLog.append(
                $('<div/>').addClass('ahm-log-entry--' + type).text(text)
            );
            $progressLog.scrollTop($progressLog[0].scrollHeight);
        }

        function bulkFinish() {
            isRunning = false;
            $btn.prop('disabled', false).text('Start Bulk Conversion');
        }
    }

    /* ============================================================
       Cache Manager — Sequential Clear
       ============================================================ */

    var $clearBtn   = $('#ahm-btn-clear-all');
    var $stepsWrap  = $('#ahm-cache-steps');

    if ($clearBtn.length && typeof ahmAdmin !== 'undefined') {

        var cacheRunning = false;

        $clearBtn.on('click', function () {
            if (cacheRunning) return;
            cacheRunning = true;
            $clearBtn.prop('disabled', true);
            $stepsWrap.show();

            // Collect steps from the DOM (respects which ones are rendered).
            var steps = [];
            $stepsWrap.find('.ahm-cache-step').each(function () {
                steps.push($(this));
            });

            runCacheSteps(steps, 0);
        });

        function runCacheSteps(steps, idx) {
            if (idx >= steps.length) {
                cacheRunning = false;
                $clearBtn.prop('disabled', false);
                return;
            }

            var $step  = steps[idx];
            var stepId = $step.data('step');
            $step.addClass('is-active');
            $step.find('.ahm-step-icon').text('⏳');

            var actionMap = {
                'elementor':    'ahm_clear_elementor_cache',
                'webp-rewrite': 'ahm_rewrite_elementor_css',
                'rocket-rucss': 'ahm_clear_rocket_rucss',
                'rocket-cache': 'ahm_clear_rocket_cache'
            };

            var action = actionMap[stepId];
            if (!action) {
                markStep($step, 'skipped', 'Unknown step');
                runCacheSteps(steps, idx + 1);
                return;
            }

            $.post(ahmAdmin.ajaxUrl, {
                action: action,
                nonce:  ahmAdmin.nonce
            }, function (res) {
                if (res.success) {
                    markStep($step, 'done', res.data.message);
                } else {
                    markStep($step, 'error', (res.data && res.data.message) || 'Failed');
                }
            }).fail(function () {
                markStep($step, 'error', 'Network error');
            }).always(function () {
                runCacheSteps(steps, idx + 1);
            });
        }

        function markStep($step, status, message) {
            $step.removeClass('is-active');
            $step.addClass('is-' + status);

            var icons = { done: '✅', error: '❌', skipped: '⚠️' };
            $step.find('.ahm-step-icon').text(icons[status] || '—');
            $step.find('.ahm-step-status').text(message || '');
        }
    }

    /* ============================================================
       Figma Importer
       ============================================================ */

    const figmaForm = document.getElementById('figma-import-form')
    if (figmaForm && typeof ahmAdmin !== 'undefined') {

        // Form Submissions via delegation
        $(document).on('submit', '.figma-form', function (e) {
            e.preventDefault()
            const form = this

            if (form.id === 'figma-settings-form') {
                handleSaveSettings(form)
            } else if (form.id === 'figma-import-form') {
                handleImportDesign(form)
            }
        })

        // Handle saving the Figma PAT
        const handleSaveSettings = async (form) => {
            const patInput = document.getElementById('figma-pat')
            const btn = form.querySelector('button[type="submit"]')
            const resultWrapper = document.getElementById('figma-settings-result')

            if (!patInput || !btn || !resultWrapper) return

            // Set loading state
            btn.classList.add('loading')
            btn.disabled = true
            resultWrapper.style.display = 'none'

            const geminiInput = document.getElementById('gemini-api-key')

            const formData = new FormData()
            formData.append('action', 'ahm_figma_save_pat')
            formData.append('nonce', ahmAdmin.figmaNonce)
            formData.append('pat', patInput.value)

            if (geminiInput) {
                formData.append('gemini_api_key', geminiInput.value)
            }

            try {
                const response = await fetch(ahmAdmin.ajaxUrl, {
                    method: 'POST',
                    body: formData
                })

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`)
                }

                const result = await response.json()

                if (result.success) {
                    resultWrapper.innerHTML = `
                        <div class="figma-alert figma-alert-success">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <p>${result.data.message}</p>
                        </div>
                    `
                    // Reload page after 1.5 seconds to unlock the import form
                    setTimeout(() => {
                        window.location.reload()
                    }, 1500)
                } else {
                    resultWrapper.innerHTML = `
                        <div class="figma-alert figma-alert-error">
                            <span class="dashicons dashicons-dismiss"></span>
                            <p>${result.data.message || 'Failed to save token.'}</p>
                        </div>
                    `
                }
            } catch (error) {
                resultWrapper.innerHTML = `
                    <div class="figma-alert figma-alert-error">
                        <span class="dashicons dashicons-dismiss"></span>
                        <p>Error: ${error.message}</p>
                    </div>
                `
            } finally {
                btn.classList.remove('loading')
                btn.disabled = false
                resultWrapper.style.display = 'block'
            }
        }

        // Handle importing the design
        const handleImportDesign = async (form) => {
            const urlInput = document.getElementById('figma-file-url')
            const titleInput = document.getElementById('figma-page-title')
            const btn = form.querySelector('button[type="submit"]')
            const progressWrapper = document.getElementById('figma-import-progress')
            const progressBarFill = progressWrapper ? progressWrapper.querySelector('.progress-bar-fill') : null
            const progressText = progressWrapper ? progressWrapper.querySelector('.progress-status-text') : null
            const resultWrapper = document.getElementById('figma-import-result')

            if (!urlInput || !btn || !progressWrapper || !progressBarFill || !progressText || !resultWrapper) return

            // UI reset
            btn.classList.add('loading')
            btn.disabled = true
            progressWrapper.style.display = 'block'
            resultWrapper.style.display = 'none'
            progressBarFill.style.width = '10%'
            progressText.textContent = 'Fetching design from Figma API...'

            // Progress animation simulation
            let progress = 10
            const progressInterval = setInterval(() => {
                if (progress < 90) {
                    progress += Math.floor(Math.random() * 8) + 2
                    if (progress > 90) progress = 90
                    progressBarFill.style.width = `${progress}%`

                    if (progress > 30 && progress <= 60) {
                        progressText.textContent = 'Downloading design assets and media...'
                    } else if (progress > 60) {
                        progressText.textContent = 'Generating Elementor template structures...'
                    }
                }
            }, 600)

            const contentWidthInput = document.getElementById('figma-content-width')
            const zeroPaddingInput = document.getElementById('figma-zero-padding')
            const defaultLayoutInput = document.getElementById('figma-default-layout')
            const importModeInput = document.getElementById('figma-import-mode')
            
            const importMode = importModeInput ? importModeInput.value : 'standard'
            
            if (importMode === 'ai') {
                progressText.textContent = 'Generating screenshot and analyzing with Gemini AI (this may take up to 30 seconds)...'
            }

            const formData = new FormData()
            formData.append('action', 'ahm_figma_import')
            formData.append('nonce', ahmAdmin.figmaNonce)
            formData.append('file_url', urlInput.value)
            formData.append('page_title', titleInput ? titleInput.value : 'Figma Import')
            formData.append('content_width', contentWidthInput ? contentWidthInput.value : '1140')
            formData.append('zero_padding', zeroPaddingInput ? zeroPaddingInput.checked : 'true')
            formData.append('default_layout', defaultLayoutInput ? defaultLayoutInput.value : 'elementor_header_footer')
            formData.append('import_mode', importMode)

            try {
                const response = await fetch(ahmAdmin.ajaxUrl, {
                    method: 'POST',
                    body: formData
                })

                clearInterval(progressInterval)

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`)
                }

                const result = await response.json()

                if (result.success) {
                    progressBarFill.style.width = '100%'
                    progressText.textContent = 'Import complete!'

                    resultWrapper.innerHTML = `
                        <div class="figma-alert figma-alert-success">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <div>
                                <p><strong>${result.data.message}</strong></p>
                                <p style="margin-top: 8px;">
                                    <a href="${result.data.edit_url}" class="button button-primary" target="_blank">
                                        Edit with Elementor
                                    </a>
                                </p>
                            </div>
                        </div>
                    `
                } else {
                    progressBarFill.style.width = '0%'
                    progressWrapper.style.display = 'none'
                    resultWrapper.innerHTML = `
                        <div class="figma-alert figma-alert-error">
                            <span class="dashicons dashicons-dismiss"></span>
                            <p>${result.data.message || 'Import failed.'}</p>
                        </div>
                    `
                }
            } catch (error) {
                clearInterval(progressInterval)
                progressBarFill.style.width = '0%'
                progressWrapper.style.display = 'none'
                resultWrapper.innerHTML = `
                    <div class="figma-alert figma-alert-error">
                        <span class="dashicons dashicons-dismiss"></span>
                        <p>Error: ${error.message}</p>
                    </div>
                `
            } finally {
                btn.classList.remove('loading')
                btn.disabled = false
                resultWrapper.style.display = 'block'
            }
        }
    }

})(jQuery);
