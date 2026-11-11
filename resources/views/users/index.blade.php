@extends('layouts.app', ['title' => 'User accounts'])

@section('content')
    {{-- The accounts table needs the room, so the form sits above it until the screen fits both side by side --}}
    <div class="grid grid-cols-1 gap-4 2xl:grid-cols-[320px_1fr]">
        <form method="POST" action="{{ route('users.store') }}" class="card h-fit p-5">
            @csrf
            <h2 class="font-semibold">Add account</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-1">
                <div><label class="label">Full name</label><input name="name" value="{{ old('name') }}" class="input" required></div>
                <div><label class="label">Username</label><input name="username" value="{{ old('username') }}" class="input" required></div>
                <div>
                    <label class="label">Role</label>
                    <select name="role" class="input">
                        <option value="cashier">Cashier (POS and own sales only)</option>
                        <option value="admin">Owner / Admin (everything)</option>
                    </select>
                </div>
                <div><label class="label">Password</label><input type="password" name="password" class="input" required minlength="6"></div>
            </div>
            <button class="btn btn-primary mt-3 w-full">Create account</button>
        </form>

        <div class="card overflow-x-auto">
            <table class="table min-w-[820px]">
                <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>New password</th><th title="Approves a voided line on the cashier screen, instead of typing the account password">Void passkey</th><th>Active</th><th></th></tr></thead>
                <tbody>
                @foreach ($users as $u)
                    <tr>
                        <form method="POST" action="{{ route('users.update', $u) }}" id="user-{{ $u->id }}">@csrf @method('PUT')</form>
                        <td><input name="name" value="{{ $u->name }}" form="user-{{ $u->id }}" class="input py-1"></td>
                        <td><input name="username" value="{{ $u->username }}" form="user-{{ $u->id }}" class="input py-1"></td>
                        <td>
                            <select name="role" form="user-{{ $u->id }}" class="input py-1">
                                <option value="cashier" @selected($u->role === 'cashier')>Cashier</option>
                                <option value="admin" @selected($u->role === 'admin')>Admin</option>
                            </select>
                        </td>
                        <td><input type="password" name="password" form="user-{{ $u->id }}" class="input py-1" placeholder="unchanged"></td>
                        <td>
                            @if ($u->isAdmin())
                                <input type="password" name="void_passkey" form="user-{{ $u->id }}" class="input py-1" minlength="4" maxlength="64" autocomplete="new-password" placeholder="{{ $u->void_passkey ? 'set, unchanged' : 'not set' }}" aria-label="Void passkey for {{ $u->name }}">
                            @else
                                <span class="text-slate-500">Admins only</span>
                            @endif
                        </td>
                        <td><input type="checkbox" name="is_active" value="1" form="user-{{ $u->id }}" @checked($u->is_active)></td>
                        <td><button form="user-{{ $u->id }}" class="tap text-sm font-medium text-brand-700 hover:underline">Save</button></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
