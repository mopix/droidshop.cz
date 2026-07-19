<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the first (or another) platform administrator.
 *
 * Not a seeder: superadmin credentials must never live in a committed file,
 * and creation is a deliberate, interactive act.
 */
class CreateSuperadmin extends Command
{
    protected $signature = 'platform:create-admin {--email=} {--name=}';

    protected $description = 'Create a platform administrator (superadmin)';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $email = $this->option('email') ?: text('Email', required: true);

        if (PlatformAdmin::where('email', $email)->exists()) {
            $this->error("A platform admin with email [{$email}] already exists.");

            return self::FAILURE;
        }

        $plain = password('Password (min 10 chars)', required: true);

        try {
            validator(['password' => $plain], [
                'password' => ['required', Password::min(10)],
            ])->validate();
        } catch (ValidationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $admin = PlatformAdmin::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($plain),
        ]);

        $this->info("Created superadmin [{$admin->email}]. They must set up 2FA on first login.");

        return self::SUCCESS;
    }
}
