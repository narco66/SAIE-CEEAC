<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Support\Present;
use App\Support\Reference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accueil et protocole : visites et délégations liées à des réservations confirmées (ACC-02, P09).
 */
class VisitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('visits.manage', 'campaigns.view');
        $visits = Visit::query()->with(['occurrence.room.floor', 'occurrence.reservation', 'content.languages'])
            ->when(! $request->boolean('past'), fn ($q) => $q->where(fn ($w) => $w->whereNull('occurrence_id')->orWhereHas('occurrence', fn ($o) => $o->where('ends_at', '>', now()))))
            ->orderBy('created_at')->get()
            ->sortBy(fn (Visit $v) => $v->occurrence?->starts_at)->values();

        return response()->json(['data' => $visits->map(fn (Visit $v) => $this->present($v))]);
    }

    public function show(Visit $visit): JsonResponse
    {
        $this->authorizePermission('visits.manage', 'campaigns.view');

        return response()->json(['data' => $this->present($visit->load(['occurrence.room.floor', 'occurrence.reservation', 'content.languages']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('visits.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'institution' => ['nullable', 'string', 'max:160'], 'visitors_count' => ['nullable', 'integer', 'min:1'], 'occurrence_id' => ['nullable', 'uuid', 'exists:reservation_occurrences,id'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $visit = Visit::query()->create($data + ['reference' => Reference::next('VIS'), 'status' => 'draft', 'created_by' => $this->user()->id]);
        $this->audit()->log('visit.created', $visit);

        return response()->json(['data' => $this->present($visit)], 201);
    }

    public function update(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizePermission('visits.manage');
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:160'], 'institution' => ['nullable', 'string', 'max:160'], 'visitors_count' => ['nullable', 'integer', 'min:1'], 'notes' => ['nullable', 'string', 'max:1000'], 'status' => ['sometimes', 'in:draft,message_draft,planned,done,cancelled']]);
        $visit->update($data);
        $this->audit()->log('visit.updated', $visit, after: $data);

        return $this->show($visit);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Visit $v): array
    {
        $o = $v->occurrence;

        return [
            'id' => $v->id, 'reference' => $v->reference, 'name' => $v->name, 'institution' => $v->institution, 'visitors_count' => $v->visitors_count, 'status' => $v->status,
            'languages' => $v->languages ?? [], 'flags' => $v->flags ?? [], 'notes' => $v->notes,
            'occurrence' => $o ? ['id' => $o->id, 'status' => $o->status, 'starts_at' => Present::date($o->starts_at), 'ends_at' => Present::date($o->ends_at), 'room' => $o->room->name, 'floor' => $o->room->floor->label, 'reservation_id' => $o->reservation_id, 'reservation_reference' => $o->reservation->reference, 'confidential' => $o->reservation->confidential] : null,
            'content' => $v->content ? ['id' => $v->content->id, 'reference' => $v->content->reference, 'status' => $v->content->status, 'languages' => $v->content->languages->map(fn ($l) => ['lang' => $l->lang, 'status' => $l->status, 'title' => $l->title])] : null,
        ];
    }
}
