# 15 — Rapport final de conformité du module « Gestion des écrans de diffusion »

Date : 8 novembre 2026. Référentiel : spécification fonctionnelle détaillée v1.0. Méthode : audit initial en lecture seule, mise en conformité par lots (12), puis **contrôle final indépendant** des mêmes exigences sur le code final (détail ligne par ligne : [02-MATRICE-CONFORMITE.md](02-MATRICE-CONFORMITE.md), section « Contrôle final »).

## 1. Chiffres

| Indicateur | Valeur |
|---|---|
| Exigences analysées | **266** |
| Conformes à l'audit initial | 75 (28,2 %) |
| Partiellement conformes à l'audit initial | 125 |
| Absentes à l'audit initial | 53 |
| Défectueuses à l'audit initial | 13 |
| **Conformes après intervention** | **187 (70,3 %)** |
| Partielles après intervention | 71 |
| Absentes après intervention | 3 |
| Défectueuses après intervention | 0 |
| Implémentées, validation matérielle en attente | 5 |
| Anomalies corrigées | 28 (et 1 faux positif de l'audit, 1 non corrigée : orientation) |
| Tests backend exécutés / réussis / échoués | 334 / 334 / 0 (2 879 assertions ; 89 tests ajoutés), après les lots 13 à 26 |
| Tests du lecteur | 22 / 22 / 0 |
| Parcours de bout en bout exécutés / réussis / échoués | 53 / 52 / 0 à la recette complète (1 ignoré : parcours kiosque historique réservé aux jours ouvrés) ; 54e parcours ajouté ensuite (réaffectation, réussi) |
| Couverture des 13 critères de recette du §20 | 11 couverts, 1 partiel (prévisualisation), 1 non couvert (conflits de programmation) au contrôle final ; les conflits sont désormais calculés par créneau et testés |

Le taux est **mesuré** : 187 exigences dont l'implémentation a été vue dans le code final, de préférence avec le test qui la couvre, sur 266. En comptant les 5 fonctions implémentées en attente de validation matérielle : 72,2 %.

| Périmètre | Initial (C / P / A / D) | Final (C / P / A / D / matériel) |
|---|---|---|
| §4 à §8 — tableau de bord, inventaire, localisation, configuration, groupes (113) | 26 / 55 / 26 / 6 | 84 / 26 / 1 / 0 / 2 |
| §9 à §11, §17.3, §18, §21, §23 — lecteurs, supervision, commandes, protocole, sécurité (82) | 22 / 40 / 18 / 2 | 49 / 28 / 2 / 0 / 3 |
| §12 à §15, §19 — affectations, historiques, rapports, habilitations, workflows, règles (71) | 27 / 30 / 9 / 5 | 54 / 17 / 0 / 0 / 0 |

## 2. Fonctionnalités développées

File de commandes à distance avec accusés et capacités ; états « en diffusion », « en veille », « diffusion arrêtée », « repli » ; historique des états ; seuils de supervision paramétrables ; alertes de lecture (lecteur figé, échecs répétés, version obsolète) ; supervision par niveau et par groupe ; fiche d'inventaire complète ; archivage ; historique des emplacements ; documents liés ; code QR d'écran ; import CSV ; profils de configuration avec confirmation d'application ; groupes dynamiques ; vue d'ensemble du parc et vue par niveau ; circuit d'incident (qualification, intervention, vérification, réouverture), escalade, responsable par défaut, statistiques ; cinq rapports (écrans hors ligne, commandes, fin de garantie, disponibilité, maintenance) ; durées de conservation ; rôles « Responsable du parc » et « Superviseur » ; périmètre par niveau et par groupe ; historique unifié de l'écran ; création de salle ; menu réorganisé.

## 3. État réel du module

Le module est **fonctionnel de bout en bout avec le lecteur web livré** : mise en service, diffusion prouvée par les accusés, perte et reprise de connexion, lecture hors ligne, commandes confirmées, diffusion collective, maintenance et sécurité sont démontrées par des parcours automatisés sur un lecteur réel. Aucune fonction n'est présentée comme validée sur matériel : la veille, le réveil et le redémarrage de l'appareil n'ont été essayés qu'avec l'agent local en simulation.

## 4. Écarts restants principaux

| Domaine | Écart | Nature |
|---|---|---|
| Enrôlement et sécurité des lecteurs | Demande de code ouverte, pas de preuve de possession de clé ni de signature des requêtes, jeton en clair dans le navigateur kiosque | Choix d'architecture web, à arbitrer (voir 12) |
| Télémétrie matérielle | Remontée par l'agent local (corrigé après le contrôle) ; température lue sur Linux seulement, firmware non mesuré, non validée sur le matériel cible | Dépendance matérielle |
| Pilotage de la dalle | Veille, réveil, redémarrage validés en simulation seulement ; luminosité et contraste sans pilote | Dépendance matérielle |
| Lecteurs | Réaffectation, suspension et vagues de déploiement faites après le contrôle ; pas de promotion automatique d'une version ni de mise à jour ciblée d'un lecteur précis | Évolution |
| Programmation | Conflits par jours et créneaux et niveaux de priorité métier faits après le contrôle ; pas d'affectation directe de liste de lecture sans campagne | Moteur de campagnes existant (hors périmètre de réécriture) |
| Inventaire | Import Excel, photo d'emplacement, code d'inventaire, unicité du n° de série, recherche élargie et filtre salle faits après le contrôle ; pas de catégorie paramétrable ni de description des interfaces physiques | Évolution |
| Localisation | Plans d'étage à déposer ; position des écrans saisissable (corrigé après le contrôle final) | Données à fournir |
| Temps réel | Rafraîchissement HTTP (5 à 30 s), pas de WebSocket ni MQTT | Décision d'infrastructure |

## 4 bis. Corrections faites après le contrôle final

Position sur le plan saisissable ; aperçu de programme, liste des groupes et suivi de diffusion bornés au périmètre ; rapport d'incidents complété et testé ; import Excel ; réaffectation d'un lecteur sans révocation (vérifiée avec un lecteur réel) ; conflits de programmation par jours et créneaux ; suspension réversible d'un lecteur (vérifiée avec un lecteur réel) ; déploiement progressif des versions par vagues avec suivi par lecteur ; télémétrie matérielle par l'agent local ; niveaux de priorité métier, le niveau urgent étant réservé au pouvoir « urgence » ; saisie de la photo d'emplacement. Puis (lots 17 à 19) : code d'inventaire, n° de série unique, adresse observée, dernière diffusion confirmée, recherche élargie, journaux techniques du lecteur, lecture en cours résolue, historique des versions, coupures et événements perdus enregistrés, alertes de connexion instable et d'écran sans programme, désactivation motivée, avant/après des campagnes et listes de lecture. Ces corrections ne modifient pas le taux mesuré par le contrôle final (70,3 %) : elles seront comptées au prochain contrôle. Tests : 305 PHPUnit réussis (2 655 assertions). Détail : matrice 02, dernière section.

## 5. Actions proposées

1. **Décisions (DSI)** : accès à `/referentials` ; serveur temps réel ; matériel de pilotage des dalles ; politique d'enrôlement (liste blanche, VLAN dédié).
2. **Validation matérielle** : un lecteur réel avec agent en mode `cec`, puis relance de `screens-commands.spec.ts`.
3. **Exploitation** : activer la purge nocturne en production (`retention.auto_purge`, paramètre de l'application).
4. **Évolutions restantes** : nouveau contrôle de conformité indépendant pour remesurer le taux ; promotion automatique des versions ; affectation directe d'une liste de lecture ; commandes signées et enrôlement renforcé (décision d'architecture) ; temps réel (décision d'infrastructure) ; validation de la télémétrie sur le lecteur Linux cible.

## 6. Livrables

`01` audit initial · `02` matrice de conformité (initiale, suivi, contrôle final) · `03` plan · `04` architecture · `05` modèle de données · `06` API · `07` SAIE Player · `08` règles métier · `09` tests backend · `10` tests frontend · `11` tests de bout en bout · `12` sécurité · `13` anomalies corrigées · `14` limitations et dépendances · `15` ce rapport · `captures/` (8 captures).

Sauvegarde préalable de la base : `backend/storage/app/private/backups/saie_ceeac-avant-module-ecrans-20261108-0738.dump` (vérifiée par restauration) ; avant les lots 17 à 19 : `saie_ceeac-avant-lots17-19-20261108-1831.dump` (restaurée dans une base temporaire, comptages identiques, base temporaire supprimée). Aucune donnée n'a été supprimée ; les écrans créés par les tests de recette ont été archivés et restent consultables.

## 7. Second contrôle de conformité et lots 20 à 26 (8 novembre 2026)

- **Taux mesuré par un second contrôle indépendant : 223 / 266 = 83,8 %** (70,3 % au contrôle précédent), avec 27 exigences partielles, 8 dépendant du matériel et 8 défauts. Les 8 défauts ont été corrigés et testés (lot 26) ; ils seront confirmés au prochain contrôle.
- Lots 20 à 25 : inventaire et mise en service, configuration et lecteurs, supervision et journal des échanges, programmation et droits, protections des lecteurs (livrées désactivées, décision DSI), fuseau par site, temps réel par flux SSE natif (Reverb 1.12 incompatible avec Guzzle 8 / psr7 3 ; décision de l'utilisateur).
- Bloc 4 (matériel) : non réalisable sur ce poste ; diagnostic `player/agent/diagnose.mjs` livré, aucune fonction matérielle déclarée validée. Applications natives Android, Windows, Smart TV non développées.
- Sauvegardes : `saie_ceeac-avant-lots20-25-20261108-1922.dump` (vérifiée) ; dépendances avant la tentative Reverb : `backups/deps-avant-reverb-*` (Composer a annulé de lui-même, rien n'a changé).
- Reste à faire : rejouer les parcours de bout en bout après le lot 26 (port 8000 libre), décisions DSI (activation des protections, rôle Administrateur SAIE, purge nocturne), validation matérielle.

