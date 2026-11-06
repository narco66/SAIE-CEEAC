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
        'operating.default_window' => ['Fonctionnement et audio', 'array', true],
        'operating.audio_max_volume' => ['Fonctionnement et audio', 'integer', true],
        'rooms.pending_hold_hours' => ['Salles', 'integer', false],
        'rooms.no_show_auto' => ['Salles', 'boolean', false],
        'channels.smtp_enabled' => ['Canaux', 'boolean', true],
        'channels.push_enabled' => ['Canaux', 'boolean', true],
        'integrations.api_enabled' => ['Intégrations', 'boolean', true],
    ];

    /**
     * Règles de validation serveur propres à chaque paramètre (les bornes ne dépendent pas du navigateur).
     *
     * @var array<string, list<string>>
     */
    private const RULES = [
        'identity.institution_name' => ['required', 'string', 'max:160'],
        'display.tagline' => ['nullable', 'string', 'max:200'],
        'display.default_slide_s' => ['required', 'integer', 'between:10,300'],
        'operating.default_window' => ['required', 'array'],
        'operating.audio_max_volume' => ['required', 'integer', 'between:0,100'],
        'rooms.pending_hold_hours' => ['required', 'integer', 'between:1,168'],
        'rooms.no_show_auto' => ['required', 'boolean'],
        'channels.smtp_enabled' => ['required', 'boolean'],
        'channels.push_enabled' => ['required', 'boolean'],
        'integrations.api_enabled' => ['required', 'boolean'],
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
        $request->validate(array_merge(['value' => self::RULES[$data['key']]], $data['key'] === 'operating.default_window' ? [
            'value.days' => ['required', 'array', 'min:1'], 'value.days.*' => ['integer', 'between:1,7'],
            'value.on' => ['required', 'date_format:H:i'], 'value.off' => ['required', 'date_format:H:i', 'after:value.on'],
        ] : []));
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
