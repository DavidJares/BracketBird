<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
/** @var string|null $backUrl */
/** @var string $activeSection */
/** @var array<string, string> $sectionNav */
/** @var array<string, mixed> $groupAssignment */

$sectionLabels = [
    'tournament' => 'nav.tournament',
    'groups' => 'nav.teams_groups',
    'matches' => 'nav.group_stage',
    'knockout' => 'nav.knockout',
    'public_view' => 'nav.public_view',
    'exports' => 'nav.exports_print',
];
$navigationActiveSection = $activeSection === 'teams' ? 'groups' : $activeSection;
$totalTeams = (int) ($groupAssignment['total_teams'] ?? count($teams ?? []));
$unassignedTeams = (int) ($groupAssignment['unassigned_count'] ?? 0);
$groupMatchTotal = max(0, (int) ($groupMatchesTotalCount ?? 0));
$groupMatchFinished = max(0, (int) ($groupMatchesFinishedCount ?? 0));
$groupMatchInProgress = max(0, (int) ($groupMatchesInProgressCount ?? 0));
$knockoutMatchTotal = count($knockoutMatches ?? []);
$knockoutMatchFinished = max(0, (int) ($knockoutMatchesFinishedCount ?? 0));
$knockoutMatchInProgress = max(0, (int) ($knockoutMatchesInProgressCount ?? 0));
$publicViewEnabled = (int) ($tournament['public_view_enabled'] ?? 0) > 0;
$groupsReady = $totalTeams > 0 && $unassignedTeams === 0;
$groupStageComplete = $groupMatchTotal > 0 && $groupMatchFinished === $groupMatchTotal;
$knockoutComplete = $knockoutMatchTotal > 0 && $knockoutMatchFinished === $knockoutMatchTotal;

$nextActionKey = 'tournament_hub.open_public';
$nextActionUrl = (string) ($publicDisplayUrl ?? '#');
$nextActionExternal = true;
if ($totalTeams === 0) {
    $nextActionKey = 'tournament_hub.add_teams';
    $nextActionUrl = (string) ($sectionNav['groups'] ?? '#');
    $nextActionExternal = false;
} elseif ($unassignedTeams > 0) {
    $nextActionKey = 'tournament_hub.assign_groups';
    $nextActionUrl = (string) ($sectionNav['groups'] ?? '#');
    $nextActionExternal = false;
} elseif ($groupMatchTotal === 0) {
    $nextActionKey = 'tournament_hub.generate_group_stage';
    $nextActionUrl = (string) ($sectionNav['matches'] ?? '#');
    $nextActionExternal = false;
} elseif (!$groupStageComplete) {
    $nextActionKey = 'tournament_hub.run_group_stage';
    $nextActionUrl = (string) ($sectionNav['matches'] ?? '#');
    $nextActionExternal = false;
} elseif ($knockoutMatchTotal === 0) {
    $nextActionKey = 'tournament_hub.generate_knockout';
    $nextActionUrl = (string) ($sectionNav['knockout'] ?? '#');
    $nextActionExternal = false;
} elseif (!$knockoutComplete) {
    $nextActionKey = 'tournament_hub.run_knockout';
    $nextActionUrl = (string) ($sectionNav['knockout'] ?? '#');
    $nextActionExternal = false;
} elseif (!$publicViewEnabled) {
    $nextActionKey = 'tournament_hub.enable_public';
    $nextActionUrl = (string) ($sectionNav['public_view'] ?? '#');
    $nextActionExternal = false;
}

$sectionStates = [
    'tournament' => ['label' => 'tournament_hub.ready', 'class' => 'is-ready'],
    'groups' => [
        'label' => $groupsReady ? 'tournament_hub.complete' : ($totalTeams > 0 ? 'tournament_hub.in_progress' : 'tournament_hub.not_started'),
        'class' => $groupsReady ? 'is-complete' : ($totalTeams > 0 ? 'is-active' : 'is-pending'),
    ],
    'matches' => [
        'label' => $groupStageComplete ? 'tournament_hub.complete' : ($groupMatchTotal > 0 ? 'tournament_hub.in_progress' : 'tournament_hub.not_started'),
        'class' => $groupStageComplete ? 'is-complete' : ($groupMatchTotal > 0 ? 'is-active' : 'is-pending'),
    ],
    'knockout' => [
        'label' => $knockoutComplete ? 'tournament_hub.complete' : ($knockoutMatchTotal > 0 ? 'tournament_hub.in_progress' : ($groupStageComplete ? 'tournament_hub.ready' : 'tournament_hub.not_started')),
        'class' => $knockoutComplete ? 'is-complete' : ($knockoutMatchTotal > 0 ? 'is-active' : ($groupStageComplete ? 'is-ready' : 'is-pending')),
    ],
    'public_view' => [
        'label' => $publicViewEnabled ? 'tournament_hub.public_live' : 'tournament_hub.public_private',
        'class' => $publicViewEnabled ? 'is-complete' : 'is-pending',
    ],
    'exports' => ['label' => 'tournament_hub.ready', 'class' => 'is-ready'],
];

