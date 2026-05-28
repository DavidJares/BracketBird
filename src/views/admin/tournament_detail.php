<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
/** @var string|null $backUrl */
/** @var string $backLabel */
/** @var string $activeSection */
/** @var array<string, string> $sectionNav */

$sectionLabels = [
    'tournament' => 'Tournament',
    'groups' => 'Teams & Groups',
    'matches' => 'Group Stage',
    'knockout' => 'Knockout',
    'public_view' => 'Public View',
    'teams' => 'Teams',
    'exports' => 'Exports & Print',
];
?>
<div class="bb-page-header">
    <div>
        <div class="bb-page-kicker">Admin console</div>
        <h1>Tournament detail</h1>
    </div>
    <?php if ($backUrl !== null): ?>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm"><?= htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endif; ?>
</div>

<ul class="nav nav-tabs bb-section-tabs bb-mobile-section-tabs mb-3">
    <?php foreach ($sectionLabels as $sectionKey => $sectionLabel): ?>
        <?php $href = (string) ($sectionNav[$sectionKey] ?? '#'); ?>
        <li class="nav-item">
            <a class="nav-link <?= $activeSection === $sectionKey ? 'active' : '' ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php
$sectionViewFile = __DIR__ . '/tournament_detail/' . $activeSection . '.php';
if (!is_file($sectionViewFile)) {
    $sectionViewFile = __DIR__ . '/tournament_detail/tournament.php';
}

require $sectionViewFile;
