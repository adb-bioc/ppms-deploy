<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Welcome Controller
 *
 * Landing page for sectorsinsightsdev.adb.org
 * Serves the OI application welcome/home page.
 *
 * Route: GET /  (default_controller)
 */
class Welcome extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('session');
        $this->load->helper('url');
    }

    public function index()
    {
        // Load OI layout if available, otherwise serve view directly
        $data = ['page_title' => 'Operations Insights'];
        if (file_exists(APPPATH . 'views/layouts/header.php')) {
            $this->load->view('layouts/header', $data);
            $this->load->view('welcome/index',   $data);
            $this->load->view('layouts/footer',  $data);
        } else {
            $this->load->view('welcome/index', $data);
        }
    }
}
