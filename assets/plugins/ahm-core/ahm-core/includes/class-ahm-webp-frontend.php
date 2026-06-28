<?php
/**
 * Front-end WebP delivery.
 *
 * Layer 1: <img> → <picture> with WebP <source> + original fallback.
 * Layer 2: CSS url() rewriting via output buffer (inline styles,
 *          <style> blocks, Elementor data-settings).
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

        // Layer 2 — CSS url() rewriting.
        if (! empty($this->settings['serve_webp_css']) && $this->browser_supports_webp) {
            add_action('template_redirect', [$this, 'start_output_buffer'], 1);
        }
    }

    /*==============================================================
     * LAYER 1 — <img> → <picture>
     *============================================================*/

    public function convert_content_images(string $content): string
    {
        if (empty($content) || is_admin() || wp_doing_ajax()) {
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

        return (string) preg_replace_callback(
            '/<img\b([^>]*?)src=["\']([^"\']+\.(jpe?g|png|gif))["\']([^>]*?)\/?>/i',
            [$this, 'replace_img_callback'],
            $html
        );
    }

    /** @param array<int,string> $matches */
    private function replace_img_callback(array $matches): string
    {
        $full_tag     = $matches[0];
        $original_src = $matches[2];

        $webp_url = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $original_src);

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
     * LAYER 2 — CSS url() REWRITING
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

        // Inline style="" attributes.
        $html = (string) preg_replace_callback(
            '/style\s*=\s*["\']([^"\']*?url\s*\([^)]+\)[^"\']*?)["\']/i',
            [$this, 'rewrite_inline_style'],
            $html
        );

        // <style> blocks.
        $html = (string) preg_replace_callback(
            '/(<style[^>]*>)(.*?)(<\/style>)/is',
            [$this, 'rewrite_style_block'],
            $html
        );

        // Elementor data-settings.
        $html = (string) preg_replace_callback(
            '/data-settings\s*=\s*["\'](\{[^"\']*\})["\']/',
            [$this, 'rewrite_elementor_data'],
            $html
        );

        return $html;
    }

    /** @param array<int,string> $m */
    private function rewrite_inline_style(array $m): string
    {
        $new_css = $this->rewrite_css_urls($m[1]);
        if ($new_css === $m[1]) {
            return $m[0];
        }
        $q = str_contains($m[0], "style='") ? "'" : '"';
        return 'style=' . $q . $new_css . $q;
    }

    /** @param array<int,string> $m */
    private function rewrite_style_block(array $m): string
    {
        return $m[1] . $this->rewrite_css_urls($m[2]) . $m[3];
    }

    /** @param array<int,string> $m */
    private function rewrite_elementor_data(array $m): string
    {
        $json = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

        $new = (string) preg_replace_callback(
            '/(https?:\\\\?\/\\\\?\/[^"\'\\\\]+\.(jpe?g|png|gif))/i',
            function (array $u): string {
                $url  = stripslashes($u[0]);
                $webp = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $url);
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

        $q = str_contains($m[0], "data-settings='") ? "'" : '"';
        return 'data-settings=' . $q . esc_attr($new) . $q;
    }

    /**
     * Rewrite all url() references inside a CSS string.
     */
    private function rewrite_css_urls(string $css): string
    {
        return (string) preg_replace_callback(
            '/url\s*\(\s*(["\']?)([^"\')\s]+\.(jpe?g|png|gif))\1\s*\)/i',
            function (array $m): string {
                $q    = $m[1];
                $url  = $m[2];
                $webp = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $url);
                if ($webp && $webp !== $url && $this->webp_file_exists($webp)) {
                    return 'url(' . $q . $webp . $q . ')';
                }
                return $m[0];
            },
            $css
        );
    }

    /*==============================================================
     * HELPERS
     *============================================================*/

    private function webp_file_exists(string $webp_url): bool
    {
        $upload = wp_get_upload_dir();
        if (str_starts_with($webp_url, $upload['baseurl'])) {
            return file_exists($upload['basedir'] . str_replace($upload['baseurl'], '', $webp_url));
        }
        return false;
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
            $webp = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $url);
            if ($webp && $webp !== $url && $this->webp_file_exists($webp)) {
                $out[] = esc_url($webp) . ($desc ? ' ' . $desc : '');
            }
        }
        return implode(', ', $out);
    }
}
