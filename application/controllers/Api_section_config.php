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
 * Api_section_config — GET and POST section enable/disable settings.
 *
 * Each config is scoped to: DMC + role (e.g. TAJ + ptl).
 * Users can only configure their own DMC + role context.
 * Admin can read/write any DMC but only for the 'admin' role.
 *
 * Routes:
 *   GET  /api/section-config/{dmc}       → get($dmc)
 *   POST /api/section-config/{dmc}       → save($dmc)
 */
class Api_section_config extends PPMS_Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    // -------------------------------------------------------------------------
    // GET /api/section-config/{dmc}
    // -------------------------------------------------------------------------
    public function get($dmc)
    {
        $this->require_ppms_user();
        $dmc  = strtoupper($dmc);
        $role = $this->current_user['role'] ?? 'ptl';

        $this->_assert_can_access_dmc($dmc);

        $config  = $this->ppms_model->get_section_config($dmc, $role);
        $manifest = $this->progress_calculator->get_section_manifest();

        // Build full list: all sections with their enabled state (default true)
        $sections = [];
        foreach ($manifest as $sec) {
            $key = $sec['key'];
            $sections[] = [
                'key'      => $key,
                'label'    => $sec['label'],
                'source'   => $sec['source'],
                'weight'   => $sec['weight'],
                'tabular'  => $sec['tabular'],
                'readonly' => $sec['readonly'],
                'enabled'  => isset($config[$key]) ? (bool)$config[$key] : true,  // default all sections enabled
            ];
        }

        $this->_json_ok([
            'dmc'      => $dmc,
            'role'     => $role,
            'sections' => $sections,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/section-config/{dmc}
    // -------------------------------------------------------------------------
    public function save($dmc)
    {
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            $this->_json_error('POST required.', 405);
        }
        $this->require_ppms_user();
        $dmc  = strtoupper($dmc);
        $role = $this->current_user['role'] ?? 'ptl';

        $this->_assert_can_access_dmc($dmc);

        $body     = $this->_json_input();
        $settings = $body['settings'] ?? null;

        if (!is_array($settings)) {
            $this->_json_error('settings array required.');
        }

        // Validate keys against the manifest and enforce OI always-on rule
        $manifest   = $this->progress_calculator->get_section_manifest();
        $oi_sources = ['csv', 'mixed'];
        $valid_keys = [];
        $oi_keys    = [];
        foreach ($manifest as $sec) {
            $valid_keys[] = $sec['key'];
            if (in_array($sec['source'], $oi_sources)) {
                $oi_keys[] = $sec['key'];
            }
        }

        foreach ($settings as $key => $val) {
            if (!in_array($key, $valid_keys)) {
                $this->_json_error('Unknown section key: ' . $key, 400);
            }
            if (in_array($key, $oi_keys) && empty($val)) {
                $this->_json_error('OI-sourced section "' . $key . '" cannot be disabled.', 422);
            }
        }

        // Strip any OI keys that slipped through — they are always on, never stored
        foreach ($oi_keys as $k) { unset($settings[$k]); }

        if (empty($settings)) {
            // Nothing to save (all OI) — return current config
            $this->_return_config($dmc, $role); // early return — nothing to save
        }

        $updated_by = $this->current_user['name'] ?? 'unknown';
        $ok = $this->ppms_model->save_section_config($dmc, $role, $settings, $updated_by);

        if (!$ok) {
            $this->_json_error('Failed to save section config.', 500);
        }

        // Recompute progress for all projects in this DMC
        // Wrapped in try/catch — a recompute failure should not block the save
        $recompute_error = null;
        try {
            $this->_recompute_dmc_progress($dmc, $role);
        } catch (Throwable $e) {
            $recompute_error = $e->getMessage();
            log_message('error', 'section_config recompute failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }

        // Return updated config (include recompute error in debug info if any)
        $this->_return_config($dmc, $role, $recompute_error);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function _return_config($dmc, $role, $recompute_error = null)
    {
        $config   = $this->ppms_model->get_section_config($dmc, $role);
        $manifest = $this->progress_calculator->get_section_manifest();
        $sections = [];
        foreach ($manifest as $sec) {
            $key = $sec['key'];
            $sections[] = [
                'key'     => $key,
                'label'   => $sec['label'],
                'source'  => $sec['source'],
                'weight'  => $sec['weight'],
                'enabled' => isset($config[$key]) ? (bool)$config[$key] : true,  // default all sections enabled
            ];
        }
        $data = ['dmc' => $dmc, 'role' => $role, 'sections' => $sections];
        if ($recompute_error) {
            $data['recompute_warning'] = $recompute_error;
        }
        $this->_json_ok($data, 'Section config saved.');
    }

    private function _assert_can_access_dmc($dmc)
    {
        $role = $this->current_user['role'] ?? 'ptl';
        if ($role === 'admin') return; // admin can access any DMC for admin role
        // PTL / viewer: can only access their own DMC
        if (strtoupper($this->effective_dmc) !== $dmc) {
            $this->_json_error('Access denied: not your DMC.', 403);
        }
    }


}
