# 05 — Architecture technique cible

La cible **conserve l'architecture existante**, qui est conforme au socle prescrit : un monolithe modulaire Laravel 13 et PostgreSQL 18, servant deux clients React. Aucune technologie nouvelle n'est introduite. Les évolutions portent sur la fiabilité des preuves, l'exploitation et les modules partiels.

## 1. Architecture applicative

```
                         ┌──────────────────────────── Serveur central (VLAN serveurs) ─────────────────────────────┐
 Agents / admin ─HTTPS─► │ Nginx ─► /           frontend (build statique React)                                    │
                         │       ├► /api /sanctum PHP-FPM · Laravel 13 ──► PostgreSQL 18 (seule source de vérité)  │
 Lecteurs ──────HTTPS──► │       ├► /player/     lecteur (build statique, SRI)                                       │
 (VLAN lecteurs,         │       └► médias       X-Accel-Redirect après autorisation PHP                           │
  flux sortants seuls)   │  Workers : default · notifications · media (conversion isolée, sans réseau) · exports    │
                         │  Planificateur : saie:tick (étapes isolées) · Redis (cache, files ; jamais source)        │
                         └───────────────────────────────────────────────────────────────────────────────────────────┘
```

| Couche | Composants | Responsabilité |
|---|---|---|
| Présentation | `frontend/` (V01–V42), `player/` (T01–T05) | Aucune règle métier ; le serveur décide toujours |
| API | `app/Http/Controllers/Api/V1`, middlewares `AssignRequestId`, `SecurityHeaders`, `EnsureHumanUser`, `EnsureDevice`, `Idempotent` | Validation, autorisation (`authorizePermission`, politiques, `assertMayAdminister`, `inScope`), présentation (`Support/Present`) |
| Métier | `app/Services` (19 services) | Habilitations (`Access`), circuit de validation, campagnes, manifeste, résolution, réservations, médias, exports, notifications, audit |
| Asynchrone | `app/Jobs`, `saie:tick` | Analyse et conversion des médias, courriels et push, exports, échéances, incidents automatiques, purges |
| Données | PostgreSQL 18 | Contraintes, exclusion `gist`, déclencheurs d'ajout seul |

## 2. Architecture des données

Hiérarchie des lieux :

- `sites` → `buildings` → `floors` → `locations` (référence vers `display_zones`, transversale) → `screens` → `devices` ;
- `rooms` est rattaché à un niveau.

Ajouts de la mise à niveau, tous **additifs** et **réversibles** (méthode `down()` exercée) :

| Migration | Contenu |
|---|---|
| `2026_11_06_200851_create_campaign_transmissions_table` | `campaign_transmissions` (`device_id`, `campaign_id`, `first_manifest_version`, `first_transmitted_at`, unicité `device_id` + `campaign_id`, `RESTRICT`) ; reprise depuis les manifestes existants |
| `2026_11_06_201301_add_hardware_details_to_screens_table` | Fiche matérielle de l'écran ; `status` élargi à 12 caractères ; contraintes `active`, `maintenance`, `inactive` et type de connexion |
| `2026_11_06_201302_add_category_to_incidents_table` | `category`, `maintenance_type`, `diagnosis` avec contraintes ; reprise `device_unavailable` → `player` |

Cible à moyen terme :

- `location_type` et plan de niveau sur `floors` (PAR-04) ;
- `playlists` réutilisables, référencées par `campaign_items` (PUB-07) ;
- `approval_circuits` par catégorie (PUB-04) ;
- jeton d'abonnement iCalendar (SAL-01).

## 3. Architecture de l'API

- REST versionnée `/api/v1`. Enveloppe `data` ou `data` + `meta`. Erreurs `{code, message, errors, request_id}`.
- Idempotence par `Idempotency-Key`. Concurrence optimiste par `If-Match` (428 ou 409).
- Contrat OpenAPI 3.1 régénéré par `php artisan saie:openapi`. **Chaque réponse produite par les tests est validée contre le contrat.**
- Points d'entrée :
  - humains : session SPA et CSRF ;
  - lecteurs : `/api/v1/device/*`, jeton `device` ;
  - intégration : `/api/v1/integration/*`, comptes de service à portée limitée.

## 4. Architecture de diffusion

```
contenu ─► validation ─► version immuable ─► campagne (version_map) ─► ManifestBuilder (par lecteur)
   ─► signature Ed25519 ─► GET /device/manifest (ETag) ─► [campaign_transmissions : « Transmis »]
   ─► lecteur : vérification signature + destinataire + validité + anti-retour
         ├─ contenu nouveau : préchargement vérifié SHA-256 → bascule atomique → accusés reçu/chargé/activé
         ├─ contenu identique, version plus récente (« renew ») : nouvelle autorisation conservée, aucun accusé d'activation
         └─ alertes et données de salle appliquées sans attendre les médias
   ─► affichage : « lecture déclarée » en fin de créneau réussi (durée, contenu, version, passages agrégés par 10 min)
   ─► file différée sérialisée ─► POST /device/receipts ─► suivi V24 et PDF05
```

Niveaux de preuve (annexe C), **jamais confondus** :

