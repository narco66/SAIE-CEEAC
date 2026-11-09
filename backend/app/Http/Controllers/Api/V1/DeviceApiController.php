<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AlertReceipt;
use App\Models\AssetVersion;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceScreenshot;
use App\Models\DeviceTelemetry;
use App\Models\Manifest;
use App\Models\PairingCode;
use App\Models\Setting;
use App\Services\Audit;
use App\Services\ManifestBuilder;
use App\Services\ManifestSigner;
use App\Services\RemoteCommands;
use App\Support\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
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
        // §9.14, §18.2 : liste blanche d'adresses autorisées à demander un code (vide : enrôlement ouvert, comportement d'origine).
        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) Setting::effective('devices.enroll_cidrs')))));
        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            app(Audit::class)->log('device.enrollment_refused', null, 'Adresse hors liste blanche', after: ['ip' => $request->ip(), 'hardware_id' => $data['hardware_id']], outcome: 'denied');
            throw new BusinessException('ENROLLMENT_NOT_ALLOWED', 'Enrôlement refusé depuis ce réseau.', 403);
        }
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

        $this->recordTransmissions($device, $manifest);

        return response()->json($builder->envelope($manifest))->header('ETag', $etag);
    }

    /**
     * Niveau de preuve « Transmis » (annexe C) : première version servie au lecteur qui porte chaque campagne. Conservé même
     * après la purge des anciens manifestes.
     */
    private function recordTransmissions(Device $device, Manifest $manifest): void
    {
        $campaignIds = collect($manifest->payload['schedule'] ?? [])->pluck('campaign_id')->filter()->unique()->values();
        if ($campaignIds->isEmpty()) {
            return;
        }
        DB::table('campaign_transmissions')->insertOrIgnore($campaignIds->map(fn (string $id) => [
            'id' => (string) Str::uuid(), 'device_id' => $device->id, 'campaign_id' => $id,
            'first_manifest_version' => $manifest->version, 'first_transmitted_at' => now(),
        ])->all());
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
            'offline_since' => ['nullable', 'date'], 'events_dropped' => ['nullable', 'integer', 'min:0'],
            // §11 : capacités déclarées par le lecteur (commandes qu'il sait exécuter) et état de la diffusion.
            'capabilities' => ['nullable', 'array', 'max:20'], 'capabilities.*' => ['string', 'max:40'], 'playback_paused' => ['nullable', 'boolean'],
            // §9.1 : caractéristiques accessibles au lecteur (écran, processeur, mémoire, langue) ; MAC, firmware et système
            // d'exploitation exigent l'agent local et ne sont pas déduits du navigateur.
            'device_info' => ['nullable', 'array'], 'device_info.screen' => ['nullable', 'string', 'max:20'], 'device_info.pixel_ratio' => ['nullable', 'numeric'],
            'device_info.cores' => ['nullable', 'integer', 'between:1,1024'], 'device_info.memory_gb' => ['nullable', 'numeric'], 'device_info.language' => ['nullable', 'string', 'max:20'],
            'device_info.timezone' => ['nullable', 'string', 'max:60'], 'device_info.touch' => ['nullable', 'boolean'], 'device_info.os' => ['nullable', 'string', 'max:80'],
            // §7 : configuration appliquée (empreinte) et paramètres non pris en charge par ce lecteur.
            'config' => ['nullable', 'array'], 'config.hash' => ['nullable', 'string', 'size:64'], 'config.applied' => ['nullable', 'array', 'max:20'], 'config.applied.*' => ['string', 'max:40'],
            'config.unsupported' => ['nullable', 'array', 'max:20'], 'config.unsupported.*' => ['string', 'max:40'],
            'device_info.mac' => ['nullable', 'string', 'max:40'],
            // Télémétrie de l'agent local (null quand la mesure est indisponible).
            'device_info.agent_version' => ['nullable', 'string', 'max:20'], 'device_info.ip' => ['nullable', 'ip'], 'device_info.cpu_count' => ['nullable', 'integer', 'between:1,1024'],
            'device_info.cpu_load' => ['nullable', 'numeric', 'between:0,10000'], 'device_info.mem_total_gb' => ['nullable', 'numeric'], 'device_info.mem_free_gb' => ['nullable', 'numeric'],
            'device_info.uptime_s' => ['nullable', 'integer', 'min:0'], 'device_info.disk_total_gb' => ['nullable', 'numeric'], 'device_info.disk_free_gb' => ['nullable', 'numeric'],
            'device_info.temperature_c' => ['nullable', 'numeric', 'between:-40,150'], 'device_info.simulated' => ['nullable', 'boolean'], 'device_info.firmware' => ['nullable', 'string', 'max:80'], 'device_info.hostname' => ['nullable', 'string', 'max:120'],
        ]);
        $device = $this->device();
        $previousVersion = $device->app_version;
        $skew = (int) round((CarbonImmutable::parse($data['device_time'])->getTimestampMs() - now()->getTimestampMs()));
        $device->forceFill([
            'last_seen_at' => now(), 'network_status' => 'online', 'app_version' => $data['app_version'] ?? $device->app_version,
            'platform' => $data['platform'] ?? $device->platform, 'reported_resolution' => $data['resolution'] ?? $device->reported_resolution,
            // La version active déclarée ne peut dépasser la dernière version effectivement émise pour ce lecteur.
            'clock_skew_ms' => $skew, 'storage' => $data['storage'] ?? $device->storage, 'active_manifest_version' => $this->boundedVersion($device, $data['active_manifest_version'] ?? null) ?? $device->active_manifest_version,
            'current_playback' => $data['playback'] ?? null, 'last_errors' => $data['errors'] ?? [], 'cache_inventory' => $data['cache_inventory'] ?? $device->cache_inventory,
            // §5.2-i, §17.10 : adresse vue par le serveur, dernière coupure déclarée et cumul des événements perdus.
            'observed_ip' => $request->ip(), 'events_dropped' => (int) $device->events_dropped + (int) ($data['events_dropped'] ?? 0),
        ] + (isset($data['offline_since']) ? ['offline_since' => CarbonImmutable::parse($data['offline_since'])] : []) + (isset($data['config']['hash']) ? ['config_hash' => $data['config']['hash'], 'config_report' => ['applied' => $data['config']['applied'] ?? [], 'unsupported' => $data['config']['unsupported'] ?? []], 'config_reported_at' => now()] : [])
          + (isset($data['device_info']) ? ['hardware_info' => array_filter($data['device_info'], fn ($v) => $v !== null)] : [])
          + (array_key_exists('capabilities', $data) ? ['capabilities' => array_values(array_unique($data['capabilities'] ?? [])), 'capabilities_reported_at' => now()] : [])
          + (array_key_exists('playback_paused', $data) ? ['playback_paused' => (bool) $data['playback_paused']] : []))->save();
        // §13.2-f : historique des versions du lecteur.
        if ($previousVersion !== null && isset($data['app_version']) && $data['app_version'] !== $previousVersion) {
            app(Audit::class)->log('device.version_changed', $device, null, ['app_version' => $previousVersion], ['app_version' => $data['app_version']], actor: $device);
        }
        if (isset($data['panel_state']) && $device->screen && $device->screen->control_channel !== 'none') {
            $device->screen->forceFill(['panel_state' => $data['panel_state'], 'panel_state_at' => now()])->save();
        }
        if (! DeviceTelemetry::query()->where('device_id', $device->id)->where('recorded_at', '>', now()->subSeconds(285))->exists()) {
            DeviceTelemetry::query()->create(['device_id' => $device->id, 'recorded_at' => now(), 'payload' => $data + ['clock_skew_ms' => $skew]]);
        }
        // Captures demandées hors file de commandes (lecteurs antérieurs) ; les autres passent par leur commande.
        $viaCommand = DB::table('device_commands')->where('device_id', $device->id)->where('command', 'screenshot')->whereIn('status', ['pending', 'sent', 'received'])->pluck('params')
            ->map(fn ($p) => json_decode((string) $p, true)['screenshot_id'] ?? null)->filter()->all();
        $screenshots = DeviceScreenshot::query()->where('device_id', $device->id)->where('status', 'requested')->where('expires_at', '>', now())->whereNotIn('id', $viaCommand)->pluck('id');

        return response()->json([
            'server_time' => now()->toIso8601ZuluString(), 'clock_skew_ms' => $skew, 'next_heartbeat_s' => (int) config('saie.device.heartbeat_s'),
            'screenshot_requests' => $screenshots, 'commands' => app(RemoteCommands::class)->deliver($device),
            // §9.6 : renouvellement des identifiants demandé par l'administration.
            'rotate_token' => $device->rotate_requested_at !== null,
            // Bloc 3 : chiffrement du jeton au repos côté lecteur, activé par la DSI.
            'secure_storage' => (bool) Setting::effective('devices.secure_storage'),
        ]);
    }

    /**
     * Accusé d'une commande à distance par le lecteur : réception, puis exécution ou échec avec compte rendu (§11.2).
     */
    public function acknowledgeCommand(Request $request, DeviceCommand $command, RemoteCommands $commands): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:received,executed,failed'], 'message' => ['nullable', 'string', 'max:500'],
            'data' => ['nullable', 'array'], 'data.errors' => ['nullable', 'array', 'max:50'], 'data.errors.*' => ['string', 'max:300'],
        ]);
        // Le compte rendu est libre selon la commande (journaux, état de la dalle…) : conservé tel quel, mais borné en taille.
        $report = $request->input('data');
        if (strlen((string) json_encode($report ?? [])) > 32768) {
            throw new BusinessException('RESULT_TOO_LARGE', 'Compte rendu trop volumineux (32 Kio au plus).', 422);
        }
        $result = $commands->acknowledge($this->device(), $command, $data['status'], $data['message'] ?? null, is_array($report) ? $report : null);

        return response()->json(['data' => ['id' => $result->id, 'status' => $result->status]]);
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
            // Horloge du lecteur déréglée : un instant dans le futur est ramené à l'heure de réception (jamais de preuve postdatée).
            'occurred_at' => CarbonImmutable::parse($e['occurred_at'])->utc()->min(now()->utc()), 'received_at' => now(), 'payload' => isset($e['payload']) ? json_encode($e['payload']) : null,
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
        // « Dernière synchronisation » : seul un manifeste vérifié et reçu par le lecteur la fait avancer, jamais un simple heartbeat.
        $synced = $rows->whereIn('kind', ['manifest_received', 'activated']);
        if ($synced->isNotEmpty()) {
            $activated = $this->boundedVersion($device, collect($data['events'])->where('kind', 'activated')->max('manifest_version'));
            $device->forceFill([
                'active_manifest_version' => $activated ? max((int) $device->active_manifest_version, $activated) : $device->active_manifest_version,
                'last_sync_at' => $synced->max('occurred_at'),
            ])->save();
        }

        return response()->json(['accepted' => $accepted, 'duplicates' => count($rows) - $accepted]);
    }

    /**
     * Version de manifeste déclarée par le lecteur, bornée à la dernière version émise pour lui (une valeur forgée ou erronée
     * ne peut pas faire croire à une synchronisation qui n'a pas eu lieu).
     */
    private function boundedVersion(Device $device, mixed $declared): ?int
    {
        if ($declared === null) {
            return null;
        }
        $issued = (int) Manifest::query()->where('device_id', $device->id)->max('version');

        return min((int) $declared, $issued);
    }

    /**
     * PLY-02 : renouvellement du jeton du lecteur. Le nouveau jeton est remis une seule fois ; l'ancien expire 10 minutes plus tard
     * (chevauchement pour les appels déjà en cours), de sorte qu'un jeton dérobé cesse de valoir au renouvellement suivant.
     */
    /**
     * §10.16 : jeton propre à l'agent local, transmis par le lecteur à l'agent sur le poste. Portée « agent » seulement :
     * il ne donne accès ni au programme ni aux médias. Un nouveau jeton remplace les précédents.
     */
    public function agentToken(): JsonResponse
    {
        $device = $this->device();
        $device->tokens()->where('name', 'agent')->delete();
        $expires = now()->addDays((int) config('saie.device.token_ttl_days'));

        return response()->json(['token' => $device->createToken('agent', ['agent'], $expires)->plainTextToken, 'expires_at' => $expires->toIso8601ZuluString()]);
    }

    /**
     * §10.16 : battement de l'agent — le poste répond même si l'application de lecture est arrêtée.
     */
    public function agentHeartbeat(Request $request): JsonResponse
    {
        $device = $request->user();
        if (! $device instanceof Device || ! $device->isActive() || ! $device->tokenCan('agent') || $device->tokenCan('device')) {
            throw new AuthenticationException('Agent non reconnu.');
        }
        $data = $request->validate([
            'agent_version' => ['required', 'string', 'max:20'], 'player_contact_at' => ['nullable', 'date'], 'uptime_s' => ['nullable', 'integer', 'min:0'],
            'disk_free_gb' => ['nullable', 'numeric'], 'mem_free_gb' => ['nullable', 'numeric'], 'temperature_c' => ['nullable', 'numeric', 'between:-40,150'],
        ]);
        $device->forceFill(['agent_seen_at' => now(), 'agent_report' => $data])->save();

        return response()->json(['ok' => true, 'server_time' => now()->toIso8601ZuluString()]);
    }

    public function rotateToken(Request $request): JsonResponse
    {
        $device = $this->device();
        $current = $request->user()->currentAccessToken();
        $expires = now()->addDays((int) config('saie.device.token_ttl_days'));
        $token = $device->createToken('device', ['device'], $expires)->plainTextToken;
        $current?->forceFill(['expires_at' => now()->addMinutes(10)])->save();
        $device->forceFill(['rotate_requested_at' => null])->save();
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
