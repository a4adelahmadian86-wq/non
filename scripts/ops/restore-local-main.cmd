@echo off
setlocal EnableExtensions
set "SOURCE_ROOT=%~dp0\..\.."
for %%I in ("%SOURCE_ROOT%") do set "SOURCE_ROOT=%%~fI"
set "TARGET_ROOT=%USERPROFILE%\Desktop\farast-main-refresh"

echo ==========================================================
echo FARAST - Safe local refresh from GitHub main
echo Source: %SOURCE_ROOT%
echo Target: %TARGET_ROOT%
echo ==========================================================

if exist "%TARGET_ROOT%" (
  echo ERROR: Target already exists. Nothing will be overwritten:
  echo %TARGET_ROOT%
  exit /b 2
)

where git >nul 2>nul
if errorlevel 1 (
  echo ERROR: Git for Windows is required and must be available on PATH.
  exit /b 2
)
where composer >nul 2>nul
if errorlevel 1 (
  echo ERROR: Composer is required and must be available on PATH.
  exit /b 2
)
where php >nul 2>nul
if errorlevel 1 (
  echo ERROR: PHP is required and must be available on PATH.
  exit /b 2
)
where bash >nul 2>nul
if errorlevel 1 (
  echo ERROR: Git Bash is required. Install Git for Windows with bash.exe on PATH.
  exit /b 2
)

echo.
echo [1/7] Cloning clean main into a new folder...
git clone --depth 1 https://github.com/a4adelahmadian86-wq/non.git "%TARGET_ROOT%"
if errorlevel 1 exit /b 1

echo.
echo [2/7] Creating an isolated local environment...
copy /y "%TARGET_ROOT%\\.env.example" "%TARGET_ROOT%\\.env" >nul
if errorlevel 1 exit /b 1
powershell -NoProfile -ExecutionPolicy Bypass -Command "$p='.env'; (Get-Content $p) -replace '^APP_ENV=.*$', 'APP_ENV=local' -replace '^APP_DEBUG=.*$', 'APP_DEBUG=true' -replace '^APP_URL=.*$', 'APP_URL=http://127.0.0.1:8002' -replace '^DB_CONNECTION=.*$', 'DB_CONNECTION=sqlite' -replace '^DB_DATABASE=.*$', 'DB_DATABASE=database/database.sqlite' -replace '^CACHE_STORE=.*$', 'CACHE_STORE=file' -replace '^SESSION_DRIVER=.*$', 'SESSION_DRIVER=file' -replace '^QUEUE_CONNECTION=.*$', 'QUEUE_CONNECTION=sync' -replace '^FILESYSTEM_DISK=.*$', 'FILESYSTEM_DISK=local' -replace '^MAIL_MAILER=.*$', 'MAIL_MAILER=log' | Set-Content -Encoding ascii $p"
if errorlevel 1 exit /b 1

pushd "%TARGET_ROOT%"
if errorlevel 1 exit /b 1

echo.
echo [3/7] Installing PHP dependencies...
composer install --no-interaction --prefer-dist
if errorlevel 1 (popd & exit /b 1)

if not exist .env (
  echo ERROR: .env was not created.
  popd
  exit /b 1
)
findstr /b /c:"APP_KEY=" .env >nul
if not errorlevel 1 (
  findstr /r /c:"APP_KEY=." .env >nul
  if errorlevel 1 php artisan key:generate --force
  if errorlevel 1 (popd & exit /b 1)
)

echo.
echo [4/7] Preparing verified PDF.js runtime assets...
bash scripts/prepare-reader-assets.sh
if errorlevel 1 (popd & exit /b 1)

echo.
echo [5/7] Applying pending database migrations...
php artisan migrate --force
if errorlevel 1 (popd & exit /b 1)

echo.
echo [6/7] Clearing Laravel caches...
php artisan optimize:clear
if errorlevel 1 (popd & exit /b 1)

echo.
echo [7/7] Starting refreshed application on port 8002...
start "FARAST refreshed main" cmd /k "cd /d ""%TARGET_ROOT%"" && php artisan serve --host=127.0.0.1 --port=8002"
timeout /t 5 /nobreak >nul
set "FARAST_BASE_URL=http://127.0.0.1:8002"
bash scripts/ops/health-check.sh
set "HEALTH_EXIT=%errorlevel%"
popd

echo.
if not "%HEALTH_EXIT%"=="0" (
  echo Refresh completed, but health checks failed. Review the output above.
  echo Fresh source is at: %TARGET_ROOT%
  exit /b %HEALTH_EXIT%
)

echo SUCCESS: clean main checkout and reader runtime setup completed.
echo Fresh project: %TARGET_ROOT%
echo Existing source folder was not modified.
exit /b 0
