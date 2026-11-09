<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Content;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ContentWorkflow;
use Tests\TestCase;

/**
 * HAB-02 : une validation n'est confiée qu'à un validateur éligible (langue, périmètre, distinct de l'auteur),
 * y compris pour le suppléant et la réaffectation ; une validation sans validateur n'est jamais perdue.
 */
class ValidatorEligibilityTest extends TestCase
{
    private User $author;

    private User $validatorFr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->author = $this->userWith('contributeur');
        $this->validatorFr = $this->userWith('validateur', 'COM', ['fr']);
    }

    private function submitted(string $lang): Content
    {
        $id = $this->actingAs($this->author)->postJson('/api/v1/contents', [
            'title' => 'Communiqué', 'category_id' => $this->category()->id, 'classification' => 'interne', 'expires_at' => now()->addDays(5)->toIso8601String(),
            'languages' => ['fr' => ['title' => 'Réunion de coordination', 'body' => 'Lundi à 10 h.'], 'en' => ['title' => 'Coordination meeting', 'body' => 'Monday 10 a.m.']],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->author)->postJson("/api/v1/contents/$id/submit", ['languages' => [$lang]])->assertOk();

        return Content::query()->findOrFail($id);
    }

    public function test_validation_without_eligible_validator_reaches_functional_admins_then_is_assigned_once_one_exists(): void
    {
        $admin = $this->userWith('admin_fonc');
        $content = $this->submitted('en');

        $approval = Approval::query()->where('object_id', $content->id)->where('lang', 'en')->firstOrFail();
        $this->assertNull($approval->assignee_id);
        $this->assertTrue(UserNotification::query()->where('user_id', $admin->id)->where('event', 'approval.unassigned')->exists());
        $tasks = collect($this->actingAs($admin)->getJson('/api/v1/tasks')->assertOk()->json('data'));
        $this->assertSame('content_unassigned', $tasks->firstWhere('id', $approval->id)['type'] ?? null);

        $validatorEn = $this->userWith('validateur', 'COM', ['en']);
        $this->assertSame(1, app(ContentWorkflow::class)->assignPending());
        $this->assertSame($validatorEn->id, $approval->refresh()->assignee_id);
        $this->assertNull(collect($this->actingAs($admin)->getJson('/api/v1/tasks')->json('data'))->firstWhere('id', $approval->id));
    }

    public function test_backup_is_kept_only_when_eligible(): void
    {
        $validatorEn = $this->userWith('validateur', 'COM', ['en']);
        $this->validatorFr->forceFill(['backup_user_id' => $validatorEn->id])->save();
        $content = $this->submitted('fr');
        $this->assertNull(Approval::query()->where('object_id', $content->id)->value('backup_id'), 'suppléant non habilité pour le français');

        Approval::query()->where('object_id', $content->id)->update(['status' => 'decided']);
        $otherFr = $this->userWith('validateur', 'COM', ['fr']);
        $this->validatorFr->forceFill(['backup_user_id' => $otherFr->id])->save();
        $otherFr->forceFill(['name' => 'Zoé Suppléante'])->save();
        $this->validatorFr->forceFill(['name' => 'Aaron Titulaire'])->save();
        $second = $this->submitted('fr');
        $approval = Approval::query()->where('object_id', $second->id)->firstOrFail();
        $this->assertSame($this->validatorFr->id, $approval->assignee_id);
        $this->assertSame($otherFr->id, $approval->backup_id);
    }

    public function test_reassignment_offers_and_accepts_only_eligible_validators(): void
    {
        $otherFr = $this->userWith('validateur', 'COM', ['fr']);
        $validatorEn = $this->userWith('validateur', 'COM', ['en']);
        $outOfScope = $this->userWith('validateur', 'DSI', ['fr'], 'DSI');
        $content = $this->submitted('fr');

        $candidates = collect($this->actingAs($this->validatorFr)->getJson("/api/v1/contents/{$content->id}")->assertOk()->json('data.reassign_candidates.fr'))->pluck('id');
        $this->assertTrue($candidates->contains($otherFr->id));
        $this->assertFalse($candidates->contains($validatorEn->id), 'non habilité pour le français');
        $this->assertFalse($candidates->contains($this->validatorFr->id), 'soi-même');
        $this->assertFalse($candidates->contains($this->author->id), 'auteur');

        $payload = ['lang' => 'fr', 'decision' => 'reassign', 'expected_revision' => 1];
        $this->actingAs($this->validatorFr)->postJson("/api/v1/contents/{$content->id}/approve", $payload + ['reassign_to' => $validatorEn->id])->assertUnprocessable()->assertJsonPath('code', 'INVALID_ASSIGNEE');
        $this->assertFalse($candidates->contains($outOfScope->id), 'contenu hors de son périmètre');
        $this->actingAs($this->validatorFr)->postJson("/api/v1/contents/{$content->id}/approve", $payload + ['reassign_to' => $outOfScope->id])->assertUnprocessable();
        $this->actingAs($this->validatorFr)->postJson("/api/v1/contents/{$content->id}/approve", $payload + ['reassign_to' => $otherFr->id])->assertOk();
        $this->assertSame($otherFr->id, Approval::query()->where('object_id', $content->id)->value('assignee_id'));
    }

    public function test_co_author_who_edited_or_submitted_cannot_validate_nor_be_assigned(): void
    {
        $content = $this->submitted('fr');
        $coAuthor = $this->userWith('contributeur');
        $this->grantRole($coAuthor, 'validateur', ['fr']);
        $this->actingAs($this->validatorFr)->postJson("/api/v1/contents/{$content->id}/approve", ['lang' => 'fr', 'decision' => 'return', 'reason' => 'Préciser la date', 'expected_revision' => 1])->assertOk();

        $line = $content->languages()->where('lang', 'fr')->firstOrFail();
        $this->actingAs($coAuthor)->patchJson("/api/v1/contents/{$content->id}", ['expected_version' => $content->refresh()->version, 'languages' => ['fr' => ['title' => 'Réunion de coordination', 'body' => 'Lundi 10 h, salle Kivu.']]])->assertOk();
        $this->actingAs($coAuthor)->postJson("/api/v1/contents/{$content->id}/submit", ['languages' => ['fr']])->assertOk();

        $revision = $line->refresh()->revision;
        $this->actingAs($coAuthor)->postJson("/api/v1/contents/{$content->id}/approve", ['lang' => 'fr', 'decision' => 'approve', 'expected_revision' => $revision])
            ->assertForbidden()->assertJsonPath('message', 'Un co-rédacteur ne peut pas valider un contenu qu’il a modifié ou soumis.');
        $this->assertNotSame($coAuthor->id, Approval::query()->where('object_id', $content->id)->where('status', 'open')->value('assignee_id'));
    }
}
