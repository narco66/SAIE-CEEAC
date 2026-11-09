<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Services\ManifestBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lot 22 : journal des échanges (§17.6), agent local distinguant un lecteur arrêté d'une perte réseau (§10.16), dalle
 * éteinte pendant la plage (§10.18), repli prolongé (§10.19), historique des erreurs codées (§10.9f, §13.2-e).
 */
class SupervisionAgentAndExchangesTest extends TestCase
{
    public function test_device_exchanges_are_logged_listed_and_purged(): void
    {
        [$device, $token] = $this->pairedDevice($this->screen('ECR-ECH'));
        $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk();
        $this->assertDatabaseHas('device_exchanges', ['device_id' => $device->id, 'endpoint' => 'api/v1/device/manifest', 'method' => 'GET', 'status' => 200]);

        $this->app['auth']->forgetGuards();
        $logs = $this->actingAs($this->userWith('admin_tech', 'DSI'))->getJson("/api/v1/devices/{$device->id}/logs?source=exchanges")->assertOk();
        $this->assertSame('GET api/v1/device/manifest', $logs->json('data.0.payload.route'));

        DB::table('device_exchanges')->update(['occurred_at' => now()->subDays(40)]);
        $this->artisan('saie:retention --apply')->assertSuccessful();
        $this->assertSame(0, DB::table('device_exchanges')->count(), 'purgé au-delà de 30 jours');
    }

    public function test_agent_heartbeat_distinguishes_a_stopped_player(): void
    {
        $screen = $this->screen('ECR-AGT');
        [$device, $token] = $this->pairedDevice($screen);
        $agentToken = $this->postJson('/api/v1/device/agent-token', [], ['Authorization' => "Bearer $token"])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/device/agent-heartbeat', ['agent_version' => '1.2.0'], ['Authorization' => "Bearer $token"])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $agentToken"])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/device/agent-heartbeat', ['agent_version' => '1.2.0', 'disk_free_gb' => 12.5], ['Authorization' => "Bearer $agentToken"])->assertOk();
        $this->assertNotNull($device->fresh()->agent_seen_at);

        $device->forceFill(['last_seen_at' => now()->subMinutes(20)])->save();
        $this->app['auth']->forgetGuards();
        $admin = $this->userWith('admin_tech', 'DSI');
        $this->actingAs($admin)->getJson("/api/v1/screens/{$screen->id}")->assertJsonPath('data.operational_state.code', 'player_stopped');
        $this->artisan('saie:tick')->assertSuccessful();
        $this->assertDatabaseHas('incidents', ['device_id' => $device->id, 'kind' => 'player_stopped', 'category' => 'player', 'status' => 'open']);
        $this->assertDatabaseMissing('incidents', ['device_id' => $device->id, 'kind' => 'device_unavailable']);
    }

    public function test_panel_off_during_the_window_and_prolonged_fallback_open_incidents(): void
    {
        $dark = $this->screen('ECR-NOIR', true, ['control_channel' => 'cec', 'panel_state' => 'off', 'panel_state_at' => now()->subMinutes(20)]);
        [$darkDevice] = $this->pairedDevice($dark);

        $fallback = $this->screen('ECR-REPLI');
        [$fallbackDevice] = $this->pairedDevice($fallback);
        $this->activeCampaignOn($fallback->id);
        app(ManifestBuilder::class)->current($fallbackDevice);
        $fallbackDevice->forceFill(['current_playback' => ['mode' => 'fallback', 'at' => now()->subMinutes(45)->toIso8601String()]])->save();

        $this->artisan('saie:tick')->assertSuccessful();
        $this->assertDatabaseHas('incidents', ['device_id' => $darkDevice->id, 'kind' => 'panel_off', 'category' => 'panel', 'status' => 'open']);
        $this->assertDatabaseHas('incidents', ['device_id' => $fallbackDevice->id, 'kind' => 'fallback_prolonged', 'status' => 'open']);
        $this->assertDatabaseMissing('incidents', ['device_id' => $fallbackDevice->id, 'kind' => 'no_programme']);
    }

    public function test_coded_errors_appear_in_the_screen_history(): void
    {
        $screen = $this->screen('ECR-ERR');
        [$device, $token] = $this->pairedDevice($screen);
        $this->postJson('/api/v1/device/receipts', ['events' => [['event_id' => (string) Str::uuid(), 'kind' => 'error', 'occurred_at' => now()->toIso8601String(),
            'ref_type' => 'entry', 'ref_id' => 'e-1', 'payload' => ['code' => 'MEDIA_VIDEO', 'message' => 'Format vidéo non pris en charge par ce lecteur', 'entry_id' => 'e-1']]]], ['Authorization' => "Bearer $token"])->assertOk();

        $this->app['auth']->forgetGuards();
        $history = $this->actingAs($this->userWith('admin_tech', 'DSI'))->getJson("/api/v1/screens/{$screen->id}/history?category=error")->assertOk()->json('data');
        $this->assertSame('Erreur MEDIA_VIDEO', $history[0]['label']);
        $this->assertSame('Format vidéo non pris en charge par ce lecteur', $history[0]['detail']);
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
