<?php

namespace Tests\Feature;

use App\Http\Controllers\PosSettingsController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/PosStyleTest.php
 */
class PosStyleTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Never run migrations against the store's real database
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }

        $this->artisan('migrate');
        $this->admin = User::create(['name' => 'Owner', 'username' => 'owner', 'email' => 'owner@example.test', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
        $this->super = User::create(['name' => 'Setup', 'username' => 'setup', 'role' => 'admin', 'is_active' => true, 'password' => 'secret123']);
        $this->super->forceFill(['is_super_admin' => true])->save();
    }

    private User $super;

    /** The props the cashier screen would be mounted with. */
    private function propsFrom(string $url): array
    {
        $response = $this->actingAs($this->super)->get($url)->assertOk();

        return $response->viewData('props');
    }

    public function test_a_saved_color_from_before_the_green_brand_falls_back_to_fresh_green(): void
    {
        Setting::put(['pos_style' => 'cafe', 'pos_scheme' => 'strawberry-milk']);

        $current = PosSettingsController::current();

        $this->assertSame('cafe', $current['style']);
        $this->assertSame('fresh-green', $current['scheme']);
    }

    public function test_every_style_opens_in_every_green(): void
    {
        foreach (array_keys(config('pos.styles')) as $style) {
            foreach (array_keys(config('pos.schemes')) as $scheme) {
                $props = $this->propsFrom("/pos?style={$style}&scheme={$scheme}");

                $this->assertSame($style, $props['style']);
                $this->assertSame($scheme, $props['scheme']);
                $this->assertTrue($props['preview']);
            }
        }
    }

    public function test_an_unknown_color_in_the_preview_link_keeps_the_saved_one(): void
    {
        Setting::put(['pos_scheme' => 'deep-forest']);

        $this->assertSame('deep-forest', $this->propsFrom('/pos?style=kiosk&scheme=ube')['scheme']);
    }

    public function test_saving_accepts_a_green_and_refuses_an_old_color(): void
    {
        $this->actingAs($this->super)
            ->putJson(route('settings.pos.update'), ['business_type' => 'grocery', 'style' => 'scanpro', 'scheme' => 'leaf-green'])
            ->assertOk()
            ->assertJsonPath('current.scheme', 'leaf-green');

        $this->actingAs($this->super)
            ->putJson(route('settings.pos.update'), ['business_type' => 'grocery', 'style' => 'scanpro', 'scheme' => 'daylight'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheme');
    }

    public function test_the_settings_page_opens(): void
    {
        $this->actingAs($this->super)->get(route('settings.pos'))->assertOk();
    }

    public function test_only_the_super_admin_has_pos_style_and_scanner_test(): void
    {
        $this->actingAs($this->super)->get('/dashboard')->assertOk()->assertSee('POS style')->assertSee('Scanner test');
        $this->actingAs($this->super)->get(route('settings.scanner'))->assertOk();

        // The owner: no links, no pages, no saving, no preview links on the cashier screen
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertDontSee('POS style')->assertDontSee('Scanner test');
        $this->actingAs($this->admin)->get(route('settings.pos'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('settings.scanner'))->assertForbidden();
        $this->actingAs($this->admin)
            ->putJson(route('settings.pos.update'), ['business_type' => 'grocery', 'style' => 'kiosk', 'scheme' => 'leaf-green'])
            ->assertForbidden();
        $props = $this->actingAs($this->admin)->get('/pos?style=kiosk')->assertOk()->viewData('props');
        $this->assertFalse($props['preview']);
        $this->assertNull($props['settingsUrl']);

        $this->actingAs($this->cashier())->get(route('settings.scanner'))->assertForbidden();
    }

    public function test_the_owner_cannot_see_or_change_the_super_admin_account(): void
    {
        $this->actingAs($this->admin)->get(route('users.index'))->assertOk()->assertDontSee('value="setup"', false)->assertSee('value="owner"', false);
        $this->actingAs($this->admin)
            ->put(route('users.update', $this->super), ['name' => 'X', 'username' => 'setup', 'role' => 'cashier', 'is_active' => '1'])
            ->assertNotFound();
        $this->assertSame('admin', $this->super->fresh()->role);

        $this->actingAs($this->super)->get(route('users.index'))->assertOk()->assertSee('Super admin');
    }

    public function test_the_command_makes_and_removes_a_super_admin(): void
    {
        $this->artisan('user:super-admin', ['username' => 'dev', '--name' => 'Developer'])
            ->expectsQuestion('Password for the new account (at least 6 characters)', 'devpass1')
            ->expectsQuestion('Type it again', 'devpass1')
            ->assertSuccessful();
        $dev = User::where('username', 'dev')->first();
        $this->assertTrue($dev->isSuperAdmin());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('devpass1', $dev->password));

        $this->artisan('user:super-admin', ['username' => 'owner'])->assertSuccessful();
        $this->assertTrue($this->admin->fresh()->isSuperAdmin());

        $this->artisan('user:super-admin', ['username' => 'owner', '--remove' => true])->assertSuccessful();
        $this->assertFalse($this->admin->fresh()->isSuperAdmin());
        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_only_the_cashier_screen_has_the_menu_controls(): void
    {
        $this->actingAs($this->admin)->get('/pos')->assertOk()
            ->assertSee('data-pos-nav-set="mini"', false)
            ->assertSee('data-pos-nav-set="open"', false)
            ->assertSee('data-pos-nav-set="hidden"', false)
            ->assertSee("localStorage.getItem('pos-nav')", false);

        $this->get('/sales')->assertOk()
            ->assertDontSee('data-pos-nav-set', false)
            ->assertDontSee('pos-nav', false);
    }

    public function test_the_color_lists_in_php_and_javascript_match(): void
    {
        $schemes = json_decode(file_get_contents(resource_path('js/components/pos/schemes.json')), true);

        $this->assertSame(array_keys(config('pos.schemes')), array_keys($schemes));
    }

    public function test_php_and_react_set_the_same_color_variables(): void
    {
        preg_match_all("/'(--pos-[a-z-]+)'/", file_get_contents(resource_path('js/components/pos/theme.js')), $react);
        preg_match_all('/(--pos-[a-z-]+):/', PosSettingsController::cssVariables('fresh-green'), $php);

        $this->assertNotEmpty($react[1]);
        $this->assertEqualsCanonicalizing($react[1], $php[1]);
    }

    private function cashier(string $name = 'Kimberley Reyes'): User
    {
        return User::create(['name' => $name, 'username' => 'kim', 'email' => 'kim@example.test', 'role' => 'cashier', 'is_active' => true, 'password' => 'secret123']);
    }

    public function test_a_cashier_sees_their_own_name_in_the_sidebar_on_every_page(): void
    {
        $this->actingAs($this->cashier());

        foreach (['/pos', '/sales'] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('Kimberley Reyes')
                ->assertSee('>KR</span>', false)
                ->assertDontSee('<span class="block text-sm font-bold ">Arnold Bulan</span>', false)
                ->assertDontSee('>AB</span>', false);
        }
    }

    public function test_the_owner_still_sees_the_store_name(): void
    {
        $this->actingAs($this->admin);

        foreach (['/pos', '/sales'] as $page) {
            $this->get($page)->assertOk()->assertSee('>AB</span>', false)->assertSee('Mini Grocery');
        }
    }

    public function test_a_cashiers_pages_wear_the_saved_green_and_the_owners_stay_blue(): void
    {
        Setting::put(['pos_scheme' => 'leaf-green']);
        $leafSidebar = '--pos-side:#1E211C';

        $cashier = $this->cashier();
        $sale = DB::table('sales')->insertGetId([
            'receipt_no' => 'R-1', 'user_id' => $cashier->id, 'subtotal' => 10, 'discount' => 0, 'total' => 10,
            'amount_paid' => 10, 'change' => 0, 'payment_method' => 'cash', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($cashier);
        foreach (['/pos', '/sales', "/sales/{$sale}"] as $page) {
            $this->get($page)->assertOk()
                ->assertSee($leafSidebar, false)
                ->assertSee('pos-chrome', false)
                ->assertSee('pos-sidebar', false)
                ->assertSee('family=Poppins', false)
                ->assertDontSee('app-sidebar', false)
                ->assertDontSee('<button type="button" data-theme-toggle', false);
        }
        // The POS draws its own theme; the pages around it get it from the layout
        $this->get('/sales')->assertSee('pos-theme', false)->assertSee('data-pos-nav-set="hidden"', false);

        $this->actingAs($this->admin)->get('/sales')->assertOk()
            ->assertDontSee($leafSidebar, false)
            ->assertDontSee('pos-chrome', false)
            ->assertDontSee('pos-theme', false)
            ->assertSee('app-sidebar', false)
            ->assertSee('<button type="button" data-theme-toggle', false);
    }
}
