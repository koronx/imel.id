<?php

namespace Imel\Shared;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thin PDO wrapper around the PostgreSQL mail store.
 */
final class Db
{
    private static ?Db $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $host = getenv('DB_HOST') ?: 'database';
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_NAME') ?: 'maildb';
        $user = getenv('DB_USER') ?: 'mailuser';
        $pass = getenv('DB_PASSWORD') ?: 'mailpassword';

        $lastError = '';
        // The database container may still be starting up: retry briefly.
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            try {
                $this->pdo = new PDO(
                    "pgsql:host={$host};port={$port};dbname={$name}",
                    $user,
                    $pass,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]
                );
                return;
            } catch (PDOException $e) {
                $lastError = $e->getMessage();
                sleep(2);
            }
        }

        throw new RuntimeException('Database connection failed: ' . $lastError);
    }

    public static function get(): Db
    {
        return self::$instance ??= new self();
    }

    /** Drop the cached handle (used by long running workers after a failure). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = [])
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    /** INSERT ... RETURNING id helper. */
    public function insert(string $sql, array $params = [])
    {
        return $this->value($sql, $params);
    }

    public function transaction(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
