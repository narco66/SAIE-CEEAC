<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Content;
use App\Models\ContentCategory;
use App\Models\Reservation;
use App\Models\ReservationOccurrence;
use App\Models\Room;
use App\Models\Template;
use App\Models\Visit;
use App\Services\ReservationService;
use App\Support\ActionLabel;
use App\Support\BusinessException;
use App\Support\Present;
use App\Support\Reference;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Réservations (V26 à V29, API-03) : contrôle SQL, idempotence, séries et confidentialité.
 */
class ReservationController extends Controller
{
    public function __construct(private ReservationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('reservations.create', 'reservations.approve', 'rooms.view');
        $user = $this->user();
        $scope = $request->query('scope', 'mine');
        $query = Reservation::query()->with(['organizer', 'structure', 'occurrences.room.floor', 'occurrences.serviceRequests.serviceType']);
        match ($scope) {
            'to_validate' => $query->where(fn ($w) => $w->whereHas('occurrences', fn ($q) => $q->where('status', 'pending')->whereHas('room', fn ($r) => $this->access()->hasRole($user, 'admin_fonc') ? $r : $r->where('manager_id', $user->id)))
                ->orWhereIn('id', Approval::query()->select('object_id')->where('object_type', 'reservation')->where('status', 'open')->whereNotNull('escalated_at')->where('backup_id', $user->id))),
            'managed' => $query->whereHas('occurrences.room', fn ($r) => $this->access()->hasRole($user, 'admin_fonc') ? $r : $r->where('manager_id', $user->id)),
            'welcome' => $query->where('confidential', false)->whereHas('occurrences', fn ($o) => $o->where('status', 'confirmed')->where('ends_at', '>', now())),
            default => $query->where('organizer_id', $user->id),
        };
        if ($scope === 'welcome' && ! $this->can('visits.manage')) {
            $this->deny('Liste réservée au Protocole');
        }
        if (! in_array($scope, ['mine', 'welcome'], true) && ! $this->can('reservations.approve')) {
            $this->deny('Liste réservée aux gestionnaires');
        }
        $when = $request->query('when');
        $query->when($when === 'future', fn ($q) => $q->whereHas('occurrences', fn ($o) => $o->where('ends_at', '>', now())))
            ->when($when === 'past', fn ($q) => $q->whereDoesntHave('occurrences', fn ($o) => $o->where('ends_at', '>', now())))
            ->when($when === 'recurring', fn ($q) => $q->whereNotNull('recurrence'))
            ->when($request->query('status'), fn ($q, $s) => $q->whereHas('occurrences', fn ($o) => $o->where('status', $s)))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('reference', 'ilike', "%$t%")->orWhere(fn ($s) => $s->where('confidential', false)->where('subject', 'ilike', "%$t%"))))
            ->orderByDesc('created_at')->orderByDesc('id');

        return $this->paginated($query, $request, function (Reservation $r): array {
            $full = $this->canSeeAll($r);
            $data = Present::reservation($r, $full);
            $approval = Approval::query()->where('object_type', 'reservation')->where('object_id', $r->id)->where('status', 'open')->first();
            $data['approval'] = $approval ? ['due_at' => Present::date($approval->due_at), 'escalated_at' => Present::date($approval->escalated_at)] : null;

            return $data;
        });
    }

    /**
     * Vérification préalable sans écriture : occurrences générées, conflits et règles appliquées (V27).
     */
    public function check(Request $request): JsonResponse
    {
        $this->authorizePermission('reservations.create');
        $data = $this->validated($request);
        $room = Room::query()->findOrFail($data['room_id']);
        $slots = $this->service->expand(CarbonImmutable::parse($data['start_at'])->utc(), CarbonImmutable::parse($data['end_at'])->utc(), $data['recurrence'] ?? null);
        $this->service->assertBookable($room, $slots, $this->user(), $data['services'] ?? []);
        $conflicts = $this->service->conflicts($room, $slots);
        $alternatives = [];
        if ($conflicts !== [] && count($slots) === 1) {
            [$s, $e] = $slots[0];
            $alternatives = Room::query()->with('floor')->where('status', 'active')->where('id', '!=', $room->id)->where('capacity', '>=', $data['participant_count'])->orderBy('capacity')->get()
                ->filter(fn (Room $r) => $this->service->conflicts($r, [[$s, $e]]) === [])->take(3)
                ->map(fn (Room $r) => ['room_id' => $r->id, 'name' => $r->name, 'floor' => $r->floor->label, 'capacity' => $r->capacity, 'start_at' => $s->toIso8601String(), 'end_at' => $e->toIso8601String()])->values()->all();
        }

        return response()->json(['data' => [
            'occurrences' => array_map(fn ($slot) => ['start_at' => $slot[0]->toIso8601String(), 'end_at' => $slot[1]->toIso8601String()], $slots),
            'conflicts' => $conflicts, 'alternatives' => $alternatives, 'approval_mode' => $room->approval_mode,
            'capacity_ok' => $data['participant_count'] <= $room->capacity,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('reservations.create');
        $data = $this->validated($request);
        $result = $this->service->create($data, $this->user());
        $reservation = $result['reservation']->load(['organizer', 'structure', 'occurrences.room.floor', 'occurrences.serviceRequests.serviceType', 'agendaItems']);

        return response()->json(['data' => Present::reservation($reservation, true), 'skipped' => $result['skipped']], 201);
    }

    public function show(Reservation $reservation): JsonResponse
    {
        $this->assertCanView($reservation);
        $reservation->load(['organizer', 'structure', 'occurrences.room.floor', 'occurrences.serviceRequests.serviceType', 'occurrences.resourceBookings.resource', 'occurrences.checklistItems', 'agendaItems', 'documents']);
        $full = $this->canSeeAll($reservation);
        $data = Present::reservation($reservation, $full);
        if ($full) {
            $data['documents'] = $reservation->documents->map(fn ($d) => ['id' => $d->id, 'reference' => $d->reference, 'title' => $d->title, 'classification' => $d->classification]);
            $data['resources'] = $reservation->occurrences->flatMap->resourceBookings->where('status', 'active')->map(fn ($b) => ['occurrence_id' => $b->occurrence_id, 'name' => $b->resource->name])->values();
            $data['welcome'] = Content::query()->whereIn('occurrence_id', $reservation->occurrences->pluck('id'))->where('kind', 'welcome')->get(['id', 'reference', 'title', 'status']);
            $data['history'] = DB::table('audit_logs')->where('object_type', 'Reservation')->where('object_id', $reservation->id)->orderByDesc('occurred_at')->limit(30)->get(['occurred_at', 'actor_label', 'action', 'reason'])
                ->map(fn ($h) => [...(array) $h, 'label' => ActionLabel::for($h->action)]);
        }
        $data['permissions'] = [
            'modify' => $reservation->organizer_id === $this->user()->id || $this->managesAny($reservation),
            'approve' => ($this->managesAny($reservation) || $this->service->isEscalatedBackup($reservation, $this->user())) && $reservation->occurrences->contains('status', 'pending'),
            'no_show' => $this->managesAny($reservation),
            'welcome' => $this->can('visits.manage') && ! $reservation->confidential,
        ];
        $data['presence'] = [
            'enabled' => $reservation->occurrences->contains(fn ($o) => $o->room?->auto_release_enabled),
            'codes' => $reservation->organizer_id === $this->user()->id ? $reservation->occurrences->mapWithKeys(fn ($o) => [$o->id => $o->check_in_code]) : (object) [],
            'checked_in' => $reservation->occurrences->mapWithKeys(fn ($o) => [$o->id => Present::date($o->checked_in_at)]),
        ];
        $data['external_notice'] = 'Les données des participants externes sont traitées selon les textes internes de la Commission de la CEEAC (GOV-02).';

        return response()->json(['data' => $data]);
    }

    public function approve(Reservation $reservation): JsonResponse
    {
        $this->service->approve($reservation, $this->user(), true, null);

        return $this->show($reservation->refresh());
    }

    public function refuse(Request $request, Reservation $reservation): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->service->approve($reservation, $this->user(), false, $data['reason']);

        return $this->show($reservation->refresh());
    }

    public function agenda(Request $request, Reservation $reservation): JsonResponse
    {
        if ($reservation->organizer_id !== $this->user()->id && ! $this->managesAny($reservation)) {
            $this->deny('Ordre du jour réservé à l’organisateur');
        }
        $data = $request->validate([
            'items' => ['present', 'array', 'max:30'], 'items.*.title' => ['required', 'string', 'max:200'], 'items.*.time_label' => ['nullable', 'string', 'max:20'], 'items.*.presenter' => ['nullable', 'string', 'max:120'],
            'document_ids' => ['sometimes', 'array'], 'document_ids.*' => ['uuid', 'exists:documents,id'],
        ]);
        DB::transaction(function () use ($reservation, $data): void {
            $reservation->agendaItems()->delete();
            foreach ($data['items'] as $i => $item) {
                $reservation->agendaItems()->create(['position' => $i + 1] + $item);
            }
            if (isset($data['document_ids'])) {
                $reservation->documents()->sync($data['document_ids']);
            }
        });
        $this->audit()->log('reservation.agenda_updated', $reservation);

        return $this->show($reservation->refresh());
    }

    /**
     * Message de bienvenue en un clic pour une réservation confirmée et non confidentielle (ACC-02, P09).
     */
    public function welcome(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorizePermission('visits.manage');
        $data = $request->validate([
            'occurrence_id' => ['required', 'uuid'], 'delegation' => ['required', 'string', 'max:160'], 'institution' => ['nullable', 'string', 'max:160'],
            'visitors_count' => ['nullable', 'integer', 'min:1'], 'languages' => ['required', 'array', 'min:1'], 'languages.*' => ['in:fr,en,pt,es'],
            'flags' => ['nullable', 'array'], 'flags.*' => ['string', 'size:2'],
        ]);
        if ($reservation->confidential) {
            throw new BusinessException('CONFIDENTIAL_MEETING', 'Aucun message de bienvenue n’est possible pour une réunion confidentielle.', 422);
        }
        $occurrence = $reservation->occurrences()->with('room.floor')->findOrFail($data['occurrence_id']);
        if ($occurrence->status !== 'confirmed') {
            throw new BusinessException('RESERVATION_NOT_CONFIRMED', 'Le message de bienvenue exige une réservation confirmée.', 422);
        }
        $texts = [
            'fr' => ['title' => 'Bienvenue à la '.$data['delegation'], 'body' => 'La Commission de la CEEAC vous souhaite la bienvenue. '.$occurrence->room->name.' · '.$occurrence->room->floor->label],
            'en' => ['title' => 'Welcome to the '.$data['delegation'], 'body' => 'The ECCAS Commission welcomes you. '.$occurrence->room->name.' · '.$occurrence->room->floor->label],
            'pt' => ['title' => 'Bem-vindos, '.$data['delegation'], 'body' => 'A Comissão da CEEAC dá-vos as boas-vindas.'],
            'es' => ['title' => 'Bienvenidos, '.$data['delegation'], 'body' => 'La Comisión de la CEEAC les da la bienvenida.'],
        ];
        $content = DB::transaction(function () use ($data, $occurrence, $texts, $reservation): Content {
            $content = Content::query()->create([
                'reference' => Reference::next('CNT'), 'title' => 'Bienvenue — '.$data['delegation'], 'kind' => 'welcome',
                'category_id' => ContentCategory::query()->where('code', 'ACCUEIL')->value('id') ?? ContentCategory::query()->value('id'),
                'owner_structure_id' => $this->user()->structure_id, 'author_id' => $this->user()->id, 'classification' => 'public', 'priority' => 70,
                'template_id' => Template::query()->where('purpose', 'welcome')->where('status', 'active')->value('id'),
                'valid_from' => $occurrence->starts_at->copy()->subHours(2), 'expires_at' => $occurrence->ends_at, 'status' => 'draft', 'occurrence_id' => $occurrence->id,
                'delegation' => ['name' => $data['delegation'], 'institution' => $data['institution'] ?? null, 'flags' => $data['flags'] ?? [], 'languages' => $data['languages']],
            ]);
            foreach ($data['languages'] as $lang) {
                $content->languages()->create(['lang' => $lang, 'title' => $texts[$lang]['title'], 'body' => $texts[$lang]['body'], 'status' => 'draft']);
            }
            Visit::query()->create([
                'reference' => Reference::next('VIS'), 'name' => $data['delegation'], 'institution' => $data['institution'] ?? null, 'visitors_count' => $data['visitors_count'] ?? $reservation->participant_count,
                'occurrence_id' => $occurrence->id, 'content_id' => $content->id, 'languages' => $data['languages'], 'flags' => $data['flags'] ?? [], 'status' => 'message_draft', 'created_by' => $this->user()->id,
            ]);
            $this->audit()->log('welcome.created', $content, after: ['reservation' => $reservation->reference]);

            return $content;
        });

        return response()->json(['data' => ['content_id' => $content->id, 'reference' => $content->reference]], 201);
    }

    public function modify(Request $request, ReservationOccurrence $occurrence): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['sometimes', 'uuid', 'exists:rooms,id'], 'start_at' => ['sometimes', 'date'], 'end_at' => ['sometimes', 'date'],
            'participant_count' => ['sometimes', 'integer', 'min:1'], 'subject' => ['sometimes', 'string', 'max:200'],
            'reason' => ['nullable', 'string', 'max:500'], 'expected_version' => ['nullable', 'integer'], 'capacity_override_reason' => ['nullable', 'string', 'max:300'],
        ]);
        $this->expectVersion($request, $occurrence->version);
        $updated = $this->service->modify($occurrence, $data, $this->user(), $data['reason'] ?? null);
        $this->syncWelcome($updated);

        return $this->show($updated->reservation);
    }

    public function cancel(Request $request, ReservationOccurrence $occurrence): JsonResponse
    {
        $data = $request->validate(['scope' => ['required', 'in:this,following,all'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $count = $this->service->cancel($occurrence, $data['scope'], $this->user(), $data['reason']);
        // Les messages de bienvenue de toutes les occurrences annulées (« cette occurrence », « les suivantes » ou « toutes ») sont retirés.
        $cancelled = $occurrence->reservation->occurrences()->where('status', 'cancelled')->pluck('id');
        Content::query()->whereIn('occurrence_id', $cancelled)->where('kind', 'welcome')->where('status', '!=', 'archived')->get()
            ->each(fn (Content $c) => $c->forceFill(['status' => 'archived', 'archived_at' => now()])->save());

        return response()->json(['cancelled' => $count] + $this->show($occurrence->reservation)->getData(true));
    }

    /**
     * Confirmation de présence (SAL-10) : organisateur authentifié ou code temporaire communiqué à l'organisateur.
     */
    public function checkIn(Request $request, ReservationOccurrence $occurrence): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:8']]);
        $this->service->checkIn($occurrence, $this->user(), $data['code'] ?? null);

        return $this->show($occurrence->reservation);
    }

    /**
     * Export iCalendar d'une réservation (SAL-11) : une réunion confidentielle reste « Réservée » pour les tiers.
     */
    public function ics(Reservation $reservation): Response
    {
        $this->assertCanView($reservation);
        $occurrences = $reservation->occurrences()->with(['reservation', 'room.floor'])->orderBy('starts_at')->get();

        return response($this->service->ics($occurrences, $this->user(), $reservation->reference), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$reservation->reference.'.ics"', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function noShow(Request $request, ReservationOccurrence $occurrence): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->service->declareNoShow($occurrence, $this->user(), $data['reason']);

        return $this->show($occurrence->reservation);
    }

    /**
     * Une réunion déplacée entraîne la recompilation du message de bienvenue et de ses dates (ACC-02).
     */
    private function syncWelcome(ReservationOccurrence $occurrence): void
    {
        foreach (Content::query()->where('occurrence_id', $occurrence->id)->where('kind', 'welcome')->get() as $content) {
            $content->forceFill(['expires_at' => $occurrence->ends_at, 'valid_from' => $occurrence->starts_at->copy()->subHours(2)])->save();
            DB::table('campaigns')->whereIn('id', DB::table('campaign_items')->where('content_id', $content->id)->pluck('campaign_id'))
                ->update(['starts_at' => $occurrence->starts_at->copy()->subHours(2), 'ends_at' => $occurrence->ends_at, 'updated_at' => now()]);
        }
    }

    private function managesAny(Reservation $reservation): bool
    {
        return $reservation->occurrences()->with('room')->get()->contains(fn ($o) => $this->service->manages($o->room, $this->user()));
    }

    private function canSeeAll(Reservation $reservation): bool
    {
        return $reservation->organizer_id === $this->user()->id || $this->managesAny($reservation) || $this->service->isEscalatedBackup($reservation, $this->user());
    }

    private function assertCanView(Reservation $reservation): void
    {
        if (! $this->canSeeAll($reservation) && ! $this->can('rooms.view')) {
            abort(404);
        }
        if (! $this->canSeeAll($reservation) && $reservation->confidential) {
            abort(404);
        }
    }

    /**
     * Contrat de POST /reservations (API-03).
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'room_id' => ['required', 'uuid', 'exists:rooms,id'], 'start_at' => ['required', 'date'], 'end_at' => ['required', 'date', 'after:start_at'],
            'confidentiality' => ['required', 'boolean'], 'subject' => ['required', 'string', 'max:200'], 'participant_count' => ['required', 'integer', 'min:1', 'max:5000'],
            'description' => ['nullable', 'string', 'max:2000'], 'is_priority' => ['sometimes', 'boolean'],
            'internal_participants' => ['nullable', 'array', 'max:200'], 'internal_participants.*' => ['uuid', 'exists:users,id'],
            'external_participants' => ['nullable', 'array', 'max:200'], 'external_participants.*.name' => ['required', 'string', 'max:120'], 'external_participants.*.organization' => ['nullable', 'string', 'max:160'],
            'recurrence' => ['nullable', 'array'], 'recurrence.freq' => ['required_with:recurrence', 'in:none,daily,weekly,monthly'], 'recurrence.interval' => ['nullable', 'integer', 'between:1,12'],
            'recurrence.until' => ['nullable', 'date'], 'recurrence.count' => ['nullable', 'integer', 'between:1,100'], 'recurrence.byweekday' => ['nullable', 'array'], 'recurrence.byweekday.*' => ['integer', 'between:1,7'],
            'recurrence_mode' => ['nullable', 'in:all_or_nothing,partial'],
            'resource_ids' => ['nullable', 'array'], 'resource_ids.*' => ['uuid', 'exists:mobile_resources,id'],
            'services' => ['nullable', 'array'], 'services.*.service_type_id' => ['required', 'uuid', 'exists:service_types,id'], 'services.*.details' => ['nullable', 'string', 'max:500'],
            'services.*.headcount' => ['nullable', 'integer', 'between:1,5000'], 'services.*.serve_time' => ['nullable', 'date_format:H:i'],
            'agenda' => ['nullable', 'array'], 'agenda.*.title' => ['required', 'string', 'max:200'], 'agenda.*.time_label' => ['nullable', 'string', 'max:20'], 'agenda.*.presenter' => ['nullable', 'string', 'max:120'],
            'capacity_override_reason' => ['nullable', 'string', 'max:300'],
        ]);
    }
}
