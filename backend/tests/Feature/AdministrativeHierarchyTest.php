<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Access;
use Tests\TestCase;

/**
 * HAB-02 (audit 2026-11-06, écarts SEC-A1 et SEC-A2) : un administrateur ne peut ni désactiver, ni réactiver, ni fermer les
 * sessions, ni retirer un rôle à un compte qui détient des droits qu'il n'a pas ; le rôle de base ne reçoit aucun droit
 * d'administration.
 */
class AdministrativeHierarchyTest extends TestCase
{
    public function test_technical_administrator_cannot_disable_a_functional_administrator(): void
    {
        $technical = $this->userWith('admin_tech');
        $functional = $this->userWith('admin_fonc');

        // Déblocage et réinitialisation du second facteur restent ouverts (assistance, titulaire prévenu : SecurityEnforcementTest).
        foreach (['deactivate', 'reactivate'] as $action) {
            $this->actingAs($technical)->postJson("/api/v1/users/{$functional->id}/$action", ['reason' => 'Essai de prise de contrôle'])->assertForbidden();
        }
        $this->actingAs($technical)->postJson("/api/v1/users/{$functional->id}/revoke-sessions")->assertForbidden();

        $this->assertSame('active', $functional->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.denied', 'object_id' => $functional->id, 'outcome' => 'denied']);
    }

    public function test_technical_administrator_still_administers_an_ordinary_agent(): void
    {
        $technical = $this->userWith('admin_tech');
        $agent = $this->userWith('agent');

        $this->actingAs($technical)->postJson("/api/v1/users/{$agent->id}/deactivate", ['reason' => 'Départ de l’agent'])->assertOk()->assertJsonPath('data.status', 'disabled');
        $this->actingAs($technical)->postJson("/api/v1/users/{$agent->id}/reactivate", ['reason' => 'Retour de l’agent'])->assertOk();
    }

    public function test_functional_administrator_cannot_strip_a_role_from_a_peer_holding_rights_they_lack(): void
    {
        $functional = $this->userWith('admin_fonc');
        $technical = $this->userWith('admin_tech');
        $assignment = RoleAssignment::query()->where('user_id', $technical->id)->firstOrFail();

        $this->actingAs($functional)->postJson("/api/v1/role-assignments/{$assignment->id}/revoke", ['reason' => 'Retrait non autorisé'])->assertForbidden();
        $this->assertNull($assignment->refresh()->revoked_at);

        $own = RoleAssignment::query()->where('user_id', $functional->id)->firstOrFail();
        $this->actingAs($functional)->postJson("/api/v1/role-assignments/{$own->id}/revoke", ['reason' => 'Retrait de son propre rôle'])->assertForbidden();
    }

    public function test_base_role_cannot_receive_administration_rights(): void
    {
        $functional = $this->userWith('admin_fonc');
        $agentRole = Role::query()->where('code', 'agent')->firstOrFail();
        $current = $agentRole->permissions()->pluck('code')->all();

        $this->actingAs($functional)->putJson("/api/v1/roles/{$agentRole->id}/permissions", ['permissions' => [...$current, 'roles.manage'], 'reason' => 'Élargissement au rôle de base'])
            ->assertUnprocessable()->assertJsonPath('code', 'BASE_ROLE_PRIVILEGE');
        $this->assertNotContains('roles.manage', $agentRole->permissions()->pluck('code')->all());

        $this->assertTrue(Permission::query()->where('code', 'rooms.view')->exists());
        $this->actingAs($functional)->putJson("/api/v1/roles/{$agentRole->id}/permissions", ['permissions' => $current, 'reason' => 'Relecture des droits'])->assertOk();
    }

    public function test_device_actions_are_bounded_by_the_site_and_zone_of_the_assignment(): void
    {
        $hall = $this->screen('ECR-HALL-P');
        $palier = $this->screen('ECR-PALIER-P', false);
        [$palierDevice] = $this->pairedDevice($palier);
        $technician = User::query()->create(['name' => 'Technicien accueil', 'email' => 'tech.accueil@test.local', 'password' => 'Secret-Passw0rd!', 'structure_id' => $this->structure('DSI')->id]);
        RoleAssignment::query()->create(['user_id' => $technician->id, 'role_id' => Role::query()->where('code', 'admin_tech')->value('id'), 'zone_id' => $this->place()['zone_public']->id, 'starts_at' => now()->subDay()]);
        app(Access::class)->flush();

        $this->actingAs($technician)->postJson("/api/v1/devices/{$palierDevice->id}/revoke", ['reason' => 'Hors de mon périmètre'])->assertForbidden();
        $this->actingAs($technician)->postJson('/api/v1/devices/pair', ['code' => 'ABC123', 'screen_id' => $palier->id])->assertForbidden();
        $this->assertSame('active', $palierDevice->refresh()->status);
        $this->actingAs($technician)->postJson('/api/v1/devices/pair', ['code' => 'ABC123', 'screen_id' => $hall->id])->assertUnprocessable()->assertJsonPath('code', 'CODE_UNKNOWN');
    }

    public function test_login_attempts_are_capped_per_address_across_accounts(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']); // connexion depuis l'interface (origine déclarée)
        config(['saie.security.login_per_ip_per_minute' => 3]);
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/auth/login', ['email' => "cible$i@test.local", 'password' => 'essai'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'cible4@test.local', 'password' => 'essai'])->assertStatus(429);
    }

    public function test_lockout_duration_grows_with_consecutive_failures(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']); // connexion depuis l'interface (origine déclarée)
        $user = $this->userWith('agent');
        $fail = fn () => $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'mauvais'])->assertUnprocessable();

        foreach (range(1, 5) as $i) {
            $fail();
        }
        $this->assertEqualsWithDelta(now()->addMinute()->getTimestamp(), $user->refresh()->locked_until->getTimestamp(), 5, '1 min dès 5 échecs');
        $user->forceFill(['failed_logins' => 9, 'locked_until' => null])->save();
        $fail();
        $this->assertEqualsWithDelta(now()->addMinutes(5)->getTimestamp(), $user->refresh()->locked_until->getTimestamp(), 5, '5 min dès 10 échecs');
    }

    public function test_maintenance_technician_handles_incidents_without_account_administration(): void
    {
        $technician = $this->userWith('technicien', 'DSI');
        $readonly = $this->userWith('consultation');

        $this->actingAs($technician)->getJson('/api/v1/supervision')->assertOk();
        $this->actingAs($technician)->postJson('/api/v1/incidents', ['title' => 'Dalle éteinte', 'severity' => 'minor'])->assertCreated();
        $this->actingAs($technician)->getJson('/api/v1/users')->assertForbidden();
        $this->assertContains('technicien', config('saie.security.mfa_roles'));

        $this->actingAs($readonly)->getJson('/api/v1/documents')->assertOk();
        $this->actingAs($readonly)->postJson('/api/v1/incidents', ['title' => 'x', 'severity' => 'minor'])->assertForbidden();
    }

    public function test_login_from_an_undeclared_origin_is_refused_without_server_error(): void
    {
        $user = $this->userWith('agent');
        $this->withHeaders(['Referer' => 'http://inconnu.example/', 'Origin' => 'http://inconnu.example'])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret-Passw0rd!'])
            ->assertStatus(400)->assertJsonPath('code', 'STATEFUL_ORIGIN_REQUIRED');
        $this->assertSame(0, (int) $user->refresh()->failed_logins, 'aucun identifiant testé');
    }
}
