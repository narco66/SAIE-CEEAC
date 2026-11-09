# Rapport d'audit indépendant — SAIE-CEEAC

**Système d'affichage institutionnel électronique, de diffusion documentaire et de gestion des salles — Commission de la CEEAC**

| Élément | Valeur |
|---|---|
| Date de référence | 2026-11-06, 11:14 UTC (début) |
| Dépôt | `C:\laragon\www\SAIE-CEEAC` — distant `github.com/narco66/SAIE-CEEAC` |
| Branche / commit | `mise-en-conformite` / `98041685dd2505f2a9a55b7a28b7217838066893` |
| Travaux non commités | 13 fichiers (lot 3 en cours), conservés tels quels ; inclus dans l'exécution des tests (`travail/etat-depot.txt`) |
| Référence fonctionnelle | `docs/SAIE-CEEAC_Cahier_des_charges_v3.0.md`, conversion du `.docx` v3.0 — **l'original `.docx` est absent du poste** : équivalence non vérifiable |
| Référence visuelle | `docs/Maquette FIGMA SAIE-CEEAC/` (extraction) — **l'archive `Maquette complète SAIE-CEEAC-valid.zip` est absente** : intégrité de l'extraction non vérifiable |
| Environnement d'exécution | Windows 11 Pro, PHP 8.4.7, PostgreSQL 18.6 (base de test isolée `saie_ceeac_test`), Node.js 22.22.0 |
| Nature de l'avis | Avis technique. La réception institutionnelle relève de la Commission. |

**Limite d'indépendance.** Une partie du code audité (commits `27b3fa3`, `9804168` et lot 3 non commité) a été produite par l'assistant qui coordonne cet audit. Les constats proviennent de cinq auditeurs séparés travaillant sur le code, et les défauts bloquants ont été revérifiés par exécution. Une relecture humaine indépendante de ces incréments reste recommandée (voir `AUDIT_PROCESSUS_GENERATION.md` §0).

## 1. Constats les plus graves

Trois défauts **bloquants**, tous de sécurité, dont deux prouvés par exécution :

| ID | Gravité | Exigence | Localisation | Preuve | Impact | Reproduction | Correction | Critère de clôture |
|---|---|---|---|---|---|---|---|---|
| **C-01** | Bloquant | SEC-01, LIV-03 | `backend/app/Http/Controllers/Api/V1/AuthController.php:117-126` ; aucun middleware d'obligation | Sonde S-SEC1 : connexion `admin_tech` non enrôlé → 200 `mfa_enrollment_required: true` ; `GET /api/v1/users` → **200** sans second facteur | Un mot de passe volé d'administrateur ou de validateur suffit pour toute l'API ; mot de passe provisoire non forcé | `travail/sondes/AuditProbeSecurityTest.php::test_c01_…` | Middleware refusant le groupe humain tant que MFA / changement de mot de passe ne sont pas faits ; MFA pour les pouvoirs étendus | Test : 403 avant enrôlement, 200 après |
| **A-07** | Bloquant | DOC-04, DOC-07, DOC-08, HAB-01 | `backend/app/Http/Controllers/Api/V1/AssetController.php` (`visible()`, `file()`) ; `DocumentController.php:105-116` | Sonde S-SEC2 : média **restreint** servi (**HTTP 200**) à un contributeur d'une autre structure dont l'affectation est sans périmètre (`orWhereRaw('true')`) ; contributeur avec périmètre → 404 | Fuite de documents restreints et internes (audience, brouillon, dépublication, accusé de lecture contournés) ; un document reclassé « restreint » garde un média « interne » diffusable | `travail/sondes/AuditProbeSecurityTest.php::test_a07_…` | Fichier d'un document servi uniquement par `DocumentAccess` ; visibilité des médias restreints limitée au propriétaire et au périmètre explicite ; propagation de la classification | Tests : 404 hors périmètre et hors audience ; refus à la compilation après reclassement |
| **A-01 = C-02** | Bloquant | HAB-02, HAB-01, SEC-02 | `backend/app/Http/Controllers/Api/V1/UserController.php:58-72`, `:119-129` ; `backend/config/saie.php` (`admin_tech` détient `users.manage`) | Lecture de code : aucun contrôle de hiérarchie sur le changement d'e-mail ni sur la réinitialisation du second facteur d'autrui | Un administrateur technique prend le compte d'un administrateur fonctionnel (e-mail → le sien, reset MFA, « mot de passe oublié ») puis obtient `roles.manage` : séparation des fonctions contournée | `PATCH /users/{admin_fonc}` (email) puis `POST /users/{id}/reset-mfa` puis `auth/forgot-password` | Interdire ces actions sur un compte détenant des droits que l'acteur n'a pas ; notifier l'ancienne adresse ; double validation | Test : 403 tracé pour `admin_tech` sur un `admin_fonc` |

