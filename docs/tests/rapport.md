# Rapport de tests — 5 novembre 2026

Environnement : Windows 11, PHP 8.4.7, PostgreSQL **18.6** (instance Laragon, port 5433, rôle `saie`), Node 22.21, Chrome stable (Playwright 1.64.1, canal `chrome`). Tous les tests ci-dessous ont été **exécutés** ; aucun n’est simulé.

## Synthèse

| Suite | Commande | Résultat |
| --- | --- | --- |
| Backend unitaires + fonctionnels (PHPUnit 12, PostgreSQL réel) | `vendor\bin\phpunit` | **74 / 74 réussis** (373 assertions) |
| Migrations depuis une base vierge, retour arrière complet, réapplication | `migrate`, `migrate:rollback`, `migrate` | 13 migrations appliquées, annulées puis réappliquées sans erreur ; les 3 migrations P2 ajoutées ensuite ont été annulées puis réappliquées sans erreur |
| Résolveur du lecteur (node:test) | `cd player; npm test` | **10 / 10 réussis** |
| Typage frontend et lecteur | `npx tsc --noEmit` | 0 erreur |
| Builds de production (frontend, lecteur) | `npm run build` | réussis |
| Parcours de bout en bout (Playwright) | `cd frontend; npx playwright test` | **43 / 43 réussis** en une exécution complète (10,9 min) |
| dont audit d’accessibilité automatisé (axe-core, WCAG 2.2 AA) | `e2e/accessibility.spec.ts` | 17 pages, aucune violation « serious » ou « critical » |
| Audit des dépendances | `composer audit`, `npm audit --omit=dev` (frontend, lecteur) | 0 vulnérabilité (après mise à jour de Symfony 8.1.10 → 8.1.11) |
| Formatage PHP | `vendor\bin\pint --test` | conforme |
| Contrat OpenAPI | `php artisan saie:openapi` | 146 chemins, à jour |

Répartition des parcours (43) : accessibilité 17, vues 3, interactions des vues 11, circuits éditoriaux et réservation 2, lecteur 1, captures 1, fonctions P1 (sources dynamiques, comptes de service, versions du lecteur, canal d’un lecteur, push, présence et iCalendar) 5, exigences P2 (mode événement, diffusion par courriel, restauration) 3.

Exigences P2 ajoutées le 5 novembre et leurs preuves : DOC-09 `DocumentBroadcastTest` (5 tests) ; PUB-12 `EventModeTest` (3), `ScheduleResolverTest` (2 cas ajoutés) et `schedule.test.ts` (2 cas ajoutés, même règle côté lecteur) ; SAL-15 `CateringServiceTest` (4) ; parcours `e2e/p2.spec.ts`.

## Recette technique (R14, R15)

### R14 — restauration (CONT-02)

`infra/restore-drill.ps1 -Port 5433` : sauvegarde `pg_dump` + copie des fichiers privés, restauration dans une base et un dossier vierges, contrôles.

| Mesure | Résultat |
| --- | --- |
| Durée de sauvegarde | 1,8 s |
| Durée de restauration (RTO technique, hors réinstallation de la configuration) | 2,9 s |
| Volumes (utilisateurs, contenus, versions, campagnes, occurrences, documents, médias, journal, reçus) | identiques (ex. 355 → 355 entrées de journal, 6 726 → 6 726 reçus) |
| Dernière écriture du journal | identique avant et après |
| Contrainte d’exclusion des réservations, déclencheur du journal en ajout seul | présents |
| Fichiers : empreinte SHA-256 | 23 conformes, 0 écart |

Portée : jeu de démonstration (quelques Mo). La durée sur les volumes réels et le RPO avec archivage WAL (`docs/exploitation/sauvegarde-restauration.md`) restent à mesurer sur l’environnement cible.

### R15 — charge de 100 lecteurs (PERF-01)

Pile proche de la production : Nginx 1.28, 8 processus PHP 8.4 (FastCGI, OPcache), PostgreSQL 18, base de charge dédiée (100 écrans et lecteurs appairés, `saie:loadtest-fixtures`), supprimée après l’essai.

