<?php

declare(strict_types=1);

namespace App\Support;

use DateInterval;
use DateTimeImmutable;
use RuntimeException;

final class GroupStageScheduler
{
    /**
     * @param array<int, list<array{id: int, name: string}>> $teamsByGroupId
     * @return list<array{
     *     group_id: int,
     *     team_a_id: int,
     *     team_b_id: int,
     *     court_number: int,
     *     schedule_order: int,
     *     planned_start: string
     * }>
     */
    public function schedule(
        array $teamsByGroupId,
        int $courtCount,
        int $matchDurationMinutes,
        DateTimeImmutable $startDateTime
    ): array {
        if ($courtCount < 1 || $matchDurationMinutes < 1) {
            throw new RuntimeException('Court count and match duration must be greater than zero.');
        }

        ksort($teamsByGroupId, SORT_NUMERIC);

        $groups = [];
        $remainingMatchCount = 0;
        foreach ($teamsByGroupId as $groupId => $groupTeams) {
            usort(
                $groupTeams,
                static function (array $a, array $b): int {
                    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
                        ?: ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
                }
            );

            $rounds = $this->roundRobinRounds((int) $groupId, $groupTeams);
            $totalMatches = array_sum(array_map('count', $rounds));
            if ($totalMatches < 1) {
                continue;
            }

            $groups[(int) $groupId] = [
                'rounds' => $rounds,
                'round_index' => 0,
                'scheduled_matches' => 0,
                'total_matches' => $totalMatches,
                'last_scheduled_slot' => null,
            ];
            $remainingMatchCount += $totalMatches;
        }

        $schedule = [];
        $lastSlotByTeam = [];
        $slotIndex = 0;
        $scheduleOrder = 1;

        while ($remainingMatchCount > 0) {
            $teamsUsedInSlot = [];
            $assignedInSlot = 0;
            $plannedStart = $this->plannedStartAtSlot(
                $startDateTime,
                $slotIndex,
                $matchDurationMinutes
            )->format('Y-m-d H:i:s');

            for ($court = 1; $court <= $courtCount; $court++) {
                $candidate = $this->bestCandidate(
                    $groups,
                    $teamsUsedInSlot,
                    $lastSlotByTeam,
                    $slotIndex
                );
                if ($candidate === null) {
                    break;
                }

                $groupId = $candidate['group_id'];
                $roundIndex = $candidate['round_index'];
                $matchIndex = $candidate['match_index'];
                $match = $groups[$groupId]['rounds'][$roundIndex][$matchIndex];
                array_splice($groups[$groupId]['rounds'][$roundIndex], $matchIndex, 1);

                $teamAId = (int) $match['team_a_id'];
                $teamBId = (int) $match['team_b_id'];
                $schedule[] = [
                    'group_id' => $groupId,
                    'team_a_id' => $teamAId,
                    'team_b_id' => $teamBId,
                    'court_number' => $court,
                    'schedule_order' => $scheduleOrder,
                    'planned_start' => $plannedStart,
                ];

                $groups[$groupId]['scheduled_matches']++;
                $groups[$groupId]['last_scheduled_slot'] = $slotIndex;
                $teamsUsedInSlot[$teamAId] = true;
                $teamsUsedInSlot[$teamBId] = true;
                $lastSlotByTeam[$teamAId] = $slotIndex;
                $lastSlotByTeam[$teamBId] = $slotIndex;
                $remainingMatchCount--;
                $assignedInSlot++;
                $scheduleOrder++;
            }

            if ($assignedInSlot === 0) {
                throw new RuntimeException('Unable to place the remaining group-stage matches.');
            }

            $slotIndex++;
        }

        return $schedule;
    }

    /**
     * @param list<array{id: int, name: string}> $groupTeams
     * @return list<list<array{
     *     group_id: int,
     *     team_a_id: int,
     *     team_b_id: int,
     *     round_number: int,
     *     match_position: int
     * }>>
     */
    private function roundRobinRounds(int $groupId, array $groupTeams): array
    {
        $teamIds = [];
        $teamPositions = [];
        foreach ($groupTeams as $position => $team) {
            $teamId = (int) ($team['id'] ?? 0);
            if ($teamId <= 0 || isset($teamPositions[$teamId])) {
                continue;
            }

            $teamIds[] = $teamId;
            $teamPositions[$teamId] = $position;
        }

        if (count($teamIds) < 2) {
            return [];
        }
        if (count($teamIds) % 2 !== 0) {
            $teamIds[] = null;
        }

        $rounds = [];
        $rotation = $teamIds;
        $participantCount = count($rotation);
        $roundCount = $participantCount - 1;
        $matchesPerRound = intdiv($participantCount, 2);

        for ($roundIndex = 0; $roundIndex < $roundCount; $roundIndex++) {
            $round = [];
            for ($position = 0; $position < $matchesPerRound; $position++) {
                $leftTeamId = $rotation[$position] ?? null;
                $rightTeamId = $rotation[$participantCount - 1 - $position] ?? null;
                if (!is_int($leftTeamId) || !is_int($rightTeamId)) {
                    continue;
                }

                $leftPosition = $teamPositions[$leftTeamId];
                $rightPosition = $teamPositions[$rightTeamId];
                $teamAId = $leftPosition < $rightPosition ? $leftTeamId : $rightTeamId;
                $teamBId = $leftPosition < $rightPosition ? $rightTeamId : $leftTeamId;
                $round[] = [
                    'group_id' => $groupId,
                    'team_a_id' => $teamAId,
                    'team_b_id' => $teamBId,
                    'round_number' => $roundIndex + 1,
                    'match_position' => $position + 1,
                ];
            }

            $rounds[] = $round;
            $fixedTeamId = array_shift($rotation);
            $rotatedTeamId = array_pop($rotation);
            array_unshift($rotation, $fixedTeamId, $rotatedTeamId);
        }

        return $rounds;
    }

