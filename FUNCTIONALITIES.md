# AHM WordPress Setup & Core Automation: Functionalities Overview

This document provides a comprehensive overview of the functionalities, orchestration scripts, helpers, and plugin engines that form the AHM WordPress setup automation.

---

## 1. Local Environment Setup & Orchestration Scripts

These command-line orchestration scripts automate the setup, configuration, and scaffolding of a brand new local WordPress site or update existing sites inside a Laragon development environment.

### 🚀 WordPress Setup Script: [setup.bat](file:///c:/laragon/www/ahm-wp-setup/setup.bat)
A unified script to provision and configure a new WordPress site on Laragon.
- **Configurations & User Prompts**: Collects site details (Name, Title, Tagline, Elementor Content Width, Container Padding, Default Page Layout, and Elementor Atomic Editor experimental feature option).
- **Domain & Database Provisioning**: Automatically generates the target URL (`https://<site-name>.test`) and database name (converting hyphens to underscores).
- **Core Deployment**: Checks for local pre-extracted/zipped WordPress cores, falling back to a fresh download via the WordPress.org REST API (or a SourceForge mirror stable ZIP stream if the primary server returns 502/error).
- **WordPress Installation**: Configures database connection credentials, initializes the database schema, installs the WordPress core, and sets custom taglines.
- **Utility & Plugin Purge**: Removes default core plugins (`hello`, `akismet`) and default themes.
- **Plugin & Theme Installation**: Installs Elementor Core, Advanced Custom Fields (ACF), and the Hello Elementor parent theme. Deploys custom local zip plugins from the `assets/plugins/` directory:
  - **Pro Elements** (Elementor Pro features counterpart)
  - **AHM Core** (main custom site functionality & protection)
  - **Novamira AI** (AI content capabilities)
  - **SEO by Rank Math Pro**
  - **All-in-One WP Migration**
- **System Configurations**: Activates Hello Elementor Child Theme, sets permalink structure to "Post name", enables Elementor Flexbox Containers, and configures Elementor layout defaults (disabling default colors/typography schemes, enabling custom post type support).
- **Injection Orchestration**: Invokes PHP helper scripts via WP-CLI to inject Custom JS, Custom CSS, default pages, loop templates, and ACF Custom Post Types.

### 🔄 Apply Page Structure Script: [apply-pages.bat](file:///c:/laragon/www/ahm-wp-setup/apply-pages.bat)
Allows developers to apply the exact same page structure, custom templates, permalink settings, and Elementor configurations to an *already existing* site folder in Laragon www without doing a complete site reinstall.

---

## 2. Shell & Configuration Helpers
These PHP helper scripts are executed during setup via `wp eval-file` to handle specific configurations directly inside the WordPress runtime:

