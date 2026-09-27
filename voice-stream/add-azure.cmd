@echo off
setlocal
cd /d "%~dp0.."
where php >nul 2>nul || (echo ERROR: PHP was not found in PATH.&pause&exit /b 1)
echo.
echo Farast - Add Azure Speech Free F0 Account
echo.
php artisan migrate --force
if errorlevel 1 (echo ERROR: Database migration failed.&pause&exit /b 1)
echo.
php artisan voice:provider:add azure --name="Azure Speech Free F0" --model="speech" --monthly-free --quota=18000 --priority=20
if errorlevel 1 (echo ERROR: Azure provider setup failed.&pause&exit /b 1)
echo.
echo Azure account has been stored encrypted in the Farast database.
echo.
pause
