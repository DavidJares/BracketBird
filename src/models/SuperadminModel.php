<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class SuperadminModel
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function hasAny(): bool
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->query('SELECT COUNT(*) FROM superadmins');
        return (int) $statement->fetchColumn() > 0;
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
        $statement->execute(['table_name' => 'superadmins']);

        return $statement->fetchColumn() !== false;
    }

    public function create(string $username, string $password): int
    {
        $pdo = $this->database->pdo();
        $passwordHash = $this->hashPassword($password);

        $statement = $pdo->prepare(
            'INSERT INTO superadmins (username, password_hash, created_at, updated_at)
             VALUES (:username, :password_hash, NOW(), NOW())'
        );

        $statement->execute([
            'username' => $username,
            'password_hash' => $passwordHash,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function createFirst(string $username, string $password): ?int
    {
        $pdo = $this->database->pdo();
        $passwordHash = $this->hashPassword($password);
        $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'bracketbird:first-admin:' . substr(hash('sha256', $databaseName), 0, 32);
        $acquire = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $acquire->execute(['lock_name' => $lockName]);
        if ((int) $acquire->fetchColumn() !== 1) {
            throw new \RuntimeException('Could not acquire the initial setup lock.');
        }

        try {
            $pdo->beginTransaction();
            $existing = $pdo->query(
                'SELECT id
                 FROM superadmins
                 ORDER BY id ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            if ($existing->fetchColumn() !== false) {
                $pdo->rollBack();
                return null;
            }

            $statement = $pdo->prepare(
                'INSERT INTO superadmins (username, password_hash, created_at, updated_at)
                 VALUES (:username, :password_hash, NOW(), NOW())'
            );
            $statement->execute([
                'username' => $username,
                'password_hash' => $passwordHash,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();

            return $id;
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $release->execute(['lock_name' => $lockName]);
            } catch (\Throwable) {
                // The connection releases named locks automatically when it closes.
            }
        }
    }

    public function rehashPassword(int $id, string $password): void
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'UPDATE superadmins
             SET password_hash = :password_hash,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'password_hash' => $this->hashPassword($password),
        ]);
    }

    /**
     * @return array{id: int, username: string, password_hash: string}|null
     */
    public function findByUsername(string $username): ?array
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT id, username, password_hash
             FROM superadmins
             WHERE username = :username
             LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'password_hash' => (string) ($row['password_hash'] ?? ''),
        ];
    }

    private function hashPassword(string $password): string
    {
        if (strlen($password) === 0 || strlen($password) > 72) {
            throw new \InvalidArgumentException('Password must contain between 1 and 72 bytes.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new \RuntimeException('Password hashing failed.');
        }

        return $passwordHash;
    }
}
