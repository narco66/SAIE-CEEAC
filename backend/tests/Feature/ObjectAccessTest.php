<?php

namespace Tests\Feature;

use App\Models\ReservationOccurrence;
use App\Services\ExportService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * SEC-02 : contrôles d'objet des exports PDF03 et des visites (accès horizontal refusé).
 */
class ObjectAccessTest extends TestCase
{
    public function test_meeting_preparation_sheet_is_limited_to_the_room_manager_or_covered_service_teams(): void
    {
        $managerA = $this->userWith('gestionnaire_salle');
        $managerB = $this->userWith('gestionnaire_salle');
        $roomA = $this->room(['manager_id' => $managerA->id]);
        $this->room(['manager_id' => $managerB->id]);
        $agent = $this->userWith('agent');
        $day = CarbonImmutable::now('Africa/Libreville')->addDays(2)->setTime(10, 0);
        $id = $this->actingAs($agent)->postJson('/api/v1/reservations', ['room_id' => $roomA->id, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(), 'confidentiality' => true, 'subject' => 'Arbitrages', 'participant_count' => 4])
            ->assertCreated()->json('data.occurrences.0.id');
        $this->assertNotNull(ReservationOccurrence::query()->find($id));
        $request = ['type' => 'meeting_preparation', 'format' => 'pdf', 'params' => ['occurrence_id' => $id]];

        $this->actingAs($managerB)->postJson('/api/v1/exports', $request)->assertForbidden();
        $this->actingAs($managerA)->postJson('/api/v1/exports', $request)->assertStatus(202);
    }

    public function test_visit_is_modified_only_by_its_author_or_a_manager_covering_the_author_structure(): void
    {
        $protocol = $this->userWith('contributeur', 'PROT', null, 'PROT');
        $id = $this->actingAs($protocol)->postJson('/api/v1/visits', ['name' => 'Délégation du Cameroun'])->assertCreated()->json('data.id');

        $outsider = $this->userWith('contributeur', 'COM', null, 'COM');
        $this->actingAs($outsider)->patchJson("/api/v1/visits/$id", ['status' => 'cancelled'])->assertForbidden();
        $this->actingAs($protocol)->patchJson("/api/v1/visits/$id", ['notes' => 'Accueil au hall'])->assertOk();
    }

    public function test_confirmation_of_a_confidential_meeting_is_classified_restricted(): void
    {
        $room = $this->room();
        $agent = $this->userWith('agent');
        $day = CarbonImmutable::now('Africa/Libreville')->addDays(2)->setTime(10, 0);
        $id = $this->actingAs($agent)->postJson('/api/v1/reservations', [
            'room_id' => $room->id, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(),
            'confidentiality' => true, 'subject' => 'Arbitrages', 'participant_count' => 4,
        ])->assertCreated()->json('data.id');

        $model = app(ExportService::class)->build('reservation_confirmation', ['reservation_id' => $id], $agent);

        $this->assertSame('restreint', $model['classification']);
    }

    public function test_confirmation_is_neither_offered_nor_delivered_to_a_third_party(): void
    {
        $room = $this->room();
        $organizer = $this->userWith('agent');
        $day = CarbonImmutable::now('Africa/Libreville')->addDays(2)->setTime(10, 0);
        $id = $this->actingAs($organizer)->postJson('/api/v1/reservations', [
            'room_id' => $room->id, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(),
            'confidentiality' => false, 'subject' => 'Point d’équipe', 'participant_count' => 4,
        ])->assertCreated()->json('data.id');
        $request = ['type' => 'reservation_confirmation', 'format' => 'pdf', 'params' => ['reservation_id' => $id]];

        // Le bouton de l'interface suit `can_manage` : il disparaît pour un tiers, que le serveur refuse.
        $thirdParty = $this->userWith('agent');
        $this->assertFalse($this->actingAs($thirdParty)->getJson("/api/v1/reservations/$id")->assertOk()->json('data.can_manage'));
        $this->actingAs($thirdParty)->postJson('/api/v1/exports', $request)->assertForbidden();

        $this->assertTrue($this->actingAs($organizer)->getJson("/api/v1/reservations/$id")->assertOk()->json('data.can_manage'));
        $this->actingAs($organizer)->postJson('/api/v1/exports', $request)->assertStatus(202);
    }

    public function test_room_closure_names_the_overlapping_reservations_to_handle(): void
    {
        $manager = $this->userWith('gestionnaire_salle');
        $room = $this->room(['manager_id' => $manager->id]);
        $day = CarbonImmutable::now('Africa/Libreville')->addDays(2)->setTime(10, 0);
        $reference = $this->actingAs($this->userWith('agent'))->postJson('/api/v1/reservations', [
            'room_id' => $room->id, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(),
            'confidentiality' => false, 'subject' => 'Comité', 'participant_count' => 4,
        ])->assertCreated()->json('data.reference');

        $response = $this->actingAs($manager)->postJson("/api/v1/rooms/{$room->id}/blocks", [
            'starts_at' => $day->subHour()->toIso8601String(), 'ends_at' => $day->addHours(3)->toIso8601String(), 'kind' => 'technical', 'reason' => 'Travaux électriques',
        ])->assertCreated();

        $this->assertSame(1, $response->json('impacted_reservations'));
        $this->assertStringContainsString($reference, $response->json('message'));
        $this->assertStringNotContainsString('À valider', $response->json('message'), 'la liste « À valider » ne contient pas les réunions confirmées');
    }
}
