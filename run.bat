@echo off
title Google Review Manager - Local Server
echo ===================================================
echo Starting Google Review Manager (Local Windows Run)
echo ===================================================

set "PATH=%~dp0tools\php;%PATH%"

if not exist "%~dp0var" mkdir "%~dp0var"

echo Checking database schema...
"%~dp0tools\php\php.exe" bin/console doctrine:migrations:migrate --no-interaction

echo.
echo ===================================================
echo [SUCCESS] Server running at: http://127.0.0.1:8000
echo Open http://127.0.0.1:8000 in your browser!
echo Press Ctrl+C to stop the server.
echo ===================================================
"%~dp0tools\php\php.exe" -S 127.0.0.1:8000 -t public/
