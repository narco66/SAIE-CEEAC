<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class DeviceReceipt extends Model
{
    use HasUuids;

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
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * Nombre de lectures déclarées : un accusé « played » agrège plusieurs passages (champ `plays`), un ancien accusé vaut 1.
     *
     * @param  Builder<DeviceReceipt>  $query
     */
    public static function playsCount(Builder $query): int
    {
        return (int) $query->sum(DB::raw("coalesce((payload->>'plays')::int, 1)"));
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
