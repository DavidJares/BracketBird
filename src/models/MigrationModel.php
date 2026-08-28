<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class MigrationModel
{
    private const LOCK_TIMEOUT_SECONDS = 30;

    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function migrate(string $migrationsDirectory): int
    {
        $pdo = $this->database->pdo();
        $lockName = $this->migrationLockName($pdo);
        $this->acquireMigrationLock($pdo, $lockName);

        try {
            $this->ensureMigrationTables($pdo);

            $applied = $this->appliedVersions($pdo);
            if (!is_dir($migrationsDirectory) || !is_readable($migrationsDirectory)) {
                throw new \RuntimeException(sprintf(
                    'Migration directory is missing or unreadable: %s',
                    $migrationsDirectory
                ));
            }

            $files = glob(rtrim($migrationsDirectory, '/\\') . '/*.php');
            if (!is_array($files) || count($files) === 0) {
                throw new \RuntimeException(sprintf(
                    'No migration files were found in: %s',
                    $migrationsDirectory
                ));
            }

            sort($files, SORT_STRING);
            $appliedCount = 0;
            $seenVersions = [];

            foreach ($files as $file) {
                $migration = require $file;
                if (!is_array($migration)) {
                    throw new \RuntimeException(sprintf('Invalid migration file: %s', $file));
                }

                $version = $migration['version'] ?? null;
                $description = $migration['description'] ?? null;
                $statements = $migration['statements'] ?? null;

                if (
                    !is_string($version)
                    || $version === ''
                    || !preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', $version)
                    || !is_string($description)
                    || strlen($description) > 255
                    || !is_array($statements)
                ) {
                    throw new \RuntimeException(sprintf('Migration file is missing or has invalid metadata: %s', $file));
                }

                if (isset($seenVersions[$version])) {
                    throw new \RuntimeException(sprintf('Duplicate migration version: %s', $version));
                }
                $seenVersions[$version] = true;

                $executableStatements = [];
                foreach ($statements as $statement) {
                    if (!is_string($statement) || trim($statement) === '') {
                        continue;
                    }

                    $executableStatements[] = $statement;
                }
                if ($executableStatements === []) {
                    throw new \RuntimeException(sprintf('Migration contains no executable statements: %s', $file));
                }

                if (isset($applied[$version])) {
                    $this->verifyOrBackfillAppliedMigrationSteps(
                        $pdo,
                        $version,
                        $executableStatements
                    );
                    continue;
                }

                $stepNumber = 0;
                foreach ($executableStatements as $statement) {
                    $stepNumber++;
                    $this->runMigrationStep($pdo, $version, $stepNumber, $statement);
                }

                $insert = $pdo->prepare(
                    'INSERT INTO schema_migrations (version, description, created_at)
                     VALUES (:version, :description, NOW())'
                );
                $insert->execute([
                    'version' => $version,
                    'description' => $description,
                ]);

                $appliedCount++;
            }

            $unknownAppliedVersions = array_diff_key($applied, $seenVersions);
            if ($unknownAppliedVersions !== []) {
                throw new \RuntimeException(
                    'The database contains migration versions unknown to this application release.'
                );
            }

            return $appliedCount;
        } finally {
            $this->releaseMigrationLock($pdo, $lockName);
        }
    }

    public function allMigrationsAreComplete(string $migrationsDirectory): bool
    {
        try {
            $expectedSteps = $this->expectedMigrationSteps($migrationsDirectory);
            $pdo = $this->database->pdo();

            $migrationRows = $pdo->query(
                'SELECT version
                 FROM schema_migrations'
            )->fetchAll(PDO::FETCH_ASSOC);
            $recordedVersions = [];
            foreach ($migrationRows as $row) {
                if (is_array($row) && is_string($row['version'] ?? null)) {
                    $recordedVersions[$row['version']] = true;
                }
            }
            if (
                count($recordedVersions) !== count($expectedSteps)
                || array_diff_key($recordedVersions, $expectedSteps) !== []
                || array_diff_key($expectedSteps, $recordedVersions) !== []
            ) {
                return false;
            }

            $stepRows = $pdo->query(
                'SELECT version, step_number, statement_hash, status, completed_at
                 FROM schema_migration_steps'
            )->fetchAll(PDO::FETCH_ASSOC);
            $recordedSteps = [];
            foreach ($stepRows as $row) {
                if (!is_array($row) || !is_string($row['version'] ?? null)) {
                    continue;
                }

                $recordedSteps[$row['version']][] = $row;
            }
            if (array_diff_key($recordedSteps, $expectedSteps) !== []) {
                return false;
            }

            foreach ($expectedSteps as $version => $statementHashes) {
                if (!isset($recordedVersions[$version])) {
                    return false;
                }

                $versionSteps = $recordedSteps[$version] ?? [];
                if (count($versionSteps) !== count($statementHashes)) {
                    return false;
                }

                $stepsByNumber = [];
                foreach ($versionSteps as $step) {
                    $stepNumber = (int) ($step['step_number'] ?? 0);
                    if ($stepNumber <= 0 || isset($stepsByNumber[$stepNumber])) {
                        return false;
                    }

                    $stepsByNumber[$stepNumber] = $step;
                }

                foreach ($statementHashes as $index => $expectedHash) {
                    $stepNumber = $index + 1;
                    $step = $stepsByNumber[$stepNumber] ?? null;
                    if (
                        !is_array($step)
                        || ($step['status'] ?? null) !== 'complete'
                        || !is_string($step['statement_hash'] ?? null)
                        || !hash_equals($expectedHash, $step['statement_hash'])
                        || !is_string($step['completed_at'] ?? null)
                        || $step['completed_at'] === ''
                    ) {
                        return false;
                    }
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ensureMigrationTables(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                version VARCHAR(64) NOT NULL UNIQUE,
                description VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migration_steps (
                version VARCHAR(64) NOT NULL,
                step_number SMALLINT UNSIGNED NOT NULL,
                statement_hash CHAR(64) NOT NULL,
                status ENUM('running', 'complete', 'failed') NOT NULL,
                error_message VARCHAR(1000) NULL,
                started_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                PRIMARY KEY (version, step_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function expectedMigrationSteps(string $migrationsDirectory): array
    {
        if (!is_dir($migrationsDirectory) || !is_readable($migrationsDirectory)) {
            throw new \RuntimeException('Migration directory is missing or unreadable.');
        }

        $files = glob(rtrim($migrationsDirectory, '/\\') . '/*.php');
        if (!is_array($files) || $files === []) {
            throw new \RuntimeException('No migration files were found.');
        }

        sort($files, SORT_STRING);
        $expectedSteps = [];

        foreach ($files as $file) {
            $migration = require $file;
            if (!is_array($migration)) {
                throw new \RuntimeException('Invalid migration file.');
            }

            $version = $migration['version'] ?? null;
            $description = $migration['description'] ?? null;
            $statements = $migration['statements'] ?? null;
            if (
                !is_string($version)
                || !preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', $version)
                || !is_string($description)
                || strlen($description) > 255
                || !is_array($statements)
                || isset($expectedSteps[$version])
            ) {
                throw new \RuntimeException('Migration file has invalid or duplicate metadata.');
            }

            $statementHashes = [];
            foreach ($statements as $statement) {
                if (!is_string($statement) || trim($statement) === '') {
                    continue;
                }

                $statementHashes[] = hash('sha256', $statement);
            }

            if ($statementHashes === []) {
                throw new \RuntimeException('Migration contains no executable statements.');
            }

            $expectedSteps[$version] = $statementHashes;
        }

        return $expectedSteps;
    }

    /**
     * Older BracketBird runners recorded only schema_migrations. A recorded
     * version means that runner reached the marker insert after executing every
     * statement, so it is safe to create recovery metadata without replaying DDL.
     *
     * @param list<string> $statements
     */
    private function verifyOrBackfillAppliedMigrationSteps(
        PDO $pdo,
        string $version,
        array $statements
    ): void {
        $select = $pdo->prepare(
            'SELECT step_number, statement_hash, status, completed_at
             FROM schema_migration_steps
             WHERE version = :version
             ORDER BY step_number'
        );
        $select->execute(['version' => $version]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            throw new \RuntimeException(sprintf(
                'Could not inspect recovery metadata for applied migration %s.',
                $version
            ));
        }

        if ($rows === []) {
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }

            try {
                $insert = $pdo->prepare(
                    "INSERT INTO schema_migration_steps
                        (version, step_number, statement_hash, status, error_message, started_at, completed_at)
                     VALUES (:version, :step_number, :statement_hash, 'complete', NULL, NOW(), NOW())"
                );
                foreach ($statements as $index => $statement) {
                    $insert->execute([
                        'version' => $version,
                        'step_number' => $index + 1,
                        'statement_hash' => hash('sha256', $statement),
                    ]);
                }

                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return;
            } catch (\Throwable $throwable) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $throwable;
            }
        }

        if (count($rows) !== count($statements)) {
            throw new \RuntimeException(sprintf(
                'Applied migration %s has an unexpected recovery step count.',
                $version
            ));
        }

        foreach ($statements as $index => $statement) {
            $expectedStepNumber = $index + 1;
            $row = $rows[$index] ?? null;
            if (
                !is_array($row)
                || (int) ($row['step_number'] ?? 0) !== $expectedStepNumber
                || !is_string($row['statement_hash'] ?? null)
                || !hash_equals(hash('sha256', $statement), $row['statement_hash'])
                || ($row['status'] ?? null) !== 'complete'
                || !is_string($row['completed_at'] ?? null)
                || $row['completed_at'] === ''
            ) {
                throw new \RuntimeException(sprintf(
                    'Applied migration %s step %d has invalid recovery metadata.',
                    $version,
                    $expectedStepNumber
                ));
            }
        }
    }

    private function runMigrationStep(
        PDO $pdo,
        string $version,
        int $stepNumber,
        string $statement
    ): void {
        $statementHash = hash('sha256', $statement);
        $select = $pdo->prepare(
            'SELECT statement_hash, status
             FROM schema_migration_steps
             WHERE version = :version AND step_number = :step_number'
        );
        $select->execute([
            'version' => $version,
            'step_number' => $stepNumber,
        ]);
        $existing = $select->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing)) {
            if (!hash_equals((string) ($existing['statement_hash'] ?? ''), $statementHash)) {
                throw new \RuntimeException(sprintf(
                    'Migration %s step %d changed after execution began.',
                    $version,
                    $stepNumber
                ));
            }

            if (($existing['status'] ?? null) === 'complete') {
                return;
            }

            throw new \RuntimeException(sprintf(
                'Migration %s step %d is marked %s. Inspect the database before manually resolving the step record.',
                $version,
                $stepNumber,
                (string) ($existing['status'] ?? 'unknown')
            ));
        }

        $insert = $pdo->prepare(
            "INSERT INTO schema_migration_steps
                (version, step_number, statement_hash, status, error_message, started_at, completed_at)
             VALUES (:version, :step_number, :statement_hash, 'running', NULL, NOW(), NULL)"
        );
        $insert->execute([
            'version' => $version,
            'step_number' => $stepNumber,
            'statement_hash' => $statementHash,
        ]);

        try {
            $pdo->exec($statement);
        } catch (\Throwable $throwable) {
            try {
                $failed = $pdo->prepare(
                    "UPDATE schema_migration_steps
                     SET status = 'failed', error_message = :error_message
                     WHERE version = :version AND step_number = :step_number"
                );
                $failed->execute([
                    'error_message' => substr($throwable::class, 0, 1000),
                    'version' => $version,
                    'step_number' => $stepNumber,
                ]);
            } catch (\Throwable) {
                // Preserve the original migration failure if status recording also fails.
            }

            throw $throwable;
        }

        $complete = $pdo->prepare(
            "UPDATE schema_migration_steps
             SET status = 'complete', error_message = NULL, completed_at = NOW()
             WHERE version = :version AND step_number = :step_number"
        );
        $complete->execute([
            'version' => $version,
            'step_number' => $stepNumber,
        ]);
    }

    private function migrationLockName(PDO $pdo): string
    {
        $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        return 'bracketbird_migrate_' . substr(hash('sha256', $databaseName), 0, 40);
    }

    private function acquireMigrationLock(PDO $pdo, string $lockName): void
    {
        $statement = $pdo->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds)');
        $statement->bindValue('lock_name', $lockName);
        $statement->bindValue('timeout_seconds', self::LOCK_TIMEOUT_SECONDS, PDO::PARAM_INT);
        $statement->execute();

        if ((int) $statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Could not acquire the database migration lock.');
        }
    }

    private function releaseMigrationLock(PDO $pdo, string $lockName): void
    {
        try {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $statement->execute(['lock_name' => $lockName]);
        } catch (\Throwable) {
            // The connection closing also releases the advisory lock.
        }
    }

    /**
     * @return array<string, bool>
     */
    private function appliedVersions(PDO $pdo): array
    {
        $statement = $pdo->query('SELECT version FROM schema_migrations');
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $versions = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $version = $row['version'] ?? null;
            if (!is_string($version) || $version === '') {
                continue;
            }

            $versions[$version] = true;
        }

        return $versions;
    }
}
