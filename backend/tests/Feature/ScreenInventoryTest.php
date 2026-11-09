<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Document;
use App\Models\Floor;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Room;
use App\Models\Screen;
use App\Models\ScreenLocationHistory;
use App\Models\User;
use App\Services\Access;
use App\Services\ExportService;
use App\Support\XlsxReader;
use App\Support\XlsxWriter;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Inventaire et localisation (§5, §6, RG-011) : fiche complète, historique des emplacements, documents liés, code QR,
 * import en masse et inventaire par bâtiment ou niveau, bornés au périmètre.
 */
class ScreenInventoryTest extends TestCase
{
    private function payload(array $extra = []): array
    {
        return array_merge([
            'code' => 'ECR-INV-01', 'name' => 'Couloir', 'kind' => 'display', 'location_id' => $this->place()['loc_public']->id, 'orientation' => 'landscape', 'resolution' => '1920x1080',
            'acquired_on' => '2025-12-01', 'supplier' => 'Fournisseur SA', 'order_reference' => 'BC-2025-77', 'technology' => 'lcd', 'power_watts' => 120,
            'mac_address' => '00-1a-2b-3c-4d-5e', 'os_name' => 'Tizen', 'firmware_version' => 'T-KSU2', 'location_detail' => 'Face aux ascenseurs', 'plan_x' => 42.5, 'plan_y' => 18,
        ], $extra);
    }

    private function zoneManager(string $zoneId): User
    {
        $user = User::query()->create(['name' => 'Gestionnaire de zone', 'email' => uniqid('zone').'@test.local', 'password' => 'Secret-Passw0rd!']);
        RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', 'admin_tech')->value('id'), 'zone_id' => $zoneId, 'starts_at' => now()->subDay()]);
        app(Access::class)->flush();

        return $user;
    }

    public function test_the_full_record_is_stored_and_each_move_is_historised(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $screen = $this->actingAs($admin)->postJson('/api/v1/screens', $this->payload())->assertCreated()
            ->assertJsonPath('data.hardware.mac_address', '00:1A:2B:3C:4D:5E')->assertJsonPath('data.hardware.technology', 'lcd')
            ->assertJsonPath('data.location_detail', 'Face aux ascenseurs')->assertJsonPath('data.plan.x', 42.5)->json('data');

        $room = Room::query()->create(['code' => 'R-AUTRE', 'name' => 'Autre niveau', 'floor_id' => Floor::query()->create(['building_id' => $this->place()['building']->id, 'code' => '1E', 'label' => '1er étage', 'level' => 1])->id, 'capacity' => 10, 'approval_mode' => 'auto', 'open_from' => '08:00', 'open_to' => '18:00']);
        $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen['id']}", ['room_id' => $room->id], ['If-Match' => (string) $screen['version']])->assertStatus(422)->assertJsonPath('code', 'ROOM_FLOOR_MISMATCH');

