<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Incident;
use App\Models\PlayerRelease;
use App\Models\ScreenGroup;
use App\Models\ScreenStatusHistory;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Supervision (§10, RG-009, RG-014) : états opérationnels distincts, historique des états, alertes de lecture,
 * délais paramétrables, caractéristiques déclarées par le lecteur, supervision par étage et par groupe.
 */
class SupervisionStatesTest extends TestCase
{
    private function stateOf(string $screenId): string
    {
        return $this->actingAs($this->userWith('admin_tech', 'DSI'))->getJson("/api/v1/screens/$screenId")->assertOk()->json('data.operational_state.code');
    }

    public function test_operational_states_distinguish_broadcasting_standby_pause_and_fallback(): void
    {
        $screen = $this->screen('ECR-ETATS');
        [$device] = $this->pairedDevice($screen);

        $device->forceFill(['current_playback' => ['entry_id' => 'e1', 'lang' => 'fr', 'at' => now()->subMinute()->toIso8601String()]])->save();
        $this->assertSame('broadcasting', $this->stateOf($screen->id));

        $device->forceFill(['current_playback' => ['entry_id' => 'e1', 'lang' => 'fr', 'at' => now()->subHour()->toIso8601String()]])->save();
        $this->assertSame('active', $this->stateOf($screen->id), 'une lecture ancienne ne prouve pas la diffusion en cours');

        $device->forceFill(['current_playback' => ['mode' => 'off', 'at' => now()->toIso8601String()]])->save();
        $this->assertSame('standby', $this->stateOf($screen->id));

        $device->forceFill(['current_playback' => ['mode' => 'fallback', 'at' => now()->toIso8601String()]])->save();
        $this->assertSame('fallback', $this->stateOf($screen->id));

        $device->forceFill(['playback_paused' => true])->save();
        $this->assertSame('paused', $this->stateOf($screen->id));

        $device->forceFill(['playback_paused' => false, 'last_seen_at' => now()->subHour(), 'current_playback' => ['entry_id' => 'e1', 'at' => now()->toIso8601String()]])->save();
        $this->assertSame('offline', $this->stateOf($screen->id), 'RG-009 : un écran hors ligne n’est jamais « en diffusion »');
    }

    public function test_the_scheduler_keeps_one_period_per_state_change(): void
    {
        $screen = $this->screen('ECR-HISTO');
        [$device] = $this->pairedDevice($screen);
        $device->forceFill(['current_playback' => ['entry_id' => 'e1', 'at' => now()->toIso8601String()]])->save();

        $this->artisan('saie:tick')->assertSuccessful();
        $this->artisan('saie:tick')->assertSuccessful();
        $this->assertSame(1, ScreenStatusHistory::query()->where('screen_id', $screen->id)->count(), 'aucune période dupliquée sans changement');

        $device->forceFill(['last_seen_at' => now()->subHour()])->save();
        $this->artisan('saie:tick')->assertSuccessful();
        $periods = ScreenStatusHistory::query()->where('screen_id', $screen->id)->orderBy('id')->get();
        $this->assertSame(['broadcasting', 'offline'], $periods->pluck('state')->all());
        $this->assertNotNull($periods[0]->ended_at);
        $this->assertNull($periods[1]->ended_at);

        $this->actingAs($this->userWith('technicien', 'DSI'))->getJson("/api/v1/screens/{$screen->id}/status-history")->assertOk()
            ->assertJsonPath('data.0.state', 'offline')->assertJsonPath('data.1.state', 'broadcasting');
    }

