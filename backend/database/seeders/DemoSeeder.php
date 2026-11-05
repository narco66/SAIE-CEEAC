<?php

namespace Database\Seeders;

use App\Jobs\ProcessAssetVersion;
use App\Models\AccessLink;
use App\Models\Alert;
use App\Models\AlertReceipt;
use App\Models\Approval;
use App\Models\AssetVersion;
use App\Models\Building;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentCategory;
use App\Models\ContentVersion;
use App\Models\Device;
use App\Models\DisplayZone;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\Export;
use App\Models\FallbackContent;
use App\Models\Floor;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\Location;
use App\Models\MobileResource;
use App\Models\Reservation;
use App\Models\ReservationOccurrence;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoleExtension;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\Screen;
use App\Models\ScreenGroup;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\Site;
use App\Models\Structure;
use App\Models\Template;
use App\Models\User;
use App\Models\Visit;
use App\Services\Access;
use App\Services\AssetService;
use App\Services\Audit;
use App\Services\CampaignService;
use App\Services\ContentWorkflow;
use App\Services\Notifier;
use App\Services\ReservationService;
use App\Services\VirusScanner;
use App\Support\Reference;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Jeu de démonstration du chapitre 31 : données fictives marquées « is_demo », produites par les services métier réels.
 * Rejouable : il ne s'exécute pas si le jeu est déjà présent, et ne modifie aucun compte existant.
 */
class DemoSeeder extends Seeder
{
    private CarbonImmutable $today;

    /** Heure de référence du scénario « du jour », alignée sur le quart d'heure courant. */
    private CarbonImmutable $anchor;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, mixed> */
    private array $refs = [];

    public function run(): void
    {
        if (User::query()->where('is_demo', true)->exists()) {
            $this->command?->info('Jeu de démonstration déjà présent : aucune modification.');

            return;
        }
        $this->today = CarbonImmutable::now(config('saie.timezone'))->startOfDay();
        $now = CarbonImmutable::now(config('saie.timezone'));
        $this->anchor = $now->setTime((int) $now->format('H'), intdiv((int) $now->format('i'), 15) * 15);
        // Réutilise le mot de passe déjà remis (fichier existant) pour que toutes les bases locales restent cohérentes.
        $existing = Storage::disk('local')->exists('demo-accounts.txt') && preg_match('/démonstration : (\S+)/u', (string) Storage::disk('local')->get('demo-accounts.txt'), $m) ? $m[1] : null;
        $password = env('SAIE_DEMO_PASSWORD') ?: $existing ?: 'Demo-'.Str::password(12, symbols: false);

        DB::transaction(function () use ($password): void {
            $this->places();
            $this->people($password);
            $this->rooms();
        });
        $this->screens();
        $this->media();
        $this->documents();
        $this->contents();
        $this->campaigns();
        $this->reservations();
        $this->alertsAndIncidents();
        $this->history();

        $lines = collect($this->users)->map(fn (User $u) => sprintf('%-40s %s', $u->email, app(Access::class)->primaryRoleLabel($u)))->implode("\n");
        Storage::disk('local')->put('demo-accounts.txt', "Comptes de démonstration SAIE-CEEAC (fictifs, ne jamais utiliser en production)\nMot de passe commun de démonstration : $password\n\n$lines\n");
        $this->command?->info('Jeu de démonstration créé. Comptes et mot de passe : storage/app/private/demo-accounts.txt');
    }

    private function places(): void
    {
        $site = Site::query()->create(['code' => 'LBV', 'name' => 'Libreville', 'timezone' => 'Africa/Libreville', 'address' => 'Siège de la Commission, Libreville (adresse fictive)']);
        $building = Building::query()->create(['site_id' => $site->id, 'code' => 'SIEGE', 'name' => 'Siège']);
        $labels = ['Rez-de-chaussée', '1er étage', '2e étage', '3e étage', '4e étage', '5e étage', '6e étage'];
        foreach ($labels as $level => $label) {
            $this->refs['floor'][$level] = Floor::query()->create(['building_id' => $building->id, 'code' => $level === 0 ? 'RDC' : 'E'.$level, 'label' => $label, 'level' => $level]);
        }
        $this->refs['zone']['accueil'] = DisplayZone::query()->create(['code' => 'Z-ACCUEIL', 'name' => 'Accueil et hall (public)', 'max_classification' => 'public', 'audio_allowed' => true, 'audio_max_volume' => 40]);
        $this->refs['zone']['interne'] = DisplayZone::query()->create(['code' => 'Z-INTERNE', 'name' => 'Paliers et circulations (interne)', 'max_classification' => 'interne']);
        $this->refs['zone']['salles'] = DisplayZone::query()->create(['code' => 'Z-SALLES', 'name' => 'Entrées des salles (public)', 'max_classification' => 'public']);
        $this->refs['loc']['hall'] = Location::query()->create(['floor_id' => $this->refs['floor'][0]->id, 'zone_id' => $this->refs['zone']['accueil']->id, 'code' => 'HALL', 'name' => 'Hall principal']);
        $this->refs['loc']['orient'] = Location::query()->create(['floor_id' => $this->refs['floor'][0]->id, 'zone_id' => $this->refs['zone']['accueil']->id, 'code' => 'ORIENT', 'name' => 'Borne d’orientation']);
        for ($level = 1; $level <= 6; $level++) {
            $this->refs['loc']["p$level"] = Location::query()->create(['floor_id' => $this->refs['floor'][$level]->id, 'zone_id' => $this->refs['zone']['interne']->id, 'code' => 'PALIER', 'name' => 'Palier des ascenseurs']);
            $this->refs['loc']["c$level"] = Location::query()->create(['floor_id' => $this->refs['floor'][$level]->id, 'zone_id' => $this->refs['zone']['interne']->id, 'code' => 'COULOIR-EST', 'name' => 'Couloir Est']);
        }
        foreach ([0, 1, 3, 6] as $level) {
            $this->refs['loc']["s$level"] = Location::query()->create(['floor_id' => $this->refs['floor'][$level]->id, 'zone_id' => $this->refs['zone']['salles']->id, 'code' => 'ENTREE-SALLE', 'name' => 'Entrée de salle']);
        }
        $structures = [
            ['CAB', 'Cabinet de la Présidence', 'cabinet', null, 1], ['SG', 'Secrétariat général', 'departement', null, 6], ['COM', 'Service Communication', 'service', 'SG', 2],
            ['PROT', 'Protocole', 'service', 'CAB', 0], ['DSI', 'Direction des Systèmes d’Information', 'direction', 'SG', 2], ['MG', 'Moyens généraux', 'direction', 'SG', 0],
            ['DRH', 'Direction des Ressources humaines', 'direction', 'SG', 4], ['DIR', 'Département Intégration régionale', 'departement', null, 3],
            ['DPS', 'Département Paix et Sécurité', 'departement', null, 5], ['AUD', 'Audit interne', 'service', null, 5],
        ];
        foreach ($structures as [$code, $name, $type, $parent, $level]) {
            $this->refs['structure'][$code] = Structure::query()->create(['code' => $code, 'name' => $name, 'type' => $type, 'parent_id' => $parent ? $this->refs['structure'][$parent]->id : null, 'floor_id' => $this->refs['floor'][$level]->id]);
        }
        $year = (int) $this->today->format('Y');
        foreach ([["$year-01-01", 'Jour de l’an'], ["$year-04-17", 'Journée nationale de la femme'], ["$year-05-01", 'Fête du travail'], ["$year-08-17", 'Fête de l’indépendance'], ["$year-11-01", 'Toussaint'], ["$year-12-25", 'Noël'], [($year + 1).'-01-01', 'Jour de l’an']] as [$date, $label]) {
            Holiday::query()->firstOrCreate(['date' => $date], ['label' => $label]);
        }
    }

