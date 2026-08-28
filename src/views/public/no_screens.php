<?php

declare(strict_types=1);

/** @var array<string, mixed> $tournament */
?>
<section class="bb-public-state" aria-labelledby="public-no-screens-title">
    <span class="bb-public-state-mark" aria-hidden="true">BB</span>
    <div>
        <span class="bb-public-kicker"><?= $e('public_view.screens') ?></span>
        <h1 id="public-no-screens-title"><?= htmlspecialchars((string) ($tournament['name'] ?? $t('nav.tournament')), ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= $e('public.no_enabled_screens') ?></p>
    </div>
</section>
