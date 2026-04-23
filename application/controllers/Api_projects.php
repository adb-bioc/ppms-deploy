<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Load PPMS base controller — try multiple paths for maximum compatibility
if (!class_exists('PPMS_Controller')) {
    // APPPATH is set by CI3 before controllers are loaded
    $ppms_ctrl = APPPATH . 'controllers/PPMS_Controller.php';
    if (file_exists($ppms_ctrl)) {
        require_once $ppms_ctrl;
    } else {
        // Fallback: relative to this file
        require_once __DIR__ . '/PPMS_Controller.php';
    }
}

/**
 * Api_projects Controller
 *
 * All endpoints use the real CSV_reader methods that map to actual OI column names.
 * Section keys match Progress_calculator::SECTION_DEFS exactly.
 *
 * Routes:
 *   GET  /api/projects                         → index()
 *   GET  /api/projects/{no}                    → show($no)
 *   GET  /api/projects/{no}/section/{key}      → section($no, $key)
 *   POST /api/projects/{no}/save               → save($no)
 *   GET  /api/projects/{no}/progress           → progress($no)
 */
class Api_projects extends PPMS_Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    // -------------------------------------------------------------------------
    // GET /api/projects
    // -------------------------------------------------------------------------
    public function index()
    {
        $this->require_ppms_user();

        // Sanity check: CSV data must exist
        if (!file_exists(PPMS_CSV_PATH . 'caanddisb.csv')) {
            $this->_json_error(
                'CSV data not found at ' . PPMS_CSV_PATH . 'caanddisb.csv. ' .
                'Copy your OI CSV exports to the csv_data/ folder.',
                503
            );
        }

        $role = $this->current_user['role'] ?? '';

        // DMC filter — admin sees all (or filters by ?dmc=), everyone else sees their DMC
        $dmc = ($role === 'admin')
            ? ($this->input->get('dmc') ?: null)
            : ($this->effective_dmc !== 'ALL' ? $this->effective_dmc : null);

        // CSV layer — latest snapshot
        // Admin all-DMC (dmc=null): iterate each DMC via per-DMC country snapshots.
        // Admin filtered (?dmc=X) and PTL: single get_projects($dmc) call.
        if ($dmc === null) {
            $csv_projects = [];
            foreach ($this->csv_reader->get_dmc_list() as $_d) {
                $csv_projects = array_merge($csv_projects, $this->csv_reader->get_projects($_d));
            }
        } else {
            $csv_projects = $this->csv_reader->get_projects($dmc);
        }

        // App progress layer (DB) — per-section data for all projects
        $progress_rows = $dmc
            ? $this->ppms_model->get_dmc_progress($dmc)
            : $this->ppms_model->get_all_progress();
        $progress_map = [];
        foreach ($progress_rows as $p) {
            $progress_map[$p['project_id']] = $p;
        }

        // Per-section DB data keyed by project_no
        // Used to compute overall the same way show() does (CSV sections = 100%)
        $all_sections = $this->ppms_model->get_all_sections_bulk(
            array_column($csv_projects, 'project_no')
        );

        $manifest = $this->progress_calculator->get_section_manifest();

        // Pre-load consecutive At Risk counts (single perfratings.csv pass)
        $consec_map = $this->csv_reader->get_all_consecutive_counts();

        // Pre-load quarterly CA%/Disb% from perfratings.csv (OI-aligned drill-through)
        // OI shows ca_percentage/disb_percentage from perfratings for ratings & consec drills.
        $perf_pct_map = $this->csv_reader->get_all_perf_percentages_latest();

        // Merge CSV + app progress — compute overall the same way show() does
        // Pre-load section configs per DMC (one DB query per unique DMC,
        // not one per project) to avoid N+1 queries inside array_map.
        $unique_dmcs  = array_unique(array_column($csv_projects, 'dmc'));
        $dmc_cfg_map  = [];
        $dmc_region_map = [];
        foreach ($unique_dmcs as $d) {
            $d_upper = strtoupper($d);
            $dmc_cfg_map[$d_upper] = $this->ppms_model->get_section_config($d_upper, $role);
        }

        // Build dmc → region lookup from country_nom (already cached by CSV_reader)
        foreach ($this->csv_reader->_load_normalized_public('country_nom') as $row) {
            $dmc_region_map[strtoupper($row['code'])] = $row['region'] ?? '';
        }

        $result = array_map(function ($proj) use ($progress_map, $all_sections, $manifest, $dmc_cfg_map, $dmc_region_map, $consec_map) {
            $pid      = $proj['project_no'];
            $app      = $progress_map[$pid] ?? null;
            $sections = $all_sections[$pid] ?? [];

            $section_progress = [];
            foreach ($manifest as $sec) {
                $key = $sec['key'];
                if ($sec['readonly']) {
                    $section_progress[$key] = ['progress' => 100, 'status' => 'complete'];
                } elseif ($key === 'project_info' || $key === 'project_rating') {
                    // Mixed sections: compute live — OI fields always present,
                    // PTL fields from DB. Ensures correct % regardless of stored value.
                    $fields = $sections[$key]['data_json'] ?? [];
                    $r      = $this->progress_calculator->section_flat_progress($key, $fields);
                    $section_progress[$key] = ['progress' => $r['progress'], 'status' => $r['status']];
                } else {
                    $s_prog = $sections[$key]['progress'] ?? 0;
                    $section_progress[$key] = [
                        'progress' => $s_prog,
                        'status'   => $sections[$key]['status'] ?? ($s_prog > 0 ? 'in_progress' : 'not_started'),
                    ];
                }
            }

            // Use pre-loaded config — no per-project DB calls
            $proj_cfg = $dmc_cfg_map[strtoupper($proj['dmc'])] ?? [];
            $overall  = $this->progress_calculator->overall_progress($section_progress, $proj_cfg);

            return [
                'project_no'         => $pid,
                'project_title'      => $proj['project_name'],
                'dmc'                => $proj['dmc'],
                'region'             => $dmc_region_map[strtoupper($proj['dmc'])] ?? '',
                'sector_department'  => $proj['sector_department'] ?? '',
                'sard_sector'        => $proj['sard_sector']        ?? '',
                'sard_sector_nom'    => $proj['sard_sector_nom']    ?? '',
                'sector'             => $proj['sector'],
                'div_nom'            => $proj['div_nom'] ?? '',
                'project_officer'    => $proj['project_officer'],
                'project_analyst'    => $proj['project_analyst'],
                'loan_grant_str'     => $proj['loan_grant_str'],
                'sheet_name'         => $proj['sheet_name'],
                'products'           => $proj['products'],
                'product_details'    => $proj['product_details'] ?? [],
                'net_amount'         => $proj['net_amount'],
                // Financial fields — summed across all products, used by dashboard cards
                'ca_actual'          => (float)($proj['ca_actual']         ?? 0),
                'ca_projn'           => (float)($proj['ca_projn']          ?? 0),
                'ca_bal'             => (float)($proj['ca_bal']            ?? 0),
                'year_actual'        => (float)($proj['year_actual']       ?? 0),  // this year's CA actual (OI)
                'year_projn'         => (float)($proj['year_projn']        ?? 0),  // this year's annual CA plan (OI)
                'ytd_actual'         => (float)($proj['ytd_actual']        ?? 0),
                'ytd_projn'          => (float)($proj['ytd_projn']         ?? 0),
                'disb_actual'        => (float)($proj['disb_actual']       ?? 0),
                'disb_projn'         => (float)($proj['disb_projn']        ?? 0),
                'disb_bal'           => (float)($proj['disb_bal']          ?? 0),
                'disb_year_actual'   => (float)($proj['disb_year_actual']  ?? 0),  // this year's disb actual (OI)
                'disb_year_projn'    => (float)($proj['disb_year_projn']   ?? 0),  // this year's annual disb plan (OI)
                'disb_ytd_actual'    => (float)($proj['disb_ytd_actual']   ?? 0),
                'disb_ytd_proj'      => (float)($proj['disb_ytd_proj']     ?? 0),
                'perf_rating'        => trim($proj['perf_rating']        ?? ''),
                'percent_elapse'     => (float)($proj['percent_elapse']  ?? 0),
                'consecutive_atrisk' => $consec_map[$pid] ?? 0,  // consecutive At Risk quarters
                // OI-aligned: quarterly CA%/Disb% from perfratings.csv (NOT annual caanddisb fields)
                // Used by drill-through modals for Ratings and Consecutive At Risk cards
                'ca_percentage'      => (float)(($perf_pct_map[$pid]['ca_pct']   ?? null) ?? 0),
                'disb_percentage'    => (float)(($perf_pct_map[$pid]['disb_pct'] ?? null) ?? 0),
                // Computed live — matches workspace calculation exactly
                'overall_progress'   => $overall['overall'],
                'status'             => $overall['status'],
                'last_opened_at'     => $app['last_opened_at'] ?? null,
            ];
        }, $csv_projects);

        // Resume last widget
        $resume_last = null;
        if ($dmc) {
            $last_row = $this->ppms_model->get_last_opened($dmc);
            if ($last_row) {
                foreach ($result as $p) {
                    if ($p['project_no'] === $last_row['project_id']) {
                        $resume_last = $p;
                        break;
                    }
                }
            }
        }

        // Country summary
        $total    = count($result);
        $complete = count(array_filter($result, fn($p) => $p['status'] === 'complete'));
        $in_prog  = count(array_filter($result, fn($p) => $p['status'] === 'in_progress'));
        $avg_prog = $total > 0
            ? (int) round(array_sum(array_column($result, 'overall_progress')) / $total)
            : 0;

        $this->_json_ok([
            'projects'    => $result,
            'summary'     => [
                'total'        => $total,
                'complete'     => $complete,
                'in_progress'  => $in_prog,
                'not_started'  => $total - $complete - $in_prog,
                'avg_progress' => $avg_prog,
                'dmc'          => $dmc,
                'country_name' => $dmc ? $this->csv_reader->get_country_name($dmc) : null,
            ],
            'resume_last' => $resume_last,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/projects/{no}
    // -------------------------------------------------------------------------
    public function show($project_no)
    {
        $this->require_ppms_user();

        $project = $this->csv_reader->get_project($project_no);
        if (!$project) $this->_json_error('Project not found.', 404);
        $this->assert_project_dmc($project);

        // Touch / create DB workspace row
        $this->ppms_model->touch_project($project_no, $project['dmc']);

        // Section manifest + current progress
        $sec_cfg    = $this->_get_section_config($project['dmc']);
        $manifest   = $this->progress_calculator->get_section_manifest();
        $app_sects  = $this->ppms_model->get_all_sections($project_no);
        $section_progress = [];

        foreach ($manifest as &$sec) {
            $key    = $sec['key'];
            $app_s  = $app_sects[$key] ?? null;

            // CSV-sourced sections are always complete
            if ($sec['readonly']) {
                $prog   = 100;
                $status = 'complete';
            } elseif ($key === 'project_info' || $key === 'project_rating') {
                // Mixed sections: compute live so OI fields always count
                $fields = $app_s['data_json'] ?? [];
                $result = $this->progress_calculator->section_flat_progress($key, $fields);
                $prog   = $result['progress'];
                $status = $result['status'];
            } else {
                $prog   = $app_s['progress'] ?? 0;
                $status = $app_s['status']   ?? 'not_started';
            }

            $sec['progress'] = $prog;
            $sec['status']   = $status;
            // enabled: default true if no config row exists
            $sec['enabled']  = isset($sec_cfg[$key]) ? (bool)$sec_cfg[$key] : true;  // default all sections enabled
            $section_progress[$key] = ['progress' => $prog, 'status' => $status];
        }
        unset($sec);

        // Progress is computed live per-user using their section config.
        // We do NOT persist to DB here — each user has their own section config
        // Each DMC+role has its own section config, so per-user DB value would be incorrect.
        $overall  = $this->progress_calculator->overall_progress($section_progress, $sec_cfg);

        // Enrich project with computed data for workspace header
        $perf    = $this->csv_reader->get_perf_ratings($project_no);
        $country = $this->csv_reader->get_country_name($project['dmc']);
        $region  = '';
        foreach ($this->csv_reader->_load_normalized_public('country_nom') as $row) {
            if (strtoupper($row['code']) === strtoupper($project['dmc'])) {
                $region = $row['region'] ?? '';
                break;
            }
        }

        $this->_json_ok([
            'section_config' => $sec_cfg,
            'project'  => array_merge($project, [
                // Field name aliases — Vue workspace uses these exact names
                'project_no'         => $project['project_no'],
                'project_title'      => $project['project_name'],
                'net_amount'         => $project['net_amount'],
                'closing_date'       => $project['products'][0]['rev_actual'] ?? ($project['products'][0]['original'] ?? ''),
                // Computed/derived fields
                'overall_progress'   => $overall['overall'],
                'status'             => $overall['status'],
                'country_name'       => $country,
                'region'             => $region,
                'perf_rating'        => $perf['perf_rating']  ?? null,
                'is_consecutive_atrisk' => $perf['is_consecutive_atrisk'] ?? false,
            ]),
            'products' => $project['products'],
            'sections' => $manifest,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/projects/{no}/section/{key}
    // -------------------------------------------------------------------------
    public function section($project_no, $section_key)
    {
        $this->require_ppms_user();

        $project = $this->csv_reader->get_project($project_no);
        if (!$project) $this->_json_error('Project not found.', 404);
        $this->assert_project_dmc($project);

        $def = Progress_calculator::SECTION_DEFS[$section_key] ?? null;
        if (!$def) $this->_json_error('Unknown section: ' . $section_key, 400);

        $tabular    = !empty($def['tabular']);
        $is_csv_src = ($def['source'] ?? 'ptl') === 'csv';

        // Gather CSV reference data for this section
        $csv_data = $this->_get_csv_data_for_section($project_no, $section_key, $project);

        // Check SARD lock status (applies to SARD narrative fields)
        $sard_locked = false;
        if (in_array($section_key, ['ca_disbursements_quarterly', 'cad_ratios'])) {
            $sard = $this->csv_reader->get_sard_projections($project_no);
            $sard_locked = !empty($sard) && $sard[0]['is_locked'];
        }

        if ($tabular) {
            $rows     = $this->ppms_model->get_rows($project_no, $section_key);
            $progress = $this->progress_calculator->section_tabular_progress($section_key, $rows);
            $this->_json_ok([
                'section'     => $section_key,
                'label'       => $def['label'],
                'tabular'     => true,
                'source'      => $def['source'] ?? 'ptl',
                'readonly'    => $is_csv_src,
                'rows'        => $rows,
                'csv_data'    => $csv_data,
                'progress'    => $progress,
                'sard_locked' => $sard_locked,
            ]);
        } else {
            $app_sec  = $this->ppms_model->get_section($project_no, $section_key);
            $fields   = $app_sec['data_json'] ?? [];

            if ($is_csv_src) {
                // CSV sections: progress always 100 when data present
                $progress = ['progress' => !empty($csv_data) ? 100 : 0, 'status' => !empty($csv_data) ? 'complete' : 'not_started'];
            } else {
                $progress = $this->progress_calculator->section_flat_progress($section_key, $fields);
            }

            $this->_json_ok([
                'section'     => $section_key,
                'label'       => $def['label'],
                'tabular'     => false,
                'source'      => $def['source'] ?? 'ptl',
                'readonly'    => $is_csv_src,
                'fields'      => $fields,
                'csv_data'    => $csv_data,
                'progress'    => $progress,
                'sard_locked' => $sard_locked,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/projects/{no}/save
    // -------------------------------------------------------------------------
    public function save($project_no)
    {
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            $this->_json_error('POST required.', 405);
        }

        $this->require_ppms_user();

        if (($this->current_user['role'] ?? '') === 'viewer') {
            $this->_json_error('Viewer role cannot save data.', 403);
        }

        $project = $this->csv_reader->get_project($project_no);
        if (!$project) $this->_json_error('Project not found.', 404);
        $this->assert_project_dmc($project);

        $body        = $this->_json_input();
        // Accept both 'section_key' (Vue) and 'section' (legacy)
        $section_key = $body['section_key'] ?? $body['section'] ?? null;
        if (!$section_key) $this->_json_error('Missing section key.');

        $def = Progress_calculator::SECTION_DEFS[$section_key] ?? null;
        if (!$def) $this->_json_error('Unknown section.', 400);

        // Block writes to CSV-sourced sections
        if (($def['source'] ?? 'ptl') === 'csv') {
            $this->_json_error('Section ' . $section_key . ' is read-only (CSV-sourced).', 403);
        }

        $tabular = !empty($def['tabular']);
        $fields  = $body['fields']  ?? null;
        $rows_op = $body['rows_op'] ?? null;

        if ($tabular && $rows_op) {
            $this->_handle_row_op($project_no, $section_key, $rows_op);
        } elseif (!$tabular && is_array($fields)) {
            $progress = $this->progress_calculator->section_flat_progress($section_key, $fields);
            $saved    = $this->ppms_model->save_section(
                $project_no, $section_key, $fields,
                $progress['progress'], $progress['status'],
                $this->audit_ctx
            );
            if (!$saved) $this->_json_error('Failed to save section.', 500);
        } else {
            $this->_json_error('Invalid save payload.', 400);
        }

        // Recompute overall live for this user's section config.
        // NOT persisted to DB — progress is per DMC+role section config.
        $overall = $this->_recompute_overall($project_no);

        $this->_json_ok([
            'overall_progress' => $overall['overall'],
            'overall_status'   => $overall['status'],
            'section_progress' => $overall['sections'][$section_key] ?? null,
        ], 'Saved.');
    }

    // -------------------------------------------------------------------------
    // GET /api/projects/{no}/progress
    // -------------------------------------------------------------------------
    public function progress($project_no)
    {
        $this->require_ppms_user();
        $project = $this->csv_reader->get_project($project_no);
        if (!$project) $this->_json_error('Project not found.', 404);
        $this->assert_project_dmc($project);
        $this->_json_ok($this->_recompute_overall($project_no));
    }

    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // GET /api/projects/{no}/atrisk_quarters
    // Returns quarterly At Risk history from perfratings.csv for the consec drill-through.
    // -------------------------------------------------------------------------
    public function atrisk_weeks($project_no)
    {
        $this->require_ppms_user();
        $project = $this->csv_reader->get_project($project_no);
        if (!$project) $this->_json_error('Project not found.', 404);
        $this->assert_project_dmc($project);

        $history = $this->csv_reader->get_project_quarterly_history($project_no);

        $this->_json_ok([
            'quarters'  => $history,
            'total'     => count($history),
            'has_more'  => false,
        ]);
    }

    /**
     * Route section keys to their CSV data sources.
     * Returns pre-computed, API-ready data for each section.
     */
    private function _get_csv_data_for_section($project_no, $section_key, array $project)
    {
        switch ($section_key) {
            case 'project_info':
                return [
                    // OI read-only fields from caanddisb.csv snapshot
                    'project_no'        => $project['project_no'],
                    'project_name'      => $project['project_name'],
                    'loan_grant_str'    => $project['loan_grant_str'],
                    'dmc'               => $project['dmc'],
                    'sector'            => $project['sector'],
                    'sard_sector_nom'   => $project['sard_sector_nom']  ?? '',
                    'sector_department' => $project['sector_department'] ?? '',
                    'div_nom'           => $project['div_nom'],
                    'project_officer'   => $project['project_officer'],
                    'project_analyst'   => $project['project_analyst'],
                    'country_name'      => $this->csv_reader->get_country_name($project['dmc']),
                    // OI dates and status per project (from first product row)
                    'report_week'       => $project['products'][0]['report_week'] ?? '',
                    'lending_modality'  => $project['product_details'][0]['lending_modality'] ?? ($project['product_details'][0]['type'] ?? ''),
                    'project_status'    => $project['status'] ?? '',
                    // OI safeguard categories from caanddisb first product row
                    'env_category'      => $project['product_details'][0]['env'] ?? '',
                    'ir_category'       => $project['product_details'][0]['ir']  ?? '',
                    'ip_category'       => $project['product_details'][0]['ip']  ?? '',
                    // Key dates — from first product (summary; details in Section A)
                    'approval'          => $project['product_details'][0]['approval']    ?? '',
                    'signing'           => $project['product_details'][0]['signing']     ?? '',
                    'effectivity'       => $project['product_details'][0]['effectivity'] ?? '',
                    'original'          => $project['product_details'][0]['original']    ?? '',
                    'rev_actual'        => $project['product_details'][0]['rev_actual']  ?? '',

                ];

            case 'basic_data':
                // Pull sard_projections for per-product annual CA/Disb targets
                // OI project_cadisb gets this from SARD_Projections_Model per loan_grant_no
                $sard_rows = $this->csv_reader->get_sard_projections($project_no);
                $sard_by_lgn = [];
                foreach ($sard_rows as $sr) {
                    $lgn = trim($sr['loan_grant_no'] ?? '');
                    if ($lgn !== '') $sard_by_lgn[$lgn] = $sr;
                }
                return [
                    'products'        => $project['products'],
                    'product_details' => $project['product_details'] ?? [],
                    'sard_by_lgn'     => $sard_by_lgn,   // OI: annual CA/Disb targets per product
                    'financing'       => $this->csv_reader->get_financing_amounts($project_no),
                    'country'         => $this->csv_reader->get_country_name($project['dmc']),
                ];

            case 'project_rating':
                // Primary source: perfratings.csv (latest report_period for this project)
                // Fields: outputs/technical, ca_percentage, disb_percentage,
                //         fin_mgt, safeguards, perf_rating, ca_rating, disb_rating
                // Fallback: caanddisb latest snapshot perf_* fields
                $perf = $this->csv_reader->get_perf_ratings($project_no) ?? [];
                return [
                    'perf_ratings' => [
                        // Outputs (Technical)
                        'outputs'         => $perf['outputs']         ?? ($perf['technical']           ?? ($project['perf_technical']  ?? '')),
                        // Contract Awards — OI always shows BOTH % and text rating as separate columns
                        'ca_percentage'   => $perf['ca_percentage']   ?? ($project['perf_ca']          ?? ''),
                        'ca_rating'       => $perf['ca_rating']       ?? '',
                        // Disbursement — same: always show both % and text
                        'disb_percentage' => $perf['disb_percentage'] ?? ($project['perf_disb']        ?? ''),
                        'disb_rating'     => $perf['disb_rating']     ?? '',
                        // Financial Management
                        'fin_mgt'         => $perf['fin_mgt']         ?? ($project['perf_fin']         ?? ''),
                        // Safeguards
                        'safeguards'      => $perf['safeguards']      ?? ($project['perf_safeguards']  ?? ''),
                        // Overall
                        'perf_rating'     => $perf['perf_rating']     ?? ($project['perf_rating']      ?? ''),
                        // OI: Val. Status (VALIDATED/INITIAL/ENDORSED)
                        'status'          => strtoupper(trim($perf['status']          ?? '')),
                        // OI: team lead names from perfratings.csv
                        'pp_lead'         => $perf['pp_lead']         ?? '',
                        'pi_lead'         => $perf['pi_lead']         ?? ($project['project_officer']  ?? ''),
                        // OI: report period
                        'report_period'   => $perf['report_period']   ?? '',
                    ],
                    'consecutive' => $this->csv_reader->get_project_quarterly_history($project_no),
                ];

            case 'ca_disbursements_quarterly':
                return $this->csv_reader->get_quarterly_cad($project_no);

            case 'cad_ratios':
                return [
                    'ratios'         => $this->csv_reader->get_section_d_ratios($project_no),
                    'sard'           => $this->csv_reader->get_sard_projections($project_no),
                    'uncontracted'   => $this->csv_reader->get_uncontracted_balance($project_no),
                    'undisbursed'    => $this->csv_reader->get_undisbursed_balance($project_no),
                ];

            default:
                return [];
        }
    }

    private function _handle_row_op($project_no, $section_key, array $op)
    {
        $action        = $op['action']        ?? null;
        $row_uuid      = $op['row_uuid']      ?? null;
        $data          = $op['data']          ?? [];
        $loan_grant_no = $op['loan_grant_no'] ?? null;

        switch ($action) {
            case 'add':
                if (!$row_uuid) $this->_json_error('row_uuid required for add.');
                if (!$this->ppms_model->add_row($project_no, $section_key, $row_uuid, $data, $loan_grant_no, $this->audit_ctx)) {
                    $this->_json_error('Failed to add row.', 500);
                }
                break;

            case 'update':
                if (!$row_uuid) $this->_json_error('row_uuid required for update.');
                if (!$this->ppms_model->update_row($row_uuid, $data, $this->audit_ctx)) {
                    $this->_json_error('Row not found or update failed.', 404);
                }
                break;

            case 'delete':
                if (!$row_uuid) $this->_json_error('row_uuid required for delete.');
                if (!$this->ppms_model->delete_row($row_uuid, $this->audit_ctx)) {
                    $this->_json_error('Row not found or delete failed.', 404);
                }
                break;

            default:
                $this->_json_error('Unknown rows_op action: ' . $action, 400);
        }
    }

    /**
     * Recompute overall project progress from all sections.
     *
     * RULE: This MUST be called after ANY data change that affects progress:
     *   - flat section save (PTL fields)
     *   - tabular row add/edit/delete
     *   - Never hardcode progress values
     *
     * CSV sections (basic_data, ca_disbursements_quarterly, cad_ratios):
     *   → always 100% if project has CSV data
     * Mixed sections (project_info, project_rating):
     *   → section_flat_progress (handles oi_fields pre-fill)
     * PTL flat sections (financial_management, env_safeguards, etc.):
     *   → section_flat_progress based on required fields filled
     * PTL tabular sections (output_delivery, contracts, etc.):
     *   → section_tabular_progress based on row count + field fill
     *
     * Result is computed live per user using their section config.
     * NOT persisted — each DMC+role has its own section config.
     */
    /**
     * RULE: Always called after any data change. Uses section_config to
     * exclude disabled sections from the weighted progress denominator.
     */
    private function _recompute_overall($project_no, $dmc = null)
    {
        $manifest  = $this->progress_calculator->get_section_manifest();
        $app_sects = $this->ppms_model->get_all_sections($project_no);
        $results   = [];

        foreach ($manifest as $sec) {
            $key      = $sec['key'];
            $tabular  = $sec['tabular'];
            $readonly = $sec['readonly'];

            if ($readonly) {
                $has_data = !empty($this->csv_reader->get_products($project_no));
                $results[$key] = ['progress' => $has_data ? 100 : 0, 'status' => $has_data ? 'complete' : 'not_started'];
            } elseif ($key === 'project_info' || $key === 'project_rating') {
                // Mixed sections: compute live with oi_fields always counted
                $fields = $app_sects[$key]['data_json'] ?? [];
                $results[$key] = $this->progress_calculator->section_flat_progress($key, $fields);
            } elseif ($tabular) {
                $rows = $this->ppms_model->get_rows($project_no, $key);
                $results[$key] = $this->progress_calculator->section_tabular_progress($key, $rows);
            } else {
                $fields = $app_sects[$key]['data_json'] ?? [];
                $results[$key] = $this->progress_calculator->section_flat_progress($key, $fields);
            }
        }

        // Use current user's section config to adjust denominator
        $sec_cfg = $this->section_config;
        if (empty($sec_cfg) && !empty($dmc)) {
            $sec_cfg = $this->ppms_model->get_section_config(
                $dmc, $this->current_user['role'] ?? 'ptl'
            );
        }

        return $this->progress_calculator->overall_progress($results, $sec_cfg);
    }

    /**
     * GET /api/portfolio-stats
     * Aggregate CA, disbursement, perf rating, and time-elapsed stats
     * across all projects visible to the current user.
     * Used by the dashboard portfolio overview cards.
     */
    public function portfolio_stats()
    {
        $this->require_ppms_user();

        $dmc  = ($this->current_user['role'] === 'admin') ? null : ($this->current_user['country'] ?? null);

        if ($dmc === null) {
            $projects = [];
            foreach ($this->csv_reader->get_dmc_list() as $_d) {
                $projects = array_merge($projects, $this->csv_reader->get_projects($_d));
            }
        } else {
            $projects = $this->csv_reader->get_projects($dmc);
        }

        $ca_year_act    = 0.0;
        $ca_year_proj   = 0.0;
        $ca_ytd_act     = 0.0;
        $ca_ytd_proj    = 0.0;
        $ca_bal         = 0.0;
        $disb_year_act  = 0.0;
        $disb_year_proj = 0.0;
        $disb_ytd_act   = 0.0;
        $disb_ytd_proj  = 0.0;
        $disb_bal       = 0.0;
        $ratings        = ['On Track' => 0, 'For Attention' => 0, 'At Risk' => 0, 'N/A' => 0];
        $elapse_sum     = 0.0;
        $elapse_cnt     = 0;
        $ahead          = 0;
        $behind         = 0;
        $rated          = 0;

        foreach ($projects as $p) {
            $ca_year_act    += (float)($p['year_actual']      ?? 0);
            $ca_year_proj   += (float)($p['year_projn']       ?? 0);
            $ca_ytd_act     += (float)($p['ytd_actual']       ?? 0);
            $ca_ytd_proj    += (float)($p['ytd_projn']        ?? 0);
            $ca_bal         += (float)($p['ca_bal']           ?? 0);
            $disb_year_act  += (float)($p['disb_year_actual'] ?? 0);
            $disb_year_proj += (float)($p['disb_year_projn']  ?? 0);
            $disb_ytd_act   += (float)($p['disb_ytd_actual']  ?? 0);
            $disb_ytd_proj  += (float)($p['disb_ytd_proj']    ?? 0);
            $disb_bal       += (float)($p['disb_bal']         ?? 0);

            // One rating per project — use overlaid perfratings.csv value (already VALIDATED).
            // perf_rating is set by get_all_perf_ratings_latest() which reads VALIDATED
            // records only — same source as OI's getPerformanceRatingsOld().
            $pr = trim($p['perf_rating'] ?? '');
            if ($pr !== '') {
                if (stripos($pr, 'On Track') !== false)          { $ratings['On Track']++;      $rated++; }
                elseif (stripos($pr, 'For Attention') !== false) { $ratings['For Attention']++; $rated++; }
                elseif (stripos($pr, 'At Risk') !== false)       { $ratings['At Risk']++;       $rated++; }
                // blank or unrecognised → not_rated (same as OI 'not counted')
            }

            $elapse = (float)($p['percent_elapse'] ?? 0);
            if ($elapse > 0) {
                $elapse_sum += $elapse;
                $elapse_cnt++;
                $ca_pct_row = $ca_year_proj > 0 ? ($ca_year_act / $ca_year_proj) : 0;
                if ($ca_pct_row >= $elapse / 100) $ahead++; else $behind++;
            }
        }

        $avg_elapse    = $elapse_cnt  > 0 ? round($elapse_sum / $elapse_cnt, 1) : 0;
        $ca_pct        = $ca_year_proj   > 0 ? round(($ca_year_act   / $ca_year_proj)   * 100, 1) : 0;
        $disb_pct      = $disb_year_proj > 0 ? round(($disb_year_act / $disb_year_proj) * 100, 1) : 0;
        $ca_ytd_pct    = $ca_ytd_proj    > 0 ? round(($ca_ytd_act    / $ca_ytd_proj)    * 100, 1) : null;
        $disb_ytd_pct  = $disb_ytd_proj  > 0 ? round(($disb_ytd_act  / $disb_ytd_proj)  * 100, 1) : null;

        // Expose latest validated quarter string (e.g. "Q12025") for the Ratings card label.
        // Matches OI's HomepageContent which shows the quarter label next to the counts.
        $latest_quarter = $this->csv_reader->get_latest_validated_quarter();

        $this->_json_ok([
            'ca' => [
                'actual'   => round($ca_year_act,    2),
                'projn'    => round($ca_year_proj,   2),
                'pct'      => $ca_pct,
                'ytd_act'  => round($ca_ytd_act,     2),
                'ytd_proj' => round($ca_ytd_proj,    2),
                'ytd_pct'  => $ca_ytd_pct,
                'bal'      => round($ca_bal,          2),
            ],
            'disb' => [
                'actual'   => round($disb_year_act,  2),
                'projn'    => round($disb_year_proj, 2),
                'pct'      => $disb_pct,
                'ytd_act'  => round($disb_ytd_act,   2),
                'ytd_proj' => round($disb_ytd_proj,  2),
                'ytd_pct'  => $disb_ytd_pct,
                'bal'      => round($disb_bal,        2),
            ],
            'ratings' => [
                'on_track'        => $ratings['On Track'],
                'for_attention'   => $ratings['For Attention'],
                'at_risk'         => $ratings['At Risk'],
                'rated'           => $rated,
                'not_rated'       => count($projects) - $rated,
                'needs_attention' => $ratings['For Attention'] + $ratings['At Risk'],
                // OI-aligned: quarter label shown next to the rating counts
                'latest_quarter'  => $latest_quarter,
            ],
            'pace' => [
                'avg_elapse' => $avg_elapse,
                'ca_pct'     => $ca_pct,
                'ahead'      => $ahead,
                'behind'     => $behind,
            ],
        ]);
    }

    /**
     * Get section config for a DMC in the current user's role context.
     */
    private function _get_section_config($dmc)
    {
        $role = $this->current_user['role'] ?? 'ptl';
        if ($role === 'admin' && !empty($dmc)) {
            return $this->ppms_model->get_section_config($dmc, 'admin');
        }
        return $this->section_config;
    }
}
