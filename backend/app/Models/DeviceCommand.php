<?php

namespace App\Models;

use App\Services\RealtimeBus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commande à distance adressée à un lecteur (§11). L'identifiant sert d'identifiant de transaction : le lecteur l'utilise
 * pour accuser réception puis rendre compte de l'exécution. Une commande n'est « exécutée » que sur confirmation du lecteur.
 */
class DeviceCommand extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        // §10.22 : chaque étape d'une commande (envoyée, reçue, exécutée, échouée) est annoncée en temps réel.
        static::saved(fn (DeviceCommand $command) => RealtimeBus::publish('command', $command->screen_id, $command->id));
    }

    /** États non terminaux : la commande peut encore être remise au lecteur ou confirmée. */
    public const OPEN = ['pending', 'sent', 'received'];

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
            'params' => 'array',
            'result_data' => 'array',
            'requested_at' => 'datetime',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<Screen, $this>
     */
    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
