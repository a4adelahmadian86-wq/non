@echo off
setlocal
cd /d "%~dp0"

where docker >nul 2>nul || (echo ERROR: Docker Desktop / Docker is not installed.&pause&exit /b 1)
docker compose version >nul 2>nul || (echo ERROR: Docker Compose v2 is not available.&pause&exit /b 1)

echo Preparing local Farast voice services...
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-local.ps1"
if errorlevel 1 (
  echo.
  echo Setup could not finish. Check the Laravel .env values and try again.
  pause
  exit /b 1
)

echo.
echo Building and starting the private Persian voice services...
docker compose up -d --build
if errorlevel 1 (
  echo ERROR: Voice services did not start. Run "docker compose logs" in this folder for diagnostics.
  pause
  exit /b 1
)

echo.
echo Farast voice services are starting.
echo Live gateway: ws://127.0.0.1:6002
echo Private Piper TTS: http://127.0.0.1:7007
echo Models stay in Docker volumes and are never downloaded to the browser.
echo Use stop.cmd to stop the services without deleting their model volumes.
pause
