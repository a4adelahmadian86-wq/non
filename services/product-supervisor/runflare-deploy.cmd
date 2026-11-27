@echo off
setlocal
cd /d "%~dp0"

where runflare >nul 2>nul
if errorlevel 1 (
    echo ERROR: Runflare CLI is not installed or is not in PATH.
    echo Install it once using the official Windows installer:
    echo https://get.runflare.com/install.bat
    echo Then close and reopen CMD and run this file again.
    pause
    exit /b 1
)

echo.
echo FARAST Product Supervisor - Runflare deployment
echo This deploys to an existing Runflare Docker service.
echo It does NOT create a new Runflare project or service.
echo.
runflare deploy
if errorlevel 1 (
    echo.
    echo Deployment failed. Check the Runflare CLI output above.
    pause
    exit /b 1
)

echo.
echo Deployment command completed. Verify /health and /v1/ready before changing the FARAST fallback URL.
pause
