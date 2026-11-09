<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Floor;
use App\Models\Incident;
use App\Models\PlayerRelease;
use App\Models\Screen;
use App\Support\Present;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord du parc (§4) : indicateurs, répartitions, tendances et vue par niveau, calculés sur les données réelles
 * (états, télémétrie et accusés reçus) et bornés au périmètre de l'utilisateur. Aucun chiffre n'est estimé.
 */
class FleetOverview
{
    /**
     * @param  list<string>|null  $scope  écrans visibles (null : tout le parc)
     * @return array<string, mixed>
     */
    public function build(?array $scope): array
    {
        $screens = Screen::query()->with(['activeDevice', 'location.floor.building', 'location.zone', 'room'])->where('status', '!=', 'archived')
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))->get();
        $open = Incident::query()->whereIn('status', ['open', 'acknowledged'])->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))->get(['device_id', 'screen_id', 'severity']);
        $withIncident = $open->pluck('device_id')->merge($open->pluck('screen_id'))->filter()->unique()->values()->all();
        $states = $screens->mapWithKeys(fn (Screen $s) => [$s->id => Present::operationalState($s, $s->activeDevice, $withIncident)['code']]);
        $network = $screens->map(fn (Screen $s) => $s->activeDevice?->computedNetworkStatus() ?? 'unpaired');
        $deviceIds = $screens->pluck('activeDevice.id')->filter()->values();
        $count = fn (string ...$codes) => $states->filter(fn ($c) => in_array($c, $codes, true))->count();

        return [
            'generated_at' => now()->toIso8601String(),
            // §4.1 : chaque carte renvoie vers la liste filtrée correspondante.
            'cards' => [
                'total' => $screens->count(),
                'online' => $network->filter(fn ($n) => $n === 'online')->count(),
                'offline' => $count('offline'),
                'broadcasting' => $count('broadcasting'),
                'anomaly' => $count('anomaly'),
                'inactive' => $count('disabled'),
                'not_configured' => $count('pending_configuration'),
                'maintenance' => $count('maintenance'),
                'standby' => $count('standby', 'paused'),
            ],
            'incidents_by_severity' => collect(['critical', 'major', 'medium', 'minor'])->mapWithKeys(fn ($s) => [$s => $open->where('severity', $s)->count()])->all(),
            'by_state' => $states->countBy()->all(),
            'by_building' => $this->group($screens, fn (Screen $s) => $s->location->floor->building?->name ?? '—', $states),
            'by_floor' => $this->group($screens->sortByDesc(fn (Screen $s) => $s->location->floor->level), fn (Screen $s) => ($s->location->floor->building?->name ?? '—').' · '.$s->location->floor->label, $states),
            'by_kind' => $screens->countBy('kind')->all(),
            'by_technology' => $screens->countBy(fn (Screen $s) => $s->technology ?? 'unknown')->all(),
            'players' => $this->players($screens),
            'availability_trend' => $this->availabilityTrend($deviceIds),
            'most_played' => $this->mostPlayed($screens),
            'incidents_trend' => $this->incidentsTrend($scope),
            'floors' => $this->floorsMap($screens, $states),
        ];
    }

    /**
     * @param  Collection<int, Screen>  $screens
     * @param  Collection<string, string>  $states
     * @return list<array{label: string, total: int, online: int, anomaly: int}>
     */
    private function group(Collection $screens, callable $key, Collection $states): array
    {
        return $screens->groupBy($key)->map(fn ($items, $label) => [
            'label' => (string) $label, 'total' => $items->count(),
            'online' => $items->filter(fn (Screen $s) => $s->activeDevice?->computedNetworkStatus() === 'online')->count(),
            'anomaly' => $items->filter(fn (Screen $s) => $states[$s->id] === 'anomaly')->count(),
        ])->values()->all();
    }

    /**
     * État des lecteurs (§4.2) : versions et lecteurs en retard sur la version cible de leur canal.
     *
     * @param  Collection<int, Screen>  $screens
     * @return array<string, mixed>
     */
    private function players(Collection $screens): array
    {
        $devices = $screens->pluck('activeDevice')->filter();
        $targetOf = fn (Device $d) => PlayerRelease::targetForDevice($d)?->version;

        return [
            'total' => $devices->count(),
            'versions' => $devices->countBy(fn (Device $d) => $d->app_version ?? 'inconnue')->all(),
            'outdated' => $devices->filter(fn (Device $d) => ($t = $targetOf($d)) && $d->app_version && $d->app_version !== $t)->count(),
            'paused' => $devices->filter(fn (Device $d) => $d->playback_paused)->count(),
        ];
    }

    /**
     * RG-014 : joignabilité quotidienne des lecteurs sur 14 jours = relevés de télémétrie reçus / créneaux de 5 minutes
     * attendus depuis l'appairage. Un jour sans lecteur appairé n'a pas de valeur.
     *
     * @param  Collection<int, string>  $deviceIds
     * @return list<array{date: string, value: float|null}>
     */
    private function availabilityTrend(Collection $deviceIds): array
    {
        $tz = config('saie.timezone');
        $devices = Device::query()->whereIn('id', $deviceIds)->get(['id', 'paired_at']);
        $from = CarbonImmutable::now($tz)->startOfDay()->subDays(13);
        $samples = DB::table('device_telemetry')->whereIn('device_id', $deviceIds)->where('recorded_at', '>=', $from->utc())
            ->selectRaw("to_char(recorded_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM-DD') as day, count(*) as total", [$tz])->groupBy('day')->pluck('total', 'day');

        return collect(range(0, 13))->map(function (int $i) use ($from, $devices, $samples): array {
            $start = $from->addDays($i);
            $end = min($start->addDay()->getTimestamp(), now()->getTimestamp());
            $expected = $devices->sum(fn ($d) => max(0, intdiv($end - max($start->getTimestamp(), $d->paired_at?->getTimestamp() ?? $start->getTimestamp()), 300)));

            return ['date' => $start->toDateString(), 'value' => $expected > 0 ? min(100.0, round(100 * ((int) ($samples[$start->toDateString()] ?? 0)) / $expected, 1)) : null];
        })->all();
    }

    /**
     * Écrans les plus sollicités sur 7 jours, d'après les lectures déclarées (accusés « played » agrégés par le lecteur).
     *
     * @param  Collection<int, Screen>  $screens
     * @return list<array{id: string, code: string, name: string, plays: int}>
     */
    private function mostPlayed(Collection $screens): array
    {
        $byDevice = $screens->filter(fn (Screen $s) => $s->activeDevice)->mapWithKeys(fn (Screen $s) => [$s->activeDevice->id => $s]);
        if ($byDevice->isEmpty()) {
            return [];
        }
        $plays = DB::table('device_receipts')->whereIn('device_id', $byDevice->keys())->where('kind', 'played')->where('occurred_at', '>=', now()->subDays(7))
            ->selectRaw("device_id, sum(coalesce((payload->>'plays')::int, 1)) as total")->groupBy('device_id')->orderByDesc('total')->limit(5)->get();

        return $plays->map(fn ($p) => ['id' => $byDevice[$p->device_id]->id, 'code' => $byDevice[$p->device_id]->code, 'name' => $byDevice[$p->device_id]->name, 'plays' => (int) $p->total])->values()->all();
    }

    /**
     * Incidents ouverts par jour sur 30 jours (§4.2, historique des incidents).
     *
     * @param  list<string>|null  $scope
     * @return list<array{date: string, opened: int, critical: int}>
     */
    private function incidentsTrend(?array $scope): array
    {
        $tz = config('saie.timezone');
        $from = CarbonImmutable::now($tz)->startOfDay()->subDays(29);
        $rows = Incident::query()->where('detected_at', '>=', $from->utc())->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))
            ->selectRaw("to_char(detected_at AT TIME ZONE ?, 'YYYY-MM-DD') as day, count(*) as opened, sum(case when severity = 'critical' then 1 else 0 end) as critical", [$tz])
            ->groupBy('day')->get()->keyBy('day');

        return collect(range(0, 29))->map(fn (int $i) => ['date' => $d = $from->addDays($i)->toDateString(), 'opened' => (int) ($rows[$d]->opened ?? 0), 'critical' => (int) ($rows[$d]->critical ?? 0)])->all();
    }

    /**
     * §4.3 : vue par niveau — plan du niveau s'il est déposé, salles, emplacements et écrans avec leur état et leur position.
     *
     * @param  Collection<int, Screen>  $screens
     * @param  Collection<string, string>  $states
     * @return list<array<string, mixed>>
     */
    private function floorsMap(Collection $screens, Collection $states): array
    {
        $floors = Floor::query()->with(['building'])->whereIn('id', $screens->pluck('location.floor_id')->unique())->orderBy('building_id')->orderByDesc('level')->get();
        $rooms = DB::table('rooms')->whereIn('floor_id', $floors->pluck('id'))->orderBy('name')->get(['id', 'floor_id', 'name', 'code']);

        return $floors->map(fn (Floor $f) => [
            'id' => $f->id, 'label' => $f->label, 'level' => $f->level, 'building' => $f->building?->name, 'plan_asset_version_id' => $f->plan_asset_version_id,
            'rooms' => $rooms->where('floor_id', $f->id)->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'code' => $r->code])->values(),
            'screens' => $screens->filter(fn (Screen $s) => $s->location->floor_id === $f->id)->sortBy('code')->map(fn (Screen $s) => [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'state' => $states[$s->id], 'location' => $s->location->name, 'room' => $s->room?->name,
                'plan' => $s->plan_x !== null ? ['x' => (float) $s->plan_x, 'y' => (float) $s->plan_y] : null,
            ])->values(),
        ])->values()->all();
    }
}
