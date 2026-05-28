<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
/** @var list<array<string, mixed>> $allMatches */
/** @var array<string, mixed> $knockoutPrint */
/** @var callable $h */
/** @var callable $formatTime */
/** @var callable $scoreText */
/** @var callable $scoreField */
/** @var callable $teamLabel */
/** @var callable $renderPrintHeader */

$sourceLabels = is_array($knockoutPrint['source_labels'] ?? null) ? $knockoutPrint['source_labels'] : [];
$matchesByCourt = [];
$configuredCourtCount = max(0, (int) ($tournament['number_of_courts'] ?? 0));
for ($court = 1; $court <= $configuredCourtCount; $court++) {
    $matchesByCourt[$court] = [];
}
foreach ($allMatches as $match) {
    $court = (int) ($match['court_number'] ?? 0);
    if ($court <= 0) {
        continue;
    }
    if (!isset($matchesByCourt[$court])) {
        $matchesByCourt[$court] = [];
    }
    $matchesByCourt[$court][] = $match;
}
ksort($matchesByCourt);
?>
<?php if (count($matchesByCourt) === 0): ?>
    <section class="print-page bb-print-page bb-print-section">
        <?php $renderPrintHeader('Schedule by Court'); ?>
        <div class="bb-print-empty">No court schedule is available yet.</div>
    </section>
<?php endif; ?>
<?php $courtPageIndex = 0; ?>
<?php foreach ($matchesByCourt as $court => $courtMatches): ?>
    <?php if ($courtPageIndex > 0): ?>
        <div class="print-page-separator" aria-hidden="true"><span>End of printed page</span></div>
    <?php endif; ?>
    <section class="print-page bb-print-page bb-print-section <?= $courtPageIndex > 0 ? 'bb-print-page-break' : '' ?>">
        <?php $renderPrintHeader('Court ' . (int) $court); ?>
        <table class="bb-print-table bb-print-schedule-table">
            <thead>
            <tr>
                <th>Time</th>
                <th>Stage</th>
                <th>Group/Round</th>
                <th>Team A</th>
                <th>Team B</th>
                <th>Result</th>
                <th>Set 1</th>
                <th>Set 2</th>
                <th>Set 3</th>
            </tr>
            </thead>
            <tbody>
            <?php if (count($courtMatches) === 0): ?>
                <tr><td colspan="9" class="bb-print-empty">No matches assigned to this court.</td></tr>
            <?php endif; ?>
            <?php foreach ($courtMatches as $match): ?>
                <tr>
                    <td><?= $h($formatTime((string) ($match['planned_start'] ?? ''))) ?></td>
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
    <?php $courtPageIndex++; ?>
<?php endforeach; ?>
