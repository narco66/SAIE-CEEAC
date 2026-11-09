<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\ReportController;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\ContentVersion;
use App\Models\Device;
use App\Models\DeviceReceipt;
use App\Models\Document;
use App\Models\Export;
use App\Models\Incident;
use App\Models\Manifest;
use App\Models\Reservation;
use App\Models\ReservationOccurrence;
use App\Models\Room;
use App\Models\Screen;
use App\Models\Setting;
use App\Models\User;
use App\Support\ActionLabel;
use App\Support\BusinessException;
use App\Support\Present;
use App\Support\XlsxWriter;
use Carbon\CarbonImmutable;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Génération des exports (TAB-02, chapitre 29). Les données sont figées à l'instant de génération, avec les mêmes
 * périmètres que la vue source ; un export confidentiel ne devient jamais public.
 */
class ExportService
{
    /** Libellés français des statuts de prestation, comme dans l'interface. */
    private const SERVICE_STATUS = ['requested' => 'À traiter', 'accepted' => 'Acceptée', 'clarification' => 'Précision demandée', 'ready' => 'Prête', 'impossible' => 'Impossible', 'cancelled' => 'Annulée'];

    /** Libellés français des statuts et gravités d'incident, comme dans l'interface. */
    private const INCIDENT_STATUS = ['open' => 'Ouvert', 'acknowledged' => 'Acquitté', 'resolved' => 'Résolu', 'closed' => 'Clos'];

    private const INCIDENT_SEVERITY = ['critical' => 'Critique', 'major' => 'Majeur', 'medium' => 'Moyen', 'minor' => 'Mineur'];

    private const INCIDENT_CATEGORY = ['player' => 'Lecteur', 'panel' => 'Dalle', 'network' => 'Réseau', 'power' => 'Alimentation', 'content' => 'Contenu', 'software' => 'Logiciel', 'other' => 'Autre'];

    /** Type => [libellé, permissions possibles, formats] */
    public const TYPES = [
        'reservation_confirmation' => ['PDF01 Confirmation de réservation', ['reservations.create', 'reservations.approve'], ['pdf']],
        'room_planning' => ['PDF02 Planning des salles', ['rooms.view'], ['pdf', 'csv', 'xlsx']],
        'meeting_preparation' => ['PDF03 Fiche de préparation de réunion', ['services.process', 'reservations.approve'], ['pdf']],
        'campaign_order' => ['PDF04 Bon de programmation de campagne', ['campaigns.view'], ['pdf']],
        'diffusion_report' => ['PDF05 Rapport de diffusion', ['reports.view', 'campaigns.view'], ['pdf', 'csv', 'xlsx']],
        'equipment_inventory' => ['PDF06 Inventaire des équipements', ['screens.view', 'rooms.manage', 'reports.fleet'], ['pdf', 'csv', 'xlsx']],
        'room_usage' => ['PDF07 Bilan d’utilisation des salles', ['reports.view'], ['pdf', 'csv', 'xlsx']],
        'incident_report' => ['PDF08 Rapport d’incidents', ['supervision.view', 'reports.fleet'], ['pdf', 'csv', 'xlsx']],
        'audit_extract' => ['PDF09 Extrait du journal', ['audit.view'], ['pdf', 'csv', 'xlsx']],
        'document_sheet' => ['PDF10 Fiche documentaire', ['documents.view'], ['pdf']],
        // §13.3 : rapports d'exploitation du parc d'écrans.
        'offline_screens' => ['PDF11 Écrans hors ligne', ['screens.view', 'supervision.view', 'reports.fleet'], ['pdf', 'csv', 'xlsx']],
        'remote_commands' => ['PDF12 Commandes à distance', ['devices.command', 'supervision.view', 'reports.fleet'], ['pdf', 'csv', 'xlsx']],
        'warranty_expiry' => ['PDF13 Équipements en fin de garantie', ['screens.view', 'reports.fleet'], ['pdf', 'csv', 'xlsx']],
        'availability_report' => ['PDF14 Disponibilité du parc', ['supervision.view', 'reports.fleet'], ['pdf', 'csv', 'xlsx']],
        'maintenance_report' => ['PDF15 Maintenance du parc', ['supervision.view', 'reports.fleet'], ['pdf', 'csv', 'xlsx']],
        // §5.5-j : fiche imprimable d'un écran.
        'screen_sheet' => ['PDF16 Fiche écran', ['screens.view', 'reports.fleet'], ['pdf']],
    ];

    public function __construct(private Access $access, private Audit $audit, private DocumentAccess $documents, private ReservationService $reservations) {}

    /** Extractions de masse : exigent en plus la permission « Générer des exports » (décision D7). Les fiches individuelles non. */
    public const BULK = ['room_planning', 'diffusion_report', 'equipment_inventory', 'room_usage', 'incident_report', 'audit_extract', 'offline_screens', 'remote_commands', 'warranty_expiry', 'availability_report', 'maintenance_report'];

    public function permits(User $user, string $type): bool
    {
        $definition = self::TYPES[$type] ?? null;

        return $definition !== null
            && collect($definition[1])->contains(fn ($p) => $this->access->can($user, $p))
            && (! in_array($type, self::BULK, true) || $this->access->can($user, 'exports.create'));
    }

