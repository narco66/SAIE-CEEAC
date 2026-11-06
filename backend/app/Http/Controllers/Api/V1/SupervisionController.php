<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Incident;
use App\Support\BusinessException;
use App\Support\Present;
use App\Support\Reference;
use Carbon\Carbon;
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
        $devices = Device::query()->with('screen')->where('status', 'active')->get();
        $statuses = $devices->groupBy(fn ($d) => $d->computedNetworkStatus())->map->count();
        $checks[] = ['name' => 'Lecteurs', 'ok' => ($statuses['unavailable'] ?? 0) === 0, 'warn' => ($statuses['suspect'] ?? 0) > 0, 'detail' => ($statuses['online'] ?? 0).' en ligne · '.($statuses['suspect'] ?? 0).' suspect(s) · '.($statuses['unavailable'] ?? 0).' indisponible(s)', 'checked_at' => now()->toAtomString()];
        $measured = $devices->filter(fn ($d) => $d->screen && $d->screen->control_channel !== 'none')->count();
        $checks[] = ['name' => 'Dalles', 'ok' => true, 'warn' => false, 'detail' => $measured === 0 ? 'Non mesuré : aucun canal de pilotage déclaré (PLY-09, P1)' : "$measured dalle(s) avec retour d’état", 'checked_at' => now()->toAtomString()];
        $checks[] = $this->backupCheck();
        $checks[] = ['name' => 'Collecte des sources dynamiques', 'ok' => true, 'warn' => false, 'detail' => DB::table('dynamic_sources')->where('is_active', true)->count() === 0 ? 'Aucune source active (PUB-10, P1)' : 'Sources actives', 'checked_at' => now()->toAtomString()];

        return response()->json(['data' => [
            'checks' => $checks,
            'devices' => $devices->sortBy('code')->map(fn ($d) => ['id' => $d->id, 'code' => $d->code, 'screen' => $d->screen?->code, 'screen_id' => $d->screen_id, 'network_status' => $d->computedNetworkStatus(), 'last_seen_at' => Present::date($d->last_seen_at), 'app_version' => $d->app_version, 'clock_skew_ms' => $d->clock_skew_ms, 'storage' => $d->storage, 'errors' => $d->last_errors])->values(),
            'versions' => $devices->groupBy('app_version')->map->count(),
            'incidents' => Incident::query()->whereIn('status', ['open', 'acknowledged'])->count(),
        ]]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $this->authorizePermission('supervision.view');
        $query = Incident::query()->with(['screen', 'device', 'assignee'])
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('severity'), fn ($q, $s) => $q->where('severity', $s))
            ->orderByDesc('detected_at')->orderByDesc('id');

        return $this->paginated($query, $request, fn (Incident $i) => [
            'id' => $i->id, 'reference' => $i->reference, 'title' => $i->title, 'description' => $i->description, 'kind' => $i->kind, 'severity' => $i->severity, 'status' => $i->status, 'impact' => $i->impact,
            'screen' => $i->screen ? ['id' => $i->screen->id, 'code' => $i->screen->code] : null, 'device' => $i->device?->code, 'assignee' => Present::userRef($i->assignee),
            'detected_at' => Present::date($i->detected_at), 'acknowledged_at' => Present::date($i->acknowledged_at), 'resolved_at' => Present::date($i->resolved_at), 'closed_at' => Present::date($i->closed_at),
            'events' => $i->events ?? [], 'is_automatic' => $i->is_automatic,
        ]);
    }

    public function storeIncident(Request $request): JsonResponse
    {
        $this->authorizePermission('incidents.manage');
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'], 'severity' => ['required', 'in:critical,major,minor'], 'screen_id' => ['nullable', 'uuid', 'exists:screens,id'], 'impact' => ['nullable', 'string', 'max:200']]);
        $incident = Incident::query()->create($data + ['reference' => Reference::next('INC'), 'kind' => 'manual', 'status' => 'open', 'detected_at' => now(), 'events' => [['at' => now()->toAtomString(), 'by' => $this->user()->name, 'action' => 'Déclaration']]]);
        $this->audit()->log('incident.created', $incident);

        return response()->json(['data' => $incident], 201);
    }

    public function updateIncident(Request $request, Incident $incident): JsonResponse
    {
        $this->authorizePermission('incidents.manage');
        $data = $request->validate(['action' => ['required', 'in:assign,acknowledge,resolve,close,comment'], 'assignee_id' => ['nullable', 'required_if:action,assign', 'uuid', 'exists:users,id'], 'note' => ['nullable', 'string', 'max:1000']]);
        $transitions = ['acknowledge' => ['open'], 'resolve' => ['open', 'acknowledged'], 'close' => ['resolved'], 'assign' => ['open', 'acknowledged'], 'comment' => ['open', 'acknowledged', 'resolved']];
        if (! in_array($incident->status, $transitions[$data['action']], true)) {
            throw new BusinessException('INVALID_TRANSITION', 'Action impossible dans l’état actuel de l’incident.', 409);
        }
        $changes = match ($data['action']) {
            'assign' => ['assignee_id' => $data['assignee_id']],
            'acknowledge' => ['status' => 'acknowledged', 'acknowledged_at' => now()],
            'resolve' => ['status' => 'resolved', 'resolved_at' => now()],
            'close' => ['status' => 'closed', 'closed_at' => now()],
            default => [],
        };
        $events = $incident->events ?? [];
        $events[] = ['at' => now()->toAtomString(), 'by' => $this->user()->name, 'action' => $data['action'], 'note' => $data['note'] ?? null];
        $incident->forceFill($changes + ['events' => $events])->save();
        $this->audit()->log('incident.'.$data['action'], $incident, $data['note'] ?? null);

        return response()->json(['data' => $incident->refresh()]);
    }

    /**
     * @param  callable(): array<string, mixed>  $probe
     * @return array<string, mixed>
     */
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

        return ['name' => 'Sauvegardes', 'ok' => $age <= 26, 'warn' => $age > 26, 'detail' => 'Dernière sauvegarde complète il y a '.round($age).' h'.$wal, 'checked_at' => $status['last_success_at']];
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
