<?php

namespace App\Services;

use App\Models\DeviceCommand;
use App\Models\Incident;
use App\Models\MaintenancePlan;
use App\Models\Screen;
use App\Models\ScreenStatusHistory;
use App\Support\Present;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rapports d'exploitation du parc (§13.3) : écrans hors ligne, commandes à distance, fin de garantie, disponibilité et
 * maintenance. Chaque rapport est borné au périmètre de l'utilisateur et décrit sa méthode de calcul.
 */
class FleetReports
{
    /**
     * @param  list<string>|null  $scope
     * @return array<string, mixed>
     */
    public function offline(callable $fmt, ?array $scope): array
    {
        $screens = $this->screens($scope);
        $open = Incident::query()->whereIn('status', ['open', 'acknowledged'])->get(['device_id', 'screen_id', 'reference']);
        $flagged = $open->pluck('device_id')->merge($open->pluck('screen_id'))->filter()->unique()->values()->all();
        $offline = $screens->filter(fn (Screen $s) => Present::operationalState($s, $s->activeDevice, $flagged)['code'] === 'offline');
        $unpaired = $screens->filter(fn (Screen $s) => ! $s->activeDevice && $s->status === 'active');
        $since = ScreenStatusHistory::query()->whereIn('screen_id', $offline->pluck('id'))->whereNull('ended_at')->pluck('started_at', 'screen_id');

        return [
            'title' => 'Écrans hors ligne', 'reference' => 'HL-'.now()->format('YmdHi'), 'classification' => 'interne', 'landscape' => true,
            'meta' => [['Hors ligne', (string) $offline->count()], ['Sans lecteur appairé', (string) $unpaired->count()], ['Écrans examinés', (string) $screens->count()]],
            'sections' => [
                ['title' => 'Lecteurs sans battement', 'table' => ['headers' => ['Écran', 'Nom', 'Niveau', 'Emplacement', 'Lecteur', 'Dernier contact', 'Hors ligne depuis', 'Incident ouvert'], 'rows' => $offline->map(fn (Screen $s) => [
                    $s->code, $s->name, $s->location->floor->label, $s->location->name, $s->activeDevice?->code ?? '—', $fmt($s->activeDevice?->last_seen_at),
                    isset($since[$s->id]) ? $fmt($since[$s->id]) : 'Non relevé', $open->first(fn ($i) => $i->screen_id === $s->id || $i->device_id === $s->activeDevice?->id)?->reference ?? '—',
                ])->values()->all()]],
                ['title' => 'Écrans en service sans lecteur', 'table' => ['headers' => ['Écran', 'Nom', 'Niveau', 'Emplacement'], 'rows' => $unpaired->map(fn (Screen $s) => [$s->code, $s->name, $s->location->floor->label, $s->location->name])->values()->all()]],
            ],
            'notes' => ['« Hors ligne » : aucun battement du lecteur depuis le délai réglé dans les paramètres. Cela ne renseigne pas l’état de la dalle.'],
        ];
    }

