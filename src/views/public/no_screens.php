<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
?>
<div class="d-flex align-items-center justify-content-center min-vh-100">
    <div class="card shadow-sm" style="max-width: 620px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-2"><?= htmlspecialchars((string) ($tournament['name'] ?? $t('nav.tournament')), ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="mb-0 text-muted"><?= $e('public.no_enabled_screens') ?></p>
        </div>
    </div>
</div>
