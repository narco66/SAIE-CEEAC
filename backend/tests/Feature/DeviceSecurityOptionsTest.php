<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Location;
use App\Models\Screen;
use App\Models\Site;
use App\Services\ManifestBuilder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lot 24 (bloc 3) : protections des lecteurs livrées désactivées — liste blanche d'enrôlement, requêtes signées avec
 * anti-rejeu, stockage chiffré du jeton —, annuaire réservé, fuseau horaire par site.
 */
class DeviceSecurityOptionsTest extends TestCase
{
    private function setting(string $key, mixed $value): void
    {
        $this->actingAs($this->userWith('admin_tech', 'DSI'))->patchJson('/api/v1/settings', ['key' => $key, 'value' => $value, 'reason' => 'Décision DSI (essai)'])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    public function test_enrollment_allowlist_is_off_by_default_then_enforced(): void
    {
        $request = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/device/pairing-codes', ['hardware_id' => 'hw-'.Str::random(6)]);
        $request('203.0.113.9')->assertCreated();

        $this->setting('devices.enroll_cidrs', '10.20.0.0/16, 192.168.1.10');
        $request('203.0.113.9')->assertForbidden()->assertJsonPath('code', 'ENROLLMENT_NOT_ALLOWED');
        $request('10.20.4.7')->assertCreated();
        $request('192.168.1.10')->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.enrollment_refused', 'outcome' => 'denied']);
    }

    public function test_signed_requests_with_replay_protection_when_enabled(): void
    {
        [$device, $token] = $this->pairedDevice($this->screen('ECR-SIG'));
        $body = json_encode(['device_time' => now()->toIso8601String()]);
        $headers = function (string $nonce, ?string $override = null, ?int $ts = null) use ($token, $body): array {
            $key = hash('sha256', Str::after($token, '|'));
            $timestamp = (string) ($ts ?? time());
            $bodyHash = hash('sha256', $body);

            return ['Authorization' => "Bearer $token", 'Content-Type' => 'application/json', 'X-SAIE-Timestamp' => $timestamp, 'X-SAIE-Nonce' => $nonce, 'X-SAIE-Body-Hash' => $bodyHash,
                'X-SAIE-Signature' => $override ?? hash_hmac('sha256', implode("\n", ['POST', '/api/v1/device/heartbeat', $timestamp, $nonce, $bodyHash]), $key)];
        };
        $send = fn (array $h) => $this->call('POST', '/api/v1/device/heartbeat', [], [], [], collect($h)->mapWithKeys(fn ($v, $k) => [$k === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all() + ['HTTP_ACCEPT' => 'application/json'], $body);

        $send(['Authorization' => "Bearer $token", 'Content-Type' => 'application/json'])->assertOk();
        $send($headers('nonce-0000000000001', 'faux'))->assertOk();

        $this->setting('devices.signed_requests', true);
        $send(['Authorization' => "Bearer $token", 'Content-Type' => 'application/json'])->assertUnauthorized()->assertJsonPath('code', 'SIGNATURE_REQUIRED');
        $send($headers('nonce-0000000000002', 'faux'))->assertUnauthorized()->assertJsonPath('code', 'SIGNATURE_INVALID');
        $send($headers('nonce-0000000000003', null, time() - 900))->assertUnauthorized()->assertJsonPath('code', 'SIGNATURE_INVALID');
        $send($headers('nonce-0000000000004'))->assertOk();
        $send($headers('nonce-0000000000004'))->assertUnauthorized()->assertJsonPath('code', 'REPLAYED_REQUEST');
        $this->assertNotNull($device->fresh()->last_seen_at);
    }

    public function test_secure_storage_flag_and_restricted_directory(): void
    {
        [, $token] = $this->pairedDevice($this->screen('ECR-STO'));
        $beat = fn () => $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String()], ['Authorization' => "Bearer $token"]);
        $beat()->assertOk()->assertJsonPath('secure_storage', false);
        $this->app['auth']->forgetGuards();
        $this->setting('devices.secure_storage', true);
        $beat()->assertOk()->assertJsonPath('secure_storage', true);
        $this->app['auth']->forgetGuards();

        $agent = $this->userWith('agent');
        $this->assertNotEmpty($this->actingAs($agent)->getJson('/api/v1/referentials')->assertOk()->json('data.users'), 'annuaire ouvert par défaut');
        $this->setting('referentials.restricted', true);
        $this->assertSame([], $this->actingAs($agent)->getJson('/api/v1/referentials')->assertOk()->json('data.users'));
        $this->assertNotEmpty($this->actingAs($this->userWith('admin_fonc', 'DSI'))->getJson('/api/v1/referentials')->json('data.users'));
    }

    public function test_the_manifest_uses_the_site_timezone(): void
    {
        $default = $this->screen('ECR-LBV');
        $this->assertSame('Africa/Libreville', app(ManifestBuilder::class)->compileForScreen($default)['timezone']);

        $site = Site::query()->create(['code' => 'KIN', 'name' => 'Kinshasa', 'timezone' => 'Africa/Kinshasa']);
        $building = Building::query()->create(['site_id' => $site->id, 'code' => 'KIN-1', 'name' => 'Bureau de Kinshasa']);
        $floor = Floor::query()->create(['building_id' => $building->id, 'code' => 'KIN-RDC', 'label' => 'RDC Kinshasa', 'level' => 0]);
        $location = Location::query()->create(['floor_id' => $floor->id, 'zone_id' => $this->place()['zone_public']->id, 'code' => 'KIN-HALL', 'name' => 'Hall de Kinshasa']);
        $remote = Screen::query()->create(['code' => 'ECR-KIN', 'name' => 'Kinshasa', 'kind' => 'display', 'location_id' => $location->id, 'orientation' => 'landscape', 'resolution' => '1920x1080']);
        $this->assertSame('Africa/Kinshasa', app(ManifestBuilder::class)->compileForScreen($remote)['timezone']);
    }
}
