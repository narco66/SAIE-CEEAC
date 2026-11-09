<?php

namespace Tests\Feature;

use App\Models\ReservationOccurrence;
use App\Models\ServiceType;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Réunion prioritaire (SAL-10) déclarée par le gestionnaire de la salle, et référentiel des types de prestation (V36).
 */
class PriorityAndServiceTypeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function booking(string $roomId, bool $priority = false): array
    {
        $day = CarbonImmutable::now('Africa/Libreville')->addDays(2)->setTime(10, 0);

        return ['room_id' => $roomId, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(), 'confidentiality' => false, 'subject' => 'Comité de pilotage', 'participant_count' => 6, 'is_priority' => $priority];
    }

    public function test_only_the_room_manager_marks_a_meeting_as_priority_which_exempts_it_from_auto_release(): void
    {
        $manager = $this->userWith('gestionnaire_salle');
        $organizer = $this->userWith('agent');
        $room = $this->room(['auto_release_enabled' => true, 'manager_id' => $manager->id]);

        $this->actingAs($organizer)->postJson('/api/v1/reservations', $this->booking($room->id, true))->assertForbidden();
        $created = $this->actingAs($organizer)->postJson('/api/v1/reservations', $this->booking($room->id))->assertCreated();
        $reservationId = $created->json('data.id');

        $this->actingAs($organizer)->postJson("/api/v1/reservations/$reservationId/priority", ['is_priority' => true, 'reason' => 'Réunion du Président'])->assertForbidden();
        $this->assertFalse($this->actingAs($organizer)->getJson("/api/v1/reservations/$reservationId")->json('data.permissions.priority'));

        $this->actingAs($manager)->postJson("/api/v1/reservations/$reservationId/priority", ['is_priority' => true])->assertUnprocessable();
        $this->actingAs($manager)->postJson("/api/v1/reservations/$reservationId/priority", ['is_priority' => true, 'reason' => 'Réunion du Président'])
            ->assertOk()->assertJsonPath('data.is_priority', true)->assertJsonPath('data.permissions.priority', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.priority_changed', 'object_id' => $reservationId, 'reason' => 'Réunion du Président']);

        $occurrence = ReservationOccurrence::query()->findOrFail($created->json('data.occurrences.0.id'));
        $occurrence->forceFill(['starts_at' => now()->subMinutes(20), 'ends_at' => now()->addMinutes(40), 'blocking_start' => now()->subMinutes(35), 'blocking_end' => now()->addMinutes(55)])->save();
        $this->assertSame(0, app(ReservationService::class)->autoRelease());
        $this->assertSame('confirmed', $occurrence->refresh()->status);
    }

    public function test_service_type_headcount_option_is_editable_and_code_is_frozen(): void
    {
        $admin = $this->userWith('admin_fonc');
        $id = $this->actingAs($admin)->postJson('/api/v1/referentials/service_types', ['code' => 'COCKTAIL', 'name' => 'Cocktail', 'requires_headcount' => true])
            ->assertCreated()->json('data.id');
        $this->assertTrue(ServiceType::query()->findOrFail($id)->requires_headcount);

        $this->actingAs($admin)->patchJson("/api/v1/referentials/service_types/$id", ['requires_headcount' => false])->assertOk()->assertJsonPath('data.requires_headcount', false);
        $this->actingAs($admin)->patchJson("/api/v1/referentials/service_types/$id", ['code' => 'APERITIF'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($admin)->patchJson("/api/v1/referentials/service_types/$id", ['code' => 'COCKTAIL', 'name' => 'Cocktail dînatoire'])->assertOk();
        $this->assertSame('COCKTAIL', ServiceType::query()->findOrFail($id)->code);
    }
}
