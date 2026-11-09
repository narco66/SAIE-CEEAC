<?php

namespace Tests\Feature;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * SEC-01, LIV-03 : le serveur impose le second facteur et le changement de mot de passe initial ;
 * tant qu'ils ne sont pas faits, seul le compte personnel est accessible.
 */
class SecurityEnforcementTest extends TestCase
{
    public function test_sensitive_role_without_second_factor_only_reaches_personal_account(): void
    {
        config(['saie.security.mfa_enforced' => true]);
        $admin = $this->userWith('admin_tech');

        $this->actingAs($admin)->getJson('/api/v1/users')->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
        $this->actingAs($admin)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mfa_enrollment_required', true);

        $admin->forceFill(['mfa_enabled_at' => now()])->save();
        $this->actingAs($admin)->getJson('/api/v1/users')->assertOk();
    }

    public function test_extended_power_holder_must_enrol_second_factor(): void
    {
        config(['saie.security.mfa_enforced' => true]);
        $agent = $this->userWith('agent');
        $this->actingAs($agent)->getJson('/api/v1/dashboard')->assertOk();

        $this->grantPower($agent, 'urgence');
        $this->actingAs($agent->refresh())->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
    }

    public function test_initial_password_must_be_changed_before_anything_else(): void
    {
        $agent = $this->userWith('agent');
        $agent->forceFill(['must_change_password' => true])->save();

        $this->actingAs($agent)->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->actingAs($agent)->getJson('/api/v1/me')->assertOk();
    }

    public function test_technical_admin_cannot_take_over_a_higher_privileged_account(): void
    {
        $tech = $this->userWith('admin_tech');
        $functional = $this->userWith('admin_fonc');
        $agent = $this->userWith('agent');

        $warned = [];
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$warned): void {
            foreach ($event->message->getTo() as $address) {
                $warned[] = $address->getAddress();
            }
        });
        $this->actingAs($tech)->patchJson("/api/v1/users/{$functional->id}", ['email' => 'pirate@test.local'])->assertForbidden();
        $this->assertNotSame('pirate@test.local', $functional->refresh()->email);
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.denied', 'object_id' => $functional->id, 'outcome' => 'denied']);

        // La réinitialisation du second facteur reste possible (téléphone perdu), mais le titulaire en est prévenu :
        // sans changement d'adresse, le lien de réinitialisation du mot de passe ne parvient qu'à lui.
        $this->actingAs($tech)->postJson("/api/v1/users/{$functional->id}/reset-mfa", ['reason' => 'Téléphone perdu'])->assertOk();
        $this->assertContains($functional->email, $warned);

        $this->actingAs($tech)->patchJson("/api/v1/users/{$agent->id}", ['email' => 'Nouvel.Agent@test.local', 'expected_version' => $agent->version])->assertOk();
        $this->assertSame('nouvel.agent@test.local', $agent->refresh()->email);
        $this->actingAs($tech)->postJson("/api/v1/users/{$agent->id}/reactivate")->assertUnprocessable();
    }
}
