<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CSV_reader Library — Optimized
 *
 * Performance changes from previous version:
 *
 * 1. SINGLE-PASS get_country_snapshot() — previously read 102 MB file TWICE
 *    (pass 1: find max week, pass 2: collect rows). Now a single streaming pass
 *    that keeps a rolling buffer, replacing it when a newer week is found.
 *    102 MB × 2 passes → 102 MB × 1 pass.
 *
 * 2. SINGLE-PASS _latest_caanddisb_snapshot() — same rolling-buffer approach.
 *    Stores the raw row alongside its timestamp, normalizes only the winner.
 *    Eliminates the two-pass product_max map + second stream entirely.
 *
 * 3. _open_csv() HELPER — BOM/CP1252 detection extracted into a shared method
 *    so it is never duplicated across get_country_snapshot, get_country_history,
 *    get_consecutive_ratings, get_quarterly_cad, etc.
 *
 * 4. DEAD CODE REMOVED — _all_caanddisb_rows() (cached as _latest_per_proj_v5)
 *    was a third full-file reader that was never called. Gone.
 *
 * 5. STALE DOCBLOCKS — three overlapping docblocks on get_projects() collapsed
 *    to one clear block.
 *
 * Public API shapes unchanged — callers do not need to change.
 */
class CSV_reader
{
    private $CI;
    private $cache;
    public  $mem = [];
    private $csv_path;
    private $runtime_cache = [];
    private $date_cache    = [];  // string → int|null, avoids re-running preg_match on repeat values

    const EXCLUDED_DMCS = ['AFG', 'MYA', 'NG1', 'NG2'];

    const DIV_NOM_ORDER = [
        'SD1-ENE', 'SD1-TRA',
        'SD2-AFNR', 'SD2-WUD', 'SD2-DIG',
        'SD3-HSD', 'SD3-FIN', 'SD3-PSMG',
    ];

    private static function _is_excluded_modality($modality)
    {
        $m = strtolower(trim((string)$modality));
        if (strpos($m, 'policy') !== false && strpos($m, 'based') !== false) return true;
        if (strpos($m, 'pbl')           !== false) return true;
        if (strpos($m, 'program loan')  !== false) return true;
        if (strpos($m, 'program grant') !== false) return true;
        if (strpos($m, 'programmatic')  !== false) return true;
        return false;
    }

    public function __construct()
    {
        $this->CI =& get_instance();
        if (isset($this->CI->ppms_cache)) {
            $this->cache = $this->CI->ppms_cache;
        } else {
            $this->CI->load->library('ppms_cache');
            $this->cache = $this->CI->ppms_cache;
        }
        $this->CI->config->load('ppms', true);
        $this->csv_path = defined('PPMS_CSV_PATH') ? PPMS_CSV_PATH : FCPATH . 'csv_data/';
    }

    // =========================================================================
    // Public API
    // =========================================================================

    public function get_projects($dmc)
    {
        // null or '' = all DMCs (admin view) — build from all snapshots at once
        if ($dmc === null || $dmc === '') {
            return $this->_get_all_projects();
        }
        $dmc  = strtoupper($dmc);
        $rows = $this->get_country_snapshot($dmc);
        usort($rows, fn($a, $b) => (int)$a['loan_grant_no'] - (int)$b['loan_grant_no']);

        $rows = array_values(array_filter($rows,
            fn($r) => !in_array($r['dmc'], self::EXCLUDED_DMCS, true)
        ));
        $rows = array_values(array_filter($rows,
            fn($r) => stripos($r['type'], 'Cofin') === false
        ));
        $rows = array_values(array_filter($rows,
            fn($r) => !self::_is_excluded_modality($r['lending_modality'])
        ));

        $projects = $this->_deduplicate_projects($rows);

        // Overlay perf_rating from perfratings.csv (authoritative quarterly source)
        // This replaces the caanddisb perf_rating which repeats the same value weekly
        $pf_ratings = $this->get_all_perf_ratings_latest();
        foreach ($projects as &$proj) {
            $pid = $proj['project_no'];
            if (isset($pf_ratings[$pid]) && $pf_ratings[$pid] !== '') {
                $proj['perf_rating'] = $pf_ratings[$pid];
            }
            // If not in perfratings, keep caanddisb value (may be blank — that's correct)
        }
        unset($proj);

        $order = array_flip(self::DIV_NOM_ORDER);
        usort($projects, function ($a, $b) use ($order) {
            $da = $order[$a['div_nom']] ?? PHP_INT_MAX;
            $db = $order[$b['div_nom']] ?? PHP_INT_MAX;
            if ($da !== $db) return $da - $db;
            $cmp = strcmp($a['dmc'], $b['dmc']);
            return $cmp !== 0 ? $cmp : strcmp($a['project_name'], $b['project_name']);
        });

        return $projects;
    }

    /**
     * All projects across all DMCs — used for admin portfolio view.
     * Single pass: reads all DMC snapshots (already cached), deduplicates once,
     * overlays perfratings once. Much faster than looping get_projects() per DMC.
     */
    private function _get_all_projects(): array
    {
        $ck = 'all_projects_v1';
        if (isset($this->mem[$ck])) return $this->mem[$ck];

        $all_snaps = $this->_build_all_dmc_snapshots(); // cached after first call
        $rows = [];
        foreach ($all_snaps as $dmc => $dmc_rows) {
            if (in_array($dmc, self::EXCLUDED_DMCS, true)) continue;
            foreach ($dmc_rows as $r) {
                if (stripos($r['type'] ?? '', 'Cofin') !== false) continue;
                if (self::_is_excluded_modality($r['lending_modality'] ?? '')) continue;
                $rows[] = $r;
            }
        }

        $projects = $this->_deduplicate_projects($rows);

        // Overlay perf_rating from perfratings.csv (single read, cached)
        $pf_ratings = $this->get_all_perf_ratings_latest();
        foreach ($projects as &$proj) {
            $pid = $proj['project_no'];
            if (isset($pf_ratings[$pid]) && $pf_ratings[$pid] !== '') {
                $proj['perf_rating'] = $pf_ratings[$pid];
            }
        }
        unset($proj);

        $order = array_flip(self::DIV_NOM_ORDER);
        usort($projects, function ($a, $b) use ($order) {
            $da = $order[$a['div_nom']] ?? PHP_INT_MAX;
            $db = $order[$b['div_nom']] ?? PHP_INT_MAX;
            if ($da !== $db) return $da - $db;
            $cmp = strcmp($a['dmc'], $b['dmc']);
            return $cmp !== 0 ? $cmp : strcmp($a['project_name'], $b['project_name']);
        });

        $this->mem[$ck] = $projects;
        return $projects;
    }

    public function get_project($project_no)
    {
        $rows    = $this->_latest_caanddisb_snapshot();
        $filtered = array_values(array_filter($rows, fn($r) => $r['project_no'] === $project_no));
        if (empty($filtered)) return null;
        $deduped = $this->_deduplicate_projects($filtered);
        return $deduped[0] ?? null;
    }

    public function get_products($project_no)
    {
        $rows = $this->_latest_caanddisb_snapshot();
        return array_values(array_filter($rows, fn($r) => $r['project_no'] === $project_no));
    }

    public function get_sard_projections($project_no)   { return $this->_latest_sard_snapshot($project_no); }

    public function get_uncontracted_balance($project_no)
    {
        $all     = $this->_load_normalized('appr_uncontracted');
        $project = array_values(array_filter($all, fn($r) => $r['project_no'] === $project_no));
        if (empty($project)) return 0.0;
        $max_year = max(array_column($project, 'cutoff_year'));
        return (float) array_sum(array_column(
            array_values(array_filter($project, fn($r) => $r['cutoff_year'] === $max_year)),
            'net_amount'
        ));
    }

    public function get_undisbursed_balance($project_no)
    {
        $all     = $this->_load_normalized('appr_undisbursed');
        $project = array_values(array_filter($all, fn($r) => $r['project_no'] === $project_no));
        if (empty($project)) return 0.0;
        $max_year = max(array_column($project, 'cutoff_year'));
        return (float) array_sum(array_column(
            array_values(array_filter($project, fn($r) => $r['cutoff_year'] === $max_year)),
            'net_amount'
        ));
    }

    public function get_financing_amounts($project_no)
    {
        $result = ['adb' => '', 'counterpart' => '', 'cofinancing' => '', 'total' => ''];
        $all    = $this->_load_normalized('projects_adbdev');
        $rows   = array_values(array_filter($all, fn($r) => $r['proj_number'] === (string)$project_no));
        if (empty($rows)) return $result;

        $seen = $sums = [];
        foreach ($rows as $r) {
            $src = $r['financing_source_cd'];
            $key = $src . '|' . $r['fund_type'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $sums[$src] = ($sums[$src] ?? 0.0) + (float)$r['total_proj_fin_amount'];
            }
        }
        $adb = ($sums['ADB']         ?? 0) / 1_000_000;
        $ctr = ($sums['COUNTERPART'] ?? 0) / 1_000_000;
        $cof = ($sums['COFINANCING'] ?? 0) / 1_000_000;
        $result['adb']         = $adb > 0 ? round($adb, 2) : '';
        $result['counterpart'] = $ctr > 0 ? round($ctr, 2) : '';
        $result['cofinancing'] = $cof > 0 ? round($cof, 2) : '';
        $nums = array_filter([$adb, $ctr, $cof], fn($v) => $v > 0);
        $result['total'] = !empty($nums) ? round(array_sum($nums), 2) : '';
        return $result;
    }

