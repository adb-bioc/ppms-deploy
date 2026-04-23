<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Performance Monitoring System</title>

    <?php
    // mtime-based cache busters — browser caches aggressively, only re-fetches on file change
    $css_mtime    = @filemtime(FCPATH . 'public/css/ppms.css')    ?: 1;
    $bundle_mtime = @filemtime(FCPATH . 'public/js/ppms.bundle.js') ?: 1;
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= base_url('public/css/ppms.css') ?>?v=<?= $css_mtime ?>">

    <?php if (file_exists(FCPATH . 'public/css/ppms-vue.css')): ?>
    <link rel="stylesheet" href="<?= base_url('public/css/ppms-vue.css') ?>?v=<?= $css_mtime ?>">
    <?php endif; ?>

    <style>
        #ppms-app[data-v-loading] { opacity: 0; }
        .ppms-sk {
            display: flex; align-items: center; justify-content: center;
            height: 100vh; flex-direction: column; gap: 12px;
            font-family: 'Segoe UI', system-ui, sans-serif; color: #6c757d; font-size: 13px;
        }
        .ppms-sk-ring {
            width: 32px; height: 32px;
            border: 3px solid #dee2e8; border-top-color: #1565C0;
            border-radius: 50%; animation: ppms-spin .7s linear infinite;
        }
        @keyframes ppms-spin { to { transform: rotate(360deg); } }
    </style>

    <?php
    // Inject full session context so Vue bootstraps instantly — zero extra API call for identity
    $session_data  = $this->session->userdata('simulation_session') ?? [];
    $guest_profile = ['id' => 'guest', 'name' => 'Guest', 'role' => 'guest', 'country' => null, 'avatar_initials' => '?'];
    $eff_user      = $session_data['effective_user'] ?? $guest_profile;
    $act_user      = $session_data['actual_user']    ?? $guest_profile;

    // index_page is '' when mod_rewrite removes index.php, 'index.php' when it doesn't.
    // This makes API URLs work on both server configs without any manual change.
    $idx          = $this->config->item('index_page');
    $idx_prefix   = $idx ? $idx . '/' : '';

    $ppms_config   = [
        'baseUrl'        => base_url($idx_prefix),
        'apiBase'        => base_url($idx_prefix . 'api'),
        'currentUser'    => $eff_user,
        'isSimulation'   => true,
        'sessionContext' => [
            'actual_user'      => $act_user,
            'effective_user'   => $eff_user,
            'is_impersonating' => (bool)($session_data['is_impersonating'] ?? false),
            'is_simulation'    => true,
        ],
    ];
    ?>
    <script>window.PPMS_CONFIG = <?= json_encode($ppms_config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
</head>
<body>

<div id="ppms-app" data-v-loading>
    <div class="ppms-sk">
        <div class="ppms-sk-ring"></div>
        <span>Loading…</span>
    </div>
</div>

<?php if (file_exists(FCPATH . 'public/js/ppms.bundle.js')): ?>
<script type="module" src="<?= base_url('public/js/ppms.bundle.js') ?>?v=<?= $bundle_mtime ?>"></script>
<?php else: ?>
<script>document.querySelector('.ppms-sk span').textContent = 'Frontend not built — run: cd vue_src && npm run build';</script>
<?php endif; ?>

</body>
</html>
