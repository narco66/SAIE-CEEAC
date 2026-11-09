<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\ContentVersion;
use App\Models\DeviceReceipt;
use App\Models\FallbackContent;
use App\Models\Manifest;
use App\Models\Screen;
use App\Services\CampaignService;
use App\Services\ManifestBuilder;
use App\Services\ScheduleResolver;
use App\Support\BusinessException;
use App\Support\CampaignPriority;
use App\Support\Present;
use App\Support\Reference;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Campagnes et listes de lecture (V20, V21), programme (V22), suivi de diffusion (V24), contenus de repli (PUB-11).
 */
class CampaignController extends Controller
{
    public function __construct(private CampaignService $campaigns) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('campaigns.view');
        $query = Campaign::query()->with(['languages', 'items.content', 'targets', 'creator'])
            // Les campagnes archivées n'apparaissent que sur demande explicite (statut « archived »).
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)), fn ($q) => $q->where('status', '!=', 'archived'))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'ilike', "%$t%")->orWhere('reference', 'ilike', "%$t%")))
            ->when($request->query('from'), fn ($q, $f) => $q->where('ends_at', '>', $f))
            ->when($request->query('to'), fn ($q, $t) => $q->where('starts_at', '<', $t));
        $this->applySort($query, $request, ['starts_at', 'ends_at', 'priority', 'name', 'updated_at'], 'starts_at');

        return $this->paginated($query, $request, function (Campaign $c): array {
            $data = Present::campaign($c);
            $data['screens'] = $this->campaigns->countTargets($c->targets->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->all());
            $data['receipts'] = $this->receiptRate($c);

            return $data;
        });
    }

    /**
     * §12.1 : niveaux de priorité métier et seuil du niveau urgent, pour l'assistant de campagne.
     */
    public function priorityLevels(): JsonResponse
    {
        $this->authorizePermission('campaigns.view');

        return response()->json(['data' => CampaignPriority::meta($this->user())]);
    }

    /**
     * État comparable d'une campagne pour le journal (RG-008).
     *
     * @return array<string, mixed>
     */
    private function snapshot(Campaign $campaign): array
    {
        $campaign->load(['items', 'languages', 'targets']);

        return collect($campaign->only(['name', 'kind', 'priority', 'starts_at', 'ends_at', 'days', 'time_from', 'time_to', 'slide_duration_s']))
            ->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v)->all() + [
                'targets' => $campaign->targets->map(fn ($t) => $t->target_type.':'.$t->target_id)->sort()->values()->all(),
                'items' => $campaign->items->sortBy('position')->pluck('content_id')->values()->all(),
                'languages' => $campaign->languages->sortBy('rank')->pluck('lang')->values()->all(),
            ];
    }

    public function show(Campaign $campaign): JsonResponse
    {
        $this->authorizePermission('campaigns.view');
        $campaign->load(['languages', 'items.content.languages', 'targets', 'creator', 'publisher']);

        return response()->json(['data' => Present::campaign($campaign) + [
            'preview' => $this->campaigns->preview($this->draftFrom($campaign), $this->screenScopeFor('campaigns.view')),
            'approved_languages' => $campaign->items->mapWithKeys(fn ($i) => [$i->content_id => ContentVersion::query()->where('content_id', $i->content_id)->distinct()->pluck('lang')]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('campaigns.manage');
        $data = $this->validated($request, true);
        CampaignPriority::assertAllowed((int) $data['priority'], $this->user());
        $this->campaigns->assertTargetsExist($data['targets'] ?? []);
        $this->campaigns->assertTargetsInScope($data['targets'] ?? [], $this->user(), 'campaigns.manage');
        $campaign = DB::transaction(function () use ($data): Campaign {
            $campaign = new Campaign(collect($data)->except(['items', 'languages', 'targets', 'publish'])->all() + ['reference' => Reference::next('CMP'), 'status' => 'draft', 'created_by' => $this->user()->id]);
            $this->campaigns->assertEventRules($campaign);
            $campaign->save();
            $this->sync($campaign, $data);
            $this->audit()->log('campaign.created', $campaign);
            if (! empty($data['publish'])) {
                $this->campaigns->publish($campaign, $this->user());
            }

            return $campaign;
        });

        return $this->show($campaign->refresh())->setStatusCode(201);
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizePermission('campaigns.manage');
        if (! in_array($campaign->status, ['draft', 'suspended'], true)) {
            throw new BusinessException('CAMPAIGN_LOCKED', 'Suspendez la campagne avant de la modifier.', 409);
        }
        $this->expectVersion($request, $campaign);
        $this->assertCampaignInScope($campaign);
        $data = $this->validated($request, false);
        if (isset($data['priority']) && (int) $data['priority'] !== (int) $campaign->priority) {
            CampaignPriority::assertAllowed((int) $data['priority'], $this->user());
        }
        if (isset($data['targets'])) {
            $this->campaigns->assertTargetsExist($data['targets']);
            $this->campaigns->assertTargetsInScope($data['targets'], $this->user(), 'campaigns.manage');
        }
        $before = $this->snapshot($campaign);
        DB::transaction(function () use ($campaign, $data): void {
            $campaign->fill(collect($data)->except(['items', 'languages', 'targets', 'publish'])->all());
            $this->campaigns->assertEventRules($campaign);
            $campaign->version++;
            $campaign->save();
            $this->sync($campaign, $data);
        });
        // RG-008, §12-h : seules les valeurs modifiées sont journalisées, avant et après (cibles, contenus et langues compris).
        $after = $this->snapshot($campaign->refresh());
        $changed = collect($after)->keys()->filter(fn (string $k) => $before[$k] !== $after[$k])->values()->all();
        $this->audit()->log('campaign.updated', $campaign, before: collect($before)->only($changed)->all(), after: collect($after)->only($changed)->all(), version: $campaign->version);

        return $this->show($campaign->refresh());
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizePermission('campaigns.manage', 'alerts.manage');
        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1'], 'targets.*.type' => ['required', 'in:screen,group,zone,floor,all'], 'targets.*.id' => ['nullable', 'uuid'],
            'items' => ['nullable', 'array'], 'items.*.content_id' => ['required', 'uuid'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date'],
            'priority' => ['nullable', 'integer'], 'id' => ['nullable', 'uuid'],
            // §12 : jours et créneau du brouillon, pour ne signaler que les conflits réels.
            'days' => ['nullable', 'array'], 'days.*' => ['integer', 'between:1,7'], 'time_from' => ['nullable', 'date_format:H:i'], 'time_to' => ['nullable', 'date_format:H:i'],
        ]);

        return response()->json(['data' => $this->campaigns->preview($data, $this->screenScopeFor('campaigns.manage', 'alerts.manage'))]);
    }

    public function publish(Campaign $campaign): JsonResponse
    {
        $this->authorizePermission('campaigns.manage');
        $this->assertCampaignInScope($campaign);
        $this->campaigns->publish($campaign, $this->user());

        return $this->show($campaign->refresh());
    }

    public function suspend(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizePermission('campaigns.manage');
        $this->assertCampaignInScope($campaign);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->campaigns->suspend($campaign, $this->user(), $data['reason']);

        return $this->show($campaign->refresh());
    }

    /**
     * PUB-07 : archivage d'une campagne terminée, suspendue ou restée en brouillon ; elle disparaît des listes courantes,
     * son suivi de diffusion et son journal restent consultables.
     */
    public function archive(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizePermission('campaigns.manage');
        $this->assertCampaignInScope($campaign);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $ended = $campaign->status === 'expired' || ($campaign->ends_at && $campaign->ends_at->isPast());
        if (! $ended && ! in_array($campaign->status, ['draft', 'suspended'], true)) {
            throw new BusinessException('INVALID_TRANSITION', 'Seule une campagne terminée, suspendue ou en brouillon peut être archivée : suspendez-la d’abord.', 409);
        }
        $campaign->forceFill(['status' => 'archived', 'version' => $campaign->version + 1])->save();
        $this->audit()->log('campaign.archived', $campaign, $data['reason'] ?? null, version: $campaign->version);

        return $this->show($campaign->refresh());
    }

    /**
     * La reprise réévalue l'approbation des versions et les dates (PUB-02).
     */
    public function resume(Campaign $campaign): JsonResponse
    {
        $this->authorizePermission('campaigns.manage');
        $this->assertCampaignInScope($campaign);
        if ($campaign->status !== 'suspended') {
            throw new BusinessException('INVALID_TRANSITION', 'Seule une campagne suspendue peut reprendre.', 409);
        }
        $this->campaigns->publish($campaign, $this->user());

        return $this->show($campaign->refresh());
    }

    /**
     * Suivi par terminal et par niveau de preuve (PUB-07, annexe C). « Écran visible » n'est jamais déduit du heartbeat.
     */
    public function delivery(Campaign $campaign): JsonResponse
    {
        $this->authorizePermission('campaigns.view');
        $campaign->load('targets');
        $screens = $this->campaigns->resolveTargets($campaign->targets->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->all());
        // HAB-01 : le suivi de diffusion ne détaille que les écrans du périmètre de l'utilisateur.
        $scope = $this->screenScopeFor('campaigns.view', 'reports.view');
        if ($scope !== null) {
            $screens = $screens->filter(fn (Screen $s) => in_array($s->id, $scope, true))->values();
        }
        $rows = $screens->map(function (Screen $screen) use ($campaign): array {
            $device = $screen->activeDevice;
            // Transmission durable (indépendante de la purge des manifestes) ; à défaut, manifestes encore conservés.
            $transmission = $device ? DB::table('campaign_transmissions')->where('device_id', $device->id)->where('campaign_id', $campaign->id)->first() : null;
            $manifest = $transmission ? (object) ['version' => (int) $transmission->first_manifest_version, 'generated_at' => $transmission->first_transmitted_at]
                : ($device ? Manifest::query()->where('device_id', $device->id)->whereRaw("payload->'schedule' @> ?::jsonb", [json_encode([['campaign_id' => $campaign->id]])])->orderBy('version')->first(['version', 'generated_at']) : null);
            $receipt = fn (string $kind, bool $byCampaign = false) => $device && $manifest ? DeviceReceipt::query()->where('device_id', $device->id)->where('kind', $kind)
                ->when($byCampaign, fn ($q) => $q->where('ref_type', 'campaign')->where('ref_id', $campaign->id), fn ($q) => $q->where('manifest_version', '>=', $manifest->version))
                ->orderByDesc('occurred_at')->value('occurred_at') : null;
            $received = $receipt('manifest_received');
            $loaded = $receipt('content_loaded');
            $activated = $receipt('activated');
            $played = $receipt('played', true);

            return [
                'screen' => ['id' => $screen->id, 'code' => $screen->code, 'name' => $screen->name, 'floor' => $screen->location->floor->label],
                'device' => $device ? ['id' => $device->id, 'code' => $device->code, 'network_status' => $device->computedNetworkStatus(), 'last_seen_at' => Present::date($device->last_seen_at)] : null,
                'scheduled' => (bool) $campaign->published_at, 'manifest_version' => $manifest?->version,
                'transmitted_at' => $manifest ? Present::date(Carbon::parse($manifest->generated_at)) : null,
                'received_at' => Present::date($received), 'loaded_at' => Present::date($loaded), 'activated_at' => Present::date($activated), 'played_at' => Present::date($played),
                'screenshot_at' => $device ? Present::date(DB::table('device_screenshots')->where('device_id', $device->id)->where('status', 'captured')->max('captured_at') ? Carbon::parse(DB::table('device_screenshots')->where('device_id', $device->id)->where('status', 'captured')->max('captured_at')) : null) : null,
                'panel_visible' => $screen->control_channel === 'none' || ! $screen->panel_state ? 'not_measured' : $screen->panel_state,
                'errors' => $device?->last_errors ?? [],
            ];
        });
        $summary = [
            'targeted' => $rows->count(), 'received' => $rows->whereNotNull('received_at')->count(), 'loaded' => $rows->whereNotNull('loaded_at')->count(),
            'activated' => $rows->whereNotNull('activated_at')->count(), 'played' => $rows->whereNotNull('played_at')->count(),
            'offline' => $rows->filter(fn ($r) => in_array($r['device']['network_status'] ?? 'unpaired', ['suspect', 'unavailable', 'unpaired'], true))->count(),
            'transmitted' => $rows->whereNotNull('transmitted_at')->count(),
            'plays_declared' => DeviceReceipt::playsCount(DeviceReceipt::query()->where('kind', 'played')->where('ref_type', 'campaign')->where('ref_id', $campaign->id)),
        ];

        return response()->json(['data' => ['campaign' => Present::campaign($campaign), 'rows' => $rows->values(), 'summary' => $summary, 'notice' => 'Un accusé de lecture déclarée ne prouve ni l’allumage de l’écran, ni qu’une personne a vu le message.']]);
    }

    public function program(Request $request): JsonResponse
    {
        $this->authorizePermission('campaigns.view');
        $request->validate(['screen_id' => ['required', 'uuid', 'exists:screens,id'], 'at' => ['nullable', 'date']]);

        return app(ScreenController::class)->preview($request, Screen::query()->findOrFail($request->query('screen_id')), app(ManifestBuilder::class), app(ScheduleResolver::class));
    }

    public function fallbacks(): JsonResponse
    {
        $this->authorizePermission('campaigns.view');
        $items = FallbackContent::query()->with(['zone', 'contentVersion.content'])->orderByDesc('created_at')->get();

        return response()->json(['data' => $items->map(fn ($f) => [
            'id' => $f->id, 'zone' => ['id' => $f->zone->id, 'name' => $f->zone->name], 'valid_until' => Present::date($f->valid_until), 'is_active' => $f->is_active && $f->valid_until->isFuture(),
            'content' => ['id' => $f->contentVersion->content_id, 'title' => $f->contentVersion->payload['title'] ?? '', 'lang' => $f->contentVersion->lang, 'reference' => $f->contentVersion->content->reference],
        ])]);
    }

    public function storeFallback(Request $request): JsonResponse
    {
        $this->authorizePermission('fallback.manage');
        $data = $request->validate(['zone_id' => ['required', 'uuid', 'exists:display_zones,id'], 'content_version_id' => ['required', 'uuid', 'exists:content_versions,id'], 'valid_until' => ['required', 'date', 'after:now']]);
        $this->campaigns->assertTargetsInScope([['type' => 'zone', 'id' => $data['zone_id']]], $this->user(), 'fallback.manage');
        $version = ContentVersion::query()->findOrFail($data['content_version_id']);
        if ($version->classification !== 'public') {
            throw new BusinessException('FALLBACK_NOT_PUBLIC', 'Un contenu de repli doit être public et approuvé (PUB-05).', 422);
        }
        $fallback = FallbackContent::query()->create($data + ['created_by' => $this->user()->id, 'is_active' => true]);
        $this->audit()->log('fallback.created', $fallback);

        return response()->json(['data' => $fallback], 201);
    }

    public function destroyFallback(FallbackContent $fallback): JsonResponse
    {
        $this->authorizePermission('fallback.manage');
        $this->campaigns->assertTargetsInScope([['type' => 'zone', 'id' => $fallback->zone_id]], $this->user(), 'fallback.manage');
        $fallback->forceFill(['is_active' => false])->save();
        $this->audit()->log('fallback.disabled', $fallback);

        return response()->json(null, 204);
    }

    /**
     * @return array{received: int, targeted: int}
     */
    private function receiptRate(Campaign $campaign): array
    {
        if (! $campaign->published_at) {
            return ['received' => 0, 'targeted' => 0];
        }
        // Transmission durable (indépendante de la purge des manifestes) : lecteurs ayant reçu la campagne, puis accusé un
        // manifeste au moins aussi récent que la première version qui la portait.
        $row = DB::selectOne(<<<'SQL'
            select count(*) as targeted,
                   count(*) filter (where exists (select 1 from device_receipts r where r.device_id = t.device_id and r.kind = 'manifest_received' and r.manifest_version >= t.first_manifest_version)) as received
            from campaign_transmissions t where t.campaign_id = ?
        SQL, [$campaign->id]);

        return ['received' => (int) $row->received, 'targeted' => (int) $row->targeted];
    }

    /**
     * @return array<string, mixed>
     */
    private function draftFrom(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id, 'priority' => $campaign->priority, 'starts_at' => $campaign->starts_at, 'ends_at' => $campaign->ends_at,
            'targets' => $campaign->targets->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->all(),
            'items' => $campaign->items->map(fn ($i) => ['content_id' => $i->content_id])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sync(Campaign $campaign, array $data): void
    {
        if (isset($data['items'])) {
            $campaign->items()->delete();
            foreach (array_values($data['items']) as $position => $item) {
                $campaign->items()->create(['content_id' => $item['content_id'], 'position' => $position + 1, 'duration_s' => $item['duration_s'] ?? null]);
            }
        }
        if (isset($data['languages'])) {
            $campaign->languages()->delete();
            foreach (array_values($data['languages']) as $rank => $lang) {
                DB::table('campaign_languages')->insert(['campaign_id' => $campaign->id, 'lang' => $lang, 'rank' => $rank + 1]);
            }
        }
        if (isset($data['targets'])) {
            $campaign->targets()->delete();
            foreach ($data['targets'] as $target) {
                $campaign->targets()->create(['target_type' => $target['type'], 'target_id' => $target['id']]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$req, 'string', 'max:160'], 'kind' => ['sometimes', 'in:standard,event'], 'priority' => [$req, 'integer', 'between:1,99'],
            'starts_at' => [$req, 'date'], 'ends_at' => [$req, 'date', 'after:starts_at'],
            'days' => ['nullable', 'array'], 'days.*' => ['integer', 'between:1,7'],
            'time_from' => ['nullable', 'date_format:H:i'], 'time_to' => ['nullable', 'date_format:H:i', 'after:time_from'],
            'slide_duration_s' => ['sometimes', 'integer', 'between:10,300'],
            'items' => [$req, 'array', 'min:1', 'max:50'], 'items.*.content_id' => ['required', 'uuid', 'exists:contents,id'], 'items.*.duration_s' => ['nullable', 'integer', 'between:10,600'],
            'languages' => [$req, 'array', 'min:1', 'max:4'], 'languages.*' => ['distinct', 'in:fr,en,pt,es', 'exists:languages,code'],
            'targets' => [$req, 'array', 'min:1'], 'targets.*.type' => ['required', 'in:screen,group,zone,floor'], 'targets.*.id' => ['required', 'uuid'],
            'publish' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * HAB-01 : une campagne n'est gérée que si toutes ses cibles relèvent du site et de la zone de l'habilitation.
     */
    private function assertCampaignInScope(Campaign $campaign): void
    {
        $this->campaigns->assertTargetsInScope($campaign->targets()->get()->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->all(), $this->user(), 'campaigns.manage');
    }
}
