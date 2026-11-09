<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AlertReceipt;
use App\Models\CampaignItem;
use App\Models\Device;
use App\Models\DeviceReceipt;
use App\Models\Floor;
use App\Models\Incident;
use App\Models\ReservationOccurrence;
use App\Models\Room;
use App\Support\BusinessException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rapports et indicateurs (V33, TAB-01, GOV-01). Occupation réservée distincte de la présence (SAL-12) ;
 * activations déclarées distinctes de toute audience humaine (PUB-07).
 */
class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after:from'], 'floor_id' => ['nullable', 'uuid'],
            'building_id' => ['nullable', 'uuid', 'exists:buildings,id'], 'site_id' => ['nullable', 'uuid', 'exists:sites,id']]);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::now();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $to->subDays(30);
        // ASVS 5.0 V2.4 : le calcul parcourt chaque jour de chaque salle ; la période est bornée à un an.
        if ($from->diffInDays($to, true) > 366) {
            throw new BusinessException('PERIOD_TOO_LONG', 'La période d’un rapport est limitée à un an.', 422);
        }
        $rooms = $this->roomUsage($from, $to, $data['floor_id'] ?? null);
        // RAP-02 : diffusion, disponibilité et incidents bornés au périmètre « rapports » de l'habilitation (site, zone)
        // et aux filtres géographiques demandés.
        $screens = $this->screensInScope($data);
        $deviceIds = Device::query()->when($screens !== null, fn ($q) => $q->whereIn('screen_id', $screens))->pluck('id');
        $incidents = Incident::query()->whereBetween('detected_at', [$from, $to])
            ->when($screens !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('screen_id', $screens)->orWhereIn('device_id', $deviceIds)));

        return response()->json(['data' => [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'timezone' => config('saie.timezone')],
            'scope' => ['screens' => $screens === null ? 'all' : count($screens), 'site_id' => $data['site_id'] ?? null, 'building_id' => $data['building_id'] ?? null, 'floor_id' => $data['floor_id'] ?? null],
            'diffusion' => $this->diffusion($from, $to, $deviceIds),
            'availability' => $this->availability($from, $to, $screens),
            'rooms' => $rooms,
            'incidents' => [
                'total' => (clone $incidents)->count(),
                'resolved' => (clone $incidents)->whereIn('status', ['resolved', 'closed'])->count(),
                'open' => (clone $incidents)->whereIn('status', ['open', 'acknowledged'])->count(),
            ],
            'publication' => [
                'contents_created' => DB::table('contents')->whereBetween('created_at', [$from, $to])->count(),
                'versions_approved' => DB::table('content_versions')->whereBetween('approved_at', [$from, $to])->count(),
                'returns' => DB::table('workflow_events')->where('action', 'returned')->whereBetween('created_at', [$from, $to])->count(),
                'campaigns_published' => DB::table('campaigns')->whereBetween('published_at', [$from, $to])->count(),
            ],
            'indicators' => $this->indicators($from, $to),
            'presence' => ['measured' => $rooms['presence_rate'] !== null, 'label' => $rooms['presence'], 'rate' => $rooms['presence_rate']],
            'notice' => 'Une activation déclarée par un lecteur ne constitue pas une preuve de vue humaine.',
        ]]);
    }

    /**
     * Indicateurs de succès GOV-01, chacun avec sa période de référence et sa cible proposée.
     *
     * @return list<array<string, mixed>>
     */
    public function indicators(CarbonInterface $from, CarbonInterface $to): array
    {
        $items = CampaignItem::query()->join('campaigns', 'campaigns.id', '=', 'campaign_items.campaign_id')->whereNotNull('campaigns.published_at')->whereBetween('campaigns.published_at', [$from, $to])->get(['campaign_items.version_map', 'campaign_items.content_id', 'campaigns.published_at']);
        $viaCircuit = $items->filter(fn ($i) => ! empty($i->version_map))->count();
        $delays = DB::table('contents')->join('campaign_items', 'campaign_items.content_id', '=', 'contents.id')->join('campaigns', 'campaigns.id', '=', 'campaign_items.campaign_id')
            ->whereNotNull('campaigns.published_at')->whereBetween('campaigns.published_at', [$from, $to])->groupBy('contents.id', 'contents.created_at')
            ->selectRaw('extract(epoch from (min(campaigns.published_at) - contents.created_at)) / 86400 as days')->pluck('days')->map(fn ($d) => (float) $d)->sort()->values();
        $median = $delays->isEmpty() ? null : round($delays->median(), 1);
        $receipts = AlertReceipt::query()->join('alerts', 'alerts.id', '=', 'alert_receipts.alert_id')->whereBetween('alerts.starts_at', [$from, $to])->whereNotNull('alert_receipts.activated_at')
            ->selectRaw('extract(epoch from (alert_receipts.activated_at - greatest(alerts.starts_at, alerts.created_at))) as delay')->pluck('delay');
        $fast = $receipts->filter(fn ($d) => (float) $d <= 60)->count();
        $doubles = (int) DB::selectOne("SELECT count(*) AS c FROM reservation_occurrences a JOIN reservation_occurrences b ON a.room_id = b.room_id AND a.id < b.id AND a.status IN ('confirmed','in_progress','completed') AND b.status IN ('confirmed','in_progress','completed') AND tstzrange(a.blocking_start, a.blocking_end, '[)') && tstzrange(b.blocking_start, b.blocking_end, '[)')")->c;
        $devices = Device::query()->where('status', 'active')->get();
        $online = $devices->filter(fn ($d) => $d->computedNetworkStatus() === 'online')->count();
        $period = ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()];

        return [
            ['code' => 'circuit', 'label' => 'Contenus diffusés via le circuit de validation', 'value' => $items->isEmpty() ? null : round(100 * $viaCircuit / $items->count(), 1), 'unit' => '%', 'target' => '100 %', 'period' => $period, 'basis' => $items->count().' élément(s) programmé(s)'],
            ['code' => 'delay', 'label' => 'Délai médian dépôt → première diffusion', 'value' => $median, 'unit' => 'jours', 'target' => '< 2 jours ouvrés', 'period' => $period, 'basis' => $delays->count().' contenu(s)'],
            ['code' => 'alert', 'label' => 'Alertes activées en ≤ 60 s (lecteurs connectés)', 'value' => $receipts->isEmpty() ? null : round(100 * $fast / $receipts->count(), 1), 'unit' => '%', 'target' => '≥ 95 %', 'period' => $period, 'basis' => $receipts->count().' activation(s) déclarée(s)'],
            ['code' => 'double_booking', 'label' => 'Doubles réservations confirmées', 'value' => $doubles, 'unit' => '', 'target' => '0', 'period' => ['from' => null, 'to' => now()->toIso8601String()], 'basis' => 'Contrainte EXCLUDE GiST'],
            ['code' => 'fleet', 'label' => 'Lecteurs en contact avec le serveur', 'value' => $devices->isEmpty() ? null : round(100 * $online / $devices->count(), 1), 'unit' => '%', 'target' => '≥ 95 %', 'period' => ['from' => now()->toIso8601String(), 'to' => now()->toIso8601String()], 'basis' => "$online / {$devices->count()} à l’instant"],
            ['code' => 'traceability', 'label' => 'Actions de publication, validation et retrait journalisées', 'value' => $this->traceability($from, $to), 'unit' => '%', 'target' => '100 %', 'period' => $period, 'basis' => 'Rapprochement événements / journal'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function diffusion(CarbonInterface $from, CarbonInterface $to, Collection $deviceIds): array
    {
        $base = DeviceReceipt::query()->whereIn('device_id', $deviceIds)->whereBetween('occurred_at', [$from, $to]);
        $daily = (clone $base)->where('kind', 'played')->selectRaw("to_char(occurred_at at time zone ?, 'YYYY-MM-DD') as day, sum(coalesce((payload->>'plays')::int, 1)) as total", [config('saie.timezone')])->groupBy('day')->orderBy('day')->pluck('total', 'day');

        return [
            'manifests_received' => (clone $base)->where('kind', 'manifest_received')->count(),
            'activations' => (clone $base)->where('kind', 'activated')->count(),
            'plays_declared' => DeviceReceipt::playsCount((clone $base)->where('kind', 'played')),
            'errors' => (clone $base)->where('kind', 'error')->count(),
            'daily' => $daily,
        ];
    }

    /**
     * Disponibilité par niveau estimée depuis les heartbeats agrégés (1 relevé par tranche de 5 minutes).
     *
     * @return list<array<string, mixed>>
     */
    private function availability(CarbonInterface $from, CarbonInterface $to, ?array $screens): array
    {
        return Floor::query()->orderBy('level')->get()->map(function (Floor $floor) use ($from, $to, $screens): ?array {
            $devices = Device::query()->where('status', 'active')->whereHas('screen.location', fn ($l) => $l->where('floor_id', $floor->id))
                ->when($screens !== null, fn ($q) => $q->whereIn('screen_id', $screens))->get();
            if ($devices->isEmpty()) {
                return null;
            }
            $samples = DB::table('device_telemetry')->whereIn('device_id', $devices->pluck('id'))->whereBetween('recorded_at', [$from, $to])->count();
            $expectedSlots = $devices->sum(function (Device $d) use ($from, $to): int {
                $start = max($from->getTimestamp(), $d->paired_at?->getTimestamp() ?? $from->getTimestamp());

                return max(0, intdiv($to->getTimestamp() - $start, 300));
            });

            return ['floor' => $floor->label, 'level' => $floor->level, 'devices' => $devices->count(), 'value' => $expectedSlots > 0 ? min(100, round(100 * $samples / $expectedSlots, 1)) : null, 'method' => 'Relevés de télémétrie / créneaux de 5 min attendus'];
        })->filter()->values()->all();
    }

    /**
     * Écrans retenus : périmètre « rapports » de l'utilisateur (null = tout le parc) croisé avec site, bâtiment et niveau demandés.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>|null
     */
    private function screensInScope(array $filters): ?array
    {
        $scope = $this->access()->screenScope($this->user(), 'reports.view');
        if ($scope === null && empty($filters['site_id']) && empty($filters['building_id']) && empty($filters['floor_id'])) {
            return null;
        }

        return DB::table('screens')->join('locations', 'locations.id', '=', 'screens.location_id')->join('floors', 'floors.id', '=', 'locations.floor_id')
            ->join('buildings', 'buildings.id', '=', 'floors.building_id')
            ->when($scope !== null, fn ($q) => $q->whereIn('screens.id', $scope))
            ->when($filters['site_id'] ?? null, fn ($q, $id) => $q->where('buildings.site_id', $id))
            ->when($filters['building_id'] ?? null, fn ($q, $id) => $q->where('floors.building_id', $id))
            ->when($filters['floor_id'] ?? null, fn ($q, $id) => $q->where('locations.floor_id', $id))
            ->pluck('screens.id')->all();
    }

    /**
     * Taux réservé = minutes réservées confirmées / minutes disponibles après fermetures (PDF07).
     *
     * @return array<string, mixed>
     */
    public function roomUsage(CarbonInterface $from, CarbonInterface $to, ?string $floorId): array
    {
        $tz = config('saie.timezone');
        $rooms = Room::query()->with('floor')->where('status', 'active')->when($floorId, fn ($q) => $q->where('floor_id', $floorId))->orderBy('name')->get();
        // Jours non ouvrés de la période chargés une fois (et non une requête par jour et par salle).
        $holidays = array_flip(DB::table('holidays')->whereBetween('date', [CarbonImmutable::parse($from)->setTimezone($tz)->toDateString(), CarbonImmutable::parse($to)->setTimezone($tz)->toDateString()])
            ->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all());
        $rows = $rooms->map(function (Room $room) use ($from, $to, $tz, $holidays): array {
            $available = 0;
            for ($day = CarbonImmutable::parse($from)->setTimezone($tz)->startOfDay(); $day->lt($to); $day = $day->addDay()) {
                if ($day->isWeekend() || isset($holidays[$day->toDateString()])) {
                    continue;
                }
                $open = $day->setTimeFromTimeString(substr($room->open_from, 0, 5));
                $close = $day->setTimeFromTimeString(substr($room->open_to, 0, 5));
                $available += $open->diffInMinutes($close, true);
            }
            $closed = (int) DB::table('room_blocks')->where('room_id', $room->id)->where('starts_at', '<', $to)->where('ends_at', '>', $from)
                ->selectRaw('coalesce(sum(extract(epoch from (least(ends_at, ?) - greatest(starts_at, ?))) / 60), 0) as m', [$to, $from])->value('m');
            $occ = ReservationOccurrence::query()->where('room_id', $room->id)->where('starts_at', '<', $to)->where('ends_at', '>', $from);
            $reserved = (int) (clone $occ)->whereIn('status', ['confirmed', 'in_progress', 'completed', 'no_show'])
                ->selectRaw('coalesce(sum(extract(epoch from (least(ends_at, ?) - greatest(starts_at, ?))) / 60), 0) as m', [$to, $from])->value('m');
            $net = max(0, $available - $closed);

            return [
                'room' => $room->name, 'floor' => $room->floor->label, 'available_minutes' => $net, 'reserved_minutes' => $reserved,
                'rate' => $net > 0 ? round(100 * $reserved / $net, 1) : null,
                'cancellations' => (clone $occ)->where('status', 'cancelled')->count(), 'no_shows' => (clone $occ)->where('status', 'no_show')->count(),
                'services' => DB::table('service_requests')->whereIn('occurrence_id', (clone $occ)->pluck('id'))->count(),
                'presence_rate' => $this->presenceRate($room, $from, $to),
            ];
        });
        $available = $rows->sum('available_minutes');
        $measured = $rows->whereNotNull('presence_rate');

        return [
            'rows' => $rows->values(), 'rate' => $available > 0 ? round(100 * $rows->sum('reserved_minutes') / $available, 1) : null,
            'presence' => $measured->isEmpty() ? 'Présence non mesurée' : 'Présence mesurée sur '.$measured->count().' salle(s) (confirmation SAL-10 activée)',
            'presence_rate' => $measured->isEmpty() ? null : round($measured->avg('presence_rate'), 1),
            'method' => 'Minutes réservées confirmées / minutes d’ouverture hors fermetures et jours non ouvrés',
        ];
    }

    /**
     * Taux de présence réelle (SAL-10) : réunions commencées avec présence confirmée / réunions commencées. Null si la règle est inactive.
     */
    private function presenceRate(Room $room, CarbonInterface $from, CarbonInterface $to): ?float
    {
        if (! $room->auto_release_enabled) {
            return null;
        }
        $started = ReservationOccurrence::query()->where('room_id', $room->id)->whereBetween('starts_at', [$from, min($to, now())])->whereIn('status', ['in_progress', 'completed', 'no_show']);
        $total = (clone $started)->count();

        return $total === 0 ? null : round(100 * (clone $started)->whereNotNull('checked_in_at')->count() / $total, 1);
    }

    private function traceability(CarbonInterface $from, CarbonInterface $to): ?float
    {
        $events = DB::table('workflow_events')->whereIn('action', ['submitted', 'approved', 'returned', 'archived'])->whereBetween('created_at', [$from, $to])->count();
        if ($events === 0) {
            return null;
        }
        $logged = DB::table('audit_logs')->whereIn('action', ['content.submitted', 'content.approved', 'content.returned', 'content.archived'])->whereBetween('occurred_at', [$from, $to])->count();

        return round(min(100, 100 * $logged / $events), 1);
    }
}
