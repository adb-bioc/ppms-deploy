<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Simulation_user_model
 *
 * Builds simulation profiles from real project officers in caanddisb.csv.
 * No hardcoded names — every profile comes from the actual data.
 *
 * Profile rules extracted from CSV:
 *   - Each distinct project_officer gets a PTL profile
 *   - Their country scope = the DMC(s) they appear in
 *   - Officer in ONE DMC only  → role=ptl,   country=that DMC
 *   - Officer in MULTIPLE DMCs → role=admin,  country=null (sees all)
 *   - One super-admin profile is always injected (id='admin_all')
 *     for full system access during development
 *
 * Caching: profiles are cached for 1 hour (CSV rarely changes mid-session).
 * Call clear_cache() after uploading a new CSV export.
 */
class Simulation_user_model extends CI_Model
{
    /** CSV column positions — based on caanddisb.csv header */
    const COL_OFFICER     = 3;
    const COL_ANALYST     = 4;
    const COL_TYPE        = 15;  // Loan / Grant / Cofin
    const COL_MODALITY    = 13;  // lending_modality
    const COL_DMC         = 7;
    const COL_DIV         = 8;
    const COL_STATUS      = 58;
    const COL_DIV_NOM     = 62;
    const COL_FIN_CLOSED  = 63;
    const COL_DEPARTMENT  = 65;

    /** Only include active (non-financially-closed) projects */
    const ACTIVE_STATUSES = ['active', 'on-going', 'on going', 'implementation'];

    /**
     * DMCs excluded from PPMS — mirrors CSV_reader::EXCLUDED_DMCS exactly.
     * Officers whose only projects are in these DMCs will not appear as profiles.
     */
    const EXCLUDED_DMCS = ['AFG', 'MYA', 'NG1', 'NG2'];

    private $_cache_key = 'ppms_sim_profiles';
    private $_cache_ttl = 3600;

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * All simulation profiles, built from caanddisb.csv.
     * Falls back to static defaults if CSV is not present.
     *
     * @return array[]
     */
    public function get_all_profiles()
    {
        $csv_path = defined('PPMS_CSV_PATH') ? PPMS_CSV_PATH : FCPATH . 'csv_data/';
        $file     = $csv_path . 'caanddisb.csv';

        if (!file_exists($file)) {
            log_message('info', 'Simulation_user_model: caanddisb.csv not found, using defaults');
            return $this->_default_profiles();
        }

        // Cache key includes mtime — replacing the CSV instantly busts the cache
        $mtime     = filemtime($file);
        $cache_key = $this->_cache_key . '_' . $mtime;

        $cached = $this->_cache_get($cache_key);
        if ($cached !== null) return $cached;

        // Cache miss — parse CSV (happens once per CSV file version)
        $profiles = $this->_extract_from_csv($file);

        if (empty($profiles)) {
            return $this->_default_profiles();
        }

        $this->_cache_set($cache_key, $profiles, $this->_cache_ttl);
        return $profiles;
    }

    /**
     * Find one profile by id.
     *
     * @param  string $id
     * @return array|null
     */
    public function get_profile_by_id($id)
    {
        if (empty($id)) return null;
        foreach ($this->get_all_profiles() as $p) {
            if ($p['id'] === $id) return $p;
        }
        return null;
    }

    /**
     * Clear profile cache (call after uploading new CSV).
     */
    public function clear_cache()
    {
        $this->CI =& get_instance();
        if (isset($this->CI->ppms_cache)) {
            $this->CI->ppms_cache->delete($this->_cache_key);
        }
        $this->_runtime_cache = [];
    }

    // =========================================================================
    // CSV extraction
    // =========================================================================

    /**
     * Read caanddisb.csv and build one profile per distinct project_officer.
     *
     * @param  string $file  Full path to caanddisb.csv
     * @return array[]
     */
    private function _extract_from_csv($file)
    {
        $handle = fopen($file, 'r');
        if (!$handle) return [];

        // Read and skip the header row
        $header = fgetcsv($handle);
        if (!$header) { fclose($handle); return []; }

        // Collect: officer_name → [dmcs => set, div_nom => string, dept => string]
        $officers  = [];
        $analysts  = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < max(self::COL_OFFICER, self::COL_DMC, self::COL_DIV_NOM) + 1) {
                continue;
            }

