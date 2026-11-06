<?php

namespace Tests\Feature;

use App\Models\AccessLink;
use App\Models\Approval;
use App\Models\ChecklistItem;
use App\Models\Content;
use App\Models\Document;
use App\Models\Language;
use App\Models\Manifest;
use App\Models\ReservationOccurrence;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Services\ContentWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * Anomalies mineures de l'audit du 6 novembre : libellés de contenus restreints, prestations closes, constats figés,
 * dérogation de capacité, désactivation, documents échus.
 */
class MinorFixesTest extends TestCase
{
    private function occurrence(): ReservationOccurrence
    {
        $agent = $this->userWith('agent');
        $day = CarbonImmutable::now('Africa/Libreville')->addDays(2)->setTime(10, 0);
        $id = $this->actingAs($agent)->postJson('/api/v1/reservations', [
            'room_id' => $this->room()->id, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(),
            'confidentiality' => false, 'subject' => 'Comité', 'participant_count' => 4,
        ])->assertCreated()->json('data.occurrences.0.id');

        return ReservationOccurrence::query()->findOrFail($id);
    }

    public function test_restricted_content_is_named_by_its_reference_in_notifications(): void
    {
        $author = $this->userWith('contributeur');
        $content = Content::query()->create([
            'reference' => 'CNT-R1', 'title' => 'Plan de crise confidentiel', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id,
            'author_id' => $author->id, 'classification' => 'restreint', 'expires_at' => now()->addDays(5), 'status' => 'draft',
        ]);

        $label = app(ContentWorkflow::class)->label($content);

        $this->assertSame('CNT-R1 (contenu restreint)', $label);
        $this->assertStringNotContainsString('crise', $label);
    }

    public function test_cancelled_service_request_is_final_and_a_recorded_check_cannot_be_rewritten(): void
    {
        $occurrence = $this->occurrence();
        $manager = $this->userWith('gestionnaire_salle');
        $this->grantPower($manager, 'prestations', ['all' => true]);
        $service = ServiceRequest::query()->create(['occurrence_id' => $occurrence->id, 'service_type_id' => ServiceType::query()->where('code', 'VISIO')->value('id'), 'due_at' => now()->addDay(), 'status' => 'cancelled']);
        $this->actingAs($manager)->patchJson("/api/v1/service-requests/{$service->id}", ['status' => 'accepted'])->assertStatus(409);

        $done = ChecklistItem::query()->create(['occurrence_id' => $occurrence->id, 'phase' => 'opening', 'label' => 'Vidéoprojecteur testé', 'done_at' => now(), 'done_by' => $manager->id]);
        $this->actingAs($manager)->postJson("/api/v1/occurrences/{$occurrence->id}/checklist", ['items' => [['id' => $done->id, 'phase' => 'opening', 'label' => 'Réécrit', 'done' => true]]])->assertOk();
        $this->assertSame('Vidéoprojecteur testé', $done->refresh()->label);
    }

    public function test_capacity_override_is_recorded_when_a_meeting_is_modified(): void
    {
        $occurrence = $this->occurrence();
        $manager = $this->userWith('gestionnaire_salle');
        $occurrence->room->forceFill(['manager_id' => $manager->id])->save();

        $this->actingAs($manager)->patchJson("/api/v1/occurrences/{$occurrence->id}", [
            'participant_count' => 60, 'capacity_override_reason' => 'Plénière exceptionnelle', 'reason' => 'Extension du public', 'expected_version' => $occurrence->version,
        ])->assertOk();

        $this->assertTrue((bool) $occurrence->refresh()->capacity_override);
        $this->assertSame('Plénière exceptionnelle', $occurrence->capacity_override_reason);
    }

    public function test_deactivating_a_validator_releases_their_open_validations(): void
    {
        $validator = $this->userWith('validateur', 'COM', ['fr']);
        $approval = Approval::query()->create(['object_type' => 'content', 'object_id' => (string) str()->uuid(), 'lang' => 'fr', 'revision' => 1, 'title' => 'x', 'assignee_id' => $validator->id, 'due_at' => now()->addDay()]);

        $this->actingAs($this->userWith('admin_tech'))->postJson("/api/v1/users/{$validator->id}/deactivate", ['reason' => 'Départ de la Commission'])->assertOk();

        $this->assertNull($approval->refresh()->assignee_id);
    }

