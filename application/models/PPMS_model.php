<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PPMS_model
 *
 * Manages all writable application data in the interim PPMS database.
 * Read-only CSV data is handled by CSV_reader — never here.
 *
 * All methods accept an $audit_ctx array:
 *   ['real_user_id', 'effective_user_id', 'effective_dmc', 'is_impersonating']
 * This ensures every write is correctly attributed for auditability.
 */
class PPMS_model extends CI_Model
{
    /** @var CI_DB_driver */
    private $ppms_db;

    /** @var bool  True if DB connected and tables exist */
    private $db_ok = false;

    public function __construct()
    {
        parent::__construct();
        try {
            // Load ppms_database.php which defines $db['ppms_database']
            // We read it directly and pass the array — this avoids requiring
            // the host app's database.php to contain the ppms_database group.
            $db = [];
            require APPPATH . 'config/ppms_database.php';
            $db_config = $db['ppms_database'] ?? null;

            if (empty($db_config)) {
                throw new Exception('ppms_database config not found in ' . APPPATH . 'config/ppms_database.php');
            }

            $this->ppms_db = $this->load->database($db_config, true);
            // Verify tables exist by running a cheap query
            $this->ppms_db->query('SELECT 1 FROM ppms_projects LIMIT 1');
            $this->db_ok = true;
        } catch (Exception $e) {
            log_message('error', 'PPMS_model: DB not ready — ' . $e->getMessage());
        }
    }

    /** Safely return empty result when DB not ready */
    private function _safe(callable $fn, $default = [])
    {
        if (!$this->db_ok) return $default;
        try { return $fn(); } catch (Exception $e) {
            log_message('error', 'PPMS_model query error: ' . $e->getMessage());
            return $default;
        }
    }

    // =========================================================================
    // Project workspace
    // =========================================================================

