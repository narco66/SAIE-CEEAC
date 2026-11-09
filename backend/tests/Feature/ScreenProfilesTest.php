<?php

namespace Tests\Feature;

use App\Models\ScreenGroup;
use App\Services\ManifestBuilder;
use Tests\TestCase;

/**
 * Profils de configuration (§7) : paramètres bornés, affectation à un groupe ou à un écran (l'écran prime), transmission
 * dans le manifeste, confirmation d'application par le lecteur et paramètres non pris en charge signalés.
 */
class ScreenProfilesTest extends TestCase
{
    private function profile(array $settings, string $code = 'HALL'): string
    {
        return $this->actingAs($this->userWith('admin_tech', 'DSI'))->postJson('/api/v1/screen-profiles', ['code' => $code, 'name' => "Profil $code", 'settings' => $settings])->assertCreated()->json('data.id');
    }

    public function test_settings_are_bounded_and_unknown_keys_are_dropped(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $this->actingAs($admin)->postJson('/api/v1/screen-profiles', ['code' => 'MAUVAIS', 'name' => 'Mauvais', 'settings' => ['brightness' => 140, 'languages' => ['de']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['settings.brightness', 'settings.languages.0']);
        $this->actingAs($admin)->postJson('/api/v1/screen-profiles', ['code' => 'COULOIR', 'name' => 'Couloir', 'settings' => ['volume_max' => 20, 'inconnu' => 1]])
            ->assertCreated()->assertJsonPath('data.settings.volume_max', 20)->assertJsonMissingPath('data.settings.inconnu');
        $this->actingAs($this->userWith('agent'))->postJson('/api/v1/screen-profiles', ['code' => 'X', 'name' => 'X', 'settings' => []])->assertForbidden();
    }

    public function test_the_screen_profile_overrides_the_group_profile_and_reaches_the_manifest(): void
    {
        $screen = $this->screen('ECR-PROFIL', true, ['audio_enabled' => true]);
        $group = ScreenGroup::query()->create(['code' => 'G-HALL', 'name' => 'Hall']);
        $group->screens()->attach($screen->id);
        $hall = $this->profile(['volume_max' => 10, 'languages' => ['fr', 'pt'], 'sync_interval_s' => 60]);
        $conference = $this->profile(['volume_max' => 30, 'brightness' => 70], 'CONFERENCE');
        $admin = $this->userWith('admin_tech', 'DSI');

        $this->actingAs($admin)->patchJson("/api/v1/screen-groups/{$group->id}", ['profile_id' => $hall], ['If-Match' => (string) $group->version])->assertOk();
        $manifest = app(ManifestBuilder::class)->compileForScreen($screen->fresh());
        $this->assertSame('HALL', $manifest['config']['profile']);
        $this->assertSame(['fr', 'pt'], (array) $manifest['config']['settings']->languages);
        $this->assertSame(10, $manifest['audio_policy']['max_volume'], 'le volume du profil plafonne la politique audio');

        $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen->id}", ['profile_id' => $conference], ['If-Match' => (string) $screen->fresh()->version])->assertOk()
            ->assertJsonPath('data.configuration.profile.code', 'CONFERENCE')->assertJsonPath('data.configuration.profile.inherited', false);
    }

    public function test_the_player_confirms_the_applied_configuration_and_reports_unsupported_settings(): void
    {
        $screen = $this->screen('ECR-CONF');
        [, $token] = $this->pairedDevice($screen);
        $admin = $this->userWith('admin_tech', 'DSI');
        $profile = $this->profile(['volume_max' => 20, 'brightness' => 70], 'SALLE');
        $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen->id}", ['profile_id' => $profile], ['If-Match' => (string) $screen->version])->assertOk()
            ->assertJsonPath('data.configuration.sync', 'unknown');
        $expected = $this->actingAs($admin)->getJson("/api/v1/screens/{$screen->id}")->json('data.configuration.expected_hash');
        $this->app['auth']->forgetGuards();

        $beat = fn (string $hash) => $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String(), 'config' => ['hash' => $hash, 'applied' => ['volume_max'], 'unsupported' => ['brightness']]], ['Authorization' => "Bearer $token"])->assertOk();
        $beat(str_repeat('0', 64));
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin)->getJson("/api/v1/screens/{$screen->id}")->assertJsonPath('data.configuration.sync', 'pending');
        $this->app['auth']->forgetGuards();

        $beat($expected);
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin)->getJson("/api/v1/screens/{$screen->id}")->assertJsonPath('data.configuration.sync', 'applied')->assertJsonPath('data.configuration.unsupported.0', 'brightness');
    }

    public function test_a_profile_in_use_cannot_be_deactivated(): void
    {
        $screen = $this->screen('ECR-USAGE');
        $admin = $this->userWith('admin_tech', 'DSI');
        $profile = $this->profile(['fullscreen' => true], 'KIOSQUE');
        $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen->id}", ['profile_id' => $profile], ['If-Match' => (string) $screen->version])->assertOk();

        $this->actingAs($admin)->patchJson("/api/v1/screen-profiles/$profile", ['is_active' => false])->assertStatus(409)->assertJsonPath('code', 'PROFILE_IN_USE');
        $this->actingAs($admin)->getJson('/api/v1/screen-profiles')->assertOk()->assertJsonPath('data.0.screens_count', 1);
    }
}
