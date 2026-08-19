@echo off
setlocal enabledelayedexpansion

:: Force-inject Laragon's MySQL path for this terminal session
SET "PATH=%PATH%;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin"

:: Resolve paths from where THIS script lives (expected: ...\www\wp-setup\)
set "SCRIPT_DIR=%~dp0"
for %%I in ("%~dp0..") do set "WWW_DIR=%%~fI"
set "PAGES_HELPER=%SCRIPT_DIR%helpers\pages-setup.php"

echo =========================================================================
echo   APPLY PAGE STRUCTURE + ELEMENTOR HAND-OFF TO AN EXISTING WP SITE
echo =========================================================================
echo.

if not exist "%PAGES_HELPER%" (
    echo [ERROR] Helper not found at "%PAGES_HELPER%".
    echo Make sure pages-setup.php is inside the wp-setup\helpers folder.
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
echo Helper file : %PAGES_HELPER%
echo.
echo Running page builder...
echo -------------------------------------------------------------------------
call wp eval-file "%PAGES_HELPER%" --user=admin
echo -------------------------------------------------------------------------
if !ERRORLEVEL! neq 0 (
    echo.
    echo [WARNING] The helper reported an error. Review the output above.
) else (
    echo.
    echo Done. Page structure applied to "%TARGET%".
)
echo.

echo Setting permalink structure to "Post name"...
:: %%postname%% -> literal %postname% once the batch parser is done with it
call wp rewrite structure "/%%%%postname%%%%/"
if %ERRORLEVEL% neq 0 (
    echo Warning: Could not set permalink structure.
) else (
    echo Permalinks set to "Post name".
)



echo.
echo Note: If your browser says "Not secure", you need to manually install
echo       the Apache SSL certificate in the Laragon menu:
echo       Apache -> SSL -> Add laragon.crt to Trust Store
echo.
pause