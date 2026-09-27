@echo off
setlocal EnableExtensions EnableDelayedExpansion
title FARAST TYPE - SAFE DEV INSTALLER
cls

echo ============================================================
echo             FARAST TYPE - DEV ENVIRONMENT
echo ============================================================
echo MAIN PROJECT: C:\xampp\htdocs\digitalshop
echo DEV PROJECT:  %USERPROFILE%\Desktop\type
echo BRANCH:       main
echo.
echo MAIN PROJECT WILL NOT BE MODIFIED.
echo MAIN DATABASE WILL NOT BE MODIFIED OR COPIED.
echo DEV DATABASE: type_dev
echo ============================================================
echo.

set "MAIN=C:\xampp\htdocs\digitalshop"
set "DEV=%USERPROFILE%\Desktop\type"
set "TOKEN_FILE=%USERPROFILE%\Desktop\github_token.txt"
set "GEMINI_KEY_FILE=%USERPROFILE%\Desktop\gemini_api_key.txt"
set "OWNER=a4adelahmadian86-wq"
set "REPO=type"
set "BRANCH=main"
set "MYSQL=C:\xampp\mysql\bin\mysql.exe"
set "DEV_DB=type_dev"
set "PORT=8001"
set "GITHUB_API=https://api.github.com/repos/%OWNER%/%REPO%/branches/%BRANCH%"
set "ZIP_URL=https://github.com/%OWNER%/%REPO%/archive/refs/heads/%BRANCH%.zip"

REM ============================================================
REM 1 - CHECK MAIN PATH ONLY
REM ============================================================
echo [1/12] Checking MAIN project path only...
if not exist "%MAIN%\artisan" (
 echo ERROR: MAIN project not found: %MAIN%
 pause
 exit /b 1
)
echo OK - MAIN project found. It will not be modified.
echo.

REM ============================================================
REM 2 - GITHUB TOKEN
REM ============================================================
echo [2/12] Checking GitHub token...
if not exist "%TOKEN_FILE%" (
 echo ERROR: %TOKEN_FILE% not found.
 pause
 exit /b 1
)
set "GITHUB_TOKEN="
for /f "usebackq delims=" %%T in ("%TOKEN_FILE%") do if not defined GITHUB_TOKEN set "GITHUB_TOKEN=%%T"
if not defined GITHUB_TOKEN (
 echo ERROR: GitHub token file is empty.
 pause
 exit /b 1
)
echo OK - GitHub token found. Value will NOT be displayed.
echo.

REM ============================================================
REM 3 - POWERSHELL
REM ============================================================
echo [3/12] Checking PowerShell...
where powershell.exe >nul 2>&1
if errorlevel 1 (
 echo ERROR: PowerShell was not found.
 pause
 exit /b 1
)
echo OK - PowerShell found.
echo.

REM ============================================================
REM 4 - GITHUB BRANCH
REM ============================================================
echo [4/12] Checking GitHub branch...
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
 "$ErrorActionPreference='Stop';$token=(Get-Content -LiteralPath '%TOKEN_FILE%' -Raw).Trim();$headers=@{'Authorization'=('Bearer '+$token);'Accept'='application/vnd.github+json';'User-Agent'='FARAST-TYPE-DEV'};$r=Invoke-RestMethod -Uri '%GITHUB_API%' -Headers $headers -Method Get;Write-Host ('BRANCH_OK|'+$r.name+'|'+$r.commit.sha);"
if errorlevel 1 (
 echo ERROR: GitHub branch is not accessible.
 pause
 exit /b 1
)
echo.

REM ============================================================
REM 5 - DEV DIRECTORY
REM ============================================================
echo [5/12] Preparing DEV project...
if not exist "%DEV%" mkdir "%DEV%"
if not exist "%DEV%" (
 echo ERROR: Could not create %DEV%
 pause
 exit /b 1
)
echo DEV directory: %DEV%
echo.

