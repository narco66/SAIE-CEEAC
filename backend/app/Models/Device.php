<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * Lecteur technique : identité propre, jeton Sanctum individuel et révocable (PLY-02).
 */
class Device extends Model
{
    use HasApiTokens, HasUuids;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'storage' => 'array',
            'current_playback' => 'array',
            'last_errors' => 'array',
            'cache_inventory' => 'array',
            'paired_at' => 'datetime',
            'revoked_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(DeviceReceipt::class);
    }

    public function manifests(): HasMany
    {
        return $this->hasMany(Manifest::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Statut réseau calculé selon PLY-06 : suspect après 90 s, indisponible après 5 min.
     */
    public function computedNetworkStatus(): string
    {
        if ($this->status !== 'active' || $this->last_seen_at === null) {
            return 'unknown';
        }
        $seconds = $this->last_seen_at->diffInSeconds(now(), true);

        return match (true) {
            $seconds <= (int) config('saie.device.suspect_after_s') => 'online',
            $seconds <= (int) config('saie.device.unavailable_after_s') => 'suspect',
            default => 'unavailable',
        };
    }
}
