<?php

declare(strict_types=1);

return [
    'version' => '20260724_000010_add_match_tournament_stage_status_index',
    'description' => 'Add composite match lookup index for tournament stage and status.',
    'statements' => [
        "ALTER TABLE matches
         ADD KEY idx_matches_tournament_stage_status (tournament_id, stage, status)",
    ],
];
