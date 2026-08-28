<?php

declare(strict_types=1);

return [
    'version' => '20260724_000011_add_match_tournament_stage_order_indexes',
    'description' => 'Add composite match ordering indexes for schedules and brackets.',
    'statements' => [
        "ALTER TABLE matches
         ADD KEY idx_matches_tournament_stage_schedule (tournament_id, stage, schedule_order),
         ADD KEY idx_matches_tournament_stage_bracket (tournament_id, stage, bracket_position)",
    ],
];
