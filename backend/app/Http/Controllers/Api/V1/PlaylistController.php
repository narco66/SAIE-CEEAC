<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Playlist;
use App\Services\CampaignService;
use App\Support\BusinessException;
use App\Support\CampaignPriority;
use App\Support\Present;
use App\Support\Reference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Listes de lecture réutilisables (PUB-07). Une liste est un modèle de composition : elle est copiée dans la campagne qui
 * l'utilise ; la campagne fige ensuite les versions approuvées. Modifier une liste ne change jamais une campagne programmée.
 */
class PlaylistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('campaigns.view');
        $query = Playlist::query()->with(['contents', 'creator'])->where('status', $request->query('status', 'active'))->orderBy('name');

        return $this->paginated($query, $request, fn (Playlist $p) => $this->present($p), 50);
    }

    public function show(Playlist $playlist): JsonResponse
    {
        $this->authorizePermission('campaigns.view');

        return response()->json(['data' => $this->present($playlist->load(['contents', 'creator']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('campaigns.manage');
        $data = $request->validate($this->rules(true));
        $playlist = DB::transaction(function () use ($data): Playlist {
            $playlist = Playlist::query()->create(['name' => $data['name'], 'description' => $data['description'] ?? null, 'created_by' => $this->user()->id]);
            $this->syncItems($playlist, $data['items']);

            return $playlist;
        });
        $this->audit()->log('playlist.created', $playlist, after: ['items' => count($data['items'])]);

        return response()->json(['data' => $this->present($playlist->load(['contents', 'creator']))], 201);
    }

    public function update(Request $request, Playlist $playlist): JsonResponse
    {
        $this->authorizePermission('campaigns.manage');
        $data = $request->validate($this->rules(false) + ['status' => ['sometimes', 'in:active,archived']]);
        $snapshot = fn (Playlist $p) => $p->only(['name', 'description', 'status']) + ['items' => DB::table('playlist_items')->where('playlist_id', $p->id)->orderBy('position')->get(['content_id', 'duration_s'])->map(fn ($i) => $i->content_id.($i->duration_s ? '@'.$i->duration_s.'s' : ''))->all()];
        $before = $snapshot($playlist);
        DB::transaction(function () use ($playlist, $data): void {
            $playlist->update(collect($data)->only(['name', 'description', 'status'])->all());
            if (array_key_exists('items', $data)) {
                $this->syncItems($playlist, $data['items']);
            }
        });
        // RG-008 : avant et après pour les seules valeurs modifiées.
        $after = $snapshot($playlist->refresh());
        $changed = collect($after)->keys()->filter(fn (string $k) => $before[$k] !== $after[$k])->values()->all();
        $this->audit()->log(($data['status'] ?? null) === 'archived' ? 'playlist.archived' : 'playlist.updated', $playlist, before: collect($before)->only($changed)->all(), after: collect($after)->only($changed)->all());

        return $this->show($playlist->refresh());
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'min:3', 'max:160'], 'description' => ['nullable', 'string', 'max:1000'],
            'items' => [$req, 'array', 'min:1', 'max:50'], 'items.*.content_id' => ['required', 'uuid', 'distinct', 'exists:contents,id'],
            'items.*.duration_s' => ['nullable', 'integer', 'between:10,600'],
        ];
    }

    /**
     * @param  list<array{content_id: string, duration_s?: int|null}>  $items
     */
    /**
     * §12-a : affectation directe d'une liste de lecture à des écrans ou des groupes. Elle produit un « programme
     * permanent » : une campagne liée à la liste, de priorité « contenus permanents » par défaut, qui passe par les mêmes
     * contrôles que toute campagne (contenus validés, périmètre, classification, publication journalisée).
     */
    public function assign(Request $request, Playlist $playlist): JsonResponse
    {
        $this->authorizePermission('campaigns.manage');
        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1'], 'targets.*.type' => ['required', 'in:screen,group'], 'targets.*.id' => ['required', 'uuid'],
            'priority' => ['sometimes', 'integer', 'between:1,99'], 'languages' => ['sometimes', 'array', 'min:1', 'max:4'], 'languages.*' => ['distinct', 'in:fr,en,pt,es', 'exists:languages,code'],
            'starts_at' => ['sometimes', 'date'], 'ends_at' => ['sometimes', 'date', 'after:starts_at'], 'publish' => ['sometimes', 'boolean'],
        ]);
        if ($playlist->status === 'archived') {
            throw new BusinessException('PLAYLIST_ARCHIVED', 'Liste de lecture archivée : elle ne peut plus être affectée.', 409);
        }
        $items = DB::table('playlist_items')->where('playlist_id', $playlist->id)->orderBy('position')->get(['content_id', 'duration_s']);
        if ($items->isEmpty()) {
            throw new BusinessException('PLAYLIST_EMPTY', 'Liste de lecture vide.', 422);
        }
        $priority = (int) ($data['priority'] ?? 10);
        CampaignPriority::assertAllowed($priority, $this->user());
        $campaigns = app(CampaignService::class);
        $campaigns->assertTargetsExist($data['targets']);
        $campaigns->assertTargetsInScope($data['targets'], $this->user(), 'campaigns.manage');
        $campaign = DB::transaction(function () use ($playlist, $data, $items, $priority, $campaigns): Campaign {
            $campaign = Campaign::query()->create([
                'reference' => Reference::next('CMP'), 'name' => mb_substr('Programme permanent — '.$playlist->name, 0, 160), 'kind' => 'standard', 'status' => 'draft', 'priority' => $priority,
                'starts_at' => $data['starts_at'] ?? now(), 'ends_at' => $data['ends_at'] ?? now()->addYear(), 'created_by' => $this->user()->id, 'playlist_id' => $playlist->id,
            ]);
            foreach ($items->values() as $position => $item) {
                $campaign->items()->create(['content_id' => $item->content_id, 'position' => $position + 1, 'duration_s' => $item->duration_s]);
            }
            foreach (array_values($data['languages'] ?? ['fr']) as $rank => $lang) {
                DB::table('campaign_languages')->insert(['campaign_id' => $campaign->id, 'lang' => $lang, 'rank' => $rank + 1]);
            }
            foreach ($data['targets'] as $target) {
                $campaign->targets()->create(['target_type' => $target['type'], 'target_id' => $target['id']]);
            }
            $this->audit()->log('playlist.assigned', $playlist, after: ['campaign' => $campaign->reference, 'targets' => $data['targets'], 'priority' => $priority]);
            if ($data['publish'] ?? true) {
                $campaigns->publish($campaign, $this->user());
            }

            return $campaign;
        });

        return response()->json(['data' => Present::campaign($campaign->refresh()->load(['languages', 'items.content', 'targets', 'creator']))], 201);
    }

    private function syncItems(Playlist $playlist, array $items): void
    {
        $archived = Content::query()->whereIn('id', array_column($items, 'content_id'))->where('status', 'archived')->pluck('title');
        if ($archived->isNotEmpty()) {
            throw new BusinessException('CONTENT_ARCHIVED', 'Contenu archivé dans la liste : '.$archived->implode(', ').'.', 422);
        }
        DB::table('playlist_items')->where('playlist_id', $playlist->id)->delete();
        DB::table('playlist_items')->insert(array_map(fn (array $item, int $i) => [
            'id' => (string) Str::uuid(), 'playlist_id' => $playlist->id, 'content_id' => $item['content_id'], 'position' => $i + 1, 'duration_s' => $item['duration_s'] ?? null,
        ], $items, array_keys($items)));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Playlist $playlist): array
    {
        $approved = ContentVersion::query()->whereIn('content_id', $playlist->contents->pluck('id'))->get(['content_id', 'lang'])->groupBy('content_id');

        return [
            'id' => $playlist->id, 'name' => $playlist->name, 'description' => $playlist->description, 'status' => $playlist->status,
            'creator' => Present::userRef($playlist->creator), 'updated_at' => Present::date($playlist->updated_at),
            'items' => $playlist->contents->map(fn (Content $c) => [
                'content_id' => $c->id, 'title' => $c->title, 'reference' => $c->reference, 'classification' => $c->classification, 'content_status' => $c->status,
                'position' => (int) $c->pivot->position, 'duration_s' => $c->pivot->duration_s, 'approved' => ($approved[$c->id] ?? collect())->pluck('lang')->unique()->values(),
            ])->values(),
        ];
    }
}
