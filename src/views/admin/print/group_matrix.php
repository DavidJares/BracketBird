<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $groups */
/** @var array{grouped_teams: array<int, list<array<string, mixed>>>} $groupAssignment */
/** @var list<array<string, mixed>> $groupMatches */
/** @var array<int, list<array<string, int|string>>> $groupStandingsByGroup */
/** @var bool $prefill */
/** @var callable $h */
/** @var callable $scoreText */
/** @var callable $scoreField */
/** @var callable $renderPrintHeader */

$matchesByPair = [];
foreach ($groupMatches as $match) {
    $teamAId = (int) ($match['team_a_id'] ?? 0);
    $teamBId = (int) ($match['team_b_id'] ?? 0);
    if ($teamAId <= 0 || $teamBId <= 0) {
        continue;
    }
    $matchesByPair[$teamAId . ':' . $teamBId] = $match;
    $matchesByPair[$teamBId . ':' . $teamAId] = $match;
}

$standingsByGroupAndTeam = [];
foreach ($groupStandingsByGroup as $groupId => $rows) {
    foreach ($rows as $row) {
        $teamId = (int) ($row['team_id'] ?? 0);
        if ($teamId <= 0) {
            continue;
        }
        $standingsByGroupAndTeam[(int) $groupId][$teamId] = $row;
    }
}
?>
<?php if (count($groups) === 0): ?>
    <section class="print-page bb-print-page bb-print-section">
        <?php $renderPrintHeader($t('print.group_round_robin_matrix')); ?>
        <div class="bb-print-empty"><?= $h($t('print.no_groups_available')) ?></div>
    </section>
<?php endif; ?>
<?php $matrixPageIndex = 0; ?>
<?php foreach ($groups as $group): ?>
    <?php
    $groupId = (int) ($group['id'] ?? 0);
    $groupName = (string) ($group['name'] ?? '');
    $teamsInGroup = array_values($groupAssignment['grouped_teams'][$groupId] ?? []);
    ?>
    <?php if ($matrixPageIndex > 0): ?>
        <div class="print-page-separator" aria-hidden="true"><span><?= $h($t('print.end_of_printed_page')) ?></span></div>
    <?php endif; ?>
    <section class="print-page bb-print-page bb-print-section <?= $matrixPageIndex > 0 ? 'bb-print-page-break' : '' ?>">
        <?php $renderPrintHeader($t('teams_groups.group_name', ['name' => $groupName])); ?>
        <?php if (count($teamsInGroup) === 0): ?>
            <div class="bb-print-empty"><?= $h($t('print.no_teams_assigned_to_group')) ?></div>
        <?php else: ?>
            <table class="bb-print-table bb-print-matrix-table bb-print-matrix-size-<?= count($teamsInGroup) ?>">
                <thead>
                <tr>
                    <th><?= $h($t('print.team')) ?></th>
                    <?php foreach ($teamsInGroup as $columnTeam): ?>
                        <th><?= $h((string) ($columnTeam['team_name'] ?? '-')) ?></th>
                    <?php endforeach; ?>
                    <th><?= $h($t('print.points')) ?></th>
                    <th><?= $h($t('print.rank')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($teamsInGroup as $rowTeam): ?>
                    <?php $rowTeamId = (int) ($rowTeam['id'] ?? 0); ?>
                    <tr>
                        <th><?= $h((string) ($rowTeam['team_name'] ?? '-')) ?></th>
                        <?php foreach ($teamsInGroup as $columnTeam): ?>
                            <?php
                            $columnTeamId = (int) ($columnTeam['id'] ?? 0);
                            $cellMatch = $matchesByPair[$rowTeamId . ':' . $columnTeamId] ?? null;
                            $invert = is_array($cellMatch) && (int) ($cellMatch['team_a_id'] ?? 0) !== $rowTeamId;
                            ?>
                            <?php if ($rowTeamId === $columnTeamId): ?>
                                <td class="bb-matrix-diagonal">X</td>
                            <?php else: ?>
                                <td class="bb-matrix-score-cell">
                                    <div class="bb-matrix-total"><?= $scoreField(is_array($cellMatch) ? $scoreText($cellMatch, 0, $invert) : '', 'bb-write-score-large') ?></div>
                                    <div class="bb-matrix-sets">
                                        <?= $scoreField(is_array($cellMatch) ? $scoreText($cellMatch, 1, $invert) : '') ?>
                                        <?= $scoreField(is_array($cellMatch) ? $scoreText($cellMatch, 2, $invert) : '') ?>
                                        <?= $scoreField(is_array($cellMatch) ? $scoreText($cellMatch, 3, $invert) : '', 'bb-write-score-muted') ?>
                                    </div>
                                </td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php
                        $standing = $prefill ? ($standingsByGroupAndTeam[$groupId][$rowTeamId] ?? null) : null;
                        $points = is_array($standing) ? (string) ((int) ($standing['tournament_points'] ?? 0)) : '';
                        $rank = is_array($standing) ? (string) ((int) ($standing['position'] ?? 0)) : '';
                        ?>
                        <td><span class="bb-write-score bb-write-score-summary"><?= $points !== '' ? $h($points) : '&nbsp;' ?></span></td>
                        <td><span class="bb-write-score bb-write-score-summary"><?= $rank !== '' ? $h($rank) : '&nbsp;' ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php $matrixPageIndex++; ?>
<?php endforeach; ?>
