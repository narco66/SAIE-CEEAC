<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * SEC-01, SEC-05 : second facteur non rejouable et soumis au verrouillage ; secrets jamais conservés par l'idempotence ;
 * une réinitialisation du mot de passe ferme toutes les sessions.
 */
class AuthHardeningTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function spa(): array
    {
        return ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'];
    }

    private function enrolled(): User
    {
        $user = $this->userWith('validateur', 'COM', ['fr']);
        $user->forceFill(['mfa_secret' => Totp::generateSecret(), 'mfa_enabled_at' => now()])->save();

        return $user;
    }

    private function startLogin(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->spa())->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret-Passw0rd!'])->assertOk()->assertJsonPath('mfa_required', true);
    }

    public function test_totp_code_is_accepted_only_once(): void
    {
        $user = $this->enrolled();
        $code = Totp::code($user->mfa_secret);

        $this->startLogin($user);
        $this->withHeaders($this->spa())->postJson('/api/v1/auth/mfa', ['code' => $code])->assertOk();
        $this->withHeaders($this->spa())->postJson('/api/v1/auth/logout')->assertOk();

        $this->startLogin($user);
        $this->withHeaders($this->spa())->postJson('/api/v1/auth/mfa', ['code' => $code])->assertUnprocessable()->assertJsonPath('code', 'MFA_INVALID');
    }

    public function test_second_factor_failures_lock_the_account(): void
    {
        $user = $this->enrolled();
        $this->startLogin($user);
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($this->spa())->postJson('/api/v1/auth/mfa', ['code' => '000000'])->assertUnprocessable();
        }
        $this->assertTrue($user->refresh()->locked_until->isFuture());
        $this->withHeaders($this->spa())->postJson('/api/v1/auth/mfa', ['code' => Totp::code($user->mfa_secret)])->assertStatus(429);
    }

    public function test_second_factor_setup_secret_is_never_stored_for_replay(): void
    {
        $user = $this->userWith('agent');
        $this->actingAs($user)->withHeaders($this->spa() + ['Idempotency-Key' => 'mfa-setup-0001'])->postJson('/api/v1/me/mfa/setup')->assertOk()->assertJsonStructure(['secret']);

        $this->assertNull(IdempotencyKey::query()->where('key', 'mfa-setup-0001')->value('response_body'));
        $this->actingAs($user)->withHeaders($this->spa() + ['Idempotency-Key' => 'mfa-setup-0001'])->postJson('/api/v1/me/mfa/setup')
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_NOT_REPLAYABLE');
    }

    public function test_password_reset_closes_every_session_and_remember_cookie(): void
    {
        $user = $this->userWith('agent');
        $user->forceFill(['remember_token' => 'ancien-jeton'])->save();
        DB::table('sessions')->insert(['id' => 'session-volee', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->postJson('/api/v1/auth/reset-password', ['token' => Password::createToken($user), 'email' => $user->email, 'password' => 'Nouveau-Passw0rd!', 'password_confirmation' => 'Nouveau-Passw0rd!'])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertNotSame('ancien-jeton', $user->refresh()->remember_token);
    }
}
