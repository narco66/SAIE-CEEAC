<?php

namespace App\Models;

use App\Services\RealtimeBus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * Lecteur technique : identité propre, jeton Sanctum individuel et révocable (PLY-02).
 */
class Device extends Model
{
    use HasApiTokens, HasUuids;

    protected static function booted(): void
    {
        // §10.22 : seuls les changements d'état significatifs sont annoncés (pas chaque battement).
        static::updated(function (Device $device): void {
            // Mode d'affichage (programme, alerte, repli, hors plage) : annoncé à son changement, pas à chaque diapositive.
            $mode = fn ($p) => is_array($p) ? ($p['mode'] ?? (isset($p['alert']) ? 'alert' : (isset($p['entry_id']) ? 'program' : null))) : null;
            $shift = $device->wasChanged('current_playback') && $mode($device->getOriginal('current_playback')) !== $mode($device->current_playback);
            if ($shift || $device->wasChanged(['network_status', 'status', 'screen_id', 'playback_paused', 'suspended_at', 'app_version', 'active_manifest_version', 'config_hash'])) {
                RealtimeBus::publish('device', $device->screen_id, $device->id);
            }
        });
    }

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'command_requested_at' => 'datetime',
            'command_delivered_at' => 'datetime',
            'storage' => 'array',
            'current_playback' => 'array',
            'last_errors' => 'array',
            'cache_inventory' => 'array',
            'paired_at' => 'datetime',
            'revoked_at' => 'datetime',
            'is_demo' => 'boolean',
            'capabilities' => 'array',
            'capabilities_reported_at' => 'datetime',
            'playback_paused' => 'boolean',
            'hardware_info' => 'array',
            'config_report' => 'array',
            'config_reported_at' => 'datetime',
            'suspended_at' => 'datetime',
            'offline_since' => 'datetime',
            'rotate_requested_at' => 'datetime',
            'agent_seen_at' => 'datetime',
            'agent_report' => 'array',
        ];
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    /**
     * @return HasMany<DeviceCommand, $this>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(DeviceReceipt::class);
    }

    public function manifests(): HasMany
    {
        return $this->hasMany(Manifest::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Statut réseau calculé selon PLY-06 : suspect après 90 s, indisponible après 5 min.
     */
    /**
     * Erreurs signalées par le lecteur depuis moins de `saie.device.error_window_min` minutes. Le lecteur renvoie ses dernières
     * erreurs, horodatées (« 2026-11-08T07:00:00.000Z message »), à chaque battement : une erreur ancienne ne doit pas
     * maintenir l'écran « en anomalie ». Une entrée sans horodatage lisible compte comme récente.
     *
     * @return list<string>
     */
    public function recentErrors(): array
    {
        $since = now()->subMinutes((int) config('saie.device.error_window_min', 10));

        return array_values(array_filter((array) ($this->last_errors ?? []), function (mixed $error) use ($since): bool {
            if (! is_string($error) || ! preg_match('/^(\d{4}-\d{2}-\d{2}T[\d:.]+Z)\s/', $error, $m)) {
                return true;
            }

            return Carbon::parse($m[1])->gte($since);
        }));
    }

    /**
     * Délais de supervision en secondes (§10.1), réglables dans les paramètres ; lus une fois par requête ou tâche.
     *
     * @return array{suspect: int, unavailable: int}
     */
    public static function thresholds(): array
    {
        return once(fn (): array => [
            'suspect' => (int) Setting::effective('supervision.suspect_after_s'),
            'unavailable' => (int) Setting::effective('supervision.offline_after_s'),
        ]);
    }

    public function computedNetworkStatus(): string
    {
        if ($this->status !== 'active') {
            return 'unknown';
        }
        // Un lecteur appairé qui ne s'est jamais manifesté est indisponible, comme dans les filtres de la liste du parc.
        if ($this->last_seen_at === null) {
            return 'unavailable';
        }
        $seconds = $this->last_seen_at->diffInSeconds(now(), true);

        return match (true) {
            $seconds <= self::thresholds()['suspect'] => 'online',
            $seconds <= self::thresholds()['unavailable'] => 'suspect',
            default => 'unavailable',
        };
    }
}
