<?php

namespace Tests\Feature;

use App\Models\AccessLink;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\AuditLog;
use App\Models\ContentCategory;
use App\Models\Document;
use App\Models\Reservation;
use App\Models\UserNotification;
use App\Services\Access;
use App\Services\Audit;
use App\Services\ExportService;
use App\Services\Notifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Accès et sécurité : R01, R19, R20, R21, R22, R23, SEC-04, TAB-02, connexion (SEC-01).
 */
class SecurityAndAccessTest extends TestCase
{
    private function document(string $classification, string $owner = 'DSI', string $text = 'texte'): Document
    {
        $author = $this->userWith('contributeur', $owner);
        $asset = Asset::query()->create(['reference' => 'MED-'.uniqid(), 'title' => 'f', 'kind' => 'pdf', 'owner_structure_id' => $author->structure_id, 'classification' => $classification, 'status' => 'ready']);
        $version = $asset->versions()->create(['version' => 1, 'storage_key' => 'assets/f.pdf', 'original_name' => 'f.pdf', 'extension' => 'pdf', 'size' => 10, 'mime' => 'application/pdf', 'sha256' => str_repeat('c', 64), 'scan_status' => 'ready', 'text_content' => $text]);
        $document = Document::query()->create(['reference' => 'DOC-'.uniqid(), 'title' => "Document $classification", 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => $classification, 'status' => 'published', 'published_at' => now(), 'search_text' => $text]);
        $dv = $document->versions()->create(['version' => 1, 'asset_version_id' => $version->id]);
        $document->forceFill(['current_version_id' => $dv->id])->save();

        return $document;
    }

    public function test_agent_cannot_read_restricted_document_by_guessing_its_uuid(): void
    {
        $restricted = $this->document('restreint', 'DSI', 'bastion confidentiel');
        $agent = $this->userWith('agent', 'DRH');
        $this->actingAs($agent)->getJson("/api/v1/documents/{$restricted->id}")->assertNotFound()->assertJsonMissing(['title' => $restricted->title]);
        $this->actingAs($agent)->get("/api/v1/documents/{$restricted->id}/versions/1/download")->assertNotFound();
    }

    public function test_full_text_search_returns_no_restricted_result_nor_count(): void
    {
        $this->document('restreint', 'DSI', 'bastion confidentiel');
        $this->document('interne', 'COM', 'note ordinaire');
        $agent = $this->userWith('agent', 'DRH');
        $response = $this->actingAs($agent)->getJson('/api/v1/documents?q=bastion')->assertOk();
        $this->assertSame(0, $response->json('meta.total'));
        $this->assertSame(0, count($response->json('data')));
    }

    public function test_internal_qr_requires_authentication_and_reevaluates_rights(): void
    {
        $document = $this->document('interne');
        AccessLink::query()->create(['code' => 'qrinterne1', 'target_type' => 'document', 'target_id' => $document->id, 'classification' => 'interne']);
        $this->getJson('/api/v1/q/qrinterne1')->assertUnauthorized();
        $agent = $this->userWith('agent', 'DRH');
        $this->actingAs($agent)->getJson('/api/v1/q/qrinterne1')->assertOk()->assertJsonPath('data.redirect', "/documents/{$document->id}");
        $agent->roleAssignments()->update(['revoked_at' => now()]);
        app(Access::class)->flush();
        $this->actingAs($agent)->getJson('/api/v1/q/qrinterne1')->assertForbidden();
    }

