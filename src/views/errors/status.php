<?php

declare(strict_types=1);

/** @var int $statusCode */
/** @var string $statusTitleKey */
/** @var string $statusMessageKey */
?>
<section class="bb-error-state" aria-labelledby="error-title">
    <span class="bb-error-code"><?= (int) $statusCode ?></span>
    <div>
        <span class="bb-section-kicker"><?= htmlspecialchars((string) $appName, ENT_QUOTES, 'UTF-8') ?></span>
        <h1 id="error-title"><?= $e($statusTitleKey) ?></h1>
        <p><?= $e($statusMessageKey) ?></p>
        <a href="<?= htmlspecialchars($url('/'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary"><?= $e('error.back_home') ?></a>
    </div>
</section>
