<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $tournaments */
/** @var list<string> $matchModes */
/** @var array<string, string> $oldTournamentInput */

$modeLabels = [
    'fixed_2_sets' => $t('match_mode.fixed_2_sets'),
    'best_of_3' => $t('match_mode.best_of_3'),
];
$oldTournamentInput = is_array($oldTournamentInput ?? null) ? $oldTournamentInput : [];
$oldValue = static fn (string $field, string $default = ''): string => (string) ($oldTournamentInput[$field] ?? $default);
$hasPreservedCreateInput = count($oldTournamentInput) > 0;
$today = new \DateTimeImmutable('today');
$totalTournaments = count($tournaments);
$upcomingTournaments = 0;
$publicViewEnabledCount = 0;
foreach ($tournaments as $tournament) {
    $eventDateRaw = trim((string) ($tournament['event_date'] ?? ''));
    if ($eventDateRaw !== '') {
        $eventDate = \DateTimeImmutable::createFromFormat('Y-m-d', $eventDateRaw);
        if ($eventDate instanceof \DateTimeImmutable && $eventDate >= $today) {
            $upcomingTournaments++;
        }
    }

    if ((int) ($tournament['public_view_enabled'] ?? 0) > 0) {
        $publicViewEnabledCount++;
    }
}

$sortableDateValue = static function (array $tournament, string $field): int {
    $value = trim((string) ($tournament[$field] ?? ''));
    if ($value === '') {
        return 0;
    }

    $timestamp = strtotime($value);
    return is_int($timestamp) ? $timestamp : 0;
};