    private function people(string $password): void
    {
        $people = [
            'alain' => ['Alain Mbeng', 'alain.mbeng', 'DSI', 'Chef de service exploitation', [['admin_tech', null, null], ['diffuseur', null, null]]],
            'clarisse' => ['Clarisse Ondo', 'clarisse.ondo', 'SG', 'Chargée des référentiels', [['admin_fonc', null, null]]],
            'nadia' => ['Nadia Kombila', 'nadia.kombila', 'COM', 'Chargée de communication', [['contributeur', 'COM', null]]],
            'jean' => ['Jean Moussavou', 'jean.moussavou', 'COM', 'Chef du service Communication', [['validateur', null, ['fr']]]],
            'grace' => ['Grace Ndong', 'grace.ndong', 'COM', 'Traductrice-réviseure', [['validateur', null, ['en', 'fr']]]],
            'patrick' => ['Patrick Obiang', 'patrick.obiang', 'DSI', 'Opérateur de diffusion', [['diffuseur', null, null]]],
            'mireille' => ['Mireille Tchibinda', 'mireille.tchibinda', 'MG', 'Gestionnaire des salles', [['gestionnaire_salle', null, null]]],
            'estelle' => ['Estelle Nzé', 'estelle.nze', 'PROT', 'Chargée du protocole', [['contributeur', 'PROT', null]]],
            'loic' => ['Loïc Bouanga', 'loic.bouanga', 'DRH', 'Assistant RH', [['agent', null, null]]],
            'sylvain' => ['Sylvain Essono', 'sylvain.essono', 'AUD', 'Auditeur interne', [['auditeur', null, null]]],
        ];
        foreach ($people as $key => [$name, $login, $structure, $title, $roles]) {
            $user = User::query()->create(['name' => $name, 'email' => "$login@demo.saie-ceeac.local", 'password' => $password, 'structure_id' => $this->refs['structure'][$structure]->id, 'title' => $title, 'is_demo' => true, 'email_verified_at' => now()]);
            foreach ($roles as [$role, $scope, $langs]) {
                RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', $role)->value('id'), 'structure_id' => $scope ? $this->refs['structure'][$scope]->id : null, 'languages' => $langs, 'starts_at' => $this->today->subMonths(3), 'reason' => 'Jeu de démonstration']);
            }
            $this->users[$key] = $user;
        }
        foreach (['jean' => 'grace', 'grace' => 'jean', 'mireille' => 'clarisse', 'nadia' => 'estelle'] as $user => $backup) {
            $this->users[$user]->forceFill(['backup_user_id' => $this->users[$backup]->id])->save();
        }
        $end = CarbonImmutable::create((int) $this->today->format('Y'), 12, 31, 23, 0, 0, config('saie.timezone'));
        RoleExtension::query()->create(['user_id' => $this->users['alain']->id, 'power' => 'urgence', 'starts_at' => $this->today->subMonth(), 'ends_at' => $end->isFuture() ? $end : $this->today->addMonths(2), 'reason' => 'Astreinte DSI']);
        RoleExtension::query()->create(['user_id' => $this->users['clarisse']->id, 'power' => 'modeles', 'starts_at' => $this->today->subMonth(), 'ends_at' => $this->today->addMonths(6), 'reason' => 'Conception des modèles']);
        RoleExtension::query()->create(['user_id' => $this->users['mireille']->id, 'power' => 'prestations', 'scope' => ['service_types' => ['INTERPRETATION', 'VISIO', 'ASSISTANCE', 'SONO']], 'starts_at' => $this->today->subMonth(), 'ends_at' => $this->today->addMonths(6), 'reason' => 'Prestations de réunion']);
        RoleExtension::query()->create(['user_id' => $this->users['patrick']->id, 'power' => 'urgence', 'starts_at' => $this->today->subMonths(2), 'ends_at' => $this->today->subDay(), 'reason' => 'Pouvoir temporaire expiré (scénario R19)']);
        app(Access::class)->flush();
    }

    private function rooms(): void
    {
        $eq = Equipment::query()->pluck('id', 'code');
        $rooms = [
            'kivu' => ['KIVU', 'Salle Kivu', 1, 12, 'manual', 15, 15, ['VISIO', 'ECRAN65']],
            'ogooue' => ['OGOOUE', 'Salle Ogooué', 3, 20, 'auto', 10, 10, ['VISIO', 'INTERP']],
            'congo' => ['CONGO', 'Salle Congo', 6, 40, 'auto', 15, 15, ['SONO', 'ECRAN85']],
            'ceeac' => ['CEEAC', 'Salle CEEAC', 0, 100, 'manual', 30, 30, ['INTERP', 'REGIE', 'SONO']],
        ];
        foreach ($rooms as $key => [$code, $name, $level, $capacity, $mode, $prep, $cleanup, $equipment]) {
            $room = Room::query()->create(['code' => $code, 'name' => $name, 'floor_id' => $this->refs['floor'][$level]->id, 'capacity' => $capacity, 'approval_mode' => $mode, 'prep_minutes' => $prep, 'cleanup_minutes' => $cleanup,
                'manager_id' => $this->users['mireille']->id, 'location_text' => $this->refs['floor'][$level]->label.' · aile Est', 'open_from' => '07:30', 'open_to' => '19:00', 'pending_hold_hours' => 48, 'is_demo' => true]);
            $room->equipment()->sync(collect($equipment)->mapWithKeys(fn ($c) => [$eq[$c] => ['quantity' => 1]]));
            $this->refs['room'][$key] = $room;
        }
        RoomBlock::query()->create(['room_id' => $this->refs['room']['ceeac']->id, 'starts_at' => $this->at(1, '07:30'), 'ends_at' => $this->at(1, '12:00'), 'kind' => 'technical', 'reason' => 'Maintenance de la régie audiovisuelle', 'created_by' => $this->users['mireille']->id]);
        MobileResource::query()->create(['code' => 'KIT-VISIO-01', 'name' => 'Kit de visioconférence mobile', 'type' => 'visioconference', 'manager_id' => $this->users['mireille']->id]);
        MobileResource::query()->create(['code' => 'CAB-INTERP-01', 'name' => 'Cabine d’interprétation mobile', 'type' => 'interpretation', 'manager_id' => $this->users['mireille']->id]);
    }

