# 09 — Guide technique du SAIE-CEEAC

Ce guide s'adresse à la DSI : installation, configuration, API, lecteurs, sauvegardes, maintenance et déploiement. Il complète les procédures détaillées existantes :

- `README.md` : démarrage local ;
- `docs/deploiement.md` : environnements, mise en production, retour arrière ;
- `docs/exploitation/mise-en-service.md` : secrets, première installation, vérifications, versions du lecteur ;
- `docs/exploitation/sauvegarde-restauration.md` ;
- `docs/lecteur.md` : matériel, navigateur, pilotage des dalles, enrôlement.

## 1. Installation de développement (Windows et Laragon)

Prérequis : PHP 8.4 (le projet tourne aussi sous 8.5) avec les extensions `pdo_pgsql`, `intl`, `fileinfo`, **`zip`**, `gd`, `openssl` ; Composer 2 ; Node.js 22 ; PostgreSQL 18.

```powershell
cd backend; composer install; copy .env.example .env; php artisan key:generate; php artisan migrate --seed
cd ..\frontend; npm ci --include=dev
cd ..\player;   npm ci --include=dev
```

Points relevés sur ce poste :

- **`extension=zip`** doit être activée dans le `php.ini` de Laragon (`C:\laragon\bin\php\php-8.5.12-1-nts-Win32-vs17-x64\php.ini`). Sans elle, la conversion bureautique et deux tests échouent. On peut aussi lancer `php -d extension=zip vendor\bin\phpunit`.
- **`npm config get omit` renvoie `dev`** sur ce poste : `npm ci` n'installe alors ni TypeScript ni Vite. Ajouter `--include=dev`.

## 2. Configuration

Fichier `backend/.env`. Valeurs par défaut dans `backend/config/saie.php`.

| Variable | Rôle | Défaut |
|---|---|---|
| `SAIE_MFA_ENFORCED` | Second facteur obligatoire pour les rôles sensibles | `true` |
| `SAIE_LOGIN_PER_IP_PER_MINUTE` **[nouveau]** | Plafond de tentatives de connexion par adresse IP, tous comptes confondus | `30` |
| `SAIE_CRITICAL_ALERT_OVERRIDES_WINDOW` **[nouveau]** | Une alerte critique s'affiche hors de la plage de fonctionnement | `true` |
| `SAIE_ANOMALY_STORAGE_RATIO`, `SAIE_ANOMALY_CLOCK_SKEW_MS`, `SAIE_ANOMALY_SYNC_STALLED_MINUTES` **[nouveau]** | Seuils des incidents automatiques d'anomalie | `0.9`, `30000`, `60` |
| `SAIE_AV_DRIVER` | `clamd` en production. **[nouveau]** En production, tout autre pilote fait échouer l'analyse et le fichier reste en quarantaine | `signature` en développement |
| `SAIE_CLAMD_HOST`, `SAIE_CLAMD_PORT` | Démon ClamAV | `127.0.0.1:3310` |
| `SAIE_OFFLINE_PUBLIC_H`, `SAIE_OFFLINE_INTERNAL_H` | Autorisation de diffusion hors ligne | 24 h et 1 h |
| `SAIE_MANIFEST_KEY_ID`, `SAIE_MANIFEST_KEY_PATH` | Clé Ed25519 de signature des manifestes | Générée hors production |
| `SAIE_SOFFICE_PATH`, `SAIE_FFMPEG_PATH` | Conversion bureautique et transcodage vidéo | Absents |
| `SAIE_VAPID_*` | Notifications push (`php artisan saie:vapid`) | Vides |
| `SAIE_DEMO_MODE` | Bandeau « Données fictives » ; jamais en production | `false` |

Contrôle avant ouverture : `php artisan saie:preflight`. Il bloque, entre autres, l'antivirus de démonstration, une clé de signature absente, les données de démonstration et un compte PostgreSQL privilégié.

## 3. API

