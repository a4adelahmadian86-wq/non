@echo off
setlocal
cd /d "%~dp0"

echo.
echo FARAST - Runflare domestic fallback recreation
echo This script does not print or upload secrets to Git.
echo.

where runflare >nul 2>&1
if errorlevel 1 (
    echo Runflare CLI is not installed.
    echo Open the official Windows installer now:
    start "" "https://get.runflare.com/install.bat"
    echo Install it, close this window, reopen CMD, and run this script again.
    pause
    exit /b 1
)

if not exist "runflare.env" (
    copy /Y "runflare.env.example" "runflare.env" >nul
    echo A private local runflare.env file was created from the template.
    echo Enter the SAME token used as FARAST_PRODUCT_SUPERVISOR_FALLBACK_TOKEN in Laravel.
    start "" notepad "runflare.env"
    echo Save the file, then return here.
    pause
)

findstr /C:"__SET_THE_SAME_LONG_RANDOM_TOKEN_AS_LARAVEL_FALLBACK__" "runflare.env" >nul
if not errorlevel 1 (
    echo The token placeholder is still present. Edit runflare.env first.
    start "" notepad "runflare.env"
    pause
    exit /b 1
)

findstr /R /C:"^SUPERVISOR_API_TOKEN=." "runflare.env" >nul
if errorlevel 1 (
    echo SUPERVISOR_API_TOKEN is missing or empty. Edit runflare.env first.
    start "" notepad "runflare.env"
    pause
    exit /b 1
)

findstr /I /C:"SUPERVISOR_OFFLINE_MODE=true" "runflare.env" >nul
if errorlevel 1 (
    echo Runflare must remain in offline-only mode. Set SUPERVISOR_OFFLINE_MODE=true.
    start "" notepad "runflare.env"
    pause
    exit /b 1
)

echo Opening Runflare portal. Create a new Docker app in your existing project
echo (or create a project if Runflare deleted it), then open its Environment Variables.
start "" "https://portal.runflare.com"

echo.
echo Copying the private environment template to clipboard without displaying it.
clip < "runflare.env"
if errorlevel 1 (
    echo Clipboard copy failed. Open runflare.env and copy its contents manually.
    pause
    exit /b 1
)

echo In Runflare, use Bulk Edit, paste the environment variables, and save.
echo Then return here and press a key. The clipboard will be cleared before deployment.
pause
echo. | clip

echo.
echo Runflare deploy handles account sign-in and asks you to select an existing project and Docker service.
echo Create the replacement project and Docker service in the portal first if the old free service was deleted.
echo.
runflare deploy
if errorlevel 1 (
    echo.
    echo Runflare deployment did not complete. Check the CLI message above.
    pause
    exit /b 1
)

echo.
echo Deployment command completed.
echo Verify /health and authenticated /v1/ready.
echo If Runflare issued a new public URL, update FARAST_PRODUCT_SUPERVISOR_FALLBACK_URL on the Laravel host.
echo Keep runflare.env private; it contains the shared supervisor token.
pause
