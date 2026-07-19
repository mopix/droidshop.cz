<?php

namespace App\Models;

use Database\Factories\PlatformAdminFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A platform administrator (spec §6.12, §15.4).
 *
 * Its own table and its own guard, sharing nothing with tenant-facing users.
 * The 2FA secret and recovery codes are encrypted at rest, so a database read
 * alone does not yield a working second factor.
 */
class PlatformAdmin extends Authenticatable
{
    /** @use HasFactory<PlatformAdminFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'two_fa_secret', 'two_fa_recovery_codes'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_fa_secret' => 'encrypted',
            'two_fa_recovery_codes' => 'encrypted:array',
            'two_fa_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_fa_confirmed_at !== null;
    }

    /**
     * Replaces the recovery codes with a fresh set, returning the plaintext
     * once. Stored hashed: a leaked database row cannot be used to log in.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        $plain = Collection::times(8, fn () => Str::random(10).'-'.Str::random(10))->all();

        $this->two_fa_recovery_codes = array_map(fn (string $code) => Hash::make($code), $plain);
        $this->save();

        return $plain;
    }

    /**
     * Consumes a recovery code if it matches, so each works exactly once.
     */
    public function useRecoveryCode(string $code): bool
    {
        $codes = $this->two_fa_recovery_codes ?? [];

        foreach ($codes as $index => $hashed) {
            if (Hash::check($code, $hashed)) {
                unset($codes[$index]);
                $this->two_fa_recovery_codes = array_values($codes);
                $this->save();

                return true;
            }
        }

        return false;
    }
}