            // Skip financially closed projects
            $fin_closed = strtolower(trim($row[self::COL_FIN_CLOSED] ?? ''));
            if (in_array($fin_closed, ['1', 'true', 'yes', 'y'])) continue;

            // Skip cofinancing rows — type=Cofin rows are third-party products,
            // not ADB-managed. Matches CSV_reader filter 2.
            $type = strtolower(trim($row[self::COL_TYPE] ?? ''));
            if (strpos($type, 'cofin') !== false) continue;

            $officer  = $this->_clean_name($row[self::COL_OFFICER] ?? '');
            $analyst  = $this->_clean_name($row[self::COL_ANALYST] ?? '');
            $dmc      = strtoupper(trim($row[self::COL_DMC]     ?? ''));
            $div_nom  = trim($row[self::COL_DIV_NOM]            ?? '');
            $div      = trim($row[self::COL_DIV]                ?? '');
            $dept     = trim($row[self::COL_DEPARTMENT]         ?? '');

            if (empty($officer) || empty($dmc)) continue;

            // Skip restricted DMCs — matches CSV_reader::EXCLUDED_DMCS
            if (in_array($dmc, self::EXCLUDED_DMCS, true)) continue;

            // Skip programme-based modalities — matches CSV_reader::_is_excluded_modality()
            // Officers only assigned to PBL/Programme Grant projects should not appear
            $modality = strtolower(trim($row[self::COL_MODALITY] ?? ''));
            if ((strpos($modality, 'policy') !== false && strpos($modality, 'based') !== false)
                || strpos($modality, 'pbl')           !== false
                || strpos($modality, 'program loan')  !== false
                || strpos($modality, 'program grant')  !== false
                || strpos($modality, 'programmatic')   !== false) {
                continue;
            }

            // Aggregate officer
            if (!isset($officers[$officer])) {
                $officers[$officer] = [
                    'dmcs'    => [],
                    'div_nom' => $div_nom,
                    'div'     => $div,
                    'dept'    => $dept,
                ];
            }
            $officers[$officer]['dmcs'][$dmc] = true;
            // Prefer non-empty div_nom
            if (empty($officers[$officer]['div_nom']) && !empty($div_nom)) {
                $officers[$officer]['div_nom'] = $div_nom;
                $officers[$officer]['div']     = $div;
                $officers[$officer]['dept']    = $dept;
            }

