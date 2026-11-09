<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Content;
use App\Models\ContentCategory;
use App\Models\ContentVersion;
use Tests\TestCase;

/**
 * PUB-04 (décision D2 à valider) : un communiqué passe par deux niveaux de validation confiés à deux validateurs distincts ;
 * aucune version diffusable n'existe avant l'approbation du dernier niveau.
 */
class ApprovalCircuitTest extends TestCase
{
    public function test_communique_requires_two_distinct_validators_before_any_broadcastable_version(): void
    {
        $category = ContentCategory::query()->where('code', 'COMMUNIQUE')->firstOrFail();
        $this->assertSame(2, (int) $category->approval_levels);
        $author = $this->userWith('contributeur');
        $first = $this->userWith('validateur', 'COM', ['fr']);
        $second = $this->userWith('validateur', 'COM', ['fr']);
        $id = $this->actingAs($author)->postJson('/api/v1/contents', [
            'title' => 'Communiqué', 'category_id' => $category->id, 'classification' => 'interne', 'expires_at' => now()->addDays(5)->toIso8601String(),
            'languages' => ['fr' => ['title' => 'Communiqué final', 'body' => 'Texte.']],
        ])->assertCreated()->json('data.id');
        $this->actingAs($author)->postJson("/api/v1/contents/$id/submit", ['languages' => ['fr']])->assertOk();
        $firstApprover = Approval::query()->where('object_id', $id)->where('level', 1)->value('assignee_id');
        $approve = fn ($user) => $this->actingAs($user)->postJson("/api/v1/contents/$id/approve", ['lang' => 'fr', 'decision' => 'approve', 'expected_revision' => 1]);

        $level1 = $firstApprover === $second->id ? $second : $first;
        $level2 = $level1->is($first) ? $second : $first;
        $approve($level1)->assertOk();
        $this->assertSame('submitted', Content::query()->findOrFail($id)->languages()->where('lang', 'fr')->value('status'), 'niveau 1 : toujours en validation');
        $this->assertSame(0, ContentVersion::query()->where('content_id', $id)->count(), 'aucune version diffusable');
        $this->assertSame($level2->id, Approval::query()->where('object_id', $id)->where('level', 2)->where('status', 'open')->value('assignee_id'), 'niveau 2 confié à un autre validateur');

        $approve($level1)->assertForbidden();
        $approve($level2)->assertOk();
        $this->assertSame('approved', Content::query()->findOrFail($id)->languages()->where('lang', 'fr')->value('status'));
        $this->assertSame($level2->id, ContentVersion::query()->where('content_id', $id)->value('approved_by'));
        $this->assertDatabaseHas('workflow_events', ['object_id' => $id, 'action' => 'approved_level', 'actor_id' => $level1->id]);
    }
}
