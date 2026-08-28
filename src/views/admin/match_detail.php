<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
/** @var array<string, mixed> $match */
/** @var list<array{set_number: int, score_a: int, score_b: int}> $matchSets */
/** @var array<string, int> $filters */
/** @var string $backToMatchesUrl */
/** @var string $scoreActionUrl */
/** @var string|null $startActionUrl */
/** @var string|null $resetActionUrl */
/** @var string|null $matchStage */
/** @var bool|null $requiresDependentResetConfirmation */
/** @var bool|null $requiresKnockoutResetConfirmation */

$tournamentId = (int) ($tournament['id'] ?? 0);
$matchStage = is_string($matchStage ?? null) ? $matchStage : 'group';
$isKnockoutStage = $matchStage === 'knockout';
$requiresDependentResetConfirmation = (bool) ($requiresDependentResetConfirmation ?? false);
$requiresKnockoutResetConfirmation = (bool) ($requiresKnockoutResetConfirmation ?? false);
$status = (string) ($match['status'] ?? 'pending');
$statusLabel = $t('match_status.' . ($status !== '' ? $status : 'pending'));
$matchMode = (string) ($match['match_mode'] ?? ($tournament['group_stage_mode'] ?? ($tournament['match_mode'] ?? '')));
$matchModeLabel = match ($matchMode) {
    'fixed_2_sets' => $t('match_mode.fixed_2_sets'),
    'best_of_3' => $t('match_mode.best_of_3'),
    default => $matchMode !== '' ? $matchMode : $t('match_detail.match_mode'),
};
$statusClass = 'text-bg-secondary';
if ($status === 'scheduled') {
    $statusClass = 'text-bg-primary';
} elseif ($status === 'in_progress') {
    $statusClass = 'text-bg-warning';
} elseif ($status === 'finished') {
    $statusClass = 'text-bg-success';
}

$plannedStartDisplay = $isKnockoutStage ? $t('common.tbd') : '-';
$plannedStartRaw = (string) ($match['planned_start'] ?? '');
if ($plannedStartRaw !== '') {
    $plannedStartDate = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $plannedStartRaw);
    if ($plannedStartDate instanceof \DateTimeImmutable) {
        $plannedStartDisplay = $plannedStartDate->format('M j, H:i');
    }
}

$teamAName = (string) ($match['team_a_name'] ?? $t('print.team_a'));
$teamBName = (string) ($match['team_b_name'] ?? $t('print.team_b'));
$teamAId = (int) ($match['team_a_id'] ?? 0);
$teamBId = (int) ($match['team_b_id'] ?? 0);
$winnerTeamId = (int) ($match['winner_team_id'] ?? 0);
$scoreEntryAllowed = in_array($status, ['scheduled', 'in_progress', 'finished'], true);
$maxSetNumber = $matchMode === 'fixed_2_sets' ? 2 : 3;
$setValues = [];
$setWinsA = 0;
$setWinsB = 0;
$setSummaryParts = [];
foreach ($matchSets as $set) {
    $setNumber = (int) ($set['set_number'] ?? 0);
    if ($setNumber <= 0) {
        continue;
    }

    $scoreA = (int) ($set['score_a'] ?? 0);
    $scoreB = (int) ($set['score_b'] ?? 0);
    $setValues[$setNumber] = [
        'score_a' => $scoreA,
        'score_b' => $scoreB,
    ];
    $setSummaryParts[] = $scoreA . ':' . $scoreB;
    if ($scoreA > $scoreB) {
        $setWinsA++;
    } elseif ($scoreB > $scoreA) {
        $setWinsB++;
    }
}

$setsSummaryA = (int) ($match['sets_summary_a'] ?? $setWinsA);
$setsSummaryB = (int) ($match['sets_summary_b'] ?? $setWinsB);

if ($winnerTeamId <= 0 && $status === 'finished') {
    if ($setsSummaryA > $setsSummaryB) {
        $winnerTeamId = $teamAId;
    } elseif ($setsSummaryB > $setsSummaryA) {
        $winnerTeamId = $teamBId;
    }
}
$isFixedGroupDraw = !$isKnockoutStage
    && $matchMode === 'fixed_2_sets'
    && $status === 'finished'
    && $setsSummaryA === $setsSummaryB;