Défauts **majeurs** prioritaires (dédoublonnés ; registre complet en annexe) :

| ID | Exigence | Constat (preuve) | Correction |
|---|---|---|---|
| C-04 | SEC-05, API-07 | Le middleware d'idempotence stocke en clair, 2 jours, les réponses contenant le secret TOTP, les codes de secours et les jetons de comptes de service (`backend/app/Http/Middleware/Idempotent.php:51`, vérifié) | Exclure ou chiffrer ces corps ; purger |
| A-02 / A-05 | HAB-01, HAB-03 | Site et zone des affectations jamais appliqués ; pouvoirs « urgence »/« prestations » attribuables sans périmètre = tout le parc | Appliquer les périmètres ; périmètre obligatoire |
| A-03 | HAB-02 | Seul l'auteur initial est exclu : un co-rédacteur validateur approuve sa propre rédaction | Exclure tout rédacteur d'une révision |
| C-19, C-18 | SEC-01 | Pas de verrouillage sur échecs du second facteur ni d'anti-rejeu TOTP ; limiteur `api` non appliqué (166 routes sans limitation, `travail/routes-middlewares.txt`) ; sessions et `remember_token` conservés après réinitialisation | Verrouillage, anti-rejeu, `throttle:api`, révocation des sessions |
| B-06 / C-32 | TAB-02, SEC-02 | PDF03/PDF04 et visites sans contrôle d'objet (`backend/app/Services/ExportService.php:58-62`) | Contrôles d'objet / Policies |
| C-12 | SEC-04, TECH-02 | L'application se connecte en superutilisateur PostgreSQL (`infra/docker-compose.yml:42`, `POSTGRES_USER: saie`) et peut neutraliser le journal en ajout seul | Rôle applicatif sans privilège |
| B-01 | SAL-07 | Déplacement vers une salle à validation manuelle sans validation de son gestionnaire (`backend/app/Services/ReservationService.php:150,175`, vérifié) | Droit calculé sur la salle cible |
| B-02 | SAL-06, SAL-07 | « Cette occurrence et les suivantes » : PATCH successifs non atomiques (`frontend/src/pages/rooms/OccurrenceEditor.tsx:41-57`) | Transaction serveur unique |
| B-03, B-04 | SAL-03, SAL-07, NOT-01 | Ressources non libérées au refus/expiration/non-présentation ; modification relançant l'approbation sans tâche ni notification | Libération ; tâche + notification |
| C-03, C-05 | PLY-01, PLY-03, PUB-06 | Lecteur sans délai réseau (blocage définitif possible) ; alerte retardée par le téléchargement des médias ou perdue si un média devient 403 | Délais ; alertes indépendantes des médias |
| S-T12 | PLY-03, PLY-05 | `verify()` ne contrôle ni `valid_until` ni l'anti-retour (`player/src/engine.ts:235-242`) : un ancien manifeste signé est rejouable | Refus des versions expirées ou inférieures |
| C-10, C-11, C-23 | PLY-08, PLY-12 | `ignore_holidays` non transmis au lecteur ; pas de plage par groupe ; retour arrière cassé si seule version du canal retirée | Voir plan R4 |
| A-10 / C-22 | ACC-01, ACC-02 | Bienvenues non filtrées par cible, date ni occurrence : réunion annulée ou d'un autre jour affichée à l'accueil | Filtrage serveur |
| C-13 | CONT-01, CONT-02 | Aucune sauvegarde planifiée ni WAL dans la pile Docker ; RPO 1 h inatteignable ; restauration éprouvée seulement en mode hôte (S-T18) | Sauvegarde conteneur + R14 sur la cible |
| C-14 / B-12 | EXP-01, NOT-02 | La supervision lit la table `jobs` alors que la production utilise Redis : « 0 en attente » affiché comme une mesure | Mesure des files Redis |
| C-06 | PERF-01 | Médias servis par PHP-FPM dans le pool de l'administration (16 processus) | `X-Accel-Redirect` |
| A-08 / C-08 | DOC-01, SEC-03 | Conversions non isolées (worker unique avec réseau et secrets) | Worker de conversion isolé |
| D-04 / B-15, D-05, D-09 | UX-01, UX-03 | Focus invisible, modales sans piège de focus, brouillons perdus à la navigation | Plan R6 |
| E-02 | TECH-03, QUAL-01 | La CI n'a jamais tourné sur le code postérieur à `009835e` (`main` = README seul ; 7 commits non poussés) | Déclencher et exiger la CI |
| E-03 / E-06 | QUAL-01 | `docs/conformite.md` surévalue la conformité (HAB-01, HAB-04, DOC-04, SEC-05, SAL-10 annoncés « Réalisé ») | Requalification depuis la matrice |

