<?php

namespace Database\Seeders;

use App\Jobs\GenerateExport;
use App\Models\Alert;
use App\Models\Approval;
use App\Models\Building;
use App\Models\CalendarSubscription;
use App\Models\Campaign;
use App\Models\ChecklistItem;
use App\Models\Content;
use App\Models\ContentCategory;
use App\Models\Device;
use App\Models\DeviceScreenshot;
use App\Models\DisplayZone;
use App\Models\Document;
use App\Models\DocumentBroadcast;
use App\Models\DocumentRead;
use App\Models\DynamicSource;
use App\Models\Equipment;
use App\Models\Export;
use App\Models\Floor;
use App\Models\Holiday;
use App\Models\ImportRun;
use App\Models\Incident;
use App\Models\Location;
use App\Models\MaintenancePlan;
use App\Models\MobileResource;
use App\Models\PlayerRelease;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Reservation;
use App\Models\ReservationOccurrence;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoleExtension;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\Screen;
use App\Models\ScreenGroup;
use App\Models\ServiceAccount;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\SourceSnapshot;
use App\Models\Structure;
use App\Models\Template;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Visit;
use App\Services\Access;
use App\Services\AssetService;
use App\Services\Audit;
use App\Services\CampaignService;
use App\Services\ContentWorkflow;
use App\Services\DocumentAccess;
use App\Services\DynamicSources;
use App\Services\ExportService;
use App\Services\Notifier;
use App\Services\ReservationService;
use App\Support\Reference;
use App\Support\Totp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Complément du jeu de démonstration : un exemple de chaque état et de chaque module que DemoSeeder ne couvre pas
 * (comptes suspendus ou verrouillés, réservations refusées ou en cours, prestations à chaque étape, exports prêts ou
 * en échec, alertes à confirmer, maintenance préventive, sources dynamiques, comptes de service…).
 *
 * Additif et rejouable : il ne supprime rien, ne modifie aucun compte existant et ne s'exécute qu'une fois.
 * Les scénarios passent autant que possible par les services métier, qui appliquent les règles et journalisent.
 */
class DemoScenariosSeeder extends DemoSeeder
{
    private const MARKER = 'herve.mba@demo.saie-ceeac.local';

    private string $tz;

    /** @var array<string, User> */
    private array $people = [];

    /** @var list<string> */
    private array $notes = [];

    public function run(): void
    {
        if (! User::query()->where('email', 'alain.mbeng@demo.saie-ceeac.local')->exists()) {
            $this->command?->warn('Jeu de démonstration de base absent : lancer d’abord DemoSeeder.');

            return;
        }
        if (User::query()->where('email', self::MARKER)->exists()) {
            $this->command?->info('Scénarios complémentaires déjà présents : aucune modification.');

            return;
        }
        $this->tz = (string) config('saie.timezone');
        foreach (['alain.mbeng', 'clarisse.ondo', 'nadia.kombila', 'jean.moussavou', 'grace.ndong', 'patrick.obiang', 'mireille.tchibinda', 'estelle.nze', 'loic.bouanga', 'sylvain.essono'] as $login) {
            $this->people[Str::before($login, '.')] = User::query()->where('email', "$login@demo.saie-ceeac.local")->firstOrFail();
        }
        $file = Storage::disk('local')->exists('demo-accounts.txt') ? (string) Storage::disk('local')->get('demo-accounts.txt') : '';
        $password = preg_match('/démonstration : (\S+)/u', $file, $m) ? $m[1] : (env('SAIE_DEMO_PASSWORD') ?: 'Demo-'.Str::password(12, symbols: false));

        // Tout ou rien : un échec n'insère aucun scénario, et le jeu peut être relancé.
        DB::transaction(function () use ($password): void {
            $this->accounts($password);
            $this->roomsAndResources();
            $this->reservationScenarios();
            $this->publicationScenarios();
            $this->alertScenarios();
            $this->fleetScenarios();
            $this->documentScenarios();
            $this->exportScenarios();
            $this->integrationScenarios();
        });

        Storage::disk('local')->put('demo-accounts.txt', rtrim($file)."\n\nScénarios complémentaires (DemoScenariosSeeder)\n".implode("\n", $this->notes)."\n");
        $this->command?->info('Scénarios complémentaires créés : '.count($this->notes).' repères ajoutés à storage/app/private/demo-accounts.txt');
    }

    /**
     * Comptes à chaque état atteignable (désactivé, verrouillé temporairement, bloqué jusqu'au déblocage par un administrateur,
     * mot de passe à changer, second facteur actif),
     * rôles temporaires, futurs ou retirés, pouvoirs étendus retirés ou à venir.
     */
    private function accounts(string $password): void
    {
        $today = $this->today();
        $accounts = [
            'didier' => ['Didier Ella', 'didier.ella', 'DSI', 'Technicien de maintenance', [['technicien', null]], [], 'actif, technicien du parc'],
            'ruth' => ['Ruth Mabika', 'ruth.mabika', 'CAB', 'Assistante de direction', [['consultation', null]], [], 'actif, consultation seule'],
            'paul' => ['Paul Nguema', 'paul.nguema', 'DIR', 'Chargé d’études', [['agent', null]], [], 'actif, second facteur activé (voir secret ci-dessous)'],
            'kevin' => ['Kévin Assoumou', 'kevin.assoumou', 'DIR', 'Chargé de communication', [['contributeur', 'DIR']], ['must_change_password' => true], 'nouvel arrivant : doit changer son mot de passe'],
            'sandra' => ['Sandra Bivigou', 'sandra.bivigou', 'MG', 'Gestionnaire des salles par intérim', [], [], 'intérim de gestionnaire de salle (fin dans 5 jours)'],
            'olga' => ['Olga Mintsa', 'olga.mintsa', 'DPS', 'Analyste', [['agent', null]], ['failed_logins' => 5, 'locked_until' => now()->addMinutes(30)], 'verrouillé 30 min après 5 échecs'],
            'herve' => ['Hervé Mba', 'herve.mba', 'DPS', 'Conseiller', [['agent', null]], ['failed_logins' => 20, 'locked_until' => now()->addYears(10)], 'bloqué après 20 codes de second facteur erronés : déblocage par un administrateur'],
            'bernard' => ['Bernard Okili', 'bernard.okili', 'DRH', 'Agent administratif (retraité)', [['agent', null]], ['status' => 'disabled'], 'désactivé (départ à la retraite)'],
        ];
        $this->notes[] = 'Comptes ajoutés (même mot de passe que ci-dessus) :';
        foreach ($accounts as $key => [$name, $login, $structure, $title, $roles, $state, $label]) {
            $user = User::query()->create(array_merge([
                'name' => $name, 'email' => "$login@demo.saie-ceeac.local", 'password' => $password, 'structure_id' => $this->structure($structure)->id,
                'title' => $title, 'is_demo' => true, 'email_verified_at' => now(), 'last_login_at' => $key === 'kevin' ? null : now()->subDays(random_int(1, 20)),
            ], $state));
            foreach ($roles as [$role, $scope]) {
                $this->assign($user, $role, $scope, $today->subMonths(2), null, 'Jeu de démonstration');
            }
            $this->people[$key] = $user;
            $this->notes[] = sprintf('  %-40s %s', $user->email, $label);
        }

        $secret = Totp::generateSecret();
        $codes = collect(range(1, 8))->map(fn () => Str::random(5).'-'.Str::random(5))->all();
        $this->people['paul']->forceFill(['mfa_secret' => $secret, 'mfa_enabled_at' => now()->subDays(12), 'mfa_recovery_codes' => array_map(fn (string $c) => Hash::make($c), $codes)])->save();
        // Hervé Mba a activé le second facteur puis saisi 20 codes erronés : seul un administrateur peut le débloquer (ASVS V6.3).
        $this->people['herve']->forceFill(['mfa_secret' => Totp::generateSecret(), 'mfa_enabled_at' => now()->subMonths(3)])->save();
        $this->notes[] = "  Second facteur de paul.nguema : secret TOTP $secret (à saisir dans une application d’authentification) ; codes de secours : ".implode(' ', $codes);

        $this->assign($this->people['sandra'], 'gestionnaire_salle', null, $today->subDays(10), $today->addDays(5)->setTime(23, 59), 'Intérim pendant les congés de Mireille Tchibinda');
        $this->assign($this->people['didier'], 'diffuseur', null, $today->addDays(7), $today->addMonths(3), 'Renfort de diffusion à compter de la semaine prochaine');
        $this->assign($this->people['ruth'], 'agent', null, $today->subYear(), null, 'Ancien rôle', revokedAt: $today->subMonths(1));

        RoleExtension::query()->create(['user_id' => $this->people['jean']->id, 'power' => 'modeles', 'starts_at' => $today->subMonths(2), 'ends_at' => $today->addMonths(2), 'revoked_at' => $today->subDays(2), 'granted_by' => $this->people['clarisse']->id, 'reason' => 'Refonte des modèles d’accueil (pouvoir retiré à la fin des travaux)']);
        RoleExtension::query()->create(['user_id' => $this->people['sandra']->id, 'power' => 'prestations', 'scope' => ['service_types' => ['RESTAURATION']], 'starts_at' => $today->addDays(3), 'ends_at' => $today->addMonths(1), 'granted_by' => $this->people['clarisse']->id, 'reason' => 'Pause-café de la session statutaire']);
        app(Access::class)->flush();

        $audit = app(Audit::class);
        $audit->log('auth.mfa_locked', $this->people['herve'], outcome: 'denied', actor: $this->people['herve']);
        $audit->log('user.disabled', $this->people['bernard'], 'Départ à la retraite', actor: $this->people['alain']);
        $audit->log('auth.login_failed', $this->people['olga'], outcome: 'denied', actor: $this->people['olga']);
    }

