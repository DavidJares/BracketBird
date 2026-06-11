<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
/** @var list<array<string, mixed>> $groups */
/** @var list<array<string, mixed>> $teams */
/** @var array{
 *     total_teams: int,
 *     group_count: int,
 *     unassigned_count: int,
 *     teams_per_group: array<int, int>,
 *     grouped_teams: array<int, list<array<string, mixed>>>,
 *     unassigned_teams: list<array<string, mixed>>
 * } $groupAssignment */
/** @var string $createTeamActionUrl */
/** @var string $updateTeamActionUrl */
/** @var string $deleteTeamActionUrl */
/** @var string $assignTeamActionUrl */
/** @var string $autoAssignTeamsActionUrl */
/** @var array<int, list<array<string, int|string>>> $groupStandingsByGroup */

$tournamentId = (int) ($tournament['id'] ?? 0);
$totalTeams = (int) $groupAssignment['total_teams'];
$groupCount = (int) $groupAssignment['group_count'];
$unassignedCount = (int) $groupAssignment['unassigned_count'];
$assignedCount = max(0, $totalTeams - $unassignedCount);

$renderAssignmentOptions = static function (array $groups, ?int $selectedGroupId) use ($e): void {
    ?>
    <option value="" <?= $selectedGroupId === null ? 'selected' : '' ?>><?= $e('teams_groups.no_group') ?></option>
    <?php foreach ($groups as $optionGroup): ?>
        <?php $optionId = (int) ($optionGroup['id'] ?? 0); ?>
        <option value="<?= $optionId ?>" <?= $selectedGroupId === $optionId ? 'selected' : '' ?>>
            <?= htmlspecialchars((string) ($optionGroup['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </option>
    <?php endforeach; ?>
    <?php
};

$renderTeamCard = static function (
    array $team,
    array $groups,
    ?int $selectedGroupId,
    int $tournamentId,
    string $assignTeamActionUrl,
    string $updateTeamActionUrl,
    string $deleteTeamActionUrl,
    callable $renderAssignmentOptions
) use ($e, $t): void {
    $teamId = (int) ($team['id'] ?? 0);
    $teamName = (string) ($team['team_name'] ?? '');
    $description = (string) ($team['description'] ?? '');
    $groupName = '';
    if ($selectedGroupId !== null) {
        foreach ($groups as $group) {
            if ((int) ($group['id'] ?? 0) === $selectedGroupId) {
                $groupName = (string) ($group['name'] ?? '');
                break;
            }
        }
    }
    $assignmentLabel = $selectedGroupId === null ? $t('teams_groups.unassigned') : $t('teams_groups.group_name', ['name' => $groupName]);
    $editPanelId = 'team-edit-panel-' . $teamId;
    ?>
    <article class="bb-team-item <?= $selectedGroupId === null ? 'bb-team-item-unassigned' : '' ?>">
        <div class="bb-team-item-header">
            <div class="bb-team-item-main">
                <strong title="<?= htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8') ?>
                </strong>
                <?php if ($description !== ''): ?>
                    <span><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <div class="bb-team-item-meta">
                    <span class="bb-team-badge"><?= htmlspecialchars($assignmentLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <div class="bb-team-actions">
                <button type="button" class="btn btn-sm btn-outline-secondary bb-team-edit-toggle" data-team-edit-target="<?= htmlspecialchars($editPanelId, ENT_QUOTES, 'UTF-8') ?>" aria-controls="<?= htmlspecialchars($editPanelId, ENT_QUOTES, 'UTF-8') ?>" aria-expanded="false"><?= $e('common.edit') ?></button>
                <form method="post" action="<?= htmlspecialchars($deleteTeamActionUrl, ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode($t('teams.delete_team_confirm')), ENT_QUOTES, 'UTF-8') ?>);" class="bb-team-delete-form">
                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                    <input type="hidden" name="team_id" value="<?= $teamId ?>">
                    <input type="hidden" name="confirm_delete" value="1">
                    <input type="hidden" name="return_section" value="groups">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><?= $e('common.delete') ?></button>
                </form>
            </div>
        </div>

        <div id="<?= htmlspecialchars($editPanelId, ENT_QUOTES, 'UTF-8') ?>" class="bb-team-edit-panel" hidden>
            <div class="bb-team-edit-panel-header">
                <strong><?= $e('teams.edit_team') ?></strong>
                <button type="button" class="btn btn-sm btn-outline-secondary bb-team-edit-cancel" data-team-edit-target="<?= htmlspecialchars($editPanelId, ENT_QUOTES, 'UTF-8') ?>"><?= $e('common.cancel') ?></button>
            </div>

            <form method="post" action="<?= htmlspecialchars($updateTeamActionUrl, ENT_QUOTES, 'UTF-8') ?>" class="bb-team-edit-form">
                <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                <input type="hidden" name="team_id" value="<?= $teamId ?>">
                <input type="hidden" name="return_section" value="groups">
                <div>
                    <label class="form-label"><?= $e('teams.team_name') ?></label>
                    <input type="text" name="team_name" class="form-control form-control-sm" required maxlength="150" value="<?= htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div>
                    <label class="form-label"><?= $e('teams.description') ?></label>
                    <textarea name="description" class="form-control form-control-sm" rows="2" maxlength="1000"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <button type="submit" class="btn btn-sm btn-primary"><?= $e('teams.save_details') ?></button>
            </form>

            <form method="post" action="<?= htmlspecialchars($assignTeamActionUrl, ENT_QUOTES, 'UTF-8') ?>" class="bb-team-assignment-form">
                <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                <input type="hidden" name="team_id" value="<?= $teamId ?>">
                <input type="hidden" name="return_section" value="groups">
                <div>
                    <label class="form-label"><?= $e('teams_groups.group_assignment') ?></label>
                    <select name="group_id" class="form-select form-select-sm" aria-label="<?= $e('teams_groups.assign_team_to_group', ['team' => $teamName]) ?>">
                        <?php $renderAssignmentOptions($groups, $selectedGroupId); ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-primary"><?= $e('teams_groups.save_group') ?></button>
            </form>
        </div>
    </article>
    <?php
};
?>
<div class="bb-workspace bb-teams-workspace">
    <header class="bb-workspace-header">
        <div>
            <div class="bb-page-kicker"><?= $e('teams_groups.preparation') ?></div>
            <h2><?= $e('teams_groups.title') ?></h2>
            <p><?= $e('teams_groups.subtitle') ?></p>
        </div>
    </header>

    <section class="bb-metric-grid" aria-label="<?= $e('teams_groups.summary') ?>">
        <div class="bb-metric-card">
            <span><?= $e('teams_groups.total_teams') ?></span>
            <strong><?= $totalTeams ?></strong>
        </div>
        <div class="bb-metric-card">
            <span><?= $e('teams_groups.groups') ?></span>
            <strong><?= $groupCount ?></strong>
        </div>
        <div class="bb-metric-card">
            <span><?= $e('teams_groups.assigned') ?></span>
            <strong><?= $assignedCount ?></strong>
        </div>
        <div class="bb-metric-card <?= $unassignedCount > 0 ? 'bb-metric-card-warning' : '' ?>">
            <span><?= $e('teams_groups.unassigned') ?></span>
            <strong><?= $unassignedCount ?></strong>
        </div>
    </section>

    <div class="bb-workspace-grid">
        <aside class="bb-workspace-rail">
            <section class="bb-action-card">
                <div class="bb-workspace-card-header">
                    <div>
                        <span class="bb-settings-eyebrow"><?= $e('teams.add_participant') ?></span>
                        <h3><?= $e('teams.add_team') ?></h3>
                    </div>
                </div>
                <form method="post" action="<?= htmlspecialchars($createTeamActionUrl, ENT_QUOTES, 'UTF-8') ?>" class="bb-stack-form">
                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                    <input type="hidden" name="return_section" value="groups">
                    <div>
                        <label for="team_name_new" class="form-label"><?= $e('teams.team_name') ?></label>
                        <input type="text" name="team_name" id="team_name_new" class="form-control" required maxlength="150">
                    </div>
                    <div>
                        <label for="team_description_new" class="form-label"><?= $e('teams.description_optional') ?></label>
                        <textarea name="description" id="team_description_new" class="form-control" rows="3" maxlength="1000"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><?= $e('teams.add_team') ?></button>
                </form>
            </section>

            <section class="bb-action-card bb-action-card-accent">
                <div class="bb-workspace-card-header">
                    <div>
                        <span class="bb-settings-eyebrow"><?= $e('teams_groups.group_draw') ?></span>
                        <h3><?= $e('teams_groups.balanced_assignment') ?></h3>
                    </div>
                    <span class="bb-status-pill"><?= $e('teams.count', ['count' => $totalTeams]) ?></span>
                </div>
                <p class="bb-card-copy"><?= $e('teams_groups.balanced_assignment_help') ?></p>
                <form method="post" action="<?= htmlspecialchars($autoAssignTeamsActionUrl, ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode($t('teams_groups.auto_assign_confirm')), ENT_QUOTES, 'UTF-8') ?>);">
                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                    <input type="hidden" name="confirm_overwrite" value="1">
                    <input type="hidden" name="return_section" value="groups">
                    <button type="submit" class="btn btn-outline-primary w-100"><?= $e('teams_groups.automatically_assign_teams') ?></button>
                </form>
            </section>
        </aside>

        <main class="bb-workspace-board">
            <section class="bb-group-card bb-group-card-unassigned">
                <div class="bb-group-card-header">
                    <div>
                        <span class="bb-settings-eyebrow"><?= $e('teams_groups.needs_attention') ?></span>
                        <h3><?= $e('teams_groups.unassigned_teams') ?></h3>
                    </div>
                    <span class="bb-status-pill"><?= $unassignedCount ?></span>
                </div>
                <?php if (count($groupAssignment['unassigned_teams']) === 0): ?>
                    <div class="bb-empty-state"><?= $e('teams_groups.all_teams_assigned') ?></div>
                <?php else: ?>
                    <div class="bb-team-card-list">
                        <?php foreach ($groupAssignment['unassigned_teams'] as $team): ?>
                            <?php
                            $renderTeamCard(
                                $team,
                                $groups,
                                null,
                                $tournamentId,
                                $assignTeamActionUrl,
                                $updateTeamActionUrl,
                                $deleteTeamActionUrl,
                                $renderAssignmentOptions
                            );
                            ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="bb-group-section">
                <div class="bb-board-section-heading">
                    <div>
                        <span class="bb-settings-eyebrow"><?= $e('teams_groups.generated_groups') ?></span>
                        <h3><?= $e('teams_groups.group_cards') ?></h3>
                    </div>
                    <div class="bb-group-chip-list" aria-label="<?= $e('teams_groups.groups') ?>">
                        <?php foreach ($groups as $group): ?>
                            <span class="bb-group-chip"><?= htmlspecialchars((string) ($group['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bb-group-card-grid">
                    <?php foreach ($groups as $group): ?>
                        <?php
                        $groupId = (int) ($group['id'] ?? 0);
                        $groupName = (string) ($group['name'] ?? '');
                        $groupTeams = $groupAssignment['grouped_teams'][$groupId] ?? [];
                        $teamCount = (int) ($groupAssignment['teams_per_group'][$groupId] ?? 0);
                        $standingsRows = $groupStandingsByGroup[$groupId] ?? [];
                        ?>
                        <article class="bb-group-card">
                            <div class="bb-group-card-header">
                                <div>
                                    <span class="bb-settings-eyebrow"><?= $e('teams_groups.group') ?></span>
                                    <h3><?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?></h3>
                                </div>
                                <span class="bb-status-pill"><?= $e('teams.count', ['count' => $teamCount]) ?></span>
                            </div>

                            <?php if (count($groupTeams) === 0): ?>
                                <div class="bb-empty-state"><?= $e('teams_groups.no_teams_assigned_to_group') ?></div>
                            <?php else: ?>
                                <div class="bb-team-card-list">
                                    <?php foreach ($groupTeams as $team): ?>
                                        <?php
                                        $renderTeamCard(
                                            $team,
                                            $groups,
                                            $groupId,
                                            $tournamentId,
                                            $assignTeamActionUrl,
                                            $updateTeamActionUrl,
                                            $deleteTeamActionUrl,
                                            $renderAssignmentOptions
                                        );
                                        ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <details class="bb-standings-details">
                                <summary><?= $e('teams_groups.standings_snapshot') ?></summary>
                                <div class="table-responsive mt-2">
                                    <table class="table table-sm table-striped align-middle mb-0">
                                        <thead>
                                        <tr>
                                            <th>#</th>
                                            <th><?= $e('print.team') ?></th>
                                            <th>MP</th>
                                            <th>W</th>
                                            <th>D</th>
                                            <th>L</th>
                                            <th>SF</th>
                                            <th>SA</th>
                                            <th>PF</th>
                                            <th>PA</th>
                                            <th>+/-</th>
                                            <th><?= $e('print.points_short') ?></th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (count($standingsRows) === 0): ?>
                                            <tr>
                                                <td colspan="12" class="text-muted"><?= $e('teams_groups.no_teams_assigned') ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($standingsRows as $row): ?>
                                            <tr>
                                                <td><?= (int) ($row['position'] ?? 0) ?></td>
                                                <td><?= htmlspecialchars((string) ($row['team_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= (int) ($row['played'] ?? 0) ?></td>
                                                <td><?= (int) ($row['wins'] ?? 0) ?></td>
                                                <td><?= (int) ($row['draws'] ?? 0) ?></td>
                                                <td><?= (int) ($row['losses'] ?? 0) ?></td>
                                                <td><?= (int) ($row['sets_for'] ?? 0) ?></td>
                                                <td><?= (int) ($row['sets_against'] ?? 0) ?></td>
                                                <td><?= (int) ($row['points_for'] ?? 0) ?></td>
                                                <td><?= (int) ($row['points_against'] ?? 0) ?></td>
                                                <td><?= (int) ($row['point_diff'] ?? 0) ?></td>
                                                <td><strong><?= (int) ($row['tournament_points'] ?? 0) ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
</div>
<script>
    (function () {
        var editLabel = <?= json_encode($t('common.edit'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        var closeLabel = <?= json_encode($t('common.close'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        var setPanelState = function (panel, open) {
            if (!panel) {
                return;
            }

            panel.hidden = !open;
            document.querySelectorAll('[data-team-edit-target="' + panel.id + '"]').forEach(function (button) {
                if (button.classList.contains('bb-team-edit-toggle')) {
                    button.setAttribute('aria-expanded', open ? 'true' : 'false');
                    button.textContent = open ? closeLabel : editLabel;
                }
            });
        };

        document.querySelectorAll('.bb-team-edit-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                var panel = document.getElementById(button.getAttribute('data-team-edit-target') || '');
                setPanelState(panel, !!panel && panel.hidden);
            });
        });

        document.querySelectorAll('.bb-team-edit-cancel').forEach(function (button) {
            button.addEventListener('click', function () {
                var panel = document.getElementById(button.getAttribute('data-team-edit-target') || '');
                setPanelState(panel, false);
            });
        });
    })();
</script>