    public function assertAllowed(User $user, string $type, array $params): void
    {
        if (! $this->permits($user, $type)) {
            throw new AuthorizationException;
        }
        match ($type) {
            'reservation_confirmation' => $this->assertReservation($user, $params['reservation_id'] ?? null),
            'document_sheet' => $this->documents->canView(Document::query()->findOrFail($params['document_id'] ?? null), $user) ?: throw new AuthorizationException,
            'meeting_preparation' => $this->assertMeetingPreparation($user, $params['occurrence_id'] ?? null),
            'campaign_order' => $this->assertCampaignOrder($user, $params['campaign_id'] ?? null),
            'diffusion_report' => $this->assertDiffusion($user, $params['campaign_id'] ?? null),
            'screen_sheet' => $this->assertScreenSheet($user, $params['screen_id'] ?? null),
            default => null,
        };
    }

    /**
     * PDF03 (SEC-02) : gestionnaire de la salle, ou équipe de prestation si la réunion comporte une prestation de son périmètre.
     */
    private function assertMeetingPreparation(User $user, ?string $id): void
    {
        $occurrence = ReservationOccurrence::query()->with(['room', 'serviceRequests.serviceType'])->findOrFail($id);
        if ($this->reservations->manages($occurrence->room, $user)) {
            return;
        }
        $scope = $this->access->powerScope($user, 'prestations');
        $types = $scope === null ? null : ($scope['service_types'] ?? []);
        $covered = $this->access->can($user, 'services.process') && $occurrence->serviceRequests->contains(fn ($s) => $types === null || in_array($s->serviceType?->code, $types, true));
        if (! $covered) {
            throw new AuthorizationException;
        }
    }

    /**
     * PDF04 (HAB-01) : les cibles de la campagne doivent relever du site et de la zone de l'habilitation.
     */
    private function assertCampaignOrder(User $user, ?string $id): void
    {
        $campaign = Campaign::query()->with('targets')->findOrFail($id);
        app(CampaignService::class)->assertTargetsInScope($campaign->targets->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->all(), $user, 'campaigns.view');
    }

    public function generate(Export $export): void
    {
        $user = $export->requester;
        $this->assertAllowed($user, $export->type, $export->params ?? []);
        $export->forceFill(['status' => 'processing', 'progress' => 10])->save();
        $model = $this->build($export->type, $export->params ?? [], $user);
        $export->forceFill(['progress' => 60, 'classification' => $model['classification']])->save();
        $stamp = now(config('saie.timezone'))->format('Ymd-His');
        $name = str($model['title'])->slug().'-'.$stamp.'.'.$export->format;
        $key = "exports/{$export->id}/$name";
        $content = match ($export->format) {
            'csv' => $this->csv($model),
            'xlsx' => $this->xlsx($model),
            default => $this->pdf($model, $user),
        };
        Storage::disk('local')->put($key, $content);
        $export->forceFill([
            'status' => 'ready', 'progress' => 100, 'file_key' => $key, 'file_name' => $name, 'size' => strlen($content), 'completed_at' => now(),
            'expires_at' => now()->addHours((int) Setting::effective('retention.export_hours')),
        ])->save();
        $this->audit->log('export.generated', $export, actor: $user, after: ['type' => $export->type, 'format' => $export->format], objectLabel: self::TYPES[$export->type][0]);
    }