    /**
     * Salle inactive, salle à libération automatique avec bornes de durée, blocage institutionnel, ressource mobile.
     */
    private function roomsAndResources(): void
    {
        $equipment = Equipment::query()->pluck('id', 'code');
        $common = ['open_from' => '07:30', 'open_to' => '19:00', 'pending_hold_hours' => 48, 'is_demo' => true];
        $minko = Room::query()->create($common + [
            'code' => 'MINKO', 'name' => 'Salle Minko', 'floor_id' => $this->floor(2)->id, 'capacity' => 24, 'approval_mode' => 'auto', 'prep_minutes' => 10, 'cleanup_minutes' => 10,
            'manager_id' => $this->people['sandra']->id, 'location_text' => '2e étage · aile Ouest', 'auto_release_enabled' => true, 'min_minutes' => 30, 'max_minutes' => 240, 'lead_days' => 60,
        ]);
        $minko->equipment()->sync(collect(['VISIO', 'ECRAN65'])->filter(fn ($c) => isset($equipment[$c]))->mapWithKeys(fn ($c) => [$equipment[$c] => ['quantity' => 1]]));
        Room::query()->create($common + [
            'code' => 'LUALABA', 'name' => 'Salle Lualaba', 'floor_id' => $this->floor(4)->id, 'capacity' => 30, 'approval_mode' => 'manual', 'prep_minutes' => 15, 'cleanup_minutes' => 15,
            'manager_id' => $this->people['mireille']->id, 'location_text' => '4e étage · aile Ouest (fermée pour travaux)', 'status' => 'inactive',
        ]);
        $day = $this->workday(9);
        RoomBlock::query()->create(['room_id' => $this->room('CONGO')->id, 'starts_at' => $day->setTime(7, 30)->utc(), 'ends_at' => $day->setTime(19, 0)->utc(), 'kind' => 'institutional',
            'reason' => 'Session statutaire du Conseil des ministres : salle retenue par le Cabinet', 'created_by' => $this->people['mireille']->id]);
        MobileResource::query()->create(['code' => 'VIDEOPROJ-01', 'name' => 'Vidéoprojecteur portable', 'type' => 'projection', 'manager_id' => $this->people['mireille']->id]);
        $this->notes[] = 'Salles : Minko (validation automatique, libération automatique, 30 à 240 min, gérée par Sandra Bivigou), Lualaba (inactive), Congo bloquée le '.$day->format('d/m').' (blocage institutionnel).';
    }

