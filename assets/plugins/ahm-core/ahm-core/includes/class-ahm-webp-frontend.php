<?php
/**
 * Front-end WebP delivery.
 *
 * Layer 1: <img>         → <picture> with WebP <source> + original fallback.
 * Layer 2: CSS url()     → output buffer (inline styles, <style>, Elementor data-*).
 * Layer 3: Elementor CSS → rewrite files on disk after Elementor generates them.
 * Layer 3b: .htaccess    → Apache/LiteSpeed content negotiation (bonus).
 * Layer 4: WP Rocket     → hooks into cache clear events to re-apply WebP URLs.
 *
 * @package AHM_Core
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class AHM_WebP_Frontend
{
    private static ?self $instance = null;
    private bool  $browser_supports_webp = false;
    private array $settings = [];

    private const HTACCESS_MARKER = 'AHM WebP Converter';

    /*--------------------------------------------------------------
     * PERFORMANCE: Cache upload dir and file_exists() results
     * to avoid repeated syscalls during a single request.
     *------------------------------------------------------------*/
    private static ?array  $upload_dir_cache = null;
    private static array   $file_exists_cache = [];

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->settings = (array) get_option('ahm_webp_settings', []);

        if (empty($this->settings['serve_webp'])) {
            return;
        }

        $this->browser_supports_webp = isset($_SERVER['HTTP_ACCEPT'])
            && str_contains($_SERVER['HTTP_ACCEPT'], 'image/webp');

        // Layer 1 — <img> → <picture>.
        add_filter('the_content', [$this, 'convert_content_images'], 99);
        add_filter('post_thumbnail_html', [$this, 'convert_single_img_tag'], 99);
        add_filter('get_avatar', [$this, 'convert_single_img_tag'], 99);
        add_filter('wp_get_attachment_image', [$this, 'convert_single_img_tag'], 99, 5);
        add_filter('widget_text', [$this, 'convert_content_images'], 99);

        // Layer 2 — CSS url() output buffer.
        if (! empty($this->settings['serve_webp_css']) && $this->browser_supports_webp) {
            add_action('template_redirect', [$this, 'start_output_buffer'], 1);
        }

        // Layer 3 + 4 hooks only needed in admin or during cache events.
        // They don't fire on regular front-end page loads.
        if (! empty($this->settings['serve_webp_css'])) {
            add_action('elementor/core/files/clear_cache', [self::class, 'on_elementor_cache_clear']);

            // Only attach the meta hooks in admin context where Elementor
            // regenerates CSS. Avoids overhead on every front-end meta query.
            if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
                add_action('updated_post_meta', [$this, 'on_elementor_css_meta_update'], 10, 4);
                add_action('added_post_meta', [$this, 'on_elementor_css_meta_update'], 10, 4);
            }

            add_action('after_rocket_clean_domain', [self::class, 'rewrite_all_elementor_css']);
            add_action('rocket_rucss_after_clearing_usedcss', [self::class, 'rewrite_all_elementor_css']);
        }
    }

    /**
     * Get cached upload directory info.
     *
     * @return array{basedir: string, baseurl: string}
     */
    private static function get_upload_dir(): array
    {
        if (self::$upload_dir_cache === null) {
            self::$upload_dir_cache = wp_get_upload_dir();
        }
        return self::$upload_dir_cache;
    }

    /*==============================================================
     * LAYER 1 — <img> → <picture>
     *============================================================*/

    public function convert_content_images(string $content): string
    {
        if (empty($content) || is_admin() || wp_doing_ajax()) {
            return $content;
        }

        if (! preg_match('/\.(jpe?g|png|gif)(?:[?#][^"\'\s]*)?/i', $content)) {
            return $content;
        }

        return (string) preg_replace_callback(
            '/<img\b([^>]*?)src=["\']([^"\']+\.(jpe?g|png|gif))["\']([^>]*?)\/?>/i',
            [$this, 'replace_img_callback'],
            $content
        );
    }

    public function convert_single_img_tag(string $html): string
    {
        if (empty($html) || is_admin()) {
            return $html;
        }

        if (! preg_match('/\.(jpe?g|png|gif)(?:[?#][^"\'\s]*)?/i', $html)) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<img\b([^>]*?)src=["\']([^"\']+\.(jpe?g|png|gif))["\']([^>]*?)\/?>/i',
            [$this, 'replace_img_callback'],
            $html
        );
    }

    private static function get_webp_candidate_url(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        $webp = preg_replace('/\.(jpe?g|png|gif)([?#].*)?$/i', '.webp$2', $url);

        return is_string($webp) ? $webp : $url;
    }

    /** @param array<int,string> $matches */
    private function replace_img_callback(array $matches): string
    {
        $full_tag     = $matches[0];
        $original_src = $matches[2];

        $webp_url = self::get_webp_candidate_url($original_src);

        if (! $webp_url || $webp_url === $original_src || ! $this->webp_file_exists($webp_url)) {
            return $full_tag;
        }

        $webp_srcset = '';
        if (preg_match('/srcset=["\']([^"\']+)["\']/i', $full_tag, $m)) {
            $webp_srcset = $this->convert_srcset_to_webp($m[1]);
        }

        $sizes_attr = '';
        if (preg_match('/sizes=["\']([^"\']+)["\']/i', $full_tag, $m)) {
            $sizes_attr = ' sizes="' . esc_attr($m[1]) . '"';
        }

        $picture = '<picture>';
        $picture .= $webp_srcset
            ? '<source type="image/webp" srcset="' . esc_attr($webp_srcset) . '"' . $sizes_attr . '>'
            : '<source type="image/webp" srcset="' . esc_url($webp_url) . '"' . $sizes_attr . '>';
        $picture .= rtrim($full_tag, '/> ') . '>';
        $picture .= '</picture>';

        return $picture;
    }

    /*==============================================================
     * LAYER 2 — CSS url() OUTPUT BUFFER
     *============================================================*/

    public function start_output_buffer(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed()) {
            return;
        }
        if (defined('REST_REQUEST') || defined('XMLRPC_REQUEST')) {
            return;
        }
        ob_start([$this, 'process_buffer']);
    }

    public function process_buffer(string $html): string
    {
        if (empty($html)) {
            return $html;
        }

        // Skip buffer if page has no image-related CSS at all (fast path).
        if (stripos($html, 'url(') === false || ! preg_match('/\.(jpe?g|png|gif)(?:[?#][^"\'\s]*)?/i', $html)) {
            return $html;
        }

        // Double-quoted inline styles.
        $html = (string) preg_replace_callback(
            '/style\s*=\s*"([^"]*)"/i',
            function (array $m): string {
                if (stripos($m[1], 'url(') === false) {
                    return $m[0];
                }
                $new = $this->rewrite_css_urls($m[1]);
                return ($new !== $m[1]) ? 'style="' . $new . '"' : $m[0];
            },
            $html
        );

        // Single-quoted inline styles.
        $html = (string) preg_replace_callback(
            "/style\s*=\s*'([^']*)'/i",
            function (array $m): string {
                if (stripos($m[1], 'url(') === false) {
                    return $m[0];
                }
                $new = $this->rewrite_css_urls($m[1]);
                return ($new !== $m[1]) ? "style='" . $new . "'" : $m[0];
            },
            $html
        );

        // <style> blocks — only process blocks that contain url().
        $html = (string) preg_replace_callback(
            '/(<style[^>]*>)(.*?)(<\/style>)/is',
            function (array $m): string {
                if (stripos($m[2], 'url(') === false) {
                    return $m[0];
                }
                return $m[1] . $this->rewrite_css_urls($m[2]) . $m[3];
            },
            $html
        );

        // Elementor data-settings + data-elementor-settings.
        if (stripos($html, 'data-') !== false) {
            $html = (string) preg_replace_callback(
                '/(data-(?:elementor-)?settings)\s*=\s*["\'](\{[^"\']*\})["\']/',
                [$this, 'rewrite_elementor_data'],
                $html
            );
        }

        return $html;
    }

    /** @param array<int,string> $m */
    private function rewrite_elementor_data(array $m): string
    {
        $attr_name = $m[1];
        $json      = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');

        // Quick check: does the JSON contain any image extension?
        if (! preg_match('/\.(jpe?g|png|gif)/i', $json)) {
            return $m[0];
        }

        $new = (string) preg_replace_callback(
            '/(https?:\\\\?\/\\\\?\/[^"\'\\\\]+\.(jpe?g|png|gif))/i',
            function (array $u): string {
                $url  = stripslashes($u[0]);
                $webp = self::get_webp_candidate_url($url);
                if ($webp && $webp !== $url && $this->webp_file_exists($webp)) {
                    return addcslashes($webp, '/');
                }
                return $u[0];
            },
            $json
        );

        if ($new === $json) {
            return $m[0];
        }

        $q = str_contains($m[0], $attr_name . "='") ? "'" : '"';
        return $attr_name . '=' . $q . esc_attr($new) . $q;
    }

    /** Rewrite url() references in a CSS string. */
    private function rewrite_css_urls(string $css): string
    {
        return (string) preg_replace_callback(
            '/url\s*\(\s*(["\']?)([^"\')\s]+\.(jpe?g|png|gif))(\?[^"\')\s]*)?\1\s*\)/i',
            function (array $m): string {
                $q     = $m[1];
                $url   = $m[2];
                $query = $m[4] ?? '';
                $webp  = self::get_webp_candidate_url($url);
                if ($webp && $webp !== $url && $this->webp_file_exists($webp)) {
                    return 'url(' . $q . $webp . $query . $q . ')';
                }
                return $m[0];
            },
            $css
        );
    }

    /*==============================================================
     * LAYER 3 — ELEMENTOR CSS FILE REWRITING
     *============================================================*/

    public static function on_elementor_cache_clear(): void
    {
        add_action('shutdown', [self::class, 'rewrite_all_elementor_css']);
    }

    public function on_elementor_css_meta_update(
        int $meta_id,
        int $post_id,
        string $meta_key,
        mixed $meta_value = null
    ): void {
        if ($meta_key !== '_elementor_css') {
            return;
        }

        $upload = self::get_upload_dir();
        $file   = $upload['basedir'] . '/elementor/css/post-' . $post_id . '.css';

        if (file_exists($file)) {
            self::rewrite_single_css_file($file, $upload);
        }
    }

    /**
     * @return int Number of files modified.
     */
    public static function rewrite_all_elementor_css(): int
    {
        $upload  = self::get_upload_dir();
        $css_dir = $upload['basedir'] . '/elementor/css/';

        if (! is_dir($css_dir)) {
            return 0;
        }

        $files = glob($css_dir . '*.css');
        if (! $files) {
            return 0;
        }

        $count = 0;
        foreach ($files as $file) {
            if (self::rewrite_single_css_file($file, $upload)) {
                $count++;
            }
        }

        return $count;
    }

    private static function rewrite_single_css_file(string $file, array $upload): bool
    {
        $css = @file_get_contents($file);
        if (! $css) {
            return false;
        }

        // Fast path: no image URLs in this CSS file at all.
        if (! preg_match('/\.(jpe?g|png|gif)/i', $css)) {
            return false;
        }

        $base_dir = $upload['basedir'];
        $base_url = $upload['baseurl'];

        $new_css = (string) preg_replace_callback(
            '/url\s*\(\s*(["\']?)([^"\')\s]+\.(jpe?g|png|gif))(\?[^"\')\s]*)?\1\s*\)/i',
            function (array $m) use ($base_dir, $base_url): string {
                $q     = $m[1];
                $url   = $m[2];
                $query = $m[4] ?? '';

                $webp_url = self::get_webp_candidate_url($url);
                if (! $webp_url || $webp_url === $url) {
                    return $m[0];
                }

                if (str_starts_with($webp_url, $base_url)) {
                    $path = $base_dir . str_replace($base_url, '', $webp_url);
                    if (file_exists($path)) {
                        return 'url(' . $q . $webp_url . $query . $q . ')';
                    }
                }

                return $m[0];
            },
            $css
        );

        if ($new_css === $css) {
            return false;
        }

        return (bool) @file_put_contents($file, $new_css);
    }

    /*==============================================================
     * LAYER 3b — .htaccess
     *============================================================*/

    public static function update_htaccess_rules(): bool
    {
        $htaccess = get_home_path() . '.htaccess';

        if (! function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        $rules = [
            '<IfModule mod_rewrite.c>',
            '  RewriteEngine On',
            '  RewriteCond %{HTTP_ACCEPT} image/webp',
            '  RewriteCond %{REQUEST_FILENAME} (.*)\.(jpe?g|png|gif)$ [NC]',
            '  RewriteCond %1.webp -f',
            '  RewriteRule (.+)\.(jpe?g|png|gif)$ $1.webp [T=image/webp,L]',
            '</IfModule>',
            '<IfModule mod_headers.c>',
            '  <FilesMatch "\.(jpe?g|png|gif|webp)$">',
            '    Header append Vary Accept',
            '  </FilesMatch>',
            '</IfModule>',
        ];

        return insert_with_markers($htaccess, self::HTACCESS_MARKER, $rules);
    }

    public static function remove_htaccess_rules(): bool
    {
        $htaccess = get_home_path() . '.htaccess';

        if (! function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        return insert_with_markers($htaccess, self::HTACCESS_MARKER, []);
    }

    /*==============================================================
     * HELPERS
     *============================================================*/

    /**
     * Check whether a WebP file exists on disk for a given URL.
     * Results are cached for the duration of the request.
     */
    private function webp_file_exists(string $webp_url): bool
    {
        // Return cached result if we've checked this URL before.
        if (isset(self::$file_exists_cache[$webp_url])) {
            return self::$file_exists_cache[$webp_url];
        }

        $upload = self::get_upload_dir();
        $result = false;

        if ($webp_url === '') {
            self::$file_exists_cache[$webp_url] = false;
            return false;
        }

        $webp_url = trim($webp_url);
        $baseurl  = trailingslashit($upload['baseurl']);
        $basedir  = trailingslashit($upload['basedir']);

        $path = wp_parse_url($webp_url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            self::$file_exists_cache[$webp_url] = false;
            return false;
        }

        $upload_path = wp_parse_url($baseurl, PHP_URL_PATH);
        if (is_string($upload_path) && $upload_path !== '' && str_starts_with($path, $upload_path)) {
            $relative = ltrim(substr($path, strlen($upload_path)), '/');
            $result   = file_exists($basedir . $relative);
        } elseif (str_starts_with($path, '/wp-content/uploads/')) {
            $result = file_exists(ABSPATH . ltrim($path, '/'));
        }

        self::$file_exists_cache[$webp_url] = $result;
        return $result;
    }

    private function convert_srcset_to_webp(string $srcset): string
    {
        $out = [];
        foreach (explode(',', $srcset) as $entry) {
            $entry = trim($entry);
            if (empty($entry)) {
                continue;
            }
            $parts = preg_split('/\s+/', $entry, 2);
            if (! $parts) {
                continue;
            }
            $url  = $parts[0];
            $desc = $parts[1] ?? '';
            $webp = self::get_webp_candidate_url($url);
            if ($webp && $webp !== $url && $this->webp_file_exists($webp)) {
                $out[] = esc_url($webp) . ($desc ? ' ' . $desc : '');
            }
        }
        return implode(', ', $out);
    }
}
