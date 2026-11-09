# 07 — Rapport des tests et de la recette

Toutes les exécutions ont eu lieu le 6 novembre 2026 sur le poste de développement :

- Windows 11, PHP 8.5.12, avec `-d extension=zip` (voir ENV-01) ;
- PostgreSQL 18, base isolée `saie_ceeac_test` ;
- Node.js 22.

Les sorties brutes sont dans `preuves/`. **Aucun résultat n'est déclaré sans exécution.**

## 1. Synthèse

| Suite | Commande | Avant la mise à niveau | Après | Preuve |
|---|---|---|---|---|
| Backend : unitaires, fonctionnels, contrat OpenAPI | `php vendor\bin\phpunit` (zip désormais chargée) | 177 tests, 177 réussis (2 en erreur sans `zip`) | **221 tests, 221 réussis, 1 547 assertions, 0 échec, 0 ignoré** | `preuves/phpunit-junit.xml`, `phpunit-sortie.txt` |
| Lecteur : résolveur, manifeste, hors ligne | `npm test` (`player/`) | 15 / 15 | **22 / 22** | `preuves/player-tests.txt` |
| Typage du lecteur | `npm run typecheck` | Non exécutable (dépendances absentes) | **0 erreur** | `preuves/player-tsc.txt` |
| Typage du frontend | `npx tsc --noEmit` | 0 erreur | **0 erreur** | `preuves/frontend-tsc.txt` |
| Compilation du frontend et du lecteur | `npm run build` | — | **Réussie** | `preuves/frontend-build.txt` |
| Style PHP | `vendor\bin\pint --test` | — | **Conforme** | `preuves/pint-test.txt` |
| Migrations | `php artisan migrate` puis `migrate:rollback --step=2` puis `migrate` | 22 appliquées | **34 appliquées, aucune en attente ; retour arrière des deux migrations de schéma du premier passage exercé** | `preuves/migrations-status.txt` |
| Bout en bout (Playwright) | `npx playwright test` | — | **Non exécuté** : exige l'API, le frontend et le lecteur démarrés | — |

## 2. Tests ajoutés ou modifiés, et ce qu'ils prouvent

| Test | Écart | Ce qui est vérifié |
|---|---|---|
| `AdministrativeHierarchyTest::test_technical_administrator_cannot_disable_a_functional_administrator` | SEC-A1 | Désactivation, réactivation et fermeture des sessions d'un administrateur fonctionnel par un administrateur technique → 403, compte intact, refus journalisé |
| `…::test_technical_administrator_still_administers_an_ordinary_agent` | SEC-A1 (non-régression) | L'assistance ordinaire reste possible |
| `…::test_functional_administrator_cannot_strip_a_role_from_a_peer_holding_rights_they_lack` | SEC-A2 | Retrait d'un rôle d'un pair de rang supérieur, ou de son propre rôle → 403 |
| `…::test_base_role_cannot_receive_administration_rights` | SEC-A3 | `roles.manage` refusé sur « Agent » (422 `BASE_ROLE_PRIVILEGE`) ; relecture sans ajout acceptée |
| `…::test_device_actions_are_bounded_by_the_site_and_zone_of_the_assignment` | SEC-A7 | Technicien limité à l'accueil : révocation et appairage hors zone → 403 ; dans sa zone, le code est contrôlé |
| `…::test_login_attempts_are_capped_per_address_across_accounts` | SEC-A5 | Au-delà du plafond par IP, tous comptes confondus → 429 |
| `SecurityAndAccessTest::test_login_is_generic_locks_after_failures_and_is_audited` (modifié) | SEC-A4 | Compte verrouillé et compte inconnu : même code et même message ; aucune session ouverte |
| `DeliveryProofTest::test_delivery_proof_survives_the_purge_of_old_manifests` | DIF-01, DIF-03 | Transmission enregistrée ; **après suppression de tous les manifestes**, le suivi garde transmis, reçu, activé et lecture ; 4 passages agrégés comptés |
| `…::test_heartbeat_alone_never_advances_the_last_synchronisation` | DIF-04, DIF-05 | Heartbeat seul : pas de synchronisation ; version déclarée bornée ; instant futur ramené à la réception |
| `…::test_critical_alert_is_shown_outside_the_operating_window_but_not_a_warning` | ALR-01 | Résolution serveur : critique affichée hors plage, avertissement non, politique désactivée → éteint |
| `player/tests/live.test.ts` « renouvellement sans réactivation » | DIF-02 | Contenu identique de version supérieure → `renew` ; contenu différent → `accept` |
| `player/tests/live.test.ts` « alerte critique hors plage » | ALR-01 | Même règle côté lecteur |
| `FleetAndIncidentTest::test_screen_hardware_record_and_operational_states` | PAR-01, PAR-02 | Fiche matérielle et garantie ; états attente de configuration, lecteur joignable, hors ligne, anomalie |
| `…::test_maintenance_status_requires_a_reason_and_keeps_the_screen_targeted` | PAR-02 | Motif obligatoire ; état « maintenance » ; garantie antérieure à l'installation refusée |
| `…::test_incident_is_categorised_diagnosed_and_assigned_only_to_an_eligible_notified_agent` | INC-01, INC-02 | Catégorie ; affectation à un agent non habilité refusée ; intervenant notifié ; diagnostic obligatoire et consigné |
| `…::test_invalid_dates_are_refused_without_server_error_and_calendar_period_is_bounded` | API-08, API-09 | 422 au lieu de 500 ; calendrier de plus de 62 jours refusé |
| `PublicationSafetyTest::test_office_file_cannot_illustrate_a_content_submitted_for_display` | MED-01 | Soumission refusée (`MEDIA_NOT_DISPLAYABLE`) |
| `…::test_template_zone_must_fit_inside_the_frame` | MOD-01, API-10 | Zone hors cadre refusée ; zone ajustée créée, avec une réponse conforme au contrat |
| `…::test_demonstration_antivirus_refuses_every_file_in_production` | SEC-AV1 | Hors production, analyse de démonstration ; en production sans `clamd`, échec |

