<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReservationOccurrence extends Model
{
    use HasUuids;

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur ou service.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Version initiale (API-04) : identique au défaut de la colonne, présente dès la réponse de création.
     *
     * @var array<string, int>
     */
    protected $attributes = ['version' => 1];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'blocking_start' => 'datetime',
            'blocking_end' => 'datetime',
            'hold_until' => 'datetime',
            'decided_at' => 'datetime',
            'no_show_at' => 'datetime',
            'capacity_override' => 'boolean',
            'checked_in_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'occurrence_id');
    }

    public function resourceBookings(): HasMany
    {
        return $this->hasMany(ResourceBooking::class, 'occurrence_id');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class, 'occurrence_id');
    }
}