    /**
     * @param array<int, array{
     *     rounds: list<list<array{
     *         group_id: int,
     *         team_a_id: int,
     *         team_b_id: int,
     *         round_number: int,
     *         match_position: int
     *     }>>,
     *     round_index: int,
     *     scheduled_matches: int,
     *     total_matches: int,
     *     last_scheduled_slot: int|null
     * }> $groups
     * @param array<int, bool> $teamsUsedInSlot
     * @param array<int, int> $lastSlotByTeam
     * @return array{group_id: int, round_index: int, match_index: int}|null
     */
    private function bestCandidate(
        array &$groups,
        array $teamsUsedInSlot,
        array $lastSlotByTeam,
        int $slotIndex
    ): ?array {
        $best = null;
        $groupCount = count($groups);
        $groupRotationOffset = $groupCount > 0 ? $slotIndex % $groupCount : 0;
        $groupPosition = 0;

        foreach ($groups as $groupId => &$group) {
            while (
                isset($group['rounds'][$group['round_index']])
                && count($group['rounds'][$group['round_index']]) === 0
            ) {
                $group['round_index']++;
            }

            $roundIndex = $group['round_index'];
            $round = $group['rounds'][$roundIndex] ?? [];
            foreach ($round as $matchIndex => $match) {
                $teamAId = (int) ($match['team_a_id'] ?? 0);
                $teamBId = (int) ($match['team_b_id'] ?? 0);
                if (
                    $teamAId <= 0
                    || $teamBId <= 0
                    || isset($teamsUsedInSlot[$teamAId])
                    || isset($teamsUsedInSlot[$teamBId])
                ) {
                    continue;
                }

                $restA = isset($lastSlotByTeam[$teamAId])
                    ? $slotIndex - $lastSlotByTeam[$teamAId]
                    : $slotIndex + 1;
                $restB = isset($lastSlotByTeam[$teamBId])
                    ? $slotIndex - $lastSlotByTeam[$teamBId]
                    : $slotIndex + 1;
                $lastScheduledSlot = $group['last_scheduled_slot'];
                $groupRecency = is_int($lastScheduledSlot)
                    ? $slotIndex - $lastScheduledSlot
                    : $slotIndex + 1;

                $candidate = [
                    'group_id' => (int) $groupId,
                    'round_index' => $roundIndex,
                    'match_index' => $matchIndex,
                    'scheduled_matches' => $group['scheduled_matches'],
                    'total_matches' => $group['total_matches'],
                    'minimum_rest' => min($restA, $restB),
                    'combined_rest' => $restA + $restB,
                    'group_recency' => $groupRecency,
                    'group_rotation_rank' => ($groupPosition - $groupRotationOffset + $groupCount) % $groupCount,
                    'round_number' => (int) ($match['round_number'] ?? 0),
                    'match_position' => (int) ($match['match_position'] ?? 0),
                    'team_a_id' => $teamAId,
                    'team_b_id' => $teamBId,
                ];

                if ($best === null || $this->candidateComesFirst($candidate, $best)) {
                    $best = $candidate;
                }
            }
            $groupPosition++;
        }
        unset($group);

        if ($best === null) {
            return null;
        }

        return [
            'group_id' => $best['group_id'],
            'round_index' => $best['round_index'],
            'match_index' => $best['match_index'],
        ];
    }

    /**
     * @param array<string, int> $candidate
     * @param array<string, int> $currentBest
     */
    private function candidateComesFirst(array $candidate, array $currentBest): bool
    {
        $candidateProgress = $candidate['scheduled_matches'] * $currentBest['total_matches'];
        $bestProgress = $currentBest['scheduled_matches'] * $candidate['total_matches'];
        if ($candidateProgress !== $bestProgress) {
            return $candidateProgress < $bestProgress;
        }

        foreach (['minimum_rest', 'combined_rest', 'group_recency'] as $descendingKey) {
            if ($candidate[$descendingKey] !== $currentBest[$descendingKey]) {
                return $candidate[$descendingKey] > $currentBest[$descendingKey];
            }
        }

        foreach (
            ['group_rotation_rank', 'group_id', 'round_number', 'match_position', 'team_a_id', 'team_b_id']
            as $ascendingKey
        ) {
            if ($candidate[$ascendingKey] !== $currentBest[$ascendingKey]) {
                return $candidate[$ascendingKey] < $currentBest[$ascendingKey];
            }
        }

        return false;
    }

    private function plannedStartAtSlot(
        DateTimeImmutable $startDateTime,
        int $slotIndex,
        int $matchDurationMinutes
    ): DateTimeImmutable {
        $minutesToAdd = max(0, $slotIndex) * $matchDurationMinutes;
        return $startDateTime->add(new DateInterval('PT' . $minutesToAdd . 'M'));
    }
}
