<?php

namespace Tests\Feature;

use App\Models\ReservationOccurrence;
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
}
