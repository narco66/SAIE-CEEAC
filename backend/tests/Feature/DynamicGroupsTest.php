<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\ScreenGroup;
use App\Models\User;
use App\Services\Access;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Groupes d'écrans (§8) : groupes dynamiques recalculés (critères, état de connexion, archivage), réservés aux habilitations
 * sans restriction, statistiques et supervision des membres.
 */
class DynamicGroupsTest extends TestCase
{
    public function test_a_dynamic_group_follows_its_criteria_and_the_connection_state(): void
    {
        $hall = $this->screen('ECR-HALL');
        $palier = $this->screen('ECR-PALIER', false);
        [$device] = $this->pairedDevice($hall);
        $admin = $this->userWith('admin_tech', 'DSI');

        $id = $this->actingAs($admin)->postJson('/api/v1/screen-groups', ['code' => 'G-EN-LIGNE', 'name' => 'Écrans en ligne de la zone publique', 'type' => 'dynamic',
            'criteria' => ['zone_ids' => [$this->place()['zone_public']->id], 'network' => ['online']]])->assertCreated()->json('data.id');
        $this->assertSame([$hall->id], DB::table('group_members')->where('group_id', $id)->pluck('screen_id')->all());

        $device->forceFill(['last_seen_at' => now()->subHour()])->save();
        $this->artisan('saie:tick')->assertSuccessful();
        $this->assertSame(0, DB::table('group_members')->where('group_id', $id)->count(), 'recalculé quand l’état de connexion change');

        $this->actingAs($admin)->patchJson("/api/v1/screen-groups/$id", ['criteria' => ['floor_ids' => [$this->place()['floor']->id]]], ['If-Match' => '1'])->assertOk();
        $this->assertEqualsCanonicalizing([$hall->id, $palier->id], DB::table('group_members')->where('group_id', $id)->pluck('screen_id')->all());

        $palier->forceFill(['status' => 'archived'])->save();
        $this->artisan('saie:tick')->assertSuccessful();
        $this->assertSame([$hall->id], DB::table('group_members')->where('group_id', $id)->pluck('screen_id')->all(), 'un écran archivé quitte le groupe');
    }

    public function test_dynamic_members_cannot_be_set_by_hand_and_a_static_group_has_no_criteria(): void
    {
        $screen = $this->screen('ECR-HALL');
        $admin = $this->userWith('admin_tech', 'DSI');

        $this->actingAs($admin)->postJson('/api/v1/screen-groups', ['code' => 'G-DYN', 'name' => 'Dyn', 'type' => 'dynamic', 'criteria' => ['kinds' => ['display']], 'screen_ids' => [$screen->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('screen_ids');
        $this->actingAs($admin)->postJson('/api/v1/screen-groups', ['code' => 'G-STAT', 'name' => 'Stat', 'criteria' => ['kinds' => ['display']]])
            ->assertUnprocessable()->assertJsonValidationErrors('criteria');
    }

    public function test_only_an_unrestricted_manager_handles_dynamic_groups(): void
    {
        $user = User::query()->create(['name' => 'Diffuseur hall', 'email' => 'diff.hall@test.local', 'password' => 'Secret-Passw0rd!']);
        RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', 'diffuseur')->value('id'), 'zone_id' => $this->place()['zone_public']->id, 'starts_at' => now()->subDay()]);
        app(Access::class)->flush();

        $this->actingAs($user)->postJson('/api/v1/screen-groups', ['code' => 'G-TOUT', 'name' => 'Tout', 'type' => 'dynamic', 'criteria' => ['kinds' => ['display']]])->assertForbidden();
    }

    public function test_group_listing_reports_member_statistics(): void
    {
        $hall = $this->screen('ECR-HALL');
        $this->pairedDevice($hall);
        $palier = $this->screen('ECR-PALIER', false);
        $group = ScreenGroup::query()->create(['code' => 'G-RDC', 'name' => 'Rez-de-chaussée']);
        $group->screens()->attach([$hall->id, $palier->id]);

        $row = collect($this->actingAs($this->userWith('admin_tech', 'DSI'))->getJson('/api/v1/screen-groups')->assertOk()->json('data'))->firstWhere('code', 'G-RDC');
        $this->assertSame(['online' => 1, 'anomaly' => 0, 'unpaired' => 1], array_intersect_key($row['stats'], array_flip(['online', 'anomaly', 'unpaired']))); // indicateurs sur 7 jours testés dans DeviceFleetManagementTest
        $this->assertSame('static', $row['type']);
    }
}
