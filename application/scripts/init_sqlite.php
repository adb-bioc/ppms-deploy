<?php
/**
 * PPMS SQLite Initializer
 *
 * Creates application/database/ppms.db with all 5 tables.
 *
 * Compatible with PHP 7.1+
 *
 * Usage (run from CI3 root):
 *   php application/scripts/init_sqlite.php
 *
 * Safe to re-run — uses CREATE TABLE IF NOT EXISTS.
 */

// ── Paths ─────────────────────────────────────────────────────────────────────
// __DIR__ = application/scripts
// CI3 root = two levels up
$ci3_root   = realpath(__DIR__ . '/../../');
$db_dir     = $ci3_root . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR . 'database';
$db_path    = $db_dir   . DIRECTORY_SEPARATOR . 'ppms.db';

echo "\n";
echo "PPMS SQLite Initializer\n";
echo str_repeat('-', 50) . "\n";
echo "CI3 root : " . $ci3_root . "\n";
echo "DB file  : " . $db_path  . "\n\n";

// ── Create database directory if missing ──────────────────────────────────────
if (!is_dir($db_dir)) {
    if (!mkdir($db_dir, 0775, true)) {
        echo "[ERROR] Cannot create directory: {$db_dir}\n";
        echo "        Check folder permissions.\n\n";
        exit(1);
    }
    echo "[OK] Created directory: {$db_dir}\n";
}

// ── Check PDO SQLite is available ─────────────────────────────────────────────
if (!extension_loaded('pdo_sqlite')) {
    echo "[ERROR] pdo_sqlite PHP extension is not loaded.\n";
    echo "        Enable it in php.ini: extension=pdo_sqlite\n\n";
    exit(1);
}

// ── Open / create the database file ──────────────────────────────────────────
try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo "[ERROR] Cannot open database: " . $e->getMessage() . "\n\n";
    exit(1);
}

echo "[OK] Database opened\n\n";

// ── WAL mode (better concurrent reads) ───────────────────────────────────────
// Use query() not exec() for PRAGMAs that return rows
try {
    $pdo->query('PRAGMA journal_mode = WAL');
    $pdo->query('PRAGMA foreign_keys = ON');
} catch (Exception $e) {
    // Non-fatal — WAL mode is a performance hint only
    echo "[WARN] PRAGMA: " . $e->getMessage() . "\n";
}

// ── Schema — defined inline (no external file dependency) ────────────────────
// Each table is executed separately so one failure doesn't block the others.

