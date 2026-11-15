<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'role',
        'is_active',
        'password',
        'void_passkey',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'void_passkey',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'void_passkey' => 'hashed',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** The setup account: everything an admin has, plus POS style and Scanner test. Made with `php artisan user:super-admin`. */
    public function isSuperAdmin(): bool
    {
        return $this->isAdmin() && $this->is_super_admin;
    }

    /**
     * Makes this username the store's one super admin (creating the account if needed) and takes the flag off anyone else.
     * A null password keeps an existing account's password.
     */
    public static function makeSuperAdmin(string $username, ?string $password = null, string $name = 'Super Admin'): self
    {
        $user = self::firstOrNew(['username' => $username]);
        if (! $user->exists) {
            $user->name = $name;
        }
        $user->forceFill(['role' => 'admin', 'is_active' => true, 'is_super_admin' => true]);
        if ($password !== null) {
            $user->password = $password;
        }
        $user->save();

        self::where('is_super_admin', true)->whereKeyNot($user->id)->update(['is_super_admin' => false]);

        return $user;
    }

    /** Only an active admin can approve a voided line, with their account password or their passkey. */
    public function canApproveVoidWith(string $credential): bool
    {
        return $this->approvalMethod($credential) !== null;
    }

    /** 'password' or 'passkey' when this active admin's credential matches, otherwise null. */
    public function approvalMethod(string $credential): ?string
    {
        if (! $this->isAdmin() || ! $this->is_active) {
            return null;
        }
        if (Hash::check($credential, $this->password)) {
            return 'password';
        }

        return $this->void_passkey && Hash::check($credential, $this->void_passkey) ? 'passkey' : null;
    }
}
