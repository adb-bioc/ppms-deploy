<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Api_debug — shows exactly what /api/projects would return, with diagnostics.
 * No PPMS_Controller dependency. Visit directly in browser.
 * Route: GET /api/debug
 * REMOVE before production.
 */
class Api_debug extends CI_Controller
{
    public function index()
    {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
        <title>PPMS API Debug</title>
        <style>
          body{font-family:monospace;font-size:13px;background:#f4f6f9;padding:20px;color:#212529}
          h2{font-size:14px;font-weight:700;margin:16px 0 6px;color:#1565C0}
          .box{background:#fff;border:1px solid #dee2e8;border-radius:6px;padding:12px 16px;margin-bottom:12px}
          .ok{color:#22c55e;font-weight:700} .fail{color:#dc2626;font-weight:700} .warn{color:#f59e0b;font-weight:700}
          pre{background:#f8f9fc;padding:10px;border-radius:4px;overflow-x:auto;font-size:12px;white-space:pre-wrap}
          .row{margin-bottom:4px}
        </style></head><body>
        <h1 style="font-size:16px;font-weight:700;margin-bottom:16px">PPMS API Debug</h1>';

        $this->load->library('session');

        // ── Session ──────────────────────────────────────────────────────────
        echo '<div class="box"><h2>Session</h2>';
        $sess = $this->session->userdata('simulation_session') ?? [];
        $eff  = $sess['effective_user'] ?? null;
        if ($eff) {
            echo '<div class="row"><span class="ok">✓</span> Session found</div>';
            echo '<div class="row">User: <b>' . htmlspecialchars($eff['name'] ?? '?') . '</b></div>';
            echo '<div class="row">Role: <b>' . htmlspecialchars($eff['role'] ?? '?') . '</b></div>';
            echo '<div class="row">Country: <b>' . htmlspecialchars($eff['country'] ?? 'null (admin)') . '</b></div>';
            $dmc = ($eff['role'] === 'admin') ? null : strtoupper($eff['country'] ?? '');
            echo '<div class="row">Effective DMC for query: <b>' . ($dmc ?? 'ALL (null = no filter)') . '</b></div>';
        } else {
            echo '<div class="row"><span class="fail">✗</span> No session — visit <a href="../index.php/simulate">simulate</a> first</div>';
        }
        echo '</div>';

        // ── CSV file ─────────────────────────────────────────────────────────
        echo '<div class="box"><h2>CSV File</h2>';
        $csv_path = FCPATH . 'csv_data/caanddisb.csv';
        if (file_exists($csv_path)) {
            $size = round(filesize($csv_path) / 1048576, 2);
            echo '<div class="row"><span class="ok">✓</span> caanddisb.csv found — ' . $size . ' MB</div>';
        } else {
            echo '<div class="row"><span class="fail">✗</span> caanddisb.csv NOT FOUND at: ' . $csv_path . '</div>';
            echo '<div class="row">Copy caanddisb.csv into the csv_data/ folder</div>';
        }
        echo '</div>';

        if (!$eff || !file_exists($csv_path)) {
            echo '</body></html>';
            return;
        }

        // ── Load PPMS libraries and call get_projects ─────────────────────────
        echo '<div class="box"><h2>Live API Call</h2>';
        try {
            $this->config->load('ppms', true);
            $this->load->library(['ppms_cache', 'csv_reader']);

            $dmc      = ($eff['role'] === 'admin') ? null : strtoupper($eff['country'] ?? '');
            if ($dmc === null) {
                $projects = [];
                foreach ($this->csv_reader->get_dmc_list() as $_d) {
                    $projects = array_merge($projects, $this->csv_reader->get_projects($_d));
                }
            } else {
                $projects = $this->csv_reader->get_projects($dmc);
            }

            echo '<div class="row"><span class="ok">✓</span> get_projects(' . ($dmc ?? 'null') . ') returned <b>' . count($projects) . ' projects</b></div>';

            if (count($projects) > 0) {
                echo '<pre>';
                foreach (array_slice($projects, 0, 5) as $p) {
                    echo htmlspecialchars(sprintf(
                        "%-15s | %-10s | products=%-30s | $%.2fM | %s\n",
                        $p['project_no'], $p['dmc'],
                        implode(', ', array_map(fn($x) => is_string($x) ? $x : ($x['loan_grant_fmt'] ?? '?'), $p['products'] ?? [])),
                        $p['net_amount'],
                        mb_substr($p['project_name'], 0, 40)
                    ));
                }
                if (count($projects) > 5) echo '... and ' . (count($projects) - 5) . ' more';
                echo '</pre>';
            } else {
                echo '<div class="row"><span class="warn">⚠</span> 0 projects returned — CSV exists but snapshot is empty</div>';
                echo '<div class="row">Check report_week date format in CSV (expected DD/MM/YYYY)</div>';

                // Quick peek at report_week values
                $fh = fopen($csv_path, 'r');
                $bom = fread($fh, 3);
                fseek($fh, ($bom === "\xEF\xBB\xBF") ? 3 : 0);
                $hdr = fgetcsv($fh, 0, ',');
                $headers = array_map(fn($h) => strtolower(trim(str_replace([' ','-'],'_',$h))), $hdr);
                $rw_idx = array_search('report_week', $headers);
                $weeks = [];
                while (($row = fgetcsv($fh, 0, ',')) !== false && count($weeks) < 5) {
                    $w = trim($row[$rw_idx] ?? '');
                    if ($w && !in_array($w, $weeks)) $weeks[] = $w;
                }
                fclose($fh);
                echo '<div class="row">Sample report_week values: <b>' . implode(', ', $weeks) . '</b></div>';
            }
        } catch (Exception $e) {
            echo '<div class="row"><span class="fail">✗</span> Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        echo '</div>';

        // ── PPMS_CONFIG values ────────────────────────────────────────────────
        $this->load->helper('url');
        echo '<div class="box"><h2>PPMS_CONFIG (from PHP)</h2>';
        echo '<div class="row">baseUrl: <b>' . base_url('index.php/') . '</b></div>';
        echo '<div class="row">apiBase: <b>' . base_url('index.php/api') . '</b></div>';
        echo '<div class="row">Expected API URL: <b>' . base_url('index.php/api') . '/projects</b></div>';
        echo '</div>';

        echo '</body></html>';
    }
}
