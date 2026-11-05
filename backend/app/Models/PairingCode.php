<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PairingCode extends Model
{
    use HasUuids;

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur ou service.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['poll_secret_hash', 'issued_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_info' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'token_collected_at' => 'datetime',
            'issued_token' => 'encrypted',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
