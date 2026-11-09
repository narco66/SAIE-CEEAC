<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\ScreenGroup;
use App\Models\User;
use App\Services\Access;
use App\Services\ManifestBuilder;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Lot 26 : défauts relevés par le second contrôle de conformité (modèle d'import, faux incidents, agent local, périmètre
 * des opérations globales et des lectures, suppression d'un groupe, classement de l'historique).
 */
class ControlFindingsFixesTest extends TestCase
{
    private function limitedUser(string $role, string $zoneId): User
    {
        $user = User::query()->create(['name' => 'Habilitation limitée', 'email' => uniqid('l').'@test.local', 'password' => 'Secret-Passw0rd!', 'structure_id' => $this->structure('DSI')->id]);
        RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', $role)->value('id'), 'zone_id' => $zoneId, 'starts_at' => now()->subDay()]);
        app(Access::class)->flush();

        return $user;
    }

    public function test_the_import_template_example_is_aligned_and_importable(): void
    {
        $this->place();
        $admin = $this->userWith('admin_tech', 'DSI');
        $template = $this->actingAs($admin)->get('/api/v1/screens/import/template')->assertOk()->streamedContent();
        [$header, $example] = array_map(fn ($l) => str_getcsv(trim($l, "\u{FEFF}\r"), ';'), explode("\n", trim($template)));
        $this->assertCount(count($header), $example, 'autant de valeurs que de colonnes');
        $row = array_combine($header, $example);
        $this->assertSame(['normal', 'window', ''], [$row['criticality'], $row['operating_mode'], $row['room_code']]);

        // Le modèle, adapté au référentiel de test, est importable tel quel (simulation).
        $content = str_replace([';2E;COULOIR-2E;'], [';RDC;HALL;'], $template);
        $this->actingAs($admin)->postJson('/api/v1/screens/import', ['file' => UploadedFile::fake()->createWithContent('modele.csv', $content)])->assertOk()
            ->assertJsonPath('data.summary.created', 1)->assertJsonPath('data.rows.0.status', 'created');
    }

    public function test_no_false_stalled_or_prolonged_fallback_incidents(): void
    {
        [$fallback] = $this->pairedDevice($this->screen('ECR-REPLI-SEUL'));
        app(ManifestBuilder::class)->current($fallback);
        $fallback->forceFill(['current_playback' => ['mode' => 'fallback', 'at' => now()->subHours(2)->toIso8601String()]])->save();
        [$suspended] = $this->pairedDevice($this->screen('ECR-SUSPENDU'));
        $suspended->forceFill(['suspended_at' => now()->subHours(3), 'suspend_reason' => 'Contrôle', 'current_playback' => ['mode' => 'fallback', 'at' => now()->subHours(2)->toIso8601String()]])->save();
        app(ManifestBuilder::class)->current($suspended->fresh());

        $this->artisan('saie:tick')->assertSuccessful();
        foreach ([$fallback, $suspended] as $device) {
            $this->assertDatabaseMissing('incidents', ['device_id' => $device->id, 'kind' => 'player_stalled']);
            $this->assertDatabaseMissing('incidents', ['device_id' => $device->id, 'kind' => 'fallback_prolonged']);
        }
    }

    public function test_an_agent_beating_every_minute_keeps_the_stopped_player_state(): void
    {
        $screen = $this->screen('ECR-AGENT-70');
        [$device] = $this->pairedDevice($screen);
        $device->forceFill(['last_seen_at' => now()->subMinutes(20), 'agent_seen_at' => now()->subSeconds(70)])->save();
        $this->actingAs($this->userWith('admin_tech', 'DSI'))->getJson("/api/v1/screens/{$screen->id}")->assertJsonPath('data.operational_state.code', 'player_stopped');
    }

    public function test_fleet_wide_operations_require_an_unrestricted_assignment(): void
    {
        $limited = $this->limitedUser('responsable_parc', $this->place()['zone_public']->id);
        $this->actingAs($limited)->postJson('/api/v1/screen-profiles', ['code' => 'PRF-ZONE', 'name' => 'Zone', 'settings' => ['volume_max' => 40]])->assertForbidden();
        $technician = $this->limitedUser('technicien', $this->place()['zone_public']->id);
        $this->actingAs($technician)->postJson('/api/v1/player-releases', ['version' => '9.9.9', 'channel' => 'pilot', 'base_url' => '/player/releases/9.9.9/', 'sha256' => str_repeat('a', 64)])->assertForbidden();
        $this->actingAs($this->userWith('admin_tech', 'DSI'))->postJson('/api/v1/screen-profiles', ['code' => 'PRF-PARC', 'name' => 'Parc', 'settings' => ['volume_max' => 40]])->assertCreated();
    }

    public function test_dashboard_and_previews_are_bounded_to_the_scope(): void
    {
        $inside = $this->screen('ECR-DANS');
        $outside = $this->screen('ECR-HORS', false);
        $zone = $this->limitedUser('superviseur', $this->place()['zone_public']->id);
        $this->assertSame(1, $this->actingAs($zone)->getJson('/api/v1/dashboard')->assertOk()->json('data.fleet.total'));
        $this->assertSame(0, $this->actingAs($this->userWith('agent'))->getJson('/api/v1/dashboard')->assertOk()->json('data.fleet.total'), 'un agent ne voit pas l’état du parc');

        $diffuser = $this->limitedUser('diffuseur', $this->place()['zone_public']->id);
        $codes = collect($this->actingAs($diffuser)->postJson('/api/v1/campaigns/preview', ['targets' => [['type' => 'all']]])->assertOk()->json('data.screens'))->pluck('code')->all();
        $this->assertContains($inside->code, $codes);
        $this->assertNotContains($outside->code, $codes);
    }

    public function test_group_deletion_and_history_classification(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $group = ScreenGroup::query()->create(['code' => 'G-HAB', 'name' => 'Habilité', 'structure_id' => $this->structure('DSI')->id]);
        $holder = User::query()->create(['name' => 'Titulaire du groupe', 'email' => uniqid('g').'@test.local', 'password' => 'Secret-Passw0rd!', 'structure_id' => $this->structure('DSI')->id]);
        $assignment = RoleAssignment::query()->create(['user_id' => $holder->id, 'role_id' => Role::query()->where('code', 'technicien')->value('id'), 'group_id' => $group->id, 'starts_at' => now()->subDay()]);
        $this->actingAs($admin)->deleteJson("/api/v1/screen-groups/{$group->id}")->assertStatus(409)->assertJsonPath('code', 'GROUP_IN_USE');
        $assignment->forceFill(['revoked_at' => now()])->save();
        $this->actingAs($admin)->deleteJson("/api/v1/screen-groups/{$group->id}")->assertNoContent();
        $this->assertNull($assignment->fresh()->group_id, 'habilitation révoquée conservée, référence retirée');

        // Modification depuis l'interface (profil inchangé envoyé) : classée « Fiche », pas « Configuration ».
        $screen = $this->screen('ECR-HIST');
        $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen->id}", ['name' => 'Hall renommé', 'profile_id' => null], ['If-Match' => '1'])->assertOk();
        $record = collect($this->actingAs($admin)->getJson("/api/v1/screens/{$screen->id}/history?category=record")->json('data'));
        $this->assertNotEmpty($record);
        $this->assertSame([], $this->actingAs($admin)->getJson("/api/v1/screens/{$screen->id}/history?category=configuration")->json('data'));
    }
}
