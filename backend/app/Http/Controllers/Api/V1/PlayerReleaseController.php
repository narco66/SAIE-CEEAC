<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\PlayerRelease;
use App\Support\BusinessException;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Canaux de version du lecteur (V40, PLY-12) : publication par canal, retrait (retour arrière en une action), affectation d'un lecteur à un canal.
 */
class PlayerReleaseController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('supervision.view');
        $releases = PlayerRelease::query()->orderByDesc('created_at')->get();

        return response()->json(['data' => $releases->map(fn ($r) => $this->present($r)), 'meta' => [
            'targets' => collect(['pilot', 'stable'])->mapWithKeys(fn ($c) => [$c => PlayerRelease::targetFor($c)?->version]),
            'devices' => Device::query()->where('status', 'active')->selectRaw('release_channel, app_version, count(*) as total')->groupBy('release_channel', 'app_version')->get(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('incidents.manage');
        $data = $request->validate([
            'version' => ['required', 'string', 'max:40', 'regex:/^[0-9A-Za-z.\-]+$/'], 'channel' => ['required', 'in:pilot,stable'],
            'base_url' => ['required', 'string', 'max:250', 'regex:#^/player/releases/[0-9A-Za-z.\-]+/$#'], 'sha256' => ['required', 'regex:/^[0-9a-f]{64}$/'], 'notes' => ['nullable', 'string', 'max:1000'],
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
        $this->authorizePermission('incidents.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $release->forceFill(['status' => 'withdrawn'])->save();
        $this->audit()->log('player.release_withdrawn', $release, $data['reason'], objectLabel: $release->version.' · '.$release->channel);

        return response()->json(['data' => $this->present($release), 'target' => PlayerRelease::targetFor($release->channel)?->version]);
    }

    public function assignChannel(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $data = $request->validate(['channel' => ['required', 'in:pilot,stable']]);
        $device->forceFill(['release_channel' => $data['channel']])->save();
        $this->audit()->log('device.channel_changed', $device, after: $data);

        return response()->json(['data' => ['id' => $device->id, 'release_channel' => $device->release_channel]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PlayerRelease $r): array
    {
        return ['id' => $r->id, 'version' => $r->version, 'channel' => $r->channel, 'base_url' => $r->base_url, 'sha256' => $r->sha256, 'notes' => $r->notes, 'status' => $r->status, 'created_at' => Present::date($r->created_at)];
    }
}
