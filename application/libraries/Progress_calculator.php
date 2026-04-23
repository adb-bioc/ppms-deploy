<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Progress_calculator Library — Production version
 *
 * Section definitions map exactly to the PPMS Excel template sections A–N.
 *
 * Sections A, B (partial), C, D are auto-populated from CSV (read-only).
 * Sections B (reason), E–N are PTL-entered (writable).
 *
 * Progress rules:
 *   Flat section:    progress = filled_required_fields / total_required * 100
 *   Tabular section: blended score (row count adequacy 50% + field fill 50%)
 *   Auto sections:   always 100% if CSV data exists for the project
 *
 * Overall progress = weighted average across all 14 sections.
 *
 * Status thresholds:
 *   0%       → not_started
 *   1–99%    → in_progress
 *   100%     → complete
 */
class Progress_calculator
{
    /**
     * Section manifest: key → definition.
     *
     * 'source'    'csv'    = auto-populated from OI data, always marks complete when data present
     *             'ptl'    = PTL-entered, progress driven by field fill
     * 'tabular'   true     = DataTable (rows), false = FlatForm (key-value fields)
     * 'weight'    contribution to overall (sum = 100)
     * 'required'  fields that must be filled for flat sections
     * 'row_required' fields that must be filled per row for tabular sections
     * 'min_rows'  minimum rows for tabular section to be considered started
     * 'label'     display name matching template section headings
     */
    const SECTION_DEFS = [

        // ── 0. Project Information ────────────────────────────────────────────
        // Header section: OI fields (read-only) + PTL fields (yellow cells).
        // PTL must fill: executing_agency, trjm_date, project_doc_link, handover_doc_link
        'project_info' => [
            'label'     => 'Project Information',
            'source'    => 'mixed',
            'tabular'   => false,
            'weight'    => 3,
            'required'  => ['executing_agency', 'project_doc_link'],
            // oi_fields: pre-filled from CSV, always count toward progress.
            // project_name, project_no, loan_grant_str, sector,
            // project_officer, project_analyst, div_nom, country_name = 8 fields
            'oi_fields' => 8,
        ],

        // ── A. Loan/Grant Basic Data ──────────────────────────────────────────
        // Auto-populated from caanddisb.csv products. Always 100% when project exists.
        'basic_data' => [
            'label'    => 'A. Loan/Grant Basic Data',
            'source'   => 'csv',
            'tabular'  => false,
            'weight'   => 5,
            'required' => [], // CSV-driven; no PTL input required
        ],

        // ── B. Project Rating ─────────────────────────────────────────────────
        // Ratings auto-populated from perfratings.csv.
        // PTL must provide reason narrative when rating is For Attention or At Risk.
        'project_rating' => [
            'label'     => 'B. Project Rating',
            'source'    => 'mixed',
            'tabular'   => false,
            'weight'    => 8,
            'oi_fields' => 6,   // 6 rating rows from perfratings.csv — always present
            'required'  => [],  // reason_fa_ar optional — frontend enforces when FA/AR
        ],

        // ── C. Contract Awards and Disbursements (Quarterly) ─────────────────
        // Auto-populated from caanddisb.csv + sard_projections.csv.
        'ca_disbursements_quarterly' => [
            'label'    => 'C. Contract Awards & Disbursements',
            'source'   => 'csv',
            'tabular'  => false,
            'weight'   => 5,
            'required' => [],
        ],

        // ── D. CAD Ratios (Annual Target vs Actual) ───────────────────────────
        // Fully computed from CSV sources. No PTL input.
        'cad_ratios' => [
            'label'    => 'D. Contract Awards and Disbursements (Annual Target vs. Actual CAD and CAD Ratios)',
            'source'   => 'csv',
            'tabular'  => false,
            'weight'   => 5,
            'required' => [],
        ],

        // ── E. Output Delivery and Procurement ───────────────────────────────
        // PTL-entered per output and procurement package.
        'output_delivery' => [
            'label'       => 'E. Output Delivery & Procurement',
            'source'      => 'ptl',
            'tabular'     => true,
            'weight'      => 12,
            'row_required'=> ['package_no', 'description', 'contractor', 'contract_amount', 'status'],
            'min_rows'    => 1,
        ],

        // ── F. Financial Management Compliance ───────────────────────────────
        // APFS + AEFS dates per fiscal year. PTL-entered.
        'financial_management' => [
            'label'    => 'F. Financial Management Compliance',
            'source'   => 'ptl',
            'tabular'  => false,
            'weight'   => 10,
            'required' => [
                'fiscal_year', 'fm_rating',
                'apfs_timeliness', 'apfs_quality', 'apfs_disclosure',
                'aefs_timeliness', 'aefs_quality', 'aefs_disclosure',
            ],
        ],

        // ── G. Environmental Safeguards ───────────────────────────────────────
        'env_safeguards' => [
            'label'    => 'G. Environmental Safeguards',
            'source'   => 'ptl',
            'tabular'  => false,
            'weight'   => 8,
            'required' => ['iee', 'eia', 'semi_annual_report_due', 'grm', 'cap_current', 'status_of_cap', 'last_monitoring_report_date'],
        ],

        // ── H. Social Safeguards ──────────────────────────────────────────────
        'social_safeguards' => [
            'label'    => 'H. Social Safeguards',
            'source'   => 'ptl',
            'tabular'  => false,
            'weight'   => 8,
            'required' => ['larp', 'due_diligence_report', 'semi_annual_report_due', 'grm', 'cap_current', 'status_of_cap'],
        ],

        // ── I. Contracts ──────────────────────────────────────────────────────
        'contracts' => [
            'label'       => 'I. Contracts',
            'source'      => 'ptl',
            'tabular'     => true,
            'weight'      => 10,
            'row_required'=> ['loan_grant', 'contract_ref', 'description', 'proc_method',
                              'contractor', 'award_date', 'contract_amount', 'contract_status'],
            'min_rows'    => 1,
        ],

        // ── J. Outputs ────────────────────────────────────────────────────────
        'outputs' => [
            'label'       => 'J. Outputs',
            'source'      => 'ptl',
            'tabular'     => true,
            'weight'      => 10,
            'row_required'=> ['output_label', 'indicator', 'baseline', 'target_year', 'current_value', 'weight', 'rating', 'progress_status'],
            'min_rows'    => 2,
        ],

        // ── K. Safeguards Assessment ──────────────────────────────────────────
        // 8 indicators. PTL fills Response/Comments + Rating per indicator.
        'safeguards_assessment' => [
            'label'       => 'K. Safeguards Assessment',
            'source'      => 'ptl',
            'tabular'     => true,
            'weight'      => 8,
            'row_required'=> ['indicator_no', 'indicator_label', 'response_comments'],
            'min_rows'    => 8,
        ],

        // ── L. Gender Action Plan ─────────────────────────────────────────────
        'gender_action_plan' => [
            'label'    => 'L. Gender Action Plan',
            'source'   => 'ptl',
            'tabular'  => false,
            'weight'   => 4,
            'required' => ['gap_category', 'status', 'issues'],
        ],

        // ── M. Missions ───────────────────────────────────────────────────────
        'missions' => [
            'label'       => 'M. Missions',
            'source'      => 'ptl',
            'tabular'     => true,
            'weight'      => 2,
            'row_required'=> ['from_date', 'mission_type'],
            'min_rows'    => 1,
        ],

        // ── N. Major Issues ───────────────────────────────────────────────────
        'major_issues' => [
            'label'       => 'N. Major Issues',
            'source'      => 'ptl',
            'tabular'     => true,
            'weight'      => 2,
            'row_required'=> ['item', 'mitigating_measure', 'responsible_party', 'target_date', 'status'],
            'min_rows'    => 1,
        ],
    ];

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Progress for a flat (non-tabular) PTL section.
     *
     * @param  string $section_key
     * @param  array  $data        Key-value field data from ppms_sections.data_json
     * @return array  ['progress' => int, 'status' => string, 'filled' => int, 'total' => int]
     */
    public function section_flat_progress($section_key, array $data)
    {
        $def = self::SECTION_DEFS[$section_key] ?? null;
        if (!$def || !empty($def['tabular'])) return $this->_result(0, 0, 0);

        // CSV-sourced sections: always complete when called (caller checks CSV exists)
        if (($def['source'] ?? '') === 'csv') {
            return $this->_result(1, 1, 1, 100);
        }

        $required  = $def['required'] ?? [];
        $oi_fields = $def['oi_fields'] ?? 0;

        // Mixed sections with oi_fields: OI part is always filled,
        // PTL part depends on required fields.
        // Total fields = oi_fields + count(required)
        // Filled = oi_fields + count of filled required PTL fields
        if ($oi_fields > 0) {
            $ptl_total  = count($required);
            $ptl_filled = $ptl_total > 0
                ? count(array_filter($required, fn($f) => !empty($data[$f])))
                : 0;
            $total  = $oi_fields + $ptl_total;
            $filled = $oi_fields + $ptl_filled;
            return $this->_result($filled, $total, $total);
        }

        if (empty($required)) return $this->_result(1, 1, 1, 100);

        $filled = count(array_filter($required, fn($f) => !empty($data[$f])));
        return $this->_result($filled, count($required), count($required));
    }

