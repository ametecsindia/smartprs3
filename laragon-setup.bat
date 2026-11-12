@echo off
REM SmartPRS 3 -- local first run on Laragon.
REM Run from C:\laragon\www\SmartPRS3 with Laragon started (Apache + MySQL).
REM Re-running is safe: migrate applies only what is pending unless you pass --fresh.
REM
REM   laragon-setup.bat                    schema, accounts, sign-in
REM   laragon-setup.bat --with-demo        and seed demo employees to look at
REM   laragon-setup.bat --from-prs2        and copy SmartPRS 2's real data in instead
REM   laragon-setup.bat --root-password=x  if your MySQL root account has a password
REM   laragon-setup.bat --baseline         database predates the migrations ledger
REM   laragon-setup.bat --fresh            drop and rebuild (destroys smartprs3)
REM
REM This file is CRLF and ASCII on purpose. The first version was written with Unix
REM line endings and em dashes; cmd.exe misparsed it and reported 'M' is not
REM recognized as an internal or external command -- which names nothing that is
REM actually wrong and sends you looking in entirely the wrong place.

setlocal
cd /d "%~dp0"

if not exist ".env" (
  echo No .env found. Copy .env.example to .env first.
  exit /b 1
)

REM Pass a root password straight through to the bootstrap step if one was given.
set ROOTPW=
for %%A in (%*) do (
  echo %%A | findstr /b /c:"--root-password=" >nul
  if not errorlevel 1 set ROOTPW=%%A
)

echo.
echo == 1/5  database accounts ==
REM DB_USERNAME= blank is not an oversight: Law 2 gives the application an account
REM without ALTER/CREATE/DROP and migrations a separate privileged one, so there are
REM two accounts to make and neither exists on a fresh machine. This step used to
REM stop and tell you to invent them yourself, which is how a local password ends up
REM being "root" on a laptop that later gets demoed from.
REM DECIDED BY TESTING, NOT BY READING .env. This step used to skip whenever
REM DB_USERNAME had a value -- and a name in a configuration file is not a working
REM account. On a machine whose accounts were created earlier with an
REM authentication plugin the server no longer loads, .env looked complete, this
REM step said "Already configured", and migrate failed with SQLSTATE 1524 three
REM lines later. --if-broken asks the server whether each account can sign in.
php tools\db\bootstrap-local.php --if-broken %ROOTPW%
if errorlevel 1 (
  echo.
  echo Could not create the database accounts. Nothing below would work.
  exit /b 1
)

echo.
echo == 2/5  schema ==
php tools\db\migrate.php %*
if errorlevel 1 (
  echo.
  echo Migrate refused. If this database was built before the migrations ledger
  echo existed, re-run as:  laragon-setup.bat --baseline
  echo To drop and rebuild it instead:  laragon-setup.bat --fresh
  exit /b 1
)

echo.
echo == 3/5  grants ==
REM Table by table, so the application account holds no rights on a table that does
REM not exist yet -- which is why this runs after the schema and not before it.
php tools\db\grant-application-account.php --apply
if errorlevel 1 (
  echo.
  echo Could not apply the grants.
  exit /b 1
)

echo.
echo == 4/5  can the application actually connect? ==
REM A CREATED ACCOUNT IS NOT A WORKING ACCOUNT. Setup used to make the account and
REM move on; the first anybody heard that it had not worked was a banner on every
REM screen saying the product was running on JSON files. Now it fails here, next to
REM the step that caused it.
php tools\db\verify-connection.php
if errorlevel 1 (
  echo.
  echo Setup stopped: the application cannot use the database it just set up.
  echo Fix the cause printed above and run this again. Nothing below would work.
  exit /b 1
)

echo.
echo == 5/5  sign-in and data ==
php tools\seed-local-admin.php

echo %* | findstr /c:"--from-prs2" >nul
if not errorlevel 1 (
  REM READS SmartPRS 2, NEVER WRITES IT. The importer opens the old database
  REM read-only and refuses outright if .env points SmartPRS 3 at it.
  php tools\db\import-from-smartprs2.php --source=smartprs
  REM Imported rows carry no uuid -- SmartPRS 2 has no such column -- and /api/v3
  REM addresses an employment by its uuid and nothing else, so without this the
  REM API can list a customer's real history and address none of it.
  php tools\db\backfill-uuids.php --apply
  goto done
)
echo %* | findstr /c:"--with-demo" >nul
if not errorlevel 1 (
  php tools\seed-demo-data.php
  goto done
)
echo.
echo   No data seeded. Add some with --with-demo, or copy SmartPRS 2's with --from-prs2.

:done
echo.
echo Open:  http://localhost/SmartPRS3/public/app.php
echo Sign in with the credentials printed above.
endlocal