*   **[js-custom-code.php](file:///c:/laragon/www/ahm-wp-setup/helpers/js-custom-code.php)**: Inserts/updates an Elementor Pro "Custom Code" snippet containing base javascript loaded before the closing `</body>` tag site-wide. Automatically handles condition cache regeneration.
*   **[site-custom-css.php](file:///c:/laragon/www/ahm-wp-setup/helpers/site-custom-css.php)**: Writes global layout styles into Elementor's Site Settings Kit (`_elementor_page_settings['custom_css']`) and flushes Elementor's CSS file cache.
*   **[pages-setup.php](file:///c:/laragon/www/ahm-wp-setup/helpers/pages-setup.php)**: Idempotently generates core site pages (Homepage, Blogs, About, FAQs, Contact, Appointment, Terms of Service, Cookie Policy, Privacy Policy). Assigns the static Homepage and Posts page, imports template JSONs (e.g., [privacy-policy-template.json](file:///c:/laragon/www/ahm-wp-setup/helpers/pages/privacy-policy-template.json)), and flags each page as "Built with Elementor" (`_elementor_edit_mode = builder`).
*   **[acf-import.php](file:///c:/laragon/www/ahm-wp-setup/helpers/acf-import.php)**: Loops through custom ACF schema exports ([acf-treatment.json](file:///c:/laragon/www/ahm-wp-setup/assets/acf-treatment.json)) to programmatically register Field Groups, Custom Post Types, and Taxonomies.
*   **[elementor-template-import.php](file:///c:/laragon/www/ahm-wp-setup/helpers/elementor-template-import.php)**: Imports custom Elementor loop items, blocks, and templates ([for-menu-item-hover-image.json](file:///c:/laragon/www/ahm-wp-setup/assets/for-menu-item-hover-image.json)) directly into the Elementor Template Library.
*   **[update-layout-settings.php](file:///c:/laragon/www/ahm-wp-setup/helpers/update-layout-settings.php)**: Writes page constraints, container padding, and default page templates directly to the active Elementor global Kit document.

---

## 3. The Core Engine: AHM Core WordPress Plugin

Located in [ahm-core.php](file:///c:/laragon/www/ahm-wp-setup/assets/plugins/ahm-core/ahm-core/ahm-core.php), the AHM Core plugin is a protected, multi-functional tool designed to handle performance optimization, user management, and layout importing.

### 🛡️ Plugin UI Protection
To guarantee system stability, the plugin intercepts WordPress admin filters to prevent its own deactivation or deletion:
- Removes "Deactivate" and "Delete" links from the plugins page actions list.
- Intercepts `pre_update_option_active_plugins` to force itself to remain active.
- Hides the bulk action checkbox for AHM Core on `plugins.php` using admin head CSS overrides.

### 🖼️ Automatic Image Converter (WebP Engine)
Converts standard images to modern WebP format transparently, using GD Library or Imagick:
*   **Conversion Engine ([class-ahm-webp-converter.php](file:///c:/laragon/www/ahm-wp-setup/assets/plugins/ahm-core/ahm-core/includes/class-ahm-webp-converter.php))**: Detects system capabilities (`GD` or `Imagick`), converts JPEG/PNG/GIF image files to WebP formats, automatically handles auto-conversions of attachments and generated thumbnails upon upload, and optionally deletes original images while updating attachment URLs in the database.
*   **Frontend Delivery ([class-ahm-webp-frontend.php](file:///c:/laragon/www/ahm-wp-setup/assets/plugins/ahm-core/ahm-core/includes/class-ahm-webp-frontend.php))**:
    - **HTML Rewriting**: Automatically hooks `the_content` and image markup to convert standard `<img>` tags into `<picture>` elements containing WebP references and original fallback sources.
    - **CSS url() Injection**: Hooks the output buffer to convert inline styles and `<style>` blocks background image references into WebP equivalents for compatible browsers.
    - **Disk-Level Static CSS Rewrites**: Watches Elementor metadata updates and cache invalidations, rewriting generated post CSS files (e.g., `post-*.css`) directly on disk with WebP image references.
    - **WP Rocket Integration**: Triggers automated CSS rewrites whenever WP Rocket clears domain caches or Used CSS (RUCSS) files.
    - **Htaccess Rules fallback**: Optionally writes mod_rewrite rules to the site's `.htaccess` to handle direct content negotiation.
*   **WebP Admin Tab & Stats ([class-ahm-webp-admin.php](file:///c:/laragon/www/ahm-wp-setup/assets/plugins/ahm-core/ahm-core/includes/class-ahm-webp-admin.php))**: Registers settings for WebP quality (1-100), auto-conversion, and background CSS rewriting. Includes an AJAX bulk converter that scans the Media Library for unconverted assets, showing real-time statistics cached via transients. Adds a dedicated conversion column to the Media Library list.

### 👤 Quick User Creation Utility
Managed by **[class-ahm-quick-user.php](file:///c:/laragon/www/ahm-wp-setup/assets/plugins/ahm-core/ahm-core/includes/class-ahm-quick-user.php)**, this utility allows administrators to quickly register users:
- Generates a secure, randomized 16-character password.
- Sets the username as the first name and "ahm" as the last name.
- Sends plain-text account details and login links to the user's email via `wp_mail`.
- Includes client/server validations for duplicate emails, usernames, and roles.

### ⚡ Cache Manager
Managed by **[class-ahm-cache-manager.php](file:///c:/laragon/www/ahm-wp-setup/assets/plugins/ahm-core/ahm-core/includes/class-ahm-cache-manager.php)**, this handles complex caching relationships:
- Rebuilds caches in a strict sequential hierarchy to prevent stale resources:
  1.  **Elementor Cache Clear**: Deletes static CSS files.
  2.  **Elementor WebP Rewrite**: Rewrites newly generated files with WebP URLs.
  3.  **WP Rocket RUCSS Clear**: Clears/truncates the Used CSS tables.
  4.  **WP Rocket Cache Clear**: Flushes page HTML caches and minified assets.

---

## 4. Figma to Elementor Importer (Work in Progress Outline)

The Figma Importer integrates external design resources directly into Elementor Flexbox Containers. This feature is currently a work in progress and is outlined below:

### ⚙️ Importer Admin UI: [class-ahm-figma-admin.php](file:///c:/laragon/www/ahm-wp-setup/assets/plugins/ahm-core/ahm-core/includes/class-ahm-figma-admin.php)
Provides the interface inside AHM Core. Saves Personal Access Tokens (PAT) and Google Gemini API Keys. Handles Figma File URL validation, node ID extraction, and initiates the import request.

### 🔗 API Client: [class-figma-api.php](file:///c:/laragon/www/ahm-wp-setup/assets/plugins/ahm-core/ahm-core/includes/class-figma-api.php)
An authenticated HTTP wrapper communicating with Figma's REST endpoints (`/files` and `/images`). Caches responses for 1 hour to prevent API rate limiting, and implements rate-limit (429) cooldown countdowns and retries.

### 📐 Standard Layout Parser: [class-figma-parser.php](file:///c:/laragon/www/ahm-wp-setup/assets/plugins/ahm-core/ahm-core/includes/class-figma-parser.php)
Translates the hierarchical Figma JSON nodes into native Elementor array formats:
- **Containers**: Maps Figma `FRAME`, `GROUP`, and `SECTION` elements, translating Auto Layout (Flex direction, gap spacing, padding, borders, shadows, and backgrounds) into native Elementor container properties.
- **Widgets**: Converts text elements into Heading widgets with matching typography rules, and shape elements into either layout boxes or image widgets.
- **Images**: Automatically identifies node IDs with image fills, requests export URLs from Figma, downloads them, and creates an image mapping dictionary.

### 👁️ AI Vision Parser: [class-gemini-api.php](file:///c:/laragon/www/ahm-wp-setup/assets/plugins/ahm-core/ahm-core/includes/class-gemini-api.php)
An alternative parsing mode for complex non-Auto-Layout designs:
- Captures/requests a full-frame PNG screenshot of the Figma frame.
- Submits the base64 screenshot along with Figma's metadata structure to Google Gemini Multimodal APIs (`gemini-3.1-pro-preview`, `gemini-pro-latest`, `gemini-2.5-pro`, `gemini-2.5-flash`).
- Prompts Gemini to recreate the design and return structured Elementor Flexbox Container JSON data.
- Normalizes and sanitizes Gemini output (assigning unique UUIDs, structures) to ensure full render compatibility.

### 💾 Elementor Importer: [class-elementor-importer.php](file:///c:/laragon/www/ahm-wp-setup/assets/plugins/ahm-core/ahm-core/includes/class-elementor-importer.php)
The final database writer for the importer:
- **Sideload Image**: Downloads external Figma image assets to the local server, adds them to the WordPress Media Library, and registers attachment IDs.
- **Import Layout**: Inserts a new draft post/page and writes the slashed JSON layout string to the `_elementor_data` metadata field, updating page templates and edit modes.
- **Kit Adjustments**: Updates Elementor active kit layout configurations with default content widths and padding guidelines.
