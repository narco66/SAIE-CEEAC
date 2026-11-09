# Plan de remédiation — audit SAIE-CEEAC du 2026-11-06

Ordre imposé par le risque : rien ne passe au lot suivant tant que le critère de clôture du lot précédent n'est pas prouvé (test qui échoue avant correctif et réussit après, exécuté en CI sur PostgreSQL 18). Les estimations sont **indicatives** (jours-personne, hors recette institutionnelle) et supposent l'équipe actuelle. Les identifiants renvoient au registre des anomalies (`RAPPORT_AUDIT_SAIE_CEEAC.md`, annexe).

Profils : **BE** développeur Laravel/PostgreSQL senior · **FE** développeur React/TypeScript · **PL** développeur lecteur/kiosque · **OPS** DevOps/exploitation · **SEC** sécurité applicative · **QA** recette · **MOA** Commission (arbitrages).

## R0 — Remettre les garde-fous en marche (préalable, 3 j)

| # | Action | Anomalies | Profil | Est. | Risque de régression | Test / critère de clôture |
|---|---|---|---|---|---|---|
| R0.1 | Pousser la branche ; déclencher `ci.yml` sur toutes les branches et rendre la CI obligatoire avant fusion | E-02 | OPS | 0,5 | Nul | Une exécution CI verte visible sur le commit de tête, toutes les étapes (Pint, PHPUnit PG18, migrations aller-retour, audits, OpenAPI, builds, e2e) |
| R0.2 | Corriger le formatage du lot 3 en cours puis terminer et tester ce lot, ou l'écarter | X5, lot 3 | BE | 0,5 | Faible | `pint --test` code 0 ; test dédié de la version de document servie pendant l'analyse |
| R0.3 | Rendre le jeu d'essai Playwright indépendant des exécutions précédentes (créneaux uniques, nettoyage) et rejouer la suite | E-04 | QA | 1 | Faible | Deux exécutions consécutives vertes ; `docs/tests/rapport.md` régénéré depuis le rapport JSON |
| R0.4 | Requalifier `docs/conformite.md` à partir de `MATRICE_CONFORMITE.csv` ; interdire le statut « Réalisé » sans test cité | E-03, E-06 | QA + MOA | 1 | Nul | Chaque ligne « Réalisé » cite un test présent dans `tests-liste` et vert en CI |

## R1 — Défauts bloquants de sécurité (6 à 8 j)

| # | Action | Anomalies | Profil | Est. | Risque de régression | Test / critère de clôture |
|---|---|---|---|---|---|---|
| R1.1 | Middleware sur le groupe humain : refus 403 `MFA_ENROLLMENT_REQUIRED` / `PASSWORD_CHANGE_REQUIRED` sauf `me`, `me/mfa/*`, `me/password`, `auth/logout` ; étendre l'obligation aux titulaires de pouvoirs étendus actifs | C-01, S6 | BE + SEC | 1,5 | Moyen (parcours de première connexion, e2e) | Sonde S-SEC1 transformée en test : `GET /users` → 403 avant enrôlement, 200 après ; test « pouvoir urgence actif sans MFA » → 403 |
| R1.2 | Interdire à un acteur de modifier l'e-mail, réinitialiser le second facteur ou désactiver un compte qui détient des droits qu'il n'a pas ; notifier l'ancienne adresse ; double validation pour les comptes d'administration | A-01 = C-02, S8 | BE + SEC | 2 | Moyen (V34, récupération de compte) | Test : `admin_tech` → `PATCH /users/{admin_fonc}` e-mail et `reset-mfa` → 403 tracé ; `admin_fonc` ne peut attribuer `admin_tech` ni `settings.technical` |
| R1.3 | Supprimer `orWhereRaw('true')` de la visibilité des médias ; un média restreint n'est visible que de son propriétaire et de son périmètre explicite ; servir le fichier d'un document **uniquement** par `DocumentAccess` (audience, statut, accusé) ; propager la classification du document à son média | A-07 | BE + SEC | 2 | Élevé (médiathèque, aperçus, documents, programmation) | Sonde S-SEC2 en test : 404 pour le contributeur hors périmètre ; test « document à audience restreinte : `/asset-versions/{id}/file` → 404 » ; test « document reclassé restreint : média restreint, refusé à la compilation » |
| R1.4 | Tests d'accès horizontaux et verticaux systématiques sur chaque route (matrice rôle × route générée depuis `route:list`) | R01, SEC-02 | QA + BE | 1,5 | Nul | Test paramétré couvrant les 184 routes : refus par défaut pour un rôle sans permission |

