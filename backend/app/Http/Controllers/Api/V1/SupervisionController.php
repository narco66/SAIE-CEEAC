<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Incident;
use App\Models\MaintenancePlan;
use App\Models\User;
use App\Services\IncidentPolicy;
use App\Services\Notifier;
use App\Services\OperationalMetrics;
use App\Support\BusinessException;
use App\Support\Present;
use App\Support\Reference;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Supervision et incidents (V40, EXP-01). Chaque contrôle indique sa date ou l'absence de mesure.
 */
class SupervisionController extends Controller
{
    public function show(): JsonResponse
    {
        $this->authorizePermission('supervision.view');
        $checks = [];
        $checks[] = $this->check('API', fn () => ['ok' => true, 'detail' => 'Réponse en '.round((microtime(true) - LARAVEL_START) * 1000).' ms']);
        $checks[] = $this->check('Base de données', function () {
            $version = DB::selectOne('select version() as v')->v;
            $connections = DB::selectOne('select count(*) as c from pg_stat_activity where datname = current_database()')->c;

            return ['ok' => true, 'detail' => strtok($version, ',')." · $connections connexion(s)"];
        });
        // EXP-01 : mesure sur le pilote réel des files (Redis en production, base sur un poste local), jamais sur une table inutilisée.
        $checks[] = $this->check('Files de traitement', function () {
            $connection = Queue::connection();
            $pending = 0;
            $oldest = null;
            foreach (['default', 'notifications', 'media', 'exports'] as $name) {
                $pending += method_exists($connection, 'pendingSize') ? (int) $connection->pendingSize($name) : (int) $connection->size($name);
                $created = method_exists($connection, 'creationTimeOfOldestPendingJob') ? $connection->creationTimeOfOldestPendingJob($name) : null;
                $oldest = $created ? min($oldest ?? PHP_INT_MAX, (int) $created) : $oldest;
            }
            $failed = DB::table('failed_jobs')->count();
            $lag = $oldest ? time() - $oldest : 0;
            $driver = config('queue.default');

            return ['ok' => $lag < 300 && $failed === 0, 'detail' => "$pending en attente · retard ".gmdate('i:s', max(0, $lag))." · $failed en échec · pilote $driver", 'warn' => $lag >= 300 || $failed > 0];
        });
        $checks[] = $this->check('Conversions et analyses', function () {
            $failed = DB::table('asset_versions')->where('scan_status', 'rejected')->where('processed_at', '>', now()->subDay())->count();
            $stuck = DB::table('asset_versions')->whereIn('scan_status', ['quarantine', 'scanning'])->where('created_at', '<', now()->subMinutes(10))->count();

            return ['ok' => $stuck === 0, 'detail' => "$failed rejet(s) sur 24 h · $stuck en attente depuis plus de 10 min · moteur ".config('saie.antivirus.driver'), 'warn' => $stuck > 0];
        });
        $checks[] = $this->check('Stockage', function () {
            $path = Storage::disk('local')->path('');
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);
            $used = (int) DB::table('asset_versions')->sum('size');

            return ['ok' => $free === false || $free / max(1, $total) > 0.1, 'detail' => ($free !== false ? round($free / 1073741824, 1).' Go libres sur '.round($total / 1073741824, 1).' Go' : 'Non mesuré').' · médias '.round($used / 1048576, 1).' Mo'];
        });
        // HAB-01 : un superviseur limité à un site ou une zone ne voit que les lecteurs de ses écrans.
        $scope = $this->screenScopeFor('supervision.view');
        $devices = Device::query()->with(['screen.location.floor.building', 'screen.groups'])->where('status', 'active')
            ->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))->get();
        $open = Incident::query()->whereIn('status', ['open', 'acknowledged'])->get(['device_id', 'screen_id']);
        $withIncident = $open->pluck('device_id')->merge($open->pluck('screen_id'))->filter()->unique()->values()->all();
        $stateOf = fn (Device $d): string => $d->screen ? Present::operationalState($d->screen, $d, $withIncident)['code'] : 'pending_configuration';
        $statuses = $devices->groupBy(fn ($d) => $d->computedNetworkStatus())->map->count();
        $checks[] = ['name' => 'Lecteurs', 'ok' => ($statuses['unavailable'] ?? 0) === 0, 'warn' => ($statuses['suspect'] ?? 0) > 0, 'detail' => ($statuses['online'] ?? 0).' en ligne · '.($statuses['suspect'] ?? 0).' suspect(s) · '.($statuses['unavailable'] ?? 0).' indisponible(s)', 'checked_at' => now()->toAtomString()];
        $measured = $devices->filter(fn ($d) => $d->screen && $d->screen->control_channel !== 'none')->count();
        // Jamais « OK » sans mesure : sans canal de pilotage, l'état des dalles est un avertissement « non mesuré ».
        $checks[] = ['name' => 'Dalles', 'ok' => $measured > 0, 'warn' => $measured < $devices->count(), 'detail' => $measured === 0 ? 'Non mesuré : aucun écran ne déclare de canal de pilotage (HDMI-CEC, RS-232 ou API)' : "$measured dalle(s) sur {$devices->count()} avec retour d’état matériel", 'checked_at' => now()->toAtomString()];
        $unauthorized = DB::table('audit_logs')->where('action', 'device.unauthorized')->where('occurred_at', '>', now()->subDay())->count();
        $checks[] = ['name' => 'Terminaux non autorisés', 'ok' => true, 'warn' => $unauthorized > 0, 'detail' => $unauthorized === 0 ? 'Aucun appel refusé sur l’API des lecteurs en 24 h'
            : "$unauthorized appel(s) refusé(s) en 24 h (jeton absent, révoqué ou invalide) : voir le journal, action « device.unauthorized »", 'checked_at' => now()->toAtomString()];
        $checks[] = $this->backupCheck();
        $checks[] = $this->dynamicSourcesCheck();

        return response()->json(['data' => [
            'checks' => $checks,
            'devices' => $devices->sortBy('code')->map(fn ($d) => [
                'id' => $d->id, 'code' => $d->code, 'screen' => $d->screen?->code, 'screen_id' => $d->screen_id, 'network_status' => $d->computedNetworkStatus(), 'last_seen_at' => Present::date($d->last_seen_at),
                'app_version' => $d->app_version, 'clock_skew_ms' => $d->clock_skew_ms, 'storage' => $d->storage, 'errors' => $d->recentErrors(), 'state' => $stateOf($d), 'playback_paused' => (bool) $d->playback_paused,
                'floor' => $d->screen?->location?->floor ? ['id' => $d->screen->location->floor->id, 'label' => $d->screen->location->floor->label, 'level' => $d->screen->location->floor->level] : null,
                'building' => $d->screen?->location?->floor?->building?->name, 'groups' => $d->screen ? $d->screen->groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values() : [],
            ])->values(),
            // §10 : supervision par étage et par groupe (lecteurs joignables / total, en anomalie).
            'by_floor' => $devices->filter(fn ($d) => $d->screen?->location?->floor)->groupBy(fn ($d) => $d->screen->location->floor->id)->map(fn ($items) => [
                'id' => $items->first()->screen->location->floor->id, 'label' => $items->first()->screen->location->floor->label, 'level' => $items->first()->screen->location->floor->level,
                'total' => $items->count(), 'online' => $items->filter(fn ($d) => $d->computedNetworkStatus() === 'online')->count(), 'anomaly' => $items->filter(fn ($d) => $stateOf($d) === 'anomaly')->count(),
            ])->sortByDesc('level')->values(),
            'by_group' => $devices->flatMap(fn ($d) => $d->screen ? $d->screen->groups->map(fn ($g) => ['group' => $g, 'device' => $d]) : [])->groupBy(fn ($x) => $x['group']->id)->map(fn ($items) => [
                'id' => $items->first()['group']->id, 'name' => $items->first()['group']->name, 'total' => $items->count(),
                'online' => $items->filter(fn ($x) => $x['device']->computedNetworkStatus() === 'online')->count(), 'anomaly' => $items->filter(fn ($x) => $stateOf($x['device']) === 'anomaly')->count(),
            ])->sortBy('name')->values(),
            'versions' => $devices->groupBy('app_version')->map->count(),
            'thresholds' => Device::thresholds(),
            'incidents' => Incident::query()->whereIn('status', ['open', 'acknowledged'])->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))->count(),
        ]]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $this->authorizePermission('supervision.view');
        $scope = $this->screenScopeFor('supervision.view', 'incidents.manage');
        $query = Incident::query()->with(['screen', 'device', 'assignee', 'verifier'])
            // HAB-01 : un titulaire limité à un site ou une zone ne voit que les incidents de ses écrans.
            ->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('screen_id'), fn ($q, $id) => $q->where('screen_id', $id))
            ->when($request->query('severity'), fn ($q, $s) => $q->where('severity', $s))
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->orderByDesc('detected_at')->orderByDesc('id');

        return $this->paginated($query, $request, fn (Incident $i) => $this->presentIncident($i));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentIncident(Incident $i): array
    {
        $i->loadMissing(['screen', 'device', 'assignee', 'verifier']);

        return [
            'id' => $i->id, 'reference' => $i->reference, 'title' => $i->title, 'description' => $i->description, 'kind' => $i->kind, 'category' => $i->category, 'maintenance_type' => $i->maintenance_type, 'diagnosis' => $i->diagnosis, 'resolution' => $i->resolution, 'severity' => $i->severity, 'status' => $i->status, 'impact' => $i->impact,
            'screen' => $i->screen ? ['id' => $i->screen->id, 'code' => $i->screen->code] : null, 'device' => $i->device?->code, 'assignee' => Present::userRef($i->assignee),
            'detected_at' => Present::date($i->detected_at), 'acknowledged_at' => Present::date($i->acknowledged_at), 'resolved_at' => Present::date($i->resolved_at), 'closed_at' => Present::date($i->closed_at),
            'events' => $i->events ?? [], 'is_automatic' => $i->is_automatic,
            'verified_at' => Present::date($i->verified_at), 'verified_by' => Present::userRef($i->verifier), 'escalation_level' => $i->escalation_level,
            'due_at' => Present::date($i->due_at), 'overdue' => $i->status === 'open' && $i->due_at?->isPast(),
        ];
    }

    public function storeIncident(Request $request): JsonResponse
    {
        $this->authorizePermission('incidents.manage');
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'], 'severity' => ['required', 'in:critical,major,medium,minor'], 'screen_id' => ['nullable', 'uuid', 'exists:screens,id'], 'impact' => ['nullable', 'string', 'max:200'],
            'category' => ['sometimes', 'in:player,panel,network,power,content,software,other'], 'maintenance_type' => ['sometimes', 'in:corrective,preventive']]);
        $this->assertScreenInScope($data['screen_id'] ?? null, 'incidents.manage');
        // Le lecteur actif est rattaché à l'incident : l'écran passe « en anomalie » comme pour un incident détecté.
        $deviceId = isset($data['screen_id']) ? Device::query()->where('screen_id', $data['screen_id'])->where('status', 'active')->value('id') : null;
        $incident = Incident::query()->create($data + ['device_id' => $deviceId, 'reference' => Reference::next('INC'), 'kind' => 'manual', 'is_automatic' => false, 'status' => 'open', 'detected_at' => now(), 'events' => [['at' => now()->toAtomString(), 'by' => $this->user()->name, 'action' => 'Déclaration']]]);
        $this->audit()->log('incident.created', $incident);

        return response()->json(['data' => $this->presentIncident($incident->refresh())], 201);
    }

    public function updateIncident(Request $request, Incident $incident): JsonResponse
    {
        $this->authorizePermission('incidents.manage');
        $data = $request->validate(['action' => ['required', 'in:assign,acknowledge,qualify,diagnose,intervene,resolve,verify,reopen,close,comment'], 'assignee_id' => ['nullable', 'required_if:action,assign', 'uuid', 'exists:users,id'],
            // §15.2 : qualification (gravité et catégorie), intervention, vérification et réouverture sont motivées.
            'severity' => ['required_if:action,qualify', 'in:critical,major,medium,minor'], 'category' => ['required_if:action,qualify', 'in:player,panel,network,power,content,software,other'],
            // RG-012 : pas de résolution sans résultat de traitement.
            'note' => ['nullable', 'string', 'max:1000', 'required_if:action,diagnose', 'required_if:action,resolve', 'required_if:action,intervene', 'required_if:action,verify', 'required_if:action,reopen']]);
        $this->assertScreenInScope($incident->screen_id, 'incidents.manage');
        $transitions = ['acknowledge' => ['open'], 'qualify' => ['open', 'acknowledged'], 'diagnose' => ['open', 'acknowledged'], 'intervene' => ['open', 'acknowledged'], 'resolve' => ['open', 'acknowledged'],
            'verify' => ['resolved'], 'reopen' => ['resolved'], 'close' => ['resolved'], 'assign' => ['open', 'acknowledged'], 'comment' => ['open', 'acknowledged', 'resolved']];
        $assignee = null;
        if ($data['action'] === 'assign') {
            // L'intervenant est un compte actif qui peut suivre l'incident (gestion des incidents ou supervision).
            $assignee = User::query()->findOrFail($data['assignee_id']);
            if (! $assignee->isActive() || ! ($this->access()->can($assignee, 'incidents.manage') || $this->access()->can($assignee, 'supervision.view'))) {
                throw new BusinessException('ASSIGNEE_NOT_ELIGIBLE', 'Cet agent ne peut pas prendre en charge un incident : compte inactif ou sans accès à la supervision.', 422);
            }
        }
        if (! in_array($incident->status, $transitions[$data['action']], true)) {
            throw new BusinessException('INVALID_TRANSITION', 'Action impossible dans l’état actuel de l’incident.', 409);
        }
        // §15.2 : la clôture suit la vérification du fonctionnement (RG-012 : résultat consigné à la résolution).
        if ($data['action'] === 'close' && ! $incident->verified_at) {
            throw new BusinessException('VERIFICATION_REQUIRED', 'Vérifiez le fonctionnement rétabli avant de clore l’incident.', 409);
        }
        $changes = match ($data['action']) {
            'assign' => ['assignee_id' => $data['assignee_id']],
            'diagnose' => ['diagnosis' => $data['note']],
            'qualify' => ['severity' => $data['severity'], 'category' => $data['category'], 'due_at' => app(IncidentPolicy::class)->dueAt($data['severity'], $incident->detected_at->toImmutable())],
            'verify' => ['verified_at' => now(), 'verified_by' => $this->user()->id],
            'reopen' => ['status' => 'open', 'resolved_at' => null, 'resolution' => null, 'verified_at' => null, 'verified_by' => null],
            'acknowledge' => ['status' => 'acknowledged', 'acknowledged_at' => now()],
            'resolve' => ['status' => 'resolved', 'resolved_at' => now(), 'resolution' => $data['note']],
            'close' => ['status' => 'closed', 'closed_at' => now()],
            default => [],
        };
        $events = $incident->events ?? [];
        $events[] = ['at' => now()->toAtomString(), 'by' => $this->user()->name, 'action' => $data['action'], 'note' => $data['note'] ?? null];
        $incident->forceFill($changes + ['events' => $events])->save();
        // INC-03 : la résolution d'une intervention préventive recale l'échéance de son plan.
        if ($data['action'] === 'resolve' && $incident->maintenance_plan_id) {
            MaintenancePlan::query()->find($incident->maintenance_plan_id)?->scheduleAfter(now(config('saie.timezone')));
        }
        $this->audit()->log('incident.'.$data['action'], $incident, $data['note'] ?? null);
        if ($assignee && $assignee->id !== $this->user()->id) {
            app(Notifier::class)->notify($assignee, 'incident.assigned', "Incident {$incident->reference} affecté", $incident->title, '/supervision', 'incident', $incident->id, $incident->id.':'.$assignee->id);
        }

        return response()->json(['data' => $this->presentIncident($incident->refresh())]);
    }

    /**
     * Statistiques des incidents (§10.3) sur 30 jours par défaut, bornées au périmètre.
     */
    public function incidentStats(Request $request, IncidentPolicy $policy): JsonResponse
    {
        $this->authorizePermission('supervision.view', 'incidents.manage');
        $days = (int) ($request->validate(['days' => ['sometimes', 'integer', 'between:1,366']])['days'] ?? 30);

        return response()->json(['data' => $policy->stats(CarbonImmutable::now()->subDays($days ?: 30), $this->screenScopeFor('supervision.view', 'incidents.manage'))]);
    }

    /**
     * Plans de maintenance préventive (INC-03).
     */
    public function plans(): JsonResponse
    {
        $this->authorizePermission('supervision.view');

        $scope = $this->screenScopeFor('supervision.view', 'incidents.manage');

        return response()->json(['data' => MaintenancePlan::query()->with('screen')->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))->orderBy('next_due_on')->get()->map(fn (MaintenancePlan $p) => $this->presentPlan($p))->values()]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $this->authorizePermission('incidents.manage');
        $data = $request->validate($this->planRules(true));
        $this->assertScreenInScope($data['screen_id'] ?? null, 'incidents.manage');
        $plan = MaintenancePlan::query()->create($data + ['created_by' => $this->user()->id]);
        $this->audit()->log('maintenance_plan.created', $plan, after: $data);

        return response()->json(['data' => $this->presentPlan($plan->load('screen'))], 201);
    }

    public function updatePlan(Request $request, MaintenancePlan $plan): JsonResponse
    {
        $this->authorizePermission('incidents.manage');
        $data = $request->validate($this->planRules(false));
        $this->assertScreenInScope($plan->screen_id, 'incidents.manage');
        if (array_key_exists('screen_id', $data)) {
            $this->assertScreenInScope($data['screen_id'], 'incidents.manage');
        }
        $before = $plan->only(array_keys($data));
        $plan->update($data);
        $this->audit()->log('maintenance_plan.updated', $plan, before: $before, after: $data);

        return response()->json(['data' => $this->presentPlan($plan->refresh()->load('screen'))]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function planRules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'min:5', 'max:200'], 'instructions' => ['nullable', 'string', 'max:2000'], 'screen_id' => ['nullable', 'uuid', 'exists:screens,id'],
            'category' => ['sometimes', 'in:player,panel,network,power,content,software,other'], 'frequency_days' => [$req, 'integer', 'between:1,730'],
            'next_due_on' => [$req, 'date'], 'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPlan(MaintenancePlan $p): array
    {
        return [
            'id' => $p->id, 'title' => $p->title, 'instructions' => $p->instructions, 'category' => $p->category, 'frequency_days' => $p->frequency_days,
            'next_due_on' => $p->next_due_on->format('Y-m-d'), 'last_done_on' => $p->last_done_on?->format('Y-m-d'), 'is_active' => $p->is_active,
            'overdue' => $p->is_active && $p->next_due_on->endOfDay()->isPast(), 'screen' => $p->screen ? ['id' => $p->screen->id, 'code' => $p->screen->code] : null,
        ];
    }

    /**
     * Dernières erreurs applicatives (sans contexte technique ni données personnelles) et métriques de l'API sur l'heure (ADM-02).
     */
    public function errors(OperationalMetrics $metrics): JsonResponse
    {
        $this->authorizePermission('settings.technical');

        return response()->json(['data' => ['api' => $metrics->lastHour(), 'errors' => $metrics->recentErrors()]]);
    }

    /**
     * Sources dynamiques actives : chacune doit avoir été relevée avec succès depuis moins de deux fois sa fréquence.
     *
     * @return array<string, mixed>
     */
    private function dynamicSourcesCheck(): array
    {
        $sources = DB::table('dynamic_sources')->where('is_active', true)->get(['name', 'frequency_minutes', 'last_fetched_at', 'last_status']);
        if ($sources->isEmpty()) {
            return ['name' => 'Collecte des sources dynamiques', 'ok' => true, 'warn' => false, 'detail' => 'Aucune source active', 'checked_at' => now()->toAtomString()];
        }
        $stale = $sources->filter(fn ($s) => ! $s->last_fetched_at || $s->last_status !== 'ok'
            || Carbon::parse($s->last_fetched_at)->lt(now()->subMinutes(2 * max(1, (int) $s->frequency_minutes))));

        return ['name' => 'Collecte des sources dynamiques', 'ok' => $stale->isEmpty(), 'warn' => false,
            'detail' => $stale->isEmpty() ? $sources->count().' source(s) à jour' : $stale->count().' source(s) en échec ou non relevée(s) : '.$stale->pluck('name')->implode(', '),
            'checked_at' => now()->toAtomString()];
    }

    /**
     * CONT-01 : fraîcheur de la dernière sauvegarde, écrite par le service « backup » (infra/backup/backup.sh) dans
     * storage/app/backup-status.json. Absente : « Non mesuré », jamais une valeur présumée.
     *
     * @return array<string, mixed>
     */
    private function backupCheck(): array
    {
        $file = storage_path('app/backup-status.json');
        $status = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($status) || empty($status['last_success_at'])) {
            return ['name' => 'Sauvegardes', 'ok' => false, 'warn' => true, 'detail' => 'Non mesuré : aucun état écrit par le service de sauvegarde (infra/backup)', 'checked_at' => null];
        }
        $age = now()->diffInHours(Carbon::parse($status['last_success_at']), true);
        $wal = isset($status['last_wal_at']) ? ' · dernier WAL archivé '.Carbon::parse($status['last_wal_at'])->setTimezone(config('saie.timezone'))->format('d/m H:i') : '';

        // Copie horaire des fichiers privés (RPO fichiers ≤ 1 h) : en retard au-delà de 2 h.
        $filesAge = empty($status['last_files_at']) ? null : now()->diffInMinutes(Carbon::parse($status['last_files_at']), true);
        $files = $filesAge === null ? ' · copie horaire des fichiers non mesurée' : ' · fichiers copiés il y a '.($filesAge < 60 ? round($filesAge).' min' : round($filesAge / 60).' h');
        $ok = $age <= 26 && $filesAge !== null && $filesAge <= 120;

        return ['name' => 'Sauvegardes', 'ok' => $ok, 'warn' => ! $ok, 'detail' => 'Dernière sauvegarde complète il y a '.round($age).' h'.$wal.$files, 'checked_at' => $status['last_success_at']];
    }

    private function check(string $name, callable $probe): array
    {
        try {
            $result = $probe();

            return ['name' => $name, 'ok' => $result['ok'], 'warn' => $result['warn'] ?? false, 'detail' => $result['detail'], 'checked_at' => now()->toAtomString()];
        } catch (Throwable $e) {
            return ['name' => $name, 'ok' => false, 'warn' => false, 'detail' => 'Contrôle en échec', 'checked_at' => now()->toAtomString()];
        }
    }
}
