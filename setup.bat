@echo off
setlocal enabledelayedexpansion

:: Force-inject Laragon's MySQL path for this specific terminal session
SET "PATH=%PATH%;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin"

:: -------------------------------------------------------------------------
:: PATH ANCHORS (resolved from where THIS script lives, not the current dir)
:: -------------------------------------------------------------------------
:: SCRIPT_DIR = ...\www\wp-setup\   (always ends with a backslash)
set "SCRIPT_DIR=%~dp0"
:: WWW_DIR = the parent of wp-setup, i.e. ...\www  (where new sites are created)
for %%I in ("%~dp0..") do set "WWW_DIR=%%~fI"

:: Bundled assets shipped inside the wp-setup folder
set "PLUGIN_PRO_ELEMENTS_ZIP=%SCRIPT_DIR%assets\plugins\pro-elements.zip"
set "PLUGIN_AHM_CORE_ZIP=%SCRIPT_DIR%assets\plugins\ahm-core.zip"
set "PLUGIN_NOVAMIRA=%SCRIPT_DIR%assets\plugins\novamira-1.7.1.zip"
set "PLUGIN_RANK_MATH_PRO_ZIP=%SCRIPT_DIR%assets\plugins\seo-by-rank-math-pro.zip"
set "PLUGIN_AIO_MIGRATION_ZIP=%SCRIPT_DIR%assets\plugins\All-In-One-WP-Migration-With-Import-master.zip"
set "CHILD_THEME_SRC=%SCRIPT_DIR%assets\themes\hello-elementor-child"
:: Local WordPress core: either a pre-extracted folder OR a zip inside this dir
set "WP_LOCAL_DIR=%SCRIPT_DIR%assets\wordpress"
set "WP_LOCAL_ZIP=%WP_LOCAL_DIR%\wordpress.zip"

:: -------------------------------------------------------------------------
:: STEP 1: INITIALIZE CONFIGURATION & DYNAMIC FOLDER CREATION
:: -------------------------------------------------------------------------
echo =========================================================================
echo             WORDPRESS STEP-BY-STEP BOILERPLATE INITIALIZATION
echo =========================================================================
echo.

:: Prompt for the website name dynamically
set /p SITE_NAME="[STEP 1] Enter the Website Name (Folder Name): "
set /p SITE_TITLE="[STEP 1] Enter the Site Title: "
set /p SITE_TAGLINE="[STEP 1] Enter the Site Tagline: "

:: 1. Folder Name: Stays exactly as entered (e.g., wp-test-automation)
set "FOLDER_NAME=%SITE_NAME%"

