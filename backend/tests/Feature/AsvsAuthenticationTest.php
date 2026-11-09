<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\QueuedResetPassword;
use App\Support\Totp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pré-audit ASVS 5.0 niveau 2 (V6 authentification, V7 sessions) : politique de mot de passe sans règle de composition,
 * pas de « se souvenir de moi », durée maximale de session, codes de secours conservés en empreintes, mot de passe
 * redemandé pour lier un second facteur, lien de réinitialisation envoyé en file d'attente.
 */
class AsvsAuthenticationTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function spa(): array
    {
        return ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'];
    }

    private function changePassword(User $user, string $password): TestResponse
    {
        return $this->actingAs($user)->withHeaders($this->spa())->postJson('/api/v1/me/password', ['current_password' => 'Secret-Passw0rd!', 'password' => $password, 'password_confirmation' => $password]);
    }

    public function test_passphrases_are_accepted_without_composition_rules_but_weak_values_are_refused(): void
    {
        $user = $this->userWith('agent');
        $user->forceFill(['email' => 'marie.ondo@test.local'])->save();

        $this->changePassword($user, 'aaaaaaaaaaaaaaa')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->changePassword($user, 'Marie.Ondo-2026-bureau')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->changePassword($user, str_repeat('abcdefgh', 9))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->changePassword($user, 'cheval agrafe batterie correcte')->assertOk();
    }

    public function test_remember_me_is_ignored_and_sessions_have_an_absolute_lifetime(): void
    {
        $user = $this->userWith('agent');
        $response = $this->withHeaders($this->spa())->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret-Passw0rd!', 'remember' => true])->assertOk();
        $this->assertEmpty(array_filter($response->headers->getCookies(), fn ($cookie) => str_starts_with($cookie->getName(), 'remember_')));

        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->withSession(['auth.at' => now()->subHours(13)->timestamp])->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->withSession(['auth.at' => now()->subHours(2)->timestamp])->getJson('/api/v1/me')->assertOk();
    }

    public function test_recovery_codes_are_stored_as_hashes_require_the_password_and_work_once(): void
    {
        $user = $this->userWith('validateur', 'COM', ['fr']);
        $secret = $this->actingAs($user)->withHeaders($this->spa())->postJson('/api/v1/me/mfa/setup')->assertOk()->json('secret');
        $this->actingAs($user)->withHeaders($this->spa())->postJson('/api/v1/me/mfa/confirm', ['code' => Totp::code($secret)])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->actingAs($user)->withHeaders($this->spa())->postJson('/api/v1/me/mfa/confirm', ['code' => Totp::code($secret), 'password' => 'mauvais'])->assertUnprocessable()->assertJsonPath('code', 'INVALID_PASSWORD');
        $codes = $this->actingAs($user)->withHeaders($this->spa())->postJson('/api/v1/me/mfa/confirm', ['code' => Totp::code($secret), 'password' => 'Secret-Passw0rd!'])->assertOk()->json('recovery_codes');

        $stored = (string) $user->fresh()->getRawOriginal('mfa_recovery_codes');
        $this->assertStringNotContainsString($codes[0], $stored, 'seules les empreintes sont conservées');

        $login = function () use ($user): void {
            $this->app['auth']->forgetGuards();
            $this->flushSession();
            $this->withHeaders($this->spa())->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret-Passw0rd!'])->assertOk()->assertJsonPath('mfa_required', true);
        };
        $login();
        $this->withHeaders($this->spa())->postJson('/api/v1/auth/mfa', ['code' => strtolower($codes[0])])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.mfa_recovery_used', 'object_id' => $user->id]);
        $this->assertCount(7, $user->fresh()->mfa_recovery_codes);

        $login();
        $this->withHeaders($this->spa())->postJson('/api/v1/auth/mfa', ['code' => $codes[0]])->assertUnprocessable()->assertJsonPath('code', 'MFA_INVALID');
    }

    public function test_reset_link_is_queued_and_carries_its_token_in_the_fragment(): void
    {
        Notification::fake();
        $user = $this->userWith('agent');

        $this->withHeaders($this->spa())->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, QueuedResetPassword::class, function (QueuedResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return $notification instanceof ShouldQueue && $notification->queue === 'notifications' && str_contains($url, '/compte/verification#token=') && ! str_contains($url, '?token=');
        });
    }

    public function test_current_password_checks_are_rate_limited(): void
    {
        $user = $this->userWith('agent');
        foreach (range(1, 5) as $attempt) {
            $this->actingAs($user)->postJson('/api/v1/me/password', ['current_password' => "faux-$attempt", 'password' => 'cheval agrafe batterie', 'password_confirmation' => 'cheval agrafe batterie'])->assertUnprocessable();
        }
        $this->actingAs($user)->postJson('/api/v1/me/password', ['current_password' => 'Secret-Passw0rd!', 'password' => 'cheval agrafe batterie', 'password_confirmation' => 'cheval agrafe batterie'])->assertStatus(429);
        $this->assertSame(5, AuditLog::query()->where('action', 'account.password_change_failed')->count());
    }

    public function test_repeated_second_factor_failures_require_an_administrator(): void
    {
        $user = $this->userWith('validateur', 'COM', ['fr']);
        $user->forceFill(['mfa_secret' => Totp::generateSecret(), 'mfa_enabled_at' => now(), 'failed_logins' => 19])->save();
        $this->withHeaders($this->spa())->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret-Passw0rd!'])->assertOk();

        $this->withHeaders($this->spa())->postJson('/api/v1/auth/mfa', ['code' => '000000'])->assertUnprocessable();

        $this->assertTrue($user->fresh()->locked_until->isAfter(now()->addYear()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.mfa_locked', 'object_id' => $user->id]);
    }
}
