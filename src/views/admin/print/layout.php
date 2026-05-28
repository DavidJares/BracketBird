<?php

declare(strict_types=1);

/** @var string $printViewFile */
/** @var string $title */
/** @var string $printOutputTitle */
/** @var string $pageOrientation */
/** @var array<string, mixed> $tournament */
/** @var bool $prefill */
/** @var string $exportsUrl */
/** @var string $prefillToggleUrl */
/** @var string $publicUrl */
/** @var string $qrUrl */
/** @var string $printedAt */
/** @var array<int, list<array{set_number: int, score_a: int, score_b: int}>> $setsByMatchId */

$pageTitle = isset($title) && is_string($title) ? $title : 'Print';
$orientation = ($pageOrientation ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
$appName = (string) ($config['app']['name'] ?? 'BracketBird');
$eventDate = trim((string) ($tournament['event_date'] ?? ''));
$startTime = trim((string) ($tournament['start_time'] ?? ''));
if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $startTime) === 1) {
    $startTime = substr($startTime, 0, 5);
}
$location = trim((string) ($tournament['location'] ?? ''));

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$formatTime = static function (string $value): string {
    $value = trim($value);
    if ($value === '') {
        return 'TBD';
    }
    $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if ($dateTime instanceof DateTimeImmutable) {
        return $dateTime->format('H:i');
    }

    return $value;
};
$scoreText = static function (array $match, int $setNumber = 0, bool $invert = false) use ($prefill, $setsByMatchId): string {
    if (!$prefill || (string) ($match['status'] ?? '') !== 'finished') {
        return '';
    }

    if ($setNumber === 0) {
        $a = (int) ($match['sets_summary_a'] ?? 0);
        $b = (int) ($match['sets_summary_b'] ?? 0);
        return $invert ? ($b . ':' . $a) : ($a . ':' . $b);
    }

    $matchId = (int) ($match['id'] ?? 0);
    foreach ($setsByMatchId[$matchId] ?? [] as $set) {
        if ((int) ($set['set_number'] ?? 0) !== $setNumber) {
            continue;
        }
        $a = (int) ($set['score_a'] ?? 0);
        $b = (int) ($set['score_b'] ?? 0);
        return $invert ? ($b . ':' . $a) : ($a . ':' . $b);
    }

    return '';
};
$scoreField = static function (string $value, string $className = '') use ($h): string {
    $class = trim('bb-write-score ' . $className);
    $display = trim($value) !== '' ? $h($value) : '&nbsp;:&nbsp;';
    return '<span class="' . $h($class) . '">' . $display . '</span>';
};
$sourceLabel = static function (string $source, array $sourceLabels = []): string {
    $source = trim($source);
    if ($source === '') {
        return '';
    }
    if (strcasecmp($source, 'bye') === 0) {
        return 'BYE';
    }
    if (isset($sourceLabels[$source])) {
        return 'Winner of ' . (string) $sourceLabels[$source];
    }
    if (preg_match('/^winner:r(\d+):m(\d+)$/', $source, $matches) === 1) {
        return 'Winner of Match ' . (string) ((int) ($matches[2] ?? 0));
    }

    return 'Pending qualifier';
};
$teamLabel = static function (array $match, string $side, array $sourceLabels = []) use ($sourceLabel): string {
    $teamKey = $side === 'a' ? 'team_a_name' : 'team_b_name';
    $sourceKey = $side === 'a' ? 'team_a_source' : 'team_b_source';
    $teamName = trim((string) ($match[$teamKey] ?? ''));
    if ($teamName !== '') {
        return $teamName;
    }
    $label = $sourceLabel((string) ($match[$sourceKey] ?? ''), $sourceLabels);
    return $label !== '' ? $label : 'TBD';
};
$usesSectionHeaders = in_array(basename($printViewFile), ['schedule_by_court.php', 'schedule_by_group.php', 'group_matrix.php'], true);
$renderPrintHeader = static function (string $specificTitle = '') use ($h, $appName, $tournament, $printOutputTitle, $eventDate, $startTime, $location, $printedAt, $qrUrl, $publicUrl): void {
    ?>
    <header class="bb-print-header">
        <div class="bb-print-brand-block">
            <div class="bb-print-brand"><?= $h($appName) ?></div>
            <h1><?= $h((string) ($tournament['name'] ?? 'Tournament')) ?></h1>
            <h2><?= $h($printOutputTitle) ?></h2>
            <?php if ($specificTitle !== ''): ?>
                <h3><?= $h($specificTitle) ?></h3>
            <?php endif; ?>
        </div>
        <dl class="bb-print-meta">
            <div><dt>Event date</dt><dd><?= $h($eventDate !== '' ? $eventDate : '-') ?></dd></div>
            <div><dt>Start time</dt><dd><?= $h($startTime !== '' ? $startTime : '-') ?></dd></div>
            <div><dt>Location</dt><dd><?= $h($location !== '' ? $location : '-') ?></dd></div>
            <div><dt>Printed</dt><dd><?= $h($printedAt) ?></dd></div>
        </dl>
        <div class="bb-print-qr">
            <img src="<?= $h($qrUrl) ?>" alt="QR code for public tournament page">
            <span><?= $h($publicUrl) ?></span>
        </div>
    </header>
    <?php
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $h($pageTitle) ?></title>
    <link href="<?= $h($url('/assets/css/print.css')) ?>" rel="stylesheet">
    <style>
        @page { size: A4 <?= $orientation ?>; margin: 10mm; }
    </style>
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('bracketbird.adminTheme') || 'dark';
                document.documentElement.classList.add(theme === 'light' ? 'bb-print-theme-light' : 'bb-print-theme-dark');
            } catch (error) {
                document.documentElement.classList.add('bb-print-theme-dark');
            }
        })();
    </script>
</head>
<body class="bb-print-body bb-print-<?= $h($orientation) ?>">
<div class="bb-print-toolbar" role="region" aria-label="Print controls">
    <div class="bb-print-toolbar-title">
        <span>Print Preview</span>
        <strong><?= $h($printOutputTitle) ?></strong>
    </div>
    <div class="bb-print-toolbar-actions">
        <button type="button" class="bb-print-close-button" id="js-print-close">Close</button>
        <button type="button" class="bb-print-primary-button" onclick="window.print()">Print</button>
        <label class="bb-print-prefill-toggle">
            <input type="checkbox" <?= $prefill ? 'checked' : '' ?> onchange="window.location.href='<?= $h($prefillToggleUrl) ?>'">
            Prefill known results
        </label>
    </div>
    <span class="bb-print-close-helper" id="js-print-close-helper" hidden>You can close this tab after printing.</span>
</div>

<main class="bb-print-preview">
    <?php if (!$usesSectionHeaders): ?>
        <section class="print-page bb-print-page">
            <?php $renderPrintHeader(); ?>
            <?php require $printViewFile; ?>
        </section>
    <?php else: ?>
        <?php require $printViewFile; ?>
    <?php endif; ?>
</main>
<script>
    (function () {
        var button = document.getElementById('js-print-close');
        var helper = document.getElementById('js-print-close-helper');
        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            window.close();
            window.setTimeout(function () {
                if (!window.closed && helper) {
                    helper.hidden = false;
                }
            }, 250);
        });
    })();
</script>
</body>
</html>
