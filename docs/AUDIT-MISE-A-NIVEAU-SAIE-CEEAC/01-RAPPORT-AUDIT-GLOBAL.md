# 01 — Rapport d'audit global du SAIE-CEEAC

| Élément | Valeur |
|---|---|
| Date | 6 novembre 2026 |
| Objet | Système d'affichage institutionnel électronique, de diffusion documentaire et de gestion des salles (SAIE-CEEAC) |
| Dépôt audité | `C:\laragon\www\SAIE-CEEAC` (copie de travail **sans historique Git** sur ce poste) |
| Références | Prompt maître d'audit (22 modules) ; cahier des charges v3.0 ; audit antérieur `docs/audit/2026-11-06/` et lots R1 à R7 |
| Environnement d'exécution | Windows 11 Pro ; PHP 8.5.12 (le projet exige `^8.4`) ; PostgreSQL 18 (port 5433, base de test `saie_ceeac_test`) ; Node.js 22 |
| Sauvegarde préalable | Archive des sources hors `vendor`, `node_modules` et `storage` faite avant toute modification (dans le dossier temporaire de session) |
| Nature de l'avis | Avis technique fondé sur des preuves d'exécution. **Ce document n'est ni une certification ni une attestation de conformité ISO.** |

## 1. Présentation de l'application

Le SAIE-CEEAC est un monolithe modulaire. Il comprend :

- `backend/` : API Laravel 13 versionnée `/api/v1`, 185 opérations ;
- `frontend/` : interface d'administration React 19 + TypeScript (vues V01 à V42) ;
- `player/` : lecteur kiosque React exécuté dans Chromium, qui assure l'affichage des écrans (T01, T02), le panneau de salle (T03), l'appairage (T04) et l'orientation (T05) ;
- `infra/` : Docker Compose, Nginx, sauvegarde.

PostgreSQL 18 est la seule source de vérité. Le lecteur reçoit un **manifeste signé Ed25519**, télécharge et vérifie les médias (SHA-256), bascule de façon atomique, poursuit la diffusion hors ligne pendant une durée d'autorisation (24 h en zone publique, 1 h en zone interne) et remonte ses accusés de réception.

Le système n'est pas une simple diffusion d'images. Il couvre déjà :

- la publication validée, les campagnes, les alertes et les modèles multi-zones ;
- la médiathèque contrôlée, avec quarantaine et antivirus ;
- les documents, les salles et les réservations ;
- l'accueil et le protocole ;
- la supervision, les rapports PDF et CSV ;
- l'audit en ajout seul, la double authentification TOTP et les habilitations datées avec périmètre.

## 2. Périmètre et méthode

1. **Inventaire** du dépôt : 69 modèles Eloquent, 25 migrations (22 avant la mise à niveau), 185 opérations d’API, 52 pages React, 9 fichiers du lecteur, 4 politiques Laravel, 19 services.
2. **Mesure de départ** : PHPUnit sur PostgreSQL 18 réel, soit 177 tests, tous verts une fois l'extension `zip` activée ; lecteur : 15 tests verts.
3. **Audit par module**, en quatre volets parallèles :
   - diffusion, lecteurs et supervision ;
   - publication ;
   - salles, sécurité et administration ;
   - interface.
   Chaque écart a été rapporté au code par fichier et ligne. **Les défauts classés P0 ont été revérifiés par lecture directe** avant correction.
4. **Corrections par lots** (§ 10 et `10-RAPPORT-FINAL-MISE-A-NIVEAU.md`) : chaque correction est accompagnée d'un test qui l'exerce. Après chaque lot : compilation, migrations, tests ciblés puis suite complète.
5. **Livrables** 01 à 10 et preuves brutes dans `preuves/`.

Hors périmètre exécutable sur ce poste :

- matériel d'affichage réel ;
- ClamAV réel ;
- SMTP institutionnel ;
- Docker ;
- test d'intrusion ;
- mesures de charge sur l'infrastructure cible.

## 3. Architecture existante

L'architecture est conforme au socle prescrit et saine. Voir `05-ARCHITECTURE-TECHNIQUE-CIBLE.md` pour la cible, qui reste la même.