    /**
     * Réservations à chaque étape (refusée, en cours, terminée, prioritaire, dépassement de capacité, série avec une
     * occurrence annulée, confidentielle en retard de validation) ; prestations à chaque statut ; visites à chaque statut.
     */
    private function reservationScenarios(): void
    {
        $service = app(ReservationService::class);
        $types = ServiceType::query()->pluck('id', 'code');
        $minko = $this->room('MINKO');

        [$s, $e] = $this->freeSlot($this->room('KIVU'), 4, '14:00', '16:00');
        $refused = $service->create(['room_id' => $this->room('KIVU')->id, 'start_at' => $s->toIso8601String(), 'end_at' => $e->toIso8601String(), 'subject' => 'Comité des œuvres sociales', 'confidentiality' => false, 'participant_count' => 10], $this->people['loic'])['reservation'];
        $service->approve($refused, $this->people['mireille'], false, 'Salle retenue pour la préparation de la session statutaire ; la salle Minko est disponible au même horaire.');
        Visit::query()->create(['reference' => Reference::next('VIS'), 'name' => 'Représentants du comité des œuvres sociales', 'visitors_count' => 4, 'occurrence_id' => $refused->occurrences()->value('id'), 'status' => 'cancelled', 'notes' => 'Visite annulée avec la réservation refusée', 'created_by' => $this->people['estelle']->id, 'is_demo' => true]);

        [$s, $e] = $this->freeSlot($minko, 3, '09:00', '12:00');
        $kit = MobileResource::query()->where('code', 'KIT-VISIO-01')->where('status', 'active')->value('id');
        $workshop = $service->create(['room_id' => $minko->id, 'start_at' => $s->toIso8601String(), 'end_at' => $e->toIso8601String(), 'subject' => 'Atelier régional sur la libre circulation', 'confidentiality' => false, 'participant_count' => 20,
            'description' => 'Atelier technique avec les points focaux des États membres.', 'external_participants' => [['name' => 'Points focaux nationaux', 'organization' => 'États membres de la CEEAC']],
            'resource_ids' => array_filter([$kit]),
            'services' => array_values(array_filter([
                isset($types['RESTAURATION']) ? ['service_type_id' => $types['RESTAURATION'], 'details' => 'Pause-café du matin', 'headcount' => 20, 'serve_time' => '10:30'] : null,
                ['service_type_id' => $types['VISIO'], 'details' => 'Liaison avec deux sites distants'],
                ['service_type_id' => $types['INTERPRETATION'], 'details' => 'Interprétation FR/EN'],
                ['service_type_id' => $types['SONO'], 'details' => 'Deux micros sans fil'],
            ])),
            'agenda' => [['title' => 'Ouverture', 'time_label' => '09:00–09:15'], ['title' => 'État des lieux par pays', 'time_label' => '09:15–10:30'], ['title' => 'Recommandations', 'time_label' => '10:45–12:00']],
        ], $this->people['nadia'])['reservation'];
        $occurrence = $workshop->occurrences()->firstOrFail();
        $status = ['RESTAURATION' => ['accepted', null], 'VISIO' => ['clarification', 'Merci de préciser les adresses des deux sites distants.'], 'INTERPRETATION' => ['requested', null], 'SONO' => ['cancelled', 'Sonorisation intégrée à la salle : prestation inutile.']];
        foreach ($occurrence->serviceRequests()->with('serviceType')->get() as $request) {
            [$state, $note] = $status[$request->serviceType->code] ?? ['requested', null];
            $request->forceFill(['status' => $state, 'response_note' => $note, 'assignee_id' => $state === 'requested' ? null : $this->people['mireille']->id, 'updated_by' => $this->people['mireille']->id])->save();
        }
        foreach ([['opening', 'Vérifier la liaison de visioconférence'], ['opening', 'Disposer les chevalets nominatifs'], ['closing', 'Ranger le kit de visioconférence'], ['closing', 'Signaler tout matériel manquant']] as [$phase, $label]) {
            ChecklistItem::query()->create(['occurrence_id' => $occurrence->id, 'phase' => $phase, 'label' => $label]);
        }
        Visit::query()->create(['reference' => Reference::next('VIS'), 'name' => 'Points focaux nationaux', 'institution' => 'États membres de la CEEAC', 'visitors_count' => 14, 'occurrence_id' => $occurrence->id, 'languages' => ['fr', 'en'], 'status' => 'planned', 'created_by' => $this->people['estelle']->id, 'is_demo' => true]);
        Visit::query()->create(['reference' => Reference::next('VIS'), 'name' => 'Délégation de l’Union africaine', 'institution' => 'Commission de l’Union africaine', 'visitors_count' => 5, 'status' => 'draft', 'notes' => 'Date à confirmer', 'created_by' => $this->people['estelle']->id, 'is_demo' => true]);

        [$s, $e] = $this->freeSlot($this->room('OGOOUE'), 5, '08:30', '10:00');
        $service->create(['room_id' => $this->room('OGOOUE')->id, 'start_at' => $s->toIso8601String(), 'end_at' => $e->toIso8601String(), 'subject' => 'Préparation du Conseil des ministres', 'confidentiality' => false, 'participant_count' => 14, 'is_priority' => true], $this->people['clarisse']);

        [$s, $e] = $this->freeSlot($this->room('OGOOUE'), 6, '14:00', '16:00');
        $service->create(['room_id' => $this->room('OGOOUE')->id, 'start_at' => $s->toIso8601String(), 'end_at' => $e->toIso8601String(), 'subject' => 'Assemblée du personnel des moyens généraux', 'confidentiality' => false, 'participant_count' => 24, 'capacity_override_reason' => 'Chaises d’appoint installées par les moyens généraux'], $this->people['mireille']);

        [$s, $e] = $this->freeSlot($minko, 7, '15:00', '16:00');
        $series = $service->create(['room_id' => $minko->id, 'start_at' => $s->toIso8601String(), 'end_at' => $e->toIso8601String(), 'subject' => 'Point d’équipe RH', 'confidentiality' => false, 'participant_count' => 6, 'recurrence' => ['freq' => 'weekly', 'count' => 4], 'recurrence_mode' => 'partial'], $this->people['loic']);
        if (count($series['created']) > 1) {
            $service->cancel($series['created'][1], 'single', $this->people['loic'], 'Organisateur en mission cette semaine-là');
        }

        [$s, $e] = $this->freeSlot($this->room('KIVU'), 6, '10:00', '11:30');
        $confidential = $service->create(['room_id' => $this->room('KIVU')->id, 'start_at' => $s->toIso8601String(), 'end_at' => $e->toIso8601String(), 'subject' => 'Entretien avec l’ambassade (confidentiel)', 'confidentiality' => true, 'participant_count' => 4], $this->people['estelle'])['reservation'];
        Approval::query()->where('object_type', 'reservation')->where('object_id', $confidential->id)->update(['created_at' => now()->subHours(30), 'due_at' => now()->subHours(6), 'reminded_at' => now()->subHours(18), 'escalated_at' => now()->subHours(6)]);

        foreach (Reservation::query()->whereIn('id', [$refused->id, $workshop->id, $confidential->id, $series['reservation']->id])->orWhere('subject', 'Préparation du Conseil des ministres')->orWhere('subject', 'Assemblée du personnel des moyens généraux')->get() as $reservation) {
            $reservation->forceFill(['is_demo' => true])->save();
        }

        $this->pastAndCurrentMeetings($types);
        $this->notes[] = 'Réservations : refusée (Kivu), atelier avec 4 prestations à des statuts différents (Minko), prioritaire et capacité dépassée (Ogooué), série hebdomadaire avec une occurrence annulée, confidentielle en retard de validation (Kivu), réunion en cours et réunion terminée.';
    }

