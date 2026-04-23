<?php
/**
 * CodeIgniter
 *
 * An open source application development framework for PHP
 *
 * This content is released under the MIT License (MIT)
 *
 * Copyright (c) 2014 - 2019, British Columbia Institute of Technology
 */

/*
|---------------------------------------------------------------
| APPLICATION ENVIRONMENT
|---------------------------------------------------------------
| You can load different configurations depending on your
| current environment. Setting the environment also influences
| things like logging and error reporting.
|
| This can be set to anything, but default usage is:
|
|     development
|     testing
|     production
|
| NOTE: If you change these, also change the error_reporting() code below
*/
define('ENVIRONMENT', isset($_SERVER['CI_ENV']) ? $_SERVER['CI_ENV'] : 'development');

/*
|---------------------------------------------------------------
| ERROR REPORTING
|---------------------------------------------------------------
| Different environments will require different levels of error
| reporting. By default development will show errors but testing
| and live will hide them.
*/
switch (ENVIRONMENT)
{
	case 'development':
		error_reporting(-1);
		ini_set('display_errors', 1);
	break;

	case 'testing':
	case 'production':
		ini_set('display_errors', 0);
		if (version_compare(PHP_VERSION, '5.3', '>='))
		{
			error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT & ~E_USER_NOTICE & ~E_USER_DEPRECATED);
		}
		else
		{
			error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_USER_NOTICE);
		}
	break;

	default:
		header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
		echo 'The application environment is not set correctly.';
		exit(1);
}

/*
|---------------------------------------------------------------
| APPLICATION FOLDER NAME
|---------------------------------------------------------------
| This variable must contain the name of your "application" folder.
| Set to 'application' (CI3 default).
*/
$application_folder = 'application';

/*
|---------------------------------------------------------------
| VIEW FOLDER NAME
|---------------------------------------------------------------
| This variable must contain the name of your "views" folder.
| Set to '' to use the default location (application/views/).
*/
$view_folder = '';

/*
|---------------------------------------------------------------
| SYSTEM FOLDER NAME
|---------------------------------------------------------------
| This variable must contain the name of your CI "system" folder.
*/
$system_path = 'system';

/*
|---------------------------------------------------------------
| DEFAULT CONTROLLER
|---------------------------------------------------------------
| Normally you will set your default controller in the routes.php file.
| You can, however, force a custom routing by hard-coding a
| specific controller class/function here. For most applications,
| you DO NOT want to set your routing here, but it's an option.
|
| Examples:
|   $routing['directory']  = '';
|   $routing['controller'] = '';
|   $routing['function']   = '';
*/
$routing['directory']  = '';
$routing['controller'] = '';
$routing['function']   = '';

/*
|---------------------------------------------------------------
| CUSTOM CONFIG VALUES
|---------------------------------------------------------------
| The $assign_to_config array below will be passed dynamically to
| the config class when initialized. This allows you to set custom
| config items or override any default config values found in the
| config.php file. This can be handy as it permits you to share one
| application between multiple installations, with different settings.
|
| Use the format:
|   $assign_to_config['name_of_config_item'] = 'value of config item';
*/
// $assign_to_config['name_of_config_item'] = 'value of config item';

// --------------------------------------------------------------------
// END OF USER CONFIGURABLE SETTINGS.  DO NOT EDIT BELOW THIS LINE
// --------------------------------------------------------------------

/*
 * ---------------------------------------------------------------
 *  Resolve the system path for increased reliability
 * ---------------------------------------------------------------
 */
if (defined('STDIN'))
{
	chdir(dirname(__FILE__));
}

if (($_temp = realpath($system_path)) !== FALSE)
{
	$system_path = $_temp.DIRECTORY_SEPARATOR;
}
else
{
	// Ensure there's a trailing slash
	$system_path = strtr(
		rtrim($system_path, '/\\'),
		'/\\',
		DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR
	).DIRECTORY_SEPARATOR;
}

// Is the system path correct?
if ( ! is_dir($system_path))
{
	header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
	echo 'Your system folder path does not appear to be set correctly. Please open the following file and correct this: '.pathinfo(__FILE__, PATHINFO_BASENAME);
	exit(3); // EXIT_CONFIG
}

/*
 * -------------------------------------------------------------------
 *  Now that we know the path, set the main path constants
 * -------------------------------------------------------------------
 */
// The name of THIS file
define('SELF', pathinfo(__FILE__, PATHINFO_BASENAME));

// Path to the system folder
define('BASEPATH', $system_path);

// Path to the front controller (this file) directory
define('FCPATH', dirname(__FILE__).DIRECTORY_SEPARATOR);

// Name of the "system folder"
define('SYSDIR', trim(strrchr(trim(BASEPATH, '/\\'), '/\\'), '/\\'));

// The path to the "application" folder
if (is_dir($application_folder))
{
	if (($_temp = realpath($application_folder)) !== FALSE)
	{
		$application_folder = $_temp.DIRECTORY_SEPARATOR;
	}
	else
	{
		$application_folder = strtr(
			rtrim($application_folder, '/\\'),
			'/\\',
			DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR
		).DIRECTORY_SEPARATOR;
	}
}
elseif (is_dir(BASEPATH.$application_folder.DIRECTORY_SEPARATOR))
{
	$application_folder = BASEPATH.$application_folder.DIRECTORY_SEPARATOR;
}
else
{
	header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
	echo 'Your application folder path does not appear to be set correctly. Please open the following file and correct this: '.pathinfo(__FILE__, PATHINFO_BASENAME);
	exit(3); // EXIT_CONFIG
}

define('APPPATH', $application_folder);

// The path to the "views" folder
if ( ! isset($view_folder) OR $view_folder === '')
{
	$view_folder = APPPATH.'views'.DIRECTORY_SEPARATOR;
}
elseif (is_dir($view_folder))
{
	if (($_temp = realpath($view_folder)) !== FALSE)
	{
		$view_folder = $_temp.DIRECTORY_SEPARATOR;
	}
	else
	{
		$view_folder = strtr(
			rtrim($view_folder, '/\\'),
			'/\\',
			DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR
		).DIRECTORY_SEPARATOR;
	}
}
elseif (is_dir(APPPATH.$view_folder.DIRECTORY_SEPARATOR))
{
	$view_folder = APPPATH.$view_folder.DIRECTORY_SEPARATOR;
}
elseif (is_dir(BASEPATH.$view_folder.DIRECTORY_SEPARATOR))
{
	$view_folder = BASEPATH.$view_folder.DIRECTORY_SEPARATOR;
}
else
{
	header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
	echo 'Your view folder path does not appear to be set correctly. Please open the following file and correct this: '.pathinfo(__FILE__, PATHINFO_BASENAME);
	exit(3); // EXIT_CONFIG
}

define('VIEWPATH', $view_folder);

/*
 * --------------------------------------------------------------------
 * LOAD THE BOOTSTRAP FILE
 * --------------------------------------------------------------------
 *
 * And away we go...
 */
require_once BASEPATH.'core/CodeIgniter.php';

/* End of file index.php */
