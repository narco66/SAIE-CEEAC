# Preuves et tests — audit SAIE-CEEAC du 2026-11-06

Ce document rassemble les vérifications **exécutées** pendant l’audit, leur commande, leur code de sortie et leur résultat, puis la correspondance avec les scénarios de recette du cahier. Les fichiers bruts cités sont dans `travail/`. Un test non exécuté reste « non exécuté ».

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

## Scénarios de recette R01–R27 (cahier §21, l.606-643)

Légende des statuts : **Réussi** (exécuté pendant l'audit, résultat conforme) · **Réussi en simulation** (exécuté hors matériel) · **Partiel** (exécuté mais ne couvre pas tout le scénario) · **Échec** (exécuté, écart constaté) · **Non exécuté** (procédure indiquée) · **Non vérifiable** (cause indiquée). « X1 » = suite PHPUnit exécutée le 2026-11-06 (111/111 réussis) ; « X4 » = tests du lecteur (10/10).

| R | Exigences | Préconditions et étapes (résumé) | Attendu | Observé / preuve | Statut |
|---|---|---|---|---|---|
| R01 | HAB, SEC | UUID d'un document restreint deviné ; publication hors zone | Aucune fuite | X1 `SecurityAndAccessTest::test_agent_cannot_read_restricted_document_by_guessing_its_uuid` réussi ; **mais** sonde S-SEC2 : média restreint servi (HTTP 200) par `/asset-versions/{id}/file` à un contributeur sans périmètre ; piste A-02 (zone non appliquée à la programmation) non testée | **Échec** |
| R02 | PUB-01, HAB-02 | Auteur soumet puis s'auto-approuve | Refus ; validateur distinct approuve | X1 `ContentWorkflowTest::test_author_cannot_approve_own_content_and_distinct_validator_can` réussi ; cas d'un co-rédacteur validateur (A-03) non couvert | **Partiel** |
| R03 | DOC-02 | Fichier EICAR | Quarantaine, rejet, publication impossible | X1 `SecurityAndAccessTest::test_eicar_test_file_is_quarantined_then_rejected_and_cannot_be_published` réussi (pilote d'analyse de test) ; X1 `ClamdScannerTest::*` réussis (pilote clamd simulé) ; clamd réel non exercé | **Réussi en simulation** |
| R04 | PUB-05 | Deux campagnes + une alerte sur une cible | Ordre déterministe, reprise | X1 `ScheduleResolverTest::test_deterministic_order_and_rotation_at_equal_priority`, `::test_alert_preempts_and_expires` ; X4 n°3, n°4 | **Réussi en simulation** |
| R05 | PLY-03 | Coupure d'un téléchargement de média | Programme précédent conservé | X4 n°9 (ressource manquante non lancée) ; la coupure réelle de téléchargement n'est pas testée ; constat C-05 (manifeste entier rejeté, alerte comprise) | **Partiel** |
| R06 | PLY-05 | Lecteur déconnecté | Diffusion valide, retrait à expiration, repli, statut « indisponible » | X4 n°8, n°10 ; agenda/panneau/bienvenue non limités hors ligne (C-22 / piste L9) ; statut central non exercé | **Partiel** |
| R07 | SAL-05 | Deux requêtes parallèles même salle/créneau | Une seule réussite, 409 documenté | Sonde S-T1 (2 connexions PostgreSQL 18.6) : B bloquée puis refusée 23P01 ; X1 `ReservationTest::test_second_request_on_same_slot_gets_documented_conflict_without_third_party_title` réussi | **Réussi** |
| R08 | SAL-04 | Créneaux adjacents avec/sans préparation | Conforme à l'intervalle bloquant | X1 `ReservationTest::test_adjacent_slots_respect_preparation_buffers` réussi | **Réussi** |
| R09 | SAL-06 | Série de 10 dont une en conflit | Rien créé (tout ou rien) | X1 `ReservationTest::test_series_with_one_conflict_creates_nothing_in_all_or_nothing_mode` réussi ; modification « cette occurrence et les suivantes » non atomique (B-02) | **Réussi** (création) / modification non couverte |
| R10 | SAL-07 | Déplacement vers une salle occupée | Erreur, réservation initiale préservée | X1 `ReservationTest::test_moving_to_an_occupied_room_keeps_original_reservation` réussi ; contournement de validation au déplacement (B-01) non couvert | **Réussi** (scénario) |
| R11 | SAL-09, PUB-09 | Réunion confidentielle partout | Ni titre ni participants | X1 `DeviceTest::test_confidential_meeting_never_appears_in_device_manifests`, `ReservationTest::test_confidential_meeting_is_masked_for_other_agents`, `PresenceAndCalendarTest::test_ics_export_masks_confidential_subject_for_third_parties` réussis ; PDF01 classé « interne » quelle que soit la confidentialité (`ExportService.php:122`, D-08) ; PDF03 sans contrôle d'objet (B-06) ; cache du lecteur et journaux non exercés | **Partiel** |
| R12 | API-03 | Requête idempotente rejouée 5 fois | Une réservation, réponse stable | X1 `ReservationTest::test_idempotent_replay_creates_a_single_reservation_with_stable_response` réussi (dont 409 `IDEMPOTENCY_MISMATCH` sur corps différent) ; le frontend régénère la clé à chaque appel (C-07) | **Réussi** (API) |
| R13 | NOT-02 | SMTP arrêté | Opération confirmée, reprise, une seule notification | Sonde S-T13 : 201 + `confirmed`, tâche remise en file, `attempts` incrémenté ; reprise jusqu'à envoi effectif non exercée | **Partiel** (indépendance prouvée) |
| R14 | CONT-01, CONT-02 | Restauration sur environnement vide | Cohérence, RPO/RTO mesurés | Sonde S-T18 (`infra/restore-drill.ps1`) : RTO 2,5 s sur volume de démonstration, 27/27 fichiers SHA-256, volumes identiques ; RPO non mesurable (aucune sauvegarde planifiée) ; pile Docker non couverte | **Partiel** |
| R15 | PERF-01 | 100 lecteurs simulés, 10 réels | p95, erreurs, limites | `saie:loadtest-fixtures` et rapport antérieur (annoncé, commit `c787669`) ; non rejoué ; aucun lecteur réel | **Non exécuté** (procédure : `php artisan saie:loadtest-fixtures 100` sur base dédiée + outil de charge sur `/device/manifest` et `/device/heartbeat`, 10 kiosques réels) |
| R16 | UX-01, UX-02 | Clavier, lecteur d'écran, contraste, FR/EN | Parcours accessibles | Dernière exécution axe (2026-11-05) : 0 violation sur 17 vues, non rejouée ; analyse statique : focus invisible (D-04, B-15), modales sans piège de focus (D-05), textes < 12 px (D-02) ; lecteur d'écran non testé | **Échec** (analyse statique) |
| R17 | PLY-02, SEC-06 | Terminal révoqué | API refusée | X1 `DeviceTest::test_revoked_device_is_refused_by_the_api` réussi ; limite de retrait du cache documentée (`docs/lecteur.md`) | **Réussi** (API) |
| R18 | TECH-02 à 04 | Déploiement depuis dépôt vierge, migration, workers, retour arrière | Reproductible | Étape CI « migrations aller-retour » présente (`.github/workflows/ci.yml`) mais la CI n'a jamais tourné sur ce code (X9, E-02) ; non exécuté | **Non exécuté** (procédure : clone vierge, `docker compose up`, `migrate`, `migrate:rollback`, `migrate`, vérification des workers) |
| R19 | HAB-01, HAB-03 | Délégation et pouvoir expirés la veille | Refus, sessions coupées, trace | X1 `SecurityAndAccessTest::test_expired_delegation_and_deactivated_account_lose_access` réussi | **Réussi** |
| R20 | DOC-05, DOC-07 | QR interne, perte de droits, rescan | Authentification puis refus | X1 `SecurityAndAccessTest::test_internal_qr_requires_authentication_and_reevaluates_rights` réussi ; absence de cache navigateur non vérifiée | **Réussi** (API) |
| R21 | REF-03 | Même CSV importé deux fois | Aucun doublon, bilan exact, simulation sans écriture | X1 `SecurityAndAccessTest::test_csv_import_is_idempotent_and_simulation_writes_nothing` réussi ; collision d'emplacements de même code sur deux étages (A-04) non couverte | **Partiel** |
| R22 | NOT-01 | Notification après réduction de périmètre | Lien → 403 | X1 `SecurityAndAccessTest::test_notification_target_is_rechecked_when_scope_was_reduced` réussi | **Réussi** |
| R23 | DATA-01 | Recherche d'un mot présent seulement dans des restreints | 0 résultat, compteur 0 | X1 `SecurityAndAccessTest::test_full_text_search_returns_no_restricted_result_nor_count` réussi | **Réussi** |
| R24 | PUB-06, PUB-08 | Alerte prolongée puis révoquée ; FR+EN ; EN non approuvée ignorée | Traçabilité, rotation, pas d'écran vide | X1 `DeviceTest::test_alert_preempts_programme_in_manifest_and_requires_urgency_power` ; X1 `ContentWorkflowTest::test_validator_cannot_validate_a_language_outside_his_scope` ; version EN non approuvée ignorée : couvert par la compilation (lot A, PUB-08) sans test dédié | **Partiel** |
| R25 | PUB-04, PLY-08 | Campagne 10 h 00–10 h 30 ; extinction/rallumage | À la minute | X1 `ScheduleResolverTest::test_campaign_from_10_00_to_10_30_starts_and_ends_at_the_minute`, `::test_operating_window_weekend_holiday_and_evening` ; X4 n°1, n°2, n°7 ; extinction physique de dalle non vérifiée | **Réussi en simulation** |
| R26 | PLY-01, PLY-06 | Redémarrage électrique | Reprise sans intervention, journaux dédoublonnés | X1 `DeviceTest::test_deferred_receipts_are_deduplicated_and_heartbeat_tracks_clock_skew` réussi ; redémarrage électrique non réalisé (pas de terminal) | **Non vérifiable** sur matériel (procédure : kiosque représentatif, coupure secteur, chronométrage de reprise) |
| R27 | SEC-07 | Rapport ASVS N2 + test d'intrusion | Aucune vulnérabilité critique/élevée non traitée | Aucun test d'intrusion ni rapport ASVS ; l'audit relève 3 défauts bloquants de sécurité | **Non exécuté** — condition de mise en production non remplie |

Synthèse R01–R27 : Réussi 10 (R07, R08, R09, R10, R12, R17, R19, R20, R22, R23 — dont plusieurs limités à l'API) · Réussi en simulation 3 (R03, R04, R25) · Partiel 8 (R02, R05, R06, R11, R13, R14, R21, R24) · Échec 2 (R01, R16) · Non exécuté 3 (R15, R18, R27) · Non vérifiable sur matériel 1 (R26). Aucun scénario n'est **vérifié sur matériel**.
