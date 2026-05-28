<?php

declare(strict_types=1);

$pageTitle = isset($title) && is_string($title) ? $title : 'FTT';
$appName = (string) ($config['app']['name'] ?? 'FTT');
$flashType = is_array($flash ?? null) ? (string) ($flash['type'] ?? '') : '';
$flashMessage = is_array($flash ?? null) ? (string) ($flash['message'] ?? '') : '';
$brandHref = $url('/');
$sidebarSectionLabels = [
    'tournament' => 'Tournament',
    'groups' => 'Teams & Groups',
    'matches' => 'Group Stage',
    'knockout' => 'Knockout',
    'public_view' => 'Public View',
    'teams' => 'Teams',
    'exports' => 'Exports & Print',
];
$sidebarTournament = is_array($tournament ?? null) ? $tournament : null;
$sidebarTournamentId = is_array($sidebarTournament) ? (int) ($sidebarTournament['id'] ?? 0) : 0;
$sidebarTournamentSlug = is_array($sidebarTournament) ? (string) ($sidebarTournament['slug'] ?? '') : '';
$sidebarTournamentName = is_array($sidebarTournament) ? (string) ($sidebarTournament['name'] ?? '') : '';
$sidebarActiveSection = is_string($activeSection ?? null) ? (string) $activeSection : '';

if ($sidebarActiveSection === '' && is_string($matchStage ?? null)) {
    $sidebarActiveSection = $matchStage === 'knockout' ? 'knockout' : 'matches';
}

if (is_array($currentSuperadmin ?? null)) {
    $brandHref = $url('/admin/dashboard');
} elseif (is_array($currentTournamentAdmin ?? null)) {
    $brandHref = $url('/tournament/' . (string) ($currentTournamentAdmin['slug'] ?? '') . '/admin');
}

$sidebarSectionNav = [];
if (is_array($sectionNav ?? null)) {
    foreach ($sidebarSectionLabels as $sectionKey => $sectionLabel) {
        $sidebarSectionNav[$sectionKey] = (string) ($sectionNav[$sectionKey] ?? '#');
    }
} elseif ($sidebarTournamentId > 0 && $sidebarTournamentSlug !== '') {
    $isTournamentAdminContext = is_array($currentTournamentAdmin ?? null)
        && (string) ($currentTournamentAdmin['slug'] ?? '') === $sidebarTournamentSlug;
    $baseAdminPath = $isTournamentAdminContext
        ? '/tournament/' . $sidebarTournamentSlug . '/admin'
        : '/admin/tournament';
    foreach ($sidebarSectionLabels as $sectionKey => $sectionLabel) {
        $sectionPath = $sectionKey === 'tournament' ? '' : '/' . $sectionKey;
        $sidebarSectionNav[$sectionKey] = $url($baseAdminPath . $sectionPath . ($isTournamentAdminContext ? '' : '?id=' . $sidebarTournamentId));
    }
}

$alertClass = 'alert-info';
if ($flashType === 'success') {
    $alertClass = 'alert-success';
}
if ($flashType === 'error') {
    $alertClass = 'alert-danger';
}
?>
<!doctype html>
<html lang="en" class="bb-theme-dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('bracketbird.adminTheme') || 'dark';
                document.documentElement.className = document.documentElement.className.replace(/\bbb-theme-(dark|light)\b/g, '').trim();
                document.documentElement.classList.add(theme === 'light' ? 'bb-theme-light' : 'bb-theme-dark');
            } catch (error) {
                document.documentElement.classList.add('bb-theme-dark');
            }
        })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="<?= htmlspecialchars($url('/assets/css/bracketbird.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="bb-admin-body">
