<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PPMS_Controller — Self-contained base for all PPMS API controllers
 *
 * PLUG-AND-PLAY DESIGN:
 *   - Extends CI_Controller directly — NOT MY_Controller
 *   - Zero changes to host app autoload.php, hooks.php, or config.php
 *   - Reads host app's simulation session but does not require it
 *   - All PPMS libraries loaded internally, not via autoload
 *   - Controller names prefixed with nothing (use route prefixing instead)
 *
 * PERFORMANCE:
 *   - All libraries loaded once at construction
 *   - Session read: once per request, result cached in properties
 *   - PPMS_Cache handles APCu → file → runtime tier selection automatically
 *
 * HOST APP INTEGRATION:
 *   The host app's session key 'simulation_session' is read to resolve
 *   the effective user. Override via PPMS_SESSION_KEY in ppms.php if
 *   the host app uses a different session structure.
 */
class PPMS_Controller extends CI_Controller
{
    /** @var array|null  Effective user from host session */
    protected $current_user = null;

    /** @var bool */
    protected $is_simulation_mode = false;

    /** @var string  DMC code, or 'ALL' for admin */
    protected $effective_dmc = '';

    /** @var array  Section enabled map for current user context [section_key => 0|1] */
    protected $section_config = [];

    /** @var array  Written to every DB audit row */
    protected $audit_ctx = [];

    public function __construct()
    {
        parent::__construct();

        $this->config->load('ppms', true);

        // Self-bootstrap vendor/autoload.php if CI3's composer_autoload hasn't loaded it.
        // This makes PPMS plug-and-play — no change to OI's config.php composer_autoload
        // setting is required. Safe to run even if autoload was already loaded by CI3.
        $autoload_path = FCPATH . 'vendor/autoload.php';
        if (file_exists($autoload_path) && !class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            require_once $autoload_path;
        }

        // Session must be loaded explicitly — PPMS_Controller extends CI_Controller
        // directly (not MY_Controller), so the host app's session autoload doesn't apply.
        $this->load->library('session');

        // Load all PPMS libraries in one shot — host app autoload untouched
        $this->load->library(['ppms_cache', 'csv_reader', 'progress_calculator']);
        $this->load->model('ppms_model');
        $this->load->helper('url');

        // One session read per request
        $this->_resolve_user_context();
    }

    // ── User context ──────────────────────────────────────────────────────────

