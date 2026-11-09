<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoleExtension;
use App\Models\UserNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * HAB-01, HAB-03 : un administrateur ne peut pas étendre ses propres droits — ni en s'attribuant un rôle ou un pouvoir,
 * ni en modifiant les permissions d'un rôle qu'il détient. Ces opérations restent possibles pour un autre administrateur.
 */
class PrivilegeEscalationTest extends TestCase
{
    public function test_administrator_cannot_assign_a_role_to_themselves_but_can_to_someone_else(): void
    {
        $admin = $this->userWith('admin_fonc');
        $colleague = $this->userWith('agent');

        $this->actingAs($admin)->postJson('/api/v1/role-assignments', ['user_id' => $admin->id, 'role_code' => 'admin_tech', 'reason' => 'Essai d’auto-attribution'])
            ->assertForbidden()->assertJsonPath('message', 'Un administrateur ne peut pas modifier ses propres droits : un autre administrateur doit le faire.');
        $this->assertSame(0, RoleAssignment::query()->where('user_id', $admin->id)->whereHas('role', fn ($q) => $q->where('code', 'admin_tech'))->count());

        $this->actingAs($admin)->postJson('/api/v1/role-assignments', ['user_id' => $colleague->id, 'role_code' => 'diffuseur', 'reason' => 'Renfort de la diffusion'])->assertCreated();
    }

    public function test_administrator_cannot_grant_an_extended_power_to_themselves(): void
    {
        $admin = $this->userWith('admin_fonc');

        $this->actingAs($admin)->postJson('/api/v1/role-extensions', ['user_id' => $admin->id, 'power' => 'modeles', 'ends_at' => now()->addMonth()->toIso8601String(), 'reason' => 'Essai d’auto-attribution'])
            ->assertForbidden();
        $this->assertSame(0, RoleExtension::query()->where('user_id', $admin->id)->count());
    }

    public function test_escalation_without_backup_alerts_only_current_functional_administrators(): void
    {
        $current = $this->userWith('admin_fonc');
        $revoked = $this->userWith('admin_fonc');
        RoleAssignment::query()->where('user_id', $revoked->id)->update(['revoked_at' => now()->subDay()]);
        $expired = $this->userWith('admin_fonc');
        RoleAssignment::query()->where('user_id', $expired->id)->update(['starts_at' => now()->subDays(30), 'ends_at' => now()->subDay()]);
        $validator = $this->userWith('validateur', 'COM', ['fr']);
        Approval::query()->create(['object_type' => 'content', 'object_id' => (string) Str::uuid(), 'lang' => 'fr', 'title' => 'Note sans suppléant', 'assignee_id' => $validator->id, 'due_at' => now()->subHour(), 'status' => 'open']);

        $this->artisan('saie:tick')->assertSuccessful();

        $this->assertSame([$current->id], UserNotification::query()->where('event', 'approval.no_backup')->pluck('user_id')->all());
    }

    public function test_administrator_cannot_change_the_permissions_of_a_role_they_hold(): void
    {
        $admin = $this->userWith('admin_fonc');
        $role = Role::query()->where('code', 'admin_fonc')->firstOrFail();
        $before = $role->permissions()->pluck('code')->sort()->values()->all();

        $this->actingAs($admin)->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => Permission::query()->pluck('code')->all(), 'reason' => 'Essai d’élargissement'])
            ->assertForbidden();
        $this->assertSame($before, $role->permissions()->pluck('code')->sort()->values()->all());

        $agentRole = Role::query()->where('code', 'agent')->firstOrFail();
        $this->actingAs($admin)->putJson("/api/v1/roles/{$agentRole->id}/permissions", ['permissions' => $agentRole->permissions()->pluck('code')->all(), 'reason' => 'Relecture des droits'])->assertOk();
    }
}
