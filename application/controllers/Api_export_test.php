<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Minimal export test — no dependencies, just proves routing works.
 * Visit: /api/export-test
 * Delete this file after confirming routing works.
 */
class Api_export_test extends CI_Controller
{
    public function index()
    {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'message' => 'Export routing works!']);
        exit;
    }
}