$eventDate = trim((string) ($tournament['event_date'] ?? ''));
$startTime = trim((string) ($tournament['start_time'] ?? ''));
$location = trim((string) ($tournament['location'] ?? ''));
?>
<section class="bb-tournament-hub">
    <header class="bb-tournament-masthead">
        <div class="bb-tournament-masthead-main">
            <div class="bb-tournament-context-line">
                <?php if ($backUrl !== null): ?>
                    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="bb-back-link"><?= $e('tournament_hub.back_to_tournaments') ?></a>
                    <span aria-hidden="true">/</span>
                <?php endif; ?>
                <span><?= $backUrl !== null ? $e('tournament_hub.scope_superadmin') : $e('tournament_hub.scope_tournament_admin') ?></span>
            </div>
            <h1><?= htmlspecialchars((string) ($tournament['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="bb-tournament-meta-line">
                <?php if ($eventDate !== ''): ?><span><?= htmlspecialchars($eventDate, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                <?php if ($startTime !== ''): ?><span><?= htmlspecialchars(substr($startTime, 0, 5), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                <?php if ($location !== ''): ?><span><?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                <span class="bb-status-dot <?= $publicViewEnabled ? 'is-on' : '' ?>"><?= $publicViewEnabled ? $e('tournament_hub.public_live') : $e('tournament_hub.public_private') ?></span>
            </div>
        </div>
        <div class="bb-next-action">
            <span><?= $e('tournament_hub.next_action') ?></span>
            <a
                href="<?= htmlspecialchars($nextActionUrl, ENT_QUOTES, 'UTF-8') ?>"
                class="btn btn-primary"
                <?= $nextActionExternal ? 'target="_blank" rel="noopener"' : '' ?>
            ><?= $e($nextActionKey) ?></a>
        </div>
    </header>

    <nav class="bb-lifecycle-nav" aria-label="<?= $e('layout.tournament_administration') ?>">
        <?php $stepNumber = 0; ?>
        <?php foreach ($sectionLabels as $sectionKey => $sectionLabel): ?>
            <?php
            $stepNumber++;
            $href = (string) ($sectionNav[$sectionKey] ?? '#');
            $state = $sectionStates[$sectionKey] ?? ['label' => 'tournament_hub.not_started', 'class' => 'is-pending'];
            ?>
            <a
                class="bb-lifecycle-step <?= htmlspecialchars((string) $state['class'], ENT_QUOTES, 'UTF-8') ?><?= $navigationActiveSection === $sectionKey ? ' active' : '' ?>"
                href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
                <?= $navigationActiveSection === $sectionKey ? 'aria-current="page"' : '' ?>
            >
                <span class="bb-lifecycle-number"><?= $stepNumber ?></span>
                <span class="bb-lifecycle-copy">
                    <strong><?= $e($sectionLabel) ?></strong>
                    <small>
                        <?php if ($sectionKey === 'groups'): ?>
                            <?= $e('tournament_hub.teams_count', ['count' => $totalTeams]) ?><?= $unassignedTeams > 0 ? ' · ' . $e('tournament_hub.unassigned_count', ['count' => $unassignedTeams]) : '' ?>
                        <?php elseif ($sectionKey === 'matches' && $groupMatchTotal > 0): ?>
                            <?= $e('tournament_hub.group_progress', ['finished' => $groupMatchFinished, 'total' => $groupMatchTotal]) ?><?= $groupMatchInProgress > 0 ? ' · ' . $e('common.live') : '' ?>
                        <?php elseif ($sectionKey === 'knockout' && $knockoutMatchTotal > 0): ?>
                            <?= $e('tournament_hub.knockout_progress', ['finished' => $knockoutMatchFinished, 'total' => $knockoutMatchTotal]) ?><?= $knockoutMatchInProgress > 0 ? ' · ' . $e('common.live') : '' ?>
                        <?php else: ?>
                            <?= $e((string) $state['label']) ?>
                        <?php endif; ?>
                    </small>
                </span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="bb-tournament-section">
        <?php
        $sectionViewFile = __DIR__ . '/tournament_detail/' . $activeSection . '.php';
        if (!is_file($sectionViewFile)) {
            $sectionViewFile = __DIR__ . '/tournament_detail/tournament.php';
        }

        require $sectionViewFile;
        ?>
    </div>
</section>
