<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Incident;
use App\Services\ManifestBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lots 17 à 19 : inventaire (code patrimonial, n° de série unique, recherche, dernière diffusion), supervision (adresse
 * observée, événements perdus, versions, journaux techniques, lecture résolue, alertes de connexion instable et de programme
 * vide), traçabilité (désactivation motivée, avant/après des campagnes et listes de lecture).
 */
class InventoryLogsAndTraceabilityTest extends TestCase
{
    public function test_inventory_code_unique_serial_and_extended_search(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $room = $this->room(['name' => 'Salle Kinshasa']);
        $first = $this->screen('ECR-INV-A', true, ['room_id' => $room->id, 'manufacturer' => 'Samsung']);
        $second = $this->screen('ECR-INV-B');
        $patch = fn ($screen, array $changes) => $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen->id}", $changes, ['If-Match' => (string) $screen->fresh()->version]);

        $patch($first, ['inventory_code' => 'PAT-2026-001', 'serial_number' => 'SN-ABC-1'])->assertOk()->assertJsonPath('data.inventory_code', 'PAT-2026-001');
        $patch($second, ['serial_number' => 'sn-abc-1'])->assertUnprocessable()->assertJsonValidationErrors('serial_number');
        $patch($second, ['inventory_code' => 'PAT-2026-001'])->assertUnprocessable()->assertJsonValidationErrors('inventory_code');

        $codes = fn (array $query) => collect($this->actingAs($admin)->getJson('/api/v1/screens?'.http_build_query($query))->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['ECR-INV-A'], $codes(['q' => 'PAT-2026']));
        $this->assertSame(['ECR-INV-A'], $codes(['q' => 'sn-abc']));
        $this->assertSame(['ECR-INV-A'], $codes(['q' => 'Kinshasa']));
        $this->assertSame(['ECR-INV-A'], $codes(['q' => 'samsung']));
        $this->assertSame(['ECR-INV-A'], $codes(['room_id' => $room->id]));
        $this->assertEqualsCanonicalizing(['ECR-INV-A', 'ECR-INV-B'], $codes(['building_id' => $this->place()['building']->id]));

