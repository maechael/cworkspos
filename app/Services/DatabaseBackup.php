<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Whole-database backups as gzipped .sql files in storage/app/private/backups (never public).
 * Written in PHP, so they work the same on XAMPP and on a server with no mysqldump.
 * Restore one with phpMyAdmin (Import) or: gunzip -c file.sql.gz | mysql -u root dbname
 */
class DatabaseBackup
{
    public const DIR = 'backups';

    /** How many backups are kept. Older ones are deleted after each new one. */
    public const KEEP = 8;

    /** A new backup is due when the last one is this many days old. */
    public const EVERY_DAYS = 7;

    /** Tables whose rows are only temporary: their structure is saved, their rows are not. */
    private const SKIP_ROWS = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

    /** Makes a backup now. Returns its file name. */
    public function run(string $why = 'manual'): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);
        $name = 'backup-'.now()->format('Y-m-d-His').'.sql.gz';
        $path = $disk->path(self::DIR.'/'.$name);

        $gz = gzopen($path, 'wb6');
        if (! $gz) {
            throw new RuntimeException("Can't write the backup file in ".dirname($path));
        }

        try {
            $this->dump($gz);
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($path);
            throw $e;
        }
        gzclose($gz);

        Setting::put(['last_backup_at' => now()->toIso8601String()]);
        $this->prune();
        Log::info('Database backup made', ['file' => $name, 'why' => $why, 'bytes' => filesize($path)]);

        return $name;
    }

    /** Runs a backup when the last one is a week old (or there is none). Safe to call often. */
    public function runIfDue(): ?string
    {
        if (! $this->isDue()) {
            return null;
        }
        $lock = Cache::lock('db-backup', 900);
        if (! $lock->get()) {
            return null; // another request is making one
        }
        try {
            return $this->isDue() ? $this->run('weekly') : null;
        } catch (\Throwable $e) {
            Log::error('Weekly database backup failed', ['error' => $e->getMessage()]);

            return null;
        } finally {
            $lock->release();
        }
    }

    public function isDue(): bool
    {
        $last = $this->lastAt();

        return ! $last || $last->lte(now()->subDays(self::EVERY_DAYS));
    }

    public function lastAt(): ?Carbon
    {
        $value = Setting::get('last_backup_at');

        return $value ? Carbon::parse($value) : null;
    }

    /** @return list<array{name:string, size:int, at:Carbon}> newest first */
    public function list(): array
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::DIR))
            ->filter(fn ($f) => str_ends_with($f, '.sql.gz'))
            ->map(fn ($f) => ['name' => basename($f), 'size' => $disk->size($f), 'at' => Carbon::createFromTimestamp($disk->lastModified($f))])
            ->sortByDesc('name')
            ->values()
            ->all();
    }

    public function path(string $name): ?string
    {
        $disk = Storage::disk('local');

        return preg_match('/^backup-[0-9-]+\.sql\.gz$/', $name) && $disk->exists(self::DIR.'/'.$name) ? $disk->path(self::DIR.'/'.$name) : null;
    }

    private function prune(): void
    {
        foreach (array_slice($this->list(), self::KEEP) as $old) {
            Storage::disk('local')->delete(self::DIR.'/'.$old['name']);
        }
    }

    /** @param  resource  $gz */
    private function dump($gz): void
    {
        $db = DB::connection();
        $driver = $db->getDriverName();
        $pdo = $db->getPdo();
        $q = fn (string $name) => $driver === 'sqlite' ? '"'.str_replace('"', '""', $name).'"' : '`'.str_replace('`', '``', $name).'`';

        gzwrite($gz, '-- '.config('app.name').' database backup, '.now()->format('Y-m-d H:i:s')."\n");
        gzwrite($gz, '-- Database: '.$db->getDatabaseName()."\n\n");
        gzwrite($gz, $driver === 'sqlite' ? "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n" : "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = $driver === 'sqlite'
            ? collect($db->select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
                ->mapWithKeys(fn ($t) => [$t->name => $t->sql])
            : collect($db->select('SHOW FULL TABLES'))
                ->filter(fn ($t) => array_values((array) $t)[1] === 'BASE TABLE')
                ->mapWithKeys(fn ($t) => [array_values((array) $t)[0] => array_values((array) $db->selectOne('SHOW CREATE TABLE '.$q(array_values((array) $t)[0])))[1]]);

        foreach ($tables as $table => $create) {
            gzwrite($gz, "DROP TABLE IF EXISTS {$q($table)};\n{$create};\n\n");
            if (in_array($table, self::SKIP_ROWS, true)) {
                continue;
            }

            $batch = [];
            $columns = null;
            foreach ($db->cursor("SELECT * FROM {$q($table)}") as $row) {
                $row = (array) $row;
                $columns ??= implode(', ', array_map($q, array_keys($row)));
                $batch[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row)).')';
                if (count($batch) === 200) {
                    gzwrite($gz, "INSERT INTO {$q($table)} ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, "INSERT INTO {$q($table)} ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
            }
            gzwrite($gz, "\n");
        }

        gzwrite($gz, $driver === 'sqlite' ? "COMMIT;\n" : "SET FOREIGN_KEY_CHECKS=1;\n");
    }
}