        $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen['id']}", ['location_id' => $this->place()['loc_internal']->id, 'move_reason' => 'Réaménagement du hall'], ['If-Match' => (string) $screen['version']])->assertOk()
            ->assertJsonPath('data.location_history.0.reason', 'Réaménagement du hall')->assertJsonPath('data.location_history.0.location', 'Palier')->assertJsonPath('data.location_history.1.location', 'Hall');
        $this->assertSame(1, ScreenLocationHistory::query()->where('screen_id', $screen['id'])->whereNull('to_at')->count(), 'un seul emplacement actif');
        $this->assertDatabaseHas('audit_logs', ['action' => 'screen.moved', 'object_id' => $screen['id'], 'reason' => 'Réaménagement du hall']);
    }

    public function test_the_screen_qr_code_opens_the_record_for_authorised_accounts_only(): void
    {
        $screen = $this->screen('ECR-QR');
        $code = $this->actingAs($this->userWith('admin_tech', 'DSI'))->postJson("/api/v1/screens/{$screen->id}/qr")->assertOk()->json('data.code');
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/q/$code")->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->actingAs($this->userWith('agent'))->getJson("/api/v1/q/$code")->assertForbidden();
        $this->actingAs($this->userWith('technicien', 'DSI'))->getJson("/api/v1/q/$code")->assertOk()->assertJsonPath('data.type', 'screen')->assertJsonPath('data.redirect', "/affichage/ecrans/{$screen->id}");
    }

    public function test_documents_can_be_linked_to_a_screen(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $screen = $this->screen('ECR-DOC');
        $asset = Asset::query()->create(['reference' => 'MED-'.uniqid(), 'title' => 'Facture', 'kind' => 'pdf', 'owner_structure_id' => $admin->structure_id, 'classification' => 'interne', 'status' => 'ready']);
        $file = $asset->versions()->create(['version' => 1, 'storage_key' => 'assets/f.pdf', 'original_name' => 'f.pdf', 'extension' => 'pdf', 'size' => 10, 'mime' => 'application/pdf', 'sha256' => str_repeat('f', 64), 'scan_status' => 'ready']);
        $document = Document::query()->create(['reference' => 'DOC-'.uniqid(), 'title' => 'Facture de la dalle', 'owner_structure_id' => $admin->structure_id, 'author_id' => $admin->id, 'classification' => 'interne', 'status' => 'published', 'published_at' => now()]);
        $document->forceFill(['current_version_id' => $document->versions()->create(['version' => 1, 'asset_version_id' => $file->id])->id])->save();

        $this->actingAs($admin)->postJson("/api/v1/screens/{$screen->id}/documents", ['document_id' => $document->id])->assertOk()->assertJsonPath('data.documents.0.title', 'Facture de la dalle');
        $this->actingAs($admin)->deleteJson("/api/v1/screens/{$screen->id}/documents/{$document->id}")->assertOk()->assertJsonCount(0, 'data.documents');
    }

    public function test_csv_import_simulates_without_writing_refuses_invalid_files_and_commits_atomically(): void
    {
        $this->place();
        $admin = $this->userWith('admin_tech', 'DSI');
        $header = 'code;name;kind;building_code;floor_code;location_code;orientation;resolution;technology;warranty_until';
        $good = "ECR-IMP-01;Hall nord;display;SIEGE;RDC;HALL;landscape;1920x1080;led;2029-01-01\nECR-IMP-02;Palier;display;SIEGE;RDC;PALIER;portrait;1080x1920;;";
        $csv = fn (string $body) => UploadedFile::fake()->createWithContent('ecrans.csv', "\u{FEFF}$header\n$body\n");

        $this->actingAs($admin)->postJson('/api/v1/screens/import', ['file' => $csv($good)])->assertOk()->assertJsonPath('data.committed', false)->assertJsonPath('data.summary.created', 2);
        $this->assertSame(0, Screen::query()->where('code', 'like', 'ECR-IMP-%')->count(), 'la simulation n’écrit rien');

        $this->actingAs($admin)->postJson('/api/v1/screens/import', ['file' => $csv($good."\nECR-IMP-03;Inconnu;display;SIEGE;RDC;NULLEPART;landscape;1920x1080;;"), 'commit' => true])->assertOk()
            ->assertJsonPath('data.committed', false)->assertJsonPath('data.rows.2.status', 'invalid');
        $this->assertSame(0, Screen::query()->where('code', 'like', 'ECR-IMP-%')->count(), 'une ligne invalide : rien n’est écrit');

        $this->actingAs($admin)->postJson('/api/v1/screens/import', ['file' => $csv($good), 'commit' => true])->assertOk()->assertJsonPath('data.committed', true);
        $this->assertSame('led', Screen::query()->where('code', 'ECR-IMP-01')->value('technology'));
        $this->assertDatabaseHas('screen_location_history', ['screen_id' => Screen::query()->where('code', 'ECR-IMP-02')->value('id'), 'reason' => 'Import CSV']);
        $this->actingAs($admin)->postJson('/api/v1/screens/import', ['file' => $csv($good)])->assertOk()->assertJsonPath('data.summary.unchanged', 2);

        $limited = $this->zoneManager($this->place()['zone_public']->id);
        $this->actingAs($limited)->postJson('/api/v1/screens/import', ['file' => $csv('ECR-IMP-04;Palier bis;display;SIEGE;RDC;PALIER;landscape;1920x1080;;')])->assertOk()
            ->assertJsonPath('data.rows.0.status', 'invalid')->assertJsonPath('data.rows.0.errors.0', 'Emplacement hors du site ou de la zone de votre habilitation.');
    }

    public function test_an_excel_workbook_is_imported_like_a_csv_file(): void
    {
        $this->place();
        $admin = $this->userWith('admin_tech', 'DSI');
        $workbook = XlsxWriter::build([['title' => 'Écrans', 'headers' => ['code', 'name', 'kind', 'building_code', 'floor_code', 'location_code', 'orientation', 'resolution', 'warranty_until', 'supplier'],
            'rows' => [['ECR-XLS-01', 'Hall (Excel)', 'display', 'SIEGE', 'RDC', 'HALL', 'landscape', '1920x1080', '2030-06-30', 'Fournisseur Excel']]]]);
        $file = UploadedFile::fake()->createWithContent('ecrans.xlsx', $workbook);

        $this->actingAs($admin)->postJson('/api/v1/screens/import', ['file' => $file, 'commit' => true])->assertOk()->assertJsonPath('data.committed', true)->assertJsonPath('data.summary.created', 1);
        $screen = Screen::query()->where('code', 'ECR-XLS-01')->firstOrFail();
        $this->assertSame(['Fournisseur Excel', '2030-06-30'], [$screen->supplier, $screen->warranty_until->format('Y-m-d')]);
        $this->assertSame('2026-11-08', XlsxReader::date('46334'), 'date Excel en numéro de série');
    }

    public function test_the_inventory_report_is_bounded_to_the_scope_and_can_target_a_floor(): void
    {
        $this->screen('ECR-HALL');
        $this->screen('ECR-PALIER', false);
        $limited = $this->zoneManager($this->place()['zone_public']->id);

        $report = app(ExportService::class)->build('equipment_inventory', [], $limited);
        $codes = collect($report['sections'][1]['table']['rows'])->pluck(0)->all();
        $this->assertSame(['ECR-HALL'], $codes);
        $this->assertContains('Bâtiment', $report['sections'][1]['table']['headers']);
        $this->assertSame('Siège · Rez-de-chaussée', $report['sections'][0]['table']['rows'][0][0]);
    }
}