    /**
     * Réunion en cours (présence confirmée) et réunion terminée hier (liste de contrôle faite, prestation livrée, visite close).
     *
     * @param  Collection<string, string>  $types
     */
    private function pastAndCurrentMeetings(Collection $types): void
    {
        $now = CarbonImmutable::now($this->tz);
        $start = $now->setTime((int) $now->format('H'), intdiv((int) $now->format('i'), 15) * 15)->subMinutes(30)->utc();
        $end = $start->addMinutes(90);
        $room = collect(['MINKO', 'CONGO', 'OGOOUE', 'KIVU'])->map(fn ($c) => $this->room($c))->first(fn (Room $r) => app(ReservationService::class)->conflicts($r, [[$start, $end]]) === []);
        if ($room) {
            $this->meeting($room, 'Revue hebdomadaire du parc d’écrans', $this->people['alain'], $start, $end, 'in_progress', ['checked_in_at' => $start->addMinutes(3), 'checked_in_by' => $this->people['alain']->id]);
        }

        $yesterday = $this->workday(-1);
        foreach (['MINKO', 'CONGO', 'OGOOUE'] as $code) {
            $room = $this->room($code);
            $start = $yesterday->setTime(9, 0)->utc();
            if (app(ReservationService::class)->conflicts($room, [[$start, $start->addHours(2)]]) !== []) {
                continue;
            }
            $occurrence = $this->meeting($room, 'Séance de travail avec la Banque mondiale', $this->people['estelle'], $start, $start->addHours(2), 'completed', ['checked_in_at' => $start->subMinutes(5), 'checked_in_by' => $this->people['estelle']->id]);
            ServiceRequest::query()->create(['occurrence_id' => $occurrence->id, 'service_type_id' => $types['VISIO'], 'details' => 'Liaison avec Washington', 'status' => 'ready', 'response_note' => 'Liaison testée à 8 h 30', 'assignee_id' => $this->people['mireille']->id, 'due_at' => $start->subHours(2)]);
            foreach ([['opening', 'Tester la liaison'], ['closing', 'Remettre la salle en configuration standard']] as [$phase, $label]) {
                ChecklistItem::query()->create(['occurrence_id' => $occurrence->id, 'phase' => $phase, 'label' => $label, 'done_at' => $phase === 'opening' ? $start->subMinutes(20) : $start->addHours(2)->addMinutes(10), 'done_by' => $this->people['mireille']->id]);
            }
            Visit::query()->create(['reference' => Reference::next('VIS'), 'name' => 'Mission de la Banque mondiale', 'institution' => 'Banque mondiale', 'visitors_count' => 3, 'occurrence_id' => $occurrence->id, 'languages' => ['fr', 'en'], 'status' => 'done', 'created_by' => $this->people['estelle']->id, 'is_demo' => true]);
            break;
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function meeting(Room $room, string $subject, User $organizer, CarbonImmutable $start, CarbonImmutable $end, string $status, array $extra = []): ReservationOccurrence
    {
        $reservation = Reservation::query()->create(['reference' => Reference::next('RES'), 'organizer_id' => $organizer->id, 'structure_id' => $organizer->structure_id, 'subject' => $subject, 'participant_count' => 8, 'confidential' => false, 'created_by' => $organizer->id, 'is_demo' => true]);

        return $reservation->occurrences()->create(array_merge(['room_id' => $room->id, 'sequence' => 1, 'starts_at' => $start, 'ends_at' => $end, 'blocking_start' => $start->subMinutes($room->prep_minutes),
            'blocking_end' => $end->addMinutes($room->cleanup_minutes), 'status' => $status, 'check_in_code' => strtoupper(Str::random(6))], $extra));
    }

    /**
     * Campagnes suspendue, en mode événement, archivée et en brouillon ; contenus suspendu et proposé avec cibles ;
     * listes de lecture active et archivée ; modèles en brouillon et archivé.
     */
    private function publicationScenarios(): void
    {
        $workflow = app(ContentWorkflow::class);
        $campaigns = app(CampaignService::class);
        $template = Template::query()->where('code', 'TPL-INST-PAYSAGE')->value('id');
        $category = fn (string $code) => ContentCategory::query()->where('code', $code)->value('id');
        $content = function (User $author, string $title, string $categoryCode, string $class, array $langs, array $extra = []) use ($template, $category): Content {
            $content = Content::query()->create(array_merge(['reference' => Reference::next('CNT'), 'title' => $title, 'category_id' => $category($categoryCode), 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id,
                'classification' => $class, 'template_id' => $template, 'expires_at' => $this->today()->addDays(25)->setTime(18, 0), 'status' => 'draft', 'is_demo' => true], $extra));
            foreach ($langs as $lang => [$t, $b]) {
                $content->languages()->create(['lang' => $lang, 'title' => $t, 'body' => $b, 'status' => 'draft']);
            }

            return $content;
        };
        $approve = function (Content $c, array $langs) use ($workflow): void {
            $workflow->submit($c, $langs, $c->author);
            foreach ($langs as $lang) {
                $this->approveAllLevels($c, $lang, $lang === 'fr' ? $this->people['jean'] : $this->people['grace']);
            }
        };
        $campaign = function (string $name, int $priority, CarbonImmutable $from, CarbonImmutable $to, array $contents, array $targets, array $extra = []): Campaign {
            $campaign = Campaign::query()->create(array_merge(['reference' => Reference::next('CMP'), 'name' => $name, 'priority' => $priority, 'starts_at' => $from->utc(), 'ends_at' => $to->utc(), 'slide_duration_s' => 12, 'created_by' => $this->people['patrick']->id, 'status' => 'draft', 'is_demo' => true], $extra));
            foreach ($contents as $i => $c) {
                $campaign->items()->create(['content_id' => $c->id, 'position' => $i + 1]);
            }
            foreach (['fr', 'en'] as $rank => $lang) {
                DB::table('campaign_languages')->insert(['campaign_id' => $campaign->id, 'lang' => $lang, 'rank' => $rank + 1]);
            }
            foreach ($targets as [$type, $id]) {
                $campaign->targets()->create(['target_type' => $type, 'target_id' => $id]);
            }

            return $campaign;
        };
        $internal = ScreenGroup::query()->where('code', 'GRP-INTERNES')->value('id');
        $today = $this->today();

        $blood = $content($this->people['nadia'], 'Collecte de sang', 'RH', 'interne', ['fr' => ['Collecte de sang', 'Le service médical organise une collecte de sang au rez-de-chaussée.'], 'en' => ['Blood drive', 'The medical unit is organising a blood drive on the ground floor.']]);
        $approve($blood, ['fr', 'en']);
        $suspended = $campaign('Collecte de sang — étages', 45, $today->setTime(8, 0), $today->addDays(7)->setTime(18, 0), [$blood], [['group', $internal]]);
        $campaigns->publish($suspended, $this->people['patrick']);
        $campaigns->suspend($suspended->refresh(), $this->people['patrick'], 'Collecte reportée à la demande du service médical');

        $programme = Content::query()->where('is_demo', true)->where('title', 'Programme de la session statutaire')->where('status', '!=', 'archived')->first();
        if ($programme) {
            $day = $this->workday(9);
            $event = $campaign('Session statutaire — mode événement', 90, $day->setTime(7, 0), $day->addDay()->setTime(19, 0), [$programme],
                [['zone', DisplayZone::query()->where('code', 'Z-ACCUEIL')->value('id')], ['zone', DisplayZone::query()->where('code', 'Z-SALLES')->value('id')]], ['kind' => 'event']);
            $campaigns->publish($event, $this->people['patrick']);
        }

        $old = $campaign('Vœux de nouvel an', 40, $today->subMonths(10)->setTime(8, 0), $today->subMonths(9)->setTime(18, 0), [$blood], [['group', $internal]]);
        $old->forceFill(['status' => 'archived', 'published_at' => $today->subMonths(10), 'published_by' => $this->people['patrick']->id])->save();

        $evacuation = Content::query()->where('is_demo', true)->where('title', 'Consignes d’évacuation')->first() ?? $blood;
        $campaign('Semaine de la sécurité (en préparation)', 60, $today->addDays(20)->setTime(8, 0), $today->addDays(26)->setTime(18, 0), [$evacuation], [['zone', DisplayZone::query()->where('code', 'Z-INTERNE')->value('id')]]);

        $floor3 = $this->floor(3);
        $proposal = $content($this->people['kevin'], 'Journée portes ouvertes du Département', 'ANNONCE', 'interne', ['fr' => ['Journée portes ouvertes', 'Le Département Intégration régionale présente ses programmes jeudi au 3e étage.']],
            ['proposed_targets' => [['type' => 'floor', 'id' => $floor3->id, 'label' => $floor3->label]]]);
        $workflow->submit($proposal, ['fr'], $this->people['kevin']);

        $playlist = Playlist::query()->create(['name' => 'Boucle d’accueil institutionnelle', 'description' => 'Messages permanents du hall', 'status' => 'active', 'created_by' => $this->people['patrick']->id]);
        $loop = Content::query()->where('is_demo', true)->whereIn('status', ['approved', 'scheduled', 'broadcasting'])->whereIn('title', ['Message de la Présidence de la Commission', 'Contenu institutionnel de repli', 'Campagne santé au travail'])->get();
        foreach ($loop->values() as $i => $c) {
            PlaylistItem::query()->create(['playlist_id' => $playlist->id, 'content_id' => $c->id, 'position' => $i + 1, 'duration_s' => 15]);
        }
        Playlist::query()->create(['name' => 'Boucle des fêtes de fin d’année (archivée)', 'description' => 'Utilisée en décembre dernier', 'status' => 'archived', 'created_by' => $this->people['patrick']->id]);

        $layout = Template::query()->where('code', 'TPL-INST-PORTRAIT')->first()?->layout;
        if ($layout !== null) {
            Template::query()->create(['code' => 'TPL-AGENDA-PORTRAIT', 'name' => 'Agenda du jour (portrait, en préparation)', 'orientation' => 'portrait', 'purpose' => 'content', 'layout' => $layout, 'status' => 'draft', 'created_by' => $this->people['clarisse']->id]);
            Template::query()->create(['code' => 'TPL-ANCIEN-PORTRAIT', 'name' => 'Ancien gabarit institutionnel', 'orientation' => 'portrait', 'purpose' => 'content', 'layout' => $layout, 'status' => 'archived', 'created_by' => $this->people['clarisse']->id]);
        }
        $this->notes[] = 'Publication : campagne suspendue (collecte de sang), mode événement programmé (session statutaire), campagne archivée, campagne en brouillon, contenu proposé avec cibles par un nouvel arrivant, listes de lecture active et archivée, modèles en brouillon et archivé.';
    }

    /**
     * Alerte à confirmer par un second titulaire, alerte active sur un étage, exercice programmé.
     */
    private function alertScenarios(): void
    {
        $alain = $this->people['alain'];
        $audit = app(Audit::class);
        $screens = Screen::query()->where('status', 'active')->count();
        $create = function (array $attributes, array $targets) use ($alain, $audit): Alert {
            $alert = Alert::query()->create($attributes + ['reference' => Reference::next('ALT'), 'author_id' => $alain->id, 'offline_at_publish' => 0]);
            foreach ($targets as [$type, $id]) {
                $alert->targets()->create(['target_type' => $type, 'target_id' => $id]);
            }
            $audit->log($alert->status === 'pending' ? 'alert.submitted' : 'alert.published', $alert, $alert->justification, power: 'urgence', actor: $alain, after: ['severity' => $alert->severity]);

            return $alert;
        };
        $pending = $create(['title' => 'Fermeture anticipée des bureaux vendredi', 'message' => 'Les bureaux fermeront à 14 h vendredi en raison des travaux électriques.', 'severity' => 'info', 'starts_at' => now(), 'expires_at' => now()->addHours(2),
            'requested_duration_min' => 120, 'justification' => 'Note du Secrétariat général sur les travaux électriques', 'status' => 'pending', 'target_count' => $screens], [['all', null]]);
        app(Notifier::class)->notify($this->people['patrick'], 'alert.pending', 'Alerte à confirmer : '.$pending->title, 'Information adressée à tout le parc : un second titulaire du pouvoir « urgence » doit la confirmer.', '/affichage/alertes', 'alert', $pending->id);

        $floor5 = $this->floor(5);
        $create(['title' => 'Coupure d’eau au 5e étage', 'message' => 'Les sanitaires du 5e étage sont fermés jusqu’à 16 h. Utilisez ceux du 4e étage.', 'translations' => ['en' => ['title' => 'Water cut on the 5th floor', 'message' => 'The 5th floor restrooms are closed until 4 p.m. Please use the 4th floor.']],
            'severity' => 'warning', 'starts_at' => now()->subMinutes(10), 'expires_at' => now()->addMinutes(80), 'requested_duration_min' => 90, 'justification' => 'Intervention du plombier signalée par les moyens généraux', 'status' => 'active',
            'target_count' => Screen::query()->whereHas('location', fn ($q) => $q->where('floor_id', $floor5->id))->count()], [['floor', $floor5->id]]);

        $day = $this->workday(2);
        $create(['title' => 'Exercice d’évacuation', 'message' => 'Exercice : rejoignez le point de rassemblement par les escaliers.', 'severity' => 'exercise', 'starts_at' => $day->setTime(10, 0)->utc(), 'expires_at' => $day->setTime(10, 20)->utc(), 'requested_duration_min' => 20,
            'justification' => 'Exercice semestriel d’évacuation programmé avec la sécurité', 'status' => 'active', 'target_count' => $screens], [['zone', DisplayZone::query()->where('code', 'Z-INTERNE')->value('id')]]);
        $this->notes[] = 'Alertes : une à confirmer (tout le parc, Patrick Obiang est sollicité), une active au 5e étage (90 min), un exercice programmé le '.$day->format('d/m').' à 10 h.';
    }

    /**
     * Écrans en maintenance et retirés, lecteur révoqué, lecteur « suspect » avec commande en attente et capture d'écran,
     * version pilote du lecteur, incidents ouvert, préventif et clos, plans de maintenance à jour, en retard et inactif.
     */
    private function fleetScenarios(): void
    {
        $didier = $this->people['didier'];
        $alain = $this->people['alain'];
        $interne = DisplayZone::query()->where('code', 'Z-INTERNE')->value('id');
        $location = fn (int $level, string $code, string $name) => Location::query()->create(['floor_id' => $this->floor($level)->id, 'zone_id' => $interne, 'code' => $code, 'name' => $name]);
        $inventory = ['manufacturer' => 'Fabricant de démonstration', 'connection_type' => 'ethernet', 'installed_on' => $this->today()->subYears(2)->toDateString(), 'is_demo' => true, 'orientation' => 'landscape', 'resolution' => '1920x1080', 'kind' => 'display'];

        $repair = Screen::query()->create($inventory + ['code' => 'ECR-4E-03', 'name' => '4e étage · Salle de pause', 'location_id' => $location(4, 'PAUSE', 'Salle de pause')->id, 'status' => 'maintenance',
            'maintenance_reason' => 'Dalle fissurée : remplacement sous garantie', 'model' => 'Dalle 55 pouces', 'serial_number' => 'DEMO-55-0412', 'diagonal_inches' => 55, 'warranty_until' => $this->today()->addYear()->toDateString()]);
        Device::query()->create(['code' => 'LCT-REVOQUE1', 'screen_id' => $repair->id, 'hardware_id' => 'demo-ecr-4e-03-ancien', 'status' => 'revoked', 'app_version' => '1.0.0', 'platform' => 'Lecteur de démonstration (simulé)',
            'paired_at' => $this->today()->subYear(), 'paired_by' => $alain->id, 'revoked_at' => now()->subDays(3), 'revoked_by' => $alain->id, 'revoke_reason' => 'Lecteur remplacé après une panne de la carte mère', 'last_seen_at' => now()->subDays(3), 'network_status' => 'unknown', 'is_demo' => true]);
        Screen::query()->create($inventory + ['code' => 'ECR-RDC-03', 'name' => 'Restaurant du personnel', 'location_id' => $location(0, 'RESTAURANT', 'Restaurant du personnel')->id, 'status' => 'inactive',
            'notes' => 'Retiré du parc : le restaurant est fermé pour rénovation.', 'model' => 'Dalle 43 pouces', 'serial_number' => 'DEMO-43-0108', 'diagonal_inches' => 43]);

        $watched = Screen::query()->create($inventory + ['code' => 'ECR-5E-03', 'name' => '5e étage · Salle de pause', 'location_id' => $location(5, 'PAUSE', 'Salle de pause')->id, 'status' => 'active',
            'model' => 'Dalle 50 pouces', 'serial_number' => 'DEMO-50-0233', 'diagonal_inches' => 50, 'ip_address' => '10.20.5.33', 'warranty_until' => $this->today()->addMonths(8)->toDateString()]);
        $device = Device::query()->create(['code' => 'LCT-SUSPECT1', 'screen_id' => $watched->id, 'hardware_id' => 'demo-ecr-5e-03', 'status' => 'active', 'app_version' => '1.1.0-rc.1', 'release_channel' => 'pilot',
            'platform' => 'Lecteur de démonstration (simulé)', 'reported_resolution' => '1920x1080', 'last_seen_at' => now()->subMinutes(3), 'last_sync_at' => now()->subMinutes(3), 'network_status' => 'suspect',
            'storage' => ['usage' => 9_800_000_000, 'quota' => 64_000_000_000, 'persisted' => true], 'clock_skew_ms' => 2400, 'last_errors' => ['Horloge décalée de 2,4 s', 'Réponse lente du serveur (8 s)'],
            'pending_command' => 'reload', 'command_requested_at' => now()->subMinutes(2), 'command_requested_by' => $didier->id, 'paired_at' => $this->today()->subMonths(2), 'paired_by' => $alain->id, 'is_demo' => true]);
        $shot = DeviceScreenshot::query()->create(['device_id' => $device->id, 'requested_by' => $didier->id, 'requested_at' => now()->subMinutes(20), 'captured_at' => now()->subMinutes(19), 'status' => 'captured', 'expires_at' => now()->addDays(7)]);
        $key = "screenshots/{$device->id}/{$shot->id}.png";
        Storage::disk('local')->put($key, $this->image('capture.png', 'Capture ECR-5E-03', [16, 42, 77], [9, 105, 168]));
        $shot->forceFill(['storage_key' => $key])->save();

        PlayerRelease::query()->create(['version' => '1.1.0-rc.1', 'channel' => 'pilot', 'base_url' => '/player/releases/1.1.0-rc.1/', 'sha256' => hash('sha256', 'saie-demo-1.1.0-rc.1'), 'notes' => 'Version pilote de démonstration : lecture hors ligne améliorée (aucun fichier publié).', 'status' => 'active', 'created_by' => $alain->id]);
        PlayerRelease::query()->create(['version' => '1.0.1', 'channel' => 'stable', 'base_url' => '/player/releases/1.0.1/', 'sha256' => hash('sha256', 'saie-demo-1.0.1'), 'notes' => 'Retirée : régression sur les écrans en portrait.', 'status' => 'withdrawn', 'created_by' => $alain->id]);

        MaintenancePlan::query()->create(['title' => 'Nettoyage des dalles et des filtres', 'instructions' => 'Chiffon microfibre, sans produit ; dépoussiérer les grilles d’aération.', 'category' => 'panel', 'frequency_days' => 90, 'next_due_on' => $this->today()->addDays(5)->toDateString(), 'last_done_on' => $this->today()->subDays(85)->toDateString(), 'is_active' => true, 'created_by' => $didier->id]);
        $power = MaintenancePlan::query()->create(['title' => 'Contrôle des alimentations et onduleurs', 'instructions' => 'Tester la bascule sur onduleur et relever l’autonomie.', 'screen_id' => Screen::query()->where('code', 'ECR-RDC-01')->value('id'), 'category' => 'power', 'frequency_days' => 30, 'next_due_on' => $this->today()->subDays(3)->toDateString(), 'last_done_on' => $this->today()->subDays(33)->toDateString(), 'is_active' => true, 'created_by' => $didier->id]);
        MaintenancePlan::query()->create(['title' => 'Mise à jour du micrologiciel des dalles', 'category' => 'software', 'frequency_days' => 180, 'next_due_on' => $this->today()->addMonths(2)->toDateString(), 'is_active' => false, 'created_by' => $didier->id]);

        $incident = fn (array $attributes) => Incident::query()->create($attributes + ['reference' => Reference::next('INC'), 'is_automatic' => false]);
        $incident(['screen_id' => Screen::query()->where('code', 'ECR-3E-01')->value('id'), 'kind' => 'manual', 'category' => 'panel', 'maintenance_type' => 'corrective', 'severity' => 'major', 'status' => 'open', 'title' => 'Bande de pixels morts ECR-3E-01',
            'description' => 'Une bande verticale noire apparaît sur le tiers droit de la dalle.', 'impact' => 'Messages partiellement illisibles au palier du 3e étage', 'detected_at' => now()->subHours(2),
            'events' => [['at' => now()->subHours(2)->toAtomString(), 'by' => $this->people['loic']->name, 'action' => 'Signalement depuis le palier']]]);
        $incident(['screen_id' => $power->screen_id, 'kind' => 'preventive', 'category' => 'power', 'maintenance_type' => 'preventive', 'maintenance_plan_id' => $power->id, 'severity' => 'minor', 'status' => 'acknowledged', 'title' => 'Maintenance préventive : '.$power->title,
            'impact' => 'Coupure de 10 minutes à prévoir', 'detected_at' => now()->subDays(3), 'acknowledged_at' => now()->subDays(2), 'assignee_id' => $didier->id,
            'events' => [['at' => now()->subDays(3)->toAtomString(), 'by' => 'Système', 'action' => 'Échéance du plan de maintenance atteinte'], ['at' => now()->subDays(2)->toAtomString(), 'by' => $didier->name, 'action' => 'acknowledge', 'note' => 'Intervention prévue en dehors des heures de bureau']]]);
        $incident(['screen_id' => Screen::query()->where('code', 'ECR-1E-02')->value('id'), 'kind' => 'manual', 'category' => 'network', 'maintenance_type' => 'corrective', 'severity' => 'major', 'status' => 'closed', 'title' => 'Perte de réseau au 1er étage',
            'description' => 'Commutateur du local technique hors service.', 'diagnosis' => 'Alimentation du commutateur défaillante, remplacée par la DSI.', 'impact' => 'Deux écrans en lecture hors ligne pendant 3 heures',
            'detected_at' => now()->subDays(9), 'acknowledged_at' => now()->subDays(9)->addMinutes(12), 'resolved_at' => now()->subDays(9)->addHours(3), 'closed_at' => now()->subDays(8), 'assignee_id' => $didier->id,
            'events' => [['at' => now()->subDays(9)->addHours(3)->toAtomString(), 'by' => $didier->name, 'action' => 'resolve', 'note' => 'Alimentation remplacée'], ['at' => now()->subDays(8)->toAtomString(), 'by' => $alain->name, 'action' => 'close', 'note' => 'Aucune récidive en 24 h']]]);
        $this->notes[] = 'Parc : écran en maintenance avec lecteur révoqué (ECR-4E-03), écran retiré (ECR-RDC-03), lecteur suspect en version pilote avec rechargement en attente et capture d’écran (ECR-5E-03), version stable retirée, incidents ouvert, préventif et clos, plans de maintenance à jour, en retard et inactif.';
    }

    /**
     * Documents dépublié, à la corbeille, expiré et en deuxième version ; diffusion ciblée par courriel ; accusés de lecture.
     */
    private function documentScenarios(): void
    {
        $service = app(AssetService::class);
        $nadia = $this->people['nadia'];
        $make = function (string $title, string $owner, string $class, string $text, string $status, array $extra = [], int $versions = 1) use ($service, $nadia): Document {
            $document = Document::query()->create(array_merge(['reference' => Reference::next('DOC'), 'title' => $title, 'summary' => $text, 'category_id' => ContentCategory::query()->where('code', 'ANNONCE')->value('id'),
                'owner_structure_id' => $this->structure($owner)->id, 'author_id' => $nadia->id, 'classification' => $class, 'language' => 'fr', 'keywords' => ['DEMO'], 'valid_from' => $this->today()->subMonths(3),
                'status' => $status, 'published_at' => $status === 'draft' ? null : $this->today()->subMonths(2), 'is_demo' => true], $extra));
            for ($v = 1; $v <= $versions; $v++) {
                $file = $this->upload($service, $this->pdf($title.($v > 1 ? " (version $v)" : ''), 1, $text), str($title)->slug()."-v$v.pdf", ['title' => $title, 'classification' => $class, 'owner_structure_id' => $this->structure($owner)->id], $nadia);
                $version = $document->versions()->create(['version' => $v, 'asset_version_id' => $file->id, 'notes' => $v === 1 ? 'Version initiale' : 'Mise à jour des numéros de poste', 'created_by' => $nadia->id]);
                $document->forceFill(['current_version_id' => $version->id, 'search_text' => $file->text_content])->save();
            }

            return $document;
        };
        $make('Ancien règlement intérieur des salles', 'MG', 'interne', 'Règlement remplacé par le guide pratique des salles.', 'unpublished', ['unpublished_at' => now()->subDays(15)]);
        $make('Brouillon abandonné — note d’organisation', 'COM', 'interne', 'Projet de note abandonné.', 'trashed', ['published_at' => null, 'trashed_at' => now()->subDays(4)]);
        $make('Avis de consultation — fournitures de bureau (clos)', 'MG', 'public', 'Avis de consultation clos ; les offres ne sont plus recevables.', 'published', ['expires_at' => $this->today()->subDays(5)]);
        $directory = $make('Annuaire téléphonique interne', 'COM', 'interne', 'Numéros de poste des directions et services de la Commission.', 'published', [], 2);

        $circular = Document::query()->where('is_demo', true)->where('title', 'Circulaire RH — campagne d’évaluation')->first();
        if ($circular) {
            $structures = [$this->structure('DRH')->id, $this->structure('DSI')->id];
            $candidates = User::query()->whereIn('structure_id', $structures)->where('status', 'active')->get();
            $recipients = $candidates->filter(fn (User $u) => app(DocumentAccess::class)->canView($circular, $u))->values();
            $broadcast = DocumentBroadcast::query()->create(['document_id' => $circular->id, 'structure_ids' => $structures, 'message' => 'Merci de prendre connaissance du calendrier avant la fin du mois.',
                'recipients_count' => $recipients->count(), 'excluded_count' => $candidates->count() - $recipients->count(), 'created_by' => $nadia->id]);
            app(Notifier::class)->notify($recipients, 'document.broadcast', 'Document diffusé : '.$circular->title, "Merci de prendre connaissance du calendrier avant la fin du mois.\n\nRéférence : {$circular->reference}. Consultation après authentification ; aucune pièce jointe n’est transmise.", "/documents/{$circular->id}", 'document', $circular->id, 'broadcast-'.$broadcast->id);
            app(Audit::class)->log('document.broadcast', $circular, actor: $nadia, after: ['structures' => $structures, 'recipients' => $recipients->count()]);
        }
        foreach (array_filter([Document::query()->where('is_demo', true)->where('title', 'Note de service — horaires de fin d’année')->first(), $directory]) as $document) {
            foreach (['loic', 'patrick', 'sylvain', 'grace', 'paul'] as $i => $reader) {
                DocumentRead::query()->create(['document_id' => $document->id, 'document_version_id' => $document->current_version_id, 'user_id' => $this->people[$reader]->id, 'first_opened_at' => now()->subHours(3 + $i * 7)]);
            }
        }
        $this->notes[] = 'Documents : dépublié, à la corbeille, expiré, annuaire en version 2 avec accusés de lecture, circulaire RH diffusée par courriel à la DRH et à la DSI.';
    }

    /**
     * Exports prêts (produits par le service réel), en échec, annulé, et un export en attente traité par la file.
     */
    private function exportScenarios(): void
    {
        $service = app(ExportService::class);
        $from = $this->today()->subMonth()->toDateString();
        $to = $this->today()->addMonth()->toDateString();
        foreach ([['equipment_inventory', 'xlsx', 'alain', []], ['room_planning', 'csv', 'mireille', ['from' => $from, 'to' => $to]], ['audit_extract', 'pdf', 'sylvain', ['from' => $this->today()->subDays(7)->toDateString(), 'to' => $this->today()->toDateString()]]] as [$type, $format, $who, $params]) {
            $export = Export::query()->create(['type' => $type, 'format' => $format, 'params' => $params, 'status' => 'pending', 'requested_by' => $this->people[$who]->id]);
            try {
                $service->generate($export);
            } catch (Throwable $e) {
                $export->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)])->save();
            }
        }
        Export::query()->create(['type' => 'incident_report', 'format' => 'pdf', 'params' => ['from' => $from], 'status' => 'failed', 'progress' => 60, 'error' => 'Délai de génération dépassé (scénario de démonstration) : relancer l’export.', 'requested_by' => $this->people['alain']->id, 'completed_at' => now()->subHours(5)]);
        Export::query()->create(['type' => 'diffusion_report', 'format' => 'csv', 'params' => ['from' => $from], 'status' => 'cancelled', 'progress' => 0, 'requested_by' => $this->people['alain']->id, 'completed_at' => now()->subDays(2)]);
        $pending = Export::query()->create(['type' => 'room_usage', 'format' => 'xlsx', 'params' => ['from' => $from, 'to' => $this->today()->toDateString()], 'status' => 'pending', 'requested_by' => $this->people['clarisse']->id]);
        GenerateExport::dispatch($pending->id);
        $this->notes[] = 'Exports : trois prêts (inventaire XLSX, planning CSV, extrait du journal PDF), un en échec, un annulé, un en attente traité par la file « exports ».';
    }

    /**
     * Sources dynamiques (horloge, compte à rebours, flux en erreur, météo désactivée), comptes de service actif et
     * révoqué, abonnements iCalendar actif et révoqué, import de référentiel, notifications lues et non lues.
     */
    private function integrationScenarios(): void
    {
        $sources = app(DynamicSources::class);
        $clock = DynamicSource::query()->create(['code' => 'HORLOGE', 'name' => 'Horloge du siège', 'type' => 'clock', 'is_active' => true, 'frequency_minutes' => 60]);
        $sources->fetch($clock);
        $countdown = DynamicSource::query()->create(['code' => 'CPT-SESSION', 'name' => 'Compte à rebours de la session statutaire', 'type' => 'countdown', 'is_active' => true, 'frequency_minutes' => 60,
            'config' => ['label' => 'Ouverture de la session statutaire', 'target' => $this->workday(9)->setTime(9, 0)->toIso8601String()]]);
        $sources->fetch($countdown);
        $feed = DynamicSource::query()->create(['code' => 'RSS-ACTU', 'name' => 'Actualités institutionnelles (flux désactivé)', 'type' => 'rss', 'url' => 'https://flux.demo.saie-ceeac.local/actualites.xml', 'is_active' => false, 'frequency_minutes' => 30,
            'last_fetched_at' => now()->subDays(1), 'last_status' => 'error']);
        SourceSnapshot::query()->create(['source_id' => $feed->id, 'fetched_at' => now()->subDays(1), 'payload' => ['error' => 'Nom de domaine introuvable (adresse de démonstration)'], 'status' => 'error']);
        DynamicSource::query()->create(['code' => 'METEO-LBV', 'name' => 'Météo de Libreville (à configurer)', 'type' => 'weather', 'url' => 'https://meteo.demo.saie-ceeac.local/libreville', 'is_active' => false, 'frequency_minutes' => 60]);

        $portal = ServiceAccount::query()->create(['name' => 'Portail intranet', 'description' => 'Affiche les réunions du jour et les annonces publiques', 'scopes' => ['integration:meetings', 'integration:announcements'], 'created_by' => $this->people['alain']->id, 'last_used_at' => now()->subHour()]);
        $token = $portal->createToken('integration', $portal->scopes, now()->addYear())->plainTextToken;
        ServiceAccount::query()->create(['name' => 'Ancien système d’affichage', 'description' => 'Remplacé par SAIE', 'scopes' => ['integration:screens'], 'status' => 'revoked', 'created_by' => $this->people['alain']->id, 'last_used_at' => now()->subMonths(2)]);
        $this->notes[] = "Compte de service « Portail intranet » : jeton $token (en-tête Authorization: Bearer ; l’API d’intégration reste fermée tant que « integrations.api_enabled » n’est pas activé dans Paramètres › Intégrations)";

        CalendarSubscription::query()->create(['user_id' => $this->people['mireille']->id, 'room_id' => $this->room('KIVU')->id, 'token_hash' => hash('sha256', Str::random(40)), 'last_used_at' => now()->subHours(2)]);
        CalendarSubscription::query()->create(['user_id' => $this->people['sandra']->id, 'room_id' => $this->room('MINKO')->id, 'token_hash' => hash('sha256', Str::random(40)), 'revoked_at' => now()->subDays(1)]);

        ImportRun::query()->create(['type' => 'floors', 'file_name' => 'etages-siege.csv', 'match_key' => 'code', 'status' => 'committed', 'summary' => ['created' => 0, 'updated' => 2, 'unchanged' => 5, 'invalid' => 0, 'total' => 7, 'written' => true], 'created_by' => $this->people['clarisse']->id, 'created_at' => now()->subDays(6)]);
        ImportRun::query()->create(['type' => 'zones', 'file_name' => 'zones-affichage.csv', 'match_key' => 'code', 'status' => 'committed', 'summary' => ['created' => 1, 'updated' => 0, 'unchanged' => 2, 'invalid' => 1, 'total' => 4, 'written' => true], 'created_by' => $this->people['clarisse']->id, 'created_at' => now()->subDays(2)]);

        $notifier = app(Notifier::class);
        $notifier->notify($this->people['kevin'], 'account.welcome', 'Bienvenue dans SAIE', 'Changez votre mot de passe provisoire à la première connexion.', '/mon-compte');
        $notifier->notify($this->people['didier'], 'incident.assigned', 'Incident qui vous est attribué', 'Maintenance préventive : contrôle des alimentations et onduleurs', '/supervision/incidents');
        $older = UserNotification::query()->whereIn('user_id', [$this->people['loic']->id, $this->people['nadia']->id])->whereNull('read_at')->orderBy('created_at')->limit(3)->pluck('id');
        UserNotification::query()->whereIn('id', $older)->update(['read_at' => now()->subHour()]);
        $this->notes[] = 'Intégrations : horloge et compte à rebours actifs, flux d’actualités en erreur et désactivé, météo à configurer, compte de service révoqué, abonnements iCalendar actif et révoqué, deux imports de référentiels.';
    }

    private function assign(User $user, string $role, ?string $scope, CarbonImmutable $from, ?CarbonImmutable $to, string $reason, ?CarbonImmutable $revokedAt = null): void
    {
        RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', $role)->value('id'), 'structure_id' => $scope ? $this->structure($scope)->id : null,
            'starts_at' => $from, 'ends_at' => $to, 'revoked_at' => $revokedAt, 'granted_by' => $this->people['clarisse']->id, 'reason' => $reason]);
    }

    /**
     * Premier créneau libre d'une salle à partir d'un jour ouvré donné (week-end, jours fériés et réservations existantes évités).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function freeSlot(Room $room, int $offset, string $from, string $to): array
    {
        $service = app(ReservationService::class);
        for ($i = 0; $i < 40; $i++) {
            $day = $this->workday($offset + $i);
            [$fh, $fm] = array_map('intval', explode(':', $from));
            [$th, $tm] = array_map('intval', explode(':', $to));
            $slot = [$day->setTime($fh, $fm)->utc(), $day->setTime($th, $tm)->utc()];
            if ($service->conflicts($room, [$slot]) === []) {
                return $slot;
            }
        }

        throw new \RuntimeException("Aucun créneau libre trouvé pour la salle {$room->code}.");
    }

    /**
     * Jour ouvré (hors week-end et jours fériés) décalé de $offset jours ouvrés à partir d'aujourd'hui (négatif : dans le passé).
     */
    private function workday(int $offset): CarbonImmutable
    {
        $day = $this->today();
        $step = $offset < 0 ? -1 : 1;
        $remaining = abs($offset);
        while ($remaining > 0 || $day->isWeekend() || Holiday::query()->whereDate('date', $day->toDateString())->exists()) {
            $day = $day->addDays($step);
            if (! $day->isWeekend() && ! Holiday::query()->whereDate('date', $day->toDateString())->exists()) {
                $remaining = max(0, $remaining - 1);
            }
        }

        return $day;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->tz)->startOfDay();
    }

    private function structure(string $code): Structure
    {
        return Structure::query()->where('code', $code)->firstOrFail();
    }

    private function room(string $code): Room
    {
        return Room::query()->where('code', $code)->firstOrFail();
    }

    private function floor(int $level): Floor
    {
        $building = Building::query()->where('code', 'SIEGE')->value('id');

        return Floor::query()->where('building_id', $building)->where('level', $level)->firstOrFail();
    }
}
