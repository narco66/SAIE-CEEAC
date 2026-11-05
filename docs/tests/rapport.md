# Rapport de tests — 5 novembre 2026

Environnement : Windows 11, PHP 8.4.7, PostgreSQL **18.6** (instance Laragon, port 5433, rôle `saie`), Node 22.21, Chrome stable (Playwright 1.64.1, canal `chrome`). Tous les tests ci-dessous ont été **exécutés** ; aucun n’est simulé.

## Synthèse

| Suite | Commande | Résultat |
| --- | --- | --- |
| Backend unitaires + fonctionnels (PHPUnit 12, PostgreSQL réel) | `vendorin\phpunit` | **55 / 55 réussis** (295 assertions) |
| Migrations depuis une base vierge, retour arrière complet, réapplication | `migrate`, `migrate:rollback`, `migrate` | 13 migrations appliquées, annulées puis réappliquées sans erreur |
| Résolveur du lecteur (node:test) | `cd player; npm test` | **8 / 8 réussis** |
| Typage frontend et lecteur | `npx tsc --noEmit` | 0 erreur |
| Build de production du frontend | `npm run build` | réussi |
| Parcours de bout en bout (Playwright) | `cd frontend; npx playwright test` | **39 / 39 réussis** en une exécution complète (10,8 min) |
| dont audit d’accessibilité automatisé (axe-core, WCAG 2.2 AA) | `e2e/accessibility.spec.ts` | 17 pages, aucune violation « serious » ou « critical » |
| Audit des dépendances | `composer audit`, `npm audit --omit=dev` (frontend, lecteur) | 0 vulnérabilité (après mise à jour de Symfony 8.1.10 → 8.1.11) |
| Formatage PHP | `vendorin\pint --test` | conforme |
| Contrat OpenAPI | `php artisan saie:openapi` | 145 chemins, à jour |

Répartition des parcours : accessibilité 17, vues 3, interactions des vues 11, circuits éditoriaux et réservation 2, lecteur 1, captures 1, fonctions P1 (sources dynamiques, comptes de service, versions du lecteur, push, présence et iCalendar) 4.

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

Non réalisables ici : R13 (arrêt du SMTP institutionnel — Mailpit local seulement), R14 (restauration chronométrée, outillage `infra/restore-drill.ps1`), R15 (charge 100 lecteurs, outillage `infra/loadtest/`), R16 (audit avec lecteur d’écran ; l’audit automatisé axe-core est fait), R18 (déploiement depuis dépôt vierge en CI : chaîne fournie, dépôt pas encore poussé), R27 (ASVS et test d’intrusion indépendant), cycle d’extinction physique de dalle (R25, partie matérielle), redémarrage électrique réel (R26, partie matérielle).

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
