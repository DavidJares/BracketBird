<?php

declare(strict_types=1);
?>
<section class="bb-auth-card bb-auth-card-wide" aria-labelledby="setup-title">
    <div class="bb-auth-intro">
        <span class="bb-section-kicker"><?= $e('setup.eyebrow') ?></span>
        <h1 id="setup-title"><?= $e('setup.title') ?></h1>
        <p><?= $e('setup.help') ?></p>
    </div>
    <form method="post" action="<?= htmlspecialchars($url('/setup'), ENT_QUOTES, 'UTF-8') ?>" class="bb-stack-form">
        <div>
            <label for="setup_token" class="form-label">Setup token</label>
            <input type="password" name="setup_token" id="setup_token" class="form-control" required minlength="32" maxlength="1024" autocomplete="off" autofocus>
        </div>
        <div>
            <label for="username" class="form-label"><?= $e('auth.username') ?></label>
            <input type="text" name="username" id="username" class="form-control" required maxlength="100" autocomplete="username">
        </div>
        <div>
            <label for="password" class="form-label"><?= $e('auth.password') ?></label>
            <input type="password" name="password" id="password" class="form-control" required minlength="8" maxlength="72" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary w-100"><?= $e('setup.create_superadmin') ?></button>
    </form>
</section>
