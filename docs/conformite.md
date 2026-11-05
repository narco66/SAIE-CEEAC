# Matrice de conformité fonctionnelle et visuelle

> Mise à jour du 5 novembre 2026 : fonctions P1 achevées et suites rejouées (PHPUnit 55/55, Playwright 39/39, voir `docs/tests/rapport.md`).

État : **Réalisé** (implémenté et vérifié par test ou exécution), **Réalisé (non testé auto.)** (implémenté, vérifié manuellement ou par compilation seulement), **Partiel**, **Préparé** (structure, API ou interface présentes, fonction désactivée ou dépendante d’un accès externe), **Hors réalisation** (organisationnel ou matériel).

## Exigences P0

| Exigence | État | Implémentation | Preuve |
| --- | --- | --- | --- |
| GOV-01 indicateurs | Réalisé | `ReportController::indicators` (période, cible, base de calcul), cartes V04/V33 | E2E tableau de bord |
| GOV-02 cadre données | Partiel | mention participants externes (V27, V28, PDF01), registre des durées dans le dictionnaire | registre des traitements à établir par la DSI |
| HAB-01 refus par défaut, périmètres, délégations datées | Réalisé | `Access`, `authorizePermission`, `EnsureHumanUser` | `SecurityAndAccessTest` (R01, R19) |
| HAB-02 séparation auteur/validateur | Réalisé | `ContentWorkflow::assertCanValidate` | `ContentWorkflowTest`, E2E |
| HAB-03 pouvoirs étendus | Réalisé | `role_extensions`, V34/V35 | `DeviceTest` (urgence expirée) |
| HAB-04 échéances, rappel, escalade | Réalisé | `approvals`, `saie:tick` | démonstration (tâche escaladée visible chez le suppléant) |
| REF-01, REF-02 référentiels, écrans | Réalisé | `Referentials`, V36, V07/V08 | E2E vues |
| REF-03 import CSV | Réalisé | `ImportController` | `test_csv_import_is_idempotent…` |
| DOC-01, DOC-02 dépôt, contrôles, antivirus | Réalisé (clamd en prod.) | `AssetService`, `ProcessAssetVersion` | R03 ; rejet MIME en démonstration |
| DOC-03 métadonnées, recherche plein texte | Réalisé | `search_vector` GIN, extraction PDF | R23 |
| DOC-04 classifications | Réalisé | `DocumentAccess`, filtrage du manifeste par zone | R01, `DeviceTest` |
| DOC-05, DOC-07 QR et routes documentaires | Réalisé | `QrController`, `/documents/...` | R20 |
| DOC-06 dépublication | Réalisé | liens QR expirés, statut | — |
| PUB-01, PUB-02 cycle et traçabilité | Réalisé | `ContentWorkflow`, `workflow_events`, `audit_logs` | `ContentWorkflowTest` |
| PUB-03 modèles structurés | Réalisé | `TemplateController` (types de zones bornés), V15/V16 | compilation, E2E vues |
| PUB-04, PUB-05 campagnes, résolution déterministe | Réalisé | `CampaignService`, `ScheduleResolver` + `schedule.ts` | tests unitaires PHP et TS |
| PUB-06 alertes | Réalisé | `AlertController`, `alert_receipts` | `DeviceTest`, E2E lecteur (< 60 s) |
| PUB-07 niveaux de preuve | Réalisé | V24 `delivery`, PDF05, « Non mesuré » | — |
| PUB-08 multilinguisme | Réalisé | `content_languages`, rotation par rang | E2E lecteur (EN non approuvée absente) |
| PUB-09 agenda des écrans | Réalisé | `ManifestBuilder::agendaFor` (« Réservée ») | `DeviceTest` R11 |
| PLY-01 lecteur kiosque, watchdog | Réalisé (logiciel) | `player/` ; superviseur système à configurer | E2E lecteur |
| PLY-02 appairage | Réalisé | codes à usage unique, jetons individuels | `DeviceTest`, E2E |
| PLY-03 manifeste signé, bascule atomique | Réalisé | Ed25519, `prepare()` puis `setActive()` | `DeviceTest`, E2E |
| PLY-04 cache, quota, éviction | Réalisé (persistance à confirmer au pilote) | `storage.ts` | — |
| PLY-05 hors ligne, autorisation 24 h / 1 h | Réalisé | `authorization` du manifeste | `schedule.test.ts`, E2E coupure |
| PLY-06 heartbeat, statuts, dédoublonnage | Réalisé | `heartbeat`, `receipts`, incidents auto | `DeviceTest` |
| PLY-07 synchronisation ETag | Réalisé | `If-None-Match` → 304 | `DeviceTest` |
| PLY-08 plage de fonctionnement | Réalisé (logiciel) ; matériel à valider au pilote | écran noir, sync espacée, extinction/allumage par l’agent local `player/agent/panel-agent.mjs` (HDMI-CEC ou commande RS-232/constructeur) | tests résolveur ; agent essayé en pilote `simulate` seulement |
| PLY-10 politique sonore, sous-titres | Réalisé | `audio_policy`, blocage sans sous-titres | — |
| SAL-01, SAL-02 salles, disponibilités | Réalisé | `RoomController`, V25/V30/V31 | E2E vues |
| SAL-03 à SAL-07 états, intervalles, EXCLUDE, séries, modifications | Réalisé | `ReservationService`, contrainte GiST | `ReservationTest` (R07–R12) |
| SAL-08 prestations | Réalisé | `service_requests`, V32 | démonstration |
| SAL-09 panneau confidentiel / hors ligne | Réalisé | T03, manifeste sans titre | `DeviceTest` R11 |
| SAL-12 non-présentation manuelle | Réalisé | `declareNoShow`, « Présence non mesurée » | `ReservationTest` |
| NOT-01, NOT-02 notifications, courriels | Réalisé | `Notifier`, file avec reprises | R22 ; Mailpit en local |
| TAB-01, TAB-02 tableaux de bord, exports | Réalisé | V04, V33, `ExportService` (CSV neutralisés) | `test_csv_export_neutralizes…`, PDF01 vérifié |
| UX-01 à UX-03 | Réalisé (audit avec lecteur d’écran non fait) | états chargement/vide/erreur/interdit, clavier, libellés, contrastes ≥ 4,5:1 | audit axe-core WCAG 2.2 AA sur 17 pages sans violation grave |
| TECH-01 à TECH-04 | Réalisé (CI à exécuter une première fois) | Dockerfile et CI vérifient les extensions ; migrations additives ; chaîne `.github/workflows/ci.yml` (tests PostgreSQL 18, builds, audits, SBOM, empreintes, E2E) | dépôt non encore poussé : la CI n’a pas tourné |
| DATA-01 à DATA-03 | Réalisé | 80 tables, index, contraintes, dictionnaire généré | migrations |
| API-01 à API-06 | Réalisé | erreurs homogènes, pagination, idempotence, If-Match, OpenAPI généré | R12, `VERSION_CONFLICT` |
| SEC-01 TLS, MFA, verrouillage | Réalisé (TLS au déploiement) | TOTP, verrouillage 5 échecs | `test_login_is_generic…` |
| SEC-02 policies, CSP, SSRF | Réalisé | contrôles par action, CSP, aucune URL libre | — |
| SEC-03 conversion isolée | Réalisé (binaires à installer) | conversion LibreOffice/ffmpeg optionnelle, processus isolé et borné (`SAIE_SOFFICE_PATH`, `SAIE_FFMPEG_PATH`) ; état explicite si absents ; contrôle des archives | `MediaConversionTest` |
| SEC-04, SEC-05 journal en ajout seul, secrets filtrés | Réalisé | déclencheur PostgreSQL, `Audit::filter` | test dédié |
| SEC-06 réseau des lecteurs | Hors réalisation | flux sortants uniquement depuis le lecteur | infra DSI |
| SEC-07 ASVS / test d’intrusion | Hors réalisation | — | à commander |
| PERF-01, CONT-01, CONT-02 | Réalisé sur poste ; à confirmer sur l’environnement cible | séparation des téléchargements, procédure de sauvegarde, outillage `infra/loadtest/` et `infra/restore-drill.ps1` | R15 : 100 lecteurs, 0 erreur, p95 199 ms ; R14 : restauration complète vérifiée (empreintes, volumes, contraintes) |
| INF-01, INF-02, QUAL-02, LIV-02 (manuels complets), EXP-01 à EXP-05 | Partiel / hors réalisation | supervision applicative fournie ; organisationnel | — |
| LIV-01, LIV-03 | Réalisé | sources, migrations, OpenAPI, dictionnaire, comptes initiaux sécurisés | — |

