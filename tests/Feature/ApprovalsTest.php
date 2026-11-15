<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A cashier's voids and returns need the owner's OK: their password or passkey at the counter,
 * or a request the owner answers from their own login anywhere. The owner can switch them off.
 * Run with a throwaway database, because phpunit.xml doesn't set one:
 *   PowerShell: $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'; php vendor/bin/phpunit tests/Feature/ApprovalsTest.php
 */
class ApprovalsTest extends TestCase
{
    private User $owner;

    private User $cashier;

    private Product $cola;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('Needs DB_CONNECTION=sqlite and DB_DATABASE=:memory:');
        }

        Log::spy(); // keep fake approvals out of the store's real log
        $this->artisan('migrate');
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->owner = User::create(['name' => 'Arnold', 'username' => 'arnold', 'role' => 'admin', 'is_active' => true, 'password' => 'owner-password', 'void_passkey' => '4827']);
        $this->cashier = User::create(['name' => 'Liza', 'username' => 'liza', 'role' => 'cashier', 'is_active' => true, 'password' => 'cashier-password']);
        $piece = Unit::create(['name' => 'Piece', 'abbreviation' => 'pc', 'allows_decimal' => false]);
        $this->cola = Product::create(['barcode' => '4801981118502', 'name' => 'Cola 1.5L', 'unit_id' => $piece->id, 'cost_price' => 62, 'selling_price' => 75, 'stock' => 100, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(?User $by = null, int $qty = 3): Sale
    {
        $id = $this->actingAs($by ?? $this->cashier)->postJson(route('sales.store'), [
            'items' => [['product_id' => $this->cola->id, 'quantity' => $qty]], 'amount_paid' => 1000, 'payment_method' => 'cash',
        ])->assertCreated()->json('id');

        return Sale::with('items')->find($id);
    }

    private function returnForm(Sale $sale, array $extra, int $qty = 1): array
    {
        return $extra + [
            'lines' => ['item-'.$sale->items->first()->id => ['qty' => $qty, 'restock' => 1]],
            'reason' => 'Changed their mind',
            'refund_method' => 'cash',
        ];
    }

    private function switchOff(): void
    {
        $this->actingAs($this->owner)->post(route('approvals.switch'), ['allowed' => 0])->assertSessionHas('success');
    }

    // ---- At the counter ----

    public function test_a_cashier_returns_an_item_with_the_owners_password_typed_at_the_counter(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->cashier)->post(route('sales.return.store', $sale), $this->returnForm($sale, ['approval_via' => 'here', 'credential' => 'owner-password']))
            ->assertRedirect(route('sales.show', $sale))->assertSessionHas('success');

        $this->assertDatabaseHas('sale_returns', ['sale_id' => $sale->id, 'user_id' => $this->cashier->id, 'approved_by' => $this->owner->id, 'refund' => 75]);
        $this->assertDatabaseHas('approval_requests', ['type' => 'return', 'status' => 'approved', 'requested_by' => $this->cashier->id,
            'decided_by' => $this->owner->id, 'decided_via' => 'password', 'sale_id' => $sale->id, 'amount' => 75]);
        $this->assertEquals(98, $this->cola->fresh()->stock);
    }

    public function test_a_wrong_password_returns_nothing(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->cashier)->post(route('sales.return.store', $sale), $this->returnForm($sale, ['approval_via' => 'here', 'credential' => 'cashier-password']))
            ->assertSessionHasErrors('credential');

        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertDatabaseCount('approval_requests', 0);
        $this->assertEquals(97, $this->cola->fresh()->stock);
    }

    public function test_a_cashier_voids_a_sale_with_the_owners_passkey(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->cashier)->get(route('sales.void.form', $sale))->assertOk()->assertSee('Why is it being voided?');
        $this->post(route('sales.void', $sale), ['reason' => 'Rang up the wrong items', 'approval_via' => 'here', 'credential' => '4827'])->assertSessionHas('success');

        $sale->refresh();
        $this->assertSame('voided', $sale->status);
        $this->assertSame($this->cashier->id, $sale->voided_by);
        $this->assertSame($this->owner->id, $sale->void_approved_by);
        $this->assertEquals(100, $this->cola->fresh()->stock);
        $this->assertDatabaseHas('approval_requests', ['type' => 'sale_void', 'decided_via' => 'passkey', 'status' => 'approved']);
        $this->get(route('sales.show', $sale))->assertSee('by Liza, approved by Arnold');
    }

    public function test_a_line_void_at_the_counter_is_kept_on_record(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/void-approval', ['credential' => '4827', 'lines' => [['product_id' => $this->cola->id, 'quantity' => 2]]])
            ->assertOk()->assertJsonPath('approved_by', 'Arnold');

        $this->assertDatabaseHas('approval_requests', ['type' => 'line_void', 'status' => 'approved', 'decided_via' => 'passkey',
            'summary' => 'Void Cola 1.5L x 2', 'amount' => 150]);
    }

    // ---- From the owner's login, anywhere ----

    public function test_a_return_sent_to_the_owners_phone_waits_then_happens_when_approved(): void
    {
        $sale = $this->sale();

        $response = $this->actingAs($this->cashier)->post(route('sales.return.store', $sale), $this->returnForm($sale, ['approval_via' => 'remote'], 2));
        $approval = ApprovalRequest::sole();
        $response->assertRedirect(route('approvals.show', $approval));

        // Nothing happens until the owner answers
        $this->assertSame('pending', $approval->status);
        $this->assertSame('Return 2 Cola 1.5L, refund ₱150.00', $approval->summary);
        $this->assertDatabaseCount('sale_returns', 0);
        $this->get(route('approvals.show', $approval))->assertOk()->assertSee('Waiting for the owner');
        $this->getJson(route('approvals.show', $approval))->assertJsonPath('status', 'pending');

        // The owner sees it on the dashboard and approves it
        $this->actingAs($this->owner)->getJson(route('approvals.index'))->assertJsonPath('waiting.0.summary', 'Return 2 Cola 1.5L, refund ₱150.00')
            ->assertJsonPath('waiting.0.cashier', 'Liza')->assertJsonPath('allowed', true);
        $this->postJson(route('approvals.approve', $approval))->assertOk()->assertJsonCount(0, 'waiting')
            ->assertJsonPath('notice', fn ($n) => str_contains($n, 'RT-261115-00001'));

        $this->assertDatabaseHas('sale_returns', ['sale_id' => $sale->id, 'user_id' => $this->cashier->id, 'approved_by' => $this->owner->id, 'refund' => 150]);
        $this->assertEquals(99, $this->cola->fresh()->stock);
        $this->actingAs($this->cashier)->getJson(route('approvals.show', $approval))
            ->assertJsonPath('status', 'approved')->assertJsonPath('decided_by', 'Arnold');
    }

    public function test_a_sale_void_sent_to_the_owners_phone(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->cashier)->post(route('sales.void', $sale), ['reason' => 'Customer changed mind at the counter', 'approval_via' => 'remote']);
        $approval = ApprovalRequest::sole();
        $this->assertSame('pending', $approval->status);
        $this->assertSame('completed', $sale->fresh()->status);

        $this->actingAs($this->owner)->post(route('approvals.approve', $approval))->assertSessionHas('success');

        $this->assertSame('voided', $sale->fresh()->status);
        $this->assertSame($this->owner->id, $sale->fresh()->void_approved_by);
        $this->assertEquals(100, $this->cola->fresh()->stock);
    }

    public function test_the_cashier_screen_asks_the_owners_phone_to_void_a_line(): void
    {
        $id = $this->actingAs($this->cashier)->postJson(route('approvals.store'), ['type' => 'line_void', 'lines' => [['product_id' => $this->cola->id, 'quantity' => 1]]])
            ->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('summary', 'Void Cola 1.5L x 1')->json('id');

        $this->actingAs($this->owner)->postJson(route('approvals.approve', $id))->assertOk();

        $this->actingAs($this->cashier)->getJson(route('approvals.show', $id))->assertJsonPath('status', 'approved');
    }

    public function test_a_declined_request_changes_nothing(): void
    {
        $sale = $this->sale();
        $this->actingAs($this->cashier)->post(route('sales.return.store', $sale), $this->returnForm($sale, ['approval_via' => 'remote']));
        $approval = ApprovalRequest::sole();

        $this->actingAs($this->owner)->postJson(route('approvals.decline', $approval))->assertOk();
        $this->postJson(route('approvals.approve', $approval))->assertUnprocessable()->assertJsonPath('notice', 'This request was already declined.');

        $this->assertDatabaseCount('sale_returns', 0);
        $this->actingAs($this->cashier)->getJson(route('approvals.show', $approval))->assertJsonPath('status', 'declined');
    }

    public function test_an_unanswered_request_runs_out_after_15_minutes(): void
    {
        $id = $this->actingAs($this->cashier)->postJson(route('approvals.store'), ['type' => 'clear_order', 'lines' => [['product_id' => $this->cola->id, 'quantity' => 1]]])->json('id');

        Carbon::setTestNow(now()->addMinutes(16));

        $this->actingAs($this->owner)->getJson(route('approvals.index'))->assertJsonCount(0, 'waiting');
        $this->postJson(route('approvals.approve', $id))->assertUnprocessable();
        $this->actingAs($this->cashier)->getJson(route('approvals.show', $id))->assertJsonPath('status', 'expired');
    }

    public function test_a_new_request_replaces_the_cashiers_last_one_and_the_cashier_can_cancel(): void
    {
        $line = ['type' => 'line_void', 'lines' => [['product_id' => $this->cola->id, 'quantity' => 1]]];
        $first = $this->actingAs($this->cashier)->postJson(route('approvals.store'), $line)->json('id');
        $second = $this->postJson(route('approvals.store'), $line)->json('id');

        $this->assertSame('cancelled', ApprovalRequest::find($first)->status);
        $this->postJson(route('approvals.cancel', $second))->assertJsonPath('status', 'cancelled');
        $this->assertSame(0, ApprovalRequest::waiting()->count());
    }

    public function test_when_the_sale_changed_before_approval_nothing_is_done(): void
    {
        $sale = $this->sale();
        $this->actingAs($this->cashier)->post(route('sales.return.store', $sale), $this->returnForm($sale, ['approval_via' => 'remote']));
        $approval = ApprovalRequest::sole();
        $this->actingAs($this->owner)->post(route('sales.void', $sale)); // the owner voided it meanwhile

        $this->postJson(route('approvals.approve', $approval))->assertOk()->assertJsonPath('error', true);

        $this->assertSame('failed', $approval->fresh()->status);
        $this->assertDatabaseCount('sale_returns', 0);
    }

    // ---- The owner's switch ----

    public function test_switched_off_cashiers_cannot_void_or_return_either_way(): void
    {
        $sale = $this->sale();
        $this->switchOff();

        $this->actingAs($this->cashier)->postJson('/pos/void-approval', ['credential' => 'owner-password', 'lines' => [['product_id' => $this->cola->id, 'quantity' => 1]]])
            ->assertStatus(423)->assertJsonPath('message', 'The owner has turned off voids and returns for now.');
        $this->postJson(route('approvals.store'), ['type' => 'line_void', 'lines' => [['product_id' => $this->cola->id, 'quantity' => 1]]])->assertStatus(423);
        $this->getJson(route('approvals.allowed'))->assertJsonPath('allowed', false);

        $this->post(route('sales.return.store', $sale), $this->returnForm($sale, ['approval_via' => 'here', 'credential' => 'owner-password']))->assertSessionHasErrors('credential');
        $this->post(route('sales.return.store', $sale), $this->returnForm($sale, ['approval_via' => 'remote']))->assertSessionHasErrors('approval');
        $this->post(route('sales.void', $sale), ['reason' => 'x', 'approval_via' => 'here', 'credential' => '4827'])->assertSessionHasErrors('credential');
        $this->get(route('sales.show', $sale))->assertSee('turned off voids and returns')->assertDontSee('Return items');

        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertDatabaseCount('approval_requests', 0);
    }

    public function test_switching_off_declines_what_is_waiting_and_the_owner_can_still_act_and_turn_it_back_on(): void
    {
        $sale = $this->sale();
        $this->actingAs($this->cashier)->post(route('sales.return.store', $sale), $this->returnForm($sale, ['approval_via' => 'remote']));

        $this->switchOff();
        $this->assertSame('declined', ApprovalRequest::sole()->status);

        // The owner, logged in, is not held back by their own switch
        $this->actingAs($this->owner)->post(route('sales.return.store', $sale), $this->returnForm($sale, []))->assertSessionHas('success');

        $this->post(route('approvals.switch'), ['allowed' => 1]);
        $this->actingAs($this->cashier)->post(route('sales.return.store', $sale), $this->returnForm($sale, ['approval_via' => 'here', 'credential' => 'owner-password']))
            ->assertSessionHas('success');
    }

    // ---- Who can do what ----

    public function test_only_the_owner_answers_and_a_cashier_only_sees_their_own_requests(): void
    {
        $id = $this->actingAs($this->cashier)->postJson(route('approvals.store'), ['type' => 'line_void', 'lines' => [['product_id' => $this->cola->id, 'quantity' => 1]]])->json('id');
        $other = User::create(['name' => 'Ana', 'username' => 'ana', 'role' => 'cashier', 'is_active' => true, 'password' => 'another-password']);

        $this->actingAs($this->cashier)->postJson(route('approvals.approve', $id))->assertForbidden();
        $this->getJson(route('approvals.index'))->assertForbidden();
        $this->post(route('approvals.switch'), ['allowed' => 0])->assertForbidden();
        $this->actingAs($other)->getJson(route('approvals.show', $id))->assertForbidden();
        $this->postJson(route('approvals.cancel', $id))->assertForbidden();
    }

    public function test_a_cashier_opens_another_cashiers_receipt_by_its_number_for_a_return(): void
    {
        $other = User::create(['name' => 'Ana', 'username' => 'ana', 'role' => 'cashier', 'is_active' => true, 'password' => 'another-password']);
        $sale = $this->sale($other);

        // Not by guessing the address
        $this->actingAs($this->cashier)->get(route('sales.show', $sale))->assertForbidden();
        $this->get(route('sales.return', $sale))->assertForbidden();

        // By the number on the receipt, typed with or without "OR-"
        $to = $this->get(route('sales.find', ['receipt' => ' or-'.substr($sale->receipt_no, 3).' ']))->assertRedirect()->headers->get('Location');
        $this->get(route('sales.find', ['receipt' => substr($sale->receipt_no, 3)]))->assertRedirect();
        $this->get($to)->assertOk()->assertSee($sale->receipt_no)->assertSee('Return items');

        $form = URL::temporarySignedRoute('sales.return.store', now()->addHour(), ['sale' => $sale]);
        $this->post($form, $this->returnForm($sale, ['approval_via' => 'here', 'credential' => '4827']))->assertSessionHas('success');
        $this->assertDatabaseHas('sale_returns', ['sale_id' => $sale->id, 'user_id' => $this->cashier->id]);

        $this->from(route('sales.index'))->get(route('sales.find', ['receipt' => 'OR-999999-99999']))->assertRedirect(route('sales.index'))->assertSessionHas('error');
    }

    public function test_the_owner_sees_waiting_requests_on_the_dashboard_menu_and_bell(): void
    {
        $this->actingAs($this->cashier)->postJson(route('approvals.store'), ['type' => 'line_void', 'lines' => [['product_id' => $this->cola->id, 'quantity' => 1]]]);

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSee('data-react="ApprovalsPanel"', false)
            ->assertSee('1 waiting', false)
            ->assertSee('waiting for your approval');
        $this->get(route('approvals.index'))->assertOk()->assertSee('History');
    }
}
