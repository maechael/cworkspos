<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Basket;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The owner's say over a cashier's voids and returns, given two ways:
 * - at the counter: the owner types their account password or passkey on the cashier's screen;
 * - from anywhere: the cashier sends a request, and the owner approves or declines it from their own login.
 * When the owner is asleep or away they turn on auto-approve: cashiers' voids and returns then go through at once,
 * each one kept in the history as auto-approved for the owner to check later.
 * An owner logged in themselves never needs approval.
 */
class Approvals
{
    private const MAX_WRONG_TRIES = 5;

    private const LOCK_SECONDS = 300;

    public function __construct(private SaleReturns $returns, private SaleVoids $voids) {}

    /** True while the owner has auto-approve on (asleep or unavailable). */
    public static function autoApprove(): bool
    {
        return Setting::get('approval_mode', 'ask') === 'auto';
    }

    /**
     * Turns auto-approve on or off. Turning it on also approves anything already waiting,
     * since the owner is saying they won't be there to answer.
     *
     * @return int how many waiting requests were approved
     */
    public function setAuto(bool $on, User $owner): int
    {
        Setting::put(['approval_mode' => $on ? 'auto' : 'ask']);
        Log::info('Auto-approve turned '.($on ? 'on' : 'off'), ['by' => $owner->name]);

        $approved = 0;
        if ($on) {
            foreach (ApprovalRequest::waiting()->oldest('id')->get() as $waiting) {
                $approved += $this->approve($waiting, null, 'auto')->status === 'approved' ? 1 : 0;
            }
        }

        return $approved;
    }

    /**
     * Checks a password or passkey typed at the counter. Wrong tries are limited per cashier, because a passkey
     * is short and the cashier could otherwise keep guessing. Every try is logged, never what was typed.
     *
     * @return array{0: User, 1: string} the owner who approved, and 'password' or 'passkey'
     */
    public function checkCredential(string $credential, User $cashier, string $what, array $context = [], string $field = 'credential'): array
    {
        $key = 'void-approval:'.$cashier->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_WRONG_TRIES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages([$field => "Too many wrong tries. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.'])->status(429);
        }

        foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
            if ($via = $admin->approvalMethod($credential)) {
                RateLimiter::clear($key);
                Log::info("{$what} approved", ['cashier' => $cashier->name, 'approved_by' => $admin->name] + $context);

                return [$admin, $via];
            }
        }

        RateLimiter::hit($key, self::LOCK_SECONDS);
        Log::warning("{$what} refused: wrong supervisor password or passkey", ['cashier' => $cashier->name] + $context);

        throw ValidationException::withMessages([$field => "That isn't a supervisor's password or passkey."]);
    }

    /** Keeps a counter approval on record next to the remote ones. */
    public function recordCounter(string $type, User $cashier, User $owner, string $via, ?Sale $sale, string $summary, float $amount, array $payload = [], ?string $message = null): ApprovalRequest
    {
        return ApprovalRequest::create([
            'type' => $type, 'status' => 'approved', 'requested_by' => $cashier->id, 'sale_id' => $sale?->id,
            'summary' => $summary, 'amount' => $amount, 'payload' => $payload,
            'decided_by' => $owner->id, 'decided_via' => $via, 'decided_at' => now(), 'message' => $message,
        ]);
    }

