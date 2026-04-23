-- =============================================================================
-- PPMS SQLite Schema
-- =============================================================================
-- SQLite equivalent of database/schema.sql.
-- Identical table structure — switching to MySQL later requires zero code changes.
--
-- Initialize:
--   php application/scripts/init_sqlite.php
--   — or —
--   sqlite3 application/database/ppms.db < database/schema_sqlite.sql
-- =============================================================================

PRAGMA journal_mode = WAL;       -- Better concurrent read performance
PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------------------
-- Project workspace
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ppms_projects (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id       TEXT    NOT NULL UNIQUE,
    dmc              TEXT    NOT NULL,
    status           TEXT    NOT NULL DEFAULT 'not_started',
    overall_progress INTEGER NOT NULL DEFAULT 0,
    last_opened_at   TEXT    NULL,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_ppms_projects_dmc    ON ppms_projects(dmc);
CREATE INDEX IF NOT EXISTS idx_ppms_projects_status ON ppms_projects(status);

-- ---------------------------------------------------------------------------
-- Section flat data
-- ---------------------------------------------------------------------------
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
);
CREATE INDEX IF NOT EXISTS idx_ppms_sections_project ON ppms_sections(project_id);

-- ---------------------------------------------------------------------------
-- Table rows (tabular sections)
-- ---------------------------------------------------------------------------
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
);
CREATE INDEX IF NOT EXISTS idx_ppms_rows_project_section ON ppms_rows(project_id, section_key);
CREATE INDEX IF NOT EXISTS idx_ppms_rows_deleted         ON ppms_rows(is_deleted);

-- ---------------------------------------------------------------------------
-- Audit log
-- ---------------------------------------------------------------------------
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
);
CREATE INDEX IF NOT EXISTS idx_ppms_audit_project   ON ppms_audit_log(project_id);
CREATE INDEX IF NOT EXISTS idx_ppms_audit_real_user ON ppms_audit_log(real_user_id);
CREATE INDEX IF NOT EXISTS idx_ppms_audit_created   ON ppms_audit_log(created_at);

-- ---------------------------------------------------------------------------
-- CSV import tracking
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ppms_csv_imports (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    filename    TEXT    NOT NULL UNIQUE,
    file_hash   TEXT    NOT NULL,
    row_count   INTEGER NOT NULL DEFAULT 0,
    imported_at TEXT    NOT NULL DEFAULT (datetime('now')),
    cache_key   TEXT    NULL
);

-- ---------------------------------------------------------------------------
-- Section config (per DMC+role section enable/disable settings)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ppms_section_config (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    dmc         TEXT    NOT NULL,
    role        TEXT    NOT NULL DEFAULT 'ptl',
    section_key TEXT    NOT NULL,
    enabled     INTEGER NOT NULL DEFAULT 1,
    updated_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE(dmc, role, section_key)
);
CREATE INDEX IF NOT EXISTS idx_ppms_section_config_dmc ON ppms_section_config(dmc, role);
