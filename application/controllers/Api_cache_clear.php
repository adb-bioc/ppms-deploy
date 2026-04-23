<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Api_cache_clear — clears all PPMS caches.
 * Visit: /api/cache-clear
 * Remove before production.
 */
class Api_cache_clear extends CI_Controller
{
    public function index()
    {
        $this->load->library('session');
        $this->config->load('ppms', true);
        $this->load->library(['ppms_cache', 'csv_reader']);

        header('Content-Type: application/json');

        // Clear CSV_reader caches
        $this->csv_reader->clear_cache();

        // Clear CI3 file cache directory
        $cleared = [];
        $cache_dir = APPPATH . 'cache/';
        if (is_dir($cache_dir)) {
            foreach (glob($cache_dir . 'ppms_*') as $f) {
                if (unlink($f)) $cleared[] = basename($f);
            }
        }

        // Clear APCu if available
        $apcu_cleared = false;
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
            $apcu_cleared = true;
        }

        echo json_encode([
            'status'        => 'cleared',
            'files_deleted' => count($cleared),
            'apcu_cleared'  => $apcu_cleared,
            'message'       => 'All PPMS caches cleared. Next request will re-parse CSVs.',
        ], JSON_PRETTY_PRINT);
    }
}
