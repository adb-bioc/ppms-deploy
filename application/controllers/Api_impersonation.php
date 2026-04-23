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
 * Api_impersonation Controller
 *
 * Exposes the impersonation state to the Vue frontend and allows
 * switching/exiting from within the SPA — without a full page reload.
 *
 * Routes:
 *   GET  /api/impersonation/context  → context()
 *   POST /api/impersonation/switch   → switch_user()
 *   POST /api/impersonation/exit     → exit_simulation()
 */
class Api_impersonation extends PPMS_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('simulation_user_model');
    }

    // -------------------------------------------------------------------------
    // GET /api/impersonation/context
    // -------------------------------------------------------------------------

    /**
     * Return full impersonation context for the current session.
     * Called on SPA mount and after every switch.
     */
    public function context()
    {
        $session_data = $this->session->userdata('simulation_session') ?? [];

        $eff_user = $session_data['effective_user'] ?? null;
        $act_user = $session_data['actual_user']    ?? $eff_user;
        $is_imp   = (bool) ($session_data['is_impersonating'] ?? false);
        $is_sim   = (bool) ($session_data['is_simulation']    ?? true);

        if ( ! $eff_user) {
            $this->_json_error('No active session. Please select a simulation profile.', 401);
        }

        // Available profiles for the switcher dropdown
        $profiles = $this->simulation_user_model->get_all_profiles();

        $this->_json_ok([
            'actual_user'      => $act_user,
            'effective_user'   => $eff_user,
            'is_impersonating' => $is_imp,
            'is_simulation'    => $is_sim,
            'effective_dmc'    => strtoupper($eff_user['country'] ?? ''),
            'profiles'         => $profiles,   // for the switcher dropdown
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/impersonation/switch
    // -------------------------------------------------------------------------

    /**
     * Switch the effective user profile without a page reload.
     * Body: { "profile_id": "user_ptl_nep" }
     *
     * In a real SSO system: only admins can switch; validate real_user role.
     * Here: any simulated user can switch (simulation mode).
     */
    public function switch_user()
    {
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            $this->_json_error('POST required.', 405);
        }

        $body       = $this->_json_input();
        $profile_id = $body['profile_id'] ?? null;

        if ( ! $profile_id) $this->_json_error('profile_id is required.');

        $profile = $this->simulation_user_model->get_profile_by_id($profile_id);
        if ( ! $profile) $this->_json_error('Profile not found.', 404);

        $session_data    = $this->session->userdata('simulation_session') ?? [];
        $actual_user     = $session_data['actual_user'] ?? $profile; // Preserve actual in real auth

        $is_impersonating = ($actual_user['id'] !== $profile['id']);

        $this->session->set_userdata('simulation_session', [
            'actual_user'      => $actual_user,
            'effective_user'   => $profile,
            'is_impersonating' => $is_impersonating,
            'is_simulation'    => true,
        ]);

        $this->_json_ok([
            'effective_user'   => $profile,
            'actual_user'      => $actual_user,
            'is_impersonating' => $is_impersonating,
            'effective_dmc'    => strtoupper($profile['country'] ?? ''),
        ], 'Switched to ' . $profile['name']);
    }

    // -------------------------------------------------------------------------
    // POST /api/impersonation/logout
    // -------------------------------------------------------------------------

    /**
     * Log out the current user — sets session to guest role.
     * Dashboard remains accessible in read-only guest mode.
     */
    public function logout()
    {
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            $this->_json_error('POST required.', 405);
        }

        $guest_profile = [
            'id'              => 'guest',
            'name'            => 'Guest',
            'role'            => 'guest',
            'country'         => null,
            'avatar_initials' => '?',
        ];

        $this->session->set_userdata('simulation_session', [
            'actual_user'      => $guest_profile,
            'effective_user'   => $guest_profile,
            'is_impersonating' => false,
            'is_simulation'    => true,
        ]);

        $this->_json_ok([], 'Logged out. Browsing as guest.');
    }

    // -------------------------------------------------------------------------
    // POST /api/impersonation/exit
    // -------------------------------------------------------------------------

    /**
     * Clear simulation session. Frontend redirects to /simulate after this.
     */
    public function exit_simulation()
    {
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            $this->_json_error('POST required.', 405);
        }

        $this->session->unset_userdata('simulation_session');

        $this->_json_ok([], 'Simulation session ended. Redirecting to profile selection.');
    }

    // -------------------------------------------------------------------------
    // POST /api/impersonation/return-url
    // Store the full current URL (including hash) so /simulate can redirect back
    // -------------------------------------------------------------------------
    public function set_return_url()
    {
        $body = $this->_json_input();
        $url  = $body['url'] ?? '';

        // Validate: must be a relative or same-origin URL
        if (!empty($url)) {
            // Strip any protocol+host — only keep path+hash
            $parsed = parse_url($url);
            $path   = ($parsed['path'] ?? '') . 
                      (isset($parsed['query'])    ? '?' . $parsed['query']    : '') .
                      (isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '');
            $this->session->set_userdata('simulate_return_url', $path ?: $url);
        }

        $this->_json_ok(['saved' => true]);
    }

}
