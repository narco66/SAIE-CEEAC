<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileResource;
use App\Models\ReservationOccurrence;
use App\Models\ResourceBooking;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\Setting;
use App\Services\Notifier;
use App\Services\ReservationService;
use App\Support\BusinessException;
use App\Support\Present;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Catalogue des salles (V30, V31), disponibilités (SAL-02) et calendrier (V25).
 */
class RoomController extends Controller
{
    public function __construct(private ReservationService $reservations, private Notifier $notifier) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('rooms.view');
        $query = Room::query()->with(['floor', 'manager', 'equipment'])
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('status', 'active'))
            ->when($request->query('floor_id'), fn ($q, $f) => $q->where('floor_id', $f))
            ->when($request->integer('capacity'), fn ($q, $c) => $q->where('capacity', '>=', $c))
            ->when($request->query('equipment'), fn ($q, $codes) => collect(explode(',', $codes))->each(fn ($code) => $q->whereHas('equipment', fn ($e) => $e->where('code', $code))))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'ilike', "%$t%")->orWhere('code', 'ilike', "%$t%")->orWhereHas('equipment', fn ($e) => $e->where('name', 'ilike', "%$t%"))))
            ->orderBy('name');
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after:from']]);
        $from = $request->query('from') ? CarbonImmutable::parse($request->query('from'))->utc() : null;
        $to = $request->query('to') ? CarbonImmutable::parse($request->query('to'))->utc() : null;

        return $this->paginated($query, $request, function (Room $room) use ($from, $to): array {
            $data = Present::room($room);
            $data['availability'] = $this->availabilityLabel($room, $from, $to);
            $data['can_manage'] = $this->reservations->manages($room, $this->user());

            return $data;
        }, 50);
    }

    public function show(Room $room): JsonResponse
    {
        $this->authorizePermission('rooms.view');
        $room->load(['floor', 'manager', 'equipment', 'blocks' => fn ($q) => $q->where('ends_at', '>', now())->orderBy('starts_at')]);
        $data = Present::room($room);
        $data['blocks'] = $room->blocks->map(fn ($b) => ['id' => $b->id, 'starts_at' => Present::date($b->starts_at), 'ends_at' => Present::date($b->ends_at), 'kind' => $b->kind, 'reason' => $b->reason]);
        $data['can_manage'] = $this->reservations->manages($room, $this->user());
        $data['availability'] = $this->availabilityLabel($room, null, null);
        $data['screens'] = $room->screens()->get(['id', 'code', 'name']);

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('rooms.manage');
        if (! $this->access()->hasRole($this->user(), 'admin_fonc')) {
            $this->deny('Création de salle réservée à l’administrateur fonctionnel');
        }
        $data = $this->validated($request);
        $room = Room::query()->create(collect($data)->except(['equipment', 'confirm_affected'])->all() + [
            'pending_hold_hours' => (int) Setting::effective('rooms.pending_hold_hours'), 'auto_release_enabled' => (bool) Setting::effective('rooms.no_show_auto'),
        ])->refresh();
        $room->equipment()->sync(collect($data['equipment'] ?? [])->mapWithKeys(fn ($e) => [$e['id'] => ['quantity' => $e['quantity'] ?? 1]]));
        $this->audit()->log('room.created', $room);

        return $this->show($room)->setStatusCode(201);
    }

    /**
     * Une réduction de capacité signale les réservations futures affectées (V31).
     */
    public function update(Request $request, Room $room): JsonResponse
    {
        $this->reservations->assertManages($room, $this->user());
        $this->expectVersion($request, $room);
        $data = $this->validated($request, $room);
        $affected = [];
        if (isset($data['capacity']) && $data['capacity'] < $room->capacity) {
            $affected = ReservationOccurrence::query()->with('reservation')->where('room_id', $room->id)->whereIn('status', ReservationService::ACTIVE)->where('starts_at', '>', now())
                ->whereHas('reservation', fn ($q) => $q->where('participant_count', '>', $data['capacity']))->get()
                ->map(fn ($o) => ['reference' => $o->reservation->reference, 'starts_at' => Present::date($o->starts_at), 'participants' => $o->reservation->participant_count])->all();
            if ($affected !== [] && ! $request->boolean('confirm_affected')) {
                throw new BusinessException('CAPACITY_AFFECTS_BOOKINGS', 'Cette réduction de capacité affecte des réservations futures. Confirmez pour continuer.', 409, ['affected' => $affected]);
            }
        }
        // V31, SAL-07 : mise hors service, horaires réduits ou tampons allongés touchent aussi des réservations futures.
        $impacted = $this->impactedByRuleChange($room, $data);
        if ($impacted->isNotEmpty() && ! $request->boolean('confirm_affected')) {
            throw new BusinessException('CHANGE_AFFECTS_BOOKINGS', 'Cette modification touche '.$impacted->count().' réservation(s) future(s). Confirmez pour continuer : leurs organisateurs seront prévenus.', 409, [
                'affected' => $impacted->map(fn ($o) => ['reference' => $o->reservation->reference, 'starts_at' => Present::date($o->starts_at), 'participants' => $o->reservation->participant_count])->values()->all(),
            ]);
        }
        $before = $room->only(array_keys($data));
        $room->update(collect($data)->except(['equipment', 'confirm_affected'])->all() + ['version' => $room->version + 1]);
        if (isset($data['equipment'])) {
            $room->equipment()->sync(collect($data['equipment'])->mapWithKeys(fn ($e) => [$e['id'] => ['quantity' => $e['quantity'] ?? 1]]));
        }
        // Les tampons modifiés s'appliquent aux réservations futures : les intervalles bloquants sont recalculés.
        if (isset($data['prep_minutes']) || isset($data['cleanup_minutes'])) {
            ReservationOccurrence::query()->where('room_id', $room->id)->whereIn('status', ReservationService::ACTIVE)->where('starts_at', '>', now())->get()
                ->each(fn (ReservationOccurrence $o) => $o->forceFill(['blocking_start' => $o->starts_at->copy()->subMinutes($room->prep_minutes), 'blocking_end' => $o->ends_at->copy()->addMinutes($room->cleanup_minutes)])->save());
        }
        foreach ($impacted->groupBy('reservation_id') as $occurrences) {
            $reservation = $occurrences->first()->reservation;
            $this->notifier->notify($reservation->organizer, 'reservation.room_changed', 'Les règles de la salle ont changé', $room->name.' : vérifiez votre réservation '.$reservation->reference, "/reservations/{$reservation->id}", 'reservation', $reservation->id, 'room-change-'.$room->id.'-'.$room->version);
        }
        $this->audit()->log('room.updated', $room, before: $before, after: collect($data)->except(['equipment', 'confirm_affected'])->all() + ($impacted->isNotEmpty() ? ['impacted_reservations' => $impacted->count()] : []));

        return response()->json(['data' => Present::room($room->load(['floor', 'manager', 'equipment'])), 'affected' => $affected]);
    }

    public function availability(Request $request, Room $room): JsonResponse
    {
        $this->authorizePermission('rooms.view');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from']]);
        $from = CarbonImmutable::parse($data['from'])->utc();
        $to = CarbonImmutable::parse($data['to'])->utc();
        if ($from->diffInDays($to, true) > 62) {
            throw new BusinessException('RANGE_TOO_LARGE', 'Période limitée à deux mois.', 422);
        }

        return response()->json(['data' => [
            'room' => ['id' => $room->id, 'name' => $room->name, 'open_from' => substr($room->open_from, 0, 5), 'open_to' => substr($room->open_to, 0, 5), 'prep_minutes' => $room->prep_minutes, 'cleanup_minutes' => $room->cleanup_minutes, 'min_minutes' => $room->min_minutes, 'max_minutes' => $room->max_minutes],
            'busy' => $this->reservations->busy($room, $from, $to, $this->user()),
            'rules' => ['interval' => '[début, fin[', 'buffers_included' => true, 'timezone' => config('saie.timezone')],
        ]]);
    }

    /**
     * Calendrier multisalle jour/semaine/mois (V25) ; les réunions confidentielles apparaissent « Réservée ».
     */
    public function calendar(Request $request): JsonResponse
    {
        $this->authorizePermission('rooms.view');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from'], 'floor_id' => ['nullable', 'uuid'], 'capacity' => ['nullable', 'integer'], 'equipment' => ['nullable', 'string', 'max:200']]);
        $from = CarbonImmutable::parse($data['from'])->utc();
        $to = CarbonImmutable::parse($data['to'])->utc();
        // Période bornée comme la disponibilité : une requête sur plusieurs années et toutes les salles serait un déni de service.
        if ($from->diffInDays($to) > 62) {
            throw new BusinessException('PERIOD_TOO_LONG', 'La période du calendrier est limitée à 62 jours.', 422);
        }
        $rooms = Room::query()->with('floor')->where('status', 'active')
            ->when($data['floor_id'] ?? null, fn ($q, $f) => $q->where('floor_id', $f))
            ->when($data['capacity'] ?? null, fn ($q, $c) => $q->where('capacity', '>=', $c))
            ->when($data['equipment'] ?? null, fn ($q, $codes) => collect(explode(',', $codes))->filter()->each(fn ($code) => $q->whereHas('equipment', fn ($e) => $e->where('code', $code))))
            ->orderBy('name')->get();

        return response()->json(['data' => $rooms->map(fn (Room $room) => [
            'room' => ['id' => $room->id, 'name' => $room->name, 'capacity' => $room->capacity, 'floor' => $room->floor->label, 'open_from' => substr($room->open_from, 0, 5), 'open_to' => substr($room->open_to, 0, 5)],
            'busy' => $this->reservations->busy($room, $from, $to, $this->user()),
        ])]);
    }

    /**
     * Abonnement iCalendar d'une salle sur 8 semaines (SAL-11 sans connecteur) ; réunions confidentielles « Réservée ».
     */
    public function ics(Room $room): Response
    {
        $this->authorizePermission('rooms.view');
        $occurrences = ReservationOccurrence::query()->with(['reservation', 'room.floor'])->where('room_id', $room->id)
            ->whereIn('status', ReservationService::ACTIVE)->whereBetween('starts_at', [now()->subWeek(), now()->addWeeks(8)])->orderBy('starts_at')->get();

        return response($this->reservations->ics($occurrences, $this->user(), $room->name), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => HeaderUtils::makeDisposition('attachment', Str::slug($room->code).'.ics'), 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function block(Request $request, Room $room): JsonResponse
    {
        $this->reservations->assertManages($room, $this->user());
        $data = $request->validate(['starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at', 'after:now'], 'kind' => ['required', 'in:technical,institutional'], 'reason' => ['required', 'string', 'min:5', 'max:300']], ['ends_at.after' => 'La fin de la fermeture doit être postérieure au début et à maintenant.']);
        $impacted = ReservationOccurrence::query()->with(['reservation:id,reference,organizer_id', 'reservation.organizer'])->where('room_id', $room->id)->whereIn('status', ReservationService::ACTIVE)
            ->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->orderBy('starts_at')->get();
        $block = $room->blocks()->create($data + ['created_by' => $this->user()->id]);
        $this->audit()->log('room.blocked', $room, $data['reason'], after: $data);

        $references = $impacted->pluck('reservation.reference')->unique()->values();
        // SAL-07, NOT-01 : chaque organisateur touché est prévenu ; il déplace ou annule depuis sa fiche.
        foreach ($impacted->groupBy('reservation_id') as $occurrences) {
            $reservation = $occurrences->first()->reservation;
            $this->notifier->notify($reservation->organizer, 'reservation.blocked', 'Salle fermée sur votre réservation', $room->name.' : '.$data['reason'], "/reservations/{$reservation->id}", 'reservation', $reservation->id, 'block-'.$block->id);
        }
        $message = $impacted->isEmpty() ? null : $impacted->count().' réservation(s) existante(s) chevauchent cette fermeture ('.$references->take(5)->implode(', ').($references->count() > 5 ? '…' : '').') : leurs organisateurs ont été prévenus et peuvent les déplacer ou les annuler depuis leur fiche.';

        return response()->json(['data' => $block, 'impacted_reservations' => $impacted->count(), 'message' => $message], 201);
    }

    public function unblock(Request $request, RoomBlock $block): JsonResponse
    {
        $this->reservations->assertManages($block->room, $this->user());
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        // SAL-07, §16 : une fermeture terminée fait partie de l'historique et n'est plus retirée.
        if ($block->ends_at->isPast()) {
            throw new BusinessException('BLOCK_ENDED', 'Cette fermeture est terminée : elle reste dans l’historique de la salle.', 409);
        }
        $this->audit()->log('room.unblocked', $block->room, $data['reason'], before: ['starts_at' => Present::date($block->starts_at), 'ends_at' => Present::date($block->ends_at), 'kind' => $block->kind, 'reason' => $block->reason]);
        $block->delete();

        return response()->json(null, 204);
    }

    public function resources(Request $request): JsonResponse
    {
        $this->authorizePermission('rooms.view');
        $request->validate(['status' => ['nullable', 'in:active,inactive,all'], 'q' => ['nullable', 'string', 'max:100']]);
        // Par défaut, seules les ressources actives sont proposées à la réservation ; la gestion demande « all ».
        $status = $request->query('status', 'active');

        return response()->json(['data' => MobileResource::query()->with('manager')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'ilike', '%'.addcslashes($t, '%_\\').'%')->orWhere('code', 'ilike', '%'.addcslashes($t, '%_\\').'%')))
            ->orderBy('name')->get()->map(fn (MobileResource $r) => $this->presentResource($r))]);
    }

    public function storeResource(Request $request): JsonResponse
    {
        $this->authorizePermission('rooms.manage');
        $data = $request->validate(['code' => ['required', 'string', 'max:40', 'unique:mobile_resources,code'], 'name' => ['required', 'string', 'max:120'], 'type' => ['required', 'string', 'max:40'], 'manager_id' => ['nullable', 'uuid', Rule::exists('users', 'id')->where('status', 'active')]]);
        $resource = MobileResource::query()->create($data);
        $this->audit()->log('resource.created', $resource, after: $data);

        return response()->json(['data' => $this->presentResource($resource->load('manager'))], 201);
    }

    /**
     * REF-01, SAL-13 : modification et mise hors service (jamais de suppression : l'historique des réservations la référence).
     */
    public function updateResource(Request $request, MobileResource $resource): JsonResponse
    {
        $this->authorizePermission('rooms.manage');
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('mobile_resources', 'code')->ignore($resource->id)], 'name' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', 'string', 'max:40'], 'manager_id' => ['nullable', 'uuid', Rule::exists('users', 'id')->where('status', 'active')],
            'status' => ['sometimes', 'in:active,inactive'], 'reason' => ['nullable', 'string', 'max:300'],
        ]);
        $changes = collect($data)->except('reason')->all();
        if (($changes['status'] ?? null) === 'inactive' && $resource->status === 'active') {
            if (mb_strlen(trim((string) ($data['reason'] ?? ''))) < 5) {
                throw new BusinessException('REASON_REQUIRED', 'Le motif de la mise hors service est obligatoire.', 422);
            }
            $future = ResourceBooking::query()->where('resource_id', $resource->id)->where('status', 'active')->where('blocking_end', '>', now())->count();
            if ($future > 0 && ! $request->boolean('confirm_affected')) {
                throw new BusinessException('CHANGE_AFFECTS_BOOKINGS', "Cette ressource est réservée pour $future réunion(s) à venir. Confirmez pour la mettre hors service : les réservations existantes sont conservées.", 409, ['affected' => $future]);
            }
        }
        $before = $resource->only(array_keys($changes));
        $resource->update($changes);
        $this->audit()->log('resource.updated', $resource, $data['reason'] ?? null, before: $before, after: $changes);

        return response()->json(['data' => $this->presentResource($resource->load('manager'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentResource(MobileResource $resource): array
    {
        return ['id' => $resource->id, 'code' => $resource->code, 'name' => $resource->name, 'type' => $resource->type, 'status' => $resource->status, 'manager_id' => $resource->manager_id, 'manager' => Present::userRef($resource->manager)];
    }

    /**
     * Réservations futures qu'une mise hors service, une réduction des horaires ou un allongement des tampons rendrait invalides.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, ReservationOccurrence>
     */
    private function impactedByRuleChange(Room $room, array $data): Collection
    {
        $deactivated = ($data['status'] ?? $room->status) === 'inactive' && $room->status === 'active';
        $from = isset($data['open_from']) ? $data['open_from'] : substr($room->open_from, 0, 5);
        $to = isset($data['open_to']) ? $data['open_to'] : substr($room->open_to, 0, 5);
        $hoursReduced = $from > substr($room->open_from, 0, 5) || $to < substr($room->open_to, 0, 5);
        $prep = (int) ($data['prep_minutes'] ?? $room->prep_minutes);
        $cleanup = (int) ($data['cleanup_minutes'] ?? $room->cleanup_minutes);
        $buffersLonger = $prep > $room->prep_minutes || $cleanup > $room->cleanup_minutes;
        if (! $deactivated && ! $hoursReduced && ! $buffersLonger) {
            return collect();
        }
        $tz = config('saie.timezone');
        $future = ReservationOccurrence::query()->with('reservation.organizer')->where('room_id', $room->id)->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>', now())->orderBy('starts_at')->get();

        return $future->filter(function (ReservationOccurrence $o) use ($deactivated, $from, $to, $prep, $cleanup, $buffersLonger, $future, $tz): bool {
            if ($deactivated) {
                return true;
            }
            $start = $o->starts_at->copy()->setTimezone($tz)->format('H:i');
            $end = $o->ends_at->copy()->setTimezone($tz)->format('H:i');
            if ($start < $from || $end > $to) {
                return true;
            }
            if (! $buffersLonger) {
                return false;
            }
            $bs = $o->starts_at->copy()->subMinutes($prep);
            $be = $o->ends_at->copy()->addMinutes($cleanup);

            // Tampons allongés : chevauchement avec l'intervalle bloquant d'une autre réservation de la salle.
            return $future->contains(fn (ReservationOccurrence $other) => $other->id !== $o->id && $other->starts_at->copy()->subMinutes($prep) < $be && $other->ends_at->copy()->addMinutes($cleanup) > $bs);
        })->values();
    }

    /**
     * @return array{state: string, label: string}
     */
    private function availabilityLabel(Room $room, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if ($room->status !== 'active') {
            return ['state' => 'unavailable', 'label' => 'Indisponible'];
        }
        $tz = config('saie.timezone');
        if ($from && $to) {
            $conflict = $this->reservations->conflicts($room, [[$from, $to]]);

            return $conflict === [] ? ['state' => 'free', 'label' => 'Disponible sur le créneau'] : ['state' => 'busy', 'label' => 'Occupée sur le créneau'];
        }
        $now = CarbonImmutable::now();
        $block = $room->blocks()->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->first();
        if ($block) {
            return ['state' => 'unavailable', 'label' => 'Indisponible jusqu’à '.$block->ends_at->setTimezone($tz)->format('H:i')];
        }
        $current = ReservationOccurrence::query()->where('room_id', $room->id)->whereIn('status', ['confirmed', 'in_progress'])->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->first();
        if ($current) {
            return ['state' => 'busy', 'label' => 'Occupée jusqu’à '.$current->ends_at->setTimezone($tz)->format('H:i')];
        }
        $next = ReservationOccurrence::query()->where('room_id', $room->id)->whereIn('status', ReservationService::ACTIVE)->where('starts_at', '>', $now)->where('starts_at', '<', now($tz)->endOfDay())->orderBy('starts_at')->first();

        return $next ? ['state' => 'free', 'label' => 'Libre jusqu’à '.$next->starts_at->setTimezone($tz)->format('H:i')] : ['state' => 'free', 'label' => 'Libre toute la journée'];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Room $room = null): array
    {
        $req = $room ? 'sometimes' : 'required';
        $data = $this->validateRoomFields($request, $room, $req);
        // Une modification partielle est recoupée avec les valeurs en place (sinon contrainte SQL et erreur 500).
        $value = fn (string $field, mixed $default = null): mixed => array_key_exists($field, $data) ? $data[$field] : ($room ? (in_array($field, ['open_from', 'open_to'], true) ? substr((string) $room->{$field}, 0, 5) : $room->{$field}) : $default);
        $errors = [];
        if ($value('open_to', '19:00') <= $value('open_from', '07:30')) {
            $errors['open_to'] = 'L’heure de fermeture doit suivre l’heure d’ouverture ('.$value('open_from', '07:30').').';
        }
        if ((int) $value('max_minutes', 600) < (int) $value('min_minutes', 30)) {
            $errors['max_minutes'] = 'La durée maximale doit être au moins égale à la durée minimale ('.$value('min_minutes', 30).' min).';
        }
        // §4 : une salle à validation manuelle a un gestionnaire désigné, destinataire des demandes.
        if ($value('approval_mode', 'auto') === 'manual' && ! $value('manager_id')) {
            $errors['manager_id'] = 'Désignez le gestionnaire de la salle : il reçoit les demandes à valider.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRoomFields(Request $request, ?Room $room, string $req): array
    {
        return $request->validate([
            'code' => [$req, 'string', 'max:40', Rule::unique('rooms', 'code')->ignore($room?->id)], 'name' => [$req, 'string', 'max:120'],
            'floor_id' => [$req, 'uuid', 'exists:floors,id'], 'capacity' => [$req, 'integer', 'min:1', 'max:2000'], 'location_text' => ['nullable', 'string', 'max:200'],
            'manager_id' => ['nullable', 'uuid', Rule::exists('users', 'id')->where('status', 'active')], 'approval_mode' => ['sometimes', 'in:auto,manual'],
            // §6-a : rattachement facultatif de la salle à un emplacement du référentiel.
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
            // max ≥ min est contrôlé dans validated() contre la valeur enregistrée : « gte:min_minutes » refusait toute
            // modification de la seule durée maximale (champ de comparaison absent de la requête).
            'min_minutes' => ['sometimes', 'integer', 'between:15,600'], 'max_minutes' => ['sometimes', 'integer', 'between:15,720'],
            'lead_days' => ['sometimes', 'integer', 'between:1,365'], 'prep_minutes' => ['sometimes', 'integer', 'between:0,240'], 'cleanup_minutes' => ['sometimes', 'integer', 'between:0,240'],
            // Fermeture après ouverture : contrôlée dans validated() contre la valeur enregistrée (même raison que ci-dessus).
            'open_from' => ['sometimes', 'date_format:H:i'], 'open_to' => ['sometimes', 'date_format:H:i'],
            'pending_hold_hours' => ['sometimes', 'integer', 'between:1,168'], 'auto_release_enabled' => ['sometimes', 'boolean'], 'status' => ['sometimes', 'in:active,inactive'],
            'equipment' => ['sometimes', 'array'], 'equipment.*.id' => ['required', 'uuid', 'exists:equipment,id'], 'equipment.*.quantity' => ['nullable', 'integer', 'min:1'],
            'confirm_affected' => ['sometimes', 'boolean'],
        ]);
    }
}
