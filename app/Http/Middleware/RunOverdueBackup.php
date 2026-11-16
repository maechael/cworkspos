<?php

namespace App\Http\Middleware;

use App\Services\DatabaseBackup;
use Closure;
use Illuminate\Http\Request;

/**
 * The weekly backup's safety net: when nobody set up the scheduler, the first signed-in page after a
 * week makes the backup, after the page has already been sent, so nobody waits for it.
 */
class RunOverdueBackup
{
    public function __construct(private DatabaseBackup $backup) {}

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($request->user() && ! app()->runningUnitTests() && $this->backup->isDue()) {
            app()->terminating(fn () => $this->backup->runIfDue());
        }

        return $response;
    }
}
