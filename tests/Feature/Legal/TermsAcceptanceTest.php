<?php

namespace Tests\Feature\Legal;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Registration is where the platform's contract with a tenant comes into
 * being, and until wave 3.2 it recorded nothing: there was no evidence a
 * tenant had ever seen the terms.
 */
class TermsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('legal.terms_version', '2026-08-05');
    }

    public function test_a_user_created_without_consent_has_neither_column_set(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->terms_accepted_at);
        $this->assertNull($user->terms_version);
    }

    public function test_the_timestamp_casts_to_a_date(): void
    {
        $user = User::factory()->create([
            'terms_accepted_at' => '2026-08-05 10:00:00',
            'terms_version' => '2026-08-05',
        ]);

        $this->assertInstanceOf(Carbon::class, $user->fresh()->terms_accepted_at);
    }

    public function test_registration_without_consent_is_refused_and_creates_nobody(): void
    {
        $this->post('/register', [
            'name' => 'Jan Novák',
            'email' => 'jan@example.com',
            'password' => 'heslo-heslo-heslo',
            'password_confirmation' => 'heslo-heslo-heslo',
        ])->assertSessionHasErrors('terms');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'jan@example.com']);
    }

    public function test_registration_with_consent_records_the_time_and_the_version(): void
    {
        $this->post('/register', [
            'name' => 'Jan Novák',
            'email' => 'jan@example.com',
            'password' => 'heslo-heslo-heslo',
            'password_confirmation' => 'heslo-heslo-heslo',
            'terms' => true,
        ])->assertRedirect(route('onboarding.create', absolute: false));

        $user = User::query()->where('email', 'jan@example.com')->firstOrFail();

        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame('2026-08-05', $user->terms_version);
    }

    public function test_the_refusal_is_explained_in_czech(): void
    {
        $this->post('/register', [
            'name' => 'Jan Novák',
            'email' => 'jan@example.com',
            'password' => 'heslo-heslo-heslo',
            'password_confirmation' => 'heslo-heslo-heslo',
        ])->assertSessionHasErrors([
            'terms' => 'Bez souhlasu s obchodními podmínkami se nelze zaregistrovat.',
        ]);
    }
}
