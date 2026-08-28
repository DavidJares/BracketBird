<?php

declare(strict_types=1);

/** @var string $tournamentName */
/** @var string $slug */
?>
<section class="bb-auth-card" aria-labelledby="tournament-login-title">
    <div class="bb-auth-intro">
        <span class="bb-section-kicker"><?= $e('auth.secure_access') ?></span>
        <h1 id="tournament-login-title"><?= $e('auth.tournament_admin_login') ?></h1>
        <div class="bb-auth-tournament"><?= htmlspecialchars($tournamentName, ENT_QUOTES, 'UTF-8') ?></div>
        <p><?= $e('auth.tournament_admin_help') ?></p>
    </div>
    <form method="post" action="<?= htmlspecialchars($url('/tournament/' . $slug . '/login'), ENT_QUOTES, 'UTF-8') ?>" class="bb-stack-form">
        <div>
            <label for="password" class="form-label"><?= $e('auth.tournament_password') ?></label>
            <input type="password" name="password" id="password" class="form-control" required maxlength="72" autocomplete="current-password" autofocus>
        </div>
        <button type="submit" class="btn btn-primary w-100"><?= $e('auth.sign_in') ?></button>
    </form>
    <a href="<?= htmlspecialchars($url('/'), ENT_QUOTES, 'UTF-8') ?>" class="bb-auth-back"><?= $e('auth.back_home') ?></a>
</section>