## Exigences P1 / P2

| Exigence | État |
| --- | --- |
| DOC-08 accusés de lecture | Réalisé (audiences, `document_reads`, V14) |
| PUB-10 composants dynamiques | Réalisé : liste blanche administrée en V39 (déclaration, modification, test), collecte serveur HTTPS public, SSRF refusé ; `IntegrationAndDynamicSourcesTest` |
| PUB-11 repli par zone | Réalisé |
| PLY-09 état physique de la dalle | Réalisé (logiciel) : état lu par l’agent local et transmis au heartbeat ; « Non mesuré » sans agent. Matériel à valider au pilote |
| PLY-11 capture de rendu | Réalisé : demande en V08, capture du rendu par le lecteur (`html-to-image`), téléversement unique, conservation 10 min |
| PLY-12 canaux de version du lecteur | Réalisé : publication pilote puis stable, retrait en une action (V40), canal par lecteur (V08), version cible dans le manifeste signé ; `PlayerReleaseTest` |
| SAL-10 confirmation de présence | Réalisé : confirmation par l’organisateur ou par code (V28), libération automatique par salle (V31) ; `PresenceAndCalendarTest` |
| SAL-11 calendrier externe | Partiel : export iCalendar par réservation (V28) et par salle (V31), confidentialité préservée ; connecteurs Exchange/Google non fournis |
| SAL-13 ressources mobiles | Réalisé (EXCLUDE dédiée) |
| SAL-14 listes de contrôle | Réalisé (par occurrence, PDF03) |
| ACC-01 orientation T05, ACC-02 bienvenue | Réalisé |
| NOT-03 push | Réalisé : activation par l’administration (V39) et clés VAPID, abonnement par l’utilisateur (V03, service worker), événements éligibles seulement ; `PushNotificationTest` |
| API-07 API d’intégration | Réalisé : comptes de service à portées (V39), jeton affiché une fois, renouvellement et révocation tracés, désactivée par défaut |
| EXP-06, GOV-03 | Hors réalisation (organisationnel) |
| ACC-03 (P2) | Réalisé pour la part attendue : API de lecture des réunions du jour (`/api/v1/integration/meetings`) ; gestion des visiteurs hors périmètre |
| DOC-09, PUB-12, SAL-15 (P2) | Non réalisés (SAL-15 : type « restauration » présent et inactif) |

## Conformité visuelle

| Élément de la maquette | Implémentation |
| --- | --- |
| Feuille de style complète, jetons de couleur, typographies | reprise à l’identique (`frontend/src/maquette.css`), polices auto-hébergées |
| Barre latérale, en-tête, fil d’Ariane, recherche, notifications, profil | `components/Layout.tsx` (menus filtrés par permissions) |
| 15 pages de la maquette | V04, V17, V11, V13, V15/V16, V20–V22, V07, V30/V25, protocole, V23, V05, V33, V34–V39, aperçus lecteur |
| 8 parcours modaux (contenu, validation, PDF, appairage, salle, visite, alerte, rapport) | assistants équivalents dans V18/V19, V11, V09, V27, accueil-protocole, V23, V33 |
| Vues de diffusion (paysage, portrait, panneau, alerte, repli, hors ligne) | lecteur réel `player/` (T01–T05) |
| Page « Cadrage & couverture » | remplacée par la documentation (`docs/conformite.md`, `docs/decisions.md`) : ce n’est pas une vue fonctionnelle du cahier |
