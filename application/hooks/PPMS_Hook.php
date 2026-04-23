<?php
defined('BASEPATH') OR exit('No direct script access allowed');

function load_ppms_controller()
{
    $path = APPPATH . 'controllers/PPMS_Controller.php';
    if (file_exists($path) && !class_exists('PPMS_Controller')) {
        require_once $path;
    }
}
