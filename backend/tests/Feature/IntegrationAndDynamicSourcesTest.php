<?php

namespace Tests\Feature;

use App\Models\DynamicSource;
use App\Models\Reservation;
use App\Models\Setting;
use App\Services\DynamicSources;
use App\Services\ManifestSigner;
use App\Support\BusinessException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PUB-10 (sources dynamiques en liste blanche, SSRF) et API-07 / ACC-03 (API d'intégration par comptes de service).
 */
class IntegrationAndDynamicSourcesTest extends TestCase
{
    public function test_rss_source_is_fetched_by_server_and_embedded_in_manifest(): void
    {
        Http::fake(['https://www.ceeac-eccas.org/*' => Http::response('<rss><channel><item><title>Sommet des chefs d’État</title><pubDate>Tue, 03 Nov 2026 10:00:00 GMT</pubDate></item><item><title><![CDATA[<b>Nouvelle</b> publication]]></title></item></channel></rss>', 200)]);
        $sources = $this->partialMock(DynamicSources::class, fn ($mock) => $mock->shouldReceive('assertSafeUrl')->andReturn(['93.184.216.34']));
        $source = DynamicSource::query()->create(['code' => 'actus', 'name' => 'Actualités', 'type' => 'rss', 'url' => 'https://www.ceeac-eccas.org/feed', 'is_active' => true]);
        $this->assertSame('ok', $sources->fetch($source)['status']);

        [, $token] = $this->pairedDevice($this->screen('ECR-DYN'));
        $envelope = $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json();
        $this->assertTrue(app(ManifestSigner::class)->verify($envelope['payload'], $envelope['signature']['value']));
        $manifest = json_decode($envelope['payload'], true);
        $this->assertSame(['Sommet des chefs d’État', 'Nouvelle publication'], array_column($manifest['dynamic']['actus']['items'], 'title'));
    }

    public function test_internal_or_plain_http_urls_are_refused(): void
    {
        $sources = app(DynamicSources::class);
        foreach (['http://example.org/feed', 'https://127.0.0.1/feed', 'https://localhost/feed', 'https://user:pass@example.org/'] as $url) {
            try {
                $sources->assertSafeUrl($url);
                $this->fail("URL acceptée à tort : $url");
            } catch (BusinessException $e) {
                $this->assertSame('SOURCE_URL_REFUSED', $e->errorCode);
            }
        }
        $admin = $this->userWith('admin_tech', 'DSI');
        $this->actingAs($admin)->postJson('/api/v1/dynamic-sources', ['code' => 'interne', 'name' => 'Interne', 'type' => 'json', 'url' => 'https://127.0.0.1/data'])->assertUnprocessable()->assertJsonPath('code', 'SOURCE_URL_REFUSED');
    }

    public function test_integration_api_requires_enabled_setting_scoped_service_token_and_masks_confidential(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $created = $this->actingAs($admin)->postJson('/api/v1/service-accounts', ['name' => 'Système d’accueil', 'scopes' => ['integration:meetings'], 'reason' => 'Raccordement du futur accueil'])->assertCreated();
        $token = $created->json('token');
        $this->app['auth']->forgetGuards();

        $room = $this->room(['name' => 'Salle Congo']);
        $reservation = Reservation::query()->create(['reference' => 'RES-I1', 'organizer_id' => $admin->id, 'subject' => 'Arbitrages secrets', 'confidential' => true, 'participant_count' => 4]);
        $start = now()->setTime(10, 0);
        $reservation->occurrences()->create(['room_id' => $room->id, 'starts_at' => $start, 'ends_at' => $start->addHour(), 'blocking_start' => $start, 'blocking_end' => $start->addHour(), 'status' => 'confirmed']);

        $this->getJson('/api/v1/integration/meetings', ['Authorization' => "Bearer $token"])->assertForbidden()->assertJsonPath('code', 'INTEGRATION_DISABLED');
        Setting::put('integrations.api_enabled', true);
        $response = $this->getJson('/api/v1/integration/meetings?date='.now('Africa/Libreville')->toDateString(), ['Authorization' => "Bearer $token"])->assertOk();
        $this->assertSame('Réservée', $response->json('data.0.label'));
        $this->assertStringNotContainsString('Arbitrages', $response->getContent());
        $this->getJson('/api/v1/integration/screens', ['Authorization' => "Bearer $token"])->assertForbidden();
        $this->getJson('/api/v1/documents', ['Authorization' => "Bearer $token"])->assertUnauthorized();

        $this->actingAs($admin)->postJson("/api/v1/service-accounts/{$created->json('data.id')}/revoke", ['reason' => 'Fin du raccordement'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/integration/meetings', ['Authorization' => "Bearer $token"])->assertUnauthorized();
    }
}
