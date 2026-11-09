<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Screen;
use App\Models\ScreenGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Groupes dynamiques (§8.3) : membres recalculés à partir de critères, à l'enregistrement du groupe et à chaque passage du
 * planificateur. Les écrans archivés n'en font jamais partie (RG-013) ; un écran ne figure qu'une fois (clé primaire).
 */
class DynamicGroups
{
    /** Critères reconnus et règles de validation. */
    public const RULES = [
        'criteria' => ['array'],
        'criteria.building_ids' => ['nullable', 'array'], 'criteria.building_ids.*' => ['uuid', 'exists:buildings,id'],
        'criteria.floor_ids' => ['nullable', 'array'], 'criteria.floor_ids.*' => ['uuid', 'exists:floors,id'],
        'criteria.zone_ids' => ['nullable', 'array'], 'criteria.zone_ids.*' => ['uuid', 'exists:display_zones,id'],
        'criteria.room_ids' => ['nullable', 'array'], 'criteria.room_ids.*' => ['uuid', 'exists:rooms,id'],
        'criteria.kinds' => ['nullable', 'array'], 'criteria.kinds.*' => ['in:display,room_panel,orientation'],
        'criteria.statuses' => ['nullable', 'array'], 'criteria.statuses.*' => ['in:active,maintenance,inactive'],
        'criteria.network' => ['nullable', 'array'], 'criteria.network.*' => ['in:online,suspect,unavailable,unpaired'],
        'criteria.campaign_id' => ['nullable', 'uuid', 'exists:campaigns,id'],
    ];

    /**
     * @param  array<string, mixed>  $criteria
     * @return Builder<Screen>
     */
    public function query(array $criteria): Builder
    {
        $thresholds = Device::thresholds();
        $suspect = now()->subSeconds($thresholds['suspect']);
        $unavailable = now()->subSeconds($thresholds['unavailable']);

        return Screen::query()->where('status', '!=', 'archived')
            ->when($criteria['building_ids'] ?? null, fn ($q, $ids) => $q->whereHas('location.floor', fn ($f) => $f->whereIn('building_id', $ids)))
            ->when($criteria['floor_ids'] ?? null, fn ($q, $ids) => $q->whereHas('location', fn ($l) => $l->whereIn('floor_id', $ids)))
            ->when($criteria['zone_ids'] ?? null, fn ($q, $ids) => $q->whereHas('location', fn ($l) => $l->whereIn('zone_id', $ids)))
            ->when($criteria['room_ids'] ?? null, fn ($q, $ids) => $q->whereIn('room_id', $ids))
            ->when($criteria['kinds'] ?? null, fn ($q, $kinds) => $q->whereIn('kind', $kinds))
            ->when($criteria['statuses'] ?? null, fn ($q, $statuses) => $q->whereIn('status', $statuses))
            ->when($criteria['network'] ?? null, fn ($q, $states) => $q->where(function ($w) use ($states, $suspect, $unavailable): void {
                $w->whereRaw('1 = 0');
                foreach ($states as $state) {
                    match ($state) {
                        'online' => $w->orWhereHas('activeDevice', fn ($d) => $d->where('last_seen_at', '>=', $suspect)),
                        'suspect' => $w->orWhereHas('activeDevice', fn ($d) => $d->whereBetween('last_seen_at', [$unavailable, $suspect])),
                        'unavailable' => $w->orWhereHas('activeDevice', fn ($d) => $d->where(fn ($x) => $x->where('last_seen_at', '<', $unavailable)->orWhereNull('last_seen_at'))),
                        'unpaired' => $w->orWhereDoesntHave('activeDevice'),
                        default => null,
                    };
                }
            }))
            // Campagne : écrans qu'elle cible directement (écran, niveau, zone) — jamais via un groupe, pour éviter les références circulaires.
            ->when($criteria['campaign_id'] ?? null, fn ($q, $id) => $q->where(function ($w) use ($id): void {
                $targets = DB::table('campaign_targets')->where('campaign_id', $id)->get(['target_type', 'target_id']);
                $w->whereRaw('1 = 0')
                    ->orWhereIn('screens.id', $targets->where('target_type', 'screen')->pluck('target_id'))
                    ->orWhereHas('location', fn ($l) => $l->whereIn('floor_id', $targets->where('target_type', 'floor')->pluck('target_id'))->orWhereIn('zone_id', $targets->where('target_type', 'zone')->pluck('target_id')));
            }));
    }

    /**
     * Recalcule les membres d'un groupe dynamique ; renvoie le nombre d'ajouts et de retraits.
     *
     * @return array{added: int, removed: int}
     */
    public function sync(ScreenGroup $group): array
    {
        if ($group->type !== 'dynamic') {
            return ['added' => 0, 'removed' => 0];
        }
        $wanted = $this->query((array) $group->criteria)->pluck('screens.id')->all();
        $current = DB::table('group_members')->where('group_id', $group->id)->pluck('screen_id')->all();
        $add = array_values(array_diff($wanted, $current));
        $remove = array_values(array_diff($current, $wanted));
        DB::transaction(function () use ($group, $add, $remove): void {
            DB::table('group_members')->where('group_id', $group->id)->whereIn('screen_id', $remove)->delete();
            DB::table('group_members')->insertOrIgnore(array_map(fn (string $id) => ['group_id' => $group->id, 'screen_id' => $id, 'created_at' => now(), 'updated_at' => now()], $add));
            $group->forceFill(['members_computed_at' => now()])->save();
        });

        return ['added' => count($add), 'removed' => count($remove)];
    }

    public function syncAll(): int
    {
        $changes = 0;
        foreach (ScreenGroup::query()->where('type', 'dynamic')->get() as $group) {
            $result = $this->sync($group);
            $changes += $result['added'] + $result['removed'];
        }

        return $changes;
    }
}
