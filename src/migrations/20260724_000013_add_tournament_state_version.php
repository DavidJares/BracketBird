<?php

declare(strict_types=1);

return [
    'version' => '20260724_000013_add_tournament_state_version',
    'description' => 'Add a monotonic tournament state version for generated-stage consistency.',
    'statements' => [
        "ALTER TABLE tournaments
         ADD COLUMN state_version BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER updated_at",
    ],
];