    private function screens(): void
    {
        $make = fn (string $code, string $name, string $kind, string $loc, string $orientation, array $extra = []) => Screen::query()->create(array_merge([
            'code' => $code, 'name' => $name, 'kind' => $kind, 'location_id' => $this->refs['loc'][$loc]->id, 'orientation' => $orientation,
            'resolution' => $orientation === 'portrait' ? '1080x1920' : '1920x1080', 'is_demo' => true,
        ], $extra));
        $this->refs['screen']['ECR-RDC-01'] = $make('ECR-RDC-01', 'Hall principal', 'display', 'hall', 'landscape', ['audio_enabled' => true, 'control_channel' => 'cec', 'panel_state' => 'on', 'panel_state_at' => now()]);
        $this->refs['screen']['ECR-RDC-02'] = $make('ECR-RDC-02', 'Orientation accueil', 'orientation', 'orient', 'portrait');
        for ($level = 1; $level <= 6; $level++) {
            $suffix = $level === 0 ? 'RDC' : $level.'E';
            $this->refs['screen']["ECR-$suffix-01"] = $make("ECR-$suffix-01", $this->refs['floor'][$level]->label.' · Palier', 'display', "p$level", 'landscape');
            $extra = $level === 3 ? ['operating_window' => ['days' => [1, 2, 3, 4, 5], 'on' => '08:00', 'off' => '10:00', 'wake_before_min' => 5], 'window_override_reason' => 'Travaux de peinture au 3e étage : extinction anticipée'] : [];
            $this->refs['screen']["ECR-$suffix-02"] = $make("ECR-$suffix-02", $this->refs['floor'][$level]->label.' · Couloir Est', 'display', "c$level", $level % 2 ? 'portrait' : 'landscape', $extra);
        }
        foreach (['ceeac' => 0, 'kivu' => 1, 'ogooue' => 3, 'congo' => 6] as $room => $level) {
            $this->refs['screen']['PAN-'.strtoupper($room)] = $make('PAN-'.strtoupper($room), 'Panneau '.$this->refs['room'][$room]->name, 'room_panel', "s$level", 'landscape', ['room_id' => $this->refs['room'][$room]->id, 'resolution' => '1280x800']);
        }
        $internal = ScreenGroup::query()->create(['code' => 'GRP-INTERNES', 'name' => 'Écrans internes 1er–6e', 'description' => 'Paliers et couloirs des étages']);
        $internal->screens()->sync(collect($this->refs['screen'])->filter(fn ($s, $code) => str_starts_with($code, 'ECR-') && ! str_contains($code, 'RDC'))->pluck('id'));
        $accueil = ScreenGroup::query()->create(['code' => 'GRP-ACCUEIL', 'name' => 'Accueil RDC', 'description' => 'Hall et orientation']);
        $accueil->screens()->sync([$this->refs['screen']['ECR-RDC-01']->id, $this->refs['screen']['ECR-RDC-02']->id]);
        $this->refs['group'] = ['internes' => $internal, 'accueil' => $accueil];

        foreach ($this->refs['screen'] as $code => $screen) {
            if ($code === 'ECR-6E-02') {
                continue;
            }
            $lastSeen = match ($code) {
                'ECR-2E-02' => now()->subMinutes(18), 'ECR-5E-01' => now()->subSeconds(150), default => now()->subSeconds(random_int(3, 25))
            };
            $device = Device::query()->create([
                'code' => 'LCT-'.strtoupper(substr(md5($code), 0, 8)), 'screen_id' => $screen->id, 'hardware_id' => 'demo-'.strtolower($code), 'status' => 'active', 'app_version' => $code === 'ECR-5E-02' ? '1.0.0-pilote' : '1.0.0',
                'platform' => 'Lecteur de démonstration (simulé)', 'reported_resolution' => $screen->resolution, 'last_seen_at' => $lastSeen, 'last_sync_at' => $lastSeen, 'network_status' => 'online',
                'storage' => ['usage' => $code === 'ECR-4E-02' ? 56_300_000_000 : 6_100_000_000, 'quota' => 64_000_000_000, 'persisted' => true], 'clock_skew_ms' => random_int(-800, 800),
                'last_errors' => $code === 'ECR-4E-02' ? ['Cache à 88 % : éviction des ressources non référencées'] : [], 'paired_at' => $this->today->subMonth(), 'paired_by' => $this->users['alain']->id, 'is_demo' => true,
            ]);
            $this->refs['device'][$code] = $device;
        }
    }

    private function media(): void
    {
        $service = app(AssetService::class);
        $nadia = $this->users['nadia'];
        $images = [
            'delegation' => ['delegation-cemac.jpg', 'Accueil de la délégation', [11, 31, 58], [9, 105, 168], 'public'],
            'hall' => ['hall-siege.webp', 'Hall du siège', [16, 42, 77], [22, 138, 101], 'public'],
            'sante' => ['campagne-sante.png', 'Campagne santé au travail', [22, 138, 101], [198, 122, 16], 'interne'],
            'journee' => ['journee-ceeac.jpg', 'Journée de la CEEAC', [120, 88, 166], [9, 105, 168], 'public'],
            'repli' => ['repli-institutionnel.png', 'Visuel institutionnel de repli', [11, 31, 58], [16, 42, 77], 'public'],
        ];
        foreach ($images as $key => [$file, $title, $from, $to, $class]) {
            $this->refs['media'][$key] = $this->upload($service, $this->image($file, $title, $from, $to), $file, ['title' => $title, 'classification' => $class], $nadia);
        }
        $this->refs['media']['programme'] = $this->upload($service, $this->pdf('Programme de la session statutaire', 6, 'Ordre du jour de la session statutaire du Conseil des ministres : ouverture, rapports des commissions, budget, intégration régionale, paix et sécurité, clôture.'), 'programme-session.pdf', ['title' => 'Programme de la session statutaire', 'classification' => 'public'], $nadia);
        $this->upload($service, random_bytes(4096), 'interview-novembre.mp4', ['title' => 'Interview de novembre (fichier corrompu)', 'classification' => 'interne'], $nadia);
        $eicar = $this->pdf('Charte graphique — fichier de test', 1, 'Fichier de test antivirus inoffensif (scénario R03).')."\n".'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'."\n";
        $this->upload($service, $eicar, 'charte-graphique-test.pdf', ['title' => 'Charte graphique (test antivirus)', 'classification' => 'interne'], $nadia);
        $this->refs['media']['subtitles'] = $this->upload($service, "WEBVTT\n\n00:00.000 --> 00:05.000\nMessage institutionnel sous-titré.\n", 'sous-titres-fr.vtt', ['title' => 'Sous-titres FR', 'classification' => 'public', 'purpose' => 'subtitle'], $nadia);
    }

