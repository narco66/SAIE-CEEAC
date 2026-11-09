<?php

namespace Tests\Feature;

use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SAL-11 (audit 2026-11-06, écart SAL-01) : un client d'agenda s'abonne aux réservations d'une salle sans session, par une
 * adresse personnelle remise une fois, révocable, qui applique toujours les droits courants de son titulaire.
 */
class CalendarSubscriptionTest extends TestCase
{
    public function test_subscription_feed_works_without_session_and_stops_when_revoked_or_disabled(): void
    {
        $room = $this->room(['name' => 'Salle Ogooué']);
        $agent = $this->userWith('agent');
        $organizer = $this->userWith('agent');
        $reservation = Reservation::query()->create(['reference' => 'RES-ICS', 'organizer_id' => $organizer->id, 'subject' => 'Arbitrages secrets', 'confidential' => true, 'participant_count' => 4]);
        $start = now()->addDay()->setTime(10, 0);
        $reservation->occurrences()->create(['room_id' => $room->id, 'starts_at' => $start, 'ends_at' => $start->addHour(), 'blocking_start' => $start, 'blocking_end' => $start->addHour(), 'status' => 'confirmed']);

        $created = $this->actingAs($agent)->postJson("/api/v1/rooms/{$room->id}/calendar-subscriptions", [], ['Idempotency-Key' => 'abonnement-1'])->assertCreated();
        $url = (string) $created->json('data.url');
        $this->assertNull(DB::table('idempotency_keys')->where('key', 'abonnement-1')->value('response_body'), 'adresse secrète jamais conservée');
        $path = parse_url($url, PHP_URL_PATH);

        $this->app['auth']->forgetGuards();
        $feed = $this->get($path)->assertOk();
        $this->assertStringContainsString('text/calendar', (string) $feed->headers->get('Content-Type'));
        $this->assertStringContainsString('BEGIN:VCALENDAR', $feed->getContent());
        $this->assertStringNotContainsString('Arbitrages', $feed->getContent(), 'confidentialité appliquée avec les droits du titulaire');
        $this->get('/api/v1/calendar/'.str_repeat('a', 48).'.ics')->assertNotFound();

        $agent->forceFill(['status' => 'disabled'])->save();
        $this->get($path)->assertNotFound();
        $agent->forceFill(['status' => 'active'])->save();
        $this->get($path)->assertOk();

        $id = $created->json('data.id');
        $this->actingAs($this->userWith('agent'))->postJson("/api/v1/calendar-subscriptions/$id/revoke")->assertNotFound();
        $this->actingAs($agent)->getJson('/api/v1/me/calendar-subscriptions')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($agent)->postJson("/api/v1/calendar-subscriptions/$id/revoke")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get($path)->assertNotFound();
    }
}