    private function _resolve_user_context()
    {
        $key          = defined('PPMS_SESSION_KEY') ? PPMS_SESSION_KEY : 'simulation_session';
        $session_data = $this->session->userdata($key) ?? [];
        $eff          = $session_data['effective_user'] ?? null;
        $act          = $session_data['actual_user']    ?? $eff;

        if (empty($eff)) return;

        $this->current_user       = $eff;
        $this->is_simulation_mode = (bool)($session_data['is_simulation']    ?? true);

        $role    = $eff['role']    ?? '';
        $country = $eff['country'] ?? '';

        // Every PTL now has exactly one country — clean DMC model
        $this->effective_dmc = ($role === 'admin' || $role === 'guest') ? 'ALL' : strtoupper($country);

        // Load section config for this role+DMC context (single-DMC only;
        // admin loads config per-DMC on demand in the controller)
        if (!empty($this->effective_dmc) && $this->effective_dmc !== 'ALL') {
            $this->section_config = $this->ppms_model->get_section_config(
                $this->effective_dmc, $role
            );
        }

        $this->audit_ctx = [
            'real_user_id'      => $act['id']  ?? 'unknown',
            'effective_user_id' => $eff['id']  ?? 'unknown',
            'effective_dmc'     => $this->effective_dmc,
            'is_impersonating'  => (bool)($session_data['is_impersonating'] ?? false),
        ];
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    protected function require_ppms_user()
    {
        if (empty($this->current_user)) {
            $this->_json_error('No active session. Select a simulation profile.', 401);
        }
        if (empty($this->effective_dmc)) {
            $this->_json_error('No effective DMC for this user.', 403);
        }
    }

    protected function assert_project_dmc(array $project)
    {
        $role = $this->current_user['role'] ?? '';
        if ($role === 'admin' || $role === 'guest') return;
        if (strtoupper($project['dmc']) !== strtoupper($this->effective_dmc)) {
            $this->_json_error('Access denied: project not in your DMC.', 403);
        }
    }

    // ── JSON helpers ──────────────────────────────────────────────────────────

    /**
     * Recompute and cache progress for all projects in a DMC.
     * Called after section config changes (DMC + role level).
     * Writes to ppms_projects.overall_progress as a cache hint.
     * NOTE: Dashboard and Workspace always compute progress LIVE per user
     * using their own section config — the cached value is a fallback only.
     */
    protected function _recompute_dmc_progress($dmc, $role = null)
    {
        if (empty($dmc)) return;
        $role    = $role ?: ($this->current_user['role'] ?? 'ptl');
        $sec_cfg = $this->ppms_model->get_section_config($dmc, $role);
        $manifest = $this->progress_calculator->get_section_manifest();
        $projects = $this->ppms_model->get_dmc_progress($dmc);

        if (empty($projects)) return;

        // Load CSV products ONCE for all projects in DMC — avoids N caanddisb streams
        $csv_projects = $this->csv_reader->get_projects($dmc);
        $has_csv_data = [];
        foreach ($csv_projects as $p) {
            $has_csv_data[$p['project_no']] = true;
        }

        // Bulk-load all sections for all projects in one DB query
        $all_pids      = array_column($projects, 'project_id');
        $all_sections  = $this->ppms_model->get_all_sections_bulk($all_pids);

        foreach ($projects as $proj) {
            $pid       = $proj['project_id'];
            $app_sects = $all_sections[$pid] ?? [];
            $results   = [];

            foreach ($manifest as $sec) {
                $key      = $sec['key'];
                $readonly = $sec['readonly'];
                $tabular  = $sec['tabular'];

                if ($readonly) {
                    $has_data      = !empty($has_csv_data[$pid]);
                    $results[$key] = ['progress' => $has_data ? 100 : 0,
                                      'status'   => $has_data ? 'complete' : 'not_started'];
                } elseif ($key === 'project_info' || $key === 'project_rating') {
                    // Mixed sections: compute live with oi_fields always counted
                    $fields        = $app_sects[$key]['data_json'] ?? [];
                    $results[$key] = $this->progress_calculator->section_flat_progress($key, $fields);
                } elseif ($tabular) {
                    $rows          = $this->ppms_model->get_rows($pid, $key);
                    $results[$key] = $this->progress_calculator->section_tabular_progress($key, $rows);
                } else {
                    $fields        = $app_sects[$key]['data_json'] ?? [];
                    $results[$key] = $this->progress_calculator->section_flat_progress($key, $fields);
                }
            }

            $overall = $this->progress_calculator->overall_progress($results, $sec_cfg);
            $this->ppms_model->update_project_progress($pid, $overall['overall'], $overall['status']);
        }
    }

    protected function _json_ok($data = [], $message = 'ok', $code = 200)
    {
        // Use header() + echo + exit instead of CI3's Output class.
        // CI3's set_output() stores JSON internally and relies on the normal
        // request lifecycle to send it. exit() skips that lifecycle, so the
        // stored JSON is never sent — browser receives an empty body.
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(
            ['status' => 'ok', 'message' => $message, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    protected function _json_error($message, $code = 400, $data = [])
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['status' => 'error', 'message' => $message, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    protected function _json_input()
    {
        $raw = $this->input->raw_input_stream;
        if (empty($raw)) $this->_json_error('Empty request body.');
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->_json_error('Invalid JSON: ' . json_last_error_msg());
        }
        return $data;
    }
}
