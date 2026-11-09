<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DynamicSource;
use App\Services\DynamicSources;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Liste blanche des sources dynamiques (V39, PUB-10), administrée par la DSI.
 */
class DynamicSourceController extends Controller
{
    public function __construct(private DynamicSources $sources) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('settings.technical', 'settings.manage');

        return response()->json(['data' => DynamicSource::query()->orderBy('name')->get()->map(fn (DynamicSource $s) => $this->present($s))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.technical');
        $data = $this->validated($request);
        if (! empty($data['url'])) {
            $this->sources->assertSafeUrl($data['url']);
        }
        $source = DynamicSource::query()->create($data);
        $this->audit()->log('source.created', $source, after: $data);

        return response()->json(['data' => $this->present($source)], 201);
    }

    public function update(Request $request, DynamicSource $source): JsonResponse
    {
        $this->authorizePermission('settings.technical');
        $data = $this->validated($request, $source);
        if (! empty($data['url'])) {
            $this->sources->assertSafeUrl($data['url']);
        }
        $before = $source->only(array_keys($data));
        $source->update($data);
        $this->audit()->log('source.updated', $source, before: $before, after: $data);

        return response()->json(['data' => $this->present($source->refresh())]);
    }

    public function test(DynamicSource $source): JsonResponse
    {
        $this->authorizePermission('settings.technical');
        $result = $this->sources->fetch($source);
        $this->audit()->log('source.tested', $source, after: ['status' => $result['status']]);

        return response()->json(['data' => $result + ['source' => $this->present($source->refresh())]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DynamicSource $s): array
    {
        return ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'type' => $s->type, 'url' => $s->url, 'config' => $s->config, 'is_active' => $s->is_active,
            'frequency_minutes' => $s->frequency_minutes, 'last_fetched_at' => Present::date($s->last_fetched_at), 'last_status' => $s->last_status];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?DynamicSource $source = null): array
    {
        $req = $source ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$req, 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/', Rule::unique('dynamic_sources', 'code')->ignore($source?->id)],
            'name' => [$req, 'string', 'max:120'], 'type' => [$req, 'in:'.implode(',', DynamicSources::TYPES)],
            'url' => ['nullable', 'url:https', 'max:500', Rule::requiredIf(fn () => in_array($request->input('type', $source?->type), ['rss', 'json', 'weather'], true))],
            'config' => ['nullable', 'array'], 'config.*' => ['nullable', 'string', 'max:200'],
            'is_active' => ['sometimes', 'boolean'], 'frequency_minutes' => ['sometimes', 'integer', 'between:5,1440'],
        ]);
    }
}