    public function test_stalled_playback_repeated_errors_and_outdated_versions_open_incidents(): void
    {
        $screen = $this->screen('ECR-ANOMALIES');
        [$device] = $this->pairedDevice($screen);
        $device->forceFill(['app_version' => '1.0.0', 'release_channel' => 'stable', 'current_playback' => ['entry_id' => 'e1', 'at' => now()->subMinutes(40)->toIso8601String()]])->save();
        $release = PlayerRelease::query()->create(['version' => '1.2.0', 'channel' => 'stable', 'base_url' => '/player/releases/1.2.0/', 'sha256' => str_repeat('a', 64), 'status' => 'active', 'created_by' => $this->userWith('admin_tech', 'DSI')->id]);
        $release->forceFill(['created_at' => now()->subDays(3)])->save();
        DB::table('device_receipts')->insert(collect(range(1, 10))->map(fn () => ['id' => (string) Str::uuid(), 'event_id' => (string) Str::uuid(), 'device_id' => $device->id, 'kind' => 'error', 'occurred_at' => now()->subMinutes(5), 'received_at' => now(), 'payload' => json_encode(['message' => 'Vidéo illisible'])])->all());

        $this->artisan('saie:tick')->assertSuccessful();

        $this->assertDatabaseHas('incidents', ['device_id' => $device->id, 'kind' => 'player_stalled', 'severity' => 'major', 'status' => 'open']);
        $this->assertDatabaseHas('incidents', ['device_id' => $device->id, 'kind' => 'playback_errors', 'severity' => 'medium', 'status' => 'open']);
        $this->assertDatabaseHas('incidents', ['device_id' => $device->id, 'kind' => 'outdated_version', 'status' => 'open']);

        $device->forceFill(['current_playback' => ['entry_id' => 'e2', 'at' => now()->toIso8601String()], 'last_seen_at' => now()])->save();
        $this->artisan('saie:tick')->assertSuccessful();
        $stalled = Incident::query()->where('device_id', $device->id)->where('kind', 'player_stalled')->firstOrFail();
        $this->assertSame('resolved', $stalled->status);
        $this->assertNotEmpty($stalled->resolution, 'RG-012 : résultat consigné même pour une résolution automatique');
    }

    public function test_offline_delay_is_a_setting_and_must_exceed_the_suspect_delay(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        [$device] = $this->pairedDevice($this->screen('ECR-SEUIL'));
        $device->forceFill(['last_seen_at' => now()->subMinutes(4)])->save();
        $this->assertSame('suspect', $device->fresh()->computedNetworkStatus());

        $this->actingAs($admin)->patchJson('/api/v1/settings', ['key' => 'supervision.offline_after_s', 'value' => 90, 'reason' => 'Essai de seuil'])->assertStatus(422)->assertJsonPath('code', 'THRESHOLD_ORDER');
        $this->actingAs($admin)->patchJson('/api/v1/settings', ['key' => 'supervision.offline_after_s', 'value' => 180, 'reason' => 'Détection plus rapide des pannes'])->assertOk();
        $this->assertSame(180, (int) Setting::effective('supervision.offline_after_s'));
        $this->assertSame(180, (int) DB::table('settings')->where('key', 'supervision.offline_after_s')->value('value'));
    }

    public function test_heartbeat_records_declared_characteristics_and_supervision_groups_by_floor_and_group(): void
    {
        $screen = $this->screen('ECR-SUPERV');
        [$device, $token] = $this->pairedDevice($screen);
        $group = ScreenGroup::query()->create(['code' => 'G-HALL', 'name' => 'Hall']);
        $group->screens()->attach($screen->id);

        $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String(), 'device_info' => ['screen' => '1920x1080', 'cores' => 4, 'memory_gb' => 8, 'language' => 'fr-FR', 'touch' => false]], ['Authorization' => "Bearer $token"])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertSame(4, Device::query()->findOrFail($device->id)->hardware_info['cores']);

        $data = $this->actingAs($this->userWith('technicien', 'DSI'))->getJson('/api/v1/supervision')->assertOk()->json('data');
        $this->assertSame(['id' => $this->place()['floor']->id, 'label' => 'Rez-de-chaussée', 'level' => 0, 'total' => 1, 'online' => 1, 'anomaly' => 0], $data['by_floor'][0]);
        $this->assertSame('Hall', $data['by_group'][0]['name']);
        $this->assertSame('Rez-de-chaussée', $data['devices'][0]['floor']['label']);
    }
}
