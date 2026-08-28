<?php

declare(strict_types=1);
?>
<section class="bb-auth-card" aria-labelledby="superadmin-login-title">
    <div class="bb-auth-intro">
        <span class="bb-section-kicker"><?= $e('auth.secure_access') ?></span>
        <h1 id="superadmin-login-title"><?= $e('auth.superadmin_login') ?></h1>
        <p><?= $e('auth.superadmin_help') ?></p>
    </div>
    <form method="post" action="<?= htmlspecialchars($url('/admin/login'), ENT_QUOTES, 'UTF-8') ?>" class="bb-stack-form">
        <div>
            <label for="username" class="form-label"><?= $e('auth.username') ?></label>
            <input type="text" name="username" id="username" class="form-control" required maxlength="100" autocomplete="username" autofocus>
        </div>
        <div>
            <label for="password" class="form-label"><?= $e('auth.password') ?></label>
            <input type="password" name="password" id="password" class="form-control" required maxlength="72" autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary w-100"><?= $e('auth.sign_in') ?></button>
    </form>
    <a href="<?= htmlspecialchars($url('/'), ENT_QUOTES, 'UTF-8') ?>" class="bb-auth-back"><?= $e('auth.back_home') ?></a>
</section>
