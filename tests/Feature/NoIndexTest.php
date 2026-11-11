<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/NoIndexTest.php
 */
class NoIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }
        $this->artisan('migrate');
    }

    public function test_search_engines_are_told_not_to_list_any_page(): void
    {
        $header = 'noindex, nofollow, noarchive, nosnippet';

        $this->get('/login')->assertOk()->assertHeader('X-Robots-Tag', $header)->assertSee('<meta name="robots" content="noindex', false);
        $this->get('/')->assertRedirect()->assertHeader('X-Robots-Tag', $header);
        $this->get('/no-such-page')->assertNotFound()->assertHeader('X-Robots-Tag', $header);

        $admin = User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertHeader('X-Robots-Tag', $header)->assertSee('<meta name="robots" content="noindex', false);
        $this->actingAs($admin)->getJson(route('products.catalog'))->assertOk()->assertHeader('X-Robots-Tag', $header);
    }
}
