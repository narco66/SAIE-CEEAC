<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Screen extends Model
{
    use HasUuids;

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur ou service.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operating_window' => 'array',
            'audio_enabled' => 'boolean',
            'panel_state_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ScreenGroup::class, 'group_members', 'screen_id', 'group_id');
    }

    /**
     * PLY-08 : plage en vigueur — celle de l'écran (dérogation), sinon celle de son premier groupe qui en porte une
     * (ordre alphabétique de code, déterministe), sinon le paramètre général.
     *
     * @return array<string, mixed>
     */
    public function effectiveOperatingWindow(): array
    {
        if ($this->operating_window) {
            return $this->operating_window;
        }
        $group = $this->groups()->whereNotNull('operating_window')->orderBy('code')->first();

        return $group?->operating_window ?: (array) Setting::effective('operating.default_window');
    }

    public function activeDevice(): HasOne
    {
        return $this->hasOne(Device::class)->where('status', 'active');
    }
}
