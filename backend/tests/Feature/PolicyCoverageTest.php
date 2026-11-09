<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Content;
use App\Models\Document;
use App\Models\Reservation;
use App\Policies\AssetPolicy;
use App\Policies\ContentPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\ReservationPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * SEC-02 : chaque objet protégé (document, média, contenu, réservation) a sa Policy, appliquée par les contrôleurs.
 */
class PolicyCoverageTest extends TestCase
{
    public function test_every_protected_model_has_its_policy(): void
    {
        $this->assertInstanceOf(DocumentPolicy::class, Gate::getPolicyFor(Document::class));
        $this->assertInstanceOf(AssetPolicy::class, Gate::getPolicyFor(Asset::class));
        $this->assertInstanceOf(ContentPolicy::class, Gate::getPolicyFor(Content::class));
        $this->assertInstanceOf(ReservationPolicy::class, Gate::getPolicyFor(Reservation::class));
    }

    public function test_confidential_reservation_policy_denies_third_parties_and_allows_its_organizer(): void
    {
        $organizer = $this->userWith('agent');
        $reservation = Reservation::query()->create(['reference' => 'RES-POL', 'organizer_id' => $organizer->id, 'subject' => 'Arbitrages', 'confidential' => true, 'participant_count' => 3]);

        $this->assertTrue(Gate::forUser($organizer)->allows('view', $reservation));
        $this->assertTrue(Gate::forUser($organizer)->allows('viewAll', $reservation));
        $this->assertFalse(Gate::forUser($this->userWith('agent', 'DSI'))->allows('view', $reservation));
    }

    public function test_restricted_media_policy_follows_media_management_scope(): void
    {
        $owner = $this->userWith('contributeur', 'DSI', null, 'DSI');
        $asset = Asset::query()->create(['reference' => 'MED-POL', 'title' => 'x', 'kind' => 'image', 'owner_structure_id' => $owner->structure_id, 'classification' => 'restreint', 'status' => 'ready', 'created_by' => $owner->id]);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $asset));
        $this->assertFalse(Gate::forUser($this->userWith('diffuseur'))->allows('view', $asset));
        $this->assertFalse(Gate::forUser($this->userWith('contributeur', 'COM', null, 'COM'))->allows('update', $asset));
    }
}
