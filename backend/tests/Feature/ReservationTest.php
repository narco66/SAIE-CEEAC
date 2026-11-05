<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\ReservationOccurrence;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Réservations : R07 à R12, SAL-03 à SAL-12, API-03.
 */
class ReservationTest extends TestCase
{
    private User $agent;

    private Room $room;

    private CarbonImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = $this->userWith('agent', 'DRH');
        $this->room = $this->room();
        $this->day = CarbonImmutable::now('Africa/Libreville')->addDays(3)->setTime(0, 0);
    }

    private function payload(string $from, string $to, array $extra = []): array
    {
        [$fh, $fm] = explode(':', $from);
        [$th, $tm] = explode(':', $to);

        return array_merge([
            'room_id' => $this->room->id, 'start_at' => $this->day->setTime((int) $fh, (int) $fm)->toIso8601String(), 'end_at' => $this->day->setTime((int) $th, (int) $tm)->toIso8601String(),
            'confidentiality' => false, 'subject' => 'Réunion test', 'participant_count' => 8,
        ], $extra);
    }

    public function test_database_constraint_rejects_overlapping_active_occurrences_even_without_application_check(): void
    {
        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('10:00', '11:00'))->assertCreated();
        $reservation = Reservation::query()->create(['reference' => 'RES-X', 'organizer_id' => $this->agent->id, 'subject' => 'Concurrente', 'participant_count' => 2]);
        $start = $this->day->setTime(10, 30)->utc();

        $this->expectException(QueryException::class);
        DB::transaction(fn () => $reservation->occurrences()->create(['room_id' => $this->room->id, 'starts_at' => $start, 'ends_at' => $start->addHour(), 'blocking_start' => $start, 'blocking_end' => $start->addHour(), 'status' => 'confirmed']));
    }

    public function test_second_request_on_same_slot_gets_documented_conflict_without_third_party_title(): void
    {
        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('10:00', '11:00', ['subject' => 'Sujet secret', 'confidentiality' => true]))->assertCreated();
        $other = $this->userWith('agent', 'DSI');
        $response = $this->actingAs($other)->postJson('/api/v1/reservations', $this->payload('10:30', '11:30'))->assertStatus(409)->assertJsonPath('code', 'BOOKING_CONFLICT');
        $this->assertStringNotContainsString('Sujet secret', $response->getContent());
        $this->assertSame(1, ReservationOccurrence::query()->count());
    }

    public function test_adjacent_slots_respect_preparation_buffers(): void
    {
        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('10:00', '11:00'))->assertCreated();
        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('11:00', '12:00'))->assertStatus(409);
        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('11:30', '12:30'))->assertCreated();

        $bare = $this->room(['prep_minutes' => 0, 'cleanup_minutes' => 0]);
        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('10:00', '11:00', ['room_id' => $bare->id]))->assertCreated();
        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('11:00', '12:00', ['room_id' => $bare->id]))->assertCreated();
    }

    public function test_series_with_one_conflict_creates_nothing_in_all_or_nothing_mode(): void
    {
        $blocker = $this->day->addWeeks(4);
        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('09:00', '10:00', ['start_at' => $blocker->setTime(9, 0)->toIso8601String(), 'end_at' => $blocker->setTime(10, 0)->toIso8601String()]))->assertCreated();
        $before = ReservationOccurrence::query()->count();
        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('09:00', '10:00', ['recurrence' => ['freq' => 'weekly', 'count' => 10]]))
            ->assertStatus(409)->assertJsonCount(1, 'details.conflicts');
        $this->assertSame($before, ReservationOccurrence::query()->count());

        $partial = $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('09:00', '10:00', ['recurrence' => ['freq' => 'weekly', 'count' => 10], 'recurrence_mode' => 'partial']))->assertCreated();
        $this->assertCount(1, $partial->json('skipped'));
        $this->assertCount(9, $partial->json('data.occurrences'));
    }

    public function test_moving_to_an_occupied_room_keeps_original_reservation(): void
    {
        $other = $this->room(['code' => 'OTHER']);
        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('14:00', '15:00', ['room_id' => $other->id]))->assertCreated();
        $mine = $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('14:00', '15:00'))->assertCreated();
        $occurrence = ReservationOccurrence::query()->findOrFail($mine->json('data.occurrences.0.id'));

        $this->actingAs($this->agent)->patchJson("/api/v1/occurrences/{$occurrence->id}", ['room_id' => $other->id])->assertStatus(409);
        $this->assertSame($this->room->id, $occurrence->refresh()->room_id);
        $this->assertSame('confirmed', $occurrence->status);
    }

    public function test_idempotent_replay_creates_a_single_reservation_with_stable_response(): void
    {
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->actingAs($this->agent)->withHeader('Idempotency-Key', 'demande-unique-0001')->postJson('/api/v1/reservations', $this->payload('16:00', '17:00'))->json('data.reference');
        }
        $this->assertSame(1, Reservation::query()->count());
        $this->assertCount(1, array_unique($responses));
        $this->actingAs($this->agent)->withHeader('Idempotency-Key', 'demande-unique-0001')->postJson('/api/v1/reservations', $this->payload('17:00', '18:00'))->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_MISMATCH');
    }

    public function test_capacity_and_opening_hours_are_enforced_server_side(): void
    {
        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('10:00', '11:00', ['participant_count' => 50]))->assertUnprocessable()->assertJsonPath('code', 'CAPACITY_EXCEEDED');
        $limited = $this->room(['open_from' => '08:00', 'open_to' => '18:00']);
        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('18:30', '19:30', ['room_id' => $limited->id]))->assertUnprocessable()->assertJsonPath('code', 'OUTSIDE_OPENING_HOURS');
    }

    public function test_manual_room_holds_slot_pending_and_manager_decides(): void
    {
        $manager = $this->userWith('gestionnaire_salle', 'MG');
        $manual = $this->room(['approval_mode' => 'manual', 'manager_id' => $manager->id]);
        $created = $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('10:00', '11:00', ['room_id' => $manual->id]))->assertCreated();
        $this->assertSame('pending', $created->json('data.occurrences.0.status'));
        $this->actingAs($this->userWith('agent'))->postJson('/api/v1/reservations', $this->payload('10:00', '11:00', ['room_id' => $manual->id]))->assertStatus(409);
        $this->actingAs($this->agent)->postJson("/api/v1/reservations/{$created->json('data.id')}/approve")->assertForbidden();
        $this->actingAs($manager)->postJson("/api/v1/reservations/{$created->json('data.id')}/approve")->assertOk();
        $this->assertSame('confirmed', ReservationOccurrence::query()->value('status'));
    }

    public function test_no_show_is_declared_by_manager_after_start_only(): void
    {
        $manager = $this->userWith('gestionnaire_salle', 'MG');
        $this->room->forceFill(['manager_id' => $manager->id])->save();
        $created = $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('10:00', '11:00'))->assertCreated();
        $occurrence = ReservationOccurrence::query()->findOrFail($created->json('data.occurrences.0.id'));
        $this->actingAs($manager)->postJson("/api/v1/occurrences/{$occurrence->id}/no-show", ['reason' => 'Salle vide'])->assertStatus(409)->assertJsonPath('code', 'TOO_EARLY');
        $occurrence->forceFill(['starts_at' => now()->subMinutes(20), 'ends_at' => now()->addMinutes(40), 'blocking_start' => now()->subMinutes(35), 'blocking_end' => now()->addMinutes(55)])->save();
        $this->actingAs($this->agent)->postJson("/api/v1/occurrences/{$occurrence->id}/no-show", ['reason' => 'Salle vide'])->assertForbidden();
        $this->actingAs($manager)->postJson("/api/v1/occurrences/{$occurrence->id}/no-show", ['reason' => 'Salle vide depuis 20 min'])->assertOk();
        $this->assertSame('no_show', $occurrence->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.no_show']);
    }

    public function test_confidential_meeting_is_masked_for_other_agents(): void
    {
        $created = $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload('10:00', '11:00', ['subject' => 'Arbitrages budgétaires', 'confidentiality' => true]))->assertCreated();
        $other = $this->userWith('agent', 'DSI');
        $this->actingAs($other)->getJson("/api/v1/reservations/{$created->json('data.id')}")->assertNotFound();
        $availability = $this->actingAs($other)->getJson("/api/v1/rooms/{$this->room->id}/availability?from=".urlencode($this->day->toIso8601String()).'&to='.urlencode($this->day->addDay()->toIso8601String()))->assertOk();
        $this->assertStringNotContainsString('Arbitrages', $availability->getContent());
        $this->assertSame('Réservée', $availability->json('data.busy.0.label'));
    }
}
