@echo off
setlocal enabledelayedexpansion

:: Force-inject Laragon's MySQL path for this terminal session
SET "PATH=%PATH%;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin"

:: Resolve paths from where THIS script lives
set "SCRIPT_DIR=%~dp0"
for %%I in ("%~dp0..") do set "WWW_DIR=%%~fI"
set "FONTS_HELPER=%SCRIPT_DIR%helpers\custom-fonts-setup.php"

echo =========================================================================
echo   APPLY ELEMENTOR CUSTOM FONTS TO AN EXISTING WP SITE
echo =========================================================================
echo.

if not exist "%FONTS_HELPER%" (
    echo [ERROR] Helper not found at "%FONTS_HELPER%".
    echo Make sure custom-fonts-setup.php is inside the helpers folder.
    pause
    exit /b 1
)

:: Ask which existing site (folder name under www) to target
set /p TARGET="Enter the existing site folder name (under www): "
set "SITE_PATH=%WWW_DIR%\%TARGET%"

if not exist "%SITE_PATH%\wp-load.php" (
    echo.
    echo [ERROR] No WordPress install found at "%SITE_PATH%".
    echo Check the folder name and try again.
    pause
    exit /b 1
)

cd /d "%SITE_PATH%"
echo.
echo Target site : %CD%
echo.

set "FONT_CHOICE="
set /p FONT_CHOICE="Enter Google Fonts to self-host (comma-separated) [default: Inter,Manrope,Sora]: "
if "%FONT_CHOICE%"=="" set "FONT_CHOICE=Inter,Manrope,Sora"

echo.
echo Downloading and registering Elementor Custom Fonts (%FONT_CHOICE%)...
echo -------------------------------------------------------------------------
call wp eval-file "%FONTS_HELPER%" "%FONT_CHOICE%" --user=admin
echo -------------------------------------------------------------------------

if !ERRORLEVEL! neq 0 (
    echo.
    echo [WARNING] The helper reported an error. Review the output above.
) else (
    echo.
    echo Done. Custom fonts applied and Google Fonts disabled for "%TARGET%".
)

echo.
pause
