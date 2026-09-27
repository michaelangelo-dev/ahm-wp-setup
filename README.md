# 🚀 AHM WordPress Automated Setup Engine (`ahm-wp-setup`)

An automated WordPress bootstrapping and boilerplate deployment engine engineered for rapid local development within the **Laragon** stack on Windows.

It fully automates core installation, database creation, plugin/theme provisioning, Elementor design system configuration, custom font self-hosting, ACF Custom Post Type imports, and baseline legal/marketing page scaffolding in under 60 seconds.

---

## 📋 Table of Contents
1. [Prerequisites & System Requirements](#-prerequisites--system-requirements)
2. [Workstation & Environment Setup](#-workstation--environment-setup)
3. [Repository Directory Structure](#-repository-directory-structure)
4. [Master Setup Guide (`setup.bat`)](#-master-setup-guide-setupbat)
5. [Auxiliary Scripts & Standalone Tools](#-auxiliary-scripts--standalone-tools)
   - [Apply Custom Fonts (`apply-fonts.bat`)](#a-apply-custom-fonts-apply-fontsbat)
   - [Apply Page Structure (`apply-pages.bat`)](#b-apply-page-structure-apply-pagesbat)
   - [Fetch Figma Node JSON (`fetch-figma-json.bat`)](#c-fetch-figma-node-json-fetch-figma-jsonbat)
   - [Repackage AHM Core (`package-ahm-core.ps1`)](#d-repackage-ahm-core-package-ahm-coreps1)
6. [Default Provisioning Credentials](#-default-provisioning-credentials)
7. [Developer Machine Calibration & Troubleshooting](#-developer-machine-calibration--troubleshooting)

---

## 💻 Prerequisites & System Requirements

Before running any script, ensure your local workstation meets the following specifications:

| Requirement | Supported Version / Specification | Purpose |
| :--- | :--- | :--- |
| **Operating System** | Windows 10 / 11 (64-bit) | Executes native `.bat` and `.ps1` automation scripts. |
| **Local Server Stack** | [Laragon (Full Edition)](https://laragon.org/) | Apache, MySQL/MariaDB, and PHP local web server environment. |
| **PHP** | PHP 8.1+ or 8.2+ | WordPress runtime and WP-CLI execution. |
| **PHP Extensions** | `curl`, `json`, `mbstring`, `mysqli`, `openssl`, `zip`, `xml` | Core WordPress, font scraping, and archive management. |
| **Database** | MySQL 8.0+ / MariaDB 10.4+ | Running locally on `localhost:3306` (`root` user, empty password). |
| **WP-CLI** | Latest (`wp.bat` in Windows `PATH`) | Headless WordPress management and script evaluation. |
| **PowerShell** | PowerShell 5.1+ or PowerShell 7+ | Automated `.zip` archive extraction and packaging. |
| **cURL** | Native Windows `curl.exe` in `PATH` | Fetching Google Fonts `.woff2` binaries and Figma API endpoints. |

---

## 🛠️ Workstation & Environment Setup

### 1. Clone Destination
Clone this repository directly into your Laragon `www` directory:
```cmd
git clone <repo-url> C:\laragon\www\ahm-wp-setup
```
> [!IMPORTANT]
> The scripts automatically resolve the parent folder (`..`) as `C:\laragon\www\`. New WordPress sites are provisioned as sibling folders alongside `ahm-wp-setup`.

### 2. Verify WP-CLI Installation
Ensure WP-CLI is globally accessible from your command line:
```cmd
wp --info
```
*If not installed, download `wp-cli.phar`, create a `wp.bat` wrapper in your PHP or Laragon bin folder, and add it to your Windows Environment `PATH`.*

### 3. Trust Laragon Apache SSL Certificate (One-Time Setup)
To ensure `https://*.test` local domains resolve securely without browser security warnings:
1. Open the **Laragon** control panel.
2. Navigate to: **Menu** -> **Apache** -> **SSL** -> **Add laragon.crt to Trust Store**.
3. Reload Apache.

---

## 📁 Repository Directory Structure

```text
C:\laragon\www\ahm-wp-setup\
├── .github/
│   └── pull_request_template.md             # Standard GitHub pull request template
├── assets/
│   ├── plugins/
│   │   ├── ahm-core.zip                     # Proprietary core functionality (Auto-activated)
│   │   ├── ahm-publisher.zip                # Proprietary publisher tools (Installed deactivated)
│   │   ├── All-In-One-WP-Migration-With-Import-master.zip  # Backup / migration utility
│   │   ├── pro-elements.zip                 # Pro Elements (Elementor Pro dynamic engine)
│   │   ├── seo-by-rank-math-pro.zip         # Rank Math Pro (Installed deactivated)
│   │   └── wp-rocket.zip                    # Performance & caching suite
│   ├── themes/
│   │   └── hello-elementor-child/           # Pre-configured Hello Elementor Child theme
│   ├── wordpress/
│   │   └── wordpress.zip                    # Bundled offline WordPress core archive
│   ├── acf-treatment.json                   # CPT 'treatment' and field groups schema
│   ├── base-custom-css.txt                  # Global CSS injected into Elementor Active Kit
│   ├── base-custom-js.txt                   # Global JS injected into Elementor Custom Code snippet
│   └── for-menu-item-hover-image.json       # Elementor Loop Item template
├── helpers/
│   ├── pages/                               # Elementor JSON page templates
│   │   ├── cookie-policy-template.json
│   │   ├── privacy-policy-template.json
│   │   └── terms-of-service-template.json
│   ├── acf-import.php                       # Imports ACF field groups, CPTs, and taxonomies
│   ├── custom-fonts-setup.php               # Scrapes & self-hosts Google Fonts in Elementor
│   ├── elementor-template-import.php        # Imports Elementor library templates / loop items
│   ├── js-custom-code.php                   # Injects Custom JS snippet before </body>
│   ├── package-ahm-core.ps1                 # Packages C:\laragon\www\ahm-core into assets/
│   ├── pages-setup.php                      # Scaffolds pages, reading settings, & templates
│   ├── site-custom-css.php                  # Injects global custom CSS into active Kit
│   └── update-layout-settings.php           # Injects width, padding, and layout to Kit
├── apply-fonts.bat                          # Retroactive font configuration script
├── apply-pages.bat                          # Retroactive page structure builder
├── fetch-figma-json.bat                     # Figma REST API node extractor
├── setup.bat                                # Master WordPress deployment script
└── README.md                                # Project documentation
```

---

## ⚡ Master Setup Guide (`setup.bat`)

To provision a brand new client website:

1. Open **Laragon Terminal** (or standard Windows Command Prompt).
2. Change directory to the setup root:
   ```cmd
   cd C:\laragon\www\ahm-wp-setup
   ```
3. Run the setup batch script:
   ```cmd
   setup.bat
   ```
4. Complete the interactive configuration prompts:

| Prompt | Example Value | Description |
| :--- | :--- | :--- |
| **Website Name (Folder Name)** | `dr-jane-clinic` | Creates `C:\laragon\www\dr-jane-clinic` & URL `https://dr-jane-clinic.test`. |
| **Site Title** | `Dr Jane Aesthetics Clinic` | Sets the WordPress site title. |
| **Site Tagline** | `Specialist Aesthetic Treatments` | Sets the WordPress blog description. |
| **Elementor Content Width** | `1140` | Global container content width (px) in Elementor Kit. |
| **Container Padding** | `10` | Global container padding (px) applied to all sides. |
| **Default Page Layout** | `1` | `1` = Theme (Default), `2` = Canvas, `3` = Full Width. |
| **Activate Atomic Editor?** | `N` | Enables Elementor V4 Atomic Elements experiment (`Y`/`N`). |
| **Disable Google Fonts & Self-Host?** | `Y` | Downloads and self-hosts fonts locally (`Y`/`N`). |
| **Google Fonts to Self-Host** | `Inter,Manrope,Sora` | Comma-separated list of Google Font families to provision. |
| **WordPress Core Source** | `L` | `[L]ocal` (uses offline bundled `wordpress.zip`) or `[F]resh` (downloads from WP.org). |

### Automated Pipeline Stages:
1. **Directory & Database Provisioning:** Creates project directory and MySQL database (e.g. `dr_jane_clinic`).
2. **WordPress Core Deployment:** Unpacks or downloads WordPress core and writes `wp-config.php` (with `WP_AUTO_UPDATE_CORE = false`).
3. **Core Installation:** Runs `wp core install` with standard admin credentials.
4. **Plugin & Theme Purge/Deployment:**
   - Purges default plugins (`hello`, `akismet`) and default Twenty* themes.
   - Installs & activates `elementor`, `advanced-custom-fields`, `stops-core-theme-and-plugin-updates`, `hello-elementor`, `pro-elements`, and `ahm-core`.
   - Copies and activates `hello-elementor-child`.
5. **Permalinks & Experiments:** Sets permalinks to `/blogs/%postname%/` and activates Elementor Flexbox Container experiment.
6. **Code Snippet & CSS Injection:**
   - Injects `Custom JS` from [`assets/base-custom-js.txt`](file:///C:/laragon/www/ahm-wp-setup/assets/base-custom-js.txt) into Elementor Custom Code (location: `elementor_body_end`).
   - Injects CSS from [`assets/base-custom-css.txt`](file:///C:/laragon/www/ahm-wp-setup/assets/base-custom-css.txt) into the active Elementor Kit.
7. **Page Scaffolding & Template Ingestion:**
   - Renames Sample Page to **Homepage** and sets it as the static front page.
   - Creates **Blogs** page and assigns it as the posts page.
   - Imports pre-built Elementor legal templates for **Privacy Policy**, **Terms of Service**, and **Cookie Policy**.
   - Scaffolds **About**, **FAQs**, **Contact**, and **Appointment** pages with Elementor metadata enabled.
8. **ACF & Elementor Template Ingestion:**
   - Imports CPT `treatment` and custom field groups from [`assets/acf-treatment.json`](file:///C:/laragon/www/ahm-wp-setup/assets/acf-treatment.json).
   - Imports Loop Item template from [`assets/for-menu-item-hover-image.json`](file:///C:/laragon/www/ahm-wp-setup/assets/for-menu-item-hover-image.json).
9. **Font Self-Hosting Engine:**
   - Downloads all font weights (100–900) as `.woff2` files to `wp-content/uploads/elementor/custom-fonts/<family>/`.
   - Registers `elementor_font` custom post types and terms.
   - Sets flags (`ahm_disable_google_fonts = yes` and `elementor_load_google_fonts = no`).
   - Syncs primary font preload URL into WP Rocket preload options and invalidates Elementor font caches.

---

## 🔧 Auxiliary Scripts & Standalone Tools

### A. Apply Custom Fonts (`apply-fonts.bat`)
Applies the universal font downloading and self-hosting engine retroactively to an **existing** local site:
1. Run [`apply-fonts.bat`](file:///C:/laragon/www/ahm-wp-setup/apply-fonts.bat).
2. Enter the existing site folder name (e.g. `dr-jane-clinic`).
3. Enter the comma-separated Google Font families (e.g. `Inter, Sora`).

### B. Apply Page Structure (`apply-pages.bat`)
Retroactively builds standard legal pages, Elementor templates, and post-name permalinks on an **existing** site:
1. Run [`apply-pages.bat`](file:///C:/laragon/www/ahm-wp-setup/apply-pages.bat).
2. Enter the existing site folder name.

### C. Fetch Figma Node JSON (`fetch-figma-json.bat`)
Extracts Figma frame AST / JSON payloads via the Figma REST API:
1. Run [`fetch-figma-json.bat`](file:///C:/laragon/www/ahm-wp-setup/fetch-figma-json.bat).
2. Provide your Figma Personal Access Token (`figd_...`).
3. Enter the Figma File Key and target Node ID (e.g. `50:2` or leave blank for structural overview).

### D. Repackage AHM Core (`package-ahm-core.ps1`)
When updates are made to the proprietary `ahm-core` codebase at `C:\laragon\www\ahm-core`, repackage the distribution zip:
```powershell
powershell -ExecutionPolicy Bypass -File .\helpers\package-ahm-core.ps1
```
*Outputs an updated, production-ready package to [`assets/plugins/ahm-core.zip`](file:///C:/laragon/www/ahm-wp-setup/assets/plugins/ahm-core.zip).*

---

## 🔐 Default Provisioning Credentials

| Setting | Default Value | Notes |
| :--- | :--- | :--- |
| **Admin Username** | `admin` | Default local administrator |
| **Admin Password** | `admin@123` | Default local administrator password |
| **Admin Email** | `michaelangelo@alliedhealthmedia.co.uk` | Admin email address |
| **Database Host** | `localhost` | Laragon MySQL port 3306 |
| **Database User** | `root` | Blank password (`""`) |
| **Local Site URL** | `https://<site-name>.test` | Routed automatically by Laragon virtual host |
| **Permalinks** | `/blogs/%postname%/` | Hardcoded default permalink structure |

---

## 🛠️ Developer Machine Calibration & Troubleshooting

### 1. MySQL Version Mismatch
In [`setup.bat`](file:///C:/laragon/www/ahm-wp-setup/setup.bat#L5), [`apply-fonts.bat`](file:///C:/laragon/www/ahm-wp-setup/apply-fonts.bat#L5), and [`apply-pages.bat`](file:///C:/laragon/www/ahm-wp-setup/apply-pages.bat#L5), the MySQL binary path is injected into the session:
```cmd
SET "PATH=%PATH%;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin"
```
> [!NOTE]
> If your Laragon installation uses a different MySQL or MariaDB version (e.g., `mysql-8.0.30-winx64` or `mariadb-10.11.x`), update line 5 of the batch files to match your local folder name, or ensure your MySQL `bin` directory is in your global system `PATH`.

### 2. Elementor Editor Stuck on Loading Screen
If clicking "Edit with Elementor" displays a perpetual loading spinner on a newly created site:
* Run `wp rewrite flush --hard` from the site's directory, or visit **WP Admin -> Settings -> Permalinks** and click **Save Changes**.

### 3. AHM Core Packaging Path
[`helpers/package-ahm-core.ps1`](file:///C:/laragon/www/ahm-wp-setup/helpers/package-ahm-core.ps1#L16) expects the active `ahm-core` repository to be located at:
```text
C:\laragon\www\ahm-core
```
If your `ahm-core` source directory is located elsewhere, adjust `$pluginSource` in `package-ahm-core.ps1` before packaging.
