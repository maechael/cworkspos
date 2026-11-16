<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBackup;
use Throwable;

/** The owner's list of database backups: download one, or make one now. */
class BackupController extends Controller
{
    public function index(DatabaseBackup $backup)
    {
        return view('backups.index', [
            'backups' => $backup->list(),
            'lastAt' => $backup->lastAt(),
            'nextAt' => $backup->lastAt()?->copy()->addDays(DatabaseBackup::EVERY_DAYS),
        ]);
    }

    public function store(DatabaseBackup $backup)
    {
        try {
            $name = $backup->run('manual');
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', "The backup couldn't be made: ".$e->getMessage());
        }

        return back()->with('success', "Backup made: {$name}. Download it and keep a copy off this computer too.");
    }

    public function download(string $file, DatabaseBackup $backup)
    {
        $path = $backup->path($file);
        abort_unless($path, 404);

        return response()->download($path, $file, ['Content-Type' => 'application/gzip']);
    }
}
