<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceBooking extends Model
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
            'blocking_start' => 'datetime',
            'blocking_end' => 'datetime',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(MobileResource::class, 'resource_id');
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ReservationOccurrence::class, 'occurrence_id');
    }
}
