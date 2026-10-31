@echo off
setlocal
cd /d "%~dp0"
where docker >nul 2>nul || (echo ERROR: Docker is not installed.&pause&exit /b 1)
docker compose down
if errorlevel 1 (echo ERROR: Could not stop Farast voice services.&pause&exit /b 1)
echo Farast voice services stopped. Persistent model volumes were kept.
pause