| Scénario | Requêtes | Erreurs | p50 | p95 | p99 | max |
| --- | --- | --- | --- | --- | --- | --- |
| Requête isolée : manifeste complet / conditionnel / heartbeat (médiane) | 10 chacune | 0 | 86 / 93 / 58 ms | — | — | — |
| 100 lecteurs, 3 min, cadence réelle (30 s + aléa) : manifeste | 556 | 0 | 91 ms | 199 ms | 216 ms | 242 ms |
| idem : heartbeat | 556 | 0 | 59 ms | 171 ms | 186 ms | 202 ms |
| Reprise simultanée après coupure : 100 manifestes complets au même instant (3 rafales) | 300 | 0 | 761–1 250 ms | 1,4–2,3 s | — | 2,5 s |

82 % des synchronisations aboutissent à un `304` (456 sur 556) : le manifeste n’est pas retransmis. Le serveur recompile cependant le programme à chaque appel (les plages et campagnes dépendent de l’heure) : le `304` économise la bande passante, pas le calcul. Limite observée : environ 40 à 60 manifestes complets par seconde avec 8 processus PHP sur ce poste. Non couverts : téléchargement des médias (servis par Nginx, hors PHP), 10 lecteurs physiques, réseau réel de la Commission.


## Défauts détectés et corrigés le 5 novembre

1. **Dépendances vulnérables** : six avis de sécurité Symfony (`http-foundation`, `http-kernel`, `routing`, `yaml`), dont CVE-2026-118514 → mise à jour 8.1.11 ; contrainte `php` alignée sur `^8.4`.
2. **Contrastes insuffisants** (WCAG 1.4.3) sur 13 pages : 22 couleurs de texte de la maquette sous 4,5:1 (navigation, fil d’Ariane, en-têtes de tableau, mentions secondaires, statuts) → teintes ajustées au plus près de l’original, contraste ≥ 4,6:1.
3. **Paramètre « Notifications push » sans effet** : l’envoi ne dépendait que des clés VAPID → le canal exige désormais aussi l’activation en V39 (test ajouté).
4. **Contrat OpenAPI périmé et invalide** : 17 routes absentes, chemins préfixés par `//` → générateur corrigé, contrat régénéré.
5. **Fonctions sans interface** : sources dynamiques, comptes de service, versions et canaux du lecteur, abonnement push, confirmation de présence et export iCalendar n’étaient accessibles que par l’API → écrans ajoutés (V39, V40, V08, V03, V28, V31) et parcours de bout en bout.
6. **Libellés du journal** : 40 codes d’action sans libellé lisible → complétés ; V38 et l’historique des réservations affichent le libellé.
7. **Test de validation dépendant de l’ordre alphabétique** : le validateur est désigné par habilitation, périmètre puis charge → le parcours suit l’affectation réelle renvoyée par l’API.
8. Sondes de diagnostic (`zz-*.spec.ts`) exclues des exécutions normales (`E2E_PROBES=1` pour les lancer).

## Couverture des scénarios de recette (chapitre 21)

