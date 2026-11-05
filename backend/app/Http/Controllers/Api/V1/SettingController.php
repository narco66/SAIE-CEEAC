<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DynamicSource;
use App\Models\Setting;
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
        'display.default_slide_s' => ['Diffusion', 'integer', false],
        'display.language_rotation' => ['Diffusion', 'array', false],
        'operating.default_window' => ['Fonctionnement et audio', 'array', true],
        'operating.audio_max_volume' => ['Fonctionnement et audio', 'integer', true],
        'rooms.pending_hold_hours' => ['Salles', 'integer', false],
        'rooms.no_show_auto' => ['Salles', 'boolean', false],
        'retention.audit_months' => ['Conservation', 'integer', true],
        'retention.trash_days' => ['Conservation', 'integer', true],
        'channels.smtp_enabled' => ['Canaux', 'boolean', true],
        'channels.push_enabled' => ['Canaux', 'boolean', true],
        'integrations.sso_enabled' => ['Intégrations', 'boolean', true],
        'integrations.calendar_connector' => ['Intégrations', 'string', true],
        'integrations.api_enabled' => ['Intégrations', 'boolean', true],
    ];

    public function index(): JsonResponse
    {
        $this->authorizePermission('settings.manage', 'settings.technical');
        $defaults = [
            'identity.institution_name' => 'Commission de la CEEAC', 'display.tagline' => 'Communauté Économique des États de l’Afrique Centrale', 'display.default_slide_s' => 12,
            'display.language_rotation' => ['fr', 'en'], 'operating.default_window' => config('saie.operating_window'), 'operating.audio_max_volume' => 40,
            'rooms.pending_hold_hours' => 48, 'rooms.no_show_auto' => false, 'retention.audit_months' => config('saie.retention.audit_months'), 'retention.trash_days' => config('saie.retention.trash_days'),
            'channels.smtp_enabled' => config('mail.default') !== 'log', 'channels.push_enabled' => false, 'integrations.sso_enabled' => false, 'integrations.calendar_connector' => 'ics', 'integrations.api_enabled' => false,
        ];
        $stored = Setting::query()->get()->keyBy('key');

        return response()->json(['data' => [
            'settings' => collect(self::KEYS)->map(fn ($def, $key) => [
                'key' => $key, 'tab' => $def[0], 'type' => $def[1], 'technical' => $def[2],
                'value' => ($stored[$key]->is_secret ?? false) ? '••••••' : ($stored[$key]->value ?? $defaults[$key] ?? null),
                'editable' => $def[2] ? $this->can('settings.technical') : $this->can('settings.manage'),
            ])->values(),
            'sources' => DynamicSource::query()->orderBy('name')->get(['id', 'code', 'name', 'type', 'url', 'is_active', 'frequency_minutes', 'last_fetched_at', 'last_status']),
            'environment' => ['antivirus' => config('saie.antivirus.driver'), 'queue' => config('queue.default'), 'mail' => config('mail.default'), 'mfa_enforced' => config('saie.security.mfa_enforced'), 'timezone' => config('saie.timezone')],
            'notice' => 'Les composants dynamiques (PUB-10) et intégrations (API-07, SAL-11) sont des fonctions P1 désactivées par défaut.',
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'in:'.implode(',', array_keys(self::KEYS))], 'value' => ['present'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
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
