<?php
/**
 * AHM Figma Importer tab and AJAX controller.
 *
 * Consolidates figma-to-elementor settings and ajax handlers.
 *
 * @package AHM_Core
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class AHM_Figma_Admin
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
        add_action('ahm_tab_content_figma-importer', [$this, 'render_tab']);
        add_action('wp_ajax_ahm_figma_import', [$this, 'ajax_import_design']);
        add_action('wp_ajax_ahm_figma_save_pat', [$this, 'ajax_save_pat']);
    }

    /**
     * Render the importer dashboard view.
     */
    public function render_tab(): void
    {
        $pat = get_option('figma_to_elementor_pat', '');
        ?>
        <div class="ahm-card">
            <h2><?php esc_html_e('Figma to Elementor Importer', 'ahm-core'); ?></h2>
            <p class="description">
                <?php esc_html_e('Convert Figma layouts directly into Elementor Flexbox Containers.', 'ahm-core'); ?>
            </p>

            <?php if (empty($pat)) : ?>
                <div class="figma-alert figma-alert-warning">
                    <span class="dashicons dashicons-warning"></span>
                    <p><?php esc_html_e('Please configure your Figma Personal Access Token in API Settings below before importing.', 'ahm-core'); ?></p>
                </div>
            <?php endif; ?>

            <form id="figma-import-form" class="figma-form">
                <div class="form-group">
                    <label for="figma-file-url"><?php esc_html_e('Figma File URL', 'ahm-core'); ?></label>
                    <input type="url" id="figma-file-url" placeholder="https://www.figma.com/design/XXXXX/My-Layout" required <?php disabled(empty($pat)); ?>>
                    <span class="input-desc"><?php esc_html_e('Example: https://www.figma.com/design/abcdef123456/Sample-File', 'ahm-core'); ?></span>
                </div>

                <div class="form-group">
                    <label for="figma-page-title"><?php esc_html_e('Imported Page Title', 'ahm-core'); ?></label>
                    <input type="text" id="figma-page-title" value="Figma Import Page" required <?php disabled(empty($pat)); ?>>
                </div>

                <div class="figma-layout-settings-section" style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;">
                    <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 16px; font-weight: 600; color: #1e1e1e;"><?php esc_html_e('Global Layout Settings', 'ahm-core'); ?></h3>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="figma-content-width"><?php esc_html_e('Content Width (px)', 'ahm-core'); ?></label>
                        <input type="number" id="figma-content-width" value="1140" min="500" max="3000" required <?php disabled(empty($pat)); ?>>
                        <span class="input-desc"><?php esc_html_e('Sets the default width of the boxed content area (Default: 1140px).', 'ahm-core'); ?></span>
                    </div>

                    <div class="form-group" style="margin-bottom: 15px; flex-direction: row; align-items: center; gap: 8px;">
                        <input type="checkbox" id="figma-zero-padding" value="yes" checked <?php disabled(empty($pat)); ?>>
                        <label for="figma-zero-padding" style="margin: 0; font-weight: normal; font-size: 13px; cursor: pointer;"><?php esc_html_e('Set Container Padding to 0px (Removes the default 10px padding)', 'ahm-core'); ?></label>
                    </div>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="figma-default-layout"><?php esc_html_e('Default Page Layout', 'ahm-core'); ?></label>
                        <select id="figma-default-layout" style="padding: 12px 16px; font-size: 14px; border: 1px solid #dcdcde; border-radius: 6px; background: #fbfbfc; width: 100%; box-sizing: border-box; transition: all 0.3s ease;" <?php disabled(empty($pat)); ?>>
                            <option value="default"><?php esc_html_e('Theme (Default)', 'ahm-core'); ?></option>
                            <option value="elementor_canvas"><?php esc_html_e('Elementor Canvas', 'ahm-core'); ?></option>
                            <option value="elementor_header_footer" selected><?php esc_html_e('Elementor Full Width', 'ahm-core'); ?></option>
                        </select>
                        <span class="input-desc"><?php esc_html_e('The default page template as defined in Elementor.', 'ahm-core'); ?></span>
                    </div>
                </div>

                <button type="submit" class="figma-btn figma-btn-primary" id="btn-import-figma" <?php disabled(empty($pat)); ?>>
                    <span class="btn-text"><?php esc_html_e('Generate Elementor Page', 'ahm-core'); ?></span>
                    <span class="spinner-loader"></span>
                </button>
            </form>

            <div id="figma-import-progress" class="figma-progress-wrapper" style="display: none;">
                <div class="progress-bar-container">
                    <div class="progress-bar-fill"></div>
                </div>
                <p class="progress-status-text"><?php esc_html_e('Starting import...', 'ahm-core'); ?></p>
            </div>

            <div id="figma-import-result" class="figma-result-wrapper" style="display: none;"></div>
        </div>

        <div class="ahm-card">
            <h2><?php esc_html_e('Figma API Settings', 'ahm-core'); ?></h2>
            <p class="description">
                <?php esc_html_e('Get your token from Figma Account Settings > Personal access tokens.', 'ahm-core'); ?>
            </p>

            <form id="figma-settings-form" class="figma-form">
                <div class="form-group">
                    <label for="figma-pat"><?php esc_html_e('Personal Access Token (PAT)', 'ahm-core'); ?></label>
                    <input type="password" id="figma-pat" value="<?php echo esc_attr($pat); ?>" placeholder="<?php esc_attr_e('figd_...', 'ahm-core'); ?>" required>
                </div>

                <button type="submit" class="figma-btn figma-btn-primary" id="btn-save-settings">
                    <span class="btn-text"><?php esc_html_e('Save Token', 'ahm-core'); ?></span>
                    <span class="spinner-loader"></span>
                </button>
            </form>
            <div id="figma-settings-result" class="figma-result-wrapper" style="display: none;"></div>
        </div>
        <?php
    }

    /**
     * AJAX handler to save Figma Personal Access Token.
     */
    public function ajax_save_pat(): void
    {
        check_ajax_referer('ahm_figma_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ahm-core')]);
        }

        $pat = isset($_POST['pat']) ? sanitize_text_field(wp_unslash($_POST['pat'])) : '';
        update_option('figma_to_elementor_pat', $pat);

        wp_send_json_success(['message' => __('Token saved successfully!', 'ahm-core')]);
    }

    /**
     * Helper to extract File Key from Figma URL.
     */
    private function extract_file_key(string $url): string|bool
    {
        if (preg_match('/(?:\/file|\/design)\/([a-zA-Z0-9]+)/', $url, $matches)) {
            return $matches[1];
        }
        return false;
    }

    /**
     * Helper to extract Node ID from Figma URL.
     */
    private function extract_node_id(string $url): string
    {
        $parsed = wp_parse_url($url);
        if (isset($parsed['query'])) {
            wp_parse_str($parsed['query'], $query);
            if (isset($query['node-id'])) {
                // Figma URL uses hyphens instead of colons for node IDs, but API expects colons.
                return str_replace('-', ':', $query['node-id']);
            }
        }
        return '';
    }

    /**
     * AJAX handler to convert and import Figma design.
     */
    public function ajax_import_design(): void
    {
        check_ajax_referer('ahm_figma_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ahm-core')]);
        }

        $file_url   = isset($_POST['file_url']) ? esc_url_raw(wp_unslash($_POST['file_url'])) : '';
        $page_title = isset($_POST['page_title']) ? sanitize_text_field(wp_unslash($_POST['page_title'])) : 'Figma Import';
        $file_key   = $this->extract_file_key($file_url);
        $node_id    = $this->extract_node_id($file_url);

        $content_width  = isset($_POST['content_width']) ? intval($_POST['content_width']) : 1140;
        $zero_padding   = isset($_POST['zero_padding']) && $_POST['zero_padding'] === 'true';
        $default_layout = isset($_POST['default_layout']) ? sanitize_text_field(wp_unslash($_POST['default_layout'])) : 'elementor_header_footer';

        if (! $file_key) {
            wp_send_json_error(['message' => __('Invalid Figma URL. Please ensure it contains a valid design/file key.', 'ahm-core')]);
        }

        $pat = get_option('figma_to_elementor_pat', '');
        if (empty($pat)) {
            wp_send_json_error(['message' => __('Please save a valid Personal Access Token first.', 'ahm-core')]);
        }

        // Initialize Figma API, Parser and Importer
        $api      = new Figma_API($pat);
        $parser   = new Figma_Parser();
        $importer = new Elementor_Importer();

        // 1. Fetch file from Figma API
        $figma_data = $api->get_file($file_key, $node_id);
        if (is_wp_error($figma_data)) {
            wp_send_json_error(['message' => sprintf(__('Figma API Error: %s', 'ahm-core'), $figma_data->get_error_message())]);
        }

        // 2. Identify Image Nodes and sideload images
        $image_node_ids = [];
        if (!empty($node_id) && isset($figma_data['nodes'][$node_id]['document'])) {
            $image_node_ids = $parser->collect_image_node_ids($figma_data['nodes'][$node_id]['document']);
        } elseif (isset($figma_data['document']['children']) && is_array($figma_data['document']['children'])) {
            foreach ($figma_data['document']['children'] as $page) {
                $image_node_ids = array_merge($image_node_ids, $parser->collect_image_node_ids($page));
            }
        }

        $image_map = [];
        if (! empty($image_node_ids)) {
            $image_urls = $api->get_image_urls($file_key, $image_node_ids);
            if (! is_wp_error($image_urls)) {
                foreach ($image_urls as $node_id => $download_url) {
                    if (! empty($download_url)) {
                        $sideload_result = $importer->sideload_image($download_url, sprintf(__('Figma Node %s Image', 'ahm-core'), $node_id));
                        if (! is_wp_error($sideload_result)) {
                            $image_map[$node_id] = $sideload_result['url'];
                        }
                    }
                }
            }
        }

        // 3. Configure parser with media references and run layout transformation
        $parser->set_image_map($image_map);
        $elementor_data = $parser->parse($figma_data, $node_id);

        if (empty($elementor_data)) {
            wp_send_json_error(['message' => __('No valid top-level Figma frames found to convert.', 'ahm-core')]);
        }

        // 4. Import mapping to Elementor template/page metadata and apply global settings
        $importer->update_global_settings($content_width, $zero_padding, $default_layout);
        $post_id = $importer->import_layout($elementor_data, $page_title, 'page', $default_layout);
        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => sprintf(__('Import failed: %s', 'ahm-core'), $post_id->get_error_message())]);
        }

        $edit_url = admin_url('post.php?post=' . $post_id . '&action=elementor');
        wp_send_json_success([
            'message'  => __('Layout imported successfully!', 'ahm-core'),
            'edit_url' => $edit_url,
        ]);
    }
}
