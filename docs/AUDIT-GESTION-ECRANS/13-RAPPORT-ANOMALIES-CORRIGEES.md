# 13 — Anomalies corrigées

Défauts constatés à l'audit initial (A-Dx, B-Dx, C-Dx) ou découverts pendant les travaux, avec la preuve de leur correction.

| Réf. | Anomalie | Correction | Preuve |
|---|---|---|---|
| C-D1 | Contrôle de périmètre neutralisé (écran désactivé, groupe vide) | Résolution des cibles sans filtre de statut ; groupe vide rattaché à une structure | `ScreenModuleIntegrityTest` |
| A-D1 / C-D6 | Déplacement hors périmètre | Contrôle après mise à jour | idem |
| C-D2 | Écran désactivé toujours programmé (RG-005) | Manifeste vide | idem |
| C-D3 | Cible de campagne inexistante acceptée | `TARGET_NOT_FOUND` | idem |
| C-D4, C-D5, A-D12, C-D13 | Incidents, plans, exports, listes sans périmètre | Périmètre appliqué | idem, `ScreenInventoryTest` |
| C-D7 | Incident résolu ou clos sans résultat (RG-012) | Résultat exigé, vérification avant clôture | `IncidentWorkflowTest` |
| A-D2 | Incident déclaré à la main sans effet sur l'état « en anomalie » | Lecteur rattaché, état calculé aussi par écran | `ScreenModuleIntegrityTest` |
| B-D3 | Anomalie « collante » après disparition de l'erreur | Seules les erreurs de moins de 10 min comptent | idem |
| B-D2 | Gravité « moyen » impossible | Contrainte, validation, libellés | idem |
| B-D1, C-D10 | Commande effacée à la remise, sans accusé ni résultat | File `device_commands` | `RemoteCommandsTest`, e2e |
| B-D5 | Échec de capture non remonté | Capture via commande avec compte rendu | `RemoteCommandsTest` |
| B-D6 | Champ mort `revoked: false` | Retiré du contrat | contrat OpenAPI |
| B-D4 | Seuils de supervision codés en dur, texte « 5 min » figé | Paramètres, texte dérivé | `SupervisionStatesTest` |
| B-D11 | `device_receipts` jamais purgé | Durée de conservation paramétrable | `FleetReportsTest` |
| A-D3 | Plage affichée différente de la plage appliquée | Plage effective (écran, groupe, général) | Suite PHPUnit |
| A-D4, A-D5, A-D6 | Totaux incohérents, carte inversée, lecteur jamais vu classé différemment | Libellés et calculs alignés | Suite PHPUnit, e2e |
| A-D7 | Description acceptée mais jamais restituée | Restituée et éditable | e2e `screens-inventory.spec.ts` |
| A-D8 | Option « jours fériés » perdue à l'enregistrement d'un groupe | Options conservées | Revue de code |
| A-D9 | Écran d'affichage non rattachable à une salle | Salle facultative pour tout type | idem |
| A-D10 | Bâtiment et type d'espace absents de l'API | Ajoutés | idem |
| A-D14 | Export d'inventaire PDF seul, pauvre | PDF, CSV, XLSX enrichis | `ScreenInventoryTest`, e2e |
| A-D15 | Pas de création de salle dans l'interface | Boîte « Nouvelle salle » | e2e des vues |
| A-D16 | Orientation non appliquée par le lecteur | **Non corrigé** (voir 14) | — |
| C-D8, C-D9 | Historique d'écran partiel ; pas d'avant/après | Historique unifié ; avant/après sur écran, groupe, profil | `FleetRolesAndHistoryTest` |
| B-D9 | « Jeton d'appairage en clair » | **Faux positif** : colonne chiffrée (`encrypted`) | revue de code |
| Nouveau | Clés de paramètres de plus de 30 caractères rejetées par le journal (`object_type` varchar 30) : erreur 500 à l'enregistrement | Clés raccourcies | `SupervisionStatesTest` |
| Nouveau | Réponse de création d'écran sans les valeurs par défaut de la base (`status`, `audio_enabled` nuls) | Relecture avant présentation | `ScreenInventoryTest` |
| Nouveau | Compte rendu de commande tronqué par la validation (seule la clé `errors` conservée) | Compte rendu conservé tel quel, borné à 32 Kio | `RemoteCommandsTest`, e2e |
| Nouveau | Fenêtre d'incident figée sur l'ancienne version après une action | Le serveur renvoie l'incident présenté, la fenêtre le suit | e2e `screens-incidents.spec.ts` |
| Nouveau (hors module) | « Tâches & validations » en erreur 500 pour un auteur ayant une version renvoyée (138 erreurs relevées le 8/11) | Fusion de collections corrigée | `TasksListTest` |
| Nouveau | Lectures déclarées pendant un arrêt de diffusion (fausse preuve) | Aucune lecture déclarée pendant l'arrêt | revue de code `Player.tsx`, e2e |
| Nouveau | Liste et fiche des écrans en erreur 500 dès qu'un lecteur réel déclarait une coupure (date non convertie par le modèle) | Conversion `offline_since` en date | `InventoryLogsAndTraceabilityTest` (liste affichée après un battement) ; e2e `views.spec.ts` |
| Nouveau | Suspension sans effet visible sur un écran sans programme (aucun nouveau manifeste) | Indicateur `suspended` dans le manifeste signé | `FleetSuspensionRolloutTelemetryTest` ; e2e `screens-suspension.spec.ts` |
| Nouveau | Paramètres d'export « bâtiment », « inclure les archivés » et « mois » validés mais écartés avant la génération (inventaire par bâtiment et rapport de garantie inopérants depuis l'API) | Paramètres transmis | `ScreenClassificationAndCommissioningTest::test_screen_sheet_and_export_parameters_are_kept` |
