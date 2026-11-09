# 06 — API du module « Gestion des écrans »

Le contrat complet et à jour est **`docs/api/openapi.json`** (OpenAPI 3.1, 180 chemins, régénéré par `php artisan saie:openapi`, avec des exemples réels anonymisés dans `docs/api/examples.json`). Chaque réponse produite par la suite de tests est validée contre ce contrat.

Conventions : préfixe `/api/v1`, session Sanctum (navigateur) ou jeton de lecteur (`/device/*`), réponses `{ data, meta }`, erreurs `{ code, message, errors }`, verrouillage optimiste par `If-Match` ou `expected_version`, périmètre de l'habilitation appliqué à toute lecture ou écriture d'écran.

## Parc et inventaire

| Méthode | Chemin | Permission | Objet |
|---|---|---|---|
| GET | `/screens` | screens.view, campaigns.view, alerts.manage | Liste ; filtres `q`, `floor_id`, `zone_id`, `group_id`, `kind`, `network`, `status` (archivés sur demande), `state` (état opérationnel) |
| GET | `/screens/overview` | screens.view, supervision.view | Tableau de bord du parc (§4) |
| POST | `/screens` | screens.manage | Création (emplacement dans le périmètre, historique initialisé) |
| GET / PATCH | `/screens/{id}` | screens.view / screens.manage | Fiche ; modification (déplacement historisé avec `move_reason`) |
| POST | `/screens/{id}/archive` | screens.manage | Retrait définitif (motif ; refusé si incident ouvert) |
| POST | `/screens/import` | screens.manage | Import CSV (`commit` absent = simulation) ; modèle : `GET /screens/import/template` |
| POST | `/screens/{id}/qr` | screens.manage | Code QR de la fiche (réservé aux comptes habilités) |
| POST / DELETE | `/screens/{id}/documents[/{document}]` | screens.manage | Documents liés |
| GET | `/screens/{id}/history` | screens.view, supervision.view | Historique unifié (`category`) |
| GET | `/screens/{id}/status-history` | screens.view, supervision.view | Périodes d'états |
| GET | `/screens/{id}/preview` | campaigns.view, screens.view | Programme effectif à une date |

## Groupes et profils

| Méthode | Chemin | Permission | Objet |
|---|---|---|---|
| GET / POST | `/screen-groups` | screens.view… / groups.manage | Groupes statiques (`screen_ids`) ou dynamiques (`type=dynamic`, `criteria`) ; statistiques des membres |
| PATCH / DELETE | `/screen-groups/{id}` | groups.manage | Modification, suppression (refusée si campagne active) |
| GET / POST / PATCH | `/screen-profiles[/{id}]` | screens.view / screens.manage | Profils de configuration (paramètres bornés) |

## Lecteurs et commandes

| Méthode | Chemin | Permission | Objet |
|---|---|---|---|
| POST | `/devices/pair` | devices.pair | Appairage par code |
| POST | `/devices/{id}/revoke` | devices.revoke | Révocation |
| GET / POST | `/devices/{id}/commands` | devices.command (lecture : supervision.view, screens.manage) | Historique et disponibilité ; envoi (`command`, `reason` exigé pour arrêt, veille, redémarrage) |
| POST | `/device-commands/{id}/cancel` | devices.command | Annulation avant réception |
| PATCH | `/devices/{id}/channel` | player_releases.manage | Canal de version |

## API des lecteurs (jeton du lecteur)

| Méthode | Chemin | Objet |
|---|---|---|
| POST | `/device/pairing-codes`, GET `/device/pairing-codes/{code}` | Enrôlement |
| GET | `/device/manifest` | Manifeste signé (ETag), configuration et empreinte |
| POST | `/device/heartbeat` | État, capacités, caractéristiques, configuration appliquée ; réponse : commandes à exécuter |
| POST | `/device/commands/{id}/ack` | `received`, `executed`, `failed` + compte rendu (32 Kio max) |
| POST | `/device/receipts` | Accusés de diffusion (dédoublonnés) |
| POST | `/device/screenshots/{id}` | Capture demandée |
| POST | `/device/token/rotate` | Rotation du jeton |

## Supervision, incidents, rapports

| Méthode | Chemin | Permission | Objet |
|---|---|---|---|
| GET | `/supervision` | supervision.view | Contrôles, lecteurs (état, niveau, groupes), synthèses par niveau et par groupe, délais |
| GET / POST | `/incidents` | supervision.view / incidents.manage | Liste (échéance, escalade, vérification) ; déclaration |
| PATCH | `/incidents/{id}` | incidents.manage | `assign`, `acknowledge`, `qualify`, `diagnose`, `intervene`, `resolve`, `verify`, `reopen`, `close`, `comment` |
| GET | `/incidents/stats` | supervision.view, incidents.manage | Statistiques (`days`) |
| POST | `/exports` | selon le type + exports.create | Types du module : `equipment_inventory`, `incident_report`, `offline_screens`, `remote_commands`, `warranty_expiry`, `availability_report`, `maintenance_report` (PDF, CSV, XLSX) |
