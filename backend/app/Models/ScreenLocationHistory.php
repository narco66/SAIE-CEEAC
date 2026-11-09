<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Emplacement d'un écran sur une période (§6, RG-011) : la période en cours n'a pas de date de fin ; un déplacement la
 * clôt et en ouvre une nouvelle, avec son auteur et son motif.
 */
class ScreenLocationHistory extends Model
{
    protected $table = 'screen_location_history';

    public $timestamps = false;

    /**
     * Les attributs sont alimentés exclusivement par le contrôleur des écrans, après validation.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['from_at' => 'datetime', 'to_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function mover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }
}
