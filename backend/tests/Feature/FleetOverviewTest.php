<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tableau de bord du parc (§4) : indicateurs calculés sur les données réelles, cartes menant aux listes filtrées par état,
 * tendances et vue par niveau, bornés au périmètre.
 */
class FleetOverviewTest extends TestCase
{
    public function test_indicators_are_computed_from_real_states_and_link_to_filtered_lists(): void
    {
        $broadcasting = $this->screen('ECR-DIFF');
        [$device] = $this->pairedDevice($broadcasting);
        $device->forceFill(['current_playback' => ['entry_id' => 'e1', 'at' => now()->toIso8601String()], 'app_version' => '1.0.0'])->save();
        $this->screen('ECR-NONCONF');
        $broken = $this->screen('ECR-PANNE');
        $this->pairedDevice($broken);
        Incident::query()->create(['reference' => 'INC-T1', 'screen_id' => $broken->id, 'kind' => 'manual', 'category' => 'panel', 'severity' => 'critical', 'status' => 'open', 'title' => 'Dalle cassée', 'detected_at' => now()]);
        $this->screen('ECR-OFF', true, ['status' => 'inactive']);
        $this->screen('ECR-ARCH', true, ['status' => 'archived']);
        DB::table('device_receipts')->insert(['id' => (string) Str::uuid(), 'event_id' => (string) Str::uuid(), 'device_id' => $device->id, 'kind' => 'played', 'occurred_at' => now()->subHour(), 'received_at' => now(), 'payload' => json_encode(['plays' => 12])]);
        $admin = $this->userWith('admin_tech', 'DSI');

        $data = $this->actingAs($admin)->getJson('/api/v1/screens/overview')->assertOk()->json('data');
        $this->assertSame(['total' => 4, 'online' => 2, 'offline' => 0, 'broadcasting' => 1, 'anomaly' => 1, 'inactive' => 1, 'not_configured' => 1, 'maintenance' => 0, 'standby' => 0], $data['cards']);
        $this->assertSame(1, $data['incidents_by_severity']['critical']);
        $this->assertSame([['id' => $broadcasting->id, 'code' => 'ECR-DIFF', 'name' => 'ECR-DIFF', 'plays' => 12]], $data['most_played']);
        $this->assertCount(14, $data['availability_trend']);
        $this->assertCount(30, $data['incidents_trend']);
        $this->assertSame('Rez-de-chaussée', $data['floors'][0]['label']);
        $this->assertCount(4, $data['floors'][0]['screens']);

        $this->actingAs($admin)->getJson('/api/v1/screens?state=anomaly')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'ECR-PANNE');
        $this->actingAs($admin)->getJson('/api/v1/screens?state=pending_configuration,disabled')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_overview_is_bounded_to_the_scope(): void
    {
        $this->screen('ECR-HALL');
        $this->screen('ECR-PALIER', false);
        $user = User::query()->create(['name' => 'Superviseur hall', 'email' => 'sup.hall@test.local', 'password' => 'Secret-Passw0rd!']);
        RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', 'technicien')->value('id'), 'zone_id' => $this->place()['zone_public']->id, 'starts_at' => now()->subDay()]);
        app(Access::class)->flush();

        $this->actingAs($user)->getJson('/api/v1/screens/overview')->assertOk()->assertJsonPath('data.cards.total', 1);
        $this->actingAs($this->userWith('agent'))->getJson('/api/v1/screens/overview')->assertForbidden();
    }
}
