@php
    $user = auth()->user();
    $fullWidth = $fullWidth ?? false; // the cashier screen itself, which fills the page

    // Everything a cashier sees wears the cashier brand. Only the owner's own pages are blue.
    $posLook = $fullWidth || ! $user->isAdmin();
    $posScheme = $posLook ? ($posScheme ?? \App\Http\Controllers\PosSettingsController::current()['scheme']) : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="base-url" content="{{ url('/') }}">
    <title>{{ $title ?? 'Dashboard' }} · Arnold Bulan Mini Grocery</title>
    {{-- Applied before the page paints, so a saved theme or sidebar size never flashes. Cashier pages have their own colors and menu state. --}}
    @if ($posLook)
        <script>
            try {
                const nav = localStorage.getItem('pos-nav');
                if (nav === 'mini' || nav === 'hidden') document.documentElement.dataset.posNav = nav;
            } catch (e) { /* the menu starts open */ }
        </script>
    @else
        <script>
            try {
                if (localStorage.getItem('sidebar') === 'collapsed') document.documentElement.dataset.sidebar = 'collapsed';
                if (localStorage.getItem('theme') === 'dark') document.documentElement.dataset.theme = 'dark';
            } catch (e) { /* the defaults are fine */ }
        </script>
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @if ($posLook)
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    @else
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @stack('head')
</head>
<body class="{{ $posLook ? 'pos-chrome' : 'bg-page' }} text-slate-800 antialiased" @if ($posLook) style="{{ \App\Http\Controllers\PosSettingsController::cssVariables($posScheme) }}" @endif>
@php
    $is = fn (string ...$patterns) => request()->routeIs(...$patterns);
    $setupLink = fn (string $label, string $type) => [$label, route('master.index', $type), request()->is('setup/'.$type), $type];

    // [label, url, is this the current page, icon]
    $groups = $user->isAdmin()
        ? [
            'Overview' => [
                ['Dashboard', route('dashboard'), $is('dashboard*'), 'dashboard'],
                ['Reports', route('reports.sales'), $is('reports.*'), 'reports'],
            ],
            'Operations' => [
                ['Cashier (POS)', route('pos.index'), $is('pos.*'), 'pos'],
                ['Sales', route('sales.index'), $is('sales.*'), 'sales'],
                ['Receive delivery', route('deliveries.index'), $is('deliveries.*'), 'deliveries'],
                ['Stock count', route('stock-counts.index'), $is('stock-counts.*'), 'counts'],
            ],
            'Master data' => [
                ['Products', route('products.index'), $is('products.*'), 'products'],
                ['Baskets', route('baskets.index'), $is('baskets.*'), 'baskets'],
                $setupLink('Categories', 'categories'),
                $setupLink('Units', 'units'),
                $setupLink('Suppliers', 'suppliers'),
            ],
            'System' => [
                ['POS style', route('settings.pos'), $is('settings.pos*'), 'style'],
                ['Scanner test', route('settings.scanner'), $is('settings.scanner'), 'scanner'],
                ['User accounts', route('users.index'), $is('users.*'), 'users'],
            ],
        ]
        : [
            'Operations' => [
                ['Cashier (POS)', route('pos.index'), $is('pos.*'), 'pos'],
                ['My sales', route('sales.index'), $is('sales.*'), 'sales'],
            ],
        ];

    $hideHeading = $hideHeading ?? false;

    $icons = [
        'dashboard' => 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
        'reports' => 'M5 20v-7M12 20V5M19 20v-11',
        'pos' => 'M3 4h2.2l2.3 11h10.2L20 7.5H6M9.5 19.5h.01M17 19.5h.01',
        'sales' => 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9.5 8h5M9.5 12h5',
        'products' => 'M12 3l8 4.5v9L12 21l-8-4.5v-9zM4 7.5l8 4.5 8-4.5M12 12v9',
        'baskets' => 'M3 10h18l-2 10H5zM7.5 10l3-6M16.5 10l-3-6M9.5 14v3M14.5 14v3',
        'deliveries' =>'M2.5 6h11.5v10H2.5zM14 10h4l3.5 3.5V16H14zM7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM17.5 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
        'counts' => 'M9 4h6v3H9zM9 5.5H5.5V21h13V5.5H15M8.5 13l2.5 2.5 4.5-5',
        'categories' => 'M3.5 3.5h8l9 9-8 8-9-9zM8 8h.01',
        'units' => 'M4 7h16M7 7l-3 8a3.5 3.5 0 0 0 6 0zM17 7l-3 8a3.5 3.5 0 0 0 6 0zM12 4v16M8 20h8',
        'suppliers' => 'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 6.5M18 14.5a6.5 6.5 0 0 1 3.5 5.5',
        'style' => 'M4 7h9M17 7h3M4 17h3M11 17h9M15 9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM9 19.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
        'scanner' => 'M3 8V4h4M21 8V4h-4M3 16v4h4M21 16v4h-4M7.5 8v8M11 8v8M14 8v8M17 8v8',
        'users' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4.5 21a7.5 7.5 0 0 1 15 0',
        'logout' => 'M10 4H5v16h5M14 8l4 4-4 4M18 12H9',
        'panel' => 'M4 5h16v14H4zM9.5 5v14',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'shrink' => 'm11 17-5-5 5-5M18 17l-5-5 5-5',
        'expand' => 'm13 17 5-5-5-5M6 17l5-5-5-5',
        'hide' => 'M3 3l18 18M10.6 5.2A9.7 9.7 0 0 1 12 5c5 0 8.5 4.200 9.500 7-.4 1-1.100 2.300-2.200 3.400M6.600 6.700C4.600 8.100 3.200 10.100 2.500 12c1 2.800 4.500 7 9.500 7 1.900 0 3.500-.6 4.900-1.500M9.900 9.900a3 3 0 0 0 4.200 4.200',
        'plus' => 'M12 5v14M5 12h14',
        'chevron' => 'm6 9 6 6 6-6',
        'moon' => 'M20 14.5A8.5 8.5 0 0 1 9.5 4 8.5 8.5 0 1 0 20 14.5z',
        'sun' => 'M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6 7 7M17 17l1.4 1.4M5.6 18.4 7 17M17 7l1.4-1.4',
        'bell' => 'M6 17V11a6 6 0 1 1 12 0v6l1.5 2h-15zM10 21.5h4',
        'search' => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4',
    ];
    $icon = fn (string $name, string $class = 'h-5 w-5') => '<svg class="'.$class.' shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.$icons[$name].'"/></svg>';

    $initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
    $showTopBar = ! $fullWidth;
    $lowStockCount = $showTopBar && $user->isAdmin() ? \App\Models\Product::lowStock()->count() : 0;
    $expiringCount = $showTopBar && $user->isAdmin() ? \App\Models\DeliveryItem::expiringSoon()->count() : 0;
    $alertCount = $lowStockCount + $expiringCount;
