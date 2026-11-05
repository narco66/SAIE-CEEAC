<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SEC-01 : un administrateur peut débloquer un compte verrouillé après des échecs de connexion et réinitialiser le second
 * facteur d'un agent qui a perdu son application ; motif obligatoire, journalisé, jamais sur son propre compte.
 */
class AccountRecoveryTest extends TestCase
{
    public function test_administrator_unlocks_a_locked_account_with_a_reason(): void
    {
        $admin = $this->userWith('admin_tech');
        $agent = $this->userWith('agent');
        $agent->forceFill(['failed_logins' => 5, 'locked_until' => now()->addMinutes(15)])->save();

        $this->actingAs($admin)->getJson("/api/v1/users/{$agent->id}")->assertOk()->assertJsonPath('data.locked', true);
        $this->actingAs($admin)->postJson("/api/v1/users/{$agent->id}/unlock", [])->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/v1/users/{$agent->id}/unlock", ['reason' => 'Identité vérifiée par téléphone'])->assertOk()->assertJsonPath('data.locked', false);

        $agent->refresh();
        $this->assertNull($agent->locked_until);
        $this->assertSame(0, $agent->failed_logins);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.unlocked', 'object_id' => $agent->id, 'reason' => 'Identité vérifiée par téléphone']);
    }

    public function test_administrator_resets_the_second_factor_of_another_user_and_closes_their_sessions(): void
    {
        $admin = $this->userWith('admin_tech');
        $validator = $this->userWith('validateur', 'COM', ['fr']);
        $validator->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_enabled_at' => now(), 'mfa_recovery_codes' => ['a', 'b']])->save();
        DB::table('sessions')->insert(['id' => 'session-validateur', 'user_id' => $validator->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)->postJson("/api/v1/users/{$validator->id}/reset-mfa", ['reason' => 'Téléphone professionnel perdu'])->assertOk()->assertJsonPath('data.mfa_enabled', false);

        $validator->refresh();
        $this->assertNull($validator->mfa_enabled_at);
        $this->assertNull($validator->mfa_secret);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $validator->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.mfa_reset', 'object_id' => $validator->id]);
    }

    public function test_administrator_cannot_unlock_or_reset_their_own_account(): void
    {
        $admin = $this->userWith('admin_tech');
        $admin->forceFill(['mfa_enabled_at' => now(), 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        $this->actingAs($admin)->postJson("/api/v1/users/{$admin->id}/reset-mfa", ['reason' => 'Essai sur mon compte'])->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/users/{$admin->id}/unlock", ['reason' => 'Essai sur mon compte'])->assertForbidden();
        $this->assertNotNull($admin->refresh()->mfa_enabled_at);
    }

    public function test_agent_without_user_management_right_cannot_reset_a_second_factor(): void
    {
        $agent = $this->userWith('agent');
        $validator = $this->userWith('validateur', 'COM', ['fr']);
        $validator->forceFill(['mfa_enabled_at' => now(), 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        $this->actingAs($agent)->postJson("/api/v1/users/{$validator->id}/reset-mfa", ['reason' => 'Tentative non autorisée'])->assertForbidden();
        $this->assertNotNull($validator->refresh()->mfa_enabled_at);
    }
}
