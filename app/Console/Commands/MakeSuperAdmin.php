<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Sets the store's one super admin (the setup account that sees POS style and Scanner test).
 * The password is typed at the prompt or read from SUPER_ADMIN_PASSWORD in .env, so it never sits in the public repo.
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'user:super-admin {username? : The login name (default: SUPER_ADMIN_USERNAME in .env)} {--name= : Full name for a new account} {--remove : Make this account a normal admin again}';

    protected $description = 'Set the one super admin, creating the account if needed';

    public function handle(): int
    {
        $username = $this->argument('username') ?: config('app.super_admin.username');
        if (! $username) {
            $this->error('Give a username, or set SUPER_ADMIN_USERNAME in .env.');

            return self::FAILURE;
        }
        $user = User::where('username', $username)->first();

        if ($this->option('remove')) {
            if (! $user) {
                $this->error("There is no account called {$username}.");

                return self::FAILURE;
            }
            $user->forceFill(['is_super_admin' => false])->save();
            $this->info("{$username} is now a normal admin.");

            return self::SUCCESS;
        }

        $password = null;
        if (! $user) {
            $password = $username === config('app.super_admin.username') ? config('app.super_admin.password') : null;
            if (! $password) {
                $password = $this->secret('Password for the new account (at least 6 characters)');
                if (strlen((string) $password) < 6) {
                    $this->error('The password needs at least 6 characters. Nothing was created.');

                    return self::FAILURE;
                }
                if ($this->secret('Type it again') !== $password) {
                    $this->error("The passwords don't match. Nothing was created.");

                    return self::FAILURE;
                }
            }
        }

        User::makeSuperAdmin($username, $password, $this->option('name') ?: 'Super Admin');
        $this->info($user
            ? "{$username} is now the super admin (the only one). The password is unchanged."
            : "Created super admin {$username} (the only one). Log in with it to see POS style and Scanner test.");

        return self::SUCCESS;
    }
}
