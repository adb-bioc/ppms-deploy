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
 * Api_section_debug — shows raw section data for debugging.
 * Visit: /api/section-debug/PROJECTNO/SECTIONKEY
 * e.g.  /api/section-debug/50347-002/basic_data
 * Remove before production.
 */
class Api_section_debug extends PPMS_Controller
{
    public function index($project_no = '50347-002', $section_key = 'basic_data')
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $project = $this->csv_reader->get_project($project_no);

            if (!$project) {
                echo json_encode(['error' => 'Project not found: ' . $project_no]);
                return;
            }

            $csv_data = null;
            if ($section_key === 'basic_data') {
                $csv_data = [
                    'products'        => $project['products'],
                    'product_details' => $project['product_details'] ?? [],
                    'financing'       => $this->csv_reader->get_financing_amounts($project_no),
                    'country'         => $this->csv_reader->get_country_name($project['dmc']),
                ];
            }

            echo json_encode([
                'project_no'           => $project_no,
                'section_key'          => $section_key,
                'products_count'       => count($project['products'] ?? []),
                'products'             => $project['products'],
                'product_details_count'=> count($project['product_details'] ?? []),
                'product_details'      => $project['product_details'] ?? [],
                'csv_data'             => $csv_data,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
