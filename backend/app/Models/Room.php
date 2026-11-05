<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
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
            'photos' => 'array',
            'auto_release_enabled' => 'boolean',
            'is_demo' => 'boolean',
            'capacity' => 'integer',
        ];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class, 'room_equipment', 'room_id', 'equipment_id')->withPivot('quantity');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(RoomBlock::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(ReservationOccurrence::class);
    }

    public function screens(): HasMany
    {
        return $this->hasMany(Screen::class);
    }
}
