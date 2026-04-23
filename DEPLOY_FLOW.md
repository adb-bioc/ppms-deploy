# PPMS Deployment Guide

## The Flow

```
Dev Machine                    Company Laptop       Jumphost (RDP)        Dev Server
───────────                    ──────────────       ──────────────        ──────────
1_BUILD.bat                    git pull         →   paste deploy_ready\   SVN update
  npm run build          →     (gets               into E:\sectorsinsights\  (automatic)
  composer install              deploy_ready\)      2_SVN_COMMIT.bat  →
  stages deploy_ready\                              svn commit
git push (with deploy_ready\)
```

**Key rules:**
- `deploy_ready/` travels via git — this is the only bridge between dev machine and company laptop
- `vendor/` and built JS/CSS live inside `deploy_ready/` — they reach the server through it
- `vendor/` at the repo root is never committed — only `deploy_ready/vendor/` is
- CSV files are zipped by `1_BUILD.bat` → `csv_data\csv_data.zip` travels via git → unzip into E:\csv_data\ via RDP

---

## Step 1 — Dev Machine: Build

Run `1_BUILD.bat`.

What it does:
- `npm run build` → `public\js\ppms.bundle.js` + `public\css\ppms-vue.css`
- `composer install` → `vendor\phpoffice\phpspreadsheet\`
- Copies all of the above + all PHP source into `deploy_ready\`

Then push to git — `deploy_ready/` is NOT gitignored so it travels with the push:
```
git add deploy_ready
git add .
git commit -m "PPMS build"
git push
```

---

## Step 2 — Company Laptop: Pull

```
git pull
```

You now have `deploy_ready\` on your company laptop — no manual file transfer needed.

---

## Step 3 — RDP into Jumphost: Patch OI Config FIRST

Before copying any PPMS files, run `PATCH_OI_CONFIG.bat` from inside the RDP session.

This script **appends** what PPMS needs to the existing OI config files — it does NOT replace them:

| File | What is added |
|------|---------------|
| `application/config/config.php` | `enable_hooks=TRUE` only (composer_autoload and sess_save_path are **not** required — PPMS self-bootstraps its autoload and inherits OI's existing session) |
| `application/config/hooks.php` | PPMS `pre_controller` hook entry |
| `application/config/routes.php` | All PPMS routes appended at the bottom |

The script checks if each line already exists before adding — safe to run multiple times.

After running it, SVN add + commit those 3 files before anything else.

---

## Step 4 — RDP into Jumphost: Copy deploy_ready\

Open RDP. Inside the RDP session, copy the contents of `deploy_ready\` into `E:\sectorsinsights\`.

The `deploy_ready\` folder contains:
```
application\controllers\*.php
application\libraries\*.php
application\models\*.php
application\config\ppms.php
application\config\ppms_database.php
application\config\hooks.php
application\config\config.php
application\config\routes.php        ← already has all PPMS routes baked in
application\views\ppms\
application\views\welcome\
application\scripts\
application\templates\
application\database\schema_sqlite.sql
public\js\ppms.bundle.js
public\css\ppms-vue.css
public\css\ppms.css
vendor\phpoffice\
vendor\maennchen\
vendor\markbaker\
index.php
.htaccess
ppms_check.php
```

---

## Step 5 — Jumphost: SVN Commit

Run `2_SVN_COMMIT.bat` from inside `E:\sectorsinsights\`.

It will:
1. `svn update` — sync working copy first
2. `svn add` all new PPMS files
3. Show `svn status` for your review
4. Ask for confirmation before committing
5. `svn commit`

**Note on index.php and .htaccess:** These are already in the existing CI3 app on E:\
The bat leaves them commented out. Only uncomment if this is a brand-new CI3 install.

---

## Step 6 — CSV Files

`1_BUILD.bat` zips your CSV files into `csv_data\csv_data.zip` which travels via git.

On your dev machine: place your 7 OI CSV exports in `csv_data\` before running `1_BUILD.bat`.

After `git pull` on the company laptop, `csv_data\csv_data.zip` is available.

Inside the RDP session on the jumphost:
1. Copy `csv_data.zip` to `E:\sectorsinsights\csv_data\`
2. Right-click → Extract All into that folder
3. Then:
```
cd E:\sectorsinsights
svn add csv_data\*.csv --force
svn commit -m "Add PPMS CSV data"
```

---

## Step 7 — Verify on Server (browser only)

| URL | Action |
|-----|--------|
| `/ppms_check.php` | **Do this first.** Sections 1, 3 (core), 4, 5, 6, 7, 8, 9, 10 must pass. Section 2 (base_url) is informational only. Section 3 shows `enable_hooks` as a warning if not yet set. |
| `/index.php/ppms-setup` | Initialize SQLite DB + create cache dirs — **one-time only** |
| `/index.php/ppms` | Open PPMS dashboard |

---

## Rollback Plan

SVN makes rollback straightforward since every commit is numbered.

### Finding the last good revision
Inside the jumphost RDP session:
```
cd E:\sectorsinsights
svn log --limit 10
```
Note the revision number before your PPMS deploy (e.g. `r142`).

### Rolling back specific PPMS files only
```
svn revert application\controllers\Api_projects.php
svn revert application\libraries\CSV_reader.php
:: ...repeat for any file that caused the problem
svn commit -m "Rollback: revert Api_projects.php to pre-PPMS state"
```

### Rolling back the entire working copy to a previous revision
```
svn merge -r HEAD:142 .
svn commit -m "Rollback: revert to r142 (pre-PPMS deploy)"
```
Replace `142` with the actual last-good revision number from `svn log`.

### What rollback does NOT affect
- `application\database\ppms.db` — SQLite DB is not in SVN, stays on server
- `csv_data\*.csv` — CSV files remain, no harm
- The rest of the CI3 app is untouched by PPMS files

### Pre-deploy safety check
Before deploying, note the current SVN revision:
```
svn info | findstr Revision
```
Write it down. If the deploy fails, that's your rollback target.

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| 404 on `/api/*` | Routes not in routes.php — check DEPLOY.md Step 3 |
| Blank dashboard | `ppms.bundle.js` missing — check `public\js\` on server |
| `pdo_sqlite` error | Enable `extension=pdo_sqlite` in php.ini on server |
| `PhpSpreadsheet not installed` | On OI server: vendor is already present — set `$oi_server = true` in `ppms_check.php`. On dev: run `composer require phpoffice/phpspreadsheet` at CI3 root. |
| CSV not found | CSV files not in `csv_data\` or SVN ignored `*.csv` |
| Cache permission error | Server needs write access to `application\cache\` |
| Timeout on large portfolio | Add `php_value max_execution_time 120` to `.htaccess` |

---

## XAMPP Local Testing

**Simple (no hosts file edit needed):**

Place the PPMS folder inside `C:\xampp\htdocs\ppms\` and visit:
- `http://localhost/ppms/index.php/ppms` → Dashboard
- `http://localhost/ppms/ppms_check.php` → Pre-flight check

PPMS works at any path — no vhost or hosts file required. The base_url mismatch in ppms_check.php Section 2 is informational only.

**Optional — to match production URL exactly:**

Add to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:
```apache
<VirtualHost *:80>
    ServerName sectorsinsightsdev.adb.org
    DocumentRoot "C:/xampp/htdocs/ppms"
    <Directory "C:/xampp/htdocs/ppms">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add to `C:\Windows\System32\drivers\etc\hosts` (as Administrator):
```
127.0.0.1    sectorsinsightsdev.adb.org
```

Restart Apache — this makes PPMS accessible at the exact production URL locally.
