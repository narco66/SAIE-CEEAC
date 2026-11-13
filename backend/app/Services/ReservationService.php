<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\MobileResource;
use App\Models\Reservation;
use App\Models\ReservationOccurrence;
use App\Models\ResourceBooking;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\User;
use App\Support\BusinessException;
use App\Support\Reference;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Règles de réservation : intervalles semi-ouverts [début, fin[, tampons de préparation, séries bornées « tout ou rien »,
 * validation manuelle et non-présentation déclarée. La contrainte EXCLUDE GiST reste la garantie finale (SAL-05).
 */
class ReservationService
{
    public const ACTIVE = ['pending', 'confirmed', 'in_progress'];

    public function __construct(private Access $access, private Audit $audit, private Notifier $notifier) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{reservation: Reservation, created: list<ReservationOccurrence>, skipped: list<array<string, string>>}
     */
    public function create(array $data, User $user): array
    {
        /** @var Room $room */
        $room = Room::query()->findOrFail($data['room_id']);
        $start = CarbonImmutable::parse($data['start_at'])->utc();
        $end = CarbonImmutable::parse($data['end_at'])->utc();
        $override = $this->checkCapacity($room, (int) $data['participant_count'], $data['capacity_override_reason'] ?? null, $user);
        $slots = $this->expand($start, $end, $data['recurrence'] ?? null);
        $this->assertBookable($room, $slots, $user, $data['services'] ?? []);
        $mode = $data['recurrence_mode'] ?? 'all_or_nothing';
        $conflicts = $this->conflicts($room, $slots);
        if ($conflicts !== [] && $mode !== 'partial') {
            throw new BusinessException('BOOKING_CONFLICT', count($slots) > 1 ? 'Au moins une occurrence de la série est en conflit : aucune occurrence n’a été créée.' : 'Le créneau demandé n’est pas disponible.', 409, ['conflicts' => $conflicts]);
        }

        return DB::transaction(function () use ($data, $user, $room, $slots, $override, $mode): array {
            $reservation = Reservation::query()->create([
                'reference' => Reference::next('RES'), 'organizer_id' => $data['organizer_id'] ?? $user->id, 'structure_id' => $user->structure_id,
                'subject' => $data['subject'], 'description' => $data['description'] ?? null, 'confidential' => (bool) $data['confidentiality'],
                'participant_count' => $data['participant_count'], 'internal_participants' => $data['internal_participants'] ?? null,
                'external_participants' => $data['external_participants'] ?? null, 'recurrence' => $data['recurrence'] ?? null,
                'is_priority' => (bool) ($data['is_priority'] ?? false), 'created_by' => $user->id,
            ]);
            $status = $room->approval_mode === 'manual' ? 'pending' : 'confirmed';
            $created = [];
            $skipped = [];
            foreach ($slots as $index => [$s, $e]) {
                $attributes = [
                    'room_id' => $room->id, 'sequence' => $index + 1, 'starts_at' => $s, 'ends_at' => $e,
                    'blocking_start' => $s->subMinutes($room->prep_minutes), 'blocking_end' => $e->addMinutes($room->cleanup_minutes),
                    'status' => $status, 'check_in_code' => strtoupper(Str::random(6)), 'hold_until' => $status === 'pending' ? now()->addHours($room->pending_hold_hours) : null,
                    'capacity_override' => $override !== null, 'capacity_override_reason' => $override,
                ];
                try {
                    $occurrence = DB::transaction(fn () => $reservation->occurrences()->create($attributes));
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '23P01') {
                        throw $exception;
                    }
                    if ($mode !== 'partial') {
                        throw new BusinessException('BOOKING_CONFLICT', 'Une réservation concurrente vient d’être confirmée sur ce créneau. Votre saisie est conservée.', 409, ['conflicts' => [$this->slotView($s, $e)]]);
                    }
                    $skipped[] = $this->slotView($s, $e);

                    continue;
                }
                $this->attachResources($occurrence, $data['resource_ids'] ?? []);
                foreach ($data['services'] ?? [] as $service) {
                    ServiceRequest::query()->create([
                        'occurrence_id' => $occurrence->id, 'service_type_id' => $service['service_type_id'], 'details' => $service['details'] ?? null,
                        'headcount' => $service['headcount'] ?? null, 'serve_time' => $service['serve_time'] ?? null, 'due_at' => $s->subHours(2),
                    ]);
                }
                $created[] = $occurrence;
            }
            if ($created === []) {
                throw new BusinessException('BOOKING_CONFLICT', 'Aucune occurrence n’a pu être créée.', 409, ['conflicts' => $skipped]);
            }
            foreach ($data['agenda'] ?? [] as $position => $item) {
                $reservation->agendaItems()->create(['position' => $position + 1, 'title' => $item['title'], 'time_label' => $item['time_label'] ?? null, 'presenter' => $item['presenter'] ?? null]);
            }
            if ($status === 'pending') {
                $manager = $room->manager;
                Approval::query()->create([
                    'object_type' => 'reservation', 'object_id' => $reservation->id, 'title' => $reservation->confidential ? 'Réservation confidentielle' : $reservation->subject,
                    'requested_by' => $user->id, 'assignee_id' => $manager?->id, 'backup_id' => $manager?->backup_user_id,
                    'due_at' => now()->addHours((int) config('saie.workflow.reservation_due_hours')),
                ]);
                $this->notifier->notify($manager, 'reservation.pending', 'Réservation à valider', $room->name.' · '.$this->localLabel($created[0]->starts_at), "/reservations/{$reservation->id}", 'reservation', $reservation->id);
            }
            $this->notifyServiceManagers($reservation, $created);
            $this->notifier->notify($reservation->organizer, 'reservation.'.$status, $status === 'confirmed' ? 'Réservation confirmée' : 'Demande de réservation enregistrée', $room->name.' · '.$this->localLabel($created[0]->starts_at), "/reservations/{$reservation->id}", 'reservation', $reservation->id);
            $this->audit->log('reservation.created', $reservation, after: ['room' => $room->code, 'occurrences' => count($created), 'skipped' => count($skipped), 'status' => $status]);

            return ['reservation' => $reservation, 'created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * Libère les ressources mobiles et annule les prestations encore ouvertes d'une occurrence qui ne se tient pas
     * (annulation, refus, délai dépassé, non-présentation, libération automatique) : SAL-03, SAL-08, SAL-13.
     */
    public function release(ReservationOccurrence $occurrence): void
    {
        $occurrence->resourceBookings()->where('status', 'active')->update(['status' => 'released']);
        $occurrence->serviceRequests()->whereNotIn('status', ['ready', 'impossible', 'cancelled'])->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    public function approve(Reservation $reservation, User $user, bool $approve, ?string $reason): void
    {
        DB::transaction(function () use ($reservation, $user, $approve, $reason): void {
            $occurrences = $reservation->occurrences()->where('status', 'pending')->lockForUpdate()->get();
            if ($occurrences->isEmpty()) {
                throw new BusinessException('INVALID_TRANSITION', 'Aucune occurrence en attente de validation.', 409);
            }
            if (! $this->isEscalatedBackup($reservation, $user)) {
                foreach ($occurrences as $occurrence) {
                    $this->assertManages($occurrence->room, $user);
                }
            }
            if (! $approve && (! $reason || mb_strlen(trim($reason)) < 5)) {
                throw new BusinessException('REASON_REQUIRED', 'Le motif du refus est obligatoire.', 422);
            }
            // SAL-01 : une fermeture posée après la demande interdit sa confirmation (le refus reste possible).
            if ($approve) {
                $blocked = $occurrences->filter(fn (ReservationOccurrence $o): bool => RoomBlock::query()->where('room_id', $o->room_id)->where('starts_at', '<', $o->ends_at)->where('ends_at', '>', $o->starts_at)->exists());
                if ($blocked->isNotEmpty()) {
                    throw new BusinessException('ROOM_BLOCKED', 'La salle est fermée sur '.$blocked->count().' créneau(x) demandé(s) : refusez la demande ou proposez un autre créneau.', 409, ['conflicts' => $blocked->map(fn ($o) => $this->slotView(CarbonImmutable::instance($o->starts_at), CarbonImmutable::instance($o->ends_at)))->values()->all()]);
                }
            }
            foreach ($occurrences as $occurrence) {
                $occurrence->forceFill(['status' => $approve ? 'confirmed' : 'refused', 'hold_until' => null, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_reason' => $reason, 'version' => $occurrence->version + 1])->save();
                if (! $approve) {
                    $this->release($occurrence);
                }
            }
            Approval::query()->where('object_type', 'reservation')->where('object_id', $reservation->id)->where('status', 'open')
                ->update(['status' => 'decided', 'decision' => $approve ? 'approved' : 'returned', 'reason' => $reason, 'decided_by' => $user->id, 'decided_at' => now(), 'updated_at' => now()]);
            $this->notifier->notify($reservation->organizer, $approve ? 'reservation.confirmed' : 'reservation.refused', $approve ? 'Réservation confirmée' : 'Réservation refusée', $reservation->confidential ? 'Réservation confidentielle' : $reservation->subject, "/reservations/{$reservation->id}", 'reservation', $reservation->id, 'decision-'.$occurrences->first()->id.'-'.$occurrences->first()->version);
            $this->audit->log($approve ? 'reservation.approved' : 'reservation.refused', $reservation, $reason);
        });
    }

    /**
     * Modification d'une occurrence (SAL-07) : les contrôles sont rejoués, et un échec laisse l'occurrence initiale intacte.
     *
     * @param  array<string, mixed>  $changes
     */
    public function modify(ReservationOccurrence $occurrence, array $changes, User $user, ?string $reason): ReservationOccurrence
    {
        $reservation = $occurrence->reservation;
        $isManager = $this->manages($occurrence->room, $user);
        if ($reservation->organizer_id !== $user->id && ! $isManager) {
            throw new AuthorizationException;
        }
        if (! in_array($occurrence->status, ['pending', 'confirmed'], true)) {
            throw new BusinessException('INVALID_TRANSITION', 'Seule une occurrence en attente ou confirmée peut être modifiée.', 409);
        }
        /** @var Room $room */
        $room = isset($changes['room_id']) ? Room::query()->findOrFail($changes['room_id']) : $occurrence->room;
        $start = isset($changes['start_at']) ? CarbonImmutable::parse($changes['start_at'])->utc() : CarbonImmutable::instance($occurrence->starts_at);
        $end = isset($changes['end_at']) ? CarbonImmutable::parse($changes['end_at'])->utc() : CarbonImmutable::instance($occurrence->ends_at);
        $administrativeMove = $isManager && $reservation->organizer_id !== $user->id;
        if ($administrativeMove && (! $reason || mb_strlen(trim($reason)) < 5)) {
            throw new BusinessException('REASON_REQUIRED', 'Un déplacement administratif exige un motif.', 422);
        }
        $this->checkRules($room, $start, $end, $user);
        $override = $this->checkCapacity($room, (int) ($changes['participant_count'] ?? $reservation->participant_count), $changes['capacity_override_reason'] ?? null, $user);
        $conflicts = $this->conflicts($room, [[$start, $end]], $occurrence->id);
        if ($conflicts !== []) {
            throw new BusinessException('BOOKING_CONFLICT', 'Le nouveau créneau n’est pas disponible. La réservation initiale est conservée.', 409, ['conflicts' => $conflicts]);
        }
        $before = $occurrence->only(['room_id', 'starts_at', 'ends_at', 'status']);

        try {
            // SAL-07 : l'exemption de validation se juge sur la salle de destination (son gestionnaire), pas sur la salle d'origine.
            $managesTarget = $this->manages($room, $user);
            $needsApproval = $room->approval_mode === 'manual' && ! $managesTarget && ($room->id !== $occurrence->room_id || ! $start->equalTo($occurrence->starts_at) || ! $end->equalTo($occurrence->ends_at));
            $roomChanged = $room->id !== $occurrence->room_id;
            // Une demande en attente déplacée vers une salle sans validation, ou par le gestionnaire de destination, est confirmée.
            $nextStatus = $needsApproval ? 'pending' : ($occurrence->status === 'pending' && $roomChanged && ($room->approval_mode === 'auto' || $managesTarget) ? 'confirmed' : $occurrence->status);
            DB::transaction(function () use ($occurrence, $room, $start, $end, $changes, $reservation, $needsApproval, $user, $override, $nextStatus, $roomChanged, $before): void {
                $occurrence->forceFill([
                    'room_id' => $room->id, 'starts_at' => $start, 'ends_at' => $end,
                    'blocking_start' => $start->subMinutes($room->prep_minutes), 'blocking_end' => $end->addMinutes($room->cleanup_minutes),
                    'status' => $nextStatus, 'hold_until' => $nextStatus === 'pending' ? ($needsApproval ? now()->addHours($room->pending_hold_hours) : $occurrence->hold_until) : null,
                    'version' => $occurrence->version + 1, 'capacity_override' => $override !== null, 'capacity_override_reason' => $override,
                ])->save();
                $occurrence->resourceBookings()->update(['blocking_start' => $occurrence->blocking_start, 'blocking_end' => $occurrence->blocking_end]);
                // Les prestations encore ouvertes suivent la réunion déplacée : échéance 2 h avant le nouveau début.
                $occurrence->serviceRequests()->whereNotIn('status', ['ready', 'impossible', 'cancelled'])->update(['due_at' => $start->subHours(2), 'updated_at' => now()]);
                if (isset($changes['participant_count']) || isset($changes['subject'])) {
                    $reservation->forceFill(array_filter(['participant_count' => $changes['participant_count'] ?? null, 'subject' => $changes['subject'] ?? null]) + ['version' => $reservation->version + 1])->save();
                }
                $openApproval = Approval::query()->where('object_type', 'reservation')->where('object_id', $reservation->id)->where('status', 'open');
                if ($nextStatus === 'confirmed' && $before['status'] === 'pending' && ! $reservation->occurrences()->where('status', 'pending')->exists()) {
                    (clone $openApproval)->update(['status' => 'cancelled', 'reason' => 'Demande déplacée vers une salle sans validation', 'updated_at' => now()]);
                }
                // Demande toujours en attente déplacée vers une autre salle : la tâche suit le gestionnaire de la salle de destination.
                if ($nextStatus === 'pending' && $roomChanged && (clone $openApproval)->exists()) {
                    $manager = $room->manager;
                    (clone $openApproval)->update(['assignee_id' => $manager?->id, 'backup_id' => $manager?->backup_user_id, 'escalated_at' => null, 'updated_at' => now()]);
                    DB::afterCommit(fn () => $this->notifier->notify($manager, 'reservation.pending', 'Réservation à valider', $room->name.' · '.$this->localLabel($start), "/reservations/{$reservation->id}", 'reservation', $reservation->id, 'moved-'.$occurrence->id.'-'.$occurrence->version));
                }
                // La demande redevenue « en attente » est présentée au gestionnaire de la salle de destination (tâche, rappel, escalade).
                if ($needsApproval && ! (clone $openApproval)->exists()) {
                    $manager = $room->manager;
                    Approval::query()->create([
                        'object_type' => 'reservation', 'object_id' => $reservation->id, 'title' => $reservation->confidential ? 'Réservation confidentielle' : $reservation->subject,
                        'requested_by' => $user->id, 'assignee_id' => $manager?->id, 'backup_id' => $manager?->backup_user_id,
                        'due_at' => now()->addHours((int) config('saie.workflow.reservation_due_hours')),
                    ]);
                    DB::afterCommit(fn () => $this->notifier->notify($manager, 'reservation.pending', 'Réservation à valider', $room->name.' · '.$this->localLabel($start), "/reservations/{$reservation->id}", 'reservation', $reservation->id, 'modified-'.$occurrence->id.'-'.$occurrence->version));
                }
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23P01') {
                throw new BusinessException('BOOKING_CONFLICT', 'Le nouveau créneau vient d’être réservé. La réservation initiale est conservée.', 409);
            }
            throw $e;
        }
        if ($administrativeMove) {
            $this->notifier->notify($reservation->organizer, 'reservation.moved', 'Votre réservation a été déplacée', $room->name.' · '.$this->localLabel($start), "/reservations/{$reservation->id}", 'reservation', $reservation->id, 'move-'.$occurrence->version);
        }
        $this->audit->log('reservation.modified', $reservation, $reason, before: $before, after: $occurrence->only(['room_id', 'starts_at', 'ends_at', 'status']), version: $occurrence->version);

        return $occurrence->refresh();
    }

    /**
     * « Cette occurrence et les suivantes » (SAL-06, SAL-07) : même salle et même horaire pour chaque date conservée,
     * en une seule transaction ; un conflit sur l'une d'elles annule l'ensemble et la série reste inchangée.
     *
     * @return list<ReservationOccurrence>
     */
    public function modifyFollowing(ReservationOccurrence $occurrence, string $roomId, string $from, string $to, User $user, ?string $reason): array
    {
        $tz = config('saie.timezone');

        return DB::transaction(function () use ($occurrence, $roomId, $from, $to, $user, $reason, $tz): array {
            $following = $occurrence->reservation->occurrences()->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', $occurrence->starts_at)
                ->orderBy('starts_at')->lockForUpdate()->get();
            $updated = [];
            foreach ($following as $item) {
                $day = CarbonImmutable::instance($item->starts_at)->setTimezone($tz)->format('Y-m-d');
                $updated[] = $this->modify($item, ['room_id' => $roomId, 'start_at' => CarbonImmutable::parse("$day $from", $tz)->toIso8601String(), 'end_at' => CarbonImmutable::parse("$day $to", $tz)->toIso8601String()], $user, $reason);
            }

            return $updated;
        });
    }

    /**
     * Annulation tracée d'une occurrence, des suivantes ou de toute la série (SAL-07).
     */
    /**
     * Prestations demandées (SAL-08, SAL-15) : type actif ; pour la restauration, nombre de couverts et heure de service
     * obligatoires, l'heure (fuseau institutionnel) devant tomber pendant chaque occurrence.
     *
     * @param  list<array{service_type_id: string, details?: string|null, headcount?: int|null, serve_time?: string|null}>  $services
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $slots
     *
     * @throws ValidationException
     */
    public function assertServicesValid(array $services, array $slots): void
    {
        if ($services === []) {
            return;
        }
        $types = ServiceType::query()->whereIn('id', array_column($services, 'service_type_id'))->get()->keyBy('id');
        $timezone = (string) config('saie.timezone');
        $errors = [];
        foreach (array_values($services) as $index => $service) {
            $type = $types->get($service['service_type_id']);
            if (! $type || ! $type->is_active) {
                $errors["services.$index.service_type_id"] = 'Ce type de prestation n’est pas disponible.';

                continue;
            }
            if (! $type->requires_headcount) {
                continue;
            }
            if (empty($service['headcount'])) {
                $errors["services.$index.headcount"] = "Indiquez le nombre de couverts pour « {$type->name} ».";
            }
            if (empty($service['serve_time'])) {
                $errors["services.$index.serve_time"] = "Indiquez l’heure de service pour « {$type->name} ».";

                continue;
            }
            foreach ($slots as [$start, $end]) {
                $localStart = $start->setTimezone($timezone);
                $serveAt = $localStart->setTimeFromTimeString($service['serve_time']);
                if ($serveAt->lt($localStart) || $serveAt->gte($end->setTimezone($timezone))) {
                    $errors["services.$index.serve_time"] = 'L’heure de service doit se situer pendant la réunion ('.$localStart->format('H:i').'–'.$end->setTimezone($timezone)->format('H:i').').';

                    break;
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function cancel(ReservationOccurrence $occurrence, string $scope, User $user, ?string $reason): int
    {
        $reservation = $occurrence->reservation;
        if ($reservation->organizer_id !== $user->id && ! $this->manages($occurrence->room, $user)) {
            throw new AuthorizationException;
        }
        $query = $reservation->occurrences()->whereIn('status', ['pending', 'confirmed']);
        $query = match ($scope) {
            'following' => $query->where('starts_at', '>=', $occurrence->starts_at),
            'all' => $query,
            default => $query->whereKey($occurrence->id),
        };
        $servicesCancelled = 0;
        $count = DB::transaction(function () use ($query, $reason, $reservation, &$servicesCancelled): int {
            $items = $query->lockForUpdate()->get();
            foreach ($items as $item) {
                $servicesCancelled += $item->serviceRequests()->whereNotIn('status', ['ready', 'impossible', 'cancelled'])->count();
                $item->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason, 'version' => $item->version + 1])->save();
                $this->release($item);
            }
            // HAB-04 : plus aucune occurrence en attente, la tâche de validation n'a plus d'objet (ni rappel ni escalade).
            if ($items->isNotEmpty() && ! $reservation->occurrences()->where('status', 'pending')->exists()) {
                Approval::query()->where('object_type', 'reservation')->where('object_id', $reservation->id)->where('status', 'open')
                    ->update(['status' => 'cancelled', 'reason' => 'Réservation annulée'.($reason ? ' : '.$reason : ''), 'updated_at' => now()]);
            }

            return $items->count();
        });
        if ($count === 0) {
            throw new BusinessException('INVALID_TRANSITION', 'Aucune occurrence annulable.', 409);
        }
        $title = $reservation->confidential ? 'Réservation confidentielle' : $reservation->subject;
        $this->notifier->notify($reservation->organizer, 'reservation.cancelled', 'Réservation annulée', $title, "/reservations/{$reservation->id}", 'reservation', $reservation->id, 'cancel-'.$occurrence->id.'-'.$scope);
        // NOT-01 : le gestionnaire de la salle est prévenu d'une annulation faite par l'organisateur, les prestataires de leurs demandes annulées.
        $manager = $occurrence->room->manager;
        if ($manager && $manager->id !== $user->id) {
            $this->notifier->notify($manager, 'reservation.cancelled', 'Réservation annulée par l’organisateur', $occurrence->room->name.' · '.$this->localLabel($occurrence->starts_at), "/reservations/{$reservation->id}", 'reservation', $reservation->id, 'cancel-manager-'.$occurrence->id.'-'.$scope);
        }
        if ($servicesCancelled > 0) {
            $this->notifyServiceHolders($reservation, $reservation->occurrences()->pluck('id')->all(), 'service.cancelled', 'Prestation annulée');
        }
        $this->audit->log('reservation.cancelled', $reservation, $reason, after: ['scope' => $scope, 'count' => $count]);

        return $count;
    }

    /**
     * Déclaration manuelle de non-présentation par le gestionnaire, après l'heure de début (SAL-12).
     */
    public function declareNoShow(ReservationOccurrence $occurrence, User $user, string $reason): void
    {
        $this->assertManages($occurrence->room, $user);
        if (! in_array($occurrence->status, ['confirmed', 'in_progress', 'completed'], true)) {
            throw new BusinessException('INVALID_TRANSITION', 'Seule une réunion confirmée peut être déclarée non honorée.', 409);
        }
        if ($occurrence->starts_at->isFuture()) {
            throw new BusinessException('TOO_EARLY', 'La non-présentation ne peut être déclarée qu’après l’heure de début.', 409);
        }
        $occurrence->forceFill(['status' => 'no_show', 'no_show_reason' => $reason, 'no_show_by' => $user->id, 'no_show_at' => now(), 'version' => $occurrence->version + 1])->save();
        $this->release($occurrence);
        $this->notifier->notify($occurrence->reservation->organizer, 'reservation.no_show', 'Réservation déclarée non honorée', $occurrence->room->name.' · '.$this->localLabel($occurrence->starts_at), "/reservations/{$occurrence->reservation_id}", 'reservation', $occurrence->reservation_id, 'noshow-'.$occurrence->id);
        $this->audit->log('reservation.no_show', $occurrence->reservation, $reason, version: $occurrence->version);
    }

    /**
     * Confirmation de présence (SAL-10) par l'organisateur authentifié ou par code temporaire, de 15 min avant le début à la fin.
     */
    public function checkIn(ReservationOccurrence $occurrence, User $user, ?string $code): ReservationOccurrence
    {
        $reservation = $occurrence->reservation;
        $byCode = $code !== null && $occurrence->check_in_code && hash_equals($occurrence->check_in_code, strtoupper(trim($code)));
        if ($reservation->organizer_id !== $user->id && ! $byCode && ! $this->manages($occurrence->room, $user)) {
            throw new AuthorizationException('Seul l’organisateur, ou le titulaire du code de présence, peut confirmer.');
        }
        if (! in_array($occurrence->status, ['confirmed', 'in_progress'], true)) {
            throw new BusinessException('INVALID_TRANSITION', 'Seule une réunion confirmée peut être marquée présente.', 409);
        }
        if (now()->lt($occurrence->starts_at->subMinutes(15)) || now()->gte($occurrence->ends_at)) {
            throw new BusinessException('CHECK_IN_WINDOW', 'La présence se confirme de 15 minutes avant le début jusqu’à la fin de la réunion.', 409);
        }
        if (! $occurrence->checked_in_at) {
            $occurrence->forceFill(['checked_in_at' => now(), 'checked_in_by' => $user->id])->save();
            $this->audit->log('reservation.presence_confirmed', $reservation, $byCode ? 'Code de présence' : null);
        }

        return $occurrence;
    }

    /**
     * Libération automatique d'une salle sans confirmation de présence 15 minutes après le début (SAL-10),
     * uniquement pour les salles où la règle est activée et hors réunions prioritaires.
     */
    public function autoRelease(): int
    {
        $released = 0;
        $occurrences = ReservationOccurrence::query()->with(['reservation.organizer', 'room'])
            ->whereIn('status', ['confirmed', 'in_progress'])->whereNull('checked_in_at')
            ->where('starts_at', '<=', now()->subMinutes(15))->where('ends_at', '>', now())
            ->whereHas('room', fn ($q) => $q->where('auto_release_enabled', true))
            ->whereHas('reservation', fn ($q) => $q->where('is_priority', false))->get();
        foreach ($occurrences as $occurrence) {
            $occurrence->forceFill(['status' => 'no_show', 'no_show_reason' => 'Libération automatique : présence non confirmée 15 minutes après le début', 'no_show_at' => now(), 'version' => $occurrence->version + 1])->save();
            $this->release($occurrence);
            $this->notifier->notify($occurrence->reservation->organizer, 'reservation.auto_released', 'Salle libérée automatiquement', $occurrence->room->name.' · '.$this->localLabel($occurrence->starts_at), "/reservations/{$occurrence->reservation_id}", 'reservation', $occurrence->reservation_id, 'release-'.$occurrence->id);
            $this->audit->log('reservation.auto_released', $occurrence->reservation, 'Présence non confirmée', actor: null);
            $released++;
        }

        return $released;
    }

    /**
     * Export iCalendar (SAL-11 sans connecteur) : aucune synchronisation bidirectionnelle n'est revendiquée.
     *
     * @param  iterable<ReservationOccurrence>  $occurrences
     */
    public function ics(iterable $occurrences, ?User $viewer, string $name): string
    {
        $escape = fn (string $v): string => str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $v);
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CEEAC//SAIE-CEEAC//FR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:'.$escape($name), 'X-WR-TIMEZONE:'.config('saie.timezone')];
        foreach ($occurrences as $o) {
            $reservation = $o->reservation;
            $visible = $viewer && ($reservation->organizer_id === $viewer->id || $this->manages($o->room, $viewer)) || (! $reservation->confidential && $o->status !== 'pending');
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$o->id.'@saie-ceeac';
            $lines[] = 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTSTART:'.$o->starts_at->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'.$o->ends_at->utc()->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:'.$escape($visible ? $reservation->subject : 'Réservée');
            $lines[] = 'LOCATION:'.$escape($o->room->name.' · '.$o->room->floor?->label);
            $lines[] = 'STATUS:'.($o->status === 'pending' ? 'TENTATIVE' : (in_array($o->status, ['cancelled', 'refused'], true) ? 'CANCELLED' : 'CONFIRMED'));
            $lines[] = 'SEQUENCE:'.$o->version;
            $lines[] = 'CLASS:'.($reservation->confidential ? 'CONFIDENTIAL' : 'PUBLIC');
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(fn ($l) => mb_strlen($l) > 74 ? implode("\r\n ", mb_str_split($l, 73)) : $l, $lines))."\r\n";
    }

    /**
     * Escalade (HAB-04) : une demande non traitée à l'échéance passe au suppléant du gestionnaire, qui peut alors la voir et
     * la trancher, à condition de détenir lui-même le droit d'approuver les réservations.
     */
    public function isEscalatedBackup(Reservation $reservation, User $user): bool
    {
        return $this->access->can($user, 'reservations.approve') && Approval::query()->where('object_type', 'reservation')->where('object_id', $reservation->id)
            ->where('status', 'open')->whereNotNull('escalated_at')->where('backup_id', $user->id)->exists();
    }

    public function manages(Room $room, User $user): bool
    {
        if (! $this->access->can($user, 'reservations.approve') && ! $this->access->can($user, 'rooms.manage')) {
            return false;
        }

        return $room->manager_id === $user->id || $this->access->hasRole($user, 'admin_fonc');
    }

    public function assertManages(Room $room, User $user): void
    {
        if (! $this->manages($room, $user)) {
            throw new AuthorizationException('Salle hors de votre périmètre.');
        }
    }

    /**
     * Créneaux occupés dans une période, sans titre confidentiel (SAL-02).
     *
     * @return list<array<string, mixed>>
     */
    public function busy(Room $room, CarbonImmutable $from, CarbonImmutable $to, ?User $viewer = null): array
    {
        $occurrences = ReservationOccurrence::query()->with('reservation')->where('room_id', $room->id)->whereIn('status', self::ACTIVE)
            ->where('blocking_start', '<', $to)->where('blocking_end', '>', $from)->orderBy('starts_at')->get();
        $blocks = RoomBlock::query()->where('room_id', $room->id)->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get();
        $canSeePending = $viewer && $this->manages($room, $viewer);

        $items = $occurrences->map(function (ReservationOccurrence $o) use ($viewer, $canSeePending): array {
            $visible = $viewer && ! $o->reservation->confidential && ($o->status !== 'pending' || $canSeePending || $o->reservation->organizer_id === $viewer->id);

            return [
                'type' => $o->status === 'pending' ? 'pending' : 'reserved', 'occurrence_id' => $visible || $canSeePending ? $o->id : null,
                'reservation_id' => $visible || $canSeePending ? $o->reservation_id : null,
                'starts_at' => $o->starts_at->toIso8601String(), 'ends_at' => $o->ends_at->toIso8601String(),
                'blocking_start' => $o->blocking_start->toIso8601String(), 'blocking_end' => $o->blocking_end->toIso8601String(),
                'label' => $visible ? $o->reservation->subject : 'Réservée', 'confidential' => $o->reservation->confidential,
            ];
        });
        $items = $items->merge($blocks->map(fn (RoomBlock $b): array => [
            'type' => 'blocked', 'occurrence_id' => null, 'starts_at' => $b->starts_at->toIso8601String(), 'ends_at' => $b->ends_at->toIso8601String(),
            'blocking_start' => $b->starts_at->toIso8601String(), 'blocking_end' => $b->ends_at->toIso8601String(), 'label' => $b->kind === 'technical' ? 'Indisponible (technique)' : 'Indisponible', 'confidential' => false,
        ]));

        return $items->sortBy('starts_at')->values()->all();
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $slots
     * @return list<array<string, string>>
     */
    public function conflicts(Room $room, array $slots, ?string $ignoreOccurrence = null): array
    {
        $conflicts = [];
        foreach ($slots as [$s, $e]) {
            $bs = $s->subMinutes($room->prep_minutes);
            $be = $e->addMinutes($room->cleanup_minutes);
            $hit = ReservationOccurrence::query()->where('room_id', $room->id)->whereIn('status', self::ACTIVE)
                ->when($ignoreOccurrence, fn ($q) => $q->whereKeyNot($ignoreOccurrence))
                ->where('blocking_start', '<', $be)->where('blocking_end', '>', $bs)->orderBy('blocking_start')->first();
            $block = RoomBlock::query()->where('room_id', $room->id)->where('starts_at', '<', $e)->where('ends_at', '>', $s)->first();
            if ($hit || $block) {
                $conflicts[] = $this->slotView($s, $e) + [
                    'unavailable_from' => ($hit?->blocking_start ?? $block->starts_at)->toIso8601String(),
                    'unavailable_to' => ($hit?->blocking_end ?? $block->ends_at)->toIso8601String(),
                    'reason' => $block ? 'Salle indisponible' : 'Créneau réservé (préparation incluse)',
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Matérialise les occurrences d'une série bornée (SAL-06).
     *
     * @param  array<string, mixed>|null  $rule
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function expand(CarbonImmutable $start, CarbonImmutable $end, ?array $rule): array
    {
        if ($end->lessThanOrEqualTo($start)) {
            throw new BusinessException('INVALID_PERIOD', 'La fin doit être postérieure au début.', 422);
        }
        if (! $rule || ($rule['freq'] ?? 'none') === 'none') {
            return [[$start, $end]];
        }
        $tz = config('saie.timezone');
        $max = (int) config('saie.rooms.max_occurrences');
        $limitDate = $start->addMonths((int) config('saie.rooms.max_recurrence_months'));
        $until = isset($rule['until']) ? CarbonImmutable::parse($rule['until'], $tz)->endOfDay()->utc() : $limitDate;
        if ($until->greaterThan($limitDate)) {
            throw new BusinessException('RECURRENCE_TOO_LONG', 'Une série ne peut dépasser '.config('saie.rooms.max_recurrence_months').' mois.', 422);
        }
        $count = (int) ($rule['count'] ?? $max);
        if ($count > $max) {
            throw new BusinessException('RECURRENCE_TOO_LONG', "Une série ne peut dépasser $max occurrences.", 422);
        }
        $interval = max(1, (int) ($rule['interval'] ?? 1));
        $duration = $end->diffInMinutes($start, true);
        $localStart = $start->setTimezone($tz);
        $weekdays = array_map('intval', $rule['byweekday'] ?? [(int) $localStart->isoWeekday()]);
        $slots = [];
        $cursor = $localStart;
        $guard = 0;
        while (count($slots) < $count && $cursor->utc()->lessThanOrEqualTo($until) && $guard++ < 2000) {
            $include = match ($rule['freq']) {
                'daily' => true,
                'weekly' => in_array((int) $cursor->isoWeekday(), $weekdays, true) && intdiv((int) $localStart->startOfWeek()->diffInDays($cursor->startOfWeek(), true), 7) % $interval === 0,
                'monthly' => $cursor->day === $localStart->day,
                default => throw new BusinessException('INVALID_RECURRENCE', 'Règle de récurrence inconnue.', 422),
            };
            if ($include) {
                $slots[] = [$cursor->utc(), $cursor->utc()->addMinutes($duration)];
            }
            $cursor = match ($rule['freq']) {
                'daily' => $cursor->addDays($interval),
                'monthly' => $cursor->addMonthsNoOverflow($interval),
                default => $cursor->addDay(),
            };
        }

        return $slots;
    }

    /**
     * Règles de réservation communes à la vérification (étape « Vérification ») et à la création, pour qu'un créneau
     * déclaré disponible ne soit jamais refusé ensuite pour une règle de la salle ou une prestation.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $slots
     * @param  list<array<string, mixed>>  $services
     */
    public function assertBookable(Room $room, array $slots, User $user, array $services = []): void
    {
        foreach ($slots as [$start, $end]) {
            $this->checkRules($room, $start, $end, $user);
        }
        $this->assertServicesValid($services, $slots);
    }

    private function checkRules(Room $room, CarbonImmutable $start, CarbonImmutable $end, User $user): void
    {
        if ($room->status !== 'active') {
            throw new BusinessException('ROOM_INACTIVE', 'Cette salle n’est pas réservable.', 422);
        }
        if ($start->isPast()) {
            throw new BusinessException('PAST_SLOT', 'Le créneau demandé est déjà passé.', 422);
        }
        $minutes = $start->diffInMinutes($end, true);
        if ($minutes < $room->min_minutes || $minutes > $room->max_minutes) {
            throw new BusinessException('DURATION_OUT_OF_RANGE', "La durée doit être comprise entre {$room->min_minutes} et {$room->max_minutes} minutes.", 422);
        }
        if ($start->greaterThan(now()->addDays($room->lead_days))) {
            throw new BusinessException('TOO_FAR', "Réservation possible au plus {$room->lead_days} jours à l’avance.", 422);
        }
        $tz = config('saie.timezone');
        $ls = $start->setTimezone($tz);
        $le = $end->setTimezone($tz);
        if (! $ls->isSameDay($le) || $ls->format('H:i') < substr($room->open_from, 0, 5) || $le->format('H:i') > substr($room->open_to, 0, 5)) {
            throw new BusinessException('OUTSIDE_OPENING_HOURS', 'Le créneau sort des horaires d’ouverture de la salle ('.substr($room->open_from, 0, 5).'–'.substr($room->open_to, 0, 5).').', 422);
        }
    }

    private function checkCapacity(Room $room, int $participants, ?string $overrideReason, User $user): ?string
    {
        if ($participants < 1) {
            throw new BusinessException('INVALID_PARTICIPANTS', 'Le nombre de participants doit être positif.', 422);
        }
        if ($participants <= $room->capacity) {
            return null;
        }
        if ($overrideReason && $this->manages($room, $user)) {
            return $overrideReason;
        }
        throw new BusinessException('CAPACITY_EXCEEDED', "La salle accueille au plus {$room->capacity} personnes.", 422);
    }

    /**
     * @param  list<string>  $resourceIds
     */
    private function attachResources(ReservationOccurrence $occurrence, array $resourceIds): void
    {
        foreach (MobileResource::query()->whereIn('id', $resourceIds)->where('status', 'active')->get() as $resource) {
            try {
                DB::transaction(fn () => ResourceBooking::query()->create(['resource_id' => $resource->id, 'occurrence_id' => $occurrence->id, 'blocking_start' => $occurrence->blocking_start, 'blocking_end' => $occurrence->blocking_end]));
            } catch (QueryException $e) {
                if ($e->getCode() === '23P01') {
                    throw new BusinessException('RESOURCE_CONFLICT', "La ressource {$resource->name} est déjà réservée sur ce créneau.", 409);
                }
                throw $e;
            }
        }
    }

    /**
     * @param  list<ReservationOccurrence>  $occurrences
     */
    private function notifyServiceManagers(Reservation $reservation, array $occurrences): void
    {
        $this->notifyServiceHolders($reservation, collect($occurrences)->pluck('id')->all(), 'service.requested', 'Nouvelle demande de prestation');
    }

    /**
     * Titulaires du pouvoir « prestations » concernés par les types de prestation demandés sur ces occurrences.
     *
     * @param  list<string>  $occurrenceIds
     */
    public function notifyServiceHolders(Reservation $reservation, array $occurrenceIds, string $event, string $title): void
    {
        $types = ServiceRequest::query()->whereIn('occurrence_id', $occurrenceIds)->with('serviceType')->get()->pluck('serviceType.code')->unique();
        if ($types->isEmpty()) {
            return;
        }
        $holders = User::query()->whereHas('roleExtensions', fn ($q) => $q->where('power', 'prestations')->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now()))->get()
            ->filter(function (User $u) use ($types): bool {
                $scope = $this->access->powerScope($u, 'prestations');

                return $scope === null || $types->intersect($scope['service_types'] ?? [])->isNotEmpty();
            });
        $this->notifier->notify($holders, $event, $title, $reservation->reference, '/prestations', 'reservation', $reservation->id);
    }

    /**
     * @return array<string, string>
     */
    private function slotView(CarbonImmutable $s, CarbonImmutable $e): array
    {
        return ['start_at' => $s->toIso8601String(), 'end_at' => $e->toIso8601String()];
    }

    private function localLabel(\DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->setTimezone(config('saie.timezone'))->locale('fr')->isoFormat('D MMM YYYY · HH:mm');
    }

    /**
     * @return Collection<int, ReservationOccurrence>
     */
    public function todayFor(array $floorIds): Collection
    {
        $tz = config('saie.timezone');
        $from = now($tz)->startOfDay()->utc();
        $to = now($tz)->endOfDay()->utc();

        return ReservationOccurrence::query()->with(['reservation', 'room.floor'])->whereIn('status', ['confirmed', 'in_progress'])
            ->whereHas('room', fn ($q) => $q->whereIn('floor_id', $floorIds))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->orderBy('starts_at')->get();
    }
}
