<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/VoidApprovalTest.php
 */
class VoidApprovalTest extends TestCase
{
    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        // Never run migrations against the store's real database
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }

        // Test runs must not leave fake approvals in the store's real log file
        Log::spy();

        $this->artisan('migrate');
        $this->owner = User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'admin', 'is_active' => true, 'password' => 'owner-password', 'void_passkey' => '4827']);
        $this->cashier = User::create(['name' => 'Kim', 'username' => 'kim', 'role' => 'cashier', 'is_active' => true, 'password' => 'cashier-password']);
    }

    private function approve(string $credential)
    {
        return $this->actingAs($this->cashier)->postJson('/pos/void-approval', [
            'credential' => $credential,
            'lines' => [['product_id' => 1, 'quantity' => 2]],
        ]);
    }

    public function test_a_supervisors_password_or_passkey_approves(): void
    {
        $this->approve('owner-password')->assertOk()->assertJsonPath('approved_by', 'Owner');
        $this->approve('4827')->assertOk()->assertJsonPath('approved_by', 'Owner');
    }

    public function test_nobody_else_can_approve(): void
    {
        $this->approve('wrong')->assertUnprocessable();
        $this->approve('cashier-password')->assertUnprocessable();
    }

    public function test_a_passkey_on_a_cashier_account_approves_nothing(): void
    {
        $this->cashier->update(['void_passkey' => '1111']);

        $this->approve('1111')->assertUnprocessable();
    }

    public function test_a_deactivated_supervisor_can_no_longer_approve(): void
    {
        $this->owner->update(['is_active' => false]);

        $this->approve('owner-password')->assertUnprocessable();
        $this->approve('4827')->assertUnprocessable();
    }

    public function test_wrong_tries_lock_the_check_even_for_the_right_password(): void
    {
        foreach (range(1, 5) as $try) {
            $this->approve('guess-'.$try)->assertUnprocessable();
        }

        $this->approve('owner-password')->assertTooManyRequests()->assertJsonFragment(['message' => 'Too many wrong tries. Try again in 5 minutes.']);
    }

    public function test_a_right_answer_resets_the_wrong_tries(): void
    {
        foreach (range(1, 4) as $try) {
            $this->approve('guess-'.$try)->assertUnprocessable();
        }
        $this->approve('4827')->assertOk();

        foreach (range(1, 4) as $try) {
            $this->approve('guess-'.$try)->assertUnprocessable();
        }
        $this->approve('4827')->assertOk();
    }

    public function test_one_cashiers_wrong_tries_dont_lock_another(): void
    {
        foreach (range(1, 5) as $try) {
            $this->approve('guess-'.$try)->assertUnprocessable();
        }

        $other = User::create(['name' => 'Ana', 'username' => 'ana', 'role' => 'cashier', 'is_active' => true, 'password' => 'another-password']);
        $this->actingAs($other)->postJson('/pos/void-approval', ['credential' => '4827', 'lines' => [['product_id' => 1, 'quantity' => 1]]])->assertOk();
    }

    public function test_every_attempt_is_logged_with_who_and_what_but_never_the_password(): void
    {
        $unit = DB::table('units')->insertGetId(['name' => 'Piece', 'abbreviation' => 'pc', 'allows_decimal' => false]);
        $cola = DB::table('products')->insertGetId(['name' => 'Cola 1.5L', 'unit_id' => $unit, 'cost_price' => 50, 'selling_price' => 75, 'stock' => 10, 'reorder_level' => 0, 'is_active' => true]);
        $try = fn (string $credential) => $this->actingAs($this->cashier)->postJson('/pos/void-approval', ['credential' => $credential, 'lines' => [['product_id' => $cola, 'quantity' => 2]]]);

        $try('a-wrong-guess')->assertUnprocessable();
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => str_contains($message, 'refused')
            && $context['cashier'] === 'Kim'
            && $context['lines'] === ['Cola 1.5L x 2']
            && ! str_contains(json_encode($context), 'a-wrong-guess'));

        $try('4827')->assertOk();
        Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context) => $message === 'Line void approved'
            && $context['cashier'] === 'Kim'
            && $context['approved_by'] === 'Owner'
            && $context['lines'] === ['Cola 1.5L x 2']
            && ! str_contains(json_encode($context), '4827'));
    }

    public function test_it_needs_a_login_and_something_to_void(): void
    {
        $this->postJson('/pos/void-approval', ['credential' => '4827', 'lines' => [['product_id' => 1, 'quantity' => 1]]])->assertUnauthorized();

        $this->actingAs($this->cashier)->postJson('/pos/void-approval', ['credential' => '4827', 'lines' => []])->assertUnprocessable();
        $this->postJson('/pos/void-approval', ['lines' => [['product_id' => 1, 'quantity' => 1]]])->assertUnprocessable();
    }

    public function test_the_passkey_is_stored_hashed_and_never_sent_out(): void
    {
        $this->assertTrue(Hash::check('4827', $this->owner->fresh()->void_passkey));
        $this->assertNotSame('4827', DB::table('users')->where('id', $this->owner->id)->value('void_passkey'));
        $this->assertArrayNotHasKey('void_passkey', $this->owner->fresh()->toArray());
    }

    public function test_the_screen_is_told_who_needs_approval(): void
    {
        $this->actingAs($this->cashier)->get('/pos')->assertOk()->assertViewHas('props', fn ($props) => $props['isSupervisor'] === false);
        $this->actingAs($this->owner)->get('/pos')->assertOk()->assertViewHas('props', fn ($props) => $props['isSupervisor'] === true);
    }

    private function saveAccount(User $user, array $changes = [])
    {
        return $this->actingAs($this->owner)->put(route('users.update', $user), $changes + [
            'name' => $user->name, 'username' => $user->username, 'role' => $user->role, 'is_active' => 1,
        ]);
    }

    public function test_the_owner_sets_a_passkey_and_a_blank_keeps_it(): void
    {
        $this->saveAccount($this->owner, ['void_passkey' => '9055'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('9055', $this->owner->fresh()->void_passkey));

        $this->saveAccount($this->owner, ['void_passkey' => ''])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('9055', $this->owner->fresh()->void_passkey));
    }

    public function test_a_passkey_must_be_long_enough_and_is_for_supervisors_only(): void
    {
        $this->saveAccount($this->owner, ['void_passkey' => '123'])->assertSessionHasErrors('void_passkey');

        $this->saveAccount($this->cashier, ['void_passkey' => '5555'])->assertSessionHasNoErrors();
        $this->assertNull($this->cashier->fresh()->void_passkey);
    }

    public function test_only_admin_rows_on_the_accounts_page_offer_a_passkey(): void
    {
        $this->actingAs($this->owner)->get('/users')->assertOk()
            ->assertSee('Void passkey for Owner')
            ->assertDontSee('Void passkey for Kim')
            ->assertSee('Admins only');
    }
}
