<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
/** @var array{
 *     unassigned_count: int
 * } $groupAssignment */
/** @var bool $hasGroupMatches */
/** @var bool $hasKnockoutMatches */
/** @var string $generateGroupMatchesActionUrl */
/** @var string $matchesFilterActionUrl */
/** @var list<array<string, mixed>> $groupMatches */
/** @var int $groupMatchesTotalCount */
/** @var array<int, string> $groupFilterOptions */
/** @var list<int> $courtFilterOptions */
/** @var int $selectedGroupFilter */
/** @var int $selectedCourtFilter */

$tournamentId = (int) ($tournament['id'] ?? 0);
$hasUnassignedTeams = (int) ($groupAssignment['unassigned_count'] ?? 0) > 0;
$hasExistingGroupMatches = isset($hasGroupMatches) && $hasGroupMatches;
$hasExistingKnockoutMatches = isset($hasKnockoutMatches) && $hasKnockoutMatches;
$hasExistingMatches = $hasExistingGroupMatches || $hasExistingKnockoutMatches;
$generateConfirmMessageParts = [];
if ($hasUnassignedTeams) {
    $generateConfirmMessageParts[] = $t('group_stage.unassigned_skipped');
}
if ($hasExistingGroupMatches) {
    $generateConfirmMessageParts[] = $t('group_stage.existing_matches_replaced');
}
if ($hasExistingKnockoutMatches) {
    $generateConfirmMessageParts[] = $t('group_stage.knockout_matches_removed');
}
$generateConfirmMessage = implode(' ', $generateConfirmMessageParts);
$generateFormOnSubmit = $generateConfirmMessage !== '' ? 'return confirm(' . htmlspecialchars(json_encode($generateConfirmMessage . ' ' . $t('common.continue_question')), ENT_QUOTES, 'UTF-8') . ');' : '';
$courtBadgeClasses = [
    'text-bg-primary',
    'text-bg-success',
    'text-bg-info',
    'text-bg-warning',
    'text-bg-danger',
    'text-bg-secondary',
    'text-bg-dark',
];

$matchViewData = static function (array $match) use ($courtBadgeClasses): array {
    $plannedStartRaw = (string) ($match['planned_start'] ?? '');
    $plannedStart = $plannedStartRaw;
    if ($plannedStartRaw !== '') {
        $dateTime = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $plannedStartRaw);
        if ($dateTime instanceof \DateTimeImmutable) {
            $plannedStart = $dateTime->format('H:i');
        }
    }

    $courtNumber = (int) ($match['court_number'] ?? 0);
    $courtBadgeClass = 'text-bg-secondary';
    if ($courtNumber > 0) {
        $courtBadgeClass = $courtBadgeClasses[($courtNumber - 1) % count($courtBadgeClasses)] ?? 'text-bg-secondary';
    }

    $status = (string) ($match['status'] ?? 'pending');
    $statusClass = 'text-bg-secondary';
    if ($status === 'scheduled') {
        $statusClass = 'text-bg-primary';
    } elseif ($status === 'in_progress') {
        $statusClass = 'text-bg-warning';
    } elseif ($status === 'finished') {
        $statusClass = 'text-bg-success';
    }

    $setsSummaryA = (int) ($match['sets_summary_a'] ?? 0);
    $setsSummaryB = (int) ($match['sets_summary_b'] ?? 0);
    $resultSummary = $status === 'finished' ? ($setsSummaryA . ':' . $setsSummaryB) : '-';
    $setScoresSummary = trim((string) ($match['set_scores_summary'] ?? ''));
    $winnerTeamId = (int) ($match['winner_team_id'] ?? 0);
    $isDrawResult = $status === 'finished' && $setsSummaryA === $setsSummaryB;
    $isWinnerA = $status === 'finished' && !$isDrawResult && $winnerTeamId > 0 && $winnerTeamId === (int) ($match['team_a_id'] ?? 0);
    $isWinnerB = $status === 'finished' && !$isDrawResult && $winnerTeamId > 0 && $winnerTeamId === (int) ($match['team_b_id'] ?? 0);

    return [
        'planned_start' => $plannedStart !== '' ? $plannedStart : '-',
        'court_number' => $courtNumber,
        'court_badge_class' => $courtBadgeClass,
        'status' => $status,
        'status_class' => $statusClass,
        'result_summary' => $resultSummary,
        'set_scores_summary' => $setScoresSummary,
        'is_winner_a' => $isWinnerA,
        'is_winner_b' => $isWinnerB,
        'detail_url' => (string) ($match['detail_url'] ?? ''),
    ];
};

