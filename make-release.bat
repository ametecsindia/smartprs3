@echo off
rem SmartPRS3 — build a release package into C:\SmartPRS3-Releases (double-click, or run from the Laragon terminal).
cd /d "%~dp0"
set PHPEXE=
for /d %%D in (C:\laragon\bin\php\*) do if exist "%%D\php.exe" set PHPEXE=%%D\php.exe
if "%PHPEXE%"=="" set PHPEXE=php
"%PHPEXE%" tools\release\build-release.php %*
echo.
pause
