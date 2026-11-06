<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Manifest;
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

    public function test_holiday_override_of_a_screen_reaches_its_manifest(): void
    {
        $screen = $this->screen('ECR-FERIE', true, ['operating_window' => ['days' => [1, 2, 3, 4, 5], 'on' => '08:00', 'off' => '18:00', 'ignore_holidays' => true], 'window_override_reason' => 'Sommet des chefs d’État']);
        [, $token] = $this->pairedDevice($screen);

        $payload = json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json('payload'), true);

        $this->assertTrue($payload['operating_window']['ignore_holidays']);
    }
}
