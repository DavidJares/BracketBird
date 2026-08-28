<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
/** @var list<string> $matchModes */
/** @var string $settingsActionUrl */
/** @var bool $hasAnyMatches */

$tournamentId = (int) ($tournament['id'] ?? 0);
$stateVersion = max(0, (int) ($tournament['state_version'] ?? 0));
$hasAnyMatches = (bool) ($hasAnyMatches ?? false);
$startTimeValueRaw = (string) ($tournament['start_time'] ?? '');
$startTimeValue = '';
if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $startTimeValueRaw) === 1) {
    $startTimeValue = substr($startTimeValueRaw, 0, 5);
}
$modeLabels = [
    'fixed_2_sets' => $t('match_mode.fixed_2_sets'),
    'best_of_3' => $t('match_mode.best_of_3'),
];
$currentSlug = (string) ($tournament['slug'] ?? '');
$currentName = (string) ($tournament['name'] ?? '');
$tournamentLoginUrlPrefix = $absoluteUrl('/tournament/');
$currentLoginUrl = $absoluteUrl('/tournament/' . $currentSlug . '/login');
?>
<section class="bb-readiness-panel" aria-labelledby="readiness-title">
    <div class="bb-readiness-heading">
        <div>
            <span class="bb-section-kicker"><?= $e('tournament_overview.readiness') ?></span>
            <h2 id="readiness-title"><?= $e('tournament_overview.readiness') ?></h2>
        </div>
        <p><?= $e('tournament_overview.readiness_help') ?></p>
    </div>
    <div class="bb-readiness-grid">
        <a href="<?= htmlspecialchars((string) ($sectionNav['groups'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>" class="bb-readiness-item <?= $totalTeams > 0 ? 'is-complete' : 'is-attention' ?>">
            <span>01</span>
            <strong><?= $e('tournament_overview.participants') ?></strong>
            <small><?= $totalTeams > 0 ? $e('tournament_hub.teams_count', ['count' => $totalTeams]) : $e('tournament_overview.no_participants') ?></small>
        </a>
        <a href="<?= htmlspecialchars((string) ($sectionNav['groups'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>" class="bb-readiness-item <?= $groupsReady ? 'is-complete' : 'is-attention' ?>">
            <span>02</span>
            <strong><?= $e('tournament_overview.assignment') ?></strong>
            <small><?= $groupsReady ? $e('tournament_overview.all_assigned') : $e('tournament_overview.needs_assignment', ['count' => $unassignedTeams]) ?></small>
        </a>
        <a href="<?= htmlspecialchars((string) ($sectionNav['matches'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>" class="bb-readiness-item <?= $groupMatchTotal > 0 ? 'is-complete' : 'is-attention' ?>">
            <span>03</span>
            <strong><?= $e('tournament_overview.group_schedule') ?></strong>
            <small><?= $groupMatchTotal > 0 ? $e('tournament_overview.matches_generated', ['count' => $groupMatchTotal]) : $e('tournament_overview.no_matches') ?></small>
        </a>
        <a href="<?= htmlspecialchars((string) ($sectionNav['public_view'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>" class="bb-readiness-item <?= $publicViewEnabled ? 'is-complete' : 'is-attention' ?>">
            <span>04</span>
            <strong><?= $e('tournament_overview.public_display') ?></strong>
            <small><?= $publicViewEnabled ? $e('tournament_hub.public_live') : $e('tournament_hub.public_private') ?> · <?= $e('tournament_overview.courts', ['count' => (int) ($tournament['number_of_courts'] ?? 0)]) ?></small>
        </a>
    </div>
</section>

