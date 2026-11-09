<?php

namespace Database\Seeders;

use App\Models\ContentCategory;
use App\Models\Equipment;
use App\Models\Language;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\Template;
use Illuminate\Database\Seeder;

/**
 * Référentiel minimal requis en tout environnement. Rejouable : il crée ce qui manque sans écraser les paramétrages existants.
 */
class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('saie.permissions') as $code => [$name, $group]) {
            Permission::query()->firstOrCreate(['code' => $code], ['name' => $name, 'group' => $group]);
        }
        foreach (config('saie.roles') as $code => [$name, $permissions]) {
            $role = Role::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_system' => true]);
            if ($role->wasRecentlyCreated || $role->permissions()->count() === 0) {
                $role->permissions()->sync(Permission::query()->whereIn('code', $permissions)->pluck('id'));
            }
        }

        foreach ([['fr', 'Français', true, 1], ['en', 'English', true, 2], ['pt', 'Português', false, 3], ['es', 'Español', false, 4]] as [$code, $name, $active, $sort]) {
            Language::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => $active, 'sort' => $sort]);
        }

        foreach ([
            ['COMMUNIQUE', 'Communiqué'], ['ANNONCE', 'Annonce institutionnelle'], ['ACCUEIL', 'Accueil des délégations'], ['AGENDA', 'Agenda des réunions'],
            ['RH', 'Information RH'], ['NOTE-SERVICE', 'Note de service'], ['SECURITE', 'Consigne de sécurité'], ['ACTUALITE', 'Actualité'],
            ['INDICATEUR', 'Indicateur public'], ['DSI', 'Information DSI'], ['DIRECTION', 'Message de la Présidence'], ['FORMATION', 'Formation'], ['REPLI', 'Contenu de repli'],
        ] as [$code, $name]) {
            // Deux niveaux de validation proposés pour les communiqués et les messages de la Présidence (décision D2 à valider).
            ContentCategory::query()->firstOrCreate(['code' => $code], ['name' => $name, 'approval_levels' => in_array($code, ['COMMUNIQUE', 'DIRECTION'], true) ? 2 : 1]);
        }

        foreach ([['INTERPRETATION', 'Interprétation'], ['VISIO', 'Visioconférence'], ['ASSISTANCE', 'Assistance technique'], ['SONO', 'Sonorisation'], ['RESTAURATION', 'Pause-café et restauration']] as [$code, $name]) {
            ServiceType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true, 'requires_headcount' => $code === 'RESTAURATION']);
        }

        foreach ([['VISIO', 'Visioconférence'], ['ECRAN65', 'Écran 65"'], ['ECRAN85', 'Écran 85"'], ['INTERP', 'Cabines d’interprétation'], ['SONO', 'Sonorisation'], ['REGIE', 'Régie audiovisuelle'], ['TABLEAU', 'Tableau blanc']] as [$code, $name]) {
            Equipment::query()->firstOrCreate(['code' => $code], ['name' => $name]);
        }

        $templates = [
            ['TPL-INST-PAYSAGE', 'Accueil institutionnel — paysage', 'landscape', 'content', [
                ['id' => 'header', 'type' => 'header', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 11, 'style' => 'dark'],
                ['id' => 'title', 'type' => 'text', 'x' => 5, 'y' => 16, 'w' => 50, 'h' => 50, 'style' => 'light', 'align' => 'left'],
                ['id' => 'media', 'type' => 'image', 'x' => 58, 'y' => 16, 'w' => 37, 'h' => 56],
                ['id' => 'qr', 'type' => 'qr', 'x' => 5, 'y' => 70, 'w' => 10, 'h' => 16],
                ['id' => 'agenda', 'type' => 'agenda', 'x' => 0, 'y' => 89, 'w' => 85, 'h' => 11, 'style' => 'dark'],
                ['id' => 'clock', 'type' => 'clock', 'x' => 85, 'y' => 89, 'w' => 15, 'h' => 11, 'style' => 'dark'],
            ]],
            ['TPL-INST-PORTRAIT', 'Communication interne — portrait', 'portrait', 'content', [
                ['id' => 'header', 'type' => 'header', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 7, 'style' => 'dark'],
                ['id' => 'media', 'type' => 'image', 'x' => 5, 'y' => 10, 'w' => 90, 'h' => 32],
                ['id' => 'title', 'type' => 'text', 'x' => 5, 'y' => 45, 'w' => 90, 'h' => 33, 'style' => 'light'],
                ['id' => 'qr', 'type' => 'qr', 'x' => 75, 'y' => 79, 'w' => 20, 'h' => 10],
                ['id' => 'agenda', 'type' => 'agenda', 'x' => 0, 'y' => 90, 'w' => 100, 'h' => 10, 'style' => 'dark'],
            ]],
            ['TPL-DOC-PAYSAGE', 'Document PDF plein écran — paysage', 'landscape', 'content', [
                ['id' => 'header', 'type' => 'header', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 9, 'style' => 'dark'],
                ['id' => 'document', 'type' => 'document', 'x' => 3, 'y' => 11, 'w' => 70, 'h' => 78],
                ['id' => 'title', 'type' => 'text', 'x' => 75, 'y' => 11, 'w' => 22, 'h' => 78, 'style' => 'light'],
                ['id' => 'ticker', 'type' => 'ticker', 'x' => 0, 'y' => 91, 'w' => 100, 'h' => 9, 'style' => 'dark'],
            ]],
            ['TPL-BIENVENUE', 'Bienvenue délégation', 'landscape', 'welcome', [
                ['id' => 'header', 'type' => 'header', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 11, 'style' => 'dark'],
                ['id' => 'title', 'type' => 'text', 'x' => 8, 'y' => 22, 'w' => 84, 'h' => 50, 'style' => 'primary', 'align' => 'center'],
                ['id' => 'clock', 'type' => 'clock', 'x' => 80, 'y' => 89, 'w' => 20, 'h' => 11, 'style' => 'dark'],
            ]],
        ];
        foreach ($templates as [$code, $name, $orientation, $purpose, $zones]) {
            Template::query()->firstOrCreate(['code' => $code], ['name' => $name, 'orientation' => $orientation, 'purpose' => $purpose, 'layout' => ['zones' => $zones, 'theme' => 'institutionnel', 'transition' => 'fade', 'safe_margin' => 5], 'is_institutional' => true, 'status' => 'active']);
        }
    }
}