    private function documents(): void
    {
        $service = app(AssetService::class);
        $docs = [
            ['Note de service — horaires de fin d’année', 'SG', 'interne', 'NOTE-SERVICE', 'Horaires aménagés du 22 décembre au 2 janvier ; permanence assurée par chaque direction.'],
            ['Rapport annuel d’activités', 'COM', 'public', 'ACTUALITE', 'Synthèse des activités de la Commission : intégration régionale, paix et sécurité, programmes sectoriels.'],
            ['Procédure de continuité informatique', 'DSI', 'restreint', 'DSI', 'Procédure de reprise d’activité informatique, sauvegardes chiffrées, restauration trimestrielle et contacts d’astreinte. Mot-clé confidentiel : bastion.'],
            ['Guide pratique des salles', 'MG', 'interne', 'ANNONCE', 'Règles de réservation des salles, temps de préparation, équipements et prestations disponibles.'],
            ['Calendrier des sessions statutaires', 'SG', 'public', 'AGENDA', 'Dates indicatives des sessions statutaires et réunions des organes de la Communauté.'],
            ['Consignes de sécurité incendie', 'MG', 'public', 'SECURITE', 'Points de rassemblement, issues de secours, interdiction des ascenseurs en cas d’alarme.'],
            ['Circulaire RH — campagne d’évaluation', 'DRH', 'interne', 'RH', 'Calendrier de la campagne d’évaluation annuelle du personnel et pièces à fournir.'],
            ['Plan de formation interne', 'DRH', 'interne', 'FORMATION', 'Sessions de formation aux outils collaboratifs et au système d’affichage SAIE.'],
            ['Charte éditoriale des écrans', 'COM', 'interne', 'COMMUNIQUE', 'Règles de rédaction, longueur des messages, multilinguisme français et anglais, sous-titrage.'],
            ['Rapport d’audit des habilitations', 'AUD', 'restreint', 'DSI', 'Revue des habilitations et des pouvoirs étendus ; recommandations de séparation des tâches. Mot-clé confidentiel : bastion.'],
            ['Feuille de route intégration régionale', 'DIR', 'public', 'ACTUALITE', 'Axes prioritaires de l’intégration régionale et indicateurs de suivi publics.'],
            ['Note confidentielle du Cabinet', 'CAB', 'restreint', 'DIRECTION', 'Préparation de la réunion restreinte du Cabinet. Mot-clé confidentiel : bastion.'],
        ];
        foreach ($docs as $i => [$title, $owner, $class, $category, $text]) {
            $author = in_array($owner, ['DSI', 'AUD', 'CAB'], true) ? $this->users['alain'] : $this->users['nadia'];
            $version = $this->upload($service, $this->pdf($title, $i % 3 + 1, $text), str($title)->slug().'.pdf', ['title' => $title, 'classification' => $class, 'owner_structure_id' => $this->refs['structure'][$owner]->id], $author);
            $doc = Document::query()->create([
                'reference' => Reference::next('DOC'), 'title' => $title, 'summary' => $text, 'category_id' => ContentCategory::query()->where('code', $category)->value('id'),
                'owner_structure_id' => $this->refs['structure'][$owner]->id, 'author_id' => $author->id, 'classification' => $class, 'keywords' => [$category], 'language' => 'fr',
                'valid_from' => $this->today->subMonths(2), 'expires_at' => $i === 0 ? $this->today->addDays(20) : ($i === 6 ? $this->today->addDays(12) : null),
                'status' => $i === 8 ? 'draft' : 'published', 'published_at' => $i === 8 ? null : $this->today->subDays(30 - $i), 'search_text' => $version->refresh()->text_content, 'is_demo' => true,
            ]);
            $dv = $doc->versions()->create(['version' => 1, 'asset_version_id' => $version->id, 'notes' => 'Version initiale', 'created_by' => $author->id]);
            $doc->forceFill(['current_version_id' => $dv->id])->save();
            if ($class !== 'restreint' && $doc->status === 'published') {
                AccessLink::query()->create(['code' => Str::lower(Str::random(10)), 'target_type' => 'document', 'target_id' => $doc->id, 'classification' => $class, 'created_by' => $author->id]);
            }
            if ($i === 6) {
                $doc->audiences()->sync([$this->refs['structure']['DRH']->id, $this->refs['structure']['DSI']->id]);
            }
            $this->refs['doc'][$i] = $doc;
        }
        $notifier = app(Notifier::class);
        $notifier->notify($this->users['loic'], 'document.published', 'Nouveau document diffusé', $this->refs['doc'][11]->title, "/documents/{$this->refs['doc'][11]->id}", 'document', $this->refs['doc'][11]->id);
    }

