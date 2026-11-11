<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PosSettingsController extends Controller
{
    /** The owner picks the business type, cashier screen style and brand green (React). */
    public function edit()
    {
        return view('settings.pos', [
            'props' => [
                'styles' => config('pos.styles'),
                'businessTypes' => config('pos.business_types'),
                'questions' => config('pos.questions'),
                'current' => static::current(),
                'storeName' => config('app.name'),
                'saveUrl' => route('settings.pos.update'),
                'previewUrl' => route('pos.index'),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'business_type' => ['required', Rule::in(array_keys(config('pos.business_types')))],
            'style' => ['required', Rule::in(array_keys(config('pos.styles')))],
            'scheme' => ['required', Rule::in(array_keys(config('pos.schemes')))],
        ]);

        Setting::put([
            'business_type' => $data['business_type'],
            'pos_style' => $data['style'],
            'pos_scheme' => $data['scheme'],
        ]);

        return response()->json(['message' => 'Cashier screen updated.', 'current' => static::current()]);
    }

    /**
     * A green as inline CSS variables (--pos-brand-dark:#066A3C;...), so the pages a cashier sees have the
     * right colors from the first paint. The values are in schemes.json, shared with the React screens.
     */
    public static function cssVariables(string $scheme): string
    {
        $schemes = json_decode(file_get_contents(resource_path('js/components/pos/schemes.json')), true);

        return collect($schemes[$scheme])
            ->except(['name', 'note', 'pal'])
            ->map(fn ($color, $key) => '--pos-'.Str::kebab($key).':'.$color)
            ->join(';');
    }

    /** The saved style, falling back to the defaults if a key is missing or no longer exists. */
    public static function current(): array
    {
        $defaults = config('pos.defaults');
        $styles = config('pos.styles');

        $business = Setting::get('business_type', $defaults['business_type']);
        $style = Setting::get('pos_style', $defaults['style']);
        $scheme = Setting::get('pos_scheme', $defaults['scheme']);

        if (! isset($styles[$style])) {
            $style = $defaults['style'];
        }
        if (! isset(config('pos.schemes')[$scheme])) {
            $scheme = $defaults['scheme'];
        }

        return [
            'business_type' => isset(config('pos.business_types')[$business]) ? $business : $defaults['business_type'],
            'style' => $style,
            'scheme' => $scheme,
        ];
    }
}
