<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

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
            'occurred_at' => 'datetime',
            'before' => 'array',
            'after' => 'array',
        ];
    }

    /**
     * Filtres du journal (V38), communs à la consultation et à l'extrait PDF09/CSV ; comparaisons de texte insensibles à la casse.
     *
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $filters
     */
    #[Scope]
    protected function filtered(Builder $query, array $filters): void
    {
        $like = fn (string $value): string => addcslashes($value, '%_\\');
        $text = fn (string $key): ?string => is_string($filters[$key] ?? null) && trim($filters[$key]) !== '' ? trim($filters[$key]) : null;
        $query
            ->when($text('action'), fn ($q, $a) => $q->where('action', 'ilike', $like($a).'%'))
            ->when($text('actor'), fn ($q, $a) => $q->where('actor_label', 'ilike', '%'.$like($a).'%'))
            ->when($text('actor_id'), fn ($q, $a) => $q->where('actor_id', $a))
            ->when($text('object_type'), fn ($q, $t) => $q->where('object_type', 'ilike', $like($t)))
            ->when($text('object_id'), fn ($q, $t) => $q->where('object_id', $t))
            ->when($text('outcome'), fn ($q, $o) => $q->where('outcome', $o))
            ->when($text('power'), fn ($q, $p) => $q->where('power', $p));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
