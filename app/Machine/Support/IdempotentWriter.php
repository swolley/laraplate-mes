<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Writes a row only when its unique keys are free, on every supported driver. MySQL, MariaDB,
 * PostgreSQL and SQLite have insert-or-ignore (Laravel's SQL Server grammar does not); any other
 * driver (SQL Server, Oracle) inserts and treats a unique-key violation as "already stored". The
 * violation is not caught on the first group: on PostgreSQL it would abort the surrounding transaction.
 */
final class IdempotentWriter
{
    private const array INSERT_OR_IGNORE_DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlite'];

    private const int CHUNK = 500;

    /** Small enough for the 2100 parameter limit of SQL Server with rows of ten columns. */
    private const int SINGLE_STATEMENT_CHUNK = 200;

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

    /**
     * Writes many rows, skipping the ones whose unique keys are taken. Rows go in chunks, one insert-or-ignore
     * statement each where the driver has it; elsewhere one plain insert each, redone row by row only for a chunk
     * that holds a duplicate.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return int how many rows were new
     */
    public function insertMany(Connection $connection, string $table, array $rows): int
    {
        $stored = 0;

        if (in_array($connection->getDriverName(), self::INSERT_OR_IGNORE_DRIVERS, true)) {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $stored += $connection->table($table)->insertOrIgnore($chunk);
            }

            return $stored;
        }

        // One statement per chunk; a chunk that hits a duplicate is redone row by row (a statement that fails stores nothing).
        foreach (array_chunk($rows, self::SINGLE_STATEMENT_CHUNK) as $chunk) {
            try {
                $connection->table($table)->insert($chunk);
                $stored += count($chunk);
            } catch (UniqueConstraintViolationException) {
                foreach ($chunk as $row) {
                    $stored += $this->insert($connection, $table, $row) ? 1 : 0;
                }
            }
        }

        return $stored;
    }
}