@endphp
<div class="flex min-h-screen">
    {{-- Below 1024px this is the slide-in menu, opened with the ☰ button in the small top bar --}}
    <div class="drawer-backdrop no-print fixed inset-0 z-50 lg:hidden" data-drawer-close aria-hidden="true"></div>
    <aside id="app-menu" class="app-drawer no-print fixed inset-y-0 left-0 z-50 flex h-dvh w-72 max-w-[85vw] shrink-0 flex-col px-3 py-5 shadow-2xl lg:sticky lg:top-0 lg:z-auto lg:h-screen lg:max-w-none lg:shadow-none {{ $posLook ? 'pos-sidebar lg:w-56' : 'app-sidebar border-r border-slate-200/70 bg-white transition-[width] lg:w-60' }}" aria-label="Menu">
        <button type="button" data-drawer-close class="bar-btn absolute top-5 right-3 lg:hidden {{ $posLook ? 'pos-drawer-close' : '' }}" aria-label="Close the menu">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        @if ($user->isAdmin())
            <a href="{{ url('/') }}" class="brand-link mb-3 flex items-center gap-3 px-2">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">AB</span>
                <span class="nav-text leading-tight whitespace-nowrap">
                    <span class="block text-sm font-bold {{ $posLook ? '' : 'text-slate-900' }}">Arnold Bulan</span>
                    <span class="block text-xs {{ $posLook ? 'opacity-75' : 'text-slate-500' }}">Mini Grocery</span>
                </span>
            </a>
        @else
            {{-- A store name in this spot reads like the person logged in, so a cashier sees their own name. The store's name is on the receipt and the login page. --}}
            <a href="{{ url('/') }}" title="{{ $user->name }} · Cashier" class="brand-link mb-3 flex items-center gap-3 px-2">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">{{ $initials }}</span>
                <span class="nav-text min-w-0 leading-tight whitespace-nowrap">
                    <span class="block truncate text-sm font-bold">{{ $user->name }}</span>
                    <span class="block text-xs opacity-75">Cashier</span>
                </span>
            </a>
        @endif
        <nav class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto">
            @foreach ($groups as $group => $links)
                <div class="nav-group">{{ $group }}</div>
                <div class="flex flex-col gap-0.5">
                    @foreach ($links as [$label, $href, $active, $name])
                        <a href="{{ $href }}" title="{{ $label }}" @if ($active) aria-current="page" @endif class="nav-link {{ $active ? 'active' : '' }}">{!! $icon($name) !!}<span class="nav-text whitespace-nowrap">{{ $label }}</span></a>
                    @endforeach
                </div>
            @endforeach
        </nav>
        @unless ($posLook)
            {{-- On a phone the theme button lives here; the small top bar has no room for it --}}
            <button type="button" data-theme-toggle class="nav-link mt-3 w-full sm:hidden" aria-label="Switch between light and dark">
                <span class="theme-moon">{!! $icon('moon') !!}</span><span class="theme-sun">{!! $icon('sun') !!}</span><span class="nav-text">Light or dark</span>
            </button>
        @endunless
        @if ($posLook)
            <div class="pos-nav-controls mt-3 hidden flex-col gap-0.5 pt-3 lg:flex">
                <button type="button" data-pos-nav-set="mini" class="nav-link pos-nav-if-open w-full" title="Minimize the menu to icons">{!! $icon('shrink') !!}<span class="nav-text whitespace-nowrap">Minimize menu</span></button>
                <button type="button" data-pos-nav-set="open" class="nav-link pos-nav-if-mini w-full" title="Expand the menu">{!! $icon('expand') !!}<span class="nav-text whitespace-nowrap">Expand menu</span></button>
                <button type="button" data-pos-nav-set="hidden" class="nav-link w-full" title="Hide the menu (Ctrl+B)">{!! $icon('hide') !!}<span class="nav-text whitespace-nowrap">Hide menu</span></button>
            </div>
        @endif
        <form method="POST" action="{{ route('logout') }}" class="{{ $posLook ? 'mt-0.5' : 'mt-3' }}">
            @csrf
            <button class="nav-link w-full" title="Log out">{!! $icon('logout') !!}<span class="nav-text">Log out</span></button>
        </form>
    </aside>

    {{-- The cashier screen themes itself in React; a cashier's other pages get the same colors here --}}
    <div class="flex min-w-0 flex-1 flex-col {{ $posLook && ! $fullWidth ? 'pos-theme' : '' }}">
        @if ($showTopBar)
            <header class="no-print bg-page/90 sticky top-0 z-40 hidden items-center gap-3 px-6 py-3 backdrop-blur lg:flex">
                @if ($posLook)
                    <button type="button" data-pos-nav-set="open" class="bar-btn pos-nav-restore" aria-label="Show the menu" title="Show the menu (Ctrl+B)">{!! $icon('menu') !!}</button>
                @else
                    <button type="button" id="sidebar-toggle" class="bar-btn" aria-label="Collapse or expand the sidebar" title="Collapse or expand the sidebar">{!! $icon('panel') !!}</button>
                @endif

                @if ($user->isAdmin())
                    <form action="{{ route('products.index') }}" class="relative w-full max-w-md" role="search">
                        <input id="top-search" name="q" type="search" value="" aria-label="Search products" placeholder="Search products by name or barcode…" autocomplete="off"
                               class="w-full rounded-xl border border-slate-200 bg-white py-2 pr-20 pl-4 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                        <kbd class="absolute top-1/2 right-9 -translate-y-1/2 rounded bg-slate-100 px-1.5 py-0.5 font-sans text-[11px] text-slate-500">Ctrl K</kbd>
                        <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-slate-500">{!! $icon('search', 'h-4 w-4') !!}</span>
                    </form>
                @endif

                <div class="ml-auto flex items-center gap-2">
                    @if ($user->isAdmin())
                        <details class="relative mr-2" data-menu>
                            <summary class="btn btn-primary cursor-pointer list-none">{!! $icon('plus', 'h-4 w-4') !!}Create{!! $icon('chevron', 'h-4 w-4') !!}</summary>
                            <div class="bar-menu w-48">
                                <a href="{{ route('pos.index') }}">New sale</a>
                                <a href="{{ route('products.create') }}">Add product</a>
                                <a href="{{ route('products.batch') }}">Batch add products</a>
                                <a href="{{ route('deliveries.create') }}">Receive delivery</a>
                                <a href="{{ route('stock-counts.create') }}">Stock count</a>
                            </div>
                        </details>
                    @endif

                    @unless ($posLook)
                        <button type="button" data-theme-toggle class="bar-btn" aria-label="Switch between light and dark" title="Switch between light and dark">
                            <span class="theme-moon">{!! $icon('moon') !!}</span>
                            <span class="theme-sun">{!! $icon('sun') !!}</span>
                        </button>
                    @endunless

                    @if ($user->isAdmin())
                        <details class="relative" data-menu>
                            <summary class="bar-btn" aria-label="Alerts: {{ $alertCount }}">
                                {!! $icon('bell') !!}
                                @if ($alertCount)
                                    <span class="absolute -top-0.5 -right-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-semibold text-white">{{ $alertCount > 99 ? '99+' : $alertCount }}</span>
                                @endif
                            </summary>
                            <div class="bar-menu w-64">
                                @if ($alertCount === 0)
                                    <p class="px-3 py-2 text-slate-500">Nothing needs attention.</p>
                                @endif
                                @if ($lowStockCount)
                                    <a href="{{ route('products.index', ['filter' => 'low']) }}"><span><b>{{ $lowStockCount }}</b> low on stock</span></a>
                                @endif
                                @if ($expiringCount)
                                    <a href="{{ route('reports.expiry') }}"><span><b>{{ $expiringCount }}</b> {{ $expiringCount === 1 ? 'lot' : 'lots' }} expiring within 30 days</span></a>
                                @endif
                            </div>
                        </details>
                    @endif

                    <details class="relative ml-2" data-menu>
                        @if ($posLook)
                            {{-- The same greeting as the cashier screen's top bar --}}
                            <summary class="flex cursor-pointer list-none items-center gap-2.5 rounded-lg p-1 transition hover:bg-slate-200/60">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700">{{ $initials }}</span>
                                <span class="text-sm leading-tight whitespace-nowrap">
                                    <span class="block text-xs text-slate-500">Hello,</span>
                                    <span class="block max-w-28 truncate font-semibold">{{ explode(' ', trim($user->name))[0] }}</span>
                                </span>
                            </summary>
                        @else
                            <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg p-1 transition hover:bg-slate-200/60">
                                <span class="text-right leading-tight whitespace-nowrap">
                                    <span class="block text-sm font-semibold text-slate-900">{{ $user->name }}</span>
                                    <span class="block text-xs text-slate-500">Owner / Admin</span>
                                </span>
                                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-100 text-sm font-semibold text-brand-700">{{ $initials }}</span>
                            </summary>
                        @endif
                        <div class="bar-menu w-40">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button>Log out</button>
                            </form>
                        </div>
                    </details>
                </div>
            </header>
        @endif

        {{-- Phone and tablet top bar: the menu, the store (or the cashier's own name), and the same tools as the big top bar --}}
        <header class="no-print sticky top-0 z-40 flex items-center gap-1.5 border-b border-slate-200/70 px-2 py-2 backdrop-blur sm:gap-2 sm:px-4 lg:hidden {{ $posLook ? 'pos-mobile-bar' : 'bg-page/90' }}">
            <button type="button" data-drawer-open class="bar-btn" aria-controls="app-menu" aria-expanded="false" aria-label="Open the menu">{!! $icon('menu') !!}</button>
            <a href="{{ url('/') }}" class="flex min-w-0 items-center gap-2 font-bold {{ $posLook ? '' : 'text-slate-900' }}">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm font-bold text-white {{ $posLook ? 'bg-white/15' : 'bg-brand-600' }}">{{ $user->isAdmin() ? 'AB' : $initials }}</span>
                @if ($user->isAdmin())
                    <span class="truncate"><span class="sm:hidden">Arnold Bulan</span><span class="hidden sm:inline">Arnold Bulan Mini Grocery</span></span>
                @else
                    <span class="truncate">{{ $user->name }}</span>
                @endif
            </a>

            @if ($user->isAdmin() && $showTopBar)
                <div class="ml-auto flex shrink-0 items-center gap-0.5 sm:gap-1">
                    {{-- Tablets have room for the search box itself; phones open it under the bar --}}
                    <form action="{{ route('products.index') }}" class="relative hidden w-56 md:block" role="search">
                        <input name="q" type="search" aria-label="Search products" placeholder="Search products…" autocomplete="off" class="input pr-9">
                        <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-slate-500">{!! $icon('search', 'h-4 w-4') !!}</span>
                    </form>
                    <details class="md:hidden" data-menu data-autofocus>
                        <summary class="bar-btn" aria-label="Search products">{!! $icon('search') !!}</summary>
                        <form action="{{ route('products.index') }}" role="search" class="bar-menu fixed inset-x-2 top-14 mt-0 p-2">
                            <input name="q" type="search" aria-label="Search products" placeholder="Search products by name or barcode…" autocomplete="off" class="input">
                        </form>
                    </details>
                    <details class="relative" data-menu>
                        <summary class="bar-btn" aria-label="Create">{!! $icon('plus') !!}</summary>
                        <div class="bar-menu w-52">
                            <a href="{{ route('pos.index') }}">New sale</a>
                            <a href="{{ route('products.create') }}">Add product</a>
                            <a href="{{ route('products.batch') }}">Batch add products</a>
                            <a href="{{ route('deliveries.create') }}">Receive delivery</a>
                            <a href="{{ route('stock-counts.create') }}">Stock count</a>
                        </div>
                    </details>
                    <details class="relative" data-menu>
                        <summary class="bar-btn" aria-label="Alerts: {{ $alertCount }}">
                            {!! $icon('bell') !!}
                            @if ($alertCount)
                                <span class="absolute top-0 right-0 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-semibold text-white">{{ $alertCount > 99 ? '99+' : $alertCount }}</span>
                            @endif
                        </summary>
                        <div class="bar-menu w-64 max-w-[calc(100vw-1rem)]">
                            @if ($alertCount === 0)
                                <p class="px-3 py-2 text-slate-500">Nothing needs attention.</p>
                            @endif
                            @if ($lowStockCount)
                                <a href="{{ route('products.index', ['filter' => 'low']) }}"><span><b>{{ $lowStockCount }}</b> low on stock</span></a>
                            @endif
                            @if ($expiringCount)
                                <a href="{{ route('reports.expiry') }}"><span><b>{{ $expiringCount }}</b> {{ $expiringCount === 1 ? 'lot' : 'lots' }} expiring within 30 days</span></a>
                            @endif
                        </div>
                    </details>
                    <button type="button" data-theme-toggle class="bar-btn hidden sm:flex" aria-label="Switch between light and dark">
                        <span class="theme-moon">{!! $icon('moon') !!}</span>
                        <span class="theme-sun">{!! $icon('sun') !!}</span>
                    </button>
                </div>
            @endif
        </header>

        <main class="{{ $fullWidth ? 'min-w-0 flex-1' : 'mx-auto w-full max-w-[2200px] min-w-0 flex-1 px-4 pt-4 pb-8 sm:px-6 lg:pt-1' }}">
            @if (! $fullWidth && ! $hideHeading)
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $title ?? '' }}</h1>
                    <div class="no-print flex flex-wrap gap-2">@yield('actions')</div>
                </div>
            @endif
            @foreach (['success' => 'bg-brand-50 text-brand-700 border-brand-100', 'error' => 'bg-red-50 text-red-700 border-red-100'] as $key => $classes)
                @if (session($key))
                    <div class="no-print mb-4 rounded-xl border px-4 py-3 text-sm font-medium {{ $classes }}">{{ session($key) }}</div>
                @endif
            @endforeach
            @if ($errors->any())
                <div class="no-print mb-4 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700">
                    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</div>
<script>
    // Ctrl+K jumps to the product search; clicking elsewhere closes the top bar menus
    document.addEventListener('keydown', (e) => {
        const search = document.getElementById('top-search');
        if (search && e.ctrlKey && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            search.focus();
        }
    });
    document.addEventListener('click', (e) => {
        document.querySelectorAll('details[data-menu][open]').forEach((menu) => {
            if (!menu.contains(e.target)) menu.removeAttribute('open');
        });
    });
    // Opening the phone search puts the cursor in it
    document.querySelectorAll('details[data-autofocus]').forEach((menu) => menu.addEventListener('toggle', () => {
        if (menu.open) menu.querySelector('input')?.focus();
    }));

    // Phones and tablets: the menu slides in from the left. Escape, the dimmed page, a link or the × closes it.
    const drawer = document.getElementById('app-menu');
    const opener = document.querySelector('[data-drawer-open]');
    const setDrawer = (open) => {
        const root = document.documentElement;
        const wasOpen = !!root.dataset.drawer;
        if (open) root.dataset.drawer = 'open'; else delete root.dataset.drawer;
        opener?.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) (drawer.querySelector('nav [aria-current="page"]') || drawer.querySelector('[data-drawer-close]')).focus({ preventScroll: true });
        else if (wasOpen) opener?.focus({ preventScroll: true });
    };
    opener?.addEventListener('click', () => setDrawer(true));
    document.querySelectorAll('[data-drawer-close]').forEach((el) => el.addEventListener('click', () => setDrawer(false)));
    drawer.addEventListener('click', (e) => { if (document.documentElement.dataset.drawer && e.target.closest('a')) setDrawer(false); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && document.documentElement.dataset.drawer) setDrawer(false); });
    window.matchMedia('(min-width: 1024px)').addEventListener('change', (e) => { if (e.matches) setDrawer(false); });

    // The sidebar size and theme are remembered on this device
    const remember = (key, value) => { try { value ? localStorage.setItem(key, value) : localStorage.removeItem(key); } catch (e) { /* only lasts this page */ } };
    document.getElementById('sidebar-toggle')?.addEventListener('click', () => {
        const root = document.documentElement;
        const collapsed = root.dataset.sidebar !== 'collapsed';
        if (collapsed) root.dataset.sidebar = 'collapsed'; else delete root.dataset.sidebar;
        remember('sidebar', collapsed ? 'collapsed' : null);
    });
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => button.addEventListener('click', () => {
        const root = document.documentElement;
        const dark = root.dataset.theme !== 'dark';
        if (dark) root.dataset.theme = 'dark'; else delete root.dataset.theme;
        remember('theme', dark ? 'dark' : null);
    }));
</script>
@if ($posLook)
    <script>
        // The cashier's menu: open, minimized or hidden. On the cashier screen the show button lives in React, so clicks are caught here.
        const setPosNav = (state) => {
            const root = document.documentElement;
            if (state === 'open') delete root.dataset.posNav; else root.dataset.posNav = state;
            remember('pos-nav', state === 'open' ? null : state);
            window.dispatchEvent(new Event('pos-nav-changed'));
        };
        document.addEventListener('click', (e) => {
            const button = e.target.closest('[data-pos-nav-set]');
            if (button) setPosNav(button.dataset.posNavSet);
        });
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && !e.shiftKey && !e.repeat && e.key.toLowerCase() === 'b') {
                e.preventDefault();
                setPosNav(document.documentElement.dataset.posNav === 'hidden' ? 'open' : 'hidden');
            }
        });
    </script>
@endif
</body>
</html>
