<?php

namespace Tests\Feature;

use App\Models\PlayerRelease;
use Tests\TestCase;

/**
 * PLY-12 : canaux pilote/stable, retour arrière en une action, version cible portée par le manifeste signé.
 */
class PlayerReleaseTest extends TestCase
{
    public function test_channels_rollback_and_manifest_target(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $release = fn (string $v, string $c) => ['version' => $v, 'channel' => $c, 'base_url' => "/player/releases/$v/", 'sha256' => str_repeat('a', 64)];
        $this->actingAs($admin)->postJson('/api/v1/player-releases', $release('1.1.0', 'stable'))->assertUnprocessable()->assertJsonPath('code', 'PILOT_REQUIRED');
        $this->actingAs($admin)->postJson('/api/v1/player-releases', $release('1.1.0', 'pilot'))->assertCreated();
        $stable110 = $this->actingAs($admin)->postJson('/api/v1/player-releases', $release('1.1.0', 'stable'))->assertCreated();
        $this->travel(1)->seconds();
        $this->actingAs($admin)->postJson('/api/v1/player-releases', $release('1.2.0', 'pilot'))->assertCreated();

        [$device, $token] = $this->pairedDevice($this->screen('ECR-REL'));
        $this->app['auth']->forgetGuards();
        $manifest = fn () => json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->json('payload'), true);
        $this->assertSame('1.1.0', $manifest()['player_release']['version'], 'canal stable par défaut');

        $this->actingAs($admin)->patchJson("/api/v1/devices/{$device->id}/channel", ['channel' => 'pilot'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertSame('1.2.0', $manifest()['player_release']['version']);

        $pilot120 = PlayerRelease::query()->where('version', '1.2.0')->firstOrFail();
        $this->actingAs($admin)->postJson("/api/v1/player-releases/{$pilot120->id}/withdraw", ['reason' => 'Régression constatée au pilote'])->assertOk()->assertJsonPath('target', '1.1.0');
        $this->app['auth']->forgetGuards();
        $this->assertSame('1.1.0', $manifest()['player_release']['version'], 'retour arrière immédiat sur le canal pilote');
        $this->assertDatabaseHas('audit_logs', ['action' => 'player.release_withdrawn', 'reason' => 'Régression constatée au pilote']);
        $this->assertNotNull($stable110->json('data.id'));
    }
}
