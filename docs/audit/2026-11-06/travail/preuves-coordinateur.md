# Preuves recueillies par le coordinateur (2026-11-06)

Environnement : Windows 11 Pro, PHP 8.4.7 (CLI ZTS), PostgreSQL 18.6 (x86_64-windows, port 5433), Node.js 22.22.0.
Base de test isolée : `saie_ceeac_test` (phpunit.xml l.26-28), distincte de la base de démonstration `saie_ceeac`. Les sondes sont hors dépôt (dossier temporaire de session) et n'écrivent que dans `saie_ceeac_test`.

## Commandes exécutées

| # | Commande | Code de sortie | Résultat | Fichier |
|---|---|---|---|---|
| X1 | `php vendor/bin/phpunit --log-junit …` (backend, arbre de travail incluant les 13 modifications non commitées) | 0 | 111 tests, 565 assertions, 0 échec, 0 erreur, 0 ignoré, 29,3 s | `phpunit-sortie.txt`, `phpunit-junit.xml`, `tests-liste.txt` |
| X2 | `npx tsc -p tsconfig.json --noEmit` (frontend) | 0 | aucune erreur de type | `frontend-tsc.txt` |
| X3 | `npx tsc -p tsconfig.json` (player) | 0 | aucune erreur de type | `player-tsc.txt` |
| X4 | `npm test` (player, `node --test tests/*.test.ts`) | 0 | 10 tests, 10 réussis (calendrier, alertes, mode événement, autorisation hors ligne, ressource manquante, repli) | `player-tests.txt` |
| X5 | `vendor/bin/pint --test` | 1 | échec sur les 7 fichiers PHP modifiés non commités (fins de ligne CRLF et règles de style) ; le contrôle CI échouerait sur l'arbre de travail actuel | `pint-test.txt` |
| X6 | `composer audit` | 0 | aucune vulnérabilité connue | `composer-audit.txt` |
| X7 | `npm audit --omit=dev` (frontend, player) | 0 / 0 | 0 vulnérabilité | `npm-audit-*.txt` |
| X8 | Requête `pg_constraint` / `pg_extension` sur `saie_ceeac_test` | 0 | `occurrences_no_overlap` : `EXCLUDE USING gist (room_id WITH =, tstzrange(blocking_start, blocking_end, '[)') WITH &&) WHERE status IN (pending, confirmed, in_progress)` ; `resource_bookings_no_overlap` idem pour les ressources ; extensions `btree_gist 1.8`, `pgcrypto 1.4` | `versions-exec.txt` |
| X9 | `gh run list` | — | `gh` absent du poste : historique d'exécution CI **non vérifiable** ; le workflow ne se déclenche que sur `main`/`master` et les PR, pas sur la branche `mise-en-conformite` | `ci-executions.txt` |
| X10 | Lecture de `frontend/test-results/.last-run.json` (Playwright, 2026-11-05 16:34) | — | statut `failed`, 1 test en échec : `integrations.spec.ts` « V28 : export iCalendar et confirmation de présence » → `409 BOOKING_CONFLICT` (collision avec des données d'une exécution antérieure : fragilité du jeu d'essai) ; synthèses axe WCAG 2.2 AA des 17 vues : 0 violation | `frontend/test-results/` |
| X11 | Suite Playwright | non exécutée | exige API, worker, frontend et lecteur démarrés et écrit dans la base de démonstration : hors du cadre isolé de l'audit | — |

## Sondes ciblées (fichiers hors dépôt)

### S-T1 Deux réservations simultanées (test prioritaire 1)
- Préconditions : salle et réservation de base créées et validées dans `saie_ceeac_test` ; deux connexions PDO indépendantes A et B.
- Étapes : A et B ouvrent une transaction ; A insère une occurrence sur un créneau libre ; B insère la même occurrence (lock_timeout 2 s) ; A valide ; B réessaie.
- Attendu : une seule insertion réussit.
- Observé : B **bloquée** pendant la transaction de A (SQLSTATE 55P03, délai de verrou) ; après validation de A, B **refusée** par la contrainte d'exclusion (SQLSTATE 23P01) ; 2 occurrences en base (base + A). Données de sonde supprimées.
- Statut : **Réussi** (testé, PostgreSQL 18.6).

### S-T13 Panne SMTP après confirmation (test prioritaire 13)
- Préconditions : file `database` (comme en production, file asynchrone), mailer SMTP vers 127.0.0.1:1 (connexion refusée).
- Étapes : création d'une réservation dans une salle à approbation automatique, puis `queue:work --once`.
- Observé : création HTTP 201, occurrence `confirmed` ; 1 tâche en file ; après passage du worker, la tentative e-mail reste `pending` (`attempts` incrémenté, `last_error` vide), tâche remise en file avec délai, `failed_jobs` = 0.
- Statut : **Réussi** pour l'indépendance métier/SMTP. Écart mineur : l'erreur intermédiaire n'est enregistrée qu'à l'épuisement des 5 essais (`DeliverNotificationEmail::failed`, `backend/app/Jobs/DeliverNotificationEmail.php:55-59`).