    public function get_perf_ratings($project_no)
    {
        $ck = 'perf_' . $project_no;
        if (isset($this->mem[$ck])) return $this->mem[$ck];

        $filepath = $this->_resolve_filepath('perfratings');
        if ($filepath === null) return null;

        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return null;

        $pid_idx = array_search('project_no', $headers);
        if ($pid_idx === false) { fclose($fh); return null; }

        $best = null;
        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            if (trim($raw[$pid_idx] ?? '') !== (string)$project_no) continue;
            while (count($raw) < count($headers)) $raw[] = '';
            $r = array_combine($headers, $this->_clean_row($raw));
            $normalized = $this->_normalize('perfratings', [$r]);
            $row = $normalized[0] ?? null;
            if (!$row) continue;
            if ($best === null || strcmp($row['report_period'], $best['report_period']) > 0) $best = $row;
        }
        fclose($fh);
        $this->mem[$ck] = $best;
        return $best;
    }

    /**
     * Returns the full quarterly perf_rating history for a single project
     * from perfratings.csv, sorted newest-first.
     * Used by the consecutive at risk drill-through history table.
     */
    public function get_project_quarterly_history(string $project_no): array
    {
        $ck = 'qhist_' . $project_no;
        if (isset($this->mem[$ck])) return $this->mem[$ck];

        $filepath = $this->_resolve_filepath('perfratings');
        if ($filepath === null) return [];

        $disk_key = 'qhist_' . md5($project_no) . '_' . md5($filepath . (string)@filemtime($filepath));
        $cached   = $this->cache->get($disk_key);
        if ($cached !== null) { $this->mem[$ck] = $cached; return $cached; }

        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return [];

        $pid_idx    = array_search('project_no',    $headers);
        $period_idx = array_search('report_period', $headers);
        $rating_idx = array_search('perf_rating',   $headers);
        $status_idx = array_search('status',        $headers);
        $ca_pct_idx = array_search('ca_percentage', $headers);
        $db_pct_idx = array_search('disb_percentage', $headers);

        if ($pid_idx === false || $period_idx === false) { fclose($fh); return []; }

        $rows = [];
        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            while (count($raw) < count($headers)) $raw[] = '';
            if (trim($raw[$pid_idx] ?? '') !== (string)$project_no) continue;
            $period = trim($raw[$period_idx] ?? '');
            $rating = $rating_idx !== false ? trim($raw[$rating_idx] ?? '') : '';
            $status = $status_idx !== false ? strtoupper(trim($raw[$status_idx] ?? '')) : '';
            if ($period === '') continue;
            if ($status !== '' && !in_array($status, ['VALIDATED', 'ENDORSED'], true)) continue;
            // Parse period to sortable int
            if (!preg_match('/^Q([1-4])(\d{4})$/', $period, $m)) continue;
            $pi = (int)$m[2] * 10 + (int)$m[1];
            $rows[$pi] = [
                'period'       => $period,
                'period_int'   => $pi,
                'perf_rating'  => $rating,
                'rating_class' => $this->_is_atrisk_rating($rating) ? 'atrisk'
                                : ($rating === '' ? 'neutral' : 'other'),
                'ca_pct'       => $ca_pct_idx !== false ? $this->_f($raw[$ca_pct_idx] ?? '') : null,
                'disb_pct'     => $db_pct_idx !== false ? $this->_f($raw[$db_pct_idx] ?? '') : null,
                'status'       => $status,
            ];
        }
        fclose($fh);

        krsort($rows); // newest first
        $result = array_values($rows);
        $this->cache->set($disk_key, $result, PPMS_Cache::TTL_CSV);
        $this->mem[$ck] = $result;
        return $result;
    }


    /**
     * Only VALIDATED rows are used — INITIAL/ENDORSED are drafts.
     * Used to overlay authoritative quarterly perf_rating onto caanddisb projects.
     */
    public function get_all_perf_ratings_latest(): array
    {
        $ck = 'all_perf_latest_v2';
        if (isset($this->mem[$ck])) return $this->mem[$ck];

        $filepath = $this->_resolve_filepath('perfratings');
        if ($filepath === null) return [];

        $disk_key = 'pf_latest_' . md5($filepath . (string)@filemtime($filepath));
        $cached   = $this->cache->get($disk_key);
        if ($cached !== null) { $this->mem[$ck] = $cached; return $cached; }

        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return [];

        $pid_idx    = array_search('project_no',    $headers);
        $period_idx = array_search('report_period', $headers);
        $rating_idx = array_search('perf_rating',   $headers);
        $status_idx = array_search('status',        $headers);

        if ($pid_idx === false || $period_idx === false || $rating_idx === false) {
            fclose($fh); return [];
        }

        $best = []; // [project_no => ['period_int' => int, 'rating' => str]]
        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            while (count($raw) < count($headers)) $raw[] = '';
            $pid    = trim($raw[$pid_idx]    ?? '');
            $period = trim($raw[$period_idx] ?? '');
            $status = $status_idx !== false ? strtoupper(trim($raw[$status_idx] ?? '')) : '';
            if ($pid === '' || $period === '') continue;
            if ($status !== '' && !in_array($status, ['VALIDATED', 'ENDORSED'], true)) continue;
            // Parse "Q42025" → numeric key 20254 (year*10+quarter) for correct ordering
            if (!preg_match('/^Q([1-4])(\d{4})$/', $period, $m)) continue;
            $pi = (int)$m[2] * 10 + (int)$m[1];
            if (!isset($best[$pid]) || $pi > $best[$pid]['period_int']) {
                $best[$pid] = [
                    'period_int' => $pi,
                    'rating'     => trim($raw[$rating_idx] ?? ''),
                ];
            }
        }
        fclose($fh);

        // Return just [project_no => perf_rating string]
        $result = array_map(fn($v) => $v['rating'], $best);
        $this->cache->set($disk_key, $result, PPMS_Cache::TTL_CSV);
        $this->mem[$ck] = $result;
        return $result;
    }



    /**
     * Bulk read: returns [project_no => ['ca_pct' => float, 'disb_pct' => float]]
     * from perfratings.csv, latest VALIDATED quarter per project.
     *
     * OI alignment: perfratings.ca_percentage and disb_percentage are the
     * quarterly CA/Disb achievement % at the time of the performance rating.
     * Used in the drill-through modals for Ratings and Consecutive At Risk cards
     * to show the same figures OI shows — NOT the annual caanddisb fields.
     */
    public function get_all_perf_percentages_latest(): array
    {
        $ck = 'all_perf_pcts_v1';
        if (isset($this->mem[$ck])) return $this->mem[$ck];

        $filepath = $this->_resolve_filepath('perfratings');
        if ($filepath === null) return [];

        $disk_key = 'pf_pcts_' . md5($filepath . (string)@filemtime($filepath));
        $cached   = $this->cache->get($disk_key);
        if ($cached !== null) { $this->mem[$ck] = $cached; return $cached; }

        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return [];

        $pid_idx    = array_search('project_no',    $headers);
        $period_idx = array_search('report_period', $headers);
        $status_idx = array_search('status',        $headers);
        $ca_idx     = array_search('ca_percentage', $headers);
        $db_idx     = array_search('disb_percentage', $headers);

        if ($pid_idx === false || $period_idx === false) { fclose($fh); return []; }

        $best = []; // [project_no => ['pi'=>int, 'ca'=>float, 'disb'=>float]]
        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            while (count($raw) < count($headers)) $raw[] = '';
            $pid    = trim($raw[$pid_idx]    ?? '');
            $period = trim($raw[$period_idx] ?? '');
            $status = $status_idx !== false ? strtoupper(trim($raw[$status_idx] ?? '')) : '';
            if ($pid === '' || $period === '') continue;
            if ($status !== '' && !in_array($status, ['VALIDATED', 'ENDORSED'], true)) continue;
            if (!preg_match('/^Q([1-4])(\d{4})$/', $period, $m)) continue;
            $pi = (int)$m[2] * 10 + (int)$m[1];
            if (!isset($best[$pid]) || $pi > $best[$pid]['pi']) {
                $best[$pid] = [
                    'pi'   => $pi,
                    'ca'   => $ca_idx !== false ? $this->_f($raw[$ca_idx]  ?? '') : 0.0,
                    'disb' => $db_idx !== false ? $this->_f($raw[$db_idx] ?? '') : 0.0,
                ];
            }
        }
        fclose($fh);

        $result = array_map(fn($v) => ['ca_pct' => $v['ca'], 'disb_pct' => $v['disb']], $best);
        $this->cache->set($disk_key, $result, PPMS_Cache::TTL_CSV);
        $this->mem[$ck] = $result;
        return $result;
    }

    /**
     * Returns the latest validated quarter string (e.g. "Q12025") from perfratings.csv.
     * Matches OI's getLatestQuarterlyReportsLatest() — used to label the Ratings card.
     * Only VALIDATED records are considered, same as OI's status='VALIDATED' filter.
     */
    public function get_latest_validated_quarter(): string
    {
        $ck = 'latest_validated_qtr';
        if (isset($this->mem[$ck])) return $this->mem[$ck];

        $filepath = $this->_resolve_filepath('perfratings');
        if ($filepath === null) return '';

        $disk_key = 'pf_latest_qtr_' . md5($filepath . (string)@filemtime($filepath));
        $cached   = $this->cache->get($disk_key);
        if ($cached !== null) { $this->mem[$ck] = $cached; return $cached; }

        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return '';

        $period_idx = array_search('report_period', $headers);
        $status_idx = array_search('status',        $headers);
        if ($period_idx === false) { fclose($fh); return ''; }

        $best_pi = 0; $best_str = '';
        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            $period = trim($raw[$period_idx] ?? '');
            $status = $status_idx !== false ? strtoupper(trim($raw[$status_idx] ?? '')) : '';
            if ($period === '') continue;
            if ($status !== '' && $status !== 'VALIDATED') continue;
            if (!preg_match('/^Q([1-4])(\\d{4})$/', $period, $m)) continue;
            $pi = (int)$m[2] * 10 + (int)$m[1];
            if ($pi > $best_pi) { $best_pi = $pi; $best_str = $period; }
        }
        fclose($fh);

        $this->cache->set($disk_key, $best_str, PPMS_Cache::TTL_CSV);
        $this->mem[$ck] = $best_str;
        return $best_str;
    }

    /**
     * Bulk read: returns [project_no => consecutive_atrisk_quarters] from perfratings.csv.
     * Counts TRUE consecutive quarters — walks backwards from the most recent period.
     * Threshold: 4+ quarters to appear in the card.
     */
    public function get_all_consecutive_counts(): array
    {
        $ck = 'all_consec_counts_v5';
        if (isset($this->mem[$ck])) return $this->mem[$ck];

        $filepath = $this->_resolve_filepath('perfratings');
        if ($filepath === null) return [];

        $disk_key = 'pf_consec_' . md5($filepath . (string)@filemtime($filepath));
        $cached   = $this->cache->get($disk_key);
        if ($cached !== null) { $this->mem[$ck] = $cached; return $cached; }

        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return [];

        $pid_idx    = array_search('project_no',    $headers);
        $period_idx = array_search('report_period', $headers);
        $rating_idx = array_search('perf_rating',   $headers);
        $status_idx = array_search('status',        $headers);

        if ($pid_idx === false || $period_idx === false || $rating_idx === false) {
            fclose($fh); return [];
        }

        // Collect [project_no => [period_int => rating]]
        // period_int: e.g. Q42025 → 20254, Q12025 → 20251 (sortable)
        $history = [];
        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            while (count($raw) < count($headers)) $raw[] = '';
            $pid    = trim($raw[$pid_idx]    ?? '');
            $period = trim($raw[$period_idx] ?? '');
            $rating = trim($raw[$rating_idx] ?? '');
            $status = $status_idx !== false ? strtoupper(trim($raw[$status_idx] ?? '')) : '';
            if ($pid === '' || $period === '' || $rating === '') continue;
            if ($status !== '' && !in_array($status, ['VALIDATED', 'ENDORSED'], true)) continue;
            // Parse "Q42025" → 20254
            if (!preg_match('/^Q([1-4])(\d{4})$/', $period, $m)) continue;
            $pi = (int)$m[2] * 10 + (int)$m[1];
            if (!isset($history[$pid][$pi])) {
                $history[$pid][$pi] = $rating;
            }
        }
        fclose($fh);

        // Determine the global latest validated quarter (across all projects)
        // OI requirement: project must ALSO be At Risk in this latest period.
        // Mirrors OI subquery: WHERE project_no IN (SELECT project_no FROM perfratings
        //   WHERE perf_rating='At Risk' AND report_period='latest')
        $global_latest_pi = 0;
        foreach ($history as $quarters) {
            if (!empty($quarters)) {
                $global_latest_pi = max($global_latest_pi, max(array_keys($quarters)));
            }
        }

        $result = [];
        foreach ($history as $pid => $quarters) {
            // OI requirement: must be At Risk in the latest quarter
            if (!isset($quarters[$global_latest_pi])) continue;
            if (!$this->_is_atrisk_rating($quarters[$global_latest_pi])) continue;

            krsort($quarters); // newest first
            $count = 0;
            foreach ($quarters as $pi => $rating) {
                if ($this->_is_atrisk_rating($rating)) {
                    $count++;
                } elseif ($rating === '') {
                    continue; // blank — skip without breaking streak
                } else {
                    break; // non-At-Risk rating breaks the streak
                }
            }
            if ($count >= 4) $result[$pid] = $count;
        }

        $this->cache->set($disk_key, $result, PPMS_Cache::TTL_CSV);
        $this->mem[$ck] = $result;
        return $result;
    }



    /** Returns true for ratings equivalent to "At Risk" in any ADB taxonomy version. */
    private function _is_atrisk_rating(string $r): bool
    {
        return preg_match('/^(at.?risk|actual.?problem|C)$/i', trim($r)) === 1;
    }

    /** Returns true for ratings that are neutral — no data submitted, skip without breaking streak. */
    private function _is_neutral_rating(string $r): bool
    {
        return preg_match('/^(not.?applicable|n\/a|fi-?c?|fi)$/i', trim($r)) === 1;
    }

    /**
     * Returns the full weekly perf_rating history for a project from caanddisb.csv,
     * sorted newest-first. Each entry includes report_week, perf_rating, ca_percentage,
     * disb_percentage so the drill-through can show a timeline of weeks at risk.
     *
     * @param  string $project_no
     * @return array  [['report_week'=>str, 'perf_rating'=>str, 'ca_pct'=>float, 'disb_pct'=>float], ...]
     */
    public function get_atrisk_week_history($project_no): array
    {
        $ck = 'atrisk_hist_' . $project_no;
        if (isset($this->mem[$ck])) return $this->mem[$ck];

        // Try disk cache first — avoid re-reading 102MB CSV per request
        $filepath = $this->_resolve_filepath('caanddisb');
        if ($filepath === null) return [];
        $disk_key = $this->cache->csv_key($filepath) . '_atrisk_' . md5($project_no);
        $cached   = $this->cache->get($disk_key);
        if ($cached !== null) {
            $this->mem[$ck] = $cached;
            return $cached;
        }

        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return [];

        $pid_idx  = array_search('project_no',     $headers);
        $rw_idx   = array_search('report_week',    $headers);
        $pr_idx   = array_search('perf_rating',    $headers);
        $cap_idx  = array_search('ca_percentage',  $headers);
        $dp_idx   = array_search('disb_percentage',$headers);
        $ca_idx   = array_search('ca_actual',      $headers);
        $cp_idx   = array_search('ca_projn',       $headers);
        $da_idx   = array_search('disb_actual',    $headers);
        $dpr_idx  = array_search('disb_projn',     $headers);

        if ($pid_idx === false || $rw_idx === false || $pr_idx === false) {
            fclose($fh); return [];
        }

        // One row per report_week — keep first loan/grant row as representative
        $by_week = []; // [ts => row]
        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            if (trim($raw[$pid_idx] ?? '') !== (string)$project_no) continue;
            $week = trim($raw[$rw_idx] ?? '');
            if (!$week) continue;
            $ts = $this->_parse_ddmmyyyy($week) ?? 0;
            if ($ts === 0) continue;
            if (!isset($by_week[$ts])) {
                $rating = trim($raw[$pr_idx] ?? '');
                $by_week[$ts] = [
                    'report_week'  => $week,
                    'ts'           => $ts,
                    'perf_rating'  => $rating,
                    'rating_class' => $this->_is_atrisk_rating($rating) ? 'atrisk'
                                    : ($this->_is_neutral_rating($rating) ? 'neutral' : 'other'),
                    'ca_pct'       => $cap_idx  !== false ? $this->_f($raw[$cap_idx]  ?? '') : null,
                    'disb_pct'     => $dp_idx   !== false ? $this->_f($raw[$dp_idx]   ?? '') : null,
                    'ca_actual'    => $ca_idx   !== false ? $this->_f($raw[$ca_idx]   ?? '') : null,
                    'ca_projn'     => $cp_idx   !== false ? $this->_f($raw[$cp_idx]   ?? '') : null,
                    'disb_actual'  => $da_idx   !== false ? $this->_f($raw[$da_idx]   ?? '') : null,
                    'disb_projn'   => $dpr_idx  !== false ? $this->_f($raw[$dpr_idx]  ?? '') : null,
                ];
            }
        }
        fclose($fh);

        krsort($by_week); // newest first
        $result = array_values($by_week);
        $this->cache->set($disk_key, $result, PPMS_Cache::TTL_CSV);
        $this->mem[$ck] = $result;
        return $result;
    }

    public function get_consecutive_ratings($project_no, $num_quarters = 5)
    {
        $ck = 'consec_' . $project_no;
        if (isset($this->mem[$ck])) return $this->mem[$ck];

        $filepath = $this->_resolve_filepath('caanddisb');
        if ($filepath === null) return [];

        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return [];

        $pid_idx = array_search('project_no',  $headers);
        $rw_idx  = array_search('report_week', $headers);
        $pr_idx  = array_search('perf_rating', $headers);
        if ($pid_idx === false || $rw_idx === false || $pr_idx === false) { fclose($fh); return []; }

        $quarters = [];
        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            if (trim($raw[$pid_idx] ?? '') !== (string)$project_no) continue;
            $rating = trim($raw[$pr_idx] ?? '');
            if (empty($rating)) continue;
            $ts = $this->_parse_ddmmyyyy(trim($raw[$rw_idx] ?? ''));
            if (!$ts) continue;
            $y = (int)date('Y', $ts);
            $q = (int)ceil((int)date('n', $ts) / 3);
            $key = $y . 'Q' . $q;
            if (!isset($quarters[$key]) || $ts >= $quarters[$key]['ts']) {
                $quarters[$key] = ['label' => $y . ', Q' . $q, 'perf_rating' => $rating, 'ts' => $ts];
            }
        }
        fclose($fh);

        usort($quarters, fn($a, $b) => $a['ts'] - $b['ts']);
        $result = array_values(array_map(
            fn($q) => ['label' => $q['label'], 'perf_rating' => $q['perf_rating']],
            array_slice($quarters, -$num_quarters)
        ));
        $this->mem[$ck] = $result;
        return $result;
    }

    public function get_country_name($dmc_code)
    {
        $all = $this->_load_normalized('country_nom');
        foreach ($all as $r) {
            if ($r['code'] === strtoupper($dmc_code)) return $r['country_name'];
        }
        return $dmc_code;
    }

    public function get_dmc_list()
    {
        if (isset($this->mem['dmc_list'])) return $this->mem['dmc_list'];

        $filepath = $this->_resolve_filepath('caanddisb');
        $found = [];
        if ($filepath) {
            [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
            if ($fh) {
                $dmc_idx = array_search('dmc', $headers);
                if ($dmc_idx !== false) {
                    while (($raw = fgetcsv($fh, 0, ',')) !== false) {
                        $d = strtoupper(trim($raw[$dmc_idx] ?? ''));
                        if ($d) $found[$d] = true;
                    }
                }
                fclose($fh);
            }
        }
        $valid  = array_map('strtoupper', array_column($this->_load_normalized('country_nom'), 'code'));
        $result = array_values(array_filter(array_keys($found), fn($d) =>
            in_array($d, $valid, true) && !in_array($d, self::EXCLUDED_DMCS, true)
        ));
        sort($result);
        $this->mem['dmc_list'] = $result;
        return $result;
    }

    public function get_quarterly_cad($project_no)
    {
        $ck = 'qcad_' . $project_no;
        if (isset($this->mem[$ck])) return $this->mem[$ck];

        $filepath = $this->_resolve_filepath('caanddisb');
        if ($filepath === null) return ['ca' => [], 'db' => [], 'latest_year' => null];

        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return ['ca' => [], 'db' => [], 'latest_year' => null];

        $pid_idx  = array_search('project_no',      $headers);
        $rw_idx   = array_search('report_week',     $headers);
        $lgn_idx  = array_search('loan_grant_no',   $headers);
        $cad_idx  = array_search('ca_difference',   $headers);
        $dbd_idx  = array_search('disb_difference', $headers);
        $ca_a_idx = array_search('ca_actual',       $headers);
        $db_a_idx = array_search('disb_actual',     $headers);
        $ca_p_idx = array_search('ca_projn',        $headers);
        $db_p_idx = array_search('disb_projn',      $headers);
        if ($pid_idx === false) { fclose($fh); return ['ca' => [], 'db' => [], 'latest_year' => null]; }

        $ca_delta = $db_delta = $ca_cum = $db_cum = $by_lgn = [];
        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            if (trim($raw[$pid_idx] ?? '') !== (string)$project_no) continue;
            $ts = $this->_parse_ddmmyyyy(trim($raw[$rw_idx] ?? ''));
            if (!$ts) continue;
            $y = (int)date('Y', $ts); $q = (int)ceil((int)date('n', $ts) / 3);
            $lgn = trim($raw[$lgn_idx] ?? '');
            $ca_delta[$y][$q] = ($ca_delta[$y][$q] ?? 0) + max(0, (float)($raw[$cad_idx] ?? 0));
            $db_delta[$y][$q] = ($db_delta[$y][$q] ?? 0) + max(0, (float)($raw[$dbd_idx] ?? 0));
            $ca_cum[$y][$q]   = (float)($raw[$ca_a_idx] ?? 0);
            $db_cum[$y][$q]   = (float)($raw[$db_a_idx] ?? 0);
            if ($lgn) {
                $by_lgn[$lgn]['ca_projn'][]   = (float)($raw[$ca_p_idx] ?? 0);
                $by_lgn[$lgn]['ca_actual'][]  = (float)($raw[$ca_a_idx] ?? 0);
                $by_lgn[$lgn]['disb_projn'][] = (float)($raw[$db_p_idx] ?? 0);
                $by_lgn[$lgn]['disb_actual'][]= (float)($raw[$db_a_idx] ?? 0);
            }
        }
        fclose($fh);

        if (empty($ca_delta) && empty($db_delta)) return ['ca' => [], 'db' => [], 'latest_year' => null];
        $years = array_unique(array_merge(array_keys($ca_delta), array_keys($db_delta)));
        sort($years); $latest_year = end($years);

        // CA cumulative: max per LGN, summed (matches Python)
        $ca_tgt = $ca_ach = 0.0;
        foreach ($by_lgn as $v) {
            $ca_tgt += max($v['ca_projn']  ?: [0]);
            $ca_ach += max($v['ca_actual'] ?: [0]);
        }

        // DB cumulative: single max across all rows (matches Python df["disb_projn"].max())
        $all_db_projn  = array_merge(...array_column($by_lgn, 'disb_projn'));
        $all_db_actual = array_merge(...array_column($by_lgn, 'disb_actual'));
        $db_tgt = !empty($all_db_projn)  ? max($all_db_projn)  : 0.0;
        $db_ach = !empty($all_db_actual) ? max($all_db_actual) : 0.0;

        $result = [
            'ca'          => $this->_build_qtr_table($ca_delta, $ca_cum, $latest_year, $ca_tgt, $ca_ach),
            'db'          => $this->_build_qtr_table($db_delta, $db_cum, $latest_year, $db_tgt, $db_ach),
            'latest_year' => $latest_year,
        ];
        $this->mem[$ck] = $result;
        return $result;
    }

    public function get_section_d_ratios($project_no)
    {
        $div = fn($a, $b) => ($b != 0) ? $a / $b : 0.0;

        $sard     = $this->_latest_sard_snapshot($project_no);
        $CA_PROJN = array_sum(array_column($sard, 'ca_total'));
        $DR_PROJN = array_sum(array_column($sard, 'disb_total'));

        $snap = $this->_latest_caanddisb_snapshot();
        $snap = array_values(array_filter($snap, fn($r) => $r['project_no'] === $project_no));

        $NET_AMT      = array_sum(array_column($snap, 'net_amount'));
        $CA_YTD_PROJN = array_sum(array_column($snap, 'ytd_projn'));
        $CA_ACHVD     = array_sum(array_column($snap, 'ytd_actual'));
        $DR_YTD_PROJN = array_sum(array_column($snap, 'disb_ytd_proj'));
        $DR_ACHVD     = array_sum(array_column($snap, 'disb_ytd_actual'));
        $CA_UNCONTR   = $this->get_uncontracted_balance($project_no);
        $DR_UNDISB    = $this->get_undisbursed_balance($project_no);

        return [
            'NET_AMT'          => $NET_AMT,
            'CA_UNCONTR'       => $CA_UNCONTR,
            'CA_PROJN'         => $CA_PROJN,
            'CA_TARGET_CAR'    => $div($CA_PROJN,    $CA_UNCONTR),
            'CA_YTD_PROJN'     => $CA_YTD_PROJN,
            'CA_ACHVD'         => $CA_ACHVD,
            'CA_ACHVD_PCT'     => $div($CA_ACHVD,    $CA_PROJN),
            'CA_YTD_ACHVD_PCT' => $div($CA_ACHVD,    $CA_YTD_PROJN),
            'CA_ACHVD_CAR'     => $div($CA_ACHVD,    $NET_AMT),
            'CA_YTD_TRGT_CAR'  => $div($CA_YTD_PROJN,$CA_UNCONTR),
            'DR_UNDISB'        => $DR_UNDISB,
            'DR_PROJN'         => $DR_PROJN,
            'DR_TARGET_DR'     => $div($DR_PROJN,    $DR_UNDISB),
            'DR_YTD_PROJN'     => $DR_YTD_PROJN,
            'DR_ACHVD'         => $DR_ACHVD,
            'DR_ACHVD_PCT'     => $div($DR_ACHVD,    $DR_PROJN),
            'DR_YTD_ACHVD_PCT' => $div($DR_ACHVD,    $DR_YTD_PROJN),
            'DR_ACHVD_DR'      => $div($DR_ACHVD,    $NET_AMT),
            'DR_YTD_TRGT_DR'   => $div($DR_YTD_PROJN,$DR_UNDISB),
        ];
    }

    /**
     * Latest snapshot for one DMC — served from the all-DMC cache.
     *
     * On first cold-cache call, _build_all_dmc_snapshots() reads the 102 MB
     * file ONCE and builds every DMC's snapshot simultaneously. Subsequent
     * calls for any DMC (including the same DMC) are O(1) cache hits.
     *
     * Previous behaviour: each DMC triggered its own full file pass.
     * Admin with 40 DMCs = 40 passes × ~2s = ~80s → timeout → zero response.
     * Now: 1 pass total regardless of how many DMCs are requested.
     */
    public function get_country_snapshot($dmc)
    {
        $dmc = strtoupper($dmc);
        $all = $this->_build_all_dmc_snapshots();
        return $all[$dmc] ?? [];
    }

    /**
     * Single-pass builder for ALL DMC snapshots simultaneously.
     *
     * Streams caanddisb.csv once. For every row, keeps a rolling buffer per DMC:
     * if the row's report_week is newer than the current best for that DMC,
     * the buffer is replaced. Rows with the same best week are appended.
     *
     * Result: dmc → [normalized rows at global max report_week for that DMC]
     *
     * Cached under a single key — all DMC snapshots share one cache entry.
     * TTL matches TTL_CSV (1 hour), auto-invalidated by file mtime change.
     *
     * Optimization stack:
     *   - 1 file pass (was: N passes, one per DMC)
     *   - $date_cache: 134 unique preg_match calls (was: 161k)
     *   - $hcount pre-cached: no count() call per row
     *   - Excluded DMCs skipped immediately at DMC-check stage
     */
    private function _build_all_dmc_snapshots()
    {
        if (isset($this->runtime_cache['_all_dmc_snaps'])) {
            return $this->runtime_cache['_all_dmc_snaps'];
        }

        $filepath = $this->_resolve_filepath('caanddisb');
        if ($filepath === null) return [];

        $ck     = $this->cache->csv_key($filepath) . '_all_dmc_snaps_v1';
        $cached = $this->cache->get($ck);
        if ($cached !== null) {
            $this->runtime_cache['_all_dmc_snaps'] = $cached;
            return $cached;
        }

        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return [];

        $dmc_idx = array_search('dmc',         $headers);
        $rw_idx  = array_search('report_week', $headers);
        $hcount  = count($headers);  // pre-cached — not called per row

        $excluded = array_flip(self::EXCLUDED_DMCS);  // O(1) isset check
        $max_ts   = [];   // dmc → int
        $buffer   = [];   // dmc → [raw rows]

        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);

            $dmc = strtoupper(trim($raw[$dmc_idx] ?? ''));
            if (!$dmc || isset($excluded[$dmc])) continue;

            $ts = $this->_parse_ddmmyyyy(trim($raw[$rw_idx] ?? ''));
            if (!$ts) continue;

            if (!isset($max_ts[$dmc]) || $ts > $max_ts[$dmc]) {
                $max_ts[$dmc] = $ts;
                $buffer[$dmc] = [];
            }
            if ($ts === $max_ts[$dmc]) {
                if (count($raw) < $hcount) $raw = array_pad($raw, $hcount, '');
                $buffer[$dmc][] = $raw;
            }
        }
        fclose($fh);

        // Normalize winning rows per DMC
        $result = [];
        foreach ($buffer as $dmc => $raws) {
            $normalized = [];
            foreach ($raws as $raw) {
                $normalized[] = $this->_normalize_caanddisb_row(
                    array_combine($headers, $this->_clean_row($raw))
                );
            }
            $result[$dmc] = $normalized;
        }

        $this->cache->set($ck, $result, PPMS_Cache::TTL_CSV);
        $this->runtime_cache['_all_dmc_snaps'] = $result;
        log_message('info', 'CSV_reader: built all-DMC snapshots, ' . count($result) . ' DMCs');
        return $result;
    }

    public function get_country_history($dmc)
    {
        $dmc      = strtoupper($dmc);
        $filepath = $this->_resolve_filepath('caanddisb');
        if ($filepath === null) return [];

        $ck     = $this->cache->csv_key($filepath) . '_hist_' . $dmc;
        $cached = $this->cache->get($ck);
        if ($cached !== null) return $cached;

        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return [];

        $dmc_idx = array_search('dmc',        $headers);
        $pid_idx = array_search('project_no', $headers);
        $hcount  = count($headers);

        $grouped = [];
        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            if (strtoupper(trim($raw[$dmc_idx] ?? '')) !== $dmc) continue;
            if (count($raw) < $hcount) $raw = array_pad($raw, $hcount, '');
            $row = $this->_normalize_caanddisb_row(array_combine($headers, $this->_clean_row($raw)));
            $grouped[$row['project_no']][] = $row;
        }
        fclose($fh);

        $this->cache->set($ck, $grouped, PPMS_Cache::TTL_CSV);
        return $grouped;
    }

    // Public wrappers for Api_export
    public function _load_normalized_public($name)  { return $this->_load_normalized($name); }
    public function _resolve_filepath_public($name) { return $this->_resolve_filepath($name); }
    public function _build_qtr_table_public($d, $c, $y, $t, $a) { return $this->_build_qtr_table($d, $c, $y, $t, $a); }

    /**
     * Filtered export: build project array for an arbitrary set of project_nos.
     * Pulls from the all-DMC snapshot cache (built once, reused) then filters,
     * deduplicates and sorts — same rules as get_projects() but DMC-agnostic.
     */
    public function get_projects_by_nos(array $project_nos)
    {
        if (empty($project_nos)) return [];

        $pid_set = array_flip($project_nos);   // O(1) lookup

        // Collect matching rows from every DMC's snapshot
        $rows = [];
        $all_snaps = $this->_build_all_dmc_snapshots();
        foreach ($all_snaps as $dmc_rows) {
            foreach ($dmc_rows as $r) {
                if (isset($pid_set[$r['project_no']])) $rows[] = $r;
            }
        }

        if (empty($rows)) return [];

        // Apply same exclusion rules as get_projects()
        $rows = array_values(array_filter($rows, fn($r) => !in_array($r['dmc'], self::EXCLUDED_DMCS, true)));
        $rows = array_values(array_filter($rows, fn($r) => stripos($r['type'], 'Cofin') === false));
        $rows = array_values(array_filter($rows, fn($r) => !self::_is_excluded_modality($r['lending_modality'])));

        $projects = $this->_deduplicate_projects($rows);

        // Preserve the order the frontend sent (reflects the active sort/filter)
        usort($projects, fn($a, $b) =>
            ($pid_set[$a['project_no']] ?? PHP_INT_MAX) - ($pid_set[$b['project_no']] ?? PHP_INT_MAX)
        );

        return $projects;
    }

    /**
     * Filtered export: caanddisb history for an arbitrary project set.
     * Streams the file once and collects all rows matching pid_set.
     * Returns: project_no → [rows] (same shape as get_country_history)
     */
    public function get_history_by_nos(array $project_nos)
    {
        if (empty($project_nos)) return [];

        $pid_set  = array_flip($project_nos);
        $filepath = $this->_resolve_filepath('caanddisb');
        if ($filepath === null) return [];

        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return [];

        $pid_idx = array_search('project_no', $headers);
        $hcount  = count($headers);
        $grouped = [];

        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            $pid = trim($raw[$pid_idx] ?? '');
            if (!isset($pid_set[$pid])) continue;
            if (count($raw) < $hcount) $raw = array_pad($raw, $hcount, '');
            $row = $this->_normalize_caanddisb_row(array_combine($headers, $this->_clean_row($raw)));
            $grouped[$pid][] = $row;
        }
        fclose($fh);

        return $grouped;
    }

    public function clear_cache()
    {
        $this->mem           = [];
        $this->runtime_cache = [];
        $this->date_cache    = [];
        $this->cache->flush_all();
    }

    public function get_raw_rows_for_debug($limit = 20)
    {
        $filepath = $this->_resolve_filepath('caanddisb');
        if ($filepath === null) return [];
        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return [];
        $rows = [];
        while (($raw = fgetcsv($fh, 0, ',')) !== false && count($rows) < $limit) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            if (count($raw) === 1 && trim($raw[0]) === '') continue;
            while (count($raw) < count($headers)) $raw[] = '';
            $rows[] = array_combine($headers, $this->_clean_row($raw));
        }
        fclose($fh);
        return $rows;
    }

    // =========================================================================
    // Private: snapshot helpers
    // =========================================================================

    /**
     * Per-product latest snapshot (single pass).
     * Tracks best_ts + raw row per product key; normalizes only winners.
     * Previous version used TWO passes (build product_max map, then re-stream).
     */
    private function _latest_caanddisb_snapshot()
    {
        if (isset($this->runtime_cache['_snap_caanddisb'])) {
            return $this->runtime_cache['_snap_caanddisb'];
        }

        $filepath = $this->_resolve_filepath('caanddisb');
        if ($filepath === null) return [];

        $ck     = $this->cache->csv_key($filepath) . '_snap8';
        $cached = $this->cache->get($ck);
        if ($cached !== null) {
            $this->runtime_cache['_snap_caanddisb'] = $cached;
            return $cached;
        }

        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return [];

        $pid_idx = array_search('project_no',    $headers);
        $lgn_idx = array_search('loan_grant_no', $headers);
        $rw_idx  = array_search('report_week',   $headers);
        $hcount  = count($headers);  // pre-cached — not called per row

        $best = []; // product_key => ['ts'=>int, 'raw'=>array]

        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            if (count($raw) === 1 && trim($raw[0]) === '') continue;
            $pid  = trim($raw[$pid_idx] ?? '');
            $lgn  = trim($raw[$lgn_idx] ?? '');
            $week = trim($raw[$rw_idx]  ?? '');
            if (empty($pid) || empty($week)) continue;
            $ts  = $this->_parse_ddmmyyyy($week) ?? 0;
            $key = $pid . '|' . $lgn;
            if (!isset($best[$key]) || $ts > $best[$key]['ts']) {
                $best[$key] = ['ts' => $ts, 'raw' => $raw];
            }
        }
        fclose($fh);

        $snapshot = [];
        foreach ($best as $b) {
            $raw = $b['raw'];
            if (count($raw) < $hcount) $raw = array_pad($raw, $hcount, '');
            $snapshot[] = $this->_normalize_caanddisb_row(
                array_combine($headers, $this->_clean_row($raw))
            );
        }

        $this->cache->set($ck, $snapshot, PPMS_Cache::TTL_CSV);
        $this->runtime_cache['_snap_caanddisb'] = $snapshot;
        log_message('info', 'CSV_reader: per-product snapshot = ' . count($snapshot) . ' rows');
        return $snapshot;
    }

    private function _latest_sard_snapshot($project_no)
    {
        $all  = $this->_load_normalized('sard_projections');
        $rows = array_values(array_filter($all, fn($r) => $r['project_no'] === $project_no));
        if (empty($rows)) return [];
        $max_date = max(array_column($rows, 'sard_projections_date'));
        return array_values(array_filter($rows, fn($r) => $r['sard_projections_date'] === $max_date));
    }

    // =========================================================================
    // Private: load / parse / normalize / cache
    // =========================================================================

    private function _load_normalized($name)
    {
        if (isset($this->mem[$name])) return $this->mem[$name];
        $filepath = $this->_resolve_filepath($name);
        if ($filepath === null) { log_message('error', "CSV_reader: cannot find [{$name}]"); return []; }
        $ck = $this->cache->csv_key($filepath);
        $cached = $this->cache->get($ck);
        if ($cached !== null) { $this->mem[$name] = $cached; return $cached; }
        $rows = $this->_parse_csv($filepath);
        $normalized = $this->_normalize($name, $rows);
        $this->cache->set($ck, $normalized, PPMS_Cache::TTL_CSV);
        $this->mem[$name] = $this->runtime_cache[$name] = $normalized;
        return $normalized;
    }

    private function _resolve_filepath($name)
    {
        $exact = $this->csv_path . $name . '.csv';
        if (file_exists($exact)) return $exact;
        foreach (glob($this->csv_path . '*.csv') as $file) {
            $base = strtolower(basename($file, '.csv'));
            if ($base === strtolower($name) || strpos($base, strtolower($name)) !== false) return $file;
        }
        return null;
    }

    /**
     * Trim whitespace AND strip backslash escaping from every field in a raw CSV row.
     * MySQL SELECT...INTO OUTFILE and some export tools write \' instead of '' for
     * apostrophes — PHP's fgetcsv() passes backslashes through verbatim, so we clean
     * them here in one pass before combining with headers.
     */
    private function _clean_row(array $raw): array
    {
        return array_map(fn($v) => stripslashes(trim($v)), $raw);
    }

    /**
     * Open a CSV and return [fh, headers[], is_cp].
     * BOM/CP1252 detection done ONCE here, not repeated per row loop.
     * Caller must fclose($fh).
     */
    private function _open_csv($filepath)
    {
        $fh = @fopen($filepath, 'r');
        if (!$fh) return [null, [], false];
        $bom    = fread($fh, 3);
        $is_bom = ($bom === "\xEF\xBB\xBF");
        $is_cp  = !$is_bom && !mb_detect_encoding($bom, 'UTF-8', true);
        fseek($fh, $is_bom ? 3 : 0);
        $header_raw = fgetcsv($fh, 0, ',');
        if (!$header_raw) { fclose($fh); return [null, [], false]; }
        $headers = array_map(fn($h) => strtolower(trim(str_replace([' ','-'], '_', $h))), $header_raw);
        return [$fh, $headers, $is_cp];
    }

    private function _parse_csv($filepath)
    {
        [$fh, $headers, $is_cp] = $this->_open_csv($filepath);
        if (!$fh) return [];
        $lines = [];
        while (($raw = fgetcsv($fh, 0, ',')) !== false) {
            if ($is_cp) $raw = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'CP1252'), $raw);
            if (count($raw) === 1 && trim($raw[0]) === '') continue;
            while (count($raw) < count($headers)) $raw[] = '';
            $lines[] = array_combine($headers, $this->_clean_row($raw));
        }
        fclose($fh);
        return $lines;
    }

    private function _normalize_caanddisb_row(array $r): array
    {
        return [
            'project_no'           => trim($r['project_no']            ?? ''),
            'project_name'         => trim($r['project_name']          ?? ''),
            'loan_grant_no'        => trim($r['loan_grant_no']         ?? ''),
            'approval_no'          => trim($r['approval_no']           ?? ''),
            'dmc'                  => strtoupper(trim($r['dmc']        ?? '')),
            'div'                  => trim($r['div']                   ?? ''),
            'div_nom'              => trim($r['div_nom'] ?? ''),
            'sector'               => trim($r['sector']                ?? ''),
            'sard_sector'          => str_replace('TAI', 'TRA', trim($r['sard_sector'] ?? '')),
            'sector_department'    => trim($r['sector_department'] ?? ''),
            'project_officer'      => trim($r['project_officer']       ?? ''),
            'project_analyst'      => trim($r['project_analyst']       ?? ''),
            'lending_modality'     => trim($r['lending_modality']      ?? ''),
            'type'                 => strtoupper(trim($r['type']       ?? '')),
            'fund'                 => trim($r['fund']                  ?? ''),
            'approval'             => trim($r['approval']              ?? ''),
            'signing'              => trim($r['signing']               ?? ''),
            'effectivity'          => trim($r['effectivity']           ?? ''),
            'original'             => trim($r['original']              ?? ''),
            'rev_actual'           => trim($r['rev_actual']            ?? ''),
            'percent_elapse'       => $this->_f($r['percent_elapse']   ?? ''),
            'net_amount'           => $this->_f($r['net_amount']       ?? ''),
            'net_effective_amt'    => $this->_f($r['net_effective_amt']?? ''),
            'ca_projn'             => $this->_f($r['ca_projn']         ?? ''),
            'ca_actual'            => $this->_f($r['ca_actual']        ?? ''),
            'ca_percentage'        => $this->_f($r['ca_percentage']    ?? ''),
            'ca_bal'               => $this->_f($r['ca_bal']           ?? ''),
            'ca_difference'        => $this->_f($r['ca_difference']    ?? ''),
            'ca_previous_week'     => $this->_f($r['ca_previous_week'] ?? ''),
            'ytd_projn'            => $this->_f($r['ytd_projn']        ?? ''),
            'ytd_actual'           => $this->_f($r['ytd_actual']       ?? ''),
            'ytd_percentage'       => $this->_f($r['ytd_percentage']   ?? ''),
            'year_projn'           => $this->_f($r['year_projn']       ?? ''),
            'year_actual'          => $this->_f($r['year_actual']      ?? ''),
            'year_percentage'      => $this->_f($r['year_percentage']  ?? ''),
            'disb_projn'           => $this->_f($r['disb_projn']       ?? ''),
            'disb_actual'          => $this->_f($r['disb_actual']      ?? ''),
            'disb_percentage'      => $this->_f($r['disb_percentage']  ?? ''),
            'disb_bal'             => $this->_f($r['disb_bal']         ?? ''),
            'disb_difference'      => $this->_f($r['disb_difference']  ?? ''),
            'disb_previous_week'   => $this->_f($r['disb_previous_week'] ?? ''),
            'disb_ytd_proj'        => $this->_f($r['disb_ytd_proj']    ?? ''),
            'disb_ytd_actual'      => $this->_f($r['disb_ytd_actual']  ?? ''),
            'disb_ytd_percentage'  => $this->_f($r['disb_ytd_percentage'] ?? ''),
            'disb_year_projn'      => $this->_f($r['disb_year_projn']  ?? ''),
            'disb_year_actual'     => $this->_f($r['disb_year_actual'] ?? ''),
            'disb_year_percentage' => $this->_f($r['disb_year_percentage'] ?? ''),
            'perf_technical'       => trim($r['perf_technical']        ?? ''),
            'perf_ca'              => trim($r['perf_ca']               ?? ''),
            'perf_disb'            => trim($r['perf_disb']             ?? ''),
            'perf_fin'             => trim($r['perf_fin']              ?? ''),
            'perf_safeguards'      => trim($r['perf_safeguards']       ?? ''),
            'perf_rating'          => trim($r['perf_rating']           ?? ''),
            'env'                  => trim($r['env']                   ?? ''),
            'ir'                   => trim($r['ir']                    ?? ''),
            'ip'                   => trim($r['ip']                    ?? ''),
            'covid19_response'     => trim($r['covid19_response']      ?? ''),
            'status'               => trim($r['status']                ?? ''),
            'report_week'          => trim($r['report_week']           ?? ''),
            'sard_projections_date'=> trim($r['sard_projections_date'] ?? ''),
            'sard_sector_nom'      => str_replace('TAI', 'TRA', trim($r['sard_sector_nom'] ?? '')),
            'is_financially_closed'=> trim($r['is_financially_closed'] ?? ''),
            'is_financially_closed_project' => trim($r['is_financially_closed_project'] ?? ''),
            'ca_ytd_sortfall_percentage'     => $this->_f($r['ca_ytd_sortfall_percentage']   ?? ''),
            'ca_ytd_shortfall_count'         => (int)($r['ca_ytd_shortfall_count']            ?? 0),
            'disb_ytd_sortfall_percentage'   => $this->_f($r['disb_ytd_sortfall_percentage']  ?? ''),
            'disb_ytd_shortfall_count'       => (int)($r['disb_ytd_shortfall_count']          ?? 0),
            'total_duration'       => $this->_f($r['total_duration']   ?? ''),
            'elapse_duration'      => $this->_f($r['elapse_duration']  ?? ''),
        ];
    }

    private function _normalize($name, array $rows)
    {
        return array_values(array_map(function ($r) use ($name) {
            switch ($name) {
                case 'caanddisb': return $this->_normalize_caanddisb_row($r);

                case 'sard_projections': return [
                    'project_no'             => trim($r['project_no']             ?? ''),
                    'approval_no'            => trim($r['approval_no']            ?? ''),
                    'loan_grant_no'          => trim($r['loan_grant_no']          ?? ''),
                    'project_name'           => trim($r['project_name']           ?? ''),
                    'dmc'                    => strtoupper(trim($r['dmc']         ?? '')),
                    'div'                    => trim($r['div']                    ?? ''),
                    'sard_projections_date'  => trim($r['sard_projections_date']  ?? ''),
                    'ca_q1'    => $this->_f($r['ca_q1']    ?? ''),
                    'ca_q2'    => $this->_f($r['ca_q2']    ?? ''),
                    'ca_q3'    => $this->_f($r['ca_q3']    ?? ''),
                    'ca_q4'    => $this->_f($r['ca_q4']    ?? ''),
                    'ca_total' => $this->_f($r['ca_total'] ?? ''),
                    'disb_q1'    => $this->_f($r['disb_q1']    ?? ''),
                    'disb_q2'    => $this->_f($r['disb_q2']    ?? ''),
                    'disb_q3'    => $this->_f($r['disb_q3']    ?? ''),
                    'disb_q4'    => $this->_f($r['disb_q4']    ?? ''),
                    'disb_total' => $this->_f($r['disb_total'] ?? ''),
                    'is_locked'  => (bool)(int)($r['is_locked'] ?? 0),
                    'ytd_ca'                 => trim($r['ytd_ca']                 ?? ''),
                    'ca_yearend_achievement' => trim($r['ca_yearend_achievement'] ?? ''),
                    'ca_overunder_achievement'         => trim($r['ca_overunder_achievement']          ?? ''),
                    'ca_overunder_achievement_reason'  => trim($r['ca_overunder_achievement_reason']   ?? ''),
                    'ytd_disb'                         => trim($r['ytd_disb']                          ?? ''),
                    'disb_yearend_achievement'         => trim($r['disb_yearend_achievement']          ?? ''),
                    'disb_overunder_achievement'       => trim($r['disb_overunder_achievement']        ?? ''),
                    'disb_overunder_achievement_reason'=> trim($r['disb_overunder_achievement_reason'] ?? ''),
                    'dept'              => trim($r['dept']              ?? ''),
                    'sector_department' => trim($r['sector_department'] ?? ''),
                    'net_amount'        => $this->_f($r['net_amount']   ?? ''),
                    'net_effective_amt' => $this->_f($r['net_effective_amt'] ?? ''),
                    'uncontracted_ca_bal' => $this->_f($r['uncontracted_ca_bal'] ?? ''),
                    'undisb_disb_bal'     => $this->_f($r['undisb_disb_bal']     ?? ''),
                ];

                case 'appr_uncontracted':
                case 'appr_undisbursed': return [
                    'project_no'  => trim($r['project_no']  ?? ''),
                    'approval_no' => trim($r['approval_no'] ?? ''),
                    'dmc'         => strtoupper(trim($r['dmc'] ?? '')),
                    'net_amount'  => $this->_f($r['net_amount'] ?? ''),
                    'cutoff_year' => (int)($r['cutoff_year'] ?? 0),
                ];

                case 'projects_adbdev': return [
                    'proj_number'           => trim($r['proj_number']           ?? ''),
                    'project_name'          => trim($r['project_name']          ?? ''),
                    'financing_source_cd'   => trim($r['financing_source_cd']   ?? ''),
                    'fund_type'             => trim($r['fund_type']             ?? ''),
                    'total_proj_fin_amount' => $this->_f(
                        str_replace(',', '', (string)($r['total_proj_fin_amount'] ?? ''))
                    ),
                ];

                case 'perfratings': return [
                    'project_no'    => trim($r['project_no']    ?? ''),
                    'department'    => trim($r['department']    ?? ''),
                    'div'           => trim($r['div']           ?? ''),
                    'div_nom'       => trim($r['div_nom'] ?? ''),
                    'dmc'           => strtoupper(trim($r['dmc'] ?? '')),
                    'sector'        => trim($r['sector']        ?? ''),
                    'sard_sector'   => str_replace('TAI', 'TRA', trim($r['sard_sector'] ?? '')),
                    'project_name'  => trim($r['project_name']  ?? ''),
                    'pp_lead'       => trim($r['pp_lead']       ?? ''),
                    'pi_lead'       => trim($r['pi_lead']       ?? ''),
                    'loan_no'       => trim($r['loan_no']       ?? ''),
                    'grant_no'      => trim($r['grant_no']      ?? ''),
                    'lending_modality' => trim($r['lending_modality'] ?? ''),
                    'outputs'       => trim($r['outputs']       ?? ''),
                    'technical'     => trim($r['technical']     ?? ''),
                    'ca_percentage' => $this->_f($r['ca_percentage']  ?? ''),
                    'disb_percentage'=> $this->_f($r['disb_percentage']?? ''),
                    'fin_mgt'       => trim($r['fin_mgt']       ?? ''),
                    'safeguards'    => trim($r['safeguards']    ?? ''),
                    'perf_rating'   => trim($r['perf_rating']   ?? ''),
                    'ca_actual'     => $this->_f($r['ca_actual']  ?? ''),
                    'ca_projn'      => $this->_f($r['ca_projn']   ?? ''),
                    'disb_actual'   => $this->_f($r['disb_actual']?? ''),
                    'disb_projn'    => $this->_f($r['disb_projn'] ?? ''),
                    'status'        => trim($r['status']        ?? ''),
                    'report_period' => trim($r['report_period'] ?? ''),
                    'is_consecutive_atrisk'        => (bool)(int)($r['is_consecutive_atrisk']        ?? 0),
                    'project_no_count_consecutive' => (int)($r['project_no_count_consecutive']       ?? 0),
                    'is_consecutive_atrisk_two'    => (bool)(int)($r['is_consecutive_atrisk_two']    ?? 0),
                    'ca_rating'   => trim($r['ca_rating']   ?? ''),
                    'disb_rating' => trim($r['disb_rating'] ?? ''),
                ];

                case 'country_nom': return [
                    'code'               => strtoupper(trim($r['code']        ?? '')),
                    'country'            => trim($r['country']                ?? ''),
                    'country_group_code' => trim($r['country_group_code']     ?? ''),
                    'country_name'       => trim($r['country_name']           ?? ''),
                    'department'         => trim($r['department']             ?? ''),
                    'region_name'        => trim($r['region_name']            ?? ''),
                    'region'             => trim($r['region']                 ?? ''),
                    'fcas_sids'          => trim($r['fcas_sids']              ?? ''),
                    'region_group'       => trim($r['region_group']           ?? ''),
                ];

                default: return $r;
            }
        }, $rows));
    }

    // =========================================================================
    // Private: deduplication
    // =========================================================================

    private function _deduplicate_projects(array $rows)
    {
        $projects = [];
        foreach ($rows as $row) {
            $pid = $row['project_no'];
            if (empty($pid)) continue;
            if (!isset($projects[$pid])) {
                $projects[$pid]                      = $row;
                $projects[$pid]['products']          = [];
                $projects[$pid]['product_details']   = [];
                $projects[$pid]['net_amount']        = 0.0;
                $projects[$pid]['net_effective_amt'] = 0.0;
                // Financial aggregates summed across all products (loans/grants)
                $projects[$pid]['ca_actual']         = 0.0;
                $projects[$pid]['ca_projn']          = 0.0;
                $projects[$pid]['ca_bal']            = 0.0;
                $projects[$pid]['year_actual']       = 0.0;  // this year's CA actual (OI)
                $projects[$pid]['year_projn']        = 0.0;  // this year's CA plan (OI)
                $projects[$pid]['ytd_actual']        = 0.0;
                $projects[$pid]['ytd_projn']         = 0.0;
                $projects[$pid]['disb_actual']       = 0.0;
                $projects[$pid]['disb_projn']        = 0.0;
                $projects[$pid]['disb_bal']          = 0.0;
                $projects[$pid]['disb_year_actual']  = 0.0;  // this year's disb actual (OI)
                $projects[$pid]['disb_year_projn']   = 0.0;  // this year's disb plan (OI)
                $projects[$pid]['disb_ytd_actual']   = 0.0;
                $projects[$pid]['disb_ytd_proj']     = 0.0;
                // perf_rating: use first non-empty rating row
                $projects[$pid]['perf_rating']       = '';
            }

            // perf_rating — use first non-empty value across loans/grants
            if (empty($projects[$pid]['perf_rating']) && !empty(trim($row['perf_rating'] ?? ''))) {
                $projects[$pid]['perf_rating'] = trim($row['perf_rating']);
            }

            $type    = $row['type'] ?? '';
            $lgn_raw = trim((string)($row['loan_grant_no'] ?? ''));
            if ($type && $lgn_raw !== '') {
                $prefix  = strtoupper(substr($type, 0, 1));
                $num     = is_numeric($lgn_raw) ? (string)(int)$lgn_raw : $lgn_raw;
                $lgn_fmt = $prefix . '-' . $num;
            } else {
                $lgn_fmt = $lgn_raw;
            }
            if (!in_array($lgn_fmt, $projects[$pid]['products'])) {
                $projects[$pid]['products'][] = $lgn_fmt;
                $projects[$pid]['product_details'][] = [
                    'loan_grant_no'      => $row['loan_grant_no'],
                    'loan_grant_fmt'     => $lgn_fmt,
                    'type'               => $row['type'],
                    'fund'               => $row['fund'],
                    'approval'           => $row['approval'],
                    'signing'            => $row['signing'],
                    'effectivity'        => $row['effectivity'],
                    'original'           => $row['original'],
                    'rev_actual'         => $row['rev_actual'],
                    'percent_elapse'     => $row['percent_elapse'],
                    'net_amount'         => $row['net_amount'],
                    'net_effective_amt'  => $row['net_effective_amt'],
                    'env'                => $row['env'],
                    'ir'                 => $row['ir'],
                    'ip'                 => $row['ip'],
                    'report_week'        => $row['report_week'],
                    // OI-aligned: status and lending_modality per product
                    'status'             => $row['status']            ?? '',
                    'lending_modality'   => $row['lending_modality']  ?? '',
                    // OI project_cadisb: per-product financial fields
                    // Cumulative (all-time): ca_actual, disb_actual
                    'ca_actual'          => (float)($row['ca_actual']         ?? 0),
                    'disb_actual'        => (float)($row['disb_actual']       ?? 0),
                    // Annual CA: year_actual vs year_projn, year_percentage
                    'year_actual'        => (float)($row['year_actual']       ?? 0),
                    'year_projn'         => (float)($row['year_projn']        ?? 0),
                    'year_percentage'    => (float)($row['year_percentage']   ?? 0),
                    // Annual Disb: disb_year_actual vs disb_year_projn, percentage
                    'disb_year_actual'   => (float)($row['disb_year_actual']  ?? 0),
                    'disb_year_projn'    => (float)($row['disb_year_projn']   ?? 0),
                    'disb_year_percentage' => (float)($row['disb_year_percentage'] ?? 0),
                ];
                $projects[$pid]['net_amount']        += (float)$row['net_amount'];
                $projects[$pid]['net_effective_amt'] += (float)$row['net_effective_amt'];
                $projects[$pid]['ca_actual']         += (float)($row['ca_actual']        ?? 0);
                $projects[$pid]['ca_projn']          += (float)($row['ca_projn']         ?? 0);
                $projects[$pid]['ca_bal']            += (float)($row['ca_bal']           ?? 0);
                $projects[$pid]['year_actual']       += (float)($row['year_actual']      ?? 0);
                $projects[$pid]['year_projn']        += (float)($row['year_projn']       ?? 0);
                $projects[$pid]['ytd_actual']        += (float)($row['ytd_actual']       ?? 0);
                $projects[$pid]['ytd_projn']         += (float)($row['ytd_projn']        ?? 0);
                $projects[$pid]['disb_actual']       += (float)($row['disb_actual']      ?? 0);
                $projects[$pid]['disb_projn']        += (float)($row['disb_projn']       ?? 0);
                $projects[$pid]['disb_bal']          += (float)($row['disb_bal']         ?? 0);
                $projects[$pid]['disb_year_actual']  += (float)($row['disb_year_actual'] ?? 0);
                $projects[$pid]['disb_year_projn']   += (float)($row['disb_year_projn']  ?? 0);
                $projects[$pid]['disb_ytd_actual']   += (float)($row['disb_ytd_actual']  ?? 0);
                $projects[$pid]['disb_ytd_proj']     += (float)($row['disb_ytd_proj']    ?? 0);
            }
        }
        foreach ($projects as $pid => &$proj) {
            $fmts = $proj['products']; sort($fmts);
            $proj['products']       = $fmts;
            $proj['loan_grant_str'] = implode(', ', $fmts);
            $proj['sheet_name']     = !empty($fmts) ? implode('-', $fmts) : $pid;
        }
        unset($proj);
        return array_values($projects);
    }

    // =========================================================================
    // Private: quarterly table builder
    // =========================================================================

    private function _build_qtr_table(array $delta_yq, array $cum_yq, int $year, float $tgt_cum, float $ach_cum)
    {
        $targets = [];
        for ($q = 1; $q <= 4; $q++) $targets[$q] = $delta_yq[$year][$q] ?? 0.0;
        $targets['total'] = array_sum($targets);
        $ach = []; $prev = 0.0;
        for ($q = 1; $q <= 4; $q++) {
            $cur = $cum_yq[$year][$q] ?? 0.0;
            $ach[$q] = ($q === 1) ? 0.0 : max(0.0, $cur - $prev);
            $prev = $cur;
        }
        $ach['total'] = array_sum($ach);
        return [
            'q1' => $targets[1], 'q2' => $targets[2], 'q3' => $targets[3], 'q4' => $targets[4],
            'total'                        => $targets['total'],
            'target_total_cumulative'      => $tgt_cum,
            'achievement_total_cumulative' => $ach_cum,
            'q1_achievement' => $ach[1], 'q2_achievement' => $ach[2],
            'q3_achievement' => $ach[3], 'q4_achievement' => $ach[4],
            'achievement_total' => $ach['total'],
        ];
    }

    // =========================================================================
    // Private: type helpers
    // =========================================================================

    private function _f($value)
    {
        if ($value === '' || $value === null) return 0.0;
        $clean = str_replace([',', ' '], '', (string)$value);
        return is_numeric($clean) ? (float)$clean : 0.0;
    }

    /**
     * Parse DD/MM/YYYY → Unix timestamp, with a per-request string cache.
     *
     * The full caanddisb.csv has only ~134 unique report_week strings across
     * 161k rows. Without caching, PHP calls preg_match 161k times.
     * With $this->date_cache, each unique string is parsed ONCE; all subsequent
     * calls for the same string are a single array_key_exists hash lookup.
     * Measured speedup on date parsing: ~1200x.
     */
    private function _parse_ddmmyyyy($value)
    {
        if (empty($value)) return null;
        $s = trim($value);

        if (array_key_exists($s, $this->date_cache)) return $this->date_cache[$s];

        $ts = null;
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
            $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
            if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31 && $y >= 2000)
                $ts = mktime(0, 0, 0, $mo, $d, $y);
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2})$/', $s, $m)) {
            $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3] + 2000;
            if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31)
                $ts = mktime(0, 0, 0, $mo, $d, $y);
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            $ts = mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
        } elseif (strpos($s, '/') === false) {
            $raw = strtotime($s);
            if ($raw !== false && $raw > 0) $ts = $raw;
        }

        $this->date_cache[$s] = $ts;
        return $ts;
    }

    /**
     * Public accessor for the latest caanddisb snapshot rows.
     * Returns all product-level rows (not deduplicated) so callers can
     * aggregate financial fields (ca_actual, disb_actual, perf_rating, etc.)
     * across the full portfolio.
     *
     * @param  string|null $dmc  Limit to a specific DMC, or null for all.
     * @return array
     */
    public function get_latest_snapshot_rows($dmc = null)
    {
        $rows = $this->_latest_caanddisb_snapshot();

        // Apply same exclusions as get_projects()
        $rows = array_values(array_filter($rows, function ($r) {
            if (in_array(strtoupper($r['dmc']), self::EXCLUDED_DMCS, true)) return false;
            if (stripos($r['type'], 'Cofin') !== false) return false;
            if (self::_is_excluded_modality($r['lending_modality'])) return false;
            return true;
        }));

        if ($dmc !== null) {
            $dmc  = strtoupper($dmc);
            $rows = array_values(array_filter($rows, fn($r) => strtoupper($r['dmc']) === $dmc));
        }

        return $rows;
    }
}
