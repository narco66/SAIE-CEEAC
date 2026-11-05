<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChecklistItem;
use App\Models\ReservationOccurrence;
use App\Models\ServiceRequest;
use App\Services\Notifier;
use App\Support\BusinessException;
use App\Support\Present;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Prestations de réunion (SAL-08, V32) sous pouvoir « prestations », limitées aux types attribués et aux données nécessaires.
 */
class ServiceRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('services.process');
        $query = $this->scoped(ServiceRequest::query())->with(['serviceType', 'assignee', 'occurrence.room.floor', 'occurrence.reservation', 'occurrence.checklistItems'])
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)), fn ($q) => $q->whereNotIn('status', ['cancelled']))
            ->whereHas('occurrence', fn ($o) => $o->where('ends_at', '>', now()->subDays((int) $request->integer('past_days', 1))))
            ->orderBy('due_at')->orderBy('id');

        return $this->paginated($query, $request, fn (ServiceRequest $s) => [
            'id' => $s->id, 'type' => ['code' => $s->serviceType->code, 'name' => $s->serviceType->name], 'label' => $s->label(), 'headcount' => $s->headcount, 'serve_time' => $s->serve_time, 'details' => $s->details, 'status' => $s->status,
            'due_at' => Present::date($s->due_at), 'assignee' => Present::userRef($s->assignee), 'response_note' => $s->response_note,
            'meeting' => [
                'reference' => $s->occurrence->reservation->reference,
                'subject' => $s->occurrence->reservation->confidential ? 'Réunion confidentielle' : $s->occurrence->reservation->subject,
                'room' => $s->occurrence->room->name, 'floor' => $s->occurrence->room->floor->label, 'participants' => $s->occurrence->reservation->participant_count,
                'starts_at' => Present::date($s->occurrence->starts_at), 'ends_at' => Present::date($s->occurrence->ends_at), 'occurrence_id' => $s->occurrence_id,
            ],
            'checklist' => $s->occurrence->checklistItems->map(fn ($c) => ['id' => $c->id, 'phase' => $c->phase, 'label' => $c->label, 'done_at' => Present::date($c->done_at), 'note' => $c->note])->values(),
        ]);
    }

    public function update(Request $request, ServiceRequest $serviceRequest, Notifier $notifier): JsonResponse
    {
        $this->authorizePermission('services.process');
        if (! $this->scoped(ServiceRequest::query()->whereKey($serviceRequest->id))->exists()) {
            $this->deny('Type de prestation hors périmètre');
        }
        $data = $request->validate(['status' => ['required', 'in:accepted,clarification,ready,impossible'], 'note' => ['nullable', 'required_if:status,clarification,impossible', 'string', 'max:500']]);
        if (in_array($serviceRequest->status, ['cancelled', 'ready', 'impossible'], true) && $data['status'] !== 'accepted') {
            throw new BusinessException('INVALID_TRANSITION', 'Cette prestation est déjà clôturée.', 409);
        }
        $serviceRequest->forceFill(['status' => $data['status'], 'response_note' => $data['note'] ?? null, 'assignee_id' => $this->user()->id, 'updated_by' => $this->user()->id])->save();
        $reservation = $serviceRequest->occurrence->reservation;
        $labels = ['accepted' => 'acceptée', 'clarification' => 'précision demandée', 'ready' => 'prête', 'impossible' => 'impossible'];
        $notifier->notify($reservation->organizer, 'service.'.$data['status'], 'Prestation '.$labels[$data['status']], $serviceRequest->serviceType->name.' · '.$reservation->reference, "/reservations/{$reservation->id}", 'reservation', $reservation->id, 'svc-'.$serviceRequest->id.'-'.$data['status']);
        $this->audit()->log('service.'.$data['status'], $serviceRequest, $data['note'] ?? null, power: 'prestations', objectLabel: $reservation->reference);

        return response()->json(['data' => ['id' => $serviceRequest->id, 'status' => $serviceRequest->status]]);
    }

    /**
     * Liste de contrôle d'ouverture et de remise en état (SAL-14, P1), constats horodatés.
     */
    public function checklist(Request $request, ReservationOccurrence $occurrence): JsonResponse
    {
        $this->authorizePermission('services.process');
        $data = $request->validate(['items' => ['required', 'array'], 'items.*.id' => ['nullable', 'uuid'], 'items.*.phase' => ['required', 'in:opening,closing'], 'items.*.label' => ['required', 'string', 'max:200'], 'items.*.done' => ['boolean'], 'items.*.note' => ['nullable', 'string', 'max:200']]);
        // Un pouvoir « prestations » limité à certains types ne couvre que les réunions qui comportent l'un de ces types.
        if ($this->access()->powerScope($this->user(), 'prestations') !== null
            && ! $this->scoped(ServiceRequest::query()->where('occurrence_id', $occurrence->id))->exists()) {
            $this->deny('Liste de contrôle d’une réunion sans prestation dans le périmètre du pouvoir');
        }
        foreach ($data['items'] as $item) {
            $row = isset($item['id']) ? ChecklistItem::query()->where('occurrence_id', $occurrence->id)->findOrFail($item['id']) : new ChecklistItem(['occurrence_id' => $occurrence->id]);
            $row->fill(['phase' => $item['phase'], 'label' => $item['label'], 'note' => $item['note'] ?? null]);
            if (! empty($item['done']) && ! $row->done_at) {
                $row->done_at = now();
                $row->done_by = $this->user()->id;
            }
            $row->save();
        }
        $this->audit()->log('service.checklist_updated', $occurrence->reservation, power: 'prestations');

        return response()->json(['data' => $occurrence->checklistItems()->orderBy('phase')->get()]);
    }

    /**
     * @param  Builder<ServiceRequest>  $query
     * @return Builder<ServiceRequest>
     */
    private function scoped(Builder $query): Builder
    {
        $scope = $this->access()->powerScope($this->user(), 'prestations');
        if ($scope === null) {
            return $query;
        }
        $types = $scope['service_types'] ?? [];

        return $query->whereHas('serviceType', fn ($q) => $q->whereIn('code', $types ?: ['__none__']));
    }
}