<form method="post" action="<?= htmlspecialchars($settingsActionUrl, ENT_QUOTES, 'UTF-8') ?>" class="bb-settings-form">
    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
    <input type="hidden" name="state_version" value="<?= $stateVersion ?>">
    <input type="hidden" name="return_section" value="tournament">

    <div class="bb-settings-header">
        <div>
            <div class="bb-page-kicker"><?= $e('tournament.configuration') ?></div>
            <h2><?= $e('tournament.settings') ?></h2>
            <p><?= $e('tournament.settings_subtitle') ?></p>
        </div>
    </div>

    <div class="bb-settings-grid">
        <div class="bb-settings-main">
            <section class="bb-settings-card">
                <div class="bb-settings-card-header">
                    <div>
                        <span class="bb-settings-eyebrow">01</span>
                        <h3><?= $e('tournament.general_information') ?></h3>
                    </div>
                    <p><?= $e('tournament.general_information_help') ?></p>
                </div>

                <div class="bb-settings-fields">
                    <div class="bb-field bb-field-full">
                        <label for="name" class="form-label"><?= $e('tournament.name') ?></label>
                        <input type="text" name="name" id="name" class="form-control" required maxlength="150" value="<?= htmlspecialchars($currentName, ENT_QUOTES, 'UTF-8') ?>" data-original-name="<?= htmlspecialchars($currentName, ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="bb-field bb-field-full">
                        <label for="slug_display" class="form-label"><?= $e('tournament.slug') ?></label>
                        <div class="input-group bb-copy-group">
                            <input type="text" id="slug_display" class="form-control bg-body-tertiary text-muted border-secondary-subtle" readonly value="<?= htmlspecialchars($currentSlug, ENT_QUOTES, 'UTF-8') ?>" aria-readonly="true">
                            <button type="button" class="btn btn-outline-secondary js-copy-slug" data-copy-target="slug_display"><?= $e('common.copy') ?></button>
                        </div>
                        <div class="form-text"><?= $e('tournament.slug_readonly_help') ?></div>
                    </div>

                    <div class="bb-field bb-field-full">
                        <label for="tournament_login_url" class="form-label"><?= $e('tournament.admin_login_url') ?></label>
                        <div class="input-group bb-copy-group">
                            <input
                                type="text"
                                id="tournament_login_url"
                                class="form-control bg-body-tertiary text-muted border-secondary-subtle"
                                readonly
                                value="<?= htmlspecialchars($currentLoginUrl, ENT_QUOTES, 'UTF-8') ?>"
                                aria-readonly="true"
                            >
                            <button type="button" class="btn btn-outline-secondary js-copy-slug" data-copy-target="tournament_login_url"><?= $e('common.copy') ?></button>
                            <a id="tournament_login_url_open" class="btn btn-outline-secondary" href="<?= htmlspecialchars($currentLoginUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= $e('common.open') ?></a>
                        </div>
                    </div>

                    <div id="js-slug-options" class="bb-field bb-field-full d-none">
                        <div class="alert alert-warning py-2 mb-2 small" role="alert">
                            <?= $e('tournament.name_change_suggests_slug') ?> <code id="js-proposed-slug">-</code>
                        </div>
                        <div class="bb-radio-stack">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="slug_update_action" id="slug_update_action_keep" value="keep" checked>
                                <label class="form-check-label" for="slug_update_action_keep"><?= $e('tournament.keep_current_slug') ?></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="slug_update_action" id="slug_update_action_update" value="update">
                                <label class="form-check-label" for="slug_update_action_update"><?= $e('tournament.update_slug_to_match_name') ?></label>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" value="keep" id="slug_update_action_fallback">

                    <div class="bb-field">
                        <label for="event_date" class="form-label"><?= $e('tournament.event_date') ?></label>
                        <input type="date" name="event_date" id="event_date" class="form-control" value="<?= htmlspecialchars((string) ($tournament['event_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="bb-field">
                        <label for="start_time" class="form-label"><?= $e('tournament.start_time') ?></label>
                        <input type="time" name="start_time" id="start_time" class="form-control" value="<?= htmlspecialchars($startTimeValue, ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="bb-field bb-field-full">
                        <label for="location" class="form-label"><?= $e('tournament.location') ?></label>
                        <input type="text" name="location" id="location" class="form-control" maxlength="150" value="<?= htmlspecialchars((string) ($tournament['location'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
            </section>

            <section class="bb-settings-card">
                <div class="bb-settings-card-header">
                    <div>
                        <span class="bb-settings-eyebrow">02</span>
                        <h3><?= $e('tournament.structure') ?></h3>
                    </div>
                    <p><?= $e('tournament.structure_help') ?></p>
                </div>

                <div class="bb-settings-fields bb-settings-fields-compact">
                    <div class="bb-field">
                        <label for="number_of_groups" class="form-label"><?= $e('tournament.groups') ?></label>
                        <input type="number" class="form-control" name="number_of_groups" id="number_of_groups" min="1" max="32" value="<?= (int) ($tournament['number_of_groups'] ?? 1) ?>" required>
                    </div>
                    <div class="bb-field">
                        <label for="number_of_courts" class="form-label"><?= $e('tournament.courts') ?></label>
                        <input type="number" class="form-control" name="number_of_courts" id="number_of_courts" min="1" max="99" value="<?= (int) ($tournament['number_of_courts'] ?? 1) ?>" required>
                    </div>
                    <div class="bb-field">
                        <label for="match_duration_minutes" class="form-label"><?= $e('tournament.match_duration') ?></label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="match_duration_minutes" id="match_duration_minutes" min="1" max="240" value="<?= (int) ($tournament['match_duration_minutes'] ?? 20) ?>" required>
                            <span class="input-group-text">min</span>
                        </div>
                    </div>
                    <div class="bb-field">
                        <label for="advancing_teams_count" class="form-label d-inline-flex align-items-center gap-1">
                            <span><?= $e('tournament.advancing_teams') ?></span>
                            <span
                                class="badge rounded-pill text-bg-light border text-secondary bb-help-badge"
                                data-bs-toggle="tooltip"
                                data-bs-placement="top"
                                data-bs-title="<?= $e('tournament.advancing_teams_help') ?>"
                                aria-label="<?= $e('tournament.advancing_teams_help_label') ?>"
                            >i</span>
                        </label>
                        <input type="number" class="form-control" name="advancing_teams_count" id="advancing_teams_count" min="2" max="64" value="<?= (int) ($tournament['advancing_teams_count'] ?? 2) ?>" required>
                    </div>
                </div>
                <?php if ($hasAnyMatches): ?>
                    <div class="alert alert-warning mt-3 mb-0" role="alert">
                        <p class="mb-2"><?= $e('tournament.structure_change_warning') ?></p>
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="confirm_reset_matches" value="1">
                            <span class="form-check-label"><?= $e('tournament.confirm_reset_matches') ?></span>
                        </label>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="bb-settings-side">
            <section class="bb-settings-card">
                <div class="bb-settings-card-header">
                    <div>
                        <span class="bb-settings-eyebrow">03</span>
                        <h3><?= $e('tournament.match_rules') ?></h3>
                    </div>
                    <p><?= $e('tournament.match_rules_help') ?></p>
                </div>

                <div class="bb-settings-fields bb-settings-fields-single">
                    <div class="bb-field">
                        <label for="group_stage_mode" class="form-label"><?= $e('tournament.group_stage_mode') ?></label>
                        <select class="form-select" name="group_stage_mode" id="group_stage_mode" required>
                            <?php foreach ($matchModes as $mode): ?>
                                <option value="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>" <?= ((string) ($tournament['group_stage_mode'] ?? ($tournament['match_mode'] ?? 'fixed_2_sets')) === $mode) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($modeLabels[$mode] ?? $mode), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="bb-field">
                        <label for="knockout_mode" class="form-label"><?= $e('tournament.knockout_mode') ?></label>
                        <select class="form-select" name="knockout_mode" id="knockout_mode" required>
                            <?php foreach ($matchModes as $mode): ?>
                                <option value="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>" <?= ((string) ($tournament['knockout_mode'] ?? 'best_of_3') === $mode) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($modeLabels[$mode] ?? $mode), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </section>

            <section class="bb-settings-card">
                <div class="bb-settings-card-header">
                    <div>
                        <span class="bb-settings-eyebrow">04</span>
                        <h3><?= $e('tournament.access') ?></h3>
                    </div>
                    <p><?= $e('tournament.access_help') ?></p>
                </div>

                <div class="bb-settings-fields bb-settings-fields-single">
                    <div class="bb-field">
                        <label for="admin_password" class="form-label"><?= $e('tournament.admin_password') ?></label>
                        <input type="password" name="admin_password" id="admin_password" class="form-control" minlength="8" maxlength="72" placeholder="<?= $e('tournament.leave_blank_keep_unchanged') ?>">
                        <div class="form-text"><?= $e('tournament.leave_blank_keep_current_password') ?></div>
                    </div>
                </div>
            </section>
        </aside>
    </div>

    <div class="bb-settings-savebar">
        <div>
            <strong><?= $e('tournament.ready_to_apply_changes') ?></strong>
            <span><?= $e('tournament.saved_to_this_tournament_only') ?></span>
        </div>
        <button type="submit" class="btn btn-primary"><?= $e('tournament.save_settings') ?></button>
    </div>
