<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $knockoutMatches */
/** @var array<string, mixed> $knockoutPrint */
/** @var callable $h */
/** @var callable $formatTime */
/** @var callable $scoreText */
/** @var callable $scoreField */
/** @var callable $teamLabel */

$sourceLabels = is_array($knockoutPrint['source_labels'] ?? null) ? $knockoutPrint['source_labels'] : [];
$leftRounds = is_array($knockoutPrint['left_rounds'] ?? null) ? $knockoutPrint['left_rounds'] : [];
$rightRounds = is_array($knockoutPrint['right_rounds'] ?? null) ? $knockoutPrint['right_rounds'] : [];
$final = is_array($knockoutPrint['final'] ?? null) ? $knockoutPrint['final'] : null;
$semifinalLabels = is_array($knockoutPrint['semifinal_labels'] ?? null) ? array_values($knockoutPrint['semifinal_labels']) : [];
$note = (string) ($knockoutPrint['note'] ?? '');

$renderMatchBox = static function (array $match, string $variant = '') use ($h, $formatTime, $scoreText, $scoreField, $teamLabel, $sourceLabels): void {
    $court = (int) ($match['court_number'] ?? 0);
    $plannedStart = trim((string) ($match['planned_start'] ?? ''));
    ?>
    <article class="bb-print-ko-match <?= $h($variant) ?>">
        <div class="bb-print-ko-label"><?= $h((string) ($match['print_match_label'] ?? ($match['print_round_name'] ?? 'Match'))) ?></div>
        <div class="bb-print-ko-team"><?= $h($teamLabel($match, 'a', $sourceLabels)) ?></div>
        <div class="bb-print-ko-team"><?= $h($teamLabel($match, 'b', $sourceLabels)) ?></div>
        <?php if ($court > 0 || $plannedStart !== ''): ?>
            <div class="bb-print-ko-meta">
                <?= $court > 0 ? 'Court ' . $court : '' ?>
                <?= $plannedStart !== '' ? ($court > 0 ? ' / ' : '') . $h($formatTime($plannedStart)) : '' ?>
            </div>
        <?php endif; ?>
        <div class="bb-print-ko-total"><?= $scoreField($scoreText($match), 'bb-write-score-large') ?></div>
        <div class="bb-print-ko-sets">
            <?= $scoreField($scoreText($match, 1)) ?>
            <?= $scoreField($scoreText($match, 2)) ?>
            <?= $scoreField($scoreText($match, 3), 'bb-write-score-muted') ?>
        </div>
    </article>
    <?php
};

$placeholderThirdPlace = [
    'print_match_label' => 'Third place match',
    'team_a_name' => $semifinalLabels[0] ?? 'Semifinal 1',
    'team_b_name' => $semifinalLabels[1] ?? 'Semifinal 2',
    'team_a_source' => '',
    'team_b_source' => '',
    'status' => 'pending',
    'sets_summary_a' => 0,
    'sets_summary_b' => 0,
];
$placeholderThirdPlace['team_a_name'] = 'Loser of ' . (string) $placeholderThirdPlace['team_a_name'];
$placeholderThirdPlace['team_b_name'] = 'Loser of ' . (string) $placeholderThirdPlace['team_b_name'];
?>
<section class="bb-print-section">
    <?php if ($note !== ''): ?>
        <div class="bb-print-note"><?= $h($note) ?></div>
    <?php endif; ?>

    <?php if (count($knockoutMatches) === 0): ?>
        <div class="bb-print-empty">No knockout matches generated yet.</div>
    <?php else: ?>
        <div class="bb-print-ko-board">
            <div class="bb-print-ko-side bb-print-ko-left">
                <?php foreach ($leftRounds as $round): ?>
                    <section class="bb-print-ko-round">
                        <h3><?= $h((string) ($round['name'] ?? 'Round')) ?></h3>
                        <?php foreach (($round['matches'] ?? []) as $match): ?>
                            <?php if (is_array($match)) {
                                $renderMatchBox($match);
                            } ?>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="bb-print-ko-center">
                <?php if (is_array($final)): ?>
                    <?php $renderMatchBox($final, 'bb-print-ko-final'); ?>
                <?php else: ?>
                    <article class="bb-print-ko-match bb-print-ko-final">
                        <div class="bb-print-ko-label">Final</div>
                        <div class="bb-print-ko-team">Winner of Semifinal 1</div>
                        <div class="bb-print-ko-team">Winner of Semifinal 2</div>
                        <div class="bb-print-ko-total"><?= $scoreField('', 'bb-write-score-large') ?></div>
                        <div class="bb-print-ko-sets"><?= $scoreField('') ?> <?= $scoreField('') ?> <?= $scoreField('', 'bb-write-score-muted') ?></div>
                    </article>
                <?php endif; ?>
                <?php $renderMatchBox($placeholderThirdPlace, 'bb-print-ko-third'); ?>
            </div>

            <div class="bb-print-ko-side bb-print-ko-right">
                <?php foreach ($rightRounds as $round): ?>
                    <section class="bb-print-ko-round">
                        <h3><?= $h((string) ($round['name'] ?? 'Round')) ?></h3>
                        <?php foreach (($round['matches'] ?? []) as $match): ?>
                            <?php if (is_array($match)) {
                                $renderMatchBox($match);
                            } ?>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