$tables = [

    'ppms_projects' => "
        CREATE TABLE IF NOT EXISTS ppms_projects (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id       TEXT    NOT NULL UNIQUE,
            dmc              TEXT    NOT NULL,
            status           TEXT    NOT NULL DEFAULT 'not_started',
            overall_progress INTEGER NOT NULL DEFAULT 0,
            last_opened_at   TEXT    NULL,
            created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ",

    'ppms_sections' => "
        CREATE TABLE IF NOT EXISTS ppms_sections (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id       TEXT    NOT NULL,
            section_key      TEXT    NOT NULL,
            progress         INTEGER NOT NULL DEFAULT 0,
            status           TEXT    NOT NULL DEFAULT 'not_started',
            data_json        TEXT    NULL,
            updated_by_real  TEXT    NULL,
            updated_by_eff   TEXT    NULL,
            updated_at       TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE(project_id, section_key)
        )
    ",

    'ppms_rows' => "
        CREATE TABLE IF NOT EXISTS ppms_rows (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id       TEXT    NOT NULL,
            section_key      TEXT    NOT NULL,
            row_uuid         TEXT    NOT NULL UNIQUE,
            loan_grant_no    TEXT    NULL,
            data_json        TEXT    NOT NULL DEFAULT '{}',
            sort_order       INTEGER NOT NULL DEFAULT 0,
            is_deleted       INTEGER NOT NULL DEFAULT 0,
            created_by_real  TEXT    NULL,
            created_by_eff   TEXT    NULL,
            updated_by_real  TEXT    NULL,
            updated_by_eff   TEXT    NULL,
            created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ",

    'ppms_section_config' => "
        CREATE TABLE IF NOT EXISTS ppms_section_config (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            dmc          TEXT    NOT NULL,
            role         TEXT    NOT NULL DEFAULT 'ptl',
            section_key  TEXT    NOT NULL,
            enabled      INTEGER NOT NULL DEFAULT 1,
            updated_by   TEXT    NULL,
            updated_at   TEXT    NOT NULL DEFAULT (datetime('now')),
            UNIQUE(dmc, role, section_key)
        )
    ",

    'ppms_audit_log' => "
        CREATE TABLE IF NOT EXISTS ppms_audit_log (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            action            TEXT    NOT NULL,
            project_id        TEXT    NOT NULL,
            section_key       TEXT    NULL,
            row_uuid          TEXT    NULL,
            real_user_id      TEXT    NOT NULL,
            effective_user_id TEXT    NOT NULL,
            effective_dmc     TEXT    NOT NULL,
            is_impersonating  INTEGER NOT NULL DEFAULT 0,
            payload_json      TEXT    NULL,
            ip_address        TEXT    NULL,
            created_at        TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ",

    'ppms_csv_imports' => "
        CREATE TABLE IF NOT EXISTS ppms_csv_imports (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            filename    TEXT    NOT NULL UNIQUE,
            file_hash   TEXT    NOT NULL,
            row_count   INTEGER NOT NULL DEFAULT 0,
            imported_at TEXT    NOT NULL DEFAULT (datetime('now')),
            cache_key   TEXT    NULL
        )
    ",
];

$indexes = [
    'ppms_projects'    => [
        "CREATE INDEX IF NOT EXISTS idx_ppms_projects_dmc    ON ppms_projects(dmc)",
        "CREATE INDEX IF NOT EXISTS idx_ppms_projects_status ON ppms_projects(status)",
    ],
    'ppms_sections'    => [
        "CREATE INDEX IF NOT EXISTS idx_ppms_sections_project ON ppms_sections(project_id)",
    ],
    'ppms_rows'        => [
        "CREATE INDEX IF NOT EXISTS idx_ppms_rows_project_section ON ppms_rows(project_id, section_key)",
        "CREATE INDEX IF NOT EXISTS idx_ppms_rows_deleted         ON ppms_rows(is_deleted)",
    ],
    'ppms_audit_log'   => [
        "CREATE INDEX IF NOT EXISTS idx_ppms_audit_project   ON ppms_audit_log(project_id)",
        "CREATE INDEX IF NOT EXISTS idx_ppms_audit_real_user ON ppms_audit_log(real_user_id)",
        "CREATE INDEX IF NOT EXISTS idx_ppms_audit_created   ON ppms_audit_log(created_at)",
    ],
    'ppms_section_config' => [
        "CREATE INDEX IF NOT EXISTS idx_ppms_seccfg_dmc_role ON ppms_section_config(dmc, role)",
    ],
    'ppms_csv_imports' => [],
];

// ── Create tables ─────────────────────────────────────────────────────────────
echo "Creating tables...\n";
$table_errors = 0;

foreach ($tables as $name => $ddl) {
    try {
        $pdo->exec(trim($ddl));
        echo "  [OK] {$name}\n";
    } catch (Exception $e) {
        echo "  [ERROR] {$name}: " . $e->getMessage() . "\n";
        $table_errors++;
    }
}

// ── Create indexes ────────────────────────────────────────────────────────────
echo "\nCreating indexes...\n";
foreach ($indexes as $table => $idx_list) {
    foreach ($idx_list as $idx_sql) {
        try {
            $pdo->exec($idx_sql);
        } catch (Exception $e) {
            echo "  [WARN] Index on {$table}: " . $e->getMessage() . "\n";
        }
    }
}
echo "  [OK] All indexes\n";

// ── Migrate existing data — enable all PTL sections ──────────────────────────
// Any existing rows with enabled=0 (the old default) are upgraded to 1.
// This runs safely on both fresh and existing databases.
echo "\nMigrating section config — enabling all PTL sections...\n";
try {
    $affected = $pdo->exec("UPDATE ppms_section_config SET enabled = 1 WHERE enabled = 0");
    echo "  [OK] " . ($affected === false ? 0 : $affected) . " row(s) updated\n";
} catch (Exception $e) {
    echo "  [WARN] Migration: " . $e->getMessage() . "\n";
}

// ── Verify ────────────────────────────────────────────────────────────────────
echo "\nVerifying tables...\n";
$all_ok = true;

foreach (array_keys($tables) as $table) {
    try {
        $row = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='" . $table . "'"
        )->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            echo "  [OK] " . $table . "\n";
        } else {
            echo "  [MISSING] " . $table . "\n";
            $all_ok = false;
        }
    } catch (Exception $e) {
        echo "  [ERROR] " . $table . ": " . $e->getMessage() . "\n";
        $all_ok = false;
    }
}

// ── Result ────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('-', 50) . "\n";

if ($all_ok && $table_errors === 0) {
    echo "SUCCESS — database is ready.\n\n";
    echo "File: " . $db_path . "\n\n";
    echo "Next steps:\n";
    echo "  1. Add PPMS routes to application/config/routes.php\n";
    echo "     (copy from PASTE_INTO_ROUTES.php)\n";
    echo "  2. Run: composer require phpoffice/phpspreadsheet\n";
    echo "  3. Run: cd vue_src && npm install && npm run build\n";
    echo "  4. Copy your CSV files to csv_data/\n";
    echo "  5. Copy PPMS_template.xlsx to application/templates/\n";
    echo "  6. Visit http://localhost/<your-app>/simulate\n\n";
    exit(0);
} else {
    echo "FAILED — " . $table_errors . " table(s) not created.\n\n";
    echo "Common causes:\n";
    echo "  - The application/database/ folder is not writable\n";
    echo "  - pdo_sqlite extension is disabled in php.ini\n";
    echo "  - Another process has the database file locked\n\n";
    exit(1);
}
