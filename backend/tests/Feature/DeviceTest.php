<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\PairingCode;
use App\Models\Reservation;
use App\Services\Access;
use App\Services\ManifestSigner;
use App\Services\ScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lecteurs : appairage (PLY-02), manifeste signé et ETag (PLY-03, PLY-07), révocation (R17), reçus dédoublonnés (R26),
 * classification par zone (DOC-04), confidentialité de l'agenda et du panneau (R11).
 */
class DeviceTest extends TestCase
{
    private function decode(array $envelope): array
    {
        $this->assertTrue(app(ManifestSigner::class)->verify($envelope['payload'], $envelope['signature']['value']), 'signature Ed25519 valide');

        return json_decode($envelope['payload'], true);
    }

    public function test_one_time_pairing_flow_delivers_individual_token_once(): void
    {
        $screen = $this->screen('ECR-T-01');
        $admin = $this->userWith('admin_tech', 'DSI');
        $code = $this->postJson('/api/v1/device/pairing-codes', ['hardware_id' => 'hw-test-1', 'info' => ['app_version' => '1.0.0']])->assertCreated();
        $this->getJson("/api/v1/device/pairing-codes/{$code['code']}", ['X-Poll-Secret' => $code['poll_secret']])->assertOk()->assertJsonPath('status', 'waiting');
        $this->getJson("/api/v1/device/pairing-codes/{$code['code']}", ['X-Poll-Secret' => 'mauvais'])->assertNotFound();

        $this->actingAs($admin)->postJson('/api/v1/devices/pair', ['code' => $code['code'], 'screen_id' => $screen->id])->assertCreated()->assertJsonMissingPath('data.token');
        $this->actingAs($admin)->postJson('/api/v1/devices/pair', ['code' => $code['code'], 'screen_id' => $screen->id])->assertStatus(409)->assertJsonPath('code', 'CODE_USED');

        $this->app['auth']->forgetGuards();
        $first = $this->getJson("/api/v1/device/pairing-codes/{$code['code']}", ['X-Poll-Secret' => $code['poll_secret']])->assertOk()->assertJsonPath('status', 'paired');
        $this->assertNotEmpty($first['token']);
        $this->getJson("/api/v1/device/pairing-codes/{$code['code']}", ['X-Poll-Secret' => $code['poll_secret']])->assertStatus(410);

        $this->getJson('/api/v1/device/manifest', ['Authorization' => 'Bearer '.$first['token']])->assertOk();
    }

    public function test_expired_pairing_code_is_refused(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $code = $this->postJson('/api/v1/device/pairing-codes', ['hardware_id' => 'hw-test-2'])->json();
        PairingCode::query()->where('code', $code['code'])->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($admin)->postJson('/api/v1/devices/pair', ['code' => $code['code'], 'screen_id' => $this->screen('ECR-T-02')->id])->assertStatus(409)->assertJsonPath('code', 'CODE_EXPIRED');
    }

    public function test_manifest_is_signed_versioned_and_conditional(): void
    {
        [$device, $token] = $this->pairedDevice($this->screen('ECR-T-03'));
        $response = $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk();
        $manifest = $this->decode($response->json());
        $this->assertSame($device->code, $manifest['device_id']);
        $this->assertSame('Africa/Libreville', $manifest['timezone']);
        $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token", 'If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
    }

