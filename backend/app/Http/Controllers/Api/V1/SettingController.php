<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DynamicSource;
use App\Models\Setting;
use App\Support\BusinessException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paramètres (V39) : les secrets sont écrits sans relecture ; un changement sensible exige un motif.
 */
class SettingController extends Controller
{
    /** Clés administrables : clé => [onglet, type, technique ?] */
    private const KEYS = [
        'identity.institution_name' => ['Identité', 'string', false],
        'display.tagline' => ['Diffusion', 'string', false],
        // Bloc 3 : protections des lecteurs livrées désactivées ; leur activation relève de la DSI.
        'devices.enroll_cidrs' => ['Sécurité des lecteurs', 'string', true],
        'devices.signed_requests' => ['Sécurité des lecteurs', 'boolean', true],
        'devices.secure_storage' => ['Sécurité des lecteurs', 'boolean', true],
        'referentials.restricted' => ['Sécurité des lecteurs', 'boolean', true],
        'display.default_slide_s' => ['Diffusion', 'integer', false],
        'operating.default_window' => ['Fonctionnement et audio', 'array', true],
        'operating.audio_max_volume' => ['Fonctionnement et audio', 'integer', true],
        'rooms.pending_hold_hours' => ['Salles', 'integer', false],
        'rooms.no_show_auto' => ['Salles', 'boolean', false],
        'channels.smtp_enabled' => ['Canaux', 'boolean', true],
        'channels.push_enabled' => ['Canaux', 'boolean', true],
        'integrations.api_enabled' => ['Intégrations', 'boolean', true],
        // PAR-S1 : seuils et durées réglables sans redéploiement.
        'alerts.critical_after_hours' => ['Alertes et supervision', 'boolean', false],
        'anomalies.storage_ratio_pct' => ['Alertes et supervision', 'integer', true],
        'anomalies.clock_skew_s' => ['Alertes et supervision', 'integer', true],
        'anomalies.sync_stalled_minutes' => ['Alertes et supervision', 'integer', true],
        // §10.1, §10.3 : délais de supervision et seuils des alertes de lecture.
        'supervision.suspect_after_s' => ['Alertes et supervision', 'integer', true],
        'supervision.offline_after_s' => ['Alertes et supervision', 'integer', true],
        'anomalies.stalled_minutes' => ['Alertes et supervision', 'integer', true],
        'anomalies.errors_per_hour' => ['Alertes et supervision', 'integer', true],
        'anomalies.outdated_hours' => ['Alertes et supervision', 'integer', true],
        'anomalies.temperature_c' => ['Alertes et supervision', 'integer', true],
        'anomalies.unstable_losses' => ['Alertes et supervision', 'integer', true],
        'anomalies.fallback_minutes' => ['Alertes et supervision', 'integer', true],
        // §10.3 : délais de prise en charge par gravité (escalade) et responsable des incidents automatiques.
        'incidents.delay_critical_min' => ['Alertes et supervision', 'integer', true],
        'incidents.delay_major_min' => ['Alertes et supervision', 'integer', true],
        'incidents.delay_medium_min' => ['Alertes et supervision', 'integer', true],
        'incidents.delay_minor_min' => ['Alertes et supervision', 'integer', true],
        'incidents.default_assignee_id' => ['Alertes et supervision', 'string', true],
        'retention.telemetry_days' => ['Conservation', 'integer', false],
        'retention.notifications_days' => ['Conservation', 'integer', false],
        'retention.auto_purge' => ['Conservation', 'boolean', true],
        // §21.3 : promotion automatique d'une version pilote saine vers le canal stable (désactivée par défaut).
        'releases.auto_promote' => ['Alertes et supervision', 'boolean', true],
        'releases.promote_after_h' => ['Alertes et supervision', 'integer', true],
        'retention.receipts_days' => ['Conservation', 'integer', false],
        'retention.commands_days' => ['Conservation', 'integer', false],
        'retention.states_days' => ['Conservation', 'integer', false],
        'retention.exchanges_days' => ['Conservation', 'integer', false],
        // §13.3-j : archivage du journal d'audit et disponibilité des exports, auparavant figés dans la configuration.
        'retention.audit_months' => ['Conservation', 'integer', false],
        'retention.export_hours' => ['Conservation', 'integer', false],
        'documents.four_eyes' => ['Diffusion', 'boolean', false],
        // §12.1 : seuil du niveau « messages institutionnels urgents », réservé au pouvoir « urgence ».
        'campaigns.urgent_from' => ['Diffusion', 'integer', false],
    ];

