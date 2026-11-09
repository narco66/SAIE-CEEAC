<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Location;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Access;
use App\Support\XlsxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Rapports (audit 2026-11-06, écarts RAP-01 et RAP-02) : export Excel sans dépendance et sans formule possible ; indicateurs
 * bornés au périmètre de l'habilitation et filtrables par bâtiment.
 */
class ReportingTest extends TestCase
{
    public function test_xlsx_workbook_is_valid_and_never_contains_formulas(): void
    {
        $content = XlsxWriter::build([
            ['title' => 'Journal: extrait/2026', 'headers' => ['Date', 'Action'], 'rows' => [['06/11/2026', '=HYPERLINK("http://x")'], ['07/11/2026', 'Connexion & sortie']]],
            ['title' => 'Journal: extrait/2026', 'headers' => ['Total'], 'rows' => [[3]]],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'archive OOXML valide');
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('name="Journal  extrait 2026"', $workbook, 'caractères interdits retirés');
        $this->assertStringContainsString('name="Journal  extrait 2026 2"', $workbook, 'noms de feuilles uniques');
        $this->assertStringNotContainsString('<f>', $sheet, 'aucune formule');
        $this->assertStringContainsString('t="inlineStr"', $sheet);
        $this->assertStringContainsString('=HYPERLINK', html_entity_decode($sheet), 'valeur conservée comme texte');
        $this->assertSame('AA', XlsxWriter::column(26));
    }

    public function test_export_can_be_generated_as_excel(): void
    {
        $auditor = $this->userWith('auditeur', 'AUD');
        $id = $this->actingAs($auditor)->postJson('/api/v1/exports', ['type' => 'audit_extract', 'format' => 'xlsx', 'params' => ['from' => now()->subDay()->toIso8601String(), 'to' => now()->addMinute()->toIso8601String()]])
            ->assertStatus(202)->json('data.id');
        $download = $this->actingAs($auditor)->get("/api/v1/exports/$id/download")->assertOk();
        $this->assertStringStartsWith('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', (string) $download->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $download->streamedContent());
    }

    public function test_report_figures_are_bounded_to_the_assignment_scope_and_building_filter(): void
    {
        $hall = $this->screen('ECR-RAP-HALL');
        $palier = $this->screen('ECR-RAP-PALIER', false);
        [$hallDevice] = $this->pairedDevice($hall);
        [$palierDevice] = $this->pairedDevice($palier);
        foreach ([$hallDevice, $palierDevice] as $device) {
            DB::table('device_receipts')->insert(['id' => (string) Str::uuid(), 'event_id' => (string) Str::uuid(), 'device_id' => $device->id, 'kind' => 'activated', 'occurred_at' => now()->subHour(), 'received_at' => now()]);
        }

        $global = $this->userWith('diffuseur');
        $this->assertSame(2, $this->actingAs($global)->getJson('/api/v1/reports/summary')->assertOk()->json('data.diffusion.activations'));

        $local = User::query()->create(['name' => 'Diffuseur accueil', 'email' => 'rap.accueil@test.local', 'password' => 'Secret-Passw0rd!', 'structure_id' => $this->structure('COM')->id]);
        RoleAssignment::query()->create(['user_id' => $local->id, 'role_id' => Role::query()->where('code', 'diffuseur')->value('id'), 'zone_id' => $this->place()['zone_public']->id, 'starts_at' => now()->subDay()]);
        app(Access::class)->flush();
        $scoped = $this->actingAs($local)->getJson('/api/v1/reports/summary')->assertOk();
        $this->assertSame(1, $scoped->json('data.diffusion.activations'), 'seuls les écrans de sa zone');
        $this->assertSame(1, $scoped->json('data.scope.screens'));

        $annexe = Building::query()->create(['site_id' => $this->place()['site']->id, 'code' => 'ANNEXE', 'name' => 'Annexe']);
        $floor = Floor::query()->create(['building_id' => $annexe->id, 'code' => 'A0', 'label' => 'Annexe RDC', 'level' => 0]);
        $annexeScreen = $this->screen('ECR-ANNEXE', true, ['location_id' => Location::query()->create(['floor_id' => $floor->id, 'zone_id' => $this->place()['zone_public']->id, 'code' => 'A-HALL', 'name' => 'Hall annexe'])->id]);
        [$annexeDevice] = $this->pairedDevice($annexeScreen);
        DB::table('device_receipts')->insert(['id' => (string) Str::uuid(), 'event_id' => (string) Str::uuid(), 'device_id' => $annexeDevice->id, 'kind' => 'activated', 'occurred_at' => now()->subHour(), 'received_at' => now()]);
        $this->assertSame(1, $this->actingAs($global)->getJson("/api/v1/reports/summary?building_id={$annexe->id}")->assertOk()->json('data.diffusion.activations'));
    }

    public function test_touch_orientation_kiosk_receives_public_directory_and_floor_plan(): void
    {
        $admin = $this->userWith('admin_fonc');
        $floor = $this->place()['floor'];
        $structure = $this->structure('DRH');
        $structure->forceFill(['floor_id' => $floor->id, 'show_in_orientation' => true])->save();
        $this->actingAs($admin)->patchJson("/api/v1/referentials/structures/{$structure->id}", ['office_location' => 'Aile B, bureau 112', 'reception_phone' => 'appeler Paul'])->assertUnprocessable();
        $this->actingAs($admin)->patchJson("/api/v1/referentials/structures/{$structure->id}", ['office_location' => 'Aile B, bureau 112', 'reception_phone' => '+241 01 00 00'])->assertOk();

        $owner = $this->userWith('contributeur');
        $plan = Asset::query()->create(['reference' => 'MED-PLAN', 'title' => 'Plan RDC', 'kind' => 'image', 'owner_structure_id' => $owner->structure_id, 'classification' => 'public', 'status' => 'ready']);
        $planVersion = $plan->versions()->create(['version' => 1, 'storage_key' => 'assets/plan.png', 'original_name' => 'plan.png', 'extension' => 'png', 'size' => 10, 'mime' => 'image/png', 'sha256' => str_repeat('9', 64), 'scan_status' => 'ready']);
        $this->actingAs($admin)->patchJson("/api/v1/referentials/floors/{$floor->id}", ['plan_asset_version_id' => $planVersion->id])->assertOk();

        $kiosk = $this->screen('BORNE-RDC', true, ['kind' => 'orientation', 'touch_enabled' => true]);
        [, $token] = $this->pairedDevice($kiosk);
        $this->app['auth']->forgetGuards();
        $payload = json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json('payload'), true);
        $this->assertTrue($payload['orientation']['touch']);
        $rdc = collect($payload['orientation']['floors'])->firstWhere('level', 0);
        $this->assertSame([['name' => $structure->name, 'office' => 'Aile B, bureau 112', 'phone' => '+241 01 00 00']], $rdc['directory']);
        $this->assertSame($planVersion->id, $rdc['plan']);
        $this->assertContains($planVersion->id, array_column($payload['assets'], 'id'), 'plan téléchargeable par la borne');

        $plan->forceFill(['classification' => 'restreint'])->save();
        $payload = json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json('payload'), true);
        $this->assertNull(collect($payload['orientation']['floors'])->firstWhere('level', 0)['plan'], 'plan non public : jamais transmis');
    }
}