| Niveau | Source |
|---|---|
| Programmé | Campagne publiée |
| Transmis | `campaign_transmissions` |
| Reçu, chargé, activé | Accusés du lecteur |
| Lecture déclarée | Accusé `played` de fin de créneau |
| Écran visible | Retour matériel de la dalle uniquement (CEC, RS-232, API) ; sinon « Non mesuré » |
| Vu par une personne | Jamais estimé |

Règles de résolution identiques côté serveur (`ScheduleResolver`) et côté lecteur (`schedule.ts`) :

1. **Hors plage** : seules les alertes critiques sont affichées, si la politique du manifeste l'autorise. Sinon, écran éteint.
2. **Alertes actives** : critique, puis avertissement, puis exercice, puis information.
3. **Mode événement**.
4. **Priorité décroissante**, puis date de début, puis identifiant ; rotation à priorité égale.
5. **Repli** valide, puis repli neutre.

## 5. Architecture du lecteur

| Fonction | Mécanisme |
|---|---|
| Identité | Code d'appairage, puis jeton individuel stocké dans IndexedDB ; rotation à 30 jours |
| Cache | Cache Storage vérifié par SHA-256 ; réserve de 20 % du quota ; éviction des ressources non référencées ; médias des deux derniers manifestes autorisés |
| Hors ligne | Autorisation portée par le manifeste (public 24 h, interne 1 h) ; service worker pour redémarrer sans réseau |
| Résilience | Délai de 20 s par appel ; heartbeat indépendant de la synchronisation ; chien de garde (rendu figé 60 s ou 5 erreurs par minute) ; `RenderGuard` |
| Événements | File IndexedDB **sérialisée** (2 000 au plus) ; événements écartés comptés et signalés |
| Heure | Écart d'horloge mesuré par le serveur à chaque heartbeat |
| Version | Canal stable ou pilote ; version cible vérifiée (SRI) ; retour à la version de base |
| Dalle | Agent local `panel-agent.mjs` (CEC, RS-232, API), accessible depuis l'origine du lecteur seulement |

Évolutions cibles :

- reprise des téléchargements par `Range` (PLY-R1) ;
- commande de redémarrage signée (PLY-R2) ;
- compensation du temps d'aller-retour pour l'horloge.

## 6. Architecture réseau

- VLAN lecteurs séparé du VLAN serveurs. Flux **sortants** seulement depuis les lecteurs, en HTTPS vers le serveur central. Aucune exposition entrante des terminaux (SEC-06).
- HTTPS obligatoire, HSTS, redirection 301 (`infra/nginx.conf`).
- Sortie Internet du serveur limitée aux sources dynamiques autorisées, avec protection contre le rebinding DNS. Le worker de conversion n'a aucun accès réseau.

## 7. Architecture de sécurité

| Domaine | Mesures |
|---|---|
| Identités humaines | Sanctum SPA, MFA TOTP imposée pour les rôles sensibles, anti-rejeu, verrouillage, réponses uniformes, limite par compte et par IP |
| Habilitations | Rôles datés, périmètre structure + site + zone, pouvoirs étendus nominatifs, séparation des fonctions, protection hiérarchique, refus journalisés |
| Terminaux | Jeton individuel à portée `device`, rotation, révocation, manifestes signés, rotation de clé sans réappairage |
| Fichiers | Quarantaine, liste blanche, signature MIME, antivirus en échec sûr en production, contrôle des archives, conversion isolée |
| Données | Compte PostgreSQL applicatif sans privilège sur le schéma ni sur le journal ; secrets en variables d'environnement ou secrets Docker |
| Traçabilité | `audit_logs` en ajout seul (déclencheur), identifiant de requête corrélé |

## 8. Architecture de supervision

`GET /api/v1/supervision` contrôle :

- API, base, files (pilote réel : Redis en production), stockage ;
- lecteurs, avec le nombre de joignables, suspects et indisponibles ;
- dalles : « non mesuré » sans canal de pilotage ;
- sauvegarde : fraîcheur lue dans `backup-status.json` ;
- sources dynamiques : fraîcheur et statut du dernier relevé.

`saie:tick` ouvre et résout les incidents « lecteur indisponible » et notifie les gestionnaires. L'état opérationnel des écrans est calculé à chaque lecture.

Évolution cible : notifier les alertes techniques (stockage, files, sauvegarde) et ajouter des métriques d'API (ADM-02).

## 9. Architecture de continuité de service

| Scénario | Comportement |
|---|---|
| Coupure Internet | Sans effet sur la diffusion (aucune dépendance externe des lecteurs) ; les sources dynamiques gardent leur dernier relevé |
| Serveur central indisponible | Les lecteurs poursuivent le dernier programme valide jusqu'à l'expiration de l'autorisation, puis passent au repli ou à l'écran neutre ; les événements s'accumulent dans la file |
| Rétablissement | Reconnexion automatique, ETag, envoi de la file différée, nouveau manifeste |
| Coupure électrique d'un terminal | Redémarrage sur la coquille hors ligne (service worker) et le cache vérifié |
| Perte de données serveur | Sauvegarde nocturne de la base et du stockage, archivage WAL (RPO ≤ 1 h visé), procédure `docs/exploitation/sauvegarde-restauration.md` ; exercice sur la pile cible à faire |
