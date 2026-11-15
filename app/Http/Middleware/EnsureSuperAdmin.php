<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** POS style and Scanner test are for the setup account only, not the owner. */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->isSuperAdmin()) {
            abort(403, 'Only the super admin can open this page.');
        }

        return $next($request);
    }
}
