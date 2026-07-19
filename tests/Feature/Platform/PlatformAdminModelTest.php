<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformAdminModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_spec_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('platform_admins', [
            'id', 'name', 'email', 'password',
            'two_fa_secret', 'two_fa_confirmed_at', 'two_fa_recovery_codes',
            'last_login_at',
        ]));
    }

    public function test_password_is_hashed(): void
    {
        $admin = PlatformAdmin::factory()->create(['password' => 'plain-text-password']);

        $this->assertNotSame('plain-text-password', $admin->password);
        $this->assertTrue(Hash::check('plain-text-password', $admin->password));
    }

    public function test_two_factor_secret_is_encrypted_at_rest(): void
    {
        $admin = PlatformAdmin::factory()->create(['two_fa_secret' => 'MYSECRET']);

        $raw = DB::table('platform_admins')->where('id', $admin->id)->value('two_fa_secret');

        $this->assertNotSame('MYSECRET', $raw, 'The secret must not be stored in the clear.');
        $this->assertSame('MYSECRET', $admin->fresh()->two_fa_secret);
    }

    public function test_two_factor_confirmation_state(): void
    {
        $this->assertFalse(PlatformAdmin::factory()->create()->hasConfirmedTwoFactor());
        $this->assertTrue(PlatformAdmin::factory()->withTwoFactor()->create()->hasConfirmedTwoFactor());
    }

    public function test_recovery_codes_are_single_use(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $codes = $admin->generateRecoveryCodes();
        $this->assertCount(8, $codes);

        $this->assertTrue($admin->useRecoveryCode($codes[0]), 'A valid code should work once.');
        $this->assertFalse($admin->fresh()->useRecoveryCode($codes[0]), 'The same code must not work twice.');
        $this->assertTrue($admin->fresh()->useRecoveryCode($codes[1]), 'Other codes remain valid.');
    }

    public function test_recovery_codes_are_hashed_at_rest(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $codes = $admin->generateRecoveryCodes();

        $stored = $admin->fresh()->two_fa_recovery_codes;

        $this->assertNotContains($codes[0], $stored, 'Recovery codes must be stored hashed, not in the clear.');
    }
}