| Couche | Constat |
|---|---|
| Séparation CMS / serveur / lecteur / écran | Réelle. L'administration et le lecteur sont deux clients distincts de la même API ; le lecteur ne contacte jamais d'URL externe ; la dalle est pilotée par un agent local distinct (`player/agent/panel-agent.mjs`, essayé en simulation seulement). |
| Données | 84 tables (83 avant la mise à niveau + `campaign_transmissions`), clés étrangères, contrainte d'exclusion `gist` contre la double réservation, déclencheurs d'ajout seul sur `audit_logs` et `content_versions`, suppression des preuves de lecteur interdite (`RESTRICT`). |
| Sécurité applicative | Refus par défaut (`authorizePermission`), politiques Laravel sur les objets sensibles, Sanctum en mode SPA (cookies HttpOnly, CSRF), jetons individuels de portée `device` pour les lecteurs, idempotence, concurrence optimiste (`If-Match`). |
| Traitements asynchrones | Files `default`, `notifications`, `media`, `exports` ; planificateur `saie:tick` découpé en étapes isolées. |
| Contrat | OpenAPI 3.1 généré depuis les règles de validation ; **chaque réponse de la suite de tests est confrontée au contrat**. |

## 4. État des modules (22 modules du prompt)

Légende : ✅ opérationnel et testé · 🟡 partiel · ⛔ absent · 🔧 complété par la présente mise à niveau (trois passages successifs, le 6 novembre).

| # | Module | État | Commentaire |
|---|---|---|---|
| 1 | Tableau de bord | ✅🔧 | Chiffres issus de la base. Ajouts : états opérationnels du parc, répartition par bâtiment, contenus en attente, salles équipées, plage paramétrée (PAR-03). Pas de filtres. |
| 2 | Sites, bâtiments, espaces | ✅🔧 | Hiérarchie site → bâtiment → niveau → emplacement → écran ; types d'espace et plans de niveau ajoutés (PAR-04). La zone de diffusion reste transversale (classification). |
| 3 | Parc d'écrans | ✅🔧 | Fiche matérielle et six états opérationnels ajoutés (PAR-01, PAR-02). Historique de maintenance couvert par les incidents liés. |
| 4 | Lecteurs | ✅🔧 | Enrôlement par code temporaire, jeton individuel à rotation, versions signées (SRI), cache vérifié. Relance à distance, détection des terminaux non autorisés et téléchargements reprenables ajoutés au second passage (PLY-R1, PLY-R2). Périmètre appliqué (SEC-A7). |
| 5 | Contenus et médias | ✅🔧 | Quarantaine, signature MIME, SHA-256, antivirus, miniatures, versions. Antivirus en échec sûr (SEC-AV1) ; bureautique refusé (MED-01). Doublons signalés (second passage) ; pas d’étiquettes ni de droits d’usage (PUB-08). |
| 6 | Modèles | ✅🔧 | Éditeur visuel multi-zones, aperçu, portrait et paysage. Zones bornées au cadre (MOD-01). |
| 7 | Listes de lecture | ✅🔧 | Listes réutilisables copiées dans la campagne, glisser-déposer, archivage des campagnes (PUB-07). |
| 8 | Campagnes et programmation | ✅ | Dates, jours, créneaux, priorités, conflits signalés, mode événement, suspension et reprise, vue hebdomadaire. Règle de priorité stricte non paramétrable. |
| 9 | Alertes | ✅🔧 | Plein écran, expiration, prolongation et révocation tracées, accusés techniques, retour au programme. Les alertes critiques s'affichent désormais hors plage (ALR-01, décision à confirmer). Pas de validation selon la criticité (PUB-05). |
| 10 | Salles de réunion | ✅🔧 | Réservation, récurrence, validation, panneau de porte, iCalendar. Abonnement iCalendar personnel par jeton ajouté (SAL-01) ; pas de synchronisation entrante (SAL-02). |
| 11 | Orientation des visiteurs | ✅🔧 | Vue d'orientation, annuaire public (bureau, téléphone d'accueil), borne tactile avec filtres et plans (ORI-01). Données d'annuaire à saisir par la Commission. |
| 12 | Communication institutionnelle | 🟡 | Catégories (communiqué, annonce, actualité…) sur le contenu générique, avec validation et programmation. Pas d'objet « événement » dédié. |
| 13 | Documents | ✅🔧 | Versions, accès par audience, plein texte, accusés de lecture, diffusion par courriel et QR. Corbeille contrôlée ajoutée ; pas de validation avant publication (PUB-06, décision). |
| 14 | Circuit de validation | ✅🔧 | Validation par langue, distincte de l'auteur, échéances, escalade, suppléant ; 1 à 3 niveaux par catégorie (PUB-04, décision D2 à valider). |
| 15 | Utilisateurs, rôles, habilitations | ✅🔧 | RBAC interne robuste (droits datés, périmètres, pouvoirs étendus, MFA imposée). Contrôle hiérarchique complété (SEC-A1 à SEC-A5). 8 rôles au lieu des 11 indiqués (ROL-01). |
| 16 | Supervision | ✅🔧 | Distingue lecteur joignable, reçu, activé, lecture déclarée et dalle non mesurée. Voyants « OK » sans mesure supprimés (SUP-01). |
| 17 | Preuves de diffusion | ✅🔧 | Preuve désormais durable, lecture déclarée réelle avec durée, statistiques non gonflées (DIF-01 à DIF-05). |
| 18 | Maintenance et incidents | ✅🔧 | Catégorie, nature, diagnostic, affectation contrôlée et notifiée (INC-01, INC-02). Pas de plan de maintenance préventive (INC-03). |
| 19 | Notifications | ✅ | Interne, courriel avec reprises, push, préférences, traçabilité. Pas de notification des alertes techniques de stockage, de files ou de sauvegarde. |
| 20 | Rapports | ✅ | PDF01 à PDF10, CSV neutralisé, filtres temporels. Pas d'export Excel ni de filtre site/bâtiment (RAP-01). |
| 21 | Paramétrage | ✅🔧 | 18 paramètres effectifs dans l'interface, dont seuils d'anomalie, alerte hors plage, conservation et double validation des documents (PAR-S1). |
| 22 | Administration technique et audit | ✅🔧 | Journal en ajout seul, supervision, sauvegarde base + WAL, `saie:preflight`, conservation avec archivage du journal (ADM-01), métriques de l'API et journal des erreurs (ADM-02). |

