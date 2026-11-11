<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>Log in · Arnold Bulan Mini Grocery</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-page p-4 text-slate-800 antialiased">
    <div class="w-full max-w-sm">
        <div class="mb-6 text-center">
            <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-600 text-xl font-bold text-white">AB</div>
            <h1 class="text-xl font-bold tracking-tight text-slate-900">Arnold Bulan Mini Grocery</h1>
            <p class="text-sm text-slate-500">Inventory &amp; Point of Sale</p>
        </div>
        <form method="POST" action="{{ route('login') }}" class="card space-y-4 p-6">
            @csrf
            <div>
                <label class="label" for="username">Username</label>
                <input id="username" name="username" value="{{ old('username') }}" class="input" autofocus required>
                @error('username')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="password">Password</label>
                <input id="password" type="password" name="password" class="input" required>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" class="rounded"> Keep me logged in
            </label>
            <button class="btn btn-primary w-full py-3">Log in</button>
        </form>
    </div>
</body>
</html>
