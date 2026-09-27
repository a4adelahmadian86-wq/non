@echo off
setlocal
cd /d "%~dp0"
where node >nul 2>nul || (echo ERROR: Node.js is not installed.&pause&exit /b 1)
where npm >nul 2>nul || (echo ERROR: NPM is not installed.&pause&exit /b 1)
if not exist node_modules (
  echo Installing Farast voice-stream dependencies...
  call npm install
  if errorlevel 1 (echo ERROR: npm install failed.&pause&exit /b 1)
)
echo.
echo Starting Farast live voice gateway on ws://127.0.0.1:6002 ...
echo.
call npm start
