<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileResource;
use App\Models\ReservationOccurrence;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Services\ReservationService;
use App\Support\BusinessException;
use App\Support\Present;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Catalogue des salles (V30, V31), disponibilités (SAL-02) et calendrier (V25).
 */
class RoomController extends Controller
{
    public function __construct(private ReservationService $reservations) {}

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
        $room = Room::query()->create(collect($data)->except(['equipment', 'confirm_affected'])->all());
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
        $before = $room->only(array_keys($data));
        $room->update(collect($data)->except(['equipment', 'confirm_affected'])->all());
        if (isset($data['equipment'])) {
            $room->equipment()->sync(collect($data['equipment'])->mapWithKeys(fn ($e) => [$e['id'] => ['quantity' => $e['quantity'] ?? 1]]));
        }
        $this->audit()->log('room.updated', $room, before: $before, after: collect($data)->except(['equipment', 'confirm_affected'])->all());

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
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from'], 'floor_id' => ['nullable', 'uuid'], 'capacity' => ['nullable', 'integer']]);
        $from = CarbonImmutable::parse($data['from'])->utc();
        $to = CarbonImmutable::parse($data['to'])->utc();
        $rooms = Room::query()->with('floor')->where('status', 'active')
            ->when($data['floor_id'] ?? null, fn ($q, $f) => $q->where('floor_id', $f))
            ->when($data['capacity'] ?? null, fn ($q, $c) => $q->where('capacity', '>=', $c))->orderBy('name')->get();

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
            'Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$room->code.'.ics"', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function block(Request $request, Room $room): JsonResponse
    {
        $this->reservations->assertManages($room, $this->user());
        $data = $request->validate(['starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'kind' => ['required', 'in:technical,institutional'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        $impacted = ReservationOccurrence::query()->where('room_id', $room->id)->whereIn('status', ReservationService::ACTIVE)->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->count();
        $block = $room->blocks()->create($data + ['created_by' => $this->user()->id]);
        $this->audit()->log('room.blocked', $room, $data['reason'], after: $data);

        return response()->json(['data' => $block, 'impacted_reservations' => $impacted, 'message' => $impacted ? "$impacted réservation(s) existante(s) chevauchent cette fermeture : traitez-les depuis « À valider »." : null], 201);
    }

    public function unblock(RoomBlock $block): JsonResponse
    {
        $this->reservations->assertManages($block->room, $this->user());
        $this->audit()->log('room.unblocked', $block->room, $block->reason);
        $block->delete();

        return response()->json(null, 204);
    }

    public function resources(): JsonResponse
    {
        $this->authorizePermission('rooms.view');

        return response()->json(['data' => MobileResource::query()->with('manager')->orderBy('name')->get()->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'status' => $r->status, 'manager' => Present::userRef($r->manager)])]);
    }

    public function storeResource(Request $request): JsonResponse
    {
        $this->authorizePermission('rooms.manage');
        $data = $request->validate(['code' => ['required', 'string', 'max:40', 'unique:mobile_resources,code'], 'name' => ['required', 'string', 'max:120'], 'type' => ['required', 'string', 'max:40'], 'manager_id' => ['nullable', 'uuid', 'exists:users,id']]);
        $resource = MobileResource::query()->create($data);
        $this->audit()->log('resource.created', $resource);

        return response()->json(['data' => $resource], 201);
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

        return $request->validate([
            'code' => [$req, 'string', 'max:40', Rule::unique('rooms', 'code')->ignore($room?->id)], 'name' => [$req, 'string', 'max:120'],
            'floor_id' => [$req, 'uuid', 'exists:floors,id'], 'capacity' => [$req, 'integer', 'min:1', 'max:2000'], 'location_text' => ['nullable', 'string', 'max:200'],
            'manager_id' => ['nullable', 'uuid', 'exists:users,id'], 'approval_mode' => ['sometimes', 'in:auto,manual'],
            'min_minutes' => ['sometimes', 'integer', 'between:15,600'], 'max_minutes' => ['sometimes', 'integer', 'between:15,720', 'gte:min_minutes'],
            'lead_days' => ['sometimes', 'integer', 'between:1,365'], 'prep_minutes' => ['sometimes', 'integer', 'between:0,240'], 'cleanup_minutes' => ['sometimes', 'integer', 'between:0,240'],
            'open_from' => ['sometimes', 'date_format:H:i'], 'open_to' => ['sometimes', 'date_format:H:i', 'after:open_from'],
            'pending_hold_hours' => ['sometimes', 'integer', 'between:1,168'], 'auto_release_enabled' => ['sometimes', 'boolean'], 'status' => ['sometimes', 'in:active,inactive'],
            'equipment' => ['sometimes', 'array'], 'equipment.*.id' => ['required', 'uuid', 'exists:equipment,id'], 'equipment.*.quantity' => ['nullable', 'integer', 'min:1'],
            'confirm_affected' => ['sometimes', 'boolean'],
        ]);
    }
}
