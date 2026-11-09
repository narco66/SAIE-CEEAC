<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Version publiée du lecteur sur un canal (PLY-12). L'empreinte porte sur le fichier index.html de la version.
 */
class PlayerRelease extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * Version cible d'un canal : la plus récente encore active.
     */
    public static function targetFor(string $channel): ?self
    {
        return static::query()->where('channel', $channel)->where('status', 'active')->orderByDesc('created_at')->first();
    }

    /**
     * §21.4 : version cible d'un lecteur, déploiement progressif compris. Une version déployée à N % n'atteint que les
     * lecteurs dont l'empreinte (lecteur + version) tombe sous N : la sélection est stable d'un appel à l'autre et augmenter
     * le pourcentage ajoute des lecteurs sans en retirer. Les autres restent sur la version précédente du canal.
     */
    public static function targetForDevice(Device $device): ?self
    {
        // §9.8 : une version épinglée sur ce lecteur prime tant qu'elle est active.
        if ($device->pinned_release_id && ($pinned = static::query()->where('id', $device->pinned_release_id)->where('status', 'active')->first())) {
            return $pinned;
        }
        $candidates = static::query()->where('channel', $device->release_channel ?? 'stable')->where('status', 'active')->orderByDesc('created_at')->get();

        return $candidates->first(fn (self $r) => $r->includes($device));
    }

    public function includes(Device $device): bool
    {
        return (int) $this->rollout_percent >= 100 || (crc32($device->id.'|'.$this->version) % 100) < (int) $this->rollout_percent;
    }
}
