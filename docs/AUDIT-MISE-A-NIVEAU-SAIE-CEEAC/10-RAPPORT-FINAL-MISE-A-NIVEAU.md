# 10 — Rapport final de mise à niveau

6 novembre 2026. Les sources exactes sont dans la matrice (`02-…`) et le rapport de tests (`07-…`).

## 1. État initial

- Application déjà étendue et cohérente : 185 opérations d'API, 22 migrations, 69 modèles, 52 pages d'administration, un lecteur hors ligne signé.
- 177 tests PHPUnit verts sur PostgreSQL 18 réel, après activation de l'extension `zip`.
- Un audit antérieur et 7 lots de corrections (R1 à R7) avaient déjà traité les défauts bloquants connus.

L'audit des 22 modules a toutefois relevé **des défauts qui faussaient la preuve de diffusion**, la fonction qui distingue un vrai système d'affichage institutionnel d'une démonstration :

- preuves effacées en 2 h 30 environ ;
- activations comptées toutes les 30 minutes ;
- « lecture » déclarée avant l'affichage ;
- « synchronisation » mise à jour par le simple contact du lecteur.

Il a aussi relevé :

- des failles de séparation des fonctions entre administrateurs ;
- une alerte d'évacuation qui pouvait ne pas s'afficher la nuit ;
- un parc d'écrans sans fiche matérielle ni statut de maintenance ;
- des voyants de supervision verts sans mesure.

## 2. Anomalies corrigées (54 écarts, dont 49 prouvés par un test dédié)

| Domaine | Écarts corrigés |
|---|---|
| Sécurité | SEC-A1 à SEC-A5, SEC-A7, SEC-AV1 |
| Diffusion et preuves | DIF-01 à DIF-07, ALR-01, SUP-01 |
| Parc et maintenance | PAR-01 à PAR-03, INC-01, INC-02 |
| Publication | MED-01, MOD-01, API-10 |
| API et interface | API-08, API-09, UX-10, UX-11 |

## 3. Modules ajoutés ou enrichis

Aucun module n'a été créé de zéro. Les modules existants ont été complétés, conformément à la règle « ne pas reconstruire ».

| Module | Enrichissement |
|---|---|
| M3 Parc d'écrans | Fiche matérielle (marque, modèle, série, diagonale, IP, connexion, installation, garantie) ; statut « en maintenance » motivé ; six états opérationnels calculés |
| M17 Preuves | Niveau « Transmis » durable ; lecture déclarée avec durée, contenu, version et nombre de passages |
| M18 Incidents | Catégorie, maintenance corrective ou préventive, diagnostic, affectation contrôlée et notifiée |
| M1 Tableau de bord | Parc complet par état, répartition par bâtiment, salles équipées, contenus en attente, frise sur la plage paramétrée |
| M9 Alertes | Alertes critiques affichées hors plage (politique transmise au lecteur) |

## 4. Améliorations de sécurité

- Protection hiérarchique mutualisée (`Controller::assertMayAdminister`) avec deux niveaux : prise de contrôle et neutralisation.
- Rôle de base fermé aux droits d'administration.
- Connexion sans énumération ; plafond par adresse IP.
- Périmètre appliqué à l'appairage, à la révocation et aux captures.
- Antivirus en échec sûr en production.
- Accusés des lecteurs bornés (instant, version).

## 5. Améliorations d'architecture

- Table `campaign_transmissions` : la preuve ne dépend plus de la rétention des manifestes.
- Verdict `renew` du lecteur : renouvellement de l'autorisation séparé du changement de programme.
- File d'événements du lecteur sérialisée, avec signalement des pertes.
- Échantillonnage de télémétrie aligné sur les créneaux du calcul de disponibilité.
- Tableau de bord sans requête par écran.
- Contrat OpenAPI régénéré ; toutes les réponses restent conformes.

Aucune dépendance ajoutée. Les trois migrations sont additives et réversibles.

## 6. Tests réalisés

