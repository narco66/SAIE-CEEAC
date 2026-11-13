<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\ContentCategory;
use App\Models\DisplayZone;
use App\Models\Equipment;
use App\Models\Floor;
use App\Models\Holiday;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScreenCategory;
use App\Models\ServiceType;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Structure;
use App\Models\User;
use App\Services\Referentials;
use App\Support\BusinessException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Référentiels (V36). Les objets utilisés sont désactivés plutôt que supprimés (REF-03).
 */
class ReferentialController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'institutions' => Institution::query()->orderBy('name')->get(),
            'screen_categories' => ScreenCategory::query()->orderBy('name')->get(),
            'sites' => Site::query()->orderBy('name')->get(),
            'buildings' => Building::query()->orderBy('name')->get(),
            'floors' => Floor::query()->with('building:id,name')->orderByDesc('level')->get(),
            'zones' => DisplayZone::query()->orderBy('name')->get(),
            // §6-h : nombre d'écrans en service par emplacement.
            'locations' => Location::query()->with(['floor:id,label,level', 'zone:id,name,code'])->withCount(['screens' => fn ($q) => $q->where('status', '!=', 'archived')])->orderBy('name')->get(),
            'structures' => Structure::query()->orderBy('name')->get(),
            'categories' => ContentCategory::query()->orderBy('name')->get(),
            'languages' => Language::query()->orderBy('sort')->get(),
            'service_types' => ServiceType::query()->orderBy('name')->get(),
            'holidays' => Holiday::query()->where('date', '>=', now()->subYear())->orderBy('date')->get(),
            'equipment' => Equipment::query()->orderBy('name')->get(),
            // Décision DSI : annuaire des comptes réservé aux gestionnaires quand « referentials.restricted » est activé.
            'users' => ! Setting::effective('referentials.restricted') || $this->canAny(['users.manage', 'roles.manage', 'referentials.manage', 'rooms.manage', 'screens.manage', 'incidents.manage', 'settings.manage'])
                ? User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'structure_id']) : [],
            'rooms' => Room::query()->orderBy('name')->get(['id', 'code', 'name', 'floor_id', 'location_id', 'capacity', 'status', 'approval_mode', 'prep_minutes', 'cleanup_minutes', 'open_from', 'open_to']),
            'defaults' => ['slide_duration_s' => (int) Setting::effective('display.default_slide_s')],
            // PAR-04 : images publiques et saines proposées comme plans de niveau (réservé aux gestionnaires des référentiels).
            'plan_images' => $this->can('referentials.manage') ? DB::table('assets')->join('asset_versions', 'asset_versions.id', '=', 'assets.current_version_id')
                ->where('assets.kind', 'image')->where('assets.classification', 'public')->where('assets.status', 'ready')->where('asset_versions.scan_status', 'ready')
                ->orderBy('assets.title')->limit(200)->get(['asset_versions.id', 'assets.title as name']) : [],
        ]]);
    }

    /**
     * API-04, §4 : les salles portent des règles métier (gestionnaire, version attendue, réservations touchées) appliquées
     * par le module Salles ; la saisie générique des référentiels ne doit pas les contourner.
     */
    private function refuseRuledType(string $type): void
    {
        if ($type === 'rooms') {
            throw new BusinessException('USE_ROOMS_MODULE', 'Les salles se créent et se modifient depuis le module « Salles », qui contrôle leurs réservations.', 409);
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $this->authorizePermission('referentials.manage');
        $this->refuseRuledType($type);
        $definition = Referentials::definition($type);
        $data = Validator::make($request->all(), ($definition['rules'])(null))->validate();
        $this->assertConsistent($type, $data, null);
        $model = $definition['model']::query()->create($data);
        $this->audit()->log("referential.$type.created", $model, after: $data);

        return response()->json(['data' => $model], 201);
    }

    public function update(Request $request, string $type, string $id): JsonResponse
    {
        $this->authorizePermission('referentials.manage');
        $this->refuseRuledType($type);
        $definition = Referentials::definition($type);
        $model = $definition['model']::query()->findOrFail($id);
        $data = Validator::make($request->all(), ($definition['rules'])($model))->validate();
        $this->assertConsistent($type, $data, $model);
        $usage = ($data['is_active'] ?? null) === false && $model->getAttribute('is_active') ? $this->usage($type, $model) : [];
        $before = $model->only(array_keys($data));
        $model->update($data);
        $this->audit()->log("referential.$type.updated", $model, before: $before, after: $data + ($usage !== [] ? ['dependants_still_active' => $usage] : []));

        // V36 : la désactivation n'efface rien ; les dépendants encore actifs sont signalés pour être traités.
        return response()->json(['data' => $model->refresh(), 'meta' => ['usage' => (object) $usage]]);
    }

    /**
     * Jours non ouvrés : une date saisie par erreur peut être retirée (aucun objet ne la référence).
     */
    public function destroyHoliday(Request $request, string $id): JsonResponse
    {
        $this->authorizePermission('referentials.manage');
        $holiday = Holiday::query()->findOrFail($id);
        $this->audit()->log('referential.holidays.deleted', $holiday, $request->input('reason'), before: $holiday->only(['date', 'label']));
        $holiday->delete();

        return response()->json(null, 204);
    }

    /**
     * Contrôles qu'une règle de validation par champ ne peut pas exprimer (REF-01, §16, V36) :
     * unicité du code dans son parent, rattachement à un parent actif, hiérarchie des structures sans cycle.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertConsistent(string $type, array $data, ?Model $model): void
    {
        $value = fn (string $field): mixed => array_key_exists($field, $data) ? $data[$field] : $model?->getAttribute($field);
        $errors = [];
        $scoped = ['buildings' => ['site_id', ['code']], 'floors' => ['building_id', ['code', 'level']], 'locations' => ['floor_id', ['code']]];
        if (isset($scoped[$type])) {
            [$parent, $fields] = $scoped[$type];
            $class = Referentials::definition($type)['model'];
            foreach ($fields as $field) {
                if (! array_key_exists($field, $data) && ! array_key_exists($parent, $data)) {
                    continue;
                }
                $taken = $class::query()->where($parent, $value($parent))->where($field, $value($field))->when($model, fn ($q) => $q->whereKeyNot($model->getKey()))->exists();
                if ($taken) {
                    $errors[$field] = $field === 'level' ? 'Ce bâtiment a déjà un niveau à cette hauteur.' : 'Ce code est déjà utilisé dans le même rattachement.';
                }
            }
        }
        $parents = ['site_id' => 'sites', 'building_id' => 'buildings', 'floor_id' => 'floors', 'zone_id' => 'display_zones', 'institution_id' => 'institutions', 'parent_id' => 'structures'];
        foreach ($parents as $field => $table) {
            $new = $data[$field] ?? null;
            if ($new !== null && $new !== $model?->getAttribute($field) && ! DB::table($table)->where('id', $new)->where('is_active', true)->exists()) {
                $errors[$field] = 'Cet élément de rattachement est désactivé : réactivez-le ou choisissez-en un autre.';
            }
        }
        if ($type === 'structures' && ($parentId = $data['parent_id'] ?? null) && $model) {
            $cursor = $parentId;
            for ($depth = 0; $cursor && $depth < 50; $depth++) {
                if ($cursor === $model->getKey()) {
                    $errors['parent_id'] = 'Une structure ne peut pas être rattachée à elle-même ni à l’une de ses sous-structures.';
                    break;
                }
                $cursor = Structure::query()->whereKey($cursor)->value('parent_id');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Dépendants encore actifs d'un élément qu'on désactive.
     *
     * @return array<string, int>
     */
    private function usage(string $type, Model $model): array
    {
        $id = $model->getKey();
        $counts = match ($type) {
            'sites' => ['bâtiments' => Building::query()->where('site_id', $id)->where('is_active', true)->count()],
            'buildings' => ['niveaux' => Floor::query()->where('building_id', $id)->where('is_active', true)->count()],
            'floors' => ['emplacements' => Location::query()->where('floor_id', $id)->where('is_active', true)->count(), 'salles' => Room::query()->where('floor_id', $id)->where('status', 'active')->count()],
            'zones' => ['emplacements' => Location::query()->where('zone_id', $id)->where('is_active', true)->count()],
            'locations' => ['écrans' => DB::table('screens')->where('location_id', $id)->where('status', '!=', 'archived')->count()],
            'structures' => ['sous-structures' => Structure::query()->where('parent_id', $id)->where('is_active', true)->count(), 'comptes' => User::query()->where('structure_id', $id)->where('status', 'active')->count()],
            'equipment' => ['salles' => DB::table('room_equipment')->where('equipment_id', $id)->count()],
            default => [],
        };

        return array_filter($counts);
    }
}