    private function contents(): void
    {
        $wf = app(ContentWorkflow::class);
        $cat = fn (string $code) => ContentCategory::query()->where('code', $code)->value('id');
        $tplL = Template::query()->where('code', 'TPL-INST-PAYSAGE')->value('id');
        $tplDoc = Template::query()->where('code', 'TPL-DOC-PAYSAGE')->value('id');
        $tplW = Template::query()->where('code', 'TPL-BIENVENUE')->value('id');
        $media = fn (string $key, array $extra = []) => [array_merge(['asset_version_id' => $this->refs['media'][$key]->id, 'role' => 'main'], $extra)];
        $make = function (string $key, User $author, string $title, string $category, string $class, array $langs, array $attrs = []) use ($cat, $tplL): Content {
            $content = Content::query()->create(array_merge([
                'reference' => Reference::next('CNT'), 'title' => $title, 'category_id' => $cat($category), 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id,
                'classification' => $class, 'template_id' => $tplL, 'expires_at' => $this->today->addDays(20)->setTime(18, 0), 'status' => 'draft', 'is_demo' => true,
            ], $attrs));
            foreach ($langs as $lang => [$t, $b]) {
                $content->languages()->create(['lang' => $lang, 'title' => $t, 'body' => $b, 'status' => 'draft']);
            }
            DB::table('workflow_events')->insert(['id' => (string) Str::uuid(), 'object_type' => 'content', 'object_id' => $content->id, 'action' => 'created', 'to_status' => 'draft', 'actor_id' => $author->id, 'created_at' => now()]);

            return $this->refs['content'][$key] = $content;
        };
        $approve = function (Content $c, array $langs) use ($wf): void {
            $wf->submit($c, $langs, $c->author);
            foreach ($langs as $lang) {
                $validator = $lang === 'fr' ? $this->users['jean'] : $this->users['grace'];
                $wf->decide($c, $lang, 'approve', null, (int) $c->languages()->where('lang', $lang)->value('revision'), $validator);
            }
        };
        $n = $this->users['nadia'];

        $c = $make('welcome', $this->users['estelle'], 'Bienvenue — Délégation de la CEMAC', 'ACCUEIL', 'public', ['fr' => ['Bienvenue à la délégation de la CEMAC', 'La Commission de la CEEAC vous souhaite la bienvenue. Salle CEEAC · Rez-de-chaussée'], 'en' => ['Welcome to the CEMAC delegation', 'The ECCAS Commission welcomes you. CEEAC Room · Ground floor']], ['kind' => 'welcome', 'template_id' => $tplW, 'priority' => 70, 'delegation' => ['name' => 'Délégation de la CEMAC', 'flags' => ['CM', 'GA', 'CG', 'TD', 'CF', 'GQ'], 'languages' => ['fr', 'en']], 'expires_at' => $this->rel(90), 'media' => $media('delegation')]);
        $approve($c, ['fr', 'en']);

        $c = $make('communique', $n, 'Communiqué du Secrétariat général', 'COMMUNIQUE', 'interne', ['fr' => ['Réunion de coordination interservices', 'La Commission informe l’ensemble du personnel de la tenue de la réunion de coordination interservices le lundi à 10 heures, salle Congo.'], 'en' => ['Inter-service coordination meeting', 'The Commission informs all staff that the inter-service coordination meeting will be held on Monday at 10 a.m. in the Congo room.']], ['media' => $media('hall')]);
        $wf->submit($c, ['fr', 'en'], $n);
        Approval::query()->where('object_id', $c->id)->where('lang', 'fr')->update(['due_at' => now()->addHours(4)]);

        $c = $make('sante', $this->users['nadia'], 'Campagne santé au travail', 'RH', 'interne', ['fr' => ['Campagne santé au travail', 'Dépistage gratuit et conseils de prévention au service médical, du lundi au vendredi de 9 h à 12 h.'], 'en' => ['Workplace health campaign', 'Free screening and prevention advice at the medical unit, Monday to Friday, 9 a.m. to 12 p.m.']], ['media' => $media('sante'), 'expires_at' => $this->today->addDays(16)->setTime(18, 0)]);
        $approve($c, ['fr', 'en']);

        $c = $make('evacuation', $n, 'Consignes d’évacuation', 'SECURITE', 'public', ['fr' => ['Consignes d’évacuation', 'En cas d’alarme, quittez calmement les locaux par les issues indiquées et rejoignez le point de rassemblement.']], ['is_permanent' => true, 'expires_at' => null]);
        $wf->submit($c, ['fr'], $n);
        $wf->decide($c, 'fr', 'return', 'Préciser l’emplacement du point de rassemblement et ajouter la consigne relative aux ascenseurs.', 1, $this->users['jean']);

        $make('journee', $n, 'Journée de la CEEAC', 'ANNONCE', 'public', ['fr' => ['Journée de la CEEAC', 'Programme des célébrations à venir.'], 'en' => ['ECCAS Day', 'Upcoming celebration programme.']], ['media' => $media('journee')]);

        $c = $make('maintenance', $this->users['alain'], 'Maintenance réseau planifiée', 'DSI', 'interne', ['fr' => ['Maintenance réseau planifiée', 'Interruption du réseau interne samedi de 8 h à 12 h.']]);
        $approve($c, ['fr']);
        $this->refs['content']['maintenance']->forceFill(['expires_at' => $this->today->subDay()->setTime(12, 0)])->save();

        $c = $make('presidence', $n, 'Message de la Présidence de la Commission', 'DIRECTION', 'public', ['fr' => ['Ensemble pour une Afrique centrale intégrée', 'La Commission réaffirme son engagement en faveur d’une coopération régionale durable et solidaire.'], 'en' => ['Together for an integrated Central Africa', 'The Commission reaffirms its commitment to lasting and supportive regional cooperation.']], ['media' => $media('hall'), 'priority' => 50]);
        $approve($c, ['fr']);
        $wf->submit($c->refresh(), ['en'], $n);

        $c = $make('note', $n, 'Note de service — horaires de fin d’année', 'NOTE-SERVICE', 'interne', ['fr' => ['Horaires de fin d’année', 'Du 22 décembre au 2 janvier, les bureaux ouvrent de 8 h à 14 h. Une permanence est assurée dans chaque direction.'], 'en' => ['End-of-year working hours', 'From 22 December to 2 January, offices are open from 8 a.m. to 2 p.m. Each directorate ensures a duty service.']], ['qr' => ['enabled' => true, 'document_id' => $this->refs['doc'][0]->id, 'code' => AccessLink::query()->where('target_id', $this->refs['doc'][0]->id)->value('code'), 'label' => 'Note complète (authentifiée)']]);
        $approve($c, ['fr', 'en']);

        $c = $make('session', $n, 'Annonce officielle — session statutaire', 'ANNONCE', 'public', ['fr' => ['Session statutaire', 'Annonce archivée.']]);
        $wf->archive($c, $n, 'Remplacée par le programme détaillé');

        $c = $make('programme', $n, 'Programme de la session statutaire', 'AGENDA', 'public', ['fr' => ['Programme de la session statutaire', 'Pages choisies du programme officiel.'], 'en' => ['Statutory session programme', 'Selected pages of the official programme.']], ['template_id' => $tplDoc, 'media' => $media('programme', ['pages' => [1, 2, 5], 'page_duration_s' => 12])]);
        $approve($c, ['fr', 'en']);

        $c = $make('repli', $this->users['nadia'], 'Contenu institutionnel de repli', 'REPLI', 'public', ['fr' => ['Commission de la CEEAC', 'Au service de l’intégration régionale en Afrique centrale.']], ['kind' => 'fallback', 'is_permanent' => true, 'expires_at' => null, 'media' => $media('repli')]);
        $approve($c, ['fr']);

        $c = $make('formation', $this->users['nadia'], 'Formation aux outils collaboratifs', 'FORMATION', 'interne', ['fr' => ['Formation aux outils collaboratifs', 'Inscriptions ouvertes auprès de la DRH pour les sessions de novembre.']]);
        $wf->submit($c, ['fr'], $n);
        Approval::query()->where('object_id', $c->id)->update(['created_at' => now()->subDays(3), 'due_at' => now()->subHours(6), 'reminded_at' => now()->subDays(2), 'escalated_at' => now()->subHours(6)]);

        $version = ContentVersion::query()->where('content_id', $this->refs['content']['repli']->id)->first();
        foreach ($this->refs['zone'] as $zone) {
            FallbackContent::query()->create(['zone_id' => $zone->id, 'content_version_id' => $version->id, 'valid_until' => $this->today->addMonths(3), 'created_by' => $this->users['patrick']->id]);
        }
    }

