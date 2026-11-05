<?php

namespace App\Services;

use App\Models\Building;
use App\Models\ContentCategory;
use App\Models\DisplayZone;
use App\Models\Equipment;
use App\Models\Floor;
use App\Models\Holiday;
use App\Models\Language;
use App\Models\Location;
use App\Models\Room;
use App\Models\ServiceType;
use App\Models\Site;
use App\Models\Structure;
use App\Support\BusinessException;
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
            'sites' => ['model' => Site::class, 'key' => 'code', 'csv' => ['code', 'name', 'timezone', 'address', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40', Rule::unique('sites', 'code')->ignore($m?->getKey())], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'],
                'timezone' => ['sometimes', 'timezone'], 'address' => ['nullable', 'string', 'max:200'], 'is_active' => ['sometimes', 'boolean']]],
            'buildings' => ['model' => Building::class, 'key' => 'code', 'csv' => ['code', 'name', 'site_code', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40'], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'], 'site_id' => [$m ? 'sometimes' : 'required', 'uuid', 'exists:sites,id'], 'is_active' => ['sometimes', 'boolean']]],
            'floors' => ['model' => Floor::class, 'key' => 'code', 'csv' => ['code', 'label', 'level', 'building_code', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40'], 'label' => [$m ? 'sometimes' : 'required', 'string', 'max:120'], 'level' => [$m ? 'sometimes' : 'required', 'integer', 'between:-5,60'],
                'building_id' => [$m ? 'sometimes' : 'required', 'uuid', 'exists:buildings,id'], 'is_active' => ['sometimes', 'boolean']]],
            'zones' => ['model' => DisplayZone::class, 'key' => 'code', 'csv' => ['code', 'name', 'max_classification', 'audio_allowed', 'audio_max_volume', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40', Rule::unique('display_zones', 'code')->ignore($m?->getKey())], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'],
                'max_classification' => [$m ? 'sometimes' : 'required', 'in:public,interne'], 'audio_allowed' => ['sometimes', 'boolean'], 'audio_max_volume' => ['sometimes', 'integer', 'between:0,100'], 'is_active' => ['sometimes', 'boolean']]],
            'locations' => ['model' => Location::class, 'key' => 'code', 'csv' => ['code', 'name', 'floor_code', 'zone_code', 'description', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40'], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'], 'floor_id' => [$m ? 'sometimes' : 'required', 'uuid', 'exists:floors,id'],
                'zone_id' => [$m ? 'sometimes' : 'required', 'uuid', 'exists:display_zones,id'], 'description' => ['nullable', 'string', 'max:500'], 'is_active' => ['sometimes', 'boolean']]],
            'structures' => ['model' => Structure::class, 'key' => 'code', 'csv' => ['code', 'name', 'type', 'parent_code', 'floor_code', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40', Rule::unique('structures', 'code')->ignore($m?->getKey())], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:160'],
                'type' => [$m ? 'sometimes' : 'required', 'in:cabinet,departement,direction,service,autre'], 'parent_id' => ['nullable', 'uuid', 'exists:structures,id'], 'floor_id' => ['nullable', 'uuid', 'exists:floors,id'],
                'show_in_orientation' => ['sometimes', 'boolean'], 'storage_quota_mb' => ['nullable', 'integer', 'min:1'], 'is_active' => ['sometimes', 'boolean']]],
            'categories' => ['model' => ContentCategory::class, 'key' => 'code', 'csv' => ['code', 'name', 'default_duration_s', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40', Rule::unique('content_categories', 'code')->ignore($m?->getKey())], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'],
                'default_duration_s' => ['sometimes', 'integer', 'between:10,120'], 'is_active' => ['sometimes', 'boolean']]],
            'languages' => ['model' => Language::class, 'key' => 'code', 'csv' => ['code', 'name', 'is_active', 'sort'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'prohibited' : 'required', 'in:fr,en,pt,es'], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:40'], 'is_active' => ['sometimes', 'boolean'], 'sort' => ['sometimes', 'integer']]],
            'service_types' => ['model' => ServiceType::class, 'key' => 'code', 'csv' => ['code', 'name', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40', Rule::unique('service_types', 'code')->ignore($m?->getKey())], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'], 'is_active' => ['sometimes', 'boolean']]],
            'holidays' => ['model' => Holiday::class, 'key' => 'date', 'csv' => ['date', 'label'], 'rules' => fn (?Model $m) => [
                'date' => [$m ? 'sometimes' : 'required', 'date', Rule::unique('holidays', 'date')->ignore($m?->getKey())], 'label' => [$m ? 'sometimes' : 'required', 'string', 'max:120']]],
            'equipment' => ['model' => Equipment::class, 'key' => 'code', 'csv' => ['code', 'name', 'is_active'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40', Rule::unique('equipment', 'code')->ignore($m?->getKey())], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'], 'is_active' => ['sometimes', 'boolean']]],
            'rooms' => ['model' => Room::class, 'key' => 'code', 'csv' => ['code', 'name', 'floor_code', 'capacity', 'approval_mode', 'prep_minutes', 'cleanup_minutes', 'open_from', 'open_to'], 'rules' => fn (?Model $m) => [
                'code' => [$m ? 'sometimes' : 'required', 'string', 'max:40'], 'name' => [$m ? 'sometimes' : 'required', 'string', 'max:120'], 'floor_id' => [$m ? 'sometimes' : 'required', 'uuid', 'exists:floors,id'],
                'capacity' => [$m ? 'sometimes' : 'required', 'integer', 'min:1'], 'approval_mode' => ['sometimes', 'in:auto,manual'], 'prep_minutes' => ['sometimes', 'integer', 'between:0,240'],
                'cleanup_minutes' => ['sometimes', 'integer', 'between:0,240'], 'open_from' => ['sometimes', 'date_format:H:i'], 'open_to' => ['sometimes', 'date_format:H:i']]],
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
     * Traduit les colonnes « *_code » d'une ligne CSV en identifiants internes.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    public static function resolveCodes(array $row): array
    {
        $map = ['site_code' => [Site::class, 'site_id'], 'building_code' => [Building::class, 'building_id'], 'floor_code' => [Floor::class, 'floor_id'], 'zone_code' => [DisplayZone::class, 'zone_id'], 'parent_code' => [Structure::class, 'parent_id']];
        foreach ($map as $column => [$model, $target]) {
            if (array_key_exists($column, $row)) {
                $value = trim((string) $row[$column]);
                $row[$target] = $value === '' ? null : ($model::query()->where('code', $value)->value('id') ?? 'introuvable:'.$value);
                unset($row[$column]);
            }
        }
        foreach (['is_active', 'audio_allowed'] as $flag) {
            if (array_key_exists($flag, $row)) {
                $row[$flag] = in_array(strtolower(trim((string) $row[$flag])), ['1', 'oui', 'true', 'yes', 'o'], true);
            }
        }

        return array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);
    }
}
