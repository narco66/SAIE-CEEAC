<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ScreenProfile;
use App\Support\BusinessException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Profils de configuration (§7.3) : création, modification et désactivation, avec le nombre d'écrans et de groupes qui les
 * utilisent. Un profil utilisé ne peut pas être désactivé.
 */
class ScreenProfileController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('screens.view', 'screens.manage');
        $usage = fn (string $table) => DB::table($table)->whereNotNull('profile_id')->groupBy('profile_id')->selectRaw('profile_id, count(*) as total')->pluck('total', 'profile_id');
        [$screens, $groups] = [$usage('screens'), $usage('screen_groups')];

        return response()->json([
            'data' => ScreenProfile::query()->orderBy('name')->get()->map(fn (ScreenProfile $p) => $this->present($p) + ['screens_count' => (int) ($screens[$p->id] ?? 0), 'groups_count' => (int) ($groups[$p->id] ?? 0)])->values(),
            'meta' => ['settings' => collect(ScreenProfile::SETTINGS)->map(fn ($s, $key) => ['key' => $key, 'label' => $s['label'], 'capability' => $s['capability']])->values()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $this->assertFleetWide();
        $data = $this->validated($request, null);
        $profile = ScreenProfile::query()->create($data + ['created_by' => $this->user()->id]);
        $this->audit()->log('screen_profile.created', $profile, after: $data);

        return response()->json(['data' => $this->present($profile->refresh())], 201);
    }

    public function update(Request $request, ScreenProfile $profile): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $this->assertFleetWide();
        $data = $this->validated($request, $profile);
        if (($data['is_active'] ?? true) === false && (DB::table('screens')->where('profile_id', $profile->id)->exists() || DB::table('screen_groups')->where('profile_id', $profile->id)->exists())) {
            throw new BusinessException('PROFILE_IN_USE', 'Profil utilisé par des écrans ou des groupes : retirez-le avant de le désactiver.', 409);
        }
        $before = $profile->only(array_keys($data));
        $profile->update($data + ['version' => $profile->version + 1]);
        $this->audit()->log('screen_profile.updated', $profile, $request->input('reason'), $before, $data);

        return response()->json(['data' => $this->present($profile->refresh())]);
    }

    /**
     * §14-9 : un profil peut s'appliquer à des écrans de tout le parc (et fixe le serveur de rattachement) : sa création et
     * sa modification sont réservées aux habilitations sans restriction de périmètre.
     */
    private function assertFleetWide(): void
    {
        if ($this->screenScopeFor('screens.manage') !== null) {
            $this->deny('Profil de configuration : habilitation limitée à un périmètre');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ScreenProfile $profile): array
    {
        $required = $profile ? 'sometimes' : 'required';
        $rules = ['code' => [$required, 'string', 'max:40', 'regex:/^[A-Z0-9-]+$/', Rule::unique('screen_profiles', 'code')->ignore($profile?->id)], 'name' => [$required, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'], 'is_active' => ['sometimes', 'boolean'], 'settings' => [$required, 'array']];
        foreach (ScreenProfile::SETTINGS as $key => $spec) {
            $rules["settings.$key"] = array_merge(['nullable'], $spec['rules']);
        }
        $rules['settings.languages.*'] = ['distinct', 'in:fr,en,pt,es'];
        // §7.2-c : un serveur hors de la liste autorisée (configuration SAIE_DEVICE_SERVERS) rendrait le lecteur orphelin.
        $rules['settings.server_url'][] = Rule::in(config('saie.device.allowed_servers'));
        $data = $request->validate($rules);
        if (isset($data['settings'])) {
            // Seuls les paramètres connus sont conservés, sans valeur vide : un paramètre absent garde le comportement par défaut.
            $data['settings'] = collect($data['settings'])->only(array_keys(ScreenProfile::SETTINGS))->reject(fn ($v) => $v === null)->all();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ScreenProfile $p): array
    {
        return ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'description' => $p->description, 'settings' => (object) ($p->settings ?? []), 'is_active' => $p->is_active, 'version' => $p->version];
    }
}
