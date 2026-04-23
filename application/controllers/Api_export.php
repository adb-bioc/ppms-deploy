<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Api_export Controller
 *
 * Extends CI_Controller directly — zero dependency on PPMS_Controller.
 * Bootstraps its own libraries so it works regardless of autoload config.
 *
 * Install: composer require phpoffice/phpspreadsheet:"^2.0" --no-scripts --ignore-platform-req=ext-gd
 * Template: place PPMS_template.xlsx in application/templates/
 *
 * Routes:
 *   GET /api/export/progress/{job_id}  → progress($job_id)
 *   GET /api/export/project/{id}       → project($id)
 *   GET /api/export/country/{dmc}      → country($dmc)
 */
class Api_export extends CI_Controller
{
    private $template_path;
    private $current_user  = null;
    private $effective_dmc = '';

    public function __construct()
    {
        parent::__construct();

        $this->config->load('ppms', true);
        $this->load->library('session');
        $this->load->library(['ppms_cache', 'csv_reader', 'progress_calculator']);
        $this->load->model('ppms_model');
        $this->load->helper('url');

        $this->template_path = APPPATH . 'templates/PPMS_template.xlsx';

        // Resolve user from session (same logic as PPMS_Controller)
        $key          = defined('PPMS_SESSION_KEY') ? PPMS_SESSION_KEY : 'simulation_session';
        $session_data = $this->session->userdata($key) ?? [];
        $eff          = $session_data['effective_user'] ?? null;
        if (!empty($eff)) {
            $this->current_user  = $eff;
            $role                = $eff['role']    ?? '';
            $country             = $eff['country'] ?? '';
            $this->effective_dmc = ($role === 'admin') ? 'ALL' : strtoupper($country);
        }
    }

    private function require_ppms_user()
    {
        if (empty($this->current_user)) {
            $this->_json_error('No active session. Select a simulation profile.', 401);
        }
    }

    private function assert_project_dmc(array $project)
    {
        if (($this->current_user['role'] ?? '') === 'admin') return;
        if (strtoupper($project['dmc']) !== strtoupper($this->effective_dmc)) {
            $this->_json_error('Access denied: project not in your DMC.', 403);
        }
    }