    /**
     * Progress for a tabular section.
     *
     * @param  string  $section_key
     * @param  array[] $rows         Active rows with data_json decoded
     * @return array
     */
    public function section_tabular_progress($section_key, array $rows)
    {
        $def = self::SECTION_DEFS[$section_key] ?? null;
        if (!$def || empty($def['tabular'])) return $this->_result(0, 0, 0);

        $min_rows   = $def['min_rows'] ?? 1;
        $req_fields = $def['row_required'] ?? [];
        $total_rows = count($rows);

        if ($total_rows === 0) return $this->_result(0, $min_rows, $min_rows * count($req_fields));

        $total_fields  = $total_rows * count($req_fields);
        $filled_fields = 0;
        foreach ($rows as $row) {
            $d = is_array($row['data_json']) ? $row['data_json'] : (json_decode($row['data_json'], true) ?? []);
            foreach ($req_fields as $f) {
                if (!empty($d[$f])) $filled_fields++;
            }
        }

        $row_score   = min(1.0, $total_rows / $min_rows);
        $field_score = $total_fields > 0 ? ($filled_fields / $total_fields) : 0.0;
        $progress    = (int) round((($row_score + $field_score) / 2) * 100);

        return $this->_result($filled_fields, $total_fields, $total_fields, $progress);
    }

