<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Api_raw — returns raw JSON of /api/projects so we can see exactly
 * what the Vue app receives. Visit directly in browser.
 * Route: GET /api/raw
 */
class Api_raw extends CI_Controller
{
    public function index()
    {
        $this->load->library('session');
        $this->load->helper('url');

        header('Content-Type: application/json; charset=utf-8');

        $sess = $this->session->userdata('simulation_session') ?? [];
        $eff  = $sess['effective_user'] ?? null;

        if (!$eff) {
            echo json_encode(['error' => 'No session. Visit /simulate first.']);
            return;
        }

        try {
            $this->config->load('ppms', true);
            $this->load->library(['ppms_cache', 'csv_reader']);
            $this->load->model('ppms_model');

            $dmc      = ($eff['role'] === 'admin') ? null : strtoupper($eff['country'] ?? '');
            if ($dmc === null) {
                $projects = [];
                foreach ($this->csv_reader->get_dmc_list() as $_d) {
                    $projects = array_merge($projects, $this->csv_reader->get_projects($_d));
                }
            } else {
                $projects = $this->csv_reader->get_projects($dmc);
            }

            // Merge with DB progress
            $progress_map = [];
            $rows = $dmc
                ? $this->ppms_model->get_dmc_progress($dmc)
                : $this->ppms_model->get_all_progress();
            foreach ($rows as $p) {
                $progress_map[$p['project_id']] = $p;
            }

            // Build dmc → region map (country_nom already cached)
            $dmc_region_map = [];
            foreach ($this->csv_reader->_load_normalized_public('country_nom') as $row) {
                $dmc_region_map[strtoupper($row['code'])] = $row['region'] ?? '';
            }

            $result = array_map(function($proj) use ($progress_map, $dmc_region_map) {
                $pid = $proj['project_no'];
                $app = $progress_map[$pid] ?? null;
                return [
                    'project_no'        => $pid,
                    'project_title'     => $proj['project_name'],
                    'dmc'               => $proj['dmc'],
                    'region'            => $dmc_region_map[strtoupper($proj['dmc'])] ?? '',
                    'sector_department' => $proj['sector_department'] ?? '',
                    'sard_sector'       => $proj['sard_sector']        ?? '',
                    'sard_sector_nom'   => $proj['sard_sector_nom']    ?? '',
                    'sector'            => $proj['sector'],
                    'products'          => $proj['products'],
                    'net_amount'        => $proj['net_amount'],
                    'overall_progress'  => $app['overall_progress'] ?? 0,
                    'status'            => $app['status'] ?? 'not_started',
                    'last_opened_at'    => $app['last_opened_at'] ?? null,
                ];
            }, $projects);

            // Return EXACT same structure as /api/projects so we can verify
            echo json_encode([
                'status'  => 'ok',
                'message' => 'ok',
                'data'    => [
                    'projects'    => array_slice($result, 0, 3),
                    'summary'     => [
                        'total'    => count($result),
                        'dmc'      => $dmc ?? 'ALL',
                    ],
                    'resume_last' => null,
                ],
                '_debug' => [
                    'user'    => $eff['name'],
                    'role'    => $eff['role'],
                    'dmc'     => $dmc ?? 'ALL',
                    'total'   => count($result),
                    'api_url' => 'http://localhost/ppms/index.php/api/projects',
                    'note'    => 'This is the same structure /api/projects returns. Vue interceptor unwraps data field.',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
