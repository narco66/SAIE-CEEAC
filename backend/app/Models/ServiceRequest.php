<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRequest extends Model
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
            'due_at' => 'datetime',
            'headcount' => 'integer',
        ];
    }

    /**
     * Libellé de la prestation avec ses précisions de restauration (SAL-15) : « Pause-café · 30 couverts à 10:30 ».
     */
    public function label(): string
    {
        $parts = array_filter([
            $this->headcount ? $this->headcount.' couvert'.($this->headcount > 1 ? 's' : '') : null,
            $this->serve_time ? 'à '.$this->serve_time : null,
        ]);

        return ($this->serviceType?->name ?? 'Prestation').($parts ? ' · '.implode(' ', $parts) : '');
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ReservationOccurrence::class, 'occurrence_id');
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