    /**
     * Modèle de document commun : métadonnées, sections et tableaux.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public function build(string $type, array $p, User $user): array
    {
        $tz = config('saie.timezone');
        $fmt = fn ($d) => $d ? CarbonImmutable::parse($d)->setTimezone($tz)->locale('fr')->isoFormat('DD/MM/YYYY HH:mm') : '—';
        $from = isset($p['from']) ? CarbonImmutable::parse($p['from']) : now()->subDays(30)->toImmutable();
        $to = isset($p['to']) ? CarbonImmutable::parse($p['to']) : now()->toImmutable();
        $period = $fmt($from).' → '.$fmt($to);

        return match ($type) {
            'reservation_confirmation' => $this->reservationConfirmation($p, $user, $fmt),
            'room_planning' => $this->roomPlanning($from, $to, $p, $user, $fmt, $period),
            'meeting_preparation' => $this->meetingPreparation($p, $fmt),
            'campaign_order' => $this->campaignOrder($p, $fmt),
            'diffusion_report' => $this->diffusionReport($p, $from, $to, $fmt, $period, $user),
            'equipment_inventory' => $this->inventory($fmt, $this->exportScreenScope($user, 'screens.view', 'reports.fleet'), $p),
            'room_usage' => $this->roomUsage($from, $to, $period, $p['floor_id'] ?? null),
            'incident_report' => $this->incidents($p, $from, $to, $fmt, $period, $this->exportScreenScope($user, 'supervision.view', 'reports.fleet')),
            'offline_screens' => app(FleetReports::class)->offline($fmt, $this->exportScreenScope($user, ...self::TYPES['offline_screens'][1])),
            'remote_commands' => app(FleetReports::class)->commands($from, $to, $fmt, $period, $this->exportScreenScope($user, ...self::TYPES['remote_commands'][1])),
            'warranty_expiry' => app(FleetReports::class)->warranty((int) ($p['months'] ?? 6), $this->exportScreenScope($user, ...self::TYPES['warranty_expiry'][1])),
            'availability_report' => app(FleetReports::class)->availability($from, $to, $period, $this->exportScreenScope($user, ...self::TYPES['availability_report'][1])),
            'maintenance_report' => app(FleetReports::class)->maintenance($from, $to, $fmt, $period, $this->exportScreenScope($user, ...self::TYPES['maintenance_report'][1])),
            'audit_extract' => $this->auditExtract($p, $from, $to, $fmt, $period, $user),
            'document_sheet' => $this->documentSheet($p, $user, $fmt),
            'screen_sheet' => $this->screenSheet($p, $fmt),
            default => throw new BusinessException('EXPORT_UNKNOWN', 'Type d’export inconnu.', 422),
        };
    }

    private function reservationConfirmation(array $p, User $user, callable $fmt): array
    {
        $r = Reservation::query()->with(['organizer', 'occurrences.room.floor', 'occurrences.room.equipment', 'occurrences.serviceRequests.serviceType', 'occurrences.resourceBookings.resource'])->findOrFail($p['reservation_id']);
        $pending = $r->occurrences->contains('status', 'pending');
        $first = $r->occurrences->sortBy('starts_at')->first();
        $history = AuditLog::query()->where('object_type', 'Reservation')->where('object_id', $r->id)->orderBy('occurred_at')->get();
        $statusLabels = ['pending' => 'En attente', 'confirmed' => 'Confirmée', 'refused' => 'Refusée', 'cancelled' => 'Annulée', 'in_progress' => 'En cours', 'completed' => 'Terminée', 'no_show' => 'Non honorée'];

        return [
            'title' => 'Confirmation de réservation', 'reference' => $r->reference, 'classification' => $r->confidential ? 'restreint' : 'interne',
            'banner' => $pending ? 'Demande non confirmée' : null,
            'meta' => [
                ['Statut', $pending ? 'Demande non confirmée' : ($statusLabels[$first->status] ?? $first->status)], ['Objet', $r->subject], ['Organisateur', $r->organizer->name],
                ['Salle', $first->room->name.' · '.$first->room->floor->label], ['Capacité de la salle', $first->room->capacity.' places'], ['Participants', $r->participant_count.' (en nombre)'],
                ['Confidentialité', $r->confidential ? 'Confidentielle — affichée « Réservée » sur les écrans' : 'Standard'], ['Fuseau', config('saie.timezone')],
                ['Équipements', $first->room->equipment->pluck('name')->implode(', ') ?: '—'],
            ],
            'sections' => [
                ['title' => 'Occurrences', 'table' => ['headers' => ['#', 'Début', 'Fin', 'Salle', 'Statut'], 'rows' => $r->occurrences->sortBy('starts_at')->map(fn ($o) => [$o->sequence, $fmt($o->starts_at), $fmt($o->ends_at), $o->room->name, $statusLabels[$o->status] ?? $o->status])->values()->all()]],
                ['title' => 'Prestations et ressources', 'table' => ['headers' => ['Élément', 'Statut'], 'rows' => $r->occurrences->flatMap(fn ($o) => $o->serviceRequests->map(fn ($s) => [$s->label(), $s->status === 'ready' ? 'Confirmée' : 'En attente de confirmation'])->merge($o->resourceBookings->where('status', 'active')->map(fn ($b) => [$b->resource->name, 'Réservée'])))->unique(0)->values()->all()], 'empty' => 'Aucune prestation demandée.'],
                ['title' => 'Historique des validations', 'table' => ['headers' => ['Date', 'Acteur', 'Action', 'Motif'], 'rows' => $history->map(fn ($h) => [$fmt($h->occurred_at), $h->actor_label ?? 'Système', ActionLabel::for($h->action), $h->reason ?? ''])->all()]],
            ],
            'notes' => ['Les données des participants externes sont traitées selon les textes internes de la Commission de la CEEAC (GOV-02).', 'Le QR code ouvre la fiche authentifiée ; il ne donne accès à aucune donnée sans connexion.'],
            'qr' => rtrim((string) config('app.frontend_url'), '/')."/reservations/{$r->id}",
        ];
    }

    private function roomPlanning(CarbonImmutable $from, CarbonImmutable $to, array $p, User $user, callable $fmt, string $period): array
    {
        $internal = ($p['variant'] ?? 'public') === 'internal' && $this->access->can($user, 'reservations.approve');
        $rows = [];
        foreach (Room::query()->with('floor')->where('status', 'active')->orderBy('name')->get() as $room) {
            foreach ($this->reservations->busy($room, $from, $to, $internal ? $user : null) as $slot) {
                $rows[] = [$room->name, $room->floor->label, $fmt($slot['starts_at']), $fmt($slot['ends_at']), $slot['type'] === 'blocked' ? 'Indisponible' : ($internal && ! $slot['confidential'] ? $slot['label'] : 'Réservée'), $room->prep_minutes + $room->cleanup_minutes > 0 ? ($room->prep_minutes.' + '.$room->cleanup_minutes.' min') : '—'];
            }
        }

        return [
            'title' => 'Planning des salles', 'reference' => 'PLN-'.$from->format('Ymd'), 'classification' => $internal ? 'interne' : 'public', 'landscape' => true,
            'meta' => [['Période', $period], ['Version', $internal ? 'Interne (selon droits)' : 'Publique : sans titres ni organisateurs'], ['Fuseau', config('saie.timezone')]],
            'sections' => [['title' => 'Créneaux', 'table' => ['headers' => ['Salle', 'Niveau', 'Début', 'Fin', 'Objet', 'Préparation'], 'rows' => $rows], 'empty' => 'Aucune réservation sur la période.']],
            'notes' => ['Les réunions confidentielles sont libellées « Réservée ».'],
        ];
    }

    private function meetingPreparation(array $p, callable $fmt): array
    {
        $o = ReservationOccurrence::query()->with(['reservation', 'room.floor', 'room.equipment', 'serviceRequests.serviceType', 'serviceRequests.assignee', 'resourceBookings.resource', 'checklistItems'])->findOrFail($p['occurrence_id']);

        return [
            'title' => 'Fiche de préparation de réunion', 'reference' => $o->reservation->reference.'-'.$o->sequence, 'classification' => 'interne',
            'meta' => [['Réunion', $o->reservation->confidential ? 'Réunion confidentielle' : $o->reservation->subject], ['Lieu', $o->room->name.' · '.$o->room->floor->label], ['Créneau', $fmt($o->starts_at).' → '.$fmt($o->ends_at)],
                ['Montage', $fmt($o->blocking_start)], ['Remise en état jusqu’à', $fmt($o->blocking_end)], ['Participants attendus', (string) $o->reservation->participant_count], ['Matériel de la salle', $o->room->equipment->pluck('name')->implode(', ') ?: '—'],
                ['Ressources mobiles', $o->resourceBookings->where('status', 'active')->map(fn ($b) => $b->resource->name)->implode(', ') ?: '—']],
            'sections' => [
                ['title' => 'Prestations', 'table' => ['headers' => ['Prestation', 'Responsable', 'Échéance', 'Statut', 'Note'], 'rows' => $o->serviceRequests->map(fn ($s) => [$s->label(), $s->assignee?->name ?? 'À attribuer', $fmt($s->due_at), self::SERVICE_STATUS[$s->status] ?? $s->status, $s->response_note ?? ''])->all()], 'empty' => 'Aucune prestation.'],
                ['title' => 'Liste de contrôle', 'table' => ['headers' => ['Phase', 'Point', 'Constat'], 'rows' => $o->checklistItems->map(fn ($c) => [$c->phase === 'opening' ? 'Ouverture' : 'Remise en état', $c->label, $c->done_at ? 'Fait le '.$fmt($c->done_at) : '☐'])->all()], 'empty' => 'Liste de contrôle non renseignée.'],
            ],
            'notes' => ['Aucun lien de réunion privé ne figure sur ce document.'],
        ];
    }

    private function campaignOrder(array $p, callable $fmt): array
    {
        $c = Campaign::query()->with(['items.content', 'languages', 'targets', 'creator', 'publisher'])->findOrFail($p['campaign_id']);
        $screens = app(CampaignService::class)->resolveTargets($c->targets->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->all());
        $versions = ContentVersion::query()->with('approver')->whereIn('id', $c->items->flatMap(fn ($i) => array_values($i->version_map ?? [])))->get();

        return [
            'title' => 'Bon de programmation de campagne', 'reference' => $c->reference, 'classification' => $c->items->contains(fn ($i) => $i->content->classification === 'interne') ? 'interne' : 'public',
            'meta' => [['Campagne', $c->name], ['Priorité', (string) $c->priority], ['Période', $fmt($c->starts_at).' → '.$fmt($c->ends_at)], ['Date de fin', $fmt($c->ends_at)],
                ['Jours', $c->days ? implode(', ', array_map(fn ($d) => ['', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'][$d], $c->days)) : 'Tous'], ['Créneau', ($c->time_from ? substr($c->time_from, 0, 5) : '—').' – '.($c->time_to ? substr($c->time_to, 0, 5) : '—')],
                ['Ordre de rotation', $c->languages->pluck('lang')->map(fn ($l) => strtoupper($l))->implode(' → ')], ['Fuseau', config('saie.timezone')], ['Programmée par', $c->publisher?->name ?? 'Non publiée']],
            'sections' => [
                ['title' => 'Versions approuvées', 'table' => ['headers' => ['Contenu', 'Langue', 'Révision', 'Validateur', 'Décision', 'Classification'], 'rows' => $versions->map(fn ($v) => [$v->payload['title'] ?? '', strtoupper($v->lang), 'v'.$v->revision, $v->approver?->name ?? '', $fmt($v->approved_at), $v->classification])->all()]],
                ['title' => 'Annexe — écrans ciblés', 'table' => ['headers' => ['Écran', 'Nom', 'Niveau', 'Zone'], 'rows' => $screens->map(fn ($s) => [$s->code, $s->name, $s->location->floor->label, $s->location->zone->name])->all()]],
            ],
            'notes' => ['Le titre « Bon » ne constitue pas une signature électronique.'],
        ];
    }

    /**
     * ASVS 5.0 V8.2 : le rapport de diffusion couvre les seuls écrans du périmètre (site, zone) de celui qui le demande.
     */
    private function diffusionPermission(User $user): string
    {
        return $this->access->can($user, 'reports.view') ? 'reports.view' : 'campaigns.view';
    }

