@extends('layouts.app', ['title' => $config['title']])

@section('content')
    <div class="grid gap-4 lg:grid-cols-[320px_1fr]">
        <form method="POST" action="{{ route('master.store', $type) }}" class="card h-fit space-y-3 p-5">
            @csrf
            <h2 class="font-semibold">Add {{ $config['singular'] }}</h2>
            @foreach ($config['fields'] as $field => $label)
                @if ($field === 'allows_decimal')
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="allows_decimal" value="1"> {{ $label }}</label>
                @else
                    <div>
                        <label class="label">{{ $label }}</label>
                        <input name="{{ $field }}" value="{{ old($field) }}" class="input" @if ($loop->first) required @endif>
                    </div>
                @endif
            @endforeach
            <button class="btn btn-primary w-full">Add</button>
        </form>

        <div class="card overflow-x-auto">
            <table class="table">
                <thead>
                <tr>
                    @foreach ($config['fields'] as $label)<th>{{ $label }}</th>@endforeach
                    @if ($type !== 'suppliers')<th class="text-right">Products</th>@endif
                    <th class="w-40"></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <form method="POST" action="{{ route('master.update', [$type, $row->id]) }}" id="row-{{ $row->id }}">@csrf @method('PUT')</form>
                        @foreach ($config['fields'] as $field => $label)
                            <td>
                                @if ($field === 'allows_decimal')
                                    <input type="checkbox" name="allows_decimal" value="1" form="row-{{ $row->id }}" @checked($row->allows_decimal)>
                                @else
                                    <input name="{{ $field }}" value="{{ $row->$field }}" form="row-{{ $row->id }}" class="input py-1">
                                @endif
                            </td>
                        @endforeach
                        @if ($type !== 'suppliers')<td class="text-right text-slate-500">{{ $row->products_count }}</td>@endif
                        <td class="whitespace-nowrap text-right">
                            <button form="row-{{ $row->id }}" class="tap text-sm font-medium text-brand-700 hover:underline">Save</button>
                            <form method="POST" action="{{ route('master.destroy', [$type, $row->id]) }}" class="inline" onsubmit="return confirm('Delete this {{ $config['singular'] }}?')">
                                @csrf @method('DELETE')
                                <button class="tap ml-3 text-sm text-red-600 hover:underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-8 text-center text-slate-400">None yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
