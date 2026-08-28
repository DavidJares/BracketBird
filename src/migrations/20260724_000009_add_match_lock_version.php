<?php

declare(strict_types=1);

return [
    'version' => '20260724_000009_add_match_lock_version',
    'description' => 'Add optimistic locking version to matches.',
    'statements' => [
        "ALTER TABLE matches
         ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER updated_at",
    ],
];