Défauts révélés par les nouveaux tests pendant la mise à niveau, puis corrigés :

- colonne `screens.status` trop courte pour « maintenance » : erreur 500 ;
- `is_institutional` à `null` dans la réponse de création d'un modèle : réponse hors contrat.

Deux tests existants ont aussi rappelé une décision antérieure : la réinitialisation du second facteur reste permise pour l'assistance, avec information du titulaire. Le contrôle hiérarchique a été ajusté en conséquence.

**Limite de méthode.** L'échec des nouveaux tests sur le code d'origine n'a pas été rejoué systématiquement, faute d'historique Git sur le poste. Il est établi par lecture du code d'origine, conservé dans l'archive de sauvegarde, et constaté directement pour les deux défauts ci-dessus.

### Second passage (6 novembre, soir)

| Test | Écart | Ce qui est vérifié |
|---|---|---|
| `PublicationRulesTest::test_content_is_never_scheduled_before_its_validity_start` | PUB-01 | Début de l'entrée = début de validité du contenu ; validité commençant après la campagne → aucune entrée |
| `…::test_preview_warns_when_template_orientation_differs_from_the_screen` | PUB-02 | Modèle portrait sur écran paysage : `orientation_mismatch` et avertissement |
| `…::test_media_still_broadcast_or_used_as_subtitles_cannot_be_trashed` | PUB-03 | Sous-titres et version approuvée diffusée → corbeille refusée, usage « campagne » listé. Preuve incidente : le déclencheur d'immutabilité de `content_versions` a refusé une modification de l'instantané par le test |
| `…::test_document_trash_requires_unpublishing_and_no_qr_reference` | PUB-06 | Document publié → 409 ; référencé par un QR → 409 ; sinon corbeille, absent des listes |
| `…::test_media_detail_lists_visible_duplicates_by_fingerprint` | PUB-08 | Doublon par empreinte listé |
| `CalendarSubscriptionTest::test_subscription_feed_works_without_session_and_stops_when_revoked_or_disabled` | SAL-01 | Flux lisible sans session, confidentialité appliquée, adresse non conservée par l'idempotence, jeton inconnu → 404, titulaire désactivé → 404, révocation par un tiers → 404, révocation par le titulaire → flux coupé |
| `FleetAndIncidentTest::test_player_anomalies_open_then_resolve_automatic_incidents` | SUP-02 | Stockage et horloge → 2 incidents notifiés, aucun doublon, résolus quand la condition disparaît |
| `…::test_remote_reload_is_delivered_once_and_traced` | PLY-R2 | Motif obligatoire ; commande remise une seule fois ; demande et remise tracées |
| `…::test_unauthorized_terminal_calls_are_traced_and_reported_in_supervision` | PLY-R2 | Jeton forgé → 401 tracé une fois par adresse et par période ; avertissement en supervision |
| `…::test_telemetry_is_sampled_once_per_five_minute_slot` | DIF-07 | Un relevé par créneau de 5 min avec des heartbeats de 30 s |
| `…::test_supervision_never_shows_unmeasured_checks_as_ok_and_dashboard_reports_the_fleet` | SUP-01, PAR-03 | Dalles non mesurées et source périmée jamais « OK » ; parc par état, par bâtiment, plage par défaut |
| `DeviceDeliveryTest::test_media_download_supports_byte_ranges_for_resumption` | PLY-R1 | Requête `Range` → 206 avec les octets demandés ; 206 déclaré au contrat |
| `player/tests/chunks.test.ts` (3 tests) | PLY-R1 | Découpage en blocs, seuls les blocs manquants redemandés, bloc tronqué refusé |

