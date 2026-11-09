<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Support\BusinessException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Modèles par orientation, éditeur à composants structurés : aucun HTML ni script libre (PUB-03, V15, V16).
 */
class TemplateController extends Controller
{
    private const ZONE_TYPES = ['header', 'text', 'image', 'video', 'document', 'clock', 'agenda', 'ticker', 'qr', 'weather', 'countdown'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('templates.view', 'templates.manage');
        $templates = Template::query()
            ->when($request->query('orientation'), fn ($q, $o) => $q->where('orientation', $o))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '!=', 'archived'))
            ->orderBy('name')->get();

        return response()->json(['data' => $templates->map(fn (Template $t) => $this->present($t))]);
    }

    public function show(Template $template): JsonResponse
    {
        $this->authorizePermission('templates.view', 'templates.manage');

        return response()->json(['data' => $this->present($template)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('templates.manage');
        $data = $this->validated($request, true);
        $template = Template::query()->create($data + ['code' => 'TPL-'.strtoupper(Str::random(6)), 'version' => 1, 'status' => 'active', 'created_by' => $this->user()->id]);
        $this->audit()->log('template.created', $template, power: 'modeles');

        return response()->json(['data' => $this->present($template)], 201);
    }

    public function update(Request $request, Template $template): JsonResponse
    {
        $this->authorizePermission('templates.manage');
        if ($template->status === 'archived') {
            throw new BusinessException('TEMPLATE_ARCHIVED', 'Un modèle archivé ne peut plus être modifié.', 409);
        }
        $this->expectVersion($request, $template);
        $data = $this->validated($request, false);
        $template->fill($data);
        $template->version++;
        $template->save();
        $this->audit()->log('template.updated', $template, power: 'modeles', version: $template->version);

        return response()->json(['data' => $this->present($template)]);
    }

    public function duplicate(Template $template): JsonResponse
    {
        $this->authorizePermission('templates.manage');
        $copy = $template->replicate(['code', 'version', 'status', 'is_institutional']);
        $copy->fill(['code' => 'TPL-'.strtoupper(Str::random(6)), 'name' => $template->name.' (copie)', 'version' => 1, 'status' => 'active', 'is_institutional' => false, 'created_by' => $this->user()->id])->save();
        $this->audit()->log('template.duplicated', $copy, power: 'modeles', after: ['source' => $template->code]);

        return response()->json(['data' => $this->present($copy)], 201);
    }

    /**
     * Un modèle archivé reste disponible pour les versions déjà approuvées qui le référencent.
     */
    public function archive(Template $template): JsonResponse
    {
        $this->authorizePermission('templates.manage');
        $template->forceFill(['status' => 'archived'])->save();
        $this->audit()->log('template.archived', $template, power: 'modeles');

        return response()->json(['data' => $this->present($template)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Template $t): array
    {
        return [
            'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'orientation' => $t->orientation, 'purpose' => $t->purpose, 'layout' => $t->layout,
            'version' => $t->version, 'status' => $t->status, 'is_institutional' => $t->is_institutional, 'updated_at' => $t->updated_at?->toAtomString(),
            'used_by' => DB::table('contents')->where('template_id', $t->id)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'orientation' => [$req, 'in:landscape,portrait'],
            'purpose' => ['sometimes', 'in:content,welcome,orientation,room_panel'],
            'layout' => [$req, 'array'],
            'layout.zones' => [$req, 'array', 'min:1', 'max:20'],
            'layout.zones.*.id' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'layout.zones.*.type' => ['required', 'in:'.implode(',', self::ZONE_TYPES)],
            'layout.zones.*.x' => ['required', 'numeric', 'between:0,100'], 'layout.zones.*.y' => ['required', 'numeric', 'between:0,100'],
            'layout.zones.*.w' => ['required', 'numeric', 'between:1,100'], 'layout.zones.*.h' => ['required', 'numeric', 'between:1,100'],
            'layout.zones.*.style' => ['nullable', 'in:primary,light,dark,accent'],
            'layout.zones.*.font_scale' => ['nullable', 'numeric', 'between:0.5,3'],
            'layout.zones.*.align' => ['nullable', 'in:left,center,right'],
            'layout.theme' => ['nullable', 'in:institutionnel,clair,sombre'],
            'layout.transition' => ['nullable', 'in:fade,none,slide'],
            'layout.safe_margin' => ['nullable', 'numeric', 'between:0,10'],
        ]);
        // Une zone doit tenir dans le cadre : position + taille ≤ 100 % sur chaque axe (sinon elle serait coupée à l'écran).
        $outside = collect($data['layout']['zones'] ?? [])->filter(fn (array $z): bool => $z['x'] + $z['w'] > 100.01 || $z['y'] + $z['h'] > 100.01)->pluck('id');
        if ($outside->isNotEmpty()) {
            throw ValidationException::withMessages(['layout.zones' => 'Zone(s) hors du cadre de l’écran : '.$outside->implode(', ').'. Réduisez la taille ou la position.']);
        }

        return $data;
    }
}
