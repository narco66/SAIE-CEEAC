<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Manifest;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PLY-03, PLY-08, PERF-01 : livraison des médias et plage de fonctionnement transmises aux lecteurs.
 */
class DeviceDeliveryTest extends TestCase
{
    public function test_media_of_the_previous_manifest_stays_downloadable_and_is_sent_by_nginx_in_production(): void
    {
        [$device, $token] = $this->pairedDevice($this->screen('ECR-LIV'));
        $owner = $this->userWith('contributeur');
        $asset = Asset::query()->create(['reference' => 'MED-LIV', 'title' => 'x', 'kind' => 'image', 'owner_structure_id' => $owner->structure_id, 'classification' => 'public', 'status' => 'ready']);
        $version = $asset->versions()->create([
            'version' => 1, 'storage_key' => 'assets/liv.png', 'original_name' => 'liv.png', 'extension' => 'png', 'size' => 10, 'mime' => 'image/png',
            'sha256' => str_repeat('c', 64), 'scan_status' => 'ready',
        ]);
        $manifest = fn (int $n, array $assets) => Manifest::query()->create([
            'device_id' => $device->id, 'version' => $n, 'etag' => "e$n", 'payload' => ['assets' => $assets], 'valid_until' => now()->addHour(), 'generated_at' => now(),
        ]);
        $manifest(1, [['id' => $version->id]]);
        $manifest(2, []);
        config(['saie.device.accel_redirect' => '/protected-storage/']);

        $response = $this->get("/api/v1/device/assets/{$version->id}", ['Authorization' => "Bearer $token"])->assertOk();
        $this->assertSame('/protected-storage/assets/liv.png', $response->headers->get('X-Accel-Redirect'));
        $this->assertSame(str_repeat('c', 64), $response->headers->get('X-Content-SHA256'));

        $manifest(3, []);
        $this->get("/api/v1/device/assets/{$version->id}", ['Authorization' => "Bearer $token"])->assertForbidden();
    }

    public function test_screen_without_own_window_inherits_its_group_window(): void
    {
        $screen = $this->screen('ECR-GRP', true, ['operating_window' => null]);
        $diffuser = $this->userWith('diffuseur');
        $this->actingAs($diffuser)->postJson('/api/v1/screen-groups', [
            'code' => 'G-SAMEDI', 'name' => 'Ouverts le samedi', 'screen_ids' => [$screen->id],
            'operating_window' => ['days' => [1, 2, 3, 4, 5, 6], 'on' => '07:00', 'off' => '20:00'],
        ])->assertCreated();
        [, $token] = $this->pairedDevice($screen);
        $this->app['auth']->forgetGuards();

        $payload = json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json('payload'), true);

        $this->assertSame('07:00', $payload['operating_window']['on']);
        $this->assertSame([1, 2, 3, 4, 5, 6], $payload['operating_window']['days']);
    }

    public function test_holiday_override_of_a_screen_reaches_its_manifest(): void
    {
        $screen = $this->screen('ECR-FERIE', true, ['operating_window' => ['days' => [1, 2, 3, 4, 5], 'on' => '08:00', 'off' => '18:00', 'ignore_holidays' => true], 'window_override_reason' => 'Sommet des chefs d’État']);
        [, $token] = $this->pairedDevice($screen);

        $payload = json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json('payload'), true);

        $this->assertTrue($payload['operating_window']['ignore_holidays']);
    }

    public function test_next_signing_key_is_announced_in_signed_manifests_for_rotation_without_repairing(): void
    {
        [, $token] = $this->pairedDevice($this->screen('ECR-CLE'));
        config(['saie.device.next_signing_key_id' => 'mk-2027-01', 'saie.device.next_signing_key_public' => base64_encode(random_bytes(32))]);

        $payload = json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json('payload'), true);

        $this->assertSame([config('saie.device.signing_key_id'), 'mk-2027-01'], array_column($payload['trusted_keys'], 'key_id'));
    }

    public function test_media_download_supports_byte_ranges_for_resumption(): void
    {
        [$device, $token] = $this->pairedDevice($this->screen('ECR-RANGE'));
        $owner = $this->userWith('contributeur');
        Storage::disk('local')->put('assets/range.bin', '0123456789ABCDEF');
        $asset = Asset::query()->create(['reference' => 'MED-RANGE', 'title' => 'x', 'kind' => 'image', 'owner_structure_id' => $owner->structure_id, 'classification' => 'public', 'status' => 'ready']);
        $version = $asset->versions()->create(['version' => 1, 'storage_key' => 'assets/range.bin', 'original_name' => 'r.png', 'extension' => 'png', 'size' => 16, 'mime' => 'image/png', 'sha256' => str_repeat('d', 64), 'scan_status' => 'ready']);
        Manifest::query()->create(['device_id' => $device->id, 'version' => 1, 'etag' => 'e1', 'payload' => ['assets' => [['id' => $version->id]]], 'valid_until' => now()->addHour(), 'generated_at' => now()]);

        $response = $this->get("/api/v1/device/assets/{$version->id}", ['Authorization' => "Bearer $token", 'Range' => 'bytes=4-9']);
        $response->assertStatus(206);
        $this->assertSame('456789', $response->streamedContent());
    }

    public function test_player_served_from_the_administration_domain_is_never_treated_as_a_session(): void
    {
        // Régression constatée en recette locale : le lecteur, servi sous /player/ sur le domaine déclaré « avec état », recevait
        // une erreur 500 à chaque appel (session ouverte pour un lecteur) et ses POST auraient exigé le jeton CSRF.
        config(['session.driver' => 'database']);
        [, $token] = $this->pairedDevice($this->screen('ECR-MEME-DOMAINE'));
        $headers = ['Authorization' => "Bearer $token", 'Referer' => 'http://localhost:5173/player/', 'Origin' => 'http://localhost:5173'];

        $this->getJson('/api/v1/device/manifest', $headers)->assertOk();
        $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String()], $headers)->assertOk();
    }
}
