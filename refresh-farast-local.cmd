@echo off
setlocal
cd /d "%~dp0"

if not exist artisan (
  echo ERROR: Put this file in the FARAST project root, beside artisan.
  pause
  exit /b 1
)
if not exist .git (
  echo ERROR: This folder is not a Git checkout.
  pause
  exit /b 1
)
where git >nul 2>nul
if errorlevel 1 (
  echo ERROR: Git is not installed or not on PATH.
  pause
  exit /b 1
)
where php >nul 2>nul
if errorlevel 1 (
  echo ERROR: PHP is not installed or not on PATH.
  pause
  exit /b 1
)

for /f %%B in ('git branch --show-current') do set CURRENT_BRANCH=%%B
if not "%CURRENT_BRANCH%"=="main" (
  echo ERROR: Current branch is "%CURRENT_BRANCH%". Switch to main first; no files were changed.
  pause
  exit /b 1
)

git status --porcelain > "%TEMP%\farast-git-status.txt"
for %%F in ("%TEMP%\farast-git-status.txt") do if %%~zF GTR 0 (
  echo ERROR: Local changes exist. Nothing was overwritten.
  type "%TEMP%\farast-git-status.txt"
  del "%TEMP%\farast-git-status.txt" >nul 2>nul
  pause
  exit /b 1
)
del "%TEMP%\farast-git-status.txt" >nul 2>nul

echo Updating FARAST main...
git fetch origin main
if errorlevel 1 (
  echo ERROR: Could not fetch origin/main.
  pause
  exit /b 1
)
git pull --ff-only origin main
if errorlevel 1 (
  echo ERROR: Fast-forward update failed. Local files were not force-overwritten.
  pause
  exit /b 1
)

echo Clearing Laravel caches...
php artisan optimize:clear
if errorlevel 1 (
  echo ERROR: Laravel cache clear failed.
  pause
  exit /b 1
)

echo Restarting only a Laravel artisan server listening on port 8001...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$connections = Get-NetTCPConnection -LocalPort 8001 -State Listen -ErrorAction SilentlyContinue; foreach ($connection in $connections) { $process = Get-CimInstance Win32_Process -Filter ('ProcessId=' + $connection.OwningProcess); if ($process -and $process.CommandLine -match 'artisan serve' -and $process.CommandLine -match '8001') { Stop-Process -Id $process.ProcessId -Force } }"
timeout /t 2 /nobreak >nul

echo Starting FARAST at http://127.0.0.1:8001 ...
start "FARAST Laravel" cmd /k "cd /d ""%CD%"" && php artisan serve --host=127.0.0.1 --port=8001"

echo.
echo Update complete. Open or refresh http://127.0.0.1:8001
pause
