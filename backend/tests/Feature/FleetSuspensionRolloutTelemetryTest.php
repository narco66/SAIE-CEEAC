<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Device;
use App\Models\PlayerRelease;
use App\Services\ManifestBuilder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lots 13 à 16 : suspension réversible d'un lecteur (§10.7), déploiement progressif des versions (§21.4), télémétrie de
 * l'agent local (§10.2) et niveaux de priorité métier des campagnes (§12.1).
 */
class FleetSuspensionRolloutTelemetryTest extends TestCase
{
    public function test_a_suspended_player_only_receives_the_fallback_and_can_be_resumed(): void
    {
        $screen = $this->screen('ECR-SUSP');
        [$device] = $this->pairedDevice($screen);
        $this->activeCampaignOn($screen->id);
        $admin = $this->userWith('admin_tech', 'DSI');
        $builder = app(ManifestBuilder::class);
        $this->assertNotEmpty($builder->compile($device->fresh())['schedule']);

        $this->actingAs($admin)->postJson("/api/v1/devices/{$device->id}/suspend", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->userWith('diffuseur'))->postJson("/api/v1/devices/{$device->id}/suspend", ['reason' => 'Lecteur suspect'])->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/devices/{$device->id}/suspend", ['reason' => 'Lecteur suspect'])->assertOk()->assertJsonPath('data.suspend_reason', 'Lecteur suspect');
        $this->actingAs($admin)->postJson("/api/v1/devices/{$device->id}/suspend", ['reason' => 'Lecteur suspect'])->assertStatus(409)->assertJsonPath('code', 'DEVICE_NOT_SUSPENDABLE');

        $manifest = $builder->compile($device->fresh());
        $this->assertSame([], $manifest['schedule'], 'aucun programme pour un lecteur suspendu');
        $this->assertSame([], $manifest['alerts']);
        $this->assertTrue($manifest['suspended'], 'la suspension change toujours le manifeste signé');
        $this->assertSame('active', $device->fresh()->status, 'identité et jeton conservés');
        $this->actingAs($admin)->getJson("/api/v1/screens/{$screen->id}")->assertJsonPath('data.operational_state.code', 'suspended');

        $this->actingAs($admin)->postJson("/api/v1/devices/{$device->id}/resume", ['reason' => 'Contrôle effectué'])->assertOk();
        $this->assertNotEmpty($builder->compile($device->fresh())['schedule']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.suspended', 'object_id' => $device->id, 'reason' => 'Lecteur suspect']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.resumed', 'object_id' => $device->id, 'reason' => 'Contrôle effectué']);
    }

    public function test_a_partial_rollout_reaches_a_stable_share_of_the_channel(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $release = fn (string $v, string $c, int $p = 100) => ['version' => $v, 'channel' => $c, 'base_url' => "/player/releases/$v/", 'sha256' => str_repeat('a', 64), 'rollout_percent' => $p];
        $this->actingAs($admin)->postJson('/api/v1/player-releases', $release('1.1.0', 'pilot'))->assertCreated();
        $this->actingAs($admin)->postJson('/api/v1/player-releases', $release('1.1.0', 'stable'))->assertCreated();
        $this->travel(1)->seconds();
        $this->actingAs($admin)->postJson('/api/v1/player-releases', $release('1.2.0', 'pilot'))->assertCreated();
        $wave = $this->actingAs($admin)->postJson('/api/v1/player-releases', $release('1.2.0', 'stable', 30))->assertCreated()->assertJsonPath('data.rollout_percent', 30)->json('data.id');

        $devices = collect(range(1, 40))->map(fn (int $i) => $this->pairedDevice($this->screen('ECR-W'.$i))[0]);
        $targets = fn () => $devices->map(fn (Device $d) => PlayerRelease::targetForDevice($d->fresh())->version);
        $first = $targets();
        $inWave = $first->filter(fn ($v) => $v === '1.2.0')->count();
        $this->assertGreaterThan(0, $inWave);
        $this->assertLessThan(40, $inWave, 'les autres restent sur 1.1.0');
        $this->assertEquals($first, $targets(), 'sélection stable d’un appel à l’autre');

        $this->actingAs($admin)->patchJson("/api/v1/player-releases/$wave", ['rollout_percent' => 70])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->patchJson("/api/v1/player-releases/$wave", ['rollout_percent' => 70, 'reason' => 'Pas d’anomalie sur la première vague'])->assertOk();
        $second = $targets();
        $first->each(fn ($v, $i) => $v === '1.2.0' && $this->assertSame('1.2.0', $second[$i], 'élargir la vague ne retire aucun lecteur'));
        $this->assertGreaterThanOrEqual($inWave, $second->filter(fn ($v) => $v === '1.2.0')->count());

        $devices->first()->forceFill(['app_version' => PlayerRelease::targetForDevice($devices->first()->fresh())->version])->save();
        $deployment = $this->actingAs($admin)->getJson("/api/v1/player-releases/$wave/deployment")->assertOk();
        $this->assertSame(40, $deployment->json('data.summary.total'));
        $this->assertSame(40 - $second->filter(fn ($v) => $v === '1.2.0')->count(), $deployment->json('data.summary.excluded'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'player.release_rollout', 'reason' => 'Pas d’anomalie sur la première vague']);
    }

    public function test_agent_telemetry_is_stored_and_drives_disk_and_temperature_anomalies(): void
    {
        [$device, $token] = $this->pairedDevice($this->screen('ECR-TEL'));
        $headers = ['Authorization' => "Bearer $token"];
        $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String(), 'device_info' => ['temperature_c' => 400]], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String(), 'device_info' => [
            'agent_version' => '1.1.0', 'ip' => '10.0.0.12', 'cpu_count' => 4, 'cpu_load' => null, 'mem_total_gb' => 8, 'mem_free_gb' => 3.2, 'uptime_s' => 3600,
            'disk_total_gb' => 100, 'disk_free_gb' => 4, 'temperature_c' => 86.5, 'simulated' => false,
        ]], $headers)->assertOk();

        $info = $device->fresh()->hardware_info;
        $this->assertSame('10.0.0.12', $info['ip']);
        $this->assertArrayNotHasKey('cpu_load', $info, 'une mesure absente n’est pas enregistrée');

        $this->artisan('saie:tick')->assertSuccessful();
        $this->assertDatabaseHas('incidents', ['device_id' => $device->id, 'kind' => 'overheat', 'status' => 'open']);
        $this->assertDatabaseHas('incidents', ['device_id' => $device->id, 'kind' => 'storage_full', 'status' => 'open']);
    }

    public function test_the_urgent_priority_level_requires_the_urgence_power(): void
    {
        $diffuser = $this->userWith('diffuseur');
        $author = $this->userWith('contributeur');
        $content = Content::query()->create(['reference' => 'CNT-'.uniqid(), 'title' => 'Avis', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'public', 'expires_at' => now()->addDays(10), 'status' => 'approved']);
        ContentVersion::query()->create(['content_id' => $content->id, 'lang' => 'fr', 'revision' => 1, 'payload' => ['title' => 'Avis', 'media' => []], 'classification' => 'public', 'author_id' => $author->id, 'approved_by' => $this->userWith('validateur', 'COM', ['fr'])->id, 'approved_at' => now(), 'payload_hash' => str_repeat('b', 64)]);
        $payload = fn (int $priority) => ['name' => 'Communiqué', 'priority' => $priority, 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addDays(2)->toIso8601String(),
            'items' => [['content_id' => $content->id]], 'languages' => ['fr'], 'targets' => [['type' => 'floor', 'id' => $this->place()['floor']->id]]];

        $this->actingAs($diffuser)->getJson('/api/v1/campaign-priority-levels')->assertOk()->assertJsonPath('data.urgent_from', 80)->assertJsonPath('data.can_urgent', false)->assertJsonCount(5, 'data.levels');
        $this->actingAs($diffuser)->postJson('/api/v1/campaigns', $payload(85))->assertForbidden()->assertJsonPath('code', 'PRIORITY_RESTRICTED');
        $official = $this->actingAs($diffuser)->postJson('/api/v1/campaigns', $payload(70))->assertCreated()->json('data.id');
        $this->actingAs($diffuser)->patchJson("/api/v1/campaigns/$official", ['priority' => 90], ['If-Match' => '1'])->assertForbidden()->assertJsonPath('code', 'PRIORITY_RESTRICTED');
        $this->assertSame(70, Campaign::query()->findOrFail($official)->priority);
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.denied', 'outcome' => 'denied']);

        $this->grantPower($diffuser, 'urgence');
        $this->actingAs($diffuser)->getJson('/api/v1/campaign-priority-levels')->assertJsonPath('data.can_urgent', true);
        $this->actingAs($diffuser)->postJson('/api/v1/campaigns', $payload(85))->assertCreated()->assertJsonPath('data.priority', 85);
    }

    private function activeCampaignOn(string $screenId): void
    {
        $author = $this->userWith('contributeur');
        $content = Content::query()->create(['reference' => 'CNT-'.uniqid(), 'title' => 'Avis', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'public', 'expires_at' => now()->addDays(10), 'status' => 'approved']);
        $version = ContentVersion::query()->create(['content_id' => $content->id, 'lang' => 'fr', 'revision' => 1, 'payload' => ['title' => 'Avis', 'media' => []], 'classification' => 'public', 'author_id' => $author->id, 'approved_by' => $this->userWith('validateur')->id, 'approved_at' => now(), 'payload_hash' => str_repeat('c', 64)]);
        $campaign = Campaign::query()->create(['reference' => 'CMP-'.uniqid(), 'name' => 'Avis', 'status' => 'active', 'priority' => 50, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(2), 'created_by' => $author->id, 'published_at' => now()]);
        $campaign->items()->create(['content_id' => $content->id, 'position' => 1, 'version_map' => ['fr' => $version->id]]);
        DB::table('campaign_languages')->insert(['campaign_id' => $campaign->id, 'lang' => 'fr', 'rank' => 1]);
        $campaign->targets()->create(['target_type' => 'screen', 'target_id' => $screenId]);
    }
}
