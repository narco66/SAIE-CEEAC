<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * SAL-15 : la pause-café et restauration exige un nombre de couverts et une heure de service pendant la réunion,
 * puis suit le circuit des prestations de SAL-08 (liste V32, fiche PDF03).
 */
class CateringServiceTest extends TestCase
{
    private User $agent;

    private Room $room;

    private ServiceType $catering;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = $this->userWith('agent', 'DRH');
        $this->room = $this->room();
        $this->catering = ServiceType::query()->where('code', 'RESTAURATION')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private function payload(array $service): array
    {
        $day = CarbonImmutable::now('Africa/Libreville')->addDays(3);

        return [
            'room_id' => $this->room->id, 'start_at' => $day->setTime(9, 0)->toIso8601String(), 'end_at' => $day->setTime(12, 0)->toIso8601String(),
            'confidentiality' => false, 'subject' => 'Atelier régional', 'participant_count' => 18,
            'services' => [['service_type_id' => $this->catering->id] + $service],
        ];
    }

    public function test_catering_is_active_and_requires_headcount_and_serving_time(): void
    {
        $this->assertTrue($this->catering->is_active);
        $this->assertTrue($this->catering->requires_headcount);

        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload([]))
            ->assertUnprocessable()->assertJsonValidationErrors(['services.0.headcount', 'services.0.serve_time']);
        $this->assertSame(0, ServiceRequest::query()->count());
    }

    public function test_serving_time_outside_the_meeting_is_refused(): void
    {
        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload(['headcount' => 30, 'serve_time' => '13:30']))
            ->assertUnprocessable()->assertJsonValidationErrors(['services.0.serve_time']);
        $this->assertSame(0, ServiceRequest::query()->count());
    }

    public function test_catering_request_is_recorded_and_listed_for_service_teams(): void
    {
        $response = $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload(['headcount' => 18, 'serve_time' => '10:30', 'details' => 'Sans gluten : 2']))
            ->assertCreated();
        $this->assertSame('Pause-café et restauration · 18 couverts à 10:30', $response->json('data.occurrences.0.services.0.label'));
        $this->assertDatabaseHas('service_requests', ['service_type_id' => $this->catering->id, 'headcount' => 18, 'serve_time' => '10:30', 'status' => 'requested']);

        $provider = $this->userWith('gestionnaire_salle', 'DSI');
        $this->grantPower($provider, 'prestations');
        $this->actingAs($provider)->getJson('/api/v1/service-requests')->assertOk()
            ->assertJsonPath('data.0.type.code', 'RESTAURATION')->assertJsonPath('data.0.headcount', 18)->assertJsonPath('data.0.serve_time', '10:30');
    }

    public function test_checklist_is_limited_to_meetings_with_a_service_in_the_power_scope(): void
    {
        $occurrence = $this->actingAs($this->agent)->postJson('/api/v1/reservations', $this->payload(['headcount' => 18, 'serve_time' => '10:30']))
            ->assertCreated()->json('data.occurrences.0.id');
        $items = ['items' => [['phase' => 'opening', 'label' => 'Tables dressées', 'done' => false]]];

        $visioOnly = $this->userWith('gestionnaire_salle', 'DSI');
        $this->grantPower($visioOnly, 'prestations', ['service_types' => ['VISIO']]);
        $this->actingAs($visioOnly)->postJson("/api/v1/occurrences/{$occurrence}/checklist", $items)->assertForbidden();
        $this->assertDatabaseCount('checklist_items', 0);

        $catering = $this->userWith('gestionnaire_salle', 'DSI');
        $this->grantPower($catering, 'prestations', ['service_types' => ['RESTAURATION']]);
        $this->actingAs($catering)->postJson("/api/v1/occurrences/{$occurrence}/checklist", $items)->assertOk();
        $this->assertDatabaseCount('checklist_items', 1);
    }

    public function test_unrestricted_power_holder_keeps_the_checklist_of_a_meeting_without_service(): void
    {
        $payload = $this->payload([]);
        unset($payload['services']);
        $occurrence = $this->actingAs($this->agent)->postJson('/api/v1/reservations', $payload)->assertCreated()->json('data.occurrences.0.id');
        $manager = $this->userWith('gestionnaire_salle', 'DSI');
        $this->grantPower($manager, 'prestations');

        $this->actingAs($manager)->postJson("/api/v1/occurrences/{$occurrence}/checklist", ['items' => [['phase' => 'opening', 'label' => 'Salle aérée']]])->assertOk();
    }

    public function test_other_services_keep_free_text_details_only(): void
    {
        $visio = ServiceType::query()->where('code', 'VISIO')->firstOrFail();
        $payload = $this->payload([]);
        $payload['services'] = [['service_type_id' => $visio->id, 'details' => 'Liaison avec Kinshasa']];

        $this->actingAs($this->agent)->postJson('/api/v1/reservations', $payload)->assertCreated();
        $this->assertDatabaseHas('service_requests', ['service_type_id' => $visio->id, 'headcount' => null, 'serve_time' => null]);
    }
}
