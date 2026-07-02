# AHM WordPress Automation: Simple System Overview

This document provides a simple, non-technical overview of how our WordPress setup automation works. It is designed to explain the key features and benefits of this system without getting bogged down in code or developer terminology.

---

## 1. Fast Local Website Creator

Creating a new website from scratch normally requires downloading files, configuring databases, installing themes, and setting up settings manually. Our system automates all of this in seconds.

*   **One-Click Website Builder ([setup.bat](file:///c:/laragon/www/ahm-wp-setup/setup.bat))**: A simple tool that asks for the name, title, and tagline of your new site, then automatically sets up the local website address (e.g., `https://my-site.test`), creates the database, downloads and installs WordPress, and sets up all default options.
*   **Automatic Page Creation**: Automatically generates all standard pages for the site, including **Homepage**, **Blogs**, **About**, **FAQs**, **Contact**, **Appointment**, **Terms of Service**, **Cookie Policy**, and **Privacy Policy**.
*   **Page Layout Scaffold**: Connects the pages to our custom visual layouts, ensuring the site is ready for design customization immediately.
*   **Pre-loaded Content Modules**: Automatically imports treatment types, custom fields, and specialized layout templates (like image-hover menus) so that content structures are ready to go.
*   **Apply to Existing Sites ([apply-pages.bat](file:///c:/laragon/www/ahm-wp-setup/apply-pages.bat))**: A tool to add this standard page structure to any local website that has already been created, without having to reinstall it.

---

## 2. AHM Core: The Heart of the System

Our custom core manager (**[ahm-core.php](file:///c:/laragon/www/ahm-wp-setup/assets/plugins/ahm-core/ahm-core/ahm-core.php)**) runs inside the website. It is locked and protected so it cannot be accidentally deactivated or deleted by site administrators. It includes three major features:

### 🖼️ Automated Image Optimizer (WebP Booster)
Images are the main cause of slow websites. Our system automatically optimizes them as they are uploaded.
- **Instant WebP Conversion**: Converts uploaded images (JPEG, PNG, GIF) into the modern WebP format, which reduces image file sizes by up to 80% while keeping them crisp and clear.
- **Smart Replacement**: Swaps image tags and background images on pages with their optimized versions only when visitors use a browser that supports WebP.
- **Original File Cleanup**: Can automatically delete original heavy images after converting them, saving valuable storage space on the server.
- **Bulk Optimization Dashboard**: Allows you to scan all existing images on the site and convert them to optimized WebP format with a single click.

### 👤 Quick User Manager
Creating new accounts for team members or clients is simplified:
- **Instant Account Creation**: Just type in a username, email, and choose their access level (like Administrator or Editor).
- **Auto-Password & Email**: The system automatically generates a highly secure password, sets up their profile details, and sends their login credentials directly to their email.

### ⚡ Single-Click Cache Clearer
When changes are made to a website's design, sometimes visitors continue seeing older versions because the browser has cached the files.
- **Correct Clearing Order**: Clears Elementor page builders and WP Rocket optimization tools in a precise sequence to ensure updates go live instantly and without layout glitches.

---

## 3. Figma Design Importer (Work in Progress)

The Figma Importer is an advanced tool currently under development. Its goal is to bridges the gap between design and development by turning layout designs into working webpages.

*   **Importer Settings Panel**: A dashboard inside WordPress where you paste a Figma URL, set your content width guidelines, and click "Generate".
*   **Design Translator**:
    - **Standard Mode**: Reads Figma's structures, downloads all design images directly, and recreates the columns, text sizes, fonts, spacing, margins, buttons, and layouts inside WordPress.
    - **AI Vision Mode**: Captures a visual image of the Figma design and uses Google's Gemini AI to analyze the screenshot, automatically constructing a matching WordPress layout.