    private function assertDiffusion(User $user, ?string $campaignId): void
    {
        if ($campaignId) {
            $campaign = Campaign::query()->with('targets')->findOrFail($campaignId);
            app(CampaignService::class)->assertTargetsInScope($campaign->targets->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->all(), $user, $this->diffusionPermission($user));
        }
    }

    private function diffusionReport(array $p, CarbonImmutable $from, CarbonImmutable $to, callable $fmt, string $period, User $user): array
    {
        $campaign = isset($p['campaign_id']) ? Campaign::query()->find($p['campaign_id']) : null;
        $screens = $this->access->screenScope($user, $this->diffusionPermission($user));
        $devices = Device::query()->with('screen.location.floor')->where('status', 'active')->when($screens !== null, fn ($q) => $q->whereIn('screen_id', $screens))->orderBy('code')->get();
        $rows = $devices->map(function (Device $d) use ($from, $to, $campaign, $fmt): array {
            $base = DeviceReceipt::query()->where('device_id', $d->id)->whereBetween('occurred_at', [$from, $to]);
            // « Programmé » n'est jamais affirmé sans preuve : transmission durable de la campagne, ou de toute campagne sans filtre.
            $manifestIn = DB::table('campaign_transmissions')->where('device_id', $d->id)->when($campaign, fn ($q) => $q->where('campaign_id', $campaign->id))->exists()
                || ($campaign && Manifest::query()->where('device_id', $d->id)->whereRaw("payload->'schedule' @> ?::jsonb", [json_encode([['campaign_id' => $campaign->id]])])->exists());

            return [$d->screen?->code ?? $d->code, $d->screen?->location->floor->label ?? '—', $manifestIn ? 'Oui' : 'Non', (clone $base)->where('kind', 'manifest_received')->count(), (clone $base)->where('kind', 'activated')->count(),
                DeviceReceipt::playsCount((clone $base)->where('kind', 'played')->when($campaign, fn ($q) => $q->where('ref_type', 'campaign')->where('ref_id', $campaign->id))),
                $d->screen && $d->screen->control_channel !== 'none' && $d->screen->panel_state ? $d->screen->panel_state : 'Non mesuré', $fmt($d->last_seen_at), (clone $base)->where('kind', 'error')->count()];
        });

        return [
            'title' => 'Rapport de diffusion', 'reference' => 'DIF-'.$from->format('Ymd'), 'classification' => 'interne', 'landscape' => true,
            'meta' => [['Période', $period], ['Campagne', $campaign ? $campaign->reference.' · '.$campaign->name : 'Toutes'], ['Écrans', (string) $devices->count()]],
            'summary' => [['Programmés', (string) $rows->where(2, 'Oui')->count()], ['Activations déclarées', (string) $rows->sum(4)], ['Lectures déclarées', (string) $rows->sum(5)]],
            'sections' => [['title' => 'Par écran', 'table' => ['headers' => ['Écran', 'Niveau', 'Programmé', 'Manifestes reçus', 'Activations', 'Lectures déclarées', 'Dalle', 'Dernier contact', 'Erreurs'], 'rows' => $rows->all()]]],
            'notes' => ['Définitions (annexe C) : « Reçu » = accusé du terminal ; « Activé » = programme déclaré actif ; « Lecture déclarée » = rendu remonté par le logiciel.', 'Aucune estimation du nombre de personnes ayant lu n’est produite. « Non mesuré » signifie qu’aucun retour matériel de la dalle n’est disponible.'],
        ];
    }

