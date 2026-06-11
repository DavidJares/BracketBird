<?php

declare(strict_types=1);

$pageTitle = isset($title) && is_string($title) ? $title : $t('public_view.title');
$publicViewTheme = is_array($tournament ?? null) ? (string) ($tournament['public_view_theme'] ?? 'dark') : 'dark';
if (!in_array($publicViewTheme, ['dark', 'light'], true)) {
    $publicViewTheme = 'dark';
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($currentLanguage ?? 'en', ENT_QUOTES, 'UTF-8') ?>" class="bb-theme-<?= htmlspecialchars($publicViewTheme, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="<?= htmlspecialchars($url('/assets/css/bracketbird.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
</head>
<body class="bb-public-body bb-theme-<?= htmlspecialchars($publicViewTheme, ENT_QUOTES, 'UTF-8') ?>">
<main class="container-fluid bb-public-main bb-public-display">
    <?php require $viewFile; ?>
</main>
</body>
</html>
