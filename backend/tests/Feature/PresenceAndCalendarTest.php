<?php

namespace Tests\Feature;

use App\Models\ReservationOccurrence;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * SAL-10 (confirmation de présence et libération automatique) et SAL-11 (export iCalendar sans connecteur).
 */
class PresenceAndCalendarTest extends TestCase
{
    public function test_presence_confirmation_and_automatic_release_only_where_enabled(): void
    {
        $organizer = $this->userWith('agent');
        $room = $this->room(['auto_release_enabled' => true]);
        $day = CarbonImmutable::now('Africa/Libreville')->addDays(2)->setTime(10, 0);
        $created = $this->actingAs($organizer)->postJson('/api/v1/reservations', ['room_id' => $room->id, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(), 'confidentiality' => false, 'subject' => 'Réunion test', 'participant_count' => 4])->assertCreated();
        $occurrence = ReservationOccurrence::query()->findOrFail($created->json('data.occurrences.0.id'));
        $this->actingAs($organizer)->postJson("/api/v1/occurrences/{$occurrence->id}/check-in")->assertStatus(409)->assertJsonPath('code', 'CHECK_IN_WINDOW');

        $occurrence->forceFill(['starts_at' => now()->subMinutes(20), 'ends_at' => now()->addMinutes(40), 'blocking_start' => now()->subMinutes(35), 'blocking_end' => now()->addMinutes(55)])->save();
        $stranger = $this->userWith('agent', 'DSI');
        $this->actingAs($stranger)->postJson("/api/v1/occurrences/{$occurrence->id}/check-in")->assertForbidden();
        $code = $this->actingAs($organizer)->getJson("/api/v1/reservations/{$occurrence->reservation_id}")->json("data.presence.codes.{$occurrence->id}");
        $this->assertNotEmpty($code);
        $this->assertEmpty($this->actingAs($stranger)->getJson("/api/v1/reservations/{$occurrence->reservation_id}")->json('data.presence.codes'), 'code réservé à l’organisateur');
        $this->actingAs($stranger)->postJson("/api/v1/occurrences/{$occurrence->id}/check-in", ['code' => $code])->assertOk();
        $this->assertNotNull($occurrence->refresh()->checked_in_at);

        $second = $this->room(['auto_release_enabled' => true]);
        $other = $occurrence->replicate(['check_in_code', 'checked_in_at', 'checked_in_by']);
        $other->forceFill(['room_id' => $second->id, 'starts_at' => now()->subMinutes(16), 'ends_at' => now()->addMinutes(30), 'blocking_start' => now()->subMinutes(31), 'blocking_end' => now()->addMinutes(45)])->save();
        $disabled = $this->room();
        $kept = $occurrence->replicate(['check_in_code', 'checked_in_at', 'checked_in_by']);
        $kept->forceFill(['room_id' => $disabled->id, 'starts_at' => now()->subMinutes(16), 'ends_at' => now()->addMinutes(30), 'blocking_start' => now()->subMinutes(31), 'blocking_end' => now()->addMinutes(45)])->save();
        $this->assertSame(1, app(ReservationService::class)->autoRelease());
        $this->assertSame('no_show', $other->refresh()->status);
        $this->assertSame('confirmed', $occurrence->refresh()->status, 'présence confirmée : pas de libération');
        $this->assertSame('confirmed', $kept->refresh()->status, 'règle inactive dans cette salle : pas de libération');
    }

    public function test_ics_export_masks_confidential_subject_for_third_parties(): void
    {
        $organizer = $this->userWith('agent');
        $room = $this->room(['name' => 'Salle Kivu']);
        $day = CarbonImmutable::now('Africa/Libreville')->addDays(3)->setTime(9, 0);
        $created = $this->actingAs($organizer)->postJson('/api/v1/reservations', ['room_id' => $room->id, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(), 'confidentiality' => true, 'subject' => 'Arbitrages secrets', 'participant_count' => 3])->assertCreated();

        $mine = $this->actingAs($organizer)->get("/api/v1/reservations/{$created->json('data.id')}/calendar.ics")->assertOk();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $mine->getContent());
        $this->assertStringContainsString('SUMMARY:Arbitrages secrets', $mine->getContent());

        $roomFeed = $this->actingAs($this->userWith('agent', 'DSI'))->get("/api/v1/rooms/{$room->id}/calendar.ics")->assertOk()->getContent();
        $this->assertStringContainsString('SUMMARY:Réservée', $roomFeed);
        $this->assertStringNotContainsString('Arbitrages', $roomFeed);
    }
}
