@echo off
setlocal
cd /d "%~dp0"
echo ================================================
echo FARAST - Product Supervisor Deployment
echo ================================================
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy-runflare.ps1"
set "RC=%ERRORLEVEL%"
if not "%RC%"=="0" (
  echo.
  echo DEPLOYMENT FAILED. Review the message above.
) else (
  echo.
  echo Deployment command completed. Verify /health and /v1/ready.
)
echo.
pause
exit /b %RC%
