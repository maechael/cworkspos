<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackup;
use Illuminate\Console\Command;

/** Makes a database backup. Runs by itself every week (routes/console.php); can also be run by hand. */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--if-due : Only when the last backup is a week old}';

    protected $description = 'Back up the whole database to storage/app/private/backups (keeps the newest '.DatabaseBackup::KEEP.')';

    public function handle(DatabaseBackup $backup): int
    {
        if ($this->option('if-due') && ! $backup->isDue()) {
            $this->info('Not due yet. The last backup was '.$backup->lastAt()->diffForHumans().'.');

            return self::SUCCESS;
        }

        $name = $backup->run($this->option('if-due') ? 'weekly' : 'command');
        $this->info("Backup made: {$name}");

        return self::SUCCESS;
    }
}
