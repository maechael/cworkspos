@php
    $styles = [
        'delivery' => ['Delivery', 'bg-brand-100 text-brand-700'],
        'opening' => ['Opening', 'bg-sky-100 text-sky-700'],
        'sale' => ['Sale', 'bg-slate-100 text-slate-700'],
        'void' => ['Void', 'bg-purple-100 text-purple-700'],
        'count' => ['Count adj.', 'bg-amber-100 text-amber-800'],
        'adjust' => ['Adjustment', 'bg-amber-100 text-amber-800'],
    ];
    [$label, $class] = $styles[$type] ?? [ucfirst($type), 'bg-slate-100 text-slate-700'];
@endphp
<span class="badge {{ $class }}">{{ $label }}</span>