REM ============================================================
REM 6 - DOWNLOAD GITHUB
REM ============================================================
echo [6/12] Downloading latest main branch...
set "ZIP=%TEMP%\farast-type-!RANDOM!!RANDOM!.zip"
set "EXTRACT=%TEMP%\farast-type-extract-!RANDOM!!RANDOM!"
if exist "!ZIP!" del /f /q "!ZIP!" >nul 2>&1
if exist "!EXTRACT!" rmdir /s /q "!EXTRACT!" >nul 2>&1
mkdir "!EXTRACT!" >nul 2>&1
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
 "$ErrorActionPreference='Stop';$token=(Get-Content -LiteralPath '%TOKEN_FILE%' -Raw).Trim();$headers=@{'Authorization'=('Bearer '+$token);'Accept'='application/vnd.github+json';'User-Agent'='FARAST-TYPE-DEV'};Invoke-WebRequest -Uri '%ZIP_URL%' -Headers $headers -OutFile '%ZIP%';"
if errorlevel 1 (
 echo ERROR: GitHub download failed.
 pause
 exit /b 1
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop';Expand-Archive -LiteralPath '%ZIP%' -DestinationPath '%EXTRACT%' -Force;"
if errorlevel 1 (
 echo ERROR: ZIP extraction failed.
 pause
 exit /b 1
)
set "SOURCE="
for /d %%D in ("!EXTRACT!\*") do if exist "%%D\artisan" set "SOURCE=%%D"
if not defined SOURCE (
 echo ERROR: Laravel source was not found in the ZIP.
 pause
 exit /b 1
)
echo Source: !SOURCE!
echo.

REM ============================================================
REM PRESERVE DEV ENV/STORAGE ONLY
REM ============================================================
set "OLD_ENV=!TEMP!\farast-old-env-!RANDOM!.env"
set "OLD_STORAGE=!TEMP!\farast-old-storage-!RANDOM!"
if exist "%DEV%\.env" copy /y "%DEV%\.env" "!OLD_ENV!" >nul
if exist "%DEV%\storage" (
 mkdir "!OLD_STORAGE!" >nul 2>&1
 robocopy "%DEV%\storage" "!OLD_STORAGE!" /E /COPY:DAT /R:1 /W:1 /NFL /NDL /NJH /NJS >nul
)

REM ============================================================
REM 7 - UPDATE DEV SOURCE ONLY
REM ============================================================
echo [7/12] Updating DEV project files...
robocopy "!SOURCE!" "%DEV%" /E /COPY:DAT /DCOPY:DAT /R:2 /W:1 /NFL /NDL /NP /XD ".git" "node_modules" "vendor" "storage" >nul
if errorlevel 8 (
 echo ERROR: DEV source update failed.
 pause
 exit /b 1
)
if exist "!OLD_STORAGE!" robocopy "!OLD_STORAGE!" "%DEV%\storage" /E /COPY:DAT /DCOPY:DAT /R:1 /W:1 /NFL /NDL /NJH /NJS >nul
if exist "!OLD_ENV!" copy /y "!OLD_ENV!" "%DEV%\.env" >nul
if not exist "%DEV%\.env" if exist "%DEV%\.env.example" copy /y "%DEV%\.env.example" "%DEV%\.env" >nul
if not exist "%DEV%\.env" (
 echo ERROR: DEV .env could not be created.
 pause
 exit /b 1
)
echo OK - DEV source files updated.
echo.

REM ============================================================
REM 8 - SAFE DEV ENVIRONMENT
REM ============================================================
echo [8/12] Creating safe DEV environment...
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
 "$p='%DEV%\.env';$s=Get-Content -LiteralPath $p -Raw;function Set-Env([string]$k,[string]$v){$pattern='(?m)^'+[regex]::Escape($k)+'=.*$';if($s -match $pattern){$script:s=[regex]::Replace($script:s,$pattern,($k+'='+$v))}else{$script:s=$script:s.TrimEnd()+[Environment]::NewLine+($k+'='+$v)+[Environment]::NewLine}};Set-Env 'APP_ENV' 'local';Set-Env 'APP_DEBUG' 'true';Set-Env 'APP_URL' 'http://127.0.0.1:%PORT%';Set-Env 'DB_CONNECTION' 'mysql';Set-Env 'DB_HOST' '127.0.0.1';Set-Env 'DB_PORT' '3306';Set-Env 'DB_DATABASE' '%DEV_DB%';Set-Env 'DB_USERNAME' 'root';Set-Env 'DB_PASSWORD' '';Set-Env 'CACHE_STORE' 'file';Set-Env 'SESSION_DRIVER' 'file';Set-Env 'QUEUE_CONNECTION' 'sync';Set-Env 'GEMINI_MODEL' 'gemini-3.8-flash';[IO.File]::WriteAllText($p,$s,(New-Object System.Text.UTF8Encoding($false)));"
if errorlevel 1 (
 echo ERROR: Could not configure DEV .env.
 pause
 exit /b 1
)

REM Optional Gemini key file: Desktop\gemini_api_key.txt
if exist "%GEMINI_KEY_FILE%" (
 set "GEMINI_KEY="
 for /f "usebackq delims=" %%K in ("%GEMINI_KEY_FILE%") do if not defined GEMINI_KEY set "GEMINI_KEY=%%K"
 if defined GEMINI_KEY powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
  "$p='%DEV%\.env';$s=Get-Content -LiteralPath $p -Raw;$v=$env:GEMINI_KEY;$pattern='(?m)^GEMINI_API_KEY=.*$';if($s -match $pattern){$s=[regex]::Replace($s,$pattern,('GEMINI_API_KEY='+$v))}else{$s=$s.TrimEnd()+[Environment]::NewLine+'GEMINI_API_KEY='+$v+[Environment]::NewLine};[IO.File]::WriteAllText($p,$s,(New-Object System.Text.UTF8Encoding($false)))"
)
echo OK - DEV .env configured.
echo DEV DATABASE: %DEV_DB%
echo.

REM ============================================================
REM 9 - PHP + COMPOSER
REM ============================================================
echo [9/12] Checking PHP and Composer...
if not exist "C:\xampp\php\php.exe" (
 echo ERROR: C:\xampp\php\php.exe was not found.
 pause
 exit /b 1
)
set "PATH=C:\xampp\php;%PATH%"
php -v
where composer.bat >nul 2>&1
if errorlevel 1 where composer.exe >nul 2>&1
if errorlevel 1 (
 echo ERROR: Composer was not found.
 pause
 exit /b 1
)
cd /d "%DEV%"
set "COMPOSER_IPRESOLVE=4"
set "COMPOSER_PROCESS_TIMEOUT=900"
set "COMPOSER_NO_INTERACTION=1"
set "COMPOSER_NO_AUDIT=1"
set "COMPOSER_OK=0"
for /L %%R in (1,1,3) do (
 if "!COMPOSER_OK!"=="0" (
  echo Composer attempt %%R of 3...
  call composer install --no-interaction --prefer-dist --no-progress
  if not errorlevel 1 set "COMPOSER_OK=1"
  if "!COMPOSER_OK!"=="0" timeout /t 3 /nobreak >nul
 )
)
if "!COMPOSER_OK!"=="0" if not exist "%DEV%\vendor\autoload.php" (
 echo ERROR: Composer failed and vendor\autoload.php does not exist.
 pause
 exit /b 1
)
echo OK - Composer dependencies are ready.
echo.

REM ============================================================
REM MYSQL - ONLY TYPE DEV DATABASE
REM ============================================================
echo Checking XAMPP MySQL/MariaDB...
if not exist "%MYSQL%" (
 echo ERROR: MySQL client not found: %MYSQL%
 pause
 exit /b 1
)
"%MYSQL%" -u root -e "CREATE DATABASE IF NOT EXISTS %DEV_DB% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 (
 echo ERROR: Could not create/access DEV database.
 echo Make sure MySQL/MariaDB is running in XAMPP.
 pause
 exit /b 1
)
echo OK - DEV database is ready: %DEV_DB%
echo MAIN database was NOT read, dumped or copied.
echo.