| Suite | Résultat |
|---|---|
| PHPUnit | **221 / 221** (1 547 assertions), dont 44 nouveaux et 2 modifiés |
| Lecteur | **22 / 22**, dont 7 nouveaux ; typage sans erreur |
| Frontend | Typage sans erreur ; compilation réussie |
| Style | Pint conforme |
| Migrations | Appliquées ; retour arrière exercé |

Non exécutés : Playwright, charge, matériel, ClamAV réel, test d'intrusion.

## 7. Écarts restant à traiter

**21 écarts restent à traiter : 11 ouverts, 2 partiels, 5 en attente de décision et 3 externes** (2 constats initiaux se sont révélés sans objet). Le P0 restant est SEC-07 (test d'intrusion et rapport ASVS niveau 2), qui relève d'un prestataire indépendant.

Principaux écarts fonctionnels :

- circuit de validation à plusieurs niveaux (PUB-04, décision) ;
- annuaire et borne d'orientation (ORI-01, données institutionnelles requises) ;
- listes de lecture réutilisables (PUB-07) ;
- rôles supplémentaires (ROL-01, décision) ;
- purge de conservation (ADM-01, décision) ;
- export Excel (RAP-01, dépendance à approuver).

Planification : `06-PLAN-MISE-A-NIVEAU.md`.

Correctifs **sans test dédié**, à couvrir au prochain lot : DIF-03, DIF-06, UX-10, UX-11, UX-12.

## 8. Points d'attention sur l'intervention

- **Pas d'historique Git** sur le poste. Une archive des sources a été faite avant toute modification (dossier temporaire de la session). Il est recommandé de verser ces modifications dans le dépôt distant par une revue humaine.
- **Base locale `saie_ceeac`** : les trois nouvelles migrations y ont été appliquées. Elles sont additives : aucune donnée existante n'a été modifiée ni supprimée. Les deux migrations de schéma ont été annulées puis rejouées une fois, pour corriger la longueur de `screens.status`, alors que leurs colonnes étaient encore vides.
- **Fichiers modifiés** :
  - backend : 27 fichiers d'application et de configuration, 3 migrations, 5 classes de tests (4 nouvelles, 1 modifiée) ;
  - frontend : 13 fichiers ;
  - lecteur : 5 fichiers et 1 fichier de tests ;
  - contrat : `docs/api/openapi.json`.
- **Décision à confirmer : ALR-01.** Le cahier prévoyait de signaler les écrans hors plage sans leur afficher l'alerte. Le nouveau comportement est réglable (`SAIE_CRITICAL_ALERT_OVERRIDES_WINDOW=false` rétablit l'ancien).

## 9. Prérequis au déploiement en production

1. Test d'intrusion indépendant et rapport ASVS 5.0 niveau 2 sans vulnérabilité critique ou élevée ouverte (SEC-07).
2. `SAIE_AV_DRIVER=clamd` avec un ClamAV réel joignable, éprouvé (SEC-AV2).
3. `php artisan saie:preflight` sans échec : HTTPS, clé de signature, compte PostgreSQL à moindre privilège, absence de données de démonstration.
4. Sauvegarde planifiée (base, WAL, stockage) et exercice de restauration réussi sur la pile cible, avec RPO et RTO consignés.
5. Images Docker figées par version et empreinte ; CI verte sur PHP 8.4 et PostgreSQL 18.
6. Pilote sur terminaux représentatifs, avec dalles pilotables (extinction, rallumage, état « écran visible »).
7. ~~Décisions D1 à D7 (`06-…` § 2) consignées dans `docs/decisions.md`.~~ **Levé le 7 novembre** : D1 à D7 validées avec l'option recommandée et consignées ; D7 appliquée (participants, exports de masse, permission des versions du lecteur).
8. Comptes de démonstration absents ; mots de passe initiaux individuels (`saie:create-admin`).

**Avis :** le système est apte à la recette fonctionnelle et à la préparation du pilote. Il n'est **pas** apte à la production avant la levée des prérequis 1, 2, 4 et 6.

