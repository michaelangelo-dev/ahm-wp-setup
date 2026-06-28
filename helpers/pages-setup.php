<?php

/**
 * pages-setup.php
 * -----------------------------------------------------------------------------
 * Builds the standard page structure and hands the relevant pages to Elementor.
 * All logic lives here (in PHP) instead of in the .bat file, so there is no
 * cmd.exe / for-loop quoting to break.
 *
 * Run it against ANY existing WordPress site from that site's root:
 *
 *     wp eval-file path\to\pages-setup.php --user=admin
 *
 * It is safe to run more than once (idempotent) - existing pages are reused,
 * not duplicated.
 * -----------------------------------------------------------------------------
 */

if (! defined('ABSPATH')) {
    WP_CLI::error('Run this via:  wp eval-file pages-setup.php --user=admin');
}

/**
 * Find a page by slug across ALL statuses.
 * (The default Privacy Policy page ships as a draft, so we cannot limit to publish.)
 */
function bs_find_page_by_slug($slug)
{
    $q = new WP_Query(array(
        'post_type'      => 'page',
        'name'           => $slug,
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ));
    return $q->have_posts() ? $q->posts[0] : null;
}

/**
 * Flag a page as "Built with Elementor" by writing the same post meta that
 * Elementor sets the first time you click "Edit with Elementor".
 */
function bs_mark_elementor($post_id, $elementor_data = '[]')
{
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
    update_post_meta($post_id, '_elementor_template_type', 'wp-page');
    update_post_meta($post_id, '_elementor_data', wp_slash($elementor_data));
    if (defined('ELEMENTOR_VERSION')) {
        update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);
    }
}

/**
 * Load Elementor page data from a template JSON file, replace domain names, and extract the content array.
 */
function bs_load_elementor_template($slug)
{
    $template_file = __DIR__ . '/pages/' . $slug . '-template.json';
    if (! file_exists($template_file)) {
        return '[]';
    }

    $raw_json = file_get_contents($template_file);
    if (! $raw_json) {
        return '[]';
    }

    // Replace the old template domain with the current site URL (both escaped and unescaped)
    $old_domain = 'https://domain.com';
    $old_domain_escaped = 'https:\/\/domain.com';

    $new_domain = untrailingslashit(get_site_url());
    $new_domain_escaped = str_replace('/', '\/', $new_domain);

    $raw_json = str_replace($old_domain_escaped, $new_domain_escaped, $raw_json);
    $raw_json = str_replace($old_domain, $new_domain, $raw_json);

    // Decode to extract the 'content' field if it is a full Elementor template export
    $data = json_decode($raw_json, true);
    if (is_array($data) && isset($data['content'])) {
        return json_encode($data['content']);
    }

    return $raw_json;
}

/**
 * Return the ID of a page with the given slug, creating it (published) if missing.
 */
function bs_ensure_page($title, $slug, $content = '')
{
    $existing = bs_find_page_by_slug($slug);
    if ($existing) {
        return (int) $existing->ID;
    }
    $id = wp_insert_post(array(
        'post_title'   => $title,
        'post_name'    => $slug,
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ));
    return is_wp_error($id) ? 0 : (int) $id;
}

// --- 8a. Sample Page -> Homepage (rename title + slug) ----------------------
$sample = bs_find_page_by_slug('sample-page');
if ($sample) {
    wp_update_post(array(
        'ID'          => $sample->ID,
        'post_title'  => 'Homepage',
        'post_name'   => 'homepage',
        'post_status' => 'publish',
    ));
    $homepage_id = (int) $sample->ID;
    WP_CLI::log("[8a] Renamed Sample Page (ID {$homepage_id}) -> Homepage / slug 'homepage'.");
} else {
    $homepage_id = bs_ensure_page('Homepage', 'homepage');
    WP_CLI::log("[8a] No Sample Page found - ensured Homepage exists (ID {$homepage_id}).");
}

bs_mark_elementor($homepage_id);
WP_CLI::log(" - Homepage (ID {$homepage_id}) ready + Built with Elementor.");

// --- 8b. Settings -> Reading: static front page -----------------------------
update_option('show_on_front', 'page');
update_option('page_on_front', $homepage_id);
WP_CLI::log('[8b] Set Homepage as the static front page.');

// --- 8c. Blogs page -> assigned as the Posts page ---------------------------
$blogs_id = bs_ensure_page('Blogs', 'blogs');
update_option('page_for_posts', $blogs_id);
WP_CLI::log("[8c] Ensured Blogs (ID {$blogs_id}) and set it as the Posts page.");

// --- 8d. Privacy Policy -> clear content, publish, hand to Elementor --------
$privacy = bs_find_page_by_slug('privacy-policy');
if ($privacy) {
    wp_update_post(array(
        'ID'           => $privacy->ID,
        'post_content' => '',
        'post_status'  => 'publish',
    ));
    $privacy_id = (int) $privacy->ID;
} else {
    $privacy_id = bs_ensure_page('Privacy Policy', 'privacy-policy');
}
$privacy_data = bs_load_elementor_template('privacy-policy');
bs_mark_elementor($privacy_id, $privacy_data);
if ($privacy_data !== '[]') {
    WP_CLI::log("[8d] Privacy Policy (ID {$privacy_id}) cleared, published, loaded custom Elementor template.");
} else {
    WP_CLI::log("[8d] Privacy Policy (ID {$privacy_id}) cleared, published, Built with Elementor (empty).");
}

// --- 8e. Core content pages -> publish + hand to Elementor ------------------
$pages = array(
    'About'            => 'about',
    'FAQs'             => 'faqs',
    'Contact'          => 'contact',
    'Appointment'      => 'appointment',
    'Terms of Service' => 'terms-of-service',
    'Cookie Policy'    => 'cookie-policy',
);
foreach ($pages as $title => $slug) {
    $pid = bs_ensure_page($title, $slug);
    if ($pid) {
        $elementor_data = bs_load_elementor_template($slug);
        bs_mark_elementor($pid, $elementor_data);
        if ($elementor_data !== '[]') {
            WP_CLI::log(" - {$title} (ID {$pid}) ready + loaded custom Elementor template.");
        } else {
            WP_CLI::log(" - {$title} (ID {$pid}) ready + Built with Elementor (empty).");
        }
    } else {
        WP_CLI::warning(" - Failed to create {$title}.");
    }
}

// Force WordPress to recognize mod_rewrite in CLI context to write the physical .htaccess file
add_filter('got_rewrite', '__return_true');
flush_rewrite_rules(true);
WP_CLI::success('Page structure complete.');