:: 2. Site URL: Uses the folder name with .test extension (e.g., http://wp-test-automation.test)
set SITE_URL="https://%FOLDER_NAME%.test"

:: 3. Database Name: Converts hyphens to underscores (e.g., wp_test_automation)
set "DB_NAME=%FOLDER_NAME:-=_%"

echo.
echo Project Folder:          %SITE_NAME%
echo Site Title:              %SITE_TITLE%
echo Site Tagline:            %SITE_TAGLINE%
echo New Target Directory:    %WWW_DIR%\%FOLDER_NAME%
echo Target URL:              %SITE_URL%
echo Database Name to Create: %DB_NAME%
echo.

:: Move into the www directory so the site folder is always created there
cd /d "%WWW_DIR%"

:: Check if directory already exists
if exist "%FOLDER_NAME%" (
    echo [WARNING] A folder named "%FOLDER_NAME%" already exists in www.
    echo If you proceed, files might be overwritten or installation could fail.
)

echo Please confirm the generation parameters before proceeding.
echo.

:: Create the new folder and move the execution context inside it
echo Creating directory "%WWW_DIR%\%FOLDER_NAME%"...
mkdir "%FOLDER_NAME%"
cd "%FOLDER_NAME%"

echo Switched directory context to: %CD%
echo.

:: -------------------------------------------------------------------------
:: STEP 2: WORDPRESS CORE (LOCAL OR FRESH) & CONFIGURATION
:: -------------------------------------------------------------------------
echo =========================================================================
echo [STEP 2] DEPLOYING WORDPRESS CORE ^& CREATING CONFIGURATION FILE
echo =========================================================================

:: Ask where the WordPress core should come from
set /p WP_SOURCE="[STEP 2] WordPress core source - [L]ocal file or [F]resh download? (L/F): "

if /i "%WP_SOURCE%"=="L" goto :use_local
goto :fresh_download

:use_local
:: Option A: a pre-extracted core folder (assets\wordpress\wp-load.php present)
if exist "%WP_LOCAL_DIR%\wp-load.php" (
    echo Copying local pre-extracted WordPress core from "%WP_LOCAL_DIR%"...
    xcopy "%WP_LOCAL_DIR%\*" "." /E /H /I /Y >nul
    goto :core_done
)
:: Option B: a local zip (assets\wordpress\wordpress.zip)
if exist "%WP_LOCAL_ZIP%" (
    echo Extracting local WordPress core from "%WP_LOCAL_ZIP%"...
    powershell -NoProfile -Command "Expand-Archive -LiteralPath '%WP_LOCAL_ZIP%' -DestinationPath '.' -Force"
    if !ERRORLEVEL! neq 0 (
        echo [WARNING] Extraction failed. Falling back to fresh download...
        goto :fresh_download
    )
    :: Official WP zips extract into a "wordpress\" subfolder -- flatten it
    if exist "wordpress\wp-load.php" (
        xcopy "wordpress\*" "." /E /H /I /Y >nul
        rmdir /S /Q "wordpress"
    )
    goto :core_done
)
echo [WARNING] No local core found in "%WP_LOCAL_DIR%". Falling back to fresh download...

:fresh_download
echo Attempting to download latest WordPress core via standard repository...

:: Try the primary WordPress.org ZIP stream link first
call wp core download "https://wordpress.org/latest.zip"

:: Check if the primary download failed (non-zero exit code)
if %ERRORLEVEL% neq 0 (
    echo.
    echo [WARNING] Primary repository returned an error or 502.
    echo Switching to alternative stable mirror source...
    echo.

    :: Fallback to the SourceForge mirror source
    call wp core download "https://sourceforge.net/projects/wordpress.mirror/files/7.0/WordPress%207.0%20source%20code.zip"

    :: If the fallback also fails, then abort the script safely
    if !ERRORLEVEL! neq 0 (
        echo [CRITICAL] Both primary and fallback mirror locations failed. Check your internet connection.
        exit /b
    )
)

:core_done
echo.
echo WordPress core is in place.
echo.
echo Creating wp-config.php file...
call wp core config --dbname=%DB_NAME% --dbuser=root --dbpass=admin123
if %ERRORLEVEL% neq 0 (
    echo Error generating wp-config.php.
    exit /b
)

echo.
echo Core files deployed and configuration generated successfully.
echo.

:: -------------------------------------------------------------------------
:: STEP 3: DATABASE GENERATION & INSTALLATION
:: -------------------------------------------------------------------------
echo =========================================================================
echo [STEP 3] DATABASE GENERATION AND WORDPRESS INSTALLATION
echo =========================================================================
echo Attempting to automatically create the database %DB_NAME% in MySQL...
call wp db create
echo.
echo Running the core runtime setup installation...
call wp core install --url=%SITE_URL% --title="%SITE_TITLE%" --admin_user="admin" --admin_password="admin@123" --admin_email="michaelangelo@alliedhealthmedia.co.uk"
if %ERRORLEVEL% neq 0 (
    echo Error during WordPress installation. Check database connection or credentials.
    exit /b
)

echo Updating Site Tagline...
call wp option update blogdescription "%SITE_TAGLINE%"

echo.
echo WordPress core installation finished successfully.
echo.

:: -------------------------------------------------------------------------
:: STEP 4: PLUGINS & THEMES DEPLOYMENT
:: -------------------------------------------------------------------------
echo =========================================================================
echo [STEP 4] PURGING UTILITIES, THEMES, AND CORE PLUGINS INSTALLATION
echo =========================================================================
echo Purging default plugins (hello, akismet)...
call wp plugin delete hello akismet

echo.
echo Installing Elementor, ACF, and Hello Elementor Theme...
call wp plugin install elementor advanced-custom-fields --activate
call wp theme install hello-elementor --activate

echo.
echo Purging all remaining default WordPress themes...
:: Deletes everything else while automatically protecting your active Hello child/parent setup
call wp theme delete --all

echo.
echo Verifying and installing custom local zip plugins (Activated)...
if exist "%PLUGIN_PRO_ELEMENTS_ZIP%" (
    call wp plugin install "%PLUGIN_PRO_ELEMENTS_ZIP%" --activate
) else (
    echo Warning: Pro Elements zip not found at "%PLUGIN_PRO_ELEMENTS_ZIP%". Skipping.
)

if exist "%PLUGIN_AHM_CORE_ZIP%" (
    call wp plugin install "%PLUGIN_AHM_CORE_ZIP%" --activate
) else (
    echo Warning: AHM Core zip not found at "%PLUGIN_AHM_CORE_ZIP%". Skipping.
)

if exist "%PLUGIN_NOVAMIRA%" (
    call wp plugin install "%PLUGIN_NOVAMIRA%" --activate
) else (
    echo Warning: Novamira AI WordPress zip not found at "%PLUGIN_NOVAMIRA%". Skipping.
)

echo.
echo Installing additional repository plugins (Deactivated)...
call wp plugin install seo-by-rank-math wp-mail-smtp better-wp-security wp-security-audit-log

echo.
echo Verifying and installing additional local zip plugins (Deactivated)...
if exist "%PLUGIN_RANK_MATH_PRO_ZIP%" (
    call wp plugin install "%PLUGIN_RANK_MATH_PRO_ZIP%"
) else (
    echo Warning: Rank Math Pro zip not found at "%PLUGIN_RANK_MATH_PRO_ZIP%". Skipping.
)

if exist "%PLUGIN_AIO_MIGRATION_ZIP%" (
    call wp plugin install "%PLUGIN_AIO_MIGRATION_ZIP%"
) else (
    echo Warning: All In One WP Migration zip not found at "%PLUGIN_AIO_MIGRATION_ZIP%". Skipping.
)

echo.
echo Core themes and plugins configured.
echo.

:: -------------------------------------------------------------------------
:: STEP 5: CHILD THEME, PERMALINKS, AND DESIGN SYSTEM INJECTIONS
:: -------------------------------------------------------------------------
echo =========================================================================
echo [STEP 5] FINAL SYSTEM CONFIGURATIONS AND COMPONENT INJECTIONS
echo =========================================================================

echo.
echo Deploying and activating Hello Elementor Child theme from repository...
:: Check if the bundled child theme master folder exists
if exist "%CHILD_THEME_SRC%" (
    :: Copy the entire folder structure natively (/I creates destination folder if missing, /E copies all subdirectories)
    xcopy "%CHILD_THEME_SRC%" "wp-content\themes\hello-elementor-child" /E /I /Y >nul

    :: Activate the newly copied child theme via its folder slug name
    call wp theme activate hello-elementor-child
) else (
    echo Warning: Child theme folder not found at "%CHILD_THEME_SRC%". Falling back to clean scaffold generation...
    call wp scaffold child-theme hello-elementor-child --parent_theme=hello-elementor --activate
)

echo.
echo Core child theme deployed.
echo.

echo Enabling Elementor "Container" experiment...
call wp elementor experiments activate container

echo Setting permalink structure to "Post name"...
:: %%postname%% -> literal %postname% once the batch parser is done with it
call wp rewrite structure "/%%%%postname%%%%/"
if %ERRORLEVEL% neq 0 (
    echo Warning: Could not set permalink structure.
) else (
    echo Permalinks set to "Post name".
)

echo.

:: -------------------------------------------------------------------------
:: STEP 6: ELEMENTOR CUSTOM CODE INJECTION ("Custom JS")
:: -------------------------------------------------------------------------
echo =========================================================================
echo [STEP 6] INJECTING ELEMENTOR CUSTOM CODE SNIPPET "Custom JS"
echo =========================================================================

:: These live inside the wp-setup folder, resolved from this script's location.
set "CUSTOM_JS_FILE=%SCRIPT_DIR%assets\base-custom-js.txt"
set "PHP_HELPER=%SCRIPT_DIR%helpers\js-custom-code.php"

if not exist "%CUSTOM_JS_FILE%" (
    echo Warning: Custom JS file not found at "%CUSTOM_JS_FILE%". Skipping Step 6.
    goto :step6done
)
if not exist "%PHP_HELPER%" (
    echo Warning: PHP helper not found at "%PHP_HELPER%". Skipping Step 6.
    goto :step6done
)

echo Reading code from: %CUSTOM_JS_FILE%
echo Creating "Custom JS" snippet (before ^</body^>, priority 10, entire site)...
call wp eval-file "%PHP_HELPER%" "%CUSTOM_JS_FILE%" --user=admin
if !ERRORLEVEL! neq 0 (
    echo Warning: Failed to create the Elementor Custom Code snippet.
) else (
    echo Elementor Custom Code "Custom JS" injected successfully.
)

:step6done
echo.

:: -------------------------------------------------------------------------
:: STEP 7: SITE SETTINGS CUSTOM CSS INJECTION
:: -------------------------------------------------------------------------
echo =========================================================================
echo [STEP 7] INJECTING SITE SETTINGS CUSTOM CSS
echo =========================================================================

:: These live inside the wp-setup folder, resolved from this script's location.
set "CUSTOM_CSS_FILE=%SCRIPT_DIR%assets\base-custom-css.txt"
set "CSS_HELPER=%SCRIPT_DIR%helpers\site-custom-css.php"

if not exist "%CUSTOM_CSS_FILE%" (
    echo Warning: Custom CSS file not found at "%CUSTOM_CSS_FILE%". Skipping Step 7.
    goto :step7done
)
if not exist "%CSS_HELPER%" (
    echo Warning: CSS helper not found at "%CSS_HELPER%". Skipping Step 7.
    goto :step7done
)

echo Reading CSS from: %CUSTOM_CSS_FILE%
echo Writing to Site Settings -^> Custom CSS on the active kit...
call wp eval-file "%CSS_HELPER%" "%CUSTOM_CSS_FILE%" --user=admin
if !ERRORLEVEL! neq 0 (
    echo Warning: Failed to write Site Settings Custom CSS.
) else (
    echo Site Settings Custom CSS injected successfully.
)

:step7done
echo.

:: -------------------------------------------------------------------------
:: STEP 8: PAGE SCAFFOLDING, FRONT-PAGE / BLOG SETUP ^& ELEMENTOR HAND-OFF
:: -------------------------------------------------------------------------
echo =========================================================================
echo [STEP 8] BUILDING SITE PAGE STRUCTURE AND ELEMENTOR HAND-OFF
echo =========================================================================

:: All page logic lives in the PHP helper (run via wp eval-file) so there is no
:: cmd.exe / for-loop quoting to break. The helper is idempotent.
set "PAGES_HELPER=%SCRIPT_DIR%helpers\pages-setup.php"

if not exist "%PAGES_HELPER%" (
    echo Warning: Page helper not found at "%PAGES_HELPER%". Skipping Step 8.
    goto :step8done
)

echo Building pages via helper: %PAGES_HELPER%
echo -------------------------------------------------------------------------
call wp eval-file "%PAGES_HELPER%" --user=admin
echo -------------------------------------------------------------------------
if !ERRORLEVEL! neq 0 (
    echo Warning: Page structure helper reported an error.
) else (
    echo Page structure created successfully.
)

:step8done
echo.

:: -------------------------------------------------------------------------
:: STEP 9: ACF JSON IMPORT (CUSTOM POST TYPES & FIELD GROUPS)
:: -------------------------------------------------------------------------
echo =========================================================================
echo [STEP 9] IMPORTING ACF JSON CONFIGURATIONS
echo =========================================================================

:: These live inside the wp-setup folder, resolved from this script's location.
set "ACF_JSON_FILE=%SCRIPT_DIR%assets\acf-treatment.json"
set "ACF_HELPER=%SCRIPT_DIR%helpers\acf-import.php"

if not exist "%ACF_JSON_FILE%" (
    echo [WARNING] ACF JSON file not found at "%ACF_JSON_FILE%". Skipping Step 9.
    goto :step9done
)
if not exist "%ACF_HELPER%" (
    echo [WARNING] PHP helper not found at "%ACF_HELPER%". Skipping Step 9.
    goto :step9done
)

echo Reading ACF configurations from: %ACF_JSON_FILE%
echo Importing Post Types and Field Groups into the database...
call wp eval-file "%ACF_HELPER%" "%ACF_JSON_FILE%" --user=admin
if !ERRORLEVEL! neq 0 (
    echo [WARNING] Failed to import ACF JSON.
) else (
    echo ACF configurations imported successfully.
)

:: -------------------------------------------------------------------------
:: IMPORT ELEMENTOR TEMPLATES / LOOP ITEMS
:: -------------------------------------------------------------------------
set "LOOP_JSON_FILE=%SCRIPT_DIR%assets\for-menu-item-hover-image.json"
set "ELEM_HELPER=%SCRIPT_DIR%helpers\elementor-template-import.php"

if exist "%LOOP_JSON_FILE%" (
    if exist "%ELEM_HELPER%" (
        echo Reading Elementor template from: %LOOP_JSON_FILE%
        call wp eval-file "%ELEM_HELPER%" "%LOOP_JSON_FILE%" --user=admin
        if !ERRORLEVEL! neq 0 (
            echo [WARNING] Failed to import Elementor Template.
        ) else (
            echo Elementor Template imported successfully.
        )
    )
)

:step9done
echo.

echo Setting permalink structure to "Post name" just to be sure...
:: %%postname%% -> literal %postname% once the batch parser is done with it
call wp rewrite structure "/%%%%postname%%%%/"
if %ERRORLEVEL% neq 0 (
    echo Warning: Could not set permalink structure.
) else (
    echo Permalinks set to "Post name".
)

:: Configure Elementor General Settings
echo Configuring Elementor General Settings...
call wp option update elementor_disable_color_schemes "yes"
call wp option update elementor_disable_typography_schemes "yes"
call wp option update elementor_cpt_support "[\"post\",\"page\",\"treatment\"]" --format=json

echo =========================================================================
echo SETUP COMPLETE: CONFIGURATION SUCCESSFUL FOR "%SITE_NAME%"
echo =========================================================================
echo Visit Local Dev URL: %SITE_URL%/wp-admin
echo Note: If Edit with Elementor is stuck, resave the permalinks to post name
echo Note: If your browser says "Not secure", you need to manually install
echo       the Apache SSL certificate in the Laragon menu:
echo       Apache -> SSL -> Add laragon.crt to Trust Store
echo =========================================================================
echo.
pause