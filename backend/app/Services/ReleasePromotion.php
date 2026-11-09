<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Incident;
use App\Models\PlayerRelease;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * §21.3 : promotion d'une version pilote vers le canal stable sur indicateurs. Une version est éligible quand elle est
 * déployée à 100 % sur le canal pilote depuis le délai réglé, qu'au moins un lecteur pilote l'exécute et qu'aucun de ses
 * lecteurs n'a d'incident ouvert (lecture, lecteur, version). La promotion automatique, désactivée par défaut, ouvre une
 * première vague de 25 % sur le canal stable, élargie ensuite à la main.
 */
class ReleasePromotion
{
    public function __construct(private Audit $audit) {}

    /**
     * @return Collection<int, PlayerRelease>
     */
    public function eligible(): Collection
    {
        $after = now()->subHours((int) Setting::effective('releases.promote_after_h'));
        $promoted = PlayerRelease::query()->where('channel', 'stable')->where('status', 'active')->pluck('version')->all();

        return PlayerRelease::query()->where('channel', 'pilot')->where('status', 'active')->where('rollout_percent', 100)->where('created_at', '<=', $after)
            ->whereNotIn('version', $promoted)->get()->filter(function (PlayerRelease $release): bool {
                $devices = Device::query()->where('status', 'active')->where('release_channel', 'pilot')->where('app_version', $release->version)->pluck('id');

                return $devices->isNotEmpty() && ! Incident::query()->whereIn('device_id', $devices)->whereIn('status', ['open', 'acknowledged'])
                    ->whereIn('kind', ['outdated_version', 'playback_errors', 'player_stalled', 'device_unavailable', 'unstable_connection'])->exists();
            })->values();
    }

    public function promoteEligible(): int
    {
        $count = 0;
        foreach ($this->eligible() as $release) {
            $stable = PlayerRelease::query()->create([
                'version' => $release->version, 'channel' => 'stable', 'base_url' => $release->base_url, 'sha256' => $release->sha256,
                'notes' => trim(($release->notes ?? '').' (promotion automatique depuis le canal pilote)'), 'rollout_percent' => 25, 'created_by' => $release->created_by,
            ]);
            $this->audit->log('player.release_auto_promoted', $stable, 'Indicateurs du canal pilote satisfaits', after: ['version' => $release->version, 'rollout_percent' => 25], actor: null, objectLabel: $release->version.' · stable');
            $count++;
        }

        return $count;
    }
}
