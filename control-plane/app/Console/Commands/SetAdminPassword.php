<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Change (or create) an admin login without tinker — Hostinger shared hosting
 * disables shell_exec(), so `php artisan tinker` will not start.
 *
 *   php artisan mvpn:admin-password
 *   php artisan mvpn:admin-password --email=admin@mbunievpn.com --password='S3cret!'
 */
class SetAdminPassword extends Command
{
    protected $signature = 'mvpn:admin-password
        {--email=admin@mbunievpn.com : Admin email}
        {--password= : New password (omit to be prompted)}
        {--name=MVPN Admin : Name to use if the user is created}';

    protected $description = 'Set the password for an admin user (creates it if missing)';

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $password = (string) ($this->option('password') ?: $this->secret('New password'));

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $created = ! $user->exists;

        $user->fill([
            'name' => $user->name ?: (string) $this->option('name'),
            'password' => Hash::make($password),
            'is_admin' => true,
            'status' => 'active',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->info(($created ? 'Created' : 'Updated') . " admin: {$email}");

        return self::SUCCESS;
    }
}
