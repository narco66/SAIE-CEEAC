<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AlertReceipt;
use App\Models\AssetVersion;
use App\Models\Device;
use App\Models\DeviceScreenshot;
use App\Models\DeviceTelemetry;
use App\Models\Manifest;
use App\Models\PairingCode;
use App\Services\Audit;
use App\Services\ManifestBuilder;
use App\Services\ManifestSigner;
use App\Support\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * API technique des lecteurs : l'identité est propre à chaque terminal, aucun accès utilisateur ou documentaire général.
 */
class DeviceApiController extends Controller
{
    private const CODE_ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';

    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hardware_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'info' => ['nullable', 'array'], 'info.app_version' => ['nullable', 'string', 'max:40'], 'info.platform' => ['nullable', 'string', 'max:120'], 'info.resolution' => ['nullable', 'string', 'max:20'],
        ]);
        PairingCode::query()->where('hardware_id', $data['hardware_id'])->whereNull('used_at')->update(['expires_at' => now()]);
        do {
            $code = collect(range(1, 6))->map(fn () => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)])->implode('');
        } while (PairingCode::query()->where('code', $code)->exists());
        $secret = Str::random(48);
        $pairing = PairingCode::query()->create([
            'code' => $code, 'poll_secret_hash' => hash('sha256', $secret), 'hardware_id' => $data['hardware_id'], 'device_info' => $data['info'] ?? null,
            'expires_at' => now()->addMinutes((int) config('saie.device.pairing_code_ttl_min')), 'created_ip' => $request->ip(),
        ]);

        return response()->json(['code' => $code, 'poll_secret' => $secret, 'expires_at' => $pairing->expires_at->toIso8601String()], 201);
    }

    public function pollCode(Request $request, string $code, ManifestSigner $signer): JsonResponse
    {
        $pairing = PairingCode::query()->where('code', strtoupper($code))->first();
        if (! $pairing || ! hash_equals($pairing->poll_secret_hash, hash('sha256', (string) $request->header('X-Poll-Secret')))) {
            throw new BusinessException('NOT_FOUND', 'Code inconnu.', 404);
        }
        if ($pairing->used_at && $pairing->issued_token && ! $pairing->token_collected_at) {
            $token = $pairing->issued_token;
            $pairing->forceFill(['issued_token' => null, 'token_collected_at' => now()])->save();
            $device = $pairing->device;

            return response()->json(['status' => 'paired', 'token' => $token, 'device' => ['id' => $device->id, 'code' => $device->code], 'public_key' => $signer->publicKeyRaw(), 'key_id' => $signer->keyId()]);
        }
        if ($pairing->used_at) {
            throw new BusinessException('CODE_USED', 'Ce code a déjà été utilisé.', 410);
        }
        if ($pairing->expires_at->isPast()) {
            throw new BusinessException('CODE_EXPIRED', 'Code expiré : un nouveau code va être généré.', 410);
        }

        return response()->json(['status' => 'waiting', 'expires_at' => $pairing->expires_at->toIso8601String()]);
    }

    public function key(ManifestSigner $signer): JsonResponse
    {
        return response()->json(['alg' => 'Ed25519', 'key_id' => $signer->keyId(), 'public_key' => $signer->publicKeyRaw()]);
    }

    public function manifest(Request $request, ManifestBuilder $builder): Response|JsonResponse
    {
        $device = $this->device();
        $manifest = $builder->current($device);
        $device->forceFill(['last_seen_at' => now(), 'network_status' => 'online'])->save();
        $etag = '"'.$manifest->etag.'-'.$manifest->version.'"';
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return response()->json($builder->envelope($manifest))->header('ETag', $etag);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app_version' => ['nullable', 'string', 'max:40'], 'platform' => ['nullable', 'string', 'max:120'], 'resolution' => ['nullable', 'string', 'max:20'],
            'device_time' => ['required', 'date'], 'active_manifest_version' => ['nullable', 'integer'],
            'storage' => ['nullable', 'array'], 'storage.usage' => ['nullable', 'numeric'], 'storage.quota' => ['nullable', 'numeric'], 'storage.persisted' => ['nullable', 'boolean'],
            'errors' => ['nullable', 'array', 'max:20'], 'errors.*' => ['string', 'max:300'],
            'playback' => ['nullable', 'array'], 'panel_state' => ['nullable', 'in:on,off,standby,unknown'],
            'cache_inventory' => ['nullable', 'array', 'max:500'], 'cache_inventory.*' => ['string', 'max:60'],
            'offline_since' => ['nullable', 'date'],
        ]);
        $device = $this->device();
        $skew = (int) round((CarbonImmutable::parse($data['device_time'])->getTimestampMs() - now()->getTimestampMs()));
        $device->forceFill([
            'last_seen_at' => now(), 'network_status' => 'online', 'app_version' => $data['app_version'] ?? $device->app_version,
            'platform' => $data['platform'] ?? $device->platform, 'reported_resolution' => $data['resolution'] ?? $device->reported_resolution,
            'clock_skew_ms' => $skew, 'storage' => $data['storage'] ?? $device->storage, 'active_manifest_version' => $data['active_manifest_version'] ?? $device->active_manifest_version,
            'current_playback' => $data['playback'] ?? null, 'last_errors' => $data['errors'] ?? [], 'cache_inventory' => $data['cache_inventory'] ?? $device->cache_inventory,
            'last_sync_at' => isset($data['active_manifest_version']) ? now() : $device->last_sync_at,
        ])->save();
        if (isset($data['panel_state']) && $device->screen && $device->screen->control_channel !== 'none') {
            $device->screen->forceFill(['panel_state' => $data['panel_state'], 'panel_state_at' => now()])->save();
        }
        if (! DeviceTelemetry::query()->where('device_id', $device->id)->where('recorded_at', '>', now()->subMinutes(5))->exists()) {
            DeviceTelemetry::query()->create(['device_id' => $device->id, 'recorded_at' => now(), 'payload' => $data + ['clock_skew_ms' => $skew]]);
        }
        $screenshots = DeviceScreenshot::query()->where('device_id', $device->id)->where('status', 'requested')->where('expires_at', '>', now())->pluck('id');

        return response()->json([
            'server_time' => now()->toIso8601ZuluString(), 'clock_skew_ms' => $skew, 'next_heartbeat_s' => (int) config('saie.device.heartbeat_s'),
            'screenshot_requests' => $screenshots, 'revoked' => false,
        ]);
    }

    /**
     * Reçus différés dédoublonnés par identifiant d'événement (PLY-06, PUB-07, PUB-06).
     */
    public function receipts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'max:500'],
            'events.*.event_id' => ['required', 'uuid'],
            'events.*.kind' => ['required', 'in:manifest_received,content_loaded,activated,played,alert_received,alert_activated,error,fallback,offline,online,off_window'],
            'events.*.manifest_version' => ['nullable', 'integer'], 'events.*.ref_type' => ['nullable', 'string', 'max:20'], 'events.*.ref_id' => ['nullable', 'string', 'max:60'],
            'events.*.lang' => ['nullable', 'string', 'size:2'], 'events.*.occurred_at' => ['required', 'date'], 'events.*.payload' => ['nullable', 'array'],
        ]);
        $device = $this->device();
        $rows = collect($data['events'])->map(fn (array $e) => [
            'id' => (string) Str::uuid(), 'event_id' => $e['event_id'], 'device_id' => $device->id, 'kind' => $e['kind'], 'manifest_version' => $e['manifest_version'] ?? null,
            'ref_type' => $e['ref_type'] ?? null, 'ref_id' => $e['ref_id'] ?? null, 'lang' => $e['lang'] ?? null,
            'occurred_at' => CarbonImmutable::parse($e['occurred_at'])->utc(), 'received_at' => now(), 'payload' => isset($e['payload']) ? json_encode($e['payload']) : null,
        ]);
        $accepted = DB::table('device_receipts')->insertOrIgnore($rows->all());
        foreach (collect($data['events'])->whereIn('kind', ['alert_received', 'alert_activated']) as $event) {
            if (! Str::isUuid((string) ($event['ref_id'] ?? '')) || ! DB::table('alerts')->where('id', $event['ref_id'])->exists()) {
                continue;
            }
            $receipt = AlertReceipt::query()->firstOrCreate(['alert_id' => $event['ref_id'], 'device_id' => $device->id]);
            $column = $event['kind'] === 'alert_received' ? 'received_at' : 'activated_at';
            if (! $receipt->{$column}) {
                $receipt->forceFill([$column => CarbonImmutable::parse($event['occurred_at'])->utc()])->save();
            }
        }
        $activated = collect($data['events'])->where('kind', 'activated')->max('manifest_version');
        if ($activated) {
            $device->forceFill(['active_manifest_version' => max((int) $device->active_manifest_version, (int) $activated), 'last_sync_at' => now()])->save();
        }

        return response()->json(['accepted' => $accepted, 'duplicates' => count($rows) - $accepted]);
    }

    /**
     * PLY-02 : renouvellement du jeton du lecteur. Le nouveau jeton est remis une seule fois ; l'ancien expire 10 minutes plus tard
     * (chevauchement pour les appels déjà en cours), de sorte qu'un jeton dérobé cesse de valoir au renouvellement suivant.
     */
    public function rotateToken(Request $request): JsonResponse
    {
        $device = $this->device();
        $current = $request->user()->currentAccessToken();
        $expires = now()->addDays((int) config('saie.device.token_ttl_days'));
        $token = $device->createToken('device', ['device'], $expires)->plainTextToken;
        $current?->forceFill(['expires_at' => now()->addMinutes(10)])->save();
        app(Audit::class)->log('device.token_rotated', $device, actor: $device);

        return response()->json(['token' => $token, 'expires_at' => $expires->toIso8601ZuluString()]);
    }

    public function asset(Request $request, AssetVersion $assetVersion): SymfonyResponse
    {
        $device = $this->device();
        // Les médias des deux derniers manifestes restent autorisés : une recompilation pendant un téléchargement ne le fait pas échouer.
        $allowed = Manifest::query()->where('device_id', $device->id)->orderByDesc('version')->limit(2)->get()
            ->contains(fn (Manifest $m) => collect($m->payload['assets'] ?? [])->pluck('id')->contains($assetVersion->id));
        if (! $allowed || $assetVersion->scan_status !== 'ready' || $assetVersion->asset->classification === 'restreint') {
            app(Audit::class)->log('device.asset_denied', $assetVersion->asset, outcome: 'denied', actor: $device);
            abort(403);
        }
        $file = $assetVersion->broadcastFile();
        $headers = [
            'Content-Type' => $file['mime'], 'X-Content-SHA256' => $file['sha256'], 'ETag' => '"'.$file['sha256'].'"',
            'Cache-Control' => 'private, max-age=86400, immutable',
        ];
        // PERF-01 : en production, Nginx envoie le fichier après cette autorisation, sans occuper un processus PHP.
        if ($prefix = config('saie.device.accel_redirect')) {
            return response('', 200, $headers + ['X-Accel-Redirect' => rtrim($prefix, '/').'/'.ltrim($file['storage_key'], '/')]);
        }

        return response()->file(Storage::disk('local')->path($file['storage_key']), $headers);
    }

    public function uploadScreenshot(Request $request, DeviceScreenshot $screenshot): JsonResponse
    {
        $device = $this->device();
        if ($screenshot->device_id !== $device->id || $screenshot->status !== 'requested') {
            abort(404);
        }
        $request->validate(['image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192']]);
        $key = "screenshots/{$device->id}/{$screenshot->id}.".$request->file('image')->extension();
        Storage::disk('local')->putFileAs(dirname($key), $request->file('image'), basename($key));
        $screenshot->forceFill(['status' => 'captured', 'captured_at' => now(), 'storage_key' => $key, 'expires_at' => now()->addDays((int) config('saie.device.screenshot_retention_days'))])->save();

        return response()->json(['ok' => true]);
    }

    private function device(): Device
    {
        /** @var Device $device */
        $device = auth()->user();

        return $device;
    }
}