## 10. Second passage de corrections (6 novembre, soir)

À la demande « corrige », les écarts qui ne dépendaient ni d'une décision ni de données institutionnelles ont été traités :

| Écart | Correction |
|---|---|
| PUB-01 | Début de validité d'un contenu appliqué au manifeste ; la priorité d'un contenu est présentée comme une proposition au diffuseur |
| PUB-02 | Avertissement d'orientation (modèle face à l'écran) dans l'aperçu de campagne |
| PUB-03 | Un média utilisé en sous-titres ou dans une version encore diffusée ne peut plus aller à la corbeille |
| PUB-06 (partiel) | Corbeille des documents contrôlée ; la validation avant publication reste à décider |
| PUB-08 (partiel) | Doublons signalés par empreinte dans la fiche média |
| SAL-01 | Abonnement iCalendar personnel et révocable, lisible par un client d'agenda sans session |
| SUP-02 | Incidents automatiques : stockage plein, horloge déréglée, programme non appliqué |
| PLY-R1 | Téléchargements reprenables par blocs (`Range`), empreinte du fichier complet toujours vérifiée |
| PLY-R2 | Relance à distance de l'application du lecteur ; appels de terminaux non autorisés tracés et signalés |
| UX-12 | Titre de page, focus après navigation, état d'erreur des champs annoncé |
| Tests ajoutés | DIF-07, SUP-01 et PAR-03, corrigés au premier passage, sont désormais couverts par des tests |

Ajouts techniques :

- 2 migrations additives : `calendar_subscriptions`, commande en attente sur `devices` ;
- 4 routes : abonnements, relance, corbeille des documents ;
- 3 réglages : `SAIE_ANOMALY_STORAGE_RATIO`, `SAIE_ANOMALY_CLOCK_SKEW_MS`, `SAIE_ANOMALY_SYNC_STALLED_MINUTES`.

**Requalifiés sans objet après vérification** : SEC-T1 (le jeton était déjà chiffré) et UX-13 (les sélecteurs étaient déjà paginés).

