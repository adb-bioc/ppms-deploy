<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Api_test — step-by-step diagnostic for the API 500 error.
 * No session required. No PPMS_Controller dependency.
 * Route: GET /api/test
 * REMOVE before production.
 */
class Api_test extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('session');
    }

    public function index()
    {
        header('Content-Type: application/json');

        $steps  = [];
        $failed = false;

        // Step 1: ppms.php config
        try {
            $this->config->load('ppms', true);
            $v = $this->config->item('ppms_version', 'ppms');
            $steps[] = ['step' => '1. config->load(ppms)', 'ok' => true,
                        'detail' => 'ppms_version = ' . $v];
        } catch (Exception $e) {
            $steps[] = ['step' => '1. config->load(ppms)', 'ok' => false, 'error' => $e->getMessage()];
            $failed = true;
        }

        // Step 2: ppms_database.php
        if (!$failed) {
            $db_config = APPPATH . 'config/ppms_database.php';
            $exists    = file_exists($db_config);
            $steps[]   = ['step' => '2. ppms_database.php exists', 'ok' => $exists,
                          'path' => $db_config];
            if (!$exists) $failed = true;
        }

        // Step 3: PPMS_Cache library
        if (!$failed) {
            try {
                $this->load->library('ppms_cache');
                $info    = $this->ppms_cache->status();
                $steps[] = ['step' => '3. ppms_cache library', 'ok' => true,
                            'detail' => 'file_cache=' . ($info['file_cache'] ? 'yes' : 'no')
                                      . ' apcu=' . ($info['apcu'] ? 'yes' : 'no')];
            } catch (Exception $e) {
                $steps[] = ['step' => '3. ppms_cache library', 'ok' => false, 'error' => $e->getMessage()];
                $failed = true;
            }
        }

        // Step 4: CSV_reader library
        if (!$failed) {
            try {
                $this->load->library('csv_reader');
                $csv_path = FCPATH . 'csv_data/caanddisb.csv';
                $steps[]  = ['step' => '4. csv_reader library', 'ok' => true,
                             'detail' => 'caanddisb.csv exists: ' . (file_exists($csv_path) ? 'yes' : 'NO')
                                       . ' (' . (file_exists($csv_path) ? round(filesize($csv_path)/1048576,1).'MB' : '0') . ')'];
            } catch (Exception $e) {
                $steps[] = ['step' => '4. csv_reader library', 'ok' => false, 'error' => $e->getMessage()];
                $failed = true;
            }
        }

        // Step 5: PPMS_model (loads ppms_database)
        if (!$failed) {
            try {
                $this->load->model('ppms_model');
                $steps[] = ['step' => '5. ppms_model (loads ppms_database.php)', 'ok' => true];
            } catch (Exception $e) {
                $steps[] = ['step' => '5. ppms_model', 'ok' => false, 'error' => $e->getMessage()];
                $failed = true;
            }
        }

        // Step 6: get_projects() — the actual CSV read
        if (!$failed) {
            try {
                $projects = $this->csv_reader->get_projects();
                $steps[]  = ['step' => '6. csv_reader->get_projects()', 'ok' => true,
                             'detail' => count($projects) . ' projects returned'];

                // Also test DMC filter
                if (!empty($projects)) {
                    $dmc  = $projects[0]['dmc'] ?? 'UZB';
                    $filt = $this->csv_reader->get_projects($dmc);
                    $steps[] = ['step' => '6b. get_projects(' . $dmc . ')', 'ok' => true,
                                'detail' => count($filt) . ' projects for ' . $dmc];
                }
            } catch (Exception $e) {
                $steps[] = ['step' => '6. csv_reader->get_projects()', 'ok' => false,
                            'error' => $e->getMessage()];
                $failed = true;
            }
        }

        // Step 7: session check
        $session_key  = defined('PPMS_SESSION_KEY') ? PPMS_SESSION_KEY : 'simulation_session';
        $session_data = $this->session->userdata($session_key) ?? [];
        $has_user     = !empty($session_data['effective_user']);
        $steps[]      = ['step' => '7. session check', 'ok' => $has_user,
                         'detail' => $has_user
                            ? 'user=' . ($session_data['effective_user']['name'] ?? '?')
                              . ' role=' . ($session_data['effective_user']['role'] ?? '?')
                            : 'No session — visit /simulate first, then call /api/projects'];

        echo json_encode([
            'status'    => $failed ? 'error' : 'ok',
            'all_pass'  => !$failed && $has_user,
            'steps'     => $steps,
            'php_version' => PHP_VERSION,
            'memory_limit'=> ini_get('memory_limit'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
