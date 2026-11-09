<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Manifest;
use App\Services\ScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PUB-07, annexe C (audit 2026-11-06, écarts DIF-01 à DIF-05) : la preuve de diffusion survit à la purge des manifestes,
 * « dernière synchronisation » n'avance que sur un manifeste reçu, les lectures agrégées sont comptées, et une alerte
 * critique s'affiche hors plage de fonctionnement.
 */
class DeliveryProofTest extends TestCase
{
    public function test_delivery_proof_survives_the_purge_of_old_manifests(): void
    {
        $screen = $this->screen('ECR-PREUVE');
        [$device, $token] = $this->pairedDevice($screen);
        $campaign = $this->publishedCampaign();

        $payload = json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json('payload'), true);
        $this->assertSame($campaign->id, $payload['schedule'][0]['campaign_id']);
        $this->assertDatabaseHas('campaign_transmissions', ['device_id' => $device->id, 'campaign_id' => $campaign->id, 'first_manifest_version' => $payload['version']]);

        $this->postReceipts($token, [
            ['kind' => 'manifest_received', 'manifest_version' => $payload['version']],
            ['kind' => 'activated', 'manifest_version' => $payload['version']],
            ['kind' => 'played', 'ref_type' => 'campaign', 'ref_id' => $campaign->id, 'manifest_version' => $payload['version'], 'payload' => ['plays' => 4, 'duration_s' => 60]],
        ]);
        Manifest::query()->where('device_id', $device->id)->delete();

        $diffuser = $this->userWith('diffuseur');
        $this->app['auth']->forgetGuards();
        $delivery = $this->actingAs($diffuser)->getJson("/api/v1/campaigns/{$campaign->id}/delivery")->assertOk();
        $row = $delivery->json('data.rows.0');
        $this->assertNotNull($row['transmitted_at']);
        $this->assertNotNull($row['received_at']);
        $this->assertNotNull($row['activated_at']);
        $this->assertNotNull($row['played_at']);
        $this->assertSame(1, $delivery->json('data.summary.transmitted'));
        $this->assertSame(4, $delivery->json('data.summary.plays_declared'));
    }

    public function test_heartbeat_alone_never_advances_the_last_synchronisation(): void
    {
        [$device, $token] = $this->pairedDevice($this->screen('ECR-SYNC'));
        $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk();
        $issued = (int) Manifest::query()->where('device_id', $device->id)->max('version');

        $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String(), 'active_manifest_version' => $issued + 50], ['Authorization' => "Bearer $token"])->assertOk();
        $device->refresh();
        $this->assertNull($device->last_sync_at, 'un heartbeat ne prouve aucune synchronisation');
        $this->assertSame($issued, (int) $device->active_manifest_version, 'version déclarée bornée à la dernière version émise');

        $this->postReceipts($token, [['kind' => 'manifest_received', 'manifest_version' => $issued, 'occurred_at' => now()->addDays(3)->toIso8601String()]]);
        $device->refresh();
        $this->assertNotNull($device->last_sync_at);
        $this->assertTrue($device->last_sync_at->lte(now()), 'un instant futur est ramené à la réception');
        $this->assertTrue(CarbonImmutable::parse(DB::table('device_receipts')->where('device_id', $device->id)->value('occurred_at'))->lte(now()));
    }

    public function test_critical_alert_is_shown_outside_the_operating_window_but_not_a_warning(): void
    {
        $night = CarbonImmutable::parse('2026-11-04 23:00', 'Africa/Libreville')->utc();
        $alert = fn (string $severity) => ['id' => (string) Str::uuid(), 'severity' => $severity, 'starts_at' => $night->subMinutes(5)->toIso8601ZuluString(), 'expires_at' => $night->addMinutes(25)->toIso8601ZuluString()];
        $manifest = fn (array $alerts, bool $policy) => [
            'timezone' => 'Africa/Libreville', 'operating_window' => ['days' => [1, 2, 3, 4, 5], 'on' => '08:00', 'off' => '18:00'], 'non_working_days' => [],
            'alerts' => $alerts, 'alert_policy' => ['critical_overrides_window' => $policy], 'schedule' => [],
        ];
        $resolver = app(ScheduleResolver::class);

        $this->assertSame('alert', $resolver->resolve($manifest([$alert('critical'), $alert('warning')], true), $night)['mode']);
        $this->assertCount(1, $resolver->resolve($manifest([$alert('critical'), $alert('warning')], true), $night)['alerts']);
        $this->assertSame('off', $resolver->resolve($manifest([$alert('warning')], true), $night)['mode']);
        $this->assertSame('off', $resolver->resolve($manifest([$alert('critical')], false), $night)['mode']);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function postReceipts(string $token, array $events): void
    {
        $events = array_map(fn (array $e) => $e + ['event_id' => (string) Str::uuid(), 'occurred_at' => now()->subMinute()->toIso8601String()], $events);
        $this->postJson('/api/v1/device/receipts', ['events' => $events], ['Authorization' => "Bearer $token"])->assertOk();
    }

    private function publishedCampaign(): Campaign
    {
        $author = $this->userWith('contributeur');
        $validator = $this->userWith('validateur', 'COM', ['fr']);
        $content = Content::query()->create(['reference' => 'CNT-PRV', 'title' => 'Annonce', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'public', 'expires_at' => now()->addDay(), 'status' => 'approved']);
        $version = ContentVersion::query()->create(['content_id' => $content->id, 'lang' => 'fr', 'revision' => 1, 'payload' => ['title' => 'Annonce', 'media' => []], 'classification' => 'public', 'author_id' => $author->id, 'approved_by' => $validator->id, 'approved_at' => now(), 'payload_hash' => str_repeat('b', 64)]);
        $campaign = Campaign::query()->create(['reference' => 'CMP-PRV', 'name' => 'Preuve', 'status' => 'active', 'priority' => 50, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'created_by' => $author->id, 'published_at' => now()]);
        $campaign->items()->create(['content_id' => $content->id, 'position' => 1, 'version_map' => ['fr' => $version->id]]);
        DB::table('campaign_languages')->insert(['campaign_id' => $campaign->id, 'lang' => 'fr', 'rank' => 1]);
        $campaign->targets()->create(['target_type' => 'floor', 'target_id' => $this->place()['floor']->id]);

        return $campaign;
    }
}