## 2. Indicateurs de conformité

Dénominateur : **106 exigences** du catalogue (corps du cahier et index de l'annexe E concordants, aucun doublon d'identifiant), dont **2 non applicables** (GOV-03 conduite du changement, EXP-03), soit **104 exigences évaluées**, découpées en **253 sous-critères** (`MATRICE_CONFORMITE.csv`, dont 2 lignes transverses hors catalogue : DATA-00 et SAL-TZ). Statut d'une exigence : « Conforme » si tous ses sous-critères le sont, « Non conforme » si l'un d'eux l'est, sinon « Partiellement conforme ».

| Indicateur | Valeur | Dénominateur |
|---|---|---|
| Couverture d'implémentation (au moins partielle) | **98 / 104 (94 %)** | exigences évaluées |
| Couverture par tests (au moins un test existant cité) | **54 / 104 (52 %)** | exigences évaluées |
| Conformité vérifiée (tous sous-critères conformes) | **12 / 104 (12 %)** | exigences évaluées |
| Non conformes | **14 / 104** : HAB-01, HAB-02, REF-03, DOC-01, DOC-04, SAL-06, SAL-07, ACC-01, API-03, SEC-01, SEC-03, SEC-05, PERF-01, EXP-02 | |
| Non implémentées | **2** : SEC-07 (P0, rapport ASVS / test d'intrusion), EXP-06 (P1) | |
| Non vérifiables | **4 / 104 (4 %)** : INF-01, INF-02, QUAL-02, EXP-04 | |
| Vérifié sur matériel | **0** | aucun terminal représentatif disponible |

Par priorité contractuelle :

| Priorité | Total | Conforme | Partiel | Non conforme | Non implémenté | Non vérifiable | N/A |
|---|---|---|---|---|---|---|---|
| P0 | 86 | 6 | 61 | 13 | 1 | 4 | 1 |
| P1 | 16 | 2 | 11 | 1 | 1 | 0 | 1 |
| P2 | 4 | 4 | 0 | 0 | 0 | 0 | 0 |

Sous-critères (253) : Conforme 95 · Partiellement conforme 120 · Non conforme 17 · Non implémenté 9 · Non vérifiable 9 · Non applicable 3.

Lecture : le code couvre presque tout le périmètre, mais **la conformité vérifiée est faible** et les P2 sont toutes conformes alors que 13 P0 sont non conformes : l'ordre de réalisation a privilégié l'étendue sur la clôture du Socle (`AUDIT_PROCESSUS_GENERATION.md`, D6). Aucun pourcentage ne doit masquer les trois défauts bloquants ci-dessus.

Reste à réaliser : P0 — SEC-07 (non implémenté) et les 74 exigences P0 partielles ou non conformes ; P1 — EXP-06 et 12 exigences partielles ou non conformes ; P2 — néant. DATA-03 (entités prescrites dès le Socle, même si leur exploitation est P1) : **partiellement conforme** — toutes les entités ont une réalisation, mais trois sont remplacées par des équivalents (`locations.zone_id`, `personal_access_tokens`, colonnes de `campaigns`/`campaign_items`) sans décision tracée, et le dictionnaire de données ne décrit ni les colonnes ni des durées de conservation fondées sur le registre GOV-02.

## 3. Ce qui a été exécuté

Détail, commandes, codes de sortie et fichiers bruts : `PREUVES_ET_TESTS.md`.

- **PHPUnit** sur PostgreSQL 18.6 : **111 / 111** réussis, 565 assertions, 0 ignoré (code 0).
- **Lecteur** : `tsc` code 0 ; `npm test` **10 / 10**. **Frontend** : `tsc` code 0.
- **Pint** `--test` : **code 1** (7 fichiers du lot 3 non commité) — la CI échouerait sur l'arbre de travail.
- **Audits de dépendances** : `composer audit` et `npm audit` (frontend, lecteur) : 0 vulnérabilité connue.
- **Contrainte SAL-05** relevée en base : `EXCLUDE USING gist … tstzrange(blocking_start, blocking_end, '[)') … WHERE status IN (pending, confirmed, in_progress)`, `btree_gist 1.8`.
- **Sondes ciblées** (hors dépôt, `travail/sondes/`) : concurrence réelle à deux connexions (une seule réussite, 23P01) ; panne SMTP (opération confirmée) ; signature du manifeste (altérations refusées) ; second facteur (défaut confirmé) ; média restreint (défaut confirmé) ; restauration (RTO 2,5 s sur volume de démonstration, 27/27 fichiers).
- **Non exécutés** : Playwright (dernier résultat connu, 2026-11-05 : 1 échec non rapporté), charge R15, déploiement R18, test d'intrusion R27, essais matériels R26.
- **R01–R27** : 10 réussis (souvent limités à l'API), 3 réussis en simulation, 8 partiels, **2 en échec (R01, R16)**, 3 non exécutés, 1 non vérifiable sur matériel ; **aucun vérifié sur matériel**.

## 4. Inventaire et architecture effective

- Architecture conforme au socle prescrit : React + TypeScript + Vite (frontend), Laravel 13.40 (API `/api/v1`), PostgreSQL 18, client lecteur/panneau distinct (`player/`). Backend modulaire unique (pas de microservices), cohérent avec la recommandation du cahier.
- 184 routes API, 83 tables, 127 clés étrangères, 172 index, 2 contraintes d'exclusion, déclencheur de journal en ajout seul ; tâches `saie:tick` (chaque minute) et `queue:prune-failed`.
- Versions verrouillées (`composer.lock`, `package-lock.json`) ; images Docker non figées (`infra/docker-compose.yml`).
- Polices DM Sans et Manrope auto-hébergées ; palette de la maquette reprise (assombrie pour le contraste).
- Simulation identifiée : `saie:demo-fleet` (accusés marqués `simulated`, interdit en production) ; 6 752 accusés de démonstration en base locale à ne pas présenter comme mesures.

## 5. Jalon réellement atteint

- **J0 Cadrage / J1 Conception** : non évaluables comme jalons (aucun procès-verbal, registre GOV-02 absent) ; maquette non exhaustive (D-19) ; OpenAPI produit mais sans schémas de corps (C-38).
- **J2 MVP** : **non atteint**. Les fonctions du Socle et de l'Affichage sont largement présentes dans le code, mais le jalon exige le Socle sécurisé, la CI et une démonstration sur matériel pilote : trois défauts de sécurité bloquants ouverts, CI jamais exécutée sur le code courant, aucune preuve sur matériel.
- Le développement a parallèlement avancé sur des lots postérieurs (Salles, P1, P2, préparation de la mise en production). Ce travail n'est pas un défaut en soi, mais il a été mené avant la clôture du Socle, et une partie des défauts constatés (HAB, SEC) a été introduite dès `009835e` puis annoncée « Réalisé ».

## 6. Conclusions

| Décision | Avis | Justification |
|---|---|---|
| **Poursuite du développement** | **Favorable sous conditions** | Base saine et cohérente avec le cahier (94 % du périmètre présent, 111 tests verts, contraintes PostgreSQL réelles, signature des manifestes). Conditions : exécuter R0 (CI effective, requalification des annonces) puis R1 avant toute nouvelle fonction ; aucune annonce « Réalisé » sans test vert en CI. |
| **Passage au jalon J2 (MVP)** | **Défavorable** | Trois défauts bloquants de sécurité ouverts (C-01, A-01/C-02, A-07) ; CI jamais exécutée ; aucune démonstration sur matériel pilote ; R01 en échec. Réexamen possible après clôture de R0, R1 et R2. |
| **Aptitude au pilote (J3)** | **Défavorable** | En plus des conditions du J2 : sauvegarde conteneur et R14 sur la cible absents (C-13), lecteur sans délai réseau ni alerte prioritaire garantie (C-03, C-05), aucun essai réseau, énergie ou extinction sur terminal, R15 et R26 non exécutés. |
| **Aptitude à la production** | **Défavorable** | SEC-07 / R27 (ASVS N2 et test d'intrusion) non réalisés, condition explicite du cahier ; défauts bloquants et majeurs de sécurité ouverts ; supervision des files non fiable ; durées de conservation (GOV-02) non appliquées. |

Ces avis sont techniques ; ils ne préjugent pas de la réception institutionnelle par la Commission.

## 7. Livrables de l'audit

| Fichier | Contenu |
|---|---|
| `RAPPORT_AUDIT_SAIE_CEEAC.md` | Ce rapport ; registre complet des anomalies en annexe |
| `MATRICE_CONFORMITE.csv` | 253 sous-critères des 106 exigences (séparateur `;`, UTF-8 avec BOM) |
| `ECARTS_MAQUETTE.md` | V01–V42, T01–T05, PDF01–PDF10, P01–P09, fondations visuelles, accessibilité |
| `AUDIT_PROCESSUS_GENERATION.md` | Processus de production, traçabilité, CI, fiabilité des annonces |
| `PLAN_REMEDIATION.md` | Lots R0 à R8 ordonnés, dépendances, profils, estimations, critères de clôture |
| `PREUVES_ET_TESTS.md` | Commandes exécutées, sondes, R01–R27 |
| `travail/` | Fichiers bruts (sorties de tests, JUnit, routes, sondes, matrices par lot) |

## Annexe — Registre complet des anomalies

119 entrées : A (habilitations, référentiels, documents, publication, accueil) 23 · B (salles, notifications, tableaux de bord, ergonomie) 22 · C (lecteur, technique, sécurité, exploitation) 41 · D (interface, maquette, accessibilité) 19 · E (processus) 14. Gravité : 4 bloquantes (3 après dédoublonnage : A-01 ≡ C-02), 50 majeures, 65 mineures.

Doublons entre lots (même défaut, angles différents) : A-01 ≡ C-02 ; A-08 ≡ C-08 ; A-10 ≡ C-22 ; B-06 ⊂ C-32 ; B-12 ≡ C-14 ; B-15 ≡ D-04 ; E-06 ≡ SAL-10 révisé dans la matrice. Les pistes d'audits antérieurs (S1–S15, L1–L14) ont été confirmées, sauf S13, infirmée (`backend/app/Console/Commands/SaieTick.php:110-115` supprime bien les fichiers des exports expirés).

