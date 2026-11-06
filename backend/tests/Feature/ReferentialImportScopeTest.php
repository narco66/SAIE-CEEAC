<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Location;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * REF-03 : un code d'emplacement ou de niveau n'est unique que dans son parent ; l'import en tient compte.
 */
class ReferentialImportScopeTest extends TestCase
{
    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('locations.csv', $content);
    }

    public function test_same_location_code_on_two_floors_is_imported_and_reimported_without_moving(): void
    {
        $admin = $this->userWith('admin_fonc', 'SG');
        $place = $this->place();
        Floor::query()->create(['building_id' => $place['building']->id, 'code' => 'E1', 'label' => 'Étage 1', 'level' => 1]);
        Floor::query()->create(['building_id' => $place['building']->id, 'code' => 'E2', 'label' => 'Étage 2', 'level' => 2]);
        $csv = "code;name;floor_code;zone_code;is_active\nPALIER-N;Palier nord;E1;Z-INT;1\nPALIER-N;Palier nord;E2;Z-INT;1\n";

        $first = $this->actingAs($admin)->post('/api/v1/imports/commit', ['type' => 'locations', 'file' => $this->csv($csv)])->assertOk();
        $this->assertSame(2, $first->json('data.summary.created'), json_encode($first->json('data.rows')));

        $second = $this->actingAs($admin)->post('/api/v1/imports/commit', ['type' => 'locations', 'file' => $this->csv($csv)])->assertOk();
        $this->assertSame(2, $second->json('data.summary.unchanged'));
        $this->assertSame(2, Location::query()->where('code', 'PALIER-N')->count());
    }

    public function test_floor_code_shared_by_two_buildings_is_reported_as_ambiguous_unless_building_is_given(): void
    {
        $admin = $this->userWith('admin_fonc', 'SG');
        $place = $this->place();
        $annex = Building::query()->create(['site_id' => $place['site']->id, 'code' => 'ANNEXE', 'name' => 'Annexe']);
        Floor::query()->create(['building_id' => $annex->id, 'code' => 'RDC', 'label' => 'Rez-de-chaussée', 'level' => 0]);

        $ambiguous = $this->actingAs($admin)->post('/api/v1/imports/simulate', ['type' => 'locations', 'file' => $this->csv("code;name;floor_code;zone_code\nACCUEIL-2;Accueil;RDC;Z-PUB\n")])->assertOk();
        $this->assertSame(1, $ambiguous->json('data.summary.invalid'));
        $this->assertStringContainsString('ambiguë', implode(' ', $ambiguous->json('data.rows.0.errors')));

        $explicit = $this->actingAs($admin)->post('/api/v1/imports/commit', ['type' => 'locations', 'file' => $this->csv("code;name;building_code;floor_code;zone_code\nACCUEIL-2;Accueil;ANNEXE;RDC;Z-PUB\n")])->assertOk();
        $this->assertSame(1, $explicit->json('data.summary.created'));
        $this->assertSame($annex->id, Location::query()->where('code', 'ACCUEIL-2')->firstOrFail()->floor->building_id);
    }
}