            // Aggregate analyst separately
            if (!empty($analyst) && !isset($officers[$analyst]) && $analyst !== $officer) {
                if (!isset($analysts[$analyst])) {
                    $analysts[$analyst] = [
                        'dmcs'    => [],
                        'div_nom' => $div_nom,
                        'div'     => $div,
                        'dept'    => $dept,
                    ];
                }
                $analysts[$analyst]['dmcs'][$dmc] = true;
            }
        }

        fclose($handle);

        if (empty($officers)) return [];

        // Sort officers alphabetically
        ksort($officers);
        ksort($analysts);

        $profiles = [];

        // ── Super-admin: always first, ONLY admin role in the system ────────
        $profiles[] = [
            'id'              => 'admin_all',
            'name'            => 'Administrator',
            'role'            => 'admin',
            'country'         => null,
            'officer_name'    => null,
            'dmcs'            => [],
            'avatar_initials' => 'AD',
            'description'     => 'Full system access — sees all projects across all DMCs.',
            'div_nom'         => 'SARD',
            'dept'            => '',
        ];

        // ── Project officers → one profile per officer+DMC pair ─────────
        // PPMS is DMC-focused: each context is one officer in one country.
        // An officer with projects in TAJ and KGZ gets two separate cards.
        foreach ($officers as $name => $data) {
            $dmcs = array_keys($data['dmcs']);
            sort($dmcs);

            foreach ($dmcs as $dmc) {
                $profiles[] = [
                    'id'              => $this->_make_id('officer', $name, $dmc),
                    'name'            => $name,
                    'role'            => 'ptl',
                    'country'         => $dmc,
                    'officer_name'    => $name,
                    'dmcs'            => [$dmc],
                    'avatar_initials' => $this->_initials($name),
                    'description'     => $this->_description('ptl', $dmc, $data['div_nom'], false),
                    'div_nom'         => $data['div_nom'],
                    'dept'            => $data['dept'],
                ];
            }
        }

        // ── Project analysts (as PTLs) ──────────────────────────────────────
        foreach ($analysts as $name => $data) {
            // Skip if already added as an officer
            $existing_names = array_column($profiles, 'name');
            if (in_array($name, $existing_names)) continue;

            $dmcs = array_keys($data['dmcs']);
            sort($dmcs);

            // One PTL card per analyst+DMC pair
            foreach ($dmcs as $dmc) {
                $profiles[] = [
                    'id'              => $this->_make_id('analyst', $name, $dmc),
                    'name'            => $name,
                    'role'            => 'ptl',
                    'country'         => $dmc,
                    'officer_name'    => null,
                    'dmcs'            => [$dmc],
                    'avatar_initials' => $this->_initials($name),
                    'description'     => $this->_description('ptl', $dmc, $data['div_nom'], false),
                    'div_nom'         => $data['div_nom'],
                    'dept'            => $data['dept'],
                ];
            }
        }

        return $profiles;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function _clean_name($name)
    {
        $name = trim($name);
        if (empty($name) || strtolower($name) === 'n/a' || $name === '-') return '';
        // Normalise ALL CAPS names to Title Case
        if ($name === strtoupper($name)) {
            $name = ucwords(strtolower($name));
        }
        return $name;
    }

    private function _initials($name)
    {
        $parts = explode(' ', trim($name));
        $init  = '';
        foreach ($parts as $p) {
            if (!empty($p)) $init .= strtoupper($p[0]);
            if (strlen($init) >= 2) break;
        }
        return $init ?: '??';
    }

    private function _make_id($type, $name, $country)
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $name));
        $slug = preg_replace('/_+/', '_', $slug);
        $slug = trim($slug, '_');
        $sfx  = $country ? '_' . strtolower($country) : '_multi';
        return $type . '_' . $slug . $sfx;
    }

    // _make_email removed — emails not used

    private function _description($role, $dmc_str, $div_nom, $is_multi)
    {
        $div = $div_nom ? " ({$div_nom})" : '';
        switch ($role) {
            case 'ptl':
                if ($is_multi) {
                    return "Project Team Lead{$div}. Projects across: {$dmc_str}.";
                }
                return "Project Team Lead{$div}. Scope: {$dmc_str}.";
            case 'ptl':  // analysts default to ptl
                return "Project analyst{$div}. Read-only access for {$dmc_str}.";
        }
        return '';
    }

    // =========================================================================
    // Runtime cache (simple PHP array — no file I/O)
    // =========================================================================

    private $_runtime_cache = [];

    private function _cache_get($key)
    {
        // 1. Runtime (fastest — same request)
        if (isset($this->_runtime_cache[$key])) {
            return $this->_runtime_cache[$key];
        }
        // 2. PPMS_Cache (APCu → file, survives across requests)
        $CI =& get_instance();
        if (isset($CI->ppms_cache)) {
            $val = $CI->ppms_cache->get($key);
            if ($val !== null) {
                $this->_runtime_cache[$key] = $val;
                return $val;
            }
        }
        return null;
    }

    private function _cache_set($key, $value, $ttl = 3600)
    {
        // 1. Runtime cache (this request)
        $this->_runtime_cache[$key] = $value;
        // 2. PPMS_Cache (across requests)
        $CI =& get_instance();
        if (isset($CI->ppms_cache)) {
            $CI->ppms_cache->set($key, $value, $ttl);
        }
    }

    // =========================================================================
    // Static fallback (used when CSV is not present)
    // =========================================================================

    private function _default_profiles()
    {
        return [
            [
                'id'              => 'admin_all',
                'name'            => 'Administrator',
                'role'            => 'admin',
                'country'         => null,
                'officer_name'    => null,
                'dmcs'            => [],
                'avatar_initials' => 'AD',
                'description'     => 'Full system access. Place caanddisb.csv in csv_data/ to see real users.',
                'div_nom'         => '',
                'dept'            => '',
            ],
        ];
    }
}
