<?php

declare(strict_types=1);

/** @var string $tournamentName */
/** @var string $slug */
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-7 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-2"><?= $e('auth.tournament_admin_login') ?></h1>
                <p class="text-muted mb-3">
                    <?= htmlspecialchars($tournamentName, ENT_QUOTES, 'UTF-8') ?>
                </p>
                <form method="post" action="<?= htmlspecialchars($url('/tournament/' . $slug . '/login'), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label for="password" class="form-label"><?= $e('auth.tournament_password') ?></label>
                        <input type="password" name="password" id="password" class="form-control" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><?= $e('auth.sign_in') ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
