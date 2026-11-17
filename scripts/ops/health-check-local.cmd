@echo off
setlocal
cd /d "%~dp0\\..\\.."
set "FARAST_BASE_URL=http://127.0.0.1:8001"
where bash >nul 2>nul
if errorlevel 1 (
  echo ERROR: Git Bash is required. Install Git for Windows and ensure bash.exe is on PATH.
  exit /b 2
)
bash "scripts/ops/health-check.sh"
exit /b %errorlevel%
