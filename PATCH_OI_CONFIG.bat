@echo off
setlocal enabledelayedexpansion
title OI-PPMS Patch — Patch OI Config (Jumphost)

echo.
echo ============================================================
echo  OI-PPMS Patch — Patch OI Config Files
echo  Run INSIDE THE RDP SESSION on the jumphost
echo  Run ONCE before deploying PPMS files (2_SVN_COMMIT.bat)
echo.
echo  This script does NOT replace any OI files.
echo  It only adds what PPMS needs to the existing OI config.
echo  Safe to re-run — checks before adding anything.
echo ============================================================
echo.

:: ── CONFIG — set your SVN working copy path on E:\ ──────────────────────────
set SVN_ROOT=E:\sectorsinsights
:: ─────────────────────────────────────────────────────────────────────────────

if not exist "%SVN_ROOT%" (
    echo ERROR: %SVN_ROOT% not found. Update SVN_ROOT at the top.
    pause & exit /b 1
)

cd /d "%SVN_ROOT%"

:: ── 0. Backup OI config files before patching ────────────────────────────────
echo [0/4] Backing up OI config files...
set BACKUP=%SVN_ROOT%\ppms_patch_backup
if not exist "%BACKUP%" mkdir "%BACKUP%"
copy /Y "%SVN_ROOT%\application\config\config.php"  "%BACKUP%\config.php.bak"  >nul
copy /Y "%SVN_ROOT%\application\config\hooks.php"   "%BACKUP%\hooks.php.bak"   >nul
copy /Y "%SVN_ROOT%\application\config\routes.php"  "%BACKUP%\routes.php.bak"  >nul
echo       Backups saved to %BACKUP%\
echo       ROLLBACK: copy *.bak files back over the originals, then svn commit.
echo.

:: ── 1. config.php ────────────────────────────────────────────────────────────
echo [1/4] Patching application\config\config.php...
set CFG=%SVN_ROOT%\application\config\config.php

if not exist "%CFG%" (
    echo ERROR: config.php not found at %CFG%
    pause & exit /b 1
)

:: enable_hooks — OI has FALSE, PPMS needs TRUE
findstr /C:"enable_hooks'] = TRUE" "%CFG%" >nul 2>&1
if %errorlevel%==0 (
    echo       enable_hooks already TRUE.
) else (
    powershell -Command "(Get-Content '%CFG%') -replace \"enable_hooks'\] = FALSE\", \"enable_hooks'] = TRUE\" | Set-Content '%CFG%'"
    echo       enable_hooks set to TRUE.
)

:: composer_autoload — OI has FALSE, PPMS needs vendor/autoload.php
findstr /C:"vendor/autoload.php" "%CFG%" >nul 2>&1
if %errorlevel%==0 (
    echo       composer_autoload already set.
) else (
    powershell -Command "(Get-Content '%CFG%') -replace \"composer_autoload'\] = FALSE\", \"composer_autoload'] = FCPATH . 'vendor/autoload.php'\" | Set-Content '%CFG%'"
    echo       composer_autoload set to vendor/autoload.php.
)

:: sess_save_path — NOT required for PPMS.
:: PPMS reads OI's existing session (simulation_session key) and never creates
:: a new session save path. OI's existing session configuration is inherited as-is.
echo       sess_save_path — not required (PPMS inherits OI session, no change needed).

:: NOTE: index_page intentionally left as 'index.php' — OI requires it.
:: PPMS shell.php reads index_page automatically and builds correct API URLs.
echo       index_page left as-is — OI requires 'index.php', PPMS adapts.
echo.

:: ── 2. hooks.php ─────────────────────────────────────────────────────────────
echo [2/4] Patching application\config\hooks.php...
set HKS=%SVN_ROOT%\application\config\hooks.php

if not exist "%HKS%" (
    echo       Creating hooks.php...
    (
        echo ^<?php
        echo defined^('BASEPATH'^) OR exit^('No direct script access allowed'^)^;
    ) > "%HKS%"
)

findstr /C:"load_ppms_controller" "%HKS%" >nul 2>&1
if %errorlevel%==0 (
    echo       PPMS hook already present.
) else (
    echo.>> "%HKS%"
    echo // PPMS: pre_controller hook — loads PPMS_Controller before any API request>> "%HKS%"
    echo $hook['pre_controller'] = [>> "%HKS%"
    echo     'function' =^> 'load_ppms_controller',>> "%HKS%"
    echo     'filename' =^> 'PPMS_Hook.php',>> "%HKS%"
    echo     'filepath' =^> 'hooks',>> "%HKS%"
    echo ];>> "%HKS%"
    echo       PPMS hook added.
)
echo.

:: ── 3. routes.php ────────────────────────────────────────────────────────────
echo [3/4] Patching application\config\routes.php...
set RTS=%SVN_ROOT%\application\config\routes.php

if not exist "%RTS%" (
    echo ERROR: routes.php not found.
    pause & exit /b 1
)

:: OI default_controller = 'home' — do NOT change it
echo       default_controller left as 'home' — OI home page unchanged.

