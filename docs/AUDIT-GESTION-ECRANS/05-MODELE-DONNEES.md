# 05 — Modèle de données

Toutes les évolutions sont **additives** (aucune table ni colonne supprimée, aucune donnée effacée). Sauvegarde préalable vérifiée par restauration : `backend/storage/app/private/backups/saie_ceeac-avant-module-ecrans-20261108-0738.dump`.

## 1. Correspondance avec les entités recommandées (§17.1)

| Entité recommandée | Table réelle | Nature |
|---|---|---|
| buildings, floors, rooms | `buildings`, `floors` (plan `plan_asset_version_id`), `rooms`, `locations` (type, responsable, photo) | Existantes, complétées |
| screens | `screens` | Complétée |
| screen_types, screen_models | colonnes `kind`, `technology`, `manufacturer`, `model` | Pas de table dédiée (référentiel simple, évite la redondance) |
| screen_profiles | `screen_profiles` | **Créée** |
| screen_groups, screen_group_members | `screen_groups` (type, critères), `group_members` | Existantes, complétées |
| media_players | `devices` | Existante, complétée |
| player_capabilities | `devices.capabilities` (jsonb) | Colonne ajoutée |
| player_heartbeats | `device_telemetry` (échantillon ~5 min) + dernier état dans `devices` | Existante |
| screen_commands, screen_command_logs | `device_commands` (état, compte rendu) + `audit_logs` | **Créée** |
| screen_assignments | `campaign_targets`, `campaign_transmissions` | Existantes (moteur de programmation unique) |
| screen_incidents, screen_maintenance | `incidents`, `maintenance_plans` | Existantes, complétées |
| screen_status_history | `screen_status_history` | **Créée** |
| screen_audit_logs | `audit_logs` (ajout seul, déclencheur) | Existante |
| (historique des emplacements) | `screen_location_history` | **Créée** |
| (documents liés) | `screen_documents` | **Créée** |

## 2. Migrations du module (8 novembre 2026)

| Migration | Contenu |
|---|---|
| `2026_11_08_064856_screen_module_integrity` | Gravité « moyen » ; `incidents.resolution` ; état `archived`, `archived_at`, `archive_reason` |
| `2026_11_08_065815_create_device_commands_table` | `device_commands` (CHECK sur état et commande) ; `devices.capabilities`, `capabilities_reported_at`, `playback_paused` ; permission `devices.command` |
| `2026_11_08_072417_create_screen_status_history_table` | `screen_status_history` (une période ouverte par écran) ; `devices.hardware_info` ; index des accusés |
| `2026_11_08_074747_extend_screen_inventory_and_location_history` | Fiche (acquisition, fournisseur, commande, technologie, consommation, MAC, système, firmware, emplacement précis, position sur plan) ; `screen_documents` ; responsable et photo d'emplacement ; `screen_location_history` initialisée pour les écrans existants |
| `2026_11_08_083230_create_screen_profiles_table` | `screen_profiles` ; `profile_id` sur écrans et groupes ; compte rendu de configuration des lecteurs |
| `2026_11_08_083849_add_dynamic_criteria_to_screen_groups` | `type`, `criteria`, `members_computed_at` (CHECK de cohérence) |
| `2026_11_08_090821_extend_incident_workflow` | `verified_at`, `verified_by`, `escalation_level`, `escalated_at`, `due_at` |
| `2026_11_08_095843_add_floor_and_group_scope_and_fleet_roles` | `role_assignments.floor_id`, `group_id` ; rôles `responsable_parc`, `superviseur` |

Chaque migration a une méthode `down()` ; celles qui étendent une contrainte CHECK échouent volontairement au retour arrière si des données utilisent la nouvelle valeur (protection des données).

## 3. Contraintes d'intégrité notables

- Un seul lecteur actif par écran (`devices_one_active_per_screen`), une seule période d'état ouverte et un seul emplacement courant par écran (index uniques partiels).
- Valeurs énumérées en CHECK : statut d'écran, technologie, format MAC, position sur plan (0–100 %), états et types de commande, type de groupe et cohérence des critères, gravité d'incident.
- Clés étrangères vers `users`, `screens`, `devices`, `locations`, `rooms`, `floors`, `screen_groups`, `screen_profiles`, `documents`, `asset_versions`.

## 4. Volumétrie et conservation (§17.3)

Purge nocturne `saie:retention` (si activée), durées réglables : télémétrie, notifications, **accusés de diffusion (400 j), commandes terminées (365 j), historique des états (730 j)** ; journal d'audit archivé, jamais supprimé.
