<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return view('users.index', ['users' => User::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:50|alpha_dash|unique:users',
            'role' => 'required|in:admin,cashier',
            'password' => 'required|string|min:6',
        ]);
        User::create($data + ['is_active' => true]);

        return back()->with('success', "Account for {$data['name']} created.");
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('users')->ignore($user)],
            'role' => 'required|in:admin,cashier',
            'password' => 'nullable|string|min:6',
            'void_passkey' => 'nullable|string|min:4|max:64',
        ]);
        $data['is_active'] = $request->boolean('is_active');

        if ($user->is($request->user()) && ($data['role'] !== 'admin' || ! $data['is_active'])) {
            return back()->with('error', "You can't remove your own admin access.");
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        // Left blank keeps the current passkey. Only supervisors have one.
        if (empty($data['void_passkey']) || $data['role'] !== 'admin') {
            unset($data['void_passkey']);
        }
        $user->update($data);

        return back()->with('success', "Saved {$user->name}.");
    }
}
