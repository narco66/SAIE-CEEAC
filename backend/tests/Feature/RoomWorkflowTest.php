<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\MobileResource;
use App\Models\Reservation;
use App\Models\ReservationOccurrence;
use App\Models\Screen;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * SAL-03, SAL-06, SAL-07, SAL-13, ACC-02 : déplacements, séries, libérations et messages de bienvenue.
 */
class RoomWorkflowTest extends TestCase
{
    private User $organizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organizer = $this->userWith('agent', 'DRH');
    }

    /**
     * @return array<string, mixed>
     */
    private function booking(string $roomId, int $days = 3, array $extra = []): array
    {
        $day = CarbonImmutable::now('Africa/Libreville')->addDays($days)->setTime(10, 0);

        return $extra + [
            'room_id' => $roomId, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(),
            'confidentiality' => false, 'subject' => 'Comité', 'participant_count' => 4,
        ];
    }

    public function test_manager_of_origin_room_cannot_bypass_approval_of_destination_room_and_its_manager_gets_a_task(): void
    {
        $managerA = $this->userWith('gestionnaire_salle');
        $managerB = $this->userWith('gestionnaire_salle');
        $roomA = $this->room(['manager_id' => $managerA->id]);
        $roomB = $this->room(['approval_mode' => 'manual', 'manager_id' => $managerB->id]);
        $created = $this->actingAs($this->organizer)->postJson('/api/v1/reservations', $this->booking($roomA->id))->assertCreated();
        $occurrence = ReservationOccurrence::query()->findOrFail($created->json('data.occurrences.0.id'));

        $this->actingAs($managerA)->patchJson("/api/v1/occurrences/{$occurrence->id}", [
            'room_id' => $roomB->id, 'reason' => 'Salle A en travaux', 'expected_version' => $occurrence->version,
        ])->assertOk();

        $this->assertSame('pending', $occurrence->refresh()->status);
        $this->assertSame($managerB->id, Approval::query()->where('object_id', $occurrence->reservation_id)->where('status', 'open')->value('assignee_id'));
        $this->assertDatabaseHas('notifications', ['user_id' => $managerB->id, 'event' => 'reservation.pending']);
    }

    public function test_series_modification_is_all_or_nothing(): void
    {
        $room = $this->room();
        $other = $this->room();
        $series = $this->actingAs($this->organizer)->postJson('/api/v1/reservations', $this->booking($room->id, 3, ['recurrence' => ['freq' => 'weekly', 'count' => 3]]))->assertCreated();
        $occurrences = collect($series->json('data.occurrences'))->sortBy('starts_at')->values();
        $third = CarbonImmutable::parse($occurrences[2]['starts_at'])->setTimezone('Africa/Libreville');
        $this->actingAs($this->userWith('agent'))->postJson('/api/v1/reservations', [
            'room_id' => $other->id, 'start_at' => $third->setTime(14, 0)->toIso8601String(), 'end_at' => $third->setTime(15, 0)->toIso8601String(),
            'confidentiality' => false, 'subject' => 'Occupant', 'participant_count' => 2,
        ])->assertCreated();

        $first = ReservationOccurrence::query()->findOrFail($occurrences[0]['id']);
        $this->actingAs($this->organizer)->postJson("/api/v1/occurrences/{$first->id}/modify-following", [
            'room_id' => $other->id, 'time_from' => '14:00', 'time_to' => '15:00', 'expected_version' => $first->version,
        ])->assertStatus(409)->assertJsonPath('code', 'BOOKING_CONFLICT');
        $this->assertSame(3, ReservationOccurrence::query()->where('reservation_id', $first->reservation_id)->where('room_id', $room->id)->count(), 'aucune occurrence déplacée');

        $this->actingAs($this->organizer)->postJson("/api/v1/occurrences/{$first->id}/modify-following", [
            'room_id' => $other->id, 'time_from' => '08:00', 'time_to' => '09:00', 'expected_version' => $first->version,
        ])->assertOk()->assertJsonPath('modified', 3);
        $this->assertSame(3, ReservationOccurrence::query()->where('reservation_id', $first->reservation_id)->where('room_id', $other->id)->count());
    }

    public function test_refused_request_releases_its_resources_and_services(): void
    {
        $manager = $this->userWith('gestionnaire_salle');
        $room = $this->room(['approval_mode' => 'manual', 'manager_id' => $manager->id]);
        $created = $this->actingAs($this->organizer)->postJson('/api/v1/reservations', $this->booking($room->id))->assertCreated();
        $occurrence = ReservationOccurrence::query()->findOrFail($created->json('data.occurrences.0.id'));
        $resource = MobileResource::query()->create(['code' => 'VP-01', 'name' => 'Vidéoprojecteur', 'type' => 'projector']);
        $booking = $occurrence->resourceBookings()->create([
            'resource_id' => $resource->id, 'blocking_start' => $occurrence->blocking_start, 'blocking_end' => $occurrence->blocking_end, 'status' => 'active',
        ]);
        $service = ServiceRequest::query()->create([
            'occurrence_id' => $occurrence->id, 'service_type_id' => ServiceType::query()->where('code', 'VISIO')->value('id'), 'due_at' => $occurrence->starts_at->subHours(2),
        ]);

        $this->actingAs($manager)->postJson("/api/v1/reservations/{$occurrence->reservation_id}/refuse", ['reason' => 'Salle réservée au Conseil'])->assertOk();

        $this->assertSame('released', $booking->refresh()->status);
        $this->assertSame('cancelled', $service->refresh()->status);
    }

    public function test_orientation_shows_only_current_targeted_welcomes_of_confirmed_meetings(): void
    {
        $kiosk = $this->screen('BORNE-01', true, ['kind' => 'orientation']);
        $elsewhere = $this->screen('BORNE-02', true, ['kind' => 'orientation']);
        $this->welcome('Délégation attendue', 'confirmed', $kiosk);
        $this->welcome('Réunion annulée', 'cancelled', $kiosk);
        $this->welcome('Visite de demain', 'confirmed', $kiosk, true);
        $this->welcome('Autre borne', 'confirmed', $elsewhere);

        [, $token] = $this->pairedDevice($kiosk);
        $manifest = json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json('payload'), true);

        $this->assertSame(['Délégation attendue'], collect($manifest['orientation']['welcomes'])->pluck('title')->all());
    }

    private function welcome(string $title, string $occurrenceStatus, Screen $target, bool $startsLater = false): void
    {
        $room = $this->room();
        $author = $this->userWith('contributeur');
        $validator = $this->userWith('validateur', 'COM', ['fr']);
        $reservation = Reservation::query()->create([
            'reference' => 'RES-'.uniqid(), 'organizer_id' => $this->organizer->id, 'subject' => $title, 'confidential' => false, 'participant_count' => 5,
        ]);
        $occurrence = $reservation->occurrences()->create([
            'room_id' => $room->id, 'sequence' => 1, 'status' => $occurrenceStatus,
            'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2), 'blocking_start' => now()->addMinutes(45), 'blocking_end' => now()->addHours(2)->addMinutes(15),
        ]);
        $content = Content::query()->create([
            'reference' => 'CNT-'.uniqid(), 'title' => $title, 'kind' => 'welcome', 'occurrence_id' => $occurrence->id, 'category_id' => $this->category()->id,
            'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'public', 'expires_at' => now()->addDay(), 'status' => 'approved',
        ]);
        $version = ContentVersion::query()->create([
            'content_id' => $content->id, 'lang' => 'fr', 'revision' => 1, 'payload' => ['title' => $title], 'classification' => 'public',
            'author_id' => $author->id, 'approved_by' => $validator->id, 'approved_at' => now(), 'payload_hash' => hash('sha256', $title),
        ]);
        $campaign = Campaign::query()->create([
            'reference' => 'CMP-'.uniqid(), 'name' => $title, 'status' => 'active', 'priority' => 70,
            'starts_at' => $startsLater ? now()->addDay() : now()->subHour(), 'ends_at' => now()->addDays(2), 'created_by' => $author->id, 'published_at' => now(),
        ]);
        $campaign->items()->create(['content_id' => $content->id, 'position' => 1, 'version_map' => ['fr' => $version->id]]);
        $campaign->targets()->create(['target_type' => 'screen', 'target_id' => $target->id]);
    }

    public function test_visit_status_follows_its_welcome_message_and_meeting(): void
    {
        $room = $this->room();
        $author = $this->userWith('contributeur');
        $created = $this->actingAs($this->organizer)->postJson('/api/v1/reservations', $this->booking($room->id))->assertCreated();
        $occurrence = ReservationOccurrence::query()->findOrFail($created->json('data.occurrences.0.id'));
        $content = Content::query()->create([
            'reference' => 'CNT-BV', 'title' => 'Bienvenue', 'kind' => 'welcome', 'occurrence_id' => $occurrence->id, 'category_id' => $this->category()->id,
            'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'public', 'expires_at' => now()->addDays(5), 'status' => 'submitted',
        ]);
        $visit = Visit::query()->create(['reference' => 'VIS-T1', 'name' => 'Délégation', 'occurrence_id' => $occurrence->id, 'content_id' => $content->id, 'status' => 'message_draft', 'created_by' => $author->id]);

        Visit::syncStatuses();
        $this->assertSame('message_draft', $visit->refresh()->status);

        $content->forceFill(['status' => 'approved'])->save();
        Visit::syncStatuses();
        $this->assertSame('planned', $visit->refresh()->status);

        $occurrence->forceFill(['status' => 'cancelled'])->save();
        Visit::syncStatuses();
        $this->assertSame('cancelled', $visit->refresh()->status);
    }
}
