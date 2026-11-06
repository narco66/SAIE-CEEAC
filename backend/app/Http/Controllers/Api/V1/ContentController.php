<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessLink;
use App\Models\Approval;
use App\Models\AssetVersion;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Language;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Services\ContentAccess;
use App\Services\ContentWorkflow;
use App\Support\BusinessException;
use App\Support\Present;
use App\Support\Reference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Contenus institutionnels multilingues (V17 à V19, PUB-01, PUB-02, PUB-08).
 */
class ContentController extends Controller
{
    public function __construct(private ContentWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('contents.view');
        $query = $this->visible(Content::query())->with(['category', 'owner', 'author', 'template', 'languages'])
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('category_id'), fn ($q, $c) => $q->where('category_id', $c))
            ->when($request->query('classification'), fn ($q, $c) => $q->where('classification', $c))
            ->when($request->query('lang'), fn ($q, $l) => $q->whereHas('languages', fn ($w) => $w->where('lang', $l)))
            ->when($request->query('lang_status'), fn ($q, $s) => $q->whereHas('languages', fn ($w) => $w->where('status', $s)))
            ->when($request->boolean('mine'), fn ($q) => $q->where('author_id', $this->user()->id))
            ->when($request->boolean('approved_only'), fn ($q) => $q->whereHas('versions')->whereNotIn('status', ['archived', 'expired']))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('title', 'ilike', "%$t%")->orWhere('reference', 'ilike', "%$t%")->orWhereHas('author', fn ($a) => $a->where('name', 'ilike', "%$t%"))));
        $this->applySort($query, $request, ['updated_at', 'title', 'expires_at', 'created_at'], 'updated_at');
        $response = $this->paginated($query, $request, fn (Content $c) => Present::content($c) + ['approved_versions' => $c->versions()->orderByDesc('revision')->get(['id', 'lang', 'revision', 'classification', 'approved_at'])->unique('lang')->values()]);
        $payload = $response->getData(true);
        $counts = $this->visible(Content::query())->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $payload['meta']['counts'] = $counts;
        $payload['meta']['returned'] = $this->visible(Content::query())->whereHas('languages', fn ($q) => $q->where('status', 'returned'))->count();

        return response()->json($payload);
    }

    public function show(Content $content): JsonResponse
    {
        $this->assertVisible($content);
        $content->load(['category', 'owner', 'author', 'template', 'languages']);
        $data = Present::content($content);
        $data['template_layout'] = $content->template?->layout;
        $data['media_details'] = collect($content->media ?? [])->map(function (array $item): array {
            $version = AssetVersion::query()->with('asset')->find($item['asset_version_id'] ?? null);

            return $item + ['asset_id' => $version?->asset_id, 'kind' => $version?->asset->kind, 'title' => $version?->asset->title, 'pages_total' => $version?->pages, 'scan_status' => $version?->scan_status, 'classification' => $version?->asset->classification];
        })->values();
        $data['approved_versions'] = ContentVersion::query()->with('approver')->where('content_id', $content->id)->orderByDesc('revision')->get()
            ->map(fn ($v) => ['id' => $v->id, 'lang' => $v->lang, 'revision' => $v->revision, 'approved_at' => Present::date($v->approved_at), 'approver' => $v->approver?->name, 'payload' => $v->payload]);
        $data['approvals'] = Approval::query()->with(['assignee', 'backup', 'decider'])->where('object_type', 'content')->where('object_id', $content->id)->orderByDesc('created_at')->get()
            ->map(fn ($a) => ['id' => $a->id, 'lang' => $a->lang, 'revision' => $a->revision, 'status' => $a->status, 'decision' => $a->decision, 'reason' => $a->reason, 'due_at' => Present::date($a->due_at), 'escalated_at' => Present::date($a->escalated_at), 'assignee' => Present::userRef($a->assignee), 'backup' => Present::userRef($a->backup), 'decider' => Present::userRef($a->decider), 'decided_at' => Present::date($a->decided_at)]);
        $data['history'] = WorkflowEvent::query()->with('actor')->where('object_type', 'content')->where('object_id', $content->id)->orderByDesc('created_at')->get()
            ->map(fn ($e) => ['action' => $e->action, 'from' => $e->from_status, 'to' => $e->to_status, 'lang' => $e->lang, 'revision' => $e->revision, 'actor' => $e->actor?->name, 'role' => $e->actor_role, 'reason' => $e->reason, 'at' => Present::date($e->created_at)]);
        $data['permissions'] = [
            'edit' => $this->canEdit($content),
            'validate_languages' => in_array($this->user()->id, $this->workflow->contributorIds($content), true) ? [] : array_values(array_intersect($this->access()->validationLanguages($this->user()), $content->languages->pluck('lang')->all())),
            'archive' => $this->can('contents.archive') || $content->author_id === $this->user()->id,
        ];
        // Réaffectation : seuls les validateurs éligibles (langue, périmètre, hors auteur), sauf soi-même.
        $data['reassign_candidates'] = (object) collect($data['permissions']['validate_languages'])
            ->filter(fn (string $lang) => $content->languages->firstWhere('lang', $lang)?->status === 'submitted')
            ->mapWithKeys(fn (string $lang) => [$lang => $this->workflow->eligibleValidators($content, $lang)->where('id', '!=', $this->user()->id)
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()])->all();
        $data['campaigns'] = DB::table('campaign_items')->join('campaigns', 'campaigns.id', '=', 'campaign_items.campaign_id')->where('content_id', $content->id)->get(['campaigns.id', 'campaigns.reference', 'campaigns.name', 'campaigns.status']);

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('contents.create', 'visits.manage');
        $data = $this->validated($request, true);
        $owner = $data['owner_structure_id'] ?? $this->user()->structure_id;
        $scope = $this->access()->structureScope($this->user(), 'contents.create');
        if ($scope !== null && ! in_array($owner, $scope, true)) {
            $this->deny('Structure propriétaire hors périmètre');
        }
        $content = DB::transaction(function () use ($data, $owner): Content {
            $content = Content::query()->create(collect($data)->except(['languages', 'owner_structure_id'])->all() + [
                'reference' => Reference::next('CNT'), 'owner_structure_id' => $owner, 'author_id' => $this->user()->id, 'status' => 'draft',
                'kind' => $data['kind'] ?? 'standard',
            ]);
            foreach ($data['languages'] as $lang => $line) {
                $content->languages()->create(['lang' => $lang, 'title' => $line['title'] ?? '', 'body' => $line['body'] ?? null, 'extra' => $line['extra'] ?? null, 'overflow' => (bool) ($line['overflow'] ?? false), 'subtitles_asset_version_id' => $line['subtitles_asset_version_id'] ?? null, 'status' => 'draft']);
            }
            $this->ensureQr($content);
            WorkflowEvent::query()->create(['object_type' => 'content', 'object_id' => $content->id, 'action' => 'created', 'to_status' => 'draft', 'actor_id' => $this->user()->id, 'created_at' => now()]);
            $this->audit()->log('content.created', $content);

            // Valeurs par défaut de la base (priorité, permanence, version) reflétées dans la réponse.
            return $content->refresh();
        });

        return $this->show($content)->setStatusCode(201);
    }

    public function update(Request $request, Content $content): JsonResponse
    {
        $this->assertEditable($content);
        $this->expectVersion($request, $content);
        $data = $this->validated($request, false);
        $content = $this->workflow->update($content, collect($data)->except('languages')->all(), $data['languages'] ?? [], $this->user());
        $this->ensureQr($content);

        return $this->show($content);
    }

    public function languages(Request $request, Content $content): JsonResponse
    {
        $this->assertEditable($content);
        $data = $request->validate(['lang' => ['required', 'in:fr,en,pt,es', Rule::exists('languages', 'code')->where('is_active', true)], 'title' => ['required', 'string', 'max:200'], 'body' => ['nullable', 'string', 'max:2000']]);
        if ($content->languages()->count() >= 4) {
            throw new BusinessException('LANGUAGE_LIMIT', 'Un contenu porte au plus quatre versions linguistiques.', 422);
        }
        $this->workflow->update($content, [], [$data['lang'] => $data], $this->user());

        return $this->show($content->refresh());
    }

    public function submit(Request $request, Content $content): JsonResponse
    {
        $this->assertEditable($content);
        $data = $request->validate(['languages' => ['required', 'array', 'min:1'], 'languages.*' => ['in:fr,en,pt,es', Rule::exists('languages', 'code')->where('is_active', true)]]);
        $this->workflow->submit($content, $data['languages'], $this->user());

        return $this->show($content->refresh());
    }

    public function approve(Request $request, Content $content): JsonResponse
    {
        $this->authorizePermission('contents.validate');
        $data = $request->validate([
            'lang' => ['required', 'in:fr,en,pt,es'], 'decision' => ['required', 'in:approve,return,reassign'],
            'reason' => ['nullable', 'required_if:decision,return', 'string', 'max:1000'], 'expected_revision' => ['required', 'integer'],
            'reassign_to' => ['nullable', 'required_if:decision,reassign', 'uuid', 'exists:users,id'],
        ]);
        $this->workflow->decide($content, $data['lang'], $data['decision'], $data['reason'] ?? null, (int) $data['expected_revision'], $this->user(), isset($data['reassign_to']) ? User::query()->find($data['reassign_to']) : null);

        return $this->show($content->refresh());
    }

    public function archive(Request $request, Content $content): JsonResponse
    {
        $this->assertVisible($content);
        if (! $this->can('contents.archive') && $content->author_id !== $this->user()->id) {
            $this->deny('Archivage non autorisé');
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->workflow->archive($content, $this->user(), $data['reason'] ?? null);

        return $this->show($content->refresh());
    }

    public function duplicate(Content $content): JsonResponse
    {
        $this->authorizePermission('contents.create');
        $this->assertVisible($content);
        $copy = DB::transaction(function () use ($content): Content {
            $copy = $content->replicate(['reference', 'status', 'version', 'archived_at', 'occurrence_id', 'is_demo']);
            $copy->fill(['reference' => Reference::next('CNT'), 'title' => $content->title.' (copie)', 'status' => 'draft', 'author_id' => $this->user()->id, 'version' => 1])->save();
            foreach ($content->languages as $line) {
                $copy->languages()->create($line->only(['lang', 'title', 'body', 'extra', 'subtitles_asset_version_id']) + ['status' => 'draft', 'revision' => 1]);
            }
            // Le QR recopié pointerait vers le contenu d'origine : la copie reçoit son propre lien (inchangé s'il vise un document).
            $this->ensureQr($copy);
            $this->audit()->log('content.duplicated', $copy, after: ['source' => $content->reference]);

            return $copy;
        });

        return $this->show($copy)->setStatusCode(201);
    }

    /**
     * @param  Builder<Content>  $query
     * @return Builder<Content>
     */
    private function visible(Builder $query): Builder
    {
        return app(ContentAccess::class)->scope($query, $this->user());
    }

    private function assertVisible(Content $content): void
    {
        if (Gate::forUser($this->user())->denies('view', $content)) {
            abort(404);
        }
    }

    private function canEdit(Content $content): bool
    {
        return Gate::forUser($this->user())->allows('update', $content);
    }

    private function assertEditable(Content $content): void
    {
        $this->assertVisible($content);
        if (! $this->canEdit($content)) {
            $this->deny('Modification du contenu non autorisée');
        }
    }

    private function ensureQr(Content $content): void
    {
        if (empty($content->qr['enabled'])) {
            return;
        }
        $target = $content->qr['document_id'] ?? null;
        $link = AccessLink::query()->firstOrCreate(
            ['target_type' => $target ? 'document' : 'content', 'target_id' => $target ?? $content->id],
            ['code' => Str::lower(Str::random(10)), 'classification' => $content->classification === 'public' ? 'public' : 'interne', 'created_by' => $this->user()->id],
        );
        if (($content->qr['code'] ?? null) !== $link->code) {
            $content->forceFill(['qr' => array_merge($content->qr, ['code' => $link->code])])->saveQuietly();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        // PUB-08 : seules les langues actives du référentiel (V36) sont acceptées, côté serveur et pas seulement dans l'interface.
        $unknown = array_diff(array_keys((array) $request->input('languages', [])), Language::query()->where('is_active', true)->pluck('code')->all());
        if ($unknown !== []) {
            throw new BusinessException('LANGUAGE_UNKNOWN', 'Langue inactive ou non prise en charge : '.implode(', ', $unknown), 422);
        }

        return $request->validate([
            'title' => [$req, 'string', 'max:200'],
            'kind' => ['sometimes', 'in:standard,welcome,fallback'],
            'category_id' => [$req, 'uuid', 'exists:content_categories,id'],
            'owner_structure_id' => ['nullable', 'uuid', 'exists:structures,id'],
            'classification' => [$req, 'in:public,interne,restreint'],
            'priority' => ['sometimes', 'integer', 'between:1,100'],
            'template_id' => ['nullable', 'uuid', 'exists:templates,id'],
            'media' => ['nullable', 'array', 'max:6'],
            'media.*.asset_version_id' => ['required', 'uuid', 'exists:asset_versions,id'],
            'media.*.pages' => ['nullable', 'array', 'max:50'], 'media.*.pages.*' => ['integer', 'min:1'],
            'media.*.page_duration_s' => ['nullable', 'integer', 'between:5,120'],
            'media.*.role' => ['nullable', 'in:main,background,logo'],
            'qr' => ['nullable', 'array'], 'qr.enabled' => ['boolean'], 'qr.document_id' => ['nullable', 'uuid', 'exists:documents,id'], 'qr.label' => ['nullable', 'string', 'max:60'],
            'proposed_targets' => ['nullable', 'array'],
            'valid_from' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'is_permanent' => ['sometimes', 'boolean'],
            'delegation' => ['nullable', 'array'],
            'languages' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:4'],
            'languages.*.title' => ['required', 'string', 'max:200'],
            'languages.*.body' => ['nullable', 'string', 'max:2000'],
            'languages.*.extra' => ['nullable', 'array'],
            'languages.*.overflow' => ['nullable', 'boolean'],
            'languages.*.subtitles_asset_version_id' => ['nullable', 'uuid', 'exists:asset_versions,id'],
        ]);
    }
}
