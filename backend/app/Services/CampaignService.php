<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Screen;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Programmation : publication atomique d'une campagne sur des versions approuvées immuables (PUB-01, PUB-04, PUB-08).
 */
class CampaignService
{
    private const CLASS_RANK = ['public' => 0, 'interne' => 1, 'restreint' => 2];

    public function __construct(private Audit $audit, private ContentWorkflow $workflow, private ManifestBuilder $manifests) {}

    /**
     * Écrans effectivement ciblés, dédoublonnés, avec compatibilité de classification et état réseau (V21, V23).
     *
     * @param  list<array{type: string, id: string|null}>  $targets
     * @return Collection<int, Screen>
     */
    public function resolveTargets(array $targets): Collection
    {
        $query = Screen::query()->with(['location.zone', 'location.floor', 'activeDevice', 'groups'])->where('status', 'active');
        if (collect($targets)->contains(fn ($t) => $t['type'] === 'all')) {
            return $query->orderBy('code')->get();
        }
        $query->where(function ($q) use ($targets): void {
            $q->whereRaw('1 = 0');
            foreach ($targets as $t) {
                match ($t['type']) {
                    'screen' => $q->orWhere('screens.id', $t['id']),
                    'group' => $q->orWhereHas('groups', fn ($g) => $g->where('screen_groups.id', $t['id'])),
                    'zone' => $q->orWhereHas('location', fn ($l) => $l->where('zone_id', $t['id'])),
                    'floor' => $q->orWhereHas('location', fn ($l) => $l->where('floor_id', $t['id'])),
                    default => null,
                };
            }
        });

        return $query->orderBy('code')->get();
    }

