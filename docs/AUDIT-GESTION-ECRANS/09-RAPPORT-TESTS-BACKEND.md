# 09 — Rapport des tests backend (PHPUnit)

Exécution du 8 novembre 2026 : `php artisan test` sur la base de test `saie_ceeac_test`.

| | Avant le module | Après |
|---|---|---|
| Tests | 245 | **334** |
| Assertions | 2 078 | **2 879** |
| Résultat | 245 réussis | **334 réussis, 0 échec** |
| Durée | — | 85 s |

Chaque réponse HTTP produite par les tests est validée contre le contrat `docs/api/openapi.json` ; les exemples du contrat sont réenregistrés à partir de réponses réelles (`SAIE_CONTRACT_RECORD=1`).

## Tests ajoutés pour le module (89)

| Fichier | Tests | Couverture |
|---|---|---|
| `ScreenModuleIntegrityTest` | 8 | Périmètre (écran désactivé, déplacement, lecture), groupe vide d'une autre structure, cible inexistante, incidents bornés et résultat exigé, état « en anomalie », programme vide d'un écran désactivé, archivage |
| `RemoteCommandsTest` | 7 | Cycle envoyée → reçue → exécutée avec compte rendu, refus d'une commande non prise en charge, lecteur sans capacités, arrêt de diffusion confirmé, expiration et annulation, permission, périmètre et lecteur, capture |
| `SupervisionStatesTest` | 5 | États (en diffusion, veille, arrêt, repli, hors ligne), historique des états, alertes de lecture, délais paramétrables, caractéristiques et supervision par étage et groupe |
| `ScreenInventoryTest` | 5 | Fiche complète et déplacements historisés, QR réservé aux comptes habilités, documents liés, import CSV (simulation, atomicité, périmètre), inventaire borné et par niveau |
| `ScreenProfilesTest` | 4 | Paramètres bornés, priorité de l'écran sur le groupe et manifeste, confirmation d'application et paramètres non pris en charge, profil utilisé non désactivable |
| `DynamicGroupsTest` | 4 | Critères et recalcul (état de connexion, archivage), membres non modifiables, réservé aux habilitations sans restriction, statistiques |
| `FleetOverviewTest` | 2 | Indicateurs réels et filtre par état, périmètre |
| `IncidentWorkflowTest` | 3 | Circuit complet avec vérification, responsable par défaut et escalade, statistiques |
| `FleetReportsTest` | 3 | Cinq rapports sur données réelles, accès de l'auditeur, conservation |
| `FleetRolesAndHistoryTest` | 3 | Périmètre par niveau et groupe, rôles superviseur et responsable du parc, historique unifié |
| `TasksListTest` | 1 | Non-régression de la liste des tâches (hors module) |
| `ScopedReadsTest` | 2 | Aperçu, groupes et suivi de diffusion bornés ; rapport d'incidents borné et complet |
| `DeviceReassignAndConflictsTest` | 3 | Réaffectation d'un lecteur (cible, remplacement, permission), conflits par jours et créneaux |
| `FleetSuspensionRolloutTelemetryTest` | 4 | Suspension et rétablissement (manifeste neutralisé, état, journal), vagues de déploiement stables et suivi, télémétrie de l'agent et anomalies disque et température, niveau urgent soumis au pouvoir « urgence » |
| `InventoryLogsAndTraceabilityTest` | 5 | Code d'inventaire et n° de série uniques, recherche élargie et filtres, décompte par emplacement ; adresse observée, événements perdus, coupure, versions ; dernière diffusion confirmée, lecture résolue, journaux techniques et leur accès ; connexion instable et programme vide ; désactivation motivée, avant/après des campagnes et listes |
| `ScreenClassificationAndCommissioningTest` | 4 | Classement de l'écran, mise en service contrôlée, Institution et salle rattachée, fiche PDF et paramètres d'export |
| `DeviceFleetManagementTest` | 5 | Paramètres de profil et serveurs autorisés, identité et renouvellement forcé, version épinglée, statistiques de groupe, promotion des versions |
| `SupervisionAgentAndExchangesTest` | 4 | Journal des échanges et purge, agent local et lecteur arrêté, dalle éteinte et repli prolongé, erreurs codées dans l'historique |
| `PlaylistAssignmentScopesAndRetentionTest` | 4 | Affectation directe d'une liste, périmètre bâtiment et écran, droits des rapports et conservation, rôle Administrateur SAIE |
| `DeviceSecurityOptionsTest` | 4 | Liste blanche d'enrôlement, requêtes signées et anti-rejeu, stockage chiffré et annuaire réservé, fuseau du site |
| `RealtimeStreamTest` | 2 | Annonces temps réel, filtrage par périmètre, reprise au dernier identifiant |
| `ControlFindingsFixesTest` | 6 | Défauts du second contrôle : modèle d'import, faux incidents, agent, périmètres, suppression de groupe, historique |
| `ScreenInventoryTest` (ajout) | 1 | Import d'un classeur Excel |

## Tests existants adaptés

| Test | Raison |
|---|---|
| `FleetAndIncidentTest::test_remote_reload_is_queued_redelivered_until_acknowledged_and_traced` | L'ancienne relance « remise une fois sans accusé » était le défaut B-D1 : le test vérifie désormais la file de commandes |
| `SettingsEffectTest::test_only_settings_with_a_real_effect_are_offered` | Liste attendue complétée par les 13 nouveaux paramètres, chacun couvert par un test d'effet |

## Lecteur (`player/`)

`npm test` (node --test) : **22 tests réussis**, typage TypeScript sans erreur, construction de production réussie.
