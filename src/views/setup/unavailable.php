<?php

declare(strict_types=1);

/** @var string $message */
?>
<div class="alert alert-warning" role="alert">
    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
</div>
<div>
    <a href="<?= htmlspecialchars($url('/'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary"><?= $e('common.back') ?></a>
</div>
