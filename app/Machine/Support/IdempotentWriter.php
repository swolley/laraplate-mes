<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Writes a row only when its unique keys are free, on every supported driver. MySQL, MariaDB,
 * PostgreSQL, SQLite and SQL Server have insert-or-ignore; any other driver (Oracle) inserts and
 * treats a unique-key violation as "already stored". The violation is not caught on the others:
 * on PostgreSQL it would abort the surrounding transaction.
 */
final class IdempotentWriter
{
    private const array INSERT_OR_IGNORE_DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'];

    /**
     * @param  array<string, mixed>  $row
     * @return bool true when the row was stored, false when a unique key already held it
     */
    public function insert(Connection $connection, string $table, array $row): bool
    {
        if (in_array($connection->getDriverName(), self::INSERT_OR_IGNORE_DRIVERS, true)) {
            return $connection->table($table)->insertOrIgnore($row) > 0;
        }

        try {
            return $connection->table($table)->insert([$row]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