    /**
     * A request for the owner to answer from their login. A cashier has one at a time; a new one replaces the last.
     * With auto-approve on, it is approved (and carried out) at once; check the returned status.
     */
    public function request(string $type, User $cashier, ?Sale $sale, string $summary, float $amount, array $payload = []): ApprovalRequest
    {
        ApprovalRequest::where('requested_by', $cashier->id)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'message' => 'Replaced by a newer request.']);

        $request = ApprovalRequest::create([
            'type' => $type, 'status' => 'pending', 'requested_by' => $cashier->id, 'sale_id' => $sale?->id,
            'summary' => $summary, 'amount' => $amount, 'payload' => $payload,
        ]);
        Log::info('Approval requested', ['id' => $request->id, 'cashier' => $cashier->name, 'summary' => $summary]);

        return self::autoApprove() ? $this->approve($request, null, 'auto') : $request;
    }

    /**
     * Yes: from the owner (via 'remote'), or from auto-approve (via 'auto', no owner).
     * Returns and sale voids are carried out now, in the cashier's name.
     */
    public function approve(ApprovalRequest $request, ?User $owner, string $via = 'remote'): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $owner, $via) {
            $request = ApprovalRequest::lockForUpdate()->findOrFail($request->id)->settle();
            $this->assertOpen($request);

            $message = null;
            try {
                if ($request->type === 'return') {
                    $p = $request->payload;
                    $return = $this->returns->process($request->sale, $p['lines'], $p['reason'], $p['note'] ?? null, $p['refund_method'], $request->requested_by, $owner?->id);
                    $message = "{$return->return_no} saved. Give back ".peso($return->refund).' by '.strtoupper($return->refund_method).'.';
                } elseif ($request->type === 'sale_void') {
                    $this->voids->void($request->sale, $request->requested_by, $owner?->id);
                    $message = 'The sale was voided and its items are back in stock.';
                }
            } catch (ValidationException $e) {
                // Something changed since it was asked (the sale was voided, say); nothing was done
                $request->update(['status' => 'failed', 'decided_by' => $owner?->id, 'decided_via' => $via, 'decided_at' => now(),
                    'message' => collect($e->errors())->flatten()->first()]);

                return $request;
            }

            $request->update(['status' => 'approved', 'decided_by' => $owner?->id, 'decided_via' => $via, 'decided_at' => now(), 'message' => $message]);
            Log::info($via === 'auto' ? 'Auto-approved (owner away)' : 'Approval given from the owner\'s login',
                ['id' => $request->id, 'approved_by' => $owner?->name, 'summary' => $request->summary]);

            return $request;
        });
    }

    public function decline(ApprovalRequest $request, User $owner, ?string $reason = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $owner, $reason) {
            $request = ApprovalRequest::lockForUpdate()->findOrFail($request->id)->settle();
            $this->assertOpen($request);
            $request->update(['status' => 'declined', 'decided_by' => $owner->id, 'decided_via' => 'remote', 'decided_at' => now(),
                'message' => $reason ?: 'The owner declined.']);
            Log::info('Approval declined', ['id' => $request->id, 'by' => $owner->name, 'summary' => $request->summary]);

            return $request;
        });
    }

    public function cancel(ApprovalRequest $request): ApprovalRequest
    {
        return DB::transaction(function () use ($request) {
            $request = ApprovalRequest::lockForUpdate()->findOrFail($request->id)->settle();
            if ($request->status === 'pending') {
                $request->update(['status' => 'cancelled', 'message' => 'The cashier cancelled it.']);
            }

            return $request;
        });
    }

    /** The cashier screen sends ids; the record gets the names and prices on file, not what the browser said. */
    public function describeLines(array $lines): array
    {
        $products = Product::whereIn('id', array_filter(array_column($lines, 'product_id')))->get()->keyBy('id');
        $baskets = Basket::whereIn('id', array_filter(array_column($lines, 'basket_id')))->get()->keyBy('id');

        $names = [];
        $amount = 0.0;
        foreach ($lines as $line) {
            $qty = (float) $line['quantity'];
            if (empty($line['basket_id'])) {
                $product = $products[$line['product_id']] ?? null;
                $names[] = ($product->name ?? "Product #{$line['product_id']}").' x '.$line['quantity'];
                $amount += $qty * (float) ($product->selling_price ?? 0);
            } else {
                $basket = $baskets[$line['basket_id']] ?? null;
                $names[] = 'Basket '.($basket->name ?? "#{$line['basket_id']}").' x '.$line['quantity'];
                $amount += $qty * (float) ($basket->price ?? 0);
            }
        }

        return [$names, round($amount, 2)];
    }

    private function assertOpen(ApprovalRequest $request): void
    {
        if ($request->status !== 'pending') {
            $was = ['approved' => 'already approved', 'declined' => 'already declined', 'cancelled' => 'cancelled by the cashier',
                'expired' => 'too old: nobody answered within '.ApprovalRequest::EXPIRES_MINUTES.' minutes', 'failed' => 'already answered'][$request->status] ?? $request->status;
            throw ValidationException::withMessages(['approval' => "This request was {$was}."]);
        }
    }
}
