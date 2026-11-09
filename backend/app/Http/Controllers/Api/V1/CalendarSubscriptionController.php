<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CalendarSubscription;
use App\Models\ReservationOccurrence;
use App\Models\Room;
use App\Models\User;
use App\Services\ReservationService;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Abonnement iCalendar d'une salle (SAL-11) utilisable par un client d'agenda, sans session : l'adresse porte un jeton
 * personnel, remis une seule fois et révocable. À chaque lecture, les droits du titulaire sont réévalués et sa visibilité
 * s'applique (réunions confidentielles « Réservée » pour qui n'en est ni l'organisateur ni le gestionnaire).
 */
class CalendarSubscriptionController extends Controller
{
    public function __construct(private readonly ReservationService $reservations) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('rooms.view');
        $subscriptions = CalendarSubscription::query()->with('room')->where('user_id', $this->user()->id)->whereNull('revoked_at')->orderByDesc('created_at')->get();

        return response()->json(['data' => $subscriptions->map(fn (CalendarSubscription $s) => $this->present($s))->values()]);
    }

    public function store(Room $room): JsonResponse
    {
        $this->authorizePermission('rooms.view');
        $token = Str::random(48);
        $subscription = CalendarSubscription::query()->create(['user_id' => $this->user()->id, 'room_id' => $room->id, 'token_hash' => hash('sha256', $token)]);
        $this->audit()->log('calendar.subscribed', $room, after: ['subscription' => $subscription->id]);

        return response()->json(['data' => $this->present($subscription->load('room')) + [
            'url' => url("/api/v1/calendar/$token.ics"),
            'notice' => 'Adresse personnelle affichée une seule fois : ne la partagez pas. Révoquez l’abonnement si elle a été divulguée.',
        ]], 201);
    }

    public function revoke(CalendarSubscription $subscription): JsonResponse
    {
        abort_unless($subscription->user_id === $this->user()->id, 404);
        $subscription->forceFill(['revoked_at' => now()])->save();
        $this->audit()->log('calendar.unsubscribed', $subscription->room, after: ['subscription' => $subscription->id]);

        return response()->json(['data' => $this->present($subscription->load('room'))]);
    }

    /**
     * Lecture publique par le client d'agenda. Jeton inconnu, révoqué, titulaire inactif ou sans droit : 404 indistinct.
     */
    public function feed(string $token): Response
    {
        $subscription = CalendarSubscription::query()->with(['room', 'user'])->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        $user = $subscription?->user;
        abort_unless($subscription && $user instanceof User && $user->isActive() && $this->access()->can($user, 'rooms.view'), 404);
        $subscription->forceFill(['last_used_at' => now()])->save();
        $occurrences = ReservationOccurrence::query()->with(['reservation', 'room.floor'])->where('room_id', $subscription->room_id)
            ->whereIn('status', ReservationService::ACTIVE)->whereBetween('starts_at', [now()->subWeek(), now()->addWeeks(8)])->orderBy('starts_at')->get();

        return response($this->reservations->ics($occurrences, $user, $subscription->room->name), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8', 'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CalendarSubscription $subscription): array
    {
        return [
            'id' => $subscription->id, 'room' => ['id' => $subscription->room_id, 'name' => $subscription->room?->name],
            'created_at' => Present::date($subscription->created_at), 'last_used_at' => Present::date($subscription->last_used_at), 'revoked_at' => Present::date($subscription->revoked_at),
        ];
    }
}
