<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Ppms Controller
 *
 * Serves the CI3 shell view that Vue 3 mounts into.
 * Extends CI_Controller directly — no dependency on MY_Controller
 * so it works in any CI3 host app without modification.
 * All subsequent navigation within /ppms is handled by Vue Router.
 *
 * Route: GET /ppms  (and all /ppms/* via routes.php wildcard)
 */
class Ppms extends CI_Controller
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

            $session_data = $this->session->userdata('simulation_session');
        }

        $effective_user = $session_data['effective_user'];
        $actual_user    = $session_data['actual_user'];

        $this->load->view('ppms/shell', [
            'page_title'      => 'PPMS',
            'current_user'    => $effective_user,
            'session_context' => [
                'actual_user'      => $actual_user,
                'effective_user'   => $effective_user,
                'is_impersonating' => (bool)($session_data['is_impersonating'] ?? false),
                'is_simulation'    => true,
            ],
            'is_simulation_mode' => true,
        ]);
    }
}
