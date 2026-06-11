<?php

declare(strict_types=1);
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-7 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-3"><?= $e('setup.initial_superadmin_setup') ?></h1>
                <p class="text-muted"><?= $e('setup.available_before_first_account') ?></p>
                <form method="post" action="<?= htmlspecialchars($url('/setup'), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label for="username" class="form-label"><?= $e('auth.username') ?></label>
                        <input type="text" name="username" id="username" class="form-control" required maxlength="100" autocomplete="username">
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label"><?= $e('auth.password') ?></label>
                        <input type="password" name="password" id="password" class="form-control" required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><?= $e('setup.create_superadmin') ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