    /**
     * HAB-01 : une campagne, une alerte ou un repli ne vise que des écrans du site et de la zone de l'affectation qui donne la permission ;
     * « tous les écrans » est réservé à une affectation sans restriction. Refus journalisé.
     *
     * @param  list<array{type: string, id?: string|null}>  $targets
     */
    public function assertTargetsInScope(array $targets, User $user, string $permission): void
    {
        $scope = app(Access::class)->screenScope($user, $permission);
        if ($scope === null) {
            return;
        }
        $outside = collect($targets)->contains(fn ($t) => $t['type'] === 'all')
            || $this->resolveTargets(array_map(fn ($t) => ['type' => $t['type'], 'id' => $t['id'] ?? null], $targets))->pluck('id')->diff($scope)->isNotEmpty();
        if ($outside) {
            $this->audit->log('access.denied', null, 'Cible hors du site ou de la zone de l’habilitation', after: ['permission' => $permission], outcome: 'denied', actor: $user);
            throw new AuthorizationException('Certaines cibles sont hors du site ou de la zone de votre habilitation.');
        }
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function preview(array $draft): array
    {
        $screens = $this->resolveTargets($draft['targets'] ?? []);
        $contents = Content::query()->whereIn('id', collect($draft['items'] ?? [])->pluck('content_id'))->get();
        $maxClass = $contents->max(fn (Content $c) => self::CLASS_RANK[$c->classification] ?? 2) ?? 0;
        $warnings = [];
        $result = $screens->map(function (Screen $s) use ($maxClass, $draft): array {
            $zoneRank = self::CLASS_RANK[$s->location->zone->max_classification] ?? 0;
            $device = $s->activeDevice;
            $overlaps = Campaign::query()->whereIn('id', $this->manifests->campaignIdsFor($s))->whereIn('status', ['scheduled', 'active'])
                ->when(isset($draft['id']), fn ($q) => $q->whereKeyNot($draft['id']))
                ->where('starts_at', '<', $draft['ends_at'] ?? now()->addYear())->where('ends_at', '>', $draft['starts_at'] ?? now())->get(['id', 'reference', 'name', 'priority']);

            return [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'kind' => $s->kind, 'floor' => $s->location->floor->label, 'zone' => $s->location->zone->name,
                'zone_max_classification' => $s->location->zone->max_classification, 'compatible' => $s->kind === 'display' && $maxClass <= $zoneRank,
                'network_status' => $device?->computedNetworkStatus() ?? 'unpaired',
                'conflicts' => $overlaps->map(fn ($c) => ['reference' => $c->reference, 'name' => $c->name, 'priority' => $c->priority, 'same_priority' => (int) $c->priority === (int) ($draft['priority'] ?? 50)])->values(),
            ];
        });
        if ($result->where('compatible', false)->isNotEmpty()) {
            $warnings[] = $result->where('compatible', false)->count().' écran(s) exclu(s) : zone publique ou type incompatible avec la classification.';
        }
        $offline = $result->whereIn('network_status', ['suspect', 'unavailable', 'unpaired'])->count();
        if ($offline > 0) {
            $warnings[] = "$offline écran(s) hors ligne ou non appairé(s) : la diffusion ne peut pas y être garantie.";
        }

        return ['screens' => $result->values(), 'effective' => $result->where('compatible', true)->count(), 'warnings' => $warnings];
    }

    /** Durée maximale d'un mode événement (PUB-12). */
    public const EVENT_MAX_DAYS = 31;

    /**
     * Mode événement (PUB-12) : date de fin obligatoire (déjà exigée pour toute campagne) et durée bornée,
     * pour qu'un programme d'exception ne remplace jamais durablement le programme ordinaire.
     */
    public function assertEventRules(Campaign $campaign): void
    {
        if ($campaign->kind !== 'event' || ! $campaign->starts_at || ! $campaign->ends_at) {
            return;
        }
        if ($campaign->ends_at->gt($campaign->starts_at->addDays(self::EVENT_MAX_DAYS))) {
            throw new BusinessException('EVENT_TOO_LONG', 'Un mode événement dure au plus '.self::EVENT_MAX_DAYS.' jours : le programme ordinaire doit reprendre.', 422);
        }
    }

    public function publish(Campaign $campaign, User $user): Campaign
    {
        return DB::transaction(function () use ($campaign, $user): Campaign {
            $campaign = Campaign::query()->with(['items.content', 'languages', 'targets'])->lockForUpdate()->findOrFail($campaign->id);
            if (! in_array($campaign->status, ['draft', 'suspended'], true)) {
                throw new BusinessException('INVALID_TRANSITION', 'Seule une campagne en brouillon ou suspendue peut être programmée.', 409);
            }
            $this->assertEventRules($campaign);
            if ($campaign->ends_at->isPast()) {
                throw new BusinessException('CAMPAIGN_EXPIRED', 'La date de fin de la campagne est dépassée.', 422);
            }
            if ($campaign->items->isEmpty() || $campaign->languages->isEmpty() || $campaign->targets->isEmpty()) {
                throw new BusinessException('CAMPAIGN_INCOMPLETE', 'Une campagne exige au moins un contenu, une langue et une cible.', 422);
            }
            $langs = $campaign->languages->pluck('lang')->all();
            foreach ($campaign->items as $item) {
                $content = $item->content;
                if (in_array($content->status, ['archived', 'expired', 'suspended'], true) || $content->classification === 'restreint') {
                    throw new BusinessException('CONTENT_NOT_DIFFUSABLE', "Le contenu {$content->reference} n’est pas diffusable.", 422);
                }
                $map = [];
                foreach ($langs as $lang) {
                    $version = ContentVersion::query()->where('content_id', $content->id)->where('lang', $lang)->orderByDesc('revision')->first();
                    if ($version) {
                        $map[$lang] = $version->id;
                    }
                }
                if ($map === []) {
                    throw new BusinessException('NO_APPROVED_LANGUAGE', "Le contenu {$content->reference} n’a aucune version approuvée dans les langues de la campagne.", 422);
                }
                $item->forceFill(['version_map' => $map])->save();
            }
            $preview = $this->preview(['targets' => $campaign->targets->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->all(), 'items' => $campaign->items->map(fn ($i) => ['content_id' => $i->content_id])->all(), 'id' => $campaign->id, 'starts_at' => $campaign->starts_at, 'ends_at' => $campaign->ends_at, 'priority' => $campaign->priority]);
            if ($preview['effective'] === 0) {
                throw new BusinessException('NO_COMPATIBLE_TARGET', 'Aucun écran ciblé n’accepte la classification de ces contenus.', 422);
            }
            $campaign->forceFill([
                'status' => $campaign->starts_at->isFuture() ? 'scheduled' : 'active', 'published_at' => now(), 'published_by' => $user->id,
                'suspended_at' => null, 'suspend_reason' => null, 'version' => $campaign->version + 1,
            ])->save();
            foreach ($campaign->items as $item) {
                $this->workflow->refreshStatus($item->content);
            }
            $this->audit->log('campaign.published', $campaign, version: $campaign->version, after: ['screens' => $preview['effective'], 'languages' => $langs]);

            return $campaign;
        });
    }

    public function suspend(Campaign $campaign, User $user, string $reason): Campaign
    {
        if (! in_array($campaign->status, ['scheduled', 'active'], true)) {
            throw new BusinessException('INVALID_TRANSITION', 'Seule une campagne programmée ou en cours peut être suspendue.', 409);
        }
        $campaign->forceFill(['status' => 'suspended', 'suspended_at' => now(), 'suspend_reason' => $reason, 'version' => $campaign->version + 1])->save();
        foreach ($campaign->items()->with('content')->get() as $item) {
            $this->workflow->refreshStatus($item->content);
        }
        $this->audit->log('campaign.suspended', $campaign, $reason, version: $campaign->version);

        return $campaign;
    }

    /**
     * Statuts temporels : programmée → en cours → expirée. Une campagne expirée est exclue même si son média reste en cache (PUB-05).
     */
    public function tick(): int
    {
        $changed = 0;
        $changed += Campaign::query()->where('status', 'scheduled')->where('starts_at', '<=', now())->where('ends_at', '>', now())->update(['status' => 'active', 'updated_at' => now()]);
        $expired = Campaign::query()->whereIn('status', ['scheduled', 'active', 'suspended'])->where('ends_at', '<=', now())->get();
        foreach ($expired as $campaign) {
            $campaign->forceFill(['status' => 'expired'])->save();
            $this->audit->log('campaign.expired', $campaign, actor: null);
            $changed++;
        }
        if ($changed > 0) {
            $contentIds = DB::table('campaign_items')->pluck('content_id')->unique();
            foreach (Content::query()->whereIn('id', $contentIds)->get() as $content) {
                $this->workflow->refreshStatus($content);
            }
        }

        return $changed;
    }
}