Deux constats de l'audit initial se sont révélés inexacts à la vérification : le jeton du lecteur était déjà chiffré (SEC-T1) et les sélecteurs étaient déjà paginés (UX-13). Ils sont classés « sans objet » dans la matrice.

## 3. Couverture des scénarios demandés

### Intégration (chaîne complète)

| Étape | Couverture |
|---|---|
| Création et validation d'un contenu | `ContentWorkflowTest`, `ValidatorEligibilityTest` |
| Campagne, ciblage écran, groupe et zone | `DeviceTest`, `ZoneScopeTest`, `EventModeTest` |
| Transmission au lecteur (manifeste signé) | `DeviceTest`, `DeliveryProofTest` |
| Réception, activation, lecture déclarée | `DeliveryProofTest` |
| Médias servis et vérifiés | `DeviceDeliveryTest` |

### Résilience

| Scénario | Couverture | Niveau |
|---|---|---|
| Coupure réseau ou serveur, autorisation hors ligne expirée | `schedule.test.ts` (PLY-05), `live.test.ts` | Simulation logicielle |
| Manifeste expiré, antérieur ou destiné à un autre lecteur | `live.test.ts` | Simulation logicielle |
| Alerte prioritaire avant la fin des téléchargements | `live.test.ts` | Simulation logicielle |
| Ressource manquante : aucune entrée incomplète lancée | `schedule.test.ts` | Simulation logicielle |
| Contenu expiré, repli expiré | `schedule.test.ts` | Simulation logicielle |
| Conflit de réservation (concurrence réelle) | `ReservationTest` ; contrainte `gist` | Base réelle |
| Lecteur révoqué | `DeviceTest` | API |
| Lecteur hors ligne → incident | `OperationsMonitoringTest` | API |
| Interruption de téléchargement avec reprise | `chunks.test.ts` (découpage), `DeviceDeliveryTest` (206) | Simulation logicielle : l'enchaînement complet dans un navigateur n'est pas automatisé |
| Coupure électrique d'un terminal, extinction de la dalle | — | **Non vérifiable sans matériel** |

### Sécurité (ASVS niveau 1)

Les classes suivantes couvrent ce niveau :

- authentification : `AuthHardeningTest`, `SecurityEnforcementTest`, `AccountRecoveryTest` ;
- accès : `PolicyCoverageTest`, `ObjectAccessTest`, `MediaAccessTest`, `PrivilegeEscalationTest`, `AdministrativeHierarchyTest` ;
- fichiers : `ClamdScannerTest`, `DocumentVersionScanTest` ;
- contrat : `OpenApiContractTest`.

Le **niveau 2 et le test d'intrusion ne sont pas réalisés** (SEC-07).

## 4. Anomalies résiduelles

