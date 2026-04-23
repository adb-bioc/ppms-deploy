<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Ppms_setup — One-time PPMS setup.
 * Fixes permissions, creates directories, initializes SQLite DB.
 * Route: GET /index.php/ppms-setup
 * DELETE before production.
 */
class Ppms_setup extends CI_Controller
{
    public function index()
    {
        header('Content-Type: text/html; charset=utf-8');
        $steps  = [];
        $errors = [];

        // Helper
        $step = function($ok, $msg, $err_detail = '') use (&$steps, &$errors) {
            if ($ok === 'skip') { $steps[] = ['skip', $msg]; return; }
            if ($ok)            { $steps[] = ['ok',   $msg]; return; }
            $errors[] = $err_detail ?: $msg;
            $steps[]  = ['err',  $msg];
        };

        // ── 1. application/database/ ─────────────────────────────────────────
        $db_dir = APPPATH . 'database/';
        if (!is_dir($db_dir)) {
            $step(mkdir($db_dir, 0775, true),
                  'Created application/database/',
                  'Cannot create application/database/ — check parent folder permissions.');
        } elseif (!is_writable($db_dir)) {
            $step(chmod($db_dir, 0775),
                  'Set application/database/ writable (chmod 775)',
                  'Cannot chmod application/database/ — web process does not own it. Ask server admin: chmod 775 ' . $db_dir);
        } else {
            $step('skip', 'application/database/ already writable');
        }

        // ── 2. application/cache/ ────────────────────────────────────────────
        $cache_dir = APPPATH . 'cache/';
        if (!is_dir($cache_dir)) {
            $step(mkdir($cache_dir, 0775, true),
                  'Created application/cache/',
                  'Cannot create application/cache/');
        } elseif (!is_writable($cache_dir)) {
            $step(chmod($cache_dir, 0775),
                  'Set application/cache/ writable (chmod 775)',
                  'Cannot chmod application/cache/ — ask server admin: chmod 775 ' . $cache_dir);
        } else {
            $step('skip', 'application/cache/ already writable');
        }

        // ── 3. application/cache/sessions/ ───────────────────────────────────
        $sess_dir = APPPATH . 'cache/sessions/';
        if (!is_dir($sess_dir)) {
            $step(mkdir($sess_dir, 0775, true),
                  'Created application/cache/sessions/',
                  'Cannot create sessions/ — ask server admin: mkdir -p ' . $sess_dir . ' && chmod 775 ' . $sess_dir);
        } elseif (!is_writable($sess_dir)) {
            $step(chmod($sess_dir, 0775),
                  'Set application/cache/sessions/ writable (chmod 775)',
                  'Cannot chmod sessions/ — ask server admin: chmod 775 ' . $sess_dir);
        } else {
            $step('skip', 'application/cache/sessions/ already writable');
        }

        // ── 4. SQLite database ────────────────────────────────────────────────
        $db_file = APPPATH . 'database/ppms.db';
        if (file_exists($db_file)) {
            // Verify tables
            try {
                $pdo    = new PDO('sqlite:' . $db_file);
                $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
                $req    = ['ppms_projects','ppms_sections','ppms_rows','ppms_section_config','ppms_audit_log'];
                $miss   = array_diff($req, $tables);
                if (empty($miss)) {
                    $step('skip', 'ppms.db already exists — all 5 tables present');
                } else {
                    // Re-run schema to add missing tables
                    $schema = APPPATH . 'database/schema_sqlite.sql';
                    if (file_exists($schema)) {
                        $pdo->exec(file_get_contents($schema));
                        $step(true, 'ppms.db exists — created missing tables: ' . implode(', ', $miss));
                    } else {
                        $step(false, 'ppms.db exists but missing tables — schema_sqlite.sql not found', 'schema_sqlite.sql missing');
                    }
                }
            } catch (Exception $e) {
                $step(false, 'Cannot open ppms.db', $e->getMessage());
            }
        } elseif (!is_writable($db_dir)) {
            $step(false, 'Cannot create ppms.db — application/database/ not writable', 'Fix Step 1 first, then reload.');
        } else {
            $schema = APPPATH . 'database/schema_sqlite.sql';
            if (!file_exists($schema)) {
                $step(false, 'schema_sqlite.sql not found', 'schema_sqlite.sql missing from application/database/');
            } else {
                try {
                    $pdo = new PDO('sqlite:' . $db_file);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $pdo->exec(file_get_contents($schema));
                    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
                    $req    = ['ppms_projects','ppms_sections','ppms_rows','ppms_section_config','ppms_audit_log'];
                    $miss   = array_diff($req, $tables);
                    if (empty($miss)) {
                        $step(true, 'SQLite database created — all 5 tables initialized');
                    } else {
                        $step(false, 'DB created but missing tables: ' . implode(', ', $miss), 'Schema may be incomplete.');
                    }
                } catch (Exception $e) {
                    $step(false, 'DB init failed', $e->getMessage());
                }
            }
        }

        $all_ok = empty($errors);
        $ppms_url = base_url('index.php/ppms');
        ?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><title>PPMS Setup</title>
<style>
  *{box-sizing:border-box}body{font-family:'Segoe UI',sans-serif;background:#f4f6f9;color:#212529;padding:32px;font-size:14px;margin:0}
  h1{font-size:22px;margin:0 0 4px;color:#1565C0}.sub{font-size:12px;color:#6c757d;margin-bottom:28px}
  .step{display:flex;align-items:flex-start;gap:12px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;margin-bottom:8px}
  .i-ok{color:#059669;font-size:18px;flex-shrink:0}.i-skip{color:#9ca3af;font-size:18px;flex-shrink:0}.i-err{color:#dc2626;font-size:18px;flex-shrink:0}
  .lbl{font-size:13px}.note{font-size:11px;color:#6b7280;margin-top:4px}
  .banner{padding:16px 20px;border-radius:8px;font-weight:700;margin-top:24px;font-size:15px}
  .ok{background:#d1fae5;color:#065f46}.err{background:#fee2e2;color:#991b1b}
  .next{margin-top:12px;font-size:13px}a{color:#1565C0;font-weight:700}
  .warn{font-size:11px;color:#9ca3af;margin-top:32px}code{background:#f0f4f8;padding:1px 6px;border-radius:4px;font-family:monospace;font-size:12px}
  .reload{display:inline-block;margin-top:12px;padding:8px 16px;background:#1565C0;color:#fff;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600}
</style></head><body>
<h1>PPMS Setup</h1>
<div class="sub">One-time setup — fixes permissions and initializes the database.<br>
Steps already done are skipped automatically on reload.</div>

<?php foreach ($steps as [$status, $msg]): ?>
<div class="step">
  <div class="i-<?= $status ?>">
    <?= $status==='ok' ? '✓' : ($status==='err' ? '✗' : '–') ?>
  </div>
  <div class="lbl"><?= htmlspecialchars($msg) ?></div>
</div>
<?php endforeach; ?>

<?php foreach ($errors as $e): ?>
<div class="step">
  <div class="i-err">✗</div>
  <div class="lbl">
    <?= htmlspecialchars($e) ?>
    <div class="note">PHP cannot change permissions on files it does not own. Ask the server admin to run this manually.</div>
  </div>
</div>
<?php endforeach; ?>

<?php if ($all_ok): ?>
<div class="banner ok">✓ Setup complete — PPMS is ready.</div>
<div class="next">→ <a href="<?= $ppms_url ?>">Open PPMS Dashboard</a></div>
<?php else: ?>
<div class="banner err">✗ Some steps could not be completed automatically.</div>
<a class="reload" href="<?= current_url() ?>">↻ Reload and retry</a>
<div class="next">Steps that succeeded will be skipped on reload.</div>
<?php endif; ?>

<p class="warn">Delete <code>Ppms_setup.php</code> and remove the <code>ppms-setup</code> route before production.</p>
</body></html>
<?php
    }
}
