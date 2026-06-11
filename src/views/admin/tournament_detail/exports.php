<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
/** @var array<string, string> $printUrls */

$printCards = [
    [
        'title' => $t('exports_print.full_match_schedule'),
        'description' => $t('exports_print.full_match_schedule_description'),
        'badges' => [$t('exports_print.a4_portrait'), $t('exports_print.manual_score_entry')],
        'url' => (string) ($printUrls['schedule'] ?? '#'),
    ],
    [
        'title' => $t('exports_print.schedule_by_court'),
        'description' => $t('exports_print.schedule_by_court_description'),
        'badges' => [$t('exports_print.a4_portrait'), $t('exports_print.each_court_new_page'), $t('exports_print.qr_on_every_page')],
        'url' => (string) ($printUrls['schedule_by_court'] ?? '#'),
    ],
    [
        'title' => $t('exports_print.schedule_by_group'),
        'description' => $t('exports_print.schedule_by_group_description'),
        'badges' => [$t('exports_print.a4_portrait'), $t('exports_print.each_group_new_page'), $t('exports_print.qr_on_every_page')],
        'url' => (string) ($printUrls['schedule_by_group'] ?? '#'),
    ],
    [
        'title' => $t('exports_print.group_matrix'),
        'description' => $t('exports_print.group_matrix_description'),
        'badges' => [$t('exports_print.a4_landscape'), $t('exports_print.each_group_new_page'), $t('exports_print.qr_on_every_page')],
        'url' => (string) ($printUrls['group_matrix'] ?? '#'),
    ],
    [
        'title' => $t('exports_print.knockout_bracket'),
        'description' => $t('exports_print.knockout_bracket_description'),
        'badges' => [$t('exports_print.a4_landscape'), $t('exports_print.final_centered'), $t('exports_print.third_place_match')],
        'url' => (string) ($printUrls['knockout'] ?? '#'),
    ],
];
?>
<div class="bb-workspace bb-exports-workspace">
    <header class="bb-workspace-header">
        <div>
            <div class="bb-page-kicker"><?= $e('exports_print.tournament_papers') ?></div>
            <h2><?= $e('exports_print.title') ?></h2>
            <p><?= $e('exports_print.subtitle') ?></p>
        </div>
    </header>

    <section class="bb-export-helper" aria-label="<?= $e('exports_print.workflow_note') ?>">
        <div>
            <span class="bb-settings-eyebrow"><?= $e('exports_print.print_center') ?></span>
            <p><?= $e('exports_print.print_outputs_open_new_tab') ?></p>
        </div>
    </section>

    <section class="bb-export-grid" aria-label="<?= $e('exports_print.printable_outputs') ?>">
        <?php foreach ($printCards as $card): ?>
            <article class="bb-export-card">
                <div class="bb-export-card-copy">
                    <span class="bb-settings-eyebrow"><?= $e('exports_print.print_output') ?></span>
                    <h3><?= htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p><?= htmlspecialchars($card['description'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="bb-export-badges" aria-label="<?= $e('exports_print.print_output_details') ?>">
                    <?php foreach ($card['badges'] as $badge): ?>
                        <span><?= htmlspecialchars($badge, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="bb-export-card-footer">
                    <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars($card['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= $e('common.open') ?></a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</div>