</form>
<script>
    (function () {
        var nameInput = document.getElementById('name');
        var slugInput = document.getElementById('slug_display');
        var slugOptions = document.getElementById('js-slug-options');
        var proposedSlug = document.getElementById('js-proposed-slug');
        var keepOption = document.getElementById('slug_update_action_keep');
        var updateOption = document.getElementById('slug_update_action_update');
        var fallbackAction = document.getElementById('slug_update_action_fallback');
        var loginUrlInput = document.getElementById('tournament_login_url');
        var loginUrlOpen = document.getElementById('tournament_login_url_open');
        var loginUrlPrefix = <?= json_encode($tournamentLoginUrlPrefix, JSON_UNESCAPED_SLASHES) ?>;
        var loginUrlSuffix = '/login';
        var currentSlug = slugInput ? (slugInput.value || '').trim() : '';
        var originalName = nameInput ? (nameInput.getAttribute('data-original-name') || '').trim() : '';

        var slugify = function (value) {
            return (value || '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .replace(/-+/g, '-');
        };

        var syncSlugChoice = function () {
            if (!nameInput || !slugOptions || !proposedSlug || !keepOption || !updateOption || !fallbackAction) {
                return;
            }
            var newName = (nameInput.value || '').trim();
            var generated = slugify(newName);
            var nameChanged = newName !== originalName;
            var slugWouldChange = generated !== '' && generated !== currentSlug;
            var showChoice = nameChanged && slugWouldChange;
            slugOptions.classList.toggle('d-none', !showChoice);
            proposedSlug.textContent = generated !== '' ? generated : 'tournament';
            if (!showChoice) {
                keepOption.checked = true;
                fallbackAction.name = 'slug_update_action';
                fallbackAction.value = 'keep';
                syncLoginUrlPreview(currentSlug);
                return;
            }
            fallbackAction.removeAttribute('name');
            syncLoginUrlPreview(updateOption.checked ? (generated !== '' ? generated : 'tournament') : currentSlug);
        };

        var syncLoginUrlPreview = function (slugValue) {
            if (!loginUrlInput || !loginUrlOpen) {
                return;
            }

            var safeSlug = (slugValue || '').trim();
            if (safeSlug === '') {
                safeSlug = 'tournament';
            }

            var fullUrl = loginUrlPrefix + safeSlug + loginUrlSuffix;
            loginUrlInput.value = fullUrl;
            loginUrlOpen.setAttribute('href', fullUrl);
        };

        if (nameInput) {
            nameInput.addEventListener('input', syncSlugChoice);
        }
        if (keepOption) {
            keepOption.addEventListener('change', syncSlugChoice);
        }
        if (updateOption) {
            updateOption.addEventListener('change', syncSlugChoice);
        }
        syncLoginUrlPreview(currentSlug);
        syncSlugChoice();

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
                var value = input.value || '';
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(value);
                    return;
                }
                input.focus();
                input.select();
                document.execCommand('copy');
            });
        });
    })();
</script>
