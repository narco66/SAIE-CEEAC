<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenancePlan extends Model
{
    use HasUuids;

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Valeurs par défaut identiques à celles des colonnes, présentes dès la réponse de création.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true, 'category' => 'panel'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_due_on' => 'date',
            'last_done_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Screen, $this>
     */
    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    /**
     * Échéance suivante après une intervention réalisée à la date donnée.
     */
    public function scheduleAfter(DateTimeInterface $doneOn): void
    {
        $done = CarbonImmutable::parse($doneOn)->startOfDay();
        $this->forceFill(['last_done_on' => $done->toDateString(), 'next_due_on' => $done->addDays($this->frequency_days)->toDateString()])->save();
    }
}
