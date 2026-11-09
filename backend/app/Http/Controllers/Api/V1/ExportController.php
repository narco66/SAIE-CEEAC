<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Services\ExportService;
use App\Support\BusinessException;
use App\Support\Present;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports et traitements (V41) : fichiers privés, téléchargement réautorisé, expiration gérée.
 */
class ExportController extends Controller
{
    public function __construct(private ExportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('dashboard.view');
        Export::query()->where('status', 'ready')->where('expires_at', '<', now())->update(['status' => 'expired']);
        $query = Export::query()->where('requested_by', $this->user()->id)->orderByDesc('created_at')->orderByDesc('id');

        $payload = $this->paginated($query, $request, fn (Export $e) => $this->present($e))->getData(true);
        $payload['meta']['types'] = collect(ExportService::TYPES)->map(fn ($t, $k) => ['type' => $k, 'label' => $t[0], 'formats' => $t[2], 'allowed' => $this->service->permits($this->user(), $k)])->values();

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        // ASVS 5.0 V2.2 et V2.4 : paramètres typés, période bornée à un an, au plus 5 exports en attente par personne.
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(ExportService::TYPES))], 'format' => ['required', 'in:pdf,csv,xlsx'], 'params' => ['nullable', 'array'],
            'params.from' => ['nullable', 'date'], 'params.to' => ['nullable', 'date', 'after_or_equal:params.from'],
            'params.reservation_id' => ['nullable', 'uuid'], 'params.occurrence_id' => ['nullable', 'uuid'], 'params.campaign_id' => ['nullable', 'uuid'], 'params.document_id' => ['nullable', 'uuid'],
            'params.incident_id' => ['nullable', 'uuid'], 'params.actor_id' => ['nullable', 'uuid'], 'params.floor_id' => ['nullable', 'uuid'], 'params.building_id' => ['nullable', 'uuid'], 'params.include_archived' => ['nullable', 'boolean'], 'params.screen_id' => ['nullable', 'uuid'], 'params.months' => ['nullable', 'integer', 'between:1,60'],
            'params.variant' => ['nullable', 'string', 'max:40'], 'params.action' => ['nullable', 'string', 'max:80'], 'params.actor' => ['nullable', 'string', 'max:120'],
            'params.object_type' => ['nullable', 'string', 'max:60'], 'params.outcome' => ['nullable', 'string', 'max:20'], 'params.power' => ['nullable', 'string', 'max:40'],
        ]);
        $from = isset($data['params']['from']) ? CarbonImmutable::parse($data['params']['from']) : null;
        $to = isset($data['params']['to']) ? CarbonImmutable::parse($data['params']['to']) : CarbonImmutable::now();
        if ($from && $from->diffInDays($to, true) > 366) {
            throw new BusinessException('PERIOD_TOO_LONG', 'La période d’un export est limitée à un an.', 422);
        }
        if (Export::query()->where('requested_by', $this->user()->id)->whereIn('status', ['pending', 'processing'])->count() >= 5) {
            throw new BusinessException('TOO_MANY_PENDING_EXPORTS', 'Cinq exports sont déjà en cours de préparation : attendez qu’ils soient prêts.', 429);
        }
        if (! in_array($data['format'], ExportService::TYPES[$data['type']][2], true)) {
            throw new BusinessException('FORMAT_UNAVAILABLE', 'Format non disponible pour ce document.', 422);
        }
        $params = collect($data['params'] ?? [])->only(['reservation_id', 'occurrence_id', 'campaign_id', 'document_id', 'incident_id', 'from', 'to', 'variant', 'action', 'actor', 'actor_id', 'object_type', 'outcome', 'power', 'floor_id', 'building_id', 'include_archived', 'months', 'screen_id'])->all();
        $this->service->assertAllowed($this->user(), $data['type'], $params);
        $export = Export::query()->create(['type' => $data['type'], 'format' => $data['format'], 'params' => $params, 'status' => 'pending', 'requested_by' => $this->user()->id]);
        GenerateExport::dispatch($export->id);
        $this->audit()->log('export.requested', $export, objectLabel: ExportService::TYPES[$data['type']][0]);

        return response()->json(['data' => $this->present($export->refresh())], 202);
    }

    public function show(Export $export): JsonResponse
    {
        $this->assertOwn($export);

        return response()->json(['data' => $this->present($export)]);
    }

    public function download(Export $export): StreamedResponse
    {
        $this->assertOwn($export);
        if ($export->status !== 'ready' || ! $export->file_key || $export->expires_at?->isPast()) {
            throw new BusinessException('EXPORT_UNAVAILABLE', 'Cet export n’est plus disponible : relancez sa génération.', 410);
        }
        $this->service->assertAllowed($this->user(), $export->type, $export->params ?? []);
        $this->audit()->log('export.downloaded', $export, objectLabel: $export->file_name);

        return Storage::disk('local')->download($export->file_key, $export->file_name, ['Cache-Control' => 'private, no-store', 'Content-Type' => match ($export->format) {
            'pdf' => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'text/csv; charset=UTF-8',
        }]);
    }

    public function cancel(Export $export): JsonResponse
    {
        $this->assertOwn($export);
        if ($export->status !== 'pending') {
            throw new BusinessException('INVALID_TRANSITION', 'Seul un export en attente peut être annulé.', 409);
        }
        $export->forceFill(['status' => 'cancelled'])->save();

        return response()->json(['data' => $this->present($export)]);
    }

    public function retry(Export $export): JsonResponse
    {
        $this->assertOwn($export);
        if (! in_array($export->status, ['failed', 'expired', 'cancelled'], true)) {
            throw new BusinessException('INVALID_TRANSITION', 'Cet export ne peut pas être relancé.', 409);
        }
        $export->forceFill(['status' => 'pending', 'progress' => 0, 'error' => null, 'file_key' => null])->save();
        GenerateExport::dispatch($export->id);

        return response()->json(['data' => $this->present($export)], 202);
    }

    private function assertOwn(Export $export): void
    {
        abort_unless($export->requested_by === $this->user()->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Export $e): array
    {
        $expired = $e->status === 'ready' && $e->expires_at?->isPast();

        return [
            'id' => $e->id, 'type' => $e->type, 'label' => ExportService::TYPES[$e->type][0] ?? $e->type, 'format' => $e->format, 'params' => $e->params,
            'status' => $expired ? 'expired' : $e->status, 'progress' => $e->progress, 'file_name' => $e->file_name, 'size' => $e->size, 'error' => $e->error,
            'classification' => $e->classification, 'created_at' => Present::date($e->created_at), 'completed_at' => Present::date($e->completed_at), 'expires_at' => Present::date($e->expires_at),
        ];
    }
}
