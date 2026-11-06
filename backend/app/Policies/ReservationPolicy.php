<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;
use App\Services\Access;
use App\Services\ReservationService;

/**
 * SEC-02 : droits sur une réservation (SAL-09 : une réunion confidentielle n'existe pas pour les tiers).
 */
class ReservationPolicy
{
    public function __construct(private Access $access, private ReservationService $reservations) {}

    /**
     * Vue complète : organisateur, gestionnaire d'une salle de la réservation, ou suppléant d'une validation escaladée.
     */
    public function viewAll(User $user, Reservation $reservation): bool
    {
        return $reservation->organizer_id === $user->id
            || $reservation->occurrences()->with('room')->get()->contains(fn ($o) => $this->reservations->manages($o->room, $user))
            || $this->reservations->isEscalatedBackup($reservation, $user);
    }

    /**
     * Vue (éventuellement masquée) : la vue complète, ou un agent qui voit les salles pour une réunion non confidentielle.
     */
    public function view(User $user, Reservation $reservation): bool
    {
        return $this->viewAll($user, $reservation) || ($this->access->can($user, 'rooms.view') && ! $reservation->confidential);
    }
}
