<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDO;
use PDOException;
use RuntimeException;

final class PdoFactory
{
    public static function createFromEnv(): PDO
    {
        $host = self::requiredEnv('DB_HOST');
        $port = self::requiredEnv('DB_PORT');
        $dbName = self::requiredEnv('DB_NAME');
        $user = self::requiredEnv('DB_USER');
        $password = self::requiredEnv('DB_PASSWORD');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbName);

        try {
            return new PDO(
                $dsn,
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to connect to database.', 0, $e);
        }
    }

    private static function requiredEnv(string $key): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            throw new RuntimeException(sprintf('Missing required environment variable: %s', $key));
        }

        return $value;
    }
}