REM ============================================================
REM 10 - DATABASE
REM ============================================================
echo [10/12] Preparing DEV database...
echo This project uses only its own Laravel migrations in %DEV_DB%.
echo Main DigitalShop database is intentionally NOT imported.
echo.

REM ============================================================
REM 11 - LARAVEL VALIDATION + MIGRATIONS
REM ============================================================
echo [11/12] Validating Laravel before startup...
cd /d "%DEV%"

php artisan key:generate --force
if errorlevel 1 (
 echo ERROR: Could not generate DEV APP_KEY.
 pause
 exit /b 1
)

php artisan optimize:clear
if errorlevel 1 (
 echo ERROR: optimize:clear failed.
 pause
 exit /b 1
)

echo Checking PHP syntax in app, config, routes and database...
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
 "$ErrorActionPreference='Stop';$roots=@('%DEV%\app','%DEV%\config','%DEV%\routes','%DEV%\database');foreach($root in $roots){if(Test-Path -LiteralPath $root){Get-ChildItem -LiteralPath $root -Recurse -File -Filter '*.php' | ForEach-Object { & 'C:\xampp\php\php.exe' -l $_.FullName | Out-Host; if($LASTEXITCODE -ne 0){throw ('PHP syntax check failed: '+$_.FullName)}}}}"
