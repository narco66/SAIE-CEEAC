<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Incident;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Screen;
use App\Models\ScreenGroup;
use App\Models\User;
use App\Services\Access;
use App\Services\ManifestBuilder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Module « Gestion des écrans », lot 1 : périmètre des habilitations (§14, §18), règles RG-005, RG-012, RG-013,
 * état opérationnel (§10.1) et gravités d'incident (§10.3).
 */
class ScreenModuleIntegrityTest extends TestCase
{
    private function zoneUser(string $role, string $zoneId): User
    {
        $user = User::query()->create(['name' => 'Agent de zone', 'email' => uniqid('zone').'@test.local', 'password' => 'Secret-Passw0rd!', 'structure_id' => $this->structure('DSI')->id]);
        RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', $role)->value('id'), 'zone_id' => $zoneId, 'starts_at' => now()->subDay()]);
        app(Access::class)->flush();

        return $user;
    }

    public function test_a_zone_manager_cannot_reactivate_or_move_a_screen_outside_the_zone(): void
    {
        $outside = $this->screen('ECR-PALIER', false, ['status' => 'inactive']);
        $inside = $this->screen('ECR-HALL');
        $manager = $this->zoneUser('admin_tech', $this->place()['zone_public']->id);

        $this->actingAs($manager)->patchJson("/api/v1/screens/{$outside->id}", ['status' => 'active'], ['If-Match' => (string) $outside->version])->assertForbidden();
        $this->assertSame('inactive', $outside->fresh()->status);

        $this->actingAs($manager)->patchJson("/api/v1/screens/{$inside->id}", ['location_id' => $this->place()['loc_internal']->id], ['If-Match' => (string) $inside->version])->assertForbidden();
        $this->assertSame($this->place()['loc_public']->id, $inside->fresh()->location_id);
    }

