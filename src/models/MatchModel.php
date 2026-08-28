<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class MatchModel
{
    public const WRITE_SAVED = 'saved';
    public const WRITE_IDEMPOTENT = 'idempotent';
    public const WRITE_STALE = 'stale';
    public const WRITE_INVALID = 'invalid';
    public const WRITE_REQUIRES_KNOCKOUT_RESET = 'requires_knockout_reset';
    public const WRITE_REQUIRES_DEPENDENT_RESET = 'requires_dependent_reset';

    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function groupMatchCountForTournament(int $tournamentId): int
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM matches
             WHERE tournament_id = :tournament_id
               AND stage = :stage'
        );
        $statement->execute([
            'tournament_id' => $tournamentId,
            'stage' => 'group',
        ]);

        return (int) $statement->fetchColumn();
    }

    public function knockoutMatchCountForTournament(int $tournamentId): int
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM matches
             WHERE tournament_id = :tournament_id
               AND stage = :stage'
        );
        $statement->execute([
            'tournament_id' => $tournamentId,
            'stage' => 'knockout',
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function inProgressMatchesForTournament(int $tournamentId): array
    {
        return $this->matchesByStatusForTournament($tournamentId, 'in_progress', 20);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function nextScheduledMatchesForTournament(int $tournamentId, int $limit = 20): array
    {
        return $this->matchesByStatusForTournament($tournamentId, 'scheduled', max(1, $limit));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentFinishedMatchesForTournament(int $tournamentId, int $limit = 20): array
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT
                m.id,
                m.stage,
                m.group_id,
                g.name AS group_name,
                m.round_name,
                m.bracket_position,
                m.team_a_id,
                ta.team_name AS team_a_name,
                m.team_b_id,
                tb.team_name AS team_b_name,
                m.team_a_source,
                m.team_b_source,
                m.court_number,
                m.schedule_order,
                m.planned_start,
                m.status,
                m.winner_team_id,
                m.sets_summary_a,
                m.sets_summary_b,
                m.lock_version,
                m.updated_at,
                (
                    SELECT GROUP_CONCAT(CONCAT(ms.score_a, \':\', ms.score_b) ORDER BY ms.set_number ASC SEPARATOR \', \')
                    FROM match_sets ms
                    WHERE ms.match_id = m.id
                ) AS set_scores_summary
             FROM matches m
             LEFT JOIN tournament_groups g ON g.id = m.group_id
             LEFT JOIN teams ta ON ta.id = m.team_a_id
             LEFT JOIN teams tb ON tb.id = m.team_b_id
             WHERE m.tournament_id = :tournament_id
               AND m.status = :status
             ORDER BY
                CASE WHEN m.updated_at IS NULL THEN 1 ELSE 0 END,
                m.updated_at DESC,
                m.id DESC
             LIMIT :limit_rows'
        );
        $statement->bindValue(':tournament_id', $tournamentId, PDO::PARAM_INT);
        $statement->bindValue(':status', 'finished', PDO::PARAM_STR);
        $statement->bindValue(':limit_rows', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findGroupMatchDetailForTournament(int $tournamentId, int $matchId): ?array
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT
                m.id,
                m.tournament_id,
                m.group_id,
                g.name AS group_name,
                m.team_a_id,
                ta.team_name AS team_a_name,
                m.team_b_id,
                tb.team_name AS team_b_name,
                m.court_number,
                m.schedule_order,
                m.planned_start,
                m.status,
                m.winner_team_id,
                m.sets_summary_a,
                m.sets_summary_b,
                m.lock_version,
                t.group_stage_mode AS match_mode
             FROM matches m
             INNER JOIN tournaments t ON t.id = m.tournament_id
             LEFT JOIN tournament_groups g ON g.id = m.group_id
             LEFT JOIN teams ta ON ta.id = m.team_a_id
             LEFT JOIN teams tb ON tb.id = m.team_b_id
             WHERE m.id = :match_id
               AND m.tournament_id = :tournament_id
               AND m.stage = :stage
             LIMIT 1'
        );
        $statement->execute([
            'match_id' => $matchId,
            'tournament_id' => $tournamentId,
            'stage' => 'group',
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findKnockoutMatchDetailForTournament(int $tournamentId, int $matchId): ?array
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT
                m.id,
                m.tournament_id,
                m.round_name,
                m.bracket_position,
                m.team_a_id,
                ta.team_name AS team_a_name,
                m.team_b_id,
                tb.team_name AS team_b_name,
                m.team_a_source,
                m.team_b_source,
                m.court_number,
                m.planned_start,
                m.status,
                m.winner_team_id,
                m.sets_summary_a,
                m.sets_summary_b,
                m.lock_version,
                t.knockout_mode AS match_mode
             FROM matches m
             INNER JOIN tournaments t ON t.id = m.tournament_id
             LEFT JOIN teams ta ON ta.id = m.team_a_id
             LEFT JOIN teams tb ON tb.id = m.team_b_id
             WHERE m.id = :match_id
               AND m.tournament_id = :tournament_id
               AND m.stage = :stage
             LIMIT 1'
        );
        $statement->execute([
            'match_id' => $matchId,
            'tournament_id' => $tournamentId,
            'stage' => 'knockout',
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function groupMatchesForTournament(int $tournamentId): array
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT
                m.id,
                m.group_id,
                g.name AS group_name,
                m.team_a_id,
                ta.team_name AS team_a_name,
                m.team_b_id,
                tb.team_name AS team_b_name,
                m.court_number,
                m.schedule_order,
                m.planned_start,
                m.status,
                m.winner_team_id,
                m.sets_summary_a,
                m.sets_summary_b,
                m.lock_version,
                (
                    SELECT GROUP_CONCAT(CONCAT(ms.score_a, \':\', ms.score_b) ORDER BY ms.set_number ASC SEPARATOR \', \')
                    FROM match_sets ms
                    WHERE ms.match_id = m.id
                ) AS set_scores_summary
             FROM matches m
             LEFT JOIN tournament_groups g ON g.id = m.group_id
             LEFT JOIN teams ta ON ta.id = m.team_a_id
             LEFT JOIN teams tb ON tb.id = m.team_b_id
             WHERE m.tournament_id = :tournament_id
               AND m.stage = :stage
             ORDER BY
                CASE WHEN m.schedule_order IS NULL THEN 1 ELSE 0 END,
                m.schedule_order ASC,
                CASE WHEN m.planned_start IS NULL THEN 1 ELSE 0 END,
                m.planned_start ASC,
                m.id ASC'
        );
        $statement->execute([
            'tournament_id' => $tournamentId,
            'stage' => 'group',
        ]);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function knockoutMatchesForTournament(int $tournamentId): array
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT
                m.id,
                m.round_name,
                m.bracket_position,
                m.team_a_id,
                ta.team_name AS team_a_name,
                m.team_b_id,
                tb.team_name AS team_b_name,
                m.team_a_source,
                m.team_b_source,
                m.court_number,
                m.planned_start,
                m.status,
                m.winner_team_id,
                m.sets_summary_a,
                m.sets_summary_b,
                m.lock_version,
                (
                    SELECT GROUP_CONCAT(CONCAT(ms.score_a, \':\', ms.score_b) ORDER BY ms.set_number ASC SEPARATOR \', \')
                    FROM match_sets ms
                    WHERE ms.match_id = m.id
                ) AS set_scores_summary
             FROM matches m
             LEFT JOIN teams ta ON ta.id = m.team_a_id
             LEFT JOIN teams tb ON tb.id = m.team_b_id
             WHERE m.tournament_id = :tournament_id
               AND m.stage = :stage
             ORDER BY
                CASE WHEN m.bracket_position IS NULL THEN 1 ELSE 0 END,
                m.bracket_position ASC,
                m.id ASC'
        );
        $statement->execute([
            'tournament_id' => $tournamentId,
            'stage' => 'knockout',
        ]);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function finishedGroupMatchesForTournament(int $tournamentId): array
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT
                id,
                group_id,
                team_a_id,
                team_b_id,
                winner_team_id,
                sets_summary_a,
                sets_summary_b
             FROM matches
             WHERE tournament_id = :tournament_id
               AND stage = :stage
               AND status = :status
             ORDER BY id ASC'
        );
        $statement->execute([
            'tournament_id' => $tournamentId,
            'stage' => 'group',
            'status' => 'finished',
        ]);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array{set_number: int, score_a: int, score_b: int}>
     */
    public function setsForMatch(int $matchId): array
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT set_number, score_a, score_b
             FROM match_sets
             WHERE match_id = :match_id
             ORDER BY set_number ASC'
        );
        $statement->execute(['match_id' => $matchId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($rows)) {
            return [];
        }

        $sets = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sets[] = [
                'set_number' => (int) ($row['set_number'] ?? 0),
                'score_a' => (int) ($row['score_a'] ?? 0),
                'score_b' => (int) ($row['score_b'] ?? 0),
            ];
        }

        return $sets;
    }

    /**
     * @param list<int> $matchIds
     * @return array<int, list<array{set_number: int, score_a: int, score_b: int}>>
     */
    public function setsForMatches(array $matchIds): array
    {
        if (count($matchIds) === 0) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($matchIds), '?'));
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT match_id, set_number, score_a, score_b
             FROM match_sets
             WHERE match_id IN (' . $placeholders . ')
             ORDER BY match_id ASC, set_number ASC'
        );
        $statement->execute($matchIds);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $setsByMatchId = [];
        if (!is_array($rows)) {
            return $setsByMatchId;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $matchId = (int) ($row['match_id'] ?? 0);
            if ($matchId <= 0) {
                continue;
            }

            if (!isset($setsByMatchId[$matchId])) {
                $setsByMatchId[$matchId] = [];
            }

            $setsByMatchId[$matchId][] = [
                'set_number' => (int) ($row['set_number'] ?? 0),
                'score_a' => (int) ($row['score_a'] ?? 0),
                'score_b' => (int) ($row['score_b'] ?? 0),
            ];
        }

        return $setsByMatchId;
    }

    public function markGroupMatchInProgress(int $tournamentId, int $matchId, int $expectedLockVersion): bool
    {
        $pdo = $this->database->pdo();
        $ownsTransaction = $this->beginTransactionIfNeeded($pdo);

        try {
            if (!$this->lockTournament($pdo, $tournamentId)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return false;
            }

            $statement = $pdo->prepare(
                'UPDATE matches
                 SET status = :next_status,
                     lock_version = lock_version + 1,
                     updated_at = NOW()
                 WHERE id = :match_id
                   AND tournament_id = :tournament_id
                   AND stage = :stage
                   AND status = :current_status
                   AND lock_version = :lock_version'
            );
            $statement->execute([
                'next_status' => 'in_progress',
                'match_id' => $matchId,
                'tournament_id' => $tournamentId,
                'stage' => 'group',
                'current_status' => 'scheduled',
                'lock_version' => max(0, $expectedLockVersion),
            ]);

            $updated = $statement->rowCount() > 0;
            if ($updated) {
                $this->bumpTournamentStateVersion($pdo, $tournamentId);
            }
            $this->commitIfOwned($pdo, $ownsTransaction);
            return $updated;
        } catch (\Throwable $throwable) {
            $this->rollBackIfOwned($pdo, $ownsTransaction);
            throw $throwable;
        }
    }

    /**
     * @param list<array{set_number: int, score_a: int, score_b: int}> $sets
     */
    public function saveGroupMatchResult(
        int $tournamentId,
        int $matchId,
        int $expectedLockVersion,
        int $expectedTeamAId,
        int $expectedTeamBId,
        array $sets,
        int $setsSummaryA,
        int $setsSummaryB,
        ?int $winnerTeamId,
        bool $confirmResetKnockout
    ): string {
        $pdo = $this->database->pdo();
        $ownsTransaction = $this->beginTransactionIfNeeded($pdo);

        try {
            if (!$this->lockTournament($pdo, $tournamentId)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }

            $currentStatement = $pdo->prepare(
                'SELECT team_a_id, team_b_id, status, winner_team_id,
                        sets_summary_a, sets_summary_b, lock_version
                 FROM matches
                 WHERE id = :match_id
                   AND tournament_id = :tournament_id
                   AND stage = :stage
                 LIMIT 1
                 FOR UPDATE'
            );
            $currentStatement->execute([
                'match_id' => $matchId,
                'tournament_id' => $tournamentId,
                'stage' => 'group',
            ]);
            $current = $currentStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }

            $currentTeamAId = (int) ($current['team_a_id'] ?? 0);
            $currentTeamBId = (int) ($current['team_b_id'] ?? 0);
            if (
                $currentTeamAId !== $expectedTeamAId
                || $currentTeamBId !== $expectedTeamBId
            ) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }

            $currentSets = $this->setsForMatchUsingPdo($pdo, $matchId);
            if ($this->resultEquals($current, $currentSets, $sets, $setsSummaryA, $setsSummaryB, $winnerTeamId)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_IDEMPOTENT;
            }
            if ((int) ($current['lock_version'] ?? -1) !== $expectedLockVersion) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }

            if (!in_array((string) ($current['status'] ?? ''), ['scheduled', 'in_progress', 'finished'], true)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }
            if (
                $winnerTeamId !== null
                && $winnerTeamId !== $currentTeamAId
                && $winnerTeamId !== $currentTeamBId
            ) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }
            if (($setsSummaryA === $setsSummaryB) !== ($winnerTeamId === null)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }

            if ($this->hasKnockoutMatchesUsingPdo($pdo, $tournamentId)) {
                if (!$confirmResetKnockout) {
                    $this->commitIfOwned($pdo, $ownsTransaction);
                    return self::WRITE_REQUIRES_KNOCKOUT_RESET;
                }

                $this->deleteMatchesByStageUsingPdo($pdo, $tournamentId, 'knockout');
            }

            $matchUpdate = $pdo->prepare(
                'UPDATE matches
                 SET sets_summary_a = :sets_summary_a,
                     sets_summary_b = :sets_summary_b,
                     winner_team_id = :winner_team_id,
                     status = :status,
                     lock_version = lock_version + 1,
                     updated_at = NOW()
                 WHERE id = :match_id
                   AND tournament_id = :tournament_id
                   AND stage = :stage
                   AND status IN (\'scheduled\', \'in_progress\', \'finished\')
                   AND lock_version = :lock_version'
            );
            $matchUpdate->bindValue(':sets_summary_a', $setsSummaryA, PDO::PARAM_INT);
            $matchUpdate->bindValue(':sets_summary_b', $setsSummaryB, PDO::PARAM_INT);
            if ($winnerTeamId === null) {
                $matchUpdate->bindValue(':winner_team_id', null, PDO::PARAM_NULL);
            } else {
                $matchUpdate->bindValue(':winner_team_id', $winnerTeamId, PDO::PARAM_INT);
            }
            $matchUpdate->bindValue(':status', 'finished', PDO::PARAM_STR);
            $matchUpdate->bindValue(':match_id', $matchId, PDO::PARAM_INT);
            $matchUpdate->bindValue(':tournament_id', $tournamentId, PDO::PARAM_INT);
            $matchUpdate->bindValue(':stage', 'group', PDO::PARAM_STR);
            $matchUpdate->bindValue(':lock_version', $expectedLockVersion, PDO::PARAM_INT);
            $matchUpdate->execute();

            if ($matchUpdate->rowCount() < 1) {
                $this->rollBackIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }

            $deleteSets = $pdo->prepare('DELETE FROM match_sets WHERE match_id = :match_id');
            $deleteSets->execute(['match_id' => $matchId]);

            $insertSet = $pdo->prepare(
                'INSERT INTO match_sets (match_id, set_number, score_a, score_b, created_at, updated_at)
                 VALUES (:match_id, :set_number, :score_a, :score_b, NOW(), NOW())'
            );

            foreach ($sets as $set) {
                $insertSet->execute([
                    'match_id' => $matchId,
                    'set_number' => (int) $set['set_number'],
                    'score_a' => (int) $set['score_a'],
                    'score_b' => (int) $set['score_b'],
                ]);
            }

            $this->bumpTournamentStateVersion($pdo, $tournamentId);
            $this->commitIfOwned($pdo, $ownsTransaction);
            return self::WRITE_SAVED;
        } catch (\Throwable $throwable) {
            $this->rollBackIfOwned($pdo, $ownsTransaction);
            throw $throwable;
        }
    }

    public function resetGroupMatchResult(
        int $tournamentId,
        int $matchId,
        int $expectedLockVersion,
        bool $confirmResetKnockout
    ): string
    {
        $pdo = $this->database->pdo();
        $ownsTransaction = $this->beginTransactionIfNeeded($pdo);

        try {
            if (!$this->lockTournament($pdo, $tournamentId)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }

            $currentStatement = $pdo->prepare(
                'SELECT status, winner_team_id, sets_summary_a, sets_summary_b, lock_version
                 FROM matches
                 WHERE id = :match_id
                   AND tournament_id = :tournament_id
                   AND stage = :stage
                 LIMIT 1
                 FOR UPDATE'
            );
            $currentStatement->execute([
                'match_id' => $matchId,
                'tournament_id' => $tournamentId,
                'stage' => 'group',
            ]);
            $current = $currentStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }
            $currentSets = $this->setsForMatchUsingPdo($pdo, $matchId);
            $alreadyReset = (string) ($current['status'] ?? '') === 'scheduled'
                && (int) ($current['winner_team_id'] ?? 0) === 0
                && (int) ($current['sets_summary_a'] ?? 0) === 0
                && (int) ($current['sets_summary_b'] ?? 0) === 0
                && count($currentSets) === 0;
            if ($alreadyReset) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_IDEMPOTENT;
            }
            if ((int) ($current['lock_version'] ?? -1) !== $expectedLockVersion) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }
            if ((string) ($current['status'] ?? '') !== 'finished') {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }

            if ($this->hasKnockoutMatchesUsingPdo($pdo, $tournamentId)) {
                if (!$confirmResetKnockout) {
                    $this->commitIfOwned($pdo, $ownsTransaction);
                    return self::WRITE_REQUIRES_KNOCKOUT_RESET;
                }

                $this->deleteMatchesByStageUsingPdo($pdo, $tournamentId, 'knockout');
            }

            $matchUpdate = $pdo->prepare(
                'UPDATE matches
                 SET sets_summary_a = 0,
                     sets_summary_b = 0,
                     winner_team_id = NULL,
                     status = :status,
                     lock_version = lock_version + 1,
                     updated_at = NOW()
                 WHERE id = :match_id
                   AND tournament_id = :tournament_id
                   AND stage = :stage
                   AND status = :current_status
                   AND lock_version = :lock_version'
            );
            $matchUpdate->execute([
                'status' => 'scheduled',
                'match_id' => $matchId,
                'tournament_id' => $tournamentId,
                'stage' => 'group',
                'current_status' => 'finished',
                'lock_version' => $expectedLockVersion,
            ]);

            if ($matchUpdate->rowCount() < 1) {
                $this->rollBackIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }

            $deleteSets = $pdo->prepare('DELETE FROM match_sets WHERE match_id = :match_id');
            $deleteSets->execute(['match_id' => $matchId]);

            $this->bumpTournamentStateVersion($pdo, $tournamentId);
            $this->commitIfOwned($pdo, $ownsTransaction);
            return self::WRITE_SAVED;
        } catch (\Throwable $throwable) {
            $this->rollBackIfOwned($pdo, $ownsTransaction);
            throw $throwable;
        }
    }

    /**
     * @param list<array{
     *     group_id: int,
     *     team_a_id: int,
     *     team_b_id: int,
     *     court_number: int,
     *     schedule_order: int,
     *     planned_start: string
     * }> $matches
     */
    public function replaceGroupMatches(
        int $tournamentId,
        int $expectedStateVersion,
        array $matches
    ): string
    {
        $pdo = $this->database->pdo();
        $ownsTransaction = $this->beginTransactionIfNeeded($pdo);

        try {
            $currentStateVersion = $this->lockTournamentStateVersion($pdo, $tournamentId);
            if ($currentStateVersion === null) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }
            if ($currentStateVersion !== $expectedStateVersion) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }

            $this->deleteMatchesByStageUsingPdo($pdo, $tournamentId, 'knockout');

            $delete = $pdo->prepare(
                'DELETE FROM matches
                 WHERE tournament_id = :tournament_id
                   AND stage = :stage'
            );
            $delete->execute([
                'tournament_id' => $tournamentId,
                'stage' => 'group',
            ]);

            if (count($matches) > 0) {
                $insert = $pdo->prepare(
                    'INSERT INTO matches (
                        tournament_id,
                        stage,
                        group_id,
                        round_name,
                        bracket_position,
                        team_a_id,
                        team_b_id,
                        team_a_source,
                        team_b_source,
                        court_number,
                        schedule_order,
                        planned_start,
                        status,
                        winner_team_id,
                        sets_summary_a,
                        sets_summary_b,
                        created_at,
                        updated_at
                     ) VALUES (
                        :tournament_id,
                        :stage,
                        :group_id,
                        NULL,
                        NULL,
                        :team_a_id,
                        :team_b_id,
                        NULL,
                        NULL,
                        :court_number,
                        :schedule_order,
                        :planned_start,
                        :status,
                        NULL,
                        0,
                        0,
                        NOW(),
                        NOW()
                     )'
                );

                foreach ($matches as $match) {
                    $insert->execute([
                        'tournament_id' => $tournamentId,
                        'stage' => 'group',
                        'group_id' => (int) $match['group_id'],
                        'team_a_id' => (int) $match['team_a_id'],
                        'team_b_id' => (int) $match['team_b_id'],
                        'court_number' => (int) $match['court_number'],
                        'schedule_order' => (int) $match['schedule_order'],
                        'planned_start' => (string) $match['planned_start'],
                        'status' => 'scheduled',
                    ]);
                }
            }

            $this->bumpTournamentStateVersion($pdo, $tournamentId);
            $this->commitIfOwned($pdo, $ownsTransaction);
            return self::WRITE_SAVED;
        } catch (\Throwable $throwable) {
            $this->rollBackIfOwned($pdo, $ownsTransaction);
            throw $throwable;
        }
    }

    /**
     * @param list<array{
     *     round_name: string,
     *     bracket_position: int,
     *     team_a_id: int|null,
     *     team_b_id: int|null,
     *     team_a_source: string|null,
     *     team_b_source: string|null,
     *     status: string,
     *     court_number?: int|null,
     *     planned_start?: string|null
     * }> $matches
     */
    public function replaceKnockoutMatches(
        int $tournamentId,
        int $expectedStateVersion,
        array $matches
    ): string
    {
        $pdo = $this->database->pdo();
        $ownsTransaction = $this->beginTransactionIfNeeded($pdo);

        try {
            $currentStateVersion = $this->lockTournamentStateVersion($pdo, $tournamentId);
            if ($currentStateVersion === null) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }
            if ($currentStateVersion !== $expectedStateVersion) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }

            $delete = $pdo->prepare(
                'DELETE FROM matches
                 WHERE tournament_id = :tournament_id
                   AND stage = :stage'
            );
            $delete->execute([
                'tournament_id' => $tournamentId,
                'stage' => 'knockout',
            ]);

            if (count($matches) > 0) {
                $insert = $pdo->prepare(
                    'INSERT INTO matches (
                        tournament_id,
                        stage,
                        group_id,
                        round_name,
                        bracket_position,
                        team_a_id,
                        team_b_id,
                        team_a_source,
                        team_b_source,
                        court_number,
                        schedule_order,
                        planned_start,
                        status,
                        winner_team_id,
                        sets_summary_a,
                        sets_summary_b,
                        created_at,
                        updated_at
                     ) VALUES (
                        :tournament_id,
                        :stage,
                        NULL,
                        :round_name,
                        :bracket_position,
                        :team_a_id,
                        :team_b_id,
                        :team_a_source,
                        :team_b_source,
                        :court_number,
                        NULL,
                        :planned_start,
                        :status,
                        NULL,
                        0,
                        0,
                        NOW(),
                        NOW()
                     )'
                );

                foreach ($matches as $match) {
                    $insert->bindValue(':tournament_id', $tournamentId, PDO::PARAM_INT);
                    $insert->bindValue(':stage', 'knockout', PDO::PARAM_STR);
                    $insert->bindValue(':round_name', (string) $match['round_name'], PDO::PARAM_STR);
                    $insert->bindValue(':bracket_position', (int) $match['bracket_position'], PDO::PARAM_INT);

                    $teamAId = $match['team_a_id'] ?? null;
                    if (is_int($teamAId) && $teamAId > 0) {
                        $insert->bindValue(':team_a_id', $teamAId, PDO::PARAM_INT);
                    } else {
                        $insert->bindValue(':team_a_id', null, PDO::PARAM_NULL);
                    }

                    $teamBId = $match['team_b_id'] ?? null;
                    if (is_int($teamBId) && $teamBId > 0) {
                        $insert->bindValue(':team_b_id', $teamBId, PDO::PARAM_INT);
                    } else {
                        $insert->bindValue(':team_b_id', null, PDO::PARAM_NULL);
                    }

                    $teamASource = $match['team_a_source'] ?? null;
                    if (is_string($teamASource) && $teamASource !== '') {
                        $insert->bindValue(':team_a_source', $teamASource, PDO::PARAM_STR);
                    } else {
                        $insert->bindValue(':team_a_source', null, PDO::PARAM_NULL);
                    }

                    $teamBSource = $match['team_b_source'] ?? null;
                    if (is_string($teamBSource) && $teamBSource !== '') {
                        $insert->bindValue(':team_b_source', $teamBSource, PDO::PARAM_STR);
                    } else {
                        $insert->bindValue(':team_b_source', null, PDO::PARAM_NULL);
                    }

                    $status = (string) ($match['status'] ?? 'pending');
                    if (!in_array($status, ['pending', 'scheduled'], true)) {
                        $status = 'pending';
                    }
                    $insert->bindValue(':status', $status, PDO::PARAM_STR);

                    $courtNumber = $match['court_number'] ?? null;
                    if (is_int($courtNumber) && $courtNumber > 0) {
                        $insert->bindValue(':court_number', $courtNumber, PDO::PARAM_INT);
                    } else {
                        $insert->bindValue(':court_number', null, PDO::PARAM_NULL);
                    }

                    $plannedStart = $match['planned_start'] ?? null;
                    if (is_string($plannedStart) && trim($plannedStart) !== '') {
                        $insert->bindValue(':planned_start', $plannedStart, PDO::PARAM_STR);
                    } else {
                        $insert->bindValue(':planned_start', null, PDO::PARAM_NULL);
                    }

                    $insert->execute();
                }
            }

            $this->bumpTournamentStateVersion($pdo, $tournamentId);
            $this->commitIfOwned($pdo, $ownsTransaction);
            return self::WRITE_SAVED;
        } catch (\Throwable $throwable) {
            $this->rollBackIfOwned($pdo, $ownsTransaction);
            throw $throwable;
        }
    }

    /**
     * @param list<array{set_number: int, score_a: int, score_b: int}> $sets
     * @param list<int> $resetMatchIds
     * @param list<string> $resetSourceCodes
     */
    public function applyKnockoutResultAndProgress(
        int $tournamentId,
        int $matchId,
        int $expectedLockVersion,
        int $expectedTeamAId,
        int $expectedTeamBId,
        array $sets,
        int $setsSummaryA,
        int $setsSummaryB,
        int $winnerTeamId,
        array $resetMatchIds,
        array $resetSourceCodes,
        string $winnerSourceCode,
        bool $confirmResetDependents
    ): string {
        $pdo = $this->database->pdo();
        $ownsTransaction = $this->beginTransactionIfNeeded($pdo);

        try {
            if (!$this->lockTournament($pdo, $tournamentId)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }

            $currentStatement = $pdo->prepare(
                'SELECT team_a_id, team_b_id, status, winner_team_id,
                        sets_summary_a, sets_summary_b, lock_version
                 FROM matches
                 WHERE id = :match_id
                   AND tournament_id = :tournament_id
                   AND stage = :stage
                 LIMIT 1
                 FOR UPDATE'
            );
            $currentStatement->execute([
                'match_id' => $matchId,
                'tournament_id' => $tournamentId,
                'stage' => 'knockout',
            ]);
            $current = $currentStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }

            $currentTeamAId = (int) ($current['team_a_id'] ?? 0);
            $currentTeamBId = (int) ($current['team_b_id'] ?? 0);
            if (
                $currentTeamAId !== $expectedTeamAId
                || $currentTeamBId !== $expectedTeamBId
            ) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }

            $currentSets = $this->setsForMatchUsingPdo($pdo, $matchId);
            if ($this->resultEquals($current, $currentSets, $sets, $setsSummaryA, $setsSummaryB, $winnerTeamId)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_IDEMPOTENT;
            }
            if ((int) ($current['lock_version'] ?? -1) !== $expectedLockVersion) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }
            if (!in_array((string) ($current['status'] ?? ''), ['scheduled', 'in_progress', 'finished'], true)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }
            if ($winnerTeamId !== $currentTeamAId && $winnerTeamId !== $currentTeamBId) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID;
            }

            $oldWinnerTeamId = (int) ($current['winner_team_id'] ?? 0);
            $winnerChanged = $oldWinnerTeamId > 0 && $oldWinnerTeamId !== $winnerTeamId;
            $winnerAssignmentChanged = $oldWinnerTeamId !== $winnerTeamId;
            $resetMatchIds = array_values(array_unique(array_filter(
                array_map('intval', $resetMatchIds),
                static fn (int $id): bool => $id > 0
            )));

            if ($winnerChanged && count($resetMatchIds) > 0) {
                $placeholders = implode(', ', array_fill(0, count($resetMatchIds), '?'));
                $downstreamCheck = $pdo->prepare(
                    'SELECT id, status, winner_team_id, sets_summary_a, sets_summary_b
                     FROM matches
                     WHERE tournament_id = ?
                       AND stage = ?
                       AND id IN (' . $placeholders . ')
                     FOR UPDATE'
                );
                $downstreamCheck->execute(array_merge([$tournamentId, 'knockout'], $resetMatchIds));
                $downstreamRows = $downstreamCheck->fetchAll(PDO::FETCH_ASSOC);
                $hasScoredDownstream = false;
                foreach ($downstreamRows as $downstreamRow) {
                    if (!is_array($downstreamRow)) {
                        continue;
                    }

                    if (
                        in_array((string) ($downstreamRow['status'] ?? ''), ['in_progress', 'finished'], true)
                        || (int) ($downstreamRow['winner_team_id'] ?? 0) > 0
                        || (int) ($downstreamRow['sets_summary_a'] ?? 0) > 0
                        || (int) ($downstreamRow['sets_summary_b'] ?? 0) > 0
                    ) {
                        $hasScoredDownstream = true;
                        break;
                    }
                }
                if ($hasScoredDownstream && !$confirmResetDependents) {
                    $this->commitIfOwned($pdo, $ownsTransaction);
                    return self::WRITE_REQUIRES_DEPENDENT_RESET;
                }

                $deleteSets = $pdo->prepare(
                    'DELETE FROM match_sets
                     WHERE match_id IN (' . $placeholders . ')'
                );
                $deleteSets->execute($resetMatchIds);

                $params = array_merge(
                    ['pending', $tournamentId, 'knockout'],
                    $resetMatchIds
                );
                $resetMatches = $pdo->prepare(
                    'UPDATE matches
                     SET sets_summary_a = 0,
                         sets_summary_b = 0,
                         winner_team_id = NULL,
                         status = ?,
                         lock_version = lock_version + 1,
                         updated_at = NOW()
                     WHERE tournament_id = ?
                       AND stage = ?
                       AND id IN (' . $placeholders . ')'
                );
                $resetMatches->execute($params);
            }

            if ($winnerChanged && count($resetSourceCodes) > 0) {
                $clearA = $pdo->prepare(
                    'UPDATE matches
                     SET team_a_id = NULL,
                         lock_version = lock_version + 1,
                         updated_at = NOW()
                     WHERE tournament_id = :tournament_id
                       AND stage = :stage
                       AND team_a_source = :source
                       AND team_a_id IS NOT NULL'
                );
                $clearB = $pdo->prepare(
                    'UPDATE matches
                     SET team_b_id = NULL,
                         lock_version = lock_version + 1,
                         updated_at = NOW()
                     WHERE tournament_id = :tournament_id
                       AND stage = :stage
                       AND team_b_source = :source
                       AND team_b_id IS NOT NULL'
                );
                foreach ($resetSourceCodes as $sourceCode) {
                    $clearA->execute([
                        'tournament_id' => $tournamentId,
                        'stage' => 'knockout',
                        'source' => $sourceCode,
                    ]);
                    $clearB->execute([
                        'tournament_id' => $tournamentId,
                        'stage' => 'knockout',
                        'source' => $sourceCode,
                    ]);
                }
            }

            $matchUpdate = $pdo->prepare(
                'UPDATE matches
                 SET sets_summary_a = :sets_summary_a,
                     sets_summary_b = :sets_summary_b,
                     winner_team_id = :winner_team_id,
                     status = :status,
                     lock_version = lock_version + 1,
                     updated_at = NOW()
                 WHERE id = :match_id
                   AND tournament_id = :tournament_id
                   AND stage = :stage
                   AND status IN (\'scheduled\', \'in_progress\', \'finished\')
                   AND lock_version = :lock_version'
            );
            $matchUpdate->execute([
                'sets_summary_a' => $setsSummaryA,
                'sets_summary_b' => $setsSummaryB,
                'winner_team_id' => $winnerTeamId,
                'status' => 'finished',
                'match_id' => $matchId,
                'tournament_id' => $tournamentId,
                'stage' => 'knockout',
                'lock_version' => $expectedLockVersion,
            ]);
            if ($matchUpdate->rowCount() < 1) {
                $this->rollBackIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }

            $deleteCurrentSets = $pdo->prepare('DELETE FROM match_sets WHERE match_id = :match_id');
            $deleteCurrentSets->execute(['match_id' => $matchId]);

            $insertSet = $pdo->prepare(
                'INSERT INTO match_sets (match_id, set_number, score_a, score_b, created_at, updated_at)
                 VALUES (:match_id, :set_number, :score_a, :score_b, NOW(), NOW())'
            );
            foreach ($sets as $set) {
                $insertSet->execute([
                    'match_id' => $matchId,
                    'set_number' => (int) $set['set_number'],
                    'score_a' => (int) $set['score_a'],
                    'score_b' => (int) $set['score_b'],
                ]);
            }

            if ($winnerAssignmentChanged) {
                $assignA = $pdo->prepare(
                    'UPDATE matches
                     SET team_a_id = :winner_team_id,
                         lock_version = lock_version + 1,
                         updated_at = NOW()
                     WHERE tournament_id = :tournament_id
                       AND stage = :stage
                       AND team_a_source = :source
                       AND winner_team_id IS NULL
                       AND team_a_id IS NULL'
                );
                $assignA->execute([
                    'winner_team_id' => $winnerTeamId,
                    'tournament_id' => $tournamentId,
                    'stage' => 'knockout',
                    'source' => $winnerSourceCode,
                ]);

                $assignB = $pdo->prepare(
                    'UPDATE matches
                     SET team_b_id = :winner_team_id,
                         lock_version = lock_version + 1,
                         updated_at = NOW()
                     WHERE tournament_id = :tournament_id
                       AND stage = :stage
                       AND team_b_source = :source
                       AND winner_team_id IS NULL
                       AND team_b_id IS NULL'
                );
                $assignB->execute([
                    'winner_team_id' => $winnerTeamId,
                    'tournament_id' => $tournamentId,
                    'stage' => 'knockout',
                    'source' => $winnerSourceCode,
                ]);
            }

            if ($winnerAssignmentChanged) {
                $setScheduled = $pdo->prepare(
                    'UPDATE matches
                     SET status = :scheduled,
                         lock_version = lock_version + 1,
                         updated_at = NOW()
                     WHERE tournament_id = :tournament_id
                       AND stage = :stage
                       AND winner_team_id IS NULL
                       AND team_a_id IS NOT NULL
                       AND team_b_id IS NOT NULL
                       AND status = :pending'
                );
                $setScheduled->execute([
                    'scheduled' => 'scheduled',
                    'tournament_id' => $tournamentId,
                    'stage' => 'knockout',
                    'pending' => 'pending',
                ]);

                $setPending = $pdo->prepare(
                    'UPDATE matches
                     SET status = :pending,
                         lock_version = lock_version + 1,
                         updated_at = NOW()
                     WHERE tournament_id = :tournament_id
                       AND stage = :stage
                       AND winner_team_id IS NULL
                       AND (team_a_id IS NULL OR team_b_id IS NULL)
                       AND status = :scheduled'
                );
                $setPending->execute([
                    'pending' => 'pending',
                    'tournament_id' => $tournamentId,
                    'stage' => 'knockout',
                    'scheduled' => 'scheduled',
                ]);
            }

            $this->bumpTournamentStateVersion($pdo, $tournamentId);
            $this->commitIfOwned($pdo, $ownsTransaction);
            return self::WRITE_SAVED;
        } catch (\Throwable $throwable) {
            $this->rollBackIfOwned($pdo, $ownsTransaction);
            throw $throwable;
        }
    }

    private function beginTransactionIfNeeded(PDO $pdo): bool
    {
        if ($pdo->inTransaction()) {
            return false;
        }

        $pdo->beginTransaction();
        return true;
    }

    private function commitIfOwned(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    private function rollBackIfOwned(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function lockTournament(PDO $pdo, int $tournamentId): bool
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM tournaments
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $tournamentId]);

        return $statement->fetchColumn() !== false;
    }

    private function lockTournamentStateVersion(PDO $pdo, int $tournamentId): ?int
    {
        $statement = $pdo->prepare(
            'SELECT state_version
             FROM tournaments
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $tournamentId]);
        $stateVersion = $statement->fetchColumn();

        return $stateVersion === false ? null : (int) $stateVersion;
    }

    private function bumpTournamentStateVersion(PDO $pdo, int $tournamentId): void
    {
        $statement = $pdo->prepare(
            'UPDATE tournaments
             SET state_version = state_version + 1
             WHERE id = :id'
        );
        $statement->execute(['id' => $tournamentId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Tournament state version could not be advanced.');
        }
    }

    /**
     * @return list<array{set_number: int, score_a: int, score_b: int}>
     */
    private function setsForMatchUsingPdo(PDO $pdo, int $matchId): array
    {
        $statement = $pdo->prepare(
            'SELECT set_number, score_a, score_b
             FROM match_sets
             WHERE match_id = :match_id
             ORDER BY set_number ASC'
        );
        $statement->execute(['match_id' => $matchId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $sets = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sets[] = [
                'set_number' => (int) ($row['set_number'] ?? 0),
                'score_a' => (int) ($row['score_a'] ?? 0),
                'score_b' => (int) ($row['score_b'] ?? 0),
            ];
        }

        return $sets;
    }

    /**
     * @param array<string, mixed> $current
     * @param list<array{set_number: int, score_a: int, score_b: int}> $currentSets
     * @param list<array{set_number: int, score_a: int, score_b: int}> $newSets
     */
    private function resultEquals(
        array $current,
        array $currentSets,
        array $newSets,
        int $setsSummaryA,
        int $setsSummaryB,
        ?int $winnerTeamId
    ): bool {
        $currentWinner = $current['winner_team_id'] ?? null;
        $normalizedCurrentWinner = is_numeric($currentWinner) && (int) $currentWinner > 0
            ? (int) $currentWinner
            : null;

        return (string) ($current['status'] ?? '') === 'finished'
            && (int) ($current['sets_summary_a'] ?? -1) === $setsSummaryA
            && (int) ($current['sets_summary_b'] ?? -1) === $setsSummaryB
            && $normalizedCurrentWinner === $winnerTeamId
            && array_values($currentSets) === array_values($newSets);
    }

    private function hasKnockoutMatchesUsingPdo(PDO $pdo, int $tournamentId): bool
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM matches
             WHERE tournament_id = :tournament_id
               AND stage = :stage
             LIMIT 1'
        );
        $statement->execute([
            'tournament_id' => $tournamentId,
            'stage' => 'knockout',
        ]);

        return $statement->fetchColumn() !== false;
    }

    private function deleteMatchesByStageUsingPdo(PDO $pdo, int $tournamentId, string $stage): void
    {
        $statement = $pdo->prepare(
            'DELETE FROM matches
             WHERE tournament_id = :tournament_id
               AND stage = :stage'
        );
        $statement->execute([
            'tournament_id' => $tournamentId,
            'stage' => $stage,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matchesByStatusForTournament(int $tournamentId, string $status, int $limit): array
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT
                m.id,
                m.stage,
                m.group_id,
                g.name AS group_name,
                m.round_name,
                m.bracket_position,
                m.team_a_id,
                ta.team_name AS team_a_name,
                m.team_b_id,
                tb.team_name AS team_b_name,
                m.team_a_source,
                m.team_b_source,
                m.court_number,
                m.schedule_order,
                m.planned_start,
                m.status,
                m.winner_team_id,
                m.sets_summary_a,
                m.sets_summary_b,
                m.lock_version,
                (
                    SELECT GROUP_CONCAT(CONCAT(ms.score_a, \':\', ms.score_b) ORDER BY ms.set_number ASC SEPARATOR \', \')
                    FROM match_sets ms
                    WHERE ms.match_id = m.id
                ) AS set_scores_summary
             FROM matches m
             LEFT JOIN tournament_groups g ON g.id = m.group_id
             LEFT JOIN teams ta ON ta.id = m.team_a_id
             LEFT JOIN teams tb ON tb.id = m.team_b_id
             WHERE m.tournament_id = :tournament_id
               AND m.status = :status
             ORDER BY
                CASE WHEN m.planned_start IS NULL THEN 1 ELSE 0 END,
                m.planned_start ASC,
                CASE WHEN m.schedule_order IS NULL THEN 1 ELSE 0 END,
                m.schedule_order ASC,
                m.id ASC
             LIMIT :limit_rows'
        );
        $statement->bindValue(':tournament_id', $tournamentId, PDO::PARAM_INT);
        $statement->bindValue(':status', $status, PDO::PARAM_STR);
        $statement->bindValue(':limit_rows', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }
}
