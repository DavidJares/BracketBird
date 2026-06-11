<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
/** @var string $screenTitle */
/** @var string $screenKey */
/** @var string $nowLabel */
/** @var string $nowIso */
/** @var string $qrUrl */
/** @var string $currentUrl */
/** @var bool $autoplay */
/** @var int $autoplay_seconds */
/** @var string $autoplay_next_url */
/** @var list<array{key: string, label: string, path: string, is_enabled: int, sort_order: int, url: string}> $enabledScreens */

$startTime = (string) ($tournament['start_time'] ?? '');
if ($startTime !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $startTime) === 1) {
    $startTime = substr($startTime, 0, 5);
}

$courtBadgeClasses = [
    'text-bg-primary',
    'text-bg-success',
    'text-bg-info',
    'text-bg-warning',
    'text-bg-danger',
    'text-bg-secondary',
    'text-bg-dark',
];
$statusBadgeClass = static function (string $status): string {
    if ($status === 'scheduled') {
        return 'text-bg-primary';
    }
    if ($status === 'in_progress') {
        return 'text-bg-warning';
    }
    if ($status === 'finished') {
        return 'text-bg-success';
    }
    return 'text-bg-secondary';
};
$publicScreenLabels = [
    'overview' => $t('public.screen.overview'),
    'next_matches' => $t('public.screen.next_matches'),
    'standings' => $t('public.screen.standings'),
    'group_schedule' => $t('public.screen.group_schedule'),
    'knockout' => $t('public.screen.knockout'),
    'recent_results' => $t('public.screen.recent_results'),
];

