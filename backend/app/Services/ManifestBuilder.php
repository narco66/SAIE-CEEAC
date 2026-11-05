<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AssetVersion;
use App\Models\Campaign;
use App\Models\ContentVersion;
use App\Models\Device;
use App\Models\FallbackContent;
use App\Models\Floor;
use App\Models\Holiday;
use App\Models\Manifest;
use App\Models\PlayerRelease;
use App\Models\ReservationOccurrence;
use App\Models\Room;
use App\Models\Screen;
use App\Models\Setting;
use App\Models\Structure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compile le manifeste minimal autorisé d'un lecteur (PLY-03, annexe A). Le manifeste ne contient jamais de contenu restreint,
 * ni de titre, d'organisateur ou de participant d'une réunion confidentielle (DOC-04, SAL-09, PUB-09).
 */
class ManifestBuilder
{
    private const CLASS_RANK = ['public' => 0, 'interne' => 1, 'restreint' => 2];

    public function __construct(private ManifestSigner $signer) {}

    /*
     * Les sources dynamiques (PUB-10) sont relevées par le serveur et intégrées telles quelles : le lecteur ne contacte jamais d'URL externe.
     */

    /**
     * Retourne le manifeste courant, régénéré si son contenu a changé ou si son autorisation arrive à mi-vie.
     */
    public function current(Device $device): Manifest
    {
        $content = $this->compile($device);
        $hash = hash('sha256', json_encode($content));
        $last = Manifest::query()->where('device_id', $device->id)->orderByDesc('version')->first();
        $internalTtl = (int) config('saie.device.manifest_validity_internal_h') * 3600;
        if ($last && $last->etag === $hash && $last->generated_at->addSeconds((int) ($internalTtl / 2))->isFuture()) {
            return $last;
        }

        return DB::transaction(function () use ($device, $content, $hash, $last): Manifest {
            $now = CarbonImmutable::now()->utc();
            $publicUntil = $now->addHours((int) config('saie.device.manifest_validity_public_h'));
            $internalUntil = $now->addHours((int) config('saie.device.manifest_validity_internal_h'));
            $version = ($last?->version ?? 0) + 1;
            $payload = [
                'format' => 'saie-manifest/1',
                'manifest_id' => (string) Str::uuid(),
                'device_id' => $device->code,
                'version' => $version,
                'generated_at' => $now->toIso8601ZuluString(),
                'valid_until' => $publicUntil->toIso8601ZuluString(),
                'authorization' => ['public' => $publicUntil->toIso8601ZuluString(), 'interne' => $internalUntil->toIso8601ZuluString()],
                'content_hash' => $hash,
            ] + $content;

            return Manifest::query()->create([
                'device_id' => $device->id, 'version' => $version, 'etag' => $hash, 'payload' => $payload,
                'valid_until' => $publicUntil, 'generated_at' => $now,
            ]);
        });
    }

    /**
     * Enveloppe signée transmise au lecteur : la signature porte sur la chaîne exacte du manifeste.
     *
     * @return array<string, mixed>
     */
    public function envelope(Manifest $manifest): array
    {
        $json = json_encode($manifest->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'format' => 'saie-manifest-envelope/1',
            'version' => $manifest->version,
            'etag' => $manifest->etag,
            'payload' => $json,
            'signature' => ['alg' => 'Ed25519', 'key_id' => $this->signer->keyId(), 'value' => $this->signer->sign($json)],
        ];
    }

