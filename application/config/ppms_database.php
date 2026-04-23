<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| PPMS Database Configuration
|--------------------------------------------------------------------------
|
| Loaded by: PPMS_model via $this->load->database('ppms_database', true)
|
| CURRENT:  SQLite — zero setup, single file, works immediately.
|
| TO SWITCH TO MYSQL:
|   1. Comment out the SQLite block
|   2. Uncomment the MySQL block
|   3. Run: mysql ppms_db < database/schema.sql
|   4. Done — no other file changes needed.
|
*/

// ── SQLite (active) ───────────────────────────────────────────────────────────
$db['ppms_database'] = [
    'dsn'          => 'sqlite:' . APPPATH . 'database/ppms.db',
    'hostname'     => '',
    'username'     => '',
    'password'     => '',
    'database'     => '',
    'dbdriver'     => 'pdo',
    'dbprefix'     => '',
    'pconnect'     => false,
    'db_debug'     => (ENVIRONMENT !== 'production'),
    'cache_on'     => false,
    'cachedir'     => '',
    'char_set'     => 'utf8',
    'dbcollat'     => '',
    'swap_pre'     => '',
    'encrypt'      => false,
    'compress'     => false,
    'stricton'     => false,
    'failover'     => [],
    'save_queries' => (ENVIRONMENT !== 'production'),
];

// ── MySQL (uncomment when ready) ──────────────────────────────────────────────
// $db['ppms_database'] = [
//     'dsn'          => '',
//     'hostname'     => 'localhost',
//     'username'     => 'ppms_user',
//     'password'     => 'ppms_password',
//     'database'     => 'ppms_db',
//     'dbdriver'     => 'mysqli',
//     'dbprefix'     => '',
//     'pconnect'     => false,
//     'db_debug'     => (ENVIRONMENT !== 'production'),
//     'cache_on'     => false,
//     'cachedir'     => '',
//     'char_set'     => 'utf8mb4',
//     'dbcollat'     => 'utf8mb4_unicode_ci',
//     'swap_pre'     => '',
//     'encrypt'      => false,
//     'compress'     => false,
//     'stricton'     => true,
//     'failover'     => [],
//     'save_queries' => (ENVIRONMENT !== 'production'),
// ];
