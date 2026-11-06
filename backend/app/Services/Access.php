<?php

namespace App\Services;

use App\Models\RoleAssignment;
use App\Models\RoleExtension;
use App\Models\Structure;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calcule les droits effectifs d'un utilisateur : rôles actifs (datés), périmètres et pouvoirs étendus.
 * Toute permission absente est refusée par défaut (HAB-01).
 */
class Access
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function flush(?User $user = null): void
    {
        if ($user) {
            unset($this->cache[$user->id]);
        } else {
            $this->cache = [];
        }
    }

    /**
     * @return Collection<int, RoleAssignment>
     */
    public function assignments(User $user): Collection
    {
        return $this->remember($user, 'assignments', fn () => RoleAssignment::query()
            ->with('role')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get());
    }

    /**
     * @return Collection<int, RoleExtension>
     */
    public function powers(User $user): Collection
    {
        return $this->remember($user, 'powers', fn () => RoleExtension::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->get());
    }

    /**
     * @return list<string>
     */
    public function roleCodes(User $user): array
    {
        return $this->assignments($user)->pluck('role.code')->unique()->values()->all();
    }

    /**
     * SEC-01 : second facteur obligatoire pour les rôles sensibles et pour tout titulaire d'un pouvoir étendu en cours.
     */
    public function requiresMfaEnrollment(User $user): bool
    {
        if (! config('saie.security.mfa_enforced') || $user->mfa_enabled_at) {
            return false;
        }

        return array_intersect($this->roleCodes($user), config('saie.security.mfa_roles')) !== [] || $this->powers($user)->isNotEmpty();
    }

    public function hasRole(User $user, string $role): bool
    {
        return in_array($role, $this->roleCodes($user), true);
    }

    public function hasPower(User $user, string $power): bool
    {
        $required = config("saie.powers.$power.role");

        return $this->powers($user)->contains('power', $power)
            && ($required === null || $this->hasRole($user, $required));
    }

    /**
     * @return list<string>
     */
    public function permissions(User $user): array
    {
        return $this->remember($user, 'permissions', function () use ($user): array {
            if (! $user->isActive()) {
                return [];
            }
            $roleIds = $this->assignments($user)->pluck('role_id')->unique()->all();
            if ($roleIds === []) {
                return [];
            }
            $permissions = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->whereIn('permission_role.role_id', $roleIds)
                ->distinct()
                ->pluck('permissions.code')
                ->all();

            foreach (config('saie.powers') as $power => $definition) {
                if (! $this->hasPower($user, $power)) {
                    $permissions = array_values(array_diff($permissions, $definition['permissions']));
                }
            }

            return array_values(array_unique($permissions));
        });
    }

    public function can(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissions($user), true);
    }

    /**
     * Structures accessibles pour une permission : null signifie « toutes » (assignation sans périmètre organique).
     *
     * @return list<string>|null
     */
    public function structureScope(User $user, string $permission): ?array
    {
        if (! $this->can($user, $permission)) {
            return [];
        }
        $roleIds = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.code', $permission)
            ->pluck('permission_role.role_id')
            ->all();
        $relevant = $this->assignments($user)->whereIn('role_id', $roleIds);
        if ($relevant->contains(fn (RoleAssignment $a): bool => $a->structure_id === null)) {
            return null;
        }
        $ids = $relevant->pluck('structure_id')->filter()->unique()->values()->all();

        return $this->withDescendants($ids);
    }

    /**
     * Langues que l'utilisateur peut valider (PUB-08). Une assignation sans langue explicite couvre toutes les langues actives.
     *
     * @return list<string>
     */
    public function validationLanguages(User $user): array
    {
        if (! $this->can($user, 'contents.validate')) {
            return [];
        }
        $langs = [];
        foreach ($this->assignments($user) as $assignment) {
            if ($assignment->role?->code !== 'validateur') {
                continue;
            }
            $langs = array_merge($langs, $assignment->languages ?: ['fr', 'en', 'pt', 'es']);
        }

        return array_values(array_unique($langs));
    }

    /**
     * Périmètre du pouvoir étendu (zones pour « urgence », types de prestation pour « prestations »).
     *
     * @return array<string, mixed>|null null = sans restriction
     */
    /**
     * HAB-01 : écrans couverts par les affectations qui donnent la permission, selon leur site et leur zone.
     * Null : affectation sans site ni zone (tout le parc). Une affectation avec site et zone couvre leur intersection.
     *
     * @return list<string>|null
     */
    public function screenScope(User $user, string $permission): ?array
    {
        if (! $this->can($user, $permission)) {
            return [];
        }

        return $this->remember($user, "screens:$permission", function () use ($user, $permission): ?array {
            $roleIds = DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permissions.code', $permission)->pluck('permission_role.role_id')->all();
            $relevant = $this->assignments($user)->whereIn('role_id', $roleIds);
            if ($relevant->contains(fn (RoleAssignment $a): bool => $a->site_id === null && $a->zone_id === null)) {
                return null;
            }

            return DB::table('screens')->join('locations', 'locations.id', '=', 'screens.location_id')
                ->join('floors', 'floors.id', '=', 'locations.floor_id')->join('buildings', 'buildings.id', '=', 'floors.building_id')
                ->where(function ($q) use ($relevant): void {
                    $q->whereRaw('1 = 0');
                    foreach ($relevant as $a) {
                        $q->orWhere(fn ($w) => $w->when($a->zone_id, fn ($z) => $z->where('locations.zone_id', $a->zone_id))
                            ->when($a->site_id, fn ($s) => $s->where('buildings.site_id', $a->site_id)));
                    }
                })->pluck('screens.id')->all();
        });
    }

    public function powerScope(User $user, string $power): ?array
    {
        $extensions = $this->powers($user)->where('power', $power);
        if ($extensions->isEmpty()) {
            return [];
        }
        if ($extensions->contains(fn (RoleExtension $e): bool => empty($e->scope) || ! empty($e->scope['all']))) {
            return null;
        }

        return $extensions->reduce(fn (array $carry, RoleExtension $e): array => array_merge_recursive($carry, $e->scope ?? []), []);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function withDescendants(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $all = $ids;
        $frontier = $ids;
        while ($frontier !== []) {
            $frontier = Structure::query()->whereIn('parent_id', $frontier)->whereNotIn('id', $all)->pluck('id')->all();
            $all = array_merge($all, $frontier);
        }

        return array_values(array_unique($all));
    }

    /**
     * Rôle principal présenté dans l'interface et les journaux.
     */
    public function primaryRoleLabel(User $user): string
    {
        $names = $this->assignments($user)->pluck('role.name')->unique()->values();

        return $names->isEmpty() ? 'Aucun rôle' : $names->implode(' · ');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function remember(User $user, string $key, callable $callback): mixed
    {
        if (! array_key_exists($key, $this->cache[$user->id] ?? [])) {
            $this->cache[$user->id][$key] = $callback();
        }

        return $this->cache[$user->id][$key];
    }
}