    /**
     * Partie déterministe du manifeste (son empreinte sert d'ETag).
     *
     * @return array<string, mixed>
     */
    public function compile(Device $device): array
    {
        /** @var Screen $screen */
        $screen = $device->screen()->with(['location.floor.building', 'location.zone', 'room.floor', 'groups'])->firstOrFail();
        $release = PlayerRelease::targetFor($device->release_channel ?? 'stable');

        return $this->compileForScreen($screen) + [
            // Version cible du lecteur sur son canal (PLY-12), transmise dans le manifeste signé.
            'player_release' => $release ? ['version' => $release->version, 'channel' => $release->channel, 'url' => $release->base_url, 'sha256' => $release->sha256] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function compileForScreen(Screen $screen): array
    {
        $screen->loadMissing(['location.floor.building', 'location.zone', 'room.floor', 'groups']);
        $zone = $screen->location->zone;
        $floor = $screen->location->floor;
        $maxRank = self::CLASS_RANK[$zone->max_classification] ?? 0;
        $tz = config('saie.timezone');
        $assets = [];

        $schedule = $screen->kind === 'display' ? $this->scheduleFor($screen, $maxRank, $assets) : [];
        $alerts = $this->alertsFor($screen);
        $fallback = $this->fallbackFor($zone->id, $assets);

        $window = $screen->operating_window ?: config('saie.operating_window');
        $audioAllowed = $screen->audio_enabled && $zone->audio_allowed;

        $manifest = [
            'timezone' => $tz,
            'screen' => [
                'code' => $screen->code, 'name' => $screen->name, 'kind' => $screen->kind, 'orientation' => $screen->orientation, 'resolution' => $screen->resolution, 'control_channel' => $screen->control_channel,
                'location' => $screen->location->name, 'floor' => $floor->label, 'building' => $floor->building->name, 'zone' => $zone->code,
            ],
            'operating_window' => [
                'days' => array_map('intval', $window['days'] ?? [1, 2, 3, 4, 5]), 'on' => $window['on'] ?? '08:00', 'off' => $window['off'] ?? '18:00',
                'wake_before_min' => (int) ($window['wake_before_min'] ?? 5), 'override_reason' => $screen->window_override_reason,
            ],
            'non_working_days' => Holiday::query()->whereBetween('date', [now($tz)->subDay()->toDateString(), now($tz)->addDays(60)->toDateString()])->orderBy('date')->pluck('date')->map(fn ($d) => $d->format('Y-m-d'))->all(),
            'audio_policy' => ['enabled' => $audioAllowed, 'max_volume' => $audioAllowed ? (int) $zone->audio_max_volume : 0, 'hours' => ['on' => '08:00', 'off' => '18:00']],
            'schedule' => $schedule,
            'alerts' => $alerts,
            'agenda' => $this->agendaFor($floor),
            'fallback' => $fallback,
            'institution' => ['name' => 'Commission de la CEEAC', 'tagline' => (string) Setting::get('display.tagline', 'Communauté Économique des États de l’Afrique Centrale')],
            'dynamic' => (object) app(DynamicSources::class)->forManifest(),
        ];
        if ($screen->kind === 'room_panel' && $screen->room) {
            $manifest['room_panel'] = $this->roomPanelFor($screen->room);
        }
        if ($screen->kind === 'orientation') {
            $manifest['orientation'] = $this->orientationFor($floor->building_id, $assets);
        }
        ksort($assets);
        $manifest['assets'] = array_values($assets);

        return $manifest;
    }

    /**
     * Identifiants des campagnes ciblant un écran, via écran, groupe, zone ou niveau.
     *
     * @return list<string>
     */
    public function campaignIdsFor(Screen $screen): array
    {
        $groupIds = $screen->groups->pluck('id')->all();

        return DB::table('campaign_targets')->where(function ($q) use ($screen, $groupIds): void {
            $q->where(fn ($q) => $q->where('target_type', 'screen')->where('target_id', $screen->id))
                ->orWhere(fn ($q) => $q->where('target_type', 'zone')->where('target_id', $screen->location->zone_id))
                ->orWhere(fn ($q) => $q->where('target_type', 'floor')->where('target_id', $screen->location->floor_id));
            if ($groupIds !== []) {
                $q->orWhere(fn ($q) => $q->where('target_type', 'group')->whereIn('target_id', $groupIds));
            }
        })->distinct()->pluck('campaign_id')->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $assets
     * @return list<array<string, mixed>>
     */
    private function scheduleFor(Screen $screen, int $maxRank, array &$assets): array
    {
        $campaigns = Campaign::query()->with(['items.content', 'languages'])
            ->whereIn('id', $this->campaignIdsFor($screen))
            ->whereIn('status', ['scheduled', 'active'])
            ->where('ends_at', '>', now())
            ->where('starts_at', '<', now()->addDays(7))
            ->orderBy('id')->get();

        $entries = [];
        foreach ($campaigns as $campaign) {
            foreach ($campaign->items as $item) {
                $content = $item->content;
                if (! $content || in_array($content->status, ['archived', 'suspended', 'expired'], true) || (self::CLASS_RANK[$content->classification] ?? 2) > $maxRank) {
                    continue;
                }
                $to = $campaign->ends_at;
                if (! $content->is_permanent && $content->expires_at && $content->expires_at->lt($to)) {
                    $to = $content->expires_at;
                }
                foreach ($campaign->languages as $language) {
                    $versionId = $item->version_map[$language->lang] ?? null;
                    $version = $versionId ? ContentVersion::query()->find($versionId) : null;
                    if (! $version || (self::CLASS_RANK[$version->classification] ?? 2) > $maxRank) {
                        continue;
                    }
                    $slide = $this->slide($version, $assets, $item->duration_s ?? $campaign->slide_duration_s);
                    $entries[] = [
                        'entry_id' => $item->id.':'.$language->lang,
                        'campaign_id' => $campaign->id,
                        'campaign_ref' => $campaign->reference,
                        'content_id' => $content->id,
                        'content_version' => $version->id,
                        'lang' => $language->lang,
                        'auth_class' => $version->classification,
                        'priority' => (int) $campaign->priority,
                        'event' => $campaign->kind === 'event',
                        'position' => (int) $item->position,
                        'rotation_rank' => (int) $language->rank,
                        'from' => $campaign->starts_at->utc()->toIso8601ZuluString(),
                        'to' => $to->utc()->toIso8601ZuluString(),
                        'days' => $campaign->days ? array_map('intval', $campaign->days) : [],
                        'time_from' => $campaign->time_from ? substr($campaign->time_from, 0, 5) : null,
                        'time_to' => $campaign->time_to ? substr($campaign->time_to, 0, 5) : null,
                        'duration_s' => $slide['duration_s'],
                        'slide' => $slide,
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, array<string, mixed>>  $assets
     * @return array<string, mixed>
     */
    private function slide(ContentVersion $version, array &$assets, int $defaultDuration): array
    {
        $payload = $version->payload;
        $media = [];
        foreach ($payload['media'] ?? [] as $item) {
            if ($this->registerAsset($item['asset_version_id'], $assets)) {
                $media[] = ['asset' => $item['asset_version_id'], 'kind' => $item['kind'], 'pages' => $item['pages'] ?? null, 'page_duration_s' => $item['page_duration_s'] ?? null, 'role' => $item['role'] ?? 'main'];
            }
        }
        $subtitles = null;
        if (! empty($payload['subtitles_asset_version_id']) && $this->registerAsset($payload['subtitles_asset_version_id'], $assets)) {
            $subtitles = $payload['subtitles_asset_version_id'];
        }
        $pdfPages = collect($media)->where('kind', 'pdf')->sum(fn ($m) => count($m['pages'] ?? [1]) * (int) ($m['page_duration_s'] ?? $defaultDuration));
        $textDuration = max(10, (int) ceil(mb_strlen(($payload['title'] ?? '').' '.($payload['body'] ?? '')) / 18));

        return [
            'title' => $payload['title'] ?? '', 'body' => $payload['body'] ?? null, 'category' => $payload['category'] ?? null, 'kind' => $payload['kind'] ?? 'standard',
            'lang' => $version->lang, 'media' => $media, 'subtitles' => $subtitles, 'qr' => $this->publicQr($payload['qr'] ?? null, $version->classification),
            'layout' => $payload['template']['layout'] ?? null, 'delegation' => $payload['delegation'] ?? null,
            'duration_s' => max($defaultDuration, $textDuration, (int) $pdfPages),
        ];
    }

    /**
     * Un QR visible ne porte qu'un code court résolu par /q/{code} : jamais d'URL signée (DOC-05).
     *
     * @param  array<string, mixed>|null  $qr
     * @return array<string, string>|null
     */
    private function publicQr(?array $qr, string $classification): ?array
    {
        if (! $qr || empty($qr['code'])) {
            return null;
        }

        return ['url' => rtrim((string) config('app.frontend_url'), '/').'/q/'.$qr['code'], 'label' => (string) ($qr['label'] ?? ($classification === 'public' ? 'En savoir plus' : 'Consultation authentifiée'))];
    }

    /**
     * @param  array<string, array<string, mixed>>  $assets
     */
    private function registerAsset(string $assetVersionId, array &$assets): bool
    {
        if (isset($assets[$assetVersionId])) {
            return true;
        }
        $version = AssetVersion::query()->with('asset')->find($assetVersionId);
        if (! $version || $version->scan_status !== 'ready' || $version->asset->classification === 'restreint' || ! $version->sha256) {
            return false;
        }
        $assets[$assetVersionId] = [
            'id' => $version->id, 'sha256' => $version->sha256, 'size' => (int) $version->size, 'type' => $version->mime, 'kind' => $version->asset->kind,
            'lang' => $version->asset->language, 'href' => '/api/v1/device/assets/'.$version->id,
        ];

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alertsFor(Screen $screen): array
    {
        $groupIds = $screen->groups->pluck('id')->all();

        return Alert::query()->where('status', 'active')->where('expires_at', '>', now())->where('starts_at', '<', now()->addMinutes(10))
            ->whereHas('targets', function ($q) use ($screen, $groupIds): void {
                $q->where('target_type', 'all')
                    ->orWhere(fn ($q) => $q->where('target_type', 'screen')->where('target_id', $screen->id))
                    ->orWhere(fn ($q) => $q->where('target_type', 'zone')->where('target_id', $screen->location->zone_id))
                    ->orWhere(fn ($q) => $q->where('target_type', 'floor')->where('target_id', $screen->location->floor_id))
                    ->orWhere(fn ($q) => $q->where('target_type', 'group')->whereIn('target_id', $groupIds ?: ['00000000-0000-0000-0000-000000000000']));
            })->orderBy('starts_at')->orderBy('id')->get()
            ->map(fn (Alert $a): array => [
                'id' => $a->id, 'reference' => $a->reference, 'severity' => $a->severity, 'title' => $a->title, 'message' => $a->message,
                'translations' => $a->translations ?? (object) [], 'starts_at' => $a->starts_at->utc()->toIso8601ZuluString(), 'expires_at' => $a->expires_at->utc()->toIso8601ZuluString(),
            ])->all();
    }

    /**
     * Agenda du jour du bâtiment : réunions confirmées, « Réservée » pour les réunions confidentielles (PUB-09).
     *
     * @return list<array<string, string>>
     */
    private function agendaFor(Floor $floor): array
    {
        $floorIds = Floor::query()->where('building_id', $floor->building_id)->pluck('id')->all();

        return app(ReservationService::class)->todayFor($floorIds)
            ->map(fn (ReservationOccurrence $o): array => [
                'room' => $o->room->name, 'floor' => $o->room->floor->label,
                'label' => $o->reservation->confidential ? 'Réservée' : $o->reservation->subject,
                'start' => $o->starts_at->utc()->toIso8601ZuluString(), 'end' => $o->ends_at->utc()->toIso8601ZuluString(),
            ])->values()->all();
    }

    /**
     * Panneau de salle (SAL-09, T03) : aucune donnée confidentielle n'entre dans le manifeste.
     *
     * @return array<string, mixed>
     */
    private function roomPanelFor(Room $room): array
    {
        $tz = config('saie.timezone');
        $occurrences = ReservationOccurrence::query()->with('reservation')->where('room_id', $room->id)->whereIn('status', ['confirmed', 'in_progress'])
            ->where('ends_at', '>', now())->where('starts_at', '<', now($tz)->addDays(1)->endOfDay()->utc())->orderBy('starts_at')->limit(12)->get();
        $blocks = $room->blocks()->where('ends_at', '>', now())->where('starts_at', '<', now()->addDays(2))->orderBy('starts_at')->get();

        return [
            'room' => ['code' => $room->code, 'name' => $room->name, 'capacity' => $room->capacity, 'floor' => $room->floor->label, 'open_from' => substr($room->open_from, 0, 5), 'open_to' => substr($room->open_to, 0, 5), 'status' => $room->status],
            'occurrences' => $occurrences->map(fn (ReservationOccurrence $o): array => [
                'start' => $o->starts_at->utc()->toIso8601ZuluString(), 'end' => $o->ends_at->utc()->toIso8601ZuluString(),
                'label' => $o->reservation->confidential ? 'Réservée' : $o->reservation->subject,
                'participants' => $o->reservation->confidential ? null : $o->reservation->participant_count,
                'confidential' => $o->reservation->confidential,
            ])->all(),
            'blocks' => $blocks->map(fn ($b): array => ['start' => $b->starts_at->utc()->toIso8601ZuluString(), 'end' => $b->ends_at->utc()->toIso8601ZuluString(), 'label' => 'Indisponible'])->all(),
        ];
    }

    /**
     * Vue d'orientation générée depuis les référentiels, sans saisie manuelle (ACC-01, T05).
     *
     * @param  array<string, array<string, mixed>>  $assets
     * @return array<string, mixed>
     */
    private function orientationFor(string $buildingId, array &$assets): array
    {
        $floors = Floor::query()->where('building_id', $buildingId)->where('is_active', true)->orderByDesc('level')->get();
        $structures = Structure::query()->where('is_active', true)->where('show_in_orientation', true)->whereIn('floor_id', $floors->pluck('id'))->orderBy('name')->get()->groupBy('floor_id');
        $rooms = Room::query()->where('status', 'active')->whereIn('floor_id', $floors->pluck('id'))->orderBy('name')->get()->groupBy('floor_id');
        $welcomes = ContentVersion::query()
            ->whereIn('id', DB::table('campaign_items')->join('campaigns', 'campaigns.id', '=', 'campaign_items.campaign_id')
                ->whereIn('campaigns.status', ['scheduled', 'active'])->where('campaigns.ends_at', '>', now())
                ->join('contents', 'contents.id', '=', 'campaign_items.content_id')->where('contents.kind', 'welcome')->where('contents.classification', 'public')
                ->pluck('campaign_items.version_map')->flatMap(fn ($map) => array_values(json_decode((string) $map, true) ?? [])))
            ->get();

        return [
            'floors' => $floors->map(fn (Floor $f): array => [
                'level' => $f->level, 'label' => $f->label,
                'structures' => ($structures[$f->id] ?? collect())->pluck('name')->values()->all(),
                'rooms' => ($rooms[$f->id] ?? collect())->map(fn (Room $r): array => ['name' => $r->name, 'capacity' => $r->capacity])->values()->all(),
            ])->values()->all(),
            'welcomes' => $welcomes->map(fn (ContentVersion $v): array => ['lang' => $v->lang, 'title' => $v->payload['title'] ?? '', 'body' => $v->payload['body'] ?? '', 'delegation' => $v->payload['delegation'] ?? null])->values()->all(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $assets
     * @return array<string, mixed>
     */
    private function fallbackFor(string $zoneId, array &$assets): array
    {
        $fallback = FallbackContent::query()->with('contentVersion')->where('zone_id', $zoneId)->where('is_active', true)->where('valid_until', '>', now())->orderByDesc('created_at')->first();
        if (! $fallback || $fallback->contentVersion->classification !== 'public') {
            return ['content_version' => null, 'valid_until' => null, 'slide' => null];
        }

        return [
            'content_version' => $fallback->content_version_id,
            'valid_until' => $fallback->valid_until->utc()->toIso8601ZuluString(),
            'slide' => $this->slide($fallback->contentVersion, $assets, 15),
        ];
    }
}
