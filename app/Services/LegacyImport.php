<?php

namespace App\Services;

use App\Models\Basket;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Brings the product list from the store's old POS into this one.
 *
 * The old POS export has the columns Name, Price, Cost, Qty, Warn Qty, Barcode 1-3 (plus member prices, tax,
 * colors and fonts, which this POS has no use for). Columns are found by their header, in any order.
 *
 * plan() works out what each row will do without saving anything, so the owner sees a preview first.
 * apply() saves that plan in one transaction.
 */
class LegacyImport
{
    /** Header names in the old file, lowercased, for each field this POS keeps. */
    private const COLUMNS = [
        'name' => ['name', 'product', 'product name', 'item', 'description'],
        'price' => ['price', 'selling price', 'srp', 'retail price'],
        'cost' => ['cost', 'cost price', 'unit cost'],
        'qty' => ['qty', 'quantity', 'stock', 'on hand'],
        'warn' => ['warn qty', 'reorder level', 'reorder', 'min qty'],
        'barcode1' => ['barcode 1', 'barcode', 'barcode1', 'upc', 'code'],
        'barcode2' => ['barcode 2', 'barcode2'],
        'barcode3' => ['barcode 3', 'barcode3'],
        'enable' => ['enable', 'enabled', 'active'],
    ];

    public function __construct(private Inventory $inventory) {}

    /**
     * @param  list<list<string>>  $rows  the sheet, header row first
     * @return array{rows: list<array>, counts: array<string,int>, ignored: list<string>}
     */
    public function plan(array $rows): array
    {
        $header = array_map(fn ($h) => Str::lower(trim(preg_replace('/\s+/', ' ', (string) $h))), $rows[0] ?? []);
        $col = [];
        foreach (self::COLUMNS as $field => $names) {
            foreach ($names as $name) {
                if (($i = array_search($name, $header, true)) !== false) {
                    $col[$field] = $i;
                    break;
                }
            }
        }
        if (! isset($col['name'], $col['price'])) {
            throw new RuntimeException('This file has no "Name" and "Price" columns, so it does not look like a product list from the old POS.');
        }
        $ignored = array_values(array_filter($rows[0], fn ($h, $i) => trim((string) $h) !== '' && ! in_array($i, $col, true), ARRAY_FILTER_USE_BOTH));

        $get = fn (array $row, string $field) => isset($col[$field]) ? trim((string) ($row[$col[$field]] ?? '')) : '';
        $number = fn (string $v) => is_numeric(str_replace(',', '', $v)) ? (float) str_replace(',', '', $v) : 0.0;

        $plan = [];
        $byBarcode = []; // barcode => index in $plan of the row that took it
        $byName = [];    // normalized name => index in $plan

        foreach (array_slice($rows, 1) as $n => $row) {
            $line = $n + 2; // spreadsheet row number, header is row 1
            $name = trim(preg_replace('/\s+/', ' ', $get($row, 'name')));
            if ($name === '') {
                continue; // blank line
            }
            $key = Str::upper($name);
            $codes = array_values(array_unique(array_filter(
                [$get($row, 'barcode1'), $get($row, 'barcode2'), $get($row, 'barcode3')],
                fn ($c) => $c !== '' && $c !== '0'
            )));
            $enable = $get($row, 'enable');

            $item = [
                'line' => $line,
                'name' => Str::limit($name, 250, ''),
                'price' => round(max(0, $number($get($row, 'price'))), 2),
                'cost' => round(max(0, $number($get($row, 'cost'))), 2),
                'qty' => max(0, (int) round($number($get($row, 'qty')))),
                'reorder' => max(0, $number($get($row, 'warn'))),
                'active' => $enable === '' || ! in_array(Str::lower($enable), ['0', 'no', 'false', 'n'], true),
                'barcodes' => [],
                'action' => 'create',
                'product_id' => null,
                'notes' => [],
            ];

            // The same item listed twice: identical name and barcode
            $first = $codes[0] ?? null;
            if ($first !== null && isset($byBarcode[$first]) && Str::upper($plan[$byBarcode[$first]]['name']) === $key) {
                $item['action'] = 'skip';
                $item['notes'][] = "Same item as row {$plan[$byBarcode[$first]]['line']}.";
                $plan[] = $item;

                continue;
            }

            foreach ($codes as $code) {
                if (isset($byBarcode[$code])) {
                    $item['notes'][] = "Barcode {$code} is already on row {$plan[$byBarcode[$code]]['line']} ({$plan[$byBarcode[$code]]['name']}), so it is left off this one.";

                    continue;
                }
                $item['barcodes'][] = $code;
            }

            // Same name as an earlier row with another barcode: one product with an extra barcode
            if (isset($byName[$key])) {
                $target = &$plan[$byName[$key]];
                foreach ($item['barcodes'] as $code) {
                    $target['barcodes'][] = $code;
                    $byBarcode[$code] = $byName[$key];
                }
                $item['action'] = 'merge';
                $item['notes'][] = $item['barcodes']
                    ? "Same name as row {$target['line']}: its barcode is added there as an extra barcode."
                    : "Same name as row {$target['line']}.";
                unset($target);
                $plan[] = $item;

                continue;
            }

            // Already in this POS: matched by barcode, then by exact name
            $existing = null;
            foreach ($item['barcodes'] as $code) {
                if ($existing = Product::barcodeOwner($code)) {
                    break;
                }
            }
            $existing ??= Product::whereRaw('UPPER(name) = ?', [$key])->first();
            if ($existing) {
                $item['action'] = 'update';
                $item['product_id'] = $existing->id;
                $item['current'] = ['name' => $existing->name, 'price' => (float) $existing->selling_price, 'stock' => (float) $existing->stock];
            }

            // Barcodes this POS already gives to a different product or a basket
            $item['barcodes'] = array_values(array_filter($item['barcodes'], function ($code) use (&$item) {
                $owner = Product::barcodeOwner($code);
                if ($owner && $owner->id !== $item['product_id']) {
                    $item['notes'][] = "Barcode {$code} already belongs to {$owner->name} here, so it is left off.";

                    return false;
                }
                if (Basket::barcodeOwner($code)) {
                    $item['notes'][] = "Barcode {$code} is a basket here, so it is left off.";

                    return false;
                }

                return true;
            }));

            if ($item['price'] <= 0) {
                $item['notes'][] = 'No price in the old POS. Set one before selling it.';
            }
            if ($item['action'] === 'create' && ! $item['barcodes']) {
                $item['notes'][] = 'No barcode: it gets an in-store code you can print on a label.';
            }

            $index = count($plan);
            $plan[] = $item;
            $byName[$key] = $index;
            foreach ($item['barcodes'] as $code) {
                $byBarcode[$code] = $index;
            }
        }

        $counts = ['create' => 0, 'update' => 0, 'merge' => 0, 'skip' => 0, 'notes' => 0];
        foreach ($plan as $item) {
            $counts[$item['action']]++;
            $counts['notes'] += $item['notes'] ? 1 : 0;
        }

        return ['rows' => $plan, 'counts' => $counts, 'ignored' => $ignored];
    }

