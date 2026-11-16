<?php

namespace App\Http\Controllers;

use App\Services\LegacyImport;
use App\Support\SpreadsheetReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Upload the product list exported from the old POS, check the preview, then bring it in. */
class ProductImportController extends Controller
{
    private const DIR = 'imports';

    public function create()
    {
        return view('products.import');
    }

    public function preview(Request $request, LegacyImport $import)
    {
        $request->validate(['file' => 'required|file|max:10240'], [], ['file' => 'file']);

        $token = Str::random(32);
        $request->file('file')->storeAs(self::DIR, $token, 'local');

        try {
            $plan = $import->plan(SpreadsheetReader::rows(Storage::disk('local')->path(self::DIR.'/'.$token)));
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete(self::DIR.'/'.$token);

            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Storage::disk('local')->delete(self::DIR.'/'.$token);
            Log::warning('Old POS import could not read a file', ['error' => $e->getMessage()]);

            return back()->with('error', "That file couldn't be read. Export the product list from the old POS again (Excel .xls/.xlsx or CSV) and try once more.");
        }

        if (! $plan['rows']) {
            Storage::disk('local')->delete(self::DIR.'/'.$token);

            return back()->with('error', 'That file has no products in it.');
        }

        return view('products.import-preview', [
            'plan' => $plan,
            'token' => $token,
            'fileName' => $request->file('file')->getClientOriginalName(),
        ]);
    }

    public function store(Request $request, string $token, LegacyImport $import)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{32}$/', $token), 404);
        $path = self::DIR.'/'.$token;
        if (! Storage::disk('local')->exists($path)) {
            return redirect()->route('products.import')->with('error', 'That upload has expired. Choose the file again.');
        }

        // Planned again from the file, so anything added since the preview is taken into account
        $plan = $import->plan(SpreadsheetReader::rows(Storage::disk('local')->path($path)));
        $done = $import->apply($plan, $request->boolean('update_existing'), $request->boolean('replace_stock'), $request->user()->id);
        Storage::disk('local')->delete($path);

        Log::info('Products imported from the old POS', $done + ['user' => $request->user()->name]);

        return redirect()->route('products.index')->with('success',
            "Imported from the old POS: {$done['created']} new product".($done['created'] === 1 ? '' : 's')
            .", {$done['updated']} already here".($request->boolean('update_existing') ? ' updated' : ' left as they were')
            .($done['barcodes'] ? ", {$done['barcodes']} extra barcode".($done['barcodes'] === 1 ? '' : 's') : '').'.');
    }
}
