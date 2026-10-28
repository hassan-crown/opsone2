<?php

namespace App\Operations\Backup;

use Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * A logical, engine-native snapshot of one connection (ADR-0028): per table its DDL as the engine reports it and its
 * rows as NDJSON (a header line with the column names, then one JSON array per row, ordered by the primary key).
 *
 * The same encoder produces the backup file and the post-restore checksum, so "restored = backed up" is a byte-level
 * comparison. Strings that are not UTF-8 are wrapped as {"$b": base64}. No mariadb-dump binary is needed in the image.
 */
final class DatabaseSnapshot
{
    private const INSERT_CHUNK = 200;

    public function __construct(private readonly Connection $db) {}

    public static function on(?string $connection = null): self
    {
        return new self(DB::connection($connection));
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    public function driver(): string
    {
        return $this->db->getDriverName() === 'mariadb' ? 'mysql' : $this->db->getDriverName();
    }

    /**
     * Base tables in a stable order.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $names = [];
        foreach ($this->db->getSchemaBuilder()->getTables($this->db->getSchemaBuilder()->getCurrentSchemaName()) as $table) {
            $name = (string) $table['name'];
            if (! str_starts_with($name, 'sqlite_')) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }

    /**
     * DDL statements that recreate the table with its indexes and keys on the same engine.
     *
     * @return list<string>
     */
    public function ddl(string $table): array
    {
        if ($this->driver() === 'sqlite') {
            $rows = $this->db->select(
                "select type, sql from sqlite_master where tbl_name = ? and sql is not null order by case type when 'table' then 0 else 1 end, name",
                [$table],
            );

            return array_values(array_map(fn (object $r): string => (string) ((array) $r)['sql'], $rows));
        }

        if ($this->driver() === 'mysql') {
            $row = (array) $this->db->selectOne('show create table '.$this->db->getQueryGrammar()->wrapTable($table));

            return [(string) ($row['Create Table'] ?? array_values($row)[1])];
        }

        throw new RuntimeException("Backups support MariaDB/MySQL and SQLite, not {$this->driver()}.");
    }

    /**
     * Streams the table as NDJSON lines (header first).
     *
     * @return Generator<int, string>
     */
    public function lines(string $table): Generator
    {
        $columns = $this->db->getSchemaBuilder()->getColumnListing($table);
        yield self::encode(['columns' => $columns])."\n";

        $order = $this->orderColumns($table, $columns);
        $query = $this->db->table($table)->select($columns);
        foreach ($order as $column) {
            $query->orderBy($column);
        }

        $rows = $order === ['id'] ? $this->db->table($table)->select($columns)->lazyById(1000, 'id') : $query->lazy(1000);
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = self::wrap(((array) $row)[$column] ?? null);
            }
            yield self::encode($values)."\n";
        }
    }

    /**
     * Rows and sha256 of the table's NDJSON, computed without writing a file.
     *
     * @return array{rows: int, sha256: string}
     */
    public function checksum(string $table): array
    {
        $hash = hash_init('sha256');
        $rows = -1;
        foreach ($this->lines($table) as $line) {
            hash_update($hash, $line);
            $rows++;
        }

        return ['rows' => $rows, 'sha256' => hash_final($hash)];
    }

    /**
     * Drops every table of this connection (foreign keys off), used only by a confirmed restore.
     */
    public function dropAll(): void
    {
        $this->withoutForeignKeys(function (): void {
            foreach ($this->tables() as $table) {
                $this->db->statement('drop table if exists '.$this->db->getQueryGrammar()->wrapTable($table));
            }
        });
    }

    /**
     * @param  list<string>  $statements
     */
    public function create(array $statements): void
    {
        $this->withoutForeignKeys(function () use ($statements): void {
            foreach ($statements as $sql) {
                $this->db->statement($sql);
            }
        });
    }

    /**
     * Inserts NDJSON lines (header first) into an empty table.
     *
     * @param  iterable<string>  $lines
     */
    public function load(string $table, iterable $lines): int
    {
        $columns = null;
        $batch = [];
        $count = 0;

        $this->withoutForeignKeys(function () use ($table, $lines, &$columns, &$batch, &$count): void {
            foreach ($lines as $line) {
                $line = rtrim($line, "\n");
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if ($columns === null) {
                    $columns = is_array($decoded) && is_array($decoded['columns'] ?? null) ? array_values($decoded['columns']) : throw new RuntimeException("Bad header for {$table}.");

                    continue;
                }
                $batch[] = array_combine($columns, array_map(self::unwrap(...), (array) $decoded));
                $count++;
                if (count($batch) >= self::INSERT_CHUNK) {
                    $this->db->table($table)->insert($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->db->table($table)->insert($batch);
            }
        });

        return $count;
    }

    /**
     * Runs the reads inside one transaction so every table comes from the same InnoDB snapshot.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function consistently(callable $work): mixed
    {
        if ($this->driver() === 'mysql' && $this->db->transactionLevel() === 0) {
            $this->db->statement('set transaction isolation level repeatable read');
        }
        $this->db->beginTransaction();

        try {
            return $work();
        } finally {
            $this->db->rollBack();
        }
    }

    /**
     * @param  callable(): void  $work
     */
    private function withoutForeignKeys(callable $work): void
    {
        $schema = $this->db->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();

        try {
            $work();
        } finally {
            $schema->enableForeignKeyConstraints();
        }
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function orderColumns(string $table, array $columns): array
    {
        foreach ($this->db->getSchemaBuilder()->getIndexes($table) as $index) {
            if ($index['primary']) {
                return $index['columns'];
            }
        }

        return $columns;
    }

    private static function wrap(mixed $value): mixed
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            return ['$b' => base64_encode($value)];
        }

        return $value;
    }

    private static function unwrap(mixed $value): mixed
    {
        return is_array($value) && array_key_exists('$b', $value) ? base64_decode((string) $value['$b'], true) : $value;
    }

    /**
     * @throws JsonException
     */
    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