if errorlevel 1 (
 echo ERROR: PHP syntax validation failed. Server will NOT start.
 pause
 exit /b 1
)

echo Running pending migrations on DEV database only...
php artisan migrate --force
if errorlevel 1 (
 echo ERROR: DEV migrations failed. Server will NOT start.
 echo MAIN PROJECT AND MAIN DATABASE WERE NOT TOUCHED.
 pause
 exit /b 1
)

echo Checking routes...
php artisan route:list --no-ansi >nul
if errorlevel 1 (
 echo ERROR: Laravel route check failed. Server will NOT start.
 pause
 exit /b 1
)

echo Compiling Blade views...
php artisan view:cache
if errorlevel 1 (
 echo ERROR: Blade compilation failed. Server will NOT start.
 pause
 exit /b 1
)
php artisan view:clear >nul 2>&1

if exist "%DEV%\public" php artisan storage:link >nul 2>&1

echo Checking Node.js / NPM...
if exist "%DEV%\package.json" (
 where npm.cmd >nul 2>&1
 if errorlevel 1 (
  echo WARNING: package.json exists but NPM was not found. Front-end build skipped.
 ) else (
  if exist "%DEV%\package-lock.json" (
   echo Running npm ci...
   call npm ci
  ) else (
   echo package-lock.json not found. Running npm install...
   call npm install
  )
  if errorlevel 1 (
   echo ERROR: NPM dependency installation failed. Server will NOT start.
   pause
   exit /b 1
  )
 )
) else (
 echo OK - package.json is not used by this project. NPM skipped.
)

echo OK - PHP syntax, migrations, routes and Blade views passed.
echo.

REM CLEAN TEMP
if exist "!ZIP!" del /f /q "!ZIP!" >nul 2>&1
if exist "!EXTRACT!" rmdir /s /q "!EXTRACT!" >nul 2>&1
if exist "!OLD_ENV!" del /f /q "!OLD_ENV!" >nul 2>&1
if exist "!OLD_STORAGE!" rmdir /s /q "!OLD_STORAGE!" >nul 2>&1

REM ============================================================
REM 12 - START ONLY AFTER ALL CHECKS PASS
REM ============================================================
echo.
echo [12/12] Starting DEV server...
echo ============================================================
echo PROJECT:  %DEV%
echo BRANCH:   %BRANCH%
echo DATABASE: %DEV_DB%
echo URL:      http://127.0.0.1:%PORT%
echo ============================================================
echo MAIN PROJECT WAS NOT MODIFIED.
echo MAIN DATABASE WAS NOT MODIFIED.
echo.
cd /d "%DEV%"
php artisan serve --host=127.0.0.1 --port=%PORT%
pause
endlocal