    private function campaigns(): void
    {
        $service = app(CampaignService::class);
        $create = function (string $name, int $priority, CarbonImmutable $from, CarbonImmutable $to, array $items, array $langs, array $targets, array $extra = []): Campaign {
            $campaign = Campaign::query()->create(array_merge(['reference' => Reference::next('CMP'), 'name' => $name, 'priority' => $priority, 'starts_at' => $from, 'ends_at' => $to, 'slide_duration_s' => 12, 'created_by' => $this->users['patrick']->id, 'status' => 'draft', 'is_demo' => true], $extra));
            foreach ($items as $i => $key) {
                $campaign->items()->create(['content_id' => $this->refs['content'][$key]->id, 'position' => $i + 1]);
            }
            foreach ($langs as $rank => $lang) {
                DB::table('campaign_languages')->insert(['campaign_id' => $campaign->id, 'lang' => $lang, 'rank' => $rank + 1]);
            }
            foreach ($targets as [$type, $id]) {
                $campaign->targets()->create(['target_type' => $type, 'target_id' => $id]);
            }

            return $campaign;
        };
        $patrick = $this->users['patrick'];
        $service->publish($create('Accueil délégation CEMAC', 70, $this->rel(-150), $this->rel(90), ['welcome'], ['fr', 'en'], [['zone', $this->refs['zone']['accueil']->id]]), $patrick);
        $service->publish($create('Communication interne — étages', 50, $this->today->subDay()->setTime(8, 0), $this->today->addDays(10)->setTime(18, 0), ['note', 'presidence'], ['fr', 'en'], [['group', $this->refs['group']['internes']->id]], ['days' => [1, 2, 3, 4, 5]]), $patrick);
        $service->publish($create('Programme de la session — hall', 60, $this->today->subDay()->setTime(8, 0), $this->today->addDays(5)->setTime(18, 0), ['programme', 'presidence'], ['fr', 'en'], [['screen', $this->refs['screen']['ECR-RDC-01']->id]], ['time_from' => '08:00', 'time_to' => '17:45']), $patrick);
        $service->publish($create('Information RH — 2e étage', 50, $this->today->setTime(8, 0), $this->today->addDays(6)->setTime(18, 0), ['sante'], ['fr', 'en'], [['floor', $this->refs['floor'][2]->id]]), $patrick);
        $service->publish($create('Campagne santé au travail', 40, $this->today->addDays(3)->setTime(8, 0), $this->today->addDays(15)->setTime(18, 0), ['sante'], ['fr', 'en'], [['group', $this->refs['group']['internes']->id]]), $patrick);
        $expired = $create('Maintenance réseau — annonce', 55, $this->today->subDays(4)->setTime(8, 0), $this->today->subDays(3)->setTime(18, 0), ['maintenance'], ['fr'], [['group', $this->refs['group']['internes']->id]]);
        $expired->items()->first()->forceFill(['version_map' => ['fr' => ContentVersion::query()->where('content_id', $this->refs['content']['maintenance']->id)->value('id')]])->save();
        $expired->forceFill(['status' => 'expired', 'published_at' => $this->today->subDays(5), 'published_by' => $patrick->id])->save();
        $service->tick();
    }

