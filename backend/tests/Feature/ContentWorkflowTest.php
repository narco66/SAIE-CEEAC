<?php

namespace Tests\Feature;

use App\Models\AccessLink;
use App\Models\Approval;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * Circuit éditorial multilingue : R02, R24 (partie langues), PUB-01, PUB-02, PUB-08, HAB-02.
 */
class ContentWorkflowTest extends TestCase
{
    private User $author;

    private User $validatorFr;

    private User $validatorEn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->author = $this->userWith('contributeur');
        $this->validatorFr = $this->userWith('validateur', 'COM', ['fr']);
        $this->validatorEn = $this->userWith('validateur', 'COM', ['en']);
    }

    private function createContent(): Content
    {
        $response = $this->actingAs($this->author)->postJson('/api/v1/contents', [
            'title' => 'Communiqué', 'category_id' => $this->category()->id, 'classification' => 'interne', 'expires_at' => now()->addDays(5)->toIso8601String(),
            'languages' => ['fr' => ['title' => 'Réunion de coordination', 'body' => 'Lundi à 10 h.'], 'en' => ['title' => 'Coordination meeting', 'body' => 'Monday 10 a.m.']],
        ])->assertCreated();

        return Content::query()->findOrFail($response->json('data.id'));
    }

    public function test_duplicated_content_gets_its_own_qr_code_pointing_to_the_copy(): void
    {
        $original = $this->actingAs($this->author)->postJson('/api/v1/contents', [
            'title' => 'Annonce avec QR', 'category_id' => $this->category()->id, 'classification' => 'interne', 'expires_at' => now()->addDays(5)->toIso8601String(),
            'qr' => ['enabled' => true, 'label' => 'En savoir plus'],
            'languages' => ['fr' => ['title' => 'Annonce', 'body' => 'Texte.']],
        ])->assertCreated()->json('data');
        $originalCode = Content::query()->findOrFail($original['id'])->qr['code'];

        $copyId = $this->actingAs($this->author)->postJson("/api/v1/contents/{$original['id']}/duplicate")->assertCreated()->json('data.id');
        $copyCode = Content::query()->findOrFail($copyId)->qr['code'] ?? null;

        $this->assertNotNull($copyCode);
        $this->assertNotSame($originalCode, $copyCode);
        $this->assertSame($copyId, AccessLink::query()->where('code', $copyCode)->value('target_id'));
    }

    public function test_author_is_warned_once_when_content_expires_within_two_days(): void
    {
        $content = $this->createContent();
        $content->forceFill(['status' => 'approved', 'expires_at' => now()->addHours(30)])->save();

        $this->artisan('saie:tick')->assertSuccessful();
        $this->artisan('saie:tick')->assertSuccessful();

        $this->assertSame(1, UserNotification::query()->where('event', 'content.expiring')->where('object_id', $content->id)->where('user_id', $this->author->id)->count());
    }

    public function test_author_cannot_approve_own_content_and_distinct_validator_can(): void
    {
        $content = $this->createContent();
        $this->actingAs($this->author)->postJson("/api/v1/contents/{$content->id}/submit", ['languages' => ['fr', 'en']])->assertOk();

        $this->grantRole($this->author, 'validateur', ['fr']);
        $this->actingAs($this->author)->postJson("/api/v1/contents/{$content->id}/approve", ['lang' => 'fr', 'decision' => 'approve', 'expected_revision' => 1])->assertForbidden()
            ->assertJsonPath('message', 'Un auteur ne peut pas valider sa propre publication.');
        $this->assertDatabaseCount('content_versions', 0);

        $this->actingAs($this->validatorFr)->postJson("/api/v1/contents/{$content->id}/approve", ['lang' => 'fr', 'decision' => 'approve', 'expected_revision' => 1])->assertOk();
        $this->assertSame('approved', $content->languages()->where('lang', 'fr')->value('status'));
        $this->assertSame('submitted', $content->languages()->where('lang', 'en')->value('status'), 'chaque langue est validée séparément');
        $this->assertDatabaseHas('workflow_events', ['object_id' => $content->id, 'action' => 'approved', 'lang' => 'fr', 'actor_id' => $this->validatorFr->id]);
        $this->assertDatabaseHas('audit_logs', ['object_id' => $content->id, 'action' => 'content.approved']);
    }

    public function test_submission_is_assigned_to_the_closest_explicitly_qualified_validator_then_the_least_loaded(): void
    {
        $generalist = $this->userWith('validateur', 'SG');
        $generalist->forceFill(['name' => 'Aaron Généraliste'])->save();
        $content = $this->createContent();
        $this->actingAs($this->author)->postJson("/api/v1/contents/{$content->id}/submit", ['languages' => ['fr']])->assertOk();
        $this->assertSame($this->validatorFr->id, Approval::query()->where('object_id', $content->id)->where('lang', 'fr')->value('assignee_id'), 'habilitation FR explicite prioritaire sur l’ordre alphabétique');

        $second = $this->userWith('validateur', 'COM', ['fr']);
        $other = $this->createContent();
        $this->actingAs($this->author)->postJson("/api/v1/contents/{$other->id}/submit", ['languages' => ['fr']])->assertOk();
        $this->assertSame($second->id, Approval::query()->where('object_id', $other->id)->where('lang', 'fr')->value('assignee_id'), 'à égalité, le moins chargé');
    }

    public function test_validator_cannot_validate_a_language_outside_his_scope(): void
    {
        $content = $this->createContent();
        $this->actingAs($this->author)->postJson("/api/v1/contents/{$content->id}/submit", ['languages' => ['en']])->assertOk();
        $this->actingAs($this->validatorFr)->postJson("/api/v1/contents/{$content->id}/approve", ['lang' => 'en', 'decision' => 'approve', 'expected_revision' => 1])->assertForbidden();
    }

    public function test_return_requires_a_reason_and_goes_back_to_draft(): void
    {
        $content = $this->createContent();
        $this->actingAs($this->author)->postJson("/api/v1/contents/{$content->id}/submit", ['languages' => ['fr']])->assertOk();
        $this->actingAs($this->validatorFr)->postJson("/api/v1/contents/{$content->id}/approve", ['lang' => 'fr', 'decision' => 'return', 'expected_revision' => 1])->assertUnprocessable();
        $this->actingAs($this->validatorFr)->postJson("/api/v1/contents/{$content->id}/approve", ['lang' => 'fr', 'decision' => 'return', 'reason' => 'Préciser le lieu', 'expected_revision' => 1])->assertOk();
        $this->assertSame('returned', $content->languages()->where('lang', 'fr')->value('status'));
        $this->assertDatabaseHas('notifications', ['user_id' => $this->author->id, 'event' => 'content.returned']);
    }

    public function test_editing_an_approved_version_creates_a_revision_to_revalidate_and_snapshot_is_immutable(): void
    {
        $content = $this->createContent();
        $this->actingAs($this->author)->postJson("/api/v1/contents/{$content->id}/submit", ['languages' => ['fr']]);
        $this->actingAs($this->validatorFr)->postJson("/api/v1/contents/{$content->id}/approve", ['lang' => 'fr', 'decision' => 'approve', 'expected_revision' => 1])->assertOk();

        $this->actingAs($this->author)->patchJson("/api/v1/contents/{$content->id}", ['languages' => ['fr' => ['title' => 'Réunion déplacée', 'body' => 'Mardi.']]], ['If-Match' => (string) $content->refresh()->version])->assertOk();
        $line = $content->languages()->where('lang', 'fr')->first();
        $this->assertSame(2, $line->revision);
        $this->assertSame('draft', $line->status);
        $this->assertSame('Réunion de coordination', ContentVersion::query()->where('content_id', $content->id)->value('payload')['title']);

        $this->expectException(QueryException::class);
        ContentVersion::query()->where('content_id', $content->id)->update(['classification' => 'public']);
    }

    public function test_stale_version_is_rejected_without_overwriting(): void
    {
        $content = $this->createContent();
        $this->actingAs($this->author)->patchJson("/api/v1/contents/{$content->id}", ['title' => 'Nouveau titre'], ['If-Match' => '999'])->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->assertSame('Communiqué', $content->refresh()->title);
    }

    public function test_text_overflow_blocks_submission(): void
    {
        $content = $this->createContent();
        $this->actingAs($this->author)->patchJson("/api/v1/contents/{$content->id}", ['languages' => ['fr' => ['title' => str_repeat('Très long titre ', 10)]]], ['If-Match' => (string) $content->refresh()->version])->assertOk();
        $this->actingAs($this->author)->postJson("/api/v1/contents/{$content->id}/submit", ['languages' => ['fr']])->assertUnprocessable()->assertJsonPath('code', 'TEXT_OVERFLOW');
    }

    public function test_restricted_content_cannot_be_submitted_for_collective_screens(): void
    {
        $content = $this->createContent();
        $content->forceFill(['classification' => 'restreint'])->save();
        $this->actingAs($this->author)->postJson("/api/v1/contents/{$content->id}/submit", ['languages' => ['fr']])->assertUnprocessable()->assertJsonPath('code', 'RESTRICTED_CONTENT');
    }

    public function test_modification_without_the_read_version_is_refused(): void
    {
        $content = $this->createContent();
        $this->actingAs($this->author)->patchJson("/api/v1/contents/{$content->id}", ['title' => 'Écrasement'])
            ->assertStatus(428)->assertJsonPath('code', 'VERSION_REQUIRED');
        $this->assertSame('Communiqué', $content->refresh()->title);
    }
}
