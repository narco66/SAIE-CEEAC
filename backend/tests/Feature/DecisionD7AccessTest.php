<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Access;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Décision D7 (validée le 7 novembre) : participants réservés à l'organisateur, aux participants nommés et aux
 * gestionnaires ; « Générer des exports » exigé pour les extractions de masse ; permission dédiée aux versions du lecteur.
 */
class DecisionD7AccessTest extends TestCase
{
    public function test_participants_of_a_standard_meeting_are_visible_only_to_those_concerned(): void
    {
        $manager = $this->userWith('gestionnaire_salle');
        $room = $this->room(['manager_id' => $manager->id]);
        $organizer = $this->userWith('agent');
        $participant = $this->userWith('agent');
        $day = CarbonImmutable::now('Africa/Libreville')->addDays(2)->setTime(10, 0);
        $id = $this->actingAs($organizer)->postJson('/api/v1/reservations', [
            'room_id' => $room->id, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(),
            'confidentiality' => false, 'subject' => 'Revue budgétaire', 'participant_count' => 4,
            'internal_participants' => [$participant->id], 'external_participants' => [['name' => 'Invité externe', 'organization' => 'Partenaire']],
        ])->assertCreated()->json('data.id');

        foreach ([$organizer, $participant, $manager] as $concerned) {
            $this->actingAs($concerned)->getJson("/api/v1/reservations/$id")->assertOk()
                ->assertJsonPath('data.internal_participants', [$participant->id])
                ->assertJsonPath('data.external_participants.0.name', 'Invité externe');
        }

        $thirdParty = $this->userWith('agent');
        $this->actingAs($thirdParty)->getJson("/api/v1/reservations/$id")->assertOk()
            ->assertJsonPath('data.subject', 'Revue budgétaire')
            ->assertJsonPath('data.internal_participants', null)
            ->assertJsonPath('data.external_participants', null);
        $row = collect($this->actingAs($thirdParty)->getJson('/api/v1/reservations')->assertOk()->json('data'))->firstWhere('id', $id);
        if ($row !== null) {
            $this->assertNull($row['external_participants']);
        }
    }

    public function test_bulk_extractions_require_the_export_permission_but_individual_sheets_do_not(): void
    {
        $agent = $this->userWith('agent');
        $planning = ['type' => 'room_planning', 'format' => 'pdf', 'params' => []];

        $this->actingAs($agent)->postJson('/api/v1/exports', $planning)->assertForbidden();
        $types = collect($this->actingAs($agent)->getJson('/api/v1/exports')->assertOk()->json('meta.types'))->keyBy('type');
        $this->assertFalse($types['room_planning']['allowed']);
        $this->assertTrue($types['reservation_confirmation']['allowed'], 'fiche individuelle : pas de permission d’export exigée');

        $this->actingAs($this->userWith('gestionnaire_salle'))->postJson('/api/v1/exports', $planning)->assertStatus(202);
    }

    public function test_player_releases_have_their_own_permission(): void
    {
        $technician = $this->userWith('technicien', 'DSI');
        $release = fn (string $version) => ['version' => $version, 'channel' => 'pilot', 'base_url' => "/player/releases/$version/", 'sha256' => str_repeat('b', 64)];

        $this->actingAs($technician)->postJson('/api/v1/player-releases', $release('2.0.0'))->assertCreated();

        Role::query()->where('code', 'technicien')->firstOrFail()->permissions()
            ->detach(Permission::query()->where('code', 'player_releases.manage')->value('id'));
        app(Access::class)->flush();
        $this->actingAs($technician->fresh())->postJson('/api/v1/player-releases', $release('2.0.1'))->assertForbidden();
        $this->actingAs($technician->fresh())->postJson('/api/v1/incidents', [])->assertStatus(422);
    }
}
