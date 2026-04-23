<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| PPMS General Configuration
|--------------------------------------------------------------------------
|
| Loaded by: PPMS_Controller, Simulate, CSV_reader via config->load('ppms')
|
| Database config is in: application/config/ppms_database.php
| (loaded by PPMS_model via $this->load->database('ppms_database', true))
|
*/

// Required by CI3's config->load() — must be present
$config['ppms_version'] = '1.0';

/*
|--------------------------------------------------------------------------
| CSV Data Directory
|--------------------------------------------------------------------------
| Absolute path to the folder containing your OI CSV exports.
| Trailing slash required.
*/
defined('PPMS_CSV_PATH') OR define('PPMS_CSV_PATH', FCPATH . 'csv_data/');

/*
|--------------------------------------------------------------------------
| Session Key
|--------------------------------------------------------------------------
| The CI3 session key where the simulation user data is stored.
| Change this if your host app uses a different key name.
*/
defined('PPMS_SESSION_KEY') OR define('PPMS_SESSION_KEY', 'simulation_session');

/*
|--------------------------------------------------------------------------
| Cache TTLs (seconds)
|--------------------------------------------------------------------------
*/
defined('PPMS_CACHE_CSV')      OR define('PPMS_CACHE_CSV',      3600);  // 1 hour
defined('PPMS_CACHE_PROJECTS') OR define('PPMS_CACHE_PROJECTS',  300);  // 5 min
defined('PPMS_CACHE_SECTION')  OR define('PPMS_CACHE_SECTION',    60);  // 1 min
