<?php

namespace Tests\Feature;

use App\Models\Export;
use App\Models\Institution;
use App\Services\Access;
use App\Services\ManifestBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lot 20 : classement de l'écran (catégorie, interfaces, mode, criticité), hiérarchie Institution et salle rattachée,
 * mise en service contrôlée (§15.1), fiche écran PDF (§5.5-j) et paramètres d'export transmis.
 */
class ScreenClassificationAndCommissioningTest extends TestCase
{
    public function test_screen_classification_and_filters(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $category = $this->actingAs($this->userWith('admin_fonc', 'DSI'))->postJson('/api/v1/referentials/screen_categories', ['code' => 'ACCUEIL', 'name' => 'Affichage d’accueil'])->assertCreated()->json('data.id');
        $screen = $this->screen('ECR-CLS');
        $other = $this->screen('ECR-AUTRE');
        $patch = fn (array $changes) => $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen->id}", $changes, ['If-Match' => (string) $screen->fresh()->version]);

        $patch(['interfaces' => ['hdmi', 'parallele']])->assertUnprocessable()->assertJsonValidationErrors('interfaces.1');
        $patch(['category_id' => $category, 'interfaces' => ['hdmi', 'rj45'], 'operating_mode' => 'window', 'criticality' => 'critical'])->assertOk()
            ->assertJsonPath('data.category.name', 'Affichage d’accueil')->assertJsonPath('data.interfaces', ['hdmi', 'rj45'])->assertJsonPath('data.criticality', 'critical')->assertJsonPath('data.operating_mode', 'window');
        $this->assertSame('normal', $other->fresh()->criticality, 'criticité normale par défaut');

        $codes = fn (array $q) => collect($this->actingAs($admin)->getJson('/api/v1/screens?'.http_build_query($q))->assertOk()->json('data'))->pluck('code')->sort()->values()->all();
        $this->assertSame(['ECR-CLS'], $codes(['category_id' => $category]));
        $this->assertSame(['ECR-CLS'], $codes(['criticality' => 'high,critical']));
        $this->assertSame(['ECR-AUTRE', 'ECR-CLS'], $codes(['commissioned' => '0']));
    }

    public function test_commissioning_requires_facts_and_a_visual_check(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $screen = $this->screen('ECR-MES');
        $this->actingAs($admin)->postJson("/api/v1/screens/{$screen->id}/commission", [])->assertUnprocessable()->assertJsonValidationErrors('visual_check');
        $refused = $this->actingAs($admin)->postJson("/api/v1/screens/{$screen->id}/commission", ['visual_check' => true])->assertUnprocessable()->assertJsonPath('code', 'COMMISSIONING_INCOMPLETE');
        $this->assertFalse($refused->json('details.checks.device_paired'));

        [$device] = $this->pairedDevice($screen);
        $manifest = app(ManifestBuilder::class)->current($device);
        $missing = $this->actingAs($admin)->postJson("/api/v1/screens/{$screen->id}/commission", ['visual_check' => true])->assertUnprocessable();
        $this->assertTrue($missing->json('details.checks.device_online'));
        $this->assertFalse($missing->json('details.checks.programme_applied'));
        $this->assertFalse($missing->json('details.checks.rendering_confirmed'));

        $device->forceFill(['active_manifest_version' => $manifest->version, 'config_hash' => app(ManifestBuilder::class)->configFor($screen->effectiveProfile())['hash']])->save();
        DB::table('device_receipts')->insert(['id' => (string) Str::uuid(), 'event_id' => (string) Str::uuid(), 'device_id' => $device->id, 'kind' => 'fallback', 'manifest_version' => $manifest->version,
            'occurred_at' => now()->subMinutes(5), 'received_at' => now()]);
        $this->actingAs($admin)->postJson("/api/v1/screens/{$screen->id}/commission", ['visual_check' => true, 'note' => 'Image nette, son coupé'])->assertOk()
            ->assertJsonPath('data.commissioning.by.name', $admin->name)->assertJsonPath('data.commissioning.report.note', 'Image nette, son coupé');
        $this->assertDatabaseHas('audit_logs', ['action' => 'screen.commissioned', 'object_id' => $screen->id]);
        $this->actingAs($this->userWith('diffuseur'))->postJson("/api/v1/screens/{$screen->id}/commission", ['visual_check' => true])->assertForbidden();
    }

    public function test_institution_level_and_room_attached_to_a_location(): void
    {
        $admin = $this->userWith('admin_fonc', 'DSI');
        $institution = $this->actingAs($admin)->postJson('/api/v1/referentials/institutions', ['code' => 'CEEAC', 'name' => 'Commission de la CEEAC'])->assertCreated()->json('data.id');
        $site = $this->place()['site'];
        $this->actingAs($admin)->patchJson("/api/v1/referentials/sites/{$site->id}", ['institution_id' => $institution])->assertOk();
        $this->assertSame([$site->id], Institution::query()->findOrFail($institution)->sites()->pluck('id')->all());
        $this->assertSame($institution, $site->fresh()->institution_id);

        $room = $this->room();
        $this->actingAs($admin)->patchJson("/api/v1/rooms/{$room->id}", ['location_id' => $this->place()['loc_public']->id], ['If-Match' => (string) $room->version])->assertOk()
            ->assertJsonPath('data.location_id', $this->place()['loc_public']->id);
    }

    public function test_screen_sheet_and_export_parameters_are_kept(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $screen = $this->screen('ECR-FICHE', true, ['inventory_code' => 'PAT-9', 'criticality' => 'high']);
        $id = $this->actingAs($admin)->postJson('/api/v1/exports', ['type' => 'screen_sheet', 'format' => 'pdf', 'params' => ['screen_id' => $screen->id]])->assertStatus(202)->json('data.id');
        $this->assertSame('ready', Export::query()->findOrFail($id)->status);
        $this->assertStringStartsWith('%PDF', $this->actingAs($admin)->get("/api/v1/exports/$id/download")->assertOk()->streamedContent());

        $outside = $this->screen('ECR-PALIER-F', false);
        $zoneUser = $this->userWith('technicien', 'DSI');
        DB::table('role_assignments')->where('user_id', $zoneUser->id)->update(['zone_id' => $this->place()['zone_public']->id]);
        app(Access::class)->flush();
        $this->actingAs($zoneUser)->postJson('/api/v1/exports', ['type' => 'screen_sheet', 'format' => 'pdf', 'params' => ['screen_id' => $outside->id]])->assertForbidden();

        $inventory = $this->actingAs($admin)->postJson('/api/v1/exports', ['type' => 'equipment_inventory', 'format' => 'csv', 'params' => ['building_id' => $this->place()['building']->id, 'include_archived' => true]])
            ->assertStatus(202)->json('data.id');
        $this->assertSame(['building_id' => $this->place()['building']->id, 'include_archived' => true], collect(Export::query()->findOrFail($inventory)->params)->only(['building_id', 'include_archived'])->all());
    }
}
