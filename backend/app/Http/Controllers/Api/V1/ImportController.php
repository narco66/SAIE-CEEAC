<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImportRun;
use App\Services\Referentials;
use App\Support\BusinessException;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Imports CSV (V37, REF-03) : modèle téléchargeable, simulation sans écriture, erreurs ligne par ligne, idempotence par code
 * métier, aucune suppression implicite.
 */
class ImportController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('imports.run');

        return response()->json(['data' => ImportRun::query()->with('creator')->orderByDesc('created_at')->limit(30)->get()->map(fn ($r) => [
            'id' => $r->id, 'type' => $r->type, 'file_name' => $r->file_name, 'status' => $r->status, 'summary' => $r->summary, 'creator' => $r->creator?->name, 'created_at' => Present::date($r->created_at),
        ]), 'types' => array_keys(Referentials::definitions())]);
    }

    public function template(string $type): StreamedResponse
    {
        $this->authorizePermission('imports.run');
        $definition = Referentials::definition($type);

        return response()->streamDownload(function () use ($definition): void {
            echo "\xEF\xBB\xBF".implode(';', $definition['csv'])."\n";
        }, "modele-$type.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function simulate(Request $request): JsonResponse
    {
        $this->authorizePermission('imports.run');
        $data = $request->validate(['type' => ['required', 'string'], 'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        return response()->json(['data' => $this->analyse($data['type'], $request->file('file'), false)]);
    }

    public function commit(Request $request): JsonResponse
    {
        $this->authorizePermission('imports.run');
        $data = $request->validate(['type' => ['required', 'string'], 'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $result = DB::transaction(fn () => $this->analyse($data['type'], $request->file('file'), true));
        if ($result['summary']['invalid'] > 0) {
            throw new BusinessException('IMPORT_INVALID', 'Le fichier contient des lignes invalides : corrigez-les avant confirmation.', 422, $result);
        }
        ImportRun::query()->create(['type' => $data['type'], 'file_name' => $request->file('file')->getClientOriginalName(), 'match_key' => Referentials::definition($data['type'])['key'], 'status' => 'committed', 'summary' => $result['summary'], 'created_by' => $this->user()->id]);
        $this->audit()->log('import.committed', $data['type'], after: $result['summary']);

        return response()->json(['data' => $result]);
    }

    /**
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>}
     */
    private function analyse(string $type, UploadedFile $file, bool $write): array
    {
        $definition = Referentials::definition($type);
        $handle = fopen($file->getRealPath(), 'r');
        $first = (string) fgets($handle);
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $headers = array_map(fn ($h) => strtolower(trim(str_replace("\xEF\xBB\xBF", '', $h))), str_getcsv($first, $delimiter));
        $missing = array_diff([$definition['key']], $headers);
        if ($missing !== []) {
            throw new BusinessException('IMPORT_HEADERS', 'Colonnes obligatoires absentes : '.implode(', ', $missing), 422);
        }
        $rows = [];
        $seen = [];
        $line = 1;
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'invalid' => 0, 'duplicates' => 0];
        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;
            if ($cells === [null] || trim(implode('', $cells)) === '') {
                continue;
            }
            $raw = array_combine($headers, array_pad(array_slice($cells, 0, count($headers)), count($headers), ''));
            $values = Referentials::resolveCodes(array_intersect_key($raw, array_flip($definition['csv'])));
            $key = (string) ($values[$definition['key']] ?? '');
            // REF-03 : niveaux, emplacements et bâtiments ne sont uniques que dans leur parent (même code « PALIER » à chaque étage).
            $parent = isset($definition['parent']) ? (string) ($values[$definition['parent']] ?? '') : '';
            $seenKey = $parent.'|'.$key;
            if (isset($seen[$seenKey])) {
                $counts['duplicates']++;
                $counts['invalid']++;
                $rows[] = ['line' => $line, 'key' => $key, 'status' => 'invalid', 'errors' => ["Doublon de la ligne {$seen[$seenKey]} dans le fichier."]];

                continue;
            }
            $seen[$seenKey] = $line;
            $unresolved = collect($values)->filter(fn ($v) => is_string($v) && (str_starts_with($v, 'introuvable:') || str_starts_with($v, 'ambigu:')))
                ->map(fn ($v, $k) => str_starts_with($v, 'ambigu:') ? "Référence ambiguë pour $k : ".substr($v, 7).' existe dans plusieurs bâtiments ; ajoutez la colonne building_code' : "Référence inconnue pour $k : ".substr($v, 12))->values()->all();
            $existing = $unresolved !== [] ? null : $definition['model']::query()->where($definition['key'], $key)
                ->when(isset($definition['parent']), fn ($q) => $q->where($definition['parent'], $values[$definition['parent']] ?? null))->first();
            $validator = Validator::make($values, ($definition['rules'])($existing));
            if ($unresolved !== [] || $validator->fails()) {
                $counts['invalid']++;
                $rows[] = ['line' => $line, 'key' => $key, 'status' => 'invalid', 'errors' => array_merge($unresolved, $validator->errors()->all())];

                continue;
            }
            $clean = $validator->validated();
            if ($existing) {
                $existing->fill($clean);
                $dirty = array_keys($existing->getDirty());
                $status = $dirty === [] ? 'unchanged' : 'updated';
                if ($write && $dirty !== []) {
                    $existing->save();
                }
            } else {
                $status = 'created';
                if ($write) {
                    $definition['model']::query()->create($clean);
                }
            }
            $counts[$status]++;
            $rows[] = ['line' => $line, 'key' => $key, 'status' => $status, 'errors' => [], 'changes' => $existing ? ($dirty ?? []) : array_keys($clean)];
        }
        fclose($handle);

        return ['summary' => $counts + ['total' => count($rows), 'written' => $write], 'rows' => $rows];
    }
}
