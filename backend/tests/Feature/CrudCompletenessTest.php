<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Asset;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\FallbackContent;
use App\Models\Holiday;
use App\Models\MobileResource;
use App\Models\ReservationOccurrence;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoomBlock;
use App\Models\Structure;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Audit des CRUD (9 novembre 2026) : opérations manquantes ou incomplètes relevées dans les salles, l'administration et la
 * publication, corrigées conformément au cahier des charges (SAL, REF, HAB, DOC, PUB, NOT) et aux règles d'API (API-01, API-04).
 */
class CrudCompletenessTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function booking(string $roomId, int $days = 3, array $extra = []): array
    {
        $day = CarbonImmutable::now('Africa/Libreville')->addDays($days)->setTime(10, 0);

        return $extra + ['room_id' => $roomId, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(), 'confidentiality' => false, 'subject' => 'Comité', 'participant_count' => 4];
    }

    public function test_cancelling_a_pending_request_closes_its_approval_and_warns_the_room_manager(): void
    {
        $manager = $this->userWith('gestionnaire_salle');
        $organizer = $this->userWith('agent', 'DRH');
        $room = $this->room(['approval_mode' => 'manual', 'manager_id' => $manager->id]);
        $created = $this->actingAs($organizer)->postJson('/api/v1/reservations', $this->booking($room->id))->assertCreated();
        $occurrenceId = $created->json('data.occurrences.0.id');
        $reservationId = ReservationOccurrence::query()->findOrFail($occurrenceId)->reservation_id;
        $this->assertSame('open', Approval::query()->where('object_id', $reservationId)->value('status'));

        $this->actingAs($organizer)->postJson("/api/v1/occurrences/{$occurrenceId}/cancel", ['scope' => 'this', 'reason' => 'Réunion reportée'])->assertOk();
        $this->assertSame('cancelled', Approval::query()->where('object_id', $reservationId)->value('status'), 'plus de rappel ni d’escalade sur une demande annulée');
        $this->assertTrue(UserNotification::query()->where('user_id', $manager->id)->where('event', 'reservation.cancelled')->exists());
    }

    public function test_a_closure_set_after_the_request_blocks_its_approval_and_warns_the_organizer(): void
    {
        $manager = $this->userWith('gestionnaire_salle');
        $organizer = $this->userWith('agent', 'DRH');
        $room = $this->room(['approval_mode' => 'manual', 'manager_id' => $manager->id]);
        $created = $this->actingAs($organizer)->postJson('/api/v1/reservations', $this->booking($room->id))->assertCreated();
        $occurrence = ReservationOccurrence::query()->findOrFail($created->json('data.occurrences.0.id'));

        $this->actingAs($manager)->postJson("/api/v1/rooms/{$room->id}/blocks", [
            'starts_at' => $occurrence->starts_at->copy()->subHour()->toIso8601String(), 'ends_at' => $occurrence->ends_at->copy()->addHour()->toIso8601String(), 'kind' => 'technical', 'reason' => 'Dégât des eaux',
        ])->assertCreated();
        $this->assertTrue(UserNotification::query()->where('user_id', $organizer->id)->where('event', 'reservation.blocked')->exists());
        $this->actingAs($manager)->postJson("/api/v1/reservations/{$occurrence->reservation_id}/approve")->assertStatus(409)->assertJsonPath('code', 'ROOM_BLOCKED');
        $this->actingAs($manager)->postJson("/api/v1/reservations/{$occurrence->reservation_id}/refuse", ['reason' => 'Salle fermée pour travaux'])->assertOk();

        // Retrait d'une fermeture : motif exigé ; une fermeture terminée reste dans l'historique.
        $block = RoomBlock::query()->where('room_id', $room->id)->firstOrFail();
        $this->actingAs($manager)->deleteJson("/api/v1/room-blocks/{$block->id}")->assertUnprocessable();
        $ended = RoomBlock::query()->create(['room_id' => $room->id, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(2), 'kind' => 'technical', 'reason' => 'Ancienne fermeture', 'created_by' => $manager->id]);
        $this->actingAs($manager)->deleteJson("/api/v1/room-blocks/{$ended->id}", ['reason' => 'Nettoyage'])->assertStatus(409)->assertJsonPath('code', 'BLOCK_ENDED');
        $this->actingAs($manager)->deleteJson("/api/v1/room-blocks/{$block->id}", ['reason' => 'Travaux reportés'])->assertNoContent();
    }

    public function test_a_pending_request_moved_to_a_room_without_approval_is_confirmed(): void
    {
        $manager = $this->userWith('gestionnaire_salle');
        $organizer = $this->userWith('agent', 'DRH');
        $manual = $this->room(['approval_mode' => 'manual', 'manager_id' => $manager->id]);
        $auto = $this->room();
        $created = $this->actingAs($organizer)->postJson('/api/v1/reservations', $this->booking($manual->id))->assertCreated();
        $occurrence = ReservationOccurrence::query()->findOrFail($created->json('data.occurrences.0.id'));

        $this->actingAs($organizer)->patchJson("/api/v1/occurrences/{$occurrence->id}", ['room_id' => $auto->id, 'expected_version' => $occurrence->version])->assertOk();
        $this->assertSame('confirmed', $occurrence->refresh()->status);
        $this->assertSame('cancelled', Approval::query()->where('object_id', $occurrence->reservation_id)->value('status'));
    }

    public function test_room_updates_are_cross_checked_and_affected_bookings_are_confirmed_first(): void
    {
        $admin = $this->userWith('admin_fonc', 'DSI');
        $organizer = $this->userWith('agent', 'DRH');
        $room = $this->room(['open_from' => '08:00', 'open_to' => '18:00']);

        // Modification partielle incohérente : 422 sur le champ, jamais une erreur 500 de la base.
        $this->actingAs($admin)->patchJson("/api/v1/rooms/{$room->id}", ['open_from' => '19:00'], ['If-Match' => (string) $room->version])->assertUnprocessable()->assertJsonValidationErrors('open_to');
        $this->actingAs($admin)->patchJson("/api/v1/rooms/{$room->id}", ['min_minutes' => 700], ['If-Match' => (string) $room->version])->assertUnprocessable();
        $this->actingAs($admin)->patchJson("/api/v1/rooms/{$room->id}", ['approval_mode' => 'manual'], ['If-Match' => (string) $room->version])->assertUnprocessable()->assertJsonValidationErrors('manager_id');
        $this->actingAs($admin)->patchJson("/api/v1/rooms/{$room->id}", ['max_minutes' => 20], ['If-Match' => (string) $room->version])->assertUnprocessable()->assertJsonValidationErrors('max_minutes');

        // Modification partielle cohérente : une seule borne envoyée, recoupée avec la valeur enregistrée (jeu d'essai des configurations).
        $this->actingAs($admin)->patchJson("/api/v1/rooms/{$room->id}", ['max_minutes' => 480], ['If-Match' => (string) $room->version])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/rooms/{$room->id}", ['open_to' => '20:30'], ['If-Match' => (string) $room->refresh()->version])->assertOk();
        $this->assertSame([480, '20:30'], [$room->refresh()->max_minutes, substr((string) $room->open_to, 0, 5)]);

        $this->actingAs($organizer)->postJson('/api/v1/reservations', $this->booking($room->id))->assertCreated();
        $this->actingAs($admin)->patchJson("/api/v1/rooms/{$room->id}", ['status' => 'inactive'], ['If-Match' => (string) $room->version])->assertStatus(409)->assertJsonPath('code', 'CHANGE_AFFECTS_BOOKINGS');
        $this->actingAs($admin)->patchJson("/api/v1/rooms/{$room->id}", ['status' => 'inactive', 'confirm_affected' => true], ['If-Match' => (string) $room->version])->assertOk();
        $this->assertTrue(UserNotification::query()->where('user_id', $organizer->id)->where('event', 'reservation.room_changed')->exists());

        // La saisie générique des référentiels ne contourne plus les règles des salles.
        $this->actingAs($admin)->patchJson("/api/v1/referentials/rooms/{$room->id}", ['capacity' => 2])->assertStatus(409)->assertJsonPath('code', 'USE_ROOMS_MODULE');
    }

    public function test_mobile_resources_can_be_edited_and_taken_out_of_service(): void
    {
        $admin = $this->userWith('admin_fonc', 'DSI');
        $id = $this->actingAs($admin)->postJson('/api/v1/mobile-resources', ['code' => 'VP-01', 'name' => 'Vidéoprojecteur', 'type' => 'projecteur'])->assertCreated()->json('data.id');
        $this->actingAs($admin)->patchJson("/api/v1/mobile-resources/{$id}", ['name' => 'Vidéoprojecteur portable'])->assertOk()->assertJsonPath('data.name', 'Vidéoprojecteur portable');
        $this->actingAs($admin)->patchJson("/api/v1/mobile-resources/{$id}", ['status' => 'inactive'])->assertUnprocessable();
        $this->actingAs($admin)->patchJson("/api/v1/mobile-resources/{$id}", ['status' => 'inactive', 'reason' => 'Lampe hors d’usage'])->assertOk();
        $this->assertSame([], $this->actingAs($admin)->getJson('/api/v1/mobile-resources')->json('data'), 'une ressource hors service n’est plus proposée');
        $this->assertCount(1, $this->actingAs($admin)->getJson('/api/v1/mobile-resources?status=all')->json('data'));
        $this->actingAs($this->userWith('agent'))->patchJson("/api/v1/mobile-resources/{$id}", ['name' => 'X'])->assertForbidden();
        $this->assertSame('inactive', MobileResource::query()->findOrFail($id)->status);
    }

    public function test_referentials_check_uniqueness_in_parent_active_parents_and_structure_cycles(): void
    {
        $admin = $this->userWith('admin_fonc', 'DSI');
        $siteId = $this->place()['site']->id;
        $this->actingAs($admin)->postJson('/api/v1/referentials/buildings', ['code' => 'BAT-X', 'name' => 'Annexe', 'site_id' => $siteId])->assertCreated();
        $this->actingAs($admin)->postJson('/api/v1/referentials/buildings', ['code' => 'BAT-X', 'name' => 'Doublon', 'site_id' => $siteId])->assertUnprocessable()->assertJsonValidationErrors('code');

        $parent = Structure::query()->create(['code' => 'PARENT', 'name' => 'Parent', 'type' => 'direction']);
        $child = Structure::query()->create(['code' => 'ENFANT', 'name' => 'Enfant', 'type' => 'service', 'parent_id' => $parent->id]);
        $this->actingAs($admin)->patchJson("/api/v1/referentials/structures/{$parent->id}", ['parent_id' => $child->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->actingAs($admin)->patchJson("/api/v1/referentials/structures/{$parent->id}", ['parent_id' => $parent->id])->assertUnprocessable();

        // Désactivation : les dépendants encore actifs sont signalés ; un parent désactivé n'est plus proposé à une nouvelle saisie.
        $this->actingAs($admin)->patchJson("/api/v1/referentials/structures/{$parent->id}", ['is_active' => false])->assertOk()->assertJsonPath('meta.usage.sous-structures', 1);
        $this->actingAs($admin)->postJson('/api/v1/referentials/structures', ['code' => 'NOUV', 'name' => 'Nouvelle', 'type' => 'service', 'parent_id' => $parent->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $holiday = Holiday::query()->create(['date' => now()->addMonth()->toDateString(), 'label' => 'Saisie erronée']);
        $this->actingAs($admin)->deleteJson("/api/v1/referentials/holidays/{$holiday->id}")->assertNoContent();
        $this->assertModelMissing($holiday);
    }

    public function test_an_import_with_an_invalid_row_writes_nothing(): void
    {
        $admin = $this->userWith('admin_fonc', 'DSI');
        $csv = "code;name;is_active\nEQ-OK;Écran tactile;1\nEQ-KO;;1\n";
        $this->actingAs($admin)->postJson('/api/v1/imports/commit', ['type' => 'equipment', 'file' => UploadedFile::fake()->createWithContent('eq.csv', $csv)])->assertUnprocessable()->assertJsonPath('code', 'IMPORT_INVALID');
        $this->assertFalse(Equipment::query()->where('code', 'EQ-OK')->exists(), 'la transaction est annulée en entier');
        $this->actingAs($admin)->postJson('/api/v1/imports/simulate', ['type' => 'inconnu', 'file' => UploadedFile::fake()->createWithContent('x.csv', $csv)])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_role_assignments_refuse_duplicates_and_repeated_revocations(): void
    {
        $admin = $this->userWith('admin_fonc', 'DSI');
        $target = $this->userWith('agent', 'DRH');
        $payload = ['user_id' => $target->id, 'role_code' => 'agent', 'structure_id' => $target->structure_id, 'reason' => 'Nouvelle mission'];
        $assignment = $this->actingAs($admin)->postJson('/api/v1/role-assignments', $payload)->assertCreated()->json('data.id');
        $this->actingAs($admin)->postJson('/api/v1/role-assignments', $payload)->assertStatus(409)->assertJsonPath('code', 'ASSIGNMENT_EXISTS');
        $this->actingAs($admin)->postJson("/api/v1/role-assignments/{$assignment}/revoke", ['reason' => 'Fin de mission'])->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/role-assignments/{$assignment}/revoke", ['reason' => 'Fin de mission'])->assertStatus(409);

        $disabled = User::query()->create(['name' => 'Compte désactivé', 'email' => uniqid('d').'@test.local', 'password' => 'Secret-Passw0rd!', 'status' => 'disabled']);
        $this->actingAs($admin)->postJson('/api/v1/role-assignments', ['user_id' => $disabled->id, 'role_code' => 'agent', 'reason' => 'Essai sur compte désactivé'])->assertUnprocessable();
        $this->assertSame(0, RoleAssignment::query()->where('user_id', $disabled->id)->count());
    }

    public function test_global_search_hides_screens_outside_the_scope(): void
    {
        $inside = $this->screen('ECR-RECH-DANS');
        $outside = $this->screen('ECR-RECH-HORS', false);
        $user = User::query()->create(['name' => 'Superviseur de zone', 'email' => uniqid('s').'@test.local', 'password' => 'Secret-Passw0rd!', 'structure_id' => $this->structure('DSI')->id]);
        RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', 'superviseur')->value('id'), 'zone_id' => $this->place()['zone_public']->id, 'starts_at' => now()->subDay()]);
        $labels = collect($this->actingAs($user)->getJson('/api/v1/search?q=ECR-RECH')->assertOk()->json('data'))->pluck('label')->all();
        $this->assertContains($inside->code, $labels);
        $this->assertNotContains($outside->code, $labels);
    }

    public function test_trashed_documents_and_media_are_frozen_until_restored(): void
    {
        $owner = $this->userWith('contributeur', 'DSI', null, 'DSI');
        $asset = Asset::query()->create(['reference' => 'MED-'.uniqid(), 'title' => 'f', 'kind' => 'pdf', 'owner_structure_id' => $owner->structure_id, 'classification' => 'interne', 'status' => 'trashed']);
        $version = $asset->versions()->create(['version' => 1, 'storage_key' => 'assets/f.pdf', 'original_name' => 'f.pdf', 'extension' => 'pdf', 'size' => 10, 'mime' => 'application/pdf', 'sha256' => str_repeat('a', 64), 'scan_status' => 'ready']);
        $document = Document::query()->create(['reference' => 'DOC-'.uniqid(), 'title' => 'Note', 'owner_structure_id' => $owner->structure_id, 'author_id' => $owner->id, 'classification' => 'interne', 'status' => 'trashed']);
        $document->forceFill(['current_version_id' => $document->versions()->create(['version' => 1, 'asset_version_id' => $version->id])->id])->save();

        $this->actingAs($owner)->postJson("/api/v1/documents/{$document->id}/publish")->assertStatus(409)->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->actingAs($owner)->patchJson("/api/v1/documents/{$document->id}", ['title' => 'Nouveau titre'], ['If-Match' => (string) $document->version])->assertStatus(409);
        $this->actingAs($owner)->postJson("/api/v1/documents/{$document->id}/unpublish", ['reason' => 'Sans objet ici'])->assertStatus(409);
        $this->actingAs($owner)->patchJson("/api/v1/assets/{$asset->id}", ['title' => 'Renommé'], ['If-Match' => (string) $asset->version])->assertStatus(409);

        $this->actingAs($owner)->postJson("/api/v1/documents/{$document->id}/restore", ['reason' => 'Mis à la corbeille par erreur'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->actingAs($owner)->postJson("/api/v1/documents/{$document->id}/restore", ['reason' => 'Deuxième essai'])->assertStatus(409);
    }

    public function test_fallback_and_archiving_respect_the_content_lifecycle(): void
    {
        $author = $this->userWith('contributeur');
        $content = Content::query()->create(['reference' => 'CNT-'.uniqid(), 'title' => 'Avis', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'public', 'expires_at' => now()->addMonths(18), 'status' => 'archived']);
        $version = ContentVersion::query()->create(['content_id' => $content->id, 'lang' => 'fr', 'revision' => 1, 'payload' => ['title' => 'Avis', 'media' => []], 'classification' => 'public', 'author_id' => $author->id, 'approved_by' => $this->userWith('validateur', 'COM', ['fr'])->id, 'approved_at' => now(), 'payload_hash' => str_repeat('b', 64)]);
        $diffuser = $this->userWith('diffuseur');
        $this->actingAs($diffuser)->postJson('/api/v1/fallbacks', ['zone_id' => $this->place()['zone_public']->id, 'content_version_id' => $version->id, 'valid_until' => now()->addMonth()->toIso8601String()])
            ->assertUnprocessable()->assertJsonPath('code', 'FALLBACK_NOT_ELIGIBLE');
        $this->assertSame(0, FallbackContent::query()->count());

        $this->actingAs($author)->postJson("/api/v1/contents/{$content->id}/archive", ['reason' => 'Deuxième archivage'])->assertStatus(409);
    }

    public function test_visits_only_link_eligible_meetings_and_cancellation_needs_a_reason(): void
    {
        $organizer = $this->userWith('agent', 'DRH');
        $room = $this->room();
        $confidential = $this->actingAs($organizer)->postJson('/api/v1/reservations', $this->booking($room->id, 3, ['confidentiality' => true]))->assertCreated()->json('data.occurrences.0.id');
        $protocol = $this->userWith('admin_fonc', 'DSI');
        $this->grantPermissionRole($protocol);
        $this->actingAs($protocol)->postJson('/api/v1/visits', ['name' => 'Délégation', 'occurrence_id' => $confidential])->assertUnprocessable()->assertJsonPath('code', 'OCCURRENCE_NOT_ELIGIBLE');

        $visit = $this->actingAs($protocol)->postJson('/api/v1/visits', ['name' => 'Délégation'])->assertCreated()->json('data.id');
        $this->actingAs($protocol)->patchJson("/api/v1/visits/{$visit}", ['status' => 'cancelled'])->assertUnprocessable()->assertJsonPath('code', 'REASON_REQUIRED');
        $this->actingAs($protocol)->patchJson("/api/v1/visits/{$visit}", ['status' => 'done'])->assertStatus(409);
        $this->actingAs($protocol)->patchJson("/api/v1/visits/{$visit}", ['status' => 'cancelled', 'reason' => 'Visite reportée sine die'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($protocol)->patchJson("/api/v1/visits/{$visit}", ['name' => 'Autre'])->assertStatus(409);
        $this->assertSame(1, $this->actingAs($protocol)->getJson('/api/v1/visits?status=cancelled')->assertOk()->json('meta.total'));
    }

    /**
     * Les visites relèvent du droit « visits.manage » : attribué ici par le rôle qui le porte dans la configuration.
     */
    private function grantPermissionRole(User $user): void
    {
        $role = collect(config('saie.roles'))->filter(fn (array $definition) => in_array('visits.manage', $definition[1], true))->keys()->first();
        $this->grantRole($user, $role);
    }
}
