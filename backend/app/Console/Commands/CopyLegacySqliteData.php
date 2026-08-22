<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the school's data out of the old SQLite file and into MySQL.
 *
 * Run once, against a freshly migrated MySQL database. It copies whatever the
 * two schemas have in common, table by table, and says what it did — a school's
 * records are not something to move on faith.
 */
class CopyLegacySqliteData extends Command
{
    protected $signature = 'legacy:copy
        {--connection=legacy_sqlite : the connection holding the old data}
        {--pretend : count the rows without writing anything}';

    protected $description = 'Copy the rows from the old SQLite database into the current one';

    /** Written by the framework, not by the school. */
    private const SKIP = ['migrations'];

    public function handle(): int
    {
        $from = DB::connection($this->option('connection'));
        $to = DB::connection();

        if ($to->getDriverName() === 'sqlite') {
            $this->error('The current connection is still SQLite. Point DB_CONNECTION at MySQL first.');

            return self::FAILURE;
        }

        $tables = collect(Schema::connection($this->option('connection'))->getTableListing())
            ->map(fn (string $name) => str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : $name)
            ->reject(fn (string $name) => in_array($name, self::SKIP, true) || str_starts_with($name, 'sqlite_'))
            ->values();

        $this->info(sprintf(
            'Copying from %s into %s (%s).',
            $this->option('connection'),
            $to->getDatabaseName(),
            $to->getDriverName(),
        ));

        // Rows arrive in whatever order the tables are listed, so the keys are
        // let be until the whole set is in place.
        $to->statement('SET FOREIGN_KEY_CHECKS=0');

        $copied = 0;
        $skipped = [];
        $rows = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $skipped[] = "{$table} (no such table here)";

                continue;
            }

            $sourceCount = $from->table($table)->count();

            if ($sourceCount === 0) {
                continue;
            }

            // Only the columns both schemas agree on: a column dropped since is
            // not worth failing the whole move for.
            $columns = array_values(array_intersect(
                Schema::connection($this->option('connection'))->getColumnListing($table),
                Schema::getColumnListing($table),
            ));

            if ($columns === []) {
                $skipped[] = "{$table} (no columns in common)";

                continue;
            }

            if ($this->option('pretend')) {
                $rows[] = [$table, $sourceCount, count($columns), 'would copy'];

                continue;
            }

            $to->table($table)->truncate();

            $from->table($table)->orderBy('rowid')->chunk(500, function ($chunk) use ($to, $table, $columns): void {
                $to->table($table)->insert(
                    collect($chunk)
                        ->map(fn ($row) => collect((array) $row)->only($columns)->all())
                        ->all(),
                );
            });

            $landed = $to->table($table)->count();
            $rows[] = [$table, $sourceCount, $landed, $sourceCount === $landed ? 'ok' : 'MISMATCH'];
            $copied += $landed;
        }

        $to->statement('SET FOREIGN_KEY_CHECKS=1');

        $this->table(['table', 'source rows', 'copied', 'result'], $rows);

        foreach ($skipped as $note) {
            $this->warn('skipped: '.$note);
        }

        $this->info($this->option('pretend')
            ? 'Nothing was written.'
            : "Done: {$copied} rows in {$this->tableCount($rows)} tables.");

        return collect($rows)->contains(fn (array $row) => $row[3] === 'MISMATCH')
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function tableCount(array $rows): int
    {
        return count($rows);
    }
}
