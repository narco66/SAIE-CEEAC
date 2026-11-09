<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Paramètres applicatifs administrés dans V39. Les secrets ne sont jamais renvoyés en lecture.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'array', 'is_secret' => 'boolean'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::remember('saie.settings', 60, fn (): array => static::query()->pluck('value', 'key')->all());

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * Valeurs par défaut des paramètres de V39, utilisées tant qu'aucune valeur n'a été enregistrée.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'identity.institution_name' => 'Commission de la CEEAC',
            'display.tagline' => 'Communauté Économique des États de l’Afrique Centrale',
            'display.default_slide_s' => 12,
            'operating.default_window' => config('saie.operating_window'),
            'operating.audio_max_volume' => 40,
            'rooms.pending_hold_hours' => 24,
            'rooms.no_show_auto' => false,
            'channels.smtp_enabled' => true,
            'channels.push_enabled' => false,
            'integrations.api_enabled' => false,
            'alerts.critical_after_hours' => (bool) config('saie.alerts.critical_overrides_window'),
            'anomalies.storage_ratio_pct' => (int) round(100 * (float) config('saie.anomalies.storage_ratio')),
            'anomalies.clock_skew_s' => intdiv((int) config('saie.anomalies.clock_skew_ms'), 1000),
            'anomalies.sync_stalled_minutes' => (int) config('saie.anomalies.sync_stalled_minutes'),
            'supervision.suspect_after_s' => (int) config('saie.device.suspect_after_s'),
            'supervision.offline_after_s' => (int) config('saie.device.unavailable_after_s'),
            'anomalies.stalled_minutes' => 15,
            'anomalies.errors_per_hour' => 10,
            'anomalies.outdated_hours' => 24,
            'anomalies.temperature_c' => 80,
            'devices.enroll_cidrs' => '',
            'devices.signed_requests' => false,
            'devices.secure_storage' => false,
            'referentials.restricted' => false,
            'anomalies.unstable_losses' => 3,
            'anomalies.fallback_minutes' => 30,
            'incidents.delay_critical_min' => 30,
            'incidents.delay_major_min' => 120,
            'incidents.delay_medium_min' => 480,
            'incidents.delay_minor_min' => 1440,
            'incidents.default_assignee_id' => null,
            'retention.telemetry_days' => (int) config('saie.retention.telemetry_days'),
            'retention.notifications_days' => (int) config('saie.retention.notifications_days'),
            'retention.auto_purge' => (bool) config('saie.retention.auto_purge_enabled'),
            'releases.auto_promote' => false,
            'releases.promote_after_h' => 72,
            'retention.receipts_days' => 400,
            'retention.commands_days' => 365,
            'retention.states_days' => 730,
            'retention.exchanges_days' => 30,
            'retention.audit_months' => (int) config('saie.retention.audit_months'),
            'retention.export_hours' => (int) config('saie.retention.export_hours'),
            'documents.four_eyes' => (bool) config('saie.documents.four_eyes'),
            'campaigns.urgent_from' => 80,
        ];
    }

    /**
     * Valeur en vigueur : valeur enregistrée, sinon valeur par défaut.
     */
    public static function effective(string $key): mixed
    {
        return static::get($key, static::defaults()[$key] ?? null);
    }

    public static function put(string $key, mixed $value, ?string $userId = null, bool $secret = false): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId, 'is_secret' => $secret]);
        Cache::forget('saie.settings');
    }
}