- Contrat : `docs/api/openapi.json`, régénéré par `php artisan saie:openapi` après toute modification d'une validation ou d'une réponse. Les exemples sont dans `docs/api/examples.json`.
- Le test de contrat (`tests/Contract`) confronte **chaque réponse** de la suite PHPUnit au contrat. Une réponse non déclarée fait échouer le test.
- Nouveaux champs **[nouveau]** :
  - écran : `operational_state {code, label}`, `maintenance_reason`, `hardware {...}`, statut `maintenance` ;
  - incident : `category`, `maintenance_type`, `diagnosis`, action `diagnose`, filtre `category` ;
  - suivi de diffusion : `rows[].transmitted_at`, `summary.transmitted` ; `plays_declared` compte les passages agrégés ;
  - tableau de bord : `fleet`, `buildings`, `rooms_equipped`, `pending_validation`, `default_window`, `floors[].building` ;
  - manifeste : `alert_policy.critical_overrides_window` ;
  - accusé `played` : `payload {entry_id, content_id, content_version, plays, duration_s, first_played_at, last_played_at}`.
- Routes ajoutées au second passage **[nouveau]** :
  - `GET /calendar/{jeton}.ics` : public, limité à 60 requêtes par minute ;
  - `GET /me/calendar-subscriptions`, `POST /rooms/{room}/calendar-subscriptions`, `POST /calendar-subscriptions/{id}/revoke` ;
  - `POST /devices/{device}/reload` ;
  - `POST /documents/{document}/trash`.

  Le heartbeat renvoie `commands` (`["reload"]`, remis une seule fois). `GET /device/assets/{id}` accepte `Range` et répond 206. La fiche média expose `duplicates` ; l'aperçu de campagne expose `screens[].orientation_mismatch`.
- Nouveaux codes d'erreur **[nouveau]** : `BASE_ROLE_PRIVILEGE`, `ASSIGNEE_NOT_ELIGIBLE`, `PERIOD_TOO_LONG`, `MEDIA_NOT_DISPLAYABLE`, `DEVICE_NOT_ACTIVE`, `DOCUMENT_PUBLISHED`, `DOCUMENT_IN_USE`. La connexion ne renvoie plus `ACCOUNT_LOCKED`, mais `INVALID_CREDENTIALS` (422) dans tous les cas d'échec.

## 4. Lecteurs

