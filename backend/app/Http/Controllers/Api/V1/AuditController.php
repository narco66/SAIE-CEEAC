<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ActionLabel;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal et traçabilité (V38, SEC-04) : lecture seule, secrets masqués.
 */
class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('audit.view');
        $query = AuditLog::query()
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'ilike', "$a%"))
            ->when($request->query('actor'), fn ($q, $a) => $q->where('actor_label', 'ilike', "%$a%"))
            ->when($request->query('object_type'), fn ($q, $t) => $q->where('object_type', $t))
            ->when($request->query('object_id'), fn ($q, $t) => $q->where('object_id', $t))
            ->when($request->query('outcome'), fn ($q, $o) => $q->where('outcome', $o))
            ->when($request->query('power'), fn ($q, $p) => $q->where('power', $p))
            ->when($request->query('from'), fn ($q, $f) => $q->where('occurred_at', '>=', $f))
            ->when($request->query('to'), fn ($q, $t) => $q->where('occurred_at', '<=', $t))
            ->orderByDesc('occurred_at')->orderByDesc('id');

        return $this->paginated($query, $request, fn (AuditLog $a) => [
            'id' => $a->id, 'occurred_at' => Present::date($a->occurred_at), 'actor' => $a->actor_label, 'actor_type' => $a->actor_type, 'role' => $a->actor_role, 'power' => $a->power,
            'action' => $a->action, 'action_label' => ActionLabel::for($a->action), 'object_type' => $a->object_type, 'object_id' => $a->object_id, 'object_label' => $a->object_label, 'lang' => $a->lang, 'version' => $a->version,
            'reason' => $a->reason, 'outcome' => $a->outcome, 'request_id' => $a->request_id, 'ip' => $a->ip, 'before' => $a->before, 'after' => $a->after,
        ], 50);
    }
}
