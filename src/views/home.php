<?php

declare(strict_types=1);

/** @var string $enteredSlug */
/** @var bool $slugError */
?>
<section class="bb-welcome" aria-labelledby="welcome-title">
    <div class="bb-welcome-copy">
        <span class="bb-section-kicker"><?= $e('home.eyebrow') ?></span>
        <h1 id="welcome-title"><?= $e('home.title') ?></h1>
        <p><?= $e('home.subtitle') ?></p>
        <div class="bb-welcome-signal" aria-hidden="true">
            <span></span><span></span><span></span><span></span>
        </div>
    </div>

    <div class="bb-access-grid">
        <article class="bb-access-card bb-access-card-primary">
            <div>
                <span class="bb-access-index">01</span>
                <h2><?= $e('home.tournament_admin_title') ?></h2>
                <p><?= $e('home.tournament_admin_help') ?></p>
            </div>
            <form method="get" action="<?= htmlspecialchars($url('/'), ENT_QUOTES, 'UTF-8') ?>" class="bb-access-form">
                <label for="tournament_slug" class="form-label"><?= $e('home.tournament_slug') ?></label>
                <input
                    type="text"
                    name="tournament_slug"
                    id="tournament_slug"
                    class="form-control<?= $slugError ? ' is-invalid' : '' ?>"
                    value="<?= htmlspecialchars($enteredSlug, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="<?= $e('home.tournament_slug_placeholder') ?>"
                    maxlength="150"
                    pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                    autocapitalize="none"
                    autocomplete="off"
                    spellcheck="false"
                    aria-describedby="tournament-slug-help<?= $slugError ? ' tournament-slug-error' : '' ?>"
                    required
                >
                <div id="tournament-slug-help" class="form-text"><?= $e('home.tournament_admin_help') ?></div>
                <?php if ($slugError): ?>
                    <div id="tournament-slug-error" class="invalid-feedback d-block" role="alert"><?= $e('home.slug_error') ?></div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $e('home.open_tournament') ?></button>
            </form>
        </article>

        <article class="bb-access-card">
            <div>
                <span class="bb-access-index">02</span>
                <h2><?= $e('home.superadmin_title') ?></h2>
                <p><?= $e('home.superadmin_help') ?></p>
            </div>
            <a href="<?= htmlspecialchars($url('/admin/login'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-primary"><?= $e('home.superadmin_action') ?></a>
        </article>
    </div>
</section>
