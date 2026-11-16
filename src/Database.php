<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * SQLite connection, schema and transactions.
 *
 * The schema is created automatically on the first connection (CREATE TABLE IF NOT EXISTS).
 */
class Database
{
    private ?PDO $pdo = null;

    private readonly string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * The connection, created (and migrated) on first use.
     */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->connect();
            $this->migrate($this->pdo);
        }

        return $this->pdo;
    }

    /**
     * Runs the callback in a transaction: commit on success, rollback on any error.
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function connect(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->path, options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function migrate(PDO $pdo): void
    {
        // A harvest is unique per member + colony + date + weight, so the same
        // harvest in two files (even in another format) is stored only once.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS members (
                id INTEGER PRIMARY KEY,
                naam VARCHAR(255) NOT NULL UNIQUE
            );

            CREATE TABLE IF NOT EXISTS beehouses (
                id INTEGER PRIMARY KEY,
                naam VARCHAR(255) NOT NULL UNIQUE
            );

            CREATE TABLE IF NOT EXISTS harvests (
                id           INTEGER PRIMARY KEY,
                member_id    INTEGER NOT NULL REFERENCES members(id),
                beehouse_id  INTEGER NOT NULL REFERENCES beehouses(id),
                colony_code  TEXT    NOT NULL,
                harvest_date TEXT    NOT NULL,
                weight_grams INTEGER NOT NULL CHECK (weight_grams > 0),
                source_file  TEXT    NOT NULL,
                source_line  INTEGER NOT NULL,
                UNIQUE (member_id, colony_code, harvest_date, weight_grams)
            );

            CREATE TABLE IF NOT EXISTS import_log (
                id          INTEGER PRIMARY KEY,
                source_file TEXT    NOT NULL,
                source_line INTEGER NOT NULL,
                reason      TEXT    NOT NULL,
                raw_row     TEXT    NOT NULL
            );
        SQL);
    }

    /**
     * Drops all tables and recreates them empty (used by import --fresh).
     */
    public function reset(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('DROP TABLE IF EXISTS import_log');
        $pdo->exec('DROP TABLE IF EXISTS harvests');
        $pdo->exec('DROP TABLE IF EXISTS beehouses');
        $pdo->exec('DROP TABLE IF EXISTS members');

        $this->migrate($pdo);
    }
}
