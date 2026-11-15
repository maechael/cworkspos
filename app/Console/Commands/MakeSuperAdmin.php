<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Creates the super admin (the setup account that sees POS style and Scanner test), or turns an existing account into one.
 * The password is typed at the prompt, so it never sits in the code or the public repo.
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'user:super-admin {username : The login name} {--name= : Full name for a new account} {--remove : Make this account a normal admin again}';

    protected $description = 'Create a super admin, or promote / demote an existing account';

    public function handle(): int
    {
        $username = $this->argument('username');
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

        if ($user) {
            $user->forceFill(['role' => 'admin', 'is_active' => true, 'is_super_admin' => true])->save();
            $this->info("{$username} is now the super admin. The password is unchanged.");

            return self::SUCCESS;
        }

        $password = $this->secret('Password for the new account (at least 6 characters)');
        if (strlen((string) $password) < 6) {
            $this->error('The password needs at least 6 characters. Nothing was created.');

            return self::FAILURE;
        }
        if ($this->secret('Type it again') !== $password) {
            $this->error("The passwords don't match. Nothing was created.");

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $this->option('name') ?: 'Super Admin',
            'username' => $username,
            'role' => 'admin',
            'is_active' => true,
            'password' => $password,
        ]);
        $user->forceFill(['is_super_admin' => true])->save();
        $this->info("Created super admin {$username}. Log in with it to see POS style and Scanner test.");

        return self::SUCCESS;
    }
}
