<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard Controller
 *
 * Landing page. If no session exists, auto-sets the admin profile
 * so users land directly on the PPMS dashboard without any login step.
 *
 * Route: GET /  (default_controller)
 * Route: GET /dashboard
 */
class Dashboard extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('session');
        $this->load->helper('url');
    }

    public function index()
    {
        $session_data = $this->session->userdata('simulation_session') ?? [];

        // No active session — auto-set admin profile as the default
        if (empty($session_data) || empty($session_data['effective_user'])) {
            $admin_profile = [
                'id'              => 'admin_all',
                'name'            => 'Administrator',
                'role'            => 'admin',
                'country'         => null,
                'officer_name'    => null,
                'dmcs'            => [],
                'avatar_initials' => 'AD',
                'description'     => 'Full system access.',
                'div_nom'         => 'SARD',
                'dept'            => '',
            ];

            $this->session->set_userdata('simulation_session', [
                'actual_user'      => $admin_profile,
                'effective_user'   => $admin_profile,
                'is_impersonating' => false,
                'is_simulation'    => true,
            ]);
        }

        redirect(site_url('ppms'));
    }
}