    /**
     * Règles de validation serveur propres à chaque paramètre (les bornes ne dépendent pas du navigateur).
     *
     * @var array<string, list<string>>
     */
    private const RULES = [
        'identity.institution_name' => ['required', 'string', 'max:160'],
        'display.tagline' => ['nullable', 'string', 'max:200'],
        'devices.enroll_cidrs' => ['nullable', 'string', 'max:1000', 'regex:#^\s*([0-9a-fA-F:.]+(/[0-9]{1,3})?\s*(,\s*[0-9a-fA-F:.]+(/[0-9]{1,3})?\s*)*)?$#'],
        'devices.signed_requests' => ['required', 'boolean'],
        'devices.secure_storage' => ['required', 'boolean'],
        'referentials.restricted' => ['required', 'boolean'],
        'display.default_slide_s' => ['required', 'integer', 'between:10,300'],
        'operating.default_window' => ['required', 'array'],
        'operating.audio_max_volume' => ['required', 'integer', 'between:0,100'],
        'rooms.pending_hold_hours' => ['required', 'integer', 'between:1,168'],
        'rooms.no_show_auto' => ['required', 'boolean'],
        'channels.smtp_enabled' => ['required', 'boolean'],
        'channels.push_enabled' => ['required', 'boolean'],
        'integrations.api_enabled' => ['required', 'boolean'],
        'alerts.critical_after_hours' => ['required', 'boolean'],
        'anomalies.storage_ratio_pct' => ['required', 'integer', 'between:50,99'],
        'anomalies.clock_skew_s' => ['required', 'integer', 'between:5,600'],
        'anomalies.sync_stalled_minutes' => ['required', 'integer', 'between:10,1440'],
        'supervision.suspect_after_s' => ['required', 'integer', 'between:45,1800'],
        'supervision.offline_after_s' => ['required', 'integer', 'between:90,3600'],
        'anomalies.stalled_minutes' => ['required', 'integer', 'between:5,240'],
        'anomalies.errors_per_hour' => ['required', 'integer', 'between:1,500'],
        'anomalies.outdated_hours' => ['required', 'integer', 'between:1,720'],
        'anomalies.temperature_c' => ['required', 'integer', 'between:40,110'],
        'anomalies.unstable_losses' => ['required', 'integer', 'between:2,50'],
        'anomalies.fallback_minutes' => ['required', 'integer', 'between:5,720'],
        'retention.exchanges_days' => ['required', 'integer', 'between:7,365'],
        'retention.audit_months' => ['required', 'integer', 'between:12,120'],
        'retention.export_hours' => ['required', 'integer', 'between:1,720'],
        'incidents.delay_critical_min' => ['required', 'integer', 'between:5,1440'],
        'incidents.delay_major_min' => ['required', 'integer', 'between:5,2880'],
        'incidents.delay_medium_min' => ['required', 'integer', 'between:5,10080'],
        'incidents.delay_minor_min' => ['required', 'integer', 'between:5,20160'],
        'incidents.default_assignee_id' => ['nullable', 'uuid', 'exists:users,id'],
        'retention.telemetry_days' => ['required', 'integer', 'between:30,730'],
        'retention.notifications_days' => ['required', 'integer', 'between:30,730'],
        'retention.auto_purge' => ['required', 'boolean'],
        'releases.auto_promote' => ['required', 'boolean'],
        'releases.promote_after_h' => ['required', 'integer', 'between:24,720'],
        'retention.receipts_days' => ['required', 'integer', 'between:90,1095'],
        'retention.commands_days' => ['required', 'integer', 'between:30,1095'],
        'retention.states_days' => ['required', 'integer', 'between:90,1825'],
        'documents.four_eyes' => ['required', 'boolean'],
        'campaigns.urgent_from' => ['required', 'integer', 'between:60,99'],
    ];

