<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\ScreenGroup;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * API-04 : salles, écrans, groupes, documents, médias et comptes se modifient avec la version lue ;
 * une modification sans version est refusée (428), une version périmée aussi (409), sans rien écraser.
 */
class VersionedEditingTest extends TestCase
{
    public function test_room_edition_requires_the_read_version(): void
    {
        $room = $this->room(['name' => 'Salle A']);
        $this->assertVersionedPatch($this->userWith('admin_fonc'), "/api/v1/rooms/{$room->id}", ['name' => 'Salle B'], 'name', 'Salle B');
    }

    public function test_screen_edition_requires_the_read_version(): void
    {
        $screen = $this->screen('ECR-V');
        $this->assertVersionedPatch($this->userWith('admin_tech', 'DSI'), "/api/v1/screens/{$screen->id}", ['name' => 'Hall nord'], 'name', 'Hall nord');
    }

    public function test_screen_group_edition_requires_the_read_version(): void
    {
        $group = ScreenGroup::query()->create(['code' => 'GRP-V', 'name' => 'Accueil']);
        $this->assertVersionedPatch($this->userWith('admin_tech', 'DSI'), "/api/v1/screen-groups/{$group->id}", ['name' => 'Accueil principal'], 'name', 'Accueil principal');
    }

    public function test_document_edition_requires_the_read_version(): void
    {
        $owner = $this->userWith('contributeur', 'DSI', null, 'DSI');
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Note</p>');
        $pdf->render();
        $id = $this->actingAs($owner)->post('/api/v1/documents', ['file' => UploadedFile::fake()->createWithContent('note.pdf', (string) $pdf->output()), 'title' => 'Note', 'classification' => 'interne', 'audience_ids' => [$owner->structure_id]])->assertCreated()->json('data.id');

        $this->assertVersionedPatch($owner, "/api/v1/documents/$id", ['title' => 'Note révisée'], 'title', 'Note révisée');
    }

    public function test_asset_edition_requires_the_read_version(): void
    {
        $owner = $this->userWith('contributeur', 'DSI', null, 'DSI');
        $asset = Asset::query()->create(['reference' => 'MED-V', 'title' => 'Photo', 'kind' => 'image', 'owner_structure_id' => $owner->structure_id, 'classification' => 'interne', 'status' => 'ready', 'created_by' => $owner->id]);

        $this->assertVersionedPatch($owner, "/api/v1/assets/{$asset->id}", ['title' => 'Photo du hall'], 'title', 'Photo du hall');
    }

    public function test_account_edition_requires_the_read_version(): void
    {
        $agent = $this->userWith('agent');
        $this->assertVersionedPatch($this->userWith('admin_tech', 'DSI'), "/api/v1/users/{$agent->id}", ['title' => 'Chef de service'], 'title', 'Chef de service');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function assertVersionedPatch(User $actor, string $uri, array $changes, string $field, string $expected): void
    {
        $this->actingAs($actor)->patchJson($uri, $changes)->assertStatus(428)->assertJsonPath('code', 'VERSION_REQUIRED')->assertJsonPath('details.current_version', 1);
        $this->actingAs($actor)->patchJson($uri, $changes + ['expected_version' => 0])->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->actingAs($actor)->patchJson($uri, $changes, ['If-Match' => '1'])->assertOk()->assertJsonPath("data.$field", $expected)->assertJsonPath('data.version', 2);
        $this->actingAs($actor)->patchJson($uri, $changes + ['expected_version' => 1])->assertStatus(409)->assertJsonPath('details.current_version', 2);
    }
}