    /**
     * HAB-01 : écrans couverts par la permission (null = tout le parc). Sans la permission (inventaire demandé au titre de
     * la gestion des salles), aucun écran n'est inclus.
     *
     * @return list<string>|null
     */
    private function exportScreenScope(User $user, string ...$permissions): ?array
    {
        // Réunion des périmètres des permissions détenues (null : tout le parc pour l'une d'elles).
        $ids = [];
        foreach ($permissions as $permission) {
            if (! $this->access->can($user, $permission)) {
                continue;
            }
            $scope = $this->access->screenScope($user, $permission);
            if ($scope === null) {
                return null;
            }
            $ids = array_merge($ids, $scope);
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<string>|null  $scope
     */
    private function inventory(callable $fmt, ?array $scope, array $p = []): array
    {
        // §13.3 : inventaire général, ou limité à un bâtiment ou à un niveau ; écrans archivés exclus sauf demande.
        $screens = Screen::query()->with(['location.floor.building', 'location.zone', 'room', 'activeDevice', 'groups', 'category'])
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))
            ->when(empty($p['include_archived']), fn ($q) => $q->where('status', '!=', 'archived'))
            ->when($p['building_id'] ?? null, fn ($q, $id) => $q->whereHas('location.floor', fn ($f) => $f->where('building_id', $id)))
            ->when($p['floor_id'] ?? null, fn ($q, $id) => $q->whereHas('location', fn ($l) => $l->where('floor_id', $id)))
            ->get()->sortBy(fn (Screen $s) => sprintf('%s|%04d|%s', $s->location->floor->building?->name, 100 - (int) $s->location->floor->level, $s->code))->values();
        $open = Incident::query()->whereIn('status', ['open', 'acknowledged'])->get(['device_id', 'screen_id']);
        $withIncident = $open->pluck('device_id')->merge($open->pluck('screen_id'))->filter()->unique()->values()->all();
        $rooms = Room::query()->with(['floor', 'equipment'])->when($p['floor_id'] ?? null, fn ($q, $id) => $q->where('floor_id', $id))->orderBy('name')->get();
        $status = ['active' => 'En service', 'maintenance' => 'En maintenance', 'inactive' => 'Désactivé', 'archived' => 'Archivé'];
        $technology = ['led' => 'LED', 'lcd' => 'LCD', 'oled' => 'OLED', 'other' => 'Autre'];
        $byFloor = $screens->groupBy(fn (Screen $s) => ($s->location->floor->building?->name ?? '—').' · '.$s->location->floor->label)->map(fn ($items, $label) => [
            $label, $items->count(), $items->where('status', 'active')->count(), $items->filter(fn ($s) => $s->activeDevice)->count(),
            $items->filter(fn ($s) => $s->warranty_until && $s->warranty_until->isPast())->count(),
        ])->values()->all();

        return [
            'title' => 'Inventaire des équipements', 'reference' => 'INV-'.now()->format('Ymd'), 'classification' => 'interne', 'landscape' => true,
            'meta' => [['Écrans', (string) $screens->count()], ['Salles', (string) $rooms->count()], ['Périmètre', $scope === null ? 'Tout le parc' : 'Site et zone de l’habilitation']],
            'sections' => [
                ['title' => 'Synthèse par bâtiment et niveau', 'table' => ['headers' => ['Bâtiment · niveau', 'Écrans', 'En service', 'Avec lecteur', 'Garantie expirée'], 'rows' => $byFloor]],
                ['title' => 'Écrans et lecteurs', 'table' => ['headers' => ['Code', 'Inventaire', 'Nom', 'Type', 'Bâtiment', 'Niveau', 'Emplacement', 'Salle', 'Statut', 'État', 'Catégorie', 'Criticité', 'Matériel', 'N° de série', 'Technologie', 'Interfaces', 'Mode', 'Résolution', 'Lecteur', 'Version', 'Acquisition', 'Garantie', 'Dernier contact'], 'rows' => $screens->map(fn (Screen $s) => [
                    $s->code, $s->inventory_code ?? '—', $s->name, $s->kind, $s->location->floor->building?->name ?? '—', $s->location->floor->label, $s->location->name.($s->location_detail ? ' · '.$s->location_detail : ''), $s->room?->name ?? '—',
                    $status[$s->status] ?? $s->status, Present::operationalState($s, $s->activeDevice, $withIncident)['label'], $s->category?->name ?? '—', ['low' => 'Faible', 'normal' => 'Normale', 'high' => 'Élevée', 'critical' => 'Critique'][$s->criticality] ?? '—',
                    trim(($s->manufacturer ?? '').' '.($s->model ?? '')) ?: '—', $s->serial_number ?? '—', $technology[$s->technology] ?? '—', implode(', ', array_map('strtoupper', $s->interfaces ?? [])) ?: '—', ['permanent' => 'Permanent', 'window' => 'Plage', 'on_demand' => 'À la demande'][$s->operating_mode] ?? '—', $s->resolution,
                    $s->activeDevice?->code ?? '—', $s->activeDevice?->app_version ?? '—', $s->acquired_on?->format('d/m/Y') ?? '—', $s->warranty_until?->format('d/m/Y') ?? '—', $fmt($s->activeDevice?->last_seen_at),
                ])->all()]],
                ['title' => 'Salles', 'table' => ['headers' => ['Code', 'Salle', 'Niveau', 'Capacité', 'Équipements'], 'rows' => $rooms->map(fn ($r) => [$r->code, $r->name, $r->floor->label, $r->capacity, $r->equipment->pluck('name')->implode(', ')])->all()]],
            ],
            'notes' => ['Ce rapport exclut jetons, secrets, adresses MAC et paramètres réseau sensibles.', '« État » : état opérationnel calculé à l’édition ; « lecture déclarée » ne prouve pas l’affichage physique.'],
        ];
    }

