<?php
declare(strict_types=1);

namespace Ko4\Core;

use Ko4\DatabaseException;

class Database
{
    private \PDO $pdo;

    public function __construct(string $path)
    {
        try {
            $this->pdo = new \PDO('sqlite:' . $path);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA foreign_keys=ON');
            $this->pdo->exec('PRAGMA synchronous=NORMAL');
        } catch (\PDOException $e) {
            throw new DatabaseException("Cannot open database at {$path}: " . $e->getMessage());
        }
    }

    public function migrate(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_version (
            version   INTEGER PRIMARY KEY,
            applied   TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $current = (int)($this->query("SELECT MAX(version) as v FROM schema_version")[0]['v'] ?? 0);

        $migrations = $this->getMigrations();
        foreach ($migrations as $version => $sql) {
            if ($version > $current) {
                $this->pdo->exec($sql);
                $this->exec("INSERT INTO schema_version (version) VALUES (?)", [$version]);
            }
        }
    }

    private function getMigrations(): array
    {
        return [
            1 => "
                CREATE TABLE IF NOT EXISTS packages (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    name        TEXT NOT NULL,
                    version     TEXT NOT NULL,
                    release     INTEGER NOT NULL DEFAULT 1,
                    arch        TEXT NOT NULL DEFAULT 'any',
                    description TEXT,
                    url         TEXT,
                    license     TEXT,
                    size        INTEGER DEFAULT 0,
                    installed   INTEGER DEFAULT 0,
                    install_reason TEXT DEFAULT 'explicit',
                    install_date   TEXT,
                    build_date     TEXT,
                    packager    TEXT,
                    groups      TEXT,
                    provides    TEXT,
                    conflicts   TEXT,
                    replaces    TEXT,
                    checksum    TEXT,
                    UNIQUE(name, version, release)
                );

                CREATE TABLE IF NOT EXISTS dependencies (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    package_id  INTEGER NOT NULL REFERENCES packages(id) ON DELETE CASCADE,
                    dep_name    TEXT NOT NULL,
                    dep_version TEXT,
                    dep_type    TEXT NOT NULL DEFAULT 'required',
                    UNIQUE(package_id, dep_name, dep_type)
                );

                CREATE TABLE IF NOT EXISTS files (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    package_id  INTEGER NOT NULL REFERENCES packages(id) ON DELETE CASCADE,
                    path        TEXT NOT NULL,
                    checksum    TEXT,
                    file_type   TEXT DEFAULT 'file',
                    UNIQUE(package_id, path)
                );

                CREATE TABLE IF NOT EXISTS repos (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    name        TEXT NOT NULL UNIQUE,
                    url         TEXT NOT NULL,
                    priority    INTEGER DEFAULT 50,
                    enabled     INTEGER DEFAULT 1,
                    gpg_key     TEXT,
                    last_sync   TEXT,
                    package_count INTEGER DEFAULT 0
                );

                CREATE TABLE IF NOT EXISTS transaction_log (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    action      TEXT NOT NULL,
                    package     TEXT NOT NULL,
                    old_version TEXT,
                    new_version TEXT,
                    timestamp   TEXT NOT NULL DEFAULT (datetime('now')),
                    user        TEXT,
                    reason      TEXT,
                    success     INTEGER DEFAULT 1
                );

                CREATE TABLE IF NOT EXISTS pinned (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    name        TEXT NOT NULL UNIQUE,
                    version     TEXT,
                    reason      TEXT,
                    pinned_at   TEXT NOT NULL DEFAULT (datetime('now'))
                );

                CREATE TABLE IF NOT EXISTS config (
                    key         TEXT PRIMARY KEY,
                    value       TEXT
                );

                CREATE INDEX IF NOT EXISTS idx_packages_name    ON packages(name);
                CREATE INDEX IF NOT EXISTS idx_packages_installed ON packages(installed);
                CREATE INDEX IF NOT EXISTS idx_files_path       ON files(path);
                CREATE INDEX IF NOT EXISTS idx_deps_package     ON dependencies(package_id);
                CREATE INDEX IF NOT EXISTS idx_txlog_timestamp  ON transaction_log(timestamp);
            ",
            2 => "
                CREATE TABLE IF NOT EXISTS repo_packages (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    repo_id     INTEGER NOT NULL REFERENCES repos(id) ON DELETE CASCADE,
                    name        TEXT NOT NULL,
                    version     TEXT NOT NULL,
                    release     INTEGER DEFAULT 1,
                    arch        TEXT DEFAULT 'any',
                    description TEXT,
                    url         TEXT,
                    license     TEXT,
                    size        INTEGER DEFAULT 0,
                    checksum    TEXT,
                    filename    TEXT,
                    has_source  INTEGER DEFAULT 0,
                    has_binary  INTEGER DEFAULT 1,
                    provides    TEXT,
                    deps        TEXT,
                    build_deps  TEXT,
                    UNIQUE(repo_id, name, version, release)
                );

                CREATE INDEX IF NOT EXISTS idx_repopkg_name ON repo_packages(name);
            ",
        ];
    }

    public function query(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            throw new DatabaseException("Query failed: " . $e->getMessage() . "\nSQL: $sql");
        }
    }

    public function exec(string $sql, array $params = []): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            throw new DatabaseException("Exec failed: " . $e->getMessage() . "\nSQL: $sql");
        }
    }

    public function lastInsertId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }
}