| Scénario | Preuve automatisée |
| --- | --- |
| R01 accès horizontal | `SecurityAndAccessTest::test_agent_cannot_read_restricted_document_by_guessing_its_uuid`, `DeviceTest::test_internal_content_never_reaches_public_zone…` |
| R02 séparation auteur/validateur | `ContentWorkflowTest::test_author_cannot_approve_own_content…` ; E2E `workflows.spec.ts` (validation par l’interface, refus pour l’auteure) |
| R03 antivirus | `SecurityAndAccessTest::test_eicar_test_file_is_quarantined_then_rejected…` |
| R04 alerte prioritaire, reprise | `DeviceTest::test_alert_preempts…`, `ScheduleResolverTest`, E2E `player.spec.ts` |
| R05 aucune activation partielle | `player/tests/schedule.test.ts` (ressource manquante) ; moteur `prepare()` (bascule après téléchargement complet vérifié) |
| R06 lecteur déconnecté | E2E `player.spec.ts` (coupure réseau : programme conservé, bandeau hors ligne) ; `schedule.test.ts` (expiration d’autorisation) |
| R07 concurrence | `ReservationTest::test_database_constraint_rejects_overlapping…`, `…second_request_on_same_slot…` |
| R08 tampons | `ReservationTest::test_adjacent_slots_respect_preparation_buffers` |
| R09 série tout ou rien | `ReservationTest::test_series_with_one_conflict_creates_nothing…` |
| R10 déplacement en conflit | `ReservationTest::test_moving_to_an_occupied_room_keeps_original_reservation` |
| R11 confidentialité | `DeviceTest::test_confidential_meeting_never_appears_in_device_manifests`, `ReservationTest::test_confidential_meeting_is_masked…`, E2E tableau de bord |
| R12 idempotence ×5 | `ReservationTest::test_idempotent_replay_creates_a_single_reservation…` |
| R17 révocation | `DeviceTest::test_revoked_device_is_refused_by_the_api`, E2E `player.spec.ts` |
| R19 délégation / pouvoir expirés | `SecurityAndAccessTest::test_expired_delegation…`, `DeviceTest::test_alert_preempts…` (pouvoir « urgence » expiré refusé) |
| R20 QR interne | `SecurityAndAccessTest::test_internal_qr_requires_authentication…` |
| R21 import idempotent | `SecurityAndAccessTest::test_csv_import_is_idempotent…` |
| R22 notification et périmètre réduit | `SecurityAndAccessTest::test_notification_target_is_rechecked…` |
| R23 recherche sans fuite | `SecurityAndAccessTest::test_full_text_search_returns_no_restricted_result_nor_count` |
| R24 multilinguisme | `ContentWorkflowTest` (validation par langue) ; E2E `player.spec.ts` (version EN non approuvée absente de l’écran) |
| R25 campagne 10:00–10:30 et plage | `ScheduleResolverTest::test_campaign_from_10_00_to_10_30…`, `…operating_window…` |
| R26 reçus dédoublonnés | `DeviceTest::test_deferred_receipts_are_deduplicated…` |
| Journal en ajout seul (SEC-04) | `SecurityAndAccessTest::test_audit_log_is_append_only_at_database_level` |
| CSV neutralisés, PDF générés et réautorisés (TAB-02) | `SecurityAndAccessTest::test_csv_export_neutralizes…` ; PDF01 contrôlé visuellement |

Non réalisables ici : R13 (arrêt du SMTP institutionnel — Mailpit local seulement), R14 et R15 sur l’environnement cible (exécutés sur ce poste, voir plus haut), R16 (audit avec lecteur d’écran ; l’audit automatisé axe-core est fait), R18 (déploiement depuis dépôt vierge en CI : chaîne fournie, dépôt pas encore poussé), R27 (ASVS et test d’intrusion indépendant), cycle d’extinction physique de dalle (R25, partie matérielle), redémarrage électrique réel (R26, partie matérielle).

## Défauts détectés et corrigés le 4 novembre

1. Clés étrangères auto-référencées émises avant la clé primaire (migration `users`) → ajoutées en seconde étape.
2. Fonction de déclencheur non rejouable après `migrate:fresh` → `CREATE OR REPLACE FUNCTION`.
3. **Décalage d’une heure de tous les instants** : la session PostgreSQL interprétait les dates dans le fuseau du serveur (WAT) → session forcée en UTC (`config/database.php`).
4. Variable de fin de créneau écrasée par l’exception dans la création de séries partielles → renommage.
5. `isActive()` faux pour un compte fraîchement créé (défaut posé par la base) → valeur par défaut explicite.
6. `GET /assets` en erreur 500 (fusion de collection Eloquent) → `toBase()`.
7. Focus des fenêtres modales réinitialisé à chaque rendu du parent → gestionnaire de fermeture stabilisé.
8. Seeder : validatrice EN hors périmètre du Protocole (la règle de périmètre fonctionnait) → périmètre de démonstration élargi.
9. React Router 7.9 vulnérable (avis GHSA) → 7.18.5.

## Contrôle visuel

Captures 1440 × 900 dans `docs/tests/captures/` (accueil, contenus, parc, salles, alertes, rapports, utilisateurs, calendrier) comparées à l’export Figma : structure, palette, typographie (DM Sans / Manrope), composants et libellés conformes ; les chiffres sont réels au lieu des valeurs figées de la maquette. Le PDF01 a été vérifié (logo, accents, QR, classification, fuseau, pagination « Page n sur N »).
