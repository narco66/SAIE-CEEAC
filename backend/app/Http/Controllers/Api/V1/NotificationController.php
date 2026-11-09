<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Document;
use App\Models\Reservation;
use App\Models\UserNotification;
use App\Services\DocumentAccess;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications internes (V06, NOT-01). Le marquage « lu » ne vaut pas validation ; l'ouverture revérifie les droits (R22).
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = UserNotification::query()->where('user_id', $this->user()->id)
            ->when($request->query('filter') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')->orderByDesc('id');
        $response = $this->paginated($query, $request, fn (UserNotification $n) => [
            'id' => $n->id, 'event' => $n->event, 'title' => $n->title, 'body' => $n->body, 'link' => $n->link, 'object_type' => $n->object_type,
            'read_at' => Present::date($n->read_at), 'created_at' => Present::date($n->created_at),
        ]);
        $payload = $response->getData(true);
        $payload['meta']['unread'] = UserNotification::query()->where('user_id', $this->user()->id)->whereNull('read_at')->count();

        return response()->json($payload);
    }

    public function read(UserNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $this->user()->id, 404);
        $notification->forceFill(['read_at' => $notification->read_at ?? now()])->save();

        return response()->json(['ok' => true]);
    }

    public function readAll(): JsonResponse
    {
        UserNotification::query()->where('user_id', $this->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Vérifie que la cible est encore accessible avant redirection : sinon 403, sans divulguer l'objet.
     */
    public function open(UserNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $this->user()->id, 404);
        $notification->forceFill(['read_at' => $notification->read_at ?? now()])->save();
        $allowed = match ($notification->object_type) {
            'content' => $this->can('contents.view') && Content::query()->whereKey($notification->object_id)->exists(),
            'reservation' => ($r = Reservation::query()->find($notification->object_id)) && ($r->organizer_id === $this->user()->id || $this->can('reservations.approve')),
            'document' => ($d = Document::query()->find($notification->object_id)) && app(DocumentAccess::class)->canView($d, $this->user()),
            default => true,
        };
        if (! $allowed) {
            $this->deny('Cible de notification inaccessible');
        }

        return response()->json(['data' => ['link' => $notification->link]]);
    }
}
