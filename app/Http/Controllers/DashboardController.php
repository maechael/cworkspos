<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const PERIODS = ['today', 'week', 'month'];

    private const PER_PAGE = [5, 10, 25];

    /** The page renders once with data; the React dashboard then polls the same URL for JSON. */
    public function __invoke(Request $request)
    {
        $data = $this->data($request);

        return $request->wantsJson() ? response()->json($data) : view('dashboard', ['data' => $data]);
    }

    /** CSV of the sales currently shown in the table: same period and filter, every page. */
    public function export(Request $request)
    {
        $period = $this->period($request);
        $filter = (string) $request->query('filter', '');
        $name = "sales-{$period}-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($period, $filter) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Receipt no.', 'Date', 'Time', 'Cashier', 'Payment', 'Status', 'Items', 'Total']);

            $this->salesQuery($period, $filter)->chunkById(500, function ($sales) use ($out) {
                foreach ($sales as $s) {
                    fputcsv($out, [
                        $s->receipt_no,
                        $s->created_at->format('Y-m-d'),
                        $s->created_at->format('H:i'),
                        $this->csvText($s->user->name),
                        $s->payment_method,
                        $s->status,
                        $this->csvText($s->items->pluck('product_name')->join(', ')),
                        $s->total,
                    ]);
                }
            });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /** Stops a spreadsheet from running typed-in text, such as a product name starting with "=", as a formula. */
    private function csvText(string $text): string
    {
        return preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }

    private function period(Request $request): string
    {
        $period = $request->query('period');

        return in_array($period, self::PERIODS, true) ? $period : 'week';
    }

    /** The period so far. */
    private function range(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now],
            'month' => [$now->copy()->startOfMonth(), $now],
            default => [$now->copy()->startOfWeek(Carbon::MONDAY), $now],
        };
    }

    private function salesQuery(string $period, string $filter)
    {
        return Sale::with('user', 'items')
            ->whereBetween('created_at', $this->range($period))
            ->when(in_array($filter, ['completed', 'voided'], true), fn ($q) => $q->where('status', $filter))
            ->when(in_array($filter, ['cash', 'gcash', 'card'], true), fn ($q) => $q->where('payment_method', $filter));
    }

    /** Completed sales per day for the chart, oldest first, with zero-filled gaps. */
    private function dailyTransactions(int $days): array
    {
        $counts = Sale::completed()->where('created_at', '>=', Carbon::today()->subDays($days - 1))
            ->selectRaw('DATE(created_at) as day, COUNT(*) as sale_count')
            ->groupBy('day')->toBase()->pluck('sale_count', 'day');

        $series = ['labels' => [], 'transactions' => []];
        foreach (range($days - 1, 0) as $ago) {
            $day = Carbon::today()->subDays($ago);
            $series['labels'][] = $day->format('M j');
            $series['transactions'][] = (int) ($counts[$day->toDateString()] ?? 0);
        }

        return $series;
    }

    /** Seven days around today for the rail calendar: stock that came in, and lots that expire. */
    private function week(): array
    {
        $today = Carbon::today();
        $start = $today->copy()->subDays(2);
        $range = [$start->toDateString(), $today->copy()->addDays(4)->toDateString()];

        $deliveries = Delivery::whereBetween('received_at', $range)
            ->selectRaw('DATE(received_at) as day, COUNT(*) as total')->groupBy('day')->toBase()->pluck('total', 'day');
        $expiring = DeliveryItem::where('remaining_qty', '>', 0)->whereBetween('expiry_date', $range)
            ->selectRaw('DATE(expiry_date) as day, COUNT(*) as total')->groupBy('day')->toBase()->pluck('total', 'day');

        return [
            'month' => $today->format('F'),
            'days' => collect(range(0, 6))->map(function ($i) use ($start, $today, $deliveries, $expiring) {
                $day = $start->copy()->addDays($i);
                $key = $day->toDateString();

                return [
                    'date' => $key,
                    'name' => $day->format('D'),
                    'number' => $day->day,
                    'today' => $day->equalTo($today),
                    'deliveries' => (int) ($deliveries[$key] ?? 0),
                    'expiring' => (int) ($expiring[$key] ?? 0),
                ];
            })->all(),
        ];
    }

    private function data(Request $request): array
    {
        $today = Carbon::today();
        $period = $this->period($request);
        $filter = (string) $request->query('filter', '');
        $perPage = in_array((int) $request->query('per_page'), self::PER_PAGE, true) ? (int) $request->query('per_page') : self::PER_PAGE[0];

        $recent = $this->dailyTransactions(14);

        $sales = $this->salesQuery($period, $filter)->latest('id')->paginate($perPage);

        // 30 days of sales, asked again every few seconds by each open dashboard. A minute old is fine for a top 5.
        $topProducts = Cache::remember('dashboard:top-products:'.$today->toDateString(), 60, function () use ($today) {
            $top = DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sales.status', 'completed')
                ->where('sales.created_at', '>=', $today->copy()->subDays(29))
                ->selectRaw('sale_items.product_id, SUM(sale_items.quantity) as qty, SUM(sale_items.line_total) as total')
                ->groupBy('sale_items.product_id')
                ->orderByDesc('total')
                ->limit(5)
                ->get();
            $names = Product::whereIn('id', $top->pluck('product_id'))->pluck('name', 'id');

            return $top->map(fn ($p) => ['name' => $names[$p->product_id] ?? "Product #{$p->product_id}", 'qty' => (float) $p->qty, 'total' => (float) $p->total])->all();
        });

        $lowStock = Product::lowStock()->with('unit')->orderBy('stock')->limit(4)->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'barcode' => $p->barcode,
                'stock' => (float) $p->stock,
                'reorder' => (float) $p->reorder_level,
                'unit' => $p->unit->abbreviation,
            ]);

        $expiring = DeliveryItem::expiringSoon()->with('product.unit')->orderBy('expiry_date')->limit(5)->get()
            ->map(fn ($lot) => [
                'id' => $lot->id,
                'name' => $lot->product->name,
                'remaining' => (float) $lot->remaining_qty,
                'unit' => $lot->product->unit->abbreviation,
                'expiry' => $lot->expiry_date->format('M j, Y'),
                'days_left' => (int) $today->diffInDays($lot->expiry_date, false),
            ]);

        return [
            'period' => $period,
            'sales' => [
                'rows' => $sales->getCollection()->map(fn ($s) => [
                    'id' => $s->id,
                    'receipt_no' => $s->receipt_no,
                    'item_count' => $s->items->count(),
                    'item_names' => $s->items->pluck('product_name')->take(3)->join(', '),
                    'payment' => $s->payment_method,
                    'cashier' => $s->user->name,
                    'voided' => $s->status === 'voided',
                    'date' => $s->created_at->format('M j, Y'),
                    'time' => $s->created_at->format('g:i A'),
                    'total' => (float) $s->total,
                ])->values(),
                'page' => $sales->currentPage(),
                'last_page' => $sales->lastPage(),
                'from' => $sales->firstItem() ?? 0,
                'to' => $sales->lastItem() ?? 0,
                'total' => $sales->total(),
                'per_page' => $perPage,
            ],
            'daily' => ['labels' => $recent['labels'], 'transactions' => $recent['transactions']],
            'week' => $this->week(),
            'top_products' => $topProducts,
            'low_stock' => $lowStock,
            'low_stock_count' => Product::lowStock()->count(),
            'expiring' => $expiring,
            'expiring_count' => DeliveryItem::expiringSoon()->count(),
            'updated_at' => now()->format('g:i:s A'),
        ];
    }
}
