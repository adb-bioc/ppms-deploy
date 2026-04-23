<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Project_model
 *
 * Provides project list data for the simulation dashboard.
 * This is a LIGHTWEIGHT model — it uses the CSV_reader library
 * which is already loaded and cached by the PPMS module.
 *
 * If PPMS module is loaded (csv_reader available), uses real OI data.
 * Otherwise falls back to the static sample data below.
 *
 * To swap to a real DB: replace get_all_projects() with a DB query.
 */
class Project_model extends CI_Model
{
    /**
     * Get all projects regardless of country.
     * Used by: admin role.
     *
     * @return array[]
     */
    public function get_all_projects()
    {
        // Use CSV_reader if available (PPMS module is loaded)
        if (isset($this->csv_reader)) {
            $all = [];
            foreach ($this->csv_reader->get_dmc_list() as $_d) {
                $all = array_merge($all, $this->csv_reader->get_projects($_d));
            }
            return $all;
        }

        return $this->_static_projects();
    }

    /**
     * Get projects filtered to a single DMC country.
     * Used by: ptl and viewer roles.
     *
     * @param  string|null $country  DMC code e.g. 'NEP', 'BAN'
     * @return array[]
     */
    public function get_projects_by_country($country)
    {
        if (empty($country)) return [];

        // Use CSV_reader if available
        if (isset($this->csv_reader)) {
            return $this->csv_reader->get_projects(strtoupper($country));
        }

        $country = strtoupper($country);
        return array_values(array_filter(
            $this->_static_projects(),
            fn($p) => strtoupper($p['dmc']) === $country
        ));
    }

    // -------------------------------------------------------------------------
    // Static fallback data (used when CSV_reader is not loaded)
    // Replace with real data or remove once CSVs are in place
    // -------------------------------------------------------------------------

    private function _static_projects()
    {
        return [
            [
                'project_no'    => 'NEP-001',
                'project_name'  => 'Rural WASH Infrastructure — Karnali Province',
                'dmc'           => 'NEP',
                'sector'        => 'Water',
                'status'        => 'active',
                'net_amount'    => 45000000,
                'loan_grant_str'=> 'L-1234, G-5678',
                'products'      => [
                    ['loan_grant_no' => '1234', 'loan_grant_fmt' => 'L-1234'],
                    ['loan_grant_no' => '5678', 'loan_grant_fmt' => 'G-5678'],
                ],
            ],
            [
                'project_no'    => 'NEP-002',
                'project_name'  => 'Secondary Education Improvement Project',
                'dmc'           => 'NEP',
                'sector'        => 'Education',
                'status'        => 'active',
                'net_amount'    => 28000000,
                'loan_grant_str'=> 'L-2345',
                'products'      => [
                    ['loan_grant_no' => '2345', 'loan_grant_fmt' => 'L-2345'],
                ],
            ],
            [
                'project_no'    => 'BAN-001',
                'project_name'  => 'Dhaka Environmental Sustainability Project',
                'dmc'           => 'BAN',
                'sector'        => 'Environment',
                'status'        => 'active',
                'net_amount'    => 80000000,
                'loan_grant_str'=> 'L-5678, G-6789',
                'products'      => [
                    ['loan_grant_no' => '5678', 'loan_grant_fmt' => 'L-5678'],
                    ['loan_grant_no' => '6789', 'loan_grant_fmt' => 'G-6789'],
                ],
            ],
            [
                'project_no'    => 'BAN-002',
                'project_name'  => "Cox's Bazar Coastal Resilience Project",
                'dmc'           => 'BAN',
                'sector'        => 'DRR',
                'status'        => 'active',
                'net_amount'    => 95000000,
                'loan_grant_str'=> 'L-7890',
                'products'      => [
                    ['loan_grant_no' => '7890', 'loan_grant_fmt' => 'L-7890'],
                ],
            ],
        ];
    }
}