- Installation, mode kiosque et agent de dalle : `docs/lecteur.md`.
- Comportement nouveau **[nouveau]** :
  - un manifeste au contenu identique mais de version plus récente (renouvellement de l'autorisation, toutes les 30 min avec les réglages par défaut) est conservé sans réactivation : il produit seulement un accusé `manifest_received` marqué `renewal` ;
  - la lecture est déclarée à la fin du créneau d'affichage. Elle n'est pas déclarée sur les panneaux de salle ni sur les bornes d'orientation ;
  - la file d'événements est sérialisée. Les événements écartés au-delà de 2 000 pendant une longue coupure sont signalés parmi les erreurs du heartbeat.
- Téléchargement reprenable **[nouveau]** : blocs de 8 Mo demandés par `Range` et conservés dans le cache `saie-partial-v1`. Après une coupure, seuls les blocs manquants sont redemandés. L'empreinte SHA-256 du fichier complet est vérifiée avant la mise en cache ; une empreinte fausse efface les blocs. Nginx (`X-Accel-Redirect`) et PHP (`BinaryFileResponse`) acceptent tous deux `Range`.
- Tests : `cd player; npm test; npm run typecheck`.

## 5. Base de données et migrations

- Migrations **additives** et réversibles. `migrate:fresh`, `db:wipe` et `migrate:reset` sont refusés en production (`DB::prohibitDestructiveCommands`).
- Migrations de la mise à niveau : `campaign_transmissions` (avec reprise depuis les manifestes conservés), fiche matérielle des écrans, catégorie des incidents. Avant de les appliquer en production :
  1. faire une sauvegarde logique (`pg_dump -Fc`, voir `sauvegarde-restauration.md`) ;
  2. lancer `php artisan migrate --force`.

  Retour arrière : `php artisan migrate:rollback --step=12` pour les douze migrations de la mise à niveau (à exécuter après avoir vidé les nouveaux états qui ne seraient plus admis : écrans « en maintenance », alertes « en attente »). Le retour arrière des écrans est refusé tant qu'un écran est « en maintenance », car l'ancienne contrainte ne l'accepte pas : repasser d'abord ces écrans au statut actif.
- Le compte applicatif `saie_app` n'a pas les droits sur le schéma : les migrations s'exécutent sous le compte propriétaire.

## 6. Sauvegarde et restauration

`infra/backup/backup.sh` (pile Docker) : sauvegarde nocturne de la base et du stockage, avec empreintes ; archivage WAL ; état écrit dans `storage/app/backup-status.json` et affiché en supervision. Exercice : `infra/restore-drill.ps1`. **À rejouer sur la pile cible** pour mesurer le RPO et le RTO réels.

## 7. Maintenance courante

| Fréquence | Action |
|---|---|
| Chaque minute | `saie:tick` (planificateur) : échéances, alertes expirées, incidents automatiques, purges |
| Quotidienne | Contrôle de la supervision (files, sauvegarde, lecteurs indisponibles) |
| À chaque version | `composer audit`, `npm audit`, `vendor\bin\pint --test`, suite PHPUnit, tests et typage du lecteur, `saie:openapi`, puis compilation |
| Trimestrielle | Exercice de restauration ; rotation de la clé de signature (sans réappairage) |

## 8. Déploiement

Voir `docs/deploiement.md` et `docs/exploitation/mise-en-service.md`. Conditions préalables à la production (livrable 10, § 9) :

- HTTPS ;
- `clamd` joignable ;
- clé de signature fournie ;
- `saie:preflight` sans échec ;
- sauvegarde planifiée ;
- test d'intrusion réalisé.

## 9. Ajouts du troisième passage [nouveau]

| Élément | Détail |
|---|---|
| Variables d'environnement | `SAIE_ALERT_FOUR_EYES_SEVERITIES` (défaut `info`), `SAIE_RETENTION_AUDIT_MONTHS` (60), `SAIE_RETENTION_TELEMETRY_DAYS` (90), `SAIE_RETENTION_NOTIFICATIONS_DAYS` (180), `SAIE_RETENTION_AUTO_PURGE` (`false`) |
| Commande | `php artisan saie:retention` (simulation) ou `--apply`, planifiée à 02 h 30 si la purge automatique est activée. Archives : `storage/app/private/archives/audit-avant-*.jsonl.gz` et leur `.sha256`. **Purge effective du journal** : réservée au propriétaire de la base, après vérification de l'archive (désactiver puis réactiver le déclencheur `audit_logs_append_only` dans une transaction) |
| Routes | `POST /alerts/{id}/approve` ; `GET`, `POST`, `PATCH /playlists` ; `POST /campaigns/{id}/archive` ; `GET`, `POST`, `PATCH /maintenance-plans` ; `GET /supervision/errors` ; `POST /documents/{id}/request-publication` ; exports `format=xlsx` ; `GET /reports/summary?site_id=&building_id=` |
| Métriques | Compteurs par minute dans le cache (`apim:*`, 2 h), alimentés par `AssignRequestId` ; Redis recommandé en production |
| Lecteur | `src/directory.ts` (annuaire), `TouchDirectory` dans `views.tsx`, `src/chunks.ts` (téléchargement reprenable) ; un manifeste d'orientation porte `orientation.touch`, `floors[].directory` et `floors[].plan` |
| Poste de développement | `extension=zip` activée dans `C:\laragon\bin\php\php-8.5.12-1-nts-Win32-vs17-x64\php.ini` (copie d'origine : `php.ini.avant-zip-2026-11-06.bak`) ; `.npmrc` (`include=dev`) dans `frontend/` et `player/` |
