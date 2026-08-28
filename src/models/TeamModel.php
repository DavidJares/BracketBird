<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class TeamModel
{
    public const MAX_TEAMS_PER_TOURNAMENT = 64;
    public const WRITE_UPDATED = 'updated';
    public const WRITE_IDEMPOTENT = 'idempotent';
    public const WRITE_NOT_FOUND = 'not_found';
    public const WRITE_INVALID_GROUP = 'invalid_group';
    public const WRITE_REQUIRES_MATCH_RESET = 'requires_match_reset';
    public const WRITE_STALE = 'stale';

    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allByTournament(int $tournamentId): array
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT id, tournament_id, group_id, team_name, description, created_at, updated_at
             FROM teams
             WHERE tournament_id = :tournament_id
             ORDER BY team_name ASC, id ASC'
        );
        $statement->execute(['tournament_id' => $tournamentId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function create(int $tournamentId, string $teamName, string $description): int
    {
        $pdo = $this->database->pdo();
        $ownsTransaction = $this->beginTransactionIfNeeded($pdo);

        try {
            if (!$this->lockTournament($pdo, $tournamentId)) {
                throw new \RuntimeException('Tournament not found.');
            }

            $count = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE tournament_id = :tournament_id');
            $count->execute(['tournament_id' => $tournamentId]);
            if ((int) $count->fetchColumn() >= self::MAX_TEAMS_PER_TOURNAMENT) {
                throw new \DomainException('TEAM_LIMIT_REACHED');
            }

            $statement = $pdo->prepare(
                'INSERT INTO teams (tournament_id, group_id, team_name, description, created_at, updated_at)
                 VALUES (:tournament_id, NULL, :team_name, :description, NOW(), NOW())'
            );

            $statement->execute([
                'tournament_id' => $tournamentId,
                'team_name' => $teamName,
                'description' => $description === '' ? null : $description,
            ]);

            $teamId = (int) $pdo->lastInsertId();
            $this->bumpTournamentStateVersion($pdo, $tournamentId);
            $this->commitIfOwned($pdo, $ownsTransaction);
            return $teamId;
        } catch (\Throwable $throwable) {
            $this->rollBackIfOwned($pdo, $ownsTransaction);
            throw $throwable;
        }
    }

    public function update(
        int $teamId,
        int $tournamentId,
        int $expectedStateVersion,
        string $teamName,
        string $description
    ): string
    {
        $pdo = $this->database->pdo();
        $ownsTransaction = $this->beginTransactionIfNeeded($pdo);

        try {
            $currentStateVersion = $this->lockTournamentStateVersion($pdo, $tournamentId);
            if ($currentStateVersion === null) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_NOT_FOUND;
            }
            if ($currentStateVersion !== $expectedStateVersion) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }

            $select = $pdo->prepare(
                'SELECT team_name, description
                 FROM teams
                 WHERE id = :id
                   AND tournament_id = :tournament_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $select->execute([
                'id' => $teamId,
                'tournament_id' => $tournamentId,
            ]);
            $current = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_NOT_FOUND;
            }

            if (
                (string) ($current['team_name'] ?? '') === $teamName
                && (string) ($current['description'] ?? '') === $description
            ) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_IDEMPOTENT;
            }

            $statement = $pdo->prepare(
                'UPDATE teams
                 SET team_name = :team_name,
                     description = :description,
                     updated_at = NOW()
                 WHERE id = :id
                   AND tournament_id = :tournament_id'
            );
            $statement->execute([
                'id' => $teamId,
                'tournament_id' => $tournamentId,
                'team_name' => $teamName,
                'description' => $description === '' ? null : $description,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Team update target disappeared.');
            }

            $this->bumpTournamentStateVersion($pdo, $tournamentId);
            $this->commitIfOwned($pdo, $ownsTransaction);
            return self::WRITE_UPDATED;
        } catch (\Throwable $throwable) {
            $this->rollBackIfOwned($pdo, $ownsTransaction);
            throw $throwable;
        }
    }

    public function updateGroupAssignment(
        int $teamId,
        int $tournamentId,
        ?int $groupId,
        int $expectedStateVersion,
        bool $confirmResetMatches = false
    ): string
    {
        $pdo = $this->database->pdo();
        $ownsTransaction = $this->beginTransactionIfNeeded($pdo);

        try {
            $currentStateVersion = $this->lockTournamentStateVersion($pdo, $tournamentId);
            if ($currentStateVersion === null) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_NOT_FOUND;
            }
            if ($currentStateVersion !== $expectedStateVersion) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }
            if ($groupId !== null && !$this->groupBelongsToTournament($pdo, $groupId, $tournamentId)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_INVALID_GROUP;
            }

            $team = $pdo->prepare(
                'SELECT group_id
                 FROM teams
                 WHERE id = :id
                   AND tournament_id = :tournament_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $team->execute([
                'id' => $teamId,
                'tournament_id' => $tournamentId,
            ]);
            $row = $team->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_NOT_FOUND;
            }

            $currentGroup = is_numeric($row['group_id'] ?? null) ? (int) $row['group_id'] : null;
            if ($currentGroup === $groupId) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_IDEMPOTENT;
            }

            if ($this->hasAnyMatchesUsingPdo($pdo, $tournamentId)) {
                if (!$confirmResetMatches) {
                    $this->commitIfOwned($pdo, $ownsTransaction);
                    return self::WRITE_REQUIRES_MATCH_RESET;
                }

                $this->deleteAllMatchesUsingPdo($pdo, $tournamentId);
            }

            $statement = $pdo->prepare(
                'UPDATE teams
                 SET group_id = :group_id,
                     updated_at = NOW()
                 WHERE id = :id
                   AND tournament_id = :tournament_id'
            );

            $statement->bindValue(':id', $teamId, PDO::PARAM_INT);
            $statement->bindValue(':tournament_id', $tournamentId, PDO::PARAM_INT);
            if ($groupId === null) {
                $statement->bindValue(':group_id', null, PDO::PARAM_NULL);
            } else {
                $statement->bindValue(':group_id', $groupId, PDO::PARAM_INT);
            }
            $statement->execute();

            $this->bumpTournamentStateVersion($pdo, $tournamentId);
            $this->commitIfOwned($pdo, $ownsTransaction);
            return self::WRITE_UPDATED;
        } catch (\Throwable $throwable) {
            $this->rollBackIfOwned($pdo, $ownsTransaction);
            throw $throwable;
        }
    }

    /**
     * @param array<int, int|null> $assignmentByTeamId
     */
    public function bulkUpdateGroupAssignments(
        int $tournamentId,
        array $assignmentByTeamId,
        int $expectedStateVersion,
        bool $confirmResetMatches = false
    ): string
    {
        if (count($assignmentByTeamId) === 0) {
            return self::WRITE_IDEMPOTENT;
        }

        $pdo = $this->database->pdo();
        $ownsTransaction = $this->beginTransactionIfNeeded($pdo);

        try {
            $currentStateVersion = $this->lockTournamentStateVersion($pdo, $tournamentId);
            if ($currentStateVersion === null) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_NOT_FOUND;
            }
            if ($currentStateVersion !== $expectedStateVersion) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }

            $selectTeam = $pdo->prepare(
                'SELECT group_id
                 FROM teams
                 WHERE id = :id
                   AND tournament_id = :tournament_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $normalizedAssignments = [];
            $hasChanges = false;
            foreach ($assignmentByTeamId as $rawTeamId => $rawGroupId) {
                $teamId = (int) $rawTeamId;
                $groupId = $rawGroupId === null ? null : (int) $rawGroupId;
                if ($teamId <= 0) {
                    $this->commitIfOwned($pdo, $ownsTransaction);
                    return self::WRITE_NOT_FOUND;
                }
                if (
                    $groupId !== null
                    && ($groupId <= 0 || !$this->groupBelongsToTournament($pdo, $groupId, $tournamentId))
                ) {
                    $this->commitIfOwned($pdo, $ownsTransaction);
                    return self::WRITE_INVALID_GROUP;
                }

                $selectTeam->execute([
                    'id' => $teamId,
                    'tournament_id' => $tournamentId,
                ]);
                $row = $selectTeam->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    $this->commitIfOwned($pdo, $ownsTransaction);
                    return self::WRITE_NOT_FOUND;
                }

                $currentGroupId = is_numeric($row['group_id'] ?? null) ? (int) $row['group_id'] : null;
                if ($currentGroupId !== $groupId) {
                    $hasChanges = true;
                }
                $normalizedAssignments[$teamId] = $groupId;
            }
            if (!$hasChanges) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_IDEMPOTENT;
            }

            if ($this->hasAnyMatchesUsingPdo($pdo, $tournamentId)) {
                if (!$confirmResetMatches) {
                    $this->commitIfOwned($pdo, $ownsTransaction);
                    return self::WRITE_REQUIRES_MATCH_RESET;
                }

                $this->deleteAllMatchesUsingPdo($pdo, $tournamentId);
            }

            $statement = $pdo->prepare(
                'UPDATE teams
                 SET group_id = :group_id,
                     updated_at = NOW()
                 WHERE id = :id
                   AND tournament_id = :tournament_id'
            );

            foreach ($normalizedAssignments as $teamId => $groupId) {
                $statement->bindValue(':id', $teamId, PDO::PARAM_INT);
                $statement->bindValue(':tournament_id', $tournamentId, PDO::PARAM_INT);
                if ($groupId === null) {
                    $statement->bindValue(':group_id', null, PDO::PARAM_NULL);
                } else {
                    $statement->bindValue(':group_id', $groupId, PDO::PARAM_INT);
                }

                $statement->execute();
                if ($statement->rowCount() < 1) {
                    $exists = $pdo->prepare(
                        'SELECT 1 FROM teams WHERE id = :id AND tournament_id = :tournament_id LIMIT 1'
                    );
                    $exists->execute([
                        'id' => $teamId,
                        'tournament_id' => $tournamentId,
                    ]);
                    if ($exists->fetchColumn() === false) {
                        throw new \RuntimeException('Team assignment target disappeared.');
                    }
                }
            }

            $this->bumpTournamentStateVersion($pdo, $tournamentId);
            $this->commitIfOwned($pdo, $ownsTransaction);
            return self::WRITE_UPDATED;
        } catch (\Throwable $throwable) {
            $this->rollBackIfOwned($pdo, $ownsTransaction);
            throw $throwable;
        }
    }

    public function hasAnyAssignedTeam(int $tournamentId): bool
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM teams
             WHERE tournament_id = :tournament_id
               AND group_id IS NOT NULL'
        );
        $statement->execute(['tournament_id' => $tournamentId]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function delete(
        int $teamId,
        int $tournamentId,
        int $expectedStateVersion,
        bool $confirmResetMatches = false
    ): string
    {
        $pdo = $this->database->pdo();
        $ownsTransaction = $this->beginTransactionIfNeeded($pdo);

        try {
            $currentStateVersion = $this->lockTournamentStateVersion($pdo, $tournamentId);
            if ($currentStateVersion === null) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_NOT_FOUND;
            }
            if ($currentStateVersion !== $expectedStateVersion) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_STALE;
            }

            $exists = $pdo->prepare(
                'SELECT id
                 FROM teams
                 WHERE id = :id
                   AND tournament_id = :tournament_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $exists->execute([
                'id' => $teamId,
                'tournament_id' => $tournamentId,
            ]);
            if ($exists->fetchColumn() === false) {
                $this->commitIfOwned($pdo, $ownsTransaction);
                return self::WRITE_NOT_FOUND;
            }

            if ($this->hasAnyMatchesUsingPdo($pdo, $tournamentId)) {
                if (!$confirmResetMatches) {
                    $this->commitIfOwned($pdo, $ownsTransaction);
                    return self::WRITE_REQUIRES_MATCH_RESET;
                }

                $this->deleteAllMatchesUsingPdo($pdo, $tournamentId);
            }

            $statement = $pdo->prepare(
                'DELETE FROM teams
                 WHERE id = :id
                   AND tournament_id = :tournament_id'
            );
            $statement->execute([
                'id' => $teamId,
                'tournament_id' => $tournamentId,
            ]);

            $this->bumpTournamentStateVersion($pdo, $tournamentId);
            $this->commitIfOwned($pdo, $ownsTransaction);
            return self::WRITE_UPDATED;
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

    private function groupBelongsToTournament(PDO $pdo, int $groupId, int $tournamentId): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1
             FROM tournament_groups
             WHERE id = :id
               AND tournament_id = :tournament_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $groupId,
            'tournament_id' => $tournamentId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    private function hasAnyMatchesUsingPdo(PDO $pdo, int $tournamentId): bool
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM matches
             WHERE tournament_id = :tournament_id
             LIMIT 1'
        );
        $statement->execute(['tournament_id' => $tournamentId]);

        return $statement->fetchColumn() !== false;
    }

    private function deleteAllMatchesUsingPdo(PDO $pdo, int $tournamentId): void
    {
        $statement = $pdo->prepare('DELETE FROM matches WHERE tournament_id = :tournament_id');
        $statement->execute(['tournament_id' => $tournamentId]);
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
}