    /**
     * Saves the plan. New products get the file's Qty as opening stock.
     * Products already here get the file's name, price, cost and reorder level when $update is on,
     * and their stock set to the file's Qty only when $replaceStock is on.
     *
     * @return array{created:int, updated:int, barcodes:int}
     */
    public function apply(array $plan, bool $update, bool $replaceStock, int $userId): array
    {
        $unit = Unit::where('abbreviation', 'pc')->first() ?? Unit::where('name', 'Piece')->first() ?? Unit::orderBy('id')->first();
        if (! $unit) {
            throw new RuntimeException('Add a unit (such as Piece) first, under Units.');
        }
        $done = ['created' => 0, 'updated' => 0, 'barcodes' => 0];

        DB::transaction(function () use ($plan, $update, $replaceStock, $userId, $unit, &$done) {
            foreach ($plan['rows'] as $item) {
                if ($item['action'] === 'create') {
                    $codes = $item['barcodes'];
                    $product = Product::create([
                        'barcode' => array_shift($codes) ?? Product::internalBarcode(),
                        'name' => $item['name'],
                        'unit_id' => $unit->id,
                        'cost_price' => $item['cost'],
                        'selling_price' => $item['price'],
                        'stock' => 0,
                        'reorder_level' => $item['reorder'],
                        'is_active' => $item['active'],
                    ]);
                    $done['barcodes'] += $this->addBarcodes($product, $codes);
                    if ($item['qty'] > 0) {
                        $this->inventory->move($product, $item['qty'], 'opening', 'Opening stock (old POS import)', $userId);
                    }
                    $done['created']++;
                } elseif ($item['action'] === 'update' && ($product = Product::find($item['product_id']))) {
                    if ($update) {
                        $product->fill([
                            'name' => $item['name'],
                            'cost_price' => $item['cost'] > 0 ? $item['cost'] : $product->cost_price,
                            'selling_price' => $item['price'] > 0 ? $item['price'] : $product->selling_price,
                            'reorder_level' => $item['reorder'],
                        ])->save();
                    }
                    $done['barcodes'] += $this->addBarcodes($product, $item['barcodes']);
                    $change = round($item['qty'] - (float) $product->stock, 3);
                    if ($replaceStock && $change != 0) {
                        if ($change < 0) {
                            $this->inventory->consumeLots($product, -$change);
                        }
                        $this->inventory->move($product, $change, 'adjust', 'Stock set from old POS import', $userId);
                    }
                    $done['updated']++;
                }
            }
        });

        return $done;
    }

    /** Extra barcodes for a product, skipping any it already has. */
    private function addBarcodes(Product $product, array $codes): int
    {
        $added = 0;
        foreach ($codes as $code) {
            if ($product->barcode === $code || Product::barcodeOwner($code)) {
                continue;
            }
            $product->extraBarcodes()->create(['barcode' => $code, 'pieces' => 1]);
            $added++;
        }

        return $added;
    }
}