$stageLabel = static function (array $match) use ($t): string {
    $stage = (string) ($match['stage'] ?? '');
    if ($stage === 'group') {
        $groupName = trim((string) ($match['group_name'] ?? ''));
        return $groupName !== '' ? $t('teams_groups.group_name', ['name' => $groupName]) : $t('group_stage.title');
    }
    $roundName = trim((string) ($match['round_name'] ?? ''));
    if ($roundName === '') {
        return $t('knockout.title');
    }
    $position = (int) ($match['bracket_position'] ?? 0);
    if (strcasecmp($roundName, 'Final') === 0) {
        return $t('knockout.final');
    }
    return trim($roundName . ($position > 0 ? ' ' . $position : ''));
};
$setSummaryText = static function (array $match): string {
    return trim((string) ($match['set_scores_summary'] ?? ''));
};
$winnerClassForTeam = static function (array $match, string $side): string {
    $status = (string) ($match['status'] ?? '');
    if ($status !== 'finished') {
        return '';
    }
    $winnerTeamId = (int) ($match['winner_team_id'] ?? 0);
    if ($winnerTeamId <= 0) {
        return '';
    }
    $teamId = $side === 'a' ? (int) ($match['team_a_id'] ?? 0) : (int) ($match['team_b_id'] ?? 0);
    return $winnerTeamId === $teamId ? 'public-winner-name' : '';
};
$isWinnerForTeam = static function (array $match, string $side): bool {
    $status = (string) ($match['status'] ?? '');
    if ($status !== 'finished') {
        return false;
    }
    $winnerTeamId = (int) ($match['winner_team_id'] ?? 0);
    if ($winnerTeamId <= 0) {
        return false;
    }
    $teamId = $side === 'a' ? (int) ($match['team_a_id'] ?? 0) : (int) ($match['team_b_id'] ?? 0);
    return $winnerTeamId === $teamId;
};
$formatMatchTime = static function (?string $raw) use ($t): string {
    $value = trim((string) $raw);
    if ($value === '') {
        return $t('common.tbd');
    }
    $dateTime = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if ($dateTime instanceof \DateTimeImmutable) {
        return $dateTime->format('H:i');
    }
    if (preg_match('/\b([01]\d|2[0-3]):[0-5]\d\b/', $value, $matches) === 1) {
        return (string) ($matches[0] ?? $t('common.tbd'));
    }
    return $t('common.tbd');
};
$scoreText = static function (array $match): string {
    $status = (string) ($match['status'] ?? '');
    if ($status !== 'finished') {
        return '-';
    }
    return (int) ($match['sets_summary_a'] ?? 0) . ':' . (int) ($match['sets_summary_b'] ?? 0);
};
$scoreParts = static function (array $match): array {
    $status = (string) ($match['status'] ?? '');
    if ($status !== 'finished') {
        return ['', ''];
    }
    return [(string) ((int) ($match['sets_summary_a'] ?? 0)), (string) ((int) ($match['sets_summary_b'] ?? 0))];
};
$teamName = static fn (array $match, string $side): string => (string) ($match[$side === 'a' ? 'team_a_name' : 'team_b_name'] ?? '-');
$courtBadge = static function (array $match) use ($courtBadgeClasses): array {
    $court = (int) ($match['court_number'] ?? 0);
    $class = $court > 0 ? ($courtBadgeClasses[($court - 1) % count($courtBadgeClasses)] ?? 'text-bg-secondary') : 'text-bg-secondary';
    return [$court, $class];
};
$advancingTeamsCount = max(0, (int) ($tournament['advancing_teams_count'] ?? 0));
?>
<section class="bb-public-shell">
    <header class="bb-public-header">
        <div>
            <div class="bb-public-kicker"><?= htmlspecialchars($screenTitle, ENT_QUOTES, 'UTF-8') ?></div>
            <h1 class="public-title"><?= htmlspecialchars((string) ($tournament['name'] ?? $t('nav.tournament')), ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="bb-public-meta-line">
                <span><?= htmlspecialchars((string) ($tournament['event_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                <span><?= htmlspecialchars($startTime !== '' ? $startTime : $t('public.start_tbd'), ENT_QUOTES, 'UTF-8') ?></span>
                <?php if (trim((string) ($tournament['location'] ?? '')) !== ''): ?>
                    <span><?= htmlspecialchars((string) $tournament['location'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <span><?= $e('public.now') ?> <span data-bb-local-now="<?= htmlspecialchars($nowIso ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($nowLabel, ENT_QUOTES, 'UTF-8') ?></span></span>
            </div>
        </div>
        <?php if ($screenKey !== 'overview'): ?>
            <?php require __DIR__ . '/_qr.php'; ?>
        <?php endif; ?>
    </header>

    <?php if (count($enabledScreens) > 1): ?>
        <nav class="bb-public-nav" aria-label="<?= $e('public.screens') ?>">
            <?php foreach ($enabledScreens as $screen): ?>
                <a href="<?= htmlspecialchars((string) $screen['url'], ENT_QUOTES, 'UTF-8') ?>" class="bb-public-nav-link <?= $screen['key'] === $screenKey ? 'active' : '' ?>">
                    <?= htmlspecialchars((string) ($publicScreenLabels[(string) $screen['key']] ?? $screen['label']), ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($screenKey === 'overview'): ?>
        <?php
        $overviewTitle = trim((string) ($tournament['public_title_override'] ?? ''));
        if ($overviewTitle === '') {
            $overviewTitle = (string) ($tournament['name'] ?? 'Tournament');
        }
        $overviewDescription = trim((string) ($tournament['public_description'] ?? ''));
        $overviewLogoPath = trim((string) ($tournament['public_logo_path'] ?? ''));
        $overviewMapButtonUrl = trim((string) ($overviewMapButtonUrl ?? ''));
        $overviewMapEmbedUrl = trim((string) ($overviewMapEmbedUrl ?? ''));
        $logoUrl = $overviewLogoPath !== '' ? $url('/' . ltrim($overviewLogoPath, '/')) : '';
        ?>
        <div class="bb-public-hero">
            <div class="bb-public-hero-copy">
                <div class="bb-public-kicker"><?= $e('public.tournament_public_view') ?></div>
                <h2><?= htmlspecialchars($overviewTitle, ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="bb-public-stat-grid">
                <div class="bb-public-stat"><span><?= $e('print.event_date') ?></span><strong><?= htmlspecialchars((string) ($tournament['event_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div class="bb-public-stat"><span><?= $e('print.start') ?></span><strong><?= htmlspecialchars($startTime !== '' ? $startTime : '-', ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div class="bb-public-stat"><span><?= $e('print.location') ?></span><strong><?= htmlspecialchars((string) ($tournament['location'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div class="bb-public-stat"><span><?= $e('public.current_time') ?></span><strong data-bb-local-now="<?= htmlspecialchars($nowIso ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($nowLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
                </div>
                <?php if ($overviewDescription !== ''): ?>
                    <div class="bb-public-description">
                    <span><?= $e('public.about_this_tournament') ?></span>
                        <p><?= nl2br(htmlspecialchars($overviewDescription, ENT_QUOTES, 'UTF-8')) ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($overviewMapButtonUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($overviewMapButtonUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-lg btn-outline-primary"><?= $e('public.open_map') ?></a>
                <?php endif; ?>
            </div>
            <aside class="bb-public-hero-side">
                <?php if ($logoUrl !== ''): ?>
                    <div class="bb-public-logo-panel">
                        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Tournament logo">
                    </div>
                <?php endif; ?>
                <?php require __DIR__ . '/_qr.php'; ?>
            </aside>
        </div>
        <?php if ($overviewMapEmbedUrl !== ''): ?>
            <div class="bb-public-map">
                <iframe src="<?= htmlspecialchars($overviewMapEmbedUrl, ENT_QUOTES, 'UTF-8') ?>" width="100%" height="350" style="border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen title="Tournament map"></iframe>
            </div>
        <?php endif; ?>
    <?php elseif ($screenKey === 'next_matches'): ?>
        <div class="bb-public-section-heading">
            <div><span><?= $e('public.live_now') ?></span><strong><?= $e('public.current_matches') ?></strong></div>
        </div>
        <?php if (!is_array($in_progress_matches ?? null) || count($in_progress_matches) === 0): ?>
                <div class="bb-public-empty"><?= $e('public.no_matches_in_progress') ?></div>
        <?php else: ?>
            <div class="bb-public-live-grid">
                <?php foreach ($in_progress_matches as $match): ?>
                    <?php [$court, $badgeClass] = $courtBadge($match); $status = (string) ($match['status'] ?? 'pending'); ?>
                    <article class="bb-public-match-card bb-public-live-card">
                        <div class="bb-public-match-top">
                            <span><?= htmlspecialchars($stageLabel($match), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="badge <?= htmlspecialchars($statusBadgeClass($status), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="bb-public-versus">
                            <div class="bb-public-team <?= htmlspecialchars($winnerClassForTeam($match, 'a'), ENT_QUOTES, 'UTF-8') ?>">
                                <span><?= htmlspecialchars($teamName($match, 'a'), ENT_QUOTES, 'UTF-8') ?></span>
<?php if ($isWinnerForTeam($match, 'a')): ?><span class="bb-public-winner"><?= $e('common.winner_short') ?></span><?php endif; ?>
                            </div>
                            <div class="bb-public-score"><?= htmlspecialchars($scoreText($match), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="bb-public-team <?= htmlspecialchars($winnerClassForTeam($match, 'b'), ENT_QUOTES, 'UTF-8') ?>">
                                <span><?= htmlspecialchars($teamName($match, 'b'), ENT_QUOTES, 'UTF-8') ?></span>
<?php if ($isWinnerForTeam($match, 'b')): ?><span class="bb-public-winner"><?= $e('common.winner_short') ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="bb-public-match-meta">
<?php if ($court > 0): ?><span class="badge <?= htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= $e('common.court_number', ['number' => $court]) ?></span><?php else: ?><span><?= $e('knockout.court_tbd') ?></span><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="bb-public-section-heading mt-4">
            <div><span><?= $e('public.coming_up') ?></span><strong><?= $e('public.next_scheduled') ?></strong></div>
        </div>
        <?php if (!is_array($next_matches ?? null) || count($next_matches) === 0): ?>
                <div class="bb-public-empty"><?= $e('public.no_scheduled_matches') ?></div>
        <?php else: ?>
            <div class="bb-public-match-grid">
                <?php foreach ($next_matches as $match): ?>
                    <?php [$court, $badgeClass] = $courtBadge($match); $status = (string) ($match['status'] ?? 'pending'); ?>
                    <article class="bb-public-match-card">
                        <div class="bb-public-match-top">
                            <span><?= htmlspecialchars($formatMatchTime((string) ($match['planned_start'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="badge <?= htmlspecialchars($statusBadgeClass($status), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="bb-public-match-teams">
                            <strong><?= htmlspecialchars($teamName($match, 'a'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= $e('common.vs') ?></span>
                            <strong><?= htmlspecialchars($teamName($match, 'b'), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="bb-public-match-meta">
                            <span><?= htmlspecialchars($stageLabel($match), ENT_QUOTES, 'UTF-8') ?></span>
<?php if ($court > 0): ?><span class="badge <?= htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= $e('common.court_number', ['number' => $court]) ?></span><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php elseif ($screenKey === 'standings'): ?>
        <?php
        $groupNameById = [];
        if (is_array($groups ?? null)) {
            foreach ($groups as $groupRow) {
                $gid = (int) ($groupRow['id'] ?? 0);
                if ($gid > 0) {
                    $groupNameById[$gid] = (string) ($groupRow['name'] ?? ('Group ' . $gid));
                }
            }
        }
        ?>
        <?php if (!is_array($groupStandingsByGroup ?? null) || count($groupStandingsByGroup) === 0): ?>
                <div class="bb-public-empty"><?= $e('public.no_standings_available') ?></div>
        <?php else: ?>
            <div class="bb-public-standings-grid">
                <?php foreach ($groupStandingsByGroup as $groupId => $rows): ?>
                    <section class="bb-public-standings-card">
                        <div class="bb-public-card-title"><?= $e('teams_groups.group_name', ['name' => (string) ($groupNameById[(int) $groupId] ?? $groupId)]) ?></div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 public-table public-standings">
                    <thead><tr><th class="col-rank">#</th><th class="col-team"><?= $e('print.team') ?></th><th class="col-num">P</th><th class="col-num">W</th><th class="col-num">D</th><th class="col-num">L</th><th class="col-num"><?= $e('print.points_short') ?></th><th class="col-num"><?= $e('print.diff') ?></th></tr></thead>
                                <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php $position = (int) ($row['position'] ?? 0); ?>
                                    <tr class="<?= $advancingTeamsCount > 0 && $position > 0 && $position <= $advancingTeamsCount ? 'bb-public-advancing-row' : '' ?>">
                                        <td class="col-rank"><span><?= $position ?></span></td>
                                        <td class="col-team"><?= htmlspecialchars((string) ($row['team_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="col-num"><?= (int) ($row['played'] ?? 0) ?></td>
                                        <td class="col-num"><?= (int) ($row['wins'] ?? 0) ?></td>
                                        <td class="col-num"><?= (int) ($row['draws'] ?? 0) ?></td>
                                        <td class="col-num"><?= (int) ($row['losses'] ?? 0) ?></td>
                                        <td class="col-num bb-public-points"><?= (int) ($row['tournament_points'] ?? 0) ?></td>
                                        <td class="col-num"><?= (int) ($row['point_diff'] ?? 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php elseif ($screenKey === 'group_schedule'): ?>
        <?php if (!is_array($groupMatches ?? null) || count($groupMatches) === 0): ?>
                <div class="bb-public-empty"><?= $e('public.no_group_matches') ?></div>
        <?php else: ?>
            <div class="bb-public-feed">
                <?php foreach ($groupMatches as $match): ?>
                    <?php [$court, $courtBadgeClass] = $courtBadge($match); $status = (string) ($match['status'] ?? 'pending'); $setScoresSummary = $setSummaryText($match); [$scoreA, $scoreB] = $scoreParts($match); ?>
                    <article class="bb-public-feed-item bb-public-schedule-item">
                        <div class="bb-public-feed-time"><?= htmlspecialchars($formatMatchTime((string) ($match['planned_start'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="bb-public-schedule-team bb-public-schedule-team-a <?= htmlspecialchars($winnerClassForTeam($match, 'a'), ENT_QUOTES, 'UTF-8') ?>">
                            <strong><?= htmlspecialchars($teamName($match, 'a'), ENT_QUOTES, 'UTF-8') ?></strong>
<?php if ($isWinnerForTeam($match, 'a')): ?><span class="bb-public-winner"><?= $e('common.winner_short') ?></span><?php endif; ?>
                            <?php if ($scoreA !== ''): ?><span class="bb-public-mobile-team-score"><?= htmlspecialchars($scoreA, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="bb-public-schedule-score <?= $setScoresSummary === '' ? 'bb-public-mobile-empty-score' : '' ?>">
                            <strong><?= htmlspecialchars($scoreText($match), ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if ($setScoresSummary !== ''): ?><div class="public-result-sub">(<?= htmlspecialchars($setScoresSummary, ENT_QUOTES, 'UTF-8') ?>)</div><?php endif; ?>
                        </div>
                        <div class="bb-public-schedule-team bb-public-schedule-team-b <?= htmlspecialchars($winnerClassForTeam($match, 'b'), ENT_QUOTES, 'UTF-8') ?>">
                            <strong><?= htmlspecialchars($teamName($match, 'b'), ENT_QUOTES, 'UTF-8') ?></strong>
<?php if ($isWinnerForTeam($match, 'b')): ?><span class="bb-public-winner"><?= $e('common.winner_short') ?></span><?php endif; ?>
                            <?php if ($scoreB !== ''): ?><span class="bb-public-mobile-team-score"><?= htmlspecialchars($scoreB, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="bb-public-schedule-meta">
                            <span class="badge <?= htmlspecialchars($statusBadgeClass($status), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
<?php if ($court > 0): ?><span class="badge <?= htmlspecialchars($courtBadgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= $e('common.court_number', ['number' => $court]) ?></span><?php endif; ?>
<span class="bb-public-schedule-group"><?= $e('teams_groups.group_name', ['name' => (string) ($match['group_name'] ?? '-')]) ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php elseif ($screenKey === 'knockout'): ?>
        <?php
        $sourceLabelByCode = [];
        $matchLabelsByIndex = [];
        $roundIndex = 0;
        $currentRoundName = '';
        $matchNumberInRound = 0;
        $rounds = [];
        if (is_array($knockoutMatches ?? null)) {
            foreach ($knockoutMatches as $index => $match) {
                $roundName = trim((string) ($match['round_name'] ?? ''));
                if ($roundName !== $currentRoundName) {
                    $currentRoundName = $roundName;
                    $roundIndex++;
                    $matchNumberInRound = 0;
                }
                $matchNumberInRound++;
                $position = (int) ($match['bracket_position'] ?? 0);
                $label = strcasecmp($roundName, 'Final') === 0 ? 'Final' : trim($roundName . ' ' . $position);
                $matchLabelsByIndex[$index] = $label;
                $sourceLabelByCode['winner:r' . $roundIndex . ':m' . $matchNumberInRound] = $label;
                if (!isset($rounds[$roundName])) {
                    $rounds[$roundName] = [];
                }
                $rounds[$roundName][] = ['index' => $index, 'match' => $match];
            }
        }
        foreach ($rounds as &$roundMatches) {
            usort($roundMatches, static function (array $a, array $b): int {
                $positionA = (int) (($a['match']['bracket_position'] ?? 0));
                $positionB = (int) (($b['match']['bracket_position'] ?? 0));
                if ($positionA !== $positionB) {
                    return $positionA <=> $positionB;
                }
                return (int) (($a['match']['id'] ?? 0)) <=> (int) (($b['match']['id'] ?? 0));
            });
        }
        unset($roundMatches);
        ?>
        <?php if (!is_array($knockoutMatches ?? null) || count($knockoutMatches) === 0): ?>
                <div class="bb-public-empty"><?= $e('public.no_knockout_matches') ?></div>
        <?php else: ?>
            <div class="bb-public-bracket-hint"><?= $e('knockout.swipe_hint') ?></div>
            <div class="bb-public-bracket-wrap">
                <div class="bb-public-bracket-grid">
                    <?php foreach ($rounds as $roundName => $roundMatches): ?>
                        <section class="bb-public-bracket-round">
                            <div class="bb-public-bracket-round-title"><?= htmlspecialchars($roundName !== '' ? $roundName : 'Round', ENT_QUOTES, 'UTF-8') ?></div>
                            <?php foreach ($roundMatches as $roundRow): ?>
                                <?php
                                $index = (int) ($roundRow['index'] ?? 0);
                                $match = is_array($roundRow['match'] ?? null) ? $roundRow['match'] : [];
                                $sourceA = trim((string) ($match['team_a_source'] ?? ''));
                                $sourceB = trim((string) ($match['team_b_source'] ?? ''));
                                $sourceALabel = ($sourceA !== '' && isset($sourceLabelByCode[$sourceA])) ? ('Winner of ' . $sourceLabelByCode[$sourceA]) : $sourceA;
                                $sourceBLabel = ($sourceB !== '' && isset($sourceLabelByCode[$sourceB])) ? ('Winner of ' . $sourceLabelByCode[$sourceB]) : $sourceB;
                                $status = (string) ($match['status'] ?? 'pending');
                                [$court, $courtBadgeClass] = $courtBadge($match);
                                $setScoresSummary = $setSummaryText($match);
                                ?>
                                <article class="bb-public-bracket-card <?= $status !== 'finished' ? 'is-pending' : '' ?>">
                                    <div class="bb-public-match-top">
                                        <span><?= htmlspecialchars((string) ($matchLabelsByIndex[$index] ?? 'Match'), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="badge <?= htmlspecialchars($statusBadgeClass($status), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="bb-public-bracket-team <?= htmlspecialchars($winnerClassForTeam($match, 'a'), ENT_QUOTES, 'UTF-8') ?>">
                                        <span><?= htmlspecialchars($teamName($match, 'a'), ENT_QUOTES, 'UTF-8') ?></span>
<?php if ($isWinnerForTeam($match, 'a')): ?><span class="bb-public-winner"><?= $e('common.winner_short') ?></span><?php endif; ?>
                                    </div>
                                    <?php if ($sourceALabel !== ''): ?><div class="bb-public-source"><?= htmlspecialchars($sourceALabel, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                    <div class="bb-public-bracket-team <?= htmlspecialchars($winnerClassForTeam($match, 'b'), ENT_QUOTES, 'UTF-8') ?>">
                                        <span><?= htmlspecialchars($teamName($match, 'b'), ENT_QUOTES, 'UTF-8') ?></span>
<?php if ($isWinnerForTeam($match, 'b')): ?><span class="bb-public-winner"><?= $e('common.winner_short') ?></span><?php endif; ?>
                                    </div>
                                    <?php if ($sourceBLabel !== ''): ?><div class="bb-public-source"><?= htmlspecialchars($sourceBLabel, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                    <div class="bb-public-bracket-result">
                                        <?= htmlspecialchars($scoreText($match), ENT_QUOTES, 'UTF-8') ?>
                                        <?php if ($setScoresSummary !== ''): ?><span>(<?= htmlspecialchars($setScoresSummary, ENT_QUOTES, 'UTF-8') ?>)</span><?php endif; ?>
                                    </div>
                                    <div class="bb-public-match-meta">
<?php if ($court > 0): ?><span class="badge <?= htmlspecialchars($courtBadgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= $e('common.court_number', ['number' => $court]) ?></span><?php else: ?><span><?= $e('knockout.court_tbd') ?></span><?php endif; ?>
                                        <span><?= htmlspecialchars($formatMatchTime((string) ($match['planned_start'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php if (!is_array($recentResults ?? null) || count($recentResults) === 0): ?>
                <div class="bb-public-empty"><?= $e('public.no_finished_matches') ?></div>
        <?php else: ?>
            <div class="bb-public-result-feed">
                <?php foreach ($recentResults as $match): ?>
                    <?php [$court, $courtBadgeClass] = $courtBadge($match); $status = (string) ($match['status'] ?? 'finished'); $setScoresSummary = $setSummaryText($match); [$scoreA, $scoreB] = $scoreParts($match); ?>
                    <article class="bb-public-result-item">
                        <div class="bb-public-result-stage"><?= htmlspecialchars($stageLabel($match), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="bb-public-schedule-team bb-public-result-team-a <?= htmlspecialchars($winnerClassForTeam($match, 'a'), ENT_QUOTES, 'UTF-8') ?>">
                            <strong><?= htmlspecialchars($teamName($match, 'a'), ENT_QUOTES, 'UTF-8') ?></strong>
<?php if ($isWinnerForTeam($match, 'a')): ?><span class="bb-public-winner"><?= $e('common.winner_short') ?></span><?php endif; ?>
                            <?php if ($scoreA !== ''): ?><span class="bb-public-mobile-team-score"><?= htmlspecialchars($scoreA, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="bb-public-schedule-score bb-public-result-score <?= $setScoresSummary === '' ? 'bb-public-mobile-empty-score' : '' ?>">
                            <strong><?= htmlspecialchars($scoreText($match), ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if ($setScoresSummary !== ''): ?><div class="public-result-sub">(<?= htmlspecialchars($setScoresSummary, ENT_QUOTES, 'UTF-8') ?>)</div><?php endif; ?>
                        </div>
                        <div class="bb-public-schedule-team bb-public-schedule-team-b bb-public-result-team-b <?= htmlspecialchars($winnerClassForTeam($match, 'b'), ENT_QUOTES, 'UTF-8') ?>">
                            <strong><?= htmlspecialchars($teamName($match, 'b'), ENT_QUOTES, 'UTF-8') ?></strong>
<?php if ($isWinnerForTeam($match, 'b')): ?><span class="bb-public-winner"><?= $e('common.winner_short') ?></span><?php endif; ?>
                            <?php if ($scoreB !== ''): ?><span class="bb-public-mobile-team-score"><?= htmlspecialchars($scoreB, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="bb-public-schedule-meta bb-public-result-badges">
                            <span class="badge <?= htmlspecialchars($statusBadgeClass($status), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
<?php if ($court > 0): ?><span class="badge <?= htmlspecialchars($courtBadgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= $e('common.court_number', ['number' => $court]) ?></span><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<script>
    (function () {
        var nodes = document.querySelectorAll('[data-bb-local-now]');
        if (!nodes.length || typeof Intl === 'undefined') {
            return;
        }
        nodes.forEach(function (node) {
            var value = node.getAttribute('data-bb-local-now');
            if (!value) {
                return;
            }
            var date = new Date(value);
            if (Number.isNaN(date.getTime())) {
                return;
            }
            node.textContent = new Intl.DateTimeFormat(undefined, {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            }).format(date);
        });
    }());
</script>

<?php if ($autoplay && $autoplay_seconds > 0 && $autoplay_next_url !== ''): ?>
    <script>
        setTimeout(function () {
            window.location.href = <?= json_encode($autoplay_next_url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        }, <?= (int) $autoplay_seconds * 1000 ?>);
    </script>
<?php endif; ?>