## 5. État des fonctionnalités : vérifications de bout en bout

Chaîne vérifiée par les tests sur PostgreSQL réel :

1. contenu ;
2. validation distincte ;
3. instantané immuable ;
4. campagne ;
5. manifeste signé ;
6. téléchargement vérifié ;
7. accusés `manifest_received`, `activated`, `played` ;
8. suivi par niveau de preuve ;
9. **persistance de la preuve après purge des manifestes** (nouveau).

**Ce qui n'est pas prouvé** :

- l'allumage réel d'une dalle (aucun canal matériel disponible : l'état est affiché « Non mesuré ») ;
- la lecture vue par une personne (jamais affirmée) ;
- le comportement sur un terminal kiosque physique.

## 6. État de la sécurité

Points forts constatés :

- refus par défaut journalisé ;
- MFA imposée côté serveur pour les rôles sensibles ;
- anti-rejeu TOTP ;
- verrouillage ;
- sessions fermées au changement de mot de passe ;
- secrets jamais conservés par l'idempotence ;
- compte PostgreSQL applicatif à moindre privilège ;
- en-têtes de sécurité et HSTS via Nginx ;
- aucun SQL interpolé (les `whereRaw` sont paramétrés) ;
- aucun `dangerouslySetInnerHTML` ;
- CORS en liste blanche ;
- manifestes signés et rotation de clé.

Défauts corrigés par la présente mise à niveau :

- SEC-A1 et SEC-A2 : neutralisation entre administrateurs ;
- SEC-A3 : élévation par le rôle de base ;
- SEC-A4 et SEC-A5 : énumération et pulvérisation ;
- SEC-A7 : périmètre sur le parc ;
- SEC-AV1 : antivirus en échec sûr.

Restent :

- SEC-07 : test d'intrusion et rapport ASVS niveau 2, **condition de production** ;
- SEC-AV2 : ClamAV réel ;
- SEC-A6 : déni de service par verrouillage, atténué.

Le constat SEC-T1 (jeton de lecteur en clair) s'est révélé inexact : le jeton est chiffré en base.

## 7. État de l'ergonomie

Points forts :

- maquette Figma reprise ;
- palette CEEAC et logo officiel ;
- focus visible ;
- modales accessibles ;
- avertissement avant de perdre un brouillon ;
- confirmations motivées sur les actions destructives ;
- aucun bouton factice ;
- aucun lien mort (27 entrées de menu vérifiées) ;
- aucun chiffre écrit en dur dans les écrans relevés, hors le tableau de bord corrigé ici.

Corrigé :

- jargon et codes d'exigence visibles ;
- intitulé de menu « Programmation » ;
- gestion d'erreur de « Tout marquer lu » ;
- libellés de notification manquants.

Corrigé au second passage : titre propre à chaque page et focus déplacé au changement de page (WCAG 2.4.2 et 2.4.3), `aria-invalid` sur les champs en erreur. Les sélecteurs de médias étaient déjà paginés (constat initial inexact).

Reste à faire : un essai avec un lecteur d'écran.

## 8. État des performances

- Requêtes paginées et triées sur liste blanche.
- Index et contraintes en place.
- Médias servis par Nginx (`X-Accel-Redirect`) en production.
- Le tableau de bord précharge désormais les incidents ouverts : une requête au lieu d'une par écran.

Points restant à surveiller :

- l'index de la médiathèque lance une requête JSONB par média de la page ;
- la liste des écrans calcule l'état opérationnel avec une requête par écran doté d'un lecteur. La liste est paginée (20 par défaut), mais il faudra précharger si le parc dépasse quelques centaines d'écrans.

Mesures de charge : voir `docs/tests/rapport.md`. Elles sont à refaire sur l'infrastructure cible.

## 9. Qualité du code

- Pint conforme (`preuves/pint-test.txt`).
- Typage TypeScript strict sans erreur (frontend et lecteur).
- 205 tests PHPUnit et 20 tests du lecteur (après le second passage).
- Test de contrat OpenAPI sur toutes les réponses.

Conventions respectées : contrôleurs par domaine, services métier, PHPDoc, migrations additives, aucune nouvelle dépendance.

Observation sur la méthode : les modifications faites par script sous Windows ont temporairement converti 16 fichiers en fins de ligne CRLF. Ils ont été remis en LF, conformément à `.editorconfig` et `.gitattributes`.

## 10. Niveau de maturité

| Domaine | Niveau (1 à 5) | Justification |
|---|---|---|
| Socle et architecture | 4 | Conforme, testé, contrat vérifié |
| Diffusion et lecteur | 4 | Chaîne signée, hors ligne, preuves désormais fiables ; non éprouvé sur matériel |
| Sécurité | 3 | Défauts connus corrigés ; test d'intrusion indépendant absent |
| Fonctions métier | 4 | 22 modules couverts ; module 12 sans objet « événement » dédié ; options D2 à D6 à valider |
| Exploitation et continuité | 3 | Sauvegarde, WAL et restauration outillées ; exercice sur la pile cible à faire |
| Interface | 4 | Homogène et accessible pour l'essentiel ; points WCAG ouverts |

## 11. Conclusions et recommandations

| Décision | Avis technique |
|---|---|
| Poursuite du développement | **Favorable.** Base saine. Tous les P0 traitables par le code sont corrigés et prouvés. |
| Démonstration et recette fonctionnelle | **Favorable**, sur environnement de recette. |
| Pilote sur matériel (J3) | **Favorable sous conditions** : terminaux représentatifs, ClamAV réel, exercice de restauration sur la pile Docker, décision ALR-01. |
| Mise en production | **Défavorable en l'état** : test d'intrusion et rapport ASVS niveau 2 (SEC-07) non réalisés ; durées de conservation (GOV-02, ADM-01) non arbitrées ; aucun essai sur matériel. |

Recommandations prioritaires :

1. Commander le test d'intrusion.
2. Arbitrer les décisions listées dans `06-PLAN-MISE-A-NIVEAU.md` § 2 : ALR-01, PUB-04, PUB-05, ROL-01, ADM-01, SEC-A6.
3. Équiper le pilote de dalles pilotables (HDMI-CEC ou RS-232) pour mesurer l'état « écran visible ».
4. Traiter les P2 ouverts de la matrice (lot M2 du plan).

## 12. Mise à jour après le troisième passage (6 novembre, nuit)

- **54 écarts corrigés sur 59**, dont 49 couverts par un test dédié ; 2 constats sans objet ; **3 écarts externes** : test d'intrusion, ClamAV réel, synchronisation entrante de l'agenda.
- **Tests** : 221 tests backend, 22 tests du lecteur, tous verts.
- **Décisions D2 à D6** : appliquées avec l'option recommandée, paramétrables et réversibles ; à confirmer par la Commission.
- **Avis de production inchangé** : il reste conditionné aux prérequis externes du livrable 10, § 9.