$teamAWon = !$isFixedGroupDraw && $winnerTeamId > 0 && $teamAId > 0 && $winnerTeamId === $teamAId;
$teamBWon = !$isFixedGroupDraw && $winnerTeamId > 0 && $teamBId > 0 && $winnerTeamId === $teamBId;
$resultDisplay = $status === 'finished' ? ($setsSummaryA . ' : ' . $setsSummaryB) : 'vs';
$setSummaryDisplay = $setSummaryParts !== [] ? implode(' / ', $setSummaryParts) : $t('match_detail.no_set_scores_yet');

$contextLabel = $isKnockoutStage ? $t('knockout.round') : $t('teams_groups.group');
$contextValue = (string) ($isKnockoutStage ? ($match['round_name'] ?? '-') : ($match['group_name'] ?? '-'));
$courtNumber = (int) ($match['court_number'] ?? 0);
$courtDisplay = $courtNumber > 0 ? $t('common.court_number', ['number' => $courtNumber]) : ($isKnockoutStage ? $t('common.tbd') : '-');
$subtitleParts = [
    $contextValue !== '' ? $contextLabel . ' ' . $contextValue : $contextLabel,
    $courtDisplay,
    ($isKnockoutStage ? $t('match_detail.estimated_start') : $t('match_detail.planned_start')) . ' ' . $plannedStartDisplay,
];
?>
<section class="bb-match-detail-workspace">
    <header class="bb-workspace-header bb-match-detail-header">
        <div>
            <span class="bb-section-kicker"><?= $isKnockoutStage ? $e('match_detail.knockout_scorekeeping') : $e('match_detail.group_scorekeeping') ?></span>
            <h1><?= $isKnockoutStage ? $e('match_detail.knockout_match_detail') : $e('match_detail.group_match_detail') ?></h1>
            <p><?= htmlspecialchars(implode(' | ', $subtitleParts), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <a href="<?= htmlspecialchars($backToMatchesUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm"><?= $e('match_detail.back_to_matches') ?></a>
    </header>

    <section class="bb-match-detail-hero" aria-label="<?= $e('match_detail.match_summary') ?>">
        <div class="bb-match-side <?= $teamAWon ? 'bb-match-side-winner' : '' ?>">
            <span class="bb-match-side-label"><?= $e('print.team_a') ?></span>
            <strong><?= htmlspecialchars($teamAName, ENT_QUOTES, 'UTF-8') ?></strong>
            <?php if ($teamAWon): ?>
                <span class="bb-winner-badge"><?= $e('common.winner_short') ?></span>
            <?php endif; ?>
        </div>

        <div class="bb-match-score-center">
            <span class="badge <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
            <strong><?= htmlspecialchars($resultDisplay, ENT_QUOTES, 'UTF-8') ?></strong>
            <small><?= htmlspecialchars($status === 'finished' ? $setSummaryDisplay : $matchModeLabel, ENT_QUOTES, 'UTF-8') ?></small>
            <div class="bb-match-hero-meta">
                <span><?= htmlspecialchars($matchModeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                <span><?= htmlspecialchars($courtDisplay, ENT_QUOTES, 'UTF-8') ?></span>
                <span><?= htmlspecialchars($plannedStartDisplay, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

        <div class="bb-match-side <?= $teamBWon ? 'bb-match-side-winner' : '' ?>">
            <span class="bb-match-side-label"><?= $e('print.team_b') ?></span>
            <strong><?= htmlspecialchars($teamBName, ENT_QUOTES, 'UTF-8') ?></strong>
            <?php if ($teamBWon): ?>
                <span class="bb-winner-badge"><?= $e('common.winner_short') ?></span>
            <?php endif; ?>
        </div>
    </section>

    <div class="bb-match-detail-grid">
        <aside class="bb-match-actions-card">
            <div class="bb-workspace-card-header">
                <div>
                    <span class="bb-section-kicker"><?= $e('match_detail.control') ?></span>
                    <h3><?= $e('match_detail.match_control') ?></h3>
                </div>
            </div>

            <?php if (!$isKnockoutStage && is_string($startActionUrl ?? null) && $startActionUrl !== ''): ?>
                <div class="bb-match-action-block">
                    <strong><?= $e('match_detail.start_match') ?></strong>
                    <?php if ($status === 'scheduled'): ?>
                        <p><?= $e('match_detail.start_match_help') ?></p>
                        <form method="post" action="<?= htmlspecialchars($startActionUrl, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                            <input type="hidden" name="group_id" value="<?= (int) ($filters['group_id'] ?? 0) ?>">
                            <input type="hidden" name="court" value="<?= (int) ($filters['court'] ?? 0) ?>">
                            <input type="hidden" name="lock_version" value="<?= (int) ($match['lock_version'] ?? 0) ?>">
                            <button type="submit" class="btn btn-primary w-100"><?= $e('match_detail.start_match') ?></button>
                        </form>
                    <?php else: ?>
                        <p class="mb-0"><?= $e('match_detail.can_only_start_scheduled', ['status' => $t('match_status.scheduled')]) ?></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="bb-match-action-block">
                    <strong><?= $e('match_detail.current_status') ?></strong>
                    <p class="mb-0"><?= $e('match_detail.score_entry_panel_help') ?></p>
                </div>
            <?php endif; ?>

            <?php if ($status === 'finished'): ?>
                <div class="bb-match-action-block">
                    <strong><?= $e('match_detail.result_correction') ?></strong>
                    <p><?= $e('match_detail.result_correction_help') ?></p>
                    <?php if (!$isKnockoutStage && is_string($resetActionUrl ?? null) && $resetActionUrl !== ''): ?>
                        <form method="post" action="<?= htmlspecialchars($resetActionUrl, ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode($t('match_detail.reset_result_confirm')), ENT_QUOTES, 'UTF-8') ?>);">
                            <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                            <input type="hidden" name="group_id" value="<?= (int) ($filters['group_id'] ?? 0) ?>">
                            <input type="hidden" name="court" value="<?= (int) ($filters['court'] ?? 0) ?>">
                            <input type="hidden" name="lock_version" value="<?= (int) ($match['lock_version'] ?? 0) ?>">
                            <?php if ($requiresKnockoutResetConfirmation): ?>
                                <label class="form-check small text-warning mb-2">
                                    <input class="form-check-input" type="checkbox" name="confirm_reset_knockout" value="1" required>
                                    <span class="form-check-label"><?= $e('match_detail.confirm_remove_knockout') ?></span>
                                </label>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-outline-danger w-100"><?= $e('match_detail.reset_result') ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($requiresDependentResetConfirmation): ?>
                <div class="bb-match-action-block bb-match-action-warning">
                    <strong><?= $e('match_detail.knockout_dependency') ?></strong>
                    <p class="mb-0"><?= $e('match_detail.knockout_dependency_help') ?></p>
                </div>
            <?php endif; ?>
        </aside>

        <section class="bb-score-entry-card">
            <div class="bb-workspace-card-header">
                <div>
                    <span class="bb-section-kicker"><?= $e('match_detail.score') ?></span>
                    <h3><?= $e('match_detail.score_entry') ?></h3>
                    <p><?= $e('match_detail.scoring_for_match', ['mode' => $matchModeLabel]) ?></p>
                </div>
            </div>

            <?php if ($scoreEntryAllowed): ?>
                <div class="bb-score-fast-note" id="score-entry-help">
                    <strong><?= $e('match_detail.fast_entry') ?></strong>
                    <span><?= $e('match_detail.fast_entry_help') ?></span>
                </div>
                <form
                    method="post"
                    action="<?= htmlspecialchars($scoreActionUrl, ENT_QUOTES, 'UTF-8') ?>"
                    class="bb-score-form"
                    autocomplete="off"
                    aria-describedby="score-entry-help score-result-impact"
                >
                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                    <?php if (!$isKnockoutStage): ?>
                        <input type="hidden" name="group_id" value="<?= (int) ($filters['group_id'] ?? 0) ?>">
                        <input type="hidden" name="court" value="<?= (int) ($filters['court'] ?? 0) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="lock_version" value="<?= (int) ($match['lock_version'] ?? 0) ?>">

                    <div class="bb-score-entry-head" aria-hidden="true">
                        <span><?= $e('print.set') ?></span>
                        <span><?= htmlspecialchars($teamAName, ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars($teamBName, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>

                    <div class="bb-score-set-list">
                        <?php for ($set = 1; $set <= $maxSetNumber; $set++): ?>
                            <?php $isRequired = $set <= 2; ?>
                            <div class="bb-score-set-row">
                                <div class="bb-score-set-label">
                                    <strong><?= $e('print.set_number', ['number' => $set]) ?></strong>
                                    <small><?= $isRequired ? $e('common.required') : $e('common.optional') ?></small>
                                </div>
                                <label class="bb-score-input-wrap" for="set-<?= $set ?>-a">
                                    <span><?= htmlspecialchars($teamAName, ENT_QUOTES, 'UTF-8') ?></span>
                                    <input
                                        type="number"
                                        inputmode="numeric"
                                        enterkeyhint="next"
                                        min="0"
                                        max="99"
                                        class="form-control bb-score-input"
                                        id="set-<?= $set ?>-a"
                                        name="set_<?= $set ?>_a"
                                        value="<?= htmlspecialchars(isset($setValues[$set]) ? (string) $setValues[$set]['score_a'] : '', ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $isRequired ? 'required' : '' ?>
                                        <?= $set === 1 && $status !== 'finished' ? 'autofocus' : '' ?>
                                    >
                                </label>
                                <label class="bb-score-input-wrap" for="set-<?= $set ?>-b">
                                    <span><?= htmlspecialchars($teamBName, ENT_QUOTES, 'UTF-8') ?></span>
                                    <input
                                        type="number"
                                        inputmode="numeric"
                                        enterkeyhint="<?= $set === $maxSetNumber ? 'done' : 'next' ?>"
                                        min="0"
                                        max="99"
                                        class="form-control bb-score-input"
                                        id="set-<?= $set ?>-b"
                                        name="set_<?= $set ?>_b"
                                        value="<?= htmlspecialchars(isset($setValues[$set]) ? (string) $setValues[$set]['score_b'] : '', ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $isRequired ? 'required' : '' ?>
                                    >
                                </label>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <div class="bb-score-help">
                        <?php if ($matchMode === 'best_of_3'): ?>
                            <p><?= $e('match_detail.best_of_3_help') ?></p>
                        <?php elseif ($isKnockoutStage): ?>
                            <p><?= $e('match_detail.fixed_2_knockout_help') ?></p>
                        <?php else: ?>
                            <p><?= $e('match_detail.fixed_2_group_help') ?></p>
                        <?php endif; ?>
                        <?php if ($status === 'finished'): ?>
                            <p><?= $e('match_detail.finished_save_corrects') ?></p>
                        <?php endif; ?>
                        <?php if ($requiresDependentResetConfirmation): ?>
                            <p class="text-warning"><?= $e('match_detail.dependent_reset_warning') ?></p>
                            <label class="form-check text-warning">
                                <input class="form-check-input" type="checkbox" name="confirm_reset_dependents" value="1">
                                <span class="form-check-label"><?= $e('match_detail.confirm_reset_dependents') ?></span>
                            </label>
                        <?php endif; ?>
                        <?php if ($requiresKnockoutResetConfirmation): ?>
                            <p class="text-warning"><?= $e('match_detail.group_change_removes_knockout') ?></p>
                            <label class="form-check text-warning">
                                <input class="form-check-input" type="checkbox" name="confirm_reset_knockout" value="1">
                                <span class="form-check-label"><?= $e('match_detail.confirm_remove_knockout') ?></span>
                            </label>
                        <?php endif; ?>
                    </div>

                    <div class="bb-score-submit">
                        <p id="score-result-impact"><?= $e('match_detail.result_saved_notice') ?></p>
                        <button type="submit" class="btn btn-success"><?= $e('match_detail.save_result_finish') ?></button>
                    </div>
                </form>
            <?php else: ?>
                <div class="bb-empty-state">
                    <?= $e('match_detail.score_entry_available_statuses') ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>

<script>
    (function () {
        var scoreForm = document.querySelector('.bb-score-form');
        if (!scoreForm) {
            return;
        }

        var scoreInputs = Array.prototype.slice.call(scoreForm.querySelectorAll('.bb-score-input'));
        scoreInputs.forEach(function (input, index) {
            input.addEventListener('focus', function () {
                input.select();
            });
            input.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' || index >= scoreInputs.length - 1) {
                    return;
                }

                event.preventDefault();
                scoreInputs[index + 1].focus();
            });
        });
    })();
</script>
