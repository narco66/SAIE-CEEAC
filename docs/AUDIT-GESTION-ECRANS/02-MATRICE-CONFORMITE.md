# 02 — Matrice de conformité du module « Gestion des écrans de diffusion »

Référentiel : *SAIE-CEEAC — Spécification fonctionnelle détaillée du module de gestion des écrans de diffusion*, v1.0 du 8 novembre 2026.
Audit initial réalisé le 8 novembre 2026, en lecture seule, sur le code et la base locale (`saie_ceeac`).

Statuts : **Conforme** (vu dans le code, backend et interface), **Partiel**, **Absent**, **Défectueux** (existe mais produit un résultat faux ou contournable).
La colonne « Résultat après intervention » est tenue dans la section *Suivi des corrections* en fin de document : une exigence n'y passe « Conforme » qu'avec la référence du test qui le démontre.

## Bilan initial

| Périmètre | Exigences | Conformes | Partielles | Absentes | Défectueuses |
|---|---|---|---|---|---|
| A — §4 à §8 (tableau de bord, inventaire, localisation, configuration, groupes) | 113 | 26 | 55 | 26 | 6 |
| B — §9 à §11, §17.3, §18, §21, §23 (lecteurs, supervision, commandes, protocole, sécurité) | 82 | 22 | 40 | 18 | 2 |
| C — §12 à §15, §19 (affectations, historiques, rapports, habilitations, workflows, règles) | 71 | 27 | 30 | 9 | 5 |
| **Total** | **266** | **75 (28 %)** | **125 (47 %)** | **53 (20 %)** | **13 (5 %)** |

Taux de conformité initial : 75 / 266 = **28 %** des exigences pleinement conformes.

---

## Partie Audit de conformité A — Gestion des écrans, §4 à §8 (lecture seule)

Date : 2026-11-08. Périmètre : backend `C:\laragon\www\SAIE-CEEAC\backend`, frontend `C:\laragon\www\SAIE-CEEAC\frontend\src`, lecteur `C:\laragon\www\SAIE-CEEAC\player\src`, base `saie_ceeac` (PostgreSQL 18, port 5433), interrogée en SELECT uniquement.
Abréviations : SC = `backend/app/Http/Controllers/Api/V1/ScreenController.php`, DC = `.../DashboardController.php`, SV = `.../SupervisionController.php`, RC = `.../ReportController.php`, PR = `backend/app/Support/Present.php`, MB = `backend/app/Services/ManifestBuilder.php`, REF = `backend/app/Services/Referentials.php`, EXS = `backend/app/Services/ExportService.php`, FE = `frontend/src/pages`.
Données observées : 21 écrans (19 `active`, 1 `maintenance`, 1 `inactive`), 25 lecteurs (18 actifs, tous `unavailable` ; 7 révoqués), 2 groupes, 2 incidents ouverts liés à un écran sans `device_id`.

Les statuts suivent cette règle : **Conforme** = vu côté backend et côté interface ; **Partiel** = présent mais incomplet ; **Absent** = introuvable ; **Défectueux** = présent mais produit un résultat faux.

## §4 Tableau de bord

| Réf | Exigence | Existant (preuve) | Statut | Écart | Impact | Priorité | Action proposée |
|---|---|---|---|---|---|---|---|
| 4.1-a | Nombre total d'écrans | DC:44,66 `cards.screens.total` = écrans `status='active'` seulement ; DC:75 `fleet.total` = tout le parc ; FE/display/Screens.tsx:65 affiche `cards.screens.total` sous le libellé « écrans inventoriés » | Défectueux | Deux totaux différents. La page Parc affiche 19 « inventoriés » alors que la table `screens` en compte 21. La liste non filtrée renvoie 21 lignes | fonctionnel | P0 | Exposer `total_inventory` (tous statuts) et `total_in_service`, avec des libellés distincts |
| 4.1-b | En ligne | DC:45-46,66 (`computedNetworkStatus()==='online'`, `backend/app/Models/Device.php:64-76`, seuil 90 s `config/saie.php:41`) ; FE/account/Dashboard.tsx:53 | Conforme | — | — | P0 | — |
| 4.1-c | Hors ligne | DC:66 `suspect` + `unavailable` ; Dashboard.tsx:53 | Partiel | Un lecteur actif jamais vu (`last_seen_at` NULL) est classé `unknown` (Device.php:66) et n'apparaît dans aucun compteur de la carte. Le filtre de liste `network=unavailable` l'inclut (SC:47) : la carte et la liste filtrée divergent | fonctionnel | P0 | Unifier les règles de classement (`unknown` → hors ligne, ou carte dédiée) |
| 4.1-d | En anomalie | PR:84-98 (`anomaly` si `last_errors` non vide ou incident ouvert portant un **device_id**) ; DC:75 `fleet.by_state.anomaly` ; chips Dashboard.tsx:116 | Défectueux | Un incident déclaré manuellement porte `screen_id` sans `device_id` (SV `storeIncident`). L'écran concerné n'est donc jamais « en anomalie » (2 cas en base). Les chips ne sont pas cliquables et il n'existe aucun filtre « anomalie » sur la liste | fonctionnel | P0 | Tenir compte de `incidents.screen_id` dans `operationalState`, puis ajouter un filtre `state=` à `GET /screens` |
| 4.1-e | En diffusion (lecture active confirmée) | `devices.current_playback` (heartbeat, DeviceApiController:129) affiché sur la fiche seulement (ScreenDetail.tsx:240) | Absent | Aucun indicateur agrégé. L'état `active` signifie « Lecteur joignable » (PR:94), pas « en diffusion » | fonctionnel | P0 | Calculer `broadcasting` = lecteur en ligne + `current_playback` récent + reçu `played`/`activated` récent |
| 4.1-f | Inactifs | PR:88 `disabled` ; DC:75 by_state ; chip Dashboard.tsx:116 | Partiel | Affiché en chip non cliquable, sans carte ni filtre de liste par statut administratif (SC:34-39 n'accepte pas `status`) | fonctionnel | P0 | Ajouter le filtre `status` et une carte cliquable |
| 4.1-g | Non configurés | PR:90 `pending_configuration` ; DC:66 `unpaired` ; Screens.tsx:69 filtre `unpaired` | Conforme | Seule l'absence de lecteur est mesurée (ni l'absence d'emplacement ni l'absence de programme) | — | P0 | Option : inclure « sans programme » |
| 4.1-h | Alertes techniques par gravité | DC:71 total d'incidents ouverts ; DC:94 4 incidents triés par gravité ; SV:`incidents` filtre `severity` ; Dashboard.tsx:209-216 | Partiel | Aucun décompte par gravité (critical/major/minor). Le clic mène à `/supervision` sans filtre | fonctionnel | P1 | Ajouter `incidents_by_severity` et des cartes menant à `/supervision?severity=` |
| 4.2-a | Répartition par bâtiment/étage | DC:47-52 (floors), DC:76-78 (buildings) ; Dashboard.tsx:102-118 ; synoptique Screens.tsx:111-128 | Partiel | La vue par étage ne compte que les écrans actifs. La vue par bâtiment n'est qu'une ligne de texte, affichée seulement s'il y a plusieurs bâtiments (Dashboard.tsx:63,118). Aucun graphique | fonctionnel | P1 | Ajouter un graphique empilé bâtiment/étage × état |
| 4.2-b | Répartition par état | DC:75 `by_state` ; Dashboard.tsx:116 | Partiel | Chips texte non cliquables, aucune visualisation | fonctionnel | P1 | Ajouter un graphique et rendre chaque état cliquable vers la liste filtrée |
| 4.2-c | Évolution du taux de disponibilité | RC:96 indicateur `fleet` instantané ; RC `availability()` (l.123+) moyenne par étage sur la période (Reports.tsx:16,78) | Partiel | Aucune série temporelle sur le tableau de bord ni dans les rapports. Le calcul existe à partir de `device_telemetry` | fonctionnel | P1 | Agréger `device_telemetry` par jour et par étage, puis tracer la courbe |
| 4.2-d | Écrans les plus sollicités | `device_receipts` kind `played` agrégés par jour uniquement (RC `diffusion()` l.104-116) | Absent | Aucun classement par écran | fonctionnel | P1 | Classement des écrans par diffusions `played` et nombre de campagnes ciblantes |
| 4.2-e | Historique des incidents | DC:94 (4 ouverts) ; SV `incidents` paginé avec filtres ; FE/admin/Supervision.tsx ; RC:54-58 totaux | Partiel | Le tableau de bord ne montre que les incidents ouverts, sans histogramme ni tendance | fonctionnel | P1 | Ajouter une série d'incidents par semaine et par gravité |
| 4.2-f | Répartition par type d'écran | `screens.kind` (CHECK display/room_panel/orientation) ; filtre `kind` SC:35 | Absent | Aucun agrégat par type sur le tableau de bord | fonctionnel | P1 | Ajouter `by_kind` à DC et un graphique |
| 4.2-g | État des lecteurs | SV:72-80 (lecteurs, versions, erreurs) ; Supervision.tsx:94 ; carte Dashboard.tsx:53 | Partiel | Présent en supervision (permission `supervision.view`), absent du tableau de bord sauf le compteur réseau | fonctionnel | P1 | Ajouter un bloc versions, erreurs et écart d'horloge au tableau de bord |
| 4.2-h | Cartes cliquables vers listes filtrées | Dashboard.tsx:53-56,86 ; Screens.tsx:92-97 (filtre `network`) ; étages → `?floor_id=` (Dashboard.tsx:103) | Défectueux | La carte « Écrans connectés » mène à `?network=unavailable` (Dashboard.tsx:53), soit la liste inverse de son libellé. Les chips d'état et les incidents ne filtrent rien. Les compteurs portent sur les écrans actifs et les listes sur tous les écrans, d'où des écarts | fonctionnel | P1 | Corriger les cibles, rendre chaque compteur cliquable et aligner les périmètres |
| 4.3-a | Vue interactive : sélection d'étage | Synoptique « tour » Screens.tsx:102-129 (bouton par étage → filtre `floor_id`) | Partiel | Vue schématique en barres, sans plan | fonctionnel | P1 | — (voir 4.3-b) |
| 4.3-b | Salles/zones et écrans repérés sur plan | `floors.plan_asset_version_id` (servi aux bornes d'orientation, REF:42) ; aucune coordonnée d'écran en base | Absent | Les écrans ne sont pas positionnés sur un plan (aucune colonne x/y) et les salles ne sont pas dessinées | fonctionnel | P1 | Ajouter `screens.plan_x/plan_y` (ou sur `locations`) et un composant de plan SVG |
| 4.3-c | États par couleur + libellé/icône | Tour : pastilles `offline`/online seulement (Screens.tsx:119) ; liste : `StatusOf OPERATIONAL_STATE` (shared.tsx:44-47) | Partiel | Le synoptique a deux couleurs (en ligne / pas en ligne) et ignore anomalie, maintenance et désactivé | fonctionnel | P1 | Coloriser selon `operational_state` avec libellé et icône |
| 4.3-d | Ouverture de la fiche depuis la vue | Liste : lien code → `/affichage/ecrans/:id` (Screens.tsx:153) | Partiel | Pas d'ouverture depuis le synoptique : un clic ne fait que filtrer | fonctionnel | P1 | Rendre chaque écran repéré cliquable |
| 4.3-e | Filtre anomalies | Filtre `network` (online/suspect/unavailable/unpaired) SC:40-51 | Partiel | Aucun filtre sur l'état opérationnel (anomalie, maintenance) | fonctionnel | P1 | Ajouter `state=anomaly` côté API et interface |

## §5 Inventaire des écrans

| Réf | Exigence | Existant (preuve) | Statut | Écart | Impact | Priorité | Action proposée |
|---|---|---|---|---|---|---|---|
| 5.1-a | Identifiant unique automatique | `screens.id` uuid PK (HasUuids, `Models/Screen.php:15`) | Conforme | — | — | P0 | — |
| 5.1-b | Code d'inventaire | `screens.code` varchar(40) UNIQUE, saisi (SC:290, regex `^[A-Z0-9-]+$`) | Partiel | Un seul code sert à la fois d'identifiant technique et de code d'inventaire. Il est saisi à la main, sans génération ni distinction avec un n° d'inventaire patrimonial | fonctionnel | P0 | Ajouter `inventory_code` (unique, éventuellement auto-numéroté) |
| 5.1-c | Nom | `screens.name` ; SC:291 ; formulaire Screens.tsx:193 | Conforme | — | — | P0 | — |
| 5.1-d | Description | `screens.notes` text validé (SC:307) | Défectueux | `notes` n'est pas renvoyé par `Present::screen` (PR:48-72) ni éditable dans l'interface (ScreenDetail.tsx:17 le type seulement) : une valeur saisie par l'API reste invisible | fonctionnel | P0 | Exposer `notes`/`description` dans PR et les formulaires |
| 5.1-e | Catégorie | `screens.kind` CHECK (display, room_panel, orientation) | Partiel | Il s'agit d'un type fonctionnel et non d'une catégorie paramétrable (hall, salle, couloir…) | fonctionnel | P0 | Créer un référentiel `screen_categories` |
| 5.1-f | Statut administratif | `screens.status` CHECK (active, maintenance, inactive) + `maintenance_reason` ; ScreenDetail.tsx:138-147 | Partiel | L'état « archivé » n'existe pas | fonctionnel | P0 | Ajouter `archived` (ou `archived_at`) |
| 5.1-g | Date d'acquisition | — | Absent | Aucune colonne | fonctionnel | P0 | Ajouter `acquired_on` |
| 5.1-h | Date de mise en service | `screens.installed_on` (SC:312 ; ScreenDetail.tsx:162) | Conforme | Libellée « Date d'installation » | — | P0 | — |
| 5.1-i | Fournisseur | — | Absent | — | fonctionnel | P0 | Ajouter `supplier` |
| 5.1-j | Référence de commande | — | Absent | — | fonctionnel | P0 | Ajouter `purchase_order_ref` |
| 5.1-k | Garantie | `screens.warranty_until` + `under_warranty` calculé (PR:68-69) ; ScreenDetail.tsx:92,163 | Conforme | — | — | P0 | — |
| 5.1-l | Documents associés | — (aucune relation écran ↔ documents/assets) | Absent | — | fonctionnel | P0 | Créer une table pivot `screen_documents` (asset_versions) |
| 5.2-a | Fabricant/marque/modèle | `screens.manufacturer`, `screens.model` ; ScreenDetail.tsx:151-152 | Conforme | Marque et fabricant sont confondus | — | P0 | — |
| 5.2-b | N° de série | `screens.serial_number` ; ScreenDetail.tsx:153 | Partiel | Pas d'unicité en base | technique | P0 | Ajouter un index unique partiel `serial_number` |
| 5.2-c | Taille (pouces) | `screens.diagonal_inches` smallint (7-120, SC:310) | Conforme | — | — | P0 | — |
| 5.2-d | Résolution | `screens.resolution` (liste fermée de 6 valeurs, SC:296) ; `devices.reported_resolution` | Conforme | Le contrôle de cohérence avec la résolution déclarée par le lecteur n'est pas affiché | — | P0 | Option : signaler l'écart |
| 5.2-e | Orientation | `screens.orientation` CHECK landscape/portrait | Conforme (inventaire) | Voir 7.1-b pour l'application | — | P0 | — |
| 5.2-f | Technologie (LED/LCD/OLED) | — | Absent | — | fonctionnel | P0 | Ajouter `panel_technology` avec CHECK |
| 5.2-g | Connectivité/interfaces | `screens.connection_type` (ethernet/wifi/cellular/other), `control_channel` (none/cec/rs232/api) | Partiel | Les interfaces physiques (HDMI, DP, USB…) ne sont pas décrites | fonctionnel | P0 | Ajouter `interfaces` jsonb |
| 5.2-h | Adresse MAC | — | Absent | Ni sur `screens` ni sur `devices` | technique | P0 | Ajouter `mac_address` (lecteur et/ou écran) |
| 5.2-i | Adresse IP | `screens.ip_address` saisi (SC:310 `ip`) | Partiel | Valeur saisie à la main et non remontée par le lecteur (le heartbeat ne l'envoie pas, DeviceApiController:113-121) | technique | P0 | Remonter l'IP observée (`request()->ip()`) dans `devices` |
| 5.2-j | OS | `devices.platform` (déclaré par le lecteur) | Partiel | Champ libre User-Agent, rattaché au lecteur et non à l'écran | technique | P0 | Structurer `os_name/os_version` |
| 5.2-k | Firmware | `devices.app_version` (version du lecteur web) | Partiel | Aucun firmware de dalle ni de boîtier | technique | P0 | Ajouter `firmware_version` |
| 5.2-l | Mode de fonctionnement | `screens.kind`, `operating_window`, `touch_enabled` | Partiel | Aucun champ explicite (24/7, plage, à la demande) | fonctionnel | P0 | Expliciter `operating_mode` |
| 5.2-m | Consommation | — | Absent | — | fonctionnel | P0 | Ajouter `power_watts` |
| 5.3-a | Bâtiment | Via `locations.floor_id → floors.building_id` ; utilisé par MB:131 | Partiel | `Present::screen` ne renvoie pas le bâtiment (PR:55-59) : la fiche et la liste ne l'affichent pas | fonctionnel | P0 | Ajouter `building` dans PR et l'interface |
| 5.3-b | Étage | `floors` (label, level) ; PR:57 ; ScreenDetail.tsx:78 | Conforme | — | — | P0 | — |
| 5.3-c | Zone | `locations.zone_id → display_zones` ; PR:58 ; ScreenDetail.tsx:79 | Conforme | Il s'agit d'une zone de diffusion (classification, audio) et non d'une zone spatiale | — | P0 | — |
| 5.3-d | Salle | `screens.room_id` (FK rooms) ; SC:294 | Partiel | L'interface force `room_id=null` hors `room_panel` (Screens.tsx:241, ScreenDetail.tsx:118) : un écran d'affichage ne peut pas être rattaché à une salle | fonctionnel | P0 | Permettre la salle pour tous les types |
| 5.3-e | Emplacement précis | `locations.name/description` (partagés) | Partiel | Aucun libellé propre à l'écran (ex. « mur nord, 2 m ») | fonctionnel | P0 | Ajouter `screens.placement_detail` |
| 5.3-f | Type d'espace | `locations.type` CHECK (8 valeurs) ; formulaire Referentials.tsx:34 | Partiel | Absent de `Present::screen` : non affiché sur la fiche | fonctionnel | P0 | Exposer `location.type` |
| 5.3-g | Responsable de l'espace | `rooms.manager_id` uniquement | Partiel | Pas de responsable sur `locations` | fonctionnel | P0 | Ajouter `locations.manager_id` |
| 5.3-h | Photo de l'emplacement | `rooms.photos` uniquement | Absent | Rien pour `locations` ni `screens` | fonctionnel | P0 | Ajouter `locations.photo_asset_version_id` |
| 5.3-i | Référence sur plan | `floors.plan_asset_version_id` (plan du niveau) | Absent | Aucune position de l'écran sur le plan | fonctionnel | P0 | Ajouter des coordonnées (voir 4.3-b) |
| 5.4-a | Lecteur associé | `devices.screen_id` + index unique partiel `devices_one_active_per_screen` ; ScreenDetail onglet Lecteur | Conforme | — | — | P0 | — |
| 5.4-b | Groupe | `group_members` ; PR:61 ; ScreenDetail.tsx:81 | Conforme | Lecture seule depuis la fiche (édition seulement depuis Groupes) | — | P0 | — |
| 5.4-c | Profil de configuration | — | Absent | Voir §7.3 | fonctionnel | P0 | — |
| 5.4-d | Playlist/campagne affectées | `GET /screens/{id}/preview` (SC:108-123) ; onglet Programme (ScreenDetail.tsx:306-332) ; `/affichage/programme?screen_id=` | Conforme | Les playlists ne passent que par des campagnes | — | P0 | — |
| 5.4-e | Calendrier | `operating_window` (écran > groupe > défaut, `Screen::effectiveOperatingWindow` l.73-81) ; piste horaire Program.tsx | Partiel | La fiche affiche une plage fausse quand elle est héritée du groupe (voir Défauts D3) | fonctionnel | P0 | Afficher la plage effective et son origine |
| 5.4-f | Priorité | `campaigns.priority` | Partiel | L'écran n'a pas de priorité propre (criticité) | fonctionnel | P0 | Ajouter `screens.priority`/criticité si l'exigence le requiert |
| 5.4-g | Dernière communication | `devices.last_seen_at` ; Screens.tsx:161 ; ScreenDetail.tsx:48,227 | Conforme | — | — | P0 | — |
| 5.4-h | Dernière diffusion confirmée | `device_receipts` (kind played/activated) ; fiche : « Lecture en cours (déclarée) » (ScreenDetail.tsx:240) | Partiel | L'horodatage du dernier reçu `played` n'est pas affiché | fonctionnel | P0 | Exposer `last_played_at` dans PR |
| 5.5-a | Création | `POST /api/v1/screens` (SC:75-89, contrôle de périmètre l.82) ; modale Screens.tsx:231-262 | Conforme | La fiche matérielle n'est saisissable qu'après création | — | P0 | — |
| 5.5-b | Modification | `PATCH /api/v1/screens/{screen}` (SC:91-106, If-Match/version) ; ScreenDetail.tsx:100-168 | Défectueux (sécurité) | Le déplacement vers un emplacement hors périmètre n'est pas contrôlé (voir D1) | sécurité | P0 | Appeler `assertStillInScope` après la mise à jour |
| 5.5-c | Consultation | `GET /screens/{screen}` (SC:62-73) ; ScreenDetail.tsx | Partiel | `show`/`index` ne filtrent pas selon le périmètre de l'habilitation (seulement la permission) | sécurité | P0 | Appliquer `screenScope` en lecture si exigé |
| 5.5-d | Archivage | Aucune route DELETE ni état archivé (route:list) | Absent | — | fonctionnel | P0 | Ajouter un état archivé et `archived_at` |
| 5.5-e | Activation/désactivation | `status` active/inactive (SC:305) ; sélecteur ScreenDetail.tsx:138-141 ; PR:88 | Conforme | — | — | P0 | — |
| 5.5-f | Import Excel/CSV | `POST /imports/*` limité aux types de REF:33-71 (sites…rooms), `mimes:csv,txt` (ImportController:45,53) | Absent | Aucun type `screens`. Excel non accepté | fonctionnel | P0 | Ajouter la définition `screens` dans REF (location_code, group…) et accepter xlsx |
| 5.5-g | Export inventaire | `equipment_inventory` pdf/csv/xlsx (EXS:54,290-307) ; bouton Screens.tsx:50-61,79 (PDF seulement) | Partiel | Colonnes réduites : ni statut, ni marque, modèle, série, garantie, IP ou bâtiment. L'interface ne propose que le PDF. La plage exportée ignore celle du groupe (EXS:301) | fonctionnel | P0 | Enrichir les colonnes et proposer CSV/XLSX |
| 5.5-h | Recherche multicritère | `q` (code/nom), `floor_id`, `zone_id`, `group_id`, `network`, `kind` (SC:34-51) ; Screens.tsx:17,133-140 | Partiel | Aucun critère salle, statut administratif, état opérationnel, marque, série ou bâtiment | fonctionnel | P0 | Étendre les filtres |
| 5.5-i | Filtres étage/salle/type/statut | étage ✔, type ✔ (SC:35-36) | Partiel | Les filtres salle et statut manquent | fonctionnel | P0 | Ajouter `room_id`, `status` et `state` |
| 5.5-j | Impression des fiches | Aucun `window.print`, aucun `@media print` (grep frontend), aucun export « fiche écran » dans EXS | Absent | — | fonctionnel | P1 | Créer un type d'export `screen_sheet` (PDF) |
| 5.5-k | QR Code par écran ouvrant la fiche (technicien autorisé) | `access_links` / QrController:31-63 : cibles `document` et `content` seulement | Absent | Aucun QR d'écran | fonctionnel / sécurité | P1 | Ajouter `target_type='screen'` (redirection authentifiée vers `/affichage/ecrans/:id`, permission `screens.view`) et générer le QR sur la fiche |

## §6 Localisation

| Réf | Exigence | Existant (preuve) | Statut | Écart | Impact | Priorité | Action proposée |
|---|---|---|---|---|---|---|---|
| 6-a | Hiérarchie Institution→Bâtiment→Étage→Zone/Salle→Écran paramétrable | Tables `sites`→`buildings`→`floors`→`locations`(+`display_zones`)→`screens` ; `rooms` rattachées à `floors` | Partiel | Le niveau Institution n'existe pas (seul le paramètre `identity.institution_name`). La salle est parallèle à l'emplacement et non dans la chaîne. Les niveaux sont figés et non paramétrables | fonctionnel | P0 | Relier `locations.room_id`, documenter la hiérarchie, ajouter éventuellement `institutions` |
| 6-b | CRUD bâtiments | `POST/PATCH /referentials/buildings` (ReferentialController:55-77, REF:36) ; arbre Referentials.tsx:128-137 ; désactivation (REF-03) | Conforme | Pas de suppression, par choix (désactivation) | — | P0 | — |
| 6-c | CRUD étages | `referentials/floors` (REF:38-42), y compris le plan | Conforme | Idem | — | P0 | — |
| 6-d | CRUD salles | `POST/PATCH /rooms` (RoomController:65,241) ; import CSV `rooms` | Partiel | Aucun formulaire de création de salle dans le frontend (aucun `post('/rooms')`), seulement modification (RoomDetail.tsx:180) et import | fonctionnel | P0 | Ajouter une modale de création |
| 6-e | CRUD emplacements | `referentials/locations` (REF:46-49) ; Referentials.tsx | Conforme | — | — | P0 | — |
| 6-f | Associer/déplacer un écran | `location_id` modifiable (SC:293) ; ScreenDetail.tsx:136 | Défectueux (sécurité) | Le déplacement fonctionne, mais sans contrôle du périmètre cible (voir D1) | sécurité | P0 | Contrôler le nouvel emplacement |
| 6-g | Historique des déplacements | `audit_logs` `screen.updated` avec before/after (SC:101-103) ; onglet Historique (ScreenDetail.tsx:417-457), 20 derniers événements | Partiel | Pas de table d'historique. L'interface affiche « Modification » sans ancien ni nouvel emplacement et sans dates de période | fonctionnel | P1 | Créer `screen_location_history` (from/to, valid_from/valid_to, motif) |
| 6-h | Nombre d'écrans par emplacement | Par étage (DC:47-52) et par bâtiment (DC:76-78) | Partiel | Aucun décompte par emplacement ni par salle (l'arbre des référentiels n'affiche aucun nombre d'écrans) | fonctionnel | P1 | Ajouter `withCount('screens')` sur locations et l'afficher dans l'arbre |
| 6-i | Plan par étage | `floors.plan_asset_version_id` (Referentials.tsx:29), utilisé par les bornes d'orientation | Partiel | Plan stocké, mais non utilisé pour la cartographie du parc | fonctionnel | P1 | Voir 4.3-b |
| 6-j | Un seul emplacement actif ; tout changement historisé | `screens.location_id` NOT NULL unique par ligne (par construction) ; historisation par audit seulement | Partiel | L'unicité est garantie. L'historisation n'est ni structurée ni exploitable | fonctionnel | P1 | Voir 6-g |

## §7 Configuration technique

| Réf | Exigence | Existant (preuve) | Statut | Écart | Impact | Priorité | Action proposée |
|---|---|---|---|---|---|---|---|
| 7.1-a | Résolution | `screens.resolution`, transmise dans le manifeste (MB:131) | Partiel | Informative : le lecteur ne l'applique pas (le navigateur impose la sienne) | technique | P0 | Signaler « non pilotable » ou comparer à `reported_resolution` |
| 7.1-b | Orientation | `screens.orientation` → manifeste (MB:131) ; `player/src/types.ts:58` | Partiel | Le lecteur ne fait aucune rotation (aucune utilisation de `screen.orientation` hors des types). Seule la vérification des modèles de campagne l'utilise (CampaignService:104) | technique | P0 | Appliquer une rotation CSS ou la marquer « indisponible » |
| 7.1-c | Luminosité | — | Absent | — | technique | P0 | Paramètre + « indisponible » sans canal CEC/RS232 |
| 7.1-d | Contraste | — | Absent | — | technique | P0 | Idem |
| 7.1-e | Volume | `screens.audio_enabled` + `display_zones.audio_max_volume` + paramètre `operating.audio_max_volume` → `audio_policy` (MB:141) ; player views.tsx:86,174 | Partiel | Aucun volume propre à l'écran : seulement un plafond de zone et l'activation | fonctionnel | P0 | Ajouter `screens.volume` (≤ plafond de zone) |
| 7.1-f | Veille et durée d'inactivité | `operating_window` + `wake_before_min` (SC:297-301) ; player engine.ts:159 | Partiel | Une veille horaire existe, mais pas de veille sur durée d'inactivité | fonctionnel | P0 | Ajouter `idle_timeout_min` ou le marquer indisponible |
| 7.1-g | Plein écran | Lecteur kiosque plein écran par conception | Partiel | Non paramétrable et non vérifié/remonté | technique | P0 | Remonter `fullscreen` dans le heartbeat |
| 7.1-h | Fréquence de rafraîchissement | `config/saie.php:40` `heartbeat_s=30` global ; `next_heartbeat_s` (DeviceApiController:147) | Partiel | Valeur globale, non configurable par écran ou groupe | technique | P0 | Paramètre par profil |
| 7.1-i | Fuseau horaire | `config('saie.timezone')` global (MB:118,129) ; `sites.timezone` existe mais n'est pas utilisé | Partiel | Aucun fuseau par site ou par écran (`sites.timezone` est ignoré) | fonctionnel | P0 | Utiliser `site.timezone` dans MB |
| 7.1-j | Langue | Langues portées par les campagnes (`campaign_languages`) | Absent | Aucune langue d'interface ou par défaut sur l'écran | fonctionnel | P0 | Ajouter `screens.default_lang` |
| 7.2-a | IP / DHCP / fixe | `screens.ip_address` | Partiel | Pas de mode d'adressage, IP non remontée | technique | P0 | Ajouter `ip_mode` (dhcp/static) et remonter l'IP |
| 7.2-b | MAC | — | Absent | — | technique | P0 | Voir 5.2-h |
| 7.2-c | Serveur | URL du serveur codée dans le lecteur | Absent | Non paramétrable depuis la console | technique | P0 | Ajouter `server_url` au profil (document de contrat) |
| 7.2-d | Fréquence de synchronisation | `heartbeat_s` global ; renouvellement du manifeste interne au lecteur | Partiel | Valeur globale uniquement | technique | P0 | Paramètre par profil |
| 7.2-e | Intervalle de remontée d'état | Idem (heartbeat 30 s, télémétrie toutes les 285 s, DeviceApiController:134) | Partiel | Valeur globale | technique | P0 | Idem |
| 7.2-f | Politique de reconnexion | Interne au lecteur (player engine) | Absent | Non paramétrable ni documentée côté console | technique | P0 | Exposer backoff/retry dans le profil |
| 7.2-g | Authentification du lecteur | Appairage par code à usage unique (SC:128-167), jeton Sanctum à durée de vie limitée (SC:159), rotation `POST /device/token/rotate`, révocation (SC:169-180), manifeste signé (`trusted_keys` MB) | Conforme | — | — | P0 | — |
| 7.3-a | Profils réutilisables (Hall, Couloir, Salle de conférence…) | Aucune table ni modèle (grep « profile ») | Absent | — | fonctionnel | P0 | Créer `config_profiles` (jsonb + version) avec affectation écran/groupe et héritage |
| 7.3-b | Affectables à un écran ou un groupe | Seule `operating_window` existe au niveau écran et groupe (SC:236,249 ; Screen.php:73-81) | Partiel | Un seul paramètre est partagé | fonctionnel | P0 | Voir 7.3-a |
| 7.3-c | Paramètres non supportés signalés « indisponibles » | `panel_state='not_measured'` sans canal (PR:53) ; supervision « Dalles non mesuré » (SV:76-78) | Partiel | Le principe est appliqué à l'état de la dalle, mais pas aux paramètres de configuration (luminosité, orientation…) | fonctionnel | P0 | Matrice de capacités par plateforme de lecteur |
| 7.3-d | État de synchronisation + confirmation d'application | Manifeste émis (`manifests.version`, SC:69) comparé à `devices.active_manifest_version` déclaré (DeviceApiController:128, borné) ; reçus `manifest_received`/`activated` (player engine.ts:259-275) ; fiche ScreenDetail.tsx:238-239 | Partiel | La confirmation porte sur le manifeste entier : il n'existe pas d'état explicite « en attente / appliqué / échec » par changement de configuration, ni d'alerte en cas de divergence prolongée | fonctionnel | P0 | Calculer `config_sync_state` (émis vs actif + horodatage) et l'afficher sur la liste et la fiche |

## §8 Groupes

| Réf | Exigence | Existant (preuve) | Statut | Écart | Impact | Priorité | Action proposée |
|---|---|---|---|---|---|---|---|
| 8-a | CRUD groupes | `GET/POST/PATCH/DELETE /screen-groups` (SC:219-273) ; FE/display/Groups.tsx:11-208 ; suppression refusée si une campagne en dépend (SC:265-268) | Conforme | — | — | P0 | — |
| 8-b | Ajout/retrait d'écrans | `screen_ids` + `sync()` (SC:239,254) ; sélection par étage (Groups.tsx:110-126,182-203) | Conforme | La liste de sélection est plafonnée à 500 écrans (`useAllScreens` per_page 500, shared.tsx:135-137 ; Controller.php:106) | — | P0 | — |
| 8-c | Composition | `screens_count` et `screens` (SC:222-226) ; « Cibles effectives » (Groups.tsx:210-241) | Conforme | — | — | P0 | — |
| 8-d | Affecter une playlist/campagne au groupe | `campaign_targets.target_type='group'` (CHECK screen/group/zone/floor) ; CampaignWizard.tsx:18,420 ; 14 cibles groupe en base | Conforme (campagne) / Partiel (playlist) | Une playlist n'est affectable qu'à travers une campagne | fonctionnel | P0 | — |
| 8-e | Configuration commune | `screen_groups.operating_window` (migration 2026_11_06_125937) ; Groups.tsx:162-180 | Partiel | Seule la plage est commune. `ignore_holidays` est accepté par l'API (SC:236) mais perdu à l'enregistrement depuis l'interface (Groups.tsx:132) | fonctionnel | P0 | Profils de configuration (7.3) et correction du formulaire |
| 8-f | Statistiques du groupe | `screens_count`, `active_campaigns` (SC:225-227) | Partiel | Aucun taux de disponibilité, état, diffusion ou incident par groupe | fonctionnel | P0 | Ajouter des agrégats d'état et de disponibilité par groupe (RC accepte un filtre `screens`) |
| 8-g | Supervision des membres | TargetsModal : état réseau par membre (Groups.tsx:210-241) | Partiel | Réseau seulement, sans état opérationnel ni anomalie, calculé côté client à partir d'une liste plafonnée à 500 | fonctionnel | P0 | Renvoyer `operational_state` des membres depuis l'API |
| 8.3 | Groupes dynamiques par critères (étage, catégorie, salle, état de connexion, campagne), recalculés automatiquement | `group_members` statique (PK group_id, screen_id) ; aucune colonne de critères dans `screen_groups` | Absent | La cible `floor`/`zone` d'une campagne couvre un cas partiel, mais il n'existe ni groupe dynamique ni recalcul | fonctionnel | P0 | Ajouter `screen_groups.kind` (static/dynamic) et `criteria` jsonb, avec une résolution dynamique dans `CampaignService::resolveTargets` |

## Schéma (relevé en base, `\d`)

**screens** : id uuid PK ; code varchar(40) NOT NULL UNIQUE (`screens_code_unique`) ; name varchar(255) NOT NULL ; kind varchar(20) NOT NULL def 'display' ; location_id uuid NOT NULL FK locations ON DELETE RESTRICT ; room_id uuid FK rooms ON DELETE SET NULL ; orientation varchar(10) def 'landscape' ; resolution varchar(12) def '1920x1080' ; operating_window jsonb ; window_override_reason varchar(255) ; audio_enabled bool def false ; control_channel varchar(10) def 'none' ; panel_state varchar(12) ; panel_state_at timestamptz ; status varchar(12) def 'active' ; notes text ; is_demo bool ; created_at/updated_at ; version int def 1 ; manufacturer varchar(80) ; model varchar(80) ; serial_number varchar(80) ; diagonal_inches smallint ; ip_address varchar(45) ; connection_type varchar(12) ; installed_on date ; warranty_until date ; maintenance_reason varchar(300) ; touch_enabled bool def false.
CHECK : `screens_channel_check` (none, cec, rs232, api) ; `screens_connection_check` (NULL or ethernet, wifi, cellular, other) ; `screens_kind_check` (display, room_panel, orientation) ; `screens_orientation_check` (landscape, portrait) ; `screens_status_check` (active, maintenance, inactive). Index : `screens_location_id_status_index` (location_id, status). Référencée par devices (RESTRICT), group_members (CASCADE), incidents (SET NULL), maintenance_plans (SET NULL).

**devices** : id uuid PK ; code varchar(40) UNIQUE ; screen_id uuid FK screens RESTRICT ; hardware_id varchar(120) NOT NULL ; status varchar(10) def 'active' ; app_version varchar(40) ; platform varchar(120) ; reported_resolution varchar(20) ; last_seen_at, last_sync_at timestamptz ; network_status varchar(12) def 'unknown' ; storage jsonb ; clock_skew_ms int ; active_manifest_version int ; current_playback jsonb ; last_errors jsonb ; cache_inventory jsonb ; paired_at, paired_by (FK users) ; revoked_at, revoked_by (FK users), revoke_reason ; is_demo ; created_at/updated_at ; release_channel varchar(10) def 'stable' ; pending_command varchar(20) ; command_requested_at, command_requested_by (FK users), command_delivered_at.
CHECK : `devices_network_check` (unknown, online, suspect, unavailable) ; `devices_pending_command_check` (NULL or 'reload') ; `devices_status_check` (active, revoked). Index : `devices_one_active_per_screen` UNIQUE (screen_id) WHERE status='active' ; `devices_status_last_seen_at_index`. Aucune colonne MAC ni IP.

**screen_groups** : id uuid PK ; code varchar(40) UNIQUE ; name varchar(255) ; description text ; structure_id uuid FK structures SET NULL ; created_at/updated_at ; operating_window jsonb ; version int def 1. Aucun CHECK. Aucune colonne de critères ou de type (pas de groupe dynamique).

**group_members** : group_id uuid FK screen_groups CASCADE ; screen_id uuid FK screens CASCADE ; created_at/updated_at. PK (group_id, screen_id). Aucun index inverse sur screen_id seul.

**locations** : id uuid PK ; floor_id uuid NOT NULL FK floors RESTRICT ; zone_id uuid NOT NULL FK display_zones RESTRICT ; code varchar(40) ; name varchar(255) ; description text ; is_active bool def true ; created_at/updated_at ; type varchar(20) def 'other'. CHECK `locations_type_check` (reception, office, meeting_room, common_area, corridor, restaurant, technical, other). UNIQUE (floor_id, code). Ni responsable, ni photo, ni coordonnées sur plan, ni room_id.

**floors** : id uuid PK ; building_id uuid NOT NULL FK buildings RESTRICT ; code varchar(40) ; label varchar(255) ; level smallint ; is_active bool ; created_at/updated_at ; plan_asset_version_id uuid FK asset_versions SET NULL. UNIQUE (building_id, code) et (building_id, level).

**buildings** : id uuid PK ; site_id uuid NOT NULL FK sites RESTRICT ; code varchar(40) ; name varchar(255) ; is_active bool ; created_at/updated_at. UNIQUE (site_id, code). (sites : code UNIQUE, name, timezone def 'Africa/Libreville', address, is_active.)

**display_zones** : id uuid PK ; code varchar(40) UNIQUE ; name ; max_classification varchar(10) def 'public' ; audio_allowed bool def false ; audio_max_volume smallint def 0 ; is_active ; created_at/updated_at. CHECK `display_zones_class_check` (public, interne) ; `display_zones_volume_check` (0..100).

**rooms** : id uuid PK ; code varchar(40) UNIQUE ; name ; floor_id uuid NOT NULL FK floors RESTRICT ; capacity int ; location_text varchar(255) ; photos jsonb ; manager_id uuid FK users SET NULL ; approval_mode varchar(10) def 'auto' ; min_minutes, max_minutes, lead_days, prep_minutes, cleanup_minutes smallint ; open_from, open_to time ; pending_hold_hours smallint ; auto_release_enabled bool ; status varchar(10) def 'active' ; is_demo ; created_at/updated_at ; version int. CHECK : `rooms_capacity_check` (>0), `rooms_duration_check` (max ≥ min), `rooms_hours_check` (open_to > open_from), `rooms_mode_check` (auto, manual), `rooms_status_check` (active, inactive). Pas de lien vers locations.

Tables absentes pour ce périmètre : historique des déplacements d'écran, profils de configuration, documents liés à un écran, critères de groupes dynamiques, positions sur plan.

## Défauts constatés

| # | Défaut | Preuve | Impact | Priorité |
|---|---|---|---|---|
| D1 | **Déplacement d'écran hors périmètre non contrôlé.** `update()` vérifie le périmètre de l'écran *avant* modification (SC:94) mais n'appelle pas `assertStillInScope` après le changement de `location_id`, alors que son commentaire (SC:327) le prévoit et que `store()` le fait (SC:82). Un gestionnaire limité à une zone ou à un site peut déplacer un écran vers une zone hors de son habilitation (et donc de sa classification) | SC:91-106 vs SC:80-85, 329-336 | sécurité | P0 |
| D2 | Écran avec incident manuel ouvert non classé « en anomalie » : `operationalState` ne regarde que `incidents.device_id`, or `storeIncident` n'enregistre que `screen_id`. 2 incidents ouverts concernés en base | PR:92-93 ; SC:55 ; DC:42 ; SV `storeIncident` | fonctionnel | P0 |
| D3 | Plage de fonctionnement affichée fausse : `Present::screen` remplace une plage vide par `config('saie.operating_window')`, alors que le lecteur applique écran > groupe > `Setting operating.default_window` (`Screen::effectiveOperatingWindow`). La liste, la fiche et l'export d'inventaire (EXS:301) peuvent donc montrer une plage que l'écran n'applique pas | PR:51 vs Models/Screen.php:73-81, MB:125 | fonctionnel | P1 |
| D4 | Total incohérent : la carte « écrans inventoriés » compte seulement les écrans `active` (19), la liste en renvoie 21 ; `fleet.total` donne 21 sur la même page d'accueil | DC:44,66,75 ; Screens.tsx:65 | fonctionnel | P0 |
| D5 | La carte « Écrans connectés » du tableau de bord ouvre la liste `?network=unavailable` (son inverse) et ignore les « suspects » | Dashboard.tsx:53 | fonctionnel | P1 |
| D6 | Classement réseau divergent entre compteurs et filtres : un lecteur actif jamais vu vaut `unknown` dans les compteurs (absent des cartes) mais `unavailable` dans le filtre de liste | Device.php:66 ; SC:47,53,57 ; DC:66 | fonctionnel | P1 |
| D7 | Description (`screens.notes`) acceptée par l'API mais jamais restituée ni éditable | SC:307 ; PR:48-72 ; ScreenDetail.tsx:17 | fonctionnel | P1 |
| D8 | La modale de groupe perd `ignore_holidays` à chaque enregistrement (envoi de `{days,on,off}` seulement) | Groups.tsx:132 vs SC:236,249 | fonctionnel | P2 |
| D9 | Un écran d'affichage ne peut pas être rattaché à une salle : l'interface force `room_id=null` hors `room_panel` | Screens.tsx:241 ; ScreenDetail.tsx:118 | fonctionnel | P1 |
| D10 | Fiche écran : le bâtiment et le type d'espace ne sont pas renvoyés par l'API | PR:55-59 | fonctionnel | P1 |
| D11 | `GET /referentials` n'a aucune vérification de permission : tout utilisateur authentifié obtient la liste des utilisateurs actifs, salles, structures, etc. | ReferentialController:31-53 | sécurité | P1 |
| D12 | `GET /screens` et `GET /screens/{id}` ne restreignent pas au périmètre de l'habilitation (permission seule) | SC:33,64 | sécurité | P2 |
| D13 | Le synoptique et le compte par étage ignorent les écrans en maintenance ou désactivés (filtre `status='active'`), sans le signaler | DC:44,47-52 ; Screens.tsx:111-128 | fonctionnel | P2 |
| D14 | Export d'inventaire limité au PDF dans l'interface (le backend accepte csv/xlsx) et pauvre en colonnes (ni matériel, ni statut, ni bâtiment) | Screens.tsx:53 ; EXS:54,299-301 | fonctionnel | P1 |
| D15 | Aucune interface de création de salle : l'API `POST /rooms` existe, mais aucun appel côté frontend | RoomController:65 ; grep frontend | fonctionnel | P1 |
| D16 | L'orientation configurée n'est pas appliquée par le lecteur, et l'interface ne la signale pas comme non pilotée | player/src (aucun usage de `screen.orientation`) | technique | P1 |
| D17 | `sites.timezone` est stocké mais ignoré : tout le calcul utilise `config('saie.timezone')` | MB:118 ; table sites | fonctionnel | P2 |
| D18 | Le sélecteur d'écrans des groupes et la supervision des membres reposent sur une liste plafonnée à 500 écrans, sans pagination | shared.tsx:135-137 ; Controller.php:106 | technique | P2 |

---

## Partie Audit B : lecteurs, supervision et commandes à distance (SAIE-CEEAC)

Audit en lecture seule, 8 novembre 2026. Sources : `backend/` (Laravel), `frontend/src/pages`, `player/` (SAIE Player web + `agent/panel-agent.mjs`), base `saie_ceeac` (port 5433, requêtes SELECT et `information_schema`/`pg_constraint`), `php artisan route:list`.
Conventions : C = Conforme, P = Partiel, A = Absent, D = Défectueux. Les chemins backend sont relatifs à `backend/`, les chemins lecteur à `player/` et les chemins frontend à `frontend/src/`.

Volumétrie observée (SELECT count) : 25 devices (18 actifs), 21 screens, 1 671 device_telemetry, 6 676 device_receipts, 1 device_screenshots, 6 pairing_codes, 5 player_releases, 23 incidents (19 `device_unavailable` automatiques, 3 manuels, 1 préventif), 3 maintenance_plans.

## Synthèse par statut

| Statut | Nombre |
|---|---|
| Conforme | 22 |
| Partiel | 40 |
| Absent | 18 |
| Défectueux | 2 |
| **Total** | **82** |

## Matrice de conformité

### §9 Lecteurs

| Réf | Exigence | Existant (preuve) | Statut | Écart | Impact | Priorité | Action proposée |
|---|---|---|---|---|---|---|---|
| 9.1 | Distinction écran physique / lecteur | Tables distinctes `screens` et `devices` (`devices.screen_id`). L'onglet « Identité » de l'écran et l'onglet « Lecteur » sont séparés (`pages/display/ScreenDetail.tsx:52,69`). Historique des lecteurs successifs (`ScreenController.php:67`, `ScreenDetail.tsx:421`). | C | — | — | P0 | — |
| 9.2 | Infos lecteur (id, nom, type, fabricant/modèle, n° série, IP, MAC, OS, version Player, firmware, date d'installation, dernière connexion, écran, état) | Côté `devices` : `id`, `code` (LCT-xxxx), `hardware_id`, `app_version`, `platform` (User-Agent tronqué, `engine.ts:334`), `paired_at`, `last_seen_at`, `screen_id`, `status`, `release_channel`. Fabricant, modèle, n° de série, IP et date d'installation existent seulement sur `screens` (`manufacturer`, `model`, `serial_number`, `ip_address`, `installed_on`), saisis à la main (`ScreenController.php:309-312`). | P | Il n'y a ni nom de lecteur, ni type de matériel, ni MAC, ni OS structuré, ni firmware. Les données matérielles décrivent la dalle, pas le boîtier lecteur. `hardware_id` est un UUID logiciel aléatoire (`engine.ts:96`), pas un identifiant matériel. | Inventaire du parc lecteurs impossible. Un remplacement de boîtier n'est pas traçable matériellement. | P0 | Ajouter à `devices` : `name`, `hardware_type`, `manufacturer`, `model`, `serial_number`, `ip_address`, `mac_address`, `os_name`/`os_version`, `firmware_version`, `installed_on`. Les alimenter par l'agent local (déclaratif) au heartbeat et par saisie. |
| 9.3 | Enrôler un lecteur | `POST api/v1/device/pairing-codes` puis le lecteur interroge `GET pairing-codes/{code}` (`DeviceApiController.php:33-73`). L'administrateur valide par `POST api/v1/devices/pair` (`ScreenController.php:128-167`), interface `pages/display/Pairing.tsx`. | C | — | — | P0 | — |
| 9.4 | Associer / désassocier | L'association se fait à l'appairage, avec option `replace`, qui révoque l'ancien lecteur (`ScreenController.php:148-153`). | P | Pas de désassociation sans révocation. Pas de réaffectation d'un lecteur existant à un autre écran (il faut réappairer). | Un déplacement de matériel oblige à révoquer puis réenrôler. | P0 | Ajouter une route `PATCH devices/{device}/screen` (réaffectation auditée) et une désassociation qui conserve l'identité. |
| 9.5 | Code d'appairage à durée limitée | TTL de 10 min (`config/saie.php:43`, `DeviceApiController.php:46`), usage unique (`used_at`). Les codes antérieurs du même `hardware_id` sont invalidés (`DeviceApiController.php:39`). Purge des codes expirés (`SaieTick.php:131`). | C | — | — | P0 | — |
| 9.6 | Renouveler les identifiants | `POST device/token/rotate` (`DeviceApiController.php:213-223`). Le lecteur s'en charge seul après 30 j (`engine.ts:17,191-202`), avec un TTL de 90 j (`config/saie.php:39`). L'ancien jeton expire 10 min plus tard. | P | Renouvellement à l'initiative du lecteur uniquement. Aucune action d'administration « forcer le renouvellement ». | En cas de suspicion de fuite, la seule option est la révocation complète. | P0 | Ajouter une commande « renouveler le jeton », délivrée par la file de commandes. |
| 9.7 | Révoquer | `POST devices/{device}/revoke` avec motif obligatoire, `tokens()->delete()`, audit `device.revoked` (`ScreenController.php:169-180,275-280`). `EnsureDevice` refuse tout lecteur non actif (`Http/Middleware/EnsureDevice.php`). | C | Le cache reste lisible jusqu'au `valid_until` (24 h pour le public). Ce comportement est voulu et documenté. | — | P0 | — |
| 9.8 | Mettre à jour l'application | `player_releases` (canaux pilot/stable, `PlayerReleaseController.php`). La version cible est portée par le manifeste signé (`ManifestBuilder.php:105`). Le lecteur vérifie le SHA-256 de `index.html` puis bascule (`engine.ts:390-413`), avec SRI sha384 sur les ressources (`scripts/sri-plugin.ts`). | P | Pas de mise à jour ciblée d'un lecteur précis (seulement par canal). Pas d'état de déploiement par lecteur (succès/échec). L'échec n'est remonté que comme erreur texte. | Une mise à jour en échec reste invisible dans la supervision. | P2 | Tracer par lecteur la version cible, la version installée, la date et le résultat. Ajouter une commande `update`. |
| 9.9 | Redémarrer l'application | Commande `reload` (`ScreenController.php:186-198`) remise au heartbeat. Le lecteur exécute `location.reload()` (`engine.ts:344-349`). Watchdog local : rechargement si le rendu est figé 60 s ou après 5 erreurs/min (`main.tsx:21-27`). | P | Aucun accusé d'exécution (voir 11.15). C'est un rechargement de la page, pas un redémarrage du processus navigateur. | Pas de preuve que la relance a eu lieu. | P1 | Le lecteur doit acquitter au redémarrage (événement `command_executed` avec l'id de la commande). |
| 9.10 | Synchroniser (à la demande) | Le lecteur synchronise seul toutes les 30 s, 300 s hors plage (`engine.ts:11-12`). Aucune commande : `devices_pending_command_check` n'admet que `'reload'`. | A | Pas de synchronisation forcée. | Après une publication urgente, on attend le cycle. | P1 | Ajouter une commande `sync` qui remet `nextSync = 0` côté lecteur. |
| 9.11 | Vérifier l'espace disque | `storage` = `navigator.storage.estimate()` (`storage.ts:185-189`) envoyé au heartbeat, affiché dans `ScreenDetail.tsx:236`. | P | C'est le quota du navigateur (origine), pas le disque du système. Pas de vérification à la demande. | Seuil « stockage plein » peu représentatif du disque réel. | P1 | Faire mesurer le disque par l'agent local (`df`/WMI) et l'envoyer au heartbeat. Ajouter une commande `check_storage`. |
| 9.12 | Consulter les journaux techniques | `devices.last_errors` (au plus 10 messages au heartbeat, `engine.ts:336`), `device_receipts` kind `error`, `device_telemetry` (payload brut). Affichage « Erreurs déclarées » (`ScreenDetail.tsx:243`). | P | Pas de consultation de l'historique des erreurs ni de la télémétrie dans l'interface. Aucune remontée des journaux système/navigateur. | Le diagnostic à distance se limite au dernier heartbeat. | P1 | Ajouter un onglet « Journaux » (receipts `error`/`fallback`/`offline` et télémétrie paginée) et une commande `upload_logs`. |
| 9.13 | Détecter les versions obsolètes | Répartition des versions (`SupervisionController.php:84`, `PlayerReleaseController.php:25`), barres de versions (`pages/admin/Supervision.tsx:123-132`). | P | Pas de comparaison automatique entre `app_version` et `PlayerRelease::targetFor(channel)`, pas de marqueur « obsolète », pas d'alerte. | Des lecteurs bloqués sur une ancienne version passent inaperçus. | P1 | Calculer `is_outdated` dans `Present::device`. Ajouter une règle `version_outdated` à `detectAnomalies`. |
| 9.14 | Enrôlement sécurisé | Code de 6 caractères sur un alphabet de 25 symboles. `poll_secret` haché en SHA-256 et comparé par `hash_equals` (`DeviceApiController.php:43-55`). Jeton remis une seule fois (`token_collected_at`). Throttle 10/min et 30/min. Permission `devices.pair` limitée au périmètre (`ScreenController.php:130-133`). | P | `requestCode` n'est pas authentifié : n'importe quel client peut se faire proposer. `hardware_id` est auto-déclaré. Le jeton reste en clair dans `pairing_codes.issued_token` jusqu'à 10 min (`SaieTick.php:133`). Aucune attestation ni liste blanche. | Un opérateur peut appairer un terminal non maîtrisé s'il saisit un code piégé. | P0 | Clé asymétrique générée par le lecteur (preuve de possession), liste blanche ou pré-enregistrement des numéros de série, chiffrement de `issued_token`. |

### §10 Supervision

| Réf | Exigence | Existant (preuve) | Statut | Écart | Impact | Priorité | Action proposée |
|---|---|---|---|---|---|---|---|
| 10.1 | En ligne / Hors ligne avec délai configurable | `Device::computedNetworkStatus()` : online ≤ 90 s, suspect ≤ 300 s, sinon unavailable (`Models/Device.php:64-76`). Seuils dans `config/saie.php:41-42`. | P | Seuils codés en dur (pas d'`env()`, pas de `Setting` éditable). Le texte de l'incident annonce « 5 min » en dur (`SaieTick.php:107`). | Délai non réglable par l'exploitant. | P0 | Passer les seuils en `Setting::effective('supervision.*')` et générer le texte à partir de la valeur. |
| 10.2 | État « En diffusion » (lecture confirmée) | `Present::operationalState` (`Support/Present.php:84-98`) ne produit que disabled, maintenance, pending_configuration, offline, anomaly et active (« Lecteur joignable »). Les receipts `played` existent mais n'alimentent pas l'état. | A | Aucun état fondé sur une lecture confirmée récente. | La supervision ne peut pas affirmer qu'un écran diffuse. | P0 | Dériver « En diffusion » d'un receipt `played` ou de `current_playback.at` datant de moins de N minutes. |
| 10.3 | État « En veille » | `screens.panel_state` peut valoir `standby` (`DeviceApiController.php:131-133`). Le lecteur émet `off_window` hors plage (`engine.ts:497-501`). | A | Absent des états opérationnels. Hors plage, l'écran apparaît « Lecteur joignable ». | Confusion entre veille normale et anomalie. | P0 | Ajouter l'état `standby` (hors plage ou `panel_state` standby/off). |
| 10.4 | État « En anomalie » | `last_errors` non vide ou incident ouvert (`Present.php:92-93`). | P | `last_errors` reste non vide tant qu'un nouveau manifeste n'est pas activé : le lecteur ne vide `errors` qu'à l'activation (`engine.ts:273`) et renvoie `errors.slice(-9)` à chaque heartbeat (`engine.ts:336`). L'anomalie colle donc à l'écran. | Faux positifs persistants. | P0 | Vider les erreurs déjà remontées après acquittement du heartbeat, ou fenêtre glissante datée. |
| 10.5 | État « Non configuré » | `pending_configuration` quand aucun lecteur n'est appairé (`Present.php:90`). | C | — | — | P0 | — |
| 10.6 | État « En maintenance » | `screens.status = maintenance` avec `maintenance_reason` obligatoire (`ScreenController.php:305-306`, `Present.php:89`). | C | — | — | P0 | — |
| 10.7 | État « Désactivé » | `screens.status = inactive` donne `disabled` (`Present.php:88`). | P | Côté lecteur, `devices_status_check` n'admet que active et revoked : pas de lecteur désactivé temporairement. | Suspendre un lecteur oblige à le révoquer. | P0 | Ajouter `suspended` à `devices.status`. |
| 10.8 | Distinction état lecteur / état écran / preuve de diffusion | `devices.network_status` (lecteur), `screens.panel_state` (dalle, « not_measured » sans canal, `Present.php:53`), `campaign_transmissions` et `device_receipts` (preuve). Avertissement affiché dans `ScreenDetail.tsx:76`. | P | `operational_state` fusionne les trois niveaux en un seul code. Sans canal de pilotage, l'état de la dalle n'est jamais mesuré, et c'est le cas par défaut. | Lecture ambiguë de l'état. | P0 | Exposer trois indicateurs séparés (lecteur, dalle, diffusion) dans les listes et la supervision. |
| 10.9a | Connexion et dernière activité | `devices.last_seen_at` (heartbeat et manifeste), `last_sync_at` (receipts `manifest_received`/`activated`, `DeviceApiController.php:183-190`). | C | — | — | P0 | — |
| 10.9b | Contenu en cours | `devices.current_playback` (`entry_id`, `lang`, `at`, ou `mode`/`alert`), `engine.ts:449-451`. | P | Identifiant brut, sans titre résolu (`ScreenDetail.tsx:240`). | Peu exploitable. | P1 | Résoudre `entry_id` en contenu/campagne côté serveur. |
| 10.9c | Campagne / playlist active | Disponible seulement par l'aperçu serveur (`GET screens/{screen}/preview`) et par le manifeste. | P | Rien n'est déclaré par le lecteur. | Le serveur suppose, il ne constate pas. | P1 | Faire envoyer `campaign_id` dans `playback`. |
| 10.9d | Disque | Voir 9.11 (quota navigateur). | P | Ce n'est pas le disque. | — | P1 | Agent local. |
| 10.9e | Mémoire, CPU, processus de lecture, température | Aucun champ dans `heartbeat` (`DeviceApiController.php:113-121`), aucun dans le lecteur. | A | — | Pas de maintenance prédictive. | P1 | Mesures par l'agent local (`os.loadavg`, `freemem`, capteurs) ajoutées au heartbeat. |
| 10.9f | Erreurs de lecture | `last_errors` et receipts `error`/`fallback`. | P | Non qualifiées : texte libre, sans code ni contenu concerné. | — | P1 | Erreurs structurées (code, entry_id, asset_id). |
| 10.9g | Synchronisation | `active_manifest_version` borné à la dernière version émise (`DeviceApiController.php:199-207`), dernier programme émis (`ScreenDetail.tsx:239`). | C | — | — | P0 | — |
| 10.10 | Historique des états | `device_telemetry` : un échantillon par ~285 s (`DeviceApiController.php:134-136`), receipts `offline`/`online`, `incidents.events` (JSON). | P | Pas de table d'historique des transitions d'état. La télémétrie n'est pas consultable dans l'interface. | Pas de calcul de disponibilité par écran. | P1 | Créer `screen_status_history` (from, to, state, cause), alimentée par `saie:tick` et le heartbeat. |
| 10.11 | Alerte : perte de connexion | Incident `device_unavailable` (gravité major) et notification aux gestionnaires (`SaieTick.php:98-116`). Résolution automatique au retour. | C | — | — | P1 | — |
| 10.12 | Alerte : absence prolongée | Même incident, gravité fixe. | P | Pas d'escalade par durée (heures, jours). | — | P1 | Relever la gravité selon la durée et notifier de nouveau. |
| 10.13 | Alerte : échec de synchronisation | Règle `sync_stalled` (`SaieTick.php:188-189`), seuil `Setting anomalies.sync_stalled_minutes`. | C | Calculée seulement quand le lecteur est en ligne (cas voulu). | — | P1 | — |
| 10.14 | Alerte : échec de lecture | Les receipts `error`/`fallback` ne sont exploités par aucune règle. | A | — | Une diffusion en repli prolongé passe inaperçue. | P1 | Règle sur le taux de `error`/`fallback` par fenêtre. |
| 10.15 | Alerte : stockage insuffisant | Règle `storage_full` (`SaieTick.php:184-185`). | P | Fondée sur le quota navigateur (9.11). | — | P1 | Mesure du disque par l'agent. |
| 10.16 | Alerte : Player arrêté | Aucune distinction entre lecteur arrêté et perte réseau. | A | — | Diagnostic erroné (réseau ou logiciel). | P1 | L'agent local signale « navigateur absent » (heartbeat agent distinct). |
| 10.17 | Alerte : version obsolète | Aucune. | A | — | — | P1 | Voir 9.13. |
| 10.18 | Alerte : écran déclaré défectueux | Incident manuel `category=panel` (`SupervisionController.php:106-115`). | P | Pas d'alerte sur `panel_state=off` pendant la plage. Pas de lien automatique avec `screens.status`. | — | P1 | Règle « dalle éteinte en plage » si un canal existe. |
| 10.19 | Alerte : anomalie de programmation | Seulement `sync_stalled`. | P | Aucune détection d'écran sans programme, en repli permanent ou ciblé par aucune campagne. | — | P1 | Règle sur un `schedule` vide ou `fallback` au manifeste. |
| 10.20 | Alerte : date, équipement, description, gravité (critique/majeur/moyen/mineur), statut, responsable | `incidents` : `detected_at`, `screen_id`/`device_id`, `title`/`description`/`impact`, `severity`, `status`, `assignee_id`. | D | `incidents_severity_check` = critical, major, minor : le niveau « moyen » est impossible en base (également `SupervisionController.php:109`, `Supervision.tsx:283`). Les incidents automatiques n'ont pas de responsable. | Classification non conforme au CDC. | P1 | Migration du CHECK avec `medium`. Affectation par défaut (astreinte) des incidents automatiques. |
| 10.21 | Supervision par étage et par groupe | Filtres `floor_id`, `zone_id`, `group_id`, `network` (`ScreenController.php:34-51`), pastilles par niveau et filtres (`pages/display/Screens.tsx:114-138`). | P | La page Supervision (`/supervision`) liste les lecteurs à plat, sans agrégat par étage ou groupe. | — | P1 | Agrégats par étage/groupe dans `SupervisionController::show`. |
| 10.22 | Rafraîchissement temps réel | Polling de 60 s (`Supervision.tsx:49,144`). Pas de `refetchInterval` sur la fiche écran ni sur la liste. Aucun WebSocket ou SSE. | P | Pas de temps réel. | Latence jusqu'à 60 s, plus 60 s de tick. | P1 | Diffusion par Reverb/SSE, ou au minimum un polling de 15 à 30 s sur les vues de parc. |

### §11 Commandes à distance

| Réf | Exigence | Existant (preuve) | Statut | Écart | Impact | Priorité | Action proposée |
|---|---|---|---|---|---|---|---|
| 11.1 | Synchroniser | — (CHECK `pending_command IN ('reload')`). | A | — | — | P1 | Commande `sync`. |
| 11.2 | Actualiser | `reload` couvre ce besoin (`ScreenController.php:186`). | P | Une seule commande pour « actualiser » et « redémarrer Player ». | — | P1 | Distinguer `refresh` (recharger le programme) et `restart_player`. |
| 11.3 | Redémarrer Player | `reload` donne `location.reload()` (`engine.ts:348`). | P | Rechargement de page, pas du processus. Sans accusé. | — | P1 | Via l'agent local : redémarrage du service navigateur. |
| 11.4 | Redémarrer l'appareil | Hors périmètre déclaré (`ScreenController.php:183-184`, `docs/lecteur.md:17`). | A | — | — | P1 | Commande `reboot` exécutée par l'agent local, si la capacité est déclarée. |
| 11.5 | Arrêter / reprendre la diffusion | — | A | — | Impossible de couper un écran à distance (hors campagne). | P1 | Commandes `stop_playback`/`resume_playback` (état `suspended` côté lecteur, écran neutre). |
| 11.6 | Mettre en veille / sortir de veille | Seulement automatique selon la plage (`engine.ts:380-384`, agent `panel-agent.mjs` on/off). | A | Pas de commande manuelle. | — | P1 | Commandes `standby`/`wake` relayées à l'agent (`POST /panel`). |
| 11.7 | Capturer l'écran | `POST devices/{device}/screenshot` (`ScreenController.php:200-208`) crée `device_screenshots` (requested, expire 10 min). La demande est remise au heartbeat (`DeviceApiController.php:137`), le lecteur capture puis téléverse (`engine.ts:419-435`), consultation par `GET device-screenshots/{id}`. | C | Voir 21.1 (rendu DOM) et D5. | — | P1 | — |
| 11.8 | Consulter les journaux (commande) | — | A | — | — | P1 | Commande `upload_logs`. |
| 11.9 | Autorisation par profil | `screens.manage` (reload, capture, canal), `devices.pair`, `devices.revoke`, `player_releases.manage`, plus le périmètre site/zone (`inScope`, `ScreenController.php:321-324`). | C | — | — | P1 | — |
| 11.10 | Envoi sécurisé | Remise en pull dans la réponse du heartbeat authentifié (Sanctum, scope `device`). | P | La commande n'est pas signée, contrairement au manifeste Ed25519. TLS dépend du déploiement. | — | P1 | Signer les commandes avec la clé du manifeste (id, type, expiration). |
| 11.11 | Horodatage et utilisateur | `devices.command_requested_at`, `command_requested_by`, `command_delivered_at`. Audit `device.reload_requested` (avec motif) et `device.command_delivered`. | P | Un seul emplacement : une nouvelle demande écrase la précédente, sans historique en base dédiée. | — | P1 | Table `screen_commands`. |
| 11.12 | Identifiant de transaction | — (la commande est une simple chaîne `'reload'`). | A | — | Aucune corrélation demande ↔ exécution. | P1 | UUID par commande, renvoyé dans l'accusé. |
| 11.13 | États (en attente, envoyée, reçue, exécutée, échouée, expirée, annulée) | Implicites : pending (`pending_command` non nul), delivered (`command_delivered_at`). | A | 5 états sur 7 absents. Ni expiration ni annulation. | — | P1 | Machine d'états dans `screen_commands.status` avec CHECK. |
| 11.14 | Compte rendu | — | A | — | — | P1 | Endpoint `POST device/commands/{id}/result`. |
| 11.15 | « Exécutée » seulement après confirmation du lecteur | La remise vaut effacement (`DeviceApiController.php:140-143`). Le lecteur exécute sans acquitter (`engine.ts:344-349`). L'interface annonce « Relance transmise » (`ScreenController.php:197`). | D | Le serveur considère la commande traitée dès sa remise. Si le lecteur échoue ou plante entre la réception et l'exécution, la commande est perdue sans trace. | Fausse assurance d'exécution. | P1 | Garder la commande en `delivered` jusqu'à l'accusé, avec rejeu jusqu'à expiration. |
| 11.16 | Capacités matérielles vérifiées avant de proposer | Seul indicateur : `screens.control_channel` (none/cec/rs232/api), déclaratif. Aucune capacité remontée par le lecteur ou l'agent. | A | — | Des actions peuvent être proposées alors que le matériel ne sait pas les exécuter. | P1 | Table `player_capabilities`, alimentée au heartbeat (agent présent, reboot, cec, capture, disk…). |
| 11.17 | Commandes indisponibles désactivées avec explication | Relance désactivée si déjà en attente (`ScreenDetail.tsx:250`). Capture désactivée si le lecteur est révoqué (`ScreenDetail.tsx:258`). | P | Pas d'explication liée aux capacités. Le backend ne refuse pas la capture pour un lecteur révoqué (`ScreenController.php:200-208`). | — | P1 | Liste de commandes calculée serveur avec `available` et `reason`. |

### §17.3 Protocole et échanges

| Réf | Exigence | Existant (preuve) | Statut | Écart | Impact | Priorité | Action proposée |
|---|---|---|---|---|---|---|---|
| 17.1 | Heartbeat | `POST device/heartbeat` toutes les 30 s (`engine.ts:13`, `next_heartbeat_s`), indépendant de la synchronisation (`engine.ts:152-157`). | C | — | — | P0 | — |
| 17.2 | Exécution asynchrone | Pull au heartbeat, exécution locale. | P | Pas de retour d'exécution (11.15). | — | P1 | Accusé asynchrone. |
| 17.3 | File de commandes | Un seul emplacement `devices.pending_command` (CHECK `'reload'`). Les captures passent par `device_screenshots`. | A | — | Commandes concurrentes perdues. | P1 | `screen_commands` FIFO par lecteur. |
| 17.4 | Authentification individuelle | Jeton Sanctum par lecteur, ability `device`, middleware `EnsureDevice`, throttle `device` 120/min (`routes/api.php:65`, `AppServiceProvider` l.51). | C | — | — | P0 | — |
| 17.5 | TLS | Nginx en TLS 1.2/1.3 avec HSTS (`docs/deploiement.md:52`). Rien n'est imposé dans l'application ni dans le lecteur (URL relative `/api/v1/device`). | P | Repose entièrement sur l'infrastructure. Non vérifiable sur ce poste. | — | P0 | Vérifier en recette et refuser le HTTP côté application pour `/api/v1/device` en production. |
| 17.6 | Journalisation des échanges | Audit : `device.command_delivered`, `device.token_rotated`, `device.asset_denied`, `device.unauthorized` (`bootstrap/app.php:65-66`). Télémétrie échantillonnée. | P | Les heartbeats et manifestes servis ne sont pas journalisés (seules les transmissions de campagne le sont). Télémétrie 1 sur ~10. | — | P1 | Journal technique des échanges avec rétention courte. |
| 17.7 | Synchronisation différentielle | ETag / `If-None-Match` donne 304 (`DeviceApiController.php:85-88`). Seuls les médias manquants sont téléchargés (`engine.ts:302-322`), par Range avec reprise (`storage.ts:99-141`). | C | — | — | P0 | — |
| 17.8 | Cache local | Cache Storage avec vérification SHA-256 (`storage.ts:81-141`), IndexedDB (identité, manifeste actif, file), service worker pour la coquille (`public/sw.js`), persistance demandée. | C | — | — | P0 | — |
| 17.9 | Hors ligne avec reprise | Manifeste valable 24 h (public) / 1 h (interne) (`ManifestBuilder.php:52-63`). File d'événements de 2 000 (`storage.ts:55`), receipts dédoublonnés, `flush` à la reconnexion. | C | — | — | P0 | — |
| 17.10 | Détection des pertes | `events_dropped` compté et signalé en erreur (`engine.ts:330-341`). Receipts `offline`/`online`. `offline_since` envoyé au heartbeat. | P | `offline_since` n'est conservé que dans la télémétrie échantillonnée. Pas d'indicateur serveur des événements perdus. | — | P1 | Persister `offline_since` et `events_dropped` dans `devices`. |
| 17.11 | Rétention des remontées techniques | `saie:retention` : télémétrie 90 j (`ApplyRetention.php:26-36`), lancé seulement si `retention.auto_purge` (`routes/console.php`). Captures 7 j, 5 derniers manifestes, codes d'appairage (`SaieTick.php:121-137`). | P | `device_receipts` n'est jamais purgé. La purge automatique est désactivée par défaut. | Croissance sans borne (6 676 receipts déjà présents). | P1 | Politique explicite pour `device_receipts` (agrégation, puis purge). |

### §18 Sécurité des lecteurs

| Réf | Exigence | Existant (preuve) | Statut | Écart | Impact | Priorité | Action proposée |
|---|---|---|---|---|---|---|---|
| 18.1 | Authentification forte | Jeton porteur Sanctum (haché en base), TTL 90 j. | P | Pas de mTLS ni de clé asymétrique propre au lecteur. Le jeton est stocké en clair dans IndexedDB (`storage.ts:40-41`). | Un jeton exfiltré vaut identité jusqu'à la rotation. | P0 | Paire de clés lecteur (WebCrypto non extractible) et requêtes signées, ou mTLS. |
| 18.2 | Anti-enrôlement non autorisé | Appairage décidé par un administrateur habilité et limité au périmètre. Tentatives non autorisées tracées (`device.unauthorized`). | P | `requestCode` est ouvert à tous (9.14). | — | P0 | Voir 9.14. |
| 18.3 | Gestion des secrets | `poll_secret` haché, jeton remis une seule fois, `issued_token` effacé après 10 min. | P | `pairing_codes.issued_token` est en clair pendant ce délai, et le jeton est en clair côté lecteur. | — | P0 | Chiffrer (`encrypted` cast) et ramener le délai au strict minimum. |
| 18.4 | Rotation | Jeton : automatique à 30 j, avec 10 min de recouvrement. Clé de signature : `trusted_keys` et `next_signing_key_*` (`config/saie.php:47-50`, `engine.ts:180-188`). | C | — | — | P0 | — |
| 18.5 | Anti-rejeu | Manifeste signé Ed25519, `device_id`, version monotone, `valid_until` (`schedule.ts:133-140`, `engine.ts:288-296`). Receipts uniques par `event_id`, `occurred_at` ramené à l'heure de réception s'il est dans le futur (`DeviceApiController.php:169`). | P | Les requêtes du lecteur (heartbeat, receipts) ne portent ni nonce ni signature. Les commandes ne sont pas signées. | — | P0 | Nonce et horodatage signés sur les requêtes du lecteur, commandes signées. |
| 18.6 | Révocation des lecteurs compromis | Voir 9.7, plus `EnsureDevice`. Le lecteur passe en phase `revoked` sur un 401 (`engine.ts:172-175,205-212`). | C | Le cache reste lisible jusqu'à 24 h (documenté). | — | P0 | — |
| 18.7 | Mise à jour sécurisée | SHA-256 de `index.html` porté par le manifeste signé, SRI sha384 sur toutes les ressources, pilote obligatoire avant stable (`PlayerReleaseController.php:36-38`), retrait avec retour arrière (`engine.ts:392-398`). | C | — | — | P2 | — |

### §21 Fonctions avancées et §23

| Réf | Exigence | Existant (preuve) | Statut | Écart | Impact | Priorité | Action proposée |
|---|---|---|---|---|---|---|---|
| 21.1 | Capture réelle du rendu | Capture du DOM `.stage` par `html-to-image` (`engine.ts:415-435`). | P | Ce n'est pas une capture de la sortie vidéo : les images vidéo et le contenu PDF canvas peuvent manquer, et l'état de la dalle n'est pas prouvé (commentaire `engine.ts:417`). Un échec n'est pas remonté au serveur : la demande reste `requested`. | Preuve visuelle partielle. | P2 | Capture OS par l'agent (ou API constructeur), et statut `failed` remonté. |
| 21.2 | Supervision intelligente (pertes répétées) | Un incident par occurrence, résolu automatiquement au retour (`SaieTick.php:111-115`). | A | Pas de détection de bagottement ni de corrélation par étage ou par switch. | Bruit et causes communes non vues. | P2 | Compter les transitions sur une fenêtre glissante, corréler par étage. |
| 21.3 | Mises à jour progressives (canaux) | Canaux pilot/stable, affectation par lecteur (`PATCH devices/{device}/channel`). | P | Pas de pourcentage, de vagues, de promotion automatique sur indicateurs, ni de suivi de succès. | — | P2 | Vagues et indicateurs de succès par version. |
| 21.4 | Maintenance préventive | `maintenance_plans` et `openDueMaintenance` ouvrent une intervention à échéance (`SaieTick.php:213-230`). La résolution recale le plan (`SupervisionController.php:146-148`). | C | — | — | P2 | — |
| 23.1 | Fonctionnement autonome hors ligne et resynchronisation | Voir 17.7 à 17.9. Démarrage hors ligne par le service worker, manifeste actif conservé, file d'événements rejouée. | C | — | — | P0 | — |

## Tables conceptuelles attendues

| Table attendue | Ce qui en tient lieu | Écart |
|---|---|---|
| `player_capabilities` | **Absente.** Seuls indicateurs déclaratifs : `screens.control_channel`, `screens.touch_enabled`, `screens.audio_enabled`. | Aucune capacité remontée par le lecteur ou l'agent. |
| `player_heartbeats` | `device_telemetry` (`id`, `device_id`, `recorded_at`, `payload` jsonb), échantillonnée à 1 ligne par ~285 s. État courant dénormalisé dans `devices` (`last_seen_at`, `storage`, `clock_skew_ms`, `current_playback`, `last_errors`, `cache_inventory`). | Pas de colonnes typées. 9 heartbeats sur 10 ne laissent pas de trace. |
| `screen_commands` | `devices.pending_command` (un seul emplacement, CHECK `'reload'`), `command_requested_at`, `command_requested_by`, `command_delivered_at`. `device_screenshots` pour les captures. | Pas de file, pas d'id, pas d'états. |
| `screen_command_logs` | `audit_logs` (`device.reload_requested`, `device.command_delivered`, `device.screenshot_requested`, `device.revoked`, `device.channel_changed`). | Pas d'accusé ni de résultat d'exécution. |
| `screen_status_history` | **Absente.** Fragments : `incidents.events` (jsonb), receipts `offline`/`online`/`off_window`, `device_telemetry`. | Pas d'historique des transitions. |

## Protocole lecteur actuel

**Endpoints** (préfixe `api/v1/device`, sans session ni CSRF, `routes/api.php:61-73`) :

| Méthode | Route | Auth | Rôle |
|---|---|---|---|
| POST | `pairing-codes` | aucune, throttle 10/min | Le lecteur envoie `hardware_id` et `info{app_version, platform, resolution}`. Réponse `{code, poll_secret, expires_at}` (201). |
| GET | `pairing-codes/{code}` | en-tête `X-Poll-Secret`, throttle 30/min | Réponse `waiting`, ou `paired` avec `{token, device{id,code}, public_key, key_id}` remis une seule fois. 410 si `CODE_USED`/`CODE_EXPIRED`. |
| GET | `key` | aucune | `{alg: Ed25519, key_id, public_key}`. |
| GET | `manifest` | Bearer Sanctum, ability `device`, `EnsureDevice`, throttle 120/min | Enveloppe signée. ETag `"<sha256>-<version>"`, 304 si inchangé. Met à jour `last_seen_at` et enregistre `campaign_transmissions`. |
| POST | `heartbeat` | idem | Voir les champs ci-dessous. |
| POST | `token/rotate` | idem | Nouveau jeton (TTL 90 j). L'ancien expire 10 min plus tard. |
| POST | `receipts` | idem | Au plus 500 événements. `kind` ∈ manifest_received, content_loaded, activated, played, alert_received, alert_activated, error, fallback, offline, online, off_window. Dédoublonnage par `event_id`. |
| GET | `assets/{assetVersion}` | idem | Média autorisé seulement s'il figure dans l'un des 2 derniers manifestes, analysé `ready`, non `restreint`. En-têtes `X-Content-SHA256`/ETag, X-Accel-Redirect possible. |
| POST | `screenshots/{screenshot}` | idem | Image png/jpg/webp ≤ 8 Mo, uniquement si la demande est `requested` et appartient au lecteur. |

**Champs du heartbeat** (`DeviceApiController.php:113-121`, envoyés par `engine.ts:331-338`) : `app_version`, `platform` (User-Agent sur 120 caractères), `resolution` (fenêtre), `device_time` (obligatoire), `active_manifest_version`, `storage{usage, quota, persisted}` (quota navigateur), `errors[]` (au plus 20 × 300 caractères), `playback{}`, `panel_state` (on/off/standby/unknown, seulement si `control_channel ≠ none` et l'agent joignable), `cache_inventory[]` (au plus 500), `offline_since`.
Réponse : `server_time`, `clock_skew_ms`, `next_heartbeat_s` (30), `screenshot_requests[]` (ids), `commands[]`, `revoked:false` (toujours `false`, champ mort).

**Contenu du manifeste** (`ManifestBuilder.php:41-160`) : enveloppe `{format: saie-manifest-envelope/1, version, etag, payload (JSON exact), signature{alg Ed25519, key_id, value}}`. La charge contient `format`, `manifest_id`, `device_id` (code LCT), `version`, `generated_at`, `valid_until`, `authorization{public, interne}`, `content_hash`, `timezone`, `screen{code, name, kind, orientation, resolution, control_channel, location, floor, building, zone}`, `operating_window{days, on, off, wake_before_min, override_reason, ignore_holidays}`, `non_working_days`, `audio_policy`, `schedule[]`, `alerts[]`, `alert_policy`, `agenda`, `fallback`, `institution`, `dynamic`, `trusted_keys`, `room_panel`/`orientation` selon le type d'écran, `assets[]` (`id`, `href`, `sha256`, `size`, `type`) et `player_release{version, channel, url, sha256}`.
Contrôles côté lecteur : signature avec une clé connue, `device_id`, `valid_until`, version monotone, `content_hash` (renouvellement sans réactivation), téléchargement complet avant la bascule (pas d'activation partielle), réserve de 20 % du quota.

**Mécanisme `pending_command`** : l'administrateur appelle `POST devices/{id}/reload` (motif obligatoire, `screens.manage` et périmètre), ce qui écrit `pending_command='reload'`, `command_requested_at/by` et efface `command_delivered_at`. Au heartbeat suivant, le serveur place la commande dans `commands[]`, la **supprime aussitôt** (`pending_command=null`, `command_delivered_at=now`) et journalise `device.command_delivered`. Le lecteur purge ses lectures, vide la file d'événements, puis `window.location.reload()`. Aucun accusé, aucun identifiant, une seule commande possible (CHECK). Les captures suivent un circuit parallèle par `device_screenshots.status='requested'`, remis tant que la demande n'a pas expiré.

## Schéma (colonnes réelles, `information_schema`, `!` = NOT NULL)

- **devices** : id uuid!, code varchar! (UNIQUE), screen_id uuid, hardware_id varchar!, status varchar! — CHECK `status IN ('active','revoked')` —, app_version, platform, reported_resolution, last_seen_at tz, last_sync_at tz, network_status varchar! — CHECK `IN ('unknown','online','suspect','unavailable')` —, storage jsonb, clock_skew_ms int, active_manifest_version int, current_playback jsonb, last_errors jsonb, cache_inventory jsonb, paired_at tz, paired_by uuid, revoked_at tz, revoked_by uuid, revoke_reason varchar, is_demo bool!, created_at, updated_at, release_channel varchar! (pas de CHECK en base, validé `pilot|stable` dans l'application), pending_command varchar — CHECK `pending_command IS NULL OR = 'reload'` —, command_requested_at tz, command_requested_by uuid, command_delivered_at tz.
- **device_telemetry** : id bigint!, device_id uuid!, recorded_at tz!, payload jsonb!. Pas de CHECK.
- **device_receipts** : id uuid!, event_id uuid! (UNIQUE), device_id uuid!, kind varchar! (pas de CHECK en base), manifest_version int, ref_type, ref_id, lang, occurred_at tz!, received_at tz!, payload jsonb.
- **device_screenshots** : id uuid!, device_id uuid!, requested_by uuid, requested_at tz!, captured_at tz, storage_key varchar, status varchar! (pas de CHECK : valeurs requested/captured/expired), expires_at tz!.
- **pairing_codes** : id uuid!, code varchar! (UNIQUE), poll_secret_hash varchar!, hardware_id varchar!, device_info jsonb, expires_at tz!, used_at tz, device_id uuid, issued_token text (jeton en clair, transitoire), token_collected_at tz, created_ip varchar, created_at, updated_at.
- **player_releases** : id uuid!, version varchar!, channel varchar! — CHECK `IN ('pilot','stable')` —, base_url varchar!, sha256 char!, notes text, status varchar! (pas de CHECK : active/withdrawn), created_by uuid, created_at, updated_at. UNIQUE (version, channel).
- **incidents** : id uuid!, reference varchar! (UNIQUE), screen_id, device_id, kind varchar! (pas de CHECK : manual, device_unavailable, storage_full, clock_drift, sync_stalled, preventive), severity varchar! — CHECK `IN ('critical','major','minor')` —, status varchar! — CHECK `IN ('open','acknowledged','resolved','closed')` —, title varchar!, description text, impact varchar, detected_at tz!, acknowledged_at, resolved_at, closed_at, assignee_id uuid, events jsonb, is_automatic bool!, created_at, updated_at, category varchar! — CHECK `IN ('player','panel','network','power','content','software','other')` —, maintenance_type varchar! — CHECK `IN ('corrective','preventive')` —, diagnosis text, maintenance_plan_id uuid.
- **maintenance_plans** : id uuid!, title varchar!, instructions text, screen_id uuid, category varchar! — CHECK (mêmes 7 valeurs) —, frequency_days smallint! — CHECK `BETWEEN 1 AND 730` —, next_due_on date!, last_done_on date, is_active bool!, created_by uuid, created_at, updated_at.
- Annexes : **manifests** (id, device_id!, version!, etag char!, payload jsonb!, valid_until!, generated_at!, UNIQUE(device_id, version)), **campaign_transmissions** (device_id, campaign_id, first_manifest_version, first_transmitted_at), **delivery_attempts** (notifications : notification_id, channel, status, attempts, last_error, sent_at, UNIQUE(notification_id, channel)), sans lien avec les lecteurs.
- **screens** (côté écran) : status CHECK `('active','maintenance','inactive')`, control_channel CHECK `('none','cec','rs232','api')`, panel_state (pas de CHECK), plus la fiche matérielle (manufacturer, model, serial_number, ip_address, connection_type CHECK, installed_on, warranty_until).

## Défauts constatés

1. **D1 Commande déclarée traitée sans exécution prouvée** : `DeviceApiController.php:140-143` efface `pending_command` dès la remise. Le lecteur exécute sans acquitter (`engine.ts:344-349`). Une commande perdue reste invisible.
2. **D2 Gravité non conforme** : `incidents_severity_check` exclut « moyen » (critique/majeur/moyen/mineur exigés). La validation `in:critical,major,minor` (`SupervisionController.php:109`) et le filtre frontend reproduisent l'écart.
3. **D3 Anomalie « collante »** : le lecteur ne vide `state.errors` qu'à l'activation d'un nouveau manifeste (`engine.ts:273`) et renvoie les 9 dernières à chaque heartbeat. `Present::operationalState` classe alors l'écran « En anomalie » (`Present.php:92`) bien après la disparition de l'erreur.
4. **D4 Seuils de supervision non configurables** : 30, 90 et 300 s sont fixés dans `config/saie.php:40-42` sans `env()` ni `Setting`. Le texte « aucun contact depuis 5 min » est codé en dur (`SaieTick.php:107`) et deviendra faux si le seuil change.
5. **D5 Captures** : `requestScreenshot` ne vérifie pas que le lecteur est actif (`ScreenController.php:200-208`). L'échec de capture côté lecteur (`engine.ts:432-434`) n'est pas transmis au serveur : la demande reste `requested`, et la purge ne traite que les lignes avec `storage_key`. Aucun CHECK sur `device_screenshots.status`.
6. **D6 Champ mort** : la réponse du heartbeat renvoie toujours `revoked: false` (`DeviceApiController.php:148`). La révocation passe en réalité par un 401.
7. **D7 « Stockage » trompeur** : `navigator.storage.estimate()` mesure le quota de l'origine navigateur, pas le disque. La règle `storage_full` (`SaieTick.php:184`) s'appuie sur cette mesure.
8. **D8 Identité lecteur fragile** : `hardware_id` = `saie-<uuid>` aléatoire en IndexedDB (`engine.ts:94-98`). Effacer les données du navigateur crée une nouvelle identité. Le raccourci clavier Ctrl+Alt+Shift+P réinitialise l'appairage local sans trace serveur (`Player.tsx:32`).
9. **D9 Secret en clair** : `pairing_codes.issued_token` (text) contient le jeton en clair jusqu'à son retrait ou au plus 10 min (`ScreenController.php:160`, `SaieTick.php:133`). Le jeton est aussi en clair dans IndexedDB.
10. **D10 Perte d'information de télémétrie** : un seul heartbeat sur ~10 est historisé (`DeviceApiController.php:134`). `offline_since` est validé mais pas persisté dans `devices`.
11. **D11 Rétention incomplète** : `device_receipts` n'est jamais purgé. La purge de télémétrie dépend de `retention.auto_purge` (désactivée par défaut, `routes/console.php`).
12. **D12 Coût du tick** : `detectAnomalies` exécute par lecteur et par minute 1 requête `manifests` et 3 requêtes `incidents` (`SaieTick.php:179-205`), soit un N+1 qui croît avec le parc.
13. **D13 Contrôles manquants** : `assignChannel` accepte un lecteur révoqué (`PlayerReleaseController.php:58-71`). `withdraw` ne vérifie pas que la version n'est pas déjà retirée. `release_channel`, `player_releases.status`, `incidents.kind` et `device_receipts.kind` n'ont pas de CHECK en base.
14. **D14 Incidents automatiques sans responsable ni escalade** : `assignee_id` reste nul, la gravité est fixe (`major` pour l'indisponibilité, `minor` pour les anomalies) quelle que soit la durée.
15. **D15 Statut « online » posé par l'appel au manifeste** : `DeviceApiController.php:84` force `network_status='online'` même quand le heartbeat échoue (sans gravité, mais cela masque un heartbeat en panne).

---

## Partie Audit C — Gestion des écrans : affectations, historiques, rapports, habilitations, workflows, règles métier

Audit en lecture seule du 08/11/2026. Base : code de `C:\laragon\www\SAIE-CEEAC` (backend Laravel, frontend React, lecteur), `php artisan route:list`, requêtes SELECT sur `saie_ceeac` (PostgreSQL 18, port 5433).
Chemins relatifs à `backend/` sauf mention `frontend/`. Statuts : **C** Conforme · **P** Partiel · **A** Absent · **D** Défectueux.
Priorités : P0 affectations/sécurité · P1 historiques, rapports · P2 analyses avancées.

Remarque préalable : le texte des §12 à §20 (en particulier la liste des 13 critères de recette du §20) ne figure pas dans le dépôt (`docs/` ne contient ni « RG-001 » ni « Responsable du parc »). L'audit s'appuie sur l'énoncé de la mission ; la liste des 13 tests du §20 est **reconstituée** à partir des exigences des §12 à §19 et doit être confrontée au cahier de référence.

## 1. Matrice de conformité

| Réf | Exigence | Existant (preuve) | Statut | Écart | Impact | Priorité | Action proposée |
|---|---|---|---|---|---|---|---|
| §12-a | Affecter une playlist à un écran ou un groupe | Table `playlists`/`playlist_items` (migr. 2026_11_06_215100) ; `PlaylistController.php:17-19` : une liste est un modèle **copié dans une campagne** ; la cible est portée par `campaign_targets.target_type IN ('screen','group','zone','floor')` (migr. 100400:196) | P | Pas d'affectation directe playlist→écran/groupe ; passage obligé par une campagne | Ergonomie, pas de « programme permanent » par écran | P0 | Documenter le choix ou ajouter une affectation directe (campagne permanente générée) |
| §12-b | Campagne sur plusieurs écrans | `campaign_targets` multiples ; `CampaignService::targetsQuery` (CampaignService.php:53-73) ; `ManifestBuilder::campaignIdsFor` (ManifestBuilder.php:169-180) | C | — | — | — | — |
| §12-c | Dates et plages horaires | `campaigns.starts_at/ends_at/days/time_from/time_to` ; CHECK `campaigns_period_check` (migr. 100400:169) ; validation `CampaignController.php:324-326` ; appliqué par `ScheduleResolver.php:49-60` | C | — | — | — | — |
| §12-d | Priorités | `campaigns.priority` entier 1–99 (`CampaignController.php:323`), tri `ScheduleResolver.php:71-74` | P | Valeur numérique libre, sans niveaux nommés | Hiérarchie §12.1 non garantie | P0 | Introduire une énumération de niveaux (cf. §12.1) |
| §12-e | Périodes | Jours de semaine, créneau, mode événement borné à 31 j (`CampaignService.php:137-152`, test EventModeTest) | C | — | — | — | — |
| §12-f | Détection des conflits | `CampaignService::preview` (CampaignService.php:110-119) : campagnes `scheduled/active` chevauchant la **période** ; affichage `frontend/src/pages/publication/CampaignWizard.tsx:526-555` | P | Chevauchement calculé sur les dates seules (ignore `days`, `time_from/time_to`, plage de fonctionnement, alertes) ; simple avertissement, non bloquant, non réévalué à la publication ; aucun test | Faux positifs/négatifs | P2 | Calcul par intersection jours×créneaux, re-vérification à `publish`, test dédié |
| §12-g | Prévisualisation des contenus | `TemplatePreview` (`frontend/src/pages/publication/shared.tsx:213`, utilisé `ContentEditor.tsx:463`) ; `POST /campaigns/preview` | C | — | — | — | — |
| §12-h | Historique des affectations | Journal `campaign.created/updated/published/suspended/archived/expired` (CampaignController.php:71,101,151 ; CampaignService.php:197,212,227) | P | `campaign.updated` sans avant/après ; cibles supprimées puis recréées (`CampaignController.php:307-311`) ; aucune vue « affectations passées » par écran | Impossible de reconstituer qui diffusait quoi sur un écran à une date | P1 | Journaliser avant/après des cibles ; vue historique par écran (via `campaign_transmissions`) |
| §12.1-a | Hiérarchie urgent institutionnel > officiel prioritaire > campagnes > informations générales > permanents | Alerte > programme > repli : `ScheduleResolver.php:36-41, 63-65` ; mode événement prioritaire (67-70) | P | Seuls 3 étages réels (alerte, campagne numérique, repli) ; « officiel prioritaire » et « informations générales » non modélisés | Ordre dépendant d'une saisie numérique | P0 | Niveaux métier mappés sur des bandes de priorité verrouillées |
| §12.1-b | Priorités configurables et soumises à autorisation | Urgence soumise au pouvoir « urgence » (`config/saie.php:199`) + double validation info (`config/saie.php:98`) | P | Toute priorité 1–99 accessible à `campaigns.manage` ; pas de paramétrage des niveaux | Un diffuseur peut passer devant un message officiel | P0 | Permission par niveau (ex. `campaigns.priority_high`) |
| §12.1-c | Urgence interrompant puis retour automatique au programme | `ScheduleResolver.php:23-41` (alerte prioritaire, y compris hors plage pour « critical ») ; expiration/révocation → manifeste recompilé | C | — | — | — | — |
| §12.2 | Prévisualisation du rendu sur un écran donné (orientation, résolution) | `GET /screens/{screen}/preview` (ScreenController.php:108-123) : programme résolu **tabulaire** (`frontend/src/pages/display/shared.tsx` `ProgramResult`) ; avertissement d'orientation (CampaignService.php:118, 125-128) | P | Aucun rendu visuel à l'échelle orientation/résolution de l'écran choisi | Erreurs de mise en page découvertes en salle | P2 | Réutiliser `TemplatePreview` dans l'onglet Programme au format de l'écran |
| §13.1-a | Historique : création | `screen.created` (ScreenController.php:86) | C | — | — | — | — |
| §13.1-b | Historique : modification | `screen.updated` avec avant/après (ScreenController.php:101-103) ; onglet Historique `ScreenDetail.tsx:443-445` | C | — | — | — | — |
| §13.1-c | Historique : déplacement | Changement de `location_id` couvert par `screen.updated` générique | P | Pas d'action dédiée ; pas de re-contrôle de périmètre après déplacement (défaut D6) | Traçabilité faible des mouvements | P1 | Action `screen.moved` (ancien/nouveau lieu) |
| §13.1-d | Historique : changement de configuration | `screen.window_changed` avec motif obligatoire (ScreenController.php:97-103) ; base : 6 entrées | C | — | — | — | — |
| §13.1-e | Historique : changement de groupe | `group.created/updated/deleted` sur l'objet `ScreenGroup` (ScreenController.php:240,256,269) | P | `group.updated` sans « avant » ; non visible dans l'historique de l'écran (filtre `object_type='Screen'`, ScreenController.php:70) | Appartenance passée introuvable depuis la fiche | P1 | Journaliser sur chaque écran ajouté/retiré |
| §13.1-f | Historique : affectation de contenu | Aucun historique par écran ; seulement `campaign_transmissions` (preuve de transmission) | A | — | Pas de « ce qu'a diffusé l'écran X » | P1 | Vue par écran à partir de `campaign_transmissions` + `device_receipts` |
| §13.1-g | Historique : désactivation / réactivation | `status` modifié via `screen.updated` ; `maintenance_reason` exigé pour maintenance (ScreenController.php:305-306) | P | Pas d'action dédiée ni motif obligatoire pour `inactive` | Motif de retrait non tracé | P1 | Actions `screen.deactivated/reactivated` avec motif |
| §13.1-h | Historique : archivage | CHECK `screens_status_check IN ('active','maintenance','inactive')` (migr. 2026_11_06_201301:30) ; aucune route de suppression/archivage | A | Pas d'état archivé | Écrans retirés mélangés au parc | P1 | État `archived` + action dédiée |
| §13.2-a | Connexions / déconnexions | Reçus `online/offline/off_window` (DeviceApiController.php:160) ; `last_seen_at` ; incident automatique `device_unavailable` ouvert/résolu (`SaieTick.php:99-115`) | P | Pas de journal horodaté des transitions (seulement incidents > 5 min) | Coupures courtes invisibles | P1 | Table/événements de transition d'état réseau |
| §13.2-b | Incidents | `incidents.events` jsonb + audit `incident.*` (SupervisionController.php:142-149) | C | — | — | — | — |
| §13.2-c | Commandes et résultats | `device.reload_requested` (ScreenController.php:195), `device.command_delivered` (DeviceApiController.php:139-144) | P | Remise tracée, **résultat d'exécution non remonté** ; une seule commande (`reload`) | Pas de preuve d'exécution | P1 | Accusé de résultat du lecteur |
| §13.2-d | Synchronisations | `device_receipts` `manifest_received/activated`, `last_sync_at` (DeviceApiController.php:182-190) ; `campaign_transmissions` | C | — | — | — | — |
| §13.2-e | Erreurs | `devices.last_errors` écrasé à chaque heartbeat (DeviceApiController.php:129) ; reçus `error` ; télémétrie échantillonnée 5 min | P | Historique partiel (écrasement) | Diagnostic a posteriori limité | P1 | Conserver les erreurs en reçus systématiquement |
| §13.2-f | Mises à jour | `app_version` (télémétrie), `device.channel_changed` (PlayerReleaseController.php:68) | P | Pas d'historique de version par lecteur | Suivi de déploiement approximatif | P1 | Événement `device.version_changed` au heartbeat |
| §13.2-g | Interventions | Incidents correctifs/préventifs, plans de maintenance (SupervisionController.php:164-190 ; SaieTick.php:215-230) | C | — | — | — | — |
| §13.3-a | Rapport inventaire général PDF/Excel | `equipment_inventory` pdf/csv/xlsx (ExportService.php:54, 290-307) | D | **Non borné au périmètre** de l'habilitation (tous les écrans) ; garantie absente des colonnes | Fuite d'inventaire hors périmètre | P0 | Filtrer par `screenScope`, ajouter fabricant/modèle/série/garantie |
| §13.3-b | Inventaire par bâtiment / étage | Aucun paramètre bâtiment/étage pour `equipment_inventory` (ExportController.php:43 `floor_id` exploité par `room_usage` seulement) | A | — | — | P1 | Paramètres `building_id/floor_id` |
| §13.3-c | Écrans hors ligne | Filtre écran `network=unavailable` dans l'API liste (ScreenController.php:40-50), pas d'export | A | — | — | P1 | Type d'export dédié |
| §13.3-d | Incidents | `incident_report` pdf/csv/xlsx (ExportService.php:56, 321-334) | D | **Non borné au périmètre** ; colonnes sans catégorie ni diagnostic | Fuite hors périmètre | P0 | Filtrer par périmètre, compléter les colonnes |
| §13.3-e | Disponibilité | Calcul JSON `reports/summary` (ReportController.php:123-140) ; pas d'export | P | Pas de PDF/Excel | — | P1 | Export disponibilité |
| §13.3-f | Maintenance | Incidents préventifs inclus dans `incident_report` ; plans non exportables | P | Pas de rapport de maintenance | — | P1 | Export plans + interventions |
| §13.3-g | Diffusions | `diffusion_report` pdf/csv/xlsx borné au périmètre (ExportService.php:53, 252-288) | C | — | — | — | — |
| §13.3-h | Commandes exécutées | Aucun export | A | — | — | P1 | Export depuis `audit_logs` (`device.*`) |
| §13.3-i | Équipements en fin de garantie | Colonne `warranty_until` et indicateur `under_warranty` (app/Support/Present.php:68-69) ; aucun rapport | A | — | — | P1 | Export filtré sur `warranty_until` |
| §13.3-j | Durées de conservation paramétrables | `config/saie.php:141-148` ; réglages `retention.telemetry_days/notifications_days/auto_purge` (SettingController.php:33-35,59-61) | P | Journal (`audit_months`) et exports (`export_hours`) non modifiables dans l'interface ; reçus/incidents sans durée | — | P1 | Exposer toutes les durées |
| §13.3-k | Droits sur les rapports paramétrables | Permissions par type figées dans le code (`ExportService::TYPES`, ExportService.php:47-58 ; `BULK` :63) ; rôles éditables (`PUT roles/{role}/permissions`) | P | Correspondance type→permission non paramétrable | — | P1 | Permission par type d'export |
| §14-1 | Profil Administrateur SAIE | `admin_fonc` (config/saie.php:209-212) : comptes, rôles, référentiels | P | Pas de `screens.manage` ni `campaigns.manage` ; aucun rôle global | Administration éclatée entre deux rôles | P0 | Valider la correspondance ou créer le rôle |
| §14-2 | Administrateur technique | `admin_tech` (config/saie.php:205-208) | C | — | — | — | — |
| §14-3 | Responsable du parc | Aucun rôle | A | Pas de création de rôle par l'API (RoleController sans `store`) | — | P0 | Créer `responsable_parc` |
| §14-4 | Superviseur | Aucun rôle (`supervision.view` seulement chez admin_tech, technicien) | A | — | — | P0 | Créer `superviseur` (lecture supervision, rapports) |
| §14-5 | Technicien | `technicien` (config/saie.php:230-232) | C | — | — | — | — |
| §14-6 | Gestionnaire de diffusion | `diffuseur` (config/saie.php:219-222) + pouvoir `urgence` (:199) | C | — | — | — | — |
| §14-7 | Auditeur | `auditeur` (config/saie.php:227) | P | Ne peut exporter ni inventaire ni incidents (exigent `screens.view`/`supervision.view`, ExportService.php:54,56) | Audit du parc impossible | P1 | Ajouter lectures nécessaires |
| §14-8 | Habilitation limitable à un périmètre (bâtiment, étage, groupe, équipement) | `role_assignments.site_id/zone_id/structure_id` (RoleController.php:68) ; `Access::screenScope` = site ∩ zone de diffusion (Access.php:181-205) ; base : 4 affectations restreintes | P | Ni bâtiment, ni étage, ni groupe, ni équipement ; `structure_id` ignoré pour les écrans | Délégation fine impossible | P0 | Ajouter `building_id/floor_id/group_id/screen_id` au périmètre |
| §14-9 | Contrôle backend de chaque opération sensible | Voir section « Contrôle des routes » | D | Contournement du périmètre (D1), incidents/plans non bornés (D4), déplacement non re-contrôlé (D6) | Actions hors périmètre possibles | P0 | Corriger D1, D4, D6 |
| §15.1 | Cycle de vie (enregistrement → … → retrait/archivage) | Enregistrement (ScreenController.php:75-89), localisation obligatoire (FK `screens.location_id` restrict, migr. 100100:20), installation (`installed_on`), enrôlement (pair :128-167), configuration (plage, canal), état opérationnel `pending_configuration/active/offline/anomaly/maintenance` (test FleetAndIncidentTest:22) | P | Pas d'étape « validation communication et rendu » ni « mise en service » (écran `active` dès sa création, migr. 100100:30) ; pas d'archivage | Écran programmable avant recette | P1 | Machine à états avec mise en service explicite |
| §15.2-a | Incident : détection automatique ou manuelle | `SaieTick.php:99-115, 170-205` ; `POST /incidents` (SupervisionController.php:106-115) | C | — | — | — | — |
| §15.2-b | Alerte | Notification `incident.opened` aux gestionnaires (SaieTick.php:108) | C | — | — | — | — |
| §15.2-c | Qualification | Gravité/catégorie à la création seulement (SupervisionController.php:109-110) | P | Pas de requalification (action absente :120) | — | P1 | Action `qualify` |
| §15.2-d | Affectation à un technicien | `assign` + éligibilité + notification (SupervisionController.php:124-130,150-152) | C | — | — | — | — |
| §15.2-e | Diagnostic | `diagnose` note obligatoire (SupervisionController.php:121,136) | C | — | — | — | — |
| §15.2-f | Intervention | Seulement `comment` | P | Pas d'étape intervention (date, durée, intervenant) | — | P1 | Action `intervene` |
| §15.2-g | Vérification | — | A | `resolve` → `close` sans vérification ni séparation des rôles | — | P1 | Étape de vérification |
| §15.2-h | Clôture avec historique | `close` exige `resolved` (SupervisionController.php:122) ; `events` + audit | P | Aucun résultat exigé (cf. RG-012) | — | P1 | Résultat obligatoire |
| §15.2-i | Incident non résolu visible au tableau de bord | DashboardController.php:71, 94 (open/acknowledged, tri par gravité) | C | — | — | — | — |
| RG-001 | Identifiant unique | `screens.code` UNIQUE (migr. 100100:17) + validation (ScreenController.php:290) ; UUID ; `devices.code` UNIQUE | C | — | — | — | — |
| RG-002 | Écran opérationnel avec localisation valide | `location_id` NOT NULL FK restrict (migr. 100100:20) ; `exists:locations,id` (ScreenController.php:293) | C | — | — | — | — |
| RG-003 | Un seul lecteur actif par écran | Index unique partiel `devices_one_active_per_screen` (migr. 100100:70) ; `SCREEN_ALREADY_PAIRED` (ScreenController.php:148-153) | C | — | — | — | — |
| RG-004 | Enrôlement authentifié | Code à usage unique + secret de sondage haché (DeviceApiController.php:33-73) ; `devices.pair` + périmètre (ScreenController.php:130-133) ; jeton `device` (EnsureDevice) | C | — | — | — | — |
| RG-005 | Écran désactivé sans nouvelle programmation | Exclu du ciblage (`CampaignService.php:55`) | D | `ManifestBuilder::scheduleFor` (ManifestBuilder.php:187-200) ignore `screens.status` : un écran `inactive` ciblé (écran, groupe, zone, niveau) continue de recevoir les campagnes ; cibles non vérifiées (`CampaignController.php:330`) | Diffusion sur écran retiré | P0 | Filtrer le statut dans `scheduleFor` et à la publication |
| RG-006 | Commande compatible avec les capacités | CHECK `pending_command IN ('reload')` (migr. 2026_11_06_205512:21) ; lecteur actif exigé (ScreenController.php:191) | P | Aucune notion de capacités ; `control_channel` non consulté | — | P1 | Matrice commande × capacités |
| RG-007 | Commande historisée | Voir §13.2-c | P | Sans résultat | — | P1 | Idem §13.2-c |
| RG-008 | Modification importante auditée | `Audit::log` (Audit.php:24-60) ; journal append-only (trigger, migr. 100500:74) | P | Avant/après absents pour `campaign.updated`, `group.updated`, `playlist.updated`, `incident.*` | Reconstitution impossible | P1 | Avant/après systématiques |
| RG-009 | Écran hors ligne jamais considéré diffusant | `computedNetworkStatus` (app/Models/Device.php:64-76) ; suivi par preuves (CampaignController.php:174-213) ; avertissement (CampaignService.php:129-132) | C | — | — | — | — |
| RG-010 | Affectation respectant programmation et priorités | `ScheduleResolver::resolve` (ScheduleResolver.php:18-77), même algorithme côté lecteur | C | — | — | — | — |
| RG-011 | Changement de localisation historisé | `screen.updated` avant/après (ScreenController.php:101-103) | P | Pas d'action dédiée ; périmètre non re-contrôlé (D6) | — | P1 | Cf. §13.1-c |
| RG-012 | Incident non clôturable sans résultat | Clôture limitée à l'état `resolved` (SupervisionController.php:122) | P | `resolve` n'exige aucune note/résultat (:120-121) | Clôtures non justifiées | P1 | `note` obligatoire pour `resolve`/`close` |
| RG-013 | Écran archivé hors nouvelles campagnes | Pas d'état archivé ; `inactive` exclu du ciblage mais pas du manifeste | D | Cf. RG-005 | — | P0 | État archivé + filtre manifeste |
| RG-014 | Disponibilité calculée sur données réellement reçues | Relevés `device_telemetry` / créneaux de 5 min attendus (ReportController.php:123-140 ; échantillonnage DeviceApiController.php:134-136) | C | Créneaux attendus hors plage de fonctionnement comptés | Disponibilité minorée la nuit | P2 | Restreindre aux plages de fonctionnement |

### Décompte (71 exigences évaluées, hors §20)

| Statut | Nombre |
|---|---|
| Conforme | 27 |
| Partiel | 30 |
| Absent | 9 |
| Défectueux | 5 |

## 2. Correspondance des profils (§14)

Rôles réels en base (SELECT `roles`/`permission_role`) : admin_fonc 17 permissions, admin_tech 20, agent 4, auditeur 4, consultation 6, contributeur 11, diffuseur 16, gestionnaire_contenus 11, gestionnaire_salle 9, technicien 8, validateur 9 (identiques à `config/saie.php:204-237`, semés par `database/seeders/ReferenceSeeder.php:21-29`).

| Profil §14 | Rôle réel | Permissions du module | Écart |
|---|---|---|---|
| Administrateur SAIE | `admin_fonc` (partiel) + `admin_tech` | admin_fonc : users.manage, roles.manage, referentials.manage, imports.run, settings.manage, screens.view, campaigns.view, reports.view, exports.create | Aucun rôle unique ; pas de screens.manage/campaigns.manage |
| Administrateur technique | `admin_tech` | screens.view/manage, groups.manage, devices.pair/revoke, supervision.view, incidents.manage, player_releases.manage, settings.technical, users.manage, reports.view, exports.create | Conforme ; users.manage en plus (anti-escalade : SecurityEnforcementTest:46) |
| Responsable du parc | — | — | Absent |
| Superviseur | — | — | Absent (supervision.view seulement via admin_tech/technicien) |
| Technicien | `technicien` | screens.view, supervision.view, incidents.manage, player_releases.manage, devices.pair, reports.view, exports.create | Ne peut ni révoquer ni relancer un lecteur (screens.manage) ; player_releases.manage s'applique à tout le parc |
| Gestionnaire de diffusion | `diffuseur` | campaigns.view/manage, fallback.manage, alerts.view, alerts.manage (pouvoir « urgence »), groups.manage, screens.view, reports.view, exports.create | Conforme |
| Auditeur | `auditeur` | audit.view, reports.view, exports.create | Pas de screens.view ni supervision.view → inventaire et rapport d'incidents inaccessibles |

Périmètres : `role_assignments.site_id`, `zone_id` (zones de diffusion `display_zones`), `structure_id` (RoleController.php:68). Le calcul `Access::screenScope` (Access.php:181-205) ne retient que site ∩ zone. Pouvoirs étendus avec périmètre de zones (`config/saie.php:198-202`, RoleController.php:105-130). Aucun périmètre par bâtiment, étage, groupe ou équipement.

## 3. Contrôle des routes (préfixe `/api/v1`, groupe `auth:sanctum, human, throttle:api, idempotent`, routes/api.php:82)

| Route | Méthode du contrôleur | Permission vérifiée | Périmètre | Constat |
|---|---|---|---|---|
| GET screens | ScreenController@index :33 | screens.view \| campaigns.view \| alerts.manage | Non | Lecture non bornée |
| POST screens | @store :77 | screens.manage | Oui (assertStillInScope :82) | OK |
| GET screens/{screen} | @show :64 | screens.view \| campaigns.view | Non | Lecture non bornée |
| PATCH screens/{screen} | @update :93 | screens.manage | Avant seulement (:94) | **D1** (écran inactif ⇒ contrôle vide) ; **D6** (nouvel emplacement non contrôlé) |
| GET screens/{screen}/preview | @preview :110 | campaigns.view \| screens.view | Non | Lecture non bornée |
| GET program | CampaignController@program :217 | campaigns.view | Non | Idem |
| POST devices/pair | ScreenController@pair :130 | devices.pair | Oui (:133) | **D1** si écran inactif |
| POST devices/{device}/revoke | @revoke :171 | devices.revoke | Oui (:173) | **D1** si écran inactif |
| POST devices/{device}/reload | @reload :188 | screens.manage | Oui (:189) | **D1** |
| POST devices/{device}/screenshot | @requestScreenshot :202 | screens.manage | Oui (:203) | **D1** |
| GET device-screenshots/{screenshot} | @screenshot :212 | screens.manage | Oui (:213) | **D1** |
| PATCH devices/{device}/channel | PlayerReleaseController@assignChannel :60 | screens.manage | Oui (:63-66, `screenScope` direct) | OK |
| GET screen-groups | ScreenController@groups :221 | screens.view \| campaigns.view \| groups.manage | Non | Lecture non bornée |
| POST screen-groups | @storeGroup :233 | groups.manage | Oui (écrans listés, :237) | Groupe vide créable par tous |
| PATCH screen-groups/{group} | @updateGroup :247 | groups.manage | Oui (:250) | **D1** : groupe vide ou sans écran actif modifiable hors périmètre |
| DELETE screen-groups/{group} | @destroyGroup :263 | groups.manage | Oui (:264) | **D1** idem |
| GET/POST/PATCH campaigns… | CampaignController :32-169 | campaigns.view / campaigns.manage | Oui pour écriture (:65, 89, 92, 121, 130, 144, 162) | Cibles non vérifiées en existence (:330) |
| POST campaigns/preview | @preview :108 | campaigns.manage \| alerts.manage | Non | Révèle conflits hors périmètre (mineur) |
| GET campaigns/{c}/delivery | @delivery :176 | campaigns.view | Non | Lecture non bornée |
| GET/POST/PATCH playlists | PlaylistController :24-54 | campaigns.view / campaigns.manage | Non | Pas de contrôle de propriétaire ni de version |
| GET/POST/DELETE fallbacks | CampaignController :225-251 | campaigns.view / fallback.manage | Oui pour écriture (:238, 252) | OK |
| GET supervision | SupervisionController@show :30 | supervision.view | Non | Vue globale |
| GET supervision/errors | @errors :223 | settings.technical | — | OK |
| GET incidents | @incidents :91 | supervision.view | **Non** | **D4** |
| POST incidents | @storeIncident :108 | incidents.manage | **Non** | **D4** (tout `screen_id`) |
| PATCH incidents/{incident} | @updateIncident :119 | incidents.manage | **Non** | **D4** |
| GET maintenance-plans | @plans :166 | supervision.view | Non | **D4** |
| POST/PATCH maintenance-plans | @storePlan :173 / @updatePlan :183 | incidents.manage | **Non** | **D4** |
| GET reports/summary | ReportController@summary :30 | reports.view | Oui (screensInScope :148-162) | OK |
| POST exports | ExportController@store :59 → ExportService::assertAllowed :75-87 | par type + exports.create (BULK) | Diffusion/bon de campagne seulement | **D5** inventaire et incidents non bornés |
| GET audit-logs | AuditController@index :19 | audit.view | — | OK (lecture seule) |
| PUT roles/{role}/permissions, role-assignments, role-extensions | RoleController :42-136 | roles.manage | Anti-escalade (:168-180) | OK |
| GET dashboard | DashboardController :35 | dashboard.view | Non | Parc et incidents visibles par tout rôle (y compris `agent`) |
| /device/* | DeviceApiController | jeton `device` + EnsureDevice (lecteur actif) | Propre au lecteur | OK ; codes d'appairage publics limités en débit (routes/api.php:62-63) |

Aucune route du module n'est dépourvue de contrôle de permission. Les failles portent sur le **périmètre**.

## 4. Couverture de recette (§20 — liste reconstituée, à confronter au cahier)

| # | Critère (reconstitué) | Test automatisé | Couverture |
|---|---|---|---|
| 1 | Enregistrer un écran (identifiant unique, localisation) | Aucun test n'appelle `POST /api/v1/screens` (écrans créés par fixture) ; modification : FleetAndIncidentTest::test_screen_hardware_record_and_operational_states, VersionedEditingTest::test_screen_edition_requires_the_read_version | Partielle |
| 2 | Déplacement historisé | Aucun | Non couvert |
| 3 | Enrôlement authentifié, un lecteur actif | DeviceTest::test_one_time_pairing_flow_delivers_individual_token_once, ::test_expired_pairing_code_is_refused, ::test_revoked_device_is_refused_by_the_api ; AdministrativeHierarchyTest::test_device_actions_are_bounded_by_the_site_and_zone_of_the_assignment ; e2e `player.spec.ts` « appairage, diffusion multilingue… » | Couvert (remplacement `SCREEN_ALREADY_PAIRED` non testé) |
| 4 | Groupe d'écrans | ZoneScopeTest::test_zone_limited_diffuser_cannot_act_on_screens_outside_its_zone ; DeviceDeliveryTest::test_screen_without_own_window_inherits_its_group_window ; VersionedEditingTest::test_screen_group_edition_requires_the_read_version | Couvert |
| 5 | Campagne multi-écrans avec dates et plages, playlist | e2e `views-interactions.spec.ts` « V21 : assistant de campagne… » ; PublicationRulesTest::test_reusable_playlist_keeps_order_and_campaign_archiving_is_controlled ; EventModeTest (3 tests) ; PublicationRulesTest::test_content_is_never_scheduled_before_its_validity_start | Couvert |
| 6 | Détection des conflits | Aucun (`conflicts` absent des tests du module) | Non couvert |
| 7 | Prévisualisation selon orientation/résolution | PublicationRulesTest::test_preview_warns_when_template_orientation_differs_from_the_screen | Partielle |
| 8 | Urgence puis retour au programme | DeviceTest::test_alert_preempts_programme_in_manifest_and_requires_urgency_power, ::test_critical_alerts_override_the_operating_window_by_default ; DeliveryProofTest::test_critical_alert_is_shown_outside_the_operating_window_but_not_a_warning ; e2e `player.spec.ts` (retour vérifié après révocation) | Couvert |
| 9 | Écran hors ligne : détection, incident, jamais diffusant | OperationsMonitoringTest::test_unavailable_player_opens_an_incident_notified_to_incident_managers ; FleetAndIncidentTest::test_player_anomalies_open_then_resolve_automatic_incidents ; DeliveryProofTest::test_heartbeat_alone_never_advances_the_last_synchronisation | Couvert |
| 10 | Commande à distance historisée | FleetAndIncidentTest::test_remote_reload_is_delivered_once_and_traced | Couvert (sans résultat) |
| 11 | Cycle d'incident jusqu'à clôture | FleetAndIncidentTest::test_incident_is_categorised_diagnosed_and_assigned_only_to_an_eligible_notified_agent, ::test_preventive_maintenance_plan_opens_one_intervention_and_reschedules_on_resolution | Partielle (clôture et refus de clôture non testés) |
| 12 | Écran désactivé/archivé exclu des campagnes | FleetAndIncidentTest::test_maintenance_status_requires_a_reason_and_keeps_the_screen_targeted (maintenance seulement) | Non couvert (et défaut D2) |
| 13 | Rapports PDF/Excel et habilitations par périmètre | ReportingTest::test_xlsx_workbook_is_valid_and_never_contains_formulas, ::test_export_can_be_generated_as_excel, ::test_report_figures_are_bounded_to_the_assignment_scope_and_building_filter ; FleetAndIncidentTest::test_incident_report_shows_french_status_and_severity ; DecisionD7AccessTest::test_bulk_extractions_require_the_export_permission_but_individual_sheets_do_not ; AsvsAuthorizationTest::test_exports_and_reports_are_bounded ; ZoneScopeTest (4 tests) | Partielle (rapports absents non testables ; périmètre des incidents/inventaire non testé) |

Bilan : 6 couverts, 4 partiels, 3 non couverts.

## 5. Défauts constatés

| Id | Gravité | Description | Preuve | Correction |
|---|---|---|---|---|
| D1 | P0 | Contrôle de périmètre neutralisé pour les écrans `inactive` et les groupes vides : `targetsQuery` ne retient que `active/maintenance`, donc `resolveTargets` renvoie une liste vide et `diff($scope)` est vide → accès accordé. Concerne modification/réactivation d'écran, appairage, révocation, relance, capture, modification/suppression de groupe | CampaignService.php:55, 87-88 ; appels ScreenController.php:94, 133, 173, 189, 203, 213, 250, 264 | Contrôle de périmètre sur les identifiants d'écrans sans filtre de statut ; groupe rattaché à un périmètre |
| D2 | P0 | Écran désactivé toujours programmé : le manifeste est compilé sans regarder `screens.status` | ManifestBuilder.php:112-122, 187-200 ; EnsureDevice ne contrôle que le lecteur | Programme vide (repli) si `status='inactive'` ; test |
| D3 | P0 | Cibles de campagne non vérifiées (identifiant UUID quelconque accepté) ; le contrôle de périmètre passe sur une cible inexistante | CampaignController.php:330 ; CampaignService.php:87-88 | Validation d'existence par type de cible |
| D4 | P0 | Incidents et plans de maintenance sans périmètre : un technicien limité à une zone lit, crée et traite les incidents de tout le parc | SupervisionController.php:89-155, 164-190 | Filtrer et contrôler par `screenScope(incidents.manage / supervision.view)` |
| D5 | P0 | Exports inventaire et incidents non bornés au périmètre (le rapport de diffusion l'est) | ExportService.php:290-307, 321-334 vs 265-268 | Appliquer `screenScope` |
| D6 | P1 | Déplacement d'un écran hors du périmètre non re-contrôlé (`assertStillInScope` utilisé à la création seulement) | ScreenController.php:82 vs 91-106 | Appeler `assertStillInScope` après mise à jour |
| D7 | P1 | Résolution/clôture d'incident sans résultat ; pas de vérification ni de requalification | SupervisionController.php:120-141 | Note obligatoire à `resolve`, étape de vérification |
| D8 | P1 | Historique d'écran incomplet : filtre `object_type='Screen'` limité à 20 lignes ; groupes, lecteurs et affectations journalisés sur d'autres objets | ScreenController.php:70 ; 240, 256, 279 | Agréger par `screen_id` |
| D9 | P1 | Journal sans avant/après pour campagnes, groupes, playlists, incidents ; cibles écrasées | CampaignController.php:101, 307-311 ; ScreenController.php:256 ; PlaylistController.php:62 | Avant/après systématiques |
| D10 | P1 | Commandes : remise tracée mais aucun résultat ; aucune vérification de capacité | DeviceApiController.php:139-144 ; migr. 2026_11_06_205512:21 | Accusé d'exécution |
| D11 | P2 | Conflits calculés sur les seules dates, non bloquants, non re-vérifiés à la publication | CampaignService.php:110-112 | Intersection jours×créneaux |
| D12 | P2 | Tableau de bord : parc et incidents visibles par tout titulaire de `dashboard.view` (agent, contributeur) sans périmètre | DashboardController.php:71, 94 | Conditionner à screens.view/supervision.view |
| D13 | P2 | Lectures du parc non bornées (liste, fiche, programme, aperçu, groupes, suivi de diffusion) | ScreenController.php:31-73, 108-123, 219-229 ; CampaignController.php:174-221 | Filtrer par périmètre en lecture |
| D14 | P2 | Disponibilité : créneaux attendus comptés hors plage de fonctionnement | ReportController.php:132-136 | Restreindre aux plages |

---

## Suivi des corrections

| Réf. | Exigence | Intervention | Test | Résultat |
|---|---|---|---|---|
| §14, §18 (C-D1) | Périmètre : écran désactivé ou groupe vide hors zone | Cibles résolues quel que soit le statut ; groupe vide géré seulement par sa structure | `ScreenModuleIntegrityTest::test_a_zone_manager_cannot_reactivate_or_move_a_screen_outside_the_zone`, `…empty_group_of_another_structure…` | Conforme |
| §6, §14 (A-D1, C-D6) | Déplacement d'un écran hors périmètre | Nouveau contrôle dans la transaction de mise à jour | `…cannot_reactivate_or_move…` | Conforme |
| §14 (A-D12, C-D13) | Liste et fiche des écrans bornées au périmètre | `screenScopeFor` en lecture | `…lists_only_the_screens_of_the_zone` | Conforme (liste, fiche) |
| §14 (C-D4) | Incidents et plans de maintenance bornés au périmètre | Filtre et contrôle par écran | `…incidents_are_bounded_to_the_zone…` | Conforme |
| §13.3, §14 (C-D5) | Exports d'inventaire et d'incidents bornés au périmètre | `exportScreenScope` | Couvert par la revue de code (test d'export à ajouter au lot 9) | Partiel |
| §12 (C-D3) | Existence des cibles de campagne et d'alerte | `assertTargetsExist` (code `TARGET_NOT_FOUND`) | `…a_campaign_target_must_exist` | Conforme |
| RG-005 (C-D2) | Écran désactivé sans programmation | Manifeste vide (programme et alertes) pour un écran désactivé ou archivé | `…an_inactive_screen_receives_no_programme` | Conforme |
| §5.5, RG-013 | Archivage d'un écran | `POST /screens/{id}/archive` : motif, refus si incident ouvert, lecteur révoqué, retrait des groupes, fiche en lecture seule, masqué des listes | `…archiving_withdraws_the_screen_and_keeps_its_record` | Conforme |
| §10.3 (B-D2) | Gravité « moyen » | Contrainte, validation, libellés, filtres | `…incidents_are_bounded…` (création « medium ») | Conforme |
| RG-012 (C-D7) | Résolution d'incident avec résultat | Note obligatoire, colonne `incidents.resolution`, affichée dans la fiche | `…resolution_requires_a_result` | Conforme |
| §10.1 (A-D2) | Incident déclaré à la main → écran « en anomalie » | Lecteur actif rattaché à l'incident ; état calculé aussi sur `screen_id` | `…manual_incident_marks_the_screen_in_anomaly…` | Conforme |
| §10.1 (B-D3) | Anomalie persistante après disparition de l'erreur | Seules les erreurs de moins de `SAIE_DEVICE_ERROR_WINDOW_MIN` (10 min) comptent | `…old_player_errors_do_not` | Conforme |
| §10.1 (B-D4) | Seuils de supervision paramétrables | Variables `SAIE_DEVICE_*` ; texte de l'incident automatique dérivé du seuil | Revue de code | Partiel (interface de paramétrage au lot 3) |
| §4.1 (A-D4, D5, D6) | Totaux et lien de carte du tableau de bord | Libellé « écrans en service », carte vers `?network=online`, lecteur jamais vu = indisponible partout, archivés exclus | Suite complète | Conforme |
| §7 (A-D3) | Plage affichée = plage appliquée | `effectiveOperatingWindow()` dans la présentation, avec `window_source` | Suite complète | Conforme |
| §5.1 (A-D7) | Description restituée | `notes` renvoyé et affiché | Suite complète | Partiel (édition au lot 4) |
| §18 (B-D9) | Jeton d'appairage en clair | **Faux positif de l'audit** : colonne déjà chiffrée (`PairingCode` cast `encrypted`) | — | Conforme |
| §14 (A-D11) | `/referentials` sans permission | Non modifié : annuaire interne (nom, structure) nécessaire aux formulaires de tous les profils. **Risque accepté, à valider par la DSI** | — | Décision à prendre |
| §11.1 | Commandes synchroniser, actualiser, redémarrer le lecteur, arrêter et reprendre la diffusion, capturer, consulter les journaux | File `device_commands`, service `RemoteCommands`, exécution dans le lecteur (`player/src/engine.ts`) | `RemoteCommandsTest` (7 tests) ; e2e `screens-commands.spec.ts` sur lecteur réel | Conforme |
| §11.1 | Mettre en veille et sortir de veille | Via l'agent local (`panel-agent.mjs`), capacité `panel_power` ; état de la dalle relu et renvoyé dans le compte rendu | e2e avec agent en **simulation** | Implémenté — validation matérielle en attente (HDMI-CEC / RS-232) |
| §11.1 | Redémarrer l'appareil | Agent local, capacité `reboot_device` déclarée seulement si `SAIE_REBOOT_CMD` est défini ; exécution confirmée au retour du lecteur | e2e avec agent en **simulation** (résultat marqué « simulation ») | Implémenté — validation matérielle en attente |
| §11.2 | Autorisation, identifiant de transaction, horodatage, auteur, état, compte rendu | Permission `devices.command` (administrateur technique, technicien) ; périmètre ; 7 états avec contrainte CHECK ; journal `device.command_*` | `RemoteCommandsTest::test_commands_are_restricted_by_permission_scope_and_device`, `…sent_received_and_executed_with_a_report` | Conforme |
| §11.2 | « Exécutée » seulement sur confirmation du lecteur ; expiration ; annulation | Accusés `POST /device/commands/{id}/ack` ; renvoi tant que non reçue ; expiration par `saie:tick` ; annulation avant réception | `…expires_and_a_pending_one_can_be_cancelled`, `…reflected_once_confirmed` | Conforme |
| §11.2, RG-006 | Capacités vérifiées, commandes indisponibles désactivées avec explication | Capacités déclarées à chaque battement ; refus serveur `COMMAND_UNAVAILABLE` journalisé ; boutons désactivés avec motif dans la fiche | `…unsupported_command_is_refused_with_its_reason`, `…never_declared_capabilities…` ; capture `captures/commandes-a-distance.png` | Conforme |
| RG-007 | Toute commande historisée | Historique paginé par lecteur (`GET /devices/{id}/commands`) et journal d'audit | e2e : 7 entrées `device.command_executed` lues par l'auditeur | Conforme |
| §11 (B-D1) | Ancienne relance effacée à la remise sans accusé | Remplacée par la file de commandes ; routes `/reload` et `/screenshot` conservées comme alias | `FleetAndIncidentTest::test_remote_reload_is_queued_redelivered_until_acknowledged_and_traced` | Conforme |
| §10.2 (B-D5) | Échec de capture non remonté | La capture est une commande : son échec est rendu au serveur (`failed` avec message) | `…screenshot_command_links_the_capture_request` | Conforme |
| §11 | Diffusion arrêtée sans fausse « lecture déclarée » | Aucune lecture déclarée pendant l'arrêt ; les alertes restent affichées | e2e (écran noir constaté) ; revue de code `Player.tsx` | Conforme |
| (B-D6) | Champ mort `revoked: false` du battement | Retiré ; la révocation reste signifiée par un refus 401 | Contrat OpenAPI régénéré, exemples réenregistrés | Conforme |
| §10.1 | États « en diffusion », « en veille », diffusion arrêtée, repli | `Present::operationalState` : lecture déclarée de moins de 15 min ; dalle mesurée en veille ou lecteur hors plage ; arrêt commandé ; repli | `SupervisionStatesTest::test_operational_states_distinguish_broadcasting_standby_pause_and_fallback` | Conforme (« en diffusion » = lecture **déclarée** par le lecteur, sans preuve matérielle de l'image) |
| RG-009 | Écran hors ligne jamais compté comme diffusant | L'état hors ligne précède tous les états de lecture | même test (dernière assertion) | Conforme |
| §10.1, §13.2, RG-014 | Historique des états | Table `screen_status_history` (une période ouverte par écran, index unique partiel), alimentée chaque minute par `saie:tick` ; `GET /screens/{id}/status-history` ; onglet Historique de la fiche | `…the_scheduler_keeps_one_period_per_state_change` | Conforme |
| §10.1 (B-D4) | Délais de supervision paramétrables | Paramètres `supervision.suspect_after_s` et `supervision.offline_after_s` (ordre contrôlé) ; texte de l'incident dérivé du délai | `…offline_delay_is_a_setting_and_must_exceed_the_suspect_delay` | Conforme |
| §10.3 | Alertes : lecteur arrêté, échecs de lecture répétés, version obsolète | Règles `player_stalled` (majeur), `playback_errors` (moyen), `outdated_version` (mineur), seuils paramétrables ; résolution automatique avec résultat consigné | `…stalled_playback_repeated_errors_and_outdated_versions_open_incidents` | Conforme |
| §9.1 | Caractéristiques du lecteur | `devices.hardware_info` : définition, cœurs, mémoire, langue, fuseau, tactile ; MAC, système et firmware **non accessibles à une application web** (champ prévu pour l'agent local) | `…heartbeat_records_declared_characteristics…` | Partiel (MAC, système, firmware : dépendance agent local) |
| §10, §16.3 | Supervision par étage et par groupe, bornée au périmètre | `by_floor`, `by_group`, état par lecteur, filtre par clic ; rafraîchissement 15 s | `…supervision_groups_by_floor_and_group` | Conforme |
| §10.2 (B-D10) | Battements historisés | Inchangé : un battement sur environ dix est conservé dans `device_telemetry` (dernier état complet dans `devices`). Choix de volumétrie documenté | — | Partiel (choix assumé) |
| §17.2 | Temps réel | Rafraîchissement HTTP 15 s (supervision) et 5 s (suivi d'une commande) ; WebSocket ou MQTT non introduits (nouvelle dépendance d'infrastructure) | — | Partiel (décision d'infrastructure) |
| §5.1 | Acquisition, fournisseur, référence de commande, description, documents associés | Colonnes `acquired_on`, `supplier`, `order_reference` ; `notes` éditable ; table `screen_documents` (document consultable par l'auteur du rattachement) | `ScreenInventoryTest::test_the_full_record_is_stored…`, `…documents_can_be_linked_to_a_screen` ; e2e `screens-inventory.spec.ts` | Conforme |
| §5.2 | Technologie, consommation, MAC, système, firmware | Colonnes avec contraintes (technologie, format MAC normalisé) ; formulaire et fiche | `…full_record_is_stored…` | Conforme |
| §5.3 | Emplacement précis, salle pour tout écran, bâtiment, position sur le plan | `location_detail`, `plan_x`/`plan_y` (0–100 %), salle facultative pour tout type et contrôlée au même niveau (`ROOM_FLOOR_MISMATCH`), bâtiment et type d'espace dans l'API | `…full_record_is_stored…` | Conforme (responsable et photo de l'emplacement : colonnes `locations.manager_id`, `photo_asset_version_id` créées ; saisie dans les référentiels au lot 7) |
| §5.5 | Archivage, import CSV, export, impression, QR code | Import `POST /screens/import` (modèle, simulation exacte en transaction annulée, validation commune, périmètre, atomicité) ; export PDF/CSV/XLSX enrichi ; QR `access_links` de type `screen` réservé aux comptes habilités ; fiche imprimable | `…csv_import_simulates_without_writing…`, `…qr_code_opens_the_record_for_authorised_accounts_only` ; e2e import → QR → archivage | Conforme |
| §6, RG-011 | Historique des déplacements, un seul emplacement actif | Table `screen_location_history` (index unique partiel), initialisée pour les 25 écrans existants ; motif, auteur ; audit `screen.moved` | `…each_move_is_historised` ; e2e | Conforme |
| §13.3 | Inventaire par bâtiment et par niveau | Synthèse par bâtiment et niveau, filtres `building_id` / `floor_id`, état opérationnel, borné au périmètre | `…inventory_report_is_bounded_to_the_scope_and_can_target_a_floor` | Conforme |
| (A-D15) | Création de salle dans l'interface | Non traité dans ce lot (référentiel des salles) | — | Absent → lot 7 |
| §7.3 | Profils réutilisables affectables à un écran ou à un groupe | Table `screen_profiles` (paramètres bornés), `screens.profile_id`, `screen_groups.profile_id` ; l'écran prime sur le groupe ; page « Profils de configuration » | `ScreenProfilesTest` (4 tests) ; e2e `screens-profiles.spec.ts` sur lecteur réel | Conforme |
| §7.1 | Volume, plein écran, langue, veille hors plage | Volume maximal (plafonne la politique audio), langues et rotation, plein écran, veille hors plage (si dalle pilotable) | `…overrides_the_group_profile_and_reaches_the_manifest` ; e2e | Conforme |
| §7.1 | Luminosité, contraste | Paramètres acceptés mais **signalés « non pris en charge »** : aucun pilotage d'image de la dalle n'est disponible (ni navigateur, ni agent actuel) | e2e : `brightness` dans `unsupported` | Implémenté — dépendance matérielle (pilote d'image de la dalle) |
| §7.1 | Résolution, orientation, fuseau horaire | Résolution et orientation déjà sur la fiche ; fuseau : celui de l'application (`Africa/Libreville`) ; fuseau par site non exploité (A-D17) | — | Partiel |
| §7.2 | Fréquence de synchronisation, intervalle de remontée, politique de reconnexion | `sync_interval_s`, `heartbeat_interval_s`, `reconnect_max_s` (espacement progressif hors ligne) appliqués par le lecteur | e2e : appliqués et confirmés | Conforme |
| §7.2 | IP / DHCP, MAC, serveur, certificat | IP et MAC sur la fiche ; serveur et authentification : jeton individuel par lecteur ; DHCP / IP fixe relèvent du système hôte | — | Partiel (hors application web) |
| §7 | État de synchronisation et confirmation d'application | Empreinte SHA-256 de la configuration dans le manifeste signé ; le lecteur renvoie l'empreinte appliquée, les paramètres appliqués et non pris en charge ; fiche : « Appliquée et confirmée » / « En attente » | `…player_confirms_the_applied_configuration…` ; capture `captures/configuration-profil.png` | Conforme |
| §8 (A-D8) | Option « jours fériés » perdue à l'enregistrement d'un groupe | Options de la plage conservées | Revue de code | Conforme |
| §8.3 | Groupes dynamiques par critères (bâtiment, niveau, zone, salle, type, statut, état de connexion, campagne) | `screen_groups.type`/`criteria` (contraintes de cohérence), service `DynamicGroups` ; membres recalculés à l'enregistrement et chaque minute ; archivés exclus ; membres non modifiables à la main | `DynamicGroupsTest` (4 tests) ; e2e `screens-groups.spec.ts` | Conforme |
| §8.3, §14 | Groupe dynamique et périmètre | Réservé aux habilitations sans restriction de site ou de zone (les critères peuvent viser tout le parc) | `…only_an_unrestricted_manager_handles_dynamic_groups` | Conforme |
| §8.2 | Statistiques et supervision par groupe | Membres en ligne, en anomalie, sans lecteur (liste des groupes) ; supervision par groupe (lot 3) | `…group_listing_reports_member_statistics` ; capture `captures/groupes-dynamiques.png` | Conforme |
| §8.2 | Configuration commune au groupe | Profil de configuration du groupe (lot 5) | `ScreenProfilesTest` | Conforme |
| §13.1 | Avant/après sur les groupes | Journal `group.updated` avec état antérieur (membres, critères, plage, profil) | Revue de code | Conforme |
| §4.1 | Indicateurs : total, en ligne, hors ligne, en diffusion, en anomalie, inactifs, non configurés, alertes techniques par gravité | Service `FleetOverview`, `GET /screens/overview` (périmètre) ; page « Vue d'ensemble du parc » | `FleetOverviewTest::test_indicators_are_computed_from_real_states_and_link_to_filtered_lists` ; e2e `screens-overview.spec.ts` | Conforme |
| §4.1 | Cartes interactives vers les listes filtrées | Filtre `state` (état opérationnel calculé) sur la liste des écrans ; chaque carte ouvre la liste filtrée | même test (`?state=anomaly`) ; e2e (carte « Non configurés ») | Conforme |
| §4.2 | Répartitions par bâtiment, niveau, état, type et technologie ; état des lecteurs | Répartitions et versions, lecteurs en retard sur leur canal, diffusions arrêtées | même test ; capture `captures/vue-ensemble-parc.png` | Conforme |
| §4.2, RG-014 | Évolution du taux de disponibilité | Joignabilité quotidienne sur 14 jours : relevés de télémétrie reçus / créneaux de 5 min attendus depuis l'appairage ; jour sans lecteur = « sans mesure » | même test | Conforme |
| §4.2 | Écrans les plus sollicités ; historique des incidents | Top 5 des lectures déclarées sur 7 jours ; incidents ouverts par jour sur 30 jours, dont critiques | même test | Conforme |
| §4.3 | Vue interactive du bâtiment | Vue par niveau : plan déposé (Référentiels › Niveaux) avec écrans positionnés (`plan_x`, `plan_y`), sinon cartes par emplacement ; salles ; état en couleur **et** icône **et** libellé ; ouverture de la fiche ; filtre des anomalies | e2e (région « Vue par niveau ») | Conforme (plans réels à déposer par la DSI) |
| §6 (A-D15) | Création de salle dans l'interface | Boîte « Nouvelle salle » (administrateur fonctionnel, seul habilité par le serveur) | Revue ; e2e des vues | Conforme |
| §5.3 | Responsable de l'espace, photographie | `locations.manager_id` saisi dans Référentiels › Emplacements ; photo : champ et validation serveur (image saine), saisie à ajouter à l'interface | Revue de code | Partiel (photo : interface non faite) |
| §15.2 | Qualification, affectation, diagnostic, intervention, vérification, clôture | Actions `qualify` (gravité et catégorie, échéance recalculée), `intervene`, `verify` (auteur et date), `reopen` ; **clôture refusée avant vérification** (`VERIFICATION_REQUIRED`) ; historique de chaque étape | `IncidentWorkflowTest::test_the_full_workflow_requires_a_verification_before_closing` ; e2e `screens-incidents.spec.ts` (vérification par un second technicien) | Conforme |
| §10.3 | Responsable de chaque alerte | Responsable par défaut des incidents automatiques (paramètre, agent actif et habilité) appliqué à toute création | `…automatic_incidents_go_to_the_default_assignee…` | Conforme |
| §10.3 | Escalade | Échéance de prise en charge par gravité (paramètres, défauts 30 min / 2 h / 8 h / 24 h) ; à l'échéance d'un incident non acquitté : niveau d'escalade, nouvelle échéance, notification des gestionnaires et du responsable | même test | Conforme |
| §10.3 | Statistiques des incidents | `GET /incidents/stats` : volumes par gravité et catégorie, délais moyens de prise en charge et de rétablissement mesurés, automatiques, escaladés ; panneau dans Supervision | `…statistics_report_volumes_and_mean_delays` | Conforme |
| (hors module) | Liste des tâches en erreur 500 pour un auteur ayant une version renvoyée | Fusion de collections corrigée (`DashboardController`) — **138 erreurs relevées dans le journal du jour** | `TasksListTest` | Corrigé |
| §13.3 | Rapports : écrans hors ligne, commandes exécutées, fin de garantie, disponibilité, maintenance | Service `FleetReports`, types PDF11 à PDF15 (PDF, CSV, XLSX), bornés au périmètre, méthode de calcul indiquée dans chaque rapport | `FleetReportsTest::test_each_fleet_report_is_built_from_real_data` ; e2e `screens-reports.spec.ts` (génération réelle par le worker, PDF `%PDF` et XLSX `PK`) | Conforme |
| §13.3, §14 | Rapports accessibles à l'auditeur | Permission `reports.view` acceptée pour les rapports du parc, l'inventaire et le rapport d'incidents (périmètre « rapports ») | `…the_auditor_can_request_fleet_reports_and_inventory` | Conforme |
| §13.3, §17.3 (B-D11) | Durées de conservation paramétrables | Paramètres `retention.receipts_days`, `retention.commands_days`, `retention.states_days` ; purge par `saie:retention` (commandes en cours jamais purgées), simulation par défaut | `…retention_purges_old_receipts_commands_and_states_only_when_applied` | Conforme |
| §13.3 (C-D5) | Exports d'inventaire et d'incidents bornés au périmètre | Réunion des périmètres des permissions détenues | `ScreenInventoryTest::test_the_inventory_report_is_bounded…` | Conforme |
| §14 | Profils « Responsable du parc » et « Superviseur » | Rôles `responsable_parc` (12 permissions, second facteur exigé) et `superviseur` (consultation, rapports) ; correspondance des autres profils : Administrateur SAIE = `admin_fonc`, Administrateur technique = `admin_tech`, Technicien = `technicien`, Gestionnaire de diffusion = `diffuseur`, Auditeur = `auditeur` | `FleetRolesAndHistoryTest::test_the_supervisor_consults_while_the_fleet_manager_administers` | Conforme |
| §14 | Habilitation limitée à un étage ou à un groupe | `role_assignments.floor_id` et `group_id`, intersectés avec site et zone dans `Access::screenScope` ; saisie dans le formulaire d'attribution de rôle | `…assignment_can_be_limited_to_a_floor_or_to_a_group` | Conforme |
| §13.1, §13.2 (C-D8) | Historique complet d'un écran | Service `ScreenHistory`, `GET /screens/{id}/history` : fiche, emplacements, lecteurs, groupes (avant/après), configuration, campagnes transmises, commandes, incidents, états ; filtre par catégorie ; onglet Historique | `…the_screen_history_gathers_every_kind_of_event` | Conforme (membres des groupes dynamiques : recalculés, non journalisés un à un) |
| §13.1 (C-D9) | Avant/après des modifications | Écran, groupe, profil, paramètres : état antérieur journalisé | Revue de code | Partiel (campagnes et listes de lecture : hors module) |

---

## Contrôle final de conformité (8 novembre 2026)

Réévaluation indépendante, en lecture seule, des **266 exigences** de l'audit initial sur le code final (même découpage A, B, C ; même nombre de lignes vérifié). Le suivi ci-dessus était une déclaration : là où le contrôle final le contredit, **c'est le contrôle final qui fait foi** (par exemple : position des écrans sur le plan non saisissable dans l'interface, détection « lecture arrêtée » qui ne distingue pas un navigateur arrêté d'une coupure, purge automatique désactivée par défaut, jeton du lecteur en clair dans le navigateur).

| Périmètre | Exigences | Conformes | Partielles | Absentes | Défectueuses | Dépendance matérielle |
|---|---|---|---|---|---|---|
| A — §4 à §8 | 113 | 84 (initial 26) | 26 (55) | 1 (26) | 0 (6) | 2 |
| B — §9 à §11, §17.3, §18, §21, §23 | 82 | 49 (22) | 28 (40) | 2 (18) | 0 (2) | 3 |
| C — §12 à §15, §19 | 71 | 54 (27) | 17 (30) | 0 (9) | 0 (5) | 0 |
| **Total** | **266** | **187 (70,3 %)** — initial 75 (28,2 %) | **71** — initial 125 | **3** — initial 53 | **0** — initial 13 | **5** |

Taux de conformité final mesuré : **187 / 266 = 70,3 %** (72,2 % en comptant les 5 fonctions implémentées en attente de validation matérielle).

### Contrôle final de conformité A — Gestion des écrans, §4 à §8 (lecture seule)

Date : 2026-11-08. Méthode : relecture du code (backend, frontend, lecteur), `php artisan route:list --path=screen`, requêtes SELECT sur `saie_ceeac` (migrations du 2026-11-08 appliquées ; 35 écrans dont 10 archivés ; 0 écran positionné sur un plan ; 0 niveau avec un plan déposé). Les tests cités ont été lus, pas exécutés.
Abréviations : SC = `backend/app/Http/Controllers/Api/V1/ScreenController.php`, PR = `backend/app/Support/Present.php`, FO = `backend/app/Services/FleetOverview.php`, DG = `backend/app/Services/DynamicGroups.php`, MB = `backend/app/Services/ManifestBuilder.php`, SP = `backend/app/Models/ScreenProfile.php`, EXS = `backend/app/Services/ExportService.php`, MIG = `backend/database/migrations/2026_11_08_074747_extend_screen_inventory_and_location_history.php`, FOV = `frontend/src/pages/display/FleetOverview.tsx`, SCR = `frontend/src/pages/display/Screens.tsx`, SD = `frontend/src/pages/display/ScreenDetail.tsx`, GR = `frontend/src/pages/display/Groups.tsx`, ENG = `player/src/engine.ts`. Tests : `T/` = `backend/tests/Feature/`, `E/` = `frontend/e2e/`.
Statuts : C = Conforme, P = Partiel, A = Absent, D = Défectueux, DM = Dépendance matérielle.

#### §4 Tableau de bord

| Réf | Exigence | Statut initial | Statut final | Preuve | Écart restant |
|---|---|---|---|---|---|
| 4.1-a | Nombre total d'écrans | Défectueux | Conforme | FO:27,40 (hors archivés) ; FOV:42 ; SCR:56 libellé « écrans en service » ; T/FleetOverviewTest::test_indicators_are_computed_from_real_states_and_link_to_filtered_lists (total=4, archivé exclu) | La page Parc (SCR:48) s'appuie encore sur `/dashboard` (écrans `active` seulement, non borné au périmètre) alors que sa liste est bornée : libellés distincts, mais deux sources |
| 4.1-b | En ligne | Conforme | Conforme | FO:41 ; FOV:43 → `?network=online` ; même test (online=2) | — |
| 4.1-c | Hors ligne | Partiel | Conforme | `Device::computedNetworkStatus` (jamais vu = `unavailable`, Device.php:118) ; PR:103 ; FO:42 ; FOV:45 `?state=offline` | Pas d'assertion de test avec un écran réellement hors ligne (offline=0 dans le test) |
| 4.1-d | En anomalie | Défectueux | Conforme | PR:104-106 (`device_id` OU `screen_id`) ; SC:70-76 filtre `state` ; T/ScreenModuleIntegrityTest::test_a_manual_incident_marks_the_screen_in_anomaly_and_old_player_errors_do_not ; T/FleetOverviewTest (`?state=anomaly`) | — |
| 4.1-e | En diffusion (lecture active confirmée) | Absent | Conforme | PR:97,111 (lecture déclarée < 15 min) ; FO:43 ; FOV:44 ; T/SupervisionStatesTest::test_operational_states_distinguish_broadcasting_standby_pause_and_fallback ; T/FleetOverviewTest (broadcasting=1) | « Confirmée » = déclarée par le lecteur ; aucune preuve matérielle de l'image (limite assumée et affichée) |
| 4.1-f | Inactifs | Partiel | Conforme | FO:45 ; FOV:50 `?state=disabled` ; SC:51 filtre `status` ; SCR:132 ; T/FleetOverviewTest (`state=pending_configuration,disabled`) | — |
| 4.1-g | Non configurés | Conforme | Conforme | FO:46 ; FOV:47 ; E/screens-overview.spec.ts (carte « Non configurés » → `state=pending_configuration`) | Mesure toujours la seule absence de lecteur (pas « sans programme ») |
| 4.1-h | Alertes techniques par gravité | Partiel | Conforme | FO:50 `incidents_by_severity` (4 gravités) ; FOV:62-65 ; T/FleetOverviewTest (critical=1) | La carte ouvre `/supervision` sans filtre de gravité |
| 4.2-a | Répartition par bâtiment/étage | Partiel | Conforme | FO:52-53 (tous statuts hors archivés) ; FOV:68-69 barres ; même test (floors) | `by_building` non assertée par un test |
| 4.2-b | Répartition par état | Partiel | Conforme | FO:51 ; FOV:72 | Lignes de répartition non cliquables (les cartes, elles, le sont) |
| 4.2-c | Évolution du taux de disponibilité | Partiel | Conforme | FO:104-119 (télémétrie / créneaux de 5 min, 14 j) ; FOV:76 ; même test (14 points) | Joignabilité du lecteur, pas disponibilité de la dalle ; pas de série par étage |
| 4.2-d | Écrans les plus sollicités | Absent | Conforme | FO:127-137 (top 5 `played`, 7 j) ; FOV:83 ; même test (`most_played`) | — |
| 4.2-e | Historique des incidents | Partiel | Conforme | FO:145-154 (30 j, dont critiques) ; FOV:77 ; même test (30 points) | Pas de ventilation par gravité autre que « critique » |
| 4.2-f | Répartition par type d'écran | Absent | Conforme | FO:54-55 (`by_kind`, `by_technology`) ; FOV:73 | Non assertée par un test |
| 4.2-g | État des lecteurs | Partiel | Conforme | FO:84-95 (versions, en retard, arrêtés) ; FOV:87-95 | Écart d'horloge et erreurs non repris sur la vue (présents en supervision) |
| 4.2-h | Cartes cliquables vers listes filtrées | Défectueux | Conforme | FOV:41-51 ; `account/Dashboard.tsx:53` → `?network=online` ; E/screens-overview.spec.ts | Synoptique de SCR:103-120 toujours bicolore et limité aux écrans `active` (D13 non corrigé) ; le filtre `state` n'a pas de sélecteur dans la liste (URL seulement) |
| 4.3-a | Vue interactive : sélection d'étage | Partiel | Conforme | FOV:150-166 (sélecteur de niveau) ; E/screens-overview.spec.ts (région « Vue par niveau ») | — |
| 4.3-b | Salles/zones et écrans repérés sur plan | Absent | Partiel | Colonnes `plan_x/plan_y` (MIG, CHECK 0–100) ; SC:663 ; FO:163-176 ; FOV:169-178 épingles | Aucun champ de saisie de la position (ni fiche ni import : grep `plan_x` absent du frontend) ; 0 écran positionné et 0 plan en base ; salles listées, non dessinées |
| 4.3-c | États par couleur + libellé/icône | Partiel | Conforme | FOV:29-33 `STATE_MARK` + `StatusOf` (FOV:182-186) | — |
| 4.3-d | Ouverture de la fiche depuis la vue | Partiel | Conforme | FOV:173,182 liens `/affichage/ecrans/:id` | — |
| 4.3-e | Filtre anomalies | Partiel | Conforme | FOV:152-165 « Anomalies seulement » ; SC:70-76 `state=` ; T/FleetOverviewTest (`?state=anomaly`) | — |

#### §5 Inventaire des écrans

| Réf | Exigence | Statut initial | Statut final | Preuve | Écart restant |
|---|---|---|---|---|---|
| 5.1-a | Identifiant unique automatique | Conforme | Conforme | uuid PK (HasUuids) | — |
| 5.1-b | Code d'inventaire | Partiel | Partiel | `screens.code` UNIQUE saisi (SC:633) | Pas de code d'inventaire patrimonial distinct ni de numérotation automatique |
| 5.1-c | Nom | Conforme | Conforme | SC:634 ; SCR:192 | — |
| 5.1-d | Description | Défectueux | Conforme | PR:69 `notes` ; SD:100 (affichage), SD:340 (édition) | Aucun test n'asserte `notes` (revue de code seulement) |
| 5.1-e | Catégorie | Partiel | Partiel | `screens.kind` (3 valeurs) | Pas de référentiel de catégories paramétrable |
| 5.1-f | Statut administratif | Partiel | Conforme | CHECK `archived` (migration 2026_11_08_064856) ; SC:378-402 ; SD:95,99 ; T/ScreenModuleIntegrityTest::test_archiving_withdraws_the_screen_and_keeps_its_record | — |
| 5.1-g | Date d'acquisition | Absent | Conforme | `acquired_on` (MIG) ; SC:657 ; SD:125,336 ; T/ScreenInventoryTest::test_the_full_record_is_stored_and_each_move_is_historised | — |
| 5.1-h | Date de mise en service | Conforme | Conforme | `installed_on` ; SD:117 | Libellé « Installation » |
| 5.1-i | Fournisseur | Absent | Conforme | `supplier` (MIG) ; SD:126,337 ; même test | — |
| 5.1-j | Référence de commande | Absent | Conforme | `order_reference` ; SD:126,338 ; même test | — |
| 5.1-k | Garantie | Conforme | Conforme | PR:75,78 ; SD:117 | — |
| 5.1-l | Documents associés | Absent | Conforme | table `screen_documents` (MIG) ; SC:321-344 ; SD:174-215 ; T/ScreenInventoryTest::test_documents_can_be_linked_to_a_screen | — |
| 5.2-a | Fabricant/marque/modèle | Conforme | Conforme | PR:73 ; SD:111 | Marque et fabricant confondus |
| 5.2-b | N° de série | Partiel | Partiel | `serial_number` ; SD:112,315 | Toujours aucune unicité (index absent en base) |
| 5.2-c | Taille (pouces) | Conforme | Conforme | SC:653 | — |
| 5.2-d | Résolution | Conforme | Conforme | SC:639 | Écart avec `devices.reported_resolution` jamais affiché |
| 5.2-e | Orientation | Conforme (inventaire) | Conforme | SC:638 | Application : voir 7.1-b |
| 5.2-f | Technologie (LED/LCD/OLED) | Absent | Conforme | `technology` + CHECK (MIG) ; SD:118,324 ; T/ScreenInventoryTest::test_the_full_record_is_stored_and_each_move_is_historised | — |
| 5.2-g | Connectivité/interfaces | Partiel | Partiel | `connection_type`, `control_channel` | Interfaces physiques (HDMI, DP, USB…) toujours non décrites |
| 5.2-h | Adresse MAC | Absent | Conforme | `mac_address` + CHECK de format, normalisation SC:676-678 ; SD:120,328 ; même test (normalisation asserte) | Saisie manuelle ; remontée automatique seulement via agent local (`device_info.mac`) |
| 5.2-i | Adresse IP | Partiel | Partiel | `ip_address` saisi (SC:653) | IP observée non remontée par le battement (aucun `request()->ip()` dans DeviceApiController) |
| 5.2-j | OS | Partiel | Conforme | `os_name` (MIG) ; SD:121,329 ; même test ; `devices.platform` déclaré | Saisie manuelle |
| 5.2-k | Firmware | Partiel | Conforme | `firmware_version` (MIG) ; SD:121,330 ; même test | Saisie manuelle |
| 5.2-l | Mode de fonctionnement | Partiel | Partiel | `kind`, `operating_window`, `touch_enabled` | Toujours aucun champ explicite (24/7, plage, à la demande) |
| 5.2-m | Consommation | Absent | Conforme | `power_watts` (MIG) ; SD:119,327 ; même test | — |
| 5.3-a | Bâtiment | Partiel | Conforme | PR:61 ; SD:127 ; SCR liste via `location.floor.building` (SC:47) ; T/ScreenInventoryTest::test_the_inventory_report_is_bounded_to_the_scope_and_can_target_a_floor (colonne Bâtiment) | `data.location.building` non assertée par un test d'API |
| 5.3-b | Étage | Conforme | Conforme | PR:60 ; SD:103 | — |
| 5.3-c | Zone | Conforme | Conforme | PR:63 ; SD:104 | Zone de diffusion, pas zone spatiale |
| 5.3-d | Salle | Partiel | Conforme | SC:637 (facultative hors `room_panel`), SC:679-687 `ROOM_FLOOR_MISMATCH` ; SCR:208-215 ; T/ScreenInventoryTest::test_the_full_record_is_stored_and_each_move_is_historised (422) | — |
| 5.3-e | Emplacement précis | Partiel | Conforme | `location_detail` ; SD:127,339 ; même test | — |
| 5.3-f | Type d'espace | Partiel | Partiel | PR:62 renvoie `location.type` | Non affiché sur la fiche (SD:96-128 ne l'utilise pas) |
| 5.3-g | Responsable de l'espace | Partiel | Partiel | `locations.manager_id` (MIG) ; saisie `admin/Referentials.tsx:35` ; PR:62 (identifiant seul) | Non affiché sur la fiche écran ; aucun test |
| 5.3-h | Photo de l'emplacement | Absent | Partiel | `locations.photo_asset_version_id` (MIG) | Aucune saisie ni affichage dans l'interface (le suivi le reconnaît) |
| 5.3-i | Référence sur plan | Absent | Partiel | `plan_x/plan_y` (MIG, SC:663, PR:70) ; T/ScreenInventoryTest (plan.x=42.5) | Aucune saisie dans l'interface ni dans l'import CSV ; 0 écran positionné en base |
| 5.4-a | Lecteur associé | Conforme | Conforme | index `devices_one_active_per_screen` ; onglet Lecteur | — |
| 5.4-b | Groupe | Conforme | Conforme | PR:66 ; SD:106 | Édition depuis Groupes seulement |
| 5.4-c | Profil de configuration | Absent | Conforme | `screens.profile_id` ; `Screen::effectiveProfile` ; SC:115-124 ; SD:341 ; T/ScreenProfilesTest::test_the_screen_profile_overrides_the_group_profile_and_reaches_the_manifest | — |
| 5.4-d | Playlist/campagne affectées | Conforme | Conforme | SC:404-419 `preview` ; onglet Programme | Playlists via campagnes seulement |
| 5.4-e | Calendrier | Partiel | Conforme | PR:54 `effectiveOperatingWindow()` + `window_source` ; Screen.php:75-85 | Aucun test n'asserte `window_source` (revue de code) |
| 5.4-f | Priorité | Partiel | Partiel | `campaigns.priority` | Pas de priorité/criticité propre à l'écran |
| 5.4-g | Dernière communication | Conforme | Conforme | PR:126 `last_seen_at` ; SCR:154 | — |
| 5.4-h | Dernière diffusion confirmée | Partiel | Partiel | SD:423 « Lecture en cours (déclarée) » ; historique `ScreenHistory` (catégorie diffusion) | Pas d'horodatage `last_played_at` exposé ni affiché |
| 5.5-a | Création | Conforme | Conforme | SC:130-145 ; SCR:230-261 | — |
| 5.5-b | Modification | Défectueux (sécurité) | Conforme | SC:163-172 `assertStillInScope` dans la transaction ; T/ScreenModuleIntegrityTest::test_a_zone_manager_cannot_reactivate_or_move_a_screen_outside_the_zone | — |
| 5.5-c | Consultation | Partiel | Conforme | SC:46-49 (liste), SC:101 (fiche) ; T/ScreenModuleIntegrityTest::test_a_zone_manager_lists_only_the_screens_of_the_zone (liste + 403 sur fiche) | — |
| 5.5-d | Archivage | Absent | Conforme | `POST /screens/{id}/archive` (route:list) ; SC:378-402 ; SD:137 ; T/ScreenModuleIntegrityTest::test_archiving_withdraws_the_screen_and_keeps_its_record ; E/screens-inventory.spec.ts | — |
| 5.5-e | Activation/désactivation | Conforme | Conforme | SC:648 ; PR:100 | — |
| 5.5-f | Import Excel/CSV | Absent | Partiel | `POST /screens/import` + modèle (SC:186-301) ; `ImportScreensDialog.tsx` ; T/ScreenInventoryTest::test_csv_import_simulates_without_writing_refuses_invalid_files_and_commits_atomically | CSV seulement (`mimes:csv,txt`, SC:189) : Excel non accepté ; pas de colonnes groupe, profil ni position |
| 5.5-g | Export inventaire | Partiel | Conforme | EXS:55 (pdf/csv/xlsx), EXS:352-353 (18 colonnes, bâtiment, statut, garantie…) ; sélecteur de format `admin/common.tsx:191` ; T/ScreenInventoryTest::test_the_inventory_report_is_bounded_to_the_scope_and_can_target_a_floor | — |
| 5.5-h | Recherche multicritère | Partiel | Partiel | SC:51-76 (`status`, `kind`, `floor_id`, `zone_id`, `group_id`, `q`, `network`, `state`) ; SCR:126-133 | Pas de critère salle, bâtiment, marque ou n° de série ; `q` ne cherche que code et nom ; pas de sélecteur `state` |
| 5.5-i | Filtres étage/salle/type/statut | Partiel | Partiel | étage, type, statut (SCR:127,131,132) | Filtre salle toujours absent (API et interface) |
| 5.5-j | Impression des fiches | Absent | Partiel | SD:241-246 `window.print()` ; `app.css:138` `@media print` | Bouton présent seulement après génération d'un QR ; pas de fiche PDF (`screen_sheet`) ; aucun test |
| 5.5-k | QR Code par écran (technicien autorisé) | Absent | Conforme | SC:306-315 ; `access_links` type `screen` ; SD:222-250 ; T/ScreenInventoryTest::test_the_screen_qr_code_opens_the_record_for_authorised_accounts_only | — |

#### §6 Localisation

| Réf | Exigence | Statut initial | Statut final | Preuve | Écart restant |
|---|---|---|---|---|---|
| 6-a | Hiérarchie Institution→Bâtiment→Étage→Zone/Salle→Écran paramétrable | Partiel | Partiel | sites→buildings→floors→locations→screens ; `screens.room_id` contrôlé au même niveau (SC:679-687) | Pas de niveau Institution ; salle toujours parallèle à l'emplacement ; niveaux figés |
| 6-b | CRUD bâtiments | Conforme | Conforme | ReferentialController ; Referentials.tsx | Pas de suppression (désactivation) |
| 6-c | CRUD étages | Conforme | Conforme | idem | idem |
| 6-d | CRUD salles | Partiel | Conforme | `pages/rooms/NewRoomDialog.tsx:38` ; `Rooms.tsx:50` ; T/SettingsEffectTest (POST /rooms) | Création réservée à `admin_fonc` ; pas d'e2e de la boîte |
| 6-e | CRUD emplacements | Conforme | Conforme | Referentials | — |
| 6-f | Associer/déplacer un écran | Défectueux (sécurité) | Conforme | SC:163-172 ; T/ScreenModuleIntegrityTest::test_a_zone_manager_cannot_reactivate_or_move_a_screen_outside_the_zone | — |
| 6-g | Historique des déplacements | Partiel | Conforme | table `screen_location_history` (MIG) ; SC:695-702 ; SC:108-112 ; SD:153-170 ; T/ScreenInventoryTest::test_the_full_record_is_stored_and_each_move_is_historised ; E/screens-inventory.spec.ts | — |
| 6-h | Nombre d'écrans par emplacement | Partiel | Partiel | Comptes par niveau et bâtiment (FO:52-53) | Aucun décompte par emplacement ni par salle (aucun `withCount` dans Referentials) |
| 6-i | Plan par étage | Partiel | Partiel | FOV:169-179 affiche le plan avec épingles | Épingles affichées seulement si des écrans sont positionnés, ce qui est impossible depuis l'interface ; 0 plan en base |
| 6-j | Un seul emplacement actif ; tout changement historisé | Partiel | Conforme | index unique partiel `screen_location_history_one_current` (MIG) ; audit `screen.moved` (SC:174) ; même test (assertion « un seul emplacement actif ») | — |

#### §7 Configuration technique

| Réf | Exigence | Statut initial | Statut final | Preuve | Écart restant |
|---|---|---|---|---|---|
| 7.1-a | Résolution | Partiel | Partiel | `screens.resolution` → manifeste | Informative : non pilotable par le lecteur, non comparée à `reported_resolution`, non signalée « non pilotée » |
| 7.1-b | Orientation | Partiel | Partiel | `player/src/views.tsx:153-158` mise en page portrait | Aucune rotation de l'affichage ; non signalée « non pilotée » (D16 partiellement traité) |
| 7.1-c | Luminosité | Absent | Dépendance matérielle | SP:30 (capacité `panel_picture`) ; ENG:425-426 → `unsupported` ; T/ScreenProfilesTest::test_the_player_confirms_the_applied_configuration_and_reports_unsupported_settings | Pilote d'image de la dalle indisponible (ni navigateur ni agent actuel) |
| 7.1-d | Contraste | Absent | Dépendance matérielle | SP:31 ; ENG:425-426 | idem ; pas de test spécifique au contraste |
| 7.1-e | Volume | Partiel | Conforme | SP:22 `volume_max` ; MB:147 (plafond) ; T/ScreenProfilesTest::test_the_screen_profile_overrides_the_group_profile_and_reaches_the_manifest (max_volume=10) | — |
| 7.1-f | Veille et durée d'inactivité | Partiel | Partiel | SP:28 `standby_outside_window` (capacité `panel_power`, ENG:427) ; plage `wake_before_min` | Aucune veille sur durée d'inactivité ; veille de la dalle dépend de l'agent CEC/RS-232 (simulation seulement) |
| 7.1-g | Plein écran | Partiel | Conforme | SP:29 ; ENG:428,436-440 (demande + compte rendu) ; E/screens-profiles.spec.ts | Refus possible du navigateur hors kiosque (alors signalé non appliqué) |
| 7.1-h | Fréquence de rafraîchissement | Partiel | Conforme | SP:25-26 ; ENG:168,177 | `next_heartbeat_s` du serveur reste global (DeviceApiController) |
| 7.1-i | Fuseau horaire | Partiel | Partiel | `config('saie.timezone')` (MB:119,135) | `sites.timezone` toujours ignoré (D17) |
| 7.1-j | Langue | Absent | Conforme | SP:23-24 ; `player/src/Player.tsx:99-100` ; T/ScreenProfilesTest (languages fr, pt) | Langues des vues non programmées seulement |
| 7.2-a | IP / DHCP / fixe | Partiel | Partiel | `ip_address` saisi | Ni mode d'adressage ni IP observée |
| 7.2-b | MAC | Absent | Conforme | `screens.mac_address` (voir 5.2-h) ; `device_info.mac` accepté au battement | Remontée automatique dépendante de l'agent local |
| 7.2-c | Serveur | Absent | Absent | ENG:10 `API = '/api/v1/device'` (même origine) | Non paramétrable depuis la console |
| 7.2-d | Fréquence de synchronisation | Partiel | Conforme | SP:25 `sync_interval_s` ; ENG:177 ; E/screens-profiles.spec.ts | — |
| 7.2-e | Intervalle de remontée d'état | Partiel | Conforme | SP:26 `heartbeat_interval_s` ; ENG:168 | — |
| 7.2-f | Politique de reconnexion | Absent | Conforme | SP:27 `reconnect_max_s` ; ENG:178 (espacement exponentiel plafonné) | Pas de test backend dédié (e2e seulement) |
| 7.2-g | Authentification du lecteur | Conforme | Conforme | SC:424-463 ; rotation du jeton ; T/DeviceTokenRotationTest | — |
| 7.3-a | Profils réutilisables | Absent | Conforme | table `screen_profiles` ; `ScreenProfileController` ; `ScreenProfiles.tsx` ; T/ScreenProfilesTest (4 tests) ; E/screens-profiles.spec.ts | — |
| 7.3-b | Affectables à un écran ou un groupe | Partiel | Conforme | `screens.profile_id`, `screen_groups.profile_id` ; SD:341 ; GR:178 ; T/ScreenProfilesTest::test_the_screen_profile_overrides_the_group_profile_and_reaches_the_manifest | — |
| 7.3-c | Paramètres non supportés signalés « indisponibles » | Partiel | Conforme | SP:21-32 (capacité par paramètre) ; ENG:419-433 ; SD:525 « non pris en charge par ce lecteur » ; T/ScreenProfilesTest::test_the_player_confirms_the_applied_configuration_and_reports_unsupported_settings | Résolution et orientation hors profil, donc non signalées (7.1-a/b) |
| 7.3-d | État de synchronisation + confirmation d'application | Partiel | Conforme | SC:115-124 (`applied` / `pending` / `unknown`) ; SD:513-525 ; même test | État affiché sur la fiche seulement (pas dans la liste) ; pas d'alerte de divergence prolongée |

#### §8 Groupes

| Réf | Exigence | Statut initial | Statut final | Preuve | Écart restant |
|---|---|---|---|---|---|
| 8-a | CRUD groupes | Conforme | Conforme | SC:516-595 ; GR ; T/ScreenModuleIntegrityTest::test_an_empty_group_of_another_structure_cannot_be_deleted_by_a_zone_manager | La liste `GET /screen-groups` (SC:516-535) n'est pas bornée au périmètre |
| 8-b | Ajout/retrait d'écrans | Conforme | Conforme | SC:550,572-574 | Sélecteur plafonné à 500 écrans (`shared.tsx:145`, D18) |
| 8-c | Composition | Conforme | Conforme | SC:519-532 | — |
| 8-d | Affecter une playlist/campagne au groupe | Conforme (campagne) / Partiel (playlist) | Conforme | `campaign_targets.target_type='group'` ; MB:191-198 ; E/screens-scenarios.spec.ts (scénario D, diffusion par groupe) | Playlist affectée via une campagne seulement |
| 8-e | Configuration commune | Partiel | Conforme | profil de groupe (`screen_groups.profile_id`) ; GR:146 conserve `ignore_holidays` ; T/ScreenProfilesTest::test_the_screen_profile_overrides_the_group_profile_and_reaches_the_manifest | Conservation de `ignore_holidays` non couverte par un test |
| 8-f | Statistiques du groupe | Partiel | Partiel | SC:527-533 (en ligne, en anomalie, sans lecteur, campagnes actives) ; GR:51 ; T/DynamicGroupsTest::test_group_listing_reports_member_statistics | Pas de taux de disponibilité, de diffusion ni de nombre d'incidents par groupe |
| 8-g | Supervision des membres | Partiel | Conforme | SupervisionController:102 `by_group` ; `admin/Supervision.tsx:141` ; T/SupervisionStatesTest::test_heartbeat_records_declared_characteristics_and_supervision_groups_by_floor_and_group | La modale « Cibles effectives » (GR:261-292) reste réseau seul, calculée côté client sur 500 écrans |
| 8.3 | Groupes dynamiques par critères, recalculés | Absent | Conforme | `screen_groups.type/criteria` ; DG:18-60 (bâtiment, niveau, zone, salle, type, statut, réseau, campagne) ; recalcul planifié ; GR:222-231 ; T/DynamicGroupsTest (4 tests) ; E/screens-groups.spec.ts | Réservé aux habilitations sans restriction ; pas de critère « état opérationnel » (anomalie) |

#### Décompte

Nombre de lignes : **113** (§4 : 21 ; §5 : 53 ; §6 : 10 ; §7 : 21 ; §8 : 8), identique à audit-A.md.

| Statut | Initial | Final |
|---|---|---|
| Conforme | 26 | 84 |
| Partiel | 55 | 26 |
| Absent | 26 | 1 |
| Défectueux | 6 | 0 |
| Dépendance matérielle | 0 | 2 |
| **Total** | **113** | **113** |

Règles de comptage du statut initial : « Conforme (inventaire) » compté Conforme ; « Défectueux (sécurité) » compté Défectueux ; « Conforme (campagne) / Partiel (playlist) » (8-d) compté Conforme. Ce comptage retrouve le bilan initial de la matrice (26 / 55 / 26 / 6).

Final par section : §4 20 C / 1 P ; §5 37 C / 16 P ; §6 7 C / 3 P ; §7 13 C / 5 P / 1 A / 2 DM ; §8 7 C / 1 P.

Affirmations du « Suivi des corrections » non confirmées par le code :
- §4.3 « Conforme (plans réels à déposer) » : la position des écrans sur le plan ne peut être saisie ni dans l'interface ni par import. La vue sur plan est donc inatteignable sans appel direct à l'API.
- §5.3 « Conforme » pour bâtiment et type d'espace : le type d'espace et le responsable sont renvoyés par l'API mais ne sont pas affichés sur la fiche.
- §5.5 « Conforme » pour l'import et l'impression : l'import n'accepte que le CSV (pas Excel), et l'impression n'est proposée qu'une fois le QR généré.

### Contrôle final B : lecteurs, supervision, commandes, protocole, sécurité (SAIE-CEEAC)

Contrôle en lecture seule du 8 novembre 2026. Les 82 lignes de `audit-B.md` ont été reprises une à une, sans regroupement. Le suivi des corrections de `docs/AUDIT-GESTION-ECRANS/02-MATRICE-CONFORMITE.md` a été vérifié dans le code, en base (SELECT, `pg_constraint`) et avec `php artisan route:list`.
Abréviations : DAC = `backend/app/Http/Controllers/Api/V1/DeviceApiController.php`, SC = `ScreenController.php`, RCS = `backend/app/Services/RemoteCommands.php`, PR = `backend/app/Support/Present.php`, TICK = `backend/app/Console/Commands/SaieTick.php`, ENG = `player/src/engine.ts`, AG = `player/agent/panel-agent.mjs`. Tests : RCT = `RemoteCommandsTest`, SST = `SupervisionStatesTest`, FIT = `FleetAndIncidentTest`, SMI = `ScreenModuleIntegrityTest`, IWT = `IncidentWorkflowTest`, DT = `DeviceTest`, tous dans `backend/tests/Feature/`. e2e = `frontend/e2e/`.
Statuts : C = Conforme, P = Partiel, A = Absent, D = Défectueux, DM = Dépendance matérielle (le logiciel est en place, mais la validation exige un matériel ou un pilote ; l'agent local n'a été essayé qu'en simulation).

#### §9 Lecteurs

| Réf | Exigence | Statut initial | Statut final | Preuve | Écart restant |
|---|---|---|---|---|---|
| 9.1 | Distinction écran physique / lecteur | C | C | Tables `screens` et `devices`, onglets séparés et historique des lecteurs (ScreenDetail). `FleetRolesAndHistoryTest::test_the_screen_history_gathers_every_kind_of_event` | — |
| 9.2 | Infos lecteur (id, nom, type, fabricant, série, IP, MAC, OS, version, firmware…) | P | P | `devices.hardware_info` (définition, cœurs, mémoire, langue, fuseau, tactile) rempli au heartbeat (DAC l.125-144, ENG `deviceInfo()`). MAC, OS et firmware saisis sur la fiche **écran** (`screens.mac_address/os_name/firmware_version`). `SST::test_heartbeat_records_declared_characteristics_and_supervision_groups_by_floor_and_group` | Le lecteur n'a ni nom, ni type matériel, ni numéro de série propre. L'API accepte `device_info.mac/firmware/hostname`, mais AG ne les envoie pas. `hardware_id` reste un UUID logiciel. |
| 9.3 | Enrôler un lecteur | C | C | `POST device/pairing-codes`, `POST devices/pair`. `DT::test_one_time_pairing_flow_delivers_individual_token_once` | — |
| 9.4 | Associer / désassocier | P | P | La seule association reste l'appairage avec `replace` (aucune route de réaffectation dans `route:list`). | Pas de réaffectation d'un lecteur vers un autre écran, pas de désassociation sans révocation. |
| 9.5 | Code d'appairage à durée limitée | C | C | TTL `pairing_code_ttl_min`, invalidation des codes précédents (DAC l.41). `DT::test_expired_pairing_code_is_refused` | — |
| 9.6 | Renouveler les identifiants | P | P | Rotation lancée par le lecteur (`device/token/rotate`). `DeviceTokenRotationTest::test_rotated_token_works_and_the_previous_one_expires_after_the_overlap` | Pas de « renouvellement forcé » côté administration : le catalogue RCS ne contient pas de commande de rotation. |
| 9.7 | Révoquer | C | C | `devices/{id}/revoke`, `EnsureDevice`. `DT::test_revoked_device_is_refused_by_the_api` | Cache lisible jusqu'à 24 h (comportement documenté). |
| 9.8 | Mettre à jour l'application | P | P | Canaux pilot/stable, manifeste signé, règle `outdated_version` (TICK l.248). `PlayerReleaseTest::test_channels_rollback_and_manifest_target` | Pas de mise à jour ciblée d'un lecteur précis (pas de commande `update`). Pas d'état de déploiement par lecteur (succès ou échec). |
| 9.9 | Redémarrer l'application | P | C | Commande `restart_player` : `pending_restart` puis `confirmRestart()` acquitte après la relance effective (ENG l.491-508, l.552). Alias `/reload` passé dans la file. `FIT::test_remote_reload_is_queued_redelivered_until_acknowledged_and_traced` ; e2e `screens-commands.spec.ts` | Il s'agit d'un rechargement de la page : le processus navigateur n'est pas relancé. |
| 9.10 | Synchroniser à la demande | A | C | Commande `sync` (RCS, ENG `case 'sync'`). `RCT::test_a_command_goes_through_sent_received_and_executed_with_a_report` ; e2e `screens-commands.spec.ts` | — |
| 9.11 | Vérifier l'espace disque | P | P | Toujours `navigator.storage.estimate()` (quota de l'origine). AG ne mesure pas le disque. | Le disque du système n'est pas mesuré. Pas de vérification à la demande. |
| 9.12 | Consulter les journaux techniques | P | P | Commande `logs` : les 50 dernières erreurs vont dans `result_data` et s'affichent dans la boîte « Détail » (`CommandsPanel.tsx` l.125). | Seules les erreurs en mémoire du lecteur remontent. Pas de vue paginée des receipts `error/fallback/offline` ni de la télémétrie. Pas de journaux système ou navigateur. |
| 9.13 | Détecter les versions obsolètes | P | C | Règle `outdated_version` (TICK l.248), compteur `outdated` de `FleetOverview` (l.92). `SST::test_stalled_playback_repeated_errors_and_outdated_versions_open_incidents` ; `FleetOverviewTest::test_indicators_are_computed_from_real_states_and_link_to_filtered_lists` | Aucun indicateur `is_outdated` sur la fiche lecteur (`Present::device`). |
| 9.14 | Enrôlement sécurisé | P | P | Le code, `poll_secret` haché et la remise unique sont inchangés. `issued_token` est chiffré (cast `encrypted`, `PairingCode.php` l.33). | `requestCode` reste ouvert sans authentification. `hardware_id` est auto-déclaré. Pas de preuve de possession de clé ni de liste blanche. |

#### §10 Supervision

| Réf | Exigence | Statut initial | Statut final | Preuve | Écart restant |
|---|---|---|---|---|---|
| 10.1 | En ligne / hors ligne, délai configurable | P | C | Paramètres `supervision.suspect_after_s` et `offline_after_s` (en base : 45 et 300), texte de l'incident dérivé du délai. `SST::test_offline_delay_is_a_setting_and_must_exceed_the_suspect_delay` | — |
| 10.2 | État « En diffusion » | A | C | PR l.111 : lecture déclarée de moins de 15 min. `SST::test_operational_states_distinguish_broadcasting_standby_pause_and_fallback` | La lecture est **déclarée** par le lecteur, sans preuve matérielle de l'image. |
| 10.3 | État « En veille » | A | C | PR l.109 : dalle en standby/off ou lecteur hors plage (`mode=off`). Même test SST. | — |
| 10.4 | État « En anomalie » | P | C | Seules les erreurs de moins de 10 min comptent (`Device::recentErrors()`), incident ouvert sur le lecteur ou l'écran. `SMI::test_a_manual_incident_marks_the_screen_in_anomaly_and_old_player_errors_do_not` | — |
| 10.5 | État « Non configuré » | C | C | PR l.102. `FIT::test_screen_hardware_record_and_operational_states` | — |
| 10.6 | État « En maintenance » | C | C | PR l.101. `FIT::test_maintenance_status_requires_a_reason_and_keeps_the_screen_targeted` | — |
| 10.7 | État « Désactivé » | P | P | L'écran `inactive` donne `disabled`. Une commande `stop_playback` donne l'état `paused`. | La contrainte `devices_status_check` ne connaît toujours que `active` et `revoked` : un lecteur ne peut pas être suspendu. |
| 10.8 | Distinction état lecteur / écran / preuve de diffusion | P | DM | La liste des écrans affiche trois colonnes séparées, « État », « Réseau » et « Dalle » (`Screens.tsx` l.141-153). La supervision affiche l'état réseau par lecteur. | Sans canal CEC/RS-232, la dalle reste « non mesurée » : non validable sans matériel. La preuve de diffusion reste fusionnée dans « État ». |
| 10.9a | Connexion et dernière activité | C | C | `last_seen_at`, `last_sync_at` (DAC l.137, l.213-220). `DT::test_deferred_receipts_are_deduplicated_and_heartbeat_tracks_clock_skew` | — |
| 10.9b | Contenu en cours | P | P | `current_playback` = `{entry_id, lang, at}` (ENG l.636). L'interface cherche `title`, sans résultat. | `entry_id` n'est toujours pas résolu en titre de contenu ou de campagne. |
| 10.9c | Campagne / playlist active | P | P | `campaign_id` n'est porté que par les receipts `played` (ENG l.670), pas par `playback`. | L'état courant ne déclare pas la campagne. |
| 10.9d | Disque | P | P | Quota navigateur (voir 9.11). | Pas de mesure du disque. |
| 10.9e | Mémoire, CPU, processus, température | A | A | Le heartbeat ne contient aucun de ces champs (`memory_gb` est la RAM totale déclarée, pas son usage). AG ne mesure rien. | Absent. |
| 10.9f | Erreurs de lecture | P | P | Erreurs horodatées, fenêtre de 10 min, règle `playback_errors`. | Erreurs en texte libre, sans code ni `entry_id`/`asset_id`. |
| 10.9g | Synchronisation | C | C | `boundedVersion` (DAC l.230). `DT::test_manifest_is_signed_versioned_and_conditional` | — |
| 10.10 | Historique des états | P | C | Table `screen_status_history` (TICK `recordScreenStates`), `GET screens/{id}/status-history`, onglet Historique. `SST::test_the_scheduler_keeps_one_period_per_state_change` | La télémétrie brute n'est toujours pas consultable dans l'interface. |
| 10.11 | Alerte perte de connexion | C | C | TICK l.113-115. `OperationsMonitoringTest::test_unavailable_player_opens_an_incident_notified_to_incident_managers` | — |
| 10.12 | Alerte absence prolongée | P | C | `IncidentPolicy::escalateOverdue` : échéance par gravité, niveau d'escalade, nouvelle notification. `IWT::test_automatic_incidents_go_to_the_default_assignee_and_overdue_ones_are_escalated` | L'escalade porte sur l'absence de prise en charge. La gravité n'augmente pas avec la durée de l'absence. |
| 10.13 | Alerte échec de synchronisation | C | C | Règle `sync_stalled` (TICK l.239). `FIT::test_player_anomalies_open_then_resolve_automatic_incidents` | — |
| 10.14 | Alerte échec de lecture | A | C | Règle `playback_errors`, de gravité moyenne, qui compte les receipts `error` sur 1 h (TICK l.233, l.245). `SST::test_stalled_playback_repeated_errors_and_outdated_versions_open_incidents` | Les receipts `fallback` ne sont pas comptés. |
| 10.15 | Alerte stockage insuffisant | P | P | Règle `storage_full` (TICK l.235). | Elle repose toujours sur le quota navigateur, pas sur le disque. |
| 10.16 | Alerte Player arrêté | A | P | Règle `player_stalled` : le lecteur est joignable mais ne déclare plus de lecture (TICK l.242). Testée dans le même test SST. | Un navigateur arrêté reste indiscernable d'une perte réseau : il manque un heartbeat propre à l'agent local. |
| 10.17 | Alerte version obsolète | A | C | Règle `outdated_version`. Même test SST. | — |
| 10.18 | Alerte écran déclaré défectueux | P | P | Incident manuel `category=panel`. | Pas de règle « dalle éteinte pendant la plage ». Pas de lien automatique avec `screens.status`. |
| 10.19 | Alerte anomalie de programmation | P | P | État `fallback` affiché (PR l.112). | Aucun incident pour un repli prolongé, un écran sans programme ou un écran qu'aucune campagne ne cible. |
| 10.20 | Alerte : date, équipement, gravité (4 niveaux), statut, responsable | D | C | La contrainte `incidents_severity_check` inclut `medium` (vérifié en base), aussi dans les filtres et formulaires (`Supervision.tsx` l.358). Responsable par défaut des incidents automatiques. `SMI::test_incidents_are_bounded_to_the_zone_and_resolution_requires_a_result` ; `IWT::test_automatic_incidents_go_to_the_default_assignee_and_overdue_ones_are_escalated` | — |
| 10.21 | Supervision par étage et par groupe | P | C | `by_floor` et `by_group` avec filtre au clic (`Supervision.tsx` l.140-141). `SST::test_heartbeat_records_declared_characteristics_and_supervision_groups_by_floor_and_group` | — |
| 10.22 | Rafraîchissement temps réel | P | P | Interrogation périodique toutes les 15 s pour la supervision, toutes les 5 s pour une commande en cours. | Ni WebSocket ni SSE. La liste et la fiche écran ne se rafraîchissent pas seules. |

#### §11 Commandes à distance

| Réf | Exigence | Statut initial | Statut final | Preuve | Écart restant |
|---|---|---|---|---|---|
| 11.1 | Synchroniser | A | C | `sync` dans la contrainte `device_commands_command_check`, RCS et ENG. `RCT::test_a_command_goes_through_sent_received_and_executed_with_a_report` ; e2e `screens-commands.spec.ts` | — |
| 11.2 | Actualiser | P | C | `refresh` est distincte de `restart_player` (ENG l.482). Mêmes tests. | — |
| 11.3 | Redémarrer Player | P | C | `restart_player`, exécution confirmée après la relance (ENG `confirmRestart`). `FIT::test_remote_reload_is_queued_redelivered_until_acknowledged_and_traced` ; e2e | Rechargement de la page : le processus navigateur n'est pas relancé. |
| 11.4 | Redémarrer l'appareil | A | DM | `reboot_device` est proposé si AG le déclare (`SAIE_REBOOT_CMD`). La confirmation arrive au retour du lecteur. | Essayé seulement en simulation : l'appareil n'a pas réellement redémarré. |
| 11.5 | Arrêter / reprendre la diffusion | A | C | `stop_playback`/`resume_playback`, `devices.playback_paused`. `RCT::test_stopping_playback_requires_a_reason_and_is_reflected_once_confirmed` | — |
| 11.6 | Mettre en veille / sortir de veille | A | DM | `standby`/`wake` exigent la capacité `panel_power`, relayée par AG (`/panel`). L'état relu est renvoyé dans le compte rendu. | AG n'a fonctionné qu'avec le pilote `simulate`. CEC et RS-232 ne sont pas validés. |
| 11.7 | Capturer l'écran | C | C | Commande `screenshot` liée à `device_screenshots`. L'échec remonte en `failed`. `RCT::test_a_screenshot_command_links_the_capture_request` | Capture du DOM (voir 21.1). |
| 11.8 | Consulter les journaux (commande) | A | C | Commande `logs`, compte rendu affiché dans `CommandsPanel.tsx`. e2e `screens-commands.spec.ts` | Contenu limité aux erreurs du lecteur (voir 9.12). |
| 11.9 | Autorisation par profil | C | C | Permission `devices.command` et périmètre. `RCT::test_commands_are_restricted_by_permission_scope_and_device` | — |
| 11.10 | Envoi sécurisé | P | P | Remise lors du heartbeat authentifié (Sanctum, ability `device`). | Les commandes ne sont pas signées (seul le manifeste l'est en Ed25519). TLS dépend de l'infrastructure. |
| 11.11 | Horodatage et utilisateur | P | C | `device_commands.requested_by/at`, `sent_at`, `received_at`, `completed_at`. Historique `GET devices/{id}/commands`. Tests RCT. | — |
| 11.12 | Identifiant de transaction | A | C | UUID `device_commands.id`, renvoyé dans l'accusé `device/commands/{id}/ack`. `RCT::test_a_command_goes_through_sent_received_and_executed_with_a_report` | — |
| 11.13 | Sept états | A | C | La contrainte `device_commands_status_check` couvre les 7 états (vérifié en base). `RCT::test_an_unconfirmed_command_expires_and_a_pending_one_can_be_cancelled` | — |
| 11.14 | Compte rendu | A | C | `result_message` et `result_data` (32 Kio au plus). `RCT::test_a_command_goes_through_sent_received_and_executed_with_a_report` | — |
| 11.15 | « Exécutée » seulement après confirmation | D | C | Une commande `sent` est renvoyée jusqu'à l'accusé, puis expire sans être présumée exécutée (RCS `deliver`, `expireOverdue`). `FIT::test_remote_reload_is_queued_redelivered_until_acknowledged_and_traced` | — |
| 11.16 | Capacités vérifiées avant de proposer | A | C | `devices.capabilities` déclarées au heartbeat. Le serveur refuse avec `COMMAND_UNAVAILABLE`. `RCT::test_an_unsupported_command_is_refused_with_its_reason`, `RCT::test_a_player_that_never_declared_capabilities_receives_no_command` | Les capacités sont déclarées par le lecteur, pas attestées. |
| 11.17 | Commandes indisponibles désactivées avec explication | P | C | `availability()` renvoie `available` et `reason`. Un lecteur révoqué est refusé, y compris par l'alias `/screenshot` (SC l.496-501). Mêmes tests RCT ; capture `commandes-a-distance.png`. | — |

#### §17.3 Protocole et échanges

| Réf | Exigence | Statut initial | Statut final | Preuve | Écart restant |
|---|---|---|---|---|---|
| 17.1 | Heartbeat | C | C | `POST device/heartbeat`, `next_heartbeat_s`. `DT::test_deferred_receipts_are_deduplicated_and_heartbeat_tracks_clock_skew` | — |
| 17.2 | Exécution asynchrone | P | C | Remise lors du heartbeat, puis accusé asynchrone `received` → `executed`/`failed`. Tests RCT. | — |
| 17.3 | File de commandes | A | C | Table `device_commands`, remise dans l'ordre de `requested_at` (RCS `deliver`). Tests RCT. | La colonne obsolète `devices.pending_command` est toujours en base. |
| 17.4 | Authentification individuelle | C | C | Sanctum, ability `device`, `EnsureDevice`. `DT::test_device_token_gives_no_access_to_user_routes_and_user_cannot_use_device_routes` | — |
| 17.5 | TLS | P | P | Nginx TLS (`docs/deploiement.md`). Aucun refus du HTTP dans l'application : aucun `forceScheme` ni contrôle `isSecure`. | Repose entièrement sur l'infrastructure, non vérifié en recette. |
| 17.6 | Journalisation des échanges | P | P | Journal `device.command_*` (requested, executed, failed, expired, cancelled, refused). | Les heartbeats et les manifestes servis ne sont pas journalisés. La télémétrie n'est que d'un relevé sur environ dix (choix assumé). |
| 17.7 | Synchronisation différentielle | C | C | ETag/304, téléchargements par plages. `DT::test_manifest_is_signed_versioned_and_conditional` ; `DeviceDeliveryTest::test_media_download_supports_byte_ranges_for_resumption` | — |
| 17.8 | Cache local | C | C | Cache Storage vérifié par SHA-256, IndexedDB, service worker. e2e `player.spec.ts` | — |
| 17.9 | Hors ligne avec reprise | C | C | `valid_until`, file d'événements, receipts dédoublonnés. `DT::test_deferred_receipts_are_deduplicated_and_heartbeat_tracks_clock_skew` | — |
| 17.10 | Détection des pertes | P | P | `events_dropped` est signalé comme erreur. `offline_since` est validé (DAC l.122). | `offline_since` et `events_dropped` ne sont toujours pas enregistrés dans `devices`. |
| 17.11 | Rétention des remontées techniques | P | P | Paramètres `retention.receipts_days`, `commands_days` et `states_days`, purge par `saie:retention`. `FleetReportsTest::test_retention_purges_old_receipts_commands_and_states_only_when_applied` | La purge automatique reste désactivée par défaut (`retention.auto_purge` = false, absent de la table `settings`). Sans décision d'exploitation, rien n'est purgé. |

#### §18 Sécurité des lecteurs

| Réf | Exigence | Statut initial | Statut final | Preuve | Écart restant |
|---|---|---|---|---|---|
| 18.1 | Authentification forte | P | P | Jeton porteur Sanctum, TTL de 90 j, rotation. | Ni mTLS ni clé asymétrique propre au lecteur. Le jeton est en clair dans IndexedDB. |
| 18.2 | Anti-enrôlement non autorisé | P | P | Appairage décidé par une personne habilitée, dans son périmètre. | `requestCode` reste ouvert (voir 9.14). |
| 18.3 | Gestion des secrets | P | P | `issued_token` est chiffré en base (cast `encrypted`) : c'était un faux positif de l'audit côté serveur. | Le jeton reste en clair côté lecteur, dans IndexedDB. |
| 18.4 | Rotation | C | C | `DeviceTokenRotationTest::test_rotated_token_works_and_the_previous_one_expires_after_the_overlap` ; `DeviceDeliveryTest::test_next_signing_key_is_announced_in_signed_manifests_for_rotation_without_repairing` | — |
| 18.5 | Anti-rejeu | P | P | Manifeste signé et monotone, receipts uniques, accusés idempotents. | Ni nonce ni signature sur les requêtes du lecteur. Les commandes ne sont pas signées. |
| 18.6 | Révocation des lecteurs compromis | C | C | `DT::test_revoked_device_is_refused_by_the_api` | Cache lisible jusqu'à 24 h (documenté). |
| 18.7 | Mise à jour sécurisée | C | C | SHA-256 dans le manifeste signé, SRI. `PlayerReleaseTest::test_channels_rollback_and_manifest_target` | — |

#### §21 Fonctions avancées et §23

| Réf | Exigence | Statut initial | Statut final | Preuve | Écart restant |
|---|---|---|---|---|---|
| 21.1 | Capture réelle du rendu | P | P | `html-to-image` sur le DOM (ENG l.608). L'échec remonte désormais en `failed`. `RCT::test_a_screenshot_command_links_the_capture_request` | Ce n'est pas une capture de la sortie vidéo : vidéo et canvas peuvent manquer, et la dalle n'est pas prouvée. |
| 21.2 | Supervision intelligente (pertes répétées) | A | A | Un incident par occurrence. Aucune détection de bagottement ni corrélation (aucune occurrence dans `backend/app`). | Absent. |
| 21.3 | Mises à jour progressives | P | P | Canaux pilot/stable, affectation par lecteur, comptage des lecteurs en retard. | Pas de vagues, de pourcentage, de promotion automatique ni de suivi de succès par version. |
| 21.4 | Maintenance préventive | C | C | `FIT::test_preventive_maintenance_plan_opens_one_intervention_and_reschedules_on_resolution` | — |
| 23.1 | Autonomie hors ligne et resynchronisation | C | C | Voir 17.7 à 17.9. e2e `player.spec.ts` | — |

#### Décompte

Nombre de lignes : **82**, les mêmes que dans `audit-B.md` (§9 : 14, §10 : 28, §11 : 17, §17.3 : 11, §18 : 7, §21/§23 : 5).

| Statut | Initial | Final |
|---|---|---|
| Conforme | 22 | 49 |
| Partiel | 40 | 28 |
| Absent | 18 | 2 |
| Défectueux | 2 | 0 |
| Dépendance matérielle | — | 3 |
| **Total** | **82** | **82** |

Lignes en dépendance matérielle : 10.8, 11.4, 11.6. Lignes restées absentes : 10.9e, 21.2.

Écarts entre ce contrôle et la déclaration du suivi des corrections :
- 10.16 est marqué P, et non C. `player_stalled` repère un rendu figé, mais ne distingue pas un navigateur arrêté d'une perte réseau.
- 17.11 est marqué P, et non C. La purge automatique est désactivée par défaut.
- 18.3 est marqué P, et non C. Le chiffrement serveur est confirmé, mais le jeton reste en clair dans IndexedDB.
- 9.2 est marqué P, comme dans la déclaration. L'agent ne transmet pas MAC, OS ni firmware, bien que l'API les accepte.

### Contrôle final C — Gestion des écrans : affectations, historiques, rapports, habilitations, workflows, règles

Contrôle en lecture seule du 08/11/2026 (code, `php artisan route:list`, SELECT sur `saie_ceeac`). Chemins relatifs à `backend/` sauf mention `frontend/`.
Statuts : **C** Conforme · **P** Partiel · **A** Absent · **D** Défectueux. La section « Suivi des corrections » de `docs/AUDIT-GESTION-ECRANS/02-MATRICE-CONFORMITE.md` a été vérifiée dans le code ; les écarts avec la déclaration sont signalés.

Abréviations : SC = `app/Http/Controllers/Api/V1/ScreenController.php`, SV = `.../SupervisionController.php`, CC = `.../CampaignController.php`, CS = `app/Services/CampaignService.php`, EXS = `app/Services/ExportService.php`, SMI = `tests/Feature/ScreenModuleIntegrityTest.php`, FRH = `tests/Feature/FleetRolesAndHistoryTest.php`, FRT = `tests/Feature/FleetReportsTest.php`, SIT = `tests/Feature/ScreenInventoryTest.php`, IWT = `tests/Feature/IncidentWorkflowTest.php`, RCT = `tests/Feature/RemoteCommandsTest.php`, SST = `tests/Feature/SupervisionStatesTest.php`.

Données observées : écrans `active` 23, `maintenance` 1, `inactive` 1, `archived` 10 ; CHECK `screens_status_check` inclut désormais `archived` ; `role_assignments` porte `floor_id` et `group_id` ; rôles `responsable_parc` (12 permissions) et `superviseur` (5) présents en base.

| Réf | Exigence | Statut initial | Statut final | Preuve | Écart restant |
|---|---|---|---|---|---|
| §12-a | Affecter une playlist à un écran ou un groupe | P | P | Inchangé : `PlaylistController` = modèle copié dans une campagne ; ciblage par `campaign_targets` | Pas d'affectation directe playlist→écran/groupe (« programme permanent ») |
| §12-b | Campagne sur plusieurs écrans | C | C | `campaign_targets` ; `CS::resolveTargets` ; e2e `screens-scenarios.spec.ts` « scénario D : diffusion collective par groupe sur deux lecteurs » | — |
| §12-c | Dates et plages horaires | C | C | CHECK `campaigns_period_check` ; validation CC:324-326 ; `ScheduleResolver` ; EventModeTest | — |
| §12-d | Priorités | P | P | Toujours `priority` entier `between:1,99` (CC:325) ; aucune énumération de niveaux | Pas de niveaux nommés |
| §12-e | Périodes | C | C | Jours, créneau, mode événement ; EventModeTest | — |
| §12-f | Détection des conflits | P | P | `CS::preview` (CS:142-151) : chevauchement sur `starts_at/ends_at` seuls, ajout d'un indicateur `same_priority` ; re-calcul à la publication uniquement pour le compte d'écrans effectifs | Ignore jours/créneaux ; non bloquant ; aucun test (`conflicts` absent des tests du module) |
| §12-g | Prévisualisation des contenus | C | C | `TemplatePreview` (`frontend/src/pages/publication/shared.tsx:213`) ; `POST /campaigns/preview` | — |
| §12-h | Historique des affectations | P | P | Nouveau : catégorie `assignment` de `ScreenHistory` (campagnes transmises par écran, `app/Services/ScreenHistory.php:38-40`) | `campaign.updated` toujours sans avant/après (CC:103) ; cibles supprimées puis recréées (CC:310) ; catégorie `assignment` non testée |
| §12.1-a | Hiérarchie des priorités métier | P | P | Inchangé : alerte > campagne (priorité numérique) > repli (`ScheduleResolver`) | « Officiel prioritaire » et « informations générales » non modélisés |
| §12.1-b | Priorités configurables et soumises à autorisation | P | P | Inchangé : pouvoir « urgence » ; aucune permission par niveau (`grep priority_high/priority_band` : rien) | Tout titulaire de `campaigns.manage` peut choisir 99 |
| §12.1-c | Urgence puis retour automatique au programme | C | C | `ScheduleResolver` ; DeviceTest::test_alert_preempts_programme_in_manifest_and_requires_urgency_power ; e2e `player.spec.ts` | — |
| §12.2 | Prévisualisation du rendu sur un écran donné | P | P | `GET /screens/{id}/preview` (SC:403-418) renvoie un programme résolu + `orientation` ; avertissement d'orientation (CS) | Pas de rendu visuel à l'échelle de l'écran (`TemplatePreview` non utilisé dans `pages/display`) ; route non bornée au périmètre |
| §13.1-a | Historique : création | C | C | `screen.created` (SC:142) ; FRH::test_the_screen_history_gathers_every_kind_of_event (catégorie `record`) | — |
| §13.1-b | Historique : modification | C | C | `screen.updated` avant/après (SC:176) ; onglet Historique | — |
| §13.1-c | Historique : déplacement | P | C | Action `screen.moved` avec motif (SC:173-175), table `screen_location_history`, re-contrôle de périmètre dans la transaction (SC:165-167) ; SIT::test_the_full_record_is_stored_and_each_move_is_historised ; SMI::test_a_zone_manager_cannot_reactivate_or_move_a_screen_outside_the_zone ; e2e `screens-inventory.spec.ts` | — |
| §13.1-d | Historique : changement de configuration | C | C | `screen.window_changed` motif obligatoire (SC:158-160) ; catégorie `configuration` de `ScreenHistory` | — |
| §13.1-e | Historique : changement de groupe | P | C | `group.updated` avec `before` (SC:578) ; agrégé dans l'historique de l'écran via `screen_ids` avant/après (`ScreenHistory.php:35-36`) ; FRH::test_the_screen_history_gathers_every_kind_of_event (catégorie `group`) | Membres des groupes dynamiques recalculés sans journal individuel |
| §13.1-f | Historique : affectation de contenu | A | C | Catégorie `assignment` (`campaign_transmissions` par lecteur de l'écran) dans `GET /screens/{id}/history` | Catégorie non vérifiée par FRH (assertion limitée à record/location/group/command/incident) |
| §13.1-g | Historique : désactivation / réactivation | P | P | Toujours via `screen.updated` ; `status in:active,maintenance,inactive`, motif exigé seulement pour `maintenance` (SC:648-649) | Pas d'action dédiée ni de motif pour `inactive`/réactivation |
| §13.1-h | Historique : archivage | A | C | État `archived` (CHECK en base), `POST /screens/{id}/archive` (motif, refus si incident ouvert, lecteur révoqué, lecture seule `SCREEN_ARCHIVED`), audit `screen.archived` (SC:385-399) ; SMI::test_archiving_withdraws_the_screen_and_keeps_its_record ; e2e `screens-inventory.spec.ts` | — |
| §13.2-a | Connexions / déconnexions | P | C | Table `screen_status_history` (période par état, alimentée par `saie:tick`), `GET /screens/{id}/status-history` ; SST::test_the_scheduler_keeps_one_period_per_state_change ; e2e scénarios A/B/G (`offline` dans l'historique) | Coupures plus courtes que le délai `supervision.offline_after_s` non tracées (granularité 1 min) |
| §13.2-b | Incidents | C | C | `incidents.events` + audit `incident.*` (SV:196-202) ; IWT | — |
| §13.2-c | Commandes et résultats | P | C | File `device_commands`, accusés `POST /device/commands/{id}/ack`, compte rendu ; RCT::test_a_command_goes_through_sent_received_and_executed_with_a_report ; e2e `screens-commands.spec.ts` | Veille/redémarrage validés en simulation seulement |
| §13.2-d | Synchronisations | C | C | `device_receipts`, `last_sync_at`, `campaign_transmissions` | — |
| §13.2-e | Erreurs | P | P | `last_errors` toujours écrasé à chaque battement (`DeviceApiController.php:142`) ; nouvel incident `playback_errors` sur erreurs répétées (`SaieTick.php:245`) | Pas d'historique exhaustif des erreurs |
| §13.2-f | Mises à jour | P | P | `app_version` au battement ; incident `outdated_version` (`SaieTick.php:248`) ; `device.channel_changed` | Aucun événement `version_changed` : pas d'historique de version par lecteur |
| §13.2-g | Interventions | C | C | Action `intervene` (note obligatoire), plans de maintenance ; IWT | — |
| §13.3-a | Rapport inventaire général PDF/Excel | D | C | `equipment_inventory` borné par `exportScreenScope` (EXS:166, 308) ; colonnes fabricant/modèle, série, acquisition, garantie (EXS:356-357) ; SIT::test_the_inventory_report_is_bounded_to_the_scope_and_can_target_a_floor | — |
| §13.3-b | Inventaire par bâtiment / étage | A | C | Filtres `building_id`/`floor_id` (EXS:335-336), synthèse par bâtiment et niveau ; SIT::test_the_inventory_report_is_bounded_to_the_scope_and_can_target_a_floor | Filtre `building_id` non testé (seule la synthèse l'est) |
| §13.3-c | Écrans hors ligne | A | C | PDF11 `offline_screens` (`app/Services/FleetReports.php`) ; FRT::test_each_fleet_report_is_built_from_real_data | — |
| §13.3-d | Incidents | D | P | `incident_report` désormais borné (`whereIn('screen_id', $scope)`, EXS:168, 382) | Colonnes sans catégorie ni diagnostic ni résolution (EXS:387) ; borne de périmètre non testée (FRT ne teste que l'accès auditeur) ; incidents sans `screen_id` exclus des exports limités |
| §13.3-e | Disponibilité | P | C | PDF14 `availability_report` (FleetReports:97-125) ; FRT::test_each_fleet_report_is_built_from_real_data | Cf. RG-014 |
| §13.3-f | Maintenance | P | C | PDF15 `maintenance_report` ; FRT::test_each_fleet_report_is_built_from_real_data | — |
| §13.3-g | Diffusions | C | C | `diffusion_report` borné | — |
| §13.3-h | Commandes exécutées | A | C | PDF12 `remote_commands` ; FRT::test_each_fleet_report_is_built_from_real_data (« Synchroniser / Exécutée / 40 s ») ; e2e `screens-reports.spec.ts` | — |
| §13.3-i | Équipements en fin de garantie | A | C | PDF13 `warranty_expiry` (paramètre `months`) ; FRT::test_each_fleet_report_is_built_from_real_data | — |
| §13.3-j | Durées de conservation paramétrables | P | P | Nouveaux paramètres `retention.receipts_days/commands_days/states_days` (SettingController:49-51, 88-90), purge `saie:retention` ; FRT::test_retention_purges_old_receipts_commands_and_states_only_when_applied | Journal d'audit (`audit_months`), exports (`export_hours`) et incidents toujours non paramétrables dans l'interface |
| §13.3-k | Droits sur les rapports paramétrables | P | P | Correspondance type→permissions toujours figée dans `ExportService::TYPES` (EXS:49-65) | Non paramétrable sans modifier le code |
| §14-1 | Profil Administrateur SAIE | P | P | `admin_fonc` (base : 17 permissions, sans `screens.manage`/`campaigns.manage`) ; correspondance déclarée dans le suivi | Aucun rôle unique ; correspondance à valider par la DSI |
| §14-2 | Administrateur technique | C | C | `admin_tech` (21 permissions dont `devices.command`) | — |
| §14-3 | Responsable du parc | A | C | Rôle `responsable_parc` (screens.manage, groups.manage, devices.*, incidents.manage, supervision.view, reports…) ; FRH::test_the_supervisor_consults_while_the_fleet_manager_administers | — |
| §14-4 | Superviseur | A | C | Rôle `superviseur` (screens.view, supervision.view, reports.view, exports.create) ; FRH::test_the_supervisor_consults_while_the_fleet_manager_administers | — |
| §14-5 | Technicien | C | C | `technicien` (+ `devices.command`) | — |
| §14-6 | Gestionnaire de diffusion | C | C | `diffuseur` + pouvoir « urgence » | — |
| §14-7 | Auditeur | P | C | `reports.view` accepté pour inventaire, incidents et PDF11-15 (EXS:55-65) ; FRT::test_the_auditor_can_request_fleet_reports_and_inventory ; e2e `screens-scenarios.spec.ts` scénario F (auditeur refusé en écriture) | — |
| §14-8 | Habilitation limitable à un périmètre | P | P | `role_assignments.floor_id/group_id` intersectés avec site et zone (`app/Services/Access.php:191-204`) ; saisie `frontend/src/pages/admin/Users.tsx:354-361` ; FRH::test_an_assignment_can_be_limited_to_a_floor_or_to_a_group | Pas de périmètre par bâtiment (seulement site) ni par équipement (écran) |
| §14-9 | Contrôle backend de chaque opération sensible | D | C | D1 (SMI::test_a_zone_manager_cannot_reactivate_or_move…, ::test_an_empty_group_of_another_structure…), D4 (SV:149,166,237,248 ; SMI::test_incidents_are_bounded_to_the_zone…), D6 (SC:165-167), D3 (SMI::test_a_campaign_target_must_exist) ; e2e scénario F | Lectures encore non bornées (P2) : `screens/{id}/preview`, `program`, `screen-groups` (SC:516-519), `campaigns/{id}/delivery` (CC:176-180) |
| §15.1 | Cycle de vie | P | P | Archivage ajouté ; état `pending_configuration` calculé (`Present.php:102`) ; e2e scénario A (création → appairage → campagne → diffusion prouvée) | Pas d'étape explicite « validation communication et rendu » / « mise en service » : écran `active` dès sa création |
| §15.2-a | Détection automatique ou manuelle | C | C | `SaieTick` ; `POST /incidents` ; e2e scénarios A/B/G | — |
| §15.2-b | Alerte | C | C | Notification + escalade à l'échéance (`IncidentPolicy.php:53`) ; IWT::test_automatic_incidents_go_to_the_default_assignee_and_overdue_ones_are_escalated | — |
| §15.2-c | Qualification | P | C | Action `qualify` (gravité, catégorie, échéance recalculée, SV:163,187) ; IWT::test_the_full_workflow_requires_a_verification_before_closing ; UI `frontend/src/pages/admin/Supervision.tsx:470-473` | — |
| §15.2-d | Affectation à un technicien | C | C | `assign` + éligibilité (SV:170-175) | — |
| §15.2-e | Diagnostic | C | C | `diagnose` note obligatoire | — |
| §15.2-f | Intervention | P | C | Action `intervene` note obligatoire (SV:165) ; IWT (refus sans note) ; e2e `screens-incidents.spec.ts` | Durée/intervenant non structurés (texte libre) |
| §15.2-g | Vérification | A | C | Action `verify` (`verified_at/by`), clôture refusée sans vérification `VERIFICATION_REQUIRED` (SV:181-183) ; IWT ; e2e `screens-incidents.spec.ts` (second technicien) | Séparation des rôles non imposée : le résolveur peut vérifier lui-même |
| §15.2-h | Clôture avec historique | P | C | Clôture après `resolved` + vérification ; résultat consigné à la résolution ; `events` + audit ; IWT (séquence d'événements) | — |
| §15.2-i | Incident non résolu au tableau de bord | C | C | DashboardController | — |
| RG-001 | Identifiant unique | C | C | `screens.code` UNIQUE + validation | Doublon de code non testé |
| RG-002 | Localisation valide | C | C | FK `location_id` NOT NULL restrict | — |
| RG-003 | Un seul lecteur actif par écran | C | C | Index unique partiel `devices_one_active_per_screen` | `SCREEN_ALREADY_PAIRED` non testé |
| RG-004 | Enrôlement authentifié | C | C | Code à usage unique, jeton individuel ; DeviceTest ; e2e scénario F (jeton forgé → 401) | — |
| RG-005 | Écran désactivé sans nouvelle programmation | D | C | Manifeste neutralisé pour `inactive`/`archived` (`app/Services/ManifestBuilder.php:124`) ; SMI::test_an_inactive_screen_receives_no_programme | — |
| RG-006 | Commande compatible avec les capacités | P | C | Capacités déclarées au battement, refus `COMMAND_UNAVAILABLE` ; RCT::test_an_unsupported_command_is_refused_with_its_reason, ::test_a_player_that_never_declared_capabilities_receives_no_command | — |
| RG-007 | Commande historisée | P | C | `GET /devices/{id}/commands`, audit `device.command_*`, résultat ; RCT ; e2e `screens-commands.spec.ts` | — |
| RG-008 | Modification importante auditée | P | P | Avant/après ajoutés pour écran, groupe (SC:578), profil, paramètres ; incidents tracés par `events` | `campaign.updated` (CC:103) et `playlist.updated` toujours sans avant/après |
| RG-009 | Écran hors ligne jamais diffusant | C | C | SST::test_operational_states_distinguish_broadcasting_standby_pause_and_fallback | — |
| RG-010 | Affectation respectant programmation et priorités | C | C | `ScheduleResolver::resolve` | — |
| RG-011 | Changement de localisation historisé | P | C | `screen_location_history` + `screen.moved` + re-contrôle de périmètre ; SIT::test_the_full_record_is_stored_and_each_move_is_historised | — |
| RG-012 | Incident non clôturable sans résultat | P | C | `note` requise pour `resolve` → `incidents.resolution` ; SMI::test_incidents_are_bounded_to_the_zone_and_resolution_requires_a_result ; IWT | — |
| RG-013 | Écran archivé hors nouvelles campagnes | D | C | État `archived`, manifeste vide, retrait des groupes, masqué des listes ; SMI::test_archiving_withdraws_the_screen_and_keeps_its_record | — |
| RG-014 | Disponibilité sur données réellement reçues | C | C | Télémétrie reçue / créneaux de 5 min (FleetReports:100-109) ; FRT | Créneaux attendus toujours comptés hors plage de fonctionnement (disponibilité minorée la nuit) |

#### Couverture de recette §20

(Liste reconstituée dans l'audit initial.)

| # | Critère | Test qui le couvre désormais | Couverture |
|---|---|---|---|
| 1 | Enregistrer un écran (identifiant unique, localisation) | FRH::test_the_screen_history_gathers_every_kind_of_event (`POST /screens`) ; SIT::test_csv_import_simulates_without_writing_refuses_invalid_files_and_commits_atomically ; e2e `screens-scenarios.spec.ts` « scénarios A, B et G » (`createScreen` → 201) ; e2e `screens-inventory.spec.ts` | Couvert (refus d'un code en doublon non testé) |
| 2 | Déplacement historisé | SIT::test_the_full_record_is_stored_and_each_move_is_historised ; SMI::test_a_zone_manager_cannot_reactivate_or_move_a_screen_outside_the_zone ; e2e `screens-inventory.spec.ts` « …déplacement historisé… » | Couvert |
| 3 | Enrôlement authentifié, un lecteur actif | DeviceTest (appairage unique, code expiré, révocation) ; e2e `screens-scenarios.spec.ts` scénarios A et D (`pairPlayer`), scénario F (jeton forgé) | Couvert (`SCREEN_ALREADY_PAIRED` non testé) |
| 4 | Groupe d'écrans | `DynamicGroupsTest` (4 tests) ; e2e `screens-groups.spec.ts` ; e2e `screens-scenarios.spec.ts` « scénario D » | Couvert |
| 5 | Campagne multi-écrans, dates, plages, playlist | e2e `screens-scenarios.spec.ts` « scénario D » (campagne au groupe, deux lecteurs, lecture prouvée) ; PublicationRulesTest::test_reusable_playlist_keeps_order_and_campaign_archiving_is_controlled ; EventModeTest | Couvert |
| 6 | Détection des conflits | Aucun | Non couvert |
| 7 | Prévisualisation selon orientation/résolution | PublicationRulesTest::test_preview_warns_when_template_orientation_differs_from_the_screen | Partielle (pas de rendu visuel) |
| 8 | Urgence puis retour au programme | DeviceTest::test_alert_preempts_programme_in_manifest_and_requires_urgency_power ; DeliveryProofTest ; e2e `player.spec.ts` | Couvert |
| 9 | Écran hors ligne : détection, incident, jamais diffusant | e2e `screens-scenarios.spec.ts` « scénarios A, B et G » (hors ligne, incident `device_unavailable`, résolution automatique) ; SST::test_operational_states_distinguish_broadcasting_standby_pause_and_fallback | Couvert |
| 10 | Commande à distance historisée | RCT (7 tests) ; e2e `screens-commands.spec.ts` | Couvert |
| 11 | Cycle d'incident jusqu'à clôture | IWT::test_the_full_workflow_requires_a_verification_before_closing ; e2e `screens-incidents.spec.ts` | Couvert |
| 12 | Écran désactivé/archivé exclu des campagnes | SMI::test_an_inactive_screen_receives_no_programme ; SMI::test_archiving_withdraws_the_screen_and_keeps_its_record ; e2e `screens-inventory.spec.ts` (archivage) | Couvert |
| 13 | Rapports PDF/Excel et habilitations par périmètre | FRT::test_each_fleet_report_is_built_from_real_data, ::test_the_auditor_can_request_fleet_reports_and_inventory ; SIT::test_the_inventory_report_is_bounded_to_the_scope_and_can_target_a_floor ; FRH::test_an_assignment_can_be_limited_to_a_floor_or_to_a_group ; e2e `screens-reports.spec.ts` (PDF `%PDF`, XLSX `PK`) ; e2e scénario F | Couvert (borne de périmètre du rapport d'incidents non testée) |

Bilan §20 : 11 couverts, 1 partiel, 1 non couvert (initial : 6 / 4 / 3).

#### Décompte

71 lignes, identiques à la matrice de `audit-C.md` (hors §20).

| Statut | Initial | Final |
|---|---|---|
| Conforme | 27 | 54 |
| Partiel | 30 | 17 |
| Absent | 9 | 0 |
| Défectueux | 5 | 0 |
| **Total** | **71** | **71** |

Écarts avec le « Suivi des corrections » : la déclaration « Conforme » est confirmée partout sauf (1) rapport d'incidents (§13.3-d) : borné mais colonnes incomplètes et périmètre non testé, la ligne « C-D5 Conforme » du suivi s'appuie sur un test d'inventaire seulement ; (2) durées de conservation (§13.3-j) : journal d'audit, exports et incidents restent non paramétrables ; (3) historique d'affectation (§13.1-f) : implémenté mais non vérifié par le test cité.


## Corrections postérieures au contrôle final (phase suivante, 8 novembre 2026)

Ces corrections ne modifient pas le décompte mesuré ci-dessus (187 / 266) ; elles seront prises en compte au prochain contrôle.

| Réf. | Écart relevé par le contrôle final | Correction | Test |
|---|---|---|---|
| 4.3-b, 5.3-i, 6-i | Position de l'écran sur le plan non saisissable | Champs « position sur le plan » (%) dans la modification de l'écran | Typage ; e2e `screens-inventory.spec.ts` (non-régression) |
| §14 (lectures) | Aperçu de programme, `/program`, liste des groupes, suivi de diffusion non bornés | Bornés au périmètre (groupes : seuls ceux entièrement dans le périmètre, et les groupes vides de la structure) | `ScopedReadsTest::test_programme_preview_groups_and_delivery_are_bounded_to_the_zone` |
| §13.3-d | Rapport d'incidents sans catégorie, diagnostic ni résultat ; périmètre non testé | Colonnes catégorie, diagnostic, résultat, vérifié par, escalade ; test de périmètre | `ScopedReadsTest::test_the_incident_report_is_bounded_and_carries_the_processing_columns` |
| 5.5-f | Import Excel absent | Lecteur XLSX sans dépendance (première feuille, taille bornée, XML sans entités externes, dates Excel converties) ; boîte d'import CSV ou Excel | `ScreenInventoryTest::test_an_excel_workbook_is_imported_like_a_csv_file` |
| 9.4, 10.7 | Pas de réaffectation d'un lecteur sans révocation | `POST /devices/{id}/reassign` (périmètre des deux écrans, remplacement confirmé, commandes ouvertes annulées) ; bouton dans l'onglet Lecteur | `DeviceReassignAndConflictsTest` (2 tests) ; e2e `screens-reassign.spec.ts` (lecteur réel, nouveau manifeste reçu) |
| §12-f, §20 n° 6 | Conflits calculés sur les dates seules | Intersection des jours et des créneaux horaires ; l'assistant de campagne transmet jours et créneau à la prévisualisation | `DeviceReassignAndConflictsTest::test_conflicts_require_shared_days_and_overlapping_time_slots` |
| 10.7 | Pas de lecteur suspendu temporairement | Suspension réversible (`POST /devices/{id}/suspend`, `/resume`, motif obligatoire, permission `devices.revoke`, périmètre, journal) : identité et jeton conservés, plus de programme ni d'alerte servis (repli seul), indicateur `suspended` dans le manifeste signé ; état opérationnel « Lecteur suspendu » ; boutons dans l'onglet Lecteur | `FleetSuspensionRolloutTelemetryTest::test_a_suspended_player_only_receives_the_fallback_and_can_be_resumed` ; e2e `screens-suspension.spec.ts` (lecteur réel : suspension puis rétablissement depuis la fiche, nouveau manifeste émis à chaque fois) |
| 21.3 | Pas de vagues ni de suivi de déploiement | Part du canal servie (`rollout_percent`, 1–100) à la publication et modifiable avec motif (`PATCH /player-releases/{id}`) ; sélection stable par empreinte lecteur + version (élargir n'en retire aucun) ; suivi par lecteur (`GET /player-releases/{id}/deployment` : à jour, en attente, en échec, hors vague), borné au périmètre ; fenêtre « Suivi » dans Supervision. Pas de promotion automatique sur indicateurs | `FleetSuspensionRolloutTelemetryTest::test_a_partial_rollout_reaches_a_stable_share_of_the_channel` ; e2e : suivi ouvert en lecture |
| 10.9e, 9.1 | Mémoire, processeur, disque, température, MAC, système non remontés | Agent local v1.1.0 : `GET /system` (origine du lecteur seule) ; le lecteur joint ces mesures au battement ; le disque mesuré prime pour l'anomalie « stockage » ; nouvelle anomalie « température élevée » (seuil paramétrable, 80 °C par défaut). Mesure indisponible = absente, jamais estimée : charge processeur non mesurée sous Windows, température lue seulement sur capteur Linux `thermal_zone0` ; firmware non mesuré | `FleetSuspensionRolloutTelemetryTest::test_agent_telemetry_is_stored_and_drives_disk_and_temperature_anomalies` ; e2e : télémétrie réelle du poste de développement (Windows) affichée sur la fiche |
| §12-d, §12.1-a/b | Priorité numérique sans niveaux métier ni habilitation | Cinq niveaux (1–19 permanents, 20–39 informations générales, 40–59 institutionnelles, 60–79 officielles prioritaires, 80–99 urgents) ; à partir du seuil `campaigns.urgent_from` (80 par défaut, paramétrable 60–99), création ou changement de priorité refusé sans pouvoir « urgence » (`PRIORITY_RESTRICTED`, journalisé) ; niveau affiché dans l'assistant (`GET /campaign-priority-levels`). Les campagnes existantes ne sont revérifiées qu'à leur prochaine modification | `FleetSuspensionRolloutTelemetryTest::test_the_urgent_priority_level_requires_the_urgence_power` ; `SettingsEffectTest` |
| 5.3-h | Photo d'emplacement sans interface | Champ « Photo de l'emplacement » dans Référentiels › Emplacements (images publiques et saines de la médiathèque) ; non affichée ailleurs | Typage ; validation serveur déjà testée |
| 5.1-b | Pas de code d'inventaire distinct | `screens.inventory_code` (unique, facultatif), saisi sur la fiche, importable (CSV/Excel), affiché | `InventoryLogsAndTraceabilityTest::test_inventory_code_unique_serial_and_extended_search` ; e2e `screens-inventory-logs.spec.ts` |
| 5.2-b | N° de série sans unicité | Index unique partiel insensible à la casse + contrôle à la saisie (aucun doublon en base au moment de la migration) | Idem |
| 5.2-i, 7.2-a (en partie) | IP observée non remontée | `devices.observed_ip` relevée par le serveur à chaque battement, affichée dans l'onglet Lecteur (le mode d'adressage DHCP/fixe reste non mesuré) | `…heartbeat_records_observed_address_losses_and_version_changes` ; e2e |
| 5.3-f, 5.3-g, 5.3-h | Type d'espace, responsable et photo non affichés | Affichés sur la fiche écran (photo de l'emplacement issue du référentiel) | Typage ; `…last_confirmed_play…` (données de la fiche) |
| 5.4-h | Dernière diffusion confirmée absente | `last_played_at` : dernier accusé « played » d'un programme émis pour cet écran (rapprochement accusé → manifeste → code d'écran) | `…last_confirmed_play_resolved_playback_and_technical_logs` |
| 5.5-h, 5.5-i | Recherche limitée au code et au nom ; pas de filtre salle | Recherche sur code, nom, code d'inventaire, n° de série, marque, modèle, emplacement, salle, code du lecteur ; filtres `room_id` et `building_id` ; filtre « Salle » dans la liste | `…extended_search` ; e2e (recherche par code d'inventaire) |
| 6-h | Pas de décompte par emplacement | Nombre d'écrans en service par emplacement (référentiels) | `…extended_search` |
| 9.12 | Pas de vue des journaux techniques | `GET /devices/{id}/logs` : événements déclarés (erreur, repli, coupure, reprise, hors plage) ou relevés de télémétrie, paginés, bornés au périmètre ; panneau dans l'onglet Lecteur | `…technical_logs` ; e2e |
| 10.9b, 10.9c | Contenu et campagne en cours non résolus | Entrée en lecture rapprochée du manifeste actif : titre et référence de campagne | `…resolved_playback…` |
| 13.2-f | Pas d'historique des versions du lecteur | Événement `device.version_changed` (avant/après) à chaque changement de version déclarée, visible dans l'historique de l'écran | `…version_changes` |
| 17.10 | `offline_since` et événements perdus non enregistrés | `devices.offline_since` (dernière coupure déclarée) et `events_dropped` (cumul) ; le lecteur transmet désormais le nombre d'événements perdus | `…losses…` ; e2e |
| 21.2 | Pas de détection des pertes répétées | Incident « connexion instable » (gravité moyenne) à partir de N pertes en 24 h (paramètre `anomalies.unstable_losses`, 3 par défaut) | `…repeated_losses_and_empty_programme_open_incidents` |
| 10.19 (en partie) | Pas d'alerte de programmation | Incident « aucun programme » quand le programme émis pour un écran actif en ligne est vide (hors suspension), résolu automatiquement dès qu'une campagne le cible. Le repli prolongé n'a pas de règle propre | Idem |
| §13.1-g | Désactivation sans motif ni action dédiée | Motif obligatoire pour désactiver ou réactiver ; événements `screen.deactivated` / `screen.reactivated` | `…deactivation_needs_a_reason…` ; e2e |
| RG-008, §12-h | `campaign.updated` et `playlist.updated` sans avant/après | Seules les valeurs modifiées sont journalisées avant/après, cibles, contenus et langues compris | `…updates_keep_before_and_after` |

### Lots 20 à 25 (8 novembre 2026, « quatre blocs restant à faire »)

Sauvegarde préalable : `saie_ceeac-avant-lots20-25-20261108-1922.dump` (restaurée dans une base temporaire, comptages identiques). Migrations additives uniquement.

| Réf. | Écart | Correction | Test |
|---|---|---|---|
| 5.1-e | Pas de catégorie paramétrable | Référentiel « Catégories d'écran » (création, désactivation), catégorie sur la fiche, filtre de liste, colonne d'inventaire | `ScreenClassificationAndCommissioningTest::test_screen_classification_and_filters` |
| 5.2-g, 5.2-l, 5.4-f | Interfaces, mode de fonctionnement, criticité absents | Interfaces physiques (liste fermée), mode (permanent, plage, à la demande), criticité (faible à critique) ; filtres | Idem |
| 5.5-j | Pas de fiche imprimable | Export PDF16 « Fiche écran » (identité, localisation, matériel, lecteur, mise en service, incidents), borné au périmètre | `…test_screen_sheet_and_export_parameters_are_kept` |
| — (défaut) | Paramètres d'export « bâtiment », « archivés », « mois » validés puis écartés par le contrôleur | Paramètres transmis au générateur | Idem |
| 6-a | Pas de niveau Institution ; salle parallèle à l'emplacement | Référentiel « Institutions » rattaché aux sites ; salle rattachable à un emplacement de son niveau | `…test_institution_level_and_room_attached_to_a_location` |
| §15.1 | Écran actif dès sa création, sans mise en service | Action « Mettre en service » : contrôles calculés (lecteur appairé et joignable, programme et configuration appliqués, rendu confirmé < 24 h) + contrôle visuel attesté ; compte rendu conservé et journalisé ; filtre « non mis en service » | `…test_commissioning_requires_facts_and_a_visual_check` ; e2e `screens-lots20-25.spec.ts` |
| 7.1-a, 7.1-b | Résolution et orientation non signalées « non pilotées », pas de rotation | Signalement dans la configuration (sortie déclarée comparée) ; rotation logicielle d'un quart de tour activable par profil | Typage ; tests du lecteur |
| 7.1-f | Pas de veille sur inactivité | Paramètre de profil « veille après inactivité » : la dalle passe en veille après N minutes de repli seul pendant la plage (agent de pilotage requis) | Typage ; `DeviceFleetManagementTest::test_profile_settings_and_allowed_servers` |
| 7.2-c | Serveur non paramétrable | Paramètre de profil « serveur de rattachement », limité aux serveurs autorisés (`SAIE_DEVICE_SERVERS`) ; le lecteur vérifie le nouveau serveur avant de basculer | `…test_profile_settings_and_allowed_servers` |
| 8-f | Statistiques de groupe partielles | Joignabilité, lectures et incidents sur 7 jours par groupe (trois requêtes pour tous les groupes) | `…test_group_statistics_over_seven_days` |
| 9.2 | Pas d'identité propre du lecteur | Nom, type de matériel, n° de série du lecteur | `…test_device_identity_and_forced_credential_rotation` |
| 9.6 | Pas de renouvellement forcé | « Renouveler les identifiants » : exécuté au battement suivant, ancien jeton valable 10 min | Idem |
| 9.8 | Pas de mise à jour ciblée | Version épinglée sur un lecteur précis | `…test_a_release_can_be_pinned_on_one_device` |
| 21.3 | Pas de promotion automatique | Promotion d'une version pilote saine (100 %, délai réglable, aucun incident) proposée dans la liste ; automatique si activée (désactivée par défaut), première vague de 25 % | `…test_healthy_pilot_release_is_proposed_then_promoted_only_when_enabled` |
| 10.9f, 13.2-e | Erreurs en texte libre, pas d'historique | Erreurs codées (MEDIA_VIDEO, MEDIA_PDF, NETWORK…) avec entrée concernée ; catégorie « Erreurs du lecteur » dans l'historique de l'écran | `SupervisionAgentAndExchangesTest::test_coded_errors_appear_in_the_screen_history` |
| 10.16 | Lecteur arrêté indiscernable d'une perte réseau | Jeton « agent » remis par le lecteur à l'agent local, qui bat seul toutes les minutes ; état « Lecteur arrêté (poste joignable) » et incident dédié | `…test_agent_heartbeat_distinguishes_a_stopped_player` ; e2e (battement réel de l'agent) |
| 10.18 | Pas d'alerte dalle éteinte | Incident « dalle éteinte pendant la plage » (canal de pilotage, > 10 min, hors arrêt commandé et veille d'inactivité) | `…test_panel_off_during_the_window_and_prolonged_fallback_open_incidents` |
| 10.19 | Repli prolongé sans alerte | Incident « contenu de repli prolongé » (seuil réglable, 30 min par défaut) quand un programme existe | Idem |
| 17.6 | Échanges non journalisés | Journal des échanges des lecteurs (route, statut, durée, volume, adresse), consultable dans l'onglet Lecteur, purgé selon sa durée (30 j) | `…test_device_exchanges_are_logged_listed_and_purged` ; e2e |
| §12-a | Pas d'affectation directe d'une liste | « Affecter une liste de lecture » (écran ou groupe) : programme permanent lié à la liste, mêmes contrôles et publication que toute campagne | `PlaylistAssignmentScopesAndRetentionTest::test_a_playlist_is_assigned_directly_as_a_permanent_programme` |
| §12.2 | Pas de rendu visuel | Aperçu du rendu des diapositives programmées à l'échelle et dans l'orientation de l'écran (gabarit par défaut) | Typage |
| §13.3-j | Conservation non paramétrable | Archivage du journal (mois) et disponibilité des exports (heures) réglables | `…test_fleet_report_rights_and_retention_settings_are_configurable` |
| §13.3-k | Droits des rapports figés | Permission « rapports du parc » accordable rôle par rôle (donnée aux rôles qui avaient les rapports : aucun droit perdu) | Idem |
| §14-8 | Pas de périmètre bâtiment ou écran | Habilitation limitée à un bâtiment ou à un écran | `…test_assignments_can_be_limited_to_a_building_or_a_single_screen` |
| §14-1 | Pas de rôle unique | Rôle « Administrateur SAIE » créé, non attribué (décision DSI) | `…test_the_single_saie_administrator_role_exists_unassigned` |
| 9.14, 18.2 | Enrôlement ouvert | Liste blanche de réseaux d'enrôlement (paramètre, **vide par défaut** = comportement d'origine), refus journalisé | `DeviceSecurityOptionsTest::test_enrollment_allowlist_is_off_by_default_then_enforced` |
| 18.5, 11.10 | Ni signature ni anti-rejeu | Requêtes signées HMAC (clé = empreinte du jeton), horodatage ±5 min, nonce unique ; **désactivé par défaut**, les lecteurs signent déjà | `…test_signed_requests_with_replay_protection_when_enabled` |
| 18.1, 18.3 | Jeton en clair dans le navigateur | Chiffrement AES-GCM au repos par clé non exportable, **désactivé par défaut** (ne protège pas d'un code exécuté dans l'origine du lecteur) | `…test_secure_storage_flag_and_restricted_directory` (drapeau) ; typage du lecteur |
| Décision 1 | Annuaire ouvert à tout compte | Option « annuaire réservé aux gestionnaires », **désactivée par défaut** | Idem |
| 7.1-i | Fuseau du site ignoré | Fuseau du site transmis au lecteur (programme, plage, affichage de l'heure) et utilisé par la surveillance | `…test_the_manifest_uses_the_site_timezone` |
| 17.5 | TLS non vérifié | Contrôle « serveurs de rattachement en HTTPS » ajouté à `saie:preflight` (le reste de TLS relève de l'infrastructure) | Revue de code |
| 10.22, §17.2 | Pas de temps réel | Flux SSE natif (aucune dépendance : Reverb 1.12 incompatible avec psr7 3, décision de l'utilisateur), borné et filtré par périmètre ; supervision, liste et fiches relues à chaque annonce ; rafraîchissement périodique en secours | `RealtimeStreamTest` (2) ; e2e (indicateur « Temps réel ») |
| Bloc 4 | Matériel | Diagnostic en lecture seule `player/agent/diagnose.mjs` (ne valide rien à lui seul) | Exécuté sur le poste de développement : pilote simulé, charge processeur et température non mesurées |

## Second contrôle de conformité indépendant (8 novembre 2026, après les lots 13 à 25)

Trois contrôleurs indépendants, en lecture seule (code, routes, base ; aucun test rejoué par eux), ont réévalué les 266 exigences sans tenir compte des déclarations de l'équipe.

| Partie | C | P | A | D | H | Total |
|---|---|---|---|---|---|---|
| A — §4 à §8 | 102 | 6 | 0 | 2 | 3 | 113 |
| B — lecteurs, supervision, commandes, protocole, sécurité | 61 | 11 | 0 | 5 | 5 | 82 |
| C — affectations, historiques, rapports, habilitations, règles | 60 | 10 | 0 | 1 | 0 | 71 |
| **Ensemble** | **223** | **27** | **0** | **8** | **8** | **266** |

**Taux mesuré : 223 / 266 = 83,8 %** (contre 187 / 266 = 70,3 % au contrôle final précédent). Les huit défauts relevés ont été corrigés dans le lot 26 (ci-dessous) ; le taux n'est pas recalculé tant qu'un nouveau contrôle ne les a pas vérifiés.

### Lot 26 — corrections des défauts du second contrôle

| Réf. | Défaut relevé | Correction | Test |
|---|---|---|---|
| 5.5-f (A) | Ligne d'exemple du modèle d'import décalée de 3 colonnes | Exemple construit colonne par colonne depuis l'en-tête | `ControlFindingsFixesTest::test_the_import_template_example_is_aligned_and_importable` (modèle importé tel quel) |
| 7.2-c (A) | Bascule de serveur impossible hors même origine (sonde `/up` hors CORS, en-têtes de signature refusés) | Sonde sur `/api/v1/device/key`, en-têtes `X-SAIE-*` autorisés par CORS. Limite : le serveur cible doit partager la base (même jeton) | Typage du lecteur |
| 4.1-a (A), §14-9 (C) | Tableau de bord, prévisualisations et compte d'incidents non bornés au périmètre | Bornés au périmètre ; un agent ne voit plus l'état du parc | `…test_dashboard_and_previews_are_bounded_to_the_scope` |
| 10.2, 10.9b, 10.9c (B) | Le lecteur réel écrasait chaque seconde l'entrée en lecture : « En diffusion » et contenu en cours jamais visibles | Le mode « programme » ne remplace plus l'entrée déclarée | e2e `screens-scenarios.spec.ts` renforcé (état « En diffusion » et campagne en cours attendus) |
| 10.16 (B) | Faux incident « lecture arrêtée » en repli, en alerte ou lecteur suspendu | Règle limitée aux diapositives programmées, hors suspension | `…test_no_false_stalled_or_prolonged_fallback_incidents` |
| 10.19 (B) | Faux « repli prolongé » sur lecteur suspendu ou écran hors service | Exclus | Idem |
| 10.16 (B) | « Lecteur arrêté » intermittent (agent à 60 s, fraîcheur 45 s) | Agent à 30 s, fraîcheur jugée sur le délai « hors ligne » | `…test_an_agent_beating_every_minute_keeps_the_stopped_player_state` |
| 18.5, 11.10 (B) | Signature activée : médias non signés, horloge non corrigée, refus pris pour une révocation définitive | Médias signés, horodatage sur l'horloge corrigée, refus de signature traité comme erreur passagère | `DeviceSecurityOptionsTest` (serveur) ; typage et tests du lecteur |
| §14-9 (C) | Profils de configuration et versions du lecteur modifiables par une habilitation limitée | Réservés aux habilitations sans restriction de périmètre | `…test_fleet_wide_operations_require_an_unrestricted_assignment` |
| §14-8 (C) | Suppression d'un groupe en erreur 500 (clé étrangère des habilitations) | Habilitation active : refus 409 ; révoquée : référence retirée ; membres journalisés | `…test_group_deletion_and_history_classification` |
| §13.1-b (C) | Toute modification faite depuis l'interface classée « Configuration » | « Configuration » seulement si le profil change réellement | Idem |
| §13.3-b, 5.5-h (A, C) | Filtres bâtiment et état opérationnel absents de la liste ; export sans bâtiment ni code d'inventaire | Filtres ajoutés ; export transmis par bâtiment, colonnes inventaire, interfaces, mode | Typage |

### Détail du second contrôle (rapports des contrôleurs)

## Contrôle n° 2 (indépendant, lecture seule) — Partie A : Gestion des écrans, §4 à §8

Date : 2026-11-08. Méthode : relecture du code actuel (backend, frontend, lecteur, agent), `php artisan route:list --path=screen` (26 routes). Aucun test exécuté (tests lus seulement), aucune requête en base. Les sections « Suivi des corrections », « Corrections postérieures » et « Lots 20 à 25 » ont été traitées comme des déclarations à vérifier.

Abréviations : SC = `backend/app/Http/Controllers/Api/V1/ScreenController.php`, PR = `backend/app/Support/Present.php`, FO = `backend/app/Services/FleetOverview.php`, DG = `backend/app/Services/DynamicGroups.php`, MB = `backend/app/Services/ManifestBuilder.php`, SP = `backend/app/Models/ScreenProfile.php`, SM = `backend/app/Models/Screen.php`, EXS = `backend/app/Services/ExportService.php`, DC = `backend/app/Http/Controllers/Api/V1/DashboardController.php`, RC = `backend/app/Http/Controllers/Api/V1/ReferentialController.php`, FOV = `frontend/src/pages/display/FleetOverview.tsx`, SCR = `frontend/src/pages/display/Screens.tsx`, SD = `frontend/src/pages/display/ScreenDetail.tsx`, GR = `frontend/src/pages/display/Groups.tsx`, REF = `frontend/src/pages/admin/Referentials.tsx`, ENG = `player/src/engine.ts`, PLY = `player/src/Player.tsx`. Tests : `T/` = `backend/tests/Feature/`, `E/` = `frontend/e2e/`.
Statuts : C Conforme, P Partiel, A Absent, D Défectueux, H dépend d'un matériel indisponible.

### §4 Tableau de bord

| Réf | Exigence | Statut précédent | Statut actuel | Preuve (fichier:ligne, test) | Écart restant |
|---|---|---|---|---|---|
| 4.1-a | Nombre total d'écrans | C | **P** | FO:27-28,40 (hors archivés, borné au périmètre) ; FOV:43 ; T/FleetOverviewTest::test_indicators_are_computed_from_real_states_and_link_to_filtered_lists (total=4) et ::test_the_overview_is_bounded_to_the_scope | La page « Tous les écrans » affiche un second total et un synoptique tirés de `/dashboard` (SCR:48,54-61,104-119) qui **ne sont pas bornés au périmètre** (DC:42-54 : aucun filtre de périmètre) et comptent seulement les écrans `active`. Un gestionnaire de zone voit les effectifs et niveaux de tout le parc alors que sa liste est bornée (SC:53-56) |
| 4.1-b | En ligne | C | C | FO:41 ; FOV:44 → `?network=online` ; SC:73-84 ; même test (online=2) | — |
| 4.1-c | Hors ligne | C | C | PR:111 ; FO:42 ; FOV:46 `?state=offline` ; SC:86-92 | Aucun test avec un écran réellement hors ligne (offline=0) |
| 4.1-d | En anomalie | C | C | PR:112-114 ; SC:86-92 ; T/ScreenModuleIntegrityTest::test_a_manual_incident_marks_the_screen_in_anomaly_and_old_player_errors_do_not ; T/FleetOverviewTest (`?state=anomaly`) | — |
| 4.1-e | En diffusion | C | C | PR:102,119 (lecture déclarée < 15 min) ; FO:43 ; FOV:45 ; T/SupervisionStatesTest::test_operational_states_distinguish_broadcasting_standby_pause_and_fallback | Lecture déclarée, pas preuve d'image (affiché comme tel) |
| 4.1-f | Inactifs | C | C | FO:45 ; FOV:51 `?state=disabled` ; T/FleetOverviewTest (`state=pending_configuration,disabled`) | — |
| 4.1-g | Non configurés | C | C | FO:46 ; FOV:48 ; E/screens-overview.spec.ts:15 | = sans lecteur (pas « sans programme ») |
| 4.1-h | Alertes techniques par gravité | C | C | FO:50 ; FOV:63-66 ; T/FleetOverviewTest (critical=1) | Carte vers `/supervision` sans filtre de gravité |
| 4.2-a | Répartition bâtiment/étage | C | C | FO:52-53 ; FOV:69-70 | `by_building` non asserté |
| 4.2-b | Répartition par état | C | C | FO:51 ; FOV:73 | Lignes non cliquables |
| 4.2-c | Évolution du taux de disponibilité | C | C | FO:104-119 ; FOV:77 ; T/FleetOverviewTest (14 points) | Joignabilité du lecteur, pas disponibilité dalle |
| 4.2-d | Écrans les plus sollicités | C | C | FO:127-137 ; FOV:81-86 ; même test (`most_played`) | — |
| 4.2-e | Historique des incidents | C | C | FO:145-154 ; FOV:78 ; même test (30 points) | Seule la gravité « critique » est ventilée |
| 4.2-f | Répartition par type | C | C | FO:54-55 ; FOV:74 | Non assertée |
| 4.2-g | État des lecteurs | C | C | FO:84-95 ; FOV:88-95 | — |
| 4.2-h | Cartes cliquables vers listes filtrées | C | C | FOV:42-66 (liens) ; SC:86-92 ; SCR:19 (`state` lu dans l'URL) ; E/screens-overview.spec.ts | Arrivé sur `?state=…`, la liste n'a **aucun sélecteur « état »** (SCR:126-137) : le filtre actif est invisible (seul « Réinitialiser » s'active) ; synoptique SCR:103-120 bicolore |
| 4.3-a | Sélection d'étage | C | C | FOV:162-165 ; E/screens-overview.spec.ts | — |
| 4.3-b | Salles/zones et écrans repérés sur plan | P | **P** | Saisie de la position désormais présente : SD:458-459 → SC:956 (`plan_x/plan_y` 0–100) ; plan du niveau REF:34 ; épingles FOV:170-179 ; T/ScreenInventoryTest::test_the_full_record_is_stored_and_each_move_is_historised (plan.x) | Salles et zones **non dessinées** sur le plan (FO:166-170 : simple liste) ; plan affiché seulement si au moins un écran est positionné (FOV:170) ; position non importable (SC:417-418) ; aucun e2e |
| 4.3-c | États par couleur + icône | C | C | FOV:29-34, 183-186 | — |
| 4.3-d | Ouverture de la fiche depuis la vue | C | C | FOV:174,183 | — |
| 4.3-e | Filtre anomalies | C | C | FOV:155,166 | — |

### §5 Inventaire des écrans

| Réf | Exigence | Statut précédent | Statut actuel | Preuve (fichier:ligne, test) | Écart restant |
|---|---|---|---|---|---|
| 5.1-a | Identifiant unique automatique | C | C | uuid (HasUuids) | — |
| 5.1-b | Code d'inventaire | P | **C** | `inventory_code` unique (migration 2026_11_08_173215:19) ; SC:941 ; SD:108,453 ; import SC:417 ; T/InventoryLogsAndTraceabilityTest::test_inventory_code_unique_serial_and_extended_search ; E/screens-inventory-logs.spec.ts:36 | Pas de numérotation automatique ; **absent de l'export d'inventaire** (EXS:360 : pas de colonne) |
| 5.1-c | Nom | C | C | SC:915 ; SCR:196 | — |
| 5.1-d | Description | C | C | PR:69 ; SD:113,460 | Non assertée |
| 5.1-e | Catégorie | P | **C** | table `screen_categories` (migration lot20:18) ; SC:951, filtre SC:64 ; REF:21 ; SD:109,403-406 ; SCR:129 ; EXS:362 ; T/ScreenClassificationAndCommissioningTest::test_screen_classification_and_filters | Non importable |
| 5.1-f | Statut administratif | C | C | SC:929, SC:561-585 ; SD:112 ; T/ScreenModuleIntegrityTest::test_archiving_withdraws_the_screen_and_keeps_its_record | — |
| 5.1-g | Date d'acquisition | C | C | SC:946 ; SD:141,454 ; T/ScreenInventoryTest::test_the_full_record_is_stored… | — |
| 5.1-h | Date de mise en service | C | C | `installed_on` SD:131,448 ; mise en service formelle SC:265-291, SD:99,111 ; T/ScreenClassificationAndCommissioningTest::test_commissioning_requires_facts_and_a_visual_check | — |
| 5.1-i | Fournisseur | C | C | SD:142,455 ; même test | — |
| 5.1-j | Référence de commande | C | C | SD:142,456 | — |
| 5.1-k | Garantie | C | C | PR:75,78 ; SD:132,449 | — |
| 5.1-l | Documents associés | C | C | SC:504-527 ; SD:206-250 ; T/ScreenInventoryTest::test_documents_can_be_linked_to_a_screen | — |
| 5.2-a | Fabricant/marque/modèle | C | C | PR:73 ; SD:126,430-431 | Marque = fabricant |
| 5.2-b | N° de série | P | **C** | index unique `lower(serial_number)` (migration 2026_11_08_173215:21) ; contrôle SC:935-939 ; T/InventoryLogsAndTraceabilityTest (doublon insensible à la casse refusé) | — |
| 5.2-c | Taille | C | C | SC:942 ; SD:128 | — |
| 5.2-d | Résolution | C | C | SC:920 ; SD:116 ; SD:826 compare à `reported_resolution` | — |
| 5.2-e | Orientation | C | C | SC:919 | — |
| 5.2-f | Technologie | C | C | SC:947 ; SD:133,441 | — |
| 5.2-g | Connectivité/interfaces | P | **C** | `interfaces` liste fermée SC:952 ; SD:136,416-426 ; T/ScreenClassificationAndCommissioningTest::test_screen_classification_and_filters (valeur hors liste refusée) | Non importable |
| 5.2-h | Adresse MAC | C | C | SC:948,969-971 ; CHECK en base ; SD:135,445 | — |
| 5.2-i | Adresse IP | P | **C** | saisie SC:942 ; IP observée `devices.observed_ip` PR:143, SD:554 ; T/InventoryLogsAndTraceabilityTest::test_heartbeat_records_observed_address_losses_and_version_changes | — |
| 5.2-j | OS | C | C | SD:137,446 | Saisie manuelle |
| 5.2-k | Firmware | C | C | SD:137,447 | Saisie manuelle |
| 5.2-l | Mode de fonctionnement | P | **P** | `operating_mode` (permanent/plage/à la demande) SC:953 ; SD:110,411-414 ; même test | Champ purement déclaratif : « Permanent (24 h/24) » n'a aucun effet et n'est pas contrôlé contre la plage réellement appliquée (PR:54, SM:114-124) — un écran « permanent » s'éteint à 18 h sans alerte d'incohérence |
| 5.2-m | Consommation | C | C | SC:947 ; SD:134,444 | — |
| 5.3-a | Bâtiment | C | C | PR:61 ; SD:143 ; filtre API SC:67 | Filtre bâtiment absent de l'interface (SCR:19 n'a pas `building_id`) |
| 5.3-b | Étage | C | C | PR:60 ; SD:118 | — |
| 5.3-c | Zone | C | C | PR:63 ; SD:119 | Zone de diffusion |
| 5.3-d | Salle | C | C | SC:918, SC:974-980 `ROOM_FLOOR_MISMATCH` ; SCR:213-218 ; T/ScreenInventoryTest (422) | — |
| 5.3-e | Emplacement précis | C | C | SD:143,457 | — |
| 5.3-f | Type d'espace | P | **C** | PR:62 ; SD:117 ; REF:39 | Pas de test d'affichage |
| 5.3-g | Responsable de l'espace | P | **C** | REF:40 ; SC:128-131 ; SD:118 | Aucun test n'asserte `location_extra.manager` |
| 5.3-h | Photo de l'emplacement | P | **C** | REF:41 ; SC:130 ; SD:145-150 | Aucun test |
| 5.3-i | Référence sur plan | P | **C** | SD:458-459 ; SC:956 ; PR:70 ; CHECK 0–100 ; T/ScreenInventoryTest (plan.x=42.5) | Non importable ; aucun e2e |
| 5.4-a | Lecteur associé | C | C | index `devices_one_active_per_screen` ; SD:474+ | — |
| 5.4-b | Groupe | C | C | PR:66 ; SD:121 | Édition depuis Groupes seulement |
| 5.4-c | Profil de configuration | C | C | SC:955 ; SM:137-145 ; SD:461-466 ; T/ScreenProfilesTest::test_the_screen_profile_overrides_the_group_profile_and_reaches_the_manifest | — |
| 5.4-d | Playlist/campagne affectées | C | C | SC:587-604 ; affectation directe `AssignPlaylistDialog` (SD:13) | — |
| 5.4-e | Calendrier | C | C | PR:54 ; SM:114-124 | `window_source` non asserté |
| 5.4-f | Priorité | P | **C** | `criticality` SC:953, filtre SC:65 ; SD:110,408-410 ; SCR:130 ; T/ScreenClassificationAndCommissioningTest::test_screen_classification_and_filters | Criticité sans effet sur l'escalade des incidents (non vérifié comme exigé) |
| 5.4-g | Dernière communication | C | C | PR:134 ; SCR:158 | — |
| 5.4-h | Dernière diffusion confirmée | P | **C** | SC:122-126 ; SD:115 ; T/InventoryLogsAndTraceabilityTest::test_last_confirmed_play_resolved_playback_and_technical_logs | Rapprochement par `code` d'écran dans le JSON du manifeste (un changement de code perd l'historique) |
| 5.5-a | Création | C | C | SC:157-172 ; SCR:234-265 | — |
| 5.5-b | Modification | C | C | SC:293-334 ; T/ScreenModuleIntegrityTest::test_a_zone_manager_cannot_reactivate_or_move_a_screen_outside_the_zone | — |
| 5.5-c | Consultation | C | C | SC:50-56, 114-117 ; T/ScreenModuleIntegrityTest::test_a_zone_manager_lists_only_the_screens_of_the_zone | — |
| 5.5-d | Archivage | C | C | SC:561-585 ; SD:101,165-174 ; T/ScreenModuleIntegrityTest::test_archiving… ; E/screens-inventory.spec.ts | — |
| 5.5-e | Activation/désactivation | C | C | SC:302-307,328-330 (motif obligatoire) ; SD:385-394 ; T/InventoryLogsAndTraceabilityTest::test_deactivation_needs_a_reason_and_updates_keep_before_and_after | — |
| 5.5-f | Import Excel/CSV | P | **D** | CSV + XLSX SC:341-412 ; `ImportScreensDialog.tsx:45-48` ; T/ScreenInventoryTest::test_csv_import_… et ::test_an_excel_workbook_is_imported_like_a_csv_file | **Le modèle téléchargeable est faux** : SC:425-426, l'en-tête a 30 colonnes, la ligne d'exemple 27 valeurs, décalées de 3 depuis l'ajout de `inventory_code, criticality, operating_mode` (« Face aux ascenseurs » → `criticality`, « Samsung » → `room_code`, « QM55B » → `location_detail`…). Importer l'exemple tel quel est refusé (salle « Samsung » introuvable, criticité invalide). Aucun test ne lit le modèle. Colonnes catégorie, interfaces, profil, groupe, position absentes |
| 5.5-g | Export inventaire | C | C | EXS:58,336-370 (pdf/csv/xlsx, bâtiment/niveau/archivés) ; SCR:171-175 ; T/ScreenInventoryTest::test_the_inventory_report_is_bounded… ; T/ScreenClassificationAndCommissioningTest::test_screen_sheet_and_export_parameters_are_kept | Pas de colonne code d'inventaire, interfaces ni mode (EXS:360) ; l'interface ne transmet que le niveau (SCR:172) |
| 5.5-h | Recherche multicritère | P | **C** | SC:54-72 (q sur code, nom, inventaire, série, marque, modèle, emplacement, salle, lecteur ; salle, bâtiment, catégorie, criticité, mise en service) ; SCR:125-137 ; T/InventoryLogsAndTraceabilityTest::test_inventory_code_unique_serial_and_extended_search ; E/screens-inventory-logs.spec.ts | Pas de sélecteur « état opérationnel » ni « bâtiment » dans l'interface |
| 5.5-i | Filtres étage/salle/type/statut | P | **C** | SC:58-63 ; SCR:127,128,135,136 ; même test (`room_id`) | — |
| 5.5-j | Impression des fiches | P | **C** | EXS:70,434-476 (PDF16, borné) ; SD:151-153,160-164 ; T/ScreenClassificationAndCommissioningTest::test_screen_sheet_and_export_parameters_are_kept ; E/screens-lots20-25.spec.ts:53-54 | — |
| 5.5-k | QR Code | C | C | SC:489-498 ; SD:252-285 ; T/ScreenInventoryTest::test_the_screen_qr_code_opens_the_record_for_authorised_accounts_only | — |

### §6 Localisation

| Réf | Exigence | Statut précédent | Statut actuel | Preuve (fichier:ligne, test) | Écart restant |
|---|---|---|---|---|---|
| 6-a | Hiérarchie Institution→…→Écran paramétrable | P | **P** | Institutions REF:19, sites REF:27 ; salle rattachable à un emplacement (`RoomDetail.tsx:288`, RoomController:243) ; T/ScreenClassificationAndCommissioningTest::test_institution_level_and_room_attached_to_a_location | Niveaux figés ; rattachement salle→emplacement facultatif ; la fiche écran ne contrôle que le **niveau** de la salle (SC:974-980), pas que la salle appartient à l'emplacement : un écran peut être à l'emplacement A et dans une salle rattachée à l'emplacement B |
| 6-b | CRUD bâtiments | C | C | RC ; REF:28 | Désactivation, pas de suppression |
| 6-c | CRUD étages | C | C | REF:31 | idem |
| 6-d | CRUD salles | C | C | `pages/rooms/NewRoomDialog.tsx`, `RoomDetail.tsx` ; T/SettingsEffectTest | — |
| 6-e | CRUD emplacements | C | C | REF:35-42 | — |
| 6-f | Associer/déplacer un écran | C | C | SC:309-326 ; T/ScreenModuleIntegrityTest::test_a_zone_manager_cannot_reactivate_or_move… | — |
| 6-g | Historique des déplacements | C | C | SC:988-995, 135-139 ; SD:180-200 ; T/ScreenInventoryTest::test_the_full_record… ; E/screens-inventory.spec.ts | — |
| 6-h | Nombre d'écrans par emplacement | P | **C** | RC:43 `withCount('screens')` ; REF:167 ; T/InventoryLogsAndTraceabilityTest (screens_count=2) | Pas de décompte par salle |
| 6-i | Plan par étage | P | **C** | plan déposable REF:34 ; position saisissable SD:458-459 ; FOV:170-180 | Plan non affiché tant qu'aucun écran n'est positionné (FOV:170) ; aucun e2e |
| 6-j | Un seul emplacement actif, historisé | C | C | index unique partiel `screen_location_history_one_current` ; SC:990 ; audit SC:326 | — |

### §7 Configuration technique

| Réf | Exigence | Statut précédent | Statut actuel | Preuve (fichier:ligne, test) | Écart restant |
|---|---|---|---|---|---|
| 7.1-a | Résolution | P | **P** | SD:826 (« non pilotée », sortie déclarée comparée) ; `views.tsx:37-44` (mise à l'échelle) | Non pilotable ; signalée seulement sur la fiche ; pas dans le profil ni dans le compte rendu `unsupported` |
| 7.1-b | Orientation | P | **C** | SP:33 `software_rotation` ; PLY:98 ; `views.tsx:44` rotate(90deg) ; SD:827 | Aucun test du comportement (le dépôt `player/` n'a aucun test, contrairement au « tests du lecteur » déclaré) ; `software_rotation` toujours déclaré « appliqué » (ENG:451) |
| 7.1-c | Luminosité | H | H | SP:30 ; ENG:446 ; agent ne déclare jamais `panel_picture` (`player/agent/panel-agent.mjs:156`) → toujours « non pris en charge » ; T/ScreenProfilesTest::test_the_player_confirms_the_applied_configuration_and_reports_unsupported_settings | Pilote d'image absent |
| 7.1-d | Contraste | H | H | SP:31 ; ENG:446 | idem |
| 7.1-e | Volume | C | C | SP:22 ; MB:150 ; `views.tsx:92` ; T/ScreenProfilesTest (max_volume) | — |
| 7.1-f | Veille et durée d'inactivité | P | **H** | SP:28,35 ; ENG:184-189 (veille hors plage et après N min de repli) ; ENG:757 ; T/DeviceFleetManagementTest::test_profile_settings_and_allowed_servers (validation seulement) | Effet réel dépend de l'agent CEC/RS-232 (pilote simulé) ; aucun test de comportement |
| 7.1-g | Plein écran | C | C | SP:29 ; ENG:448,458-461 ; E/screens-profiles.spec.ts | — |
| 7.1-h | Fréquence de rafraîchissement | C | C | SP:25-26 ; ENG:178,190 | — |
| 7.1-i | Fuseau horaire | P | **C** | SM:64-70 `effectiveTimezone` (fuseau du site) ; MB:122,138 ; PLY:97 ; saisie REF:26 ; T/DeviceSecurityOptionsTest::test_the_manifest_uses_the_site_timezone | Saisie libre (validée côté modèle seulement à l'usage) |
| 7.1-j | Langue | C | C | SP:23-24 ; PLY:104 | — |
| 7.2-a | IP / DHCP / fixe | P | P | IP saisie + IP observée (PR:143) | Mode d'adressage non décrit ni mesuré |
| 7.2-b | MAC | C | C | SC:948 ; SD:135,565 | — |
| 7.2-c | Serveur | A | **D** | SP:37 ; validation liste blanche ScreenProfileController:68 ; ENG:103-104, 620-633 ; `ScreenProfiles.tsx:136` ; T/DeviceFleetManagementTest::test_profile_settings_and_allowed_servers | **Bascule inopérante vers un autre serveur** : la sonde `fetch(${target}/up)` (ENG:625) est une requête d'origine croisée alors que `/up` n'est pas dans `config/cors.php` (`paths: api/*, sanctum/csrf-cookie`) ; et les requêtes du lecteur portent toujours `X-SAIE-Timestamp/Nonce/Body-Hash/Signature` (ENG:204,792) absents de `allowed_headers` : le pré-contrôle CORS échoue. Le jeton émis par le serveur d'origine n'est de plus valable que sur une base partagée. Le paramètre se règle dans l'interface mais ne peut produire d'effet hors même origine ; aucun test du lecteur |
| 7.2-d | Fréquence de synchronisation | C | C | SP:25 ; ENG:190 ; E/screens-profiles.spec.ts | — |
| 7.2-e | Intervalle de remontée d'état | C | C | SP:26 ; ENG:178 | — |
| 7.2-f | Politique de reconnexion | C | C | SP:27 ; ENG:191 | Pas de test backend |
| 7.2-g | Authentification du lecteur | C | C | SC:609-648 ; rotation SC:251-263 ; T/DeviceTokenRotationTest | — |
| 7.3-a | Profils réutilisables | C | C | SP ; `ScreenProfiles.tsx` ; T/ScreenProfilesTest (4) ; E/screens-profiles.spec.ts | — |
| 7.3-b | Affectables écran ou groupe | C | C | SD:461-466 ; GR:183-188 ; T/ScreenProfilesTest::test_the_screen_profile_overrides… | — |
| 7.3-c | Paramètres non supportés signalés | C | C | ENG:440-455 ; SD:821 ; T/ScreenProfilesTest::test_the_player_confirms… | Résolution hors profil (7.1-a) |
| 7.3-d | État de synchronisation + confirmation | C | C | SC:142-151 ; SD:813-818 | Fiche seulement ; pas d'alerte de divergence prolongée |

### §8 Groupes

| Réf | Exigence | Statut précédent | Statut actuel | Preuve (fichier:ligne, test) | Écart restant |
|---|---|---|---|---|---|
| 8-a | CRUD groupes | C | C | SC:774-876 ; liste bornée SC:779-782 ; GR ; T/ScreenModuleIntegrityTest::test_an_empty_group_of_another_structure_cannot_be_deleted_by_a_zone_manager ; T/ScopedReadsTest | — |
| 8-b | Ajout/retrait d'écrans | C | C | SC:849-855 ; GR:141-145,239-259 | Sélecteur chargé à 500 écrans au plus (`shared.tsx:172`) |
| 8-c | Composition | C | C | SC:813 | — |
| 8-d | Playlist/campagne au groupe | C | C | MB (cibles `group`) ; `AssignPlaylistDialog` GR:72 ; E/screens-scenarios.spec.ts | — |
| 8-e | Configuration commune | C | C | GR:152-155 (profil, plage, `ignore_holidays` conservé) ; T/ScreenProfilesTest | Conservation de `ignore_holidays` non testée |
| 8-f | Statistiques du groupe | P | **C** | SC:786-812 (joignabilité, lectures, incidents 7 j) ; GR:53-54 ; T/DeviceFleetManagementTest::test_group_statistics_over_seven_days ; T/DynamicGroupsTest::test_group_listing_reports_member_statistics | — |
| 8-g | Supervision des membres | C | C | SupervisionController `by_group` ; T/SupervisionStatesTest::test_heartbeat_records_declared_characteristics_and_supervision_groups_by_floor_and_group | Modale « Cibles effectives » GR:266-296 calculée côté client sur 500 écrans au plus |
| 8.3 | Groupes dynamiques | C | C | DG:18-66 ; recalcul `SaieTick.php:141` ; GR:216-237 ; T/DynamicGroupsTest (4) ; E/screens-groups.spec.ts | Critères salle et campagne gérés par l'API (DG:23,27) mais absents de l'interface (GR:219-226) ; pas de critère « état opérationnel » |

### Décompte

Lignes : 113 (§4 : 21 ; §5 : 53 ; §6 : 10 ; §7 : 21 ; §8 : 8).

| Statut | Contrôle précédent | Contrôle actuel |
|---|---|---|
| Conforme (C) | 84 | **102** |
| Partiel (P) | 26 | **6** |
| Absent (A) | 1 | **0** |
| Défectueux (D) | 0 | **2** |
| Matériel (H) | 2 | **3** |
| **Total** | 113 | **113** |

Par section : §4 19 C / 2 P ; §5 51 C / 1 P / 1 D ; §6 9 C / 1 P ; §7 15 C / 2 P / 3 H / 1 D ; §8 8 C.
Taux de conformité A : 102 / 113 = 90,3 % (92,9 % avec les 3 dépendances matérielles).

Changements de statut : 19 lignes passent de P à C (5.1-b, 5.1-e, 5.2-b, 5.2-g, 5.2-i, 5.3-f, 5.3-g, 5.3-h, 5.3-i, 5.4-f, 5.4-h, 5.5-h, 5.5-i, 5.5-j, 6-h, 6-i, 7.1-b, 7.1-i, 8-f) ; 7.1-f passe de P à H ; régressions : 4.1-a C→P, 5.5-f P→D, 7.2-c A→D. Contrôle : 84 + 19 − 1 = 102 C.

### Défauts trouvés

1. **D — 5.5-f, modèle d'import faux.** `ScreenController.php:415-418` définit 8 colonnes obligatoires + 22 facultatives (30) ; la ligne d'exemple de `importTemplate()` (`ScreenController.php:426`) ne compte que 27 valeurs, écrites avant l'ajout de `inventory_code`, `criticality`, `operating_mode` en tête des colonnes facultatives. Correspondance mesurée : `criticality = "Face aux ascenseurs"`, `room_code = "Samsung"`, `location_detail = "QM55B"`, `notes = "SN0001"`, `manufacturer = "55"`, `serial_number = "ethernet"`, `technology = "00:1A:2B:3C:4D:5E"`, `mac_address = "120"`, `acquired_on = "Fournisseur SA"`, `warranty_until`/`supplier`/`order_reference` absents. Réimporter le modèle fourni échoue (« Salle Samsung introuvable à ce niveau », `importLine` SC:443-445). Aucun test ne porte sur le modèle.
2. **D — 7.2-c, bascule de serveur inopérante hors même origine.** Le lecteur sonde `${target}/up` (`player/src/engine.ts:625`) en requête d'origine croisée ; `backend/config/cors.php` limite CORS à `api/*` et `sanctum/csrf-cookie`, donc `/up` ne renvoie pas d'en-tête CORS et la sonde échoue → « Serveur de rattachement injoignable » et aucune bascule. Même si elle aboutissait, chaque appel signé porte `X-SAIE-*` (`engine.ts:204,792`), absents de `allowed_headers` (cors.php) : le pré-contrôle CORS serait refusé. Le paramètre est réglable dans `ScreenProfiles.tsx:136` mais ne peut produire d'effet que si le « serveur autorisé » est la même origine. Couverture : validation de la liste blanche seulement (T/DeviceFleetManagementTest::test_profile_settings_and_allowed_servers).
3. **P (défaut de périmètre) — 4.1-a.** `DashboardController.php:42-54` calcule les effectifs et le synoptique par niveau sur tout le parc, sans périmètre ; `Screens.tsx:48,54-61,104-119` les affiche en tête de la liste, elle bornée (SC:53-56). Un gestionnaire de zone voit le nombre d'écrans et les niveaux de tout le parc, et des chiffres incohérents avec sa liste et avec la vue d'ensemble (FO bornée).

### Déclarations non confirmées

- « Lots 20-25 : 7.1-a, 7.1-b … Typage ; tests du lecteur » : le dossier `player/src` ne contient aucun fichier de test ; rotation, veille d'inactivité et bascule de serveur n'ont qu'une validation serveur.
- « 5.1-b … importable, affiché » : vrai pour la fiche et l'import, mais l'export d'inventaire (EXS:360) n'a pas de colonne code d'inventaire.
- « 5.5-h, 5.5-i … filtres `room_id` et `building_id` » : `building_id` n'existe que dans l'API, pas dans l'interface (SCR:19).
- « 6-a … salle rattachable à un emplacement de son niveau » : rattachement facultatif et non vérifié contre l'emplacement de l'écran (SC:974-980).

## Contrôle 2 (indépendant, lecture seule) : partie B, lecteurs, supervision, commandes, protocole et sécurité

Date : 8 novembre 2026. Méthode : lecture du code actuel, `php artisan route:list`, lecture SELECT en base (`settings`, `pg_constraint`, colonnes de `devices`). Aucun test n'a été lancé : les tests sont cités, pas réexécutés.

Abréviations :
- DAC = `backend/app/Http/Controllers/Api/V1/DeviceApiController.php`
- SC = `backend/app/Http/Controllers/Api/V1/ScreenController.php`
- RCS = `backend/app/Services/RemoteCommands.php`
- PR = `backend/app/Support/Present.php`
- TICK = `backend/app/Console/Commands/SaieTick.php`
- VDS = `backend/app/Http/Middleware/VerifyDeviceSignature.php`
- LDE = `backend/app/Http/Middleware/LogDeviceExchange.php`
- SET = `backend/app/Models/Setting.php`
- ENG = `player/src/engine.ts`, STO = `player/src/storage.ts`, PLY = `player/src/Player.tsx`
- AG = `player/agent/panel-agent.mjs`
- SD = `frontend/src/pages/display/ScreenDetail.tsx`, CP = `frontend/src/pages/display/CommandsPanel.tsx`

Tests (tous dans `backend/tests/Feature/`) : DT = DeviceTest, RCT = RemoteCommandsTest, SST = SupervisionStatesTest, FIT = FleetAndIncidentTest, DFM = DeviceFleetManagementTest, FSR = FleetSuspensionRolloutTelemetryTest, ILT = InventoryLogsAndTraceabilityTest, SAE = SupervisionAgentAndExchangesTest, DSO = DeviceSecurityOptionsTest, DRC = DeviceReassignAndConflictsTest.

Paramètres relevés en base (`settings`) : seules les lignes `supervision.suspect_after_s = 45` et `supervision.offline_after_s = 300` existent. Aucune ligne `devices.*`, `retention.*` ni `releases.*` : les valeurs par défaut de SET l.65-67 et l.78 s'appliquent. Liste blanche vide, signature désactivée, chiffrement désactivé, purge automatique désactivée (aucune variable `SAIE_RETENTION_AUTO_PURGE` dans `.env`).
Contraintes : `devices_status_check` = {active, revoked}. `device_commands_status_check` couvre 7 états. `device_commands_command_check` couvre 10 commandes. `incidents_severity_check` = {critical, major, medium, minor}.

### Matrice

| Réf | Exigence | Statut précédent | Statut actuel | Preuve (fichier:ligne, test) | Écart restant |
|---|---|---|---|---|---|
| 9.1 | Distinction écran physique / lecteur | C | C | Tables `screens` et `devices` distinctes. Identité propre du lecteur (SC:235-245, SD:535). Onglet Lecteur dans SD. `FleetRolesAndHistoryTest::test_the_screen_history_gathers_every_kind_of_event` | — |
| 9.2 | Infos lecteur (id, nom, type, fabricant, série, IP, MAC, OS, version, firmware…) | P | P | Nom, type et série saisis (SC:235-245, `DFM::test_device_identity_and_forced_credential_rotation`). IP, MAC, OS et hostname fournis par AG `system()` (AG:51-71), joints au battement (ENG:376), validés (DAC:136-147), affichés (SD:560-568, 729-742). IP observée par le serveur (DAC:159). | Le firmware n'est jamais mesuré : AG ne l'envoie pas, il reste saisi à la main sur la fiche écran (SD:447). MAC, OS et IP n'existent que si l'agent est installé. `hardware_id` reste un UUID logiciel auto-déclaré (ENG:120). |
| 9.3 | Enrôler un lecteur | C | C | DAC:38-61, SC:609-648, appairage depuis `Pairing.tsx`. `DT::test_one_time_pairing_flow_delivers_individual_token_once` | — |
| 9.4 | Associer / désassocier | P | C | Association : SC:609. Réaffectation sans révocation (SC:655-689 : périmètre des deux écrans, commandes ouvertes annulées). Désassociation par révocation (SC:723). Bouton et `ReassignDeviceDialog.tsx`. `DRC::test_a_player_moves_to_another_screen_without_being_revoked`, `…respects_the_target_screen` ; e2e `screens-reassign.spec.ts` | Un lecteur ne peut pas être remis en stock sans écran : la seule sortie est la révocation. |
| 9.5 | Code d'appairage à durée limitée | C | C | DAC:50 (invalidation des codes précédents), DAC:57 (TTL). `DT::test_expired_pairing_code_is_refused` | — |
| 9.6 | Renouveler les identifiants | P | C | Renouvellement forcé : SC:251-263 → `rotate_token` dans la réponse au battement (DAC:183) → ENG:382 → DAC:300-311 (ancien jeton valable 10 min). Bouton dans SD:577. `DFM::test_device_identity_and_forced_credential_rotation`, `DeviceTokenRotationTest` | — |
| 9.7 | Révoquer | C | C | SC:723-734, middleware `EnsureDevice`, bouton dans SD:583. `DT::test_revoked_device_is_refused_by_the_api` | Le cache reste lisible jusqu'à l'expiration de l'autorisation (comportement documenté). |
| 9.8 | Mettre à jour l'application | P | C | Version épinglée sur un lecteur (`PATCH devices/{id}/release`, SD:578, 691-704). Suivi par lecteur (`GET player-releases/{id}/deployment`, `PlayerReleases.tsx`:78). `DFM::test_a_release_can_be_pinned_on_one_device`, `FSR::test_a_partial_rollout_reaches_a_stable_share_of_the_channel` | — |
| 9.9 | Redémarrer l'application | C | C | `restart_player` (RCS:30) → `pending_restart` puis `confirmRestart` après la relance (ENG:545-563, 606-614). `FIT::test_remote_reload_is_queued_redelivered_until_acknowledged_and_traced` ; e2e `screens-commands.spec.ts` | La page est rechargée (`location.reload`) : le processus du navigateur n'est pas relancé. |
| 9.10 | Synchroniser à la demande | C | C | RCS:28, ENG:531-535. `RCT::test_a_command_goes_through_sent_received_and_executed_with_a_report` ; e2e `screens-commands.spec.ts` | — |
| 9.11 | Vérifier l'espace disque | P | C | AG `statfs` (AG:53-57) → `disk_total_gb/disk_free_gb` (DAC:146) → affiché dans SD:739. Le disque prime sur le quota dans la règle (TICK:245, 262). `FSR::test_agent_telemetry_is_stored_and_drives_disk_and_temperature_anomalies` | Mesure disponible seulement avec l'agent ; sinon quota du navigateur. Pas de vérification à la demande (relevé périodique). |
| 9.12 | Consulter les journaux techniques | P | C | `GET devices/{id}/logs` : événements, télémétrie, échanges, paginés et bornés au périmètre (SC:204-225), panneau SD:996-1015. Commande `logs` (ENG:587-599, CP:125-132). `ILT::test_last_confirmed_play_resolved_playback_and_technical_logs` | Ni journaux système ni journaux du navigateur. |
| 9.13 | Détecter les versions obsolètes | C | C | Règle `outdated_version` (TICK:287-289), compteur `outdated` (`FleetOverview.tsx`:94). `SST::test_stalled_playback_repeated_errors_and_outdated_versions_open_incidents` | Aucun indicateur sur la fiche lecteur (PR:130-146). |
| 9.14 | Enrôlement sécurisé | P | P | Liste blanche livrée (DAC:44-49), réglable (`Settings.tsx`:54). `DSO::test_enrollment_allowlist_is_off_by_default_then_enforced` | **Désactivée par défaut** (SET:65 = '' ; aucune ligne en base) : `requestCode` reste ouvert à tout réseau. `hardware_id` auto-déclaré, aucune preuve de possession de clé. |
| 10.1 | En ligne / hors ligne, délai configurable | C | C | `Device::thresholds()` lit `supervision.*` (en base : 45 s et 300 s). `SST::test_offline_delay_is_a_setting_and_must_exceed_the_suspect_delay` | — |
| 10.2 | État « En diffusion » | C | **D** | La règle existe (PR:119 : `entry_id` ou `alert` de moins de 15 min), mais le vrai lecteur n'envoie presque jamais `entry_id` (voir défaut D1). Les tests serveur injectent `current_playback` à la main (SST:31). L'e2e avec un vrai lecteur accepte aussi `active` (`frontend/e2e/screens-scenarios.spec.ts:90`). | Un écran qui diffuse s'affiche le plus souvent « Lecteur joignable », pas « En diffusion ». |
| 10.3 | État « En veille » | C | C | PR:117 (dalle `standby/off` mesurée, ou `mode=off` hors plage). ENG:756-761 envoie `mode: 'off'`. `SST::test_operational_states_distinguish_broadcasting_standby_pause_and_fallback` | La veille par la dalle dépend du canal CEC/RS-232 (H). Hors plage, la veille est logicielle. |
| 10.4 | État « En anomalie » | C | C | PR:112-114. `ScreenModuleIntegrityTest::test_a_manual_incident_marks_the_screen_in_anomaly_and_old_player_errors_do_not` | Les incidents à tort (D2, D3) mettent aussi l'écran « En anomalie ». |
| 10.5 | État « Non configuré » | C | C | PR:107. `FIT::test_screen_hardware_record_and_operational_states` | — |
| 10.6 | État « En maintenance » | C | C | PR:106. `FIT::test_maintenance_status_requires_a_reason_and_keeps_the_screen_targeted` | — |
| 10.7 | État « Désactivé » | P | C | Écran inactif (PR:105). Lecteur suspendu de façon réversible (SC:695-721, PR:108, manifeste neutralisé `ManifestBuilder.php`:104-109, boutons SD:580). `FSR::test_a_suspended_player_only_receives_the_fallback_and_can_be_resumed` ; e2e `screens-suspension.spec.ts` | `devices_status_check` ne connaît toujours que active et revoked : la suspension est une colonne (`suspended_at`), pas un statut. Un lecteur suspendu déclenche de faux incidents (D3). |
| 10.8 | Distinction état lecteur / écran / preuve de diffusion | DM | H | Colonnes État, Réseau et Dalle (`Screens.tsx`). L'état de la dalle n'est mesuré que si `control_channel` est différent de `none` (DAC:168-170). | Sans canal CEC/RS-232, la dalle n'est pas mesurée. La preuve de diffusion reste faussée par D1. |
| 10.9a | Connexion et dernière activité | C | C | `last_seen_at`, `last_sync_at` (DAC:153, 238-245), `agent_seen_at` (SD:555). `DT::test_deferred_receipts_are_deduplicated_and_heartbeat_tracks_clock_skew` | — |
| 10.9b | Contenu en cours | P | **D** | `resolvePlayback` (SC:179-198) et affichage SD:550-552. Le résultat est presque toujours nul : `entry_id` est écrasé par `{mode:'program'}` en moins d'une seconde (D1). `ILT::test_last_confirmed_play_resolved_playback_and_technical_logs` injecte `entry_id` à la main. | En exploitation réelle, la fiche affiche « program » au lieu du titre. |
| 10.9c | Campagne / playlist active | P | **D** | Même chaîne (SC:196 `campaign_ref`), même cause (D1). | Campagne en cours non résolue en exploitation réelle. |
| 10.9d | Disque | P | C | Voir 9.11 (AG:53-57, SD:739). | Exige l'agent local. |
| 10.9e | Mémoire, CPU, processus, température | A | P | Mémoire libre et totale, nombre de cœurs, charge et température (AG:63-70) affichés (SD:740-742). | Charge CPU nulle sous Windows (AG:67). Température lue seulement sur le capteur Linux `thermal_zone0` (AG:58-61), donc dépendante du matériel. **Aucune mesure des processus.** |
| 10.9f | Erreurs de lecture | P | C | Erreurs codées avec `entry_id` (ENG:766-772), historique (catégorie error). `SAE::test_coded_errors_appear_in_the_screen_history` | Le code est déduit du texte du message par expression régulière (ENG:767-769). |
| 10.9g | Synchronisation | C | C | `boundedVersion` (DAC:255-263), `last_sync_at` (DAC:238-245). `DT::test_manifest_is_signed_versioned_and_conditional` | — |
| 10.10 | Historique des états | C | C | `recordScreenStates` (TICK:204-229), `GET screens/{id}/status-history`, SD:920-921. Télémétrie consultable (SC:214-218). `SST::test_the_scheduler_keeps_one_period_per_state_change` | D2 crée des bascules parasites dans l'historique. |
| 10.11 | Alerte perte de connexion | C | C | TICK:120-128. `OperationsMonitoringTest::test_unavailable_player_opens_an_incident_notified_to_incident_managers` | — |
| 10.12 | Alerte absence prolongée | C | P | `IncidentPolicy::escalateOverdue` (`IncidentPolicy.php`:46-62). `IncidentWorkflowTest::test_automatic_incidents_go_to_the_default_assignee_and_overdue_ones_are_escalated` | L'escalade porte sur l'absence de prise en charge (statut `open` seulement) : un incident « pris en compte » dont l'absence dure ne relance plus personne, et la gravité ne dépend pas de la durée d'absence. |
| 10.13 | Alerte échec de synchronisation | C | C | `sync_stalled` (TICK:266-267). `FIT::test_player_anomalies_open_then_resolve_automatic_incidents` | — |
| 10.14 | Alerte échec de lecture | C | C | `playback_errors` (TICK:260, 284-285). `SST::test_stalled_playback_repeated_errors_and_outdated_versions_open_incidents` | — |
| 10.15 | Alerte stockage insuffisant | P | C | `storage_full` sur le disque mesuré, sinon le quota (TICK:245, 262-263). `FSR::test_agent_telemetry_is_stored_and_drives_disk_and_temperature_anomalies` | Sans agent, la règle porte sur le quota du navigateur. |
| 10.16 | Alerte Player arrêté | P | **D** | Jeton « agent » (DAC:273-280), battement de l'agent (DAC:285-298 ; AG:33-44), état `player_stopped` (PR:110), incident dédié (TICK:117-125). `SAE::test_agent_heartbeat_distinguishes_a_stopped_player` | L'agent bat toutes les 60 s (AG:44), alors que la fenêtre de fraîcheur est de 45 s en base (TICK:119, PR:110). La détection est intermittente (D4). La règle `player_stalled` se déclenche aussi à tort sur le contenu de repli (D2). |
| 10.17 | Alerte version obsolète | C | C | TICK:287-289. `SST::test_stalled_playback_…_outdated_versions_open_incidents` | — |
| 10.18 | Alerte écran déclaré défectueux | P | H | Règle `panel_off` (TICK:254-257, 279). `SAE::test_panel_off_during_the_window_and_prolonged_fallback_open_incidents` | Exige un canal de pilotage CEC/RS-232. Validé seulement avec un `panel_state` injecté. |
| 10.19 | Alerte anomalie de programmation | P | **D** | `no_programme` (TICK:258-259, 276) et `fallback_prolonged` (TICK:281-283). `ILT::test_repeated_losses_and_empty_programme_open_incidents`, `SAE::test_panel_off_during_the_window_and_prolonged_fallback_open_incidents` | `fallback_prolonged` se déclenche à tort sur tout lecteur suspendu et sur tout écran inactif (D3), avec le texte « Le programme existe ». |
| 10.20 | Alerte : date, équipement, gravité (4 niveaux), statut, responsable | C | C | Contrainte vérifiée en base (4 niveaux). Responsable par défaut, échéance par gravité. `IWT::test_automatic_incidents_go_to_the_default_assignee_…` | — |
| 10.21 | Supervision par étage et par groupe | C | C | `SST::test_heartbeat_records_declared_characteristics_and_supervision_groups_by_floor_and_group` | — |
| 10.22 | Rafraîchissement temps réel | P | C | SSE `GET realtime/stream` (`RealtimeController.php`:17-55), publication sur changement (`Device.php`:22-29, `Incident`, `DeviceCommand`, `Screen`), client `frontend/src/lib/realtime.ts`, branché dans `Layout.tsx`:79, indicateur dans `Supervision.tsx`:559. `RealtimeStreamTest` (2 tests) ; e2e `screens-lots20-25.spec.ts`:40 | Le serveur interroge la base chaque seconde, et chaque client ouvert occupe un processus PHP pendant 25 s. L'état réseau n'est recalculé qu'au tick d'une minute. |
| 11.1 | Synchroniser | C | C | RCS:28, ENG:531. `RCT::test_a_command_goes_through_…` ; e2e | — |
| 11.2 | Actualiser | C | C | RCS:29, ENG:536-544. | — |
| 11.3 | Redémarrer Player | C | C | Voir 9.9. | Rechargement de la page. |
| 11.4 | Redémarrer l'appareil | DM | H | RCS:31, ENG:551-563, AG:144-150 (`SAE_REBOOT_CMD`). | Essayé en simulation seulement. De plus, `reboot_device` n'est déclaré que si l'écran a un canal de dalle (ENG:406-411, 497-500), même quand l'agent sait redémarrer. |
| 11.5 | Arrêter / reprendre la diffusion | C | C | RCS:32-33, 138-140 ; ENG:565-571 ; PLY:101. `RCT::test_stopping_playback_requires_a_reason_and_is_reflected_once_confirmed` | — |
| 11.6 | Mettre en veille / sortir de veille | DM | H | RCS:34-35, ENG:572-580, AG:92-102. | Pilotes CEC et `command` non validés sur du matériel ; seul `simulate` a été essayé. |
| 11.7 | Capturer l'écran | C | C | RCS:89-92, ENG:581-586, 674-693, SD:590. `RCT::test_a_screenshot_command_links_the_capture_request` | Capture du DOM (voir 21.1). |
| 11.8 | Consulter les journaux (commande) | C | C | ENG:587-599, CP:107, 125-132. e2e `screens-commands.spec.ts` | — |
| 11.9 | Autorisation par profil | C | C | `devices.command` et périmètre. `RCT::test_commands_are_restricted_by_permission_scope_and_device` | — |
| 11.10 | Envoi sécurisé | P | P | Remise au battement authentifié (routes/api.php:72). Signature HMAC livrée (VDS) mais **désactivée par défaut** (VDS:27, SET:66). | Les commandes elles-mêmes ne sont pas signées. Une fois la signature activée, le lecteur casse (D5). TLS relève de l'infrastructure. |
| 11.11 | Horodatage et utilisateur | C | C | RCS:93-96, 133-135, PR:153-160, CP:102. Tests RCT. | — |
| 11.12 | Identifiant de transaction | C | C | UUID renvoyé dans l'accusé (`device/commands/{id}/ack`), affiché (CP:102). `RCT::test_a_command_goes_through_…` | — |
| 11.13 | Sept états | C | C | Contrainte vérifiée en base. `RCT::test_an_unconfirmed_command_expires_and_a_pending_one_can_be_cancelled` | — |
| 11.14 | Compte rendu | C | C | DAC:192-206 (32 Kio au plus), CP:125-132. | — |
| 11.15 | « Exécutée » seulement après confirmation | C | C | RCS:109-115 (renvoi tant que la commande est `sent`), RCS:163-173 (expiration, jamais présumée exécutée). `FIT::test_remote_reload_is_queued_redelivered_until_acknowledged_and_traced` | — |
| 11.16 | Capacités vérifiées avant de proposer | C | C | RCS:54-72, ENG:497-500. `RCT::test_an_unsupported_command_is_refused_with_its_reason`, `RCT::test_a_player_that_never_declared_capabilities_receives_no_command` | Capacités déclarées par le lecteur, non attestées. |
| 11.17 | Commandes indisponibles désactivées avec explication | C | C | RCS:60-68, CP:86-89 (`disabled` et motif affiché). | — |
| 17.1 | Heartbeat | C | C | DAC:122-187, ENG:362-400. `DT::test_deferred_receipts_…` | — |
| 17.2 | Exécution asynchrone | C | C | RCS:109-147. Tests RCT. | — |
| 17.3 | File de commandes | C | C | Table `device_commands`, ordre de `requested_at` (RCS:111). | La colonne obsolète `devices.pending_command` est toujours en base et toujours exposée (PR:135). |
| 17.4 | Authentification individuelle | C | C | routes/api.php:72, `EnsureDevice`. Le jeton agent est cantonné à sa portée (DAC:288). `DT::test_device_token_gives_no_access_to_user_routes_…`, `SAE::test_agent_heartbeat_…` | — |
| 17.5 | TLS | P | P | Contrôle `saie:preflight` des serveurs en HTTPS (déclaré). Aucun `forceScheme` ni refus du HTTP dans l'application. AG accepte un serveur `http://` (AG:129). | Repose sur l'infrastructure. Non vérifié en recette. |
| 17.6 | Journalisation des échanges | P | C | LDE:18-30 sur toutes les routes du lecteur (routes/api.php:72), consultation dans SD:1014-1015. `SAE::test_device_exchanges_are_logged_listed_and_purged` ; e2e `screens-lots20-25.spec.ts` | Les requêtes refusées en amont (authentification, signature) ne sont pas journalisées : LDE est placé après VDS. La table n'est purgée que si la purge automatique est activée, ce qui n'est pas le cas (voir 17.11). |
| 17.7 | Synchronisation différentielle | C | C | ETag/304 (DAC:96-99), plages d'octets (STO:147-186). `DT::test_manifest_is_signed_versioned_and_conditional`, `DeviceDeliveryTest::test_media_download_supports_byte_ranges_for_resumption` | — |
| 17.8 | Cache local | C | C | STO:122-186. e2e `player.spec.ts` | — |
| 17.9 | Hors ligne avec reprise | C | C | STO:98-120, DAC:211-249. | — |
| 17.10 | Détection des pertes | P | C | `offline_since` et cumul de `events_dropped` (DAC:159-160), envoyés par ENG:368, 375, affichés dans SD:556-557. `ILT::test_heartbeat_records_observed_address_losses_and_version_changes` | — |
| 17.11 | Rétention des remontées techniques | P | P | `saie:retention --apply` planifié (`routes/console.php`:9), réglages `retention.*` (`Settings.tsx`:60, 64). `FleetReportsTest::test_retention_purges_old_receipts_commands_and_states_only_when_applied` | **Purge automatique désactivée par défaut** (SET:78, pas de ligne en base) : rien n'est purgé, y compris `device_exchanges`, qui reçoit environ 2 lignes par minute et par lecteur. |
| 18.1 | Authentification forte | P | P | Jeton Sanctum de 90 j avec rotation. | Ni mTLS ni clé asymétrique propre au lecteur. Le chiffrement du jeton au repos est **désactivé par défaut** (SET:67). |
| 18.2 | Anti-enrôlement non autorisé | P | P | Appairage décidé par une personne habilitée dans son périmètre (SC:611-614). La liste blanche existe. | Liste blanche **vide par défaut** (voir 9.14). |
| 18.3 | Gestion des secrets | P | P | `issued_token` chiffré côté serveur. Chiffrement AES-GCM côté lecteur (STO:44-84) piloté par `secure_storage` (DAC:185). | **Désactivé par défaut** : le jeton reste en clair dans IndexedDB. Même activé, la clé est rangée dans la même base IndexedDB (STO:48-51). Aucun test du lecteur (DSO teste seulement le drapeau). |
| 18.4 | Rotation | C | C | DAC:300-311, ENG:224-236. `DeviceTokenRotationTest`, `DeviceDeliveryTest::test_next_signing_key_is_announced_…` | — |
| 18.5 | Anti-rejeu | P | P | HMAC, horodatage à ±5 min, nonce à usage unique (VDS:33-51). `DSO::test_signed_requests_with_replay_protection_when_enabled` | **Désactivé par défaut** (VDS:27). Activé, il casse le téléchargement des médias et peut « auto-révoquer » le lecteur (D5). |
| 18.6 | Révocation des lecteurs compromis | C | C | `DT::test_revoked_device_is_refused_by_the_api` | Le cache reste lisible jusqu'à l'expiration (documenté). |
| 18.7 | Mise à jour sécurisée | C | C | SHA-256 dans le manifeste signé, vérifié avant la bascule (ENG:645-668). `PlayerReleaseTest::test_channels_rollback_and_manifest_target` | — |
| 21.1 | Capture réelle du rendu | P | H | `html-to-image` sur le DOM (ENG:674-693), limite annoncée (ENG:585). | Ce n'est pas une capture de la sortie vidéo : il faudrait un matériel de capture. |
| 21.2 | Supervision intelligente (pertes répétées) | A | C | `unstable_connection` (TICK:251, 274-275), seuil réglable. `ILT::test_repeated_losses_and_empty_programme_open_incidents` | Ne compte que les pertes qui ont dépassé 5 min. |
| 21.3 | Mises à jour progressives | P | C | `rollout_percent`, suivi par lecteur, promotion proposée (`PlayerReleases.tsx`:70-79), promotion automatique si activée (TICK:236-238). `FSR::test_a_partial_rollout_…`, `DFM::test_healthy_pilot_release_is_proposed_then_promoted_only_when_enabled` | La promotion automatique est désactivée par défaut (proposition manuelle seulement). |
| 21.4 | Maintenance préventive | C | C | TICK:314-331. `FIT::test_preventive_maintenance_plan_opens_one_intervention_and_reschedules_on_resolution` | — |
| 23.1 | Autonomie hors ligne et resynchronisation | C | C | Voir 17.7 à 17.9. e2e `player.spec.ts` | — |

### Décompte

| Statut | §9 | §10 | §11 | §17.3 | §18 | §21/23 | Total |
|---|---|---|---|---|---|---|---|
| Conforme (C) | 12 | 19 | 14 | 9 | 3 | 4 | **61** |
| Partiel (P) | 2 | 2 | 1 | 2 | 4 | 0 | **11** |
| Absent (A) | 0 | 0 | 0 | 0 | 0 | 0 | **0** |
| Défectueux (D) | 0 | 5 | 0 | 0 | 0 | 0 | **5** |
| Matériel (H) | 0 | 2 | 2 | 0 | 0 | 1 | **5** |
| **Total** | 14 | 28 | 17 | 11 | 7 | 5 | **82** |

Lignes par statut :
- P : 9.2, 9.14, 10.9e, 10.12, 11.10, 17.5, 17.11, 18.1, 18.2, 18.3, 18.5.
- D : 10.2, 10.9b, 10.9c, 10.16, 10.19.
- H : 10.8, 10.18, 11.4, 11.6, 21.1.

Changements par rapport au contrôle précédent :
- 10.12 passe de C à P.
- 10.2, 10.9b, 10.9c, 10.16 et 10.19 passent à D.
- 10.18 et 21.1 passent à H.
- 9.4, 9.6, 9.8, 9.11, 9.12, 10.7, 10.9d, 10.9f, 10.15, 10.22, 17.6, 17.10, 21.2 et 21.3 passent à C : les corrections déclarées ont été vérifiées dans le code.
- 10.9e passe de A à P.

### Défauts constatés (avec preuve)

**D1 : l'état « lecture en cours » envoyé par le vrai lecteur perd `entry_id` (lignes 10.2, 10.9b et 10.9c, toutes D).**
- `PLY:11-21` : `useNow()` change `now` chaque seconde. `PLY:37` recalcule `manifest` (useMemo dépendant de `now`) et `PLY:59` recalcule `resolution`, donc un nouvel objet chaque seconde.
- `PLY:90-93` : l'effet `[resolution, …]` appelle donc `engine.reportMode('program')` chaque seconde.
- `ENG:707-710` : `reportPlaying` pose `{entry_id, lang, at}` sans `mode`. Le test `ENG:758` (`this.playback?.mode === mode`) échoue alors, et `ENG:759` écrase `playback` par `{mode:'program', at}`.
- `entry_id` ne survit donc qu'une seconde au plus par diapositive, et le battement de 30 s (ENG:374) ne le transmet presque jamais.
- Conséquences : `broadcasting` (PR:119) et `resolvePlayback` (SC:188-190) restent presque toujours faux ou nuls.
- Pourquoi les tests ne le voient pas : les tests serveur injectent `current_playback` à la main (SST:31, ILT:72), et l'e2e réel tolère `active` (`frontend/e2e/screens-scenarios.spec.ts:90`).

**D2 : `player_stalled` (« Lecture arrêtée… application probablement figée », gravité majeure) s'ouvre à tort sur un écran en repli ou en alerte (ligne 10.16).**
- TICK:269-271 exclut seulement `mode === 'off'` et l'arrêt commandé.
- En repli, `playback.at` n'est posé qu'au changement de mode (ENG:756-759), et aucune diapositive n'appelle `reportPlaying` (PLY:78). En alerte, `at` est posé une seule fois par alerte (ENG:748-753).
- Résultat : au bout de `anomalies.stalled_minutes` (15 min), tout écran d'affichage en repli durable (y compris un écran sans programme, déjà signalé par `no_programme`, ou un lecteur suspendu) ou en alerte longue reçoit un incident majeur à tort, notifié aux gestionnaires (TICK:298).
- Le test `SAE::test_panel_off_during_the_window_and_prolonged_fallback_open_incidents` place un lecteur en repli depuis 45 min, mais ne vérifie pas l'absence de `player_stalled`.

**D3 : `fallback_prolonged` s'ouvre à tort sur les lecteurs suspendus et les écrans inactifs (ligne 10.19).**
- `$emptyProgramme` est forcé à faux quand `suspended_at` n'est pas nul ou que l'écran n'est pas actif (TICK:258).
- La règle `fallback_prolonged` (TICK:281-283) exige seulement `! $emptyProgramme` et un repli de plus de 30 min.
- Or un lecteur suspendu ou un écran inactif ne reçoit qu'un manifeste neutralisé (`ManifestBuilder.php`:104-109, 127) et affiche donc le repli par construction.
- Résultat : un incident « Contenu de repli prolongé : le programme existe mais… » s'ouvre au bout de 30 min, et l'écran inactif passe « En anomalie ».

**D4 : la détection du lecteur arrêté par l'agent est intermittente (ligne 10.16).**
- L'agent bat toutes les 60 s (AG:44), alors que la fraîcheur exigée est `Device::thresholds()['suspect']`, soit 45 s en base (TICK:119, PR:110).
- Pendant environ 25 % du temps, un lecteur arrêté avec un agent vivant apparaît « Hors ligne » au lieu de « Lecteur arrêté (poste joignable) ».
- L'incident ouvert au passage à « indisponible » prend alors le mauvais type (`device_unavailable` au lieu de `player_stopped`), selon l'instant du tick.
- `recordScreenStates` enregistre une nouvelle période à chaque bascule (TICK:204-229).
- Le test SAE envoie un battement puis interroge aussitôt : il ne couvre pas ce décalage.

**D5 (latent, option désactivée par défaut) : activer `devices.signed_requests` casse le lecteur (lignes 18.5 et 11.10, classées P).**
- Les médias sont téléchargés par `downloadAsset` avec un simple `fetch` sans en-têtes de signature (STO:157). Or la route `assets/{assetVersion}` est soumise à VDS (routes/api.php:72, 78). Elle renvoie donc `SIGNATURE_REQUIRED` (401) et plus aucun média nouveau n'est téléchargé.
- Le lecteur signe avec `Date.now()` non corrigé (ENG:788), alors qu'il connaît la dérive (`clockOffsetMs`). Au-delà de 5 min de dérive d'horloge, VDS:38-40 répond 401.
- `call()` traite tout 401 comme une révocation (ENG:205-207) et persiste `revoked: true` (ENG:239-246). Le lecteur se met définitivement en écran « révoqué » et n'en sort que par un réappairage manuel au clavier.
- L'aide de l'interface affirme « Les lecteurs à jour signent déjà » (`frontend/src/pages/admin/Settings.tsx`:55), ce qui est faux pour les médias.
- `DSO::test_signed_requests_with_replay_protection_when_enabled` ne teste que le battement.

### Autres constats (non bloquants)
- Liste blanche d'enrôlement, signature des requêtes, chiffrement du jeton, purge automatique et promotion automatique : tout est livré mais désactivé (SET:65-67, 78 ; aucune ligne `settings` en base).
- `device_exchanges` reçoit une ligne par appel du lecteur (battement et manifeste toutes les 30 s environ) et n'est jamais purgée tant que `retention.auto_purge` vaut faux.
- `devices.pending_command` est obsolète mais toujours en base et exposée (PR:135).
- `reboot_device` n'est jamais proposé sans canal de dalle, même si l'agent est configuré avec `SAE_REBOOT_CMD` (ENG:406-411, 497-500).
- L'agent n'envoie pas le firmware (AG:64-70). La charge CPU n'est pas mesurée sous Windows (AG:67). Les processus ne sont jamais mesurés.

## Contrôle 2 — partie C (gestion des écrans) — réévaluation indépendante, lecture seule, 08/11/2026

Méthode : lecture du code (`backend/`, `frontend/src`, `player/src`), `php artisan route:list` ; aucun test exécuté (les tests cités existent et ont été lus, pas joués). Chemins relatifs à `backend/` sauf mention `frontend/`.
Abréviations : SC = `app/Http/Controllers/Api/V1/ScreenController.php`, SV = `.../SupervisionController.php`, CC = `.../CampaignController.php`, PC = `.../PlaylistController.php`, CS = `app/Services/CampaignService.php`, EXS = `app/Services/ExportService.php`, FR = `app/Services/FleetReports.php`, SH = `app/Services/ScreenHistory.php`, SD = `frontend/src/pages/display/ScreenDetail.tsx`.
Tests : SMI ScreenModuleIntegrityTest, FRH FleetRolesAndHistoryTest, FRT FleetReportsTest, SIT ScreenInventoryTest, IWT IncidentWorkflowTest, RCT RemoteCommandsTest, SST SupervisionStatesTest, SRT ScopedReadsTest, PAS PlaylistAssignmentScopesAndRetentionTest, ILT InventoryLogsAndTraceabilityTest, DRC DeviceReassignAndConflictsTest, FSR FleetSuspensionRolloutTelemetryTest, SCC ScreenClassificationAndCommissioningTest, SAE SupervisionAgentAndExchangesTest.

| Réf | Exigence | Statut précédent | Statut actuel | Preuve (fichier:ligne, test) | Écart restant |
|---|---|---|---|---|---|
| §12-a | Affecter une playlist à un écran ou un groupe | P | C | PC:98-141 (`assign`, cibles screen/group, périmètre, priorité, publication) ; UI `frontend/src/pages/display/AssignPlaylistDialog.tsx`, SD:775-781, `Groups.tsx:72` ; PAS::test_a_playlist_is_assigned_directly_as_a_permanent_programme | « Permanent » = campagne d'un an par défaut (PC:121) ; copie des éléments : une modification ultérieure de la liste n'est pas propagée |
| §12-b | Campagne sur plusieurs écrans | C | C | CS:31-73 ; e2e `screens-scenarios.spec.ts` scénario D | — |
| §12-c | Dates et plages horaires | C | C | CC:368-371 ; EventModeTest | — |
| §12-d | Priorités | P | C | `app/Support/CampaignPriority.php:19-25` (5 niveaux nommés) ; `GET /campaign-priority-levels` CC:54-59 ; UI `PriorityField` `frontend/src/pages/publication/CampaignWizard.tsx:504,571` ; FSR::test_the_urgent_priority_level_requires_the_urgence_power | Bornes des niveaux figées dans le code |
| §12-e | Périodes | C | C | Jours, créneau, mode événement (CS:197-205) ; EventModeTest | — |
| §12-f | Détection des conflits | P | C | CS:107-117 (`slotsOverlap` jours + créneaux), CS:161-165 ; wizard transmet jours/créneau `CampaignWizard.tsx:513-526,542-555` ; DRC::test_conflicts_require_shared_days_and_overlapping_time_slots | Non bloquant ; la fiche campagne (CC:84 → `draftFrom` CC:326-333) et la publication (CS:239) recalculent sans jours/créneau (faux conflits possibles dans la fiche) |
| §12-g | Prévisualisation des contenus | C | C | `TemplatePreview` `frontend/src/pages/publication/shared.tsx` ; `POST /campaigns/preview` | — |
| §12-h | Historique des affectations | P | C | `campaign.updated` avant/après des seules valeurs changées, cibles/contenus/langues compris (CC:66-76, 128-139) ; `playlist.updated` idem (PC:60-71) ; `playlist.assigned` (PC:132) ; ILT::test_deactivation_needs_a_reason_and_updates_keep_before_and_after | Vue par écran : voir §13.1-f |
| §12.1-a | Hiérarchie des priorités métier | P | C | Niveaux permanents < généraux < institutionnels < officiels < urgents (`CampaignPriority.php:19-25`) ; alerte > campagne > repli (`ScheduleResolver`) | — |
| §12.1-b | Priorités configurables et soumises à autorisation | P | C | Seuil `campaigns.urgent_from` paramétrable 60-99 ; refus `PRIORITY_RESTRICTED` journalisé (`CampaignPriority.php:49-55`) appliqué à la création, la modification (CC:93,121-123) et l'affectation de liste (PC:114) ; FSR::test_the_urgent_priority_level_requires_the_urgence_power ; SettingsEffectTest | Un seul seuil configurable ; campagnes existantes non revérifiées |
| §12.1-c | Urgence puis retour au programme | C | C | `ManifestBuilder.php:127-129` ; DeviceTest::test_alert_preempts_programme_in_manifest_and_requires_urgency_power ; e2e `player.spec.ts` | — |
| §12.2 | Prévisualisation du rendu sur un écran donné | P | P | `GET /screens/{id}/preview` borné (SC:587-604) ; rendu `RenderPreview` SD:790-807 (résolution et orientation de l'écran) | Rendu avec le gabarit par défaut, pas le modèle réel du contenu ; médias représentés par leur type ; 4 diapositives max ; aucun test (« Typage ») |
| §13.1-a | Historique : création | C | C | `screen.created` SC:169 ; FRH::test_the_screen_history_gathers_every_kind_of_event | — |
| §13.1-b | Historique : modification | C | P | `screen.updated` avant/après SC:314,331 ; onglet Historique SD:953-985 | **Défaut** : SH:28-29 exclut de la catégorie « Fiche » toute entrée dont `after` contient la clé `profile_id` ; l'interface envoie toujours `profile_id` (SD:365) → toutes les modifications faites depuis l'interface sont classées « Configuration » (SH:37). L'historique n'affiche pas les valeurs avant/après (SH:65 ne lit que action/motif) |
| §13.1-c | Historique : déplacement | C | C | SC:309,315-327 ; `recordLocation` SC:988-995 ; SIT::test_the_full_record_is_stored_and_each_move_is_historised ; SMI::test_a_zone_manager_cannot_reactivate_or_move_a_screen_outside_the_zone | — |
| §13.1-d | Historique : changement de configuration | C | C | `screen.window_changed` motif obligatoire SC:310-313 ; catégorie `configuration` SH:37 ; `screen_profile.updated` avant/après | Catégorie polluée par les modifications ordinaires (cf. §13.1-b) |
| §13.1-e | Historique : changement de groupe | C | P | `group.created`/`group.updated` avec `screen_ids` avant/après (SC:832,851,859) agrégés par SH:35-36 ; FRH (catégorie `group`) | `group.deleted` journalisé sans avant (SC:872) → la sortie des membres n'apparaît pas dans l'historique de l'écran ; retrait des groupes à l'archivage (SC:579) non tracé par groupe ; membres des groupes dynamiques recalculés sans journal (`DynamicGroups.php:73-89`) |
| §13.1-f | Historique : affectation de contenu | C | P | Catégorie `assignment` = première transmission d'une campagne au lecteur (SH:38-40), libellée « Diffusion » dans l'UI (SD:950) | Ni les retraits de campagne ni `playlist.assigned`/`campaign.updated` ne figurent dans l'historique de l'écran ; catégorie toujours non testée (FRH:76 fige la liste à record/location/group/command/incident) |
| §13.1-g | Historique : désactivation / réactivation | P | C | Motif obligatoire, événements `screen.deactivated`/`screen.reactivated` (SC:304-307,328-330) ; UI motif SD:363,391 ; ILT::test_deactivation_needs_a_reason_and_updates_keep_before_and_after | — |
| §13.1-h | Historique : archivage | C | C | SC:561-585 ; SMI::test_archiving_withdraws_the_screen_and_keeps_its_record ; UI SD:169 ; e2e `screens-inventory.spec.ts` | — |
| §13.2-a | Connexions / déconnexions | C | C | `screen_status_history`, SC:544-555 ; UI SD:920 ; SST::test_the_scheduler_keeps_one_period_per_state_change | Coupures < délai hors ligne non tracées |
| §13.2-b | Incidents | C | C | `events` + audit `incident.*` SV:196-203 ; IWT | — |
| §13.2-c | Commandes et résultats | C | C | `device_commands`, SH:41-43 ; RCT::test_a_command_goes_through_sent_received_and_executed_with_a_report ; e2e `screens-commands.spec.ts` | Veille/redémarrage en simulation |
| §13.2-d | Synchronisations | C | C | `device_receipts`, `campaign_transmissions` ; CC:214-258 | — |
| §13.2-e | Erreurs | P | C | Erreurs codées émises par le lecteur (`player/src/engine.ts:767-770`) ; catégorie `error` SH:50-51 ; UI SD:950 ; SAE::test_coded_errors_appear_in_the_screen_history | Limite de 200 entrées par écran dans l'historique |
| §13.2-f | Mises à jour | P | C | `device.version_changed` avant/après (`DeviceApiController.php:166`) visible en catégorie `device` (SH:34) ; ILT::test_heartbeat_records_observed_address_losses_and_version_changes | — |
| §13.2-g | Interventions | C | C | SV:162-166 (`intervene` note obligatoire) ; IWT | — |
| §13.3-a | Rapport inventaire PDF/Excel | C | C | EXS:172,336-370 borné ; UI `Screens.tsx:172` ; SIT::test_the_inventory_report_is_bounded_to_the_scope_and_can_target_a_floor ; e2e `screens-reports.spec.ts` | — |
| §13.3-b | Inventaire par bâtiment / étage | C | P | Serveur : filtres `building_id`/`floor_id` EXS:342-343, `ExportController.php` (params conservés) ; SCC::test_screen_sheet_and_export_parameters_are_kept | Interface : l'export d'inventaire ne transmet que `floor_id` (`frontend/src/pages/display/Screens.tsx:172`), la liste n'a pas de filtre bâtiment → inventaire par bâtiment inaccessible dans l'UI (seule la synthèse par bâtiment existe) |
| §13.3-c | Écrans hors ligne | C | C | FR:25-46 borné ; FRT::test_each_fleet_report_is_built_from_real_data | — |
| §13.3-d | Incidents | P | C | EXS:387-401 (catégorie, diagnostic, résultat, vérifié par, escalade, borne `whereIn screen_id`) ; UI `Supervision.tsx:88` ; SRT::test_the_incident_report_is_bounded_and_carries_the_processing_columns | Incidents sans écran exclus des exports limités |
| §13.3-e | Disponibilité | C | C | FR:97-125 ; FRT | Cf. RG-014 |
| §13.3-f | Maintenance | C | C | FR:131-151 ; FRT | — |
| §13.3-g | Diffusions | C | C | EXS:276-307 borné | — |
| §13.3-h | Commandes exécutées | C | C | FR:52-67 ; FRT ; e2e `screens-reports.spec.ts` | — |
| §13.3-i | Fin de garantie | C | C | FR:73-88 ; FRT | Horizon `months` non saisissable dans l'UI (6 mois fixes, générateur générique `Exports.tsx:128`) |
| §13.3-j | Durées de conservation paramétrables | P | C | `SettingController.php:54-66,104-119` (dont `audit_months`, `export_hours`, `exchanges_days`) ; usage EXS:147, `ApplyRetention.php:28` ; UI `frontend/src/pages/admin/Settings.tsx:42-64` ; PAS::test_fleet_report_rights_and_retention_settings_are_configurable ; FRT::test_retention_purges… | Incidents sans durée de conservation ; purge automatique désactivée par défaut |
| §13.3-k | Droits sur les rapports paramétrables | P | P | Permission `reports.fleet` accordable par rôle (`config/saie.php:197`, `RoleController.php:40-58`, UI `Roles.tsx:145`) ; PAS::test_fleet_report_rights… | Correspondance type→permissions toujours figée (EXS:52-71) ; retirer `reports.fleet` ne retire pas l'inventaire/hors-ligne/garantie à qui a `screens.view`, ni incidents/disponibilité/maintenance à qui a `supervision.view` ; pas de réglage par rapport. La migration a accordé `reports.fleet` à tout rôle ayant `reports.view`, dont `gestionnaire_salle` et `diffuseur` (`config/saie.php:240-246`) |
| §14-1 | Profil Administrateur SAIE | P | P | Rôle `admin_saie` (`config/saie.php:222-226`) ; PAS::test_the_single_saie_administrator_role_exists_unassigned | Créé mais non attribué : décision DSI toujours requise |
| §14-2 | Administrateur technique | C | C | `config/saie.php:227-230` | — |
| §14-3 | Responsable du parc | C | C | `config/saie.php:253-256` ; FRH::test_the_supervisor_consults_while_the_fleet_manager_administers | — |
| §14-4 | Superviseur | C | C | `config/saie.php:257-259` ; FRH | — |
| §14-5 | Technicien | C | C | `config/saie.php:260-262` | — |
| §14-6 | Gestionnaire de diffusion | C | C | `diffuseur` + pouvoir « urgence » | — |
| §14-7 | Auditeur | C | C | `config/saie.php:249` ; FRT::test_the_auditor_can_request_fleet_reports_and_inventory | — |
| §14-8 | Habilitation limitable à un périmètre | P | P | `Access::screenScope` intersecte site, zone, niveau, bâtiment, écran, groupe (`app/Services/Access.php:181-210`) ; `RoleController.php:63-89` ; UI `frontend/src/pages/admin/Users.tsx:355-378` ; FRH::test_an_assignment_can_be_limited_to_a_floor_or_to_a_group ; PAS::test_assignments_can_be_limited_to_a_building_or_a_single_screen | Site (`site_id`) non saisissable dans l'UI (formulaire `Users.tsx:282,295` sans site) ; affectation limitée à un groupe : FK `role_assignments.group_id` sans `ON DELETE` (migration `2026_11_08_095843…:19-20`) → `DELETE /screen-groups/{id}` échoue en erreur SQL 500 (SC:864-875) dès qu'une affectation, même révoquée, référence le groupe |
| §14-9 | Contrôle backend de chaque opération sensible | C | **D** | Écritures sur écrans/lecteurs/groupes/incidents/commandes bornées (SC, SV:150,167,238-251, `DeviceCommandController.php:25,41,55`) ; SMI ; RCT::test_commands_are_restricted_by_permission_scope_and_device ; SRT | **Écritures hors périmètre** : `ScreenProfileController.php:31-52` (création/modification d'un profil, y compris `server_url`, appliqué à des écrans de tout le parc, par un titulaire de `screens.manage` limité) ; `PlayerReleaseController.php:34-80` (publication, retrait, élargissement de vague pour tout le parc par un technicien limité). **Lectures non bornées** : CC:31-49 (liste des campagnes), CC:78-87 (fiche + prévisualisation listant les écrans ciblés hors périmètre), CC:144-156 et `AlertController.php:68-75` (prévisualisation avec cible `all` = liste de tout le parc), `DashboardController.php:42,73,96` (parc et 4 incidents ouverts pour tout compte `dashboard.view`), SV:108 (compte d'incidents), CC:268-277 (replis) |
| §15.1 | Cycle de vie | P | P | Mise en service explicite fondée sur des faits + contrôle visuel (SC:265-291) ; UI SD:298 ; SCC::test_commissioning_requires_facts_and_a_visual_check ; e2e `screens-lots20-25.spec.ts` | La mise en service ne conditionne rien : `commissioned_at` n'est lu que pour un filtre, la présentation et la fiche PDF (SC:66, `Present.php:83`, EXS:462) ; un écran non mis en service est `active`, ciblé et diffusant ; un déplacement ou une réaffectation ne réinitialise pas la mise en service |
| §15.2-a | Détection automatique ou manuelle | C | C | `SaieTick` ; SV:145-157 | — |
| §15.2-b | Alerte | C | C | `IncidentPolicy.php:53` ; IWT::test_automatic_incidents_go_to_the_default_assignee_and_overdue_ones_are_escalated | — |
| §15.2-c | Qualification | C | C | SV:164,188 ; UI `Supervision.tsx:43` ; IWT | Valeurs antérieures (gravité/catégorie) non conservées (cf. RG-008) |
| §15.2-d | Affectation à un technicien | C | C | SV:171-177 | Éligibilité sans contrôle du périmètre de l'intervenant |
| §15.2-e | Diagnostic | C | C | SV:166,187 | — |
| §15.2-f | Intervention | C | C | SV:166 ; IWT ; e2e `screens-incidents.spec.ts` | Durée/intervenant en texte libre |
| §15.2-g | Vérification | C | C | SV:182-184,189 ; IWT ; e2e `screens-incidents.spec.ts` | Le résolveur peut vérifier lui-même |
| §15.2-h | Clôture avec historique | C | C | SV:182-198 ; IWT | — |
| §15.2-i | Incident non résolu au tableau de bord | C | C | `DashboardController.php:73,96` | Non borné au périmètre ni à la permission (cf. §14-9) |
| RG-001 | Identifiant unique | C | C | SC:914 + unique en base | Doublon non testé |
| RG-002 | Localisation valide | C | C | SC:917 ; FK NOT NULL | — |
| RG-003 | Un seul lecteur actif | C | C | Index partiel ; SC:629-634,672-677 ; DRC::test_reassignment_respects_the_target_screen (`SCREEN_ALREADY_PAIRED`) | — |
| RG-004 | Enrôlement authentifié | C | C | SC:609-648 ; DeviceTest | — |
| RG-005 | Écran désactivé sans programmation | C | C | `ManifestBuilder.php:127-129` ; CS:55 ; SMI::test_an_inactive_screen_receives_no_programme | — |
| RG-006 | Commande compatible | C | C | RCT::test_an_unsupported_command_is_refused_with_its_reason | — |
| RG-007 | Commande historisée | C | C | SH:41-43 ; RCT ; e2e `screens-commands.spec.ts` | — |
| RG-008 | Modification importante auditée | P | P | Avant/après : écran SC:331, déplacement SC:326, statut SC:329, groupe SC:859, campagne CC:139, liste PC:71, profil, paramètre, rôle, lecteur SC:242, vague | Sans avant : qualification d'incident (SV:188,203 : gravité/catégorie antérieures perdues, `events` ne garde que l'action), suppression de groupe (SC:872), changement de canal (`PlayerReleaseController.php:133`), désactivation de repli (CC:299), archivage de campagne |
| RG-009 | Hors ligne jamais diffusant | C | C | SST::test_operational_states_distinguish_broadcasting_standby_pause_and_fallback | — |
| RG-010 | Programmation et priorités | C | C | `ScheduleResolver::resolve` | — |
| RG-011 | Localisation historisée | C | C | SC:988-995 ; SIT | — |
| RG-012 | Pas de clôture sans résultat | C | C | SV:166,192 ; SMI::test_incidents_are_bounded_to_the_zone_and_resolution_requires_a_result | — |
| RG-013 | Archivé hors campagnes | C | C | SC:575-581 ; `ManifestBuilder.php:127` ; `DynamicGroups.php:40` ; SMI | — |
| RG-014 | Disponibilité sur données reçues | C | C | FR:97-125 ; `SC:794-798` (groupes) ; FRT | Créneaux hors plage comptés comme attendus |

### Couverture de recette §20

| # | Critère | Couverture |
|---|---|---|
| 1 | Enregistrer un écran | Couvert (doublon de code non testé) |
| 2 | Déplacement historisé | Couvert |
| 3 | Enrôlement, un lecteur actif | Couvert (`SCREEN_ALREADY_PAIRED` testé via réaffectation, pas via appairage) |
| 4 | Groupe d'écrans | Couvert |
| 5 | Campagne multi-écrans | Couvert |
| 6 | Détection des conflits | Couvert (DRC, API seulement) |
| 7 | Prévisualisation orientation/résolution | Partiel (rendu visuel non testé) |
| 8 | Urgence puis retour | Couvert |
| 9 | Hors ligne | Couvert |
| 10 | Commande historisée | Couvert |
| 11 | Cycle d'incident | Couvert |
| 12 | Désactivé/archivé exclu | Couvert |
| 13 | Rapports et habilitations par périmètre | Couvert pour les exports ; lectures de campagnes, tableau de bord, profils et versions non testées par périmètre |

Bilan §20 : 12 couverts, 1 partiel.

### Décompte (71 lignes)

| Statut | Contrôle précédent | Ce contrôle |
|---|---|---|
| Conforme | 54 | 60 |
| Partiel | 17 | 10 |
| Absent | 0 | 0 |
| Défectueux | 0 | 1 |
| **Total** | **71** | **71** |

Montées à C (corrections vérifiées) : §12-a, §12-d, §12-f, §12-h, §12.1-a, §12.1-b, §13.1-g, §13.2-e, §13.2-f, §13.3-d, §13.3-j.
Descentes : §13.1-b (C→P), §13.1-e (C→P), §13.1-f (C→P), §13.3-b (C→P), §14-9 (C→D).
Restés P : §12.2, §13.3-k, §14-1, §14-8, §15.1, RG-008.

### Défauts trouvés

1. **§14-9 — profils de configuration modifiables hors périmètre.** `ScreenProfileController.php:31-52` : `store`/`update` n'exigent que `screens.manage`, sans contrôle de périmètre ; un profil est partagé par des écrans et groupes de tout le parc (usage compté `ScreenProfileController.php:22-23`) et porte des réglages sensibles (`server_url`, `app/Models/ScreenProfile.php:37`). Un responsable de parc limité à une zone modifie la configuration d'écrans d'autres zones. Aucun test de périmètre (`ScreenProfilesTest.php:27` ne teste que l'agent).
2. **§14-9 — versions du lecteur gérées pour tout le parc par un titulaire limité.** `PlayerReleaseController.php:34-80` (`store`, `withdraw`, `update`) : `player_releases.manage` sans périmètre ; le rôle `technicien` (`config/saie.php:261`) la détient, y compris s'il est limité à un écran.
3. **§14-9 — lectures non bornées.** CC:31-49 et CC:78-87 (campagnes et écrans ciblés, quelle que soit la zone) ; CC:144-156 et `AlertController.php:68-75` (cible `all` → liste/états de tout le parc) ; `DashboardController.php:42,73,96` (état du parc et titres d'incidents ouverts pour tout compte `dashboard.view`, rôle `agent` compris) ; SV:108.
4. **§13.1-b — catégorisation erronée de l'historique.** SH:28-29 classe hors « Fiche » toute entrée dont `after` contient `profile_id` ; l'UI l'envoie à chaque modification (SD:365) → les modifications faites depuis l'interface n'apparaissent que sous « Configuration ». FRH passe car il appelle l'API sans `profile_id`.
5. **§14-8 / §13.1-e — suppression de groupe.** FK `role_assignments.group_id` sans règle de suppression (`database/migrations/2026_11_08_095843_add_floor_and_group_scope_and_fleet_roles.php:19-20`) : `DELETE /screen-groups/{id}` (SC:864-875) lève une erreur SQL (500) si une affectation, même révoquée, référence le groupe ; et `group.deleted` (SC:872) ne garde pas les membres.
6. **Mineur — §12-f.** La fiche campagne (CC:84, `draftFrom` CC:326-333) et la publication (CS:239) calculent les conflits sans jours ni créneau : la fiche affiche des conflits que l'assistant ne signale pas.
7. **Mineur — code.** Le bloc PHPDoc de `commission()` (SC:227-231) est placé avant `updateDevice()`, qui porte donc deux blocs de documentation.
