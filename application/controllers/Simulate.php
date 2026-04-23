<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Simulate Controller
 *
 * Handles user profile selection and session management.
 *
 * CACHING STRATEGY:
 *   The profile selector page is expensive to build — it reads caanddisb.csv
 *   (~seconds on first load) and renders all profile cards.
 *
 *   We cache the fully-rendered HTML output keyed on the CSV file's mtime.
 *   Cache TTL: 1 hour. Auto-invalidates the moment caanddisb.csv is replaced.
 *
 *   First visit after a new CSV:  ~200–500ms (CSV parse + render)
 *   Every subsequent visit:       ~2–5ms (cache hit, zero file parsing)
 *
 * Routes:
 *   GET  /simulate         → index()
 *   POST /simulate/switch  → switch_user()
 *   GET  /simulate/exit    → exit_simulation()
 */
class Simulate extends CI_Controller
{
    /** Cache TTL in seconds — 1 hour */
    const CACHE_TTL = 3600;

    /** @var array|null */
    protected $current_user = null;

    /** @var bool */
    protected $is_simulation_mode = false;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('session');
        $this->load->helper('url');
        $this->config->load('ppms', true);
        $this->load->library('ppms_cache');
        $this->load->model('simulation_user_model');

        // Resolve current user from session (mirrors MY_Controller logic)
        $session_data = $this->session->userdata('simulation_session') ?? [];
        if (!empty($session_data['effective_user'])) {
            $this->current_user       = $session_data['effective_user'];
            $this->is_simulation_mode = true;
        }
    }

    // -------------------------------------------------------------------------
    // GET /simulate
    // -------------------------------------------------------------------------

    public function index()
    {
        // ── Remember where the user came from ─────────────────────────────
        // Store the HTTP Referer so switch_user() can send them back.
        // Only store PPMS internal URLs (not /simulate itself).
        $referer = $this->input->server('HTTP_REFERER') ?? '';
        if (!empty($referer) && strpos($referer, 'simulate') === false) {
            $this->session->set_userdata('simulate_return_url', $referer);
        }

        $session_data = $this->session->userdata('simulation_session') ?? [];

        // Active profile ID for the picker to highlight current selection
        $active_id = $session_data['effective_user']['id'] ?? null;

        // Try to serve fully-cached HTML first (cache includes all profiles,
        // active highlighting is injected client-side via data attribute)
        $cache_key   = $this->_page_cache_key();
        $cached_html = $this->ppms_cache->get($cache_key);

        if ($cached_html !== null) {
            // Inject active_id into cached HTML as a data attribute on body
            $cached_html = str_replace(
                '<body',
                '<body data-active-profile="' . htmlspecialchars((string)$active_id) . '"',
                $cached_html
            );
            $this->output->set_output($cached_html);
            return;
        }

        // Cache miss — build the page normally
        $profiles = $this->simulation_user_model->get_all_profiles();

        $data = [
            'page_title'        => 'Select Simulation Profile',
            'profiles'          => $profiles,
            'active_profile_id' => $active_id,
            'current_user'      => $this->current_user,
            'is_simulation_mode'=> $this->is_simulation_mode,
        ];

        if (file_exists(APPPATH . 'views/layouts/header.php')) {
            $output  = $this->load->view('layouts/header',         $data, true);
            $output .= $this->load->view('simulation/select_user', $data, true);
            $output .= $this->load->view('layouts/footer',         $data, true);
        } else {
            $output = $this->load->view('simulation/select_user', $data, true);
        }

        $this->ppms_cache->set($cache_key, $output, self::CACHE_TTL);

        // Inject active_id before serving
        $output = str_replace(
            '<body',
            '<body data-active-profile="' . htmlspecialchars((string)$active_id) . '"',
            $output
        );
        $this->output->set_output($output);
    }

    // -------------------------------------------------------------------------
    // POST /simulate/switch
    // -------------------------------------------------------------------------

    public function switch_user()
    {
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            redirect(site_url('simulate'));
            return;
        }

        $profile_id = $this->input->post('profile_id', true);
        $profile    = $this->simulation_user_model->get_profile_by_id($profile_id);

        if (empty($profile)) {
            $this->session->set_flashdata('error', 'Invalid profile. Please try again.');
            redirect(site_url('simulate'));
            return;
        }

        // Write the full session structure.
        // When SSO arrives: replace actual_user with IdP identity.
        $this->session->set_userdata('simulation_session', [
            'actual_user'      => $profile,
            'effective_user'   => $profile,
            'is_impersonating' => false,
            'is_simulation'    => true,
        ]);

        // Return to where the user came from (e.g. the workspace they were in)
        $return_url = $this->session->userdata('simulate_return_url');
        $this->session->unset_userdata('simulate_return_url');

        if (!empty($return_url)) {
            redirect($return_url);
        } else {
            redirect(site_url('ppms'));
        }
    }

    // -------------------------------------------------------------------------
    // GET /simulate/exit
    // -------------------------------------------------------------------------

    public function exit_simulation()
    {
        $this->session->unset_userdata('simulation_session');
        $this->session->set_flashdata('info', 'Simulation ended. Please select a profile.');
        redirect(site_url('simulate'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a cache key that auto-invalidates when caanddisb.csv changes.
     * Uses mtime so replacing the CSV immediately busts the cache.
     */
    private function _page_cache_key()
    {
        $csv_path = defined('PPMS_CSV_PATH') ? PPMS_CSV_PATH : FCPATH . 'csv_data/';
        $csv_file = $csv_path . 'caanddisb.csv';
        $mtime    = file_exists($csv_file) ? filemtime($csv_file) : 0;
        return 'simulate_page_' . $mtime;
    }
}
