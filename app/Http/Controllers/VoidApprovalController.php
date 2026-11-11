<?php

namespace App\Http\Controllers;

use App\Models\Basket;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Checks a supervisor's password or passkey before the cashier screen takes a line off a sale.
 * Wrong tries are limited, because a passkey is short and the cashier could otherwise keep guessing.
 */
class VoidApprovalController extends Controller
{
    private const MAX_WRONG_TRIES = 5;

    private const LOCK_SECONDS = 300;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required_without:lines.*.basket_id', 'nullable', 'integer'],
            'lines.*.basket_id' => ['required_without:lines.*.product_id', 'nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric'],
        ]);

        $cashier = $request->user();
        $key = 'void-approval:'.$cashier->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_WRONG_TRIES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            return response()->json(['message' => "Too many wrong tries. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.'], 429);
        }

        $supervisor = User::where('role', 'admin')->where('is_active', true)->get()
            ->first(fn (User $admin) => $admin->canApproveVoidWith($data['credential']));

        if (! $supervisor) {
            RateLimiter::hit($key, self::LOCK_SECONDS);
            Log::warning('Line void refused: wrong supervisor password or passkey', ['cashier' => $cashier->name, 'lines' => $this->describe($data['lines'])]);

            return response()->json(['message' => "That isn't a supervisor's password or passkey."], 422);
        }

        RateLimiter::clear($key);
        Log::info('Line void approved', ['cashier' => $cashier->name, 'approved_by' => $supervisor->name, 'lines' => $this->describe($data['lines'])]);

        return response()->json(['approved_by' => $supervisor->name]);
    }

    /** The screen sends ids, so the log has the product names on record rather than what the browser said. */
    private function describe(array $lines): array
    {
        $names = Product::whereIn('id', array_filter(array_column($lines, 'product_id')))->pluck('name', 'id');
        $baskets = Basket::whereIn('id', array_filter(array_column($lines, 'basket_id')))->pluck('name', 'id');

        return array_map(fn ($line) => (empty($line['basket_id'])
            ? $names[$line['product_id']] ?? "Product #{$line['product_id']}"
            : 'Basket '.($baskets[$line['basket_id']] ?? "#{$line['basket_id']}")).' x '.$line['quantity'], $lines);
    }
}
