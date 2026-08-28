<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
/** @var list<array<string, mixed>> $teams */
/** @var string $createTeamActionUrl */
/** @var string $updateTeamActionUrl */
/** @var string $deleteTeamActionUrl */
/** @var bool $hasAnyMatches */

$tournamentId = (int) ($tournament['id'] ?? 0);
$stateVersion = max(0, (int) ($tournament['state_version'] ?? 0));
$hasAnyMatches = (bool) ($hasAnyMatches ?? false);
$teamLimitReached = count($teams) >= 64;
$deleteConfirmation = $hasAnyMatches
    ? $t('teams.delete_team_with_matches_confirm')
    : $t('teams.delete_team_confirm');
?>
<div class="bb-workspace">
    <div class="bb-workspace-header">
        <div>
            <div class="bb-page-kicker"><?= $e('teams.participants') ?></div>
            <h2><?= $e('teams.title') ?></h2>
            <p><?= $e('teams.subtitle') ?></p>
        </div>
        <span class="bb-status-pill"><?= $e('teams.count', ['count' => count($teams)]) ?></span>
    </div>

    <?php if ($hasAnyMatches): ?>
        <div class="alert alert-warning" role="alert">
            <?= $e('teams.match_reset_warning') ?>
        </div>
    <?php endif; ?>

    <div class="bb-workspace-grid bb-workspace-grid-narrow">
        <aside class="bb-workspace-side">
            <section class="bb-workspace-card">
                <div class="bb-workspace-card-header">
                    <div>
                        <span class="bb-settings-eyebrow"><?= $e('teams.add_participant') ?></span>
                        <h3><?= $e('teams.add_team') ?></h3>
                    </div>
                </div>
                <form method="post" action="<?= htmlspecialchars($createTeamActionUrl, ENT_QUOTES, 'UTF-8') ?>" class="bb-stack-form">
                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                    <input type="hidden" name="return_section" value="teams">
                    <div>
                        <label for="team_name_new" class="form-label"><?= $e('teams.team_name') ?></label>
                        <input type="text" name="team_name" id="team_name_new" class="form-control" required maxlength="150" <?= $teamLimitReached ? 'disabled' : '' ?>>
                    </div>
                    <div>
                        <label for="team_description_new" class="form-label"><?= $e('teams.description_optional') ?></label>
                        <textarea name="description" id="team_description_new" class="form-control" rows="3" maxlength="1000" <?= $teamLimitReached ? 'disabled' : '' ?>></textarea>
                    </div>
                    <?php if ($teamLimitReached): ?>
                        <div class="form-text text-warning"><?= $e('teams.team_limit_reached') ?></div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary w-100" <?= $teamLimitReached ? 'disabled' : '' ?>><?= $e('teams.add_team') ?></button>
                </form>
            </section>
        </aside>

        <div class="bb-workspace-main">
            <section class="bb-workspace-card">
                <div class="bb-workspace-card-header">
                    <div>
                        <span class="bb-settings-eyebrow"><?= $e('teams.roster') ?></span>
                        <h3><?= $e('teams.existing_teams') ?></h3>
                    </div>
                </div>
                <?php if (count($teams) === 0): ?>
                    <div class="bb-empty-state"><?= $e('teams.no_teams_yet') ?></div>
                <?php endif; ?>
                <div class="bb-team-list">
                    <?php foreach ($teams as $team): ?>
                        <?php
                        $teamId = (int) ($team['id'] ?? 0);
                        $teamName = (string) ($team['team_name'] ?? '');
                        $description = (string) ($team['description'] ?? '');
                        ?>
                        <div class="bb-team-row bb-team-row-editing">
                            <div class="bb-team-row-main">
                                <div class="bb-team-name" title="<?= htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php if ($description !== ''): ?>
                                    <div class="bb-team-description"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </div>
                            <details class="bb-team-edit">
                                <summary><?= $e('common.edit') ?></summary>
                                <div class="bb-team-edit-panel">
                                    <form method="post" action="<?= htmlspecialchars($updateTeamActionUrl, ENT_QUOTES, 'UTF-8') ?>" class="bb-team-edit-form">
                                        <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                                        <input type="hidden" name="state_version" value="<?= $stateVersion ?>">
                                        <input type="hidden" name="team_id" value="<?= $teamId ?>">
                                        <input type="hidden" name="return_section" value="teams">
                                        <div>
                                            <label class="form-label"><?= $e('teams.team_name') ?></label>
                                            <input type="text" name="team_name" class="form-control form-control-sm" required maxlength="150" value="<?= htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8') ?>">
                                        </div>
                                        <div>
                                            <label class="form-label"><?= $e('teams.description') ?></label>
                                            <textarea name="description" class="form-control form-control-sm" rows="2" maxlength="1000"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></textarea>
                                        </div>
                                        <div class="bb-team-edit-actions">
                                            <button type="submit" class="btn btn-sm btn-primary"><?= $e('teams.save_team') ?></button>
                                        </div>
                                    </form>
                                    <form method="post" action="<?= htmlspecialchars($deleteTeamActionUrl, ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode($deleteConfirmation), ENT_QUOTES, 'UTF-8') ?>);" class="bb-team-delete-form">
                                        <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                                        <input type="hidden" name="state_version" value="<?= $stateVersion ?>">
                                        <input type="hidden" name="team_id" value="<?= $teamId ?>">
                                        <input type="hidden" name="confirm_delete" value="1">
                                        <input type="hidden" name="return_section" value="teams">
                                        <?php if ($hasAnyMatches): ?>
                                            <input type="hidden" name="confirm_reset_matches" value="1">
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><?= $e('teams.delete_team') ?></button>
                                    </form>
                                </div>
                            </details>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
</div>
