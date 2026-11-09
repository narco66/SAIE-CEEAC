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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
        $definition = Referentials::definition($type);
        $data = Validator::make($request->all(), ($definition['rules'])(null))->validate();
        $model = $definition['model']::query()->create($data);
        $this->audit()->log("referential.$type.created", $model, after: $data);

        return response()->json(['data' => $model], 201);
    }

    public function update(Request $request, string $type, string $id): JsonResponse
    {
        $this->authorizePermission('referentials.manage');
        $definition = Referentials::definition($type);
        $model = $definition['model']::query()->findOrFail($id);
        $data = Validator::make($request->all(), ($definition['rules'])($model))->validate();
        $before = $model->only(array_keys($data));
        $model->update($data);
        $this->audit()->log("referential.$type.updated", $model, before: $before, after: $data);

        return response()->json(['data' => $model->refresh()]);
    }
}
