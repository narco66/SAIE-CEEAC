# 06 — Plan de mise à niveau

Les lots A à D ont été **réalisés** le 6 novembre 2026 (détail dans `10-…`). Le présent plan porte sur la suite. Les estimations, en jours-personne, sont indicatives. Une correction n'est close que si un test qui la couvre est vert en intégration continue sur PostgreSQL 18.

## 1. Lots réalisés (rappel)

| Lot | Contenu | Écarts | Preuve |
|---|---|---|---|
| A — Sécurité des comptes | Protection hiérarchique, rôle de base, réponses de connexion uniformes, plafond par IP, périmètre sur le parc | SEC-A1 à SEC-A5, SEC-A7 | `AdministrativeHierarchyTest`, `SecurityAndAccessTest` |
| B — Preuves de diffusion | Transmission durable, renouvellement sans réactivation, lecture déclarée réelle, synchronisation exacte, file sérialisée, alertes critiques hors plage, supervision sans faux « OK » | DIF-01 à DIF-07, ALR-01, SUP-01 | `DeliveryProofTest`, `live.test.ts` |
| C — Parc et maintenance | Fiche matérielle, états opérationnels, maintenance motivée, tableau de bord, incidents, dates et calendrier | PAR-01 à PAR-03, INC-01, INC-02, API-08, API-09, UX-10, UX-11 | `FleetAndIncidentTest` |
| D — Sûreté de la publication | Antivirus en échec sûr, bureautique refusé, zones bornées, contrat des modèles | SEC-AV1, MED-01, MOD-01, API-10 | `PublicationSafetyTest` |

## 2. Décisions attendues de la Commission (préalables)

| # | Question | Option recommandée | Écart |
|---|---|---|---|
| D1 | Une alerte critique doit-elle s'afficher hors de la plage de fonctionnement ? | **Oui** (réglage actuel), avec dalles pilotables pour l'allumage | ALR-01 |
| D2 | Circuits de validation à plusieurs niveaux : lesquels, pour quelles catégories ? | Deux niveaux pour les communiqués et les messages de la Présidence ; un niveau ailleurs | PUB-04 |
| D3 | Validation préalable des alertes selon leur criticité ? | Non pour « critique » (rapidité) ; oui pour « information » diffusée à tout le parc | PUB-05 |
| D4 | Rôles supplémentaires ? | Créer « technicien de maintenance » et « lecture seule » | ROL-01 |
| D5 | Durées de conservation (registre GOV-02) | Journal d'audit 5 ans, télémétrie 90 jours, captures 7 jours | ADM-01 |
| D6 | Verrouillage de compte | Verrouillage progressif (1, 5 puis 15 min) plutôt que fixe | SEC-A6 |
| D7 | Participants visibles, `exports.create`, permission des versions du lecteur | Voir `docs/limites.md`, « Priorité 1 » | — |

## 3. Lots à venir

### M1 — Conditions du pilote (P0 et P1 externes), 6 à 8 j

| Action | Écart | Dépendance | Risque | Critère de validation |
|---|---|---|---|---|
| Test d'intrusion indépendant et rapport ASVS 5.0 niveau 2 | SEC-07 | Prestataire | Délai de commande | Aucune vulnérabilité critique ou élevée ouverte |
| ClamAV réel sur le serveur de recette | SEC-AV2 | DSI | Faible | Dépôt du fichier EICAR rejeté par `clamd` ; fichier sain accepté |
| Terminaux représentatifs avec dalles pilotables | PLY-08, PLY-09 | Achat | Matériel | Extinction et rallumage conformes ; état « écran visible » mesuré |
| Exercice de restauration sur la pile Docker | CONT-01 | DSI | Moyen | RPO et RTO mesurés et consignés |

### M2 — Écarts P2 du logiciel, 12 à 15 j — **réalisé en grande partie le 6 novembre (soir)**