    private function reservations(): void
    {
        $rooms = $this->refs['room'];
        $occ = function (string $room, string $subject, int|CarbonImmutable $day, string|CarbonImmutable $from, string|CarbonImmutable|null $to, string $status, array $extra = [], array $occExtra = []) use ($rooms): ReservationOccurrence {
            $r = $rooms[$room];
            $reservation = Reservation::query()->create(array_merge(['reference' => Reference::next('RES'), 'organizer_id' => $this->users['loic']->id, 'structure_id' => $this->users['loic']->structure_id, 'subject' => $subject, 'participant_count' => 12, 'confidential' => false, 'created_by' => $this->users['loic']->id, 'is_demo' => true], $extra));
            $start = $from instanceof CarbonImmutable ? $from : $this->at($day, $from);
            $end = $to instanceof CarbonImmutable ? $to : $this->at($day, $to);

            return $reservation->occurrences()->create(array_merge(['room_id' => $r->id, 'starts_at' => $start, 'ends_at' => $end, 'blocking_start' => $start->subMinutes($r->prep_minutes), 'blocking_end' => $end->addMinutes($r->cleanup_minutes), 'status' => $status], $occExtra));
        };
        $types = ServiceType::query()->pluck('id', 'code');

        $bilateral = $occ('ceeac', 'Réunion bilatérale CEMAC', 0, $this->rel(-30), $this->rel(90), 'confirmed', ['participant_count' => 18, 'organizer_id' => $this->users['estelle']->id, 'external_participants' => [['name' => 'Délégation CEMAC', 'organization' => 'Commission de la CEMAC']]]);
        $this->refs['content']['welcome']->forceFill(['occurrence_id' => $bilateral->id])->save();
        Visit::query()->create(['reference' => Reference::next('VIS'), 'name' => 'Délégation de la CEMAC', 'institution' => 'Commission de la CEMAC', 'visitors_count' => 18, 'occurrence_id' => $bilateral->id, 'content_id' => $this->refs['content']['welcome']->id, 'languages' => ['fr', 'en'], 'flags' => ['CM', 'GA', 'CG', 'TD', 'CF', 'GQ'], 'status' => 'planned', 'created_by' => $this->users['estelle']->id, 'is_demo' => true]);

        $copil = $occ('kivu', 'Comité de pilotage SAIE', 0, $this->rel(-15), $this->rel(75), 'confirmed', ['participant_count' => 12, 'organizer_id' => $this->users['nadia']->id]);
        foreach (['Validation du périmètre pilote' => '10:30–10:45', 'Revue des écrans et emplacements' => '10:45–11:15', 'Circuit éditorial et habilitations' => '11:15–11:45', 'Décisions et prochaines étapes' => '11:45–12:00'] as $i => $time) {
            $copil->reservation->agendaItems()->create(['position' => count($copil->reservation->agendaItems()->get()) + 1, 'title' => $i, 'time_label' => $time]);
        }
        $copil->reservation->documents()->sync([$this->refs['doc'][3]->id, $this->refs['doc'][8]->id]);
        ServiceRequest::query()->create(['occurrence_id' => $copil->id, 'service_type_id' => $types['VISIO'], 'details' => 'Liaison avec le bureau de Kinshasa', 'status' => 'ready', 'assignee_id' => $this->users['mireille']->id, 'due_at' => $copil->starts_at->subHours(2)]);

        $occ('ogooue', 'Réunion restreinte du Cabinet', 0, $this->rel(120), $this->rel(210), 'confirmed', ['confidential' => true, 'participant_count' => 8, 'organizer_id' => $this->users['clarisse']->id, 'subject' => 'Réunion restreinte du Cabinet — arbitrages budgétaires']);
        $budget = $occ('congo', 'Revue budgétaire', 0, $this->rel(240), $this->rel(330), 'confirmed', ['participant_count' => 32]);
        ServiceRequest::query()->create(['occurrence_id' => $budget->id, 'service_type_id' => $types['INTERPRETATION'], 'details' => 'Interprétation FR/EN/PT', 'status' => 'impossible', 'response_note' => 'Aucun interprète portugais disponible à cette date.', 'assignee_id' => $this->users['mireille']->id, 'due_at' => $budget->starts_at->subHours(2)]);

        $pending = $occ('kivu', 'Formation outils collaboratifs', 2, '09:00', '12:00', 'pending', ['participant_count' => 12], ['hold_until' => now()->addHours(30)]);
        Approval::query()->create(['object_type' => 'reservation', 'object_id' => $pending->reservation_id, 'title' => 'Formation outils collaboratifs', 'requested_by' => $this->users['loic']->id, 'assignee_id' => $this->users['mireille']->id, 'backup_id' => $this->users['clarisse']->id, 'due_at' => now()->subHours(3), 'reminded_at' => now()->subHours(15), 'escalated_at' => now()->subHours(3), 'created_at' => now()->subHours(27)]);
        $pending2 = $occ('ceeac', 'Atelier de coordination régionale', 1, '14:00', '16:00', 'pending', ['participant_count' => 18, 'organizer_id' => $this->users['nadia']->id], ['hold_until' => now()->addHours(42)]);
        Approval::query()->create(['object_type' => 'reservation', 'object_id' => $pending2->reservation_id, 'title' => 'Atelier de coordination régionale', 'requested_by' => $this->users['nadia']->id, 'assignee_id' => $this->users['mireille']->id, 'backup_id' => $this->users['clarisse']->id, 'due_at' => now()->addHours(18)]);
        Visit::query()->create(['reference' => Reference::next('VIS'), 'name' => 'Mission de la Banque africaine de développement', 'institution' => 'BAD', 'visitors_count' => 6, 'occurrence_id' => $pending2->id, 'status' => 'draft', 'created_by' => $this->users['estelle']->id, 'is_demo' => true]);

        $noShow = $occ('congo', 'Réunion de suivi des projets', -1, '09:00', '10:00', 'no_show', ['participant_count' => 10], ['no_show_reason' => 'Salle restée vide 20 minutes après l’heure de début', 'no_show_by' => $this->users['mireille']->id, 'no_show_at' => $this->at(-1, '09:25')]);
        $occ('ogooue', 'Point mensuel DRH', 3, '10:00', '11:00', 'cancelled', [], ['cancel_reason' => 'Report à une date ultérieure']);

        $service = app(ReservationService::class);
        $weekday = $this->today->addDays(7);
        $blockerStart = $weekday->addWeeks(2)->setTime(9, 0);
        $service->create(['room_id' => $rooms['ogooue']->id, 'start_at' => $blockerStart->toIso8601String(), 'end_at' => $blockerStart->addHour()->toIso8601String(), 'subject' => 'Audit des accès', 'confidentiality' => false, 'participant_count' => 6], $this->users['sylvain']);
        $result = $service->create(['room_id' => $rooms['ogooue']->id, 'start_at' => $weekday->setTime(9, 0)->toIso8601String(), 'end_at' => $weekday->setTime(10, 0)->toIso8601String(), 'subject' => 'Point hebdomadaire DSI', 'confidentiality' => false, 'participant_count' => 8,
            'recurrence' => ['freq' => 'weekly', 'count' => 6], 'recurrence_mode' => 'partial'], $this->users['alain']);
        app(Audit::class)->log('reservation.series_partial', $result['reservation'], 'Série en mode partiel : '.count($result['skipped']).' occurrence(s) en conflit écartée(s)', actor: $this->users['alain']);
        Reservation::query()->whereIn('id', Reservation::query()->whereIn('created_by', [$this->users['sylvain']->id, $this->users['alain']->id])->pluck('id'))->update(['is_demo' => true]);

        Export::query()->create(['type' => 'room_usage', 'format' => 'pdf', 'params' => ['from' => $this->today->subMonth()->toIso8601String(), 'to' => $this->today->toIso8601String()], 'status' => 'expired', 'progress' => 100, 'file_name' => 'bilan-utilisation-des-salles.pdf', 'requested_by' => $this->users['alain']->id, 'completed_at' => now()->subDays(4), 'expires_at' => now()->subDay()]);
    }