**Clôture R1 : R01 rejoué → Réussi ; aucun bloquant ouvert.**

## R2 — Sécurité majeure et séparation des fonctions (11 à 13 j)

| # | Action | Anomalies | Profil | Est. | Risque | Test / critère de clôture |
|---|---|---|---|---|---|---|
| R2.1 | Ne plus conserver le corps des réponses porteuses de secrets dans `idempotency_keys` (liste d'exclusion ou chiffrement) ; purger l'existant | C-04 | BE | 1 | Faible | Test : après `me/mfa/setup`, la ligne d'idempotence ne contient ni secret ni code |
| R2.2 | Compter les échecs du second facteur dans le verrouillage ; limiter `auth/mfa` par utilisateur en attente ; anti-rejeu TOTP (dernier pas accepté) ; appliquer `throttle:api` au groupe authentifié | C-19, S5, S15 | BE + SEC | 1,5 | Faible | Tests : 5 codes faux → verrouillage ; même code rejoué → refus ; 429 au-delà du seuil |
| R2.3 | Réinitialisation et changement de mot de passe : supprimer toutes les sessions et régénérer `remember_token` | C-18 | BE | 0,5 | Faible | Test : session parallèle refusée après réinitialisation |
| R2.4 | Appliquer site et zone des affectations (programmation, groupes, alertes, validation, liste d'écrans) ; périmètre obligatoire pour les pouvoirs « urgence » et « prestations » | A-02, A-05, S3, S9 | BE | 3 | Élevé (campagnes, alertes) | Tests : diffuseur limité à la zone Accueil → 403 sur un écran d'étage ; pouvoir sans périmètre → 422 |
| R2.5 | Exclure de la validation tout rédacteur d'une révision, pas seulement l'auteur initial | A-03 | BE | 0,5 | Faible | Test : co-rédacteur validateur → refus d'approbation |
| R2.6 | Contrôles d'objet des exports PDF03/PDF04 et des visites ; `GET /referentials` sans annuaire complet | B-06, C-32, S10, S11, A-06 | BE | 1,5 | Moyen | Tests horizontaux : gestionnaire de la salle A → 404 sur PDF03 d'une réunion de la salle B |
| R2.7 | Compte PostgreSQL applicatif sans superutilisateur ni droit `TRIGGER`/`TRUNCATE` sur `audit_logs` ; migrations sous un compte distinct | C-12 | OPS + BE | 1 | Moyen (déploiement) | `select rolsuper from pg_roles where rolname = current_user` → false ; tentative `DELETE FROM audit_logs` → refus |
| R2.8 | Introduire des Policies Laravel pour Document, Asset, Reservation, Content, Campaign, Visit, Export et y déplacer les règles d'objet existantes | S14, SEC-02 | BE | 3 | Moyen | Suite R1.4 verte ; chaque contrôleur appelle `authorize()` |

## R3 — Salles et accueil (5 à 6 j)

| # | Action | Anomalies | Profil | Est. | Risque | Test / critère de clôture |
|---|---|---|---|---|---|---|
| R3.1 | Calculer le droit de validation sur la salle **cible** d'un déplacement | B-01 | BE | 0,5 | Faible | Test : gestionnaire de A déplace vers B manuelle → occurrence `pending` et tâche chez le gestionnaire de B |
| R3.2 | Modification « cette occurrence et les suivantes » en une transaction serveur unique | B-02 | BE + FE | 2 | Moyen | Test : conflit sur la 3e occurrence → aucune occurrence modifiée |
| R3.3 | Libérer ressources mobiles et prestations au refus, à l'expiration et à la non-présentation | B-03 | BE | 0,5 | Faible | Test : après refus, la ressource se réserve sur le même créneau |
| R3.4 | Créer une tâche de validation et notifier quand une modification relance l'approbation ; clé de dédoublonnage par décision | B-04 | BE | 1 | Faible | Test : modification → tâche ouverte, notification ; décision suivante notifiée |
| R3.5 | Saisie du code de présence (fiche, panneau authentifié) | SAL-10, E-06 | FE | 0,5 | Faible | e2e : saisie du code → présence confirmée |
| R3.6 | Filtrer les bienvenues par cible, période et occurrence confirmée non confidentielle | A-10, C-22 | BE | 1 | Faible | Test : bienvenue d'une réunion annulée ou d'un autre jour absente du manifeste d'orientation |

## R4 — Lecteur et panneau (9 à 11 j)

| # | Action | Anomalies | Profil | Est. | Risque | Test / critère de clôture |
|---|---|---|---|---|---|---|
| R4.1 | Délai maximal sur chaque appel réseau ; synchronisation indépendante du heartbeat ; chien de garde réseau | C-03 | PL | 1 | Moyen | Test lecteur : serveur muet → heartbeat suivant émis dans le délai |
| R4.2 | Activer alertes, agenda et données de salle sans attendre les médias ; autoriser au téléchargement les médias des deux derniers manifestes | C-05, L2 | PL + BE | 2 | Élevé (bascule atomique PLY-03) | Test : alerte visible < 60 s pendant le téléchargement d'un média de 200 Mo ; aucune activation partielle du programme |
| R4.3 | Refuser un manifeste expiré (`valid_until`) ou de version inférieure à l'active | S-T12, L8 | PL | 0,5 | Faible | Test lecteur : manifeste ancien bien signé → rejeté |
| R4.4 | Transmettre `ignore_holidays` ; plage par groupe (écran > groupe > défaut) | C-10, C-11 | BE | 1,5 | Faible | Tests serveur et lecteur sur un jour férié avec dérogation |
| R4.5 | Retour à la version de base quand le canal n'a plus de cible ; intégrité de tout le build (SRI ou liste signée) | C-23, L7 | PL + OPS | 1,5 | Moyen | Test : retrait de la seule version du canal → lecteur sur `/player/` |
| R4.6 | Rotation du jeton lecteur et gestion de deux clés de signature | C-09 | BE + PL | 2 | Moyen | Test : rotation sans réappairage |
| R4.7 | Limites hors ligne de l'agenda, du panneau et de l'orientation ; ErrorBoundary ; CORS de l'agent de dalle limité ; retrait du bouton public de réappairage | L9, L14 | PL | 1 | Faible | Tests lecteur dédiés |

## R5 — Exploitation et continuité (7 à 9 j)

| # | Action | Anomalies | Profil | Est. | Risque | Test / critère de clôture |
|---|---|---|---|---|---|---|
| R5.1 | Sauvegarde planifiée base + WAL + volume `storage` en mode conteneur, copie chiffrée hors site ; procédure adaptée | C-13 | OPS | 2 | Faible | R14 rejoué sur la pile Docker : RPO ≤ 1 h et RTO mesurés |
| R5.2 | Supervision des files Redis ; sondes certificat et fraîcheur des sauvegardes ; notifications d'incident | C-14, B-12, B-05 | BE + OPS | 1,5 | Faible | Test : file bloquée → indicateur non nul et incident notifié |
| R5.3 | Servir les médias par Nginx (`X-Accel-Redirect`) après autorisation PHP | C-06 | OPS + BE | 1 | Moyen | R15 rejoué : p95 de l'administration stable pendant 16 téléchargements simultanés |
| R5.4 | Figer les images par version et empreinte ; politique de mise à jour du navigateur kiosque | C-15, L13 | OPS | 1 | Faible | `docker compose config` sans tag flottant ; procédure validée par la DSI |
| R5.5 | Découper `saie:tick` en tâches isolées ; purger les manifestes ; purge de conservation paramétrable une fois GOV-02 arbitré (rétablir le réglage retiré en `9804168` ou consigner la décision) | L13, S12, E-09 | BE + MOA | 1,5 | Moyen | Test : l'échec d'une étape n'empêche pas les suivantes ; décision GOV-02 datée |
| R5.6 | Isoler le worker de conversion (sans réseau, limites mémoire/CPU, convertisseurs présents dans l'image) | A-08, C-08 | OPS | 1 | Moyen | Test d'image : conversion OK, `curl` sortant refusé depuis le conteneur |

## R6 — Interface et accessibilité (6 à 7 j)

| # | Action | Anomalies | Profil | Est. | Risque | Test / critère de clôture |
|---|---|---|---|---|---|---|
| R6.1 | Focus visible conforme (contraste ≥ 3:1), y compris barres de recherche | D-04, B-15 | FE | 0,5 | Faible | Test e2e clavier + contrôle de contraste automatisé |
| R6.2 | Piège et retour du focus dans les 4 implémentations de modale ; Échap sur les menus | D-05 | FE | 1 | Faible | Test e2e clavier par modale |
| R6.3 | Avertissement de sortie à la navigation interne (`useBlocker`) et à l'expiration de session | D-09 | FE | 1 | Moyen | e2e : quitter V18 avec brouillon → confirmation |
| R6.4 | Téléversement avec progression et reprise | D-13 | FE + BE | 2 | Moyen | e2e : fichier de 300 Mo interrompu puis repris |
| R6.5 | Tailles minimales (12 px interface, 10 pt PDF) ; PDF01 confidentiel classé correctement | D-02, D-07, D-08 | FE + BE | 1 | Faible | Contrôle CSS automatisé ; test PDF01 confidentiel |
| R6.6 | Étendre le test axe aux 42 vues ; essai lecteur d'écran sur les parcours clés | R16 | QA | 1 | Nul | R16 rejoué → Réussi |

## R7 — Contrat, qualité et livrables (6 à 8 j)

| # | Action | Anomalies | Profil | Est. | Risque | Test / critère de clôture |
|---|---|---|---|---|---|---|
| R7.1 | Schémas OpenAPI des requêtes et réponses (générés depuis les règles de validation et les ressources) ; test de contrat en CI | C-38, E-07 | BE | 2 | Faible | Test de contrat vert sur les 184 routes |
| R7.2 | Clé d'idempotence stable par intention côté frontend | C-07 | FE | 0,5 | Faible | e2e : double clic → une seule demande |
| R7.3 | Concurrence optimiste généralisée (versions sur les formulaires d'édition) | C-30 | BE + FE | 1,5 | Moyen | Test : modification depuis une version obsolète → 409 |
| R7.4 | Jeu de cas commun au résolveur serveur et au résolveur lecteur | E-08 | BE + PL | 1 | Faible | Mêmes vecteurs JSON exécutés par PHPUnit et `node --test` |
| R7.5 | Livrables LIV-01 à LIV-03 manquants ; traçabilité exigence → test (identifiant dans le nom des tests) | C-40, E-01 | QA + MOA | 2 | Nul | Matrice générée automatiquement depuis les tests |

## R8 — Conditions du pilote et de la production (hors développement)

| # | Action | Profil | Critère |
|---|---|---|---|
| R8.1 | Test d'intrusion indépendant et rapport ASVS 5.0 niveau 2 | SEC externe | R27 : aucune vulnérabilité critique ou élevée ouverte |
| R8.2 | Charge de référence 100 lecteurs simulés + 10 réels | QA + OPS | R15 : rapport p95 sans erreur bloquante |
| R8.3 | Essais sur terminaux représentatifs (coupure, redémarrage, extinction CEC, dalle éteinte vs écran noir) | PL + QA | R05, R06, R25, R26 vérifiés sur matériel |
| R8.4 | Arbitrages en attente : charte graphique, GOV-02 (durées de conservation), décisions de `docs/limites.md` (participants visibles, `exports.create`, permission des versions du lecteur) | MOA | Décisions datées dans `docs/decisions.md` |

**Charge indicative totale R0 à R7 : 53 à 64 jours-personne** (somme des actions : 58,5 j), dont R0 + R1 (9 à 11 j) à engager immédiatement.
