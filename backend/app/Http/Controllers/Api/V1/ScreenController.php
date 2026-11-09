<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessLink;
use App\Models\Campaign;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceExchange;
use App\Models\DeviceReceipt;
use App\Models\DeviceScreenshot;
use App\Models\DeviceTelemetry;
use App\Models\Document;
use App\Models\Incident;
use App\Models\PairingCode;
use App\Models\Screen;
use App\Models\ScreenGroup;
use App\Models\ScreenLocationHistory;
use App\Models\ScreenStatusHistory;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\DocumentAccess;
use App\Services\DynamicGroups;
use App\Services\FleetOverview;
use App\Services\ManifestBuilder;
use App\Services\RemoteCommands;
use App\Services\ScheduleResolver;
use App\Services\ScreenHistory;
use App\Support\BusinessException;
use App\Support\Present;
use App\Support\XlsxReader;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
        $scope = $this->screenScopeFor('screens.view', 'campaigns.view', 'alerts.manage');
        $query = Screen::query()->with(['location.floor.building', 'location.zone', 'room', 'groups', 'activeDevice', 'category'])
            // HAB-01 : seuls les écrans du site et de la zone de l'habilitation sont listés.
            ->when($scope !== null, fn ($q) => $q->whereIn('screens.id', $scope))
            // Les écrans archivés ne sont listés que sur demande explicite (fiches conservées, hors parc).
            ->when($request->query('status'), fn ($q, $status) => $q->whereIn('status', explode(',', $status)), fn ($q) => $q->where('status', '!=', 'archived'))
            ->when($request->query('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->when($request->query('floor_id'), fn ($q, $id) => $q->whereHas('location', fn ($l) => $l->where('floor_id', $id)))
            ->when($request->query('zone_id'), fn ($q, $id) => $q->whereHas('location', fn ($l) => $l->where('zone_id', $id)))
            ->when($request->query('group_id'), fn ($q, $id) => $q->whereHas('groups', fn ($g) => $g->where('screen_groups.id', $id)))
            ->when($request->query('room_id'), fn ($q, $id) => $q->where('room_id', $id))
            ->when($request->query('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->query('criticality'), fn ($q, $c) => $q->whereIn('criticality', explode(',', (string) $c)))
            ->when($request->query('commissioned') !== null, fn ($q) => $request->boolean('commissioned') ? $q->whereNotNull('commissioned_at') : $q->whereNull('commissioned_at'))
            ->when($request->query('building_id'), fn ($q, $id) => $q->whereHas('location.floor', fn ($f) => $f->where('building_id', $id)))
            // §5.5-h : code, nom, code d'inventaire, marque, modèle, n° de série, emplacement, salle ou code du lecteur.
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('code', 'ilike', "%$term%")->orWhere('name', 'ilike', "%$term%")
                ->orWhere('inventory_code', 'ilike', "%$term%")->orWhere('serial_number', 'ilike', "%$term%")->orWhere('manufacturer', 'ilike', "%$term%")->orWhere('model', 'ilike', "%$term%")
                ->orWhereHas('location', fn ($l) => $l->where('name', 'ilike', "%$term%"))->orWhereHas('room', fn ($r) => $r->where('name', 'ilike', "%$term%"))
                ->orWhereHas('activeDevice', fn ($d) => $d->where('code', 'ilike', "%$term%"))));
        $network = $request->query('network');
        if ($network) {
            $suspect = now()->subSeconds(Device::thresholds()['suspect']);
            $unavailable = now()->subSeconds(Device::thresholds()['unavailable']);
            $query->where(match ($network) {
                'online' => fn ($q) => $q->whereHas('activeDevice', fn ($d) => $d->where('last_seen_at', '>=', $suspect)),
                'suspect' => fn ($q) => $q->whereHas('activeDevice', fn ($d) => $d->whereBetween('last_seen_at', [$unavailable, $suspect])),
                'unavailable' => fn ($q) => $q->whereHas('activeDevice', fn ($d) => $d->where(fn ($w) => $w->where('last_seen_at', '<', $unavailable)->orWhereNull('last_seen_at'))),
                'unpaired' => fn ($q) => $q->whereDoesntHave('activeDevice'),
                default => fn ($q) => $q,
            });
        }
        // §4.1 : filtre par état opérationnel (calculé), utilisé par les cartes du tableau de bord du parc.
        if ($state = $request->query('state')) {
            $candidates = (clone $query)->with('activeDevice')->get();
            $open = Incident::query()->whereIn('status', ['open', 'acknowledged'])->get(['device_id', 'screen_id']);
            $flagged = $open->pluck('device_id')->merge($open->pluck('screen_id'))->filter()->unique()->values()->all();
            $wanted = explode(',', (string) $state);
            $query->whereIn('screens.id', $candidates->filter(fn (Screen $s) => in_array(Present::operationalState($s, $s->activeDevice, $flagged)['code'], $wanted, true))->pluck('id'));
        }
        $this->applySort($query, $request, ['code', 'name', 'created_at'], 'code', 'asc');
        $totals = (clone $query)->reorder()->get()->groupBy(fn (Screen $s) => $s->activeDevice?->computedNetworkStatus() ?? 'unpaired')->map->count();
        // Incidents ouverts chargés une fois pour la page (état opérationnel), et non une requête par écran.
        $open = Incident::query()->whereIn('status', ['open', 'acknowledged'])->get(['device_id', 'screen_id']);
        $withIncident = $open->pluck('device_id')->merge($open->pluck('screen_id'))->filter()->unique()->values()->all();
        $payload = $this->paginated($query, $request, fn (Screen $s) => Present::screen($s, $withIncident), 50)->getData(true);
        $payload['meta']['network_totals'] = ['online' => $totals['online'] ?? 0, 'suspect' => $totals['suspect'] ?? 0, 'unavailable' => $totals['unavailable'] ?? 0, 'unpaired' => $totals['unpaired'] ?? 0, 'unknown' => $totals['unknown'] ?? 0];

        return response()->json($payload);
    }

    /**
     * Tableau de bord du parc (§4) : indicateurs, répartitions, tendances et vue par niveau, bornés au périmètre.
     */
    public function overview(FleetOverview $overview): JsonResponse
    {
        $this->authorizePermission('screens.view', 'supervision.view');

        return response()->json(['data' => $overview->build($this->screenScopeFor('screens.view', 'supervision.view'))]);
    }

    public function show(Screen $screen): JsonResponse
    {
        $this->authorizePermission('screens.view', 'campaigns.view');
        $this->assertScreenInScope($screen->id, 'screens.view', 'campaigns.view');
        $screen->load(['location.floor.building', 'location.zone', 'room', 'groups', 'activeDevice', 'category', 'commissioner']);
        $data = Present::screen($screen);
        $data['devices_history'] = $screen->devices()->orderByDesc('created_at')->get()->map(fn (Device $d) => Present::device($d));
        $data['current_playback_resolved'] = $this->resolvePlayback($screen->activeDevice);
        // §5.4-h : dernière lecture confirmée par un accusé « played » pour cet écran (programme émis pour cet écran, même
        // si le lecteur a été réaffecté depuis).
        $lastPlayed = DB::table('device_receipts')->join('manifests', fn ($j) => $j->on('manifests.device_id', '=', 'device_receipts.device_id')->on('manifests.version', '=', 'device_receipts.manifest_version'))
            ->where('device_receipts.kind', 'played')->whereRaw("manifests.payload->'screen'->>'code' = ?", [$screen->code])->max('device_receipts.occurred_at');
        $data['last_played_at'] = $lastPlayed ? CarbonImmutable::parse($lastPlayed)->toIso8601String() : null;
        // §5.3-g/h : responsable et photographie de l'emplacement.
        $data['location_extra'] = $screen->location ? [
            'manager' => $screen->location->manager_id ? Present::userRef(User::query()->find($screen->location->manager_id)) : null,
            'photo_asset_version_id' => $screen->location->photo_asset_version_id,
        ] : null;
        $data['last_screenshot'] = $screen->activeDevice ? DeviceScreenshot::query()->where('device_id', $screen->activeDevice->id)->orderByDesc('requested_at')->first() : null;
        $data['manifest'] = $screen->activeDevice ? $screen->activeDevice->manifests()->orderByDesc('version')->first(['version', 'etag', 'generated_at', 'valid_until']) : null;
        $data['audit'] = DB::table('audit_logs')->where('object_type', 'Screen')->where('object_id', $screen->id)->orderByDesc('occurred_at')->limit(20)->get(['occurred_at', 'actor_label', 'action', 'reason']);
        $data['location_history'] = ScreenLocationHistory::query()->with(['location.floor', 'room', 'mover'])->where('screen_id', $screen->id)->orderByDesc('from_at')->limit(20)->get()
            ->map(fn (ScreenLocationHistory $h) => [
                'location' => $h->location?->name, 'floor' => $h->location?->floor?->label, 'room' => $h->room?->name, 'detail' => $h->location_detail,
                'from_at' => Present::date($h->from_at), 'to_at' => Present::date($h->to_at), 'moved_by' => Present::userRef($h->mover), 'reason' => $h->reason,
            ]);
        $data['documents'] = DB::table('screen_documents')->join('documents', 'documents.id', '=', 'screen_documents.document_id')->where('screen_documents.screen_id', $screen->id)
            ->orderBy('documents.title')->get(['documents.id', 'documents.reference', 'documents.title', 'documents.status', 'screen_documents.linked_at']);
        $profile = $screen->effectiveProfile();
        $expected = app(ManifestBuilder::class)->configFor($profile);
        $device = $screen->activeDevice;
        $data['configuration'] = [
            'profile' => $profile ? ['id' => $profile->id, 'code' => $profile->code, 'name' => $profile->name, 'version' => $profile->version, 'inherited' => $screen->profile_id === null] : null,
            'settings' => $expected['settings'], 'expected_hash' => $expected['hash'],
            // §7 : configuration « appliquée » seulement si le lecteur a confirmé la même empreinte.
            'sync' => ! $device ? 'no_device' : ($device->config_hash === null ? 'unknown' : ($device->config_hash === $expected['hash'] ? 'applied' : 'pending')),
            'reported_at' => Present::date($device?->config_reported_at), 'unsupported' => $device?->config_report['unsupported'] ?? [], 'applied' => $device?->config_report['applied'] ?? [],
        ];
        $data['qr'] = AccessLink::query()->where('target_type', 'screen')->where('target_id', $screen->id)->whereNull('expires_at')->value('code');

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $data = $this->normalized($this->validated($request));
        // ASVS 5.0 V8.2 : un écran n'est créé qu'à un emplacement du périmètre de l'habilitation.
        $screen = DB::transaction(function () use ($data): Screen {
            $screen = Screen::query()->create($data);
            $this->assertStillInScope($screen);
            $this->recordLocation($screen, 'Enregistrement de l’écran à cet emplacement');

            return $screen;
        });
        $this->audit()->log('screen.created', $screen, after: $data);

        return response()->json(['data' => Present::screen($screen->refresh()->load(['location.floor.building', 'location.zone', 'groups']))], 201);
    }

    /**
     * §10.9b/c : l'entrée déclarée en lecture est rapprochée du programme signé émis pour ce lecteur (titre, campagne).
     *
     * @return array{title: string|null, campaign_ref: string|null, campaign_id: string|null, lang: string|null, at: string|null, alert: bool}|null
     */
    private function resolvePlayback(?Device $device): ?array
    {
        $playback = (array) ($device?->current_playback ?? []);
        if (! $device || ! $playback) {
            return null;
        }
        if (isset($playback['alert'])) {
            return ['title' => 'Alerte en cours', 'campaign_ref' => null, 'campaign_id' => null, 'lang' => null, 'at' => $playback['at'] ?? null, 'alert' => true];
        }
        $entryId = $playback['entry_id'] ?? null;
        if (! $entryId) {
            return null;
        }
        $version = $device->active_manifest_version;
        $manifest = $device->manifests()->when($version, fn ($q) => $q->where('version', $version), fn ($q) => $q->orderByDesc('version'))->first(['payload']);
        $entry = collect($manifest?->payload['schedule'] ?? [])->firstWhere('entry_id', $entryId);

        return ['title' => $entry['slide']['title'] ?? null, 'campaign_ref' => $entry['campaign_ref'] ?? null, 'campaign_id' => $entry['campaign_id'] ?? null,
            'lang' => $playback['lang'] ?? null, 'at' => $playback['at'] ?? null, 'alert' => false];
    }

    /**
     * §9.12 : journaux techniques d'un lecteur — événements déclarés (erreurs, repli, coupures, reprises, hors plage) ou
     * relevés de télémétrie, paginés, bornés au périmètre.
     */
    public function deviceLogs(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission('screens.view', 'supervision.view');
        $this->assertScreenInScope((string) $device->screen_id, 'screens.view', 'supervision.view');
        $data = $request->validate(['source' => ['sometimes', 'in:events,telemetry,exchanges'], 'kind' => ['sometimes', 'in:error,fallback,offline,online,off_window']]);
        if (($data['source'] ?? 'events') === 'exchanges') {
            return $this->paginated(DeviceExchange::query()->where('device_id', $device->id)->orderByDesc('occurred_at'), $request, fn (DeviceExchange $e) => [
                'id' => (string) $e->id, 'occurred_at' => Present::date($e->occurred_at), 'payload' => ['route' => $e->method.' '.$e->endpoint, 'status' => $e->status, 'duration_ms' => $e->duration_ms, 'bytes' => $e->bytes_out, 'ip' => $e->ip],
            ], 50);
        }
        if (($data['source'] ?? 'events') === 'telemetry') {
            return $this->paginated(DeviceTelemetry::query()->where('device_id', $device->id)->orderByDesc('recorded_at'), $request, fn (DeviceTelemetry $t) => [
                'id' => $t->id, 'recorded_at' => Present::date($t->recorded_at), 'payload' => collect((array) $t->payload)->except(['cache_inventory'])->all(),
            ], 50);
        }
        $query = DeviceReceipt::query()->where('device_id', $device->id)->whereIn('kind', isset($data['kind']) ? [$data['kind']] : ['error', 'fallback', 'offline', 'online', 'off_window'])->orderByDesc('occurred_at');

        return $this->paginated($query, $request, fn (DeviceReceipt $r) => [
            'id' => $r->id, 'kind' => $r->kind, 'occurred_at' => Present::date($r->occurred_at), 'received_at' => Present::date($r->received_at),
            'manifest_version' => $r->manifest_version, 'ref_type' => $r->ref_type, 'ref_id' => $r->ref_id, 'payload' => $r->payload,
        ], 50);
    }

    /**
     * §15.1 : mise en service explicite. Les contrôles « communication et rendu » sont calculés sur des faits (lecteur
     * appairé et joignable, programme et configuration appliqués, rendu confirmé par un accusé de moins de 24 h) ; le
     * contrôle visuel est attesté par l'agent. Le compte rendu est conservé sur la fiche et journalisé.
     */
    /**
     * §9.2 : identité propre du lecteur (nom, type matériel, n° de série), distincte de celle de l'écran.
     */
    public function updateDevice(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $this->assertScreenInScope((string) $device->screen_id, 'screens.manage');
        $data = $request->validate(['name' => ['nullable', 'string', 'max:120'], 'hardware_type' => ['nullable', 'string', 'max:60'], 'serial_number' => ['nullable', 'string', 'max:80']]);
        $before = $device->only(array_keys($data));
        $device->forceFill($data)->save();
        $this->audit()->log('device.updated', $device, before: $before, after: $data);

        return response()->json(['data' => Present::device($device->refresh())]);
    }

    /**
     * §9.6 : renouvellement forcé des identifiants — le lecteur l'exécute au battement suivant ; l'ancien jeton cesse de
     * fonctionner dix minutes après la délivrance du nouveau.
     */
    public function rotateCredentials(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission('devices.revoke');
        $this->assertScreenInScope((string) $device->screen_id, 'devices.revoke');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        if ($device->status !== 'active') {
            throw new BusinessException('DEVICE_NOT_ACTIVE', 'Seul un lecteur actif peut renouveler ses identifiants.', 409);
        }
        $device->forceFill(['rotate_requested_at' => now()])->save();
        $this->audit()->log('device.rotation_requested', $device, $data['reason']);

        return response()->json(['data' => Present::device($device->refresh()), 'message' => 'Renouvellement demandé : il sera effectué au prochain battement du lecteur.']);
    }

    public function commission(Request $request, Screen $screen): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $this->inScope([['type' => 'screen', 'id' => $screen->id]], 'screens.manage');
        $data = $request->validate(['visual_check' => ['required', 'accepted'], 'note' => ['nullable', 'string', 'max:500']]);
        if ($screen->status === 'archived' || $screen->status === 'inactive') {
            throw new BusinessException('SCREEN_NOT_IN_SERVICE', 'Un écran désactivé ou archivé ne peut pas être mis en service.', 409);
        }
        $device = $screen->activeDevice;
        $latest = $device?->manifests()->orderByDesc('version')->value('version');
        $checks = [
            'device_paired' => $device !== null,
            'device_online' => $device?->computedNetworkStatus() === 'online',
            'programme_applied' => $device !== null && $latest !== null && (int) $device->active_manifest_version >= (int) $latest,
            'configuration_applied' => $device !== null && $device->config_hash !== null && $device->config_hash === app(ManifestBuilder::class)->configFor($screen->effectiveProfile())['hash'],
            'rendering_confirmed' => $device !== null && DeviceReceipt::query()->where('device_id', $device->id)->whereIn('kind', ['played', 'fallback', 'activated'])->where('occurred_at', '>=', now()->subDay())->exists(),
            'visual_check' => true,
        ];
        if (in_array(false, $checks, true)) {
            throw new BusinessException('COMMISSIONING_INCOMPLETE', 'Mise en service impossible : des contrôles de communication ou de rendu ne sont pas satisfaits.', 422, ['checks' => $checks]);
        }
        $report = ['checks' => $checks, 'note' => $data['note'] ?? null, 'device' => $device->code, 'manifest_version' => (int) $latest];
        $screen->forceFill(['commissioned_at' => now(), 'commissioned_by' => $this->user()->id, 'commissioning_report' => $report, 'version' => $screen->version + 1])->save();
        $this->audit()->log('screen.commissioned', $screen, $data['note'] ?? null, after: $report);

        return $this->show($screen->refresh());
    }

    public function update(Request $request, Screen $screen): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $this->inScope([['type' => 'screen', 'id' => $screen->id]], 'screens.manage');
        $this->expectVersion($request, $screen);
        if ($screen->status === 'archived') {
            throw new BusinessException('SCREEN_ARCHIVED', 'Écran archivé : sa fiche est conservée en lecture seule.', 409);
        }
        $data = $this->normalized($this->validated($request, $screen), $screen);
        $moveReason = $request->validate(['move_reason' => ['nullable', 'string', 'max:300']])['move_reason'] ?? null;
        $statusReason = $request->validate(['status_reason' => ['nullable', 'string', 'max:300']])['status_reason'] ?? null;
        $toggled = array_key_exists('status', $data) && $data['status'] !== $screen->status && in_array('inactive', [$data['status'], $screen->status], true);
        if ($toggled && mb_strlen(trim((string) $statusReason)) < 5) {
            throw new BusinessException('REASON_REQUIRED', 'La désactivation ou la réactivation d’un écran exige un motif (5 caractères au moins).', 422, ['field' => 'status_reason']);
        }
        $previousStatus = $screen->status;
        $moved = collect(['location_id', 'room_id', 'location_detail'])->contains(fn ($k) => array_key_exists($k, $data) && (string) $data[$k] !== (string) $screen->getAttribute($k));
        $windowChanged = array_key_exists('operating_window', $data) && json_encode($data['operating_window']) !== json_encode($screen->operating_window);
        if ($windowChanged && $data['operating_window'] !== null && empty($data['window_override_reason'])) {
            throw new BusinessException('REASON_REQUIRED', 'Une dérogation à la plage de fonctionnement exige un motif.', 422);
        }
        $before = $screen->only(array_keys($data));
        DB::transaction(function () use ($screen, $data, $moved, $moveReason): void {
            $screen->update($data + ['version' => $screen->version + 1]);
            // ASVS 5.0 V8.2 : un déplacement ne sort pas l'écran du périmètre de l'habilitation.
            if (array_key_exists('location_id', $data)) {
                $this->assertStillInScope($screen);
            }
            if ($moved) {
                $this->recordLocation($screen, $moveReason);
            }
        });
        if ($moved) {
            $this->audit()->log('screen.moved', $screen, $moveReason, collect($before)->only(['location_id', 'room_id', 'location_detail'])->all(), collect($data)->only(['location_id', 'room_id', 'location_detail'])->all());
        }
        if ($toggled) {
            $this->audit()->log($data['status'] === 'inactive' ? 'screen.deactivated' : 'screen.reactivated', $screen, $statusReason, ['status' => $previousStatus], ['status' => $data['status']]);
        }
        $this->audit()->log($windowChanged ? 'screen.window_changed' : 'screen.updated', $screen, $data['window_override_reason'] ?? null, $before, $data);

        return $this->show($screen->refresh());
    }

    /**
     * Import en masse des écrans (§5.5) depuis un fichier CSV (séparateur « ; » ou « , », UTF-8) : création ou mise à jour par
     * code. La simulation exécute l'import dans une transaction annulée (bilan exact, contrôle de périmètre compris) ; la
     * confirmation est atomique : une seule ligne invalide et rien n'est écrit.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:4096'], 'commit' => ['sometimes', 'boolean']]);
        $commit = (bool) ($data['commit'] ?? false);
        // §5.5 : fichier CSV (séparateur « ; » ou « , ») ou classeur Excel (première feuille), même modèle de colonnes.
        $rows = $this->importRows($data['file']);
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), array_shift($rows) ?? []);
        $missing = array_diff(self::IMPORT_REQUIRED, $header);
        if ($missing !== []) {
            throw new BusinessException('IMPORT_COLUMNS', 'Colonnes obligatoires absentes : '.implode(', ', $missing).'.', 422, ['field' => 'file']);
        }
        $lines = [];
        foreach ($rows as $row) {
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $line = array_combine($header, array_pad(array_slice(array_map('strval', $row), 0, count($header)), count($header), ''));
            foreach (['acquired_on', 'installed_on', 'warranty_until'] as $date) {
                if (isset($line[$date])) {
                    $line[$date] = XlsxReader::date($line[$date]);
                }
            }
            $lines[] = $line;
            if (count($lines) > 500) {
                throw new BusinessException('IMPORT_TOO_LARGE', 'Fichier limité à 500 écrans par import.', 422, ['field' => 'file']);
            }
        }

        $report = [];
        DB::beginTransaction();
        try {
            foreach ($lines as $index => $line) {
                $report[] = $this->importLine($index + 2, $line);
            }
            $valid = collect($report)->every(fn ($r) => $r['status'] !== 'invalid');
            $commit && $valid ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $summary = collect($report)->countBy('status')->all() + ['total' => count($report)];
        if ($commit && $valid) {
            $this->audit()->log('screen.imported', 'Screen', null, after: $summary, objectLabel: $data['file']->getClientOriginalName());
        }

        return response()->json(['data' => ['committed' => $commit && $valid, 'summary' => $summary, 'rows' => $report]]);
    }

    /**
     * @return list<list<string>> lignes du fichier, en-tête comprise
     */
    private function importRows(UploadedFile $file): array
    {
        if (strtolower($file->getClientOriginalExtension()) === 'xlsx' || $file->getMimeType() === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
            try {
                return XlsxReader::rows($file->getRealPath(), 502);
            } catch (\RuntimeException $e) {
                throw new BusinessException('IMPORT_UNREADABLE', $e->getMessage(), 422, ['field' => 'file']);
            }
        }
        $handle = fopen($file->getRealPath(), 'r');
        $first = (string) fgets($handle);
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $rows = [str_getcsv($first, $delimiter)];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false && count($rows) <= 502) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /** Colonnes obligatoires et facultatives du modèle d'import des écrans. */
    private const IMPORT_REQUIRED = ['code', 'name', 'kind', 'building_code', 'floor_code', 'location_code', 'orientation', 'resolution'];

    private const IMPORT_OPTIONAL = ['inventory_code', 'criticality', 'operating_mode', 'room_code', 'location_detail', 'notes', 'manufacturer', 'model', 'serial_number', 'diagonal_inches', 'technology', 'connection_type', 'ip_address',
        'mac_address', 'os_name', 'firmware_version', 'power_watts', 'acquired_on', 'installed_on', 'warranty_until', 'supplier', 'order_reference'];

    public function importTemplate(): StreamedResponse
    {
        $this->authorizePermission('screens.manage');

        return response()->streamDownload(function (): void {
            echo "\u{FEFF}".implode(';', array_merge(self::IMPORT_REQUIRED, self::IMPORT_OPTIONAL))."\n";
            // Ligne d'exemple construite colonne par colonne : elle reste alignée sur l'en-tête quand des colonnes s'ajoutent.
            $example = ['code' => 'ECR-2E-10', 'name' => 'Couloir 2e étage', 'kind' => 'display', 'building_code' => 'SIEGE', 'floor_code' => '2E', 'location_code' => 'COULOIR-2E',
                'orientation' => 'landscape', 'resolution' => '1920x1080', 'inventory_code' => 'PAT-2026-010', 'criticality' => 'normal', 'operating_mode' => 'window', 'location_detail' => 'Face aux ascenseurs',
                'manufacturer' => 'Samsung', 'model' => 'QM55B', 'serial_number' => 'SN0001', 'diagonal_inches' => '55', 'technology' => 'lcd', 'connection_type' => 'ethernet', 'ip_address' => '10.20.2.10',
                'mac_address' => '00:1A:2B:3C:4D:5E', 'os_name' => 'Tizen', 'firmware_version' => 'T-KSU2', 'power_watts' => '120', 'acquired_on' => '2026-01-15', 'installed_on' => '2026-02-01',
                'warranty_until' => '2029-02-01', 'supplier' => 'Fournisseur SA', 'order_reference' => 'BC-2026-014'];
            echo implode(';', array_map(fn (string $column) => $example[$column] ?? '', array_merge(self::IMPORT_REQUIRED, self::IMPORT_OPTIONAL)))."\n";
        }, 'modele-import-ecrans.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, string>  $line
     * @return array{line: int, code: string, status: string, errors: list<string>}
     */
    private function importLine(int $number, array $line): array
    {
        $code = strtoupper(trim($line['code'] ?? ''));
        $location = DB::table('locations')->join('floors', 'floors.id', '=', 'locations.floor_id')->join('buildings', 'buildings.id', '=', 'floors.building_id')
            ->where('buildings.code', trim($line['building_code']))->where('floors.code', trim($line['floor_code']))->where('locations.code', trim($line['location_code']))
            ->first(['locations.id', 'locations.floor_id']);
        if (! $location) {
            return ['line' => $number, 'code' => $code, 'status' => 'invalid', 'errors' => ['Emplacement introuvable : '.$line['building_code'].' / '.$line['floor_code'].' / '.$line['location_code'].'.']];
        }
        $roomId = trim($line['room_code'] ?? '') !== '' ? DB::table('rooms')->where('code', trim($line['room_code']))->where('floor_id', $location->floor_id)->value('id') : null;
        if (trim($line['room_code'] ?? '') !== '' && ! $roomId) {
            return ['line' => $number, 'code' => $code, 'status' => 'invalid', 'errors' => ['Salle '.$line['room_code'].' introuvable à ce niveau.']];
        }
        $attributes = collect(self::IMPORT_OPTIONAL)->reject(fn ($k) => $k === 'room_code')->mapWithKeys(fn ($k) => [$k => trim((string) ($line[$k] ?? '')) === '' ? null : trim((string) $line[$k])])->filter(fn ($v) => $v !== null)->all()
            + ['code' => $code, 'name' => trim($line['name']), 'kind' => trim($line['kind']), 'orientation' => trim($line['orientation']), 'resolution' => trim($line['resolution']), 'location_id' => $location->id, 'room_id' => $roomId];
        $existing = Screen::query()->where('code', $code)->first();
        if ($existing?->status === 'archived') {
            return ['line' => $number, 'code' => $code, 'status' => 'invalid', 'errors' => ['Écran archivé : sa fiche ne peut plus être modifiée.']];
        }
        $validator = Validator::make($attributes, $this->rules($existing));
        if ($validator->fails()) {
            return ['line' => $number, 'code' => $code, 'status' => 'invalid', 'errors' => $validator->errors()->all()];
        }
        try {
            $data = $this->normalized($validator->validated(), $existing);
            if ($existing) {
                $this->inScope([['type' => 'screen', 'id' => $existing->id]], 'screens.manage');
                $changes = collect($data)->filter(fn ($v, $k) => (string) $existing->getAttribute($k) !== (string) $v && ! ($existing->getAttribute($k) instanceof \DateTimeInterface && $existing->getAttribute($k)->format('Y-m-d') === $v));
                if ($changes->isEmpty()) {
                    return ['line' => $number, 'code' => $code, 'status' => 'unchanged', 'errors' => []];
                }
                $moved = $changes->has('location_id') || $changes->has('room_id') || $changes->has('location_detail');
                $existing->update($changes->all() + ['version' => $existing->version + 1]);
                $this->assertStillInScope($existing);
                if ($moved) {
                    $this->recordLocation($existing, 'Import CSV');
                }

                return ['line' => $number, 'code' => $code, 'status' => 'updated', 'errors' => []];
            }
            $screen = Screen::query()->create($data);
            $this->assertStillInScope($screen);
            $this->recordLocation($screen, 'Import CSV');

            return ['line' => $number, 'code' => $code, 'status' => 'created', 'errors' => []];
        } catch (AuthorizationException) {
            return ['line' => $number, 'code' => $code, 'status' => 'invalid', 'errors' => ['Emplacement hors du site ou de la zone de votre habilitation.']];
        } catch (BusinessException $e) {
            return ['line' => $number, 'code' => $code, 'status' => 'invalid', 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Code QR de la fiche (§5.5) : lien stable, réservé aux comptes habilités sur l'écran (jamais public).
     */
    public function qr(Screen $screen): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $this->inScope([['type' => 'screen', 'id' => $screen->id]], 'screens.manage');
        $link = AccessLink::query()->where('target_type', 'screen')->where('target_id', $screen->id)->whereNull('expires_at')->first()
            ?? AccessLink::query()->create(['target_type' => 'screen', 'target_id' => $screen->id, 'code' => Str::lower(Str::random(10)), 'classification' => 'interne', 'created_by' => $this->user()->id]);
        $this->audit()->log('screen.qr_generated', $screen);

        return response()->json(['data' => ['code' => $link->code, 'url' => url("/q/{$link->code}")]]);
    }

    /**
     * Documents rattachés à l'écran (§5.1 : facture, notice, procès-verbal de réception). Seul un document que l'auteur du
     * rattachement peut consulter est accepté.
     */
    public function attachDocument(Request $request, Screen $screen, DocumentAccess $documents): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $this->inScope([['type' => 'screen', 'id' => $screen->id]], 'screens.manage');
        $data = $request->validate(['document_id' => ['required', 'uuid', 'exists:documents,id']]);
        $document = Document::query()->findOrFail($data['document_id']);
        if (! $documents->canView($document, $this->user())) {
            $this->deny('Document non consultable par l’auteur du rattachement');
        }
        DB::table('screen_documents')->insertOrIgnore(['screen_id' => $screen->id, 'document_id' => $document->id, 'linked_by' => $this->user()->id, 'linked_at' => now()]);
        $this->audit()->log('screen.document_linked', $screen, after: ['document' => $document->reference]);

        return $this->show($screen);
    }

    public function detachDocument(Screen $screen, Document $document): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $this->inScope([['type' => 'screen', 'id' => $screen->id]], 'screens.manage');
        DB::table('screen_documents')->where('screen_id', $screen->id)->where('document_id', $document->id)->delete();
        $this->audit()->log('screen.document_unlinked', $screen, before: ['document' => $document->reference]);

        return $this->show($screen);
    }

    /**
     * Historique unifié de l'écran (§13.1, §13.2), filtrable par catégorie.
     */
    public function history(Request $request, Screen $screen, ScreenHistory $history): JsonResponse
    {
        $this->authorizePermission('screens.view', 'supervision.view');
        $this->assertScreenInScope($screen->id, 'screens.view', 'supervision.view');
        $data = $request->validate(['category' => ['nullable', 'in:'.implode(',', ScreenHistory::CATEGORIES)]]);

        return response()->json(['data' => $history->for($screen, $data['category'] ?? null), 'meta' => ['categories' => ScreenHistory::CATEGORIES]]);
    }

    /**
     * Historique des états opérationnels de l'écran (§10.1), du plus récent au plus ancien, avec la durée de chaque période.
     */
    public function statusHistory(Request $request, Screen $screen): JsonResponse
    {
        $this->authorizePermission('screens.view', 'supervision.view');
        $this->assertScreenInScope($screen->id, 'screens.view', 'supervision.view');
        $query = ScreenStatusHistory::query()->where('screen_id', $screen->id)->orderByDesc('started_at')->orderByDesc('id');

        return $this->paginated($query, $request, fn (ScreenStatusHistory $h) => [
            'id' => $h->id, 'state' => $h->state, 'network_status' => $h->network_status, 'panel_state' => $h->panel_state,
            'started_at' => Present::date($h->started_at), 'ended_at' => Present::date($h->ended_at),
            'duration_s' => (int) $h->started_at->diffInSeconds($h->ended_at ?? now(), true),
        ], 50);
    }

    /**
     * Retrait du parc (§5.5, §15.1 étape 9, RG-013) : l'écran sort de toute programmation, son lecteur est révoqué et sa fiche,
     * son historique et ses incidents sont conservés. Irréversible : un écran remis en service est réinventorié.
     */
    public function archive(Request $request, Screen $screen): JsonResponse
    {
        $this->authorizePermission('screens.manage');
        $this->inScope([['type' => 'screen', 'id' => $screen->id]], 'screens.manage');
        $this->expectVersion($request, $screen);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        if ($screen->status === 'archived') {
            throw new BusinessException('SCREEN_ARCHIVED', 'Cet écran est déjà archivé.', 409);
        }
        $open = Incident::query()->where('screen_id', $screen->id)->whereIn('status', ['open', 'acknowledged'])->pluck('reference');
        if ($open->isNotEmpty()) {
            throw new BusinessException('OPEN_INCIDENTS', 'Archivage impossible : des incidents sont encore ouverts sur cet écran.', 409, ['incidents' => $open]);
        }
        $before = $screen->only(['status']);
        DB::transaction(function () use ($screen, $data): void {
            if ($screen->activeDevice) {
                $this->revokeDevice($screen->activeDevice, 'Archivage de l’écran : '.$data['reason']);
            }
            $screen->groups()->detach();
            $screen->forceFill(['status' => 'archived', 'archived_at' => now(), 'archive_reason' => $data['reason'], 'version' => $screen->version + 1])->save();
        });
        $this->audit()->log('screen.archived', $screen, $data['reason'], $before, ['status' => 'archived']);

        return $this->show($screen->refresh());
    }

    public function preview(Request $request, Screen $screen, ManifestBuilder $builder, ScheduleResolver $resolver): JsonResponse
    {
        $this->authorizePermission('campaigns.view', 'screens.view');
        // HAB-01 : le programme d'un écran hors du périmètre n'est pas consultable.
        $this->assertScreenInScope($screen->id, 'campaigns.view', 'screens.view');
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
        // HAB-01 : appairage limité aux écrans du site et de la zone de l'habilitation, comme leur modification.
        $this->inScope([['type' => 'screen', 'id' => $data['screen_id']]], 'devices.pair');
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
            $token = $device->createToken('device', ['device'], now()->addDays((int) config('saie.device.token_ttl_days')))->plainTextToken;
            $pairing->forceFill(['used_at' => now(), 'device_id' => $device->id, 'issued_token' => $token])->save();
            $this->audit()->log('device.paired', $screen, after: ['device' => $device->code]);

            return $device;
        });

        return response()->json(['data' => Present::device($device), 'message' => 'Lecteur associé. Il récupère son identité et se synchronise.'], 201);
    }

    /**
     * §9.2 : réaffectation d'un lecteur à un autre écran sans révocation (déplacement du boîtier, remplacement d'une dalle) :
     * le lecteur garde son identité et son jeton, reçoit au prochain battement le programme du nouvel écran ; l'ancien écran
     * redevient « en attente de configuration ». Les deux écrans relèvent du périmètre de l'habilitation.
     */
    public function reassign(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission('devices.pair');
        $data = $request->validate(['screen_id' => ['required', 'uuid', 'exists:screens,id'], 'reason' => ['required', 'string', 'min:5', 'max:300'], 'replace' => ['boolean']]);
        $this->inScope([['type' => 'screen', 'id' => $device->screen_id], ['type' => 'screen', 'id' => $data['screen_id']]], 'devices.pair');
        if ($device->status !== 'active') {
            throw new BusinessException('DEVICE_NOT_ACTIVE', 'Seul un lecteur actif peut être réaffecté.', 409);
        }
        if ($data['screen_id'] === $device->screen_id) {
            throw new BusinessException('SAME_SCREEN', 'Le lecteur est déjà associé à cet écran.', 422, ['field' => 'screen_id']);
        }
        $from = $device->screen;
        $target = Screen::query()->with('activeDevice')->findOrFail($data['screen_id']);
        if (in_array($target->status, ['inactive', 'archived'], true)) {
            throw new BusinessException('SCREEN_NOT_IN_SERVICE', 'L’écran cible est désactivé ou archivé.', 409);
        }
        DB::transaction(function () use ($device, $target, $data): void {
            if ($target->activeDevice) {
                if (empty($data['replace'])) {
                    throw new BusinessException('SCREEN_ALREADY_PAIRED', 'L’écran cible a déjà un lecteur actif. Confirmez son remplacement.', 409);
                }
                $this->revokeDevice($target->activeDevice, 'Remplacé par la réaffectation du lecteur '.$device->code.' : '.$data['reason']);
            }
            // Programme, configuration et commandes en attente sont ceux du nouvel écran : les commandes ouvertes sont annulées.
            DeviceCommand::query()->where('device_id', $device->id)->whereIn('status', ['pending', 'sent'])->update(['status' => 'cancelled', 'completed_at' => now(), 'cancelled_by' => $this->user()->id, 'result_message' => 'Annulée : lecteur réaffecté à un autre écran']);
            $device->forceFill(['screen_id' => $target->id, 'config_hash' => null, 'config_report' => null])->save();
        });
        $this->audit()->log('device.reassigned', $device, $data['reason'], ['screen' => $from?->code], ['screen' => $target->code]);
        if ($from) {
            $this->audit()->log('screen.device_removed', $from, $data['reason'], after: ['device' => $device->code, 'to' => $target->code]);
        }
        $this->audit()->log('device.paired', $target, $data['reason'], after: ['device' => $device->code, 'from' => $from?->code]);

        return response()->json(['data' => Present::device($device->refresh()), 'message' => 'Lecteur réaffecté : il recevra le programme du nouvel écran à sa prochaine synchronisation.']);
    }

    /**
     * §10.7 : suspension réversible d'un lecteur (lecteur suspect, intervention) : son identité et son jeton sont conservés,
     * il reste supervisé et peut recevoir des commandes de diagnostic, mais le serveur ne lui sert plus aucun programme.
     */
    public function suspend(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission('devices.revoke');
        $this->inScope([['type' => 'screen', 'id' => $device->screen_id]], 'devices.revoke');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        if ($device->status !== 'active' || $device->suspended_at) {
            throw new BusinessException('DEVICE_NOT_SUSPENDABLE', 'Seul un lecteur actif non suspendu peut être suspendu.', 409);
        }
        $device->forceFill(['suspended_at' => now(), 'suspended_by' => $this->user()->id, 'suspend_reason' => $data['reason']])->save();
        $this->audit()->log('device.suspended', $device, $data['reason']);

        return response()->json(['data' => Present::device($device->refresh()), 'message' => 'Lecteur suspendu : il n’affichera plus que le contenu de repli à sa prochaine synchronisation.']);
    }

    public function resume(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission('devices.revoke');
        $this->inScope([['type' => 'screen', 'id' => $device->screen_id]], 'devices.revoke');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        if (! $device->suspended_at || $device->status !== 'active') {
            throw new BusinessException('DEVICE_NOT_SUSPENDED', 'Ce lecteur n’est pas suspendu.', 409);
        }
        $device->forceFill(['suspended_at' => null, 'suspended_by' => null, 'suspend_reason' => null])->save();
        $this->audit()->log('device.resumed', $device, $data['reason']);

        return response()->json(['data' => Present::device($device->refresh()), 'message' => 'Lecteur rétabli : il reprendra son programme à sa prochaine synchronisation.']);
    }

    public function revoke(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission('devices.revoke');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $this->inScope([['type' => 'screen', 'id' => $device->screen_id]], 'devices.revoke');
        if ($device->status === 'revoked') {
            throw new BusinessException('ALREADY_REVOKED', 'Ce lecteur est déjà révoqué.', 409);
        }
        $this->revokeDevice($device, $data['reason']);

        return response()->json(['data' => Present::device($device->refresh()), 'message' => 'Lecteur révoqué. Son cache hors ligne reste lisible jusqu’à l’expiration de l’autorisation en cours.']);
    }

    /**
     * Relance à distance de l'application du lecteur (rechargement complet, cache vérifié conservé), remise au heartbeat suivant.
     * Ce n'est pas un redémarrage du système d'exploitation, qui exigerait l'agent local et un matériel compatible.
     */
    public function reload(Request $request, Device $device): JsonResponse
    {
        // Alias historique de la commande « restart_player » de la file de commandes (§11) : même contrôle, même suivi.
        $this->authorizePermission('devices.command');
        $this->inScope([['type' => 'screen', 'id' => $device->screen_id]], 'devices.command');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        if ($device->status !== 'active') {
            throw new BusinessException('DEVICE_NOT_ACTIVE', 'Seul un lecteur actif peut être relancé.', 409);
        }
        $command = app(RemoteCommands::class)->request($device, 'restart_player', $this->user(), $data['reason']);

        return response()->json(['data' => Present::device($device->refresh()) + ['command' => Present::deviceCommand($command)], 'message' => 'Relance transmise au prochain battement du lecteur ; elle sera confirmée par le lecteur.']);
    }

    public function requestScreenshot(Device $device): JsonResponse
    {
        // Alias historique de la commande « screenshot » (§11) ; refusée si le lecteur n'est pas actif ou ne sait pas capturer.
        $this->authorizePermission('screens.manage', 'devices.command');
        $this->inScope([['type' => 'screen', 'id' => $device->screen_id]], $this->can('devices.command') ? 'devices.command' : 'screens.manage');
        $command = app(RemoteCommands::class)->request($device, 'screenshot', $this->user());
        $this->audit()->log('device.screenshot_requested', $device);

        return response()->json(['data' => DeviceScreenshot::query()->findOrFail($command->params['screenshot_id']), 'message' => 'Demande transmise au prochain battement du lecteur (environ 30 secondes).'], 201);
    }

    public function screenshot(DeviceScreenshot $screenshot): StreamedResponse
    {
        $this->authorizePermission('screens.manage');
        $this->inScope([['type' => 'screen', 'id' => $screenshot->device?->screen_id]], 'screens.manage');
        abort_unless($screenshot->status === 'captured' && $screenshot->storage_key && $screenshot->expires_at->isFuture(), 404);

        return Storage::disk('local')->response($screenshot->storage_key, null, ['Cache-Control' => 'no-store']);
    }

    public function groups(Request $request): JsonResponse
    {
        $this->authorizePermission('screens.view', 'campaigns.view', 'groups.manage');
        // HAB-01 : un titulaire limité ne voit que les groupes entièrement composés d'écrans de son périmètre (seuls ceux
        // qu'il peut cibler ou gérer), et les groupes vides de sa structure.
        $scope = $this->screenScopeFor('screens.view', 'campaigns.view', 'groups.manage');
        $groups = ScreenGroup::query()
            ->when($scope !== null, fn ($q) => $q->whereDoesntHave('screens', fn ($s) => $s->whereNotIn('screens.id', $scope))
                ->where(fn ($w) => $w->whereHas('screens')->orWhere('structure_id', $this->user()->structure_id)))
            ->withCount('screens')->with(['screens' => fn ($q) => $q->select('screens.id', 'code', 'name', 'status')->with('activeDevice')])->orderBy('name')->get();
        $open = Incident::query()->whereIn('status', ['open', 'acknowledged'])->get(['device_id', 'screen_id']);
        $withIncident = $open->pluck('device_id')->merge($open->pluck('screen_id'))->filter()->unique()->values()->all();
        // §8-f : indicateurs sur 7 jours, calculés en trois requêtes pour tous les groupes (pas de requête par groupe).
        $since = now()->subDays(7);
        $deviceIds = $groups->flatMap(fn (ScreenGroup $g) => $g->screens->pluck('activeDevice.id'))->filter()->unique()->values();
        $samples = DB::table('device_telemetry')->whereIn('device_id', $deviceIds)->where('recorded_at', '>=', $since)->selectRaw('device_id, count(*) as total')->groupBy('device_id')->pluck('total', 'device_id');
        $plays = DB::table('device_receipts')->whereIn('device_id', $deviceIds)->where('kind', 'played')->where('occurred_at', '>=', $since)
            ->selectRaw("device_id, sum(coalesce((payload->>'plays')::int, 1)) as total")->groupBy('device_id')->pluck('total', 'device_id');
        $incidents = Incident::query()->whereIn('screen_id', $groups->flatMap(fn (ScreenGroup $g) => $g->screens->pluck('id'))->unique())->where('detected_at', '>=', $since)
            ->selectRaw('screen_id, count(*) as total')->groupBy('screen_id')->pluck('total', 'screen_id');
        $availability = function (ScreenGroup $g) use ($samples, $since): ?float {
            $expected = $g->screens->sum(fn ($s) => $s->activeDevice ? intdiv(now()->getTimestamp() - max($since->getTimestamp(), $s->activeDevice->paired_at?->getTimestamp() ?? $since->getTimestamp()), 300) : 0);

            return $expected > 0 ? min(100.0, round(100 * $g->screens->sum(fn ($s) => (int) ($samples[$s->activeDevice?->id] ?? 0)) / $expected, 1)) : null;
        };

        return response()->json(['data' => $groups->map(fn (ScreenGroup $g) => [
            'id' => $g->id, 'version' => $g->version, 'code' => $g->code, 'name' => $g->name, 'description' => $g->description, 'screens_count' => $g->screens_count, 'operating_window' => $g->operating_window, 'profile_id' => $g->profile_id,
            'type' => $g->type, 'criteria' => $g->criteria, 'members_computed_at' => Present::date($g->members_computed_at),
            // §8.2 : statistiques et supervision des membres.
            'stats' => [
                'online' => $g->screens->filter(fn ($s) => $s->activeDevice?->computedNetworkStatus() === 'online')->count(),
                'anomaly' => $g->screens->filter(fn ($s) => Present::operationalState($s, $s->activeDevice, $withIncident)['code'] === 'anomaly')->count(),
                'unpaired' => $g->screens->filter(fn ($s) => ! $s->activeDevice)->count(),
                // Joignabilité = relevés de télémétrie reçus / créneaux de 5 minutes attendus (même méthode que RG-014).
                'availability_7d' => $availability($g),
                'plays_7d' => (int) $g->screens->sum(fn ($s) => (int) ($plays[$s->activeDevice?->id] ?? 0)),
                'incidents_7d' => (int) $g->screens->sum(fn ($s) => (int) ($incidents[$s->id] ?? 0)),
            ],
            'screens' => $g->screens->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name]),
            'active_campaigns' => DB::table('campaign_targets')->join('campaigns', 'campaigns.id', '=', 'campaign_targets.campaign_id')->where('target_type', 'group')->where('target_id', $g->id)->whereIn('campaigns.status', ['scheduled', 'active'])->count(),
        ])]);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $this->authorizePermission('groups.manage');
        $data = $request->validate(['code' => ['required', 'string', 'max:40', 'unique:screen_groups,code'], 'name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'], 'screen_ids' => ['array'], 'screen_ids.*' => ['uuid', 'exists:screens,id'],
            // PLY-08 : plage du groupe, appliquée aux écrans du groupe sans plage propre.
            'operating_window' => ['nullable', 'array'], 'operating_window.days' => ['required_with:operating_window', 'array', 'min:1'], 'operating_window.days.*' => ['integer', 'between:1,7'], 'operating_window.on' => ['required_with:operating_window', 'date_format:H:i'], 'operating_window.off' => ['required_with:operating_window', 'date_format:H:i', 'after:operating_window.on'], 'operating_window.ignore_holidays' => ['sometimes', 'boolean'],
            'profile_id' => ['nullable', 'uuid', Rule::exists('screen_profiles', 'id')->where('is_active', true)],
            'type' => ['sometimes', 'in:static,dynamic'], 'screen_ids' => ['prohibited_if:type,dynamic', 'array'], 'criteria' => ['required_if:type,dynamic', 'prohibited_unless:type,dynamic', 'array'],
        ] + DynamicGroups::RULES);
        $this->assertDynamicAllowed($data['type'] ?? 'static');
        $this->inScope(array_map(fn ($id) => ['type' => 'screen', 'id' => $id], $data['screen_ids'] ?? []), 'groups.manage');
        // Rattaché à la structure de son auteur : un gestionnaire limité garde la main sur son groupe tant qu'il est vide.
        $group = ScreenGroup::query()->create(collect($data)->except('screen_ids')->all() + ['structure_id' => $this->user()->structure_id]);
        $group->type === 'dynamic' ? app(DynamicGroups::class)->sync($group) : $group->screens()->sync($data['screen_ids'] ?? []);
        $this->audit()->log('group.created', $group, after: collect($data)->only(['type', 'criteria', 'screen_ids'])->all());

        return response()->json(['data' => $group->refresh()], 201);
    }

    public function updateGroup(Request $request, ScreenGroup $group): JsonResponse
    {
        $this->authorizePermission('groups.manage');
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'], 'screen_ids' => ['sometimes', 'array'], 'screen_ids.*' => ['uuid', 'exists:screens,id'],
            'operating_window' => ['nullable', 'array'], 'operating_window.days' => ['required_with:operating_window', 'array', 'min:1'], 'operating_window.days.*' => ['integer', 'between:1,7'], 'operating_window.on' => ['required_with:operating_window', 'date_format:H:i'], 'operating_window.off' => ['required_with:operating_window', 'date_format:H:i', 'after:operating_window.on'], 'operating_window.ignore_holidays' => ['sometimes', 'boolean'],
            'profile_id' => ['nullable', 'uuid', Rule::exists('screen_profiles', 'id')->where('is_active', true)],
            'criteria' => [$group->type === 'dynamic' ? 'sometimes' : 'prohibited', 'array'],
        ] + DynamicGroups::RULES);
        if ($group->type === 'dynamic' && array_key_exists('screen_ids', $data)) {
            throw new BusinessException('DYNAMIC_GROUP', 'Les membres d’un groupe dynamique sont calculés à partir de ses critères.', 422, ['field' => 'screen_ids']);
        }
        $this->assertDynamicAllowed($group->type);
        $this->inScope(array_merge([['type' => 'group', 'id' => $group->id]], array_map(fn ($id) => ['type' => 'screen', 'id' => $id], $data['screen_ids'] ?? [])), 'groups.manage');
        $this->expectVersion($request, $group);
        $before = $group->only(['name', 'description', 'operating_window', 'profile_id', 'criteria']) + ['screen_ids' => $group->screens()->pluck('screens.id')->all()];
        $group->update(collect($data)->except('screen_ids')->all() + ['version' => $group->version + 1]);
        if (array_key_exists('screen_ids', $data)) {
            $group->screens()->sync($data['screen_ids']);
        }
        if ($group->type === 'dynamic') {
            app(DynamicGroups::class)->sync($group);
        }
        $this->audit()->log('group.updated', $group, before: $before, after: $data);

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
        // §14-8 : une habilitation active limitée à ce groupe bloque la suppression (la retirer l'étendrait à tout le parc).
        $holders = DB::table('role_assignments')->join('users', 'users.id', '=', 'role_assignments.user_id')->where('role_assignments.group_id', $group->id)->whereNull('role_assignments.revoked_at')
            ->where(fn ($q) => $q->whereNull('role_assignments.ends_at')->orWhere('role_assignments.ends_at', '>', now()))->pluck('users.name');
        if ($holders->isNotEmpty()) {
            throw new BusinessException('GROUP_IN_USE', 'Suppression impossible : des habilitations actives sont limitées à ce groupe.', 409, ['users' => $holders]);
        }
        // §13.1-e, RG-008 : les membres retirés par la suppression sont conservés dans le journal.
        $this->audit()->log('group.deleted', $group, before: ['members' => $group->screens()->pluck('code')->all(), 'name' => $group->name]);
        $group->delete();

        return response()->json(null, 204);
    }

    /**
     * Groupe dynamique : ses critères peuvent viser tout le parc ; il n'est géré que par un titulaire sans restriction de
     * site ni de zone (un gestionnaire limité utilise des groupes statiques composés d'écrans de son périmètre).
     */
    private function assertDynamicAllowed(string $type): void
    {
        if ($type === 'dynamic' && $this->access()->screenScope($this->user(), 'groups.manage') !== null) {
            $this->deny('Groupe dynamique réservé à une habilitation sans restriction de périmètre');
        }
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

        return $request->validate($this->rules($screen));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Screen $screen = null): array
    {
        $required = $screen ? 'sometimes' : 'required';

        return [
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
            'touch_enabled' => ['sometimes', 'boolean'],
            'control_channel' => ['sometimes', 'in:none,cec,rs232,api'],
            'status' => ['sometimes', 'in:active,maintenance,inactive'],
            'maintenance_reason' => ['nullable', 'string', 'min:5', 'max:300', 'required_if:status,maintenance'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Fiche matérielle (inventaire du parc), facultative.
            'manufacturer' => ['nullable', 'string', 'max:80'], 'model' => ['nullable', 'string', 'max:80'],
            // §5.2-b : numéro de série unique quand il est renseigné (insensible à la casse, index partiel en base).
            'serial_number' => ['nullable', 'string', 'max:80', function (string $attribute, mixed $value, \Closure $fail) use ($screen): void {
                if (is_string($value) && Screen::query()->whereRaw('lower(serial_number) = lower(?)', [$value])->when($screen, fn ($q) => $q->where('id', '!=', $screen->id))->exists()) {
                    $fail('Ce numéro de série est déjà attribué à un autre écran.');
                }
            }],
            // §5.1-b : code d'inventaire patrimonial, distinct du code technique.
            'inventory_code' => ['nullable', 'string', 'max:40', Rule::unique('screens', 'inventory_code')->ignore($screen?->id)],
            'diagonal_inches' => ['nullable', 'integer', 'between:7,120'], 'ip_address' => ['nullable', 'ip'],
            'connection_type' => ['nullable', 'in:ethernet,wifi,cellular,other'],
            'installed_on' => ['nullable', 'date', 'before_or_equal:today'], 'warranty_until' => ['nullable', 'date', 'after_or_equal:installed_on'],
            // §5.1, §5.2, §5.3 : fiche administrative, technique et emplacement précis.
            'acquired_on' => ['nullable', 'date', 'before_or_equal:today'], 'supplier' => ['nullable', 'string', 'max:120'], 'order_reference' => ['nullable', 'string', 'max:80'],
            'technology' => ['nullable', 'in:led,lcd,oled,other'], 'power_watts' => ['nullable', 'integer', 'between:1,5000'],
            'mac_address' => ['nullable', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'], 'os_name' => ['nullable', 'string', 'max:60'], 'firmware_version' => ['nullable', 'string', 'max:60'],
            'location_detail' => ['nullable', 'string', 'max:200'],
            // §5.1-e, §5.2-g, §5.2-l, §5.4-f : catégorie, interfaces physiques, mode de fonctionnement et criticité.
            'category_id' => ['nullable', 'uuid', Rule::exists('screen_categories', 'id')->where('is_active', true)],
            'interfaces' => ['nullable', 'array', 'max:12'], 'interfaces.*' => ['distinct', 'in:'.implode(',', Screen::INTERFACES)],
            'operating_mode' => ['nullable', 'in:permanent,window,on_demand'], 'criticality' => ['sometimes', 'in:low,normal,high,critical'],
            // §7.3 : profil de configuration propre à l'écran (sinon celui de son groupe).
            'profile_id' => ['nullable', 'uuid', Rule::exists('screen_profiles', 'id')->where('is_active', true)],
            'plan_x' => ['nullable', 'numeric', 'between:0,100', 'required_with:plan_y'], 'plan_y' => ['nullable', 'numeric', 'between:0,100', 'required_with:plan_x'],
        ];
    }

    /**
     * Normalisation et cohérence de la fiche : adresse MAC en majuscules séparée par « : », salle du même niveau que
     * l'emplacement (§6 : un écran n'a qu'un emplacement physique cohérent).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalized(array $data, ?Screen $screen = null): array
    {
        if (! empty($data['mac_address'])) {
            $data['mac_address'] = strtoupper(str_replace('-', ':', $data['mac_address']));
        }
        $roomId = array_key_exists('room_id', $data) ? $data['room_id'] : $screen?->room_id;
        $locationId = $data['location_id'] ?? $screen?->location_id;
        if ($roomId && $locationId) {
            $roomFloor = DB::table('rooms')->where('id', $roomId)->value('floor_id');
            $locationFloor = DB::table('locations')->where('id', $locationId)->value('floor_id');
            if ($roomFloor !== $locationFloor) {
                throw new BusinessException('ROOM_FLOOR_MISMATCH', 'La salle choisie n’est pas au même niveau que l’emplacement de l’écran.', 422, ['field' => 'room_id']);
            }
        }

        return $data;
    }

    /**
     * RG-011 : l'emplacement en cours est clos et le nouveau ouvert, avec l'auteur et le motif du déplacement.
     */
    private function recordLocation(Screen $screen, ?string $reason): void
    {
        ScreenLocationHistory::query()->where('screen_id', $screen->id)->whereNull('to_at')->update(['to_at' => now()]);
        ScreenLocationHistory::query()->create([
            'screen_id' => $screen->id, 'location_id' => $screen->location_id, 'room_id' => $screen->room_id, 'location_detail' => $screen->location_detail,
            'from_at' => now(), 'moved_by' => $this->user()->id, 'reason' => $reason,
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

    /**
     * Après création ou déplacement : l'écran doit rester dans le périmètre (le calcul du périmètre est relu sans cache).
     */
    private function assertStillInScope(Screen $screen): void
    {
        $this->access()->flush($this->user());
        $scope = $this->access()->screenScope($this->user(), 'screens.manage');
        if ($scope !== null && ! in_array($screen->id, $scope, true)) {
            $this->deny('Emplacement hors périmètre');
        }
    }
}
