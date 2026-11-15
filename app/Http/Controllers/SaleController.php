<?php

namespace App\Http\Controllers;

use App\Models\Basket;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Approvals;
use App\Services\Inventory;
use App\Services\SaleVoids;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    /**
     * The cashier screen (React). The owner can try another style without saving it
     * with ?style=cafe&scheme=leaf-green (used by the POS style settings page).
     */
    public function pos(Request $request)
    {
        $styles = config('pos.styles');
        $current = PosSettingsController::current();
        $preview = false;

        if ($request->user()->isAdmin() && isset($styles[$request->query('style')])) {
            $current['style'] = $request->query('style');
            $current['scheme'] = isset(config('pos.schemes')[$request->query('scheme')]) ? $request->query('scheme') : $current['scheme'];
            $preview = true;
        }

        $style = $styles[$current['style']];

        return view('pos', [
            'props' => [
                'cashier' => $request->user()->name,
                'userId' => $request->user()->id,
                'draft' => $preview ? null : Cache::get(self::draftKey($request)),
                'isSupervisor' => $request->user()->isAdmin(),
                'autoApprove' => Approvals::autoApprove(),
                'storeName' => config('app.name'),
                'style' => $current['style'],
                'styleName' => $style['name'],
                'scheme' => $current['scheme'],
                'preview' => $preview,
                'settingsUrl' => $request->user()->isAdmin() ? route('settings.pos') : null,
            ],
        ]);
    }

    private static function draftKey(Request $request): string
    {
        return 'pos-draft:'.$request->user()->id;
    }

    /**
     * The cashier's open order and held sales, saved as they change. The browser keeps a copy too, but writes it
     * to disk a few seconds late; the database has it at once, so a brownout loses at most the last scan.
     */
    public function saveDraft(Request $request)
    {
        $data = $request->validate([
            'cart' => 'present|array|max:500',
            'held' => 'present|array|max:50',
            'savedAt' => 'required|integer',
        ]);
        abort_if(strlen(json_encode($data)) > 500_000, 413, 'The order is too big to keep.');
        Cache::put(self::draftKey($request), $data, now()->addDay());

        return response()->noContent();
    }

    public function store(Request $request, Inventory $inventory)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required_without:items.*.basket_id|nullable|integer|exists:products,id',
            'items.*.basket_id' => 'required_without:items.*.product_id|nullable|integer|exists:baskets,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'discount' => 'nullable|numeric|min:0',
            'amount_paid' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,gcash,card',
        ]);

        $sale = DB::transaction(function () use ($data, $inventory, $request) {
            $lines = [];
            $subtotal = 0;

            foreach ($data['items'] as $item) {
                $qty = round((float) $item['quantity'], 3);

                if (! empty($item['basket_id'])) {
                    $basketLines = $this->basketLines($item['basket_id'], $qty, $inventory);
                    $subtotal += array_sum(array_column($basketLines, 'lineTotal'));
                    array_push($lines, ...$basketLines);

                    continue;
                }

                $product = Product::with('unit')->lockForUpdate()->findOrFail($item['product_id']);
                $inventory->assertQuantity($product, $qty);

                // Buy 1 Take 1 and the like: every item is scanned and leaves stock, the free ones cost nothing
                $free = $product->freeQty($qty);
                $lineTotal = round(($qty - $free) * (float) $product->selling_price, 2);
                $subtotal += $lineTotal;
                $lines[] = ['product' => $product, 'qty' => $qty, 'lineTotal' => $lineTotal, 'extra' => [
                    'free_qty' => $free,
                    'promo' => $free > 0 ? $product->promoLabel() : null,
                ]];
            }
            $subtotal = round($subtotal, 2);

            $discount = min(round((float) ($data['discount'] ?? 0), 2), $subtotal);
            $total = round($subtotal - $discount, 2);
            $paid = round((float) $data['amount_paid'], 2);

            if ($paid < $total) {
                throw ValidationException::withMessages(['amount_paid' => 'Amount paid is less than the total.']);
            }

            $sale = Sale::create([
                'receipt_no' => 'TMP-'.uniqid(),
                'user_id' => $request->user()->id,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'amount_paid' => $paid,
                'change' => round($paid - $total, 2),
                'payment_method' => $data['payment_method'],
            ]);
            $sale->update(['receipt_no' => 'OR-'.now()->format('ymd').'-'.str_pad((string) $sale->id, 5, '0', STR_PAD_LEFT)]);

            foreach ($lines as ['product' => $product, 'qty' => $qty, 'lineTotal' => $lineTotal, 'extra' => $extra]) {
                $sale->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $qty,
                    'unit_price' => $product->selling_price,
                    'unit_cost' => $product->cost_price,
                    'line_total' => $lineTotal,
                ] + $extra);
                $inventory->consumeLots($product, $qty);
                $inventory->move($product, -$qty, 'sale', $sale->receipt_no, $request->user()->id);
            }

            return $sale;
        });

        // The paid order must never come back after a refresh, even if the screen's own save didn't arrive
        if ($draft = Cache::get(self::draftKey($request))) {
            Cache::put(self::draftKey($request), ['cart' => [], 'held' => $draft['held'] ?? [], 'savedAt' => (int) (microtime(true) * 1000)], now()->addDay());
        }

        return response()->json([
            'id' => $sale->id,
            'receipt_no' => $sale->receipt_no,
            'total' => (float) $sale->total,
            'change' => (float) $sale->change,
            'receipt_url' => route('sales.receipt', $sale),
        ], 201);
    }

    /**
     * One sale line per item in the basket. The basket price is shared out by each item's regular price,
     * so sales by product and profit stay right, and a void puts every item back.
     */
    private function basketLines(int $basketId, float $qty, Inventory $inventory): array
    {
        $basket = Basket::with('items')->lockForUpdate()->findOrFail($basketId);

        if (! $basket->is_active || $basket->items->isEmpty()) {
            throw ValidationException::withMessages(['items' => "{$basket->name} is no longer sold. Remove it from the sale."]);
        }
        if (floor($qty) != $qty) {
            throw ValidationException::withMessages(['items' => "{$basket->name} is sold per basket, so the quantity must be a whole number."]);
        }

        $total = round($qty * (float) $basket->price, 2);
        $parts = $basket->items->map(function ($item) use ($qty, $inventory) {
            $product = Product::with('unit')->lockForUpdate()->findOrFail($item->product_id);
            $itemQty = round((float) $item->quantity * $qty, 3);
            $inventory->assertQuantity($product, $itemQty);

            return ['product' => $product, 'qty' => $itemQty, 'weight' => $itemQty * (float) $product->selling_price];
        })->values();

        // Items all priced at zero are shared out by quantity instead
        $by = $parts->sum('weight') > 0 ? 'weight' : 'qty';
        $weights = $parts->sum($by);
        $left = $total;
        $lines = [];
        foreach ($parts as $i => $part) {
            $last = $i === $parts->count() - 1;
            // The last item takes the rounding, so the lines add up to the basket price exactly
            $share = $last ? $left : round($total * $part[$by] / $weights, 2);
            $left = round($left - $share, 2);
            $lines[] = ['product' => $part['product'], 'qty' => $part['qty'], 'lineTotal' => $share, 'extra' => [
                'basket_id' => $basket->id,
                'basket_name' => $basket->name,
                'basket_qty' => $qty,
            ]];
        }

        return $lines;
    }

    public function index(Request $request)
    {
        $from = $request->date('from') ?? Carbon::today();
        $to = $request->date('to') ?? Carbon::today();
        $user = $request->user();

        $query = Sale::with('user')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            // Cashiers only see their own transactions
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->when($user->isAdmin() && $request->cashier, fn ($q, $id) => $q->where('user_id', $id))
            // A whole receipt number goes straight to it; part of one has to be looked for in every sale in the dates
            ->when($request->q, fn ($q, $term) => preg_match('/^OR-\d{6}-\d{5}$/i', trim($term))
                ? $q->where('receipt_no', strtoupper(trim($term)))
                : $q->where('receipt_no', 'like', '%'.addcslashes($term, '%_\\').'%'));

        if (! $request->wantsJson()) {
            // Blade shell; the React list polls this URL for JSON
            return view('sales.index', [
                'props' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'isAdmin' => $user->isAdmin(),
                    'cashiers' => $user->isAdmin() ? User::orderBy('name')->get(['id', 'name']) : [],
                ],
            ]);
        }

        $totals = (clone $query)->completed()
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total),0) as total, COALESCE(SUM(CASE WHEN payment_method = ? THEN total ELSE 0 END),0) as cash', ['cash'])
            ->first();
        $sales = $query->withCount('returns')->latest('id')->paginate(30);

        return response()->json([
            'data' => $sales->getCollection()->map(fn ($s) => [
                'id' => $s->id,
                'receipt_no' => $s->receipt_no,
                'time' => $s->created_at->format('M j, Y g:i A'),
                'cashier' => $s->user->name,
                'payment' => $s->payment_method,
                'total' => (float) $s->total,
                'voided' => $s->status === 'voided',
                'returned' => (float) $s->refunded > 0 || $s->returns_count > 0,
            ]),
            'totals' => ['count' => (int) $totals->count, 'total' => (float) $totals->total, 'cash' => (float) $totals->cash],
            'page' => $sales->currentPage(),
            'last_page' => $sales->lastPage(),
            'total' => $sales->total(),
            'updated_at' => now()->format('g:i:s A'),
        ]);
    }

    public function show(Request $request, Sale $sale)
    {
        $this->authorizeView($request, $sale);
        $sale->load('items.product.unit', 'user', 'returns.items', 'returns.user', 'returns.approver', 'voider', 'voidApprover');

        return view('sales.show', ['sale' => $sale]);
    }

    public function receipt(Request $request, Sale $sale)
    {
        $this->authorizeView($request, $sale);
        $sale->load('items.product.unit', 'user', 'returns.items');

        return view('sales.receipt', compact('sale'));
    }

    /** A whole receipt number opens that sale, for a return or a void, whichever cashier sold it. */
    public function find(Request $request)
    {
        $typed = strtoupper(preg_replace('/\s+/', '', (string) $request->query('receipt')));
        $receipt = preg_match('/^\d{6}-\d{5}$/', $typed) ? 'OR-'.$typed : $typed;
        $sale = $receipt !== '' ? Sale::where('receipt_no', $receipt)->first() : null;

        if (! $sale) {
            return back()->with('error', $receipt === '' ? 'Type the receipt number, such as OR-261115-00012.' : "No receipt {$receipt} was found. Check the number on the receipt.");
        }

        return redirect($sale->linkFor('sales.show', $request->user()));
    }

    /** A cashier's page for voiding a sale with the owner's approval. The owner voids straight from the sale page. */
    public function voidForm(Request $request, Sale $sale)
    {
        $this->authorizeView($request, $sale);
        if ($request->user()->isAdmin()) {
            return redirect()->route('sales.show', $sale);
        }
        if ($sale->status !== 'completed') {
            return redirect($sale->linkFor('sales.show', $request->user()))->with('error', 'This sale is already voided.');
        }
        $sale->load('items', 'user');

        return view('sales.void', ['sale' => $sale, 'action' => $sale->linkFor('sales.void', $request->user()), 'autoApprove' => Approvals::autoApprove()]);
    }

    public function void(Request $request, Sale $sale, SaleVoids $voids, Approvals $approvals)
    {
        $this->authorizeView($request, $sale);
        $user = $request->user();
        $data = $request->validate([
            'reason' => $user->isAdmin() ? 'nullable|string|max:200' : 'required|string|max:200',
            'approval_via' => $user->isAdmin() ? 'nullable' : 'required|in:here,remote,auto',
            'credential' => 'nullable|string|max:255|required_if:approval_via,here',
        ], [
            'reason.required' => 'Write why the sale is being voided.',
            'credential.required_if' => "Ask the owner to type their password or passkey, or send it to the owner's phone.",
        ]);

        if ($sale->status !== 'completed') {
            return back()->with('error', 'This sale is already voided.');
        }

        if ($user->isAdmin()) {
            $voids->void($sale, $user->id);

            return back()->with('success', "Sale {$sale->receipt_no} was voided and the items were returned to stock.");
        }

        $summary = "Void sale {$sale->receipt_no} (".peso($sale->total).')';
        $payload = ['reason' => $data['reason']];

        // Sent to the owner's phone, or auto-approved at once if the owner turned that on
        if ($data['approval_via'] !== 'here') {
            $approval = $approvals->request('sale_void', $user, $sale, $summary, (float) $sale->total, $payload);

            return self::afterRequest($approval, $sale, $user);
        }

        [$owner, $via] = $approvals->checkCredential($data['credential'], $user, 'Sale void', ['receipt' => $sale->receipt_no]);
        $voids->void($sale, $user->id, $owner->id);
        $approvals->recordCounter('sale_void', $user, $owner, $via, $sale, $summary, (float) $sale->total, $payload, 'The sale was voided and its items are back in stock.');

        return redirect($sale->linkFor('sales.show', $user))->with('success', "Sale {$sale->receipt_no} was voided, approved by {$owner->name}. The items are back in stock.");
    }

    /** After asking: done already (auto-approve), refused, or waiting for the owner's answer. */
    public static function afterRequest(\App\Models\ApprovalRequest $approval, Sale $sale, User $user)
    {
        return match ($approval->status) {
            'approved' => redirect($sale->linkFor('sales.show', $user))->with('success', 'Auto-approved (the owner is away). '.$approval->message),
            'failed' => redirect($sale->linkFor('sales.show', $user))->with('error', 'It couldn\'t be done: '.$approval->message),
            default => redirect()->route('approvals.show', $approval),
        };
    }

    /**
     * The owner sees every sale; a cashier sees their own, and any other one they opened by its receipt number
     * (a signed link from find()).
     */
    public static function canOpen(Request $request, Sale $sale): bool
    {
        return $request->user()->isAdmin() || $sale->user_id === $request->user()->id || $request->hasValidSignature();
    }

    private function authorizeView(Request $request, Sale $sale): void
    {
        abort_unless(self::canOpen($request, $sale), 403);
    }
}