    public function test_expired_document_is_no_longer_offered_to_readers(): void
    {
        $owner = $this->userWith('contributeur', 'DSI', null, 'DSI');
        $document = Document::query()->create([
            'reference' => 'DOC-EXP', 'title' => 'Note échue', 'owner_structure_id' => $owner->structure_id, 'author_id' => $owner->id,
            'classification' => 'public', 'status' => 'published', 'published_at' => now()->subMonth(), 'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->userWith('agent', 'COM'))->getJson("/api/v1/documents/{$document->id}")->assertNotFound();
        $this->actingAs($owner)->getJson("/api/v1/documents/{$document->id}")->assertOk();
    }

    public function test_content_qr_requires_a_broadcastable_content_and_the_right_to_see_it(): void
    {
        $author = $this->userWith('contributeur');
        $content = Content::query()->create([
            'reference' => 'CNT-QR', 'title' => 'Note interne', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id,
            'author_id' => $author->id, 'classification' => 'interne', 'expires_at' => now()->addDays(5), 'status' => 'approved',
        ]);
        AccessLink::query()->create(['code' => 'qrcontenu1', 'target_type' => 'content', 'target_id' => $content->id, 'classification' => 'interne', 'created_by' => $author->id]);

        $this->getJson('/api/v1/q/qrcontenu1')->assertUnauthorized();
        $this->actingAs($this->userWith('agent', 'DSI'))->getJson('/api/v1/q/qrcontenu1')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($author)->getJson('/api/v1/q/qrcontenu1')->assertOk();

        $content->forceFill(['status' => 'archived'])->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($author)->getJson('/api/v1/q/qrcontenu1')->assertNotFound();
    }

    public function test_inactive_language_is_refused_by_the_server(): void
    {
        Language::query()->where('code', 'en')->update(['is_active' => false]);
        $author = $this->userWith('contributeur');

        $this->actingAs($author)->postJson('/api/v1/contents', [
            'title' => 'Communiqué', 'category_id' => $this->category()->id, 'classification' => 'interne', 'expires_at' => now()->addDays(5)->toIso8601String(),
            'languages' => ['fr' => ['title' => 'Réunion'], 'en' => ['title' => 'Meeting']],
        ])->assertUnprocessable()->assertJsonPath('code', 'LANGUAGE_UNKNOWN');
    }

    public function test_subject_of_a_pending_request_is_hidden_from_third_parties(): void
    {
        $manager = $this->userWith('gestionnaire_salle');
        $room = $this->room(['approval_mode' => 'manual', 'manager_id' => $manager->id]);
        $day = CarbonImmutable::now('Africa/Libreville')->addDays(3)->setTime(9, 0);
        $id = $this->actingAs($this->userWith('agent'))->postJson('/api/v1/reservations', [
            'room_id' => $room->id, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(),
            'confidentiality' => false, 'subject' => 'Restructuration du service', 'participant_count' => 4,
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->userWith('agent', 'DSI'))->getJson("/api/v1/reservations/$id")->assertOk()->assertJsonPath('data.subject', 'Réservée');
        $this->actingAs($manager)->getJson("/api/v1/reservations/$id")->assertOk()->assertJsonPath('data.subject', 'Restructuration du service');
        $feed = $this->actingAs($this->userWith('agent', 'DSI'))->get("/api/v1/rooms/{$room->id}/calendar.ics")->assertOk()->getContent();
        $this->assertStringNotContainsString('Restructuration', $feed);
    }

    public function test_proof_of_broadcast_is_not_deleted_with_its_player(): void
    {
        [$device] = $this->pairedDevice($this->screen('ECR-PREUVE'));
        Manifest::query()->create(['device_id' => $device->id, 'version' => 1, 'etag' => 'e1', 'payload' => [], 'valid_until' => now()->addHour(), 'generated_at' => now()]);

        $this->expectException(QueryException::class);
        $device->delete();
    }
}
