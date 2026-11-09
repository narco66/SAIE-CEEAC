<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Access;
use Tests\TestCase;

/**
 * HAB-01, HAB-03 : le site et la zone d'une affectation bornent réellement l'action ; les pouvoirs étendus exigent un périmètre.
 */
class ZoneScopeTest extends TestCase
{
    private function diffuserOfZone(string $zoneId): User
    {
        $user = User::query()->create(['name' => 'Diffuseur accueil', 'email' => 'diffuseur.accueil@test.local', 'password' => 'Secret-Passw0rd!', 'structure_id' => $this->structure('COM')->id]);
        RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', 'diffuseur')->value('id'), 'zone_id' => $zoneId, 'starts_at' => now()->subDay()]);
        app(Access::class)->flush();

        return $user;
    }

    public function test_zone_limited_diffuser_cannot_act_on_screens_outside_its_zone(): void
    {
        $hall = $this->screen('ECR-HALL');
        $palier = $this->screen('ECR-PALIER', false);
        $diffuser = $this->diffuserOfZone($this->place()['zone_public']->id);

        $this->actingAs($diffuser)->postJson('/api/v1/screen-groups', ['code' => 'G-MIXTE', 'name' => 'Mixte', 'screen_ids' => [$hall->id, $palier->id]])->assertForbidden();
        $this->actingAs($diffuser)->postJson('/api/v1/screen-groups', ['code' => 'G-HALL', 'name' => 'Hall', 'screen_ids' => [$hall->id]])->assertCreated();

        $this->grantPower($diffuser, 'urgence', ['all' => true]);
        $payload = ['title' => 'Évacuation', 'message' => 'Quittez calmement les locaux.', 'severity' => 'critical', 'duration_min' => 30, 'justification' => 'Exercice incendie annoncé', 'confirm' => true];
        $this->actingAs($diffuser)->postJson('/api/v1/alerts', $payload + ['targets' => [['type' => 'all']]])->assertForbidden();
        $this->actingAs($diffuser)->postJson('/api/v1/alerts', $payload + ['targets' => [['type' => 'screen', 'id' => $palier->id]]])->assertForbidden();
        $this->actingAs($diffuser)->postJson('/api/v1/alerts', $payload + ['targets' => [['type' => 'zone', 'id' => $this->place()['zone_public']->id]]])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.denied', 'actor_id' => $diffuser->id, 'outcome' => 'denied']);
    }

    public function test_urgency_power_limited_to_a_zone_cannot_extend_or_revoke_an_alert_elsewhere(): void
    {
        $this->screen('ECR-HALL');
        $global = $this->userWith('diffuseur', 'DSI');
        $this->grantPower($global, 'urgence', ['all' => true]);
        $payload = ['title' => 'Évacuation', 'message' => 'Quittez calmement les locaux.', 'severity' => 'critical', 'duration_min' => 30, 'justification' => 'Exercice incendie annoncé', 'confirm' => true, 'targets' => [['type' => 'all']]];
        $id = $this->actingAs($global)->postJson('/api/v1/alerts', $payload)->assertCreated()->json('data.id');

        $limited = $this->userWith('diffuseur', 'COM');
        $this->grantPower($limited, 'urgence', ['zone_ids' => [$this->place()['zone_internal']->id]]);
        $this->actingAs($limited)->postJson("/api/v1/alerts/$id/extend", ['minutes' => 15, 'reason' => 'Prolongation'])->assertForbidden();
        $this->actingAs($limited)->postJson("/api/v1/alerts/$id/revoke", ['reason' => 'Fin de l’exercice'])->assertForbidden();
        $this->assertSame('active', Alert::query()->findOrFail($id)->status);
    }

    public function test_extended_power_requires_an_explicit_scope(): void
    {
        $admin = $this->userWith('admin_fonc');
        $diffuser = $this->userWith('diffuseur');
        $base = ['user_id' => $diffuser->id, 'power' => 'urgence', 'ends_at' => now()->addWeek()->toIso8601String(), 'reason' => 'Astreinte de la semaine'];

        $this->actingAs($admin)->postJson('/api/v1/role-extensions', $base)->assertUnprocessable()->assertJsonValidationErrors('scope.zone_ids');
        $this->actingAs($admin)->postJson('/api/v1/role-extensions', $base + ['scope' => ['zone_ids' => [$this->place()['zone_public']->id]]])->assertCreated();
        $this->actingAs($admin)->postJson('/api/v1/role-extensions', $base + ['scope' => ['all' => true]])->assertCreated();
    }

    public function test_fleet_wide_information_alert_needs_a_second_holder_while_critical_stays_immediate(): void
    {
        [, $token] = $this->pairedDevice($this->screen('ECR-ALERTE-4Y'));
        $author = $this->userWith('diffuseur');
        $this->grantPower($author, 'urgence', ['all' => true]);
        $second = $this->userWith('diffuseur');
        $this->grantPower($second, 'urgence', ['all' => true]);
        $payload = ['title' => 'Coupure d’eau', 'message' => 'Coupure d’eau prévue de 14 h à 15 h.', 'severity' => 'info', 'duration_min' => 30, 'justification' => 'Information des services généraux', 'confirm' => true, 'targets' => [['type' => 'all']]];

        $created = $this->actingAs($author)->postJson('/api/v1/alerts', $payload)->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('pending_approval', true);
        $id = $created->json('data.id');
        $this->assertDatabaseHas('notifications', ['user_id' => $second->id, 'event' => 'alert.pending']);
        $this->app['auth']->forgetGuards();
        $manifest = json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->json('payload'), true);
        $this->assertSame([], $manifest['alerts'], 'jamais diffusée avant confirmation');

        $this->actingAs($author)->postJson("/api/v1/alerts/$id/approve")->assertForbidden();
        $this->actingAs($second)->postJson("/api/v1/alerts/$id/approve")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('alerts', ['id' => $id, 'approved_by' => $second->id]);

        $this->actingAs($author)->postJson('/api/v1/alerts', ['severity' => 'critical', 'title' => 'Évacuation'] + $payload)->assertCreated()->assertJsonPath('data.status', 'active');
    }
}