- **Correctifs sans test dédié** (couverts par le typage et la relecture) : DIF-03 (composant d'affichage), DIF-06 (file IndexedDB), UX-10, UX-11, UX-12 ; côté lecteur, l'enchaînement complet du téléchargement par blocs dans un navigateur.
- **Non exécutés** : Playwright, charge sur la cible, matériel, ClamAV réel, SMTP institutionnel, lecteur d'écran.
- **Écarts restants** : 3 externes (SEC-07 test d'intrusion, SEC-AV2 ClamAV réel, SAL-02 agenda institutionnel). Six corrections appliquent une option recommandée en attente de validation (D2 à D6).

## 5. Troisième passage (6 novembre, nuit)

| Test | Écart | Ce qui est vérifié |
|---|---|---|
| `AdministrativeHierarchyTest::test_lockout_duration_grows_with_consecutive_failures` | SEC-A6 | 1 min dès 5 échecs, 5 min dès 10 |
| `…::test_maintenance_technician_handles_incidents_without_account_administration` | ROL-01 | Technicien : supervision et incidents, pas de comptes, second facteur exigé ; consultation : lecture seule |
| `ReportingTest::test_xlsx_workbook_is_valid_and_never_contains_formulas` | RAP-01 | Archive OOXML valide, noms de feuilles nettoyés et uniques, aucune formule |
| `…::test_export_can_be_generated_as_excel` | RAP-01 | Export XLSX généré et téléchargé avec le bon type |
| `…::test_report_figures_are_bounded_to_the_assignment_scope_and_building_filter` | RAP-02 | Diffuseur limité à une zone : ses écrans seulement ; filtre par bâtiment |
| `…::test_touch_orientation_kiosk_receives_public_directory_and_floor_plan` | ORI-01, PAR-04 | Annuaire (bureau, téléphone validé), plan public téléchargeable, plan restreint jamais transmis |
| `FleetAndIncidentTest::test_retention_simulates_by_default_then_purges_and_archives…` | ADM-01 | Simulation sans effet ; purge des données échues ; journal archivé, jamais supprimé |
| `…::test_error_log_and_api_metrics_are_exposed_to_technical_staff…` | ADM-02 | Masquage du mot de passe, de l'adresse et du SQL ; métriques comptées ; accès réservé |
| `…::test_preventive_maintenance_plan_opens_one_intervention_and_reschedules…` | INC-03 | Une intervention par échéance ; échéance recalée à la résolution |
| `SettingsEffectTest::test_supervision_thresholds_and_alert_policy_are_settable…` | PAR-S1 | Bornes contrôlées ; seuil abaissé → incident ; politique d'alerte transmise au lecteur |
| `ZoneScopeTest::test_fleet_wide_information_alert_needs_a_second_holder…` | PUB-05 | Information visant tout le parc en attente, jamais diffusée, auto-confirmation refusée ; critique immédiate |
| `ApprovalCircuitTest` | PUB-04 | Deux validateurs distincts, aucune version diffusable avant le second niveau |
| `PublicationRulesTest::test_media_tags_and_expired_usage_rights_are_enforced` | PUB-08 | Étiquettes normalisées et filtrables ; droits échus : entrée retirée, soumission refusée |
| `…::test_document_four_eyes_rule_requires_another_manager_to_publish` | PUB-06 | Règle désactivée : publication directe ; activée : auto-publication refusée, demande notifiée, collègue publie |
| `…::test_reusable_playlist_keeps_order_and_campaign_archiving_is_controlled` | PUB-07 | Ordre conservé, contenu archivé refusé ; campagne en cours non archivable ; archivée absente de la liste |
| `PublicationSafetyTest::test_converted_presentation_is_broadcast_through_its_fingerprinted_pdf_copy` | PUB-09 | Fichier diffusé = copie PDF (empreinte, type) ; soumission acceptée |
| `player/tests/directory.test.ts` (2 tests) | ORI-01 | Tri, filtres par niveau et par initiale, accents ignorés |

Défauts révélés par ces tests et corrigés :

- une clé de paramètre trop longue pour le journal d'audit (erreur 500), renommée `alerts.critical_after_hours` ;
- `is_active` absent de la réponse de création d'un plan de maintenance ;
- un plan de niveau enregistré dans une copie du tableau des ressources, car une fonction fléchée PHP capture par valeur : le plan n'était pas téléchargeable.

Non couverts par un test dédié : le glisser-déposer de l'assistant de campagne et le rendu de la borne tactile dans un navigateur.

## 6. Recette locale de l'application en fonctionnement (6 et 7 novembre)

L'application a été lancée complètement sur le poste : API, file d'attente, planificateur, frontend et lecteur, sur la base de démonstration. La suite Playwright de bout en bout (43 parcours) a été exécutée pour la première fois depuis l'audit.

| Exécution | Résultat | Commentaire |
|---|---|---|
| 1 (API sur le port 8000) | 24 réussis, 19 en échec | 13 échecs dus au second facteur imposé : un serveur `artisan serve` préexistant, lancé par une autre session, occupait aussi le port 8000 et lisait le `.env` avec `SAIE_MFA_ENFORCED=true` |
| 2 (pile dédiée : API sur 8010, frontend sur 5175, lecteur sur 5176) | **38 réussis, 5 en échec** | Les 5 échecs ont été analysés un par un (ci-dessous) |
| 3 (les 5 parcours en échec) | 3 réussis, 1 ignoré avec motif, 1 bloqué par un compte modifié par une autre session | Le parcours du lecteur a été vérifié par un script de pilotage dédié (ci-dessous) |

Défauts révélés par la recette réelle et corrigés, chacun avec un test de non-régression qui échoue sans la correction :

| Défaut | Gravité | Correction | Test |
|---|---|---|---|
| Le lecteur, servi sous `/player/` sur le domaine déclaré à Sanctum, recevait une **erreur 500 sur chaque manifeste et heartbeat** : une session était ouverte pour un lecteur, qui n'est pas un compte, et ses `POST` auraient exigé le jeton CSRF. Défaut latent depuis l'origine, invisible en CI parce que le lecteur y tournait sur une autre origine | **P0** : aucun écran n'aurait reçu de programme en production (sessions en base par défaut) | Routes `device/*`, `integration/*` et flux iCalendar exclues de la détection de session Sanctum | `DeviceDeliveryTest::test_player_served_from_the_administration_domain…` (échec constaté sans la correction) |
| Scène du lecteur décalée et coupée quand la résolution du navigateur diffère de celle de la scène (téléviseur exposant 1280 × 720 pour une scène 1920 × 1080) | P1 | Centrage absolu, puis réduction | Vérifié par capture et mesure de géométrie (x = 0, y = 0, 1280 × 720) |
| Régression des optimisations : listes des contenus et des médias présentées avec des données vides (« — » au lieu des versions approuvées, 0 utilisation), car une fonction fléchée PHP capture par valeur | P1 | Capture par référence | `PublicationRulesTest::test_list_endpoints_expose_preloaded_versions_and_usage_counts` |
| Import CSV : une cellule numérique vide provoquait une erreur SQL | P2 | Cellule vide = « ne pas modifier » | `ReferentialImportScopeTest::test_empty_optional_cells_keep_defaults…` |
| Connexion depuis une origine non déclarée : erreur 500 au lieu d'un refus | P3 | Refus 400 `STATEFUL_ORIGIN_REQUIRED` avant toute vérification d'identifiants | `AdministrativeHierarchyTest::test_login_from_an_undeclared_origin…` |

Vérification du lecteur par pilotage, avec un compte technicien temporaire, désactivé ensuite :

- appairage par code ;
- manifeste signé reçu ;
- médias téléchargés **par blocs** (réponses 206 `bytes=0-…`) ;
- accusés `manifest_received`, `content_loaded` et `activated`, puis `off_window` hors plage ;
- avec une plage étendue temporairement : contenu de repli affiché plein cadre, aucune erreur de page.

Plage et lecteur ont été remis en état.

Parcours dépendants de l'heure, désormais ignorés avec leur motif hors des heures d'ouverture au lieu d'échouer : V28 (présence en salle) et le parcours complet du lecteur.

Le mot de passe du compte de démonstration `alain.mbeng`, modifié pendant la recette par une autre session active sur le poste, a été rétabli le 7 novembre sur demande, avec une trace au journal d'audit.

**Exécution complète finale (7 novembre au soir, après les finitions d'interface) : 41 réussis, 2 ignorés avec motif (hors heures d'ouverture), 0 échec.** Au premier passage, 3 parcours ont échoué : dépôt d'image, dépôt de PDF et confirmation PDF01. Le worker de recette avait été lancé sans la liste des files `media`, `exports` et `notifications`. Une fois le worker relancé sur ces files, comme dans `infra/docker-compose.yml`, les 3 parcours passent. Il ne s'agissait pas d'un défaut de l'application.

Résultats finaux : **PHPUnit 228 / 228** (1 573 assertions), lecteur 22 / 22, typage et compilation sans erreur.
