<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Ppms_check — lightweight CSV diagnostic. Streams line by line.
 * Route: GET /ppms-check   (add to routes.php)
 * REMOVE before production.
 */
class Ppms_check extends CI_Controller
{
    /** Parse DD/MM/YYYY dates — mirrors CSV_reader::_parse_ddmmyyyy exactly */
    private function _parse_date($s)
    {
        $s = trim($s);
        if (empty($s)) return false;

        $formats = ['d/m/Y', 'j/n/Y', 'd/m/y', 'j/n/y', 'Y-m-d', 'd-m-Y'];
        foreach ($formats as $fmt) {
            $d = DateTime::createFromFormat($fmt, $s);
            if ($d && $d->format($fmt) === $s) return $d->getTimestamp();
        }
        // strtotime only for non-slash strings
        if (strpos($s, '/') === false) {
            $ts = strtotime($s);
            if ($ts !== false && $ts > 0) return $ts;
        }
        return false;
    }

    public function index()
    {
        $csv_path = FCPATH . 'csv_data/';
        $file     = $csv_path . 'caanddisb.csv';

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>PPMS CSV Check</title>
        <style>
          body{font-family:monospace;font-size:13px;padding:24px;background:#f4f6f9;color:#212529}
          h2{color:#1565C0;margin:16px 0 6px}
          .ok{color:#22c55e;font-weight:700}.fail{color:#dc2626;font-weight:700}
          .box{background:#fff;border:1px solid #dee2e8;border-radius:8px;padding:16px;margin-bottom:12px}
          table{border-collapse:collapse;width:100%;margin-top:8px}
          th{background:#f0f4f8;padding:6px 10px;text-align:left;font-size:11px;text-transform:uppercase}
          td{padding:5px 10px;border-top:1px solid #f0f4f8}
        </style></head><body>';

        // ── 1. File check ────────────────────────────────────────────────────
        echo '<div class="box"><h2>1. File</h2>';
        echo 'Path: <b>' . htmlspecialchars($file) . '</b><br><br>';

        if (!file_exists($file)) {
            echo '<span class="fail">✗ caanddisb.csv NOT FOUND</span><br><br>Files in csv_data/:<br>';
            foreach (glob($csv_path . '*') ?: [] as $f)
                echo '&nbsp;&nbsp;' . htmlspecialchars(basename($f)) . ' (' . round(filesize($f)/1024,1) . ' KB)<br>';
            echo '</div></body></html>'; return;
        }

        echo '<span class="ok">✓ EXISTS</span> — '
           . number_format(filesize($file)) . ' bytes ('
           . round(filesize($file)/1048576, 2) . ' MB)<br>';
        echo '</div>';

        // ── 2. Stream: header + row count + samples ──────────────────────────
        echo '<div class="box"><h2>2. Streaming parse</h2>';

        $fh = fopen($file, 'r');
        $bom = fread($fh, 3);
        fseek($fh, ($bom === "\xEF\xBB\xBF") ? 3 : 0);

        $raw_header = fgetcsv($fh, 0, ',');
        $headers = array_map(fn($h) => strtolower(trim(str_replace([' ','-'],'_',$h))), $raw_header);

        $rw_idx  = array_search('report_week', $headers);
        $dmc_idx = array_search('dmc', $headers);

        // Required columns
        echo '<b>Columns (' . count($headers) . '):</b> ' . htmlspecialchars(implode(', ', $headers)) . '<br><br>';
        echo '<b>Required column check:</b><br>';
        foreach (['project_no','project_name','dmc','sector','net_amount','loan_grant_no','report_week'] as $col) {
            $pos = array_search($col, $headers);
            echo '&nbsp;&nbsp;' . ($pos !== false ? '<span class="ok">✓</span>' : '<span class="fail">✗ MISSING</span>') . " $col<br>";
        }

        // Stream all rows — only keep scalars
        $total_rows  = 0;
        $dmc_counts  = [];
        $week_counts = [];
        $sample_rows = [];
        $max_ts      = 0;

        while (($row = fgetcsv($fh, 0, ',')) !== false) {
            if (count($row) === 1 && trim($row[0]) === '') continue;
            $total_rows++;

            $dmc  = $dmc_idx !== false ? strtoupper(trim($row[$dmc_idx] ?? '')) : '';
            $week = $rw_idx  !== false ? trim($row[$rw_idx] ?? '') : '';

            if ($dmc)  $dmc_counts[$dmc]   = ($dmc_counts[$dmc]  ?? 0) + 1;
            if ($week) $week_counts[$week]  = ($week_counts[$week] ?? 0) + 1;

            // Track max timestamp
            if ($week) {
                $ts = $this->_parse_date($week);
                if ($ts && $ts > $max_ts) $max_ts = $ts;
            }

            if (count($sample_rows) < 5) {
                $sample_rows[] = array_combine(
                    $headers,
                    array_map('trim', array_slice(array_pad($row, count($headers), ''), 0, count($headers)))
                );
            }
        }
        fclose($fh);

        echo '<br><b>Total rows: <span class="ok">' . number_format($total_rows) . '</span></b><br>';
        echo '</div>';

        // ── 3. report_week format ────────────────────────────────────────────
        echo '<div class="box"><h2>3. report_week values (top 8 by frequency)</h2>';
        arsort($week_counts);
        $i = 0;
        foreach ($week_counts as $week => $cnt) {
            $ts = $this->_parse_date($week);
            $ok = ($ts !== false && $ts > 0);
            echo '<code>' . htmlspecialchars($week) . '</code> '
               . ($ok ? '<span class="ok">✓ ' . date('Y-m-d', $ts) . '</span>'
                      : '<span class="fail">✗ cannot parse</span>')
               . " — $cnt rows<br>";
            if (++$i >= 8) break;
        }
        echo '<br><b>Latest week: ';
        if ($max_ts) {
            echo '<span class="ok">' . date('d/m/Y', $max_ts) . ' = ' . date('D d M Y', $max_ts) . '</span>';
        } else {
            echo '<span class="fail">could not determine (date parse failed for all rows)</span>';
        }
        echo '</b><br>';

        // Count snapshot rows — per-project latest week (same logic as CSV_reader)
        $snap_count  = 0;
        $snap_dmc    = [];
        $proj_idx    = array_search('project_no', $headers);

        // Pass 1b: build per-project max ts (already have $week_counts but need per-project)
        $proj_max = [];
        $fh2 = fopen($file, 'r');
        $bom2 = fread($fh2, 3);
        fseek($fh2, ($bom2 === "\xEF\xBB\xBF") ? 3 : 0);
        fgetcsv($fh2, 0, ',');
        while (($row = fgetcsv($fh2, 0, ',')) !== false) {
            if (count($row) === 1 && trim($row[0]) === '') continue;
            $pid  = $proj_idx !== false ? trim($row[$proj_idx] ?? '') : '';
            $week = $rw_idx   !== false ? trim($row[$rw_idx]   ?? '') : '';
            if (!$pid || !$week) continue;
            $ts = $this->_parse_date($week);
            if ($ts && (!isset($proj_max[$pid]) || $ts > $proj_max[$pid])) {
                $proj_max[$pid] = $ts;
            }
        }
        fclose($fh2);

        // Pass 2b: count rows matching per-project latest
        $fh3 = fopen($file, 'r');
        $bom3 = fread($fh3, 3);
        fseek($fh3, ($bom3 === "\xEF\xBB\xBF") ? 3 : 0);
        fgetcsv($fh3, 0, ',');
        while (($row = fgetcsv($fh3, 0, ',')) !== false) {
            if (count($row) === 1 && trim($row[0]) === '') continue;
            $pid  = $proj_idx !== false ? trim($row[$proj_idx] ?? '') : '';
            $week = $rw_idx   !== false ? trim($row[$rw_idx]   ?? '') : '';
            $dmc  = $dmc_idx  !== false ? strtoupper(trim($row[$dmc_idx] ?? '')) : '';
            if (!$pid || !$week) continue;
            $ts = $this->_parse_date($week);
            if ($ts && isset($proj_max[$pid]) && $ts === $proj_max[$pid]) {
                $snap_count++;
                if ($dmc) $snap_dmc[$dmc] = ($snap_dmc[$dmc] ?? 0) + 1;
            }
        }
        fclose($fh3);

        echo '<b>Rows in latest snapshot (per-project): <span class="ok">' . $snap_count . '</span></b><br>';
        echo '<b>Total raw rows (all weeks): <span class="ok">' . number_format($total_rows) . '</span></b> — these all feed the project card list<br>';
        echo '</div>';

        // ── 4. DMC breakdown ─────────────────────────────────────────────────
        echo '<div class="box"><h2>4. DMC breakdown (latest snapshot)</h2>';
        arsort($snap_dmc);
        echo '<table><tr><th>DMC</th><th>Rows in snapshot</th></tr>';
        foreach ($snap_dmc as $dmc => $cnt)
            echo '<tr><td><b>' . htmlspecialchars($dmc) . '</b></td><td>' . $cnt . '</td></tr>';
        echo '</table><br>Distinct DMCs: <b>' . count($snap_dmc) . '</b></div>';

        // ── 5. First 5 rows ───────────────────────────────────────────────────
        echo '<div class="box"><h2>5. First 5 data rows</h2><table><tr>';
        $show = ['project_no','project_name','dmc','sector','net_amount','loan_grant_no','report_week'];
        foreach ($show as $c) echo '<th>' . $c . '</th>';
        echo '</tr>';
        foreach ($sample_rows as $r) {
            echo '<tr>';
            foreach ($show as $c) echo '<td>' . htmlspecialchars(substr($r[$c] ?? '', 0, 35)) . '</td>';
            echo '</tr>';
        }
        echo '</table></div>';

        echo '<p style="color:#6c757d;font-size:11px">Remove Ppms_check.php before production.</p>';
        echo '</body></html>';
    }
}
