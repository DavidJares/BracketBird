<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
/** @var array<string, string> $printUrls */

$printCards = [
    [
        'title' => 'Full match schedule',
        'description' => 'Main A4 schedule of all matches with space for manual score entry.',
        'badges' => ['A4 portrait', 'Manual score entry'],
        'url' => (string) ($printUrls['schedule'] ?? '#'),
    ],
    [
        'title' => 'Schedule by court',
        'description' => 'Each court on its own A4 page, intended to be posted directly at the court.',
        'badges' => ['A4 portrait', 'Each court starts on a new page', 'QR on every page'],
        'url' => (string) ($printUrls['schedule_by_court'] ?? '#'),
    ],
    [
        'title' => 'Schedule by group',
        'description' => 'Each group on its own A4 page, intended for players in that group.',
        'badges' => ['A4 portrait', 'Each group starts on a new page', 'QR on every page'],
        'url' => (string) ($printUrls['schedule_by_group'] ?? '#'),
    ],
    [
        'title' => 'Group matrix',
        'description' => 'Traditional round robin matrix for manual group operation.',
        'badges' => ['A4 landscape', 'Each group starts on a new page', 'QR on every page'],
        'url' => (string) ($printUrls['group_matrix'] ?? '#'),
    ],
    [
        'title' => 'Knockout bracket',
        'description' => 'Printable bracket flowing from the sides to the center, with final and third place match.',
        'badges' => ['A4 landscape', 'Final centered', 'Third place match'],
        'url' => (string) ($printUrls['knockout'] ?? '#'),
    ],
];
?>
<div class="bb-workspace bb-exports-workspace">
    <header class="bb-workspace-header">
        <div>
            <div class="bb-page-kicker">Tournament papers</div>
            <h2>Exports &amp; Print</h2>
            <p>Prepare printable materials for players, organizers and offline tournament operation.</p>
        </div>
    </header>

    <section class="bb-export-helper" aria-label="Print workflow note">
        <div>
            <span class="bb-settings-eyebrow">Print center</span>
            <p>Print outputs open in a new tab. Use the print toolbar to print or save as PDF.</p>
        </div>
    </section>

    <section class="bb-export-grid" aria-label="Printable outputs">
        <?php foreach ($printCards as $card): ?>
            <article class="bb-export-card">
                <div class="bb-export-card-copy">
                    <span class="bb-settings-eyebrow">Print output</span>
                    <h3><?= htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p><?= htmlspecialchars($card['description'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="bb-export-badges" aria-label="Print output details">
                    <?php foreach ($card['badges'] as $badge): ?>
                        <span><?= htmlspecialchars($badge, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="bb-export-card-footer">
                    <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars($card['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open</a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</div>