### S-T12 Manifeste altéré ou mal signé (test prioritaire 12, partie signature)
- Étapes : enveloppe signée par `App\Services\ManifestSigner::sign()` (clé de l'environnement de test), vérifiée par `tweetnacl` (bibliothèque du lecteur, même appel que `player/src/engine.ts:237`).
- Observé : charge intacte acceptée ; charge modifiée (version 7→8) refusée ; signature modifiée (1 bit) refusée.
- Statut : **Réussi** pour l'intégrité. Non couvert : `verify()` (`player/src/engine.ts:235-242`) ne contrôle ni `valid_until` ni l'anti-retour (seule une version identique est ignorée, `engine.ts:211`) → manifeste ancien valablement signé rejouable.

## Correspondance des 18 tests prioritaires avec l'existant

| # | Scénario | Preuve | Statut |
|---|---|---|---|
| 1 | Réservations simultanées | S-T1 ; `ReservationTest::test_database_constraint_rejects_overlapping_active_occurrences_even_without_application_check` | Testé (réussi) |
| 2 | Créneaux adjacents avec/sans préparation | `ReservationTest::test_adjacent_slots_respect_preparation_buffers` (X1) | Testé (réussi) |
| 3 | Récurrence avec conflit | `ReservationTest::test_series_with_one_conflict_creates_nothing_in_all_or_nothing_mode` (X1) | Testé (réussi) |
| 4 | Idempotency-Key rejouée / corps différent | `ReservationTest::test_idempotent_replay_creates_a_single_reservation_with_stable_response` (409 `IDEMPOTENCY_MISMATCH`) (X1) | Testé (réussi) |
| 5 | Version obsolète | `ContentWorkflowTest::test_stale_version_is_rejected_without_overwriting` (X1) | Testé (réussi) |
| 6 | Auto-approbation | `ContentWorkflowTest::test_author_cannot_approve_own_content_and_distinct_validator_can` (X1) | Testé (réussi) |
| 7 | Téléchargement / QR hors périmètre | `SecurityAndAccessTest::test_internal_qr_requires_authentication_and_reevaluates_rights` + test de visibilité documentaire (X1) | Testé (réussi) |
| 8 | Fichier rejeté / conversion en échec | `SecurityAndAccessTest::test_eicar_test_file_is_quarantined_then_rejected_and_cannot_be_published`, `MediaConversionTest::*` (X1) | Testé (réussi) ; antivirus réel clamd non exercé (pilote de test) |
| 9 | Version anglaise non approuvée | voir matrice (lot A) | À confirmer par le lot A |
| 10 | Alerte connectée / limites hors ligne | `DeviceTest::test_alert_preempts_programme_in_manifest_and_requires_urgency_power`, `player schedule.test.ts` n°3, n°8 (X1, X4) | Testé (simulation) ; non vérifié sur matériel |
| 11 | Expiration hors ligne | `player schedule.test.ts` n°8, n°10 (X4) | Testé (simulation) ; agenda/panneau non couverts (piste L9) |
| 12 | Manifeste altéré / mal signé | S-T12 | Partiellement testé (signature oui, validité/anti-retour non) |
| 13 | Panne SMTP | S-T13 | Testé (réussi, écart mineur) |
| 14 | Réunion confidentielle partout | `DeviceTest::test_confidential_meeting_never_appears_in_device_manifests`, `ReservationTest::test_confidential_meeting_is_masked_for_other_agents`, `PresenceAndCalendarTest::test_ics_export_masks_confidential_subject_for_third_parties`, `IntegrationAndDynamicSourcesTest::…masks_confidential` (X1) | Partiellement testé (PDF et notifications : voir lot B) |
| 15 | Révocation utilisateur / terminal | `SecurityAndAccessTest::test_expired_delegation_and_deactivated_account_lose_access`, `DeviceTest::test_revoked_device_is_refused_by_the_api` (X1) | Testé (réussi) |
| 16 | Absence de mesure matérielle / présence | voir matrice (lots B, C) | À confirmer |
| 17 | Coupure réseau, redémarrage, extinction | aucun test automatisé exécuté ; aucun terminal réel | **Non vérifiable** (procédure : kiosque Chromium représentatif, coupure Ethernet 30 min, redémarrage électrique, cycle CEC) |
| 18 | Restauration et RPO/RTO | `infra/restore-drill.ps1`, `docs/exploitation/sauvegarde-restauration.md` (annoncé) ; non exécuté pendant l'audit | **Non vérifiable** dans le cadre (restauration sur base dédiée requise) |

### S-T18 Restauration et mesure RPO/RTO (test prioritaire 18)
- Commande : `powershell -File infra/restore-drill.ps1 -Port 5433 -Database saie_ceeac -User saie` (lecture seule de la base de démonstration par `pg_dump` ; restauration dans la base temporaire `saie_ceeac_restore_drill`, supprimée en fin de script ; copie des fichiers dans `%TEMP%\saie-drill-*`).
- Observé (2026-11-06) : sauvegarde 1,5 s, restauration (RTO technique) 2,5 s ; dernière écriture du journal identique avant/après (`2026-11-06 11:46:45+01`) ; volumes identiques sur 9 tables (users 14, contents 25, content_versions 15, campaigns 13, reservation_occurrences 32, documents 16, asset_versions 29, audit_logs 618, device_receipts 6 752) ; contrainte `occurrences_no_overlap` et déclencheur `audit_logs_append_only` présents ; 27/27 fichiers conformes SHA-256. Sortie : `restauration.txt`.
- Statut : **Réussi** sur volume de démonstration en mode hôte. Limites : volume sans rapport avec la production (500 Go prévus) ; aucune sauvegarde planifiée, donc RPO réel **non mesurable** ; la pile Docker (volume nommé `storage`, base conteneurisée) n'est pas couverte par le script ; le dossier `%TEMP%\saie-drill-*` conserve une copie des fichiers privés après l'exercice (à purger).

## Inventaire technique (exécuté)

- Dépôt : 9 commits (`git log`), du `9082d93` (2026-11-04, « first commit ») au `9804168` (2026-11-06) ; distant `github.com/narco66/SAIE-CEEAC`.
- Applications exécutables : API Laravel (`backend/`), SPA (`frontend/`, Vite 8.3.5, React 19.3.1, TypeScript 5.9.3, React Router 7.18.5, TanStack Query 5.105.3), lecteur/panneau (`player/`, React 19.3.1, tweetnacl 1.0.3, pdfjs-dist 5.4.624). Polices DM Sans et Manrope embarquées par `@fontsource` 5.2.8 (aucune dépendance à un CDN).
- Backend : Laravel 13.40.0, Sanctum 4.3.3, PHPUnit 12.5.43, dompdf 3.1.6, web-push 11.0.0 (`composer show --direct`, `versions-php.txt`). Fichiers de verrouillage présents : `composer.lock`, `frontend/package-lock.json`, `player/package-lock.json`.
- API : 184 routes hors fournisseurs (GET 74, POST 85, PATCH 18, DELETE 5, PUT 2) ; 166 sous `Authenticate + EnsureHumanUser + Idempotent`, **sans limitation de débit** ; 5 routes lecteur sous `EnsureDevice + throttle` ; 7 routes publiques limitées (connexion, appairage, QR) (`routes-api.json`, `routes-middlewares.txt`).
- Base de test migrée : 83 tables, 127 clés étrangères, 172 index, 2 déclencheurs non internes, 2 contraintes d'exclusion, extensions `btree_gist`, `pgcrypto` ; 16 fichiers de migration.
- Tâches planifiées (`schedule:list`) : `saie:tick` chaque minute (tâche unique regroupant expirations, rappels, escalades, supervision, purges) ; `queue:prune-failed` quotidienne. Aucune tâche de sauvegarde ni de purge de conservation.
- Fonctions simulées : `saie:demo-fleet` (`backend/app/Console/Commands/DemoFleet.php:14-66`) produit des accusés lecteurs marqués `{"simulated": true}`, refusé en production (`DemoFleet.php:20`) ; la base de démonstration contient 6 752 `device_receipts` (`restauration.txt`), à ne pas présenter comme mesures réelles.
- Aucun marqueur `TODO`/`FIXME` dans `backend/app`.

### S-SEC1 Second facteur non imposé par le serveur (confirme C-01 / piste S1)
- Préconditions : `saie.security.mfa_enforced = true` ; compte `admin_tech` actif, non enrôlé au second facteur (rôle listé dans `mfa_roles`, `backend/config/saie.php:14-15`).
- Étapes : `POST /api/v1/auth/login` (requête « stateful » d'origine `http://localhost:5173`), puis `GET /api/v1/users`.
- Observé : connexion **HTTP 200** `{"authenticated":true,"mfa_enrollment_required":true,"must_change_password":false}` ; `GET /api/v1/users` **HTTP 200** sans second facteur.
- Statut : **Défaut confirmé à l'exécution** (Bloquant).

### S-SEC2 Média restreint téléchargeable hors périmètre (confirme A-07)
- Préconditions : média PDF classé `restreint`, propriété de la structure DSI, déclaré sain ; contributeur de la structure COM dont l'affectation n'a pas de périmètre de structure ; second contributeur COM avec périmètre `COM`.
- Étapes : `GET /api/v1/asset-versions/{id}/file` par chacun.
- Observé : contributeur sans périmètre **HTTP 200** (fichier restreint servi) ; contributeur avec périmètre **HTTP 404**. Cause : `AssetController::visible()` ajoute `orWhereRaw('true')` quand le périmètre est nul.
- Statut : **Défaut confirmé à l'exécution** (Bloquant : une affectation de rôle « sans périmètre », cas par défaut, ouvre l'accès à tous les médias restreints).