<div class="bb-app-shell">
    <aside class="bb-sidebar" aria-label="Primary navigation">
        <a class="bb-brand" href="<?= htmlspecialchars($brandHref, ENT_QUOTES, 'UTF-8') ?>">
            <span class="bb-brand-mark">BB</span>
            <span>
                <span class="bb-brand-name"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="bb-brand-subtitle">Tournament control</span>
            </span>
        </a>
        <nav class="bb-side-nav">
            <?php if (count($sidebarSectionNav) > 0): ?>
                <?php if ($sidebarTournamentName !== ''): ?>
                    <div class="bb-side-context">
                        <span class="bb-side-context-label">Tournament</span>
                        <span class="bb-side-context-name"><?= htmlspecialchars($sidebarTournamentName, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>
                <?php foreach ($sidebarSectionLabels as $sectionKey => $sectionLabel): ?>
                    <?php $sectionHref = (string) ($sidebarSectionNav[$sectionKey] ?? '#'); ?>
                    <a class="bb-side-link <?= $sidebarActiveSection === $sectionKey ? 'active' : '' ?>" href="<?= htmlspecialchars($sectionHref, ENT_QUOTES, 'UTF-8') ?>">
                        <span><?= htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endforeach; ?>
            <?php elseif (is_array($currentSuperadmin ?? null)): ?>
                <a class="bb-side-link active" href="<?= htmlspecialchars($url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>">
                    <span>Dashboard</span>
                    <span class="bb-side-link-muted">Tournaments</span>
                </a>
            <?php else: ?>
                <a class="bb-side-link active" href="<?= htmlspecialchars($brandHref, ENT_QUOTES, 'UTF-8') ?>">
                    <span>Home</span>
                    <span class="bb-side-link-muted">App</span>
                </a>
            <?php endif; ?>
        </nav>
    </aside>

    <div class="bb-main-area">
        <header class="bb-topbar">
            <div class="bb-topbar-inner">
                <a class="bb-brand bb-mobile-brand" href="<?= htmlspecialchars($brandHref, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="bb-brand-mark">BB</span>
                    <span class="bb-brand-name"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></span>
                </a>
                <a class="bb-topbar-app" href="<?= htmlspecialchars($brandHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></a>
                <div id="js-header-clock" class="bb-clock small pe-none" aria-label="Current time">--:--</div>
                <div class="bb-topbar-actions d-flex align-items-center gap-2 ms-auto">
                    <?php if ($sidebarTournamentName !== ''): ?>
                        <span class="bb-topbar-tournament"><?= htmlspecialchars($sidebarTournamentName, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary bb-theme-toggle" id="js-admin-theme-toggle" aria-label="Toggle admin theme">Theme</button>
                    <?php if (is_array($currentSuperadmin ?? null)): ?>
                        <span class="bb-topbar-user"><?= htmlspecialchars((string) $currentSuperadmin['username'], ENT_QUOTES, 'UTF-8') ?></span>
                        <form method="post" action="<?= htmlspecialchars($url('/admin/logout'), ENT_QUOTES, 'UTF-8') ?>" class="m-0">
                            <?= $csrfField() ?>
                            <button type="submit" class="btn btn-sm btn-outline-light">Logout</button>
                        </form>
                    <?php elseif (is_array($currentTournamentAdmin ?? null)): ?>
                        <span class="bb-topbar-user"><?= htmlspecialchars((string) ($currentTournamentAdmin['name'] ?? 'Tournament admin'), ENT_QUOTES, 'UTF-8') ?></span>
                        <form method="post" action="<?= htmlspecialchars($url('/tournament/' . (string) ($currentTournamentAdmin['slug'] ?? '') . '/logout'), ENT_QUOTES, 'UTF-8') ?>" class="m-0">
                            <?= $csrfField() ?>
                            <button type="submit" class="btn btn-sm btn-outline-light">Logout</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <main class="bb-content pb-4">
            <?php if ($flashMessage !== ''): ?>
                <div class="alert bb-flash <?= htmlspecialchars($alertClass, ENT_QUOTES, 'UTF-8') ?>" role="alert">
                    <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php
            ob_start();
            require $viewFile;
            $viewOutput = (string) ob_get_clean();
            $csrfInput = '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
            $viewOutput = preg_replace('/(<form\b[^>]*\bmethod\s*=\s*["\']post["\'][^>]*>)/i', '$1' . $csrfInput, $viewOutput) ?? $viewOutput;
            echo $viewOutput;
            ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
    (function () {
        if (typeof bootstrap === 'undefined' || typeof bootstrap.Tooltip === 'undefined') {
            return;
        }

        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
            bootstrap.Tooltip.getOrCreateInstance(element);
        });
    })();

    (function () {
        var clock = document.getElementById('js-header-clock');
        if (!clock) {
            return;
        }

        function pad(value) {
            return value < 10 ? '0' + value : String(value);
        }

        function renderClock() {
            var now = new Date();
            clock.textContent = pad(now.getHours()) + ':' + pad(now.getMinutes());
        }

        renderClock();
        setInterval(renderClock, 1000);
    })();

    (function () {
        var button = document.getElementById('js-admin-theme-toggle');
        if (!button) {
            return;
        }

        function currentTheme() {
            return document.documentElement.classList.contains('bb-theme-light') ? 'light' : 'dark';
        }

        function applyTheme(theme) {
            var normalized = theme === 'light' ? 'light' : 'dark';
            document.documentElement.classList.toggle('bb-theme-light', normalized === 'light');
            document.documentElement.classList.toggle('bb-theme-dark', normalized !== 'light');
            button.textContent = normalized === 'light' ? 'Dark mode' : 'Light mode';
            button.setAttribute('aria-pressed', normalized === 'light' ? 'true' : 'false');
            try {
                localStorage.setItem('bracketbird.adminTheme', normalized);
            } catch (error) {
            }
        }

        applyTheme(currentTheme());
        button.addEventListener('click', function () {
            applyTheme(currentTheme() === 'light' ? 'dark' : 'light');
        });
    })();
</script>
</body>
</html>
