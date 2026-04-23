<?php
/**
 * View: welcome/index.php
 * Landing page for sectorsinsightsdev.adb.org
 */
?>
<style>
    .welcome-wrap {
        max-width: 760px;
        margin: 60px auto;
        padding: 0 24px;
        text-align: center;
    }
    .welcome-logo {
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        color: var(--color-primary, #1565C0);
        margin-bottom: 32px;
    }
    .welcome-title {
        font-size: 32px;
        font-weight: 800;
        letter-spacing: -0.5px;
        color: var(--color-text, #1a1a2e);
        margin-bottom: 12px;
    }
    .welcome-sub {
        font-size: 16px;
        color: var(--color-text-muted, #6c757d);
        margin-bottom: 48px;
        line-height: 1.6;
    }
    .welcome-cards {
        display: flex;
        gap: 20px;
        justify-content: center;
        flex-wrap: wrap;
    }
    .welcome-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 28px 32px;
        width: 220px;
        text-decoration: none;
        color: inherit;
        transition: box-shadow .15s, transform .15s;
        box-shadow: 0 1px 4px rgba(0,0,0,.06);
    }
    .welcome-card:hover {
        box-shadow: 0 6px 24px rgba(21,101,192,.13);
        transform: translateY(-2px);
        border-color: #1565C0;
    }
    .welcome-card-icon {
        font-size: 32px;
        margin-bottom: 14px;
    }
    .welcome-card-title {
        font-size: 15px;
        font-weight: 700;
        color: #1565C0;
        margin-bottom: 6px;
    }
    .welcome-card-desc {
        font-size: 12px;
        color: #6c757d;
        line-height: 1.5;
    }
</style>

<div class="welcome-wrap">
    <div class="welcome-logo">Asian Development Bank</div>
    <h1 class="welcome-title">Operations Insights</h1>
    <p class="welcome-sub">
        Project Performance Monitoring System
    </p>
    <div class="welcome-cards">
        <a href="<?= site_url('ppms') ?>" class="welcome-card">
            <div class="welcome-card-icon">📊</div>
            <div class="welcome-card-title">PPMS</div>
            <div class="welcome-card-desc">Project Performance Monitoring System</div>
        </a>
        <a href="<?= site_url('simulate') ?>" class="welcome-card">
            <div class="welcome-card-icon">👤</div>
            <div class="welcome-card-title">Switch Profile</div>
            <div class="welcome-card-desc">View the system as a PTL or country user</div>
        </a>
    </div>
</div>