    public function test_internal_content_never_reaches_public_zone_and_restricted_never_reaches_any_screen(): void
    {
        $public = $this->screen('ECR-PUB');
        $internal = $this->screen('ECR-INT', false);
        [, $publicToken] = $this->pairedDevice($public);
        [, $internalToken] = $this->pairedDevice($internal);
        $author = $this->userWith('contributeur');
        $validator = $this->userWith('validateur', 'COM', ['fr']);
        $content = Content::query()->create(['reference' => 'CNT-T1', 'title' => 'Note interne', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'interne', 'expires_at' => now()->addDay(), 'status' => 'approved']);
        $version = ContentVersion::query()->create(['content_id' => $content->id, 'lang' => 'fr', 'revision' => 1, 'payload' => ['title' => 'Note interne', 'media' => []], 'classification' => 'interne', 'author_id' => $author->id, 'approved_by' => $validator->id, 'approved_at' => now(), 'payload_hash' => str_repeat('a', 64)]);
        $campaign = Campaign::query()->create(['reference' => 'CMP-T1', 'name' => 'Tous', 'status' => 'active', 'priority' => 50, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'created_by' => $author->id, 'published_at' => now()]);
        $campaign->items()->create(['content_id' => $content->id, 'position' => 1, 'version_map' => ['fr' => $version->id]]);
        DB::table('campaign_languages')->insert(['campaign_id' => $campaign->id, 'lang' => 'fr', 'rank' => 1]);
        $campaign->targets()->create(['target_type' => 'floor', 'target_id' => $this->place()['floor']->id]);

        $this->assertCount(0, $this->decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $publicToken"])->json())['schedule']);
        $this->app['auth']->forgetGuards();
        $internalManifest = $this->decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $internalToken"])->json());
        $this->assertCount(1, $internalManifest['schedule']);
        $this->assertSame('interne', $internalManifest['schedule'][0]['auth_class']);
        $this->assertTrue(strtotime($internalManifest['authorization']['interne']) <= strtotime('+1 hour 1 minute'), 'autorisation interne limitée à 1 h');
    }

    public function test_confidential_meeting_never_appears_in_device_manifests(): void
    {
        $room = $this->room(['name' => 'Salle Kivu']);
        $panel = $this->screen('PAN-KIVU', true, ['kind' => 'room_panel', 'room_id' => $room->id, 'resolution' => '1280x800']);
        [, $token] = $this->pairedDevice($panel);
        $organizer = $this->userWith('agent');
        $reservation = Reservation::query()->create(['reference' => 'RES-T1', 'organizer_id' => $organizer->id, 'subject' => 'Arbitrages secrets', 'confidential' => true, 'participant_count' => 5, 'internal_participants' => [$organizer->id]]);
        $start = now()->addMinutes(10);
        $reservation->occurrences()->create(['room_id' => $room->id, 'starts_at' => $start, 'ends_at' => $start->addHour(), 'blocking_start' => $start, 'blocking_end' => $start->addHour(), 'status' => 'confirmed']);

        $raw = $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->getContent();
        $this->assertStringNotContainsString('Arbitrages', $raw);
        $this->assertStringNotContainsString($organizer->name, $raw);
        $manifest = $this->decode(json_decode($raw, true));
        $this->assertSame('Réservée', $manifest['room_panel']['occurrences'][0]['label']);
        $this->assertSame('Réservée', $manifest['agenda'][0]['label']);
    }

    public function test_revoked_device_is_refused_by_the_api(): void
    {
        [$device, $token] = $this->pairedDevice($this->screen('ECR-T-04'));
        $admin = $this->userWith('admin_tech', 'DSI');
        $this->actingAs($admin)->postJson("/api/v1/devices/{$device->id}/revoke", ['reason' => 'Terminal volé'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.revoked', 'reason' => 'Terminal volé']);
    }

    public function test_device_token_gives_no_access_to_user_routes_and_user_cannot_use_device_routes(): void
    {
        [, $token] = $this->pairedDevice($this->screen('ECR-T-05'));
        $this->getJson('/api/v1/documents', ['Authorization' => "Bearer $token"])->assertUnauthorized();
        $this->actingAs($this->userWith('admin_tech', 'DSI'))->getJson('/api/v1/device/manifest')->assertUnauthorized();
    }

    public function test_deferred_receipts_are_deduplicated_and_heartbeat_tracks_clock_skew(): void
    {
        [$device, $token] = $this->pairedDevice($this->screen('ECR-T-06'));
        $events = collect(range(1, 3))->map(fn () => ['event_id' => (string) Str::uuid(), 'kind' => 'played', 'occurred_at' => now()->subMinutes(5)->toIso8601String(), 'ref_type' => 'campaign', 'ref_id' => 'x'])->all();
        $this->postJson('/api/v1/device/receipts', ['events' => $events], ['Authorization' => "Bearer $token"])->assertOk()->assertJson(['accepted' => 3, 'duplicates' => 0]);
        $this->postJson('/api/v1/device/receipts', ['events' => $events], ['Authorization' => "Bearer $token"])->assertOk()->assertJson(['accepted' => 0, 'duplicates' => 3]);

        $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->addSeconds(42)->toIso8601String(), 'app_version' => '1.0.0'], ['Authorization' => "Bearer $token"])->assertOk();
        $this->assertEqualsWithDelta(42000, $device->refresh()->clock_skew_ms, 2000);
        $this->assertSame('online', $device->computedNetworkStatus());
        $device->forceFill(['last_seen_at' => now()->subMinutes(2)])->save();
        $this->assertSame('suspect', $device->computedNetworkStatus());
        $device->forceFill(['last_seen_at' => now()->subMinutes(6)])->save();
        $this->assertSame('unavailable', $device->computedNetworkStatus());
    }

    public function test_device_can_only_download_assets_listed_in_its_manifest(): void
    {
        [, $token] = $this->pairedDevice($this->screen('ECR-T-07'));
        $owner = $this->userWith('contributeur');
        $asset = Asset::query()->create(['reference' => 'MED-T', 'title' => 'x', 'kind' => 'image', 'owner_structure_id' => $owner->structure_id, 'classification' => 'public', 'status' => 'ready']);
        $version = $asset->versions()->create(['version' => 1, 'storage_key' => 'assets/x.png', 'original_name' => 'x.png', 'extension' => 'png', 'size' => 10, 'mime' => 'image/png', 'sha256' => str_repeat('b', 64), 'scan_status' => 'ready']);
        $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"]);
        $this->get("/api/v1/device/assets/{$version->id}", ['Authorization' => "Bearer $token"])->assertForbidden();
    }

    public function test_alert_preempts_programme_in_manifest_and_requires_urgency_power(): void
    {
        $screen = $this->screen('ECR-T-08');
        [, $token] = $this->pairedDevice($screen);
        $diffuser = $this->userWith('diffuseur', 'DSI');
        $payload = ['title' => 'Évacuation', 'message' => 'Quittez calmement les locaux.', 'severity' => 'critical', 'duration_min' => 30, 'justification' => 'Exercice incendie annoncé', 'targets' => [['type' => 'all']], 'confirm' => true];
        $this->actingAs($diffuser)->postJson('/api/v1/alerts', $payload)->assertForbidden();

        $expired = $this->grantPower($diffuser, 'urgence', null, now()->subDay());
        $this->actingAs($diffuser)->postJson('/api/v1/alerts', $payload)->assertForbidden();
        $expired->forceFill(['ends_at' => now()->addDay()])->save();
        app(Access::class)->flush();

        $alert = $this->actingAs($diffuser)->postJson('/api/v1/alerts', $payload)->assertCreated();
        $this->app['auth']->forgetGuards();
        $manifest = $this->decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->json());
        $this->assertSame('Évacuation', $manifest['alerts'][0]['title']);
        $this->assertSame('alert', app(ScheduleResolver::class)->resolve($manifest, CarbonImmutable::now())['mode']);

        $this->actingAs($diffuser)->postJson("/api/v1/alerts/{$alert->json('data.id')}/extend", ['minutes' => 15, 'reason' => 'Exercice prolongé'])->assertOk();
        $this->actingAs($diffuser)->postJson("/api/v1/alerts/{$alert->json('data.id')}/revoke", ['reason' => 'Fin de l’exercice'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.extended', 'power' => 'urgence', 'actor_id' => $diffuser->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.revoked', 'power' => 'urgence']);
    }
}
