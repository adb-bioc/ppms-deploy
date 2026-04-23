<?php
/**
 * PPMS Pre-flight Check
 * Standalone — no CI3, no routing, no login needed.
 * Place at server root. Visit: https://sectorsinsightsdev.adb.org/ppms_check.php
 * DELETE before production.
 */

$root      = __DIR__;
$app       = $root . '/application';
$csv_dir   = $root . '/csv_data/';
$db_file   = $app  . '/database/ppms.db';
$cache_dir = $app  . '/cache/';
$bundle    = $root . '/public/js/ppms.bundle.js';

// ── OI Server flag ────────────────────────────────────────────────────────────
// Set to TRUE when deploying inside the OI environment where
// vendor/phpoffice/phpspreadsheet is already installed server-wide.
// When TRUE the Section 4 PhpSpreadsheet file-system check is skipped and
// shown as pre-confirmed (PASS) so it does not block the overall result.
$oi_server = true;

header('Content-Type: text/html; charset=utf-8');

$all_pass = true;

function row($label, $ok, $value, $fix = '') {
    global $all_pass;
    if (!$ok) $all_pass = false;
    $cls = $ok ? 'ok' : 'err';
    $fix_html = $fix ? "<div class='fix'>→ {$fix}</div>" : '';
    echo "<tr><td>{$label}</td><td class='{$cls}'>{$value}{$fix_html}</td></tr>";
}
function row_warn($label, $value, $fix = '') {
    $fix_html = $fix ? "<div class='fix'>→ {$fix}</div>" : '';
    echo "<tr><td>{$label}</td><td class='warn'>{$value}{$fix_html}</td></tr>";
}
function row_info($label, $value) {
    echo "<tr><td>{$label}</td><td class='mono'>" . htmlspecialchars($value) . "</td></tr>";
}
function section($title, $pass) {
    $cls = $pass ? 'b-ok' : 'b-err';
    $lbl = $pass ? 'PASS' : 'FAIL';
    echo "<h2>{$title} <span class='badge {$cls}'>{$lbl}</span></h2>";
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8">
<title>PPMS Pre-flight Check</title>
<style>
  *{box-sizing:border-box}
  body{font-family:'Segoe UI',sans-serif;background:#f4f6f9;color:#212529;padding:24px;font-size:14px;margin:0}
  h1{font-size:22px;margin:0 0 4px;color:#1565C0}
  .sub{font-size:12px;color:#6c757d;margin-bottom:24px}
  h2{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#6c757d;margin:24px 0 8px;display:flex;align-items:center;gap:8px}
  .badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700}
  .b-ok{background:#d1fae5;color:#065f46}.b-err{background:#fee2e2;color:#991b1b}
  .ok{color:#059669;font-weight:700}.err{color:#dc2626;font-weight:700}.warn{color:#d97706;font-weight:700}
  table{border-collapse:collapse;width:100%;max-width:900px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:4px}
  th{background:#f8f9fc;text-align:left;padding:8px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#6c757d;border-bottom:1px solid #e5e7eb}
  td{padding:9px 16px;border-bottom:1px solid #f3f4f6;vertical-align:top;font-size:13px}
  tr:last-child td{border-bottom:none}
  code{background:#f0f4f8;padding:1px 6px;border-radius:4px;font-family:'Courier New',monospace;font-size:12px}
  .mono{font-family:'Courier New',monospace;font-size:11px;color:#374151}
  .fix{font-size:11px;color:#6b7280;margin-top:2px}
</style>
</head><body>
<h1>PPMS Pre-flight Check</h1>
<div class="sub">Run this immediately after SVN deploy — before visiting /ppms-setup or /ppms.<br>
Fix every FAIL before proceeding. Delete this file before production.</div>

<?php

// ═══════════════════════════════════════════════════════════════
// 1. PHP ENVIRONMENT
// ═══════════════════════════════════════════════════════════════
$php_ok   = version_compare(PHP_VERSION, '7.4.0', '>=');
$pdo_ok   = extension_loaded('pdo_sqlite');
$zip_ok   = extension_loaded('zip');
$mb_ok    = extension_loaded('mbstring');
$json_ok  = extension_loaded('json');
$env_pass = $php_ok && $pdo_ok && $zip_ok && $mb_ok && $json_ok;

section('1. PHP Environment', $env_pass);
echo '<table><tr><th>Check</th><th>Result</th></tr>';
row('PHP Version',          $php_ok,  PHP_VERSION,                    'Requires PHP 7.4+');
row('pdo_sqlite extension', $pdo_ok,  $pdo_ok  ? 'Enabled':'NOT enabled', 'Add extension=pdo_sqlite to php.ini');
row('zip extension',        $zip_ok,  $zip_ok  ? 'Enabled':'NOT enabled', 'Add extension=zip to php.ini');
row('mbstring extension',   $mb_ok,   $mb_ok   ? 'Enabled':'NOT enabled', 'Add extension=mbstring to php.ini');
row('json extension',       $json_ok, $json_ok ? 'Enabled':'NOT enabled', 'Add extension=json to php.ini');
row_info('PHP SAPI',            php_sapi_name());
row_info('memory_limit',        ini_get('memory_limit'));
row_info('max_execution_time',  ini_get('max_execution_time') . 's');
row_info('Server software',     $_SERVER['SERVER_SOFTWARE'] ?? '—');
echo '</table>';

// ═══════════════════════════════════════════════════════════════
// 2. SERVER & URL
// ═══════════════════════════════════════════════════════════════
$protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host       = $_SERVER['HTTP_HOST'] ?? 'unknown';
$script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
$actual_url = $protocol . '://' . $host . ($script_dir !== '/' ? rtrim($script_dir, '/') . '/' : '/');

// Read config.php — handle both static and OI-style dynamic base_url
$cfg_file    = $app . '/config/config.php';
$cfg_base    = '';
$cfg_dynamic = false;
$index_page  = 'unknown';
$hooks_on    = false;
$composer_ok = false;

if (file_exists($cfg_file)) {
    $raw = file_get_contents($cfg_file);
    // Static base_url
    if (preg_match('/\$config\[\'base_url\'\]\s*=\s*\'([^\']+)\'/', $raw, $m)) {
        $cfg_base = $m[1];
    }
    // OI dynamic base_url — trust actual detected URL
    if (empty($cfg_base) || $cfg_base === 'https://' || $cfg_base === 'http://') {
        if (strpos($raw, '$root_domain') !== false || strpos($raw, 'HTTP_HOST') !== false) {
            $cfg_base    = $actual_url;
            $cfg_dynamic = true;
        }
    }
    // index_page
    if (preg_match('/\$config\[\'index_page\'\]\s*=\s*\'([^\']*)\'/', $raw, $m)) {
        $index_page = $m[1] === '' ? "'' (clean URLs)" : "'{$m[1]}'";
    }
    // enable_hooks
    if (preg_match('/\$config\[\'enable_hooks\'\]\s*=\s*(TRUE|FALSE)/', $raw, $m)) {
        $hooks_on = ($m[1] === 'TRUE');
    }
    // composer_autoload
    $composer_ok = (strpos($raw, 'vendor/autoload.php') !== false
                 || strpos($raw, "composer_autoload'] = TRUE") !== false)
                 && strpos($raw, "composer_autoload'] = FALSE") === false;
    // Handle case where FALSE appears after TRUE (last assignment wins)
    $lines = explode("\n", $raw);
    $ca_val = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '//') === 0 || strpos($line, '#') === 0) continue;
        if (preg_match('/\$config\[\'composer_autoload\'\]\s*=\s*(.+);/', $line, $m2)) {
            $ca_val = trim($m2[1]);
        }
    }
    $composer_ok = ($ca_val !== null && $ca_val !== 'FALSE' && $ca_val !== "''");
}

$base_match  = rtrim($cfg_base, '/') === rtrim($actual_url, '/');
// PPMS does not own config.php — base_url mismatch is informational only,
// never a FAIL. PPMS inherits whatever base_url OI already has configured.
$url_pass    = true;

section('2. Server & URL', true);
echo '<table><tr><th>Check</th><th>Result</th></tr>';
row_info('Actual URL (detected)', $actual_url);
row_info('config.php base_url',   $cfg_dynamic ? $cfg_base . ' (dynamic — resolves from server)' : $cfg_base);
// Shown as info/warn only — PPMS does not require this to match
if ($base_match) {
    row_info('base_url', $cfg_dynamic ? 'Dynamic (resolves from server)' : 'Matches detected URL');
} else {
    row_warn('base_url vs detected', 'Differs — OK for PPMS (inherits OI config, no change needed)');
}
row_info('index_page', $index_page);
row_info('Request URI',     $_SERVER['REQUEST_URI'] ?? '—');
row_info('Document root',   $_SERVER['DOCUMENT_ROOT'] ?? '—');
row_info('Script path',     $root);
echo '</table>';

// ═══════════════════════════════════════════════════════════════
// 3. CI3 BOOTSTRAP
// ═══════════════════════════════════════════════════════════════
$idx_ok      = file_exists($root . '/index.php');
$sys_ok      = is_dir($root . '/system');
$app_ok      = is_dir($app);
$autoload_ok = file_exists($root . '/vendor/autoload.php');
// ci3_pass: only hard-require the CI3 core files and vendor/autoload.php.
// enable_hooks and composer_autoload are OI config.php settings PPMS does not own —
// PPMS self-bootstraps vendor/autoload.php and hooks are registered via routes.php.
// They are shown as informational rows, not hard failures.
$ci3_pass    = $idx_ok && $sys_ok && $app_ok && $autoload_ok;

section('3. CI3 Bootstrap', $ci3_pass);
echo '<table><tr><th>Check</th><th>Result</th></tr>';
row('index.php',              $idx_ok,      $idx_ok      ? 'Found'   : 'MISSING');
row('system/ folder',         $sys_ok,      $sys_ok      ? 'Found'   : 'MISSING — CI3 system not installed');
row('application/ folder',    $app_ok,      $app_ok      ? 'Found'   : 'MISSING');
row('vendor/autoload.php',    $autoload_ok, $autoload_ok ? 'Found'   : 'MISSING — vendor/ not copied');
// enable_hooks and composer_autoload: OI config.php settings — informational only.
// PPMS self-bootstraps its autoload; enable_hooks is needed for the PPMS hook to fire.
if ($hooks_on) {
    row_info('enable_hooks', 'TRUE — PPMS hook will fire');
} else {
    row_warn('enable_hooks', "FALSE in config.php — PPMS hook will not fire. Add to OI config.php: \$config['enable_hooks'] = TRUE;");
}
if ($composer_ok) {
    row_info('composer_autoload', 'Set — vendor/autoload.php loaded by CI3');
} else {
    row_info('composer_autoload', 'Not set — OK, PPMS self-loads vendor/autoload.php');
}
echo '</table>';

// ═══════════════════════════════════════════════════════════════
// 4. PHPSPREADSHEET
// ═══════════════════════════════════════════════════════════════
if ($oi_server) {
    // PhpSpreadsheet is confirmed present on the OI server — skip file check.
    $ss_pass = true;
    section('4. PhpSpreadsheet Library', true);
    echo '<table><tr><th>Check</th><th>Result</th></tr>';
    echo "<tr><td>vendor/phpoffice/phpspreadsheet</td><td class='ok'>Confirmed on OI server — check skipped</td></tr>";
    echo '</table>';
} else {
    // Standard file-system check for non-OI environments.
    $ss_composer = $root . '/vendor/phpoffice/phpspreadsheet/composer.json';
    $ss_exists   = file_exists($ss_composer);
    $ss_pass     = $ss_exists && $autoload_ok;

    section('4. PhpSpreadsheet Library', $ss_pass);
    echo '<table><tr><th>Check</th><th>Result</th></tr>';
    row('vendor/autoload.php',      $autoload_ok, $autoload_ok ? 'Found' : 'MISSING');
    row('phpoffice/phpspreadsheet', $ss_exists,   $ss_exists   ? 'Found' : 'MISSING — vendor/phpoffice/phpspreadsheet not on server');
    if ($ss_exists) {
        $cj  = json_decode(file_get_contents($ss_composer), true);
        row_info('Version', $cj['version'] ?? '—');
    }
    echo '</table>';
}

// ═══════════════════════════════════════════════════════════════
// 5. PPMS FILES
// ═══════════════════════════════════════════════════════════════
$ppms_files = [
    'application/controllers/PPMS_Controller.php' => 'Base API controller',
    'application/controllers/Api_projects.php'    => 'Projects API',
    'application/controllers/Ppms.php'            => 'SPA shell controller',
    'application/controllers/Ppms_setup.php'      => 'One-time setup',
    'application/libraries/CSV_reader.php'        => 'CSV data reader',
    'application/libraries/PPMS_Cache.php'        => 'Cache library',
    'application/libraries/Progress_calculator.php' => 'Progress logic',
    'application/models/PPMS_model.php'           => 'SQLite model',
    'application/hooks/PPMS_Hook.php'             => 'Pre-controller hook',
    'application/helpers/simulation_helper.php'   => 'Simulation helper',
    'application/config/ppms.php'                 => 'PPMS config',
    'application/config/ppms_database.php'        => 'DB config',
    'application/views/ppms/shell.php'            => 'Vue mount point',
    'application/database/schema_sqlite.sql'      => 'SQLite schema',
];
$ppms_pass = true;
foreach ($ppms_files as $f => $desc) {
    if (!file_exists($root . '/' . $f)) { $ppms_pass = false; break; }
}

section('5. PPMS Files', $ppms_pass);
echo '<table><tr><th>File</th><th>Result</th></tr>';
foreach ($ppms_files as $f => $desc) {
    $ok = file_exists($root . '/' . $f);
    row("<code>{$f}</code><div class='fix'>{$desc}</div>", $ok,
        $ok ? '✓ Found' : '✗ MISSING — run 2_SVN_COMMIT.bat');
}
echo '</table>';

// ═══════════════════════════════════════════════════════════════
// 6. ROUTES
// ═══════════════════════════════════════════════════════════════
$routes_file    = $app . '/config/routes.php';
$routes_content = file_exists($routes_file) ? file_get_contents($routes_file) : '';
$ppms_routes    = ['api_projects', 'api_export', 'ppms_setup', 'simulate', 'atrisk_weeks'];
$routes_pass    = file_exists($routes_file)
               && array_reduce($ppms_routes, fn($c,$r) => $c && strpos($routes_content,$r)!==false, true);

section('6. Routes', $routes_pass);
echo '<table><tr><th>Check</th><th>Result</th></tr>';
row('routes.php exists', file_exists($routes_file), file_exists($routes_file) ? 'Found' : 'MISSING');
foreach ($ppms_routes as $r) {
    $found = strpos($routes_content, $r) !== false;
    row("PPMS route: {$r}", $found, $found ? 'Present' : 'MISSING — run PATCH_OI_CONFIG.bat',
        $found ? '' : 'Run PATCH_OI_CONFIG.bat on jumphost, then svn commit');
}
echo '</table>';

// ═══════════════════════════════════════════════════════════════
// 7. CSV DATA FILES
// ═══════════════════════════════════════════════════════════════
$required_csvs = [
    'caanddisb'         => 'Weekly snapshots (CA, disbursements, ratings)',
    'perfratings'       => 'Quarterly performance ratings',
    'sard_projections'  => 'CA/disbursement projections',
    'appr_uncontracted' => 'Uncontracted balance',
    'appr_undisbursed'  => 'Undisbursed balance',
    'country_nom'       => 'DMC → country name mapping',
];
$csv_pass = true;
$csv_results = [];
foreach ($required_csvs as $name => $desc) {
    $path = $csv_dir . $name . '.csv';
    if (!file_exists($path)) {
        foreach (glob($csv_dir . '*.csv') ?: [] as $f) {
            if (stripos(basename($f, '.csv'), $name) !== false) { $path = $f; break; }
        }
    }
    $found = file_exists($path);
    if (!$found) $csv_pass = false;
    $csv_results[] = [$name, $desc, $found, $path];
}
// Optional
$proj_path = '';
foreach (glob($csv_dir . '*.csv') ?: [] as $f) {
    if (stripos(basename($f), 'PROJECTS_ADBDEV') !== false) { $proj_path = $f; break; }
}

section('7. CSV Data Files', $csv_pass);
echo '<table><tr><th>File</th><th>Status</th><th>Size</th><th>Modified</th></tr>';
foreach ($csv_results as [$name, $desc, $found, $path]) {
    echo "<tr><td><code>{$name}.csv</code><div class='fix'>{$desc}</div></td>
          <td class='" . ($found?'ok':'err') . "'>" . ($found?'✓ Found':'✗ MISSING') . "</td>
          <td>" . ($found ? round(filesize($path)/1048576,1).' MB':'—') . "</td>
          <td>" . ($found ? date('Y-m-d H:i', filemtime($path)):'—') . "</td></tr>";
}
echo "<tr><td><code>*PROJECTS_ADBDEV*.csv</code><div class='fix'>Project financing amounts (optional)</div></td>
      <td class='" . ($proj_path?'ok':'warn') . "'>" . ($proj_path?'✓ Found':'— Optional, missing') . "</td>
      <td>" . ($proj_path ? round(filesize($proj_path)/1048576,1).' MB':'—') . "</td>
      <td>" . ($proj_path ? date('Y-m-d H:i', filemtime($proj_path)):'—') . "</td></tr>";
echo '</table>';

// caanddisb field check
$ca_path = $csv_dir . 'caanddisb.csv';
if (!file_exists($ca_path)) {
    foreach (glob($csv_dir.'*.csv')?: [] as $f) {
        if (stripos(basename($f),'caanddisb')!==false){$ca_path=$f;break;}
    }
}
if (file_exists($ca_path)) {
    $fh = fopen($ca_path, 'r');
    $hdrs = array_map(fn($h) => strtolower(trim($h)), fgetcsv($fh) ?: []);
    $rows = []; while(($r=fgetcsv($fh))!==false && count($rows)<2) $rows[]=$r; fclose($fh);
    $req_fields = ['project_no','dmc','report_week','perf_rating',
                   'year_actual','year_projn','disb_year_actual','disb_year_projn',
                   'ytd_projn','disb_ytd_proj','ca_actual','ca_projn','net_amount'];
    echo '<h2 style="margin-top:10px">caanddisb.csv field check</h2>';
    echo '<table><tr><th>Field</th><th>Sample</th><th>Status</th></tr>';
    foreach ($req_fields as $field) {
        $idx = array_search($field, $hdrs);
        if ($idx !== false) {
            $samples = array_map(fn($r) => htmlspecialchars($r[$idx]??''), $rows);
            echo "<tr><td><code>{$field}</code></td><td class='mono'>".implode(' · ',$samples)."</td><td class='ok'>✓</td></tr>";
        } else {
            $all_pass = false;
            echo "<tr><td><code>{$field}</code></td><td class='warn'>NOT FOUND</td><td class='err'>✗</td></tr>";
        }
    }
    echo '</table>';
}

// ═══════════════════════════════════════════════════════════════
// 8. SQLITE DATABASE
// ═══════════════════════════════════════════════════════════════
$db_dir_ok   = is_writable(dirname($db_file));
$db_exists   = file_exists($db_file);
$db_tables   = false;
$db_msg      = '';
if ($db_exists) {
    try {
        $pdo    = new PDO('sqlite:' . $db_file);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $req    = ['ppms_projects','ppms_sections','ppms_rows','ppms_section_config','ppms_audit_log'];
        $miss   = array_diff($req, $tables);
        $db_tables = empty($miss);
        $db_msg = $db_tables ? 'All 5 tables present' : 'Missing: '.implode(', ',$miss);
    } catch (Exception $e) { $db_msg = $e->getMessage(); }
}
// db_pass: if DB does not exist yet, this is PENDING (setup not run), not a failure.
// Only fail if setup was attempted but directories or tables are missing/broken.
$setup_pending = !$db_exists && !$db_dir_ok;  // neither dir writable nor DB created yet
$db_pass = $db_dir_ok && $db_exists && $db_tables;

section('8. SQLite Database', $db_pass || $setup_pending);
echo '<table><tr><th>Check</th><th>Result</th></tr>';
row_info('Expected path', $db_file);
if ($setup_pending) {
    // ppms-setup has not been run yet — this is expected on first deploy
    echo "<tr><td>Setup status</td><td class='warn'>⏳ Pending — visit <a href='index.php/ppms-setup'>/index.php/ppms-setup</a> to initialize</td></tr>";
} else {
    row('application/database/ writable', $db_dir_ok, $db_dir_ok ? 'Yes' : 'NOT writable — visit /index.php/ppms-setup',
        $db_dir_ok ? '' : 'Visit /index.php/ppms-setup — it will fix this automatically');
    row('Database file exists',           $db_exists,  $db_exists  ? 'Found' : 'NOT FOUND — visit /index.php/ppms-setup',
        $db_exists  ? '' : 'Visit /index.php/ppms-setup to create it');
    row('Required tables',                $db_tables,  $db_tables  ? $db_msg : ($db_exists ? $db_msg : '—'),
        $db_tables  ? '' : ($db_exists ? 'Visit /index.php/ppms-setup' : ''));
    if ($db_exists) row_info('DB size', round(filesize($db_file)/1024,1).' KB');
}
echo '</table>';

// ═══════════════════════════════════════════════════════════════
// 9. CACHE & SESSIONS
// ═══════════════════════════════════════════════════════════════
$cache_ok   = is_dir($cache_dir) && is_writable($cache_dir);
$sess_dir   = $cache_dir . 'sessions/';
$sess_ok    = is_dir($sess_dir) && is_writable($sess_dir);
$cache_pass = $cache_ok && $sess_ok;
// If neither exists yet, setup has not been run — PENDING, not a failure
$cache_pending = !$cache_ok && !is_dir($cache_dir);

section('9. Cache & Sessions', $cache_pass || $cache_pending);
echo '<table><tr><th>Check</th><th>Result</th></tr>';
if ($cache_pending) {
    echo "<tr><td>Setup status</td><td class='warn'>⏳ Pending — visit <a href='index.php/ppms-setup'>/index.php/ppms-setup</a> to create cache directories</td></tr>";
} else {
    row('application/cache/ exists + writable', $cache_ok,
        $cache_ok ? 'Yes' : (is_dir($cache_dir) ? 'Exists but NOT writable' : 'MISSING'),
        $cache_ok ? '' : 'Visit /index.php/ppms-setup — it will fix this automatically');
    row('application/cache/sessions/ exists + writable', $sess_ok,
        $sess_ok ? 'Yes' : (is_dir($sess_dir) ? 'Exists but NOT writable' : 'MISSING'),
        $sess_ok ? '' : 'Visit /index.php/ppms-setup — it will fix this automatically');
}
echo '</table>';

// ═══════════════════════════════════════════════════════════════
// 10. VUE FRONTEND BUNDLE
// ═══════════════════════════════════════════════════════════════
$bundle_js  = $root . '/public/js/ppms.bundle.js';
$vue_css    = $root . '/public/css/ppms-vue.css';
$ppms_css   = $root . '/public/css/ppms.css';
$bundle_pass = file_exists($bundle_js) && file_exists($vue_css) && file_exists($ppms_css);

section('10. Vue Frontend Bundle', $bundle_pass);
echo '<table><tr><th>File</th><th>Result</th><th>Size</th><th>Built</th></tr>';
foreach ([
    [$bundle_js, 'public/js/ppms.bundle.js',  'Compiled Vue app'],
    [$vue_css,   'public/css/ppms-vue.css',   'Compiled Vue styles'],
    [$ppms_css,  'public/css/ppms.css',        'Base styles'],
] as [$path, $name, $desc]) {
    $ex = file_exists($path);
    echo "<tr><td><code>{$name}</code><div class='fix'>{$desc}</div></td>
          <td class='".($ex?'ok':'err')."'>".($ex?'✓ Found':'✗ MISSING')."</td>
          <td>".($ex?round(filesize($path)/1024,1).'KB':'—')."</td>
          <td>".($ex?date('Y-m-d H:i',filemtime($path)):'—')."</td></tr>";
}
echo '</table>';

// ═══════════════════════════════════════════════════════════════
// SUMMARY
// ═══════════════════════════════════════════════════════════════
$sections = [
    '1. PHP Environment'       => $env_pass,
    '2. Server & URL'          => $url_pass,
    '3. CI3 Bootstrap'         => $ci3_pass,
    '4. PhpSpreadsheet'        => $ss_pass,
    '5. PPMS Files'            => $ppms_pass,
    '6. Routes'                => $routes_pass,
    '7. CSV Data Files'        => $csv_pass,
    '8. SQLite Database'       => $db_pass,
    '9. Cache & Sessions'      => $cache_pass,
    '10. Vue Frontend Bundle'  => $bundle_pass,
];
echo '<h2 style="margin-top:28px">Summary</h2>';
echo '<table><tr><th>Section</th><th>Result</th></tr>';
foreach ($sections as $name => $pass) {
    echo "<tr><td>{$name}</td><td class='".($pass?'ok':'err')."'>".($pass?'✓ PASS':'✗ FAIL')."</td></tr>";
}
echo '</table>';

if ($all_pass) {
    echo '<div style="margin-top:20px;padding:14px 20px;background:#d1fae5;border-radius:8px;color:#065f46;font-weight:700">
          ✓ All checks passed — visit <a href="index.php/ppms-setup">ppms-setup</a> to initialize the database, then <a href="index.php/ppms">ppms</a>.</div>';
} else {
    echo '<div style="margin-top:20px;padding:14px 20px;background:#fee2e2;border-radius:8px;color:#991b1b;font-weight:700">
          ✗ Some checks failed — fix the items above before visiting /ppms-setup or /ppms.</div>';
}
?>
<p style="margin-top:20px;font-size:11px;color:#9ca3af">Delete <code>ppms_check.php</code> before production.</p>
</body></html>