    private function roomUsage(CarbonImmutable $from, CarbonImmutable $to, string $period, ?string $floorId = null): array
    {
        $usage = app(ReportController::class)->roomUsage($from, $to, $floorId);

        return [
            'title' => 'Bilan d’utilisation des salles', 'reference' => 'BIL-'.$from->format('Ymd'), 'classification' => 'interne', 'landscape' => true,
            'meta' => [['Période', $period], ['Taux réservé global', $usage['rate'] !== null ? str_replace('.', ',', (string) $usage['rate']).' %' : '—'], ['Présence réelle', $usage['presence_rate'] !== null ? str_replace('.', ',', (string) $usage['presence_rate']).' % · '.$usage['presence'] : 'Présence non mesurée']],
            'sections' => [['title' => 'Par salle', 'table' => ['headers' => ['Salle', 'Niveau', 'Minutes disponibles', 'Minutes réservées', 'Taux réservé', 'Annulations', 'Non-présentations déclarées', 'Prestations'], 'rows' => collect($usage['rows'])->map(fn ($r) => [$r['room'], $r['floor'], $r['available_minutes'], $r['reserved_minutes'], $r['rate'] !== null ? $r['rate'].' %' : '—', $r['cancellations'], $r['no_shows'], $r['services']])->all()]]],
            'notes' => ['Taux réservé = minutes réservées confirmées / minutes disponibles après fermetures et jours non ouvrés.', 'La présence réelle n’est affichée que si la confirmation de présence (SAL-10) est activée : présence non mesurée.'],
        ];
    }