    private function _json_error($message, $status = 400)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $message]);
        exit;
    }

    // -------------------------------------------------------------------------
    // GET /api/export/project/{id}
    // -------------------------------------------------------------------------
    public function project($project_no)
    {
        @ini_set('memory_limit', '-1');
        @ini_set('max_execution_time', '120');

        $this->require_ppms_user();

        // Release session lock so progress polls are not blocked
        session_write_close();

        $job_id = $this->input->get('job_id') ?: null;
        if ($job_id) $this->_progress_init($job_id, 4);

        $this->_progress_write(5, 'Loading project data…');
        $project = $this->csv_reader->get_project($project_no);
        if (!$project) $this->_json_error('Project not found.', 404);
        $this->assert_project_dmc($project);

        $this->_progress_write(20, 'Loading template…');
        $this->_boot_spreadsheet();

        $wb             = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->template_path);
        $template_sheet = $wb->getSheetByName('<Approval numbers>');

        if (!$template_sheet) {
            $this->_json_error('PPMS template sheet not found. Check template file.', 500);
        }

        $this->_progress_write(50, 'Populating sections…');

        // Build export_data for single project (same preload path as country export)
        $export_data = $this->_preload_country_export($project['dmc'], [$project]);

        // Clone template sheet for this project
        $ws = clone $template_sheet;
        $ws->setTitle($this->_make_sheet_name($project));
        $ws->setShowGridLines(false);
        $wb->addSheet($ws);

        // Populate
        $this->_populate_project_sheet($ws, $project_no, $project, $export_data);

        // Remove blank template sheet
        $wb->removeSheetByIndex($wb->getIndex($template_sheet));

        // Move Portfolio to front
        $this->_move_portfolio_front($wb);

        $this->_progress_write(90, 'Writing file…');
        $this->_stream_xlsx($wb, 'PPMS_' . $project_no . '_' . date('Ymd'));
    }

    // -------------------------------------------------------------------------
    // GET /api/export/country/{dmc}
    // -------------------------------------------------------------------------
    public function country($dmc)
    {
        // Excel generation is memory-intensive — remove PHP limits for this request
        @ini_set('memory_limit', '-1');
        @ini_set('max_execution_time', '0');

        $this->require_ppms_user();
        session_write_close();

        $dmc    = strtoupper($dmc);
        $job_id = $this->input->get('job_id') ?: null;

        if ($this->current_user['role'] !== 'admin' && $dmc !== strtoupper($this->effective_dmc)) {
            $this->_json_error('Access denied: you can only export your own DMC.', 403);
        }

        $projects = $this->csv_reader->get_projects($dmc);
        if (empty($projects)) $this->_json_error('No projects found for DMC: ' . $dmc, 404);

        $project_count = count($projects);
        $total         = $project_count + 2;
        if ($job_id) $this->_progress_init($job_id, $total);

        // ── Preload all CSV data in single passes (mirrors Python load_all_csvs) ──
        // Builds lookup maps before PhpSpreadsheet loop so:
        // 1. Each CSV file is read ONCE regardless of project count
        // 2. Per-project lookup is O(1) hash access
        // 3. Large arrays are freed before worksheet cloning begins
        $start_time = microtime(true);
        $this->_progress_write(2, 'Loading data… (' . $project_count . ' projects)');
        $export_data = $this->_preload_country_export($dmc, $projects);

        // Free large CSV arrays from CSV_reader mem cache before PhpSpreadsheet
        // mem['caanddisb'] holds the full normalized array from _load_normalized()
        // runtime_cache is private and can't be unset externally — that's fine,
        // the snapshot is much smaller than the full array.
        unset($this->csv_reader->mem['caanddisb']);
        unset($this->csv_reader->mem['projects_adbdev']); // freed after preload step 4
        gc_collect_cycles();

        $this->_progress_write(5, 'Loading template…');
        $this->_boot_spreadsheet();

        $wb             = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->template_path);
        $template_sheet = $wb->getSheetByName('<Approval numbers>');

        if (!$template_sheet) {
            $this->_json_error('PPMS template sheet not found.', 500);
        }

        $used_names    = [];
        $project_count = count($projects);

        // One sheet per project — uses preloaded lookup maps (O(1) per project)
        foreach ($projects as $idx => $project) {
            $project_no = $project['project_no'];
            $sheet_name = $this->_unique_sheet_name($project, $used_names);
            $used_names[] = $sheet_name;

            $pct     = (int) round((($idx + 1) / $total) * 85) + 5;
            $elapsed = microtime(true) - $start_time;
            $per_sec = $elapsed / max(1, $idx + 1);
            $remain  = $per_sec > 0 ? (int)(($project_count - $idx - 1) * $per_sec) : 0;
            $eta     = $remain > 60
                ? '~' . (int)ceil($remain / 60) . ' min left'
                : ($remain > 0 ? '~' . $remain . 's left' : '');
            $this->_progress_write($pct,
                'Processing ' . $project_no . ' (' . ($idx + 1) . '/' . $project_count . ')'
                . ($eta ? ' — ' . $eta : '') . '…'
            );

            $ws = clone $template_sheet;
            $ws->setTitle($sheet_name);
            $ws->setShowGridLines(false);
            $wb->addSheet($ws);

            $this->_populate_project_sheet($ws, $project_no, $project, $export_data);

            unset($ws);

            if (($idx + 1) % 10 === 0) gc_collect_cycles();
        }

        // Remove blank template sheet
        $wb->removeSheetByIndex($wb->getIndex($template_sheet));

        // Populate Portfolio sheet
        $this->_progress_write(92, 'Building portfolio sheet…');
        $portfolio = $wb->getSheetByName('Portfolio');
        if ($portfolio) {
            $portfolio->setShowGridLines(false);
            $this->_populate_portfolio($portfolio, $projects, $dmc, $used_names, $export_data);
            $wb->setActiveSheetIndex($wb->getIndex($portfolio));
            $this->_move_portfolio_front($wb);
        }

        $this->_progress_write(97, 'Writing file…');
        $this->_stream_xlsx($wb, 'PPMS_Portfolio_' . $dmc . '_' . date('Ymd'));
    }

    // -------------------------------------------------------------------------
    // POST /api/export/filtered
    // Exports exactly the project_nos[] sent by the frontend — respects all
    // Vue-side filters (region, division, status, DSR, text search, DMC).
    // Body: { project_nos: [...], label: '...', job_id: '...' }
    // -------------------------------------------------------------------------
    public function filtered()
    {
        @ini_set('memory_limit', '-1');
        @ini_set('max_execution_time', '0');

        $this->require_ppms_user();
        session_write_close();

        $body        = json_decode($this->input->raw_input_stream, true) ?: [];
        $project_nos = array_values(array_filter(array_map('strval', $body['project_nos'] ?? [])));
        $label       = preg_replace('/[^A-Za-z0-9_\- ]/', '', $body['label'] ?? 'Filtered');
        $job_id      = $body['job_id'] ?? null;

        if (empty($project_nos)) {
            $this->_json_error('No project_nos provided.', 400);
        }

        $projects = $this->csv_reader->get_projects_by_nos($project_nos);

        // PTL: every project must belong to their own DMC
        if ($this->current_user['role'] !== 'admin') {
            $allowed_dmc = strtoupper($this->effective_dmc);
            foreach ($projects as $p) {
                if (strtoupper($p['dmc']) !== $allowed_dmc) {
                    $this->_json_error('Access denied: project ' . $p['project_no'] . ' is outside your DMC.', 403);
                }
            }
        }

        $project_count = count($projects);

        if (empty($projects)) $this->_json_error('No matching projects found.', 404);

        $total = $project_count + 2;
        if ($job_id) $this->_progress_init($job_id, $total);

        $start_time = microtime(true);
        $this->_progress_write(2, 'Loading data… (' . $project_count . ' projects)');

        $export_data = $this->_preload_filtered_export($project_nos, $projects);

        unset($this->csv_reader->mem['caanddisb']);
        unset($this->csv_reader->mem['projects_adbdev']);
        gc_collect_cycles();

        $this->_progress_write(5, 'Loading template…');
        $this->_boot_spreadsheet();

        $wb             = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->template_path);
        $template_sheet = $wb->getSheetByName('<Approval numbers>');

        if (!$template_sheet) {
            $this->_json_error('PPMS template sheet not found.', 500);
        }

        $used_names = [];

        foreach ($projects as $idx => $project) {
            $project_no = $project['project_no'];
            $sheet_name = $this->_unique_sheet_name($project, $used_names);
            $used_names[] = $sheet_name;

            $pct     = (int) round((($idx + 1) / $total) * 85) + 5;
            $elapsed = microtime(true) - $start_time;
            $per_sec = $elapsed / max(1, $idx + 1);
            $remain  = $per_sec > 0 ? (int)(($project_count - $idx - 1) * $per_sec) : 0;
            $eta     = $remain > 60
                ? '~' . (int)ceil($remain / 60) . ' min left'
                : ($remain > 0 ? '~' . $remain . 's left' : '');
            $this->_progress_write($pct,
                'Processing ' . $project_no . ' (' . ($idx + 1) . '/' . $project_count . ')'
                . ($eta ? ' — ' . $eta : '') . '…'
            );

            $ws = clone $template_sheet;
            $ws->setTitle($sheet_name);
            $ws->setShowGridLines(false);
            $wb->addSheet($ws);

            $this->_populate_project_sheet($ws, $project_no, $project, $export_data);

            unset($ws);
            if (($idx + 1) % 10 === 0) gc_collect_cycles();
        }

        $wb->removeSheetByIndex($wb->getIndex($template_sheet));

        $this->_progress_write(92, 'Building portfolio sheet…');
        $portfolio = $wb->getSheetByName('Portfolio');
        if ($portfolio) {
            // Derive DMC for portfolio header — use first project's DMC or 'MULTI'
            $dmcs = array_unique(array_column($projects, 'dmc'));
            $dmc_label = count($dmcs) === 1 ? $dmcs[0] : 'MULTI';
            $portfolio->setShowGridLines(false);
            $this->_populate_portfolio($portfolio, $projects, $dmc_label, $used_names, $export_data);
            $wb->setActiveSheetIndex($wb->getIndex($portfolio));
            $this->_move_portfolio_front($wb);
        }

        $this->_progress_write(97, 'Writing file…');
        $safe_label = str_replace([' ', '/'], ['_', '-'], $label);
        $this->_stream_xlsx($wb, 'PPMS_' . $safe_label . '_' . date('Ymd'));
    }

    /**
     * Preload export data for a filtered set of project_nos.
     * Same as _preload_country_export but uses get_history_by_nos() instead of
     * get_country_history() — works across multiple DMCs.
     */
    private function _preload_filtered_export(array $project_nos, array $projects)
    {
        $pid_set = array_flip($project_nos);

        // ── 1. Snapshot — $projects already contains the deduplicated snapshot rows
        $snap = [];
        foreach ($projects as $p) {
            $snap[$p['project_no']] = [$p];
        }

        // ── 2. Full history for this project set ─────────────────────────────
        $history = $this->csv_reader->get_history_by_nos($project_nos);

        // ── 3. Ratings (perfratings.csv) ─────────────────────────────────────
        $ratings = [];
        $all_ratings = $this->csv_reader->_load_normalized_public('perfratings');
        foreach ($all_ratings as $r) {
            $pid = $r['project_no'];
            if (!isset($pid_set[$pid])) continue;
            if (!isset($ratings[$pid]) || strcmp($r['report_period'], $ratings[$pid]['report_period']) > 0) {
                $ratings[$pid] = $r;
            }
        }
        unset($all_ratings);

        // ── 4. Financing amounts (projects_adbdev.csv) ───────────────────────
        $financing = [];
        $all_fin   = $this->csv_reader->_load_normalized_public('projects_adbdev');
        $fin_raw   = [];
        foreach ($all_fin as $r) {
            $pid = $r['proj_number'];
            if (!isset($pid_set[$pid])) continue;
            $src = $r['financing_source_cd'];
            $key = $src . '|' . $r['fund_type'];
            if (!isset($fin_raw[$pid][$key])) {
                $fin_raw[$pid][$key] = ['src' => $src, 'amount' => (float)$r['total_proj_fin_amount']];
            }
        }
        unset($all_fin);
        foreach ($fin_raw as $pid => $groups) {
            $sums = [];
            foreach ($groups as $g) {
                $sums[$g['src']] = ($sums[$g['src']] ?? 0.0) + $g['amount'];
            }
            $adb = ($sums['ADB']         ?? 0) / 1_000_000;
            $ctr = ($sums['COUNTERPART'] ?? 0) / 1_000_000;
            $cof = ($sums['COFINANCING'] ?? 0) / 1_000_000;
            $nums = array_filter([$adb, $ctr, $cof], fn($v) => $v > 0);
            $financing[$pid] = [
                'adb'         => $adb > 0 ? round($adb, 2) : '',
                'counterpart' => $ctr > 0 ? round($ctr, 2) : '',
                'cofinancing' => $cof > 0 ? round($cof, 2) : '',
                'total'       => !empty($nums) ? round(array_sum($nums), 2) : '',
            ];
        }
        unset($fin_raw);

        // ── 5. Consecutive ratings (perfratings VALIDATED) + quarterly CAD ─────
        $consecutive   = [];
        $quarterly_cad = [];
        $all_pf_history_f = $this->csv_reader->_load_normalized_public('perfratings');
        $pf_by_pid_f = [];
        foreach ($all_pf_history_f as $r) {
            $pid = $r['project_no'];
            if (!isset($pid_set[$pid])) continue;
            $status = strtoupper(trim($r['status'] ?? ''));
            if ($status !== '' && !in_array($status, ['VALIDATED','ENDORSED'], true)) continue;
            $pf_by_pid_f[$pid][] = $r;
        }
        unset($all_pf_history_f);
        foreach ($project_nos as $pid) {
            $rows = $history[$pid] ?? [];
            $pf_rows = $pf_by_pid_f[$pid] ?? [];
            $consecutive[$pid]   = !empty($pf_rows)
                ? $this->_compute_consecutive_from_perfratings($pf_rows, 5)
                : $this->_compute_consecutive_ratings($rows, 5);
            $quarterly_cad[$pid] = $this->_compute_quarterly_cad($rows);
        }
        unset($pf_by_pid_f);

        // ── 6. Section D ratios ──────────────────────────────────────────────
        $section_d = [];
        foreach ($project_nos as $pid) {
            $section_d[$pid] = $this->csv_reader->get_section_d_ratios($pid);
        }

        unset($history);
        gc_collect_cycles();

        return compact('snap', 'ratings', 'financing', 'consecutive', 'quarterly_cad', 'section_d');
    }
    // Mirrors Python's load_all_csvs() + per-project filtering approach.
    // Returns lookup maps: project_no → computed result
    // =========================================================================

    private function _preload_country_export($dmc, array $projects)
    {
        $project_nos = array_column($projects, 'project_no');
        $pid_set     = array_flip($project_nos);  // for O(1) membership check

        // ── 1. Country snapshot (global latest date for DMC) ─────────────────
        // Matches Python: df_cad_latest = caanddisb[dmc==DMC][report_week==max]
        // Scoped to $pid_set (already filtered by get_projects() — excludes
        // restricted DMCs, cofin types, programme-based modalities) so the
        // Portfolio sheet row count matches the project sheet count exactly.
        $snap_rows = $this->csv_reader->get_country_snapshot($dmc);
        $snap = [];
        foreach ($snap_rows as $r) {
            $pid = $r['project_no'];
            if (isset($pid_set[$pid])) $snap[$pid][] = $r;
        }

        // ── 2. Full caanddisb history for this DMC's projects ─────────────────
        // Used by get_consecutive_ratings + get_quarterly_cad
        // Single pass, DMC-filtered, cached — mirrors Python's in-memory filter
        $history = $this->csv_reader->get_country_history($dmc);

        // ── 3. Ratings (perfratings.csv) ──────────────────────────────────────
        $ratings = [];
        $all_ratings = $this->csv_reader->_load_normalized_public('perfratings');
        foreach ($all_ratings as $r) {
            $pid = $r['project_no'];
            if (!isset($pid_set[$pid])) continue;
            // Keep latest report_period per project
            if (!isset($ratings[$pid]) || strcmp($r['report_period'], $ratings[$pid]['report_period']) > 0) {
                $ratings[$pid] = $r;
            }
        }
        unset($all_ratings);

        // ── 4. Financing amounts (projects_adbdev.csv) ────────────────────────
        $financing = [];
        $all_fin = $this->csv_reader->_load_normalized_public('projects_adbdev');
        // Group by proj_number → financing_source_cd logic (mirrors Python exactly)
        $fin_raw = [];
        foreach ($all_fin as $r) {
            $pid = $r['proj_number'];
            if (!isset($pid_set[$pid])) continue;
            $src  = $r['financing_source_cd'];
            $key  = $src . '|' . $r['fund_type'];
            if (!isset($fin_raw[$pid][$key])) {
                $fin_raw[$pid][$key] = ['src' => $src, 'amount' => (float)$r['total_proj_fin_amount']];
            }
        }
        unset($all_fin);
        foreach ($fin_raw as $pid => $groups) {
            $sums = [];
            foreach ($groups as $g) {
                $sums[$g['src']] = ($sums[$g['src']] ?? 0.0) + $g['amount'];
            }
            $adb = ($sums['ADB']         ?? 0) / 1_000_000;
            $ctr = ($sums['COUNTERPART'] ?? 0) / 1_000_000;
            $cof = ($sums['COFINANCING'] ?? 0) / 1_000_000;
            $nums = array_filter([$adb, $ctr, $cof], fn($v) => $v > 0);
            $financing[$pid] = [
                'adb'         => $adb > 0 ? round($adb, 2) : '',
                'counterpart' => $ctr > 0 ? round($ctr, 2) : '',
                'cofinancing' => $cof > 0 ? round($cof, 2) : '',
                'total'       => !empty($nums) ? round(array_sum($nums), 2) : '',
            ];
        }
        unset($fin_raw);

        // ── 5. Consecutive ratings (perfratings VALIDATED) + quarterly CAD ─────
        // OI-aligned: use perfratings.csv quarterly history (not caanddisb weekly)
        $consecutive   = [];
        $quarterly_cad = [];
        // Load perfratings history for all projects in one pass
        $all_pf_history = $this->csv_reader->_load_normalized_public('perfratings');
        $pf_by_pid = [];
        foreach ($all_pf_history as $r) {
            $pid = $r['project_no'];
            if (!isset($pid_set[$pid])) continue;
            $status = strtoupper(trim($r['status'] ?? ''));
            if ($status !== '' && !in_array($status, ['VALIDATED','ENDORSED'], true)) continue;
            $pf_by_pid[$pid][] = $r;
        }
        unset($all_pf_history);
        foreach ($project_nos as $pid) {
            $rows = $history[$pid] ?? [];
            // OI-aligned consecutive: from perfratings VALIDATED quarterly records
            $pf_rows = $pf_by_pid[$pid] ?? [];
            $consecutive[$pid]   = !empty($pf_rows)
                ? $this->_compute_consecutive_from_perfratings($pf_rows, 5)
                : $this->_compute_consecutive_ratings($rows, 5); // fallback to caanddisb
            // Quarterly CAD (still from caanddisb — correct source for C section)
            $quarterly_cad[$pid] = $this->_compute_quarterly_cad($rows);
        }
        unset($pf_by_pid);

        // ── 6. Section D ratios ───────────────────────────────────────────────
        // Uses sard + caanddisb snapshot + uncontracted/undisbursed
        // These methods already have per-request caching so call them directly
        $section_d = [];
        foreach ($project_nos as $pid) {
            $section_d[$pid] = $this->csv_reader->get_section_d_ratios($pid);
        }

        // Free large caanddisb history from memory — no longer needed
        unset($history);
        gc_collect_cycles();

        return compact('snap', 'ratings', 'financing', 'consecutive', 'quarterly_cad', 'section_d');
    }

    /**
     * Stream caanddisb.csv once, grouping rows by project_no for only the
     * projects in $pid_set. Keeps ALL history (not just latest snapshot).
     */
    private function _stream_caanddisb_for_projects(array $pid_set)
    {
        $filepath = $this->csv_reader->_resolve_filepath_public('caanddisb');
        if (!$filepath) return [];

        $fh = @fopen($filepath, 'r');
        if (!$fh) return [];

        $bom    = fread($fh, 3);
        $is_bom = ($bom === "\xEF\xBB\xBF");
        $is_cp  = !$is_bom && !mb_detect_encoding($bom, 'UTF-8', true);
        fseek($fh, $is_bom ? 3 : 0);

        $header_row = fgetcsv($fh, 0, ',');
        if (!$header_row) { fclose($fh); return []; }
        $headers = array_map(fn($h) => strtolower(trim(str_replace([' ','-'], '_', $h))), $header_row);
        $pid_idx = array_search('project_no', $headers);

        $grouped = [];
        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            $pid = trim($raw[$pid_idx] ?? '');
            if (!isset($pid_set[$pid])) continue;
            $r = array_combine($headers, array_map('trim', $raw));
            $grouped[$pid][] = $r;
        }
        fclose($fh);
        return $grouped;
    }

    /**
     * Compute last N consecutive quarterly ratings from raw caanddisb rows.
     * Replicates get_consecutive_ratings() without loading full array.
     */
    private function _compute_consecutive_ratings(array $rows, $num = 5)
    {
        $quarters = [];
        foreach ($rows as $r) {
            $rating = trim($r['perf_rating'] ?? '');
            if (empty($rating)) continue;
            $ts = $this->_parse_ddmmyyyy_export(trim($r['report_week'] ?? ''));
            if (!$ts) continue;
            $year    = (int) date('Y', $ts);
            $quarter = (int) ceil((int)date('n', $ts) / 3);
            $key     = $year . 'Q' . $quarter;
            if (!isset($quarters[$key]) || $ts >= $quarters[$key]['ts']) {
                $quarters[$key] = ['label' => $year . ', Q' . $quarter, 'perf_rating' => $rating, 'ts' => $ts];
            }
        }
        usort($quarters, fn($a, $b) => $a['ts'] - $b['ts']);
        $sliced = array_slice($quarters, -$num);
        return array_values(array_map(fn($q) => ['label' => $q['label'], 'perf_rating' => $q['perf_rating']], $sliced));
    }

    /**
     * Compute quarterly CA/DB tables from raw caanddisb rows.
     * Replicates get_quarterly_cad() without loading full array.
     */
    private function _compute_quarterly_cad(array $rows)
    {
        if (empty($rows)) return ['ca' => [], 'db' => [], 'latest_year' => null];

        $ca_delta = $db_delta = $ca_cum = $db_cum = [];
        $by_lgn   = [];

        foreach ($rows as $r) {
            $ts = $this->_parse_ddmmyyyy_export(trim($r['report_week'] ?? ''));
            if (!$ts) continue;
            $y = (int) date('Y', $ts);
            $q = (int) ceil((int)date('n', $ts) / 3);
            $lgn = trim($r['loan_grant_no'] ?? '');

            $ca_d = max(0, (float)($r['ca_difference']   ?? 0));
            $db_d = max(0, (float)($r['disb_difference'] ?? 0));

            $ca_delta[$y][$q] = ($ca_delta[$y][$q] ?? 0) + $ca_d;
            $db_delta[$y][$q] = ($db_delta[$y][$q] ?? 0) + $db_d;
            $ca_cum[$y][$q]   = (float)($r['ca_actual']    ?? 0);
            $db_cum[$y][$q]   = (float)($r['disb_actual']  ?? 0);

            if ($lgn) {
                $by_lgn[$lgn]['ca_projn'][]   = (float)($r['ca_projn']    ?? 0);
                $by_lgn[$lgn]['ca_actual'][]  = (float)($r['ca_actual']   ?? 0);
                $by_lgn[$lgn]['disb_projn'][] = (float)($r['disb_projn']  ?? 0);
                $by_lgn[$lgn]['disb_actual'][]= (float)($r['disb_actual'] ?? 0);
            }
        }

        if (empty($ca_delta) && empty($db_delta)) return ['ca' => [], 'db' => [], 'latest_year' => null];

        $years = array_unique(array_merge(array_keys($ca_delta), array_keys($db_delta)));
        sort($years);
        $latest_year = end($years);

        // CA cumulative totals: max(ca_projn) + max(ca_actual) per LGN, summed
        $ca_tgt_cum = $ca_ach_cum = 0.0;
        foreach ($by_lgn as $vals) {
            $ca_tgt_cum += max($vals['ca_projn']  ?: [0]);
            $ca_ach_cum += max($vals['ca_actual'] ?: [0]);
        }

        // DB cumulative totals: single max across ALL rows (matches Python df["disb_projn"].max())
        $all_db_projn  = array_merge(...array_column($by_lgn, 'disb_projn'));
        $all_db_actual = array_merge(...array_column($by_lgn, 'disb_actual'));
        $db_tgt_cum = !empty($all_db_projn)  ? max($all_db_projn)  : 0.0;
        $db_ach_cum = !empty($all_db_actual) ? max($all_db_actual) : 0.0;

        return [
            'ca'          => $this->csv_reader->_build_qtr_table_public($ca_delta, $ca_cum, $latest_year, $ca_tgt_cum, $ca_ach_cum),
            'db'          => $this->csv_reader->_build_qtr_table_public($db_delta, $db_cum, $latest_year, $db_tgt_cum, $db_ach_cum),
            'latest_year' => $latest_year,
        ];
    }

    /** Parse DD/MM/YYYY → Unix timestamp (export-only helper) */
    private function _parse_ddmmyyyy_export($value)
    {
        if (empty($value)) return null;
        $d = \DateTime::createFromFormat('d/m/Y', $value);
        return $d ? $d->getTimestamp() : null;
    }

    /**
     * Compute last N consecutive quarterly ratings from perfratings.csv VALIDATED rows.
     * OI-aligned: uses report_period (e.g. Q12025) directly — no weekly bucketing.
     * Returns [{label, perf_rating}] sorted oldest-first.
     */
    private function _compute_consecutive_from_perfratings(array $rows, $num = 5)
    {
        $quarters = [];
        foreach ($rows as $r) {
            $period = trim($r['report_period'] ?? '');
            $rating = trim($r['perf_rating']   ?? '');
            if (empty($period) || empty($rating)) continue;
            if (!preg_match('/^Q([1-4])(\d{4})$/', $period, $m)) continue;
            $pi  = (int)$m[2] * 10 + (int)$m[1];  // sortable integer
            $key = $period;
            if (!isset($quarters[$key]) || $pi > ($quarters[$key]['pi'] ?? 0)) {
                $quarters[$key] = ['label' => $period, 'perf_rating' => $rating, 'pi' => $pi];
            }
        }
        // Sort oldest-first by period_int
        uasort($quarters, fn($a, $b) => $a['pi'] - $b['pi']);
        $sliced = array_slice(array_values($quarters), -$num);
        return array_map(fn($q) => ['label' => $q['label'], 'perf_rating' => $q['perf_rating']], $sliced);
    }

    // =========================================================================
    // Private: project sheet population
    // Matches populate_template() from Python script cell-for-cell
    // =========================================================================

    private function _populate_project_sheet($ws, $project_no, array $project, array $export_data = [])
    {
        // ── Header block ──────────────────────────────────────────────────────
        // B2: "[Country] Project Performance Monitoring Sheet"
        // For admin country exports, country_name is the DMC scope being exported.
        $country_name = $this->csv_reader->get_country_name($project['dmc']);
        $ws->setCellValue('B2', $country_name . ' Project Performance Monitoring Sheet');
        // H4: "Update as of" — report_week from snapshot
        $snap_rows   = $export_data['snap'][$project_no] ?? [];
        $report_week = !empty($snap_rows) ? ($snap_rows[0]['report_week'] ?? '') : '';
        if (empty($report_week) && !empty($project['product_details'][0]['report_week'])) {
            $report_week = $project['product_details'][0]['report_week'];
        }
        $ws->setCellValue('H4', $report_week);

        $ws->setCellValue('D6',  $project['project_name']);
        $ws->setCellValue('D7',  $project['project_no']);
        $ws->setCellValue('D8',  $project['loan_grant_str']);
        $ws->setCellValue('D10', $project['div_nom']);
        $ws->setCellValue('K6',  $project['sector']);
        $ws->setCellValue('K7',  $project['project_officer']);
        $ws->setCellValue('K8',  $project['project_analyst']);

        // ── Financing totals (B14, C14, D14) ─────────────────────────────────
        $financing = $export_data['financing'][$project_no] ?? $this->csv_reader->get_financing_amounts($project_no);
        if ($financing['adb'] !== '')         $this->_write_number($ws, 'B14', $financing['adb']);
        if ($financing['counterpart'] !== '') $this->_write_number($ws, 'C14', $financing['counterpart']);
        if ($financing['total'] !== '')       $this->_write_number($ws, 'D14', $financing['total']);

        // ── Safeguard categories (H14, I14, J14) — from product_details ─────────
        $first_product_detail = $project['product_details'][0] ?? [];
        $ws->setCellValue('H14', $first_product_detail['env'] ?? '');
        $ws->setCellValue('I14', $first_product_detail['ir']  ?? '');
        $ws->setCellValue('J14', $first_product_detail['ip']  ?? '');

        // ── A. Loan/Grant Basic Data rows (starting row 18) ───────────────────
        // One row per product — matching populate_loan_grant_basic_data()
        $this->_populate_loan_grant_rows($ws, $project['product_details']);

        // ── B. Project Rating (row 26) ────────────────────────────────────────
        $perf = $export_data['ratings'][$project_no] ?? $this->csv_reader->get_perf_ratings($project_no);
        if ($perf) {
            // Row 25 headers: B=Outputs, C=CA%(formula =C39), D=Disb%(formula), E=Disb%(formula =C44),
            // F=Financial Management, G=Safeguards, H=Overall Rating
            // C26=formula =C39, E26=formula =C44 — DO NOT OVERWRITE
            $this->_apply_rating_cell($ws, 'C26', $perf['outputs']     ?? '');
            $this->_apply_rating_cell($ws, 'F26', $perf['fin_mgt']     ?? '');
            $this->_apply_rating_cell($ws, 'G26', $perf['safeguards']  ?? '');
            $this->_apply_rating_cell($ws, 'H26', $perf['perf_rating'] ?? '');
        }

        // PTL-entered reason (from ppms_sections if saved)
        $ptl_rating = $this->ppms_model->get_section($project_no, 'project_rating');
        if ($ptl_rating && !empty($ptl_rating['data_json']['reason_fa_ar'])) {
            $ws->setCellValue('B28', $ptl_rating['data_json']['reason_fa_ar']);
        }

        // ── B. Consecutive Overall Rating (rows 31–32) ────────────────────────
        $consecutive = $export_data['consecutive'][$project_no] ?? $this->csv_reader->get_consecutive_ratings($project_no, 5);
        $rating_cols = ['B', 'C', 'D', 'E', 'F'];
        foreach ($rating_cols as $i => $col) {
            if (isset($consecutive[$i])) {
                $ws->setCellValue($col . '31', $consecutive[$i]['label']);
                $this->_apply_rating_cell($ws, $col . '32', $consecutive[$i]['perf_rating']);
            }
        }

        // ── C. Quarterly CA & DB tables (rows 35–43) ─────────────────────────
        $cad = $export_data['quarterly_cad'][$project_no] ?? $this->csv_reader->get_quarterly_cad($project_no);
        if ($cad['latest_year'] && !empty($cad['ca'])) {
            $yr = $cad['latest_year'];
            $ca = $cad['ca'];
            $db = $cad['db'];

            // Python populate_ca_and_db_tables cell mapping:
            // Headers:  D35=Q1, F35=Q2, H35=Q3, J35=Q4, L35=Total  (CA)
            //           D40=Q1, F40=Q2, H40=Q3, J40=Q4, L40=Total  (DB)
            // Targets:  C37=cumul, E37=Q1, G37=Q2, I37=Q3, K37=Q4, M37=Total
            // Achieved: C38=cumul, D38=Q1, F38=Q2, H38=Q3, J38=Q4, L38=Total
            // (same layout for DB rows 42/43)

            // CA quarter headers
            $ws->setCellValue('D35', "Q1 {$yr}");
            $ws->setCellValue('F35', "Q2 {$yr}");
            $ws->setCellValue('H35', "Q3 {$yr}");
            $ws->setCellValue('J35', "Q4 {$yr}");
            $ws->setCellValue('L35', "Total {$yr}");

            // CA targets row 37
            $this->_write_number($ws, 'C37', $ca['target_total_cumulative']);
            $this->_write_number($ws, 'E37', $ca['q1']);
            $this->_write_number($ws, 'G37', $ca['q2']);
            $this->_write_number($ws, 'I37', $ca['q3']);
            $this->_write_number($ws, 'K37', $ca['q4']);
            $this->_write_number($ws, 'M37', $ca['total']);

            // CA achievement row 38
            $this->_write_number($ws, 'C38', $ca['achievement_total_cumulative']);
            $this->_write_number($ws, 'D38', $ca['q1_achievement']);
            $this->_write_number($ws, 'F38', $ca['q2_achievement']);
            $this->_write_number($ws, 'H38', $ca['q3_achievement']);
            $this->_write_number($ws, 'J38', $ca['q4_achievement']);
            $this->_write_number($ws, 'L38', $ca['achievement_total']);

            // DB quarter headers
            $ws->setCellValue('D40', "Q1 {$yr}");
            $ws->setCellValue('F40', "Q2 {$yr}");
            $ws->setCellValue('H40', "Q3 {$yr}");
            $ws->setCellValue('J40', "Q4 {$yr}");
            $ws->setCellValue('L40', "Total {$yr}");

            // DB targets row 42
            $this->_write_number($ws, 'C42', $db['target_total_cumulative']);
            $this->_write_number($ws, 'E42', $db['q1']);
            $this->_write_number($ws, 'G42', $db['q2']);
            $this->_write_number($ws, 'I42', $db['q3']);
            $this->_write_number($ws, 'K42', $db['q4']);
            $this->_write_number($ws, 'M42', $db['total']);

            // DB achievement row 43
            $this->_write_number($ws, 'C43', $db['achievement_total_cumulative']);
            $this->_write_number($ws, 'D43', $db['q1_achievement']);
            $this->_write_number($ws, 'F43', $db['q2_achievement']);
            $this->_write_number($ws, 'H43', $db['q3_achievement']);
            $this->_write_number($ws, 'J43', $db['q4_achievement']);
            $this->_write_number($ws, 'L43', $db['achievement_total']);
        }

        // ── D. Contract Awards and Disbursements (Annual Target vs. Actual CAD and CAD Ratios) ──
        $ratios = $export_data['section_d'][$project_no] ?? $this->csv_reader->get_section_d_ratios($project_no);
        // Cell mapping matches Python populate_project_section_d exactly (B50–T50)
        $ratio_map = [
            'B50' => 'NET_AMT',          'C50' => 'CA_UNCONTR',       'D50' => 'CA_PROJN',
            'E50' => 'CA_TARGET_CAR',    'F50' => 'CA_YTD_PROJN',     'G50' => 'CA_ACHVD',
            'H50' => 'CA_ACHVD_PCT',     'I50' => 'CA_YTD_ACHVD_PCT', 'J50' => 'CA_ACHVD_CAR',
            'K50' => 'CA_YTD_TRGT_CAR',
            'L50' => 'DR_UNDISB',        'M50' => 'DR_PROJN',         'N50' => 'DR_TARGET_DR',
            'O50' => 'DR_YTD_PROJN',     'P50' => 'DR_ACHVD',         'Q50' => 'DR_ACHVD_PCT',
            'R50' => 'DR_YTD_ACHVD_PCT', 'S50' => 'DR_ACHVD_DR',      'T50' => 'DR_YTD_TRGT_DR',
        ];
        foreach ($ratio_map as $cell => $key) {
            if (isset($ratios[$key]) && $ratios[$key] != 0) {
                $this->_write_number($ws, $cell, $ratios[$key]);
            }
        }

        // ── E–N: PTL-entered sections (from ppms_sections + ppms_rows) ────────
        // These are loaded from the DB and written into the sheet as plain text.
        // For export purposes we write the stored PTL data; formatting follows template.

        $ptl_sections = ['project_info', 'output_delivery', 'financial_management', 'env_safeguards',
                          'social_safeguards', 'contracts', 'outputs', 'safeguards_assessment',
                          'gender_action_plan', 'missions', 'major_issues'];

        foreach ($ptl_sections as $sec_key) {
            $this->_write_ptl_section_to_sheet($ws, $project_no, $sec_key);
        }
    }

    // =========================================================================
    // Private: loan/grant basic data rows (Section A, row 18+)
    // Matches populate_loan_grant_basic_data() from Python script
    // =========================================================================

    private function _populate_loan_grant_rows($ws, array $products)
    {
        $start_row = 18;
        $row       = $start_row;

        $center = new \PhpOffice\PhpSpreadsheet\Style\Alignment();
        $center->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
               ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        $right = new \PhpOffice\PhpSpreadsheet\Style\Alignment();
        $right->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT)
              ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
              ->setIndent(1);

        $font10 = new \PhpOffice\PhpSpreadsheet\Style\Font();
        $font10->setSize(10);

        foreach ($products as $prod) {
            // Col B: loan_grant_no (numeric, zero-padded)
            $lgn_raw = (string)($prod['loan_grant_no'] ?? '');
            $lgn_fmt = is_numeric($lgn_raw)
                ? ((int)$lgn_raw < 10000 ? str_pad((int)$lgn_raw, 4, '0', STR_PAD_LEFT) : (string)(int)$lgn_raw)
                : $lgn_raw;
            $ws->setCellValue('B' . $row, $lgn_fmt);

            // Col C: fund
            $ws->setCellValue('C' . $row, $prod['fund'] ?? '');

            // Col D: approval date (DD-MMM-YY)
            $ws->setCellValue('D' . $row, $this->_format_date($prod['approval'] ?? ''));

            // Col E: signing date
            $ws->setCellValue('E' . $row, $this->_format_date($prod['signing'] ?? ''));

            // Col F: effectivity date
            $ws->setCellValue('F' . $row, $this->_format_date($prod['effectivity'] ?? ''));

            // Col G: original closing date
            $ws->setCellValue('G' . $row, $this->_format_date($prod['original'] ?? ''));

            // Col H: revised closing date
            $ws->setCellValue('H' . $row, $this->_format_date($prod['rev_actual'] ?? ''));

            // Col I: percent_elapse (format as 0.0%)
            $elapse = (float)($prod['percent_elapse'] ?? 0);
            if ($elapse > 1) $elapse = $elapse / 100; // normalize if in 0–100 range
            $cell_i = $ws->getCell('I' . $row);
            $cell_i->setValue($elapse);
            $ws->getStyle('I' . $row)->getNumberFormat()->setFormatCode('0.0%');
            $ws->getStyle('I' . $row)->getAlignment()->setHorizontal(
                \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
            );

            // Col K: net_amount
            $cell_k = $ws->getCell('K' . $row);
            $cell_k->setValue((float)($prod['net_amount'] ?? 0));
            $ws->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $ws->getStyle('K' . $row)->getAlignment()->applyFromArray(['horizontal' => 'right', 'indent' => 1]);

            // Col L: net_effective_amt
            $cell_l = $ws->getCell('L' . $row);
            $cell_l->setValue((float)($prod['net_effective_amt'] ?? 0));
            $ws->getStyle('L' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $ws->getStyle('L' . $row)->getAlignment()->applyFromArray(['horizontal' => 'right', 'indent' => 1]);

            $row++;
        }
    }

    // =========================================================================
    // Private: portfolio sheet population
    // Matches generate_country_ppms() portfolio block from Python script
    // =========================================================================

    private function _populate_portfolio($portfolio, array $projects, $dmc, array $sheet_names, array $export_data = [])
    {
        // Use country snapshot rows if available (consistent global date — matches Python)
        $snap_map = $export_data['snap'] ?? [];

        // Pre-compute project_fc per project_no:
        // project_fc = max(financial_closing per product) + 365 days
        // financial_closing = (original or rev_actual) + 180 days  (Python logic)
        $project_fc_map = [];
        foreach ($projects as $project) {
            $pid     = $project['project_no'];
            $max_fc  = 0;
            foreach ($project['product_details'] as $prod) {
                $orig_ts = strtotime($prod['original'] ?? '');
                $rev_ts  = strtotime($prod['rev_actual'] ?? '');
                $base_ts = $orig_ts ?: $rev_ts;
                if ($base_ts) {
                    $fc_ts = $base_ts + (180 * 86400);
                    if ($fc_ts > $max_fc) $max_fc = $fc_ts;
                }
            }
            $project_fc_map[$pid] = $max_fc ? date('d-M-y', $max_fc + (365 * 86400)) : '';
        }

        $start_row       = 7;
        $current_row     = $start_row;
        $project_counter = 0;
        $prev_pid        = null;

        $hyperlink_color = new \PhpOffice\PhpSpreadsheet\Style\Color('FF0563C1');

        foreach ($projects as $project_idx => $project) {
            $project_no  = $project['project_no'];
            $sheet_name  = $sheet_names[$project_idx] ?? $project['sheet_name'];
            $is_new_proj = $project_no !== $prev_pid;
            if ($is_new_proj) $project_counter++;

            foreach ($project['product_details'] as $prod_idx => $prod) {
                $lgn_raw = (string)($prod['loan_grant_no'] ?? '');
                $lgn_fmt = is_numeric($lgn_raw)
                    ? ((int)$lgn_raw < 10000 ? str_pad((int)$lgn_raw, 4, '0', STR_PAD_LEFT) : (string)(int)$lgn_raw)
                    : $lgn_raw;

                $values = [
                    1 => $prod_idx === 0 ? $project_counter : '',
                    2 => $project['dmc'],
                    3 => $lgn_fmt,
                    4 => $project_no,
                    5 => $project['project_officer'],
                    6 => $project['project_analyst'],
                    7 => $project['project_name'],
                ];

                // Python: ALL 7 base columns get hyperlink to project sheet
                foreach ($values as $col => $val) {
                    $cell = $this->_cell($portfolio, $col, $current_row);
                    $cell->setValue($val);
                    $cell->getStyle()->getFont()->setName('Arial Nova')->setSize(9);

                    if ($col === 1) {
                        $cell->getStyle()->getAlignment()->setHorizontal(
                            \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
                        );
                    }

                    // Hyperlink all 7 base columns (matches Python behaviour)
                    if ($sheet_name) {
                        $cell->getHyperlink()->setUrl("sheet://'{$sheet_name}'!A1");
                        $cell->getStyle()->getFont()
                            ->setColor($hyperlink_color)
                            ->setUnderline(\PhpOffice\PhpSpreadsheet\Style\Font::UNDERLINE_SINGLE);
                    }
                }

                // Date + numeric block starting at col 10
                $orig_dt  = $this->_format_date($prod['original']  ?? '');
                $rev_dt   = $this->_format_date($prod['rev_actual'] ?? '');
                $orig_ts  = strtotime($prod['original']  ?? '');
                $rev_ts   = strtotime($prod['rev_actual'] ?? '');
                $base_ts  = $orig_ts ?: $rev_ts;
                $fc_dt    = $base_ts ? date('d-M-y', $base_ts + (180 * 86400)) : '';
                $proj_fc  = $project_fc_map[$project_no] ?? '';  // col 16 — Python project_fc

                $date_nums = [
                    $this->_format_date($prod['approval']    ?? ''),  // col 10
                    $this->_format_date($prod['signing']     ?? ''),  // col 11
                    $this->_format_date($prod['effectivity'] ?? ''),  // col 12
                    $orig_dt,                                          // col 13
                    $rev_dt,                                           // col 14
                    $fc_dt,                                            // col 15 product financial closing
                    $proj_fc,                                          // col 16 project_fc (+365)
                    (float)($prod['net_amount']        ?? 0),          // col 17
                    (float)($prod['net_effective_amt'] ?? 0),          // col 18
                ];

                foreach ($date_nums as $offset => $val) {
                    $col  = 10 + $offset;
                    $cell = $this->_cell($portfolio, $col, $current_row);
                    $cell->setValue($val);
                    $cell->getStyle()->getFont()->setName('Arial Nova')->setSize(9);

                    if (is_float($val) && $val > 0) {
                        $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0.00');
                        $cell->getStyle()->getAlignment()->setHorizontal(
                            \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT
                        )->setIndent(1);
                    }
                }

                $current_row++;
                $prev_pid = $project_no;
            }
        }
    }

    // =========================================================================
    // Private: column+row → cell coordinate (replaces getCellByColumnAndRow for PhpSpreadsheet 2.x)
    // =========================================================================

    private function _cell($ws, $col_index, $row)
    {
        $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col_index) . $row;
        return $ws->getCell($coord);
    }

    // =========================================================================
    // Private: write PTL-entered section data to worksheet
    // =========================================================================

    /**
     * Write PTL-entered section data into the worksheet.
     * Cell addresses match the PPMS_template.xlsx structure exactly.
     * Tabular sections append rows starting at the first blank data row.
     * Flat sections write to named cells matching template column headers.
     */
    private function _write_ptl_section_to_sheet($ws, $project_no, $section_key)
    {
        switch ($section_key) {

            // ── Project Information (PTL fields only) ─────────────────────────
            // D9  = Executing/Implementing Agency
            // K9  = Project Document Link
            // G10 = TRJM date of project transfer
            // K10 = Project Handover Document Link
            case 'project_info':
                $d = $this->ppms_model->get_section($project_no, $section_key);
                if (!$d) return;
                $f = $d['data_json'] ?? [];
                // Template B9 = "Executing/Implementing Agency:" — both share the value cell
                // Write as combined string if both present, else just whichever exists
                $ea  = trim($f['executing_agency']    ?? '');
                $ia  = trim($f['implementing_agency'] ?? '');
                $agency_str = $ea;
                if ($ea && $ia) $agency_str = $ea . ' / ' . $ia;
                elseif ($ia)    $agency_str = $ia;
                if ($agency_str) $ws->setCellValue('D9', $agency_str);
                if (!empty($f['project_doc_link']))  $ws->setCellValue('K9',  $f['project_doc_link']);
                if (!empty($f['trjm_date']))         $ws->setCellValue('G10', $f['trjm_date']);
                if (!empty($f['handover_doc_link'])) $ws->setCellValue('K10', $f['handover_doc_link']);
                break;


            // Template: APFS rows 106-108, AEFS rows 110-112
            // Columns: C=FY2018...K=FY2026  (most recent = col K)
            // We write the stored values into the latest FY column (J=FY2025, K=FY2026)
            case 'financial_management':
                $d = $this->ppms_model->get_section($project_no, $section_key);
                if (!$d) return;
                $f = $d['data_json'] ?? [];
                // Determine target FY column from stored fiscal_year field
                // Template cols: C=FY2018(3), D=FY2019(4), E=FY2020(5), F=FY2021(6), G=FY2022(7),
                //                H=FY2023(8), I=FY2024(9), J=FY2025(10), K=FY2026(11)
                $fy_col_map = [
                    'FY2018'=>'C','FY2019'=>'D','FY2020'=>'E','FY2021'=>'F','FY2022'=>'G',
                    'FY2023'=>'H','FY2024'=>'I','FY2025'=>'J','FY2026'=>'K',
                ];
                $fy  = strtoupper(trim($f['fiscal_year'] ?? ''));
                $col = $fy_col_map[$fy] ?? 'J';  // default to J (FY2025)
                // APFS
                if (!empty($f['apfs_timeliness']))  $ws->setCellValue($col . '106', $f['apfs_timeliness']);
                if (!empty($f['apfs_quality']))     $ws->setCellValue($col . '107', $f['apfs_quality']);
                if (!empty($f['apfs_disclosure']))  $ws->setCellValue($col . '108', $f['apfs_disclosure']);
                if (!empty($f['apfs_remarks']))     $ws->setCellValue('L106', $f['apfs_remarks']);
                // AEFS
                if (!empty($f['aefs_timeliness']))  $ws->setCellValue($col . '110', $f['aefs_timeliness']);
                if (!empty($f['aefs_quality']))     $ws->setCellValue($col . '111', $f['aefs_quality']);
                if (!empty($f['aefs_disclosure']))  $ws->setCellValue($col . '112', $f['aefs_disclosure']);
                if (!empty($f['aefs_remarks']))     $ws->setCellValue('L110', $f['aefs_remarks']);
                break;

            // ── G. Environmental Safeguards ──────────────────────────────────
            // Template row 115 col headers: B=EIA, D=Semi-annual, E=GRM, F=CAP current, G=Status of CAP
            // Data row 116
            case 'env_safeguards':
                // Template row 115: C=IEE, D=EIA, E=Semi-annual, F=GRM, G=CAP current, H=Status of CAP
                // Data row 116
                $d = $this->ppms_model->get_section($project_no, $section_key);
                if (!$d) return;
                $f = $d['data_json'] ?? [];
                if (!empty($f['iee']))                    $ws->setCellValue('C116', $f['iee']);
                if (!empty($f['eia']))                    $ws->setCellValue('D116', $f['eia']);
                if (!empty($f['semi_annual_report_due'])) $ws->setCellValue('E116', $f['semi_annual_report_due']);
                if (!empty($f['grm']))                    $ws->setCellValue('F116', $f['grm']);
                if (!empty($f['cap_current']))            $ws->setCellValue('G116', $f['cap_current']);
                if (!empty($f['status_of_cap']))          $ws->setCellValue('H116', $f['status_of_cap']);
                break;

            // ── H. Social Safeguards ─────────────────────────────────────────
            // Template row 122 headers: B=Due Diligence, D=Issues, E=GRM, F=CAP, G=Status, H=LARP
            // Data row 123
            case 'social_safeguards':
                // Template row 122: C=LARP, D=Due Diligence, E=Semi-annual, F=GRM, G=CAP current, H=Status
                // Data row 123
                $d = $this->ppms_model->get_section($project_no, $section_key);
                if (!$d) return;
                $f = $d['data_json'] ?? [];
                if (!empty($f['larp']))                   $ws->setCellValue('C123', $f['larp']);
                if (!empty($f['due_diligence_report']))   $ws->setCellValue('D123', $f['due_diligence_report']);
                if (!empty($f['semi_annual_report_due'])) $ws->setCellValue('E123', $f['semi_annual_report_due']);
                if (!empty($f['grm']))                    $ws->setCellValue('F123', $f['grm']);
                if (!empty($f['cap_current']))            $ws->setCellValue('G123', $f['cap_current']);
                if (!empty($f['status_of_cap']))          $ws->setCellValue('H123', $f['status_of_cap']);
                break;

            // ── I. Contracts ─────────────────────────────────────────────────
            // Template: header rows 129, data rows 130-138 (9 rows before "DO NOT INSERT" warning)
            // Cols: B=Loan/Grant, C=Contract, D=Description, F=Proc Method, G=Contract Type,
            //       H=Contractor, J=Estimate, K=Amount, L=Disbursed, N=Status, P=Implementation Status
            case 'contracts':
                // Template row 129 headers (exact column positions):
                // B=Loan/Grant, C=Contract(ref/PCSS), D=Description,
                // F=Procurement/Selection Method, G=Contract Type, H=Contractor,
                // J=Contract Estimate, K=Contract Amount, L=Disbursed Amount,
                // M=Disbursed vs Contract (FORMULA — do NOT overwrite),
                // N=Contract Status, O=Related Output Indicator, P=Status of Implementation
                $rows = $this->ppms_model->get_rows($project_no, $section_key);
                $start = 130;
                $max   = 138;  // 9 data rows before "DO NOT INSERT" warning at row 139
                foreach ($rows as $i => $row) {
                    $r = $start + $i;
                    if ($r > $max) break;
                    $d = $row['data_json'] ?? [];
                    if (!empty($d['loan_grant']))          $ws->setCellValue('B' . $r, $d['loan_grant']);
                    if (!empty($d['contract_ref']))        $ws->setCellValue('C' . $r, $d['contract_ref']);
                    if (!empty($d['description']))         $ws->setCellValue('D' . $r, $d['description']);
                    if (!empty($d['proc_method']))         $ws->setCellValue('F' . $r, $d['proc_method']);
                    if (!empty($d['contract_type']))       $ws->setCellValue('G' . $r, $d['contract_type']);
                    if (!empty($d['contractor']))          $ws->setCellValue('H' . $r, $d['contractor']);
                    if (!empty($d['contract_estimate']))   $this->_write_number($ws, 'J' . $r, $d['contract_estimate']);
                    if (!empty($d['contract_amount']))     $this->_write_number($ws, 'K' . $r, $d['contract_amount']);
                    if (!empty($d['disbursed_amount']))    $this->_write_number($ws, 'L' . $r, $d['disbursed_amount']);
                    // M is formula (Disbursed vs Contract %) — do NOT overwrite
                    if (!empty($d['contract_status']))     $ws->setCellValue('N' . $r, $d['contract_status']);
                    if (!empty($d['related_output']))      $ws->setCellValue('O' . $r, $d['related_output']);
                    if (!empty($d['impl_status']))         $ws->setCellValue('P' . $r, $d['impl_status']);
                }
                break;

            // ── E. Output Delivery & Procurement ────────────────────────────
            // Template: Output 1 rows 56-61, Output 2 rows 66-71, Project Mgmt rows 76-99
            // Cols: B=Package No, C-D=Description, E=Contractor, F=Contract No,
            //       G=Contract Amount, H=Disbursement, I=Completion Date, J=Status, K=Contract Progress, M=Output Delivery Status
            case 'output_delivery':
                // Template blocks: Output 1 rows 56-61, Output 2 rows 66-71, Project Mgmt rows 76-99
                // Route rows to block using output_group field if present; else sequential
                $rows = $this->ppms_model->get_rows($project_no, $section_key);
                $block_map = [
                    'output 1'          => ['start' => 56, 'max' => 6,  'used' => 0],
                    'output 2'          => ['start' => 66, 'max' => 6,  'used' => 0],
                    'output 3'          => ['start' => 66, 'max' => 6,  'used' => 0], // reuse O2 block
                    'project management'=> ['start' => 76, 'max' => 24, 'used' => 0],
                    'default'           => ['start' => 56, 'max' => 6,  'used' => 0],
                ];
                // Sequential fallback blocks
                $seq_blocks = [
                    ['start' => 56, 'max' => 6,  'used' => 0],
                    ['start' => 66, 'max' => 6,  'used' => 0],
                    ['start' => 76, 'max' => 24, 'used' => 0],
                ];
                $seq_bi = 0;
                foreach ($rows as $row) {
                    $d     = $row['data_json'] ?? [];
                    $group = strtolower(trim($d['output_group'] ?? ''));
                    // Determine target block
                    $block = null;
                    if ($group !== '') {
                        if (strpos($group, 'project') !== false || strpos($group, 'mgmt') !== false || strpos($group, 'management') !== false) {
                            $block = &$seq_blocks[2];
                        } elseif (strpos($group, '2') !== false) {
                            $block = &$seq_blocks[1];
                        } else {
                            $block = &$seq_blocks[0];
                        }
                    } else {
                        // Sequential fallback
                        while ($seq_bi < count($seq_blocks) && $seq_blocks[$seq_bi]['used'] >= $seq_blocks[$seq_bi]['max']) $seq_bi++;
                        if ($seq_bi >= count($seq_blocks)) break;
                        $block = &$seq_blocks[$seq_bi];
                    }
                    if ($block['used'] >= $block['max']) continue;
                    $r = $block['start'] + $block['used'];
                    $block['used']++;
                    if (!empty($d['package_no']))             $ws->setCellValue('B' . $r, $d['package_no']);
                    if (!empty($d['description']))            $ws->setCellValue('C' . $r, $d['description']);
                    if (!empty($d['contractor']))             $ws->setCellValue('E' . $r, $d['contractor']);
                    if (!empty($d['contract_no']))            $ws->setCellValue('F' . $r, $d['contract_no']);
                    if (!empty($d['contract_amount']))        $this->_write_number($ws, 'G' . $r, $d['contract_amount']);
                    if (!empty($d['disbursement']))           $this->_write_number($ws, 'H' . $r, $d['disbursement']);
                    if (!empty($d['completion_date']))        $ws->setCellValue('I' . $r, $this->_format_date($d['completion_date']));
                    if (!empty($d['status']))                 $ws->setCellValue('J' . $r, $d['status']);
                    if (!empty($d['contract_progress']))      $ws->setCellValue('K' . $r, $d['contract_progress']);
                    if (!empty($d['output_delivery_status'])) $ws->setCellValue('M' . $r, $d['output_delivery_status']);
                    unset($block);
                }
                break;

            // ── J. Outputs ───────────────────────────────────────────────────
            // Template: Output groups start at B148 (Output 1), B152 (Output 2), etc.
            // Data rows: 149-151 (O1), 153-157 (O2), 159-161 (O3), 163-165 (O4), 167-169 (O5)
            // Cols: B=Indicator text, F=Target Year, G=Weight, H=Rating, J=Progress/Status, N=Action Plan
            case 'outputs':
                // Template: Output 1 (rows 149-151), Output 2 (rows 153-157), Output 3 (159-161),
                //           Output 4 (163-165), Output 5 (167-169). Label rows: 148, 152, 158, 162, 166.
                // Cols: B=Indicator, F=Target Year, G=Weight, H=Rating, I=Score(formula), J=Progress/Status, N=Action Plan
                $rows = $this->ppms_model->get_rows($project_no, $section_key);
                $output_groups = [
                    ['label_row' => 148, 'data_rows' => [149,150,151]],
                    ['label_row' => 152, 'data_rows' => [153,154,155,156,157]],
                    ['label_row' => 158, 'data_rows' => [159,160,161]],
                    ['label_row' => 162, 'data_rows' => [163,164,165]],
                    ['label_row' => 166, 'data_rows' => [167,168,169]],
                ];
                $gi = 0; $di = 0;  // group index, within-group data row index
                $last_label = '';
                foreach ($rows as $row) {
                    if ($gi >= count($output_groups)) break;
                    $d   = $row['data_json'] ?? [];
                    $grp = $output_groups[$gi];
                    // Write output label to group label row when group changes
                    $cur_label = trim($d['output_label'] ?? '');
                    if ($cur_label && $cur_label !== $last_label) {
                        $ws->setCellValue('B' . $grp['label_row'], $cur_label . ':');
                        $last_label = $cur_label;
                    }
                    if ($di < count($grp['data_rows'])) {
                        $r = $grp['data_rows'][$di];
                        if (!empty($d['indicator']))       $ws->setCellValue('B' . $r, $d['indicator']);
                        if (!empty($d['target_year']))     $ws->setCellValue('F' . $r, $d['target_year']);
                        if (!empty($d['weight']))          $this->_write_number($ws, 'G' . $r, $d['weight']);
                        if (!empty($d['rating']))          $this->_apply_rating_cell($ws, 'H' . $r, $d['rating']);
                        if (!empty($d['progress_status'])) $ws->setCellValue('J' . $r, $d['progress_status']);
                        if (!empty($d['action_plan']))     $ws->setCellValue('N' . $r, $d['action_plan']);
                        $di++;
                    }
                    // Advance to next group when this group is full
                    if ($di >= count($grp['data_rows'])) { $gi++; $di = 0; }
                }
                break;

            // ── K. Safeguards Assessment ─────────────────────────────────────
            // Template: 8 fixed indicators at rows 176,177,178,180,181,183,185,187
            // Cols: I=Response/Comments, N=Action Plan (M=Rating is formula, don't overwrite)
            case 'safeguards_assessment':
                $rows = $this->ppms_model->get_rows($project_no, $section_key);
                $indicator_rows = [176, 177, 178, 180, 181, 183, 185, 187];
                foreach ($rows as $i => $row) {
                    if ($i >= count($indicator_rows)) break;
                    $r = $indicator_rows[$i];
                    $d = $row['data_json'] ?? [];
                    if (!empty($d['response_comments'])) $ws->setCellValue('I' . $r, $d['response_comments']);
                    if (!empty($d['action_plan']))        $ws->setCellValue('N' . $r, $d['action_plan']);
                    // Note: M column rating is computed by template formula VLOOKUP — don't overwrite
                }
                break;

            // ── L. Gender Action Plan ────────────────────────────────────────
            // Template: header row 191, data rows 192-196 (5 rows)
            // Cols: B=Status, D=Issues, G=Remarks
            case 'gender_action_plan':
                $d = $this->ppms_model->get_section($project_no, $section_key);
                if (!$d) return;
                $f = $d['data_json'] ?? [];
                if (!empty($f['status']))  $ws->setCellValue('B192', $f['status']);
                if (!empty($f['issues']))  $ws->setCellValue('D192', $f['issues']);
                if (!empty($f['remarks'])) $ws->setCellValue('G192', $f['remarks']);
                break;

            // ── M. Missions ──────────────────────────────────────────────────
            // Template: header row 198, data rows 199-200 (2 rows in template)
            // Cols: B=Date, C=Mission Type, D=Aide Memoire, E=BTOR, F=Remarks
            case 'missions':
                // Template: B198=Date, C198=Type of Mission, D198=Aide Memoire, E198=BTOR, F198=Remarks
                // New fields: from_date + to_date (date range); write as "from_date – to_date" in col B
                $rows = $this->ppms_model->get_rows($project_no, $section_key);
                $start = 199;
                foreach ($rows as $i => $row) {
                    $r = $start + $i;
                    $d = $row['data_json'] ?? [];
                    // Date: prefer from_date, fallback to legacy 'date' field
                    $from = trim($d['from_date'] ?? ($d['date'] ?? ''));
                    $to   = trim($d['to_date']   ?? '');
                    $date_str = $from ? $this->_format_date($from) : '';
                    if ($to) $date_str .= ($date_str ? ' – ' : '') . $this->_format_date($to);
                    if ($date_str)              $ws->setCellValue('B' . $r, $date_str);
                    if (!empty($d['mission_type'])) $ws->setCellValue('C' . $r, $d['mission_type']);
                    if (!empty($d['aide_memoire'])) $ws->setCellValue('D' . $r, $d['aide_memoire']);
                    if (!empty($d['btor']))          $ws->setCellValue('E' . $r, $d['btor']);
                    if (!empty($d['remarks']))       $ws->setCellValue('F' . $r, $d['remarks']);
                }
                break;

            // ── N. Major Issues & Actions ────────────────────────────────────
            // Template: header row 202, data rows 203-207 (5 rows)
            // Cols: B=Item, D=Mitigating Measure, G=Status
            case 'major_issues':
                // Template: B202=Item, D202=Mitigating Measure, G202=Status
                // New fields: responsible_party, target_date, date_resolved (no template cells — append to H, I, J)
                $rows = $this->ppms_model->get_rows($project_no, $section_key);
                $start = 203;
                $max   = 207;
                foreach ($rows as $i => $row) {
                    $r = $start + $i;
                    if ($r > $max) break;
                    $d = $row['data_json'] ?? [];
                    if (!empty($d['item']))                $ws->setCellValue('B' . $r, $d['item']);
                    if (!empty($d['mitigating_measure']))  $ws->setCellValue('D' . $r, $d['mitigating_measure']);
                    if (!empty($d['status']))              $ws->setCellValue('G' . $r, $d['status']);
                    // Extra fields written to adjacent columns (no dedicated template cells)
                    if (!empty($d['responsible_party']))   $ws->setCellValue('H' . $r, $d['responsible_party']);
                    if (!empty($d['target_date']))         $ws->setCellValue('I' . $r, $this->_format_date($d['target_date']));
                    if (!empty($d['date_resolved']))       $ws->setCellValue('J' . $r, $this->_format_date($d['date_resolved']));
                }
                break;
        }
    }

    // =========================================================================
    // Private: rating cell formatting
    // Matches apply_rating_formatting() from Python script
    // =========================================================================

    private function _apply_rating_cell($ws, $cell_address, $rating)
    {
        $rating = trim($rating);
        if (empty($rating)) return;

        $ws->setCellValue($cell_address, $rating);

        $colors = [
            'On Track'      => 'FF008000',  // Green
            'For Attention' => 'FFFFA500',  // Orange
            'At Risk'       => 'FFFF0000',  // Red
        ];

        $color = $colors[$rating] ?? null;
        if (!$color) return;

        $style = $ws->getStyle($cell_address);
        $style->getFill()
              ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setARGB($color);
        $style->getFont()
              ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'))
              ->setName('Arial')->setBold(true)->setSize(9);
        $style->getAlignment()
              ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
              ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    }

    // =========================================================================
    // Private: cell write helpers
    // =========================================================================

    private function _write_number($ws, $cell, $value)
    {
        if ($value === '' || $value === null) return;
        $ws->setCellValue($cell, (float)$value);
        $ws->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
    }

    private function _format_date($value)
    {
        if (empty($value)) return '';
        // Try multiple formats
        foreach (['d/m/Y', 'Y-m-d', 'd-M-y', 'Y/m/d'] as $fmt) {
            $d = \DateTime::createFromFormat($fmt, trim($value));
            if ($d) return $d->format('d-M-y');
        }
        return $value;
    }

    // =========================================================================
    // Private: sheet naming (matching Python build_loan_grant_string)
    // =========================================================================

    private function _make_sheet_name(array $project)
    {
        return substr($project['sheet_name'], 0, 31);
    }

    private function _unique_sheet_name(array $project, array $used_names)
    {
        $base = substr($project['sheet_name'], 0, 31);
        if (!in_array($base, $used_names)) return $base;

        $counter = 1;
        do {
            $suffix    = '_' . $counter;
            $candidate = substr($base, 0, 31 - strlen($suffix)) . $suffix;
            $counter++;
        } while (in_array($candidate, $used_names));

        return $candidate;
    }

    private function _move_portfolio_front($wb)
    {
        $portfolio = $wb->getSheetByName('Portfolio');
        if ($portfolio) {
            $wb->setActiveSheetIndex($wb->getIndex($portfolio));
            // Move to index 0
            $current_idx = $wb->getIndex($portfolio);
            if ($current_idx > 0) {
                $wb->removeSheetByIndex($current_idx);
                $wb->addSheet($portfolio, 0);
            }
        }
    }

    // =========================================================================
    // GET /api/export/download/{job_id}
    // Streams the saved xlsx file and cleans up temp files
    // =========================================================================

    public function download($job_id)
    {
        $job_id  = preg_replace('/[^a-zA-Z0-9_]/', '', $job_id);
        $dir     = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        $xlsx    = $dir . 'ppms_export_' . $job_id . '.xlsx';
        $meta    = $dir . 'ppms_export_' . $job_id . '_meta.json';
        $prog    = $dir . 'ppms_export_' . $job_id . '.json';

        if (!file_exists($xlsx)) {
            http_response_code(404);
            echo 'Export file not found or already downloaded.';
            exit;
        }

        // Read filename from meta file
        $filename = 'PPMS_Export.xlsx';
        if (file_exists($meta)) {
            $m = @json_decode(file_get_contents($meta), true);
            $filename = $m['filename'] ?? $filename;
        }

        // Stream the file
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($xlsx));
        header('Cache-Control: no-cache');
        header('Pragma: public');

        if (ob_get_level()) ob_end_clean();
        readfile($xlsx);

        // Cleanup
        @unlink($xlsx);
        @unlink($meta);
        @unlink($prog);
        exit;
    }

    // =========================================================================
    // GET /api/export/progress/{job_id}
    // =========================================================================

    public function progress($job_id)
    {
        // Release session lock immediately — this is a lightweight read-only endpoint
        session_write_close();

        // Sanitise job_id — alphanumeric only
        $job_id = preg_replace('/[^a-zA-Z0-9_]/', '', $job_id);
        $file   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ppms_export_' . $job_id . '.json';

        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store');

        if (!file_exists($file)) {
            echo json_encode(['progress' => 0, 'step' => 'Initialising…', 'done' => false]);
            exit;
        }

        $data = @json_decode(file_get_contents($file), true) ?: [];
        echo json_encode($data);
        exit;
    }

    // =========================================================================
    // Private: progress helpers
    // =========================================================================

    private $_job_id   = null;
    private $_job_total = 1;
    private $_job_done  = 0;

    private function _progress_init($job_id, $total_projects)
    {
        $this->_job_id    = preg_replace('/[^a-zA-Z0-9_]/', '', $job_id);
        $this->_job_total = max(1, $total_projects);
        $this->_job_done  = 0;
        $this->_progress_write(0, 'Starting export…');
    }

    private function _progress_step($step_label)
    {
        if (!$this->_job_id) return;
        $this->_job_done++;
        $pct = (int) round(($this->_job_done / $this->_job_total) * 90); // 0-90, last 10% for write
        $this->_progress_write($pct, $step_label);
    }

    private function _progress_finish()
    {
        if (!$this->_job_id) return;
        $this->_progress_write(100, 'Done', true);
    }

    private function _progress_write($pct, $step, $done = false)
    {
        if (!$this->_job_id) return;
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ppms_export_' . $this->_job_id . '.json';
        @file_put_contents($file, json_encode([
            'progress' => $pct,
            'step'     => $step,
            'done'     => $done,
        ]));
    }

    // =========================================================================
    // Private: streaming + bootstrap
    // =========================================================================

    private function _boot_spreadsheet()
    {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $autoload = FCPATH . 'vendor/autoload.php';
            if (!file_exists($autoload)) {
                $this->_json_error('PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet:"^2.0" --no-scripts --ignore-platform-req=ext-gd', 500);
            }
            require_once $autoload;
        }

        if (!file_exists($this->template_path)) {
            $this->_json_error(
                'PPMS template not found at ' . $this->template_path .
                '. Copy PPMS_template.xlsx to application/templates/', 500
            );
        }
    }

    private function _stream_xlsx($wb, $filename)
    {
        $writer  = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($wb, 'Xlsx');
        $job_id  = $this->_job_id;

        if ($job_id) {
            // Save xlsx to temp file first — THEN signal done:true.
            // Order matters: if done:true is written before save() completes,
            // Vue navigates away (window.location), aborting the fetch request,
            // which kills PHP mid-save → 404 on download.
            $dir      = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
            $tmp_path = $dir . 'ppms_export_' . $job_id . '.xlsx';
            $writer->save($tmp_path);

            @file_put_contents(
                $dir . 'ppms_export_' . $job_id . '_meta.json',
                json_encode(['filename' => $filename . '.xlsx'])
            );

            // Signal completion AFTER file is fully written to disk
            $this->_progress_finish();
            exit;
        }

        // No job_id — direct stream (single project or manual URL visit)
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        if (ob_get_level()) ob_end_clean();
        $writer->save('php://output');
        exit;
    }
}
