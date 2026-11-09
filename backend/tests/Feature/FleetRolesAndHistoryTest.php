<?php

namespace Tests\Feature;

use App\Models\DeviceCommand;
use App\Models\Floor;
use App\Models\Location;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Screen;
use App\Models\ScreenGroup;
use App\Models\User;
use App\Services\Access;
use Tests\TestCase;

/**
 * Habilitations du module (§14) — responsable du parc, superviseur, périmètre limité à un niveau ou à un groupe — et
 * historique unifié d'un écran (§13.1, §13.2).
 */
class FleetRolesAndHistoryTest extends TestCase
{
    private function assigned(string $role, array $scope): User
    {
        $user = User::query()->create(['name' => 'Agent '.$role, 'email' => uniqid($role).'@test.local', 'password' => 'Secret-Passw0rd!', 'mfa_enabled_at' => now()]);
        RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', $role)->value('id'), 'starts_at' => now()->subDay()] + $scope);
        app(Access::class)->flush();

        return $user;
    }

    public function test_an_assignment_can_be_limited_to_a_floor_or_to_a_group(): void
    {
        $ground = $this->screen('ECR-RDC');
        $first = Floor::query()->create(['building_id' => $this->place()['building']->id, 'code' => '1E', 'label' => '1er étage', 'level' => 1]);
        $upstairs = $this->screen('ECR-1E', true, ['location_id' => Location::query()->create(['floor_id' => $first->id, 'zone_id' => $this->place()['zone_public']->id, 'code' => 'P1', 'name' => 'Palier 1'])->id]);
        $group = ScreenGroup::query()->create(['code' => 'G-1E', 'name' => '1er étage']);
        $group->screens()->attach($upstairs->id);

        $byFloor = $this->assigned('superviseur', ['floor_id' => $first->id]);
        $this->actingAs($byFloor)->getJson('/api/v1/screens')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'ECR-1E');
        $this->actingAs($byFloor)->getJson("/api/v1/screens/{$ground->id}")->assertForbidden();

        $byGroup = $this->assigned('responsable_parc', ['group_id' => $group->id]);
        $this->actingAs($byGroup)->patchJson("/api/v1/screens/{$upstairs->id}", ['name' => 'Palier du 1er'], ['If-Match' => (string) $upstairs->version])->assertOk();
        $this->actingAs($byGroup)->patchJson("/api/v1/screens/{$ground->id}", ['name' => 'Hall'], ['If-Match' => (string) $ground->version])->assertForbidden();
    }

    public function test_the_supervisor_consults_while_the_fleet_manager_administers(): void
    {
        $screen = $this->screen('ECR-ROLES');
        $supervisor = $this->assigned('superviseur', []);
        $manager = $this->assigned('responsable_parc', []);

        $this->actingAs($supervisor)->getJson('/api/v1/supervision')->assertOk();
        $this->actingAs($supervisor)->getJson('/api/v1/screens/overview')->assertOk();
        $this->actingAs($supervisor)->patchJson("/api/v1/screens/{$screen->id}", ['name' => 'X'], ['If-Match' => (string) $screen->version])->assertForbidden();
        $this->actingAs($supervisor)->postJson('/api/v1/screen-groups', ['code' => 'G-X', 'name' => 'X'])->assertForbidden();

        $this->actingAs($manager)->postJson('/api/v1/screen-groups', ['code' => 'G-PARC', 'name' => 'Parc', 'screen_ids' => [$screen->id]])->assertCreated();
        $this->actingAs($manager)->postJson("/api/v1/screens/{$screen->id}/qr")->assertOk();
    }

    public function test_the_screen_history_gathers_every_kind_of_event(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $screen = $this->actingAs($admin)->postJson('/api/v1/screens', ['code' => 'ECR-HISTO', 'name' => 'Histo', 'kind' => 'display', 'location_id' => $this->place()['loc_public']->id, 'orientation' => 'landscape', 'resolution' => '1920x1080'])->json('data');
        $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen['id']}", ['location_id' => $this->place()['loc_internal']->id, 'move_reason' => 'Travaux du hall'], ['If-Match' => '1'])->assertOk();
        $this->actingAs($admin)->postJson('/api/v1/screen-groups', ['code' => 'G-H', 'name' => 'Groupe H', 'screen_ids' => [$screen['id']]])->assertCreated();
        [$device] = $this->pairedDevice(Screen::query()->findOrFail($screen['id']));
        DeviceCommand::query()->create(['device_id' => $device->id, 'screen_id' => $screen['id'], 'command' => 'logs', 'status' => 'executed', 'requested_by' => $admin->id, 'requested_at' => now(), 'expires_at' => now()->addMinutes(10), 'result_message' => 'Journaux transmis']);
        $this->actingAs($admin)->postJson('/api/v1/incidents', ['title' => 'Dalle rayée', 'severity' => 'minor', 'screen_id' => $screen['id']])->assertCreated();

        $items = collect($this->actingAs($this->assigned('superviseur', []))->getJson("/api/v1/screens/{$screen['id']}/history")->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(['record', 'location', 'group', 'command', 'incident'], $items->pluck('category')->unique()->values()->all());
        $this->assertTrue($items->contains(fn ($i) => $i['category'] === 'location' && str_contains((string) $i['detail'], 'Travaux du hall')));
        $this->actingAs($admin)->getJson("/api/v1/screens/{$screen['id']}/history?category=command")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.detail', 'Journaux transmis');
    }
}
