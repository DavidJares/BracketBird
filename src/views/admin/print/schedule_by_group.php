<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $groups */
/** @var array{grouped_teams: array<int, list<array<string, mixed>>>} $groupAssignment */
/** @var list<array<string, mixed>> $groupMatches */
/** @var callable $h */
/** @var callable $formatTime */
/** @var callable $scoreText */
/** @var callable $scoreField */
/** @var callable $renderPrintHeader */

$matchesByGroup = [];
foreach ($groupMatches as $match) {
    $groupId = (int) ($match['group_id'] ?? 0);
    if ($groupId <= 0) {
        continue;
    }
    if (!isset($matchesByGroup[$groupId])) {
        $matchesByGroup[$groupId] = [];
    }
    $matchesByGroup[$groupId][] = $match;
}
?>
<?php if (count($groups) === 0): ?>
    <section class="print-page bb-print-page bb-print-section">
        <?php $renderPrintHeader($t('print.schedule_by_group')); ?>
        <div class="bb-print-empty"><?= $h($t('print.no_groups_available')) ?></div>
    </section>
<?php endif; ?>
<?php $groupPageIndex = 0; ?>
<?php foreach ($groups as $group): ?>
    <?php
    $groupId = (int) ($group['id'] ?? 0);
    $groupName = (string) ($group['name'] ?? '');
    $teamsInGroup = $groupAssignment['grouped_teams'][$groupId] ?? [];
    $matches = $matchesByGroup[$groupId] ?? [];
    ?>
    <?php if ($groupPageIndex > 0): ?>
        <div class="print-page-separator" aria-hidden="true"><span><?= $h($t('print.end_of_printed_page')) ?></span></div>
    <?php endif; ?>
    <section class="print-page bb-print-page bb-print-section <?= $groupPageIndex > 0 ? 'bb-print-page-break' : '' ?>">
        <?php $renderPrintHeader($t('teams_groups.group_name', ['name' => $groupName])); ?>
        <div class="bb-print-team-list">
            <strong><?= $h($t('print.teams_in_this_group')) ?></strong>
            <ol>
                <?php foreach ($teamsInGroup as $team): ?>
                    <li><?= $h((string) ($team['team_name'] ?? '-')) ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
        <table class="bb-print-table bb-print-schedule-table">
            <thead>
            <tr>
                <th><?= $h($t('print.time')) ?></th>
                <th><?= $h($t('print.court')) ?></th>
                <th><?= $h($t('print.team_a')) ?></th>
                <th><?= $h($t('print.team_b')) ?></th>
                <th><?= $h($t('print.result')) ?></th>
                <th><?= $h($t('print.set_1')) ?></th>
                <th><?= $h($t('print.set_2')) ?></th>
                <th><?= $h($t('print.set_3')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (count($matches) === 0): ?>
                <tr><td colspan="8" class="bb-print-empty"><?= $h($t('print.no_matches_for_group')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($matches as $match): ?>
                <tr>
                    <td><?= $h($formatTime((string) ($match['planned_start'] ?? ''))) ?></td>
                    <td><?= (int) ($match['court_number'] ?? 0) > 0 ? $h($t('common.court_number', ['number' => (int) ($match['court_number'] ?? 0)])) : $h($t('common.tbd')) ?></td>
                    <td><?= $h((string) ($match['team_a_name'] ?? '-')) ?></td>
                    <td><?= $h((string) ($match['team_b_name'] ?? '-')) ?></td>
                    <td><?= $scoreField($scoreText($match)) ?></td>
                    <td><?= $scoreField($scoreText($match, 1)) ?></td>
                    <td><?= $scoreField($scoreText($match, 2)) ?></td>
                    <td><?= $scoreField($scoreText($match, 3), 'bb-write-score-muted') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php $groupPageIndex++; ?>
<?php endforeach; ?>