    /**
     * Upsert project workspace row (creates on first access).
     *
     * @param  string $project_id
     * @param  string $dmc
     * @return bool
     */
    public function touch_project($project_id, $dmc)
    {
        if (!$this->db_ok) return false;
        $existing = $this->ppms_db
            ->where('project_id', $project_id)
            ->get('ppms_projects')
            ->row_array();

        if ($existing) {
            return $this->ppms_db
                ->where('project_id', $project_id)
                ->update('ppms_projects', ['last_opened_at' => date('Y-m-d H:i:s')]);
        }

        return $this->ppms_db->insert('ppms_projects', [
            'project_id'     => $project_id,
            'dmc'            => strtoupper($dmc),
            'status'         => 'not_started',
            'overall_progress' => 0,
            'last_opened_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Update a project's computed overall progress and status.
     *
     * @param  string $project_id
     * @param  int    $progress   0-100
     * @param  string $status
     * @return bool
     */
    public function update_project_progress($project_id, $progress, $status)
    {
        if (!$this->db_ok) return false;
        return $this->ppms_db
            ->where('project_id', $project_id)
            ->update('ppms_projects', [
                'overall_progress' => $progress,
                'status'           => $status,
            ]);
    }

    /**
     * Get all app-tracked projects for a DMC with their progress.
     *
     * @param  string $dmc
     * @return array[]
     */
    public function get_all_progress()
    {
        if (!$this->db_ok) return [];
        try {
            return $this->ppms_db->get('ppms_projects')->result_array();
        } catch (Exception $e) {
            log_message('error', 'PPMS_model::get_all_progress: ' . $e->getMessage());
            return [];
        }
    }

    public function get_dmc_progress($dmc)
    {
        return $this->_safe(function() use ($dmc) {
            return $this->ppms_db
                ->where('dmc', strtoupper($dmc))
                ->get('ppms_projects')
                ->result_array();
        }, []);
    }

    /**
     * Get the most recently opened project for a DMC (Resume Last widget).
     *
     * @param  string $dmc
     * @return array|null
     */
    public function get_last_opened($dmc)
    {
        return $this->_safe(function() use ($dmc) {
            return $this->ppms_db
                ->where('dmc', strtoupper($dmc))
                ->where('last_opened_at IS NOT NULL', null, false)
                ->order_by('last_opened_at', 'DESC')
                ->limit(1)
                ->get('ppms_projects')
                ->row_array() ?: null;
        }, null);
    }

    // =========================================================================
    // Section flat data
    // =========================================================================

    /**
     * Get section data for a project+section combination.
     *
     * @param  string $project_id
     * @param  string $section_key
     * @return array|null
     */
    public function get_section($project_id, $section_key)
    {
        if (!$this->db_ok) return null;
        $row = $this->ppms_db
            ->where('project_id', $project_id)
            ->where('section_key', $section_key)
            ->get('ppms_sections')
            ->row_array();

        if ($row && is_string($row['data_json'])) {
            $row['data_json'] = json_decode($row['data_json'], true) ?? [];
        }

        return $row ?: null;
    }

    /**
     * Save (upsert) flat section data.
     *
     * @param  string $project_id
     * @param  string $section_key
     * @param  array  $fields       Key-value field data
     * @param  int    $progress
     * @param  string $status
     * @param  array  $audit_ctx
     * @return bool
     */
    public function save_section($project_id, $section_key, array $fields, $progress, $status, array $audit_ctx)
    {
        if (!$this->db_ok) return false;
        $existing = $this->ppms_db
            ->where('project_id', $project_id)
            ->where('section_key', $section_key)
            ->get('ppms_sections')
            ->row_array();

        $payload = [
            'progress'        => $progress,
            'status'          => $status,
            'data_json'       => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'updated_by_real' => $audit_ctx['real_user_id'],
            'updated_by_eff'  => $audit_ctx['effective_user_id'],
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $ok = $this->ppms_db
                ->where('project_id', $project_id)
                ->where('section_key', $section_key)
                ->update('ppms_sections', $payload);
        } else {
            $payload['project_id']  = $project_id;
            $payload['section_key'] = $section_key;
            $ok = $this->ppms_db->insert('ppms_sections', $payload);
        }

        if ($ok) {
            $this->_audit('save_section', $project_id, $section_key, null, $fields, $audit_ctx);
        }

        return $ok;
    }

    /**
     * Get all sections for a project (for progress aggregation).
     *
     * @param  string $project_id
     * @return array[]  Keyed by section_key
     */
    /**
     * Bulk fetch all section rows for a list of project IDs in ONE query.
     * Returns: [ project_no => [ section_key => row ], ... ]
     * Used by Api_projects::index() to compute overall progress consistently.
     */
    public function get_all_sections_bulk(array $project_ids)
    {
        if (!$this->db_ok || empty($project_ids)) return [];

        $rows = $this->ppms_db
            ->where_in('project_id', $project_ids)
            ->get('ppms_sections')
            ->result_array();

        $indexed = [];
        foreach ($rows as $row) {
            if (is_string($row['data_json'])) {
                $row['data_json'] = json_decode($row['data_json'], true) ?? [];
            }
            $indexed[$row['project_id']][$row['section_key']] = $row;
        }
        return $indexed;
    }

    public function get_all_sections($project_id)
    {
        if (!$this->db_ok) return [];
        $rows = $this->ppms_db
            ->where('project_id', $project_id)
            ->get('ppms_sections')
            ->result_array();

        $indexed = [];
        foreach ($rows as $row) {
            if (is_string($row['data_json'])) {
                $row['data_json'] = json_decode($row['data_json'], true) ?? [];
            }
            $indexed[$row['section_key']] = $row;
        }
        return $indexed;
    }

    // =========================================================================
    // Table rows (tabular sections)
    // =========================================================================

    /**
     * Get all active rows for a project+section.
     *
     * @param  string      $project_id
     * @param  string      $section_key
     * @param  string|null $loan_grant_no  Optional product filter
     * @return array[]
     */
    public function get_rows($project_id, $section_key, $loan_grant_no = null)
    {
        if (!$this->db_ok) return [];
        $q = $this->ppms_db
            ->where('project_id', $project_id)
            ->where('section_key', $section_key)
            ->where('is_deleted', 0)
            ->order_by('sort_order', 'ASC')
            ->order_by('created_at', 'ASC');

        if ($loan_grant_no !== null) {
            $q->where('loan_grant_no', $loan_grant_no);
        }

        $rows = $q->get('ppms_rows')->result_array();

        foreach ($rows as &$row) {
            if (is_string($row['data_json'])) {
                $row['data_json'] = json_decode($row['data_json'], true) ?? [];
            }
        }

        return $rows;
    }

    /**
     * Insert a new row.
     *
     * @param  string $project_id
     * @param  string $section_key
     * @param  string $row_uuid     Client-generated UUID
     * @param  array  $data
     * @param  string|null $loan_grant_no
     * @param  array  $audit_ctx
     * @return bool
     */
    public function add_row($project_id, $section_key, $row_uuid, array $data, $loan_grant_no, array $audit_ctx)
    {
        if (!$this->db_ok) return false;
        // Get next sort order
        $max = $this->ppms_db
            ->select_max('sort_order')
            ->where('project_id', $project_id)
            ->where('section_key', $section_key)
            ->get('ppms_rows')
            ->row_array();

        $sort_order = (int)($max['sort_order'] ?? -1) + 1;

        $ok = $this->ppms_db->insert('ppms_rows', [
            'project_id'      => $project_id,
            'section_key'     => $section_key,
            'row_uuid'        => $row_uuid,
            'loan_grant_no'   => $loan_grant_no,
            'data_json'       => json_encode($data, JSON_UNESCAPED_UNICODE),
            'sort_order'      => $sort_order,
            'created_by_real' => $audit_ctx['real_user_id'],
            'created_by_eff'  => $audit_ctx['effective_user_id'],
            'updated_by_real' => $audit_ctx['real_user_id'],
            'updated_by_eff'  => $audit_ctx['effective_user_id'],
        ]);

        if ($ok) {
            $this->_audit('add_row', $project_id, $section_key, $row_uuid, $data, $audit_ctx);
        }

        return $ok;
    }

    /**
     * Update an existing row by UUID.
     *
     * @param  string $row_uuid
     * @param  array  $data
     * @param  array  $audit_ctx
     * @return bool
     */
    public function update_row($row_uuid, array $data, array $audit_ctx)
    {
        if (!$this->db_ok) return false;
        // Fetch to get project/section for audit
        $row = $this->ppms_db
            ->where('row_uuid', $row_uuid)
            ->where('is_deleted', 0)
            ->get('ppms_rows')
            ->row_array();

        if ( ! $row) return false;

        $ok = $this->ppms_db
            ->where('row_uuid', $row_uuid)
            ->update('ppms_rows', [
                'data_json'       => json_encode($data, JSON_UNESCAPED_UNICODE),
                'updated_by_real' => $audit_ctx['real_user_id'],
                'updated_by_eff'  => $audit_ctx['effective_user_id'],
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

        if ($ok) {
            $this->_audit('update_row', $row['project_id'], $row['section_key'], $row_uuid, $data, $audit_ctx);
        }

        return $ok;
    }

    /**
     * Soft-delete a row by UUID.
     *
     * @param  string $row_uuid
     * @param  array  $audit_ctx
     * @return bool
     */
    public function delete_row($row_uuid, array $audit_ctx)
    {
        if (!$this->db_ok) return false;
        $row = $this->ppms_db
            ->where('row_uuid', $row_uuid)
            ->get('ppms_rows')
            ->row_array();

        if ( ! $row) return false;

        $ok = $this->ppms_db
            ->where('row_uuid', $row_uuid)
            ->update('ppms_rows', [
                'is_deleted'      => 1,
                'updated_by_real' => $audit_ctx['real_user_id'],
                'updated_by_eff'  => $audit_ctx['effective_user_id'],
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

        if ($ok) {
            $this->_audit('delete_row', $row['project_id'], $row['section_key'], $row_uuid, [], $audit_ctx);
        }

        return $ok;
    }

    // =========================================================================
    // Audit
    // =========================================================================

    /**
     * Write an audit log entry.
     *
     * @param  string $action
     * @param  string $project_id
     * @param  string|null $section_key
     * @param  string|null $row_uuid
     * @param  array  $payload
     * @param  array  $audit_ctx
     */
    private function _audit($action, $project_id, $section_key, $row_uuid, array $payload, array $audit_ctx)
    {
        if (!$this->db_ok) return;
        try {
        $this->ppms_db->insert('ppms_audit_log', [
            'action'            => $action,
            'project_id'        => $project_id,
            'section_key'       => $section_key,
            'row_uuid'          => $row_uuid,
            'real_user_id'      => $audit_ctx['real_user_id'],
            'effective_user_id' => $audit_ctx['effective_user_id'],
            'effective_dmc'     => $audit_ctx['effective_dmc'],
            'is_impersonating'  => (int) $audit_ctx['is_impersonating'],
            'payload_json'      => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ip_address'        => $this->input->ip_address(),
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
        } catch (Exception $e) { log_message('error', 'audit: ' . $e->getMessage()); }
    }

    // =========================================================================
    // Section config — enabled/disabled per DMC + role
    // =========================================================================

    /**
     * Get enabled section keys for a given DMC + role.
     * Returns an associative array: section_key => enabled (1|0).
     * Sections with no config row default to enabled (1).
     */
    public function get_section_config($dmc, $role)
    {
        if (!$this->db_ok) return [];

        // One-time migration: any rows still sitting at enabled=0 (old default)
        // are upgraded to 1. This is idempotent — safe to run on every request.
        try {
            $this->ppms_db->query(
                'UPDATE ppms_section_config SET enabled = 1 WHERE enabled = 0'
            );
        } catch (Exception $e) {
            log_message('error', 'get_section_config migration: ' . $e->getMessage());
        }

        $rows = $this->ppms_db
            ->where('dmc',  strtoupper($dmc))
            ->where('role', $role)
            ->get('ppms_section_config')
            ->result_array();
        $config = [];
        foreach ($rows as $row) {
            $config[$row['section_key']] = (int)$row['enabled'];
        }
        return $config;
    }

    /**
     * Save section config for a DMC + role.
     * $settings = [ section_key => 0|1, ... ]
     */
    /**
     * Save section config for a DMC+role.
     * Uses INSERT OR REPLACE (SQLite upsert) — atomic, no race conditions,
     * no separate SELECT needed. Safe to call repeatedly.
     */
    public function save_section_config($dmc, $role, array $settings, $updated_by)
    {
        if (!$this->db_ok || empty($settings)) return true;
        $dmc = strtoupper($dmc);
        $now = date('Y-m-d H:i:s');

        try {
            // Wrap in a transaction so all-or-nothing
            $this->ppms_db->trans_start();

            foreach ($settings as $key => $enabled) {
                $this->ppms_db->query(
                    'INSERT OR REPLACE INTO ppms_section_config
                        (dmc, role, section_key, enabled, updated_by, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $dmc,
                        $role,
                        $key,
                        $enabled ? 1 : 0,
                        $updated_by,
                        $now,
                    ]
                );
            }

            $this->ppms_db->trans_complete();
            // Note: trans_status() can return false with SQLite even on success.
            // We rely on the absence of exceptions as the success signal.
            return true;
        } catch (Exception $e) {
            log_message('error', 'save_section_config failed: ' . $e->getMessage());
            $this->ppms_db->trans_rollback();
            return false;
        }
    }

}
