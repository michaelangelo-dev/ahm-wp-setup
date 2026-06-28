@echo off
setlocal enabledelayedexpansion

:: -------------------------------------------------------------------------
:: fetch-figma-json.bat
:: Pulls a Figma file (or a single frame) as raw JSON via the REST API.
:: Needs a Figma Personal Access Token: Figma -> Settings -> Security.
:: Uses curl, which ships with Windows 10/11.
:: -------------------------------------------------------------------------

:: Optional: hardcode your token here to skip the prompt (keep this file private!)
set "FIGMA_TOKEN="

echo =========================================================================
echo   FIGMA -> RAW JSON FETCHER
echo =========================================================================
echo.

if not defined FIGMA_TOKEN set /p FIGMA_TOKEN="Figma token (figd_...): "
set /p FILE_KEY="File key (the part after /design/ in the URL): "
echo Tip: enter a frame node-id (e.g. 50:2 = homepage, 82:2258 = About) to get
echo      its FULL content. Leaving this BLANK returns a structure-only overview
echo      with NO text or images - only use blank to discover node-ids.
set /p NODE_ID="node-id to fetch (blank = overview only): "
set /p OUT_FILE="Output filename [figma.json]: "
if "%OUT_FILE%"=="" set "OUT_FILE=figma.json"

REM Whole file = shallow OVERVIEW (find node-ids). Per-frame = FULL subtree (build).
REM A deep whole-file dump is huge and still risks truncation, so it stays capped.
if "%NODE_ID%"=="" (
    set /p OV_DEPTH="Overview depth [2 = pages+sections]: "
    if "!OV_DEPTH!"=="" set "OV_DEPTH=2"
    set "URL=https://api.figma.com/v1/files/%FILE_KEY%?depth=!OV_DEPTH!"
    echo NOTE: whole-file fetch is an OVERVIEW. For full content, fetch by node-id.
) else (
    set "NODE_API=%NODE_ID:-=:%"
    set "URL=https://api.figma.com/v1/files/%FILE_KEY%/nodes?ids=!NODE_API!"
)

echo.
echo Fetching: !URL!
echo -------------------------------------------------------------------------
curl -s -H "X-Figma-Token: %FIGMA_TOKEN%" "!URL!" -o "%OUT_FILE%"
echo -------------------------------------------------------------------------

if not exist "%OUT_FILE%" (
    echo [ERROR] No file written - check token, file key, and connection.
    goto :done
)

:: Quick sanity check: Figma returns {"status":403...} or {"err":...} on failure
findstr /C:"\"status\":4" /C:"\"status\":5" /C:"\"err\":" "%OUT_FILE%" >nul
if !ERRORLEVEL! equ 0 (
    echo [WARNING] Figma returned an error response. Open "%OUT_FILE%" to read it.
    echo Common causes: bad/expired token, wrong file key, or no access to the file.
) else (
    echo Saved JSON to "%OUT_FILE%".
)

:done
echo.
pause