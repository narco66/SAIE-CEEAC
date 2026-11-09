<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\ContentCategory;
use App\Models\Document;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pré-audit ASVS 5.0 niveau 2 (V8 autorisations, V2 logique métier) : transfert hors périmètre, document publié modifié
 * sans nouvelle publication, périodes et volumes d'export bornés, références à des objets invisibles, retrait de pouvoir.
 */
class AsvsAuthorizationTest extends TestCase
{
    private function document(User $author, string $status = 'draft'): Document
    {
        $asset = Asset::query()->create(['reference' => 'MED-'.uniqid(), 'title' => 'Note', 'kind' => 'pdf', 'owner_structure_id' => $author->structure_id, 'classification' => 'interne', 'status' => 'ready']);
        $file = $asset->versions()->create(['version' => 1, 'storage_key' => 'assets/'.uniqid().'.pdf', 'original_name' => 'n.pdf', 'extension' => 'pdf', 'size' => 10, 'mime' => 'application/pdf', 'sha256' => str_repeat('e', 64), 'scan_status' => 'ready']);
        $document = Document::query()->create(['reference' => 'DOC-'.uniqid(), 'title' => 'Note de service', 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'interne', 'status' => $status]);
        $document->forceFill(['current_version_id' => $document->versions()->create(['version' => 1, 'asset_version_id' => $file->id])->id])->save();

        return $document;
    }

    public function test_a_scoped_manager_cannot_move_a_document_out_of_their_structures(): void
    {
        $manager = $this->userWith('contributeur', 'COM', null, 'COM');
        $document = $this->document($manager);

        $this->actingAs($manager)->patchJson("/api/v1/documents/{$document->id}", ['owner_structure_id' => $this->structure('DSI')->id], ['If-Match' => (string) $document->version])->assertForbidden();
        $this->assertSame($this->structure('COM')->id, $document->fresh()->owner_structure_id);
    }

    public function test_reclassifying_a_published_document_under_four_eyes_requires_a_new_publication(): void
    {
        Setting::put('documents.four_eyes', true);
        $author = $this->userWith('contributeur', 'COM', null, 'COM');
        $document = $this->document($author, 'published');

        $this->actingAs($author)->patchJson("/api/v1/documents/{$document->id}", ['classification' => 'public'], ['If-Match' => (string) $document->version])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('workflow_events', ['object_id' => $document->id, 'action' => 'republication_required']);
        $this->actingAs($author)->postJson("/api/v1/documents/{$document->id}/publish")->assertForbidden()->assertJsonPath('code', 'FOUR_EYES_REQUIRED');
    }

    public function test_exports_and_reports_are_bounded(): void
    {
        Queue::fake(); // exports maintenus « en attente », comme lorsque la file est occupée
        $manager = $this->userWith('gestionnaire_salle');
        $planning = fn (array $params) => ['type' => 'room_planning', 'format' => 'pdf', 'params' => $params];

        $this->actingAs($manager)->postJson('/api/v1/exports', $planning(['from' => '2020-01-01', 'to' => '2026-01-01']))->assertUnprocessable()->assertJsonPath('code', 'PERIOD_TOO_LONG');
        $this->actingAs($manager)->postJson('/api/v1/exports', $planning(['from' => 'pas-une-date']))->assertUnprocessable()->assertJsonValidationErrors('params.from');
        foreach (range(1, 5) as $i) {
            $this->actingAs($manager)->postJson('/api/v1/exports', $planning([]))->assertStatus(202);
        }
        $this->actingAs($manager)->postJson('/api/v1/exports', $planning([]))->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_PENDING_EXPORTS');

        $this->actingAs($this->userWith('auditeur'))->getJson('/api/v1/reports/summary?from=2000-01-01')->assertUnprocessable()->assertJsonPath('code', 'PERIOD_TOO_LONG');
    }

    public function test_a_content_cannot_reference_a_document_its_author_cannot_see(): void
    {
        $owner = $this->userWith('contributeur', 'DSI', null, 'DSI');
        $hidden = $this->document($owner);
        $hidden->forceFill(['classification' => 'restreint'])->save();
        $author = $this->userWith('contributeur', 'COM', null, 'COM');
        $category = ContentCategory::query()->firstOrFail();

        $this->actingAs($author)->postJson('/api/v1/contents', [
            'title' => 'Avis', 'category_id' => $category->id, 'classification' => 'interne', 'qr' => ['enabled' => true, 'document_id' => $hidden->id],
            'languages' => ['fr' => ['title' => 'Avis']],
        ])->assertForbidden();
    }

    public function test_an_administrator_cannot_revoke_their_own_extended_power(): void
    {
        $admin = $this->userWith('admin_fonc');
        $power = $this->grantPower($admin, 'modeles');

        $this->actingAs($admin)->postJson("/api/v1/role-extensions/{$power->id}/revoke", ['reason' => 'Fin de mission'])->assertStatus(403);
        $this->assertNull($power->fresh()->revoked_at);
    }
}
