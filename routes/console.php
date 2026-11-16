<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Weekly database backup, Sunday 2 AM. Needs `php artisan schedule:run` every minute (cron, or Windows Task Scheduler).
// Without that, the app still makes one by itself the first time it is used after a week (RunOverdueBackup).
Schedule::command('db:backup --if-due')->weeklyOn(0, '02:00')->withoutOverlapping();
