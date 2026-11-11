<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Categories, units and suppliers share one simple add/edit/delete page.
 */
class MasterDataController extends Controller
{
    private const TYPES = [
        'categories' => [
            'model' => Category::class,
            'title' => 'Categories',
            'singular' => 'category',
            'fields' => ['name' => 'Name'],
        ],
        'units' => [
            'model' => Unit::class,
            'title' => 'Units',
            'singular' => 'unit',
            'fields' => ['name' => 'Name', 'abbreviation' => 'Short name', 'allows_decimal' => 'Sold by weight (allows 0.25, 1.5…)'],
        ],
        'suppliers' => [
            'model' => Supplier::class,
            'title' => 'Suppliers',
            'singular' => 'supplier',
            'fields' => ['name' => 'Name', 'contact_person' => 'Contact person', 'phone' => 'Phone', 'address' => 'Address'],
        ],
    ];

    public function index(string $type)
    {
        $config = $this->config($type);
        $query = $config['model']::query()->orderBy('name');
        if ($type !== 'suppliers') {
            $query->withCount('products');
        }

        return view('master.index', ['type' => $type, 'config' => $config, 'rows' => $query->get()]);
    }

    public function store(Request $request, string $type)
    {
        $config = $this->config($type);
        $config['model']::create($this->validated($request, $type));

        return back()->with('success', ucfirst($config['singular']).' added.');
    }

    public function update(Request $request, string $type, int $id)
    {
        $config = $this->config($type);
        $row = $config['model']::findOrFail($id);
        $row->update($this->validated($request, $type, $id));

        return back()->with('success', ucfirst($config['singular']).' saved.');
    }

    public function destroy(string $type, int $id)
    {
        $config = $this->config($type);
        try {
            $config['model']::findOrFail($id)->delete();
        } catch (QueryException) {
            return back()->with('error', "This {$config['singular']} is still in use and can't be deleted.");
        }

        return back()->with('success', ucfirst($config['singular']).' deleted.');
    }

    private function validated(Request $request, string $type, ?int $id = null): array
    {
        $rules = match ($type) {
            'categories' => ['name' => ['required', 'string', 'max:100', Rule::unique('categories')->ignore($id)]],
            'units' => [
                'name' => ['required', 'string', 'max:50', Rule::unique('units')->ignore($id)],
                'abbreviation' => 'required|string|max:20',
            ],
            'suppliers' => [
                'name' => 'required|string|max:255',
                'contact_person' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:50',
                'address' => 'nullable|string|max:255',
            ],
        };
        $data = $request->validate($rules);
        if ($type === 'units') {
            $data['allows_decimal'] = $request->boolean('allows_decimal');
        }

        return $data;
    }

    private function config(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type];
    }
}