    /**
     * Overall project progress from an array of section results.
     *
     * @param  array $section_results  [section_key => ['progress' => int, 'status' => string]]
     * @return array ['overall' => int, 'status' => string, 'sections' => [...]]
     */
    /**
     * Overall progress, optionally scoped to enabled sections only.
     *
     * @param  array $section_results  [key => ['progress'=>int, 'status'=>string]]
     * @param  array $enabled_map      [key => 0|1] — missing keys default to enabled
     * @return array
     */
    public function overall_progress(array $section_results, array $enabled_map = [])
    {
        $total_weight   = 0;
        $weighted_score = 0.0;

        $oi_sources = ['csv', 'mixed'];
        foreach (self::SECTION_DEFS as $key => $def) {
            $is_oi   = in_array($def['source'] ?? 'ptl', $oi_sources);
            // OI-sourced sections are always counted — cannot be disabled
            $enabled = $is_oi || (isset($enabled_map[$key]) ? (bool)$enabled_map[$key] : false);
            if (!$enabled) continue;

            $weight = $def['weight'];
            $prog   = $section_results[$key]['progress'] ?? 0;
            $weighted_score += ($prog / 100) * $weight;
            $total_weight   += $weight;
        }

        $overall = $total_weight > 0 ? (int) round(($weighted_score / $total_weight) * 100) : 0;

        return [
            'overall'  => $overall,
            'status'   => $this->_status($overall),
            'sections' => $section_results,
        ];
    }

    /**
     * Section manifest for frontend rendering (SectionTabs).
     * Returns all sections with label, tabular flag, weight, required fields.
     *
     * @return array[]
     */
    public function get_section_manifest()
    {
        $manifest = [];
        foreach (self::SECTION_DEFS as $key => $def) {
            $manifest[] = [
                'key'      => $key,
                'label'    => $def['label'],
                'tabular'  => !empty($def['tabular']),
                'source'   => $def['source'] ?? 'ptl',
                'weight'   => $def['weight'],
                'required' => $def['required']       ?? ($def['row_required'] ?? []),
                'min_rows' => $def['min_rows']        ?? null,
                'readonly' => ($def['source'] ?? '') === 'csv',
            ];
        }
        return $manifest;
    }

    /**
     * Determine if a section is read-only (CSV-sourced).
     *
     * @param  string $section_key
     * @return bool
     */
    public function is_readonly_section($section_key)
    {
        return ($this->SECTION_DEFS[$section_key]['source'] ?? 'ptl') === 'csv';
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function _result($filled, $total, $max, $override = null)
    {
        $progress = $override ?? ($max > 0 ? (int) round($filled / $max * 100) : 0);
        $progress = min(100, max(0, $progress));
        return [
            'progress' => $progress,
            'status'   => $this->_status($progress),
            'filled'   => $filled,
            'total'    => $total,
        ];
    }

    private function _status($progress)
    {
        if ($progress <= 0)   return 'not_started';
        if ($progress >= 100) return 'complete';
        return 'in_progress';
    }
}