    public function test_expired_delegation_and_deactivated_account_lose_access(): void
    {
        $user = $this->userWith('diffuseur', 'DSI');
        $user->roleAssignments()->update(['ends_at' => now()->subMinute(), 'starts_at' => now()->subDays(3)]);
        app(Access::class)->flush();
        $this->actingAs($user)->getJson('/api/v1/campaigns')->assertForbidden();

        $admin = $this->userWith('admin_tech', 'DSI');
        $victim = $this->userWith('agent');
        DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $victim->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($admin)->postJson("/api/v1/users/{$victim->id}/deactivate", ['reason' => 'Départ de l’agent'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['user_id' => $victim->id]);
        $this->actingAs($victim->refresh())->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_notification_target_is_rechecked_when_scope_was_reduced(): void
    {
        $manager = $this->userWith('gestionnaire_salle', 'MG');
        $reservation = Reservation::query()->create(['reference' => 'RES-N', 'organizer_id' => $this->userWith('agent')->id, 'subject' => 'x', 'participant_count' => 3]);
        app(Notifier::class)->notify($manager, 'reservation.pending', 'Réservation à valider', null, "/reservations/{$reservation->id}", 'reservation', $reservation->id);
        $notification = UserNotification::query()->where('user_id', $manager->id)->firstOrFail();
        $this->actingAs($manager)->getJson("/api/v1/notifications/{$notification->id}/open")->assertOk();
        $manager->roleAssignments()->update(['revoked_at' => now()]);
        $this->grantRole($manager, 'agent');
        $this->actingAs($manager)->getJson("/api/v1/notifications/{$notification->id}/open")->assertForbidden();
    }

    public function test_login_is_generic_locks_after_failures_and_is_audited(): void
    {
        $user = $this->userWith('agent');
        $this->postJson('/api/v1/auth/login', ['email' => 'inconnu@test.local', 'password' => 'x'])->assertUnprocessable()->assertJsonPath('code', 'INVALID_CREDENTIALS');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'mauvais'])->assertUnprocessable()->assertJsonPath('message', 'Identifiant ou mot de passe incorrect.');
        }
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret-Passw0rd!'])->assertStatus(429);
        $this->assertGreaterThanOrEqual(5, AuditLog::query()->where('action', 'auth.login_failed')->count());
    }

    public function test_audit_log_is_append_only_at_database_level(): void
    {
        app(Audit::class)->log('test.event', null, 'motif');
        $this->expectException(QueryException::class);
        AuditLog::query()->where('action', 'test.event')->delete();
    }

    public function test_csv_import_is_idempotent_and_simulation_writes_nothing(): void
    {
        $admin = $this->userWith('admin_fonc', 'SG');
        $csv = "code;name;default_duration_s;is_active\nTEST-A;Catégorie A;12;1\nTEST-B;Catégorie B;15;oui\n";
        $file = fn (string $content) => UploadedFile::fake()->createWithContent('categories.csv', $content);
        $this->actingAs($admin)->post('/api/v1/imports/simulate', ['type' => 'categories', 'file' => $file($csv)])->assertOk()->assertJsonPath('data.summary.created', 2);
        $this->assertDatabaseMissing('content_categories', ['code' => 'TEST-A']);
        $this->actingAs($admin)->post('/api/v1/imports/commit', ['type' => 'categories', 'file' => $file($csv)])->assertOk();
        $second = $this->actingAs($admin)->post('/api/v1/imports/commit', ['type' => 'categories', 'file' => $file(str_replace('Catégorie B', 'Catégorie B modifiée', $csv))])->assertOk();
        $this->assertSame(1, $second->json('data.summary.updated'));
        $this->assertSame(1, $second->json('data.summary.unchanged'));
        $this->assertSame(1, ContentCategory::query()->where('code', 'TEST-B')->count());
    }

    public function test_csv_export_neutralizes_formula_cells_and_pdf_is_generated(): void
    {
        $this->assertSame("'=SUM(A1)", ExportService::neutralize('=SUM(A1)'));
        $this->assertSame("'+33", ExportService::neutralize('+33'));
        $this->assertSame('Texte', ExportService::neutralize('Texte'));

        $auditor = $this->userWith('auditeur', 'AUD');
        $export = $this->actingAs($auditor)->postJson('/api/v1/exports', ['type' => 'audit_extract', 'format' => 'pdf', 'params' => ['from' => now()->subDay()->toIso8601String(), 'to' => now()->addMinute()->toIso8601String()]])->assertStatus(202);
        $this->actingAs($auditor)->getJson("/api/v1/exports/{$export->json('data.id')}")->assertOk()->assertJsonPath('data.status', 'ready');
        $download = $this->actingAs($auditor)->get("/api/v1/exports/{$export->json('data.id')}/download")->assertOk();
        $this->assertStringStartsWith('%PDF', $download->streamedContent());

        $agent = $this->userWith('agent');
        $this->actingAs($agent)->postJson('/api/v1/exports', ['type' => 'audit_extract', 'format' => 'pdf'])->assertForbidden();
        $this->actingAs($agent)->get("/api/v1/exports/{$export->json('data.id')}/download")->assertNotFound();
    }

    public function test_eicar_test_file_is_quarantined_then_rejected_and_cannot_be_published(): void
    {
        $user = $this->userWith('contributeur');
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n".'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $response = $this->actingAs($user)->post('/api/v1/assets', ['file' => UploadedFile::fake()->createWithContent('test.pdf', $pdf), 'classification' => 'interne'])->assertCreated();
        $version = AssetVersion::query()->findOrFail($response->json('version.id'));
        $this->assertSame('rejected', $version->refresh()->scan_status);
        $this->actingAs($user)->get("/api/v1/asset-versions/{$version->id}/file")->assertStatus(409);
    }
}
