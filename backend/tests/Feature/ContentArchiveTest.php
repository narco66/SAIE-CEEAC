<?php

namespace Tests\Feature;

use App\Models\Approval;
use Tests\TestCase;

/**
 * Archivage d'un contenu : réservé à l'auteur ou au titulaire de contents.archive ; les validations ouvertes sont annulées.
 */
class ContentArchiveTest extends TestCase
{
    public function test_author_archives_content_and_open_validations_are_cancelled(): void
    {
        $author = $this->userWith('contributeur');
        $this->userWith('validateur', 'COM', ['fr']);
        $id = $this->actingAs($author)->postJson('/api/v1/contents', [
            'title' => 'Communiqué', 'category_id' => $this->category()->id, 'classification' => 'interne', 'expires_at' => now()->addDays(5)->toIso8601String(),
            'languages' => ['fr' => ['title' => 'Réunion de coordination', 'body' => 'Lundi à 10 h.']],
        ])->assertCreated()->json('data.id');
        $this->actingAs($author)->postJson("/api/v1/contents/$id/submit", ['languages' => ['fr']])->assertOk();
        $this->assertTrue($this->actingAs($author)->getJson("/api/v1/contents/$id")->json('data.permissions.archive'));

        $otherContributor = $this->userWith('contributeur');
        $this->actingAs($otherContributor)->postJson("/api/v1/contents/$id/archive", ['reason' => 'Essai'])->assertForbidden();

        $this->actingAs($author)->postJson("/api/v1/contents/$id/archive", ['reason' => 'Annonce annulée'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertSame('cancelled', Approval::query()->where('object_id', $id)->value('status'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.archived', 'object_id' => $id, 'reason' => 'Annonce annulée']);
    }
}
