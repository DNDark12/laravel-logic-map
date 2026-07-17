<?php

namespace DNDark\LogicMap\Repositories\Sqlite;

use PDO;
use RuntimeException;

final class SqliteConnectionFactory
{
    private ?PDO $connection = null;

    public function __construct(private readonly string $databasePath)
    {
        if ($databasePath === '') {
            throw new RuntimeException('SQLite database path is required.');
        }
    }

    public function connection(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $connection = new PDO('sqlite:'.$this->databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec('PRAGMA busy_timeout = 5000');
        $connection->exec('PRAGMA journal_mode = WAL');
        $this->connection = $connection;

        return $connection;
    }
}
