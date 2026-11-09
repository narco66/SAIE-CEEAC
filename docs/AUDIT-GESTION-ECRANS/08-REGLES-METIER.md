# 08 — Règles métier (§19) et leur application

| Code | Règle | Application dans le code | Test |
|---|---|---|---|
| RG-001 | Identifiant unique | Clé UUID ; code unique (`screens.code` unique, validation) | Suite PHPUnit (création d'écrans) |
| RG-002 | Écran opérationnel avec localisation valide | `screens.location_id` NOT NULL + clé étrangère ; salle contrôlée au même niveau (`ROOM_FLOOR_MISMATCH`) | `ScreenInventoryTest::test_the_full_record_is_stored_and_each_move_is_historised` |
| RG-003 | Un seul lecteur actif par écran | Index unique partiel `devices_one_active_per_screen` ; remplacement explicite (`replace`) qui révoque l'ancien | `DeviceTest` |
| RG-004 | Enrôlement authentifié | Code à usage unique 10 min, secret de sondage, permission `devices.pair` et périmètre | `DeviceTest` |
| RG-005 | Écran désactivé sans nouvelle programmation | `ManifestBuilder` : programme et alertes vides pour un écran désactivé ou archivé ; ciblage limité aux écrans en service ou en maintenance | `ScreenModuleIntegrityTest::test_an_inactive_screen_receives_no_programme` |
| RG-006 | Commande compatible avec les capacités | `RemoteCommands::availability` (capacités déclarées) ; refus `COMMAND_UNAVAILABLE` journalisé | `RemoteCommandsTest::test_an_unsupported_command_is_refused_with_its_reason` |
| RG-007 | Commande historisée | `device_commands` + journal `device.command_*` ; historique par lecteur et dans l'historique de l'écran | `RemoteCommandsTest`, e2e `screens-commands.spec.ts` |
| RG-008 | Modification importante auditée | `Audit::log` avec avant/après (écran, déplacement, groupe, profil, paramètres, archivage) ; journal en ajout seul | Suite PHPUnit |
| RG-009 | Hors ligne jamais « en diffusion » | Ordre de calcul de `Present::operationalState` (hors ligne avant tout état de lecture) | `SupervisionStatesTest::test_operational_states_distinguish…` |
| RG-010 | Affectation respectant programmation et priorités | Moteur de campagnes existant (priorités, plages, mode événement, alertes prioritaires puis retour au programme) | `EventModeTest`, `ScheduleResolver` (tests existants) |
| RG-011 | Changement d'emplacement historisé | `screen_location_history` (un emplacement courant), motif et auteur | `ScreenInventoryTest`, e2e `screens-inventory.spec.ts` |
| RG-012 | Incident non clos sans résultat | Résultat exigé à la résolution (y compris automatique) ; **clôture exigeant une vérification** | `IncidentWorkflowTest::test_the_full_workflow_requires_a_verification_before_closing` |
| RG-013 | Écran archivé hors nouvelles campagnes | Statut `archived` : hors ciblage, hors groupes, lecteur révoqué, fiche en lecture seule | `ScreenModuleIntegrityTest::test_archiving_withdraws_the_screen_and_keeps_its_record` |
| RG-014 | Disponibilité calculée sur les données reçues | Joignabilité = télémétrie reçue / créneaux attendus ; durées hors ligne issues de l'historique des états | `FleetOverviewTest`, `FleetReportsTest` |

## Règles complémentaires introduites

- **Périmètre** : toute lecture et écriture d'écran, de lecteur, de groupe, d'incident, de plan de maintenance ou de rapport est bornée au site, à la zone, au niveau et au groupe de l'habilitation.
- **Groupe dynamique** : réservé aux habilitations sans restriction de périmètre ; membres non modifiables à la main.
- **Commande perturbante** (arrêt, veille, redémarrage) : motif obligatoire ; une commande identique en cours ne peut pas être doublée.
- **Profil utilisé** : ne peut pas être désactivé.
