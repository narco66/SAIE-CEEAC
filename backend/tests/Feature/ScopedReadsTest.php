<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Incident;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\ScreenGroup;
use App\Models\User;
use App\Services\Access;
use App\Services\ExportService;
use Tests\TestCase;

/**
 * HAB-01 (§14) : lectures encore ouvertes relevées par le contrôle final — aperçu de programme, liste des groupes, suivi
 * de diffusion — et rapport d'incidents, bornés au périmètre de l'habilitation.
 */
class ScopedReadsTest extends TestCase
{
    private function zoneUser(string $role): User
    {
        $user = User::query()->create(['name' => 'Agent de zone', 'email' => uniqid('zone').'@test.local', 'password' => 'Secret-Passw0rd!', 'structure_id' => $this->structure('COM')->id]);
        RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', $role)->value('id'), 'zone_id' => $this->place()['zone_public']->id, 'starts_at' => now()->subDay()]);
        app(Access::class)->flush();

        return $user;
    }

    public function test_programme_preview_groups_and_delivery_are_bounded_to_the_zone(): void
    {
        $hall = $this->screen('ECR-HALL');
        $palier = $this->screen('ECR-PALIER', false);
        ScreenGroup::query()->create(['code' => 'G-HALL', 'name' => 'Hall'])->screens()->attach($hall->id);
        ScreenGroup::query()->create(['code' => 'G-MIXTE', 'name' => 'Mixte'])->screens()->attach([$hall->id, $palier->id]);
        ScreenGroup::query()->create(['code' => 'G-AUTRE-VIDE', 'name' => 'Vide autre direction', 'structure_id' => $this->structure('DSI')->id]);
        $diffuser = $this->zoneUser('diffuseur');

        $this->actingAs($diffuser)->getJson("/api/v1/screens/{$hall->id}/preview")->assertOk();
        $this->actingAs($diffuser)->getJson("/api/v1/screens/{$palier->id}/preview")->assertForbidden();
        $this->actingAs($diffuser)->getJson("/api/v1/program?screen_id={$palier->id}")->assertForbidden();
        $this->assertSame(['G-HALL'], collect($this->actingAs($diffuser)->getJson('/api/v1/screen-groups')->assertOk()->json('data'))->pluck('code')->all());

        $campaign = Campaign::query()->create(['reference' => 'CMP-T1', 'name' => 'Niveau', 'status' => 'active', 'priority' => 50, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'created_by' => $diffuser->id, 'published_at' => now()]);
        $campaign->targets()->create(['target_type' => 'floor', 'target_id' => $this->place()['floor']->id]);
        $this->assertSame(['ECR-HALL'], collect($this->actingAs($diffuser)->getJson("/api/v1/campaigns/{$campaign->id}/delivery")->assertOk()->json('data.rows'))->pluck('screen.code')->all());
        $this->assertCount(2, $this->actingAs($this->userWith('diffuseur', 'COM'))->getJson("/api/v1/campaigns/{$campaign->id}/delivery")->json('data.rows'), 'sans restriction : tout le niveau');
    }

    public function test_the_incident_report_is_bounded_and_carries_the_processing_columns(): void
    {
        $hall = $this->screen('ECR-HALL');
        $palier = $this->screen('ECR-PALIER', false);
        foreach ([[$hall, 'INC-H'], [$palier, 'INC-P']] as [$screen, $ref]) {
            Incident::query()->create(['reference' => $ref, 'screen_id' => $screen->id, 'kind' => 'manual', 'category' => 'panel', 'severity' => 'minor', 'status' => 'resolved', 'title' => 'Dalle',
                'detected_at' => now()->subHours(2), 'resolved_at' => now(), 'diagnosis' => 'Câble HDMI défectueux', 'resolution' => 'Câble remplacé']);
        }

        $report = app(ExportService::class)->build('incident_report', [], $this->zoneUser('technicien'));
        $table = $report['sections'][0]['table'];
        $this->assertSame(['INC-H'], array_column($table['rows'], 0));
        $row = array_combine($table['headers'], $table['rows'][0]);
        $this->assertSame(['Dalle', 'Câble HDMI défectueux', 'Câble remplacé'], [$row['Catégorie'], $row['Diagnostic'], $row['Résultat du traitement']]);
    }
}
