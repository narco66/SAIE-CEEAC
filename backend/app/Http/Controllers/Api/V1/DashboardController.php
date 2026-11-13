<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Approval;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentLanguage;
use App\Models\Device;
use App\Models\Document;
use App\Models\Floor;
use App\Models\Incident;
use App\Models\ReservationOccurrence;
use App\Models\Room;
use App\Models\Screen;
use App\Models\ServiceRequest;
use App\Models\Setting;
use App\Services\ContentAccess;
use App\Services\DocumentAccess;
use App\Support\Present;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Accueil par rôle (V04), Mes tâches (V05) et recherche autorisée. Tous les chiffres proviennent de la base (TAB-01, UX-03).
 */
class DashboardController extends Controller
{
    public function show(): JsonResponse
    {
        $this->authorizePermission('dashboard.view');
        $tz = config('saie.timezone');
        $user = $this->user();
        $dayStart = now($tz)->startOfDay()->utc();
        $dayEnd = now($tz)->endOfDay()->utc();

        // Parc hors écrans archivés (retirés du service, fiches conservées).
        // §4.1, §14-9 : état du parc et incidents bornés au périmètre (aucun écran sans droit de consultation du parc).
        $scope = $this->screenScopeFor('screens.view', 'supervision.view');
        $fleet = Screen::query()->with(['activeDevice', 'location.floor.building'])->where('status', '!=', 'archived')->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))->get();
        $open = Incident::query()->whereIn('status', ['open', 'acknowledged'])->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))->get(['device_id', 'screen_id']);
        $withIncident = $open->pluck('device_id')->merge($open->pluck('screen_id'))->filter()->unique()->values()->all();
        $stateOf = fn (Screen $s) => Present::operationalState($s, $s->activeDevice, $withIncident)['code'];
        $screens = $fleet->where('status', 'active');
        $statusOf = fn (Screen $s) => $s->activeDevice?->computedNetworkStatus() ?? 'unpaired';
        $byStatus = $screens->groupBy($statusOf)->map->count();
        $floors = Floor::query()->with('building')->orderByDesc('level')->get()->map(function (Floor $f) use ($screens, $statusOf): array {
            $items = $screens->filter(fn ($s) => $s->location->floor_id === $f->id);
            $online = $items->filter(fn ($s) => $statusOf($s) === 'online')->count();

            return ['id' => $f->id, 'label' => $f->label, 'building' => $f->building?->name, 'level' => $f->level, 'total' => $items->count(), 'online' => $online, 'state' => $items->isEmpty() ? 'empty' : ($online === $items->count() ? 'ok' : 'check')];
        })->filter(fn ($f) => $f['total'] > 0)->values();

        $tasks = $this->taskQuery()->get();
        $meetings = ReservationOccurrence::query()->with(['reservation', 'room.floor'])->whereIn('status', ['confirmed', 'in_progress', 'pending'])
            ->where('starts_at', '<', $dayEnd)->where('ends_at', '>', $dayStart)->orderBy('starts_at')->get();
        $rooms = Room::query()->where('status', 'active')->get();
        $busyNow = ReservationOccurrence::query()->whereIn('status', ['confirmed', 'in_progress'])->where('starts_at', '<=', now())->where('ends_at', '>', now())->pluck('room_id')->unique();
        $blockedNow = DB::table('room_blocks')->where('starts_at', '<=', now())->where('ends_at', '>', now())->pluck('room_id')->unique();

        $todayCampaigns = Campaign::query()->with(['targets'])->whereIn('status', ['active', 'scheduled'])->where('starts_at', '<', $dayEnd)->where('ends_at', '>', $dayStart)->orderByDesc('priority')->limit(8)->get();

        $data = [
            'generated_at' => now()->toIso8601String(), 'timezone' => $tz,
            'cards' => [
                'screens' => ['online' => $byStatus['online'] ?? 0, 'total' => $screens->count(), 'suspect' => $byStatus['suspect'] ?? 0, 'unavailable' => $byStatus['unavailable'] ?? 0, 'unpaired' => $byStatus['unpaired'] ?? 0],
                'campaigns' => ['active' => Campaign::query()->where('status', 'active')->count(), 'upcoming' => Campaign::query()->where('status', 'scheduled')->count()],
                'tasks' => ['total' => $tasks->count(), 'due_soon' => $tasks->where('due_at', '<', now()->addDay())->count(), 'overdue' => $tasks->where('due_at', '<', now())->count()],
                'rooms' => ['free' => $rooms->count() - $busyNow->merge($blockedNow)->unique()->intersect($rooms->pluck('id'))->count(), 'total' => $rooms->count(), 'meetings_today' => $meetings->where('status', '!=', 'pending')->count()],
                'alerts' => ['active' => Alert::query()->where('status', 'active')->where('starts_at', '<=', now())->where('expires_at', '>', now())->count()],
                'incidents' => ['open' => $open->count()],
            ],
            'floors' => $floors,
            // Parc complet par état opérationnel (désactivés et en maintenance compris) et par bâtiment.
            'fleet' => ['total' => $fleet->count(), 'by_state' => $fleet->groupBy($stateOf)->map->count()],
            'buildings' => $fleet->groupBy(fn (Screen $s) => $s->location->floor->building?->name ?? '—')->map(fn ($items, $name) => [
                'name' => $name, 'total' => $items->count(), 'reachable' => $items->filter(fn ($s) => $stateOf($s) === 'active')->count(),
            ])->sortBy('name')->values(),
            'rooms_equipped' => DB::table('room_equipment')->distinct()->count('room_id'),
            'pending_validation' => $this->can('contents.view') ? Approval::query()->where('status', 'open')->where('object_type', 'content')->count() : null,
            'default_window' => (array) Setting::effective('operating.default_window'),
            'last_contact' => Present::date(Device::query()->where('status', 'active')->max('last_seen_at') ? Carbon::parse(Device::query()->where('status', 'active')->max('last_seen_at')) : null),
            'tasks' => $tasks->take(5)->map(fn (Approval $a) => $this->presentTask($a))->values(),
            'meetings' => $meetings->where('status', '!=', 'pending')->take(6)->map(fn (ReservationOccurrence $o) => [
                'id' => $o->id, 'reservation_id' => $o->reservation_id, 'starts_at' => Present::date($o->starts_at), 'ends_at' => Present::date($o->ends_at),
                'label' => $o->reservation->confidential ? 'Réservée' : $o->reservation->subject, 'confidential' => $o->reservation->confidential,
                'room' => $o->room->name, 'participants' => $o->reservation->participant_count,
            ])->values(),
            'timeline' => $todayCampaigns->map(fn (Campaign $c) => [
                'id' => $c->id, 'name' => $c->name, 'priority' => $c->priority, 'status' => $c->status,
                'starts_at' => Present::date($c->starts_at->max($dayStart)), 'ends_at' => Present::date($c->ends_at->min($dayEnd)),
                'targets' => $c->targets->count(),
            ])->values(),
            'incidents' => Incident::query()->whereIn('status', ['open', 'acknowledged'])->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))->orderByRaw("case severity when 'critical' then 0 when 'major' then 1 else 2 end")->limit(4)->get(['id', 'reference', 'title', 'severity', 'status', 'detected_at']),
            'activity' => $this->can('audit.view') || $this->can('users.manage')
                ? DB::table('audit_logs')->orderByDesc('occurred_at')->limit(8)->get(['occurred_at', 'actor_label', 'action', 'object_label'])
                : DB::table('audit_logs')->where('actor_id', $user->id)->orderByDesc('occurred_at')->limit(8)->get(['occurred_at', 'actor_label', 'action', 'object_label']),
            'documents' => $this->can('documents.view') ? app(DocumentAccess::class)->scope(Document::query(), $user)->where('status', 'published')->orderByDesc('published_at')->limit(4)->get(['id', 'reference', 'title', 'classification', 'published_at']) : [],
            'my_reservations' => ReservationOccurrence::query()->with('room')->whereHas('reservation', fn ($q) => $q->where('organizer_id', $user->id))->where('ends_at', '>', now())->whereIn('status', ['pending', 'confirmed'])->orderBy('starts_at')->limit(4)->get()
                ->map(fn ($o) => ['id' => $o->id, 'reservation_id' => $o->reservation_id, 'room' => $o->room->name, 'starts_at' => Present::date($o->starts_at), 'status' => $o->status]),
            'tasks_without_backup' => $this->can('roles.manage') ? Approval::query()->where('status', 'open')->whereNull('backup_id')->count() : null,
        ];
        if ($this->can('reports.view')) {
            $data['indicators'] = app(ReportController::class)->indicators(now()->subDays(90), now());
        }

        return response()->json(['data' => $data]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $this->authorizePermission('dashboard.view');
        $filter = $request->query('filter', 'open');
        $approvals = $this->taskQuery()->get();
        $items = $approvals->map(fn (Approval $a) => $this->presentTask($a));

        $returned = ContentLanguage::query()->with('content')->where('status', 'returned')->whereHas('content', fn ($q) => $q->where('author_id', $this->user()->id)->whereNotIn('status', ['archived', 'expired']))->get()
            ->map(fn ($l) => ['id' => 'ret-'.$l->id, 'type' => 'content_return', 'object' => $l->content->title, 'reference' => $l->content->reference, 'lang' => $l->lang,
                'action' => 'Corriger la version '.strtoupper($l->lang), 'emitter' => null, 'due_at' => null, 'state' => 'to_correct', 'link' => "/affichage/contenus/{$l->content_id}/edition", 'reason' => $l->last_return_reason]);
        $services = $this->can('services.process') ? ServiceRequest::query()->with(['serviceType', 'occurrence.room', 'occurrence.reservation'])->whereIn('status', ['requested', 'clarification'])
            // SAL-08 : un pouvoir « prestations » limité à certains types ne voit que ces types dans ses tâches.
            ->when($this->access()->powerScope($this->user(), 'prestations'), fn ($q, $scope) => $q->whereHas('serviceType', fn ($t) => $t->whereIn('code', $scope['service_types'] ?? ['__none__'])))
            ->whereHas('occurrence', fn ($o) => $o->where('ends_at', '>', now()))->orderBy('due_at')->get()
            ->map(fn ($s) => ['id' => 'svc-'.$s->id, 'type' => 'service', 'object' => $s->serviceType->name.' · '.$s->occurrence->room->name, 'reference' => $s->occurrence->reservation->reference, 'lang' => null,
                'action' => 'Traiter la prestation', 'emitter' => null, 'due_at' => Present::date($s->due_at), 'state' => $s->due_at?->isPast() ? 'overdue' : 'open', 'link' => '/prestations']) : collect();

        // Collections de natures différentes (modèles et tableaux) : fusion sur des collections simples.
        $all = collect($items->all())->merge($returned->all())->merge($services->all())->values();
        $filtered = match ($filter) {
            'overdue' => $all->where('state', 'overdue'),
            'escalated' => $all->where('state', 'escalated'),
            default => $all,
        };
        $done = Approval::query()->where('decided_by', $this->user()->id)->where('decided_at', '>=', now()->startOfMonth())->count();

        return response()->json(['data' => $filtered->values(), 'meta' => ['counts' => ['open' => $all->count(), 'overdue' => $all->where('state', 'overdue')->count(), 'escalated' => $all->where('state', 'escalated')->count(), 'done_this_month' => $done]]]);
    }

    public function search(Request $request): JsonResponse
    {
        $raw = trim((string) $request->query('q', ''));
        if (mb_strlen($raw) < 2) {
            return response()->json(['data' => []]);
        }
        // Les jokers saisis (% et _) sont recherchés littéralement.
        $term = addcslashes($raw, '%_\\');
        $results = collect();
        if ($this->can('contents.view')) {
            $results = $results->merge(app(ContentAccess::class)->scope(Content::query(), $this->user())->where(fn ($q) => $q->where('title', 'ilike', "%$term%")->orWhere('reference', 'ilike', "%$term%"))->limit(5)->get()->map(fn ($c) => ['type' => 'content', 'label' => $c->title, 'sub' => $c->reference, 'link' => "/affichage/contenus/{$c->id}/edition"]));
        }
        if ($this->can('documents.view')) {
            $results = $results->merge(app(DocumentAccess::class)->scope(Document::query(), $this->user())->whereRaw("search_vector @@ websearch_to_tsquery('french', ?)", [$raw])->limit(5)->get()->map(fn ($d) => ['type' => 'document', 'label' => $d->title, 'sub' => $d->reference, 'link' => "/documents/{$d->id}"]));
        }
        if ($this->can('rooms.view')) {
            $results = $results->merge(Room::query()->where('status', 'active')->where('name', 'ilike', "%$term%")->limit(5)->get()->map(fn ($r) => ['type' => 'room', 'label' => $r->name, 'sub' => $r->capacity.' places', 'link' => "/salles/{$r->id}"]));
        }
        if ($this->can('screens.view')) {
            // HAB-01, DATA-01 : aucun écran hors périmètre dans les résultats.
            $scope = $this->screenScopeFor('screens.view');
            $results = $results->merge(Screen::query()->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))->where(fn ($q) => $q->where('code', 'ilike', "%$term%")->orWhere('name', 'ilike', "%$term%"))->limit(5)->get()->map(fn ($s) => ['type' => 'screen', 'label' => $s->code, 'sub' => $s->name, 'link' => "/affichage/ecrans/{$s->id}"]));
        }

        return response()->json(['data' => $results->values()]);
    }

    /**
     * Tâches ouvertes de l'utilisateur, y compris celles escaladées vers lui en tant que suppléant (HAB-04) ;
     * l'administrateur fonctionnel voit aussi les validations qu'aucun validateur habilité ne peut prendre.
     */
    private function taskQuery()
    {
        $id = $this->user()->id;

        $admin = $this->access()->hasRole($this->user(), 'admin_fonc');

        return Approval::query()->with(['requester', 'assignee'])->where('status', 'open')
            ->where(fn ($q) => $q->where('assignee_id', $id)->orWhere(fn ($w) => $w->where('backup_id', $id)->whereNotNull('escalated_at'))
                ->when($admin, fn ($w) => $w->orWhere(fn ($u) => $u->where('object_type', 'content')->whereNull('assignee_id'))))
            ->orderBy('due_at');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTask(Approval $a): array
    {
        $isContent = $a->object_type === 'content';
        if ($isContent && $a->assignee_id === null) {
            return [
                'id' => $a->id, 'type' => 'content_unassigned', 'object' => $a->title, 'lang' => $a->lang, 'revision' => $a->revision,
                'action' => 'Habiliter un validateur pour la version '.strtoupper((string) $a->lang), 'emitter' => $a->requester?->name, 'due_at' => Present::date($a->due_at),
                'escalated_at' => Present::date($a->escalated_at), 'state' => 'unassigned', 'as_backup' => false, 'link' => '/administration/utilisateurs',
            ];
        }

        return [
            'id' => $a->id, 'type' => $isContent ? 'content_validation' : 'reservation_approval', 'object' => $a->title, 'lang' => $a->lang, 'revision' => $a->revision,
            'action' => $isContent ? 'Valider la version '.strtoupper((string) $a->lang) : 'Approuver la réservation',
            'emitter' => $a->requester?->name, 'due_at' => Present::date($a->due_at), 'escalated_at' => Present::date($a->escalated_at),
            'state' => $a->escalated_at ? 'escalated' : ($a->due_at->isPast() ? 'overdue' : 'open'),
            'as_backup' => $a->assignee_id !== $this->user()->id,
            'link' => $isContent ? "/affichage/contenus/{$a->object_id}/validation?lang={$a->lang}" : "/reservations/{$a->object_id}",
        ];
    }
}
