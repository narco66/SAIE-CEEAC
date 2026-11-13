<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ReservationOccurrence;
use App\Models\User;
use App\Models\Visit;
use App\Support\BusinessException;
use App\Support\Present;
use App\Support\Reference;
use Carbon\CarbonImmutable;
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
        $request->validate(['status' => ['nullable', 'in:draft,message_draft,planned,done,cancelled'], 'q' => ['nullable', 'string', 'max:100']]);
        // UX-03 : liste paginée et filtrée côté serveur, triée par date de la réunion liée.
        $query = Visit::query()->with(['occurrence.room.floor', 'occurrence.reservation', 'content.languages'])
            ->when(! $request->boolean('past'), fn ($q) => $q->where(fn ($w) => $w->whereNull('occurrence_id')->orWhereHas('occurrence', fn ($o) => $o->where('ends_at', '>', now()))))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'ilike', '%'.addcslashes($t, '%_\\').'%')->orWhere('institution', 'ilike', '%'.addcslashes($t, '%_\\').'%')->orWhere('reference', 'ilike', '%'.addcslashes($t, '%_\\').'%')))
            ->orderBy(ReservationOccurrence::query()->select('starts_at')->whereColumn('reservation_occurrences.id', 'visits.occurrence_id'))->orderBy('created_at');

        return $this->paginated($query, $request, fn (Visit $v) => $this->present($v), 100);
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
        // ACC-02, SAL-09 : une visite ne se rattache qu'à une réunion confirmée et non confidentielle (sinon fuite d'objet et d'horaire).
        if ($data['occurrence_id'] ?? null) {
            $occurrence = ReservationOccurrence::query()->with('reservation')->findOrFail($data['occurrence_id']);
            if ($occurrence->reservation->confidential || ! in_array($occurrence->status, ['confirmed', 'in_progress'], true)) {
                throw new BusinessException('OCCURRENCE_NOT_ELIGIBLE', 'Cette réunion ne peut pas accueillir une visite : elle est confidentielle ou n’est pas confirmée.', 422);
            }
        }
        $visit = Visit::query()->create($data + ['reference' => Reference::next('VIS'), 'status' => 'draft', 'created_by' => $this->user()->id]);
        $this->audit()->log('visit.created', $visit);

        return response()->json(['data' => $this->present($visit)], 201);
    }

    public function update(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizePermission('visits.manage');
        // SEC-02 : une visite n'est modifiée que par son auteur ou par un gestionnaire des visites couvrant la structure de l'auteur.
        $scope = $this->access()->structureScope($this->user(), 'visits.manage');
        $creatorStructure = $visit->created_by ? User::query()->whereKey($visit->created_by)->value('structure_id') : null;
        if ($visit->created_by !== $this->user()->id && $scope !== null && ! in_array($creatorStructure, $scope, true)) {
            $this->deny('Visite hors du périmètre de gestion des visites');
        }
        $this->expectVersionIfSent($request, $visit);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:160'], 'institution' => ['nullable', 'string', 'max:160'], 'visitors_count' => ['nullable', 'integer', 'min:1'], 'notes' => ['nullable', 'string', 'max:1000'], 'status' => ['sometimes', 'in:draft,message_draft,planned,done,cancelled'], 'reason' => ['nullable', 'string', 'max:300']]);
        if (in_array($visit->status, ['done', 'cancelled'], true)) {
            throw new BusinessException('INVALID_TRANSITION', 'Une visite terminée ou annulée n’est plus modifiable.', 409);
        }
        // §30 : transitions bornées ; l'annulation exige un motif, inscrit au journal.
        $allowed = ['draft' => ['message_draft', 'planned', 'cancelled'], 'message_draft' => ['draft', 'planned', 'cancelled'], 'planned' => ['message_draft', 'done', 'cancelled']];
        if (isset($data['status']) && $data['status'] !== $visit->status && ! in_array($data['status'], $allowed[$visit->status] ?? [], true)) {
            throw new BusinessException('INVALID_TRANSITION', 'Changement d’état non autorisé pour cette visite.', 409);
        }
        if (($data['status'] ?? null) === 'cancelled' && mb_strlen(trim((string) ($data['reason'] ?? ''))) < 5) {
            throw new BusinessException('REASON_REQUIRED', 'Le motif de l’annulation est obligatoire.', 422);
        }
        $changes = collect($data)->except('reason')->all();
        $before = $visit->only(array_keys($changes));
        $visit->update($changes);
        $this->audit()->log(($changes['status'] ?? null) === 'cancelled' ? 'visit.cancelled' : 'visit.updated', $visit, $data['reason'] ?? null, before: $before, after: $changes);

        return $this->show($visit);
    }

    /**
     * Les visites n'ont pas de colonne de version : la date de dernière modification sert de témoin facultatif (If-Unmodified-Since).
     */
    private function expectVersionIfSent(Request $request, Visit $visit): void
    {
        $since = $request->header('If-Unmodified-Since');
        if ($since && $visit->updated_at && $visit->updated_at->greaterThan(CarbonImmutable::parse($since)->addSecond())) {
            throw new BusinessException('VERSION_CONFLICT', 'Cette visite a été modifiée entre-temps. Rechargez-la avant de continuer.', 409);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Visit $v): array
    {
        $o = $v->occurrence;

        return [
            'id' => $v->id, 'reference' => $v->reference, 'name' => $v->name, 'institution' => $v->institution, 'visitors_count' => $v->visitors_count, 'status' => $v->status,
            'languages' => $v->languages ?? [], 'flags' => $v->flags ?? [], 'notes' => $v->notes, 'updated_at' => Present::date($v->updated_at),
            'occurrence' => $o ? ['id' => $o->id, 'status' => $o->status, 'starts_at' => Present::date($o->starts_at), 'ends_at' => Present::date($o->ends_at), 'room' => $o->room->name, 'floor' => $o->room->floor->label, 'reservation_id' => $o->reservation_id, 'reservation_reference' => $o->reservation->reference, 'confidential' => $o->reservation->confidential] : null,
            'content' => $v->content ? ['id' => $v->content->id, 'reference' => $v->content->reference, 'status' => $v->content->status, 'languages' => $v->content->languages->map(fn ($l) => ['lang' => $l->lang, 'status' => $l->status, 'title' => $l->title])] : null,
        ];
    }
}
