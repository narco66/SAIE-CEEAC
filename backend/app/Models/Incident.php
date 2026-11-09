<?php

namespace App\Models;

use App\Services\IncidentPolicy;
use App\Services\RealtimeBus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Incident extends Model
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
            'events' => 'array',
            'detected_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'is_automatic' => 'boolean',
            'verified_at' => 'datetime',
            'escalated_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    /**
     * §10.3 : tout incident reçoit une échéance de prise en charge selon sa gravité ; un incident détecté automatiquement
     * est confié au responsable désigné dans les paramètres (quel que soit le point de détection).
     */
    protected static function booted(): void
    {
        // §10.22 : tout incident créé ou modifié est annoncé au flux temps réel.
        static::saved(fn (Incident $incident) => RealtimeBus::publish('incident', $incident->screen_id, $incident->id));
        static::creating(function (Incident $incident): void {
            $policy = app(IncidentPolicy::class);
            $incident->due_at ??= $policy->dueAt($incident->severity ?? 'minor');
            if ($incident->is_automatic && ! $incident->assignee_id) {
                $incident->assignee_id = $policy->defaultAssignee()?->id;
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
