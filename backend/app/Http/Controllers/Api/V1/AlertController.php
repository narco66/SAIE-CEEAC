<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Screen;
use App\Services\CampaignService;
use App\Services\ManifestBuilder;
use App\Services\ScheduleResolver;
use App\Support\BusinessException;
use App\Support\Present;
use App\Support\Reference;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Centre des alertes (V23, P05). Une alerte préempte les programmes ordinaires et expire automatiquement ;
 * un lecteur déconnecté ne peut pas la recevoir, ce que l'interface signale explicitement.
 */
class AlertController extends Controller
{
    public function __construct(private CampaignService $campaigns, private ManifestBuilder $manifests) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('alerts.view', 'alerts.manage');
        Alert::query()->where('status', 'active')->where('expires_at', '<=', now())->update(['status' => 'expired', 'updated_at' => now()]);
        $query = Alert::query()->with(['author', 'revoker', 'targets', 'receipts'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('starts_at')->orderByDesc('id');
        $response = $this->paginated($query, $request, fn (Alert $a) => Present::alert($a) + ['scope_label' => $this->scopeLabel($a)]);
        $payload = $response->getData(true);
        $payload['meta']['active'] = Alert::query()->where('status', 'active')->where('starts_at', '<=', now())->where('expires_at', '>', now())->count();
        $payload['meta']['can_publish'] = $this->can('alerts.manage');
        $payload['meta']['max_duration_min'] = (int) config('saie.alerts.max_duration_min');
        $payload['meta']['default_duration_min'] = (int) config('saie.alerts.default_duration_min');
        $power = $this->access()->powers($this->user())->firstWhere('power', 'urgence');
        $payload['meta']['power_until'] = $power ? Present::date($power->ends_at) : null;

        return response()->json($payload);
    }

    public function show(Alert $alert): JsonResponse
    {
        $this->authorizePermission('alerts.view', 'alerts.manage');
        $alert->load(['author', 'revoker', 'targets', 'receipts.device.screen']);
        $screens = $this->screensFor($alert->targets->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->all());

        return response()->json(['data' => Present::alert($alert) + [
            'scope_label' => $this->scopeLabel($alert),
            'screens' => $screens->map(function (Screen $s) use ($alert): array {
                $receipt = $s->activeDevice ? $alert->receipts->firstWhere('device_id', $s->activeDevice->id) : null;

                return ['code' => $s->code, 'name' => $s->name, 'floor' => $s->location->floor->label, 'network_status' => $s->activeDevice?->computedNetworkStatus() ?? 'unpaired',
                    'received_at' => Present::date($receipt?->received_at), 'activated_at' => Present::date($receipt?->activated_at), 'in_window' => $this->inWindow($s)];
            })->values(),
        ]]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizePermission('alerts.manage');
        $data = $request->validate(['targets' => ['required', 'array', 'min:1'], 'targets.*.type' => ['required', 'in:all,zone,floor,screen,group'], 'targets.*.id' => ['nullable', 'uuid']]);
        $screens = $this->screensFor($data['targets']);

        return response()->json(['data' => $this->summary($screens)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('alerts.manage');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'], 'message' => ['required', 'string', 'max:280'],
            'translations' => ['nullable', 'array'], 'translations.*.title' => ['required', 'string', 'max:120'], 'translations.*.message' => ['required', 'string', 'max:280'],
            'severity' => ['required', 'in:critical,warning,info,exercise'],
            'duration_min' => ['required', 'integer', 'between:5,'.config('saie.alerts.max_duration_min')],
            'starts_at' => ['nullable', 'date', 'after_or_equal:-1 minute'],
            'justification' => ['required', 'string', 'min:10', 'max:500'],
            'targets' => ['required', 'array', 'min:1'], 'targets.*.type' => ['required', 'in:all,zone,floor,screen,group'], 'targets.*.id' => ['nullable', 'required_unless:targets.*.type,all', 'uuid'],
            'confirm' => ['accepted'],
        ]);
        $this->assertZoneScope($data['targets']);
        $this->campaigns->assertTargetsInScope($data['targets'], $this->user(), 'alerts.manage');
        $screens = $this->screensFor($data['targets']);
        $summary = $this->summary($screens);
        $start = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : now();

        $alert = DB::transaction(function () use ($data, $start, $summary): Alert {
            $alert = Alert::query()->create([
                'reference' => Reference::next('ALT'), 'title' => $data['title'], 'message' => $data['message'], 'translations' => $data['translations'] ?? null,
                'severity' => $data['severity'], 'starts_at' => $start, 'expires_at' => $start->copy()->addMinutes($data['duration_min']),
                'author_id' => $this->user()->id, 'justification' => $data['justification'], 'status' => 'active',
                'target_count' => $summary['targeted'], 'offline_at_publish' => $summary['offline'],
            ]);
            foreach ($data['targets'] as $target) {
                $alert->targets()->create(['target_type' => $target['type'], 'target_id' => $target['id'] ?? null]);
            }
            $this->audit()->log('alert.published', $alert, $data['justification'], power: 'urgence', after: ['severity' => $alert->severity, 'expires_at' => $alert->expires_at->toAtomString(), 'targeted' => $summary['targeted'], 'offline' => $summary['offline']]);

            return $alert;
        });

        return response()->json(['data' => Present::alert($alert->load(['author', 'targets', 'receipts'])), 'summary' => $summary], 201);
    }

    public function extend(Request $request, Alert $alert): JsonResponse
    {
        $this->authorizePermission('alerts.manage');
        $this->assertAlertInScope($alert);
        $data = $request->validate(['minutes' => ['required', 'integer', 'between:5,120'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        if ($alert->status !== 'active' || $alert->expires_at->isPast()) {
            throw new BusinessException('ALERT_NOT_ACTIVE', 'Cette alerte n’est plus active.', 409);
        }
        $total = $alert->starts_at->diffInMinutes($alert->expires_at->copy()->addMinutes($data['minutes']), true);
        if ($total > config('saie.alerts.max_duration_min')) {
            throw new BusinessException('ALERT_TOO_LONG', 'Durée maximale d’une alerte dépassée.', 422);
        }
        $before = $alert->expires_at->toAtomString();
        $extensions = $alert->extensions ?? [];
        $extensions[] = ['by' => $this->user()->name, 'at' => now()->toAtomString(), 'minutes' => $data['minutes'], 'reason' => $data['reason'], 'previous_expiry' => $before];
        $alert->forceFill(['expires_at' => $alert->expires_at->copy()->addMinutes($data['minutes']), 'extensions' => $extensions])->save();
        $this->audit()->log('alert.extended', $alert, $data['reason'], ['expires_at' => $before], ['expires_at' => $alert->expires_at->toAtomString()], 'urgence');

        return $this->show($alert->refresh());
    }

    public function revoke(Request $request, Alert $alert): JsonResponse
    {
        $this->authorizePermission('alerts.manage');
        $this->assertAlertInScope($alert);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        if ($alert->status !== 'active') {
            throw new BusinessException('ALERT_NOT_ACTIVE', 'Cette alerte n’est plus active.', 409);
        }
        $alert->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $this->user()->id, 'revoke_reason' => $data['reason']])->save();
        $this->audit()->log('alert.revoked', $alert, $data['reason'], power: 'urgence');

        return $this->show($alert->refresh());
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     * @return Collection<int, Screen>
     */
    private function screensFor(array $targets): Collection
    {
        return $this->campaigns->resolveTargets(array_map(fn ($t) => ['type' => $t['type'], 'id' => $t['id'] ?? null], $targets));
    }

    /**
     * @param  Collection<int, Screen>  $screens
     * @return array<string, mixed>
     */
    private function summary(Collection $screens): array
    {
        $rows = $screens->map(fn (Screen $s) => ['code' => $s->code, 'name' => $s->name, 'network_status' => $s->activeDevice?->computedNetworkStatus() ?? 'unpaired', 'in_window' => $this->inWindow($s)]);

        return [
            'targeted' => $rows->count(),
            'online' => $rows->where('network_status', 'online')->count(),
            'offline' => $rows->whereIn('network_status', ['suspect', 'unavailable', 'unpaired', 'unknown'])->count(),
            'off_window' => $rows->where('in_window', false)->count(),
            'offline_screens' => $rows->whereIn('network_status', ['suspect', 'unavailable', 'unpaired', 'unknown'])->pluck('code')->values(),
            'notice' => 'La réception ne peut pas être garantie sur un lecteur déconnecté ou hors plage de fonctionnement.',
        ];
    }

    private function inWindow(Screen $screen): bool
    {
        $window = $screen->effectiveOperatingWindow();

        return app(ScheduleResolver::class)->withinOperatingWindow($window, [], CarbonImmutable::now(config('saie.timezone')));
    }

    /**
     * HAB-01, HAB-03 : prolonger ou révoquer une alerte exige que ses cibles relèvent de l'habilitation et du périmètre « urgence ».
     */
    private function assertAlertInScope(Alert $alert): void
    {
        $targets = $alert->targets()->get()->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->all();
        $this->assertZoneScope($targets);
        $this->campaigns->assertTargetsInScope($targets, $this->user(), 'alerts.manage');
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     */
    private function assertZoneScope(array $targets): void
    {
        $scope = $this->access()->powerScope($this->user(), 'urgence');
        if ($scope === null) {
            return;
        }
        $zones = $scope['zone_ids'] ?? [];
        foreach ($targets as $t) {
            if ($t['type'] !== 'zone' || ! in_array($t['id'], $zones, true)) {
                $this->deny('Cible hors du périmètre du pouvoir « urgence »');
            }
        }
    }

    private function scopeLabel(Alert $alert): string
    {
        $types = $alert->targets->pluck('target_type');
        if ($types->contains('all')) {
            return 'Tous les écrans';
        }

        return $alert->target_count.' écran(s) · '.$types->unique()->map(fn ($t) => ['zone' => 'zones', 'floor' => 'niveaux', 'screen' => 'écrans', 'group' => 'groupes'][$t] ?? $t)->implode(', ');
    }
}
