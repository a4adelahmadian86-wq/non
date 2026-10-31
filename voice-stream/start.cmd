@echo off
setlocal
cd /d "%~dp0"

where docker >nul 2>nul || (echo ERROR: Docker Desktop / Docker is not installed.&pause&exit /b 1)
docker compose version >nul 2>nul || (echo ERROR: Docker Compose v2 is not available.&pause&exit /b 1)

echo Preparing local Farast voice and AI services...
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-local.ps1"
if errorlevel 1 (
  echo.
  echo Setup could not finish. Check the Laravel .env values and try again.
  pause
  exit /b 1
)

echo.
echo Building and starting the private Persian voice and Qwen3 services...
docker compose --profile local-ai up -d --build
if errorlevel 1 (
  echo ERROR: Voice services did not start. Run "docker compose --profile local-ai logs" in this folder for diagnostics.
  pause
  exit /b 1
)

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0wait-ready.ps1"
if errorlevel 1 (
  echo ERROR: Engines did not become ready. Run "docker compose --profile local-ai logs" in this folder for diagnostics.
  pause
  exit /b 1
)

echo.
echo Farast voice and local AI services are healthy.
echo Live gateway: ws://127.0.0.1:6002
echo Private Piper TTS: http://127.0.0.1:7007
echo Private Qwen3 AI: http://127.0.0.1:7008
echo Models stay in Docker volumes and are never downloaded to the browser.
echo Use stop.cmd to stop the services without deleting their model volumes.
pause
