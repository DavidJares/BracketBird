<?php

declare(strict_types=1);

namespace App\Models;

final class LoginAttemptModel
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function tableExists(): bool
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
             LIMIT 1'
        );
        $statement->execute(['table_name' => 'login_attempts']);

        return $statement->fetchColumn() !== false;
    }

    public function reserve(
        string $scope,
        string $clientHash,
        int $maxAttempts,
        int $lockSeconds,
        int $windowSeconds
    ): bool {
        $maxAttempts = max(2, min(100, $maxAttempts));
        $lockSeconds = max(30, min(86400, $lockSeconds));
        $windowSeconds = max(60, min(86400, $windowSeconds));

        $pdo = $this->database->pdo();
        $keys = $this->keys($scope, $clientHash);
        $pdo->exec(
            'DELETE FROM login_attempts
             WHERE updated_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
             ORDER BY updated_at ASC
             LIMIT 100'
        );

        if (!$pdo->beginTransaction()) {
            throw new \RuntimeException('Could not reserve a login attempt.');
        }

        try {
            $ensureRow = $pdo->prepare(
                'INSERT INTO login_attempts (
                    scope_hash,
                    client_hash,
                    attempts,
                    locked_until,
                    updated_at
                 ) VALUES (
                    :scope_hash,
                    :client_hash,
                    0,
                    NULL,
                    NOW()
                 )
                 ON DUPLICATE KEY UPDATE
                    scope_hash = scope_hash'
            );
            $ensureRow->execute($keys);

            $select = $pdo->prepare(
                'SELECT
                    attempts,
                    CASE
                        WHEN locked_until IS NOT NULL AND locked_until > NOW() THEN 1
                        ELSE 0
                    END AS has_active_lock,
                    CASE
                        WHEN (
                            (locked_until IS NOT NULL AND locked_until <= NOW())
                            OR updated_at < DATE_SUB(NOW(), INTERVAL ' . $windowSeconds . ' SECOND)
                        ) THEN 1
                        ELSE 0
                    END AS has_expired_state
                 FROM login_attempts
                 WHERE scope_hash = :scope_hash
                   AND client_hash = :client_hash
                 FOR UPDATE'
            );
            $select->execute($keys);
            $row = $select->fetch();
            if (!is_array($row)) {
                throw new \RuntimeException('Could not read the reserved login attempt.');
            }

            if ((int) ($row['has_active_lock'] ?? 0) === 1) {
                if (!$pdo->commit()) {
                    throw new \RuntimeException('Could not complete a login attempt reservation.');
                }
                return false;
            }

            $attempts = (int) ($row['attempts'] ?? 0);
            if ((int) ($row['has_expired_state'] ?? 0) === 1) {
                $attempts = 0;
            }

            if ($attempts >= $maxAttempts) {
                $lock = $pdo->prepare(
                    'UPDATE login_attempts
                     SET locked_until = DATE_ADD(NOW(), INTERVAL ' . $lockSeconds . ' SECOND),
                         updated_at = NOW()
                     WHERE scope_hash = :scope_hash
                       AND client_hash = :client_hash'
                );
                $lock->execute($keys);
                if (!$pdo->commit()) {
                    throw new \RuntimeException('Could not complete a login attempt reservation.');
                }
                return false;
            }

            $attempts++;
            $update = $pdo->prepare(
                'UPDATE login_attempts
                 SET attempts = :attempts,
                     locked_until = CASE
                         WHEN :lock_after_reservation = 1
                             THEN DATE_ADD(NOW(), INTERVAL ' . $lockSeconds . ' SECOND)
                         ELSE NULL
                     END,
                     updated_at = NOW()
                 WHERE scope_hash = :scope_hash
                   AND client_hash = :client_hash'
            );
            $update->execute([
                'attempts' => $attempts,
                'lock_after_reservation' => $attempts >= $maxAttempts ? 1 : 0,
                'scope_hash' => $keys['scope_hash'],
                'client_hash' => $keys['client_hash'],
            ]);

            if (!$pdo->commit()) {
                throw new \RuntimeException('Could not complete a login attempt reservation.');
            }

            return true;
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }

    public function reset(string $scope, string $clientHash): void
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'DELETE FROM login_attempts
             WHERE scope_hash = :scope_hash
               AND client_hash = :client_hash'
        );
        $statement->execute($this->keys($scope, $clientHash));
    }

    /**
     * @return array{scope_hash: string, client_hash: string}
     */
    private function keys(string $scope, string $clientHash): array
    {
        return [
            'scope_hash' => hash('sha256', $scope),
            'client_hash' => preg_match('/^[a-f0-9]{64}$/', $clientHash) === 1
                ? $clientHash
                : hash('sha256', $clientHash),
        ];
    }
}