    private function alertsAndIncidents(): void
    {
        $alain = $this->users['alain'];
        $exercise = Alert::query()->create(['reference' => Reference::next('ALT'), 'title' => 'Exercice technique', 'message' => 'Exercice de diffusion d’alerte : aucune action requise.', 'severity' => 'exercise', 'starts_at' => $this->at(-6, '10:00'), 'expires_at' => $this->at(-6, '10:30'), 'author_id' => $alain->id, 'justification' => 'Exercice trimestriel de diffusion d’alerte', 'status' => 'revoked', 'revoked_at' => $this->at(-6, '10:08'), 'revoked_by' => $alain->id, 'revoke_reason' => 'Fin de l’exercice', 'target_count' => 14, 'offline_at_publish' => 1]);
        $exercise->targets()->create(['target_type' => 'all']);
        $access = Alert::query()->create(['reference' => Reference::next('ALT'), 'title' => 'Accès Est temporairement fermé', 'message' => 'Veuillez éviter l’accès Est du bâtiment. Utilisez l’entrée principale.', 'translations' => ['en' => ['title' => 'East entrance temporarily closed', 'message' => 'Please avoid the East entrance. Use the main entrance.']], 'severity' => 'info', 'starts_at' => $this->at(-2, '09:00'), 'expires_at' => $this->at(-2, '09:45'), 'author_id' => $alain->id, 'justification' => 'Fermeture signalée par les moyens généraux', 'status' => 'expired', 'target_count' => 2,
            'extensions' => [['by' => $alain->name, 'at' => $this->at(-2, '09:25')->toAtomString(), 'minutes' => 15, 'reason' => 'Travaux prolongés', 'previous_expiry' => $this->at(-2, '09:30')->toAtomString()]]]);
        $access->targets()->create(['target_type' => 'zone', 'target_id' => $this->refs['zone']['accueil']->id]);
        foreach ($this->refs['device'] as $code => $device) {
            if ($code !== 'ECR-2E-02') {
                AlertReceipt::query()->create(['alert_id' => $exercise->id, 'device_id' => $device->id, 'received_at' => $this->at(-6, '10:00')->addSeconds(random_int(5, 30)), 'activated_at' => $this->at(-6, '10:00')->addSeconds(random_int(31, 58))]);
            }
            if (str_contains($code, 'RDC')) {
                AlertReceipt::query()->create(['alert_id' => $access->id, 'device_id' => $device->id, 'received_at' => $this->at(-2, '09:00')->addSeconds(12), 'activated_at' => $this->at(-2, '09:00')->addSeconds(25)]);
            }
        }
        $offline = $this->refs['device']['ECR-2E-02'];
        Incident::query()->create(['reference' => Reference::next('INC'), 'screen_id' => $offline->screen_id, 'device_id' => $offline->id, 'kind' => 'device_unavailable', 'severity' => 'critical', 'status' => 'acknowledged', 'title' => 'Lecteur indisponible ECR-2E-02', 'impact' => 'Diffusion non garantie au 2e étage (couloir Est)', 'detected_at' => now()->subMinutes(13), 'acknowledged_at' => now()->subMinutes(8), 'assignee_id' => $alain->id, 'is_automatic' => true,
            'events' => [['at' => now()->subMinutes(13)->toAtomString(), 'by' => 'Système', 'action' => 'Détection automatique (aucun contact depuis 5 min)'], ['at' => now()->subMinutes(8)->toAtomString(), 'by' => $alain->name, 'action' => 'acknowledge', 'note' => 'Intervention planifiée : vérification de l’alimentation']]]);
        Incident::query()->create(['reference' => Reference::next('INC'), 'screen_id' => $this->refs['screen']['ECR-4E-02']->id, 'kind' => 'manual', 'severity' => 'minor', 'status' => 'resolved', 'title' => 'Saturation du cache ECR-4E-02', 'impact' => 'Aucun', 'detected_at' => $this->at(-3, '11:00'), 'resolved_at' => $this->at(-3, '11:40'), 'assignee_id' => $alain->id, 'events' => [['at' => $this->at(-3, '11:40')->toAtomString(), 'by' => $alain->name, 'action' => 'resolve', 'note' => 'Éviction des ressources non référencées']]]);
    }

    /**
     * Historique de télémétrie et de reçus des 14 derniers jours pour les lecteurs de démonstration.
     */
    private function history(): void
    {
        $rows = [];
        $telemetry = [];
        foreach ($this->refs['device'] as $code => $device) {
            for ($d = 14; $d >= 1; $d--) {
                $day = $this->today->subDays($d);
                if ($day->isWeekend()) {
                    continue;
                }
                $quality = $code === 'ECR-2E-02' ? 0.8 : 0.99;
                for ($h = 8; $h < 18; $h++) {
                    if (mt_rand() / mt_getrandmax() > $quality) {
                        continue;
                    }
                    $telemetry[] = ['device_id' => $device->id, 'recorded_at' => $day->setTime($h, 5)->utc(), 'payload' => json_encode(['simulated' => true, 'demo' => true])];
                    foreach (['activated', 'played', 'played', 'played'] as $i => $kind) {
                        $rows[] = ['id' => (string) Str::uuid(), 'event_id' => (string) Str::uuid(), 'device_id' => $device->id, 'kind' => $i === 0 && $h !== 8 ? 'manifest_received' : $kind, 'manifest_version' => 1, 'ref_type' => $kind === 'played' ? 'demo' : null, 'ref_id' => null, 'lang' => null, 'occurred_at' => $day->setTime($h, 10 + $i * 7)->utc(), 'received_at' => $day->setTime($h, 30)->utc(), 'payload' => json_encode(['demo' => true])];
                    }
                }
            }
        }
        foreach (array_chunk($telemetry, 500) as $chunk) {
            DB::table('device_telemetry')->insert($chunk);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('device_receipts')->insert($chunk);
        }
    }

    private function rel(int $minutes): CarbonImmutable
    {
        return $this->anchor->addMinutes($minutes)->utc();
    }

    private function at(int $dayOffset, string $time): CarbonImmutable
    {
        [$h, $m] = explode(':', $time);

        return $this->today->addDays($dayOffset)->setTime((int) $h, (int) $m)->utc();
    }

    private function upload(AssetService $service, string $bytes, string $name, array $meta, User $user): AssetVersion
    {
        $path = tempnam(sys_get_temp_dir(), 'saie');
        file_put_contents($path, $bytes);
        $version = $service->store(new UploadedFile($path, $name, null, null, true), $meta, $user);
        (new ProcessAssetVersion($version->id))->handle(app(VirusScanner::class), app(Audit::class));
        $version->asset()->update(['is_demo' => true]);

        return $version->refresh();
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $from
     * @param  array{0: int, 1: int, 2: int}  $to
     */
    private function image(string $file, string $title, array $from, array $to): string
    {
        $w = 1920;
        $h = 1080;
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y += 4) {
            $t = $y / $h;
            $color = imagecolorallocate($img, (int) ($from[0] + ($to[0] - $from[0]) * $t), (int) ($from[1] + ($to[1] - $from[1]) * $t), (int) ($from[2] + ($to[2] - $from[2]) * $t));
            imagefilledrectangle($img, 0, $y, $w, $y + 4, $color);
        }
        $white = imagecolorallocatealpha($img, 255, 255, 255, 70);
        imagefilledellipse($img, 1450, 540, 700, 700, $white);
        $font = 5;
        $text = imagecolorallocate($img, 255, 255, 255);
        imagestring($img, $font, 120, 980, mb_convert_encoding($title.' - visuel de demonstration', 'ASCII', 'UTF-8'), $text);
        ob_start();
        match (pathinfo($file, PATHINFO_EXTENSION)) {
            'png' => imagepng($img), 'webp' => imagewebp($img, null, 80), default => imagejpeg($img, null, 85)
        };

        return (string) ob_get_clean();
    }

    private function pdf(string $title, int $pages, string $text): string
    {
        $body = '';
        for ($p = 1; $p <= $pages; $p++) {
            $body .= '<div style="page-break-after:'.($p < $pages ? 'always' : 'auto').'"><h1 style="color:#0b1f3a">'.e($title).'</h1><p style="font-size:14pt">'.e($text).'</p><p>Page '.$p.' sur '.$pages.' — document fictif de démonstration SAIE-CEEAC.</p></div>';
        }
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<html><body style="font-family: DejaVu Sans">'.$body.'</body></html>', 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