        $locations = collect($this->actingAs($admin)->getJson('/api/v1/referentials')->assertOk()->json('data.locations'));
        $this->assertSame(2, $locations->firstWhere('code', 'HALL')['screens_count']);
    }

    public function test_heartbeat_records_observed_address_losses_and_version_changes(): void
    {
        [$device, $token] = $this->pairedDevice($this->screen('ECR-HB'));
        $headers = ['Authorization' => "Bearer $token", 'REMOTE_ADDR' => '10.20.30.40'];
        $beat = fn (array $extra) => $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String()] + $extra, $headers)->assertOk();

        $beat(['app_version' => '1.0.0', 'events_dropped' => 4, 'offline_since' => now()->subMinutes(20)->toIso8601String()]);
        $beat(['app_version' => '1.1.0', 'events_dropped' => 2]);

        $device->refresh();
        $this->assertSame('10.20.30.40', $device->observed_ip);
        $this->assertSame(6, $device->events_dropped, 'cumul des événements perdus');
        $this->assertNotNull($device->offline_since, 'la dernière coupure déclarée est conservée');
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.version_changed', 'object_id' => $device->id, 'actor_type' => 'device']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'device.version_changed')->count(), 'pas d’événement au premier battement');
        $shown = $this->actingAs($this->userWith('admin_tech', 'DSI'))->getJson('/api/v1/screens?per_page=50')->assertOk()->json('data.0.device');
        $this->assertSame([6, '10.20.30.40'], [$shown['events_dropped'], $shown['observed_ip']]);
        $this->assertNotNull($shown['offline_since']);
    }

    public function test_last_confirmed_play_resolved_playback_and_technical_logs(): void
    {
        $screen = $this->screen('ECR-LOG');
        [$device] = $this->pairedDevice($screen);
        $this->activeCampaignOn($screen->id);
        $manifest = app(ManifestBuilder::class)->current($device);
        $entry = $manifest->payload['schedule'][0];
        $device->forceFill(['active_manifest_version' => $manifest->version, 'current_playback' => ['entry_id' => $entry['entry_id'], 'lang' => 'fr', 'at' => now()->toIso8601String()]])->save();
        $receipt = fn (string $kind, array $payload = []) => DB::table('device_receipts')->insert(['id' => (string) Str::uuid(), 'event_id' => (string) Str::uuid(), 'device_id' => $device->id, 'kind' => $kind,
            'manifest_version' => $manifest->version, 'occurred_at' => now()->subMinutes(2), 'received_at' => now(), 'payload' => json_encode($payload)]);
        $receipt('played', ['plays' => 1]);
        $receipt('error', ['message' => 'Média illisible']);

        $admin = $this->userWith('admin_tech', 'DSI');
        $show = $this->actingAs($admin)->getJson("/api/v1/screens/{$screen->id}")->assertOk();
        $this->assertNotNull($show->json('data.last_played_at'));
        $this->assertSame('Avis', $show->json('data.current_playback_resolved.title'));
        $this->assertNotNull($show->json('data.current_playback_resolved.campaign_ref'));

        $logs = $this->actingAs($admin)->getJson("/api/v1/devices/{$device->id}/logs")->assertOk();
        $this->assertSame(['error'], array_column($logs->json('data'), 'kind'), 'les lectures ne sont pas des journaux techniques');
        $this->actingAs($admin)->getJson("/api/v1/devices/{$device->id}/logs?source=telemetry")->assertOk();
        $this->actingAs($this->userWith('contributeur'))->getJson("/api/v1/devices/{$device->id}/logs")->assertForbidden();
    }

    public function test_repeated_losses_and_empty_programme_open_incidents(): void
    {
        [$unstable] = $this->pairedDevice($this->screen('ECR-INSTABLE'));
        foreach (range(1, 3) as $i) {
            Incident::query()->create(['reference' => 'INC-L'.$i, 'screen_id' => $unstable->screen_id, 'device_id' => $unstable->id, 'kind' => 'device_unavailable', 'category' => 'network', 'severity' => 'major',
                'status' => 'resolved', 'title' => 'Lecteur indisponible', 'detected_at' => now()->subHours($i * 2), 'resolved_at' => now()->subHours($i * 2 - 1), 'is_automatic' => true]);
        }
        [$empty] = $this->pairedDevice($this->screen('ECR-VIDE'));
        app(ManifestBuilder::class)->current($empty);

        $this->artisan('saie:tick')->assertSuccessful();
        $this->assertDatabaseHas('incidents', ['device_id' => $unstable->id, 'kind' => 'unstable_connection', 'status' => 'open', 'severity' => 'medium']);
        $this->assertDatabaseHas('incidents', ['device_id' => $empty->id, 'kind' => 'no_programme', 'status' => 'open']);

        $this->activeCampaignOn($empty->screen_id);
        app(ManifestBuilder::class)->current($empty->fresh());
        $this->artisan('saie:tick')->assertSuccessful();
        $this->assertDatabaseHas('incidents', ['device_id' => $empty->id, 'kind' => 'no_programme', 'status' => 'resolved']);
    }

    public function test_deactivation_needs_a_reason_and_updates_keep_before_and_after(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $screen = $this->screen('ECR-OFF');
        $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen->id}", ['status' => 'inactive'], ['If-Match' => '1'])->assertUnprocessable()->assertJsonPath('code', 'REASON_REQUIRED');
        $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen->id}", ['status' => 'inactive', 'status_reason' => 'Dalle déposée pour réparation'], ['If-Match' => '1'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'screen.deactivated', 'object_id' => $screen->id, 'reason' => 'Dalle déposée pour réparation']);

        $diffuser = $this->userWith('diffuseur');
        $content = $this->approvedContent();
        $id = $this->actingAs($diffuser)->postJson('/api/v1/campaigns', ['name' => 'Avant', 'priority' => 30, 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addDays(2)->toIso8601String(),
            'items' => [['content_id' => $content->id]], 'languages' => ['fr'], 'targets' => [['type' => 'floor', 'id' => $this->place()['floor']->id]]])->assertCreated()->json('data.id');
        $this->actingAs($diffuser)->patchJson("/api/v1/campaigns/$id", ['name' => 'Après', 'targets' => [['type' => 'screen', 'id' => $this->screen('ECR-CIBLE')->id]]], ['If-Match' => '1'])->assertOk();
        $log = DB::table('audit_logs')->where('action', 'campaign.updated')->where('object_id', $id)->first();
        $before = json_decode($log->before, true);
        $after = json_decode($log->after, true);
        $this->assertSame(['Avant', 'Après'], [$before['name'], $after['name']]);
        $this->assertArrayHasKey('targets', $after, 'changement de cibles journalisé');
        $this->assertArrayNotHasKey('priority', $after, 'valeur inchangée non journalisée');

        $playlist = $this->actingAs($diffuser)->postJson('/api/v1/playlists', ['name' => 'Accueil', 'items' => [['content_id' => $content->id]]])->assertCreated()->json('data.id');
        $this->actingAs($diffuser)->patchJson("/api/v1/playlists/$playlist", ['name' => 'Accueil du soir'])->assertOk();
        $this->assertSame(['name' => 'Accueil du soir'], json_decode(DB::table('audit_logs')->where('action', 'playlist.updated')->where('object_id', $playlist)->value('after'), true));
        $this->assertSame(Campaign::query()->findOrFail($id)->name, 'Après');
    }

    private function approvedContent(): Content
    {
        $author = $this->userWith('contributeur');
        $content = Content::query()->create(['reference' => 'CNT-'.uniqid(), 'title' => 'Avis', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'public', 'expires_at' => now()->addDays(10), 'status' => 'approved']);
        ContentVersion::query()->create(['content_id' => $content->id, 'lang' => 'fr', 'revision' => 1, 'payload' => ['title' => 'Avis', 'media' => []], 'classification' => 'public', 'author_id' => $author->id, 'approved_by' => $this->userWith('validateur', 'COM', ['fr'])->id, 'approved_at' => now(), 'payload_hash' => str_repeat('b', 64)]);

        return $content;
    }

    private function activeCampaignOn(string $screenId): void
    {
        $content = $this->approvedContent();
        $version = ContentVersion::query()->where('content_id', $content->id)->firstOrFail();
        $campaign = Campaign::query()->create(['reference' => 'CMP-'.uniqid(), 'name' => 'Avis', 'status' => 'active', 'priority' => 50, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(2), 'created_by' => $content->author_id, 'published_at' => now()]);
        $campaign->items()->create(['content_id' => $content->id, 'position' => 1, 'version_map' => ['fr' => $version->id]]);
        DB::table('campaign_languages')->insert(['campaign_id' => $campaign->id, 'lang' => 'fr', 'rank' => 1]);
        $campaign->targets()->create(['target_type' => 'screen', 'target_id' => $screenId]);
    }
}
