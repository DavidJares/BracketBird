<?php

declare(strict_types=1);

/** @var string $qrUrl */
/** @var string $currentUrl */
?>
<div class="qr-box bb-public-qr bb-public-qr-desktop">
    <div class="bb-public-qr-image">
        <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $e('public.qr_alt') ?>" class="img-fluid">
    </div>
    <div class="bb-public-qr-copy">
        <div><?= $e('public.open_on_phone') ?></div>
        <a href="<?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?></a>
    </div>
</div>
<details class="bb-public-mobile-qr">
    <summary><?= $e('public.show_qr') ?></summary>
    <div class="bb-public-mobile-qr-panel">
        <div class="bb-public-qr-image">
            <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $e('public.qr_alt') ?>" class="img-fluid">
        </div>
        <a href="<?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?></a>
    </div>
</details>
