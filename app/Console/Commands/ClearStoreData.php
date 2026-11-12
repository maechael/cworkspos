<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Database\Seeders\StarterListsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Empties the store for a fresh start: products, sales, deliveries, baskets, stock counts and stock history.
 * Accounts (with their passwords and passkeys) and the POS style settings stay.
 */
class ClearStoreData extends Command
{
    protected $signature = 'store:clear
        {--force : Skip the confirmation (for scripts)}
        {--demo : Add the sample products and sales again afterwards (staging only)}';

    protected $description = 'Delete all products, sales, deliveries and stock history, keeping the accounts';

    /** Children before parents, so the order also works where foreign keys can't be switched off. */
    private const TABLES = [
        'sale_items' => 'sale lines',
        'sales' => 'sales',
        'basket_items' => 'basket items',
        'baskets' => 'baskets',
        'stock_count_items' => 'stock count lines',
        'stock_counts' => 'stock counts',
        'stock_movements' => 'stock history entries',
        'delivery_items' => 'delivery lines',
        'deliveries' => 'deliveries',
        'product_barcodes' => 'extra barcodes',
        'products' => 'products',
        'suppliers' => 'suppliers',
        'categories' => 'categories',
        'units' => 'units',
    ];

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();

        $this->line("Database: <comment>{$database}</comment>");
        $this->table(['Will be deleted', 'Rows'], collect(self::TABLES)
            ->map(fn ($label, $table) => [$label, number_format(DB::table($table)->count())])->values()->all());
        $this->line('Kept: '.DB::table('users')->count().' accounts (passwords and passkeys unchanged) and the POS style settings.');
        $this->line('Categories, units and the walk-in supplier are put back as the starting lists.');
        $this->newLine();

        if (! $this->option('force')) {
            $name = basename($database); // a SQLite path becomes just its file name
            $typed = $this->ask("This cannot be undone. Type the database name ({$name}) to delete everything above");
            if (trim((string) $typed) !== $name) {
                $this->warn('Nothing was deleted.');

                return self::FAILURE;
            }
        }

        Schema::disableForeignKeyConstraints();
        try {
            foreach (array_keys(self::TABLES) as $table) {
                DB::table($table)->truncate(); // also restarts the numbering, so the first new sale is 1 again
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        Cache::flush(); // cached dashboard and cashier best-seller totals
        $this->callSilently('db:seed', ['--class' => StarterListsSeeder::class, '--force' => true]);

        if ($this->option('demo')) {
            $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            $this->info('The store was emptied and the sample data added again.');
        } else {
            $this->info('The store is empty. The accounts can sign in and start adding products.');
        }

        return self::SUCCESS;
    }
}
