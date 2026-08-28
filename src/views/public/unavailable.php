<?php

declare(strict_types=1);

/** @var string $tournamentName */
?>
<section class="bb-public-state" aria-labelledby="public-unavailable-title">
    <span class="bb-public-state-mark" aria-hidden="true">BB</span>
    <div>
        <span class="bb-public-kicker"><?= $e('public_view.public_disabled') ?></span>
        <h1 id="public-unavailable-title"><?= htmlspecialchars($tournamentName, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= $e('public.unavailable') ?></p>
    </div>
</section>
