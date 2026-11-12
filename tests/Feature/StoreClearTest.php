<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * php artisan store:clear empties the store but keeps the accounts, and seeding is clean by default.
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/StoreClearTest.php
 */
class StoreClearTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }
        $this->artisan('migrate');
    }

    public function test_seeding_gives_the_accounts_and_starter_lists_but_no_sample_data(): void
    {
        $this->seed();

        $this->assertSame(['arnold', 'cashier'], User::orderBy('username')->pluck('username')->all());
        $this->assertSame(4, Unit::count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Sale::count());
    }

    public function test_seeding_again_keeps_a_changed_password(): void
    {
        $this->seed();
        User::where('username', 'arnold')->first()->update(['password' => 'new-secret-9']);

        $this->seed();

        $this->assertTrue(Hash::check('new-secret-9', User::where('username', 'arnold')->value('password')));
    }

    public function test_store_clear_deletes_the_store_data_and_keeps_accounts_and_settings(): void
    {
        $this->seed();
        $this->seed(DemoSeeder::class);
        $this->assertGreaterThan(0, Product::count());
        $this->assertGreaterThan(0, Sale::count());
        User::where('username', 'arnold')->first()->update(['password' => 'new-secret-9']);
        Setting::put(['pos_style' => 'cafe']);

        $this->artisan('store:clear', ['--force' => true])->assertSuccessful();

        foreach (['products', 'product_barcodes', 'sales', 'sale_items', 'deliveries', 'delivery_items', 'stock_movements', 'stock_counts', 'baskets', 'basket_items'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "$table should be empty");
        }
        $this->assertSame(2, User::count());
        $this->assertTrue(Hash::check('new-secret-9', User::where('username', 'arnold')->value('password')));
        $this->assertSame('cafe', Setting::where('key', 'pos_style')->value('value'));
        $this->assertSame(4, Unit::count(), 'the starting units come back, so products can be added');
    }

    public function test_store_clear_deletes_nothing_unless_the_database_name_is_typed(): void
    {
        $this->seed();
        $this->seed(DemoSeeder::class);
        $products = Product::count();

        $this->artisan('store:clear')
            ->expectsQuestion('This cannot be undone. Type the database name (:memory:) to delete everything above', 'yes')
            ->expectsOutput('Nothing was deleted.')
            ->assertFailed();

        $this->assertSame($products, Product::count());
    }

    public function test_store_clear_with_demo_puts_fresh_sample_data_back(): void
    {
        $this->seed();
        $this->seed(DemoSeeder::class);

        $this->artisan('store:clear', ['--force' => true, '--demo' => true])->assertSuccessful();

        $this->assertGreaterThan(0, Product::count());
        $this->assertSame(1, Product::min('id'), 'numbering restarted');
    }
}