    /**
     * @param  list<string>|null  $scope
     */
    private function incidents(array $p, CarbonImmutable $from, CarbonImmutable $to, callable $fmt, string $period, ?array $scope): array
    {
        $items = Incident::query()->with(['screen', 'device', 'assignee', 'verifier'])->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))->when($p['incident_id'] ?? null, fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->whereBetween('detected_at', [$from, $to]))->orderBy('detected_at')->get();

        return [
            'title' => 'Rapport d’incidents et interventions', 'reference' => 'INC-'.$from->format('Ymd'), 'classification' => 'interne', 'landscape' => true,
            'meta' => [['Période', $period], ['Incidents', (string) $items->count()]],
            'sections' => [['title' => 'Incidents', 'table' => ['headers' => ['Référence', 'Détection', 'Équipement', 'Catégorie', 'Gravité', 'Impact', 'Responsable', 'Diagnostic', 'Résultat du traitement', 'Rétablissement', 'Indisponibilité mesurée', 'Vérifié par', 'Escalade', 'Statut'], 'rows' => $items->map(fn ($i) => [
                $i->reference, $fmt($i->detected_at), $i->screen?->code ?? $i->device?->code ?? '—', self::INCIDENT_CATEGORY[$i->category] ?? $i->category, self::INCIDENT_SEVERITY[$i->severity] ?? $i->severity, $i->impact ?? '', $i->assignee?->name ?? '—',
                $i->diagnosis ?? '', $i->resolution ?? '', $fmt($i->resolved_at), $i->resolved_at ? $i->detected_at->diffInMinutes($i->resolved_at, true).' min' : 'En cours',
                $i->verifier?->name ?? '—', $i->escalation_level > 0 ? 'Niveau '.$i->escalation_level : '—', self::INCIDENT_STATUS[$i->status] ?? $i->status,
            ])->all()]]],
            'notes' => ['La durée d’indisponibilité est mesurée entre détection et rétablissement ; le délai d’intervention est déclaratif.'],
        ];
    }

    private function auditExtract(array $p, CarbonImmutable $from, CarbonImmutable $to, callable $fmt, string $period, User $user): array
    {
        $items = AuditLog::query()->whereBetween('occurred_at', [$from, $to])->filtered($p)
            ->orderBy('occurred_at')->limit(2000)->get();
        $this->audit->log('audit.exported', null, actor: $user, after: ['count' => $items->count()], objectLabel: 'Extrait du journal');

        return [
            'title' => 'Extrait du journal', 'reference' => 'JRN-'.$from->format('Ymd'), 'classification' => 'interne', 'landscape' => true,
            'meta' => [['Période', $period], ['Filtres', collect($p)->except(['from', 'to'])->map(fn ($v, $k) => "$k=$v")->implode(', ') ?: 'Aucun'], ['Auteur de l’export', $user->name], ['Date serveur', $fmt(now())], ['Lignes', $items->count().($items->count() >= 2000 ? ' (limite atteinte)' : '')]],
            'sections' => [['title' => 'Événements', 'table' => ['headers' => ['Date', 'Acteur', 'Rôle', 'Pouvoir', 'Action', 'Objet', 'Motif', 'Résultat'], 'rows' => $items->map(fn ($a) => [$fmt($a->occurred_at), $a->actor_label ?? 'Système', $a->actor_role ?? '', $a->power ?? '', ActionLabel::for($a->action).' ('.$a->action.')', trim(($a->object_type ?? '').' '.($a->object_label ?? '')), $a->reason ?? '', $a->outcome])->all()]]],
            'notes' => ['Extrait et non journal intégral. Les données sensibles sont filtrées ; cet export est lui-même journalisé.'],
        ];
    }

    private function documentSheet(array $p, User $user, callable $fmt): array
    {
        $d = Document::query()->with(['owner', 'author', 'category', 'versions.creator', 'versions.assetVersion', 'currentVersion'])->findOrFail($p['document_id']);
        $reads = $d->classification !== 'public' && $this->documents->canManage($d, $user) ? $d->reads()->where('document_version_id', $d->current_version_id)->count() : null;

        return [
            'title' => 'Fiche documentaire', 'reference' => $d->reference, 'classification' => $d->classification,
            'meta' => [['Titre', $d->title], ['Propriétaire', $d->owner->name], ['Classification', ucfirst($d->classification)], ['Version courante', 'v'.($d->currentVersion?->version ?? '—')], ['Publication', $fmt($d->published_at)],
                ['Validité', ($d->valid_from?->format('d/m/Y') ?? '—').' → '.($d->expires_at?->format('d/m/Y') ?? 'sans échéance')], ['Catégorie', $d->category?->name ?? '—'], ['Accusés de lecture', $reads !== null ? "$reads lecteur(s) de la version courante" : 'Non applicable']],
            'sections' => [
                ['title' => 'Résumé', 'text' => $d->summary ?: 'Aucun résumé.'],
                ['title' => 'Historique des versions', 'table' => ['headers' => ['Version', 'Fichier', 'Taille', 'Empreinte SHA-256', 'Déposée par', 'Date'], 'rows' => $d->versions->sortByDesc('version')->map(fn ($v) => ['v'.$v->version, $v->assetVersion->original_name, number_format($v->assetVersion->size / 1024, 0, ',', ' ').' Ko', substr((string) $v->assetVersion->sha256, 0, 16).'…', $v->creator?->name ?? '', $fmt($v->created_at)])->values()->all()]],
            ],
            'notes' => ['La conversion en PDF est une copie de consultation ; elle ne vaut pas original signé.'],
        ];
    }

    private function assertScreenSheet(User $user, ?string $id): void
    {
        $screen = Screen::query()->findOrFail($id);
        $scope = $this->exportScreenScope($user, ...self::TYPES['screen_sheet'][1]);
        if ($scope !== null && ! in_array($screen->id, $scope, true)) {
            throw new AuthorizationException;
        }
    }

    /**
     * §5.5-j : fiche d'un écran (identité, localisation, matériel, lecteur, mise en service, derniers incidents).
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function screenSheet(array $p, callable $fmt): array
    {
        $s = Screen::query()->with(['location.floor.building', 'location.zone', 'room', 'activeDevice', 'groups', 'category', 'commissioner'])->findOrFail($p['screen_id']);
        $d = $s->activeDevice;
        $status = ['active' => 'En service', 'maintenance' => 'En maintenance', 'inactive' => 'Désactivé', 'archived' => 'Archivé'];
        $criticality = ['low' => 'Faible', 'normal' => 'Normale', 'high' => 'Élevée', 'critical' => 'Critique'];
        $mode = ['permanent' => 'Permanent (24 h/24)', 'window' => 'Selon la plage de fonctionnement', 'on_demand' => 'À la demande'];
        $kv = fn (array $rows) => ['headers' => ['Élément', 'Valeur'], 'rows' => array_map(fn ($r) => [$r[0], (string) ($r[1] ?? '—')], $rows)];
        $incidents = Incident::query()->where('screen_id', $s->id)->orderByDesc('detected_at')->limit(10)->get();

        return [
            'title' => 'Fiche écran', 'reference' => $s->code, 'classification' => 'interne',
            'meta' => [['Nom', $s->name], ['Code d’inventaire', $s->inventory_code ?? '—'], ['Catégorie', $s->category?->name ?? '—'], ['Statut', $status[$s->status] ?? $s->status],
                ['Criticité', $criticality[$s->criticality] ?? '—'], ['Mise en service', $s->commissioned_at ? $fmt($s->commissioned_at).' par '.($s->commissioner?->name ?? '—') : 'Non mis en service']],
            'sections' => [
                ['title' => 'Localisation', 'table' => $kv([['Bâtiment', $s->location->floor->building?->name], ['Niveau', $s->location->floor->label], ['Emplacement', $s->location->name],
                    ['Emplacement précis', $s->location_detail], ['Salle', $s->room?->name], ['Zone de diffusion', $s->location->zone?->name], ['Groupes', $s->groups->pluck('name')->implode(', ') ?: '—']])],
                ['title' => 'Matériel', 'table' => $kv([['Marque et modèle', trim(($s->manufacturer ?? '').' '.($s->model ?? '')) ?: '—'], ['N° de série', $s->serial_number], ['Résolution', $s->resolution],
                    ['Orientation', $s->orientation === 'portrait' ? 'Portrait' : 'Paysage'], ['Interfaces', implode(', ', array_map('strtoupper', $s->interfaces ?? [])) ?: '—'], ['Mode de fonctionnement', $mode[$s->operating_mode] ?? '—'],
                    ['Adresse IP · MAC', trim(($s->ip_address ?? '').' · '.($s->mac_address ?? ''), ' ·') ?: '—'], ['Acquisition', $s->acquired_on?->format('d/m/Y')], ['Garantie', $s->warranty_until?->format('d/m/Y')], ['Fournisseur', $s->supplier]])],
                ['title' => 'Lecteur', 'table' => $kv([['Code', $d?->code], ['Version', $d?->app_version], ['Canal', $d?->release_channel ?? ($d ? 'stable' : null)], ['Dernier contact', $d ? $fmt($d->last_seen_at) : null],
                    ['Adresse observée', $d?->observed_ip], ['Appairé le', $d ? $fmt($d->paired_at) : null]])],
                ['title' => 'Derniers incidents', 'table' => ['headers' => ['Référence', 'Titre', 'Gravité', 'Statut', 'Détecté le'], 'rows' => $incidents->map(fn (Incident $i) => [
                    $i->reference, $i->title, self::INCIDENT_SEVERITY[$i->severity] ?? $i->severity, self::INCIDENT_STATUS[$i->status] ?? $i->status, $fmt($i->detected_at)])->all()]],
            ],
            'notes' => ['Fiche générée à partir des données de l’inventaire et de la supervision au moment de l’export.'],
        ];
    }

    private function assertReservation(User $user, ?string $id): void
    {
        $reservation = Reservation::query()->findOrFail($id);
        if (Gate::forUser($user)->denies('viewAll', $reservation)) {
            throw new AuthorizationException;
        }
    }

    /**
     * CSV UTF-8 avec neutralisation des cellules interprétables comme formules (TAB-02).
     *
     * @param  array<string, mixed>  $model
     */
    public function csv(array $model): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($model['sections'] as $section) {
            if (empty($section['table'])) {
                continue;
            }
            fputcsv($handle, [$section['title']], ';');
            fputcsv($handle, $section['table']['headers'], ';');
            foreach ($section['table']['rows'] as $row) {
                fputcsv($handle, array_map(fn ($cell) => self::neutralize((string) $cell), $row), ';');
            }
            fputcsv($handle, [], ';');
        }
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * Classeur Excel : une feuille par section tabulaire, cellules toujours en texte (aucune formule possible).
     *
     * @param  array<string, mixed>  $model
     */
    public function xlsx(array $model): string
    {
        $sheets = collect($model['sections'])->filter(fn ($section) => ! empty($section['table']))->map(fn ($section) => [
            'title' => (string) $section['title'], 'headers' => array_values($section['table']['headers']), 'rows' => array_map('array_values', $section['table']['rows']),
        ])->values()->all();

        return XlsxWriter::build($sheets);
    }

    public static function neutralize(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /**
     * @param  array<string, mixed>  $model
     */
    public function pdf(array $model, User $user): string
    {
        $logo = 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents(resource_path('images/ceeac-logo.jpg')));
        $qr = isset($model['qr']) ? (new QRCode(new QROptions(['outputBase64' => true, 'scale' => 4, 'outputType' => 'png'])))->render($model['qr']) : null;
        $html = view('pdf.document', ['model' => $model, 'logo' => $logo, 'qr' => $qr, 'generatedAt' => now(config('saie.timezone'))->format('d/m/Y à H:i'), 'timezone' => config('saie.timezone'), 'user' => $user])->render();
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isPhpEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', ! empty($model['landscape']) ? 'landscape' : 'portrait');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $label = 'Généré le '.now(config('saie.timezone'))->format('d/m/Y H:i').' · '.config('saie.timezone').' · '.strtoupper((string) $model['classification']).' · Page {PAGE_NUM} sur {PAGE_COUNT}';
        $canvas->page_text(42, $canvas->get_height() - 28, $label, $font, 7.5, [0.4, 0.45, 0.53]);

        return (string) $dompdf->output();
    }
}
