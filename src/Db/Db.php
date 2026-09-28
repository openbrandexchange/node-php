<?php

declare(strict_types=1);

namespace Obx\Node\Db;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper for SQLite and MySQL/MariaDB. All SQL in the node is written to run on both.
 */
final class Db
{
    public readonly PDO $pdo;
    public readonly string $driver;
    private int $depth = 0;

    public function __construct(string $dsn, ?string $user = null, ?string $password = null)
    {
        if (str_starts_with($dsn, 'sqlite:')) {
            $path = substr($dsn, 7);
            if ($path !== ':memory:' && $path !== '') {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0770, true);
                }
            }
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        $this->pdo = new PDO($dsn, $user, $password, $options);
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 15000');
            $this->pdo->exec('PRAGMA foreign_keys = OFF');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
        } else {
            $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_bin");
            $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
            $this->pdo->exec("SET SESSION time_zone = '+00:00'");
        }
    }

    public function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($key, is_bool($v) ? (int) $v : $v, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<int|string, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<mixed>
     */
    public function column(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array<string, mixed> $row */
    public function insert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' .
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols)) . ')';
        $this->run($sql, $row);
    }

    /**
     * @param array<string, mixed> $set
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $set, array $where): int
    {
        $params = [];
        $parts = [];
        foreach ($set as $k => $v) {
            $parts[] = $k . ' = :s_' . $k;
            $params['s_' . $k] = $v;
        }
        $conds = [];
        foreach ($where as $k => $v) {
            $conds[] = $k . ' = :w_' . $k;
            $params['w_' . $k] = $v;
        }
        return $this->run('UPDATE ' . $table . ' SET ' . implode(', ', $parts) . ' WHERE ' . implode(' AND ', $conds), $params)->rowCount();
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        $params = [];
        $conds = [];
        foreach ($where as $k => $v) {
            $conds[] = $k . ' = :w_' . $k;
            $params['w_' . $k] = $v;
        }
        return $this->run('DELETE FROM ' . $table . ' WHERE ' . implode(' AND ', $conds), $params)->rowCount();
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Runs $fn in a transaction. Nested calls join the outer transaction. SQLite uses
     * BEGIN IMMEDIATE (take the write lock up front); MySQL serialises writers on a lock row
     * so change-feed sequence numbers commit in order.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function tx(callable $fn): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $fn();
            } finally {
                $this->depth--;
            }
        }
        if ($this->isSqlite()) {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
            $this->pdo->exec("SELECT v FROM obx_settings WHERE k = 'write_lock' FOR UPDATE");
        }
        $this->depth = 1;
        try {
            $result = $fn();
            if ($this->isSqlite()) {
                $this->pdo->exec('COMMIT');
            } else {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($this->isSqlite()) {
                $this->pdo->exec('ROLLBACK');
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    public function inTx(): bool
    {
        return $this->depth > 0;
    }

    /** Placeholder list "?, ?, ?" for an IN clause. */
    public static function in(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }
}
