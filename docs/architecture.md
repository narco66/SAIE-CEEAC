# Architecture

Monolithe modulaire Laravel (chapitre 14) exposant une API versionnée `/api/v1`, consommée par deux clients distincts : l’interface d’administration et le lecteur kiosque. PostgreSQL est la seule source de vérité ; aucun client ne s’y connecte directement.

```
 Navigateur agent / admin ──HTTPS──► Nginx ──► /            frontend (React, build statique)
                                        ├──► /api, /sanctum  PHP-FPM · Laravel 13 ──► PostgreSQL 18
 Lecteur kiosque (Chromium) ──HTTPS──►  └──► /player/       lecteur (React, build statique)      ▲
        │ jeton individuel « device »                                                            │
        └── GET /api/v1/device/manifest (ETag) · heartbeat · receipts · assets      Workers (files) · planificateur (saie:tick)
                                                                                    Redis (cache, files) · stockage privé
```

## Backend (`backend/app/`)

| Couche | Rôle |
| --- | --- |
| `Http/Controllers/Api/V1` | Un contrôleur par domaine ; chaque action appelle `authorizePermission()` (refus par défaut, journalisé) ; formats de sortie centralisés dans `Support/Present` (masquage des réunions confidentielles). |
| `Services/Access` | Droits effectifs : rôles datés, périmètres (structure + descendants), langues de validation, pouvoirs étendus « urgence », « modèles », « prestations » (HAB-01 à HAB-03). |
| `Services/ContentWorkflow` | Cycle PUB-01 par langue : soumission, validation distincte de l’auteur, retour motivé, révision automatique d’une version approuvée modifiée, instantané immuable `content_versions`. |
| `Services/CampaignService` | Publication atomique sur versions approuvées, ciblage écran/groupe/zone/niveau, compatibilité de classification, statuts temporels. |
| `Services/ManifestBuilder`, `ManifestSigner`, `ScheduleResolver` | Manifeste minimal par lecteur (annexe A), signature Ed25519, régénération à mi-vie de l’autorisation, résolution déterministe (PUB-05) identique à `player/src/schedule.ts`. |
| `Services/ReservationService` | Intervalles `[début, fin[` avec tampons, séries bornées « tout ou rien » ou partielles, validation manuelle, déplacement, annulation par portée, non-présentation. Garantie finale : contrainte `EXCLUDE USING gist` sur `reservation_occurrences`. |
| `Services/AssetService`, `Jobs/ProcessAssetVersion`, `VirusScanner` | Dépôt en quarantaine dans le stockage privé, signature MIME réelle, SHA-256, antivirus, contrôle des archives, miniatures, extraction de texte PDF. |
| `Services/ExportService`, `Jobs/GenerateExport` | PDF01–PDF10 (Dompdf, modèle commun `resources/views/pdf/document.blade.php`) et CSV neutralisés ; droits revérifiés à la génération et au téléchargement. |
| `Services/Notifier`, `Jobs/DeliverNotificationEmail` | Notifications internes dédoublonnées et courriels avec reprises exponentielles ; l’échec d’un courriel n’annule jamais l’opération métier. |
| `Services/Audit` | Journal en ajout seul (déclencheur PostgreSQL), secrets filtrés. |
| `Console/Commands` | `saie:tick` (chaque minute), `saie:create-admin`, `saie:openapi`, `saie:demo-fleet` (démonstration uniquement). |

Middleware : `AssignRequestId` (corrélation), `SecurityHeaders` (CSP, nosniff), `EnsureHumanUser` (comptes actifs, jamais de jeton lecteur), `EnsureDevice`, `Idempotent` (`Idempotency-Key` lié à l’acteur et à l’empreinte du corps).

## Frontend (`frontend/src`)

- `lib/api.ts` : client unique (cookies, CSRF, clé d’idempotence, erreurs `ApiError`) ; `lib/auth.tsx` : `GET /me`, gardes de route et de menu ; `lib/hooks.ts` : listes paginées côté serveur.
- `components/` : coquille et composants de la maquette (classes CSS reprises de l’export Figma dans `maquette.css`).
- `pages/<domaine>/` : vues V01–V42 regroupées par domaine (account, display, publication, rooms, admin).

## Lecteur (`player/src`)

| Fichier | Rôle |
| --- | --- |
| `engine.ts` | Appairage, synchronisation (30 s ± aléa, 5 min hors plage), vérification de signature, téléchargement vérifié, bascule atomique, heartbeat (écart d’horloge), file d’événements différés, révocation. |
| `storage.ts` | IndexedDB (identité, manifeste actif, événements) et Cache Storage (ressources vérifiées), quota et éviction. |
| `schedule.ts` | Résolution du programme, plage de fonctionnement, autorisation hors ligne, audio. |
| `views.tsx`, `Player.tsx` | Vues T01 à T05, alerte plein écran, repli, bandeau hors connexion. |
| `public/sw.js` | Coquille hors ligne pour redémarrer sans réseau après coupure électrique. |

## Flux de publication

dépôt → quarantaine → analyse → (miniature / texte) → composition par langue → soumission → validation distincte → instantané immuable → campagne (versions figées dans `version_map`) → manifeste signé → téléchargement vérifié → bascule atomique → lecture (y compris hors ligne) → reçus `manifest_received`, `content_loaded`, `activated`, `played` → suivi V24 par niveau de preuve (annexe C).
