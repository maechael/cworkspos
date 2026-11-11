<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\DeliveryItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function sales(Request $request)
    {
        $from = $request->date('from') ?? Carbon::today()->startOfMonth();
        $to = $request->date('to') ?? Carbon::today();
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        // Only columns that are in the sales and sale_items indexes, so a year of sales is read from the indexes alone
        $base = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', $range);
        $profitExpr = 'SUM(sale_items.line_total - sale_items.unit_cost * sale_items.quantity)';

        $daily = (clone $base)
            ->selectRaw("DATE(sales.created_at) as day, COUNT(DISTINCT sales.id) as transactions, SUM(sale_items.line_total) as gross, {$profitExpr} as profit")
            ->groupBy('day')->orderBy('day')->get();

        // One pass per product; names and categories come from the products table, which is small
        $perProduct = (clone $base)
            ->selectRaw("sale_items.product_id, SUM(sale_items.quantity) as qty, SUM(sale_items.line_total) as gross, {$profitExpr} as profit")
            ->groupBy('sale_items.product_id')->get();
        $products = Product::with('category:id,name')->whereIn('id', $perProduct->pluck('product_id'))->get(['id', 'name', 'category_id'])->keyBy('id');

        $byCategory = $perProduct->groupBy(fn ($row) => $products[$row->product_id]?->category?->name ?? 'Uncategorized')
            ->map(fn ($rows, $category) => (object) ['category' => $category, 'gross' => $rows->sum('gross'), 'profit' => $rows->sum('profit')])
            ->sortByDesc('gross')->values();

        $byProduct = $perProduct->sortByDesc('gross')->take(50)
            ->map(fn ($row) => (object) ['product_name' => $products[$row->product_id]?->name ?? "Product #{$row->product_id}", 'qty' => $row->qty, 'gross' => $row->gross, 'profit' => $row->profit])
            ->values();

        $discounts = (float) DB::table('sales')->where('status', 'completed')->whereBetween('created_at', $range)->sum('discount');

        return view('reports.sales', compact('from', 'to', 'daily', 'byCategory', 'byProduct', 'discounts'));
    }

    /**
     * Stock is always today's. The dates pick which sales show what was made, and how fast each item
     * sells, which gives an estimate of what today's stock should earn if that speed keeps up.
     */
    public function inventory(Request $request)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        $today = Carbon::today();
        $to = $request->date('to') ?? $today->copy();
        $to = $to->greaterThan($today) ? $today->copy() : $to->copy()->startOfDay();
        $from = $request->date('from') ?? $today->copy()->subDays(29);
        $from = $from->greaterThan($to) ? $to->copy() : $from->copy()->startOfDay();

        // Days before the store's first sale aren't slow days, so they don't count towards the speed
        // Oldest first, take one: MIN() with a status filter makes MariaDB read the whole index
        $firstSale = Sale::completed()->orderBy('created_at')->value('created_at');
        $speedFrom = $firstSale && Carbon::parse($firstSale)->startOfDay()->greaterThan($from) ? Carbon::parse($firstSale)->startOfDay() : $from;
        $speedDays = $speedFrom->greaterThan($to) ? 1 : (int) $speedFrom->diffInDays($to) + 1;
        $monthDaysLeft = (int) $today->diffInDays($today->copy()->endOfMonth()->startOfDay()) + 1;

        $products = Product::with('category', 'unit')->where('is_active', true)
            ->when($request->category, fn ($q, $id) => $q->where('category_id', $id))
            ->orderBy('name')->get();

        $sold = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($request->category, fn ($q, $id) => $q->where('products.category_id', $id))
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id, SUM(sale_items.quantity) as qty, SUM(sale_items.line_total - sale_items.unit_cost * sale_items.quantity) as profit')
            ->toBase()->get()->keyBy('product_id');

        $rows = $products->map(function ($p) use ($sold, $speedDays, $monthDaysLeft) {
            $stock = (float) $p->stock;
            $margin = (float) $p->selling_price - (float) $p->cost_price;
            $qtySold = (float) ($sold[$p->id]->qty ?? 0);
            $perDay = $qtySold / $speedDays;
            // An item can't earn past what is on the shelf
            $expected = fn (int $days) => min(max($stock, 0), $perDay * $days) * $margin;

            return [
                'product' => $p,
                'sold' => $qtySold,
                'per_day' => $perDay,
                'days_of_stock' => $perDay > 0 && $stock > 0 ? (int) floor($stock / $perDay) : null,
                'expected_month' => $expected($monthDaysLeft),
                'expected_30' => $expected(30),
            ];
        });

        // Discounts apply to a whole sale, so they can only come off when every category is showing
        $profitMade = (float) $sold->sum('profit');
        $discounts = $request->category ? null : (float) DB::table('sales')->where('status', 'completed')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->sum('discount');

        return view('reports.inventory', [
            'rows' => $rows,
            'totalCost' => $products->sum(fn ($p) => (float) $p->stock * (float) $p->cost_price),
            'totalRetail' => $products->sum(fn ($p) => (float) $p->stock * (float) $p->selling_price),
            'profitMade' => $profitMade - ($discounts ?? 0),
            'discountsTaken' => $discounts !== null,
            'expectedMonth' => $rows->sum('expected_month'),
            'expected30' => $rows->sum('expected_30'),
            'from' => $from,
            'to' => $to,
            'speedDays' => $speedDays,
            'speedFrom' => $speedFrom,
            'monthDaysLeft' => $monthDaysLeft,
            'today' => $today,
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function expiry(Request $request)
    {
        $days = (int) $request->input('days', 30);

        $lots = DeliveryItem::with('product.unit', 'delivery.supplier')
            ->where('remaining_qty', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', Carbon::today()->addDays($days))
            ->orderBy('expiry_date')
            ->get();

        return view('reports.expiry', compact('lots', 'days'));
    }

    public function movements(Request $request)
    {
        // Find the products first: checking each of a million movements against the products table is what made a search take a minute
        $productIds = $request->q ? Product::where(fn ($p) => $p
            ->where('name', 'like', "%{$request->q}%")->orWhere('barcode', $request->q)
            ->orWhereHas('extraBarcodes', fn ($b) => $b->where('barcode', $request->q)))->pluck('id') : null;

        // Previous / Next only: counting every movement ever, just to number the pages, gets slower each day
        $movements = StockMovement::with('product.unit', 'user')
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->latest('id')
            ->simplePaginate(50)->withQueryString();

        return view('reports.movements', compact('movements'));
    }
}
