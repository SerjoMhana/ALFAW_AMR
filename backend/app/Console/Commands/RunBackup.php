<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Takes one night's backup: the database and the uploaded files, in one file.
 *
 * Two things decide whether a backup is worth anything — that it ran, and that
 * it can be read back. So this writes the archive, then opens it again and
 * checks the dump inside carries the tables it should, and says how big it is.
 * A run that produced an unreadable file reports failure rather than success.
 */
class RunBackup extends Command
{
    protected $signature = 'backup:run
        {--keep= : days of archives to keep, overriding the configured value}
        {--quiet-success : say nothing unless something went wrong}';

    protected $description = 'Back up the database and the uploaded files';

    public function handle(): int
    {
        $startedAt = microtime(true);
        $folder = (string) config('backup.path');
        File::ensureDirectoryExists($folder);

        $name = 'vis-'.now()->format('Y-m-d-His');
        $archive = "{$folder}/{$name}.zip";
        $dump = "{$folder}/{$name}.sql";

        try {
            $this->say('Dumping the database...');
            $tables = $this->dumpDatabase($dump);

            $this->say('Packing...');
            $files = $this->pack($archive, $dump, $name);

            // Written and then read back: an archive nobody can open is not a
            // backup, and the night it matters is the wrong time to find out.
            $this->verify($archive, $tables);
        } catch (Throwable $e) {
            File::delete([$dump, $archive]);
            $this->components->error('Backup failed: '.$e->getMessage());
            Log::error('Backup failed', ['exception' => $e]);

            return self::FAILURE;
        } finally {
            File::delete($dump);
        }

        $removed = $this->prune($folder);
        $seconds = round(microtime(true) - $startedAt, 1);
        $size = $this->humanSize(filesize($archive));

        Log::info('Backup written', ['file' => basename($archive), 'size' => $size]);

        if (! $this->option('quiet-success')) {
            $this->components->info("Backed up to {$name}.zip ({$size}, {$tables} tables, {$files} files, {$seconds}s).");

            if ($removed > 0) {
                $this->line("  <fg=gray>{$removed} archive(s) older than {$this->keepDays()} days removed.</>");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return int the number of tables written
     */
    private function dumpDatabase(string $path): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) === 'sqlite') {
            // A file-backed database is its own dump; an in-memory one, as the
            // tests use, has no file to copy and goes through PHP instead.
            if (is_string($config['database']) && is_file($config['database'])) {
                File::copy($config['database'], $path);

                return count(DB::select("select name from sqlite_master where type='table'"));
            }

            return $this->dumpWithPhp($path);
        }

        $binary = (string) config('backup.mysqldump');

        return is_file($binary)
            ? $this->dumpWithMysqldump($binary, $config, $path)
            : $this->dumpWithPhp($path);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function dumpWithMysqldump(string $binary, array $config, string $path): int
    {
        $arguments = [
            $binary,
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            '--single-transaction',
            '--quick',
            '--default-character-set=utf8mb4',
            // Rebuilding into a database that already has tables is the normal
            // case when restoring, so the dump replaces rather than collides.
            '--add-drop-table',
            '--result-file='.$path,
            $config['database'],
        ];

        // The password goes through the environment, never the command line,
        // where anyone listing processes could read it.
        $process = new Process($arguments, env: ['MYSQL_PWD' => (string) $config['password']]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('mysqldump: '.trim($process->getErrorOutput()));
        }

        return substr_count((string) file_get_contents($path), 'CREATE TABLE');
    }

    /**
     * The fallback where mysqldump is not installed: slower, but it needs
     * nothing beyond PHP and works on any host.
     */
    private function dumpWithPhp(string $path): int
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $pdo = DB::connection()->getPdo();
        $handle = fopen($path, 'w');

        fwrite($handle, $sqlite
            ? "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n"
            : "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $count = 0;

        foreach ($this->tablesToDump($sqlite) as [$table, $create]) {
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");

            foreach (DB::table($table)->cursor() as $record) {
                $values = collect((array) $record)
                    ->map(fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value))
                    ->implode(', ');

                fwrite($handle, "INSERT INTO `{$table}` VALUES ({$values});\n");
            }

            fwrite($handle, "\n");
            $count++;
        }

        fwrite($handle, $sqlite
            ? "COMMIT;\nPRAGMA foreign_keys=ON;\n"
            : "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        return $count;
    }

    /**
     * The table names and their CREATE statements, in whichever dialect this
     * connection speaks.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function tablesToDump(bool $sqlite): array
    {
        if ($sqlite) {
            return collect(DB::select(
                "select name, sql from sqlite_master where type='table' and name not like 'sqlite_%' and sql is not null",
            ))->map(fn ($row) => [$row->name, $row->sql])->all();
        }

        return collect(DB::select('show tables'))->map(function ($row) {
            $table = array_values((array) $row)[0];

            return [$table, array_values((array) DB::select("show create table `{$table}`")[0])[1]];
        })->all();
    }

    /**
     * @return int the number of uploaded files included
     */
    private function pack(string $archive, string $dump, string $name): int
    {
        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create [{$archive}].");
        }

        $zip->addFile($dump, "{$name}/database.sql");

        $excluded = collect(config('backup.files.exclude', []))
            ->map(fn (string $path) => rtrim(str_replace('\\', '/', $path), '/'));

        $files = 0;

        foreach (config('backup.files.include', []) as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                $real = str_replace('\\', '/', $file->getPathname());

                if ($excluded->contains(fn (string $skip) => str_starts_with($real, $skip))) {
                    continue;
                }

                $relative = str_replace(str_replace('\\', '/', storage_path()).'/', '', $real);
                $zip->addFile($file->getPathname(), "{$name}/files/{$relative}");
                $files++;
            }
        }

        $zip->close();

        return $files;
    }

    private function verify(string $archive, int $tables): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw new \RuntimeException('The archive could not be reopened.');
        }

        $name = collect(range(0, $zip->numFiles - 1))
            ->map(fn (int $i) => $zip->getNameIndex($i))
            ->first(fn (string $entry) => str_ends_with($entry, 'database.sql'));

        if ($name === null) {
            $zip->close();

            throw new \RuntimeException('The archive holds no database dump.');
        }

        $head = (string) $zip->getFromName($name, 4096);
        $zip->close();

        if ($tables > 0 && ! str_contains($head, 'CREATE TABLE') && ! str_contains($head, 'SQLite format')) {
            throw new \RuntimeException('The dump inside the archive does not look like one.');
        }
    }

    private function prune(string $folder): int
    {
        $cutoff = now()->subDays($this->keepDays());
        $removed = 0;

        foreach (File::glob("{$folder}/vis-*.zip") as $file) {
            if (File::lastModified($file) < $cutoff->getTimestamp()) {
                File::delete($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function keepDays(): int
    {
        return (int) ($this->option('keep') ?: config('backup.keep_days', 30));
    }

    private function say(string $message): void
    {
        if (! $this->option('quiet-success')) {
            $this->line("  <fg=gray>{$message}</>");
        }
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }
}
