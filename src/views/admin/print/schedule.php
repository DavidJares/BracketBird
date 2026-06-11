<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $allMatches */
/** @var array<string, mixed> $knockoutPrint */
/** @var callable $h */
/** @var callable $formatTime */
/** @var callable $scoreText */
/** @var callable $scoreField */
/** @var callable $teamLabel */

$sourceLabels = is_array($knockoutPrint['source_labels'] ?? null) ? $knockoutPrint['source_labels'] : [];
?>
<section class="bb-print-section">
    <table class="bb-print-table bb-print-schedule-table">
        <thead>
        <tr>
            <th><?= $h($t('print.time')) ?></th>
            <th><?= $h($t('print.court')) ?></th>
            <th><?= $h($t('print.stage')) ?></th>
            <th><?= $h($t('print.group_round')) ?></th>
            <th><?= $h($t('print.team_a')) ?></th>
            <th><?= $h($t('print.team_b')) ?></th>
            <th><?= $h($t('print.result')) ?></th>
            <th><?= $h($t('print.set_1')) ?></th>
            <th><?= $h($t('print.set_2')) ?></th>
            <th><?= $h($t('print.set_3')) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if (count($allMatches) === 0): ?>
            <tr><td colspan="10" class="bb-print-empty"><?= $h($t('print.no_matches_generated')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($allMatches as $match): ?>
            <tr>
                <td><?= $h($formatTime((string) ($match['planned_start'] ?? ''))) ?></td>
                <td><?= (int) ($match['court_number'] ?? 0) > 0 ? $h($t('common.court_number', ['number' => (int) ($match['court_number'] ?? 0)])) : $h($t('common.tbd')) ?></td>
                <td><?= $h((string) ($match['stage_label'] ?? '-')) ?></td>
                <td><?= $h((string) ($match['context_label'] ?? '-')) ?></td>
                <td><?= $h($teamLabel($match, 'a', $sourceLabels)) ?></td>
                <td><?= $h($teamLabel($match, 'b', $sourceLabels)) ?></td>
                <td><?= $scoreField($scoreText($match)) ?></td>
                <td><?= $scoreField($scoreText($match, 1)) ?></td>
                <td><?= $scoreField($scoreText($match, 2)) ?></td>
                <td><?= $scoreField($scoreText($match, 3), 'bb-write-score-muted') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
