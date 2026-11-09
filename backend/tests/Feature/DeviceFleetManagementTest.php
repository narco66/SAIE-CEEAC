<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Incident;
use App\Models\PlayerRelease;
use App\Models\ScreenGroup;
use App\Services\ManifestBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lot 21 : paramètres de profil (rotation logicielle, veille après inactivité, serveur autorisé), identité du lecteur,
 * renouvellement forcé des identifiants, mise à jour ciblée, statistiques de groupe et promotion des versions.
 */
class DeviceFleetManagementTest extends TestCase
{
    public function test_profile_settings_and_allowed_servers(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $profile = fn (array $settings, string $code) => $this->actingAs($admin)->postJson('/api/v1/screen-profiles', ['code' => $code, 'name' => $code, 'settings' => $settings]);

        $profile(['software_rotation' => true, 'idle_standby_min' => 30], 'PRF-ROT')->assertCreated()->assertJsonPath('data.settings.idle_standby_min', 30);
        $profile(['idle_standby_min' => 0], 'PRF-IDLE')->assertUnprocessable()->assertJsonValidationErrors('settings.idle_standby_min');
        $profile(['server_url' => 'https://ailleurs.example.org'], 'PRF-SRV')->assertUnprocessable()->assertJsonValidationErrors('settings.server_url');
        config(['saie.device.allowed_servers' => ['https://saie2.ceeac.example']]);
        $profile(['server_url' => 'https://saie2.ceeac.example'], 'PRF-SRV2')->assertCreated();
    }

    public function test_device_identity_and_forced_credential_rotation(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        [$device, $token] = $this->pairedDevice($this->screen('ECR-ID'));

        $this->actingAs($admin)->patchJson("/api/v1/devices/{$device->id}", ['name' => 'Lecteur du hall', 'hardware_type' => 'Mini-PC', 'serial_number' => 'MPC-001'])->assertOk()
            ->assertJsonPath('data.name', 'Lecteur du hall')->assertJsonPath('data.serial_number', 'MPC-001');
        $this->actingAs($this->userWith('diffuseur'))->patchJson("/api/v1/devices/{$device->id}", ['name' => 'Autre'])->assertForbidden();

        $this->actingAs($admin)->postJson("/api/v1/devices/{$device->id}/rotate-credentials", ['reason' => 'Jeton possiblement exposé'])->assertOk();
        $this->app['auth']->forgetGuards();
        $headers = ['Authorization' => "Bearer $token"];
        $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String()], $headers)->assertOk()->assertJsonPath('rotate_token', true);
        $this->postJson('/api/v1/device/token/rotate', [], $headers)->assertOk();
        $this->assertNull($device->fresh()->rotate_requested_at, 'demande soldée par le renouvellement');
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.rotation_requested', 'object_id' => $device->id, 'reason' => 'Jeton possiblement exposé']);
    }

    public function test_a_release_can_be_pinned_on_one_device(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $release = fn (string $v, string $c) => $this->actingAs($admin)->postJson('/api/v1/player-releases', ['version' => $v, 'channel' => $c, 'base_url' => "/player/releases/$v/", 'sha256' => str_repeat('a', 64)])->assertCreated()->json('data.id');
        $release('2.0.0', 'pilot');
        $release('2.0.0', 'stable');
        $this->travel(1)->seconds();
        $pilot = $release('2.1.0', 'pilot');
        [$device] = $this->pairedDevice($this->screen('ECR-PIN'));
        $this->assertSame('2.0.0', PlayerRelease::targetForDevice($device)->version);

        $this->actingAs($admin)->patchJson("/api/v1/devices/{$device->id}/release", ['release_id' => $pilot])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->patchJson("/api/v1/devices/{$device->id}/release", ['release_id' => $pilot, 'reason' => 'Correctif à valider sur ce seul lecteur'])->assertOk()->assertJsonPath('target', '2.1.0');
        $this->assertSame('2.1.0', app(ManifestBuilder::class)->compile($device->fresh())['player_release']['version']);
        $this->actingAs($admin)->patchJson("/api/v1/devices/{$device->id}/release", ['release_id' => null, 'reason' => 'Retour au canal'])->assertOk()->assertJsonPath('target', '2.0.0');
    }

    public function test_group_statistics_over_seven_days(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $screen = $this->screen('ECR-GRP');
        [$device] = $this->pairedDevice($screen);
        $device->forceFill(['paired_at' => now()->subHour()])->save();
        $group = ScreenGroup::query()->create(['code' => 'G-STAT', 'name' => 'Statistiques', 'structure_id' => $this->structure('DSI')->id]);
        $group->screens()->attach($screen->id);
        foreach (range(1, 6) as $i) {
            DB::table('device_telemetry')->insert(['device_id' => $device->id, 'recorded_at' => now()->subMinutes($i * 5), 'payload' => '{}']);
        }
        DB::table('device_receipts')->insert(['id' => (string) Str::uuid(), 'event_id' => (string) Str::uuid(), 'device_id' => $device->id, 'kind' => 'played', 'occurred_at' => now()->subMinutes(3), 'received_at' => now(), 'payload' => json_encode(['plays' => 4])]);
        Incident::query()->create(['reference' => 'INC-G1', 'screen_id' => $screen->id, 'kind' => 'manual', 'category' => 'panel', 'severity' => 'minor', 'status' => 'resolved', 'title' => 'Rayure', 'detected_at' => now()->subDay()]);

        $stats = collect($this->actingAs($admin)->getJson('/api/v1/screen-groups')->assertOk()->json('data'))->firstWhere('code', 'G-STAT')['stats'];
        $this->assertSame(50.0, (float) $stats['availability_7d'], '6 relevés sur 12 créneaux attendus depuis l’appairage');
        $this->assertSame(4, $stats['plays_7d']);
        $this->assertSame(1, $stats['incidents_7d']);
    }

    public function test_healthy_pilot_release_is_proposed_then_promoted_only_when_enabled(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $pilot = PlayerRelease::query()->create(['version' => '3.0.0', 'channel' => 'pilot', 'base_url' => '/player/releases/3.0.0/', 'sha256' => str_repeat('b', 64), 'created_at' => now()->subHours(100)]);
        [$device] = $this->pairedDevice($this->screen('ECR-PILOTE'));
        $device->forceFill(['release_channel' => 'pilot', 'app_version' => '3.0.0'])->save();

        $listed = collect($this->actingAs($admin)->getJson('/api/v1/player-releases')->assertOk()->json('data'))->firstWhere('id', $pilot->id);
        $this->assertTrue($listed['promotion_eligible']);
        $this->artisan('saie:tick')->assertSuccessful();
        $this->assertFalse(PlayerRelease::query()->where('version', '3.0.0')->where('channel', 'stable')->exists(), 'promotion automatique désactivée par défaut');

        $this->actingAs($admin)->patchJson('/api/v1/settings', ['key' => 'releases.auto_promote', 'value' => true, 'reason' => 'Essai de promotion automatique'])->assertOk();
        $this->artisan('saie:tick')->assertSuccessful();
        $stable = PlayerRelease::query()->where('version', '3.0.0')->where('channel', 'stable')->firstOrFail();
        $this->assertSame(25, (int) $stable->rollout_percent, 'première vague limitée');
        $this->assertDatabaseHas('audit_logs', ['action' => 'player.release_auto_promoted']);
        $this->assertInstanceOf(Device::class, $device);
    }
}
