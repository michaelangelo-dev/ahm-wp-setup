<?php
/**
 * Cache Manager tab.
 *
 * Centralised one-click cache clearing:
 *  1. Clear Elementor CSS cache  → triggers regeneration.
 *  2. Rewrite Elementor CSS      → injects WebP URLs into regenerated files.
 *  3. Clear WP Rocket RUCSS      → removes stale optimised CSS.
 *  4. Clear WP Rocket page cache → removes stale HTML.
 *
 * @package AHM_Core
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class AHM_Cache_Manager
{
    private static ?self $instance = null;

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('ahm_tab_content_cache-manager', [$this, 'render_tab']);

        // AJAX endpoints for each cache-clear step.
        add_action('wp_ajax_ahm_clear_elementor_cache', [$this, 'ajax_clear_elementor']);
        add_action('wp_ajax_ahm_rewrite_elementor_css', [$this, 'ajax_rewrite_elementor_css']);
        add_action('wp_ajax_ahm_clear_rocket_rucss', [$this, 'ajax_clear_rocket_rucss']);
        add_action('wp_ajax_ahm_clear_rocket_cache', [$this, 'ajax_clear_rocket_cache']);
    }

    /*--------------------------------------------------------------
     * Tab Renderer
     *------------------------------------------------------------*/

    public function render_tab(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $elementor_active = defined('ELEMENTOR_VERSION');
        $rocket_active    = defined('WP_ROCKET_VERSION');

        ?>
        <div class="ahm-card">
            <h2><?php esc_html_e('Cache Manager', 'ahm-core'); ?></h2>
            <p class="description">
                <?php esc_html_e('Clear all caches in the correct order. This ensures Elementor CSS is regenerated with WebP URLs before WP Rocket rebuilds its optimised cache.', 'ahm-core'); ?>
            </p>

            <!-- Status badges -->
            <table class="ahm-info-table" style="margin: 16px 0 24px;">
                <tr>
                    <td><strong>Elementor</strong></td>
                    <td>
                        <?php if ($elementor_active): ?>
                            <span class="ahm-badge ahm-badge--success">
                                <?php echo esc_html('Active — v' . ELEMENTOR_VERSION); ?>
                            </span>
                        <?php else: ?>
                            <span class="ahm-badge ahm-badge--error"><?php esc_html_e('Not Active', 'ahm-core'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>WP Rocket</strong></td>
                    <td>
                        <?php if ($rocket_active): ?>
                            <span class="ahm-badge ahm-badge--success">
                                <?php echo esc_html('Active — v' . WP_ROCKET_VERSION); ?>
                            </span>
                        <?php else: ?>
                            <span class="ahm-badge ahm-badge--error"><?php esc_html_e('Not Active', 'ahm-core'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <!-- Clear All Button -->
            <button type="button" id="ahm-btn-clear-all" class="ahm-btn-clear">
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e('Clear All Caches', 'ahm-core'); ?>
            </button>

            <!-- Step Progress -->
            <div id="ahm-cache-steps" class="ahm-cache-steps" style="display:none;">
                <div class="ahm-cache-step" data-step="elementor">
                    <span class="ahm-step-icon">⏳</span>
                    <span class="ahm-step-label"><?php esc_html_e('Clear Elementor CSS Cache', 'ahm-core'); ?></span>
                    <span class="ahm-step-status"></span>
                </div>
                <div class="ahm-cache-step" data-step="webp-rewrite">
                    <span class="ahm-step-icon">⏳</span>
                    <span class="ahm-step-label"><?php esc_html_e('Rewrite Elementor CSS → WebP', 'ahm-core'); ?></span>
                    <span class="ahm-step-status"></span>
                </div>
                <?php if ($rocket_active): ?>
                <div class="ahm-cache-step" data-step="rocket-rucss">
                    <span class="ahm-step-icon">⏳</span>
                    <span class="ahm-step-label"><?php esc_html_e('Clear WP Rocket RUCSS', 'ahm-core'); ?></span>
                    <span class="ahm-step-status"></span>
                </div>
                <div class="ahm-cache-step" data-step="rocket-cache">
                    <span class="ahm-step-icon">⏳</span>
                    <span class="ahm-step-label"><?php esc_html_e('Clear WP Rocket Page Cache', 'ahm-core'); ?></span>
                    <span class="ahm-step-status"></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /*--------------------------------------------------------------
     * AJAX: Step 1 — Clear Elementor CSS Cache
     *------------------------------------------------------------*/

    public function ajax_clear_elementor(): void
    {
        check_ajax_referer('ahm_webp_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.'], 403);
        }

        if (class_exists('\Elementor\Plugin')) {
            \Elementor\Plugin::instance()->files_manager->clear_cache();
            wp_send_json_success(['message' => __('Elementor CSS cache cleared.', 'ahm-core')]);
        } else {
            wp_send_json_error(['message' => __('Elementor is not active.', 'ahm-core')]);
        }
    }

    /*--------------------------------------------------------------
     * AJAX: Step 2 — Rewrite Elementor CSS with WebP URLs
     *------------------------------------------------------------*/

    public function ajax_rewrite_elementor_css(): void
    {
        check_ajax_referer('ahm_webp_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.'], 403);
        }

        $count = AHM_WebP_Frontend::rewrite_all_elementor_css();

        wp_send_json_success([
            'message' => sprintf(
                __('Rewrote %d Elementor CSS file(s) with WebP URLs.', 'ahm-core'),
                $count
            ),
            'count' => $count,
        ]);
    }

    /*--------------------------------------------------------------
     * AJAX: Step 3 — Clear WP Rocket RUCSS
     *------------------------------------------------------------*/

    public function ajax_clear_rocket_rucss(): void
    {
        check_ajax_referer('ahm_webp_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.'], 403);
        }

        if (! defined('WP_ROCKET_VERSION')) {
            wp_send_json_error(['message' => __('WP Rocket is not active.', 'ahm-core')]);
        }

        // Use WP Rocket's internal function if available.
        if (function_exists('rocket_clean_used_css')) {
            rocket_clean_used_css();
            wp_send_json_success(['message' => __('WP Rocket RUCSS cleared.', 'ahm-core')]);
        }

        // Fallback: truncate the used_css table directly (Rocket 3.9+).
        global $wpdb;
        $table = $wpdb->prefix . 'wpr_rucss_used_css';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
            $wpdb->query("TRUNCATE TABLE `{$table}`");
            wp_send_json_success(['message' => __('WP Rocket RUCSS table cleared.', 'ahm-core')]);
        }

        wp_send_json_success(['message' => __('No RUCSS data found to clear.', 'ahm-core')]);
    }

    /*--------------------------------------------------------------
     * AJAX: Step 4 — Clear WP Rocket Page Cache
     *------------------------------------------------------------*/

    public function ajax_clear_rocket_cache(): void
    {
        check_ajax_referer('ahm_webp_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.'], 403);
        }

        if (! defined('WP_ROCKET_VERSION')) {
            wp_send_json_error(['message' => __('WP Rocket is not active.', 'ahm-core')]);
        }

        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            wp_send_json_success(['message' => __('WP Rocket page cache cleared.', 'ahm-core')]);
        }

        // Fallback: clear minified CSS/JS too.
        if (function_exists('rocket_clean_minify')) {
            rocket_clean_minify('css');
            rocket_clean_minify('js');
        }

        wp_send_json_success(['message' => __('WP Rocket cache cleared.', 'ahm-core')]);
    }
}
