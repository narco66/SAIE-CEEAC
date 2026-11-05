<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoleExtension;
use App\Models\User;
use App\Support\BusinessException;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rôles, délégations datées et pouvoirs étendus nominatifs (V35, HAB-01 à HAB-03).
 */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('roles.manage', 'users.manage');
        $roles = Role::query()->with('permissions:id,code')->orderBy('name')->get();
        $permissions = Permission::query()->orderBy('group')->orderBy('name')->get(['id', 'code', 'name', 'group']);
        $powers = collect(config('saie.powers'))->map(fn ($p, $k) => ['code' => $k, 'label' => $p['label'], 'role' => $p['role'], 'permissions' => $p['permissions']])->values();
        $holders = RoleExtension::query()->with('user:id,name')->whereNull('revoked_at')->where('ends_at', '>', now())->get()
            ->groupBy('power')->map(fn ($items) => $items->map(fn ($e) => ['user' => $e->user->name, 'ends_at' => Present::date($e->ends_at), 'scope' => $e->scope])->values());

        return response()->json(['data' => [
            'roles' => $roles->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'description' => $r->description, 'permissions' => $r->permissions->pluck('code'),
                'users' => RoleAssignment::query()->where('role_id', $r->id)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->distinct()->count('user_id')]),
            'permissions' => $permissions, 'powers' => $powers, 'power_holders' => $holders,
        ]]);
    }

    public function permissions(Request $request, Role $role): JsonResponse
    {
        $this->authorizePermission('roles.manage');
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', 'exists:permissions,code'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        $before = $role->permissions()->pluck('code')->all();
        $powerOnly = collect(config('saie.powers'))->flatMap(fn ($p) => $p['permissions'])->all();
        if (! $this->access()->hasRole($this->user(), 'admin_fonc') && array_diff($data['permissions'], $this->access()->permissions($this->user())) !== []) {
            $this->deny('Délégation au-delà de vos propres droits');
        }
        $role->permissions()->sync(Permission::query()->whereIn('code', $data['permissions'])->pluck('id'));
        $this->access()->flush();
        $this->audit()->log('role.permissions_changed', $role, $data['reason'], ['permissions' => $before], ['permissions' => $data['permissions'], 'power_bound' => array_values(array_intersect($data['permissions'], $powerOnly))]);

        return $this->index();
    }

    public function assign(Request $request): JsonResponse
    {
        $this->authorizePermission('roles.manage');
        $data = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'], 'role_code' => ['required', 'exists:roles,code'],
            'structure_id' => ['nullable', 'uuid', 'exists:structures,id'], 'site_id' => ['nullable', 'uuid', 'exists:sites,id'], 'zone_id' => ['nullable', 'uuid', 'exists:display_zones,id'],
            'languages' => ['nullable', 'array'], 'languages.*' => ['in:fr,en,pt,es'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ]);
        $role = Role::query()->where('code', $data['role_code'])->firstOrFail();
        $this->preventEscalation($role);
        $assignment = RoleAssignment::query()->create([
            'user_id' => $data['user_id'], 'role_id' => $role->id, 'structure_id' => $data['structure_id'] ?? null, 'site_id' => $data['site_id'] ?? null, 'zone_id' => $data['zone_id'] ?? null,
            'languages' => $data['languages'] ?? null, 'starts_at' => $data['starts_at'] ?? now(), 'ends_at' => $data['ends_at'] ?? null, 'granted_by' => $this->user()->id, 'reason' => $data['reason'],
        ]);
        $this->access()->flush();
        $this->audit()->log('role.assigned', User::query()->find($data['user_id']), $data['reason'], after: ['role' => $role->code, 'structure' => $data['structure_id'] ?? null, 'ends_at' => $data['ends_at'] ?? null]);

        return response()->json(['data' => $assignment], 201);
    }

    public function revokeAssignment(Request $request, RoleAssignment $assignment): JsonResponse
    {
        $this->authorizePermission('roles.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $assignment->forceFill(['revoked_at' => now()])->save();
        $this->access()->flush();
        $this->audit()->log('role.revoked', $assignment->user, $data['reason'], after: ['role' => $assignment->role->code]);

        return response()->json(['data' => $assignment]);
    }

    /**
     * Pouvoir étendu nominatif avec début, fin et périmètre (HAB-03) ; il exige le rôle de rattachement.
     */
    public function grantPower(Request $request): JsonResponse
    {
        $this->authorizePermission('roles.manage');
        $data = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'], 'power' => ['required', 'in:urgence,modeles,prestations'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['required', 'date', 'after:now'], 'reason' => ['required', 'string', 'min:5', 'max:300'],
            'scope' => ['nullable', 'array'], 'scope.zone_ids' => ['nullable', 'array'], 'scope.zone_ids.*' => ['uuid', 'exists:display_zones,id'],
            'scope.service_types' => ['nullable', 'array'], 'scope.service_types.*' => ['string', 'exists:service_types,code'],
        ]);
        $user = User::query()->findOrFail($data['user_id']);
        $requiredRole = config("saie.powers.{$data['power']}.role");
        if (! $this->access()->hasRole($user, $requiredRole)) {
            throw new BusinessException('POWER_REQUIRES_ROLE', 'Ce pouvoir est une extension du rôle « '.config("saie.roles.$requiredRole.0").' » que l’utilisateur ne détient pas.', 422);
        }
        $extension = RoleExtension::query()->create(['user_id' => $user->id, 'power' => $data['power'], 'scope' => $data['scope'] ?? null, 'starts_at' => $data['starts_at'] ?? now(), 'ends_at' => $data['ends_at'], 'granted_by' => $this->user()->id, 'reason' => $data['reason']]);
        $this->access()->flush();
        $this->audit()->log('power.granted', $user, $data['reason'], after: ['power' => $data['power'], 'ends_at' => $data['ends_at'], 'scope' => $data['scope'] ?? null], power: $data['power']);

        return response()->json(['data' => $extension], 201);
    }

    public function revokePower(Request $request, RoleExtension $extension): JsonResponse
    {
        $this->authorizePermission('roles.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $extension->forceFill(['revoked_at' => now()])->save();
        $this->access()->flush();
        $this->audit()->log('power.revoked', $extension->user, $data['reason'], power: $extension->power);

        return response()->json(['data' => $extension]);
    }

    /**
     * Comparaison des droits effectifs (V35) : permissions issues des rôles actifs et des pouvoirs en cours de validité.
     */
    public function effective(User $user): JsonResponse
    {
        $this->authorizePermission('roles.manage', 'users.manage');
        $this->access()->flush($user);

        return response()->json(['data' => [
            'user' => Present::userRef($user), 'roles' => $this->access()->roleCodes($user), 'permissions' => $this->access()->permissions($user),
            'powers' => collect(['urgence', 'modeles', 'prestations'])->mapWithKeys(fn ($p) => [$p => $this->access()->hasPower($user, $p)]),
            'validation_languages' => $this->access()->validationLanguages($user),
        ]]);
    }

    private function preventEscalation(Role $role): void
    {
        if ($this->access()->hasRole($this->user(), 'admin_fonc')) {
            return;
        }
        $rolePermissions = DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->where('role_id', $role->id)->pluck('code')->all();
        if (array_diff($rolePermissions, $this->access()->permissions($this->user())) !== []) {
            $this->deny('Attribution d’un rôle excédant vos propres droits');
        }
    }
}