findstr /C:"api_projects" "%RTS%" >nul 2>&1
if %errorlevel%==0 (
    echo       PPMS routes already present.
) else (
    echo       Appending PPMS routes via PowerShell...
    powershell -Command ^
        "$r = \"`r`n// ── PPMS Routes ──────────────────────────────────────````r`n\" + ^
        \"`$route['welcome'] = 'welcome/index';`r`n\" + ^
        \"`$route['simulate'] = 'simulate/index';`r`n\" + ^
        \"`$route['simulate/switch'] = 'simulate/switch_user';`r`n\" + ^
        \"`$route['simulate/exit'] = 'simulate/exit_simulation';`r`n\" + ^
        \"`$route['ppms'] = 'ppms/index';`r`n\" + ^
        \"`$route['ppms/(:any)'] = 'ppms/index';`r`n\" + ^
        \"`$route['api/projects'] = 'api_projects/index';`r`n\" + ^
        \"`$route['api/portfolio-stats'] = 'api_projects/portfolio_stats';`r`n\" + ^
        \"`$route['api/projects/([^/]+)/section/([^/]+)'] = 'api_projects/section/`$1/`$2';`r`n\" + ^
        \"`$route['api/projects/([^/]+)/save'] = 'api_projects/save/`$1';`r`n\" + ^
        \"`$route['api/projects/([^/]+)/progress'] = 'api_projects/progress/`$1';`r`n\" + ^
        \"`$route['api/projects/([^/]+)/atrisk_weeks'] = 'api_projects/atrisk_weeks/`$1';`r`n\" + ^
        \"`$route['api/projects/([^/]+)'] = 'api_projects/show/`$1';`r`n\" + ^
        \"`$route['api/export/filtered'] = 'api_export/filtered';`r`n\" + ^
        \"`$route['api/export/download/(:segment)'] = 'api_export/download/`$1';`r`n\" + ^
        \"`$route['api/export/progress/(:segment)'] = 'api_export/progress/`$1';`r`n\" + ^
        \"`$route['api/export/project/(:segment)'] = 'api_export/project/`$1';`r`n\" + ^
        \"`$route['api/export/country/(:segment)'] = 'api_export/country/`$1';`r`n\" + ^
        \"`$route['api/impersonation/return-url'] = 'api_impersonation/set_return_url';`r`n\" + ^
        \"`$route['api/impersonation/context'] = 'api_impersonation/context';`r`n\" + ^
        \"`$route['api/impersonation/switch'] = 'api_impersonation/switch_user';`r`n\" + ^
        \"`$route['api/impersonation/logout'] = 'api_impersonation/logout';`r`n\" + ^
        \"`$route['api/impersonation/exit'] = 'api_impersonation/exit_simulation';`r`n\" + ^
        \"`$route['api/raw'] = 'api_raw/index';`r`n\" + ^
        \"`$route['api/test'] = 'api_test/index';`r`n\" + ^
        \"`$route['ppms-setup'] = 'ppms_setup/index';`r`n\" + ^
        \"`$route['ppms-check'] = 'ppms_check/index';`r`n\" + ^
        \"`$route['api/cache-clear'] = 'api_cache_clear/index';`r`n\" + ^
        \"`$route['api/section-config/([A-Z]{2,4})/save'] = 'api_section_config/save/`$1';`r`n\" + ^
        \"`$route['api/section-config/([A-Z]{2,4})'] = 'api_section_config/get/`$1';`r`n\"; ^
        Add-Content -Path '%RTS%' -Value $r -Encoding UTF8"
    if errorlevel 1 ( echo ERROR: Failed to append routes. & pause & exit /b 1 )
    echo       PPMS routes appended.
)
echo.

:: ── 4. SVN commit the 3 patched files ────────────────────────────────────────
echo [4/4] Committing patched OI config files to SVN...
svn add application\config\config.php  --force 2>nul
svn add application\config\hooks.php   --force 2>nul
svn add application\config\routes.php  --force 2>nul

echo.
echo SVN status of patched files:
svn status application\config\config.php
svn status application\config\hooks.php
svn status application\config\routes.php
echo.

set /p COMMITPATCH=Commit these 3 patched files to SVN? (Y/N): 
if /i "%COMMITPATCH%" NEQ "Y" (
    echo Skipped SVN commit. Run manually when ready:
    echo   svn commit application\config\config.php application\config\hooks.php application\config\routes.php -m "Patch OI config for PPMS"
    pause & exit /b 0
)

svn commit application\config\config.php application\config\hooks.php application\config\routes.php -m "Patch OI config for PPMS"
if errorlevel 1 (
    echo ERROR: SVN commit failed. Check output above.
    pause & exit /b 1
)

echo.
echo ============================================================
echo  OI config patched and committed successfully.
echo.
echo  What was changed:
echo    config.php  — enable_hooks=TRUE, composer_autoload,
echo                  sess_save_path (index_page unchanged)
echo    hooks.php   — PPMS pre_controller hook added
echo    routes.php  — PPMS routes appended (default_controller unchanged)
echo.
echo  Backups saved to: %BACKUP%\
echo.
echo  ROLLBACK (if needed):
echo    copy %BACKUP%\config.php.bak  application\config\config.php
echo    copy %BACKUP%\hooks.php.bak   application\config\hooks.php
echo    copy %BACKUP%\routes.php.bak  application\config\routes.php
echo    svn commit -m "Rollback OI config patch"
echo.
echo  Next step: Run 2_SVN_COMMIT.bat from the PPMS repo.
echo ============================================================
echo.
echo ============================================================
echo  NOTE: No SSH or manual server chmod required.
echo  Ppms_setup.php (visit /index.php/ppms-setup in browser)
echo  creates and chmods all required directories automatically.
echo ============================================================
echo.
pause
