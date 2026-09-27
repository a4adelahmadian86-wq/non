@echo off
setlocal
cd /d "%~dp0.."
where php >nul 2>nul || (echo ERROR: PHP was not found in PATH.&pause&exit /b 1)
echo.
echo Farast - Add Gladia Free Streaming Account
echo.
php artisan migrate --force
if errorlevel 1 (echo ERROR: Database migration failed.&pause&exit /b 1)
echo.
php artisan voice:provider:add gladia --name="Gladia Free 10h" --model="solaria-1" --monthly-free --quota=36000 --priority=10
if errorlevel 1 (echo ERROR: Gladia provider setup failed.&pause&exit /b 1)
echo.
echo Gladia account has been stored encrypted in the Farast database.
echo You do not need to configure the gateway manually.
echo.
pause
