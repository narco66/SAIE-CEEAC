<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Incident;
use App\Models\PlayerRelease;
use App\Services\ReleasePromotion;
use App\Support\BusinessException;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Canaux de version du lecteur (V40, PLY-12) : publication par canal, retrait (retour arrière en une action), affectation d'un lecteur à un canal.
 */
class PlayerReleaseController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('supervision.view');
        $releases = PlayerRelease::query()->orderByDesc('created_at')->get();

        $eligible = app(ReleasePromotion::class)->eligible()->pluck('id')->all();

        return response()->json(['data' => $releases->map(fn ($r) => $this->present($r) + ['promotion_eligible' => in_array($r->id, $eligible, true)]), 'meta' => [
            'targets' => collect(['pilot', 'stable'])->mapWithKeys(fn ($c) => [$c => PlayerRelease::targetFor($c)?->version]),
            'devices' => Device::query()->where('status', 'active')->selectRaw('release_channel, app_version, count(*) as total')->groupBy('release_channel', 'app_version')->get(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('player_releases.manage');
        // §14-9 : une version s'applique à tout un canal : réservé aux habilitations sans restriction de périmètre.
        if ($this->screenScopeFor('player_releases.manage') !== null) {
            $this->deny('Versions du lecteur : habilitation limitée à un périmètre');
        }
        $data = $request->validate([
            'version' => ['required', 'string', 'max:40', 'regex:/^[0-9A-Za-z.\-]+$/'], 'channel' => ['required', 'in:pilot,stable'],
            'base_url' => ['required', 'string', 'max:250', 'regex:#^/player/releases/[0-9A-Za-z.\-]+/$#'], 'sha256' => ['required', 'regex:/^[0-9a-f]{64}$/'], 'notes' => ['nullable', 'string', 'max:1000'],
            // §21.4 : déploiement progressif (part du canal qui reçoit la version), 100 % par défaut.
            'rollout_percent' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        if ($data['channel'] === 'stable' && ! PlayerRelease::query()->where('version', $data['version'])->where('channel', 'pilot')->exists()) {
            throw new BusinessException('PILOT_REQUIRED', 'Une version passe d’abord par le canal pilote avant le canal stable (EXP-02).', 422);
        }
        $release = PlayerRelease::query()->create($data + ['created_by' => $this->user()->id]);
        $this->audit()->log('player.release_published', $release, objectLabel: $data['version'].' · '.$data['channel']);

        return response()->json(['data' => $this->present($release)], 201);
    }

    /**
     * Retrait d'une version : le canal revient immédiatement à la version active précédente (retour arrière).
     */
    public function withdraw(Request $request, PlayerRelease $release): JsonResponse
    {
        $this->authorizePermission('player_releases.manage');
        // §14-9 : une version s'applique à tout un canal : réservé aux habilitations sans restriction de périmètre.
        if ($this->screenScopeFor('player_releases.manage') !== null) {
            $this->deny('Versions du lecteur : habilitation limitée à un périmètre');
        }
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $release->forceFill(['status' => 'withdrawn'])->save();
        $this->audit()->log('player.release_withdrawn', $release, $data['reason'], objectLabel: $release->version.' · '.$release->channel);

        return response()->json(['data' => $this->present($release), 'target' => PlayerRelease::targetFor($release->channel)?->version]);
    }

    /**
     * §21.4 : élargissement (ou réduction) d'une vague de déploiement ; la sélection des lecteurs est stable.
     */
    public function update(Request $request, PlayerRelease $release): JsonResponse
    {
        $this->authorizePermission('player_releases.manage');
        // §14-9 : une version s'applique à tout un canal : réservé aux habilitations sans restriction de périmètre.
        if ($this->screenScopeFor('player_releases.manage') !== null) {
            $this->deny('Versions du lecteur : habilitation limitée à un périmètre');
        }
        $data = $request->validate(['rollout_percent' => ['required', 'integer', 'between:1,100'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        if ($release->status !== 'active') {
            throw new BusinessException('RELEASE_WITHDRAWN', 'Version retirée : son déploiement ne peut plus évoluer.', 409);
        }
        $before = ['rollout_percent' => $release->rollout_percent];
        $release->forceFill(['rollout_percent' => $data['rollout_percent']])->save();
        $this->audit()->log('player.release_rollout', $release, $data['reason'], $before, ['rollout_percent' => $data['rollout_percent']], objectLabel: $release->version.' · '.$release->channel);

        return response()->json(['data' => $this->present($release->refresh())]);
    }

    /**
     * Suivi de déploiement (§21.4) : lecteurs du canal visés par la version, déjà passés, en attente, en échec (incident de
     * version obsolète ouvert), et lecteurs restés sur la version précédente (hors vague).
     */
    public function deployment(PlayerRelease $release): JsonResponse
    {
        $this->authorizePermission('supervision.view', 'player_releases.manage');
        $scope = $this->screenScopeFor('supervision.view', 'player_releases.manage');
        $devices = Device::query()->with('screen')->where('status', 'active')->where('release_channel', $release->channel)
            ->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))->orderBy('code')->get();
        $failing = Incident::query()->where('kind', 'outdated_version')->whereIn('status', ['open', 'acknowledged'])->pluck('device_id')->all();
        $rows = $devices->map(function (Device $d) use ($release, $failing): array {
            $target = PlayerRelease::targetForDevice($d);
            $state = match (true) {
                $target?->id !== $release->id => 'excluded',
                $d->app_version === $release->version => 'updated',
                in_array($d->id, $failing, true) => 'failing',
                default => 'pending',
            };

            return ['device' => $d->code, 'device_id' => $d->id, 'screen' => $d->screen?->code, 'app_version' => $d->app_version, 'state' => $state, 'last_seen_at' => Present::date($d->last_seen_at)];
        });

        return response()->json(['data' => ['release' => $this->present($release), 'summary' => $rows->countBy('state')->all() + ['total' => $rows->count()], 'devices' => $rows->values()]]);
    }

    /**
     * §9.8 : mise à jour ciblée — une version active est épinglée sur un lecteur précis (ou l'épinglage est retiré).
     */
    public function pin(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission('player_releases.manage');
        $this->assertScreenInScope((string) $device->screen_id, 'player_releases.manage', 'screens.manage');
        $data = $request->validate(['release_id' => ['nullable', 'uuid', Rule::exists('player_releases', 'id')->where('status', 'active')], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        $before = ['pinned_release_id' => $device->pinned_release_id];
        $device->forceFill(['pinned_release_id' => $data['release_id'] ?? null])->save();
        $this->audit()->log('device.release_pinned', $device, $data['reason'], $before, ['pinned_release_id' => $device->pinned_release_id]);

        return response()->json(['data' => Present::device($device->refresh()), 'target' => PlayerRelease::targetForDevice($device)?->version]);
    }

    public function assignChannel(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $data = $request->validate(['channel' => ['required', 'in:pilot,stable']]);
        // ASVS 5.0 V8.2 : seuls les lecteurs des écrans du périmètre (site, zone) changent de canal.
        $scope = $this->access()->screenScope($this->user(), 'screens.manage');
        if ($scope !== null && ! in_array($device->screen_id, $scope, true)) {
            $this->deny('Lecteur hors périmètre');
        }
        $device->forceFill(['release_channel' => $data['channel']])->save();
        $this->audit()->log('device.channel_changed', $device, after: $data);

        return response()->json(['data' => ['id' => $device->id, 'release_channel' => $device->release_channel]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PlayerRelease $r): array
    {
        return ['id' => $r->id, 'version' => $r->version, 'channel' => $r->channel, 'base_url' => $r->base_url, 'sha256' => $r->sha256, 'notes' => $r->notes, 'status' => $r->status, 'rollout_percent' => (int) $r->rollout_percent, 'created_at' => Present::date($r->created_at)];
    }
}
