<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Access;
use App\Services\ManifestBuilder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lot 23 : affectation directe d'une liste de lecture (§12-a), périmètre par bâtiment et par écran (§14-8), droits des
 * rapports du parc paramétrables (§13.3-k), durées de conservation réglables (§13.3-j), rôle Administrateur SAIE (§14-1).
 */
class PlaylistAssignmentScopesAndRetentionTest extends TestCase
{
    public function test_a_playlist_is_assigned_directly_as_a_permanent_programme(): void
    {
        $diffuser = $this->userWith('diffuseur');
        $screen = $this->screen('ECR-PERM');
        [$device] = $this->pairedDevice($screen);
        $content = $this->approvedContent();
        $playlist = $this->actingAs($diffuser)->postJson('/api/v1/playlists', ['name' => 'Accueil permanent', 'items' => [['content_id' => $content->id, 'duration_s' => 20]]])->assertCreated()->json('data.id');

        $this->actingAs($diffuser)->postJson("/api/v1/playlists/$playlist/assign", ['targets' => [['type' => 'zone', 'id' => $this->place()['zone_public']->id]]])->assertUnprocessable()->assertJsonValidationErrors('targets.0.type');
        $this->actingAs($diffuser)->postJson("/api/v1/playlists/$playlist/assign", ['targets' => [['type' => 'screen', 'id' => $screen->id]], 'priority' => 90])->assertForbidden()->assertJsonPath('code', 'PRIORITY_RESTRICTED');
        $created = $this->actingAs($diffuser)->postJson("/api/v1/playlists/$playlist/assign", ['targets' => [['type' => 'screen', 'id' => $screen->id]]])->assertCreated()
            ->assertJsonPath('data.priority', 10)->json('data');

        $campaign = Campaign::query()->findOrFail($created['id']);
        $this->assertSame($playlist, $campaign->playlist_id);
        $this->assertContains($campaign->status, ['scheduled', 'active'], 'publiée par les contrôles habituels');
        $schedule = app(ManifestBuilder::class)->compile($device->fresh())['schedule'];
        $this->assertSame($campaign->id, $schedule[0]['campaign_id']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'playlist.assigned', 'object_id' => $playlist]);
    }

    public function test_assignments_can_be_limited_to_a_building_or_a_single_screen(): void
    {
        $one = $this->screen('ECR-SEUL');
        $other = $this->screen('ECR-AUTRE');
        $technician = User::query()->create(['name' => 'Technicien d’un écran', 'email' => uniqid('t').'@test.local', 'password' => 'Secret-Passw0rd!', 'structure_id' => $this->structure('DSI')->id]);
        RoleAssignment::query()->create(['user_id' => $technician->id, 'role_id' => Role::query()->where('code', 'technicien')->value('id'), 'screen_id' => $one->id, 'starts_at' => now()->subDay()]);
        $builder = User::query()->create(['name' => 'Technicien du siège', 'email' => uniqid('b').'@test.local', 'password' => 'Secret-Passw0rd!', 'structure_id' => $this->structure('DSI')->id]);
        RoleAssignment::query()->create(['user_id' => $builder->id, 'role_id' => Role::query()->where('code', 'technicien')->value('id'), 'building_id' => $this->place()['building']->id, 'starts_at' => now()->subDay()]);
        app(Access::class)->flush();

        $this->assertSame([$one->id], app(Access::class)->screenScope($technician, 'screens.view'));
        $this->assertEqualsCanonicalizing([$one->id, $other->id], app(Access::class)->screenScope($builder, 'screens.view'));
        $this->actingAs($technician)->getJson("/api/v1/screens/{$other->id}")->assertForbidden();

        $admin = $this->userWith('admin_fonc', 'DSI');
        $target = $this->userWith('agent');
        $this->actingAs($admin)->postJson('/api/v1/role-assignments', ['user_id' => $target->id, 'role_code' => 'technicien', 'screen_id' => $one->id, 'reason' => 'Maintenance du seul écran du hall'])->assertCreated();
        $this->assertDatabaseHas('role_assignments', ['user_id' => $target->id, 'screen_id' => $one->id]);
    }

    public function test_fleet_report_rights_and_retention_settings_are_configurable(): void
    {
        $auditor = $this->userWith('auditeur', 'AUD');
        $request = fn () => $this->actingAs($auditor)->postJson('/api/v1/exports', ['type' => 'offline_screens', 'format' => 'csv', 'params' => []]);
        $request()->assertStatus(202);

        $role = Role::query()->where('code', 'auditeur')->firstOrFail();
        $keep = $role->permissions()->pluck('code')->reject(fn ($c) => $c === 'reports.fleet')->values()->all();
        $this->actingAs($this->userWith('admin_fonc', 'DSI'))->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => $keep, 'reason' => 'Rapports du parc retirés aux auditeurs'])->assertOk();
        app(Access::class)->flush();
        $request()->assertForbidden();
        $this->actingAs($auditor)->postJson('/api/v1/exports', ['type' => 'audit_extract', 'format' => 'csv', 'params' => []])->assertStatus(202);

        $tech = $this->userWith('admin_tech', 'DSI');
        $this->actingAs($this->userWith('admin_fonc', 'DSI'))->patchJson('/api/v1/settings', ['key' => 'retention.export_hours', 'value' => 6, 'reason' => 'Exports disponibles 6 heures'])->assertOk();
        $id = $this->actingAs($tech)->postJson('/api/v1/exports', ['type' => 'offline_screens', 'format' => 'csv', 'params' => []])->assertStatus(202)->json('data.id');
        $expires = DB::table('exports')->where('id', $id)->value('expires_at');
        $this->assertEqualsWithDelta(now()->addHours(6)->getTimestamp(), strtotime((string) $expires), 120);
        $this->actingAs($this->userWith('admin_fonc', 'DSI'))->patchJson('/api/v1/settings', ['key' => 'retention.audit_months', 'value' => 6, 'reason' => 'Trop court'])->assertUnprocessable();
    }

    public function test_the_single_saie_administrator_role_exists_unassigned(): void
    {
        $role = Role::query()->where('code', 'admin_saie')->firstOrFail();
        $codes = $role->permissions()->pluck('code')->all();
        foreach (['roles.manage', 'settings.technical', 'screens.manage', 'devices.command', 'reports.fleet'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->assertSame(0, RoleAssignment::query()->where('role_id', $role->id)->count(), 'attribution laissée à la décision de la DSI');
    }

    private function approvedContent(): Content
    {
        $author = $this->userWith('contributeur');
        $content = Content::query()->create(['reference' => 'CNT-'.uniqid(), 'title' => 'Avis', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'public', 'expires_at' => now()->addMonths(18), 'status' => 'approved']);
        ContentVersion::query()->create(['content_id' => $content->id, 'lang' => 'fr', 'revision' => 1, 'payload' => ['title' => 'Avis', 'media' => []], 'classification' => 'public', 'author_id' => $author->id, 'approved_by' => $this->userWith('validateur', 'COM', ['fr'])->id, 'approved_at' => now(), 'payload_hash' => str_repeat('b', 64)]);

        return $content;
    }
}
