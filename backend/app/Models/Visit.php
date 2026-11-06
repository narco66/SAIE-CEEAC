<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visit extends Model
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
            'languages' => 'array',
            'flags' => 'array',
            'is_demo' => 'boolean',
        ];
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ReservationOccurrence::class, 'occurrence_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * ACC-02 : le statut d'une visite suit son message de bienvenue et sa réunion — message validé ou diffusé : « Planifiée » ;
     * réunion terminée : « Terminée » ; réunion annulée, refusée ou non honorée : « Annulée ».
     */
    public static function syncStatuses(): int
    {
        $changed = 0;
        foreach (static::query()->with(['occurrence', 'content'])->whereNotIn('status', ['done', 'cancelled'])->whereNotNull('occurrence_id')->get() as $visit) {
            $occurrence = $visit->occurrence;
            $next = match (true) {
                $occurrence === null || in_array($occurrence->status, ['cancelled', 'refused', 'no_show'], true) => 'cancelled',
                $occurrence->ends_at->isPast() || $occurrence->status === 'completed' => 'done',
                $visit->status === 'message_draft' && in_array($visit->content?->status, ['approved', 'scheduled', 'broadcasting'], true) => 'planned',
                default => $visit->status,
            };
            if ($next !== $visit->status) {
                $visit->forceFill(['status' => $next])->save();
                $changed++;
            }
        }

        return $changed;
    }
}
