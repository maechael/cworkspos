<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A list that runs one query per row is fine with 20 rows and slow with 20,000.
        // Tests fail on it; the live app only notes it in the log once per page, so nobody's screen breaks.
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            if (app()->runningUnitTests()) {
                throw new LazyLoadingViolationException($model, $relation);
            }

            static $seen = [];
            $key = $model::class.'::'.$relation;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                Log::warning("One query per row: load {$key} up front with with()", ['url' => request()?->fullUrl()]);
            }
        });

        // Any query slower than a second is written down with the page it came from, so a slow spot shows up as data grows
        DB::listen(function (QueryExecuted $query) {
            if ($query->time >= self::SLOW_QUERY_MS) {
                Log::warning('Slow query: '.round($query->time).' ms', ['sql' => $query->sql, 'url' => app()->runningInConsole() ? 'console' : request()->fullUrl()]);
            }
        });
    }

    private const SLOW_QUERY_MS = 1000;
}
