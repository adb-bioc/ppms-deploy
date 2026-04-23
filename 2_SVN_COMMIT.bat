@echo off
setlocal enabledelayedexpansion
title PPMS — Step 2: SVN Commit (Jumphost)

echo.
echo ============================================================
echo  PPMS — Step 2: SVN Commit
echo  Run INSIDE THE RDP SESSION on the jumphost
echo  AFTER copying ppms_deploy contents to E:\sectorsinsights\
echo ============================================================
echo.

:: ── CONFIG ───────────────────────────────────────────────────────────────────
set SVN_ROOT=E:\sectorsinsights
:: ─────────────────────────────────────────────────────────────────────────────

if not exist "%SVN_ROOT%" (
    echo ERROR: %SVN_ROOT% not found. Update SVN_ROOT at the top.
    pause & exit /b 1
)
cd /d "%SVN_ROOT%"

:: ── 1. SVN update ─────────────────────────────────────────────────────────────
echo [1/3] SVN update...
svn update
if errorlevel 1 (
    echo ERROR: svn update failed. Resolve conflicts before deploying.
    pause & exit /b 1
)
echo       OK.
echo.

:: ── Confirm PATCH_OI_CONFIG has been run ──────────────────────────────────────
echo ============================================================
echo  Has PATCH_OI_CONFIG.bat been run yet?
echo  (Required for routes, hooks, and config — first time only)
echo ============================================================
echo.
set /p PATCHED=Have you run PATCH_OI_CONFIG.bat? (Y/N): 
if /i "%PATCHED%" NEQ "Y" (
    echo Run PATCH_OI_CONFIG.bat first, then re-run this script.
    pause & exit /b 0
)
echo.

:: ── 2. SVN add all PPMS files ─────────────────────────────────────────────────
echo [2/3] Adding PPMS files to SVN...
echo.

:: Controllers
svn add application\controllers\Api_cache_clear.php      --force 2>nul
svn add application\controllers\Api_export.php           --force 2>nul
svn add application\controllers\Api_impersonation.php    --force 2>nul
svn add application\controllers\Api_projects.php         --force 2>nul
svn add application\controllers\Api_raw.php              --force 2>nul
svn add application\controllers\Api_section_config.php   --force 2>nul
svn add application\controllers\Api_test.php             --force 2>nul
svn add application\controllers\Dashboard.php            --force 2>nul
svn add application\controllers\PPMS_Controller.php      --force 2>nul
svn add application\controllers\Ppms.php                 --force 2>nul
svn add application\controllers\Ppms_check.php           --force 2>nul
svn add application\controllers\Ppms_setup.php           --force 2>nul
svn add application\controllers\Simulate.php             --force 2>nul
svn add application\controllers\Welcome.php              --force 2>nul

:: Libraries
svn add application\libraries\CSV_reader.php             --force 2>nul
svn add application\libraries\PPMS_Cache.php             --force 2>nul
svn add application\libraries\Progress_calculator.php    --force 2>nul

:: Models
svn add application\models\PPMS_model.php                --force 2>nul
svn add application\models\Project_model.php             --force 2>nul
svn add application\models\Simulation_user_model.php     --force 2>nul

:: Helpers + Hooks
svn add application\helpers\simulation_helper.php        --force 2>nul
svn add application\hooks\PPMS_Hook.php                  --force 2>nul

:: Config (PPMS-only — NOT config.php/hooks.php/routes.php, those are patched)
svn add application\config\ppms.php                      --force 2>nul
svn add application\config\ppms_database.php             --force 2>nul

:: Views
svn add application\views\ppms                           --force 2>nul
svn add application\views\welcome                        --force 2>nul

:: Scripts + templates + schema
svn add application\scripts\init_sqlite.php              --force 2>nul
svn add application\templates\PPMS_template.xlsx         --force 2>nul
svn add application\database\schema_sqlite.sql           --force 2>nul

:: Public (built frontend)
svn add public\js\ppms.bundle.js                         --force 2>nul
svn add public\css\ppms.css                              --force 2>nul
svn add public\css\ppms-vue.css                          --force 2>nul

:: Root
svn add ppms_check.php                                   --force 2>nul

:: Do NOT add — OI owns these:
:: svn add index.php   --force
:: svn add .htaccess   --force

:: vendor/ already on server
:: svn add vendor\*    --force

echo.
echo [3/3] SVN status — review before committing:
svn status
echo.

:: ── 3. Confirm and commit ─────────────────────────────────────────────────────
set /p COMMIT=Commit to SVN? (Y/N): 
if /i "%COMMIT%" NEQ "Y" (
    echo Cancelled. Nothing committed.
    pause & exit /b 0
)

echo.
echo Committing...
svn commit -m "Deploy PPMS module"
if errorlevel 1 (
    echo ERROR: svn commit failed. See output above.
    pause & exit /b 1
)

echo.
echo ============================================================
echo  SVN commit done.
echo.
echo  CSV files — if not yet committed:
echo    1. Copy csv_data\csv_data.zip to %SVN_ROOT%\csv_data\
echo    2. Unzip it there
echo    3. svn add csv_data\*.csv --force
echo    4. svn commit -m "Add PPMS CSV data"
echo.
echo  After SVN propagates, verify in order:
echo.
echo  1. https://sectorsinsightsdev.adb.org/ppms_check.php
echo     All 10 sections must be green.
echo.
echo  2. https://sectorsinsightsdev.adb.org/index.php/ppms-setup
echo     Run once to fix permissions + create database.
echo.
echo  3. https://sectorsinsightsdev.adb.org/index.php/ppms
echo     Dashboard should load.
echo.
echo  ROLLBACK (if anything breaks):
echo    svn log --limit 5
echo    svn merge -r HEAD:NNN .
echo    svn commit -m "Rollback PPMS"
echo    Or config-only: copy ppms_patch_backup\*.bak back, svn commit
echo ============================================================
echo.
pause
