<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Device;
use App\Models\DeviceScreenshot;
use App\Models\PairingCode;
use App\Models\Screen;
use App\Models\ScreenGroup;
use App\Services\CampaignService;
use App\Services\ManifestBuilder;
use App\Services\ScheduleResolver;
use App\Support\BusinessException;
use App\Support\Present;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Parc des écrans (V07, V08), appairage (V09) et groupes (V10).
 */
class ScreenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('screens.view', 'campaigns.view', 'alerts.manage');
        $query = Screen::query()->with(['location.floor', 'location.zone', 'room', 'groups', 'activeDevice'])
            ->when($request->query('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->when($request->query('floor_id'), fn ($q, $id) => $q->whereHas('location', fn ($l) => $l->where('floor_id', $id)))
            ->when($request->query('zone_id'), fn ($q, $id) => $q->whereHas('location', fn ($l) => $l->where('zone_id', $id)))
            ->when($request->query('group_id'), fn ($q, $id) => $q->whereHas('groups', fn ($g) => $g->where('screen_groups.id', $id)))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('code', 'ilike', "%$term%")->orWhere('name', 'ilike', "%$term%")));
        $network = $request->query('network');
        if ($network) {
            $suspect = now()->subSeconds((int) config('saie.device.suspect_after_s'));
            $unavailable = now()->subSeconds((int) config('saie.device.unavailable_after_s'));
            $query->where(match ($network) {
                'online' => fn ($q) => $q->whereHas('activeDevice', fn ($d) => $d->where('last_seen_at', '>=', $suspect)),
                'suspect' => fn ($q) => $q->whereHas('activeDevice', fn ($d) => $d->whereBetween('last_seen_at', [$unavailable, $suspect])),
                'unavailable' => fn ($q) => $q->whereHas('activeDevice', fn ($d) => $d->where(fn ($w) => $w->where('last_seen_at', '<', $unavailable)->orWhereNull('last_seen_at'))),
                'unpaired' => fn ($q) => $q->whereDoesntHave('activeDevice'),
                default => fn ($q) => $q,
            });
        }
        $this->applySort($query, $request, ['code', 'name', 'created_at'], 'code', 'asc');
        $totals = (clone $query)->reorder()->get()->groupBy(fn (Screen $s) => $s->activeDevice?->computedNetworkStatus() ?? 'unpaired')->map->count();
        $payload = $this->paginated($query, $request, fn (Screen $s) => Present::screen($s), 50)->getData(true);
        $payload['meta']['network_totals'] = ['online' => $totals['online'] ?? 0, 'suspect' => $totals['suspect'] ?? 0, 'unavailable' => $totals['unavailable'] ?? 0, 'unpaired' => $totals['unpaired'] ?? 0, 'unknown' => $totals['unknown'] ?? 0];

        return response()->json($payload);
    }

    public function show(Screen $screen): JsonResponse
    {
        $this->authorizePermission('screens.view', 'campaigns.view');
        $screen->load(['location.floor', 'location.zone', 'room', 'groups', 'activeDevice']);
        $data = Present::screen($screen);
        $data['devices_history'] = $screen->devices()->orderByDesc('created_at')->get()->map(fn (Device $d) => Present::device($d));
        $data['last_screenshot'] = $screen->activeDevice ? DeviceScreenshot::query()->where('device_id', $screen->activeDevice->id)->orderByDesc('requested_at')->first() : null;
        $data['manifest'] = $screen->activeDevice ? $screen->activeDevice->manifests()->orderByDesc('version')->first(['version', 'etag', 'generated_at', 'valid_until']) : null;
        $data['audit'] = DB::table('audit_logs')->where('object_type', 'Screen')->where('object_id', $screen->id)->orderByDesc('occurred_at')->limit(20)->get(['occurred_at', 'actor_label', 'action', 'reason']);

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $data = $this->validated($request);
        $screen = Screen::query()->create($data);
        $this->audit()->log('screen.created', $screen, after: $data);

        return response()->json(['data' => Present::screen($screen->load(['location.floor', 'location.zone', 'groups']))], 201);
    }

    public function update(Request $request, Screen $screen): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $this->inScope([['type' => 'screen', 'id' => $screen->id]], 'screens.manage');
        $data = $this->validated($request, $screen);
        $windowChanged = array_key_exists('operating_window', $data) && json_encode($data['operating_window']) !== json_encode($screen->operating_window);
        if ($windowChanged && $data['operating_window'] !== null && empty($data['window_override_reason'])) {
            throw new BusinessException('REASON_REQUIRED', 'Une dérogation à la plage de fonctionnement exige un motif.', 422);
        }
        $before = $screen->only(array_keys($data));
        $screen->update($data);
        $this->audit()->log($windowChanged ? 'screen.window_changed' : 'screen.updated', $screen, $data['window_override_reason'] ?? null, $before, $data);

        return $this->show($screen->refresh());
    }

    public function preview(Request $request, Screen $screen, ManifestBuilder $builder, ScheduleResolver $resolver): JsonResponse
    {
        $this->authorizePermission('campaigns.view', 'screens.view');
        $at = $request->query('at') ? CarbonImmutable::parse($request->query('at'))->utc() : CarbonImmutable::now()->utc();
        $compiled = $builder->compileForScreen($screen);
        $compiled['authorization'] = [];
        $result = $resolver->resolve($compiled, $at);
        $campaigns = Campaign::query()->whereIn('id', collect($compiled['schedule'])->pluck('campaign_id')->unique())->get(['id', 'reference', 'name', 'priority', 'status']);

        return response()->json(['data' => [
            'at' => $at->toIso8601String(), 'timezone' => $compiled['timezone'], 'operating_window' => $compiled['operating_window'], 'result' => $result,
            'campaigns' => $campaigns, 'fallback' => $compiled['fallback'], 'agenda' => $compiled['agenda'], 'audio_policy' => $compiled['audio_policy'],
            'room_panel' => $compiled['room_panel'] ?? null, 'orientation' => $compiled['orientation'] ?? null, 'screen' => $compiled['screen'],
            'entries_count' => count($compiled['schedule']),
        ]]);
    }

    /**
     * Appairage par code à usage unique (PLY-02). Le jeton durable n'est jamais affiché dans l'interface.
     */
    public function pair(Request $request): JsonResponse
    {
        $this->authorizePermission('devices.pair');
        $data = $request->validate(['code' => ['required', 'string', 'max:12'], 'screen_id' => ['required', 'uuid', 'exists:screens,id'], 'replace' => ['boolean']]);
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $data['code']));

        $device = DB::transaction(function () use ($code, $data): Device {
            $pairing = PairingCode::query()->where('code', $code)->lockForUpdate()->first();
            if (! $pairing) {
                throw new BusinessException('CODE_UNKNOWN', 'Code inconnu : vérifiez la saisie affichée sur le lecteur.', 422);
            }
            if ($pairing->used_at) {
                throw new BusinessException('CODE_USED', 'Ce code a déjà été utilisé.', 409);
            }
            if ($pairing->expires_at->isPast()) {
                throw new BusinessException('CODE_EXPIRED', 'Code expiré : le lecteur affiche un nouveau code.', 409);
            }
            $screen = Screen::query()->with('activeDevice')->findOrFail($data['screen_id']);
            if ($screen->activeDevice) {
                if (empty($data['replace'])) {
                    throw new BusinessException('SCREEN_ALREADY_PAIRED', 'Cet écran a déjà un lecteur actif. Confirmez son remplacement.', 409);
                }
                $this->revokeDevice($screen->activeDevice, 'Remplacement par un nouveau lecteur');
            }
            $device = Device::query()->create([
                'code' => 'LCT-'.strtoupper(substr(hash('sha256', $pairing->hardware_id.$pairing->id), 0, 8)), 'screen_id' => $screen->id, 'hardware_id' => $pairing->hardware_id,
                'status' => 'active', 'app_version' => $pairing->device_info['app_version'] ?? null, 'platform' => $pairing->device_info['platform'] ?? null,
                'reported_resolution' => $pairing->device_info['resolution'] ?? null, 'paired_at' => now(), 'paired_by' => $this->user()->id,
            ]);
            $token = $device->createToken('device', ['device'])->plainTextToken;
            $pairing->forceFill(['used_at' => now(), 'device_id' => $device->id, 'issued_token' => $token])->save();
            $this->audit()->log('device.paired', $screen, after: ['device' => $device->code]);

            return $device;
        });

        return response()->json(['data' => Present::device($device), 'message' => 'Lecteur associé. Il récupère son identité et se synchronise.'], 201);
    }

    public function revoke(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission('devices.revoke');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        if ($device->status === 'revoked') {
            throw new BusinessException('ALREADY_REVOKED', 'Ce lecteur est déjà révoqué.', 409);
        }
        $this->revokeDevice($device, $data['reason']);

        return response()->json(['data' => Present::device($device->refresh()), 'message' => 'Lecteur révoqué. Son cache hors ligne reste lisible jusqu’à l’expiration de l’autorisation en cours.']);
    }

    public function requestScreenshot(Device $device): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $this->inScope([['type' => 'screen', 'id' => $device->screen_id]], 'screens.manage');
        $shot = DeviceScreenshot::query()->create(['device_id' => $device->id, 'requested_by' => $this->user()->id, 'requested_at' => now(), 'status' => 'requested', 'expires_at' => now()->addMinutes(10)]);
        $this->audit()->log('device.screenshot_requested', $device);

        return response()->json(['data' => $shot, 'message' => 'Demande transmise au prochain heartbeat du lecteur.'], 201);
    }

    public function screenshot(DeviceScreenshot $screenshot): StreamedResponse
    {
        $this->authorizePermission('screens.manage');
        abort_unless($screenshot->status === 'captured' && $screenshot->storage_key && $screenshot->expires_at->isFuture(), 404);

        return Storage::disk('local')->response($screenshot->storage_key, null, ['Cache-Control' => 'no-store']);
    }

    public function groups(Request $request): JsonResponse
    {
        $this->authorizePermission('screens.view', 'campaigns.view', 'groups.manage');
        $groups = ScreenGroup::query()->withCount('screens')->with('screens:id,code,name')->orderBy('name')->get();

        return response()->json(['data' => $groups->map(fn (ScreenGroup $g) => [
            'id' => $g->id, 'code' => $g->code, 'name' => $g->name, 'description' => $g->description, 'screens_count' => $g->screens_count, 'operating_window' => $g->operating_window,
            'screens' => $g->screens->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name]),
            'active_campaigns' => DB::table('campaign_targets')->join('campaigns', 'campaigns.id', '=', 'campaign_targets.campaign_id')->where('target_type', 'group')->where('target_id', $g->id)->whereIn('campaigns.status', ['scheduled', 'active'])->count(),
        ])]);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $this->authorizePermission('groups.manage');
        $data = $request->validate(['code' => ['required', 'string', 'max:40', 'unique:screen_groups,code'], 'name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'], 'screen_ids' => ['array'], 'screen_ids.*' => ['uuid', 'exists:screens,id'],
            // PLY-08 : plage du groupe, appliquée aux écrans du groupe sans plage propre.
            'operating_window' => ['nullable', 'array'], 'operating_window.days' => ['required_with:operating_window', 'array', 'min:1'], 'operating_window.days.*' => ['integer', 'between:1,7'], 'operating_window.on' => ['required_with:operating_window', 'date_format:H:i'], 'operating_window.off' => ['required_with:operating_window', 'date_format:H:i', 'after:operating_window.on'], 'operating_window.ignore_holidays' => ['sometimes', 'boolean']]);
        $this->inScope(array_map(fn ($id) => ['type' => 'screen', 'id' => $id], $data['screen_ids'] ?? []), 'groups.manage');
        $group = ScreenGroup::query()->create(collect($data)->except('screen_ids')->all());
        $group->screens()->sync($data['screen_ids'] ?? []);
        $this->audit()->log('group.created', $group);

        return response()->json(['data' => $group], 201);
    }

    public function updateGroup(Request $request, ScreenGroup $group): JsonResponse
    {
        $this->authorizePermission('groups.manage');
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'], 'screen_ids' => ['sometimes', 'array'], 'screen_ids.*' => ['uuid', 'exists:screens,id'],
            'operating_window' => ['nullable', 'array'], 'operating_window.days' => ['required_with:operating_window', 'array', 'min:1'], 'operating_window.days.*' => ['integer', 'between:1,7'], 'operating_window.on' => ['required_with:operating_window', 'date_format:H:i'], 'operating_window.off' => ['required_with:operating_window', 'date_format:H:i', 'after:operating_window.on'], 'operating_window.ignore_holidays' => ['sometimes', 'boolean']]);
        $this->inScope(array_merge([['type' => 'group', 'id' => $group->id]], array_map(fn ($id) => ['type' => 'screen', 'id' => $id], $data['screen_ids'] ?? [])), 'groups.manage');
        $group->update(collect($data)->except('screen_ids')->all());
        if (array_key_exists('screen_ids', $data)) {
            $group->screens()->sync($data['screen_ids']);
        }
        $this->audit()->log('group.updated', $group, after: $data);

        return response()->json(['data' => $group->load('screens:id,code,name')]);
    }

    public function destroyGroup(ScreenGroup $group): JsonResponse
    {
        $this->authorizePermission('groups.manage');
        $this->inScope([['type' => 'group', 'id' => $group->id]], 'groups.manage');
        $dependent = DB::table('campaign_targets')->join('campaigns', 'campaigns.id', '=', 'campaign_targets.campaign_id')->where('target_type', 'group')->where('target_id', $group->id)->whereIn('campaigns.status', ['scheduled', 'active', 'suspended'])->pluck('campaigns.reference');
        if ($dependent->isNotEmpty()) {
            throw new BusinessException('GROUP_IN_USE', 'Suppression impossible : des campagnes actives ciblent ce groupe.', 409, ['campaigns' => $dependent]);
        }
        $this->audit()->log('group.deleted', $group);
        $group->delete();

        return response()->json(null, 204);
    }

    private function revokeDevice(Device $device, string $reason): void
    {
        $device->tokens()->delete();
        $device->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $this->user()->id, 'revoke_reason' => $reason, 'network_status' => 'unknown'])->save();
        $this->audit()->log('device.revoked', $device, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Screen $screen = null): array
    {
        $required = $screen ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'string', 'max:40', 'regex:/^[A-Z0-9-]+$/', Rule::unique('screens', 'code')->ignore($screen?->id)],
            'name' => [$required, 'string', 'max:120'],
            'kind' => [$required, 'in:display,room_panel,orientation'],
            'location_id' => [$required, 'uuid', 'exists:locations,id'],
            'room_id' => ['nullable', 'uuid', 'exists:rooms,id', 'required_if:kind,room_panel'],
            'orientation' => [$required, 'in:landscape,portrait'],
            'resolution' => [$required, 'in:1920x1080,1080x1920,3840x2160,2160x3840,1280x800,800x1280'],
            'operating_window' => ['nullable', 'array'],
            'operating_window.days' => ['required_with:operating_window', 'array'], 'operating_window.days.*' => ['integer', 'between:1,7'],
            'operating_window.on' => ['required_with:operating_window', 'date_format:H:i'], 'operating_window.off' => ['required_with:operating_window', 'date_format:H:i', 'after:operating_window.on'],
            'operating_window.wake_before_min' => ['nullable', 'integer', 'between:0,30'], 'operating_window.ignore_holidays' => ['nullable', 'boolean'],
            'window_override_reason' => ['nullable', 'string', 'max:300'],
            'audio_enabled' => ['sometimes', 'boolean'],
            'control_channel' => ['sometimes', 'in:none,cec,rs232,api'],
            'status' => ['sometimes', 'in:active,inactive'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * HAB-01 : écrans et groupes gérés seulement dans le site et la zone de l'habilitation.
     *
     * @param  list<array{type: string, id: string|null}>  $targets
     */
    private function inScope(array $targets, string $permission): void
    {
        app(CampaignService::class)->assertTargetsInScope($targets, $this->user(), $permission);
    }
}
