<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Simulation Helper
 *
 * Global convenience functions for accessing user context anywhere
 * in the application without passing $current_user through every call.
 *
 * Load in MY_Controller (already done) or add to autoload.php:
 *   $autoload['helper'] = ['url', 'form', 'simulation'];
 */

// -----------------------------------------------------------------------------

if (!function_exists('current_user')) {
    /**
     * Get the effective user from session, or a specific field from it.
     *
     * Usage:
     *   $user  = current_user();           // full user array
     *   $role  = current_user('role');     // 'admin', 'ptl', 'viewer'
     *   $name  = current_user('name');
     *
     * @param  string|null $key
     * @return mixed
     */
    function current_user($key = null)
    {
        $CI =& get_instance();
        $session_data = $CI->session->userdata('simulation_session') ?? [];
        $user = $session_data['effective_user'] ?? null;

        if ($user === null) return null;
        if ($key !== null) return $user[$key] ?? null;

        return $user;
    }
}

// -----------------------------------------------------------------------------

if (!function_exists('is_simulation_mode')) {
    /**
     * Returns true when running in simulation (no real auth) mode.
     *
     * @return bool
     */
    function is_simulation_mode()
    {
        $CI =& get_instance();
        $session_data = $CI->session->userdata('simulation_session') ?? [];
        return (bool)($session_data['is_simulation'] ?? false);
    }
}

// -----------------------------------------------------------------------------

if (!function_exists('user_has_role')) {
    /**
     * Check if the current effective user has a specific role.
     *
     * Usage: if (user_has_role('admin')) { ... }
     *
     * @param  string $role
     * @return bool
     */
    function user_has_role($role)
    {
        $user = current_user();
        return isset($user['role']) && $user['role'] === $role;
    }
}

// -----------------------------------------------------------------------------

if (!function_exists('user_country')) {
    /**
     * Returns the DMC country code of the effective user, or null.
     *
     * @return string|null
     */
    function user_country()
    {
        return current_user('country');
    }
}

// -----------------------------------------------------------------------------

if (!function_exists('is_impersonating')) {
    /**
     * Returns true when actual_user differs from effective_user.
     *
     * @return bool
     */
    function is_impersonating()
    {
        $CI =& get_instance();
        $session_data = $CI->session->userdata('simulation_session') ?? [];
        return (bool)($session_data['is_impersonating'] ?? false);
    }
}