    public function index(): JsonResponse
    {
        $this->authorizePermission('settings.manage', 'settings.technical');
        $defaults = Setting::defaults();
        $stored = Setting::query()->get()->keyBy('key');

        return response()->json(['data' => [
            'settings' => collect(self::KEYS)->map(fn ($def, $key) => [
                'key' => $key, 'tab' => $def[0], 'type' => $def[1], 'technical' => $def[2],
                'value' => ($stored[$key]->is_secret ?? false) ? '••••••' : ($stored[$key]->value ?? $defaults[$key] ?? null),
                'editable' => $def[2] ? $this->can('settings.technical') : $this->can('settings.manage'),
            ])->values(),
            'sources' => DynamicSource::query()->orderBy('name')->get(['id', 'code', 'name', 'type', 'url', 'is_active', 'frequency_minutes', 'last_fetched_at', 'last_status']),
            'environment' => ['antivirus' => config('saie.antivirus.driver'), 'queue' => config('queue.default'), 'mail' => config('mail.default'), 'mfa_enforced' => config('saie.security.mfa_enforced'), 'timezone' => config('saie.timezone')],
            'notice' => 'Les notifications push et l’API d’intégration sont désactivées tant qu’elles ne sont pas activées ici ; les sources dynamiques ne sont collectées que si elles sont déclarées actives.',
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'in:'.implode(',', array_keys(self::KEYS))], 'value' => ['present'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        // Le droit sur la clé est vérifié avant la valeur : un compte non habilité ne peut pas sonder la validation.
        $this->authorizePermission(self::KEYS[$data['key']][2] ? 'settings.technical' : 'settings.manage');
        $request->validate(array_merge(['value' => self::RULES[$data['key']]], $data['key'] === 'operating.default_window' ? [
            'value.days' => ['required', 'array', 'min:1'], 'value.days.*' => ['integer', 'between:1,7'],
            'value.on' => ['required', 'date_format:H:i'], 'value.off' => ['required', 'date_format:H:i', 'after:value.on'],
        ] : []));
        // Le délai « hors ligne » reste supérieur au délai « suspect » (sinon aucun lecteur ne serait jamais suspect).
        $suspect = $data['key'] === 'supervision.suspect_after_s' ? (int) $data['value'] : (int) Setting::effective('supervision.suspect_after_s');
        $unavailable = $data['key'] === 'supervision.offline_after_s' ? (int) $data['value'] : (int) Setting::effective('supervision.offline_after_s');
        if (str_starts_with($data['key'], 'supervision.') && $unavailable <= $suspect) {
            throw new BusinessException('THRESHOLD_ORDER', 'Le délai « hors ligne » doit dépasser le délai « suspect ».', 422, ['field' => 'value']);
        }
        [, $type, $technical] = self::KEYS[$data['key']];
        $this->authorizePermission($technical ? 'settings.technical' : 'settings.manage');
        $value = match ($type) {
            'integer' => (int) $data['value'], 'boolean' => filter_var($data['value'], FILTER_VALIDATE_BOOLEAN), 'array' => (array) $data['value'], default => mb_substr((string) $data['value'], 0, 300),
        };
        $before = Setting::get($data['key']);
        Setting::put($data['key'], $value, $this->user()->id);
        $this->audit()->log('setting.updated', $data['key'], $data['reason'], ['value' => $before], ['value' => $value]);

        return $this->index();
    }
}