$renderTeamName = static function (string $teamName, bool $isWinner) use ($e): void {
    ?>
    <span class="bb-admin-match-team-name <?= $isWinner ? 'match-winner-name' : '' ?>">
        <?= htmlspecialchars($teamName !== '' ? $teamName : '-', ENT_QUOTES, 'UTF-8') ?>
    </span>
    <?php if ($isWinner): ?>
        <span class="badge text-bg-light border text-secondary match-winner-badge"><?= $e('common.winner_short') ?></span>
    <?php endif; ?>
    <?php
};

$renderStartAction = static function (array $match, string $status, int $tournamentId, int $selectedGroupFilter, int $selectedCourtFilter) use ($e): void {
    if ($status !== 'scheduled') {
        $detailUrl = (string) ($match['detail_url'] ?? '');
        $actionLabel = $status === 'in_progress'
            ? $e('group_stage.enter_score')
            : ($status === 'finished' ? $e('group_stage.edit_result') : $e('group_stage.open_match'));
        ?>
        <?php if ($detailUrl !== ''): ?>
            <a href="<?= htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm <?= $status === 'in_progress' ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $actionLabel ?></a>
        <?php else: ?>
            <span class="text-muted small"><?= $e('common.open_detail') ?></span>
        <?php endif; ?>
        <?php
        return;
    }
    ?>
    <div class="bb-match-actions-inline">
        <form method="post" action="<?= htmlspecialchars((string) ($match['start_action_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="m-0">
            <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
            <input type="hidden" name="group_id" value="<?= $selectedGroupFilter ?>">
            <input type="hidden" name="court" value="<?= $selectedCourtFilter ?>">
            <input type="hidden" name="return_to" value="matches">
            <input type="hidden" name="lock_version" value="<?= (int) ($match['lock_version'] ?? 0) ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary"><?= $e('common.start') ?></button>
        </form>
        <?php if ((string) ($match['detail_url'] ?? '') !== ''): ?>
            <a href="<?= htmlspecialchars((string) $match['detail_url'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary"><?= $e('group_stage.open_match') ?></a>
        <?php endif; ?>
    </div>
    <?php
};