    public function test_a_zone_manager_lists_only_the_screens_of_the_zone(): void
    {
        $this->screen('ECR-PALIER', false);
        $inside = $this->screen('ECR-HALL');
        $manager = $this->zoneUser('admin_tech', $this->place()['zone_public']->id);

        $this->actingAs($manager)->getJson('/api/v1/screens')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inside->id);
        $outside = Screen::query()->where('code', 'ECR-PALIER')->firstOrFail();
        $this->actingAs($manager)->getJson("/api/v1/screens/{$outside->id}")->assertForbidden();
    }

    public function test_an_empty_group_of_another_structure_cannot_be_deleted_by_a_zone_manager(): void
    {
        $foreign = ScreenGroup::query()->create(['code' => 'G-AUTRE', 'name' => 'Autre direction', 'structure_id' => $this->structure('COM')->id]);
        $manager = $this->zoneUser('admin_tech', $this->place()['zone_public']->id);

        $this->actingAs($manager)->deleteJson("/api/v1/screen-groups/{$foreign->id}")->assertForbidden();
        $own = $this->actingAs($manager)->postJson('/api/v1/screen-groups', ['code' => 'G-MIEN', 'name' => 'Le mien'])->assertCreated()->json('data.id');
        $this->actingAs($manager)->deleteJson("/api/v1/screen-groups/$own")->assertNoContent();
        $this->assertDatabaseHas('screen_groups', ['id' => $foreign->id]);
    }

    public function test_a_campaign_target_must_exist(): void
    {
        $diffuser = $this->userWith('diffuseur');
        $author = $this->userWith('contributeur');
        $content = Content::query()->create(['reference' => 'CNT-'.uniqid(), 'title' => 'Avis', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'public', 'expires_at' => now()->addDays(10), 'status' => 'approved']);

        $this->actingAs($diffuser)->postJson('/api/v1/campaigns', [
            'name' => 'Cible fantôme', 'priority' => 50, 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addDays(2)->toIso8601String(),
            'items' => [['content_id' => $content->id]], 'languages' => ['fr'], 'targets' => [['type' => 'group', 'id' => '01a1b979-0000-7000-8000-000000000000']],
        ])->assertStatus(422)->assertJsonPath('code', 'TARGET_NOT_FOUND');
    }

    public function test_incidents_are_bounded_to_the_zone_and_resolution_requires_a_result(): void
    {
        $outside = $this->screen('ECR-PALIER', false);
        $inside = $this->screen('ECR-HALL');
        $technician = $this->zoneUser('technicien', $this->place()['zone_public']->id);
        $global = $this->userWith('technicien', 'DSI');
        $foreign = $this->actingAs($global)->postJson('/api/v1/incidents', ['title' => 'Dalle noire au palier', 'severity' => 'major', 'screen_id' => $outside->id])->assertCreated()->json('data.id');

        $this->actingAs($technician)->getJson('/api/v1/incidents')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($technician)->patchJson("/api/v1/incidents/$foreign", ['action' => 'acknowledge'])->assertForbidden();
        $this->actingAs($technician)->postJson('/api/v1/incidents', ['title' => 'Hors zone', 'severity' => 'minor', 'screen_id' => $outside->id])->assertForbidden();

        $own = $this->actingAs($technician)->postJson('/api/v1/incidents', ['title' => 'Image figée au hall', 'severity' => 'medium', 'screen_id' => $inside->id])->assertCreated()->json('data.id');
        $this->actingAs($technician)->patchJson("/api/v1/incidents/$own", ['action' => 'resolve'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->actingAs($technician)->patchJson("/api/v1/incidents/$own", ['action' => 'resolve', 'note' => 'Lecteur relancé, image rétablie.'])->assertOk();
        $this->assertDatabaseHas('incidents', ['id' => $own, 'status' => 'resolved', 'severity' => 'medium', 'resolution' => 'Lecteur relancé, image rétablie.']);
    }

    public function test_a_manual_incident_marks_the_screen_in_anomaly_and_old_player_errors_do_not(): void
    {
        $screen = $this->screen('ECR-HALL');
        [$device] = $this->pairedDevice($screen);
        $device->forceFill(['last_errors' => [now()->subHour()->utc()->format('Y-m-d\TH:i:s.v\Z').' Lecture vidéo interrompue']])->save();
        $admin = $this->userWith('admin_tech', 'DSI');

        $this->actingAs($admin)->getJson("/api/v1/screens/{$screen->id}")->assertOk()->assertJsonPath('data.operational_state.code', 'active');
        $this->actingAs($admin)->postJson('/api/v1/incidents', ['title' => 'Dalle rayée', 'severity' => 'minor', 'screen_id' => $screen->id])->assertCreated();
        $this->actingAs($admin)->getJson("/api/v1/screens/{$screen->id}")->assertOk()->assertJsonPath('data.operational_state.code', 'anomaly');
        $this->actingAs($admin)->getJson('/api/v1/screens')->assertOk()->assertJsonPath('data.0.operational_state.code', 'anomaly');
    }

    public function test_an_inactive_screen_receives_no_programme(): void
    {
        $screen = $this->screen('ECR-HALL');
        $author = $this->userWith('contributeur');
        $content = Content::query()->create(['reference' => 'CNT-'.uniqid(), 'title' => 'Avis', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'public', 'expires_at' => now()->addDays(10), 'status' => 'approved']);
        $version = ContentVersion::query()->create(['content_id' => $content->id, 'lang' => 'fr', 'revision' => 1, 'payload' => ['title' => 'Avis', 'media' => []], 'classification' => 'public', 'author_id' => $author->id, 'approved_by' => $this->userWith('validateur')->id, 'approved_at' => now(), 'payload_hash' => str_repeat('c', 64)]);
        $campaign = Campaign::query()->create(['reference' => 'CMP-'.uniqid(), 'name' => 'Avis', 'status' => 'active', 'priority' => 50, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(2), 'created_by' => $author->id, 'published_at' => now()]);
        $campaign->items()->create(['content_id' => $content->id, 'position' => 1, 'version_map' => ['fr' => $version->id]]);
        DB::table('campaign_languages')->insert(['campaign_id' => $campaign->id, 'lang' => 'fr', 'rank' => 1]);
        $campaign->targets()->create(['target_type' => 'screen', 'target_id' => $screen->id]);
        $builder = app(ManifestBuilder::class);

        $this->assertNotEmpty($builder->compileForScreen($screen->fresh())['schedule']);
        $screen->forceFill(['status' => 'inactive'])->save();
        $this->assertSame([], $builder->compileForScreen($screen->fresh())['schedule']);
    }

    public function test_archiving_withdraws_the_screen_and_keeps_its_record(): void
    {
        $screen = $this->screen('ECR-HALL');
        [$device] = $this->pairedDevice($screen);
        $admin = $this->userWith('admin_tech', 'DSI');
        $incident = Incident::query()->create(['reference' => 'INC-T1', 'screen_id' => $screen->id, 'kind' => 'manual', 'category' => 'panel', 'severity' => 'minor', 'status' => 'open', 'title' => 'Dalle rayée', 'detected_at' => now()]);

        $this->actingAs($admin)->postJson("/api/v1/screens/{$screen->id}/archive", ['reason' => 'Dalle hors d’usage'], ['If-Match' => (string) $screen->version])->assertStatus(409)->assertJsonPath('code', 'OPEN_INCIDENTS');
        $incident->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $this->actingAs($admin)->postJson("/api/v1/screens/{$screen->id}/archive", ['reason' => 'Dalle hors d’usage'], ['If-Match' => (string) $screen->version])->assertOk()
            ->assertJsonPath('data.status', 'archived')->assertJsonPath('data.operational_state.code', 'archived')->assertJsonPath('data.archive_reason', 'Dalle hors d’usage');

        $this->assertSame('revoked', $device->fresh()->status);
        $this->actingAs($admin)->getJson('/api/v1/screens')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/v1/screens?status=archived')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen->id}", ['name' => 'Renommé'], ['If-Match' => (string) $screen->fresh()->version])->assertStatus(409)->assertJsonPath('code', 'SCREEN_ARCHIVED');
        $this->assertDatabaseHas('audit_logs', ['action' => 'screen.archived', 'object_id' => $screen->id]);
    }
}