**Sans changement** : les décisions D1 à D7, SEC-07 (test d'intrusion) et les essais sur matériel. Les prérequis de production du § 9 restent valables.

## 11. Troisième passage : implémentation des écarts restants (6 novembre, nuit)

À la demande d'implémenter les 21 écarts restants, **18 ont été traités** ; les 3 autres dépendent d'un tiers.

| Écart | Réalisation | Statut |
|---|---|---|
| ROL-01 (D4) | Rôles technicien de maintenance (second facteur exigé), gestionnaire des contenus, consultation | Option recommandée, à valider |
| SEC-A6 (D6) | Verrouillage progressif 1 / 5 / 15 min | Option recommandée, à valider |
| PUB-04 (D2) | Circuit à 1 à 3 niveaux par catégorie ; 2 niveaux pour les communiqués et les messages de la Présidence | Option recommandée, à valider |
| PUB-05 (D3) | Double validation des alertes d'information visant tout le parc | Option recommandée, à valider |
| PUB-06 | Double validation des documents ; demande de publication notifiée | **Activée par défaut** (décision du 8 novembre) |
| ADM-01 (D5) | `saie:retention` : simulation, purge nocturne activable, journal archivé et jamais supprimé ; durées proposées 5 ans, 90 jours, 180 jours | Option recommandée, à valider |
| RAP-01, RAP-02 | Export Excel sans dépendance ; filtres par site et par bâtiment ; cloisonnement par périmètre | Fait |
| PAR-S1 | 8 paramètres supplémentaires réglables dans l'interface | Fait |
| ADM-02 | Métriques de l'API et journal des erreurs filtré | Fait |
| INC-03 | Plans de maintenance préventive | Fait |
| PUB-07 | Listes de lecture réutilisables, glisser-déposer, archivage des campagnes | Fait |
| PUB-08 | Étiquettes, droits d'usage et leur expiration | Fait |
| PUB-09 | Présentations diffusées dans leur copie PDF ; pages web exclues par choix de sécurité | Fait |
| PAR-04, ORI-01 | Types d'espace, plans de niveau, annuaire public, borne d'orientation tactile | Fait |
| ENV-01, ENV-02 | `zip` activée (php.ini sauvegardé) ; `.npmrc` de projet | Fait |
| SEC-07 | Test d'intrusion et rapport ASVS niveau 2 | **Externe** : prestataire indépendant |
| SEC-AV2 | ClamAV réel | **Externe** : démon à installer sur le serveur de recette |
| SAL-02 | Synchronisation entrante Exchange ou Google | **Externe** : accès au système d'agenda institutionnel requis |

Ajouts techniques du troisième passage :

- 7 migrations additives : rôles, alertes, niveaux de validation, droits des médias, plans de maintenance, listes de lecture, orientation ;
- 1 commande `saie:retention` ;
- 1 générateur `XlsxWriter` ;
- 1 service `OperationalMetrics`.

Les décisions D2 à D6 sont **appliquées avec l'option recommandée, mais restent à valider** par la Commission. Chacune se règle par paramètre ou par référentiel, sans redéploiement : niveaux par catégorie, gravités soumises à double validation, paliers de verrouillage, durées de conservation, double validation des documents.

Les prérequis de production du § 9 sont inchangés. Le prérequis 7 porte désormais sur la confirmation des options appliquées, et non plus sur un choix initial.

## 12. Exécution locale, vérification et optimisation (6 et 7 novembre)

**Défaut critique découvert en conditions réelles et corrigé.** En production, le lecteur partage le domaine de l'administration. Chaque appel d'un lecteur aurait reçu une erreur 500 : aucun écran n'aurait reçu de programme. Les routes des lecteurs sont désormais sans session, avec un test de non-régression (voir `07-…` § 6). **Ce correctif est un prérequis absolu de toute mise en service.**

Autres corrections issues de la recette :

- centrage de la scène du lecteur sur les écrans de résolution différente ;
- import CSV avec cellules vides ;
- refus explicite d'une connexion depuis une origine non déclarée.

Optimisations mesurées par comptage des requêtes SQL sur la base de démonstration :

| Écran | Avant | Après | Cause traitée |
|---|---|---|---|
| Médiathèque | 78 | 20 (constant) | Usages calculés par média → comptage groupé pour la page |
| Campagnes | 68 | 26 | Écrans ciblés chargés avec leurs relations → simple comptage ; taux de réception tiré de la transmission durable, ce qui corrige aussi un taux faux après la purge des manifestes |
| Rapports | 167 | 78 | Jours fériés lus une fois au lieu d'une requête par jour et par salle |
| Contenus | 37 | 19 | Versions approuvées préchargées pour la page |
| Tableau de bord, toutes les routes | — | −8 requêtes par appel | Métriques d'API tenues seulement sur Redis (Memcached ou mémoire en test) ; le cache en base aurait ajouté des verrous à chaque requête |
| Lecteur : fichier principal | 703 ko | 311 ko | `pdf.js` chargé à la demande, préchargé avec empreinte SRI : intégrité et fonctionnement hors ligne conservés. Un module déjà chargé à la demande auparavant et non couvert par SRI l'est désormais |

Constats sans correction nécessaire :

- la lenteur locale (0,3 à 2 s par appel) vient du serveur PHP de développement, sans OPcache ; la production a OPcache sans revalidation et les caches de configuration, de routes et d'événements ;
- le message SQL brut n'apparaît qu'en mode débogage local ; la production renvoie le message générique.

## 13. Lot M1 « Conditions du pilote » : ce qui a pu être fait sur le poste (8 novembre)

| Action M1 | Résultat | Reste à faire |
|---|---|---|
| Exercice de restauration (CONT-01) | **Répétition locale réussie** avec `infra/restore-drill.ps1` : sauvegarde en 0,6 s, restauration en 5,7 s dans une base séparée, supprimée ensuite. Volumes identiques sur 9 tables (dont 447 entrées du journal), dernière écriture conservée, contrainte anti-chevauchement et protection du journal présentes, 29 fichiers sur 29 avec la bonne empreinte SHA-256. Copie temporaire supprimée | Restauration de **production** (sauvegarde complète + journaux de transactions archivés) sur la pile Docker cible, avec RPO (cible ≤ 1 h) et RTO mesurés : Docker absent du poste |
| Images Docker figées (prérequis n° 5) | `docker compose` exige désormais `SAIE_VERSION` et une image `nom:étiquette@sha256:…` pour PHP, Node, Nginx, PostgreSQL, Redis et ClamAV ; les Dockerfiles reçoivent leur image de base de la pile ; nouveau contrôle de pré-production « Images figées par version et empreinte », avec un test | Relever les empreintes des images validées (le poste n'a pas accès à Docker Hub) ; exécuter `dc config --quiet` sur le serveur |
| ClamAV réel (SEC-AV2) | Image ClamAV déjà prévue dans la pile ; le contrôle de pré-production bloque tant que l'antivirus de test est actif | Démarrer la pile sur le serveur de recette, puis déposer le fichier EICAR (rejet attendu) et un fichier sain (accepté) |
| Test d'intrusion (SEC-07) | Préparation : **aucune vulnérabilité connue** dans les dépendances (Composer, frontend, lecteur) ; contrôle de pré-production opérationnel, avec 9 blocages attendus sur un poste de développement | Commande auprès d'un prestataire indépendant ; rapport ASVS 5.0 niveau 2 |
| Terminaux et dalles pilotables (PLY-08, PLY-09) | — | Achat de matériel représentatif, puis pilote |

Constat sur le poste de développement (hors application) : la variable `NODE_TLS_REJECT_UNAUTHORIZED=0` y désactive la vérification des certificats HTTPS pour Node et npm. Elle est à retirer.

Tests : PHPUnit 233 / 233.

**Fiche d'intervention pour la DSI** : [docs/exploitation/fiche-intervention-recette.md](../exploitation/fiche-intervention-recette.md). Elle couvre les images figées, le preflight, ClamAV (seul, puis relié à l'application, puis par l'interface) et l'exercice de restauration de production dans un volume isolé, avec une grille de résultats. Procédure sur serveur vierge corrigée : les fichiers de la base extraits doivent appartenir à `postgres` avec des droits 700, faute de quoi PostgreSQL ne démarre pas.

**Constat corrigé (validé le 8 novembre)** : les fichiers (médias, documents) n'étaient sauvegardés qu'une fois par nuit, soit jusqu'à 24 h de perte contre une heure pour la base. `infra/backup/backup.sh` ajoute une copie horaire incrémentale (`files-current`), jamais purgée ; la supervision alerte si elle a plus de 2 h. Le même passage corrige un risque : la purge des 14 jours aurait effacé tout dossier ancien de `/backups`, y compris cette copie. Le script, réécrit en fonctions, a été éprouvé sur le poste (copie, mise à jour, conservation des fichiers supprimés, purge, état JSON) ; la sauvegarde complète reste à éprouver sur la pile Docker (fiche § 4).

## 14. Pré-audit OWASP ASVS 5.0 niveau 2 (8 novembre)

Revue interne avant le test d'intrusion, détaillée dans [11-PRE-AUDIT-ASVS-N2.md](11-PRE-AUDIT-ASVS-N2.md). Aucun constat de gravité élevée. 15 constats moyens et une vingtaine de faibles ; la plupart sont corrigés, avec 11 tests dédiés et de nouveaux contrôles de pré-production. PHPUnit 244 / 244.

Restent cinq décisions (contrôle des mots de passe divulgués, administration limitée à une structure, auto-approbation des réservations, désactivation de la double validation, cookie anti-CSRF) et des actions DSI sur le serveur (limites de taille de ClamAV, worker des conversions, journaux et sauvegardes hors site). **Le test d'intrusion indépendant (SEC-07) reste un prérequis de production.**