$liveMatches = array_values(array_filter(
    $groupMatches,
    static fn (array $match): bool => (string) ($match['status'] ?? '') === 'in_progress'
));
$nextMatchesByCourt = [];
foreach ($groupMatches as $candidateMatch) {
    if ((string) ($candidateMatch['status'] ?? '') !== 'scheduled') {
        continue;
    }

    $candidateCourt = (int) ($candidateMatch['court_number'] ?? 0);
    if ($candidateCourt <= 0 || isset($nextMatchesByCourt[$candidateCourt])) {
        continue;
    }

    $nextMatchesByCourt[$candidateCourt] = $candidateMatch;
}
?>
<div class="bb-workspace bb-stage-workspace">
    <header class="bb-workspace-header">
        <div>
            <div class="bb-page-kicker"><?= $e('group_stage.match_control') ?></div>
            <h2><?= $e('group_stage.title') ?></h2>
            <p><?= $e('group_stage.subtitle') ?></p>
        </div>
    </header>

    <details class="bb-stage-generation" <?= !$hasExistingGroupMatches ? 'open' : '' ?>>
        <summary>
            <span><?= $e('group_stage.generation') ?></span>
            <strong><?= $e('group_stage.generate_matches') ?></strong>
        </summary>
    <section class="bb-stage-action-card">
        <div class="bb-stage-action-copy">
            <span class="bb-settings-eyebrow"><?= $e('group_stage.generation') ?></span>
            <h3><?= $e('group_stage.generate_matches') ?></h3>
            <p><?= $e('group_stage.generate_matches_help') ?></p>
        </div>
        <div class="bb-stage-action-side">
            <?php if ($hasUnassignedTeams): ?>
                <div class="alert alert-warning py-2 mb-2 small" role="alert">
                    <?= $e('group_stage.unassigned_generation_warning') ?>
                </div>
            <?php endif; ?>
            <?php if ($hasExistingGroupMatches): ?>
                <div class="alert alert-warning py-2 mb-2 small" role="alert">
                    <?= $e('group_stage.existing_generation_warning') ?>
                </div>
            <?php endif; ?>
            <?php if ($hasExistingKnockoutMatches): ?>
                <div class="alert alert-warning py-2 mb-2 small" role="alert">
                    <?= $e('group_stage.knockout_generation_warning') ?>
                </div>
            <?php endif; ?>
            <form method="post" action="<?= htmlspecialchars($generateGroupMatchesActionUrl, ENT_QUOTES, 'UTF-8') ?>"<?= $generateFormOnSubmit !== '' ? ' onsubmit="' . $generateFormOnSubmit . '"' : '' ?>>
                <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                <input type="hidden" name="return_section" value="matches">
                <?php if ($hasUnassignedTeams): ?>
                    <input type="hidden" name="confirm_unassigned" value="1">
                <?php endif; ?>
                <?php if ($hasExistingMatches): ?>
                    <label class="form-check small text-warning mb-2">
                        <input class="form-check-input" type="checkbox" name="confirm_regenerate" value="1" required>
                        <span class="form-check-label"><?= $e('group_stage.confirm_regenerate') ?></span>
                    </label>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary w-100"><?= $e('group_stage.generate_group_stage_matches') ?></button>
            </form>
        </div>
    </section>
    </details>

    <?php if ($groupMatchesTotalCount > 0): ?>
        <section class="bb-live-desk" aria-labelledby="live-desk-title">
            <div class="bb-live-desk-heading">
                <div>
                    <span class="bb-section-kicker"><?= $e('group_stage.live_desk') ?></span>
                    <h3 id="live-desk-title"><?= $e('group_stage.live_desk') ?></h3>
                </div>
                <p><?= $e('group_stage.live_desk_help') ?></p>
            </div>
            <div class="bb-live-desk-grid">
                <?php foreach ($liveMatches as $liveMatch): ?>
                    <?php $liveView = $matchViewData($liveMatch); ?>
                    <article class="bb-live-match-card is-live">
                        <div class="bb-live-match-top">
                            <span class="bb-live-indicator"><span aria-hidden="true"></span><?= $e('group_stage.live_now') ?></span>
                            <strong><?= (int) ($liveMatch['court_number'] ?? 0) > 0 ? $e('common.court_number', ['number' => (int) $liveMatch['court_number']]) : '-' ?></strong>
                        </div>
                        <div class="bb-live-match-teams">
                            <span><?= htmlspecialchars((string) ($liveMatch['team_a_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                            <b aria-hidden="true">×</b>
                            <span><?= htmlspecialchars((string) ($liveMatch['team_b_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <a href="<?= htmlspecialchars((string) $liveView['detail_url'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary btn-sm"><?= $e('group_stage.enter_score') ?></a>
                    </article>
                <?php endforeach; ?>

                <?php foreach ($nextMatchesByCourt as $nextMatch): ?>
                    <?php $nextView = $matchViewData($nextMatch); ?>
                    <article class="bb-live-match-card">
                        <div class="bb-live-match-top">
                            <span><?= $e('group_stage.ready_next') ?></span>
                            <strong><?= $e('common.court_number', ['number' => (int) $nextMatch['court_number']]) ?></strong>
                        </div>
                        <div class="bb-live-match-time"><?= htmlspecialchars((string) $nextView['planned_start'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="bb-live-match-teams">
                            <span><?= htmlspecialchars((string) ($nextMatch['team_a_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                            <b aria-hidden="true">×</b>
                            <span><?= htmlspecialchars((string) ($nextMatch['team_b_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php $renderStartAction($nextMatch, 'scheduled', $tournamentId, $selectedGroupFilter, $selectedCourtFilter); ?>
                    </article>
                <?php endforeach; ?>

                <?php if (count($liveMatches) === 0 && count($nextMatchesByCourt) === 0): ?>
                    <div class="bb-empty-state"><?= $e('group_stage.no_live_matches') ?></div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($groupMatchesTotalCount > 0): ?>
        <section class="bb-stage-toolbar">
            <form method="get" action="<?= htmlspecialchars($matchesFilterActionUrl, ENT_QUOTES, 'UTF-8') ?>" class="bb-stage-filter-form">
                <input type="hidden" name="id" value="<?= $tournamentId ?>">
                <div>
                    <label for="group_id_filter" class="form-label mb-1"><?= $e('teams_groups.group') ?></label>
                    <select class="form-select form-select-sm" id="group_id_filter" name="group_id">
                        <option value="0"><?= $e('group_stage.all_groups') ?></option>
                        <?php foreach ($groupFilterOptions as $groupId => $groupName): ?>
                            <option value="<?= (int) $groupId ?>" <?= $selectedGroupFilter === (int) $groupId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="court_filter" class="form-label mb-1"><?= $e('print.court') ?></label>
                    <select class="form-select form-select-sm" id="court_filter" name="court">
                        <option value="0"><?= $e('group_stage.all_courts') ?></option>
                        <?php foreach ($courtFilterOptions as $court): ?>
                            <option value="<?= $court ?>" <?= $selectedCourtFilter === $court ? 'selected' : '' ?>>
                                <?= $e('common.court_number', ['number' => $court]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-primary"><?= $e('common.filter') ?></button>
                <a href="<?= htmlspecialchars($matchesFilterActionUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary"><?= $e('common.reset') ?></a>
            </form>
            <div class="bb-status-filter" role="group" aria-label="<?= $e('group_stage.filter_status') ?>">
                <button type="button" class="btn btn-sm btn-primary" data-match-status-filter="all" aria-pressed="true"><?= $e('group_stage.filter_all') ?></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-match-status-filter="in_progress" aria-pressed="false"><?= $e('group_stage.filter_live') ?></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-match-status-filter="scheduled" aria-pressed="false"><?= $e('group_stage.filter_ready') ?></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-match-status-filter="finished" aria-pressed="false"><?= $e('group_stage.filter_finished') ?></button>
            </div>
        </section>
    <?php endif; ?>

    <section class="bb-stage-board">
        <div class="bb-group-card-header">
            <div>
                <span class="bb-settings-eyebrow"><?= $e('group_stage.matches') ?></span>
                <h3><?= $e('group_stage.match_overview') ?></h3>
            </div>
            <span class="bb-status-pill"><?= $e('group_stage.shown_total', ['shown' => count($groupMatches), 'total' => $groupMatchesTotalCount]) ?></span>
        </div>

        <?php if (count($groupMatches) === 0): ?>
            <div class="bb-empty-state">
                <?php if ($groupMatchesTotalCount > 0): ?>
                    <?= $e('group_stage.no_matches_for_filters') ?>
                <?php else: ?>
                    <?= $e('group_stage.no_matches_generated') ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="bb-admin-match-table-wrap">
                <table class="table table-sm mb-0 align-middle bb-admin-match-table">
                    <caption class="visually-hidden"><?= $e('group_stage.table_caption') ?></caption>
                    <colgroup>
                        <col class="bb-match-col-order">
                        <col class="bb-match-col-group">
                        <col class="bb-match-col-match">
                        <col class="bb-match-col-result">
                        <col class="bb-match-col-court">
                        <col class="bb-match-col-start">
                        <col class="bb-match-col-status">
                        <col class="bb-match-col-action">
                    </colgroup>
                    <thead>
                    <tr>
                        <th scope="col"><?= $e('common.order') ?></th>
                        <th scope="col"><?= $e('teams_groups.group') ?></th>
                        <th scope="col"><?= $e('print.match') ?></th>
                        <th scope="col"><?= $e('print.result') ?></th>
                        <th scope="col"><?= $e('print.court') ?></th>
                        <th scope="col"><?= $e('print.start') ?></th>
                        <th scope="col"><?= $e('common.status') ?></th>
                        <th scope="col" class="text-end"><?= $e('common.action') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groupMatches as $match): ?>
                        <?php
                        $view = $matchViewData($match);
                        $detailUrl = (string) $view['detail_url'];
                        $teamAName = (string) ($match['team_a_name'] ?? '-');
                        $teamBName = (string) ($match['team_b_name'] ?? '-');
                        ?>
                        <tr class="bb-admin-match-row" data-match-status="<?= htmlspecialchars((string) $view['status'], ENT_QUOTES, 'UTF-8') ?>">
                            <td><span class="bb-admin-match-order"><?= (int) ($match['schedule_order'] ?? 0) ?></span></td>
                            <td><?= htmlspecialchars((string) ($match['group_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <div class="bb-admin-match-teams">
                                    <div><?php $renderTeamName($teamAName, (bool) $view['is_winner_a']); ?></div>
                                    <div><?php $renderTeamName($teamBName, (bool) $view['is_winner_b']); ?></div>
                                </div>
                            </td>
                            <td>
                                <div class="bb-admin-match-result"><?= htmlspecialchars((string) $view['result_summary'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php if ((string) $view['set_scores_summary'] !== ''): ?>
                                    <div class="small text-muted">(<?= htmlspecialchars((string) $view['set_scores_summary'], ENT_QUOTES, 'UTF-8') ?>)</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $view['court_number'] > 0): ?>
                                    <span class="badge <?= htmlspecialchars((string) $view['court_badge_class'], ENT_QUOTES, 'UTF-8') ?>"><?= $e('common.court_number', ['number' => (int) $view['court_number']]) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string) $view['planned_start'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge <?= htmlspecialchars((string) $view['status_class'], ENT_QUOTES, 'UTF-8') ?>"><?= $e('match_status.' . (string) $view['status']) ?></span></td>
                            <td class="text-end">
                                <?php $renderStartAction($match, (string) $view['status'], $tournamentId, $selectedGroupFilter, $selectedCourtFilter); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="bb-admin-match-cards">
                <?php foreach ($groupMatches as $match): ?>
                    <?php
                    $view = $matchViewData($match);
                    $detailUrl = (string) $view['detail_url'];
                    $teamAName = (string) ($match['team_a_name'] ?? '-');
                    $teamBName = (string) ($match['team_b_name'] ?? '-');
                    ?>
                    <article class="bb-admin-match-card" data-match-status="<?= htmlspecialchars((string) $view['status'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="bb-admin-match-card-top">
                            <span class="bb-admin-match-order">#<?= (int) ($match['schedule_order'] ?? 0) ?></span>
                            <span><?= htmlspecialchars((string) ($match['group_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="badge <?= htmlspecialchars((string) $view['status_class'], ENT_QUOTES, 'UTF-8') ?>"><?= $e('match_status.' . (string) $view['status']) ?></span>
                        </div>
                        <div class="bb-admin-match-card-teams">
                            <div><?php $renderTeamName($teamAName, (bool) $view['is_winner_a']); ?></div>
                            <div><?php $renderTeamName($teamBName, (bool) $view['is_winner_b']); ?></div>
                        </div>
                        <div class="bb-admin-match-card-meta">
                            <div>
                                <span><?= $e('print.result') ?></span>
                                <strong><?= htmlspecialchars((string) $view['result_summary'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ((string) $view['set_scores_summary'] !== ''): ?>
                                    <small>(<?= htmlspecialchars((string) $view['set_scores_summary'], ENT_QUOTES, 'UTF-8') ?>)</small>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span><?= $e('print.court') ?></span>
                                <?php if ((int) $view['court_number'] > 0): ?>
                                    <strong><?= $e('common.court_number', ['number' => (int) $view['court_number']]) ?></strong>
                                <?php else: ?>
                                    <strong>-</strong>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span><?= $e('print.start') ?></span>
                                <strong><?= htmlspecialchars((string) $view['planned_start'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                        </div>
                        <div class="bb-admin-match-card-action">
                            <?php $renderStartAction($match, (string) $view['status'], $tournamentId, $selectedGroupFilter, $selectedCourtFilter); ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="bb-empty-state d-none" id="js-match-status-empty" role="status"><?= $e('group_stage.no_status_matches') ?></div>
        <?php endif; ?>
    </section>
</div>
<script>
    (function () {
        var statusButtons = Array.prototype.slice.call(document.querySelectorAll('[data-match-status-filter]'));
        var statusItems = Array.prototype.slice.call(document.querySelectorAll('[data-match-status]'));
        var statusEmpty = document.getElementById('js-match-status-empty');
        statusButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var selectedStatus = button.getAttribute('data-match-status-filter') || 'all';
                var visibleItems = 0;
                statusItems.forEach(function (item) {
                    var visible = selectedStatus === 'all' || item.getAttribute('data-match-status') === selectedStatus;
                    item.hidden = !visible;
                    if (visible) {
                        visibleItems++;
                    }
                });
                statusButtons.forEach(function (candidate) {
                    var active = candidate === button;
                    candidate.classList.toggle('btn-primary', active);
                    candidate.classList.toggle('btn-outline-secondary', !active);
                    candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
                if (statusEmpty) {
                    statusEmpty.classList.toggle('d-none', visibleItems > 0);
                }
            });
        });
    })();
</script>