usort(
    $tournaments,
    static function (array $a, array $b) use ($sortableDateValue): int {
        $eventA = $sortableDateValue($a, 'event_date');
        $eventB = $sortableDateValue($b, 'event_date');
        if ($eventA > 0 && $eventB > 0 && $eventA !== $eventB) {
            return $eventB <=> $eventA;
        }
        if ($eventA > 0 && $eventB <= 0) {
            return -1;
        }
        if ($eventB > 0 && $eventA <= 0) {
            return 1;
        }

        $createdCompare = $sortableDateValue($b, 'created_at') <=> $sortableDateValue($a, 'created_at');
        if ($createdCompare !== 0) {
            return $createdCompare;
        }

        return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
    }
);
?>
<section class="bb-dashboard-shell">
    <header class="bb-workspace-header bb-dashboard-header">
        <div>
            <span class="bb-section-kicker"><?= $e('dashboard.superadmin') ?></span>
            <h1><?= $e('dashboard.tournaments') ?></h1>
            <p><?= $e('dashboard.subtitle') ?></p>
        </div>
        <a href="#create-tournament" class="btn btn-primary"><?= $e('dashboard.create_tournament') ?></a>
    </header>

    <section class="bb-metric-grid" aria-label="<?= $e('dashboard.tournament_summary') ?>">
        <div class="bb-metric-card">
            <span><?= $e('dashboard.total_tournaments') ?></span>
            <strong><?= $totalTournaments ?></strong>
        </div>
        <div class="bb-metric-card">
            <span><?= $e('dashboard.upcoming') ?></span>
            <strong><?= $upcomingTournaments ?></strong>
        </div>
        <div class="bb-metric-card">
            <span><?= $e('dashboard.public_enabled') ?></span>
            <strong><?= $publicViewEnabledCount ?></strong>
        </div>
        <div class="bb-metric-card">
            <span><?= $e('dashboard.draft_private') ?></span>
            <strong><?= max(0, $totalTournaments - $publicViewEnabledCount) ?></strong>
        </div>
    </section>

    <section class="bb-dashboard-section">
        <div class="bb-board-section-heading">
            <div>
                <span class="bb-section-kicker"><?= $e('dashboard.overview') ?></span>
                <h3><?= $e('dashboard.control_center') ?></h3>
            </div>
        </div>

        <?php if ($totalTournaments === 0): ?>
            <div class="bb-empty-state bb-empty-state-action">
                <strong><?= $e('dashboard.empty_title') ?></strong>
                <span><?= $e('dashboard.empty_help') ?></span>
                <a href="#create-tournament" class="btn btn-primary btn-sm"><?= $e('dashboard.create_tournament') ?></a>
            </div>
        <?php else: ?>
            <div class="bb-dashboard-toolbar">
                <label for="dashboard_tournament_search" class="visually-hidden"><?= $e('dashboard.search_tournaments') ?></label>
                <input type="search" class="form-control" id="dashboard_tournament_search" placeholder="<?= $e('dashboard.search_tournaments_placeholder') ?>" autocomplete="off">
                <span id="dashboard_tournament_count"><?= $e('dashboard.tournament_count', ['count' => $totalTournaments]) ?></span>
            </div>

            <div class="bb-dashboard-list" id="dashboard_tournament_list">
                <?php foreach ($tournaments as $tournament): ?>
                    <?php
                    $tournamentId = (int) ($tournament['id'] ?? 0);
                    $stateVersion = max(0, (int) ($tournament['state_version'] ?? 0));
                    $name = (string) ($tournament['name'] ?? '');
                    $slug = (string) ($tournament['slug'] ?? '');
                    $eventDate = trim((string) ($tournament['event_date'] ?? ''));
                    $startTimeRaw = trim((string) ($tournament['start_time'] ?? ''));
                    $startTime = $startTimeRaw !== '' ? substr($startTimeRaw, 0, 5) : '';
                    $location = trim((string) ($tournament['location'] ?? ''));
                    $groupMode = (string) ($tournament['group_stage_mode'] ?? ($tournament['match_mode'] ?? ''));
                    $knockoutMode = (string) ($tournament['knockout_mode'] ?? 'best_of_3');
                    $groupModeLabel = (string) ($modeLabels[$groupMode] ?? $groupMode);
                    $knockoutModeLabel = (string) ($modeLabels[$knockoutMode] ?? $knockoutMode);
                    $detailUrl = $url('/admin/tournament?id=' . $tournamentId);
                    $adminLoginUrl = $absoluteUrl('/tournament/' . $slug . '/login');
                    $publicDisplayUrl = $absoluteUrl('/public/' . $slug . '/display');
                    $publicViewEnabled = (int) ($tournament['public_view_enabled'] ?? 0) > 0;
                    $searchText = strtolower(trim($name . ' ' . $slug . ' ' . $location));
                    ?>
                    <article class="bb-tournament-row" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="bb-tournament-row-main">
                            <h3><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></h3>
                            <div class="bb-tournament-row-slug">
                                <code><?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code>
                            </div>
                            <div class="bb-tournament-row-meta">
                                <span><?= htmlspecialchars($eventDate !== '' ? $eventDate : $t('common.no_date'), ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= htmlspecialchars($startTime !== '' ? $startTime : $t('common.no_start'), ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= htmlspecialchars($location !== '' ? $location : $t('common.no_location'), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>

                        <div class="bb-tournament-row-badges" aria-label="<?= $e('dashboard.tournament_status_and_rules') ?>">
                            <span class="badge <?= $publicViewEnabled ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $publicViewEnabled ? $e('common.public') : $e('common.private') ?></span>
                            <div class="bb-tournament-mode-group">
                                <span class="bb-tournament-mode-pill"><?= $e('dashboard.group_mode', ['mode' => $groupModeLabel]) ?></span>
                                <span class="bb-tournament-mode-pill"><?= $e('dashboard.ko_mode', ['mode' => $knockoutModeLabel]) ?></span>
                            </div>
                        </div>

                        <div class="bb-tournament-actions">
                            <div class="bb-action-group" aria-label="<?= $e('dashboard.tournament_actions') ?>">
                                <a class="btn btn-sm btn-primary bb-tournament-primary-action" href="<?= htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') ?>"><?= $e('common.detail') ?></a>
                                <button type="button" class="btn btn-sm btn-outline-secondary js-dashboard-copy" data-copy-value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"><?= $e('dashboard.copy_slug') ?></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary js-dashboard-copy" data-copy-value="<?= htmlspecialchars($adminLoginUrl, ENT_QUOTES, 'UTF-8') ?>"><?= $e('dashboard.copy_admin_url') ?></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary js-dashboard-copy" data-copy-value="<?= htmlspecialchars($publicDisplayUrl, ENT_QUOTES, 'UTF-8') ?>"><?= $e('dashboard.copy_display_url') ?></button>
                                <form method="post" action="<?= htmlspecialchars($url('/admin/tournaments/delete'), ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode($t('dashboard.delete_tournament_confirm')), ENT_QUOTES, 'UTF-8') ?>);">
                                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                                    <input type="hidden" name="state_version" value="<?= $stateVersion ?>">
                                    <input type="hidden" name="confirm_delete" value="1">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><?= $e('common.delete') ?></button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="bb-empty-state d-none" id="dashboard_tournament_empty"><?= $e('dashboard.no_tournaments_match') ?></div>
        <?php endif; ?>
    </section>

    <section id="create-tournament" class="bb-create-tournament-panel">
        <div class="bb-workspace-card-header">
            <div>
                <span class="bb-section-kicker"><?= $e('dashboard.create') ?></span>
                <h3><?= $e('dashboard.create_tournament_title') ?></h3>
                <p><?= $e('dashboard.create_tournament_subtitle') ?></p>
            </div>
        </div>

        <?php if ($hasPreservedCreateInput): ?>
            <div class="alert alert-warning mx-3 mt-3 mb-0" role="status" id="create-tournament-validation" tabindex="-1">
                <?= $e('dashboard.form_preserved') ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars($url('/admin/tournaments/create'), ENT_QUOTES, 'UTF-8') ?>" class="bb-create-tournament-form">
            <section class="bb-create-form-section">
                <div class="bb-create-form-section-head">
                    <span>01</span>
                    <div>
                        <h4><?= $e('tournament.basic_information') ?></h4>
                        <p><?= $e('dashboard.basic_information_help') ?></p>
                    </div>
                </div>
                <div class="bb-create-form-grid">
                    <div class="bb-field bb-field-full">
                        <label for="name" class="form-label"><?= $e('tournament.name') ?></label>
                        <input type="text" class="form-control" name="name" id="name" required maxlength="150" autocomplete="off" value="<?= htmlspecialchars($oldValue('name'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="bb-field bb-field-full">
                        <label for="slug" class="form-label"><?= $e('tournament.slug') ?></label>
                        <div class="bb-copy-group">
                            <input type="text" class="form-control" name="slug" id="slug" readonly maxlength="150" aria-readonly="true">
                            <button type="button" class="btn btn-outline-secondary js-copy-slug" data-copy-target="slug"><?= $e('common.copy') ?></button>
                        </div>
                        <div class="form-text"><?= $e('dashboard.slug_help') ?></div>
                    </div>
                    <div class="bb-field">
                        <label for="event_date" class="form-label"><?= $e('tournament.event_date') ?></label>
                        <input type="date" class="form-control" name="event_date" id="event_date" value="<?= htmlspecialchars($oldValue('event_date'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="bb-field">
                        <label for="start_time" class="form-label"><?= $e('tournament.start_time') ?></label>
                        <input type="time" class="form-control" name="start_time" id="start_time" value="<?= htmlspecialchars($oldValue('start_time', '09:00'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="bb-field bb-field-full">
                        <label for="location" class="form-label"><?= $e('tournament.location') ?></label>
                        <input type="text" class="form-control" name="location" id="location" maxlength="150" value="<?= htmlspecialchars($oldValue('location'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
            </section>

            <section class="bb-create-form-section">
                <div class="bb-create-form-section-head">
                    <span>02</span>
                    <div>
                        <h4><?= $e('tournament.access') ?></h4>
                        <p><?= $e('dashboard.access_help') ?></p>
                    </div>
                </div>
                <div class="bb-create-form-grid bb-create-form-grid-single">
                    <div class="bb-field">
                        <label for="admin_password" class="form-label"><?= $e('tournament.admin_password') ?></label>
                        <input type="password" class="form-control" name="admin_password" id="admin_password" required minlength="8" maxlength="72">
                    </div>
                </div>
            </section>

            <section class="bb-create-form-section">
                <div class="bb-create-form-section-head">
                    <span>03</span>
                    <div>
                        <h4><?= $e('tournament.structure') ?></h4>
                        <p><?= $e('dashboard.structure_help') ?></p>
                    </div>
                </div>
                <div class="bb-create-form-grid bb-create-form-grid-compact">
                    <div class="bb-field">
                        <label for="number_of_groups" class="form-label"><?= $e('tournament.groups') ?></label>
                        <input type="number" class="form-control" name="number_of_groups" id="number_of_groups" min="1" max="32" value="<?= htmlspecialchars($oldValue('number_of_groups', '2'), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="bb-field">
                        <label for="number_of_courts" class="form-label"><?= $e('tournament.courts') ?></label>
                        <input type="number" class="form-control" name="number_of_courts" id="number_of_courts" min="1" max="99" value="<?= htmlspecialchars($oldValue('number_of_courts', '1'), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="bb-field">
                        <label for="match_duration_minutes" class="form-label"><?= $e('tournament.match_duration') ?></label>
                        <input type="number" class="form-control" name="match_duration_minutes" id="match_duration_minutes" min="1" max="240" value="<?= htmlspecialchars($oldValue('match_duration_minutes', '20'), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="bb-field">
                        <label for="advancing_teams_count" class="form-label"><?= $e('tournament.advancing_teams') ?></label>
                        <input type="number" class="form-control" name="advancing_teams_count" id="advancing_teams_count" min="2" max="64" value="<?= htmlspecialchars($oldValue('advancing_teams_count', '2'), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                </div>
            </section>

            <section class="bb-create-form-section">
                <div class="bb-create-form-section-head">
                    <span>04</span>
                    <div>
                        <h4><?= $e('tournament.rules') ?></h4>
                        <p><?= $e('dashboard.rules_help') ?></p>
                    </div>
                </div>
                <div class="bb-create-form-grid">
                    <div class="bb-field">
                        <label for="group_stage_mode" class="form-label"><?= $e('tournament.group_stage_mode') ?></label>
                        <select class="form-select" name="group_stage_mode" id="group_stage_mode" required>
                            <?php foreach ($matchModes as $mode): ?>
                                <option value="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>" <?= $mode === $oldValue('group_stage_mode', 'fixed_2_sets') ? 'selected' : '' ?>><?= htmlspecialchars((string) ($modeLabels[$mode] ?? $mode), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bb-field">
                        <label for="knockout_mode" class="form-label"><?= $e('tournament.knockout_mode') ?></label>
                        <select class="form-select" name="knockout_mode" id="knockout_mode" required>
                            <?php foreach ($matchModes as $mode): ?>
                                <option value="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>" <?= $mode === $oldValue('knockout_mode', 'best_of_3') ? 'selected' : '' ?>><?= htmlspecialchars((string) ($modeLabels[$mode] ?? $mode), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </section>

            <div class="bb-dashboard-savebar">
                <div>
                    <strong><?= $e('dashboard.ready_to_create') ?></strong>
                    <span><?= $e('dashboard.opens_after_creation') ?></span>
                </div>
                <button type="submit" class="btn btn-primary"><?= $e('dashboard.create_tournament') ?></button>
            </div>
        </form>
    </section>
</section>

<script>
    (function () {
        var nameInput = document.getElementById('name');
        var slugInput = document.getElementById('slug');
        if (nameInput && slugInput) {
            var slugify = function (value) {
                return (value || '')
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '')
                    .replace(/-+/g, '-');
            };
            var syncSlug = function () {
                slugInput.value = slugify(nameInput.value || '');
            };
            nameInput.addEventListener('input', syncSlug);
            syncSlug();
        }

        var copyText = function (value, fallbackInput) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value);
                return;
            }
            if (fallbackInput) {
                fallbackInput.focus();
                fallbackInput.select();
                document.execCommand('copy');
            }
        };

        document.querySelectorAll('.js-copy-slug').forEach(function (button) {
            button.addEventListener('click', function () {
                var targetId = button.getAttribute('data-copy-target');
                if (!targetId) {
                    return;
                }
                var input = document.getElementById(targetId);
                if (!input) {
                    return;
                }
                copyText(input.value || '', input);
            });
        });

        document.querySelectorAll('.js-dashboard-copy').forEach(function (button) {
            button.addEventListener('click', function () {
                copyText(button.getAttribute('data-copy-value') || '', null);
            });
        });

        var searchInput = document.getElementById('dashboard_tournament_search');
        var list = document.getElementById('dashboard_tournament_list');
        var emptyState = document.getElementById('dashboard_tournament_empty');
        var countLabel = document.getElementById('dashboard_tournament_count');
        var tournamentCountLabel = <?= json_encode($t('dashboard.tournament_count'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        if (searchInput && list) {
            var rows = Array.prototype.slice.call(list.querySelectorAll('.bb-tournament-row'));
            var syncSearch = function () {
                var query = (searchInput.value || '').trim().toLowerCase();
                var visibleCount = 0;
                rows.forEach(function (row) {
                    var searchable = row.getAttribute('data-search') || '';
                    var visible = query === '' || searchable.indexOf(query) !== -1;
                    row.hidden = !visible;
                    if (visible) {
                        visibleCount++;
                    }
                });
                if (emptyState) {
                    emptyState.classList.toggle('d-none', visibleCount > 0);
                }
                if (countLabel) {
                    countLabel.textContent = tournamentCountLabel.replace('{count}', String(visibleCount));
                }
            };

            searchInput.addEventListener('input', syncSearch);
            syncSearch();
        }

        var validationSummary = document.getElementById('create-tournament-validation');
        if (validationSummary) {
            validationSummary.scrollIntoView({block: 'center'});
            validationSummary.focus();
        }
    })();
</script>