| Action | Écart | Est. | Critère |
|---|---|---|---|
| ~~`contents.valid_from` et `priority` appliqués au manifeste, ou retirés~~ **fait** | PUB-01 | 1 | `PublicationRulesTest` |
| ~~Avertissement d'orientation~~ **fait** | PUB-02 | 1 | `PublicationRulesTest` |
| ~~`usages()` complet~~ **fait** | PUB-03 | 1 | `PublicationRulesTest` |
| Corbeille des documents **faite** ; validation avant publication à décider | PUB-06 | 2 | `PublicationRulesTest` |
| ~~Jeton d'abonnement iCalendar révocable~~ **fait** | SAL-01 | 1,5 | `CalendarSubscriptionTest` |
| Sans objet : le jeton était déjà chiffré | SEC-T1 | — | — |
| ~~Reprise des téléchargements (`Range`)~~ **fait** | PLY-R1 | 2 | `chunks.test.ts`, `DeviceDeliveryTest` |
| ~~Relance à distance ; journal des appels non autorisés~~ **fait** (redémarrage système : externe) | PLY-R2 | 2 | `FleetAndIncidentTest` |
| ~~Détection d'anomalies~~ **fait** | SUP-02 | 1,5 | `FleetAndIncidentTest` |
| Sans objet : déjà paginés | UX-13 | — | — |
| Purge du journal selon D5 | ADM-01 | 1 | Test de purge |
| Rôles issus de D4 | ROL-01 | 1 | Test d'accès par rôle |

### M3 — Modules partiels, 15 à 20 j (après D2)

| Action | Écart | Est. |
|---|---|---|
| Circuits de validation à plusieurs niveaux par catégorie | PUB-04 | 5 |
| Listes de lecture réutilisables, glisser-déposer, archivage des campagnes | PUB-07 | 4 |
| Annuaire et recherche visiteurs, borne tactile, plans de niveau | ORI-01, PAR-04 | 6 (données institutionnelles requises) |
| Synchronisation entrante de l'agenda (Exchange ou Google) | SAL-02 | 4 (accès requis) |

### M4 — Améliorations P3, 6 à 8 j

Couvre les écarts suivants :

- UX-12 : titres de page, focus au changement de page, `aria-invalid` ;
- RAP-01 et RAP-02 : XLSX, à approuver car il ajoute une dépendance ; filtres par site et bâtiment ; cloisonnement ;
- PAR-S1 : paramètres dans l'interface ;
- PUB-08 : étiquettes, doublons, droits d'usage ;
- INC-03 : plan de maintenance préventive ;
- ADM-02 : journal des erreurs et métriques d'API.

## 4. Risques

| Risque | Effet | Parade |
|---|---|---|
| Aucun historique Git sur le poste de travail | Retour arrière impossible hors sauvegarde | Restaurer le dépôt distant ; archiver avant chaque lot |
| Version de PHP du poste (8.5) différente de la cible (8.4) | Écart de comportement non détecté | Faire tourner la CI sur PHP 8.4 |
| Absence de matériel représentatif | Comportement des dalles non prouvé | Lot M1 avant le pilote |
| Décisions D1 à D7 tardives | Blocage des lots M2 et M3 | Instance d'arbitrage mensuelle |

## 5. État au 6 novembre (nuit)

Les lots M2, M3 et M4 sont **réalisés** (voir `10-…` § 11), à l'exception de SAL-02 (synchronisation entrante, accès externe requis).

Les décisions D2 à D6 ont été appliquées avec l'option recommandée et restent à confirmer :

| Décision | Réglage |
|---|---|
| D2 | Référentiel des catégories, champ « niveaux de validation » |
| D3 | `SAIE_ALERT_FOUR_EYES_SEVERITIES` |
| D4 | Rôles `technicien`, `gestionnaire_contenus`, `consultation` |
| D5 | Paramètres « Conservation » |
| D6 | `saie.security.lockout_steps` |

D1 se règle par le paramètre « Alerte critique affichée hors plage ». D7 reste ouverte.

Seul le lot M1 (conditions du pilote : test d'intrusion, ClamAV, matériel, exercice de restauration) reste à engager.
