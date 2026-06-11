<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
/** @var string $publicViewSettingsActionUrl */
/** @var string $publicDisplayUrl */
/** @var list<array{
 *     key: string,
 *     label: string,
 *     is_enabled: int,
 *     sort_order: int,
 *     path: string,
 *     direct_url: string
 * }> $publicScreenSettings */

$tournamentId = (int) ($tournament['id'] ?? 0);
$publicViewEnabled = (int) ($tournament['public_view_enabled'] ?? 0) > 0;
$autoplayEnabled = (int) ($tournament['autoplay_enabled'] ?? 1) > 0;
$rotationIntervalSeconds = (int) ($tournament['rotation_interval_seconds'] ?? 15);
$publicViewTheme = (string) ($tournament['public_view_theme'] ?? 'dark');
if (!in_array($publicViewTheme, ['dark', 'light'], true)) {
    $publicViewTheme = 'dark';
}
$publicTitleOverride = (string) ($tournament['public_title_override'] ?? '');
$publicDescription = (string) ($tournament['public_description'] ?? '');
$publicLogoPath = trim((string) ($tournament['public_logo_path'] ?? ''));
$publicMapUrl = (string) ($tournament['public_map_url'] ?? '');
$publicMapEmbedUrl = (string) ($tournament['public_map_embed_url'] ?? '');
$enabledScreenCount = 0;
foreach ($publicScreenSettings as $screen) {
    if ((int) ($screen['is_enabled'] ?? 0) > 0) {
        $enabledScreenCount++;
    }
}
$screenHelpText = [
    'overview' => $t('public_view.screen_help.overview'),
    'next_matches' => $t('public_view.screen_help.next_matches'),
    'standings' => $t('public_view.screen_help.standings'),
    'group_schedule' => $t('public_view.screen_help.group_schedule'),
    'knockout' => $t('public_view.screen_help.knockout'),
    'recent_results' => $t('public_view.screen_help.recent_results'),
];
$screenLabels = [
    'overview' => $t('public.screen.overview'),
    'next_matches' => $t('public.screen.next_matches'),
    'standings' => $t('public.screen.standings'),
    'group_schedule' => $t('public.screen.group_schedule'),
    'knockout' => $t('public.screen.knockout'),
    'recent_results' => $t('public.screen.recent_results'),
];
?>
<section class="bb-public-settings-shell">
    <header class="bb-workspace-header bb-public-settings-header">
        <div>
            <span class="bb-section-kicker"><?= $e('public_view.display_configuration') ?></span>
            <h2><?= $e('public_view.title') ?></h2>
            <p><?= $e('public_view.subtitle') ?></p>
        </div>
        <a href="<?= htmlspecialchars($publicDisplayUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary"><?= $e('public_view.open_display') ?></a>
    </header>

    <form method="post" enctype="multipart/form-data" action="<?= htmlspecialchars($publicViewSettingsActionUrl, ENT_QUOTES, 'UTF-8') ?>" class="bb-public-settings-form">
        <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
        <input type="hidden" name="return_section" value="public_view">
        <input type="hidden" name="public_view_form" value="general">

        <div class="bb-public-settings-grid">
            <section class="bb-display-control-card">
                <div class="bb-workspace-card-header">
                    <div>
                        <span class="bb-section-kicker"><?= $e('common.status') ?></span>
                        <h3><?= $e('public_view.display_controls') ?></h3>
                        <p><?= $e('public_view.display_controls_help') ?></p>
                    </div>
                    <span class="badge <?= $publicViewEnabled ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $publicViewEnabled ? $e('public_view.public_enabled') : $e('public_view.public_disabled') ?></span>
                </div>

                <div class="bb-display-control-grid">
                    <div class="bb-toggle-stack">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="public_view_enabled" name="public_view_enabled" value="1" <?= $publicViewEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="public_view_enabled"><?= $e('public_view.enable_public_view') ?></label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="autoplay_enabled" name="autoplay_enabled" value="1" <?= $autoplayEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="autoplay_enabled"><?= $e('public_view.enable_autoplay_rotation') ?></label>
                        </div>
                    </div>

                    <div class="bb-display-control-fields">
                        <div class="bb-field">
                            <label for="rotation_interval_seconds" class="form-label"><?= $e('public_view.rotation_interval') ?></label>
                            <input type="number" class="form-control" name="rotation_interval_seconds" id="rotation_interval_seconds" min="5" max="300" value="<?= $rotationIntervalSeconds ?>" required>
                            <div class="form-text"><?= $e('public_view.rotation_interval_help') ?></div>
                        </div>
                        <div class="bb-field">
                            <label for="public_view_theme" class="form-label"><?= $e('public_view.theme') ?></label>
                            <select class="form-select" name="public_view_theme" id="public_view_theme" required>
                                <option value="dark" <?= $publicViewTheme === 'dark' ? 'selected' : '' ?>><?= $e('public_view.theme_dark_broadcast') ?></option>
                                <option value="light" <?= $publicViewTheme === 'light' ? 'selected' : '' ?>><?= $e('public_view.theme_light_outdoor') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
            </section>

            <section class="bb-public-overview-card">
                <div class="bb-workspace-card-header">
                    <div>
                        <span class="bb-section-kicker"><?= $e('public_view.content') ?></span>
                        <h3><?= $e('public_view.overview_content') ?></h3>
                        <p><?= $e('public_view.overview_content_help') ?></p>
                    </div>
                </div>

                <div class="bb-public-overview-grid">
                    <div class="bb-field bb-field-full">
                        <label for="public_title_override" class="form-label"><?= $e('public_view.title_override') ?></label>
                        <input type="text" class="form-control" name="public_title_override" id="public_title_override" maxlength="200" value="<?= htmlspecialchars($publicTitleOverride, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= $e('public_view.title_override_placeholder') ?>">
                    </div>
                    <div class="bb-field bb-field-full">
                        <label for="public_description" class="form-label"><?= $e('public_view.description') ?></label>
                        <textarea class="form-control bb-large-textarea" name="public_description" id="public_description" rows="5" maxlength="3000" placeholder="<?= $e('public_view.description_placeholder') ?>"><?= htmlspecialchars($publicDescription, ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <div class="bb-field bb-field-full bb-map-field">
                        <label for="public_map_url" class="form-label"><?= $e('public_view.map_url') ?></label>
                        <input type="url" class="form-control bb-long-input" name="public_map_url" id="public_map_url" maxlength="500" value="<?= htmlspecialchars($publicMapUrl, ENT_QUOTES, 'UTF-8') ?>" placeholder="https://maps.google.com/...">
                        <div class="form-text"><?= $e('public_view.map_url_help') ?></div>
                    </div>
                    <div class="bb-field bb-field-full bb-map-field">
                        <label for="public_map_embed_url" class="form-label"><?= $e('public_view.map_embed') ?></label>
                        <textarea class="form-control bb-large-textarea bb-map-embed-textarea" name="public_map_embed_url" id="public_map_embed_url" rows="6" maxlength="5000" placeholder="<?= $e('public_view.map_embed_placeholder') ?>"><?= htmlspecialchars($publicMapEmbedUrl, ENT_QUOTES, 'UTF-8') ?></textarea>
                        <div class="form-text"><?= $e('public_view.map_embed_help') ?></div>
                    </div>
                </div>
            </section>

            <section class="bb-branding-card">
                <div class="bb-workspace-card-header">
                    <div>
                        <span class="bb-section-kicker"><?= $e('public_view.branding') ?></span>
                        <h3><?= $e('public_view.logo') ?></h3>
                        <p><?= $e('public_view.logo_help') ?></p>
                    </div>
                </div>

                <div class="bb-branding-layout">
                    <div class="bb-logo-preview-box">
                        <span class="bb-logo-preview-label"><?= $e('public_view.current_logo') ?></span>
                        <?php if ($publicLogoPath !== ''): ?>
                            <img src="<?= htmlspecialchars($url('/' . ltrim($publicLogoPath, '/')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= $e('public_view.current_logo_alt') ?>">
                        <?php else: ?>
                            <strong><?= $e('public_view.no_logo_uploaded') ?></strong>
                            <span><?= $e('public_view.optional_branding') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="bb-logo-upload-area">
                        <div>
                            <strong><?= $e('public_view.upload_new_logo') ?></strong>
                            <span><?= $e('public_view.upload_new_logo_help') ?></span>
                        </div>
                        <label for="public_logo" class="form-label"><?= $e('public_view.logo_file') ?></label>
                        <input type="file" class="form-control" name="public_logo" id="public_logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                        <div class="form-text"><?= $e('public_view.logo_file_help') ?></div>
                    </div>
                </div>
            </section>
        </div>

        <div class="bb-settings-savebar">
            <div>
                <strong><?= $e('public_view.save_display_settings') ?></strong>
                <span><?= $e('public_view.save_display_settings_help') ?></span>
            </div>
            <div class="bb-public-save-actions">
                <a href="<?= htmlspecialchars($publicDisplayUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary"><?= $e('public_view.open_display') ?></a>
                <button type="submit" class="btn btn-primary"><?= $e('public_view.save_settings') ?></button>
            </div>
        </div>
    </form>

    <section class="bb-public-screens-card">
        <form method="post" action="<?= htmlspecialchars($publicViewSettingsActionUrl, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
            <input type="hidden" name="return_section" value="public_view">
            <input type="hidden" name="public_view_form" value="screen_list">
            <input type="hidden" name="public_view_enabled" value="<?= $publicViewEnabled ? '1' : '0' ?>">
            <input type="hidden" name="autoplay_enabled" value="<?= $autoplayEnabled ? '1' : '0' ?>">
            <input type="hidden" name="rotation_interval_seconds" value="<?= $rotationIntervalSeconds ?>">
            <input type="hidden" name="public_view_theme" value="<?= htmlspecialchars($publicViewTheme, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="public_title_override" value="<?= htmlspecialchars($publicTitleOverride, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="public_description" value="<?= htmlspecialchars($publicDescription, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="public_map_url" value="<?= htmlspecialchars($publicMapUrl, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="public_map_embed_url" value="<?= htmlspecialchars($publicMapEmbedUrl, ENT_QUOTES, 'UTF-8') ?>">

            <div class="bb-workspace-card-header">
                <div>
                    <span class="bb-section-kicker"><?= $e('public_view.screens') ?></span>
                    <h3><?= $e('public_view.public_screens') ?></h3>
                    <p><?= $e('public_view.public_screens_help') ?></p>
                </div>
                <span class="bb-screen-count"><?= $e('public_view.enabled_count', ['enabled' => $enabledScreenCount, 'total' => count($publicScreenSettings)]) ?></span>
            </div>

            <?php if (count($publicScreenSettings) === 0): ?>
                <div class="bb-empty-state"><?= $e('public_view.no_screens_configured') ?></div>
            <?php else: ?>
                <div class="bb-public-screen-list">
                    <?php foreach ($publicScreenSettings as $screen): ?>
                        <?php
                        $screenKey = (string) ($screen['key'] ?? '');
                        $helpText = (string) ($screenHelpText[$screenKey] ?? '');
                        $screenLabel = (string) ($screenLabels[$screenKey] ?? (string) $screen['label']);
                        $screenEnabled = (int) ($screen['is_enabled'] ?? 0) > 0;
                        $directUrl = (string) ($screen['direct_url'] ?? '');
                        ?>
                        <div class="bb-public-screen-row">
                            <div class="bb-public-screen-name">
                                <strong><?= htmlspecialchars($screenLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($helpText !== ''): ?>
                                    <span
                                        class="bb-info-pill"
                                        data-bs-toggle="tooltip"
                                        data-bs-placement="top"
                                        data-bs-title="<?= htmlspecialchars($helpText, ENT_QUOTES, 'UTF-8') ?>"
                                        aria-label="<?= $e('public_view.screen_help_label') ?>"
                                    >i</span>
                                <?php endif; ?>
                            </div>

                            <div class="bb-public-screen-enabled">
                                <input type="hidden" name="screen_enabled[<?= htmlspecialchars($screenKey, ENT_QUOTES, 'UTF-8') ?>]" value="0">
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="screen_enabled_<?= htmlspecialchars($screenKey, ENT_QUOTES, 'UTF-8') ?>" name="screen_enabled[<?= htmlspecialchars($screenKey, ENT_QUOTES, 'UTF-8') ?>]" value="1" <?= $screenEnabled ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="screen_enabled_<?= htmlspecialchars($screenKey, ENT_QUOTES, 'UTF-8') ?>"><?= $e('common.enabled') ?></label>
                                </div>
                            </div>

                            <div class="bb-public-screen-order">
                                <label class="form-label" for="screen_order_<?= htmlspecialchars($screenKey, ENT_QUOTES, 'UTF-8') ?>"><?= $e('common.order') ?></label>
                                <input type="number" class="form-control form-control-sm" min="1" max="99" id="screen_order_<?= htmlspecialchars($screenKey, ENT_QUOTES, 'UTF-8') ?>" name="screen_order[<?= htmlspecialchars($screenKey, ENT_QUOTES, 'UTF-8') ?>]" value="<?= (int) $screen['sort_order'] ?>">
                                <span><?= $e('public_view.one_equals_first') ?></span>
                            </div>

                            <code class="bb-direct-link"><?= htmlspecialchars($directUrl, ENT_QUOTES, 'UTF-8') ?></code>

                            <a class="btn btn-sm btn-outline-primary bb-public-screen-open" target="_blank" rel="noopener" href="<?= htmlspecialchars($directUrl, ENT_QUOTES, 'UTF-8') ?>"><?= $e('common.open') ?></a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="bb-screen-savebar">
                    <span><?= $e('public_view.screen_save_note') ?></span>
                    <button type="submit" class="btn btn-outline-primary btn-sm"><?= $e('public_view.save_screen_list') ?></button>
                </div>
            <?php endif; ?>
        </form>
    </section>
</section>
