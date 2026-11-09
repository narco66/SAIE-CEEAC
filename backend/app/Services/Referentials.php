<?php

namespace App\Services;

use App\Models\Building;
use App\Models\ContentCategory;
use App\Models\DisplayZone;
use App\Models\Equipment;
use App\Models\Floor;
use App\Models\Holiday;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScreenCategory;
use App\Models\ServiceType;
use App\Models\Site;
use App\Models\Structure;
use App\Support\BusinessException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Définition déclarative des référentiels administrables (V36) et importables (V37).
 */
class Referentials
{
    /**
     * @return array<string, array{model: class-string<Model>, key: string, rules: callable(?Model): array<string, mixed>, csv: list<string>}>
     */
    public static function definitions(): array
    {
        return [
            // §6-a : niveau Institution, au-dessus des sites.
            'institutions' => ['model' => Institution::class, 'key' => 'code', 'csv' => ['code', 'name', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40', Rule::unique('institutions', 'code')->ignore($m?->getKey())], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:160'], 'is_active' => ['sometimes', 'boolean']]],
            // §5.1-e : catégories d'écran paramétrables.
            'screen_categories' => ['model' => ScreenCategory::class, 'key' => 'code', 'csv' => ['code', 'name', 'description', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40', Rule::unique('screen_categories', 'code')->ignore($m?->getKey())], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'],
                'description' => ['nullable', 'string', 'max:300'], 'is_active' => ['sometimes', 'boolean']]],
            'sites' => ['model' => Site::class, 'key' => 'code', 'csv' => ['code', 'name', 'timezone', 'address', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40', Rule::unique('sites', 'code')->ignore($m?->getKey())], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'],
                'timezone' => ['sometimes', 'timezone'], 'address' => ['nullable', 'string', 'max:200'], 'is_active' => ['sometimes', 'boolean'],
                'institution_id' => ['nullable', 'uuid', 'exists:institutions,id']]],
            'buildings' => ['model' => Building::class, 'key' => 'code', 'parent' => 'site_id', 'csv' => ['code', 'name', 'site_code', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40'], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'], 'site_id' => [$m ? 'sometimes' : 'required', 'uuid', 'exists:sites,id'], 'is_active' => ['sometimes', 'boolean']]],
            'floors' => ['model' => Floor::class, 'key' => 'code', 'parent' => 'building_id', 'csv' => ['code', 'label', 'level', 'building_code', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40'], 'label' => [$m ? 'sometimes' : 'required', 'string', 'max:120'], 'level' => [$m ? 'sometimes' : 'required', 'integer', 'between:-5,60'],
                'building_id' => [$m ? 'sometimes' : 'required', 'uuid', 'exists:buildings,id'], 'is_active' => ['sometimes', 'boolean'],
                // PAR-04 : plan du niveau (image saine de la médiathèque), affiché par les bornes d'orientation.
                'plan_asset_version_id' => ['nullable', 'uuid', Rule::exists('asset_versions', 'id')->where('scan_status', 'ready')]]],
            'zones' => ['model' => DisplayZone::class, 'key' => 'code', 'csv' => ['code', 'name', 'max_classification', 'audio_allowed', 'audio_max_volume', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40', Rule::unique('display_zones', 'code')->ignore($m?->getKey())], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'],
                'max_classification' => [$m ? 'sometimes' : 'required', 'in:public,interne'], 'audio_allowed' => ['sometimes', 'boolean'], 'audio_max_volume' => ['sometimes', 'integer', 'between:0,100'], 'is_active' => ['sometimes', 'boolean']]],
            'locations' => ['model' => Location::class, 'key' => 'code', 'parent' => 'floor_id', 'csv' => ['code', 'name', 'building_code', 'floor_code', 'zone_code', 'description', 'type', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40'], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'], 'floor_id' => [$m ? 'sometimes' : 'required', 'uuid', 'exists:floors,id'],
                'zone_id' => [$m ? 'sometimes' : 'required', 'uuid', 'exists:display_zones,id'], 'description' => ['nullable', 'string', 'max:500'], 'is_active' => ['sometimes', 'boolean'],
                'type' => ['sometimes', 'in:reception,office,meeting_room,common_area,corridor,restaurant,technical,other'],
                // §5.3 : responsable de l'espace et photographie de l'emplacement (image saine de la médiathèque).
                'manager_id' => ['nullable', 'uuid', 'exists:users,id'], 'photo_asset_version_id' => ['nullable', 'uuid', Rule::exists('asset_versions', 'id')->where('scan_status', 'ready')]]],
            'structures' => ['model' => Structure::class, 'key' => 'code', 'csv' => ['code', 'name', 'type', 'parent_code', 'floor_code', 'office_location', 'reception_phone', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40', Rule::unique('structures', 'code')->ignore($m?->getKey())], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:160'],
                'type' => [$m ? 'sometimes' : 'required', 'in:cabinet,departement,direction,service,autre'], 'parent_id' => ['nullable', 'uuid', 'exists:structures,id'], 'floor_id' => ['nullable', 'uuid', 'exists:floors,id'],
                'show_in_orientation' => ['sometimes', 'boolean'], 'storage_quota_mb' => ['nullable', 'integer', 'min:1'], 'is_active' => ['sometimes', 'boolean'],
                // ORI-01 : informations d'annuaire publiques (jamais de nom de personne : ACC-01).
                'office_location' => ['nullable', 'string', 'max:120'], 'reception_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+().\s-]+$/']]],
            'categories' => ['model' => ContentCategory::class, 'key' => 'code', 'csv' => ['code', 'name', 'default_duration_s', 'approval_levels', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40', Rule::unique('content_categories', 'code')->ignore($m?->getKey())], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'],
                'default_duration_s' => ['sometimes', 'integer', 'between:10,120'], 'approval_levels' => ['sometimes', 'integer', 'between:1,3'], 'is_active' => ['sometimes', 'boolean']]],
            'languages' => ['model' => Language::class, 'key' => 'code', 'csv' => ['code', 'name', 'is_active', 'sort'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'prohibited' : 'required', 'in:fr,en,pt,es'], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:40'], 'is_active' => ['sometimes', 'boolean'], 'sort' => ['sometimes', 'integer']]],
            'service_types' => ['model' => ServiceType::class, 'key' => 'code', 'csv' => ['code', 'name', 'requires_headcount', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => $m ? ['sometimes', self::unchangedCode($m)] : ['required', 'string', 'max:40', Rule::unique('service_types', 'code')], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'],
                'requires_headcount' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean']]],
            'holidays' => ['model' => Holiday::class, 'key' => 'date', 'csv' => ['date', 'label'], 'rules' => fn (?Model $m) => [
                'date' => [$m ? 'sometimes' : 'required', 'date', Rule::unique('holidays', 'date')->ignore($m?->getKey())], 'label' => [$m ? 'sometimes' : 'required', 'string', 'max:120']]],
            'equipment' => ['model' => Equipment::class, 'key' => 'code', 'csv' => ['code', 'name', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40', Rule::unique('equipment', 'code')->ignore($m?->getKey())], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'], 'is_active' => ['sometimes', 'boolean']]],
            'rooms' => ['model' => Room::class, 'key' => 'code', 'csv' => ['code', 'name', 'floor_code', 'capacity', 'approval_mode', 'prep_minutes', 'cleanup_minutes', 'open_from', 'open_to'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40'], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'], 'floor_id' => [$m ? 'sometimes' : 'required', 'uuid', 'exists:floors,id'],
                'capacity' => [$m ? 'sometimes' : 'required', 'integer', 'min:1'], 'approval_mode' => ['sometimes', 'in:auto,manual'], 'prep_minutes' => ['sometimes', 'integer', 'between:0,240'],
                'cleanup_minutes' => ['sometimes', 'integer', 'between:0,240'], 'open_from' => ['sometimes', 'date_format:H:i'], 'open_to' => ['sometimes', 'date_format:H:i'],
                // §6-a : la salle peut être rattachée à un emplacement de son niveau.
                'location_id' => ['nullable', 'uuid', 'exists:locations,id']]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(string $type): array
    {
        return self::definitions()[$type] ?? throw new BusinessException('REFERENTIAL_UNKNOWN', 'Référentiel inconnu.', 404);
    }

    /**
     * Code figé après création : les périmètres du pouvoir « prestations » désignent les types par leur code.
     */
    private static function unchangedCode(Model $model): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model): void {
            if ($value !== $model->getAttribute('code')) {
                $fail('Le code d’un type de prestation ne se modifie pas : les périmètres du pouvoir « prestations » y font référence.');
            }
        };
    }

    /**
     * Traduit les colonnes « *_code » d'une ligne CSV en identifiants internes.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    public static function resolveCodes(array $row): array
    {
        $map = ['site_code' => [Site::class, 'site_id'], 'building_code' => [Building::class, 'building_id'], 'floor_code' => [Floor::class, 'floor_id'], 'zone_code' => [DisplayZone::class, 'zone_id'], 'parent_code' => [Structure::class, 'parent_id']];
        // Un niveau n'est unique que dans son bâtiment : building_code, s'il est fourni, lève l'ambiguïté de floor_code.
        $building = isset($row['building_code']) && trim((string) $row['building_code']) !== '' ? Building::query()->where('code', trim((string) $row['building_code']))->value('id') : null;
        foreach ($map as $column => [$model, $target]) {
            if (array_key_exists($column, $row)) {
                $value = trim((string) $row[$column]);
                if ($value === '') {
                    $row[$target] = null;
                } else {
                    $ids = $model::query()->where('code', $value)->when($column === 'floor_code' && $building, fn ($q) => $q->where('building_id', $building))->limit(2)->pluck('id');
                    $row[$target] = match ($ids->count()) {
                        0 => 'introuvable:'.$value, 1 => $ids->first(), default => 'ambigu:'.$value
                    };
                }
                unset($row[$column]);
            }
        }
        // building_code ne sert qu'à désigner le niveau d'un emplacement : il n'est pas un attribut de l'emplacement.
        if (array_key_exists('floor_id', $row) && array_key_exists('building_id', $row)) {
            unset($row['building_id']);
        }
        foreach (['is_active', 'audio_allowed', 'requires_headcount'] as $flag) {
            if (array_key_exists($flag, $row)) {
                $row[$flag] = in_array(strtolower(trim((string) $row[$flag])), ['1', 'oui', 'true', 'yes', 'o'], true);
            }
        }

        return array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);
    }
}
