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
        <?php $renderPrintHeader('Schedule by Group'); ?>
        <div class="bb-print-empty">No groups are available yet.</div>
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
        <div class="print-page-separator" aria-hidden="true"><span>End of printed page</span></div>
    <?php endif; ?>
    <section class="print-page bb-print-page bb-print-section <?= $groupPageIndex > 0 ? 'bb-print-page-break' : '' ?>">
        <?php $renderPrintHeader('Group ' . $groupName); ?>
        <div class="bb-print-team-list">
            <strong>Teams in this group:</strong>
            <ol>
                <?php foreach ($teamsInGroup as $team): ?>
                    <li><?= $h((string) ($team['team_name'] ?? '-')) ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
        <table class="bb-print-table bb-print-schedule-table">
            <thead>
            <tr>
                <th>Time</th>
                <th>Court</th>
                <th>Team A</th>
                <th>Team B</th>
                <th>Result</th>
                <th>Set 1</th>
                <th>Set 2</th>
                <th>Set 3</th>
            </tr>
            </thead>
            <tbody>
            <?php if (count($matches) === 0): ?>
                <tr><td colspan="8" class="bb-print-empty">No matches generated for this group.</td></tr>
            <?php endif; ?>
            <?php foreach ($matches as $match): ?>
                <tr>
                    <td><?= $h($formatTime((string) ($match['planned_start'] ?? ''))) ?></td>
                    <td><?= (int) ($match['court_number'] ?? 0) > 0 ? 'Court ' . (int) ($match['court_number'] ?? 0) : 'TBD' ?></td>
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