    /**
     * @param  list<string>|null  $scope
     * @return array<string, mixed>
     */
    public function commands(CarbonImmutable $from, CarbonImmutable $to, callable $fmt, string $period, ?array $scope): array
    {
        $items = DeviceCommand::query()->with(['device', 'screen', 'requester'])->whereBetween('requested_at', [$from, $to])
            ->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))->orderBy('requested_at')->get();
        $status = ['pending' => 'En attente', 'sent' => 'Envoyée', 'received' => 'Reçue', 'executed' => 'Exécutée', 'failed' => 'Échouée', 'expired' => 'Expirée', 'cancelled' => 'Annulée'];

        return [
            'title' => 'Commandes à distance', 'reference' => 'CMD-'.$from->format('Ymd'), 'classification' => 'interne', 'landscape' => true,
            'meta' => [['Période', $period], ['Commandes', (string) $items->count()], ['Exécutées', (string) $items->where('status', 'executed')->count()], ['Échouées ou expirées', (string) $items->whereIn('status', ['failed', 'expired'])->count()]],
            'sections' => [['title' => 'Commandes', 'table' => ['headers' => ['Demandée', 'Écran', 'Lecteur', 'Commande', 'Auteur', 'Motif', 'État', 'Compte rendu', 'Délai d’exécution'], 'rows' => $items->map(fn (DeviceCommand $c) => [
                $fmt($c->requested_at), $c->screen?->code ?? '—', $c->device?->code ?? '—', RemoteCommands::CATALOGUE[$c->command]['label'] ?? $c->command, $c->requester?->name ?? 'Système', $c->reason ?? '',
                $status[$c->status] ?? $c->status, $c->result_message ?? '', $c->completed_at && $c->status === 'executed' ? $c->requested_at->diffInSeconds($c->completed_at, true).' s' : '—',
            ])->all()]]],
            'notes' => ['Une commande n’est « exécutée » qu’après le compte rendu du lecteur ; sans réponse avant l’échéance, elle est « expirée ».'],
        ];
    }

    /**
     * @param  list<string>|null  $scope
     * @return array<string, mixed>
     */
    public function warranty(int $months, ?array $scope): array
    {
        $limit = now()->addMonths($months);
        $screens = $this->screens($scope)->filter(fn (Screen $s) => $s->warranty_until !== null && $s->warranty_until->lte($limit))->sortBy('warranty_until');
        $unknown = $this->screens($scope)->filter(fn (Screen $s) => $s->warranty_until === null)->count();

        return [
            'title' => 'Équipements en fin de garantie', 'reference' => 'GAR-'.now()->format('Ymd'), 'classification' => 'interne', 'landscape' => true,
            'meta' => [['Horizon', "$months mois"], ['Garantie expirée', (string) $screens->filter(fn ($s) => $s->warranty_until->isPast())->count()], ['À échéance', (string) $screens->filter(fn ($s) => ! $s->warranty_until->isPast())->count()], ['Garantie non renseignée', (string) $unknown]],
            'sections' => [['title' => 'Écrans', 'table' => ['headers' => ['Écran', 'Nom', 'Niveau', 'Matériel', 'N° de série', 'Fournisseur', 'Référence de commande', 'Fin de garantie', 'Situation'], 'rows' => $screens->map(fn (Screen $s) => [
                $s->code, $s->name, $s->location->floor->label, trim(($s->manufacturer ?? '').' '.($s->model ?? '')) ?: '—', $s->serial_number ?? '—', $s->supplier ?? '—', $s->order_reference ?? '—',
                $s->warranty_until->format('d/m/Y'), $s->warranty_until->isPast() ? 'Expirée' : 'Dans '.max(0, (int) now()->diffInDays($s->warranty_until, true)).' jour(s)',
            ])->values()->all()]]],
            'notes' => ['Les écrans dont la garantie n’est pas renseignée sont comptés à part : compléter leur fiche.'],
        ];
    }

    /**
     * RG-014 : disponibilité calculée sur les données reçues — joignabilité (relevés de télémétrie / créneaux de 5 minutes
     * attendus) et durée passée hors ligne d'après l'historique des états.
     *
     * @param  list<string>|null  $scope
     * @return array<string, mixed>
     */
    public function availability(CarbonImmutable $from, CarbonImmutable $to, string $period, ?array $scope): array
    {
        $screens = $this->screens($scope)->filter(fn (Screen $s) => $s->activeDevice);
        $samples = DB::table('device_telemetry')->whereIn('device_id', $screens->pluck('activeDevice.id'))->whereBetween('recorded_at', [$from, $to])
            ->groupBy('device_id')->selectRaw('device_id, count(*) as total')->pluck('total', 'device_id');
        $periods = ScreenStatusHistory::query()->whereIn('screen_id', $screens->pluck('id'))->where('state', 'offline')
            ->where('started_at', '<', $to)->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $from))->get();
        $rows = $screens->map(function (Screen $s) use ($from, $to, $samples, $periods): array {
            $start = max($from->getTimestamp(), $s->activeDevice->paired_at?->getTimestamp() ?? $from->getTimestamp());
            $expected = max(0, intdiv($to->getTimestamp() - $start, 300));
            $mine = $periods->where('screen_id', $s->id);
            $offlineMin = (int) round($mine->sum(fn ($p) => max(0, min($to->getTimestamp(), ($p->ended_at ?? now())->getTimestamp()) - max($from->getTimestamp(), $p->started_at->getTimestamp()))) / 60);
            $rate = $expected > 0 ? min(100, round(100 * ((int) ($samples[$s->activeDevice->id] ?? 0)) / $expected, 1)) : null;

            return ['floor' => $s->location->floor->label, 'level' => $s->location->floor->level, 'row' => [$s->code, $s->name, $s->location->floor->label, $s->activeDevice->code,
                $rate === null ? 'Non mesurée' : str_replace('.', ',', (string) $rate).' %', (string) $mine->count(), $offlineMin.' min'], 'rate' => $rate];
        })->sortBy([['level', 'desc'], [fn ($r) => $r['row'][0], 'asc']])->values();
        $byFloor = $rows->groupBy('floor')->map(fn ($items, $floor) => [$floor, (string) $items->count(), ($m = $items->pluck('rate')->filter(fn ($r) => $r !== null))->isEmpty() ? 'Non mesurée' : str_replace('.', ',', (string) round($m->avg(), 1)).' %'])->values()->all();

        return [
            'title' => 'Disponibilité du parc', 'reference' => 'DISP-'.$from->format('Ymd'), 'classification' => 'interne', 'landscape' => true,
            'meta' => [['Période', $period], ['Lecteurs', (string) $rows->count()]],
            'sections' => [
                ['title' => 'Par niveau', 'table' => ['headers' => ['Niveau', 'Lecteurs', 'Joignabilité moyenne'], 'rows' => $byFloor]],
                ['title' => 'Par écran', 'table' => ['headers' => ['Écran', 'Nom', 'Niveau', 'Lecteur', 'Joignabilité', 'Périodes hors ligne', 'Durée hors ligne'], 'rows' => $rows->pluck('row')->all()]],
            ],
            'notes' => ['Joignabilité = relevés de télémétrie reçus / créneaux de 5 minutes attendus depuis l’appairage (RG-014).', 'Les périodes hors ligne proviennent de l’historique des états relevé chaque minute.'],
        ];
    }

    /**
     * @param  list<string>|null  $scope
     * @return array<string, mixed>
     */
    public function maintenance(CarbonImmutable $from, CarbonImmutable $to, callable $fmt, string $period, ?array $scope): array
    {
        $plans = MaintenancePlan::query()->with('screen')->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))->orderBy('next_due_on')->get();
        $interventions = Incident::query()->with(['screen', 'assignee', 'verifier'])->whereBetween('detected_at', [$from, $to])
            ->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))->orderBy('detected_at')->get();

        return [
            'title' => 'Maintenance du parc', 'reference' => 'MNT-'.$from->format('Ymd'), 'classification' => 'interne', 'landscape' => true,
            'meta' => [['Période', $period], ['Plans préventifs', (string) $plans->count()], ['Interventions', (string) $interventions->count()], ['Préventives', (string) $interventions->where('maintenance_type', 'preventive')->count()]],
            'sections' => [
                ['title' => 'Plans de maintenance préventive', 'table' => ['headers' => ['Plan', 'Écran', 'Fréquence (jours)', 'Prochaine échéance', 'Actif'], 'rows' => $plans->map(fn ($p) => [
                    $p->title, $p->screen?->code ?? 'Tout le parc', (string) ($p->frequency_days ?? '—'), $p->next_due_on ? CarbonImmutable::parse($p->next_due_on)->format('d/m/Y') : '—', $p->is_active ? 'Oui' : 'Non',
                ])->all()]],
                ['title' => 'Interventions', 'table' => ['headers' => ['Référence', 'Écran', 'Type', 'Détection', 'Responsable', 'Résultat', 'Vérifié par', 'Clôture'], 'rows' => $interventions->map(fn (Incident $i) => [
                    $i->reference, $i->screen?->code ?? '—', $i->maintenance_type === 'preventive' ? 'Préventive' : 'Corrective', $fmt($i->detected_at), $i->assignee?->name ?? '—',
                    $i->resolution ?? '—', $i->verifier?->name ?? '—', $fmt($i->closed_at),
                ])->all()]],
            ],
            'notes' => ['Une intervention n’est close qu’après vérification du fonctionnement (§15.2).'],
        ];
    }

    /**
     * @param  list<string>|null  $scope
     * @return Collection<int, Screen>
     */
    private function screens(?array $scope)
    {
        return Screen::query()->with(['activeDevice', 'location.floor'])->where('status', '!=', 'archived')
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))->orderBy('code')->get();
    }
}
