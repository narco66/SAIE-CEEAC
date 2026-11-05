COMMISSION DE LA COMMUNAUTÉ ÉCONOMIQUE DES ÉTATS DE L'AFRIQUE CENTRALE (CEEAC)

# Cahier des charges fonctionnel et technique — SAIE-CEEAC

**Système d'affichage institutionnel électronique, de diffusion documentaire et de gestion des salles**

Application web React + TypeScript + Vite • API Laravel 13 • PostgreSQL 18 • Lecteurs kiosque autonomes

| Rubrique | Valeur |
| --- | --- |
| Référence | SAIE-CEEAC-CDC-FT |
| Version | 3.0 — version consolidée unique |
| Date | 3 novembre 2026 |
| Émetteur | Direction des Systèmes d'Information (DSI) — Projet SAIE-CEEAC |
| Statut | Projet de référence à valider avant contractualisation |
| Destinataires | Maîtrise d'ouvrage, DSI, Communication, Protocole, gestionnaires de salles, directions, équipe de réalisation |
| Remplace | Cahier des charges v1.0 (31/10/2026), Étude technique et financière v1.0 (31/10/2026), cahiers des charges v1.0, v2.0 et v2.1 (03/11/2026) |

## Préambule

### Objet du document

Le présent document est le **référentiel unique** du projet SAIE-CEEAC. Il définit les fonctions à développer, les règles de gestion, les interfaces, l'architecture, les exigences de sécurité et d'exploitation, le cadre budgétaire indicatif et les preuves attendues en recette. Il sert à consulter une équipe de réalisation, à cadrer le développement puis à vérifier la conformité de la solution livrée.

### Documents fusionnés

| Source | Date | Apport principal repris |
| --- | --- | --- |
| Étude technique et financière v1.0 | 31/10/2026 | Résumé exécutif, contexte, indicateurs, scénarios d'hébergement, enveloppe budgétaire, calendrier, recommandation |
| Cahier des charges v1.0 (.docx) | 31/10/2026 | Parties prenantes, cas d'utilisation, modules F01–F15, NF01–NF08, RT01–RT10, jalons J0–J5, garantie |
| Cahier des charges v1.0 et v2.0 (.md) | 03/11/2026 | Socle des exigences numérotées, catalogue des vues, PDF et parcours |
| Cahier des charges v2.1 (.md) | 03/11/2026 | 31 exigences issues de l'audit, six arbitrages Q1–Q6, version la plus récente |

La v2.1 sert de base, car elle contient toutes les exigences des versions précédentes. Le contenu propre aux documents du 31 octobre y a été intégré. Les écarts entre sources ont été résolus selon les harmonisations H1 à H14 de l'annexe D. **Aucune exigence des sources n'a été supprimée** ; l'annexe F donne la correspondance entre anciens et nouveaux identifiants.

### Conventions

- **Identifiants** : chaque exigence porte un code `FAMILLE-NN` (par exemple `SAL-05`), une priorité et un énoncé vérifiable. Les familles sont listées dans l'annexe E.
- **Priorités** : **P0** obligatoire pour la première mise en production (pilote inclus) ; **P1** après stabilisation (cible de généralisation) ; **P2** extension. Les priorités P1/P2/P3 du cahier du 31 octobre correspondent respectivement à P0/P1/P2 (H1).
- **Valeurs chiffrées** : délais, volumes, seuils et montants sont des **propositions** qui ne deviennent contractuelles qu'après validation explicite de la maîtrise d'ouvrage.
- **Vues et documents** : `V01–V42` vues web, `T01–T05` vues des terminaux, `PDF01–PDF10` documents générés, `P01–P09` parcours, `R01–R27` scénarios de recette, `UC01–UC08` cas d'utilisation.
- **Temps** : instants stockés en UTC, affichés dans le fuseau Africa/Libreville, horloges synchronisées par NTP.

### Sommaire

**Partie I — Cadrage**

1. Contexte, objectifs et résumé exécutif
2. Gouvernance et parties prenantes
3. Périmètre, lots et hypothèses
4. Acteurs et habilitations
5. Cas d'utilisation principaux

**Partie II — Exigences fonctionnelles**

6. Référentiels et administration
7. Médiathèque et documents
8. Publication et programmation
9. Lecteurs et affichage
10. Salles et réservations
11. Accueil, protocole et orientation
12. Notifications et tableaux de bord
13. Interfaces utilisateur et ergonomie

**Partie III — Exigences techniques**

14. Architecture applicative
15. Socle technique et déploiement
16. Modèle de données
17. Contrats des API
18. Sécurité et protection des données
19. Performances et continuité
20. Infrastructure, équipements et hébergement

**Partie IV — Réalisation, recette et exploitation**

21. Qualité et recette
22. Planning, jalons et livrables
23. Exploitation, maintenance et garantie
24. Estimation budgétaire indicative
25. Risques, décisions et recommandation

**Partie V — Spécifications de conception**

26. Maquette : livrables, navigation et fondations visuelles
27. Catalogue des vues web
28. Vues des terminaux
29. Documents PDF générés
30. Parcours cliquables et règles de formulaire
31. Jeu de données de la maquette
32. Matrice de couverture
33. Recette de la maquette et transmission au développement

**Annexes**

- A. Manifeste de diffusion minimal
- B. Scénario complet de bout en bout
- C. Dictionnaire des niveaux de preuve
- D. Décisions d'arbitrage et harmonisations de la fusion
- E. Index des exigences
- F. Correspondance avec les documents sources
- G. Références et glossaire
- H. Grille de validation du cahier des charges

# Partie I — Cadrage

## 1 Contexte, objectifs et résumé exécutif

### 1.1 Contexte et justification

L'information institutionnelle doit être diffusée de façon cohérente entre le Cabinet, le Secrétariat général, les départements, les directions, les halls et les salles de réunion du siège à Libreville. Les affiches papier, les transmissions informelles et les réservations de salles sans contrôle centralisé produisent des informations périmées, des diffusions non maîtrisées et des conflits d'occupation.

Le SAIE-CEEAC est une plateforme centralisée, développée sur mesure et déployée en priorité sur l'infrastructure de la Commission. Elle gère les contenus, leur validation, les calendriers de diffusion, les écrans, la bibliothèque documentaire et la réservation des salles. Les lecteurs restent autonomes en cas de coupure réseau. Elle ne remplace ni la messagerie officielle, ni la gestion électronique des documents (GED) réglementaire, ni les dispositifs d'alarme.

### 1.2 Résumé exécutif

- **Solution** : application web d'administration, API métier, lecteurs d'affichage kiosque, panneaux de salle et vue d'orientation, hébergés en local avec lecture hors ligne.
- **Périmètre physique confirmé** : rez-de-chaussée, six étages et autres emplacements à inventorier. Le nombre d'écrans et de salles reste à établir.
- **Hypothèse de cadrage** : dix points de diffusion au siège pour l'enveloppe budgétaire. L'architecture est dimensionnée pour 100 lecteurs sans refonte (H5).
- **Enveloppe indicative** : environ 39,5 M XAF hors traitement fiscal pour le scénario de dix écrans (chapitre 24). Ce montant n'est ni un devis ni une autorisation d'engagement.
- **Durée indicative** : 17 à 21 semaines après cadrage, avec un plan de référence de 20 semaines (chapitre 22).
- **Démarche recommandée** : valider le principe, faire un pilote de 2 à 3 écrans et un panneau de salle, puis arrêter le budget définitif (chapitre 25).

### 1.3 Objectifs et indicateurs de succès

**GOV-01** · P0 — Le succès du système est mesuré sur les trois premiers mois suivant la généralisation à l'aide des indicateurs ci-dessous. Chaque indicateur a une carte dans les tableaux de bord (TAB-01) avec sa période de référence ; les valeurs cibles sont confirmées au cadrage.

| Objectif | Indicateur | Cible proposée |
| --- | --- | --- |
| Diffusion contrôlée | Part des contenus ordinaires passés par le circuit de validation configuré | 100 % |
| Réactivité éditoriale | Délai médian entre le dépôt d'un contenu et sa première diffusion | < 2 jours ouvrés |
| Réactivité urgente | Alerte affichée sur les lecteurs connectés | ≤ 60 s sur ≥ 95 % des lecteurs |
| Fiabilité des réservations | Double réservation confirmée sur une même salle | 0 |
| Parc opérationnel | Lecteurs en contact avec le serveur pendant la plage de fonctionnement | ≥ 95 % à tout instant |
| Disponibilité | Disponibilité mensuelle du service central, hors maintenance approuvée | ≥ 99,5 % |
| Autonomie | Contenus synchronisés lisibles sans réseau | ≥ 24 h |
| Traçabilité | Actions de publication, validation, annulation et retrait journalisées | 100 % |

## 2 Gouvernance et parties prenantes

### 2.1 Parties prenantes et responsabilités

Ce tableau décrit les responsabilités organisationnelles. Les rôles applicatifs sont définis au chapitre 4.

| Partie prenante | Responsabilité |
| --- | --- |
| Maîtrise d'ouvrage (Secrétariat général ou autorité désignée) | Objectifs, arbitrages, autorité de validation institutionnelle, validation de la recette |
| Maîtrise d'œuvre technique (DSI) | Architecture, sécurité, hébergement, exploitation, pilotage de la recette technique |
| Service chargé de la communication | Ligne et charte éditoriales, modèles, qualité des contenus institutionnels |
| Protocole | Accueil des délégations, messages de bienvenue, drapeaux et cérémonies |
| Directions et départements | Production, revue et validation des contenus de leur ressort |
| Gestionnaires de salles et moyens généraux | Salles, équipements, prestations de réunion, installation électrique |
| Opérateurs SAIE (diffuseurs) | Programmation, affectation des écrans, support de premier niveau |
| Responsable de la sécurité des SI | Contrôles, revue des habilitations, incidents, audit, acceptation formelle des risques |
| Prestataire de réalisation | Conception, développement, tests, documentation, transfert |

Les responsabilités nominatives (propriétaire métier, validateurs par langue et suppléants) sont approuvées au jalon J0.

### 2.2 Principes de réalisation

- Le backend est la seule source de vérité des droits, états, validations et calendriers. Le frontend ne décide jamais seul d'une autorisation : les droits sont contrôlés côté serveur et pas seulement par le menu de l'interface.
- Les opérations métier sont transactionnelles, horodatées, attribuées à un acteur, auditables et vérifiables par API.
- Chaque contenu a un propriétaire, une classification, une durée de validité et un statut traçable.
- Les écrans publics ne reçoivent que des contenus explicitement autorisés pour leur zone.
- Aucune donnée de démonstration, aucun contenu figé et aucune fausse métrique en production.
- Le présent cahier repose sur le besoin exprimé ; aucun inventaire de l'infrastructure ni audit sur site n'a encore été réalisé.

### 2.3 Traçabilité des décisions et gestion des changements

Un registre consigne chaque décision : identifiant, question, options, décision, auteur, date et impact. Toute modification d'une exigence approuvée fait l'objet d'une analyse de coût, de délai, de sécurité et de recette, puis d'un arbitrage inscrit au registre des changements. Aucun état proposé dans ce document ne vaut approbation institutionnelle. Les décisions déjà rendues sont reprises dans l'annexe D.

## 3 Périmètre, lots et hypothèses

### 3.1 Lots et priorités

| Lot | Contenu | Priorité |
| --- | --- | --- |
| Socle | Comptes, droits, structures, lieux, journaux et configuration | P0 |
| Affichage | Médias, validation, campagnes, versions linguistiques, agenda des réunions, alertes, lecteur hors ligne, pilotage des dalles, supervision | P0 |
| Documents | Dépôt, versions, classement, recherche et diffusion contrôlée | P0 |
| Salles | Inventaire, réservation, approbation, conflits et panneaux | P0 |
| Exploitation | Alertes techniques, sauvegardes, tableaux de bord et recette | P0 |
| Intégrations et accueil | SSO, calendriers externes, annuaire, messagerie, API d'intégration SI, composants dynamiques (actualités, météo), notifications push, état physique des dalles, ressources mobiles, accueil des délégations et orientation des visiteurs, selon les accès disponibles | P1 |
| Extensions | OCR, signature électronique, analytique avancée, multisite, mode événement, diffusion documentaire par courriel, prestations de restauration | P2 |

### 3.2 Zones et catégories couvertes

- **Lieux** : halls d'accueil, circulations principales, paliers des étages, directions, zones événementielles et entrées des salles de réunion.
- **Utilisateurs** : contributeurs, validateurs, Communication, Protocole, responsables organiques, gestionnaires de salles, agents, administrateurs DSI, auditeurs et terminaux techniques.
- **Catégories de contenus** : annonces institutionnelles, accueil des délégations, agenda des réunions, informations RH diffusables, consignes de sécurité, actualités et indicateurs publics.

### 3.3 Hors périmètre initial

Remplacement d'une GED réglementaire, archivage probant, système d'alarme incendie certifié et pilotage des systèmes de sécurité, contrôle d'accès physique, vidéosurveillance, équipement audiovisuel complet des salles, gestion complète des visiteurs (pré-enregistrement, badges), diffusion de documents restreints sur les écrans collectifs et création d'un ERP. Les alertes affichées complètent les dispositifs de sécurité et ne remplacent jamais les alarmes réglementaires.

### 3.4 Hypothèses de travail

- Application mono-institution avec structures et sites paramétrables.
- Interface d'administration en français au lancement, avec un mécanisme d'internationalisation prévu pour l'anglais, le portugais et l'espagnol.
- Contenus diffusés sur les écrans multilingues dès le lancement, en français et en anglais (PUB-08). Le portugais et l'espagnol s'activent sans développement.
- Référence horaire d'affichage Africa/Libreville ; instants stockés en UTC.
- Le nombre d'écrans, les performances, le budget et les délais sont des objectifs de planification à confirmer. La DSI vérifie l'hébergement, le réseau, les onduleurs et les locaux avant tout achat.
- Chaque raccordement à un système tiers exige un inventaire des interfaces et l'autorisation de son responsable applicatif.
- La conformité juridique, fiscale et institutionnelle, y compris le traitement de la TVA, est validée par les services compétents.

## 4 Acteurs et habilitations

| Rôle applicatif | Pouvoirs | Limites |
| --- | --- | --- |
| Administrateur technique DSI | Comptes, paramètres, appareils, supervision, sauvegardes, plages de fonctionnement des écrans | Pas de validation éditoriale implicite |
| Administrateur fonctionnel | Référentiels, périmètres, habilitations déléguées ; avec le pouvoir « modèles » : création, duplication et archivage des modèles d'affichage (V15, V16) | Aucun accès automatique aux secrets techniques ; ne valide pas un contenu |
| Contributeur | Brouillons, dépôt, versions linguistiques et soumission | Publication interdite |
| Validateur éditorial | Approbation ou retour motivé, par version linguistique | Limité à son service, sa zone et sa langue |
| Diffuseur | Programmation des versions approuvées ; contenus de repli ; avec le pouvoir « urgence » : publication, prolongation et révocation des alertes (V23) | Ne modifie pas une version validée ; pouvoir « urgence » nominatif, daté et audité |
| Gestionnaire de salle | Validation, blocage, réaffectation, équipements ; déclaration manuelle d'une non-présentation ; avec le pouvoir « prestations » : traitement des demandes de son type (V32) | Salles affectées uniquement ; pouvoir « prestations » limité à un ou plusieurs types et aux données nécessaires |
| Agent | Consultation interne et demande de réservation | Ses demandes et les documents autorisés |
| Auditeur | Lecture des journaux et exports autorisés | Aucune modification |
| Lecteur technique | Manifeste et médias affectés, remontée de statut, captures de rendu | Aucun accès utilisateur ou documentaire général |

Le Protocole et la Communication utilisent les rôles Contributeur et Validateur éditorial avec un périmètre dédié (catégorie « Accueil des délégations », zones d'accueil). Le responsable sécurité des SI reçoit le rôle Auditeur et, si besoin, Administrateur technique. Aucun rôle supplémentaire n'est créé (décision Q1).

**HAB-01** · P0 — Combiner rôle, structure, site, zone, classification et propriété de l'objet pour chaque autorisation. Toute nouvelle permission est refusée par défaut. Un utilisateur peut cumuler des rôles dans la limite des règles de séparation (HAB-02). Les délégations ont un début, une fin et un périmètre. Une désactivation coupe les sessions et empêche tout nouveau traitement.

**HAB-02** · P0 — Un auteur ne valide pas sa propre publication. L'urgence suit une permission spéciale, avec justification et audit. Les administrateurs techniques ne peuvent pas contourner cette séparation depuis l'interface courante.

**HAB-03** · P0 — Les pouvoirs « urgence » (Diffuseur), « modèles » (Administrateur fonctionnel) et « prestations » (Gestionnaire de salle) sont des extensions nominatives d'un rôle existant. Ils sont attribués avec début, fin et périmètre, comme une délégation (HAB-01). Ils apparaissent comme colonnes distinctes dans la matrice V35 et dans la comparaison des droits effectifs.

**HAB-04** · P0 — Délai de validation paramétrable par type d'objet : 2 jours ouvrés pour un contenu, 24 heures pour une réservation à validation manuelle (aligné sur SAL-03). Un rappel automatique part à mi-délai. À l'échéance, la tâche est escaladée au suppléant désigné, avec notification des deux personnes et trace dans le journal. Aucune approbation automatique n'est possible. Une tâche sans suppléant est signalée dans le tableau de bord de l'administrateur fonctionnel.

## 5 Cas d'utilisation principaux

| Cas | Déroulé nominal | Exigences | Parcours |
| --- | --- | --- | --- |
| UC01 Préparer une annonce | Un contributeur crée une annonce classée « interne », choisit ses médias, saisit les versions française et anglaise, propose des dates et soumet. | PUB-01, PUB-08, DOC-04 | P01 |
| UC02 Valider | Un validateur habilité, distinct de l'auteur, examine le rendu par langue, approuve ou renvoie avec un motif. | HAB-02, HAB-04, PUB-02 | P01 |
| UC03 Programmer | Le diffuseur programme la version approuvée sur des écrans ou groupes de son périmètre ; les conflits sont affichés avant confirmation. | PUB-04, PUB-05 | P01 |
| UC04 Diffuser | Le lecteur récupère le manifeste signé, vérifie les empreintes, met en cache, bascule atomiquement et accuse réception. | PLY-03, PLY-04, PUB-07 | P06 |
| UC05 Alerter | Une alerte publiée par un titulaire du pouvoir « urgence » préempte le programme puis s'arrête à expiration ; chaque lecteur journalise réception et activation. | PUB-06, HAB-03 | P05 |
| UC06 Résister à une panne | Pendant une coupure réseau, le lecteur continue les contenus valides ; au retour, il se resynchronise sans doublon. | PLY-05, PLY-06 | P06 |
| UC07 Réserver une salle | Un agent réserve une salle ; la base de données interdit tout chevauchement ; le panneau et l'agenda des écrans sont mis à jour. | SAL-03 à SAL-09, PUB-09 | P03, P04 |
| UC08 Accueillir une délégation | Le Protocole crée un message de bienvenue lié à une réservation confirmée ; il est validé, affiché à l'accueil et retiré à la fin de la réunion. | ACC-02, PUB-01 | P09 |

# Partie II — Exigences fonctionnelles

## 6 Référentiels et administration

**REF-01** · P0 — Gérer les sites, bâtiments, niveaux, emplacements, zones de diffusion, structures (département, direction, service, avec hiérarchie et statut), catégories de contenus, langues, salles, équipements, ressources mobiles, types de prestation, périodes d'ouverture et jours non ouvrés. Chaque objet a un code unique et un statut actif. Les niveaux comprennent le RDC et les étages 1 à 6, sans limiter les extensions futures.

**REF-02** · P0 — Affecter chaque écran à un emplacement, une orientation, une résolution et une plage de fonctionnement (PLY-08). Distinguer l'écran physique, le lecteur et le panneau de salle. Un lecteur a un identifiant stable et sa propre identité technique ; il peut être activé ou révoqué. Les groupes peuvent croiser étage et usage ; les droits restent contrôlés sur chaque cible.

**REF-03** · P0 — Import CSV avec modèle téléchargeable, simulation préalable sans écriture, erreurs ligne par ligne et bilan. L'import est idempotent sur un code métier choisi et ne supprime jamais rien implicitement. Les référentiels utilisés sont désactivés plutôt que supprimés. Les secrets ne sont jamais exportés.

## 7 Médiathèque et documents

**DOC-01** · P0 — Déposer des PDF, des images JPEG/PNG/WebP, des vidéos MP4 et des fichiers bureautiques DOCX/PPTX/XLSX à télécharger. Les fichiers bureautiques ne sont jamais exécutés sur les lecteurs ; une prévisualisation PDF ou image et des miniatures sont produites côté serveur dans un environnement isolé. Codec vidéo cible : H.264 et AAC, testé sur le matériel retenu, avec transcodage si nécessaire. La diffusion sonore des vidéos suit PLY-10.

**DOC-02** · P0 — Contrôler l'extension, la signature MIME, la taille, l'analyse antivirus et l'intégrité SHA-256. Les états sont : quarantaine, analyse, prêt et rejeté. Aucun fichier non déclaré sain ne peut être publié. Limites initiales proposées : 50 Mo par document et 500 Mo par vidéo, configurables et vérifiées côté serveur. Des quotas de stockage par structure sont paramétrables et surveillés.

**DOC-03** · P0 — Conserver titre, résumé, catégorie, service propriétaire, auteur, langue, mots-clés, classification, version, dates de validité et date d'expiration. La recherche porte sur les métadonnées et sur le texte extrait des PDF textuels ; l'OCR des scans est en P2. Chaque version est immuable et garde le lien avec les publications qui l'utilisent.

**DOC-04** · P0 — Trois classifications : **public**, **interne** et **restreint**. Un contenu public peut être diffusé dans une zone publique après validation. Un contenu interne exige un terminal ou un utilisateur habilité. Un contenu restreint est exclu de tous les lecteurs collectifs ; un simple changement de groupe ne permet aucune dérogation. Les écrans des zones publiques n'acceptent que du public.

**DOC-05** · P0 — Un QR code public ouvre uniquement un contenu public. Un QR code interne redirige vers une page authentifiée et réévalue les droits à chaque accès. Aucun lien signé de longue durée n'est encodé dans un QR code visible. Une URL de stockage ne constitue jamais un droit d'accès.

**DOC-06** · P0 — La dépublication arrête la diffusion et invalide les manifestes concernés. La conservation des documents et des journaux suit la politique approuvée selon GOV-02. Proposition de départ : journaux 12 mois, corbeille documentaire 30 jours. Aucun effacement automatique n'a lieu avant validation de la politique et traitement des obligations de conservation.

**DOC-07** · P0 — Routes documentaires du contrat d'API : `GET /documents` (liste paginée dans le périmètre), `GET /documents/{id}` (fiche et versions), `GET /documents/{id}/versions/{v}/download` (téléchargement réautorisé à chaque appel, URL de stockage à durée très courte) et `GET /q/{code}` (résolution d'un QR code après contrôle de classification et d'authentification). Voir chapitre 17.

**DOC-08** · P1 — Accusé de lecture des circulaires internes : pour un document interne diffusé à une liste de structures, le propriétaire voit les agents destinataires ayant ouvert la version courante, avec la date de première ouverture. Aucun suivi nominatif pour un document public ; la liste n'est pas exportable hors du périmètre du propriétaire.

**DOC-09** · P2 — Diffusion par courriel d'un document publié à une liste de structures, via la file de NOT-02, avec un lien authentifié. Aucune pièce jointe n'est envoyée pour un document interne ou restreint.

## 8 Publication et programmation

### 8.1 Cycle éditorial

**PUB-01** · P0 — États : brouillon → soumis → approuvé → programmé → diffusé → expiré → archivé. Un refus ou un retour renvoie au brouillon avec motif ; la suspension et la dépublication retirent le contenu de la diffusion. Toute modification du texte, du média ou de la classification d'une version approuvée crée une révision qui doit être validée à nouveau. La programmation référence toujours une version approuvée immuable. Les délais et l'escalade suivent HAB-04. Un validateur peut réaffecter une tâche à un autre validateur habilité, avec trace.

**PUB-02** · P0 — Chaque transition conserve l'auteur, le rôle, la date serveur, le motif et la version. Le diffuseur peut suspendre une campagne ; la reprise réévalue l'approbation et les dates. Une transition invalide renvoie un conflit métier et ne modifie aucune donnée.

### 8.2 Composition et calendrier

**PUB-03** · P0 — Créer des modèles par orientation (16:9 paysage et portrait) avec zones texte, image, vidéo, horloge, agenda, bandeau et QR code. Prévisualiser à la résolution cible avec un rendu identique à celui du lecteur. Limiter polices et couleurs au thème validé. Interdire le HTML et le JavaScript arbitraires ainsi que les pages web tierces non autorisées. Un éditeur à composants structurés suffit en P0. La zone agenda suit PUB-09 ; les composants dynamiques suivent PUB-10.

**PUB-04** · P0 — Une campagne définit les cibles, les langues et leur ordre de rotation (PUB-08), la période, les jours, les créneaux, la durée des diapositives et la priorité. Les occurrences sont évaluées dans le fuseau du site. Une date de fin est obligatoire pour toute annonce temporaire ; l'expiration est automatique. Avant confirmation, la prévisualisation affiche la liste effective des écrans et les conflits.

**PUB-05** · P0 — Résolution déterministe de la programmation : alerte active autorisée d'abord, puis priorité numérique décroissante, puis date de début, puis identifiant. À priorité égale, une rotation ordonnée utilise la durée de chaque contenu. Une campagne expirée est exclue même si son média reste en cache. Le contenu de repli est public, validé et géré selon PUB-11.

**PUB-06** · P0 — Une alerte comporte un message court, une zone, un début, une expiration et un auteur titulaire du pouvoir « urgence » (HAB-03). Elle interrompt les campagnes ordinaires et peut être révoquée. Expiration obligatoire proposée à 30 minutes, prolongeable par action tracée. Chaque lecteur journalise la réception et l'activation de l'alerte. La diffusion vers un lecteur déconnecté ne peut pas être garantie ; l'interface le signale explicitement.

**PUB-07** · P0 — Distinguer les niveaux programmé, manifeste reçu, contenu chargé, lecture déclarée et, si PLY-09 est actif, écran visible (annexe C). Un accusé de téléchargement ne prouve ni qu'un écran est allumé, ni qu'une personne a vu le message. Les rapports indiquent le niveau de preuve et le dernier horodatage connu.

### 8.3 Multilinguisme et contenus enrichis

**PUB-08** · P0 — Un contenu porte une à quatre versions linguistiques (français, anglais, portugais, espagnol) qui partagent le même média, la même classification et la même expiration. Le français et l'anglais sont activés au lancement (décision Q2). Chaque version linguistique est soumise et validée séparément par un validateur habilité pour cette langue ; une campagne ne diffuse que les versions approuvées. La campagne définit l'ordre de rotation des langues par zone (proposé : français puis anglais). Une langue absente ou non approuvée est ignorée sans produire d'écran vide. Le lecteur recompose la mise en page par langue ; un débordement de texte bloque la validation de la version concernée.

**PUB-09** · P0 — La zone agenda d'un modèle est alimentée par les réservations confirmées du jour, filtrées selon le bâtiment ou le niveau de l'écran, avec heure de début, heure de fin, salle et objet. Une réunion confidentielle apparaît uniquement sous le libellé « Réservée » avec son créneau (SAL-09). Le composant est recompilé dans le manifeste à chaque création, modification ou annulation d'une réservation concernée. Hors ligne, le lecteur affiche la dernière version reçue avec sa date.

**PUB-10** · P1 — Composants dynamiques : heure serveur, météo de Libreville, actualités du site institutionnel de la Commission (flux RSS ou JSON) et compte à rebours d'un événement. Les sources sont inscrites dans une liste blanche administrée dans V39. Le serveur interroge les sources, met le résultat en cache et l'intègre au manifeste. Le lecteur ne contacte jamais une URL externe (SEC-02).

**PUB-11** · P1 — Bibliothèque de contenus de repli par zone, gérée dans V22. Avant l'activation d'un écran, sa zone doit avoir au moins un contenu de repli public et approuvé. Le repli est horodaté et expirable ; à son expiration, il est remplacé par un repli institutionnel neutre.

**PUB-12** · P2 — Mode événement : programme dédié qui bascule plusieurs zones pendant un sommet, un conseil des ministres ou une session statutaire. Date de fin obligatoire et retour automatique au programme ordinaire.

## 9 Lecteurs et affichage

**PLY-01** · P0 — Fournir une application React dédiée au mode kiosque, exécutée sur un lecteur administré (navigateur Chromium supporté, ou conteneur Electron ou équivalent) avec démarrage et redémarrage automatiques et un chien de garde (watchdog). Valider la mise en veille, la lecture automatique des vidéos, l'accélération graphique et la relance après coupure sur chaque famille de matériel. Prendre en charge le Full HD et la 4K, en portrait et en paysage.

**PLY-02** · P0 — Appairage par code à usage unique valable 10 minutes : un administrateur associe le terminal à un écran. Le jeton de longue durée est propre au terminal, renouvelable, révocable et stocké dans un magasin sécurisé quand le système le permet. Aucun jeton ou clé commune à tous les lecteurs. Un réappairage est requis après compromission ou réinitialisation.

**PLY-03** · P0 — Le backend produit un manifeste signé et versionné contenant la liste des ressources, les empreintes, la validité, le calendrier, les versions linguistiques, l'agenda et le contenu de repli (annexe A). Le lecteur télécharge et vérifie l'ensemble nécessaire, puis bascule atomiquement vers la nouvelle version. En cas d'échec, il conserve la version précédente encore valide. Il ne lance jamais un programme incomplet.

**PLY-04** · P0 — Cache local persistant (IndexedDB et Cache Storage), contrôle du quota et réserve proposée de 20 %. Le lecteur évince d'abord les ressources non référencées ; la capacité réelle doit être mesurée. Un agent natif de cache peut être nécessaire si le navigateur ne garantit pas la persistance ; ce choix est arrêté au pilote.

**PLY-05** · P0 — Hors ligne, le lecteur continue uniquement les contenus préchargés et encore valides. À l'expiration de leur autorisation de diffusion, il les retire et affiche le repli. Une révocation distante ne peut pas effacer immédiatement un cache hors ligne : durée maximale d'autorisation proposée de 24 heures pour le public et de 1 heure pour l'interne, à arbitrer. Aucun contenu restreint n'est mis en cache sur un lecteur collectif.

**PLY-06** · P0 — Heartbeat toutes les 30 secondes ; terminal « suspect » après 90 secondes et « indisponible » après 5 minutes sans contact. Le statut réseau est distinct de l'état physique de la dalle. Remonter version logicielle, stockage, erreurs, manifeste actif, dernière synchronisation, lecture en cours, écart d'horloge et, si PLY-09 est actif, état de la dalle. Les journaux différés sont dédoublonnés à la reconnexion et l'historique est conservé.

**PLY-07** · P0 — Synchronisation toutes les 30 secondes au plus, avec temporisation aléatoire et téléchargement conditionnel par ETag. Un WebSocket peut accélérer la propagation ; le polling reste le mécanisme de secours. Les mises à jour se font par groupes pilotes avec retour à la version précédente.

**PLY-08** · P0 — Plage de fonctionnement par écran ou par groupe : de 8 h à 18 h en semaine, éteint les jours non ouvrés du référentiel (décision Q4), avec dérogation possible par écran et motif. Hors plage, le lecteur éteint la dalle par HDMI-CEC, RS-232 ou API du constructeur quand le matériel le permet ; sinon, il affiche un écran noir et réduit sa synchronisation à une fois toutes les 5 minutes. Le rallumage a lieu 5 minutes avant l'ouverture pour permettre la synchronisation. La consommation électrique des deux modes est mesurée au pilote.

**PLY-09** · P1 — Retour d'état physique de la dalle (alimentation, entrée active, température, erreurs) par le canal de PLY-08. Cet état alimente le niveau de preuve « Écran visible » (annexe C). Sans retour matériel, les vues et rapports affichent « Non mesuré », jamais « Visible ».

**PLY-10** · P0 — Politique sonore : écrans muets par défaut. L'audio est autorisé à l'accueil (rez-de-chaussée) de 8 h à 18 h, avec un volume plafond paramétré par la DSI (décision Q5). Les autres emplacements restent muets, sauf dérogation tracée. Toute vidéo diffusée sur un écran muet porte des sous-titres dans la langue de la version linguistique ; la validation est bloquée sans sous-titres.

**PLY-11** · P1 — Capture à distance du rendu logiciel, à la demande depuis V08. La capture est conservée 7 jours, accessible selon les droits et sans aucune donnée confidentielle. Elle prouve le rendu du lecteur, pas l'allumage de la dalle.

**PLY-12** · P1 — Mise à jour applicative du lecteur par canal de version (pilote, stable), avec builds signés et vérifiés avant installation, déploiement par groupe et retour arrière en une action depuis V40.

## 10 Salles et réservations

### 10.1 Inventaire et disponibilité

**SAL-01** · P0 — Chaque salle a un code, un nom, un niveau, une capacité, une localisation, des photos, des équipements, des horaires, un gestionnaire et un statut. Paramétrer la durée minimale et maximale, le délai de réservation, le temps de préparation et la fermeture. Les indisponibilités techniques et institutionnelles bloquent la réservation.

**SAL-02** · P0 — Recherche par date, intervalle, capacité et équipement. Vues jour, semaine, mois et liste ; les créneaux affichés sont calculés avec les mêmes règles que la validation serveur. Les demandes non encore approuvées ne sont visibles que selon l'habilitation.

### 10.2 Règles et concurrence

**SAL-03** · P0 — États : brouillon, en attente, confirmée, refusée, annulée, en cours, terminée et non honorée. Une salle à validation manuelle bloque le créneau dès la soumission pendant un délai d'attente paramétrable (proposé : 24 heures). Une salle à validation automatique confirme immédiatement si les règles sont satisfaites. Le passage à « non honorée » suit SAL-12.

**SAL-04** · P0 — Intervalles semi-ouverts \[début, fin\[ : deux créneaux adjacents sont admis si les temps de préparation le permettent. L'intervalle bloquant inclut la préparation et la remise en état. Le nombre de participants est positif et ne dépasse pas la capacité, sauf dérogation motivée du gestionnaire.

**SAL-05** · P0 — Empêcher les chevauchements au niveau de PostgreSQL par une contrainte `EXCLUDE` GiST sur la salle et l'intervalle bloquant, pour les statuts actifs (extension `btree_gist`). La vérification applicative améliore le message mais ne remplace pas la contrainte. Deux validations simultanées ne peuvent jamais réussir sur le même créneau.

**SAL-06** · P0 — Réservations récurrentes bornées (proposé : 6 mois et 100 occurrences au maximum). Chaque occurrence est matérialisée dans une transaction. Par défaut, tout conflit rejette la série et renvoie les occurrences en conflit ; un mode partiel doit être choisi explicitement. Modifier une occurrence ou les suivantes conserve l'historique et recrée les blocages de façon atomique.

**SAL-07** · P0 — Une modification d'horaire, de salle ou de capacité relance les contrôles et, si nécessaire, la validation. En cas d'échec, la réservation précédente reste intacte. Les suppressions sont des annulations tracées. Un déplacement administratif exige un motif et la notification de l'organisateur.

### 10.3 Réunion et panneau de salle

**SAL-08** · P0 — Saisir l'objet, l'organisateur, les participants internes ou externes, la confidentialité et les prestations (interprétation, visioconférence, assistance, sonorisation). Une prestation crée une demande auprès du gestionnaire titulaire du pouvoir « prestations » pour ce type (HAB-03), avec un statut ; elle n'est jamais considérée comme satisfaite sans confirmation. Les participants externes sont informés du traitement de leurs données selon GOV-02.

**SAL-09** · P0 — Le panneau affiche : libre, occupée ou indisponible, heure de fin et prochaines disponibilités. Pour une réunion confidentielle, il affiche uniquement « Réservée » et le créneau ; ni titre, ni organisateur, ni participants ne figurent dans le manifeste du panneau. En cas de coupure réseau, il affiche « Statut non actualisé » avec la date ; aucune réservation hors ligne n'est acceptée.

**SAL-10** · P1 — Confirmation de présence par l'organisateur authentifié ou par code temporaire. Après un délai proposé de 15 minutes, la salle est libérée automatiquement, uniquement si cette règle est activée pour elle. Les réunions prioritaires peuvent être exemptées. Un panneau public ne permet pas d'annuler une réservation sans contrôle d'identité.

**SAL-11** · P1 — Synchronisation avec un calendrier externe via un connecteur adapté au fournisseur réellement utilisé. Une seule source de vérité par salle. Stocker l'identifiant externe, la version, le fuseau et l'état de synchronisation ; traiter les webhooks de façon idempotente, ainsi que les doublons et les conflits. Sans connecteur, fournir un export ICS sans prétendre à une synchronisation bidirectionnelle.

**SAL-12** · P0 — En P0, le statut « non honorée » est déclaré manuellement par le gestionnaire de la salle, après l'heure de début, avec un motif et une trace dans le journal (décision Q3). L'organisateur en est notifié. Le passage automatique attend l'activation de SAL-10. Tant que SAL-10 est inactif, V33 et PDF07 affichent « Présence non mesurée » et ne calculent aucun taux de présence.

**SAL-13** · P1 — Ressources mobiles réservables (matériel de visioconférence mobile, cabines d'interprétation, vidéoprojecteurs) avec code, type, disponibilité et gestionnaire. Une ressource est rattachée à une occurrence et soumise à la même contrainte d'exclusion que les salles (SAL-05).

**SAL-14** · P1 — Liste de contrôle d'ouverture et de remise en état d'une salle, liée à l'occurrence et imprimable dans PDF03. Les constats sont horodatés par le titulaire du pouvoir « prestations » et consultables par le gestionnaire.

**SAL-15** · P2 — Type de prestation « pause-café et restauration », avec nombre de participants et heure de service, traité comme toute prestation de SAL-08.

## 11 Accueil, protocole et orientation

**ACC-01** · P1 — La vue d'orientation T05 est produite par le même lecteur que T01 et T02, à partir d'un modèle dédié. Elle est générée depuis les référentiels de REF-01 sans saisie manuelle, respecte PLY-08 et PLY-10 et ne contient jamais de donnée confidentielle.

**ACC-02** · P1 — Un message de bienvenue est un contenu type créé en un clic depuis V28 pour une réservation confirmée et non confidentielle. Il comporte le nom de la délégation, les langues retenues, l'horaire, le lieu et, si le Protocole le valide, les drapeaux des États membres concernés, tirés d'une bibliothèque officielle validée par le Protocole. Il suit le circuit PUB-01 avec validation protocolaire, cible l'accueil et l'étage de la salle, et expire automatiquement à la fin de l'occurrence. Une réunion déplacée entraîne la recompilation du message.

**ACC-03** · P2 — La gestion complète des visiteurs (pré-enregistrement, badges, notification de l'hôte) est hors périmètre et fera l'objet d'une étude distincte. Le système expose seulement une API de lecture des réunions du jour autorisées, destinée à un futur système d'accueil.

## 12 Notifications et tableaux de bord

**NOT-01** · P0 — Notifications internes pour : soumission, retour, approbation, rappel et escalade de validation (HAB-04), expiration prochaine, réservation, déplacement, annulation, déclaration de non-présentation et incident. Chaque notification contient l'événement, la date, l'objet et un lien vers une cible dont les droits sont revérifiés à l'ouverture. Une notification n'étend jamais une habilitation.

**NOT-02** · P0 — Courriels via le SMTP institutionnel s'il est disponible, avec files de traitement, reprises exponentielles, limite de tentatives et file des échecs. Dédoublonner par événement, destinataire et canal. L'échec d'un courriel n'annule jamais une réservation déjà confirmée. Masquer les détails confidentiels dans les objets et aperçus.

**NOT-03** · P1 — Notifications push du navigateur (application web progressive) pour les agents sur mobile : confirmation ou refus d'une réservation, rappel 15 minutes avant une réunion, retour éditorial. Chaque canal est activable par l'utilisateur dans V03. Le contenu poussé ne contient aucun titre confidentiel.

**TAB-01** · P0 — Tableaux de bord adaptés au rôle : contenus à valider, validations en retard ou escaladées, campagnes en cours, contenus expirés, terminaux indisponibles et taux de lecteurs en ligne, réservations à traiter, prestations en attente, diffusion par structure, stockage et indicateurs de GOV-01. Les cartes ouvrent des listes filtrées. Tous les chiffres proviennent de la base et portent leur période de référence.

**TAB-02** · P0 — Export CSV et PDF des inventaires, réservations et bilans de diffusion selon les droits. Neutraliser les cellules CSV pouvant exécuter une formule. Distinguer l'occupation réservée de l'occupation réellement confirmée.

## 13 Interfaces utilisateur et ergonomie

| Écran | Contenu et actions attendus |
| --- | --- |
| Connexion et compte | Authentification, réinitialisation, MFA selon le rôle, sessions actives, canaux de notification |
| Accueil par rôle | Indicateurs, tâches, alertes et raccourcis, avec filtres conservés |
| Parc des écrans | Carte logique par bâtiment et niveau, liste, état, affectation, appairage, plage de fonctionnement, capture de rendu |
| Médiathèque | Recherche, versions, classification, analyse et aperçu |
| Éditeur et campagne | Composition, versions linguistiques, prévisualisation, cibles, calendrier, validation et suspension |
| Documents | Classement, recherche, téléchargement autorisé, accusés de lecture et historique |
| Salles et calendrier | Filtres, disponibilités, création, série, validation, ressources mobiles et prestations |
| Panneau et lecteur | Affichage kiosque, confidentialité, repli, agenda du jour, orientation et statut réseau |
| Administration | Rôles et pouvoirs, référentiels, imports, journaux, sources dynamiques et paramètres |
| Supervision | Incidents, files, sauvegardes, versions, état des dalles et diagnostics |

**UX-01** · P0 — Design sobre conforme à une charte validée, responsive pour l'administration sur ordinateur et tablette, consultation agent sur mobile. Libellés en français, formulaires utilisables au clavier, erreurs associées au champ et focus visible. Viser WCAG 2.2 niveau AA sur les parcours web, avec un rapport de vérification.

**UX-02** · P0 — Sur les écrans collectifs, tester la lisibilité à la distance réelle, le contraste, la taille et la densité, pour chaque langue diffusée. Ne jamais transmettre une information par la seule couleur. Prévoir des sous-titres pour les vidéos informatives (PLY-10). La durée d'une diapositive dépend de son volume de texte, avec un minimum proposé de 10 secondes.

**UX-03** · P0 — Chaque vue prévoit les états chargement, vide, erreur, accès interdit et déconnexion. Prévenir la sortie d'un formulaire contenant un brouillon non enregistré. Les listes sont paginées et filtrées côté serveur. Aucune donnée factice ni aucun chiffre codé en dur dans les vues livrées.

# Partie III — Exigences techniques

## 14 Architecture applicative

Architecture retenue : frontend React indépendant, backend Laravel exposant une API versionnée, base PostgreSQL. Un **monolithe modulaire Laravel** est recommandé au démarrage pour garder des transactions métier simples ; ce n'est pas un découpage en microservices. Les applications lecteur et panneau sont des clients distincts de la même API, avec des permissions spécifiques.

| Composant | Responsabilité et communications |
| --- | --- |
| Frontend d'administration | React, TypeScript, Vite ; HTTPS vers `/api/v1` ; aucune connexion directe à la base |
| Backend API | Laravel 13 : validation, policies, services métier, transactions, génération des manifestes, agrégation des sources dynamiques (PUB-10) |
| Workers et planificateur | Conversions, antivirus, notifications, rappels et escalades (HAB-04), expirations, compilation des programmes, collecte des sources dynamiques, exports |
| PostgreSQL 18 | Référentiels, états, droits, calendriers, contraintes et événements |
| Redis | Cache, limitation de débit, verrous temporaires et files de travaux ; jamais source de vérité métier |
| Stockage privé | Stockage objet compatible S3 ou volume local isolé : originaux, dérivés, empreintes, médias et captures ; accès contrôlé ou URL très courte après autorisation |
| Lecteur et panneau | Cache local, rendu multilingue, pilotage de la dalle, synchronisation et télémétrie via l'API technique |
| Proxy et supervision | TLS, routage, limites de taille, métriques, journaux et alertes |

**Flux de publication** : dépôt → quarantaine → analyse → conversion → validation par langue → programmation → compilation du manifeste signé → téléchargement vérifié via HTTPS → cache local → activation atomique → lecture (y compris hors ligne) → télémétrie. La programmation n'attend jamais le succès d'un courriel. Un événement métier persistant est écrit dans la même transaction que le changement d'état, puis distribué par un mécanisme *outbox*. Les écrans n'ont aucune connexion directe à PostgreSQL ni aux outils d'administration.

**Organisation du dépôt recommandée** : `/frontend`, `/backend`, `/player`, `/room-panel`, `/docs`, `/infra` et `/tests`. Les composants visuels peuvent être partagés entre clients ; les profils de compilation et les permissions restent distincts. Le contrat OpenAPI est versionné avec le code.

## 15 Socle technique et déploiement

| Couche | Choix et exigences |
| --- | --- |
| Backend | Laravel 13 ; PHP 8.3 minimum selon la documentation officielle ; PHP 8.4 proposé après validation des dépendances |
| Base | PostgreSQL 18 au dernier correctif de sécurité qualifié ; UTF-8, `timestamptz`, migrations versionnées |
| Frontend | React avec TypeScript et Vite ; versions stables compatibles figées dans le lockfile au démarrage |
| Environnement JS | Node.js LTS compatible avec la version de Vite retenue ; vérifier `engines` et figer la version en CI |
| Bibliothèques UI | React Router, client de requêtes (par exemple TanStack Query), formulaires typés et validation ; choix confirmé par prototype |
| Identité | Sanctum pour la SPA avec cookies sécurisés ; SSO OIDC/LDAP/AD en P1 si un annuaire est disponible |
| Services | Nginx ou équivalent, PHP-FPM, Redis, workers supervisés, ordonnanceur, antivirus, outil de conversion isolé |
| Qualité | Pest ou PHPUnit, tests React, Playwright, analyse statique PHP et TypeScript, formatage, audit des dépendances |

**TECH-01** · P0 — Documenter les extensions PHP exigées par Composer et par les fonctions retenues, notamment `pdo_pgsql`, `mbstring`, `openssl`, `intl`, `fileinfo` et `zip` si les traitements documentaires l'exigent. Le déploiement échoue explicitement si une extension obligatoire manque. Un package communautaire doit démontrer sa compatibilité avec Laravel 13 avant adoption.

**TECH-02** · P0 — Environnements de développement, d'intégration, de préproduction (recette) et de production séparés, sans aucun secret dans Git. Images ou paquets reproductibles, versions verrouillées et nomenclature des composants (SBOM). Services exécutés sans privilège root ; base jamais exposée à Internet.

**TECH-03** · P0 — Chaîne CI : installation reproductible, vérifications statiques, tests, build frontend, audit de sécurité, artefact signé ou contrôlé par empreinte, déploiement en préproduction puis recette. Mise en production après validation de la Commission, avec un plan de retour arrière testé.

**TECH-04** · P0 — Migrations selon la stratégie « ajout, puis transition, puis retrait » ; sauvegarde avant tout changement sensible. Un retour arrière applicatif ne rend pas réversible une migration destructive. Documenter la restauration et la compatibilité entre versions. Ne jamais lancer `migrate:fresh` ni un seeder destructeur en production.

Le cycle de vie des versions du socle et le calendrier des montées de version sont fixés par EXP-05 (chapitre 23).

## 16 Modèle de données

Les clés techniques sont des UUID ; les codes métier lisibles sont uniques dans leur périmètre. Toutes les tables métier ont `created_at`, `updated_at` et, si nécessaire, une version de concurrence. Les suppressions logiques ne remplacent pas une politique de conservation. Les contraintes entre tables et les index sont livrés avec les migrations.

| Entités | Champs principaux et relations |
| --- | --- |
| structures, users, roles, permissions, role_extensions | Hiérarchie organique ; affectations datées ; rôles et périmètres multiples ; pouvoirs « urgence », « modèles », « prestations » avec début, fin et périmètre (HAB-03) ; suppléant ; identité externe facultative |
| sites, buildings, floors, locations | Hiérarchie de localisation ; code unique par parent |
| display_zones, zone_locations | Zone de diffusion, classification maximale admise, emplacements rattachés |
| screens, devices, device_credentials | Localisation, orientation, résolution, appairage, secret haché, révocation, dernier contact, plage de fonctionnement, canal de pilotage, dernier état de dalle |
| screen_groups, group_members | Ciblage N à N ; contrôle sur chaque écran |
| assets, asset_versions | Propriétaire, classification ; clé de stockage privée, taille, MIME, SHA-256, sous-titres, miniatures, état antivirus ; quotas par structure |
| documents, document_versions, document_reads | Métadonnées, classement, lien vers la version de fichier, droits, accusés de lecture (DOC-08) |
| access_links | Code QR, cible, classification, expiration, compteur d'accès (DOC-05, DOC-07) |
| languages, content_categories, service_types, holidays | Langues, catégories de contenus, types de prestation, jours non ouvrés (REF-01) |
| templates, content_versions, content_languages | Structure JSON validée, version, rendu cible ; une ligne par version linguistique avec son état de validation (PUB-08) |
| campaigns, schedules, campaign_targets, campaign_languages | Version approuvée, priorité, occurrences, cibles et ordre de rotation des langues |
| alerts, alert_receipts | Message, zone, début, expiration, auteur, prolongations, révocation ; réception et activation par lecteur (PUB-06) |
| fallback_contents | Contenu de repli par zone, validité et expiration (PUB-11) |
| dynamic_sources, source_snapshots | Source en liste blanche, fréquence, dernier relevé et contenu en cache (PUB-10) |
| approvals, workflow_events | Objet, version, langue, acteur, décision, motif, échéance, escalade, réaffectation et horodatage |
| manifests, device_receipts, device_screenshots | Version, empreinte, validité, statuts téléchargé et activé ; captures de rendu horodatées (PLY-11) |
| device_telemetry | Heartbeats, version logicielle, stockage, erreurs, écart d'horloge, état de dalle ; agrégation et purge selon GOV-02 |
| rooms, room_equipment, room_blocks | Capacité, gestionnaire, équipements et fermetures |
| mobile_resources, resource_bookings | Ressource mobile, type, gestionnaire ; rattachement à une occurrence avec exclusion (SAL-13) |
| reservations, reservation_occurrences | Organisateur, confidentialité, série, intervalle, statut, déclaration de non-présentation |
| service_requests, checklist_items | Occurrence, type de prestation, responsable, statut ; constats d'ouverture et de remise en état (SAL-14) |
| notifications, delivery_attempts, push_subscriptions | Destinataire, événement, canal, tentatives, lecture, abonnements push (NOT-03) |
| audit_logs, outbox_events | Auteur, action, objet, corrélation, valeurs avant et après filtrées, distribution |

**Relations clés** : un bâtiment contient plusieurs niveaux. Un emplacement appartient à une zone de diffusion et accueille plusieurs écrans. Un écran a un seul lecteur actif à un instant donné. Un contenu a une à quatre versions linguistiques. Une campagne référence plusieurs cibles. Une réservation produit une ou plusieurs occurrences ; chaque occurrence concerne une seule salle et zéro ou plusieurs ressources mobiles. Les fichiers restent hors base ; seules leurs métadonnées et empreintes y sont stockées.

**DATA-01** · P0 — Index sur le statut et l'échéance des campagnes, la classification et le propriétaire des documents, la salle et les périodes de réservation, le `device_id` et la date de heartbeat, l'objet et la date des journaux, la zone et la validité des alertes. Pagination stable par date et UUID. Recherche plein texte PostgreSQL sur les documents autorisés, sans fuite de résultats ou de compteurs hors périmètre.

**DATA-02** · P0 — Contrôles SQL : fin après début, capacité positive, codes uniques, références obligatoires, une seule version active d'un appairage, exclusion des réservations concurrentes et des affectations concurrentes de ressources mobiles. Les clés étrangères bloquent les suppressions incohérentes. Les changements d'affectation utiles à l'audit sont historisés.

**DATA-03** · P0 — Toutes les entités du tableau sont livrées avec leurs migrations, index et contraintes dès le lot Socle, même lorsque la fonction qui les exploite est P1, pour éviter une migration structurelle après la mise en production. Le dictionnaire de données (LIV-01) décrit chaque colonne, sa classification et sa durée de conservation.

## 17 Contrats des API

**API-01** · P0 — Base `/api/v1`, JSON UTF-8, dates ISO 8601 avec fuseau, instants normalisés en UTC. Codes HTTP : 200/201/204 succès, 400 requête invalide, 401 non authentifié, 403 interdit, 404 objet absent ou inaccessible selon la politique, 409 conflit métier, 412 précondition échouée, 422 validation, 429 limitation. Ne jamais renvoyer de pile technique au client.

| Méthode et route | Contrat métier |
| --- | --- |
| `GET /me` | Identité, permissions effectives, pouvoirs étendus et périmètres |
| `GET POST /screens`, `/rooms` | Listes paginées et création contrôlée |
| `GET POST /screen-groups` | Groupes de diffusion et cibles effectives dédoublonnées |
| `POST /devices/pair`, `/devices/{id}/revoke` | Appairage à usage unique et révocation |
| `POST /devices/{id}/screenshot` | Demande de capture de rendu ; résultat asynchrone (PLY-11) |
| `POST /assets`, `GET /assets/{id}/status` | Dépôt en quarantaine et résultat du traitement |
| `GET POST /templates` | Modèles d'affichage, versions et archivage |
| `GET POST /contents`, `POST /contents/{id}/languages` | Contenus et versions linguistiques |
| `POST /contents/{id}/submit` | Soumission d'une version linguistique précise |
| `POST /contents/{id}/approve` | Décision par langue (approuver, retourner, réaffecter) avec version attendue et motif |
| `POST /campaigns`, `/campaigns/{id}/publish` | Cibles, langues, calendrier et publication atomique |
| `POST /campaigns/{id}/suspend` | Retrait avec motif et nouveau manifeste |
| `POST /alerts`, `/alerts/{id}/extend`, `/alerts/{id}/revoke` | Alerte sous pouvoir « urgence » ; prolongation et révocation tracées |
| `GET /documents`, `/documents/{id}`, `/documents/{id}/versions/{v}/download`, `/q/{code}` | Bibliothèque documentaire, téléchargement réautorisé, résolution de QR (DOC-07) |
| `GET /rooms/{id}/availability` | Intervalles et règles appliquées, sans titre confidentiel |
| `POST /reservations` | Création simple ou série, contrôle SQL et idempotence |
| `POST /reservations/{id}/approve`, `/cancel`, `/no-show` | Transition, motif et version attendue ; déclaration de non-présentation (SAL-12) |
| `GET /device/manifest` | Identité du terminal, ETag, manifeste minimal autorisé |
| `POST /device/heartbeat`, `/device/receipts` | Télémétrie, état de dalle, reçus dédoublonnés (y compris réception d'alerte) |
| `POST /imports/simulate`, `/imports/commit` | Simulation sans écriture puis confirmation idempotente (REF-03) |
| `GET POST /exports` | Demande et suivi des exports asynchrones (V41) |
| `GET /notifications`, `POST /notifications/{id}/read` | Périmètre autorisé, pagination et marquage lu |
| `GET /audit-logs` | Périmètre autorisé et pagination |

**API-02** · P0 — Réponse de liste : `data` et `meta` avec pagination. Erreur : code stable, message utilisateur, `errors` par champ et `request_id`. Aucune donnée confidentielle dans les erreurs. Documenter chaque schéma, permission, exemple et limite dans OpenAPI 3.1, avec versionnement des schémas et tests de contrat automatisés.

**API-03** · P0 — `POST /reservations` reçoit `room_id`, `start_at`, `end_at`, `confidentiality`, `subject`, `participant_count` et, en option, `recurrence` et `resource_ids`. Un chevauchement renvoie `409 BOOKING_CONFLICT` avec les créneaux indisponibles, sans exposer le titre d'un tiers. L'en-tête `Idempotency-Key`, lié à l'utilisateur et à l'empreinte du corps, empêche un double clic de créer deux demandes ; sa réutilisation avec un autre corps renvoie 409. Les autres POST sensibles (publication, alerte, appairage) sont également idempotents.

**API-04** · P0 — Modification avec `If-Match` ou `expected_version`. Une révision obsolète renvoie 409 ou 412 selon le contrat retenu et n'écrase jamais les modifications d'un autre utilisateur. Les clients permettent de recharger la version courante. Les fichiers volumineux peuvent être envoyés par morceaux, avec une finalisation contrôlée avant analyse.

**API-05** · P0 — La SPA utilise des cookies HttpOnly, Secure et SameSite adaptés au déploiement, une protection CSRF et une liste CORS explicite. Servir de préférence le frontend et l'API sous le même domaine avec un routage `/api`. Les terminaux utilisent des jetons de portée réduite. Aucun jeton utilisateur durable dans `localStorage`.

**API-06** · P0 — Toutes les routes du tableau font partie du contrat OpenAPI livré avec le lot Socle. Chaque route indique sa permission, son pouvoir étendu éventuel (HAB-03), ses codes d'erreur et un exemple. Une route P1 est documentée mais désactivée par configuration jusqu'à l'activation de sa fonction.

**API-07** · P1 — API d'intégration sécurisée pour l'agenda, l'intranet, les RH et d'autres applications de la Commission : comptes de service dédiés, portées minimales, limitation de débit, journalisation et contrat OpenAPI. Le SAIE n'accède jamais directement aux bases de données de systèmes tiers ; ceux-ci n'accèdent jamais directement à la sienne.

## 18 Sécurité et protection des données

**SEC-01** · P0 — TLS pour toutes les communications, terminaux compris. Accès d'administration limité au réseau institutionnel ou au VPN, selon décision de la DSI. Comptes individuels, MFA pour les administrateurs, les validateurs et les titulaires de pouvoirs étendus, limitation des tentatives, politique de session et de mot de passe, révocation immédiate des sessions. Le SSO n'est activé qu'après validation des attributs et du cycle de désactivation.

**SEC-02** · P0 — Policies Laravel sur chaque objet et chaque téléchargement, avec tests d'accès horizontaux et verticaux. Valider toutes les entrées, utiliser des requêtes paramétrées, assainir les textes enrichis, appliquer des en-têtes de sécurité et une politique CSP restrictive. Protéger contre CSRF, XSS et SSRF : interdire les URL libres côté conversion et côté sources dynamiques ; seules les sources de la liste blanche de PUB-10 sont interrogées, par le serveur uniquement.

**SEC-03** · P0 — Conversion documentaire dans un worker isolé sans accès réseau, avec plafonds de mémoire, de CPU et de durée (proposé : 120 secondes par fichier avant échec contrôlé). Contrôler les archives compressées, les quotas et les types réels ; aucun exécutable autorisé. Les originaux restent en stockage privé.

**SEC-04** · P0 — Journal en ajout seul au niveau applicatif : connexions, refus, changements de droits et de pouvoirs étendus, versions, validations, escalades, programmations, alertes, réservations, déclarations de non-présentation, téléchargements sensibles et actions techniques. Un rôle dédié lit les traces ; l'application courante ne peut pas les modifier, et les rédacteurs n'y ont aucun accès en écriture. Un export périodique protégé hors serveur est recommandé. Ne pas qualifier le journal d'« inviolable » sans mécanisme probant.

**SEC-05** · P0 — Ne jamais journaliser mots de passe, jetons, liens signés complets ou contenu intégral des documents. Minimiser les données des participants externes. Chiffrer sauvegardes et volumes selon l'environnement ; secrets dans un coffre dédié, avec rotation ; accès d'exploitation nominatifs. Les clés de chiffrement ne sont jamais stockées uniquement dans la sauvegarde chiffrée.

**SEC-06** · P0 — Séparer le réseau des lecteurs (VLAN dédié) de celui des serveurs et n'autoriser que les flux nécessaires, sortants depuis les lecteurs, y compris les flux de pilotage des dalles (PLY-08). Aucune exposition entrante des lecteurs. Désactiver la navigation libre, les ports inutiles et les comptes système par défaut ; durcir le mode kiosque. Un terminal compromis peut être révoqué sans affecter le reste du parc. Établir une procédure de perte, de vol et de remplacement.

**SEC-07** · P0 — Vérifier la sécurité selon le référentiel OWASP ASVS 5.0, niveau 2 au minimum pour les fonctions d'administration et d'API. Un test d'intrusion indépendant est réalisé avant la mise en production, puis après chaque changement majeur. Les vulnérabilités critiques ou élevées sont corrigées ou formellement acceptées par la DSI avant la mise en service (QUAL-02).

**GOV-02** · P0 — Cadre de protection des données : le traitement des données personnelles (participants internes et externes, journaux, télémétrie, accusés de lecture, abonnements push) est régi par les textes internes de la Commission de la CEEAC, en raison de son statut d'organisation internationale (décision Q6). La DSI tient un registre des traitements qui précise pour chacun la finalité, les catégories de données, les accès, la durée de conservation et la base interne applicable. Les participants externes sont informés par une mention affichée à la saisie et reprise dans toute convocation. Les durées de DOC-06 et SEC-04 sont fondées sur ce registre avant la mise en production. Si l'hébergement ou la sauvegarde sont confiés à un tiers, la convention précise que les textes internes de la Commission s'appliquent.

## 19 Performances et continuité

Les objectifs ci-dessous sont des critères proposés pour une charge de recette synthétique dimensionnée pour un siège complet. Ils ne décrivent pas l'inventaire réel de la CEEAC et sont à valider au cadrage. La capacité initiale budgétée est de 10 écrans ; l'architecture doit atteindre la charge de référence sans refonte fonctionnelle (H5).

| Indicateur | Cible proposée et conditions |
| --- | --- |
| Charge de référence | 100 lecteurs, 20 panneaux, 100 utilisateurs web simultanés, 50 salles et 10 000 documents |
| Latence API | p95 < 500 ms en lecture et < 1 s en écriture métier, hors envoi de fichiers, conversion et service externe |
| Parcours web | Vue utilisable en < 3 s au 95e centile sur le réseau local de référence ; mesurer cache froid et cache chaud |
| Propagation normale | Manifeste actualisé reçu en < 60 s par un terminal connecté ; médias lourds mesurés séparément |
| Agenda des réunions | Modification d'une réservation reflétée dans le manifeste des écrans concernés en < 2 minutes (PUB-09) |
| Alerte | Texte urgent reçu et activé en < 60 s sur 95 % des lecteurs connectés ; lecteurs hors ligne signalés |
| Disponibilité centrale | 99,5 % par mois pendant les heures de fonctionnement, hors maintenance approuvée ; mesure par sonde externe |
| RPO et RTO | Cible : RPO 1 heure et RTO 4 heures (archivage WAL, sauvegardes et exercice de restauration). Plancher : RPO 24 h et RTO 8 h, admis uniquement par décision formelle de la DSI (H3) |
| Hors ligne | Lecture locale jusqu'à expiration de l'autorisation ; test de coupure de 24 heures pour les contenus publics valides |
| Affichage | Full HD et 4K, portrait et paysage |

**PERF-01** · P0 — Séparer les téléchargements de médias des requêtes métier. Précharger et étaler les mises à jour pour éviter les pointes, en privilégiant la fenêtre de rallumage de PLY-08. Ordre de grandeur : 100 lecteurs qui téléchargent chacun 200 Mo représentent 20 Go ; un déploiement simultané doit être évité. Le dimensionnement dépend du débit réel et du renouvellement des médias.

**CONT-01** · P0 — Sauvegarde quotidienne, complète ou différentielle, documentée ; archivage WAL assez fréquent pour atteindre le RPO ; copie chiffrée sur un emplacement distinct et copie isolée selon la politique. Inclure la base, les fichiers, la configuration et la procédure de récupération des clés. Redis et les caches doivent pouvoir être reconstruits.

**CONT-02** · P0 — Restaurer périodiquement dans un environnement isolé et mesurer le RPO et le RTO : un exercice avant la mise en production, puis au moins un par trimestre. Désigner les responsables de l'alerte, du diagnostic, de la restauration et de la communication. Un serveur unique n'offre pas de haute disponibilité et constitue un risque explicite.

## 20 Infrastructure, équipements et hébergement

### 20.1 Scénarios d'hébergement

| Scénario | Avantages | Limites |
| --- | --- | --- |
| **Local (recommandé)** | Maîtrise des données, diffusion sans Internet, intégration au SI, conformité directe à GOV-02 | Plan de reprise, maintenance et compétences DSI nécessaires |
| Cloud | Mise en œuvre de l'infrastructure simplifiée | Dépendance à la connectivité, conditions contractuelles et localisation des données |
| Hybride | Sauvegardes externalisées et accès extérieur autorisé, moteur de diffusion local | Exploitation plus complexe, coûts potentiellement supérieurs |

Le scénario local suppose un réseau interne fiable et une exploitation assurée. La DSI arbitre après visite technique ; tout recours à un tiers respecte GOV-02.

### 20.2 Ressources de référence

| Ressource | Hypothèse initiale à mesurer au pilote |
| --- | --- |
| Application et workers | Machine virtuelle Linux de 4 à 8 vCPU, 16 Go de RAM, 150 Go SSD ; conversion vidéo à isoler si la charge est élevée |
| Base de données | Machine virtuelle de 4 vCPU, 16 Go de RAM, 200 Go SSD, supervision des IOPS, stockage évolutif |
| Stockage des médias | 500 Go utiles au départ, extensibles et sauvegardés séparément ; calculer originaux, versions linguistiques, dérivés et captures |
| Cache et files | Instance Redis dédiée ou isolée, mémoire suffisante, aucune éviction des files critiques |
| Écran professionnel | 43 à 55 pouces, 4K, usage 16 h ou 24 h selon le site, luminosité adaptée, fixation sécurisée, garantie adaptée ; port de pilotage HDMI-CEC, RS-232 ou réseau requis (PLY-08, PLY-09) ; haut-parleurs ou sortie audio à l'accueil (PLY-10) |
| Lecteur | Mini-PC ou lecteur dédié, 8 Go de RAM, 64 Go SSD, décodage matériel 1080p minimum (4K selon les écrans), Ethernet privilégié, redémarrage automatique, sortie CEC ou port série selon le canal retenu |
| Panneau de salle | Écran adapté à la distance de lecture ; tactile seulement si des actions sont autorisées ; alimentation et fixation prévues |
| Réseau et énergie | VLAN dédié, filtrage entre VLAN, points RJ45, NTP, protection électrique et onduleur dimensionné par bilan de charge tenant compte de la plage de fonctionnement |

**INF-01** · P0 — Le quantitatif final comprend écrans, lecteurs, panneaux, supports, câbles, prises, commutation, licences éventuelles, réseau, énergie et installation. Un écran par niveau ne suffit pas forcément : les sept niveaux sont des zones minimales d'étude, pas une commande de sept unités. Standardiser au plus deux modèles de lecteur et d'écran et prévoir des pièces de rechange.

**INF-02** · P0 — Relever pour chaque emplacement : plans, visibilité, ensoleillement, distance de lecture, orientation, flux de visiteurs, fixation, alimentation, réseau, ambiance sonore et accès pour la maintenance. Installer un prototype réel à l'accueil, à un étage et à l'entrée d'une salle avant la généralisation, avec un cycle complet d'extinction et de rallumage. Aucun engagement d'autonomie électrique sans mesure des puissances et du rendement des onduleurs. Le matériel acheté doit disposer d'un port de pilotage.

# Partie IV — Réalisation, recette et exploitation

## 21 Qualité et recette

La recette se déroule en préproduction avec des données fictives réalistes, des comptes distincts par rôle et des terminaux représentatifs. Chaque résultat indique l'exigence, les préconditions, les étapes, le résultat attendu, le résultat observé et la preuve. Une capture d'écran seule ne démontre ni une contrainte de concurrence ni une propriété de sécurité.

| Test | Exigences | Scénario et preuve attendue |
| --- | --- | --- |
| R01 | HAB, SEC | Un agent modifie l'UUID d'un document restreint, ou un utilisateur hors unité tente de publier dans une zone non autorisée : aucun contenu ni métadonnée ne fuit ; réponse conforme au contrat |
| R02 | PUB-01, HAB-02 | L'auteur soumet puis tente sa propre approbation : refus ; un validateur habilité approuve ; aucune annonce ordinaire ne paraît avant la dernière validation ; événements datés |
| R03 | DOC-02 | Fichier de test antivirus inoffensif prévu pour la recette : quarantaine puis rejet ; publication impossible |
| R04 | PUB-05 | Deux campagnes et une alerte sur une même cible : ordre déterministe, interruption puis reprise après expiration |
| R05 | PLY-03 | Coupure d'un téléchargement de média : programme précédent conservé ; aucune activation partielle |
| R06 | PLY-05 | Lecteur déconnecté : diffusion valide, retrait à expiration et repli ; statut central « indisponible » |
| R07 | SAL-05 | Deux requêtes parallèles sur la même salle et le même créneau : une seule réussite ; une erreur 409 documentée |
| R08 | SAL-04 | Créneaux adjacents avec puis sans temps de préparation : acceptation ou conflit conforme à l'intervalle bloquant |
| R09 | SAL-06 | Série de 10 occurrences dont une en conflit : aucune occurrence créée en mode « tout ou rien » |
| R10 | SAL-07 | Déplacement vers une salle occupée : erreur et réservation initiale préservée |
| R11 | SAL-09, PUB-09 | Réunion confidentielle : ni titre ni participants dans le panneau, l'API terminal, le cache, l'agenda des écrans et les journaux |
| R12 | API-03 | Même requête idempotente rejouée 5 fois : une seule réservation et une réponse stable |
| R13 | NOT-02 | SMTP arrêté : opération métier confirmée, reprise contrôlée et une seule notification finale |
| R14 | CONT-01, CONT-02 | Restauration de la base et des fichiers sur un environnement vide : cohérence vérifiée, RPO et RTO mesurés et documentés |
| R15 | PERF-01 | Charge de référence et diffusion sur un parc simulé de 100 lecteurs (10 lecteurs réels) : rapport p95, erreurs et limites, sans erreur bloquante |
| R16 | UX-01, UX-02 | Navigation au clavier, lecteurs d'écran sur les parcours clés, contraste, lisibilité sur site en français et en anglais |
| R17 | PLY-02, SEC-06 | Terminal révoqué : API refusée ; limite de retrait du cache hors ligne documentée |
| R18 | TECH-02 à TECH-04 | Déploiement reproductible depuis un dépôt vierge, migration, démarrage des workers et retour arrière |
| R19 | HAB-01, HAB-03 | Délégation et pouvoir « urgence » expirés la veille : toute action associée est refusée ; sessions ouvertes coupées ; trace datée |
| R20 | DOC-05, DOC-07 | Un agent scanne un QR interne, perd ses droits, rescanne : authentification puis refus ; aucun contenu en cache dans le navigateur |
| R21 | REF-03 | Même CSV de salles importé deux fois avec une ligne modifiée : aucun doublon, une mise à jour, bilan exact ; simulation sans écriture |
| R22 | NOT-01 | Notification reçue par un agent dont le périmètre a été réduit : l'ouverture du lien renvoie 403 ; aperçu sans donnée |
| R23 | DATA-01 | Recherche plein texte d'un mot présent uniquement dans des documents restreints : zéro résultat et compteur à zéro pour un agent non habilité |
| R24 | PUB-06, PUB-08 | Alerte prolongée au-delà de 30 minutes puis révoquée : chaque action tracée avec son auteur ; un contenu FR + EN tourne dans l'ordre configuré ; une version EN non approuvée est ignorée sans écran vide |
| R25 | PUB-04, PLY-08 | Campagne programmée de 10 h 00 à 10 h 30 : début et fin à la minute près (tolérance validée au cadrage) ; extinction et rallumage de la dalle conformes à la plage |
| R26 | PLY-01, PLY-06 | Redémarrage électrique du lecteur : reprise du programme sans intervention, journaux différés dédoublonnés |
| R27 | SEC-07 | Rapport ASVS niveau 2 et test d'intrusion : aucune vulnérabilité critique ou élevée non corrigée ou non acceptée formellement |

**QUAL-01** · P0 — Tests unitaires sur les règles de calendrier, les droits, les pouvoirs étendus et la rotation linguistique. Tests d'intégration sur un vrai PostgreSQL 18 pour les contraintes, les transactions et les fuseaux ; SQLite ne suffit pas pour valider `EXCLUDE` GiST. Tests API et de bout en bout sur les parcours critiques, exécutés en CI. Tests de charge, de coupure réseau et de cycle d'extinction obligatoires pour le lecteur.

**QUAL-02** · P0 — À la réception : aucun défaut bloquant ou critique ouvert, et aucune vulnérabilité critique ou élevée exploitable qui ne soit corrigée ou formellement acceptée par la DSI. Chaque anomalie restante a un responsable et une échéance. La maîtrise d'ouvrage signe la recette après vérification des exigences P0 ; chaque exigence P0 est couverte par au moins un scénario R01 à R27.

## 22 Planning, jalons et livrables

### 22.1 Phases et calendrier de référence

| Phase | Semaines | Produits et condition de sortie | Jalon |
| --- | --- | --- | --- |
| Cadrage et inventaire | S1–S2 | Cartographie des sites, inventaire, ateliers, règles arbitrées, gouvernance, grille de coût (EXP-06) et risques approuvés | **J0 Cadrage** |
| Conception détaillée et prototypes | S3–S5 | Maquettes par rôle et par langue (Partie V), modèle de données, OpenAPI, architecture de sécurité, registre des traitements (GOV-02), stratégie de tests, backlog validé | **J1 Conception** |
| Socle et affichage documentaire | S6–S11 | Authentification, droits et pouvoirs étendus, référentiels, CI, journaux ; dépôt, validation par langue, campagnes, alertes, agenda, lecteur et pilotage des dalles démontrés sur matériel pilote | **J2 MVP** : lots Socle, Affichage et Documents intégrés et testés |
| Salles et compléments | S12–S14 | Concurrence, confidentialité, séries, non-présentation manuelle, panneaux ; supervision, tableaux de bord | — |
| Sécurité et recette pilote | S15–S17 | Audit ASVS et test d'intrusion, essais réseau et énergie, cycle d'extinction, restauration, performances, PV pilote sur 2–3 écrans et un panneau | **J3 Pilote** : R01–R27 documentés |
| Généralisation et transfert | S18–S20 | Déploiement par zones, inventaire livré, formation complète, recette signée, indicateurs GOV-01 activés ; code, documentation, accès et sauvegardes transférés à la DSI | **J4 Généralisation**, **J5 Transfert** |

L'enveloppe indicative est de **17 à 21 semaines** après le cadrage ; le plan de référence ci-dessus en compte 20. Ce n'est pas un engagement de délai : les acquisitions, les accès aux fournisseurs d'identité et de calendrier, les traductions et la disponibilité des validateurs peuvent le modifier. Le prestataire propose un plan chiffré avec dépendances et ressources.

### 22.2 Conduite du projet

Méthode incrémentale avec démonstration à la fin de chaque sprint, dépôt de code contrôlé par la Commission, validation des maquettes avant développement, revue de sécurité et tests automatisés en CI. Environnements séparés (TECH-02). Toute modification d'exigence est arbitrée dans le registre des changements (§ 2.3).

### 22.3 Livrables contractuels

**LIV-01** · P0 — Livrer les sources complètes du frontend, du backend, du lecteur et du panneau dans un dépôt versionné, les licences, les scripts de build, les migrations non destructives et les procédures de retour arrière, des jeux de démonstration séparés, les contrats OpenAPI, les schémas et le dictionnaire de données, le dossier d'architecture générale et détaillée, la matrice des flux réseau et les schémas de déploiement, les maquettes validées, les scripts d'installation, la chaîne CI, la nomenclature des dépendances et des licences, ainsi que les rapports de tests, de performance et de sécurité.

**LIV-02** · P0 — Livrer les manuels administrateur, contributeur, validateur, agent et exploitant DSI, la procédure de publication, la procédure d'appairage, le client lecteur installable avec son image système de référence et sa procédure de mise à jour et de reprise, la procédure de pilotage des dalles, les procédures de sauvegarde et de restauration, d'exploitation des files, de gestion des incidents, de déploiement et de retour arrière, l'inventaire matériel, un dossier de configuration sans secrets et les procédures de remise des accès.

**LIV-03** · P0 — Les données de démonstration sont marquées comme telles et ne sont jamais injectées en production sans accord. Les comptes initiaux sont créés par une commande sécurisée, avec un mot de passe individuel à changer ; aucun mot de passe partagé n'est livré dans le code. Le dossier de transfert comprend l'inventaire des comptes techniques et un registre des secrets remis par un canal sûr. La Commission reçoit les accès, artefacts et connaissances nécessaires à une reprise par une autre équipe.

## 23 Exploitation, maintenance et garantie

**EXP-01** · P0 — Superviser : sondes HTTP, erreurs API, saturation disque, CPU, mémoire, connexions à la base, retard des files, échecs de conversion, échecs de collecte des sources dynamiques, expirations des certificats et fraîcheur des sauvegardes. Chaque alerte a un seuil, un destinataire responsable et une procédure. Les détails sensibles ne figurent jamais dans les notifications publiques.

**EXP-02** · P0 — Journaliser les déploiements, changements de configuration, mises à jour et opérations sur les terminaux. Les mises à jour de sécurité passent par la préproduction puis par un groupe pilote. Les navigateurs des lecteurs sont maintenus à jour : une version figée sans correctifs n'est pas une stratégie de stabilité.

**EXP-03** · P0 — Garantie corrective de **12 mois** après la réception définitive, puis contrat d'assistance reconductible. Délais de prise en charge proposés : incident critique (service indisponible ou diffusion abusive) sous 1 heure ouvrée avec point de situation ; majeur sous 4 heures ouvrées ; mineur sous 2 jours ouvrés. Ces délais concernent la prise en charge ; les délais de rétablissement, les astreintes hors horaires, les pénalités et les limites de responsabilité font l'objet d'un accord de service distinct.

**EXP-04** · P0 — Former séparément la DSI (administrateurs et exploitants), les contributeurs et validateurs, les gestionnaires de salles et les agents. Prévoir des exercices réels : publication multilingue, réservation, gestion d'un conflit, escalade de validation et incident lecteur. L'exploitation commence après le transfert des procédures et la vérification de leur exécution par la DSI.

**EXP-05** · P0 — Le cycle de vie des versions du socle est inscrit au contrat de maintenance. Laravel 13 reçoit des correctifs jusqu'au troisième trimestre 2027 et des correctifs de sécurité jusqu'au premier trimestre 2028. PostgreSQL 18 suit la politique de support de cinq ans de son éditeur. Node.js suit sa ligne LTS. Une montée de version majeure est planifiée chaque année, exécutée en préproduction puis sur un groupe pilote, avec un retour arrière testé. Aucun composant ne reste en production après la fin de son support de sécurité.

**EXP-06** · P1 — Grille de coût total sur cinq ans renseignée au cadrage : matériel (écrans, lecteurs, panneaux, supports), installation et câblage, licences éventuelles, énergie estimée selon la plage de PLY-08, hébergement ou infrastructure, maintenance applicative et matérielle, formation, montées de version. Elle part des hypothèses des chapitres 20 et 24 et est mise à jour après le pilote.

**GOV-03** · P1 — Conduite du changement : charte éditoriale validée par le service chargé de la communication, correspondants désignés par direction pour les contenus et les réservations, plan de communication interne au lancement, bilan d'adoption à trois mois présenté avec les indicateurs de GOV-01.

## 24 Estimation budgétaire indicative

### 24.1 Investissement — scénario 10 écrans

Ces montants sont des **hypothèses de cadrage** en francs CFA (XAF), non vérifiées par devis. Ils ne constituent ni une offre ferme ni une autorisation d'engagement.

| Poste | Base de calcul | Estimation (XAF) |
| --- | --- | --- |
| Écrans professionnels | 10 × 850 000 | 8 500 000 |
| Lecteurs | 10 × 180 000 | 1 800 000 |
| Supports, câblage et pose | 10 × 140 000 | 1 400 000 |
| Réseau et protections électriques complémentaires | Forfait | 1 800 000 |
| Infrastructure serveur et stockage (quote-part) | Forfait | 2 500 000 |
| Conception, développement et tests | Forfait | 14 000 000 |
| Intégration, déploiement, formation et documentation | Forfait | 3 500 000 |
| Audit de sécurité et recette | Forfait | 1 800 000 |
| **Sous-total** | | **35 300 000** |
| Provision pour aléas | 12 % | 4 236 000 |
| **Enveloppe indicative hors traitement fiscal** | | **39 536 000** |

### 24.2 Postes à chiffrer après inventaire

L'enveloppe ci-dessus date du 31 octobre et ne couvre pas encore les ajouts des versions 2.0 à 3.0. Les postes suivants sont à chiffrer après l'inventaire et la consultation des fournisseurs :

- panneaux de salle (nombre de salles à établir) et écran d'orientation de l'accueil ;
- surcoût éventuel des écrans dotés d'un port de pilotage (PLY-08, PLY-09) et de l'audio à l'accueil (PLY-10) ;
- effort de développement du multilinguisme, de l'agenda des réunions et des fonctions P1 (environ une semaine de plus selon § 22.1) ;
- traductions et sous-titrage des contenus ;
- test d'intrusion indépendant (SEC-07) s'il n'est pas couvert par le poste « Audit de sécurité » ;
- pièces de rechange (INF-01) et licences éventuelles.

### 24.3 Fiscalité et fonctionnement récurrent

La TVA et les droits ou taxes éventuels doivent être analysés ligne par ligne, en tenant compte des exonérations, du statut de l'institution et de la réglementation gabonaise ; aucun taux uniforme ne doit être appliqué sans validation. Le fonctionnement annuel récurrent est estimé de 3 à 6 M XAF (énergie, réseau, renouvellement, support, stockage, correctifs), à affiner dans la grille de coût sur cinq ans (EXP-06).

## 25 Risques, décisions et recommandation

### 25.1 Registre des risques

| Risque | Mesure | Responsable pressenti |
| --- | --- | --- |
| Réseau ou courant instable | Cache hors ligne, repli, Ethernet, VLAN, onduleurs, plage de fonctionnement, mesures de débit et bilan électrique | DSI, moyens généraux |
| Publication sensible au public | Circuit obligatoire, classification, séparation public/interne, contrôle des manifestes, tests négatifs | Métiers, DSI |
| Contenu obsolète | Date d'expiration obligatoire, propriétaire de contenu, alertes, composants dynamiques | Communication |
| Calendriers divergents | Source unique par salle, connecteur testé | Gestionnaires, DSI |
| Stockage saturé | Quotas, surveillance, politique de versions | DSI |
| Validation trop lente | Délégations datées, rappels et escalade (HAB-04) | Maîtrise d'ouvrage |
| Panne des lecteurs | Watchdog, supervision, image système reproductible, pièces de rechange | DSI |
| Pénurie de pièces, délais d'importation | Deux modèles standard au plus, stock de rechange | DSI, achats |
| Dérive fonctionnelle | MVP contractuel, registre des changements | Maîtrise d'ouvrage |
| Dépendance au prestataire | Sources, documentation, CI, exercice de reprise | DSI |
| Fausse preuve de diffusion | Séparer reçu technique, rendu logiciel et état physique (annexe C) | DSI, Communication |
| Traductions indisponibles | Rotation tolérante aux langues manquantes (PUB-08), correspondants linguistiques | Communication |
| Matériel sans port de pilotage | Exigence d'achat (INF-02), écran noir logiciel de repli | DSI |

### 25.2 Décisions

**Décisions rendues le 3 novembre 2026** (annexe D) : rôles fusionnés en pouvoirs étendus, multilinguisme P0 en français et en anglais, non-présentation déclarée manuellement, plage de 8 h à 18 h, audio à l'accueil sur la plage, cadre juridique interne.

**Décisions à prendre avant contractualisation** : confirmer le nom du système ; désigner le propriétaire métier, les validateurs par langue et leurs suppléants ; inventorier écrans et salles ; choisir l'hébergement ; fixer la classification par zone ; choisir l'annuaire et le calendrier éventuels ; valider le budget, la politique de conservation, les objectifs RPO/RTO et la maintenance ; approuver les durées d'autorisation hors ligne ; valider la tolérance de R25.

**Décisions à prendre avant le pilote** : matériel lecteur et navigateur, canal de pilotage des dalles, résolutions, formats vidéo, cache persistant, méthode de signalement de présence, règles de priorité et d'urgence, délais de validation des salles, bibliothèque de drapeaux et calendrier des formations.

### 25.3 Recommandation et décision attendue

Il est proposé à la maîtrise d'ouvrage :

1. de valider le principe d'un SAIE-CEEAC développé sur mesure et hébergé en local, sur la base du présent cahier des charges ;
2. de mandater la DSI pour l'étude d'implantation, l'inventaire et un pilote de 2 à 3 écrans et un panneau de salle ;
3. d'arrêter le budget définitif après inventaire, consultation des fournisseurs, validation des circuits éditoriaux et contrôle de sécurité.

L'enveloppe de dix écrans est indicative et ne vaut pas autorisation automatique d'engagement.

# Partie V — Spécifications de conception

## 26 Spécifications pour la maquette exhaustive

Les chapitres 26 à 33 complètent les parties I à IV sans en supprimer les exigences. Ils définissent les vues, interactions et documents nécessaires à une maquette navigable. Un prototype peut simuler des réponses ; l'application livrée utilisera exclusivement les API et la base. Les choix visuels ci-dessous sont des prescriptions de conception proposées à valider, pas une charte officielle déjà approuvée.

### 26.1 Livrables de conception attendus

Fournir un fichier de conception éditable avec pages Fondations, Composants, Administration, Agent, Lecteur, Panneau, Orientation, Documents PDF et Parcours. Chaque cadre porte son identifiant de vue, son rôle, son format, sa langue et son état. Fournir un prototype cliquable, les composants réutilisables, les variantes responsive, les règles de comportement et les exports des modèles PDF. Toute exigence possède un lien vers une vue ou vers une preuve technique si elle n'est pas visible.

Créer une matrice exigences → vues → composants → API → tests. Tous les identifiants de vues ci-après doivent apparaître dans l'inventaire de livraison. Une page uniquement décorative ne constitue pas une vue fonctionnelle. Les éléments non disponibles en P0 sont étiquetés P1 ou P2 dans les spécifications et ne doivent pas paraître opérationnels dans le parcours P0.

### 26.2 Navigation et structure globale

Menu administration : Accueil ; Mes tâches ; Affichage avec Écrans, Groupes, Modèles, Contenus, Campagnes, Alertes et Diffusion ; Documents avec Médiathèque et Documents ; Salles avec Calendrier, Réservations, Salles, Ressources et Prestations ; Rapports ; Administration avec Utilisateurs, Rôles, Référentiels, Imports, Journaux, Sources dynamiques et Paramètres ; Supervision. Un contributeur voit ses contenus et soumissions ; un validateur voit la file à valider pour ses langues ; un agent voit Accueil, Documents autorisés, Disponibilités, Mes réservations et Notifications. Un auditeur consulte les rapports et traces autorisés. Ne pas afficher un menu non habilité avec une page vide.

En-tête : logo fourni par la Commission ou emplacement neutre identifié, nom du produit, recherche autorisée, notifications, aide et menu du compte. Sous l'en-tête : fil d'Ariane, titre, description courte et action principale. Le menu indique la section active et peut être réduit. Les liens directs doivent fonctionner après reconnexion, sous réserve des droits.

### 26.3 Fondations visuelles

Maquette bureau à 1440 × 900 px, tablette 1024 × 768 px et mobile 390 × 844 px. Grille bureau 12 colonnes, gouttières 24 px, menu 248 px, en-tête 64 px ; espacement sur base 8 px. Police proposée Inter avec solution de repli système : corps 14 à 16 px, titres 24 à 32 px, libellés au moins 12 px. Ces dimensions sont indicatives et ne doivent pas empêcher zoom et adaptation.

Palette proposée : fond #F6F8FA, texte #182230, couleur principale #165A48, accent #B18A35. Les états succès, avertissement et erreur combinent icône, libellé et couleur ; vérifier le contraste avant validation. Éviter les dégradés décoratifs et les cadres surchargés. Les écrans publics utilisent une mise en page différente, optimisée pour lecture à distance.

Composants obligatoires : boutons principal et secondaire et destructif, champs et aide, listes déroulantes avec recherche, multisélection, sélecteur de période, sélecteur de langue, calendrier, tableau paginé, badge d'état, onglets, chronologie, aperçu fichier, dépôt, indicateur de progression, panneau latéral, boîte de confirmation, message d'erreur et notification. Prévoir les variantes actif, désactivé, chargement, focus et erreur.

## 27 Catalogue détaillé des vues

Convention commune à toutes les vues web : chargement explicite, état vide avec action autorisée, erreur avec réessai, accès interdit et session expirée. Les listes proposent recherche, filtres, tri stable, pagination et compteur dans le périmètre visible. Une action modifiant les données attend le résultat serveur avant de confirmer le succès ; conserver les données saisies en cas d'erreur. Les descriptions ci-dessous complètent ces règles communes.

| Vue | Route et profil | Structure et données | Actions et interactions | Règles et variantes |
| --- | --- | --- | --- | --- |
| V01 Connexion | /connexion • Tous | Identifiant professionnel, mot de passe masqué, afficher masquer, connexion et mot de passe oublié | Connexion vers V04 ; erreur générique sans révéler l'existence du compte ; lien expiré et session expirée | Aucun lien vers contenu privé avant authentification |
| V02 Réinitialisation et second facteur | /compte/verification • Utilisateur concerné | Adresse de récupération ; nouveau mot de passe et confirmation ; code MFA et code de secours sur étapes séparées | Confirmation neutre d'envoi ; lien invalide ; code incorrect ; tentatives limitées ; reprise du parcours | Ne jamais afficher de secret MFA existant |
| V03 Mon compte | /mon-compte • Utilisateur connecté | Identité, structure, langue, fuseau, sessions, préférences de notification et canal push | Modifier préférences ; changer mot de passe ; révoquer session ; inscrire MFA selon politique ; activer ou désactiver le push | Identité issue annuaire non modifiable si SSO |
| V04 Accueil par rôle | /accueil • Selon rôle | Indicateurs contextualisés dont GOV-01, tâches urgentes, validations escaladées, dernières actions et incidents selon droits | Carte ouvre liste filtrée ; période visible ; état sans activité et erreur partielle par widget | Agent : réservations et documents ; DSI : parc et incidents |
| V05 Mes tâches | /mes-taches • Contributeur validateur gestionnaire | Objet, type, langue, étape, échéance HAB-04, émetteur, date et rôle attendu | Ouvrir traitement ; filtrer en retard, escaladées et à traiter ; mise à jour après action | Aucune tâche déjà traitée dans la liste active |
| V06 Notifications | /notifications • Utilisateur connecté | Lu non lu, événement, objet, date, extrait et lien | Marquer lu ; ouvrir cible ; cible retirée et droits perdus gérés | Le marquage lu ne vaut pas validation métier |
| V07 Parc des écrans | /affichage/ecrans • DSI diffuseur | Code, bâtiment, étage, zone publique interne, groupe, orientation, plage de fonctionnement, dernier contact, état de dalle ou « Non mesuré » et manifeste | Filtres étage groupe état ; ouvrir V08 ; créer ; exporter inventaire | Distinguer contact lecteur et état physique écran |
| V08 Fiche écran | /affichage/ecrans/:id • DSI diffuseur | Onglets Identité, Lecteur, Programme, Fonctionnement, Historique ; localisation, résolution, cache, canal de pilotage, dernière capture de rendu | Modifier affectation ; modifier plage et dérogation ; demander une capture ; prévisualiser programme ; révoquer lecteur avec motif | Révocation précise que retrait hors ligne attend expiration |
| V09 Appairage terminal | /affichage/appairage • DSI | Code temporaire, terminal détecté, écran cible, orientation et résultat | Saisir code ; confirmer association ; traiter code expiré utilisé inconnu | Ne jamais afficher jeton durable dans la fiche |
| V10 Groupes de diffusion | /affichage/groupes • DSI diffuseur | Nom, périmètre, nombre de membres, liste des écrans et exclusions | Créer ; ajouter retirer ; prévisualiser les cibles effectives | Suppression interdite si campagne active dépendante sans résolution |
| V11 Médiathèque | /mediatheque • Contributeur diffuseur | Grille et liste : vignette, nom, type, poids, version, analyse, sous-titres et classification | Déposer ; filtrer ; ouvrir V12 ; sélectionner une version saine | États quarantaine conversion échec visibles séparément |
| V12 Fiche média et dépôt | /mediatheque/:id • Propriétaire habilité | Fichier, titre, langue, service, catégorie, classification, sous-titres et versions | Progression upload ; reprise ; validation MIME taille ; remplacement crée version ; dépôt de sous-titres | Aperçu indisponible tant que analyse non saine |
| V13 Bibliothèque documentaire | /documents • Agent habilité | Titre, référence, catégorie, service, version, date et classification | Recherche métadonnées et texte autorisé ; aperçu ; téléchargement | Résultats et compteurs filtrés selon droits |
| V14 Fiche document | /documents/:id • Agent ou propriétaire | Résumé, référence, propriétaire, dates, historique versions, droits et accusés de lecture P1 | Consulter version courante ; télécharger ; propriétaire ajoute révision ou dépublie | QR interne ouvre authentification ; version retirée explicitée |
| V15 Modèles affichage | /affichage/modeles • Administrateur fonctionnel | Vignettes paysage portrait, zones, compatibilité, langues supportées et version | Créer à partir modèle ; dupliquer ; prévisualiser ; archiver | Modèle utilisé reste disponible pour ses versions publiées |
| V16 Éditeur de modèle | /affichage/modeles/:id/edition • Administrateur fonctionnel | Canevas, palette composants dont agenda et composants dynamiques, calques et inspecteur dimensions polices couleurs | Ajouter déplacer redimensionner aligner ; annuler rétablir ; aperçu portrait paysage par langue | Grille et zones sûres ; aucun script libre |
| V17 Liste des contenus | /affichage/contenus • Contributeur validateur | Titre, type, auteur, langues et état par langue, version, statut, validité et cibles proposées | Créer ; filtrer par langue ; dupliquer ; ouvrir V18 ou V19 | Action approuver absente pour auteur |
| V18 Composition de contenu | /affichage/contenus/:id/edition • Contributeur | Étapes Informations, Composition, Langues, Vérification ; titre, résumé, classification et expiration ; un onglet par version linguistique | Choisir modèle média et QR ; saisir chaque langue ; aperçu par langue ; sauvegarder brouillon ; soumettre une ou plusieurs langues | Champs obligatoires et texte débordant signalés par langue ; révision après approbation |
| V19 Validation éditoriale | /affichage/contenus/:id/validation • Validateur distinct | Aperçu version figée par langue, modifications, métadonnées, cibles, échéance et chronologie | Approuver une langue ; retourner avec motif ; conflit de version et droits expirés | Motif obligatoire au retour ; auteur et validateur distincts ; validateur habilité pour la langue |
| V20 Campagnes | /affichage/campagnes • Diffuseur | Nom, priorité, langues et rotation, calendrier, état, cibles et taux de reçus | Créer ; filtrer ; ouvrir ; suspendre avec motif ; consulter rapport | Taux reçu ne représente pas audience humaine |
| V21 Assistant campagne | /affichage/campagnes/nouvelle • Diffuseur | Étapes Version approuvée, Langues, Cibles, Calendrier, Vérification ; priorité et répétition | Choix multiple écrans groupes ; ordre des langues ; aperçu cibles dédoublonnées ; conflits ; programmer | Empêcher campagne sans contenu approuvé, langue approuvée ou zone compatible |
| V22 Programme et prévisualisation | /affichage/programme • Diffuseur | Calendrier par écran groupe ; piste horaire ; version gagnante, langue affichée, contenu de repli par zone | Simuler date heure écran et langue ; ouvrir conflit et règle de priorité appliquée ; gérer la bibliothèque de repli | Afficher fuseau, plage de fonctionnement et validité des ressources |
| V23 Centre des alertes | /affichage/alertes • Diffuseur avec permission « urgence » | Message, sévérité, cibles, début, expiration, prolongations et motif | Aperçu ; confirmation publication ; révoquer ; prolonger avec audit | Indiquer lecteurs hors ligne, écrans hors plage et portée réelle |
| V24 Suivi diffusion | /affichage/diffusions/:id • Diffuseur DSI | Par terminal : programmé reçu téléchargé activé, rendu capturé, écran visible ou « Non mesuré », erreur et derniers horodatages | Filtrer incidents ; ouvrir terminal ; exporter bilan | Afficher clairement ce qui est déclaré par logiciel |
| V25 Calendrier des salles | /salles/calendrier • Agent gestionnaire | Jour semaine mois liste ; capacité équipement étage et date ; ressources mobiles P1 | Sélectionner créneau ; ouvrir V27 ; consulter détail selon confidentialité | État indisponible préparation réservé et libre différencié |
| V26 Mes réservations | /mes-reservations • Organisateur | Référence, salle, créneau, statut, confidentialité et prestations | Ouvrir ; modifier ; annuler ; filtrer passées futures et récurrentes | Actions conditionnées au statut et au périmètre |
| V27 Nouvelle réservation | /reservations/nouvelle • Agent | Salle, objet, début fin, participants, confidentialité, récurrence, prestations, ressources mobiles P1 ; mention d'information des participants externes | Vérifier disponibilité ; aperçu occurrences ; soumettre ; résultat et conflits | Capacité date et tampons contrôlés ; garder saisie lors conflit |
| V28 Détail réservation | /reservations/:id • Organisateur gestionnaire | Résumé, occurrences, participants, prestations, ressources et historique | Modifier occurrence ou futures ; annuler avec portée ; imprimer confirmation ; gestionnaire déclare non honorée avec motif | Échec modification préserve réservation initiale |
| V29 Validation réservation | /reservations/a-valider • Gestionnaire | Demandes, ancienneté, échéance de blocage, échéance HAB-04 et détails | Approuver ; refuser avec motif ; déplacer avec contrôle et notification | Une confirmation concurrente perdante affiche conflit serveur |
| V30 Catalogue des salles | /salles • Agent DSI gestionnaire | Cartes et liste : photo, étage, capacité, équipements et disponibilité | Consulter V31 ; filtrer ; réserver ; administrer selon rôle | La disponibilité est datée et non une propriété permanente |
| V31 Fiche et édition salle | /salles/:id • Agent ou gestionnaire | Onglets Présentation, Disponibilités, Équipements, Règles, Fermetures et Listes de contrôle P1 | Modifier horaires tampons capacité ; bloquer période ; aperçu panneau | Réduction capacité signale réservations futures affectées |
| V32 Prestations de réunion | /prestations • Gestionnaire avec pouvoir « prestations » | Réunion autorisée, type besoin, échéance, responsable, état et liste de contrôle P1 | Accepter ; demander précision ; déclarer prêt ou impossible ; notifier ; horodater les constats | Voir seulement les données nécessaires à la prestation et au type attribué |
| V33 Rapports | /rapports • Responsable habilité | Période, périmètre, occupation réservée, présences si SAL-10 activé sinon « Présence non mesurée », indicateurs GOV-01, diffusion et incidents | Filtrer ; consulter détail ; générer PDF ou CSV ; suivre export | Aucun taux de présence fictif en P0 |
| V34 Utilisateurs et affectations | /administration/utilisateurs • Administrateur | Identité, structure, rôles, pouvoirs étendus, suppléant, statut et source locale annuaire | Créer ; affecter périmètre ; désigner suppléant ; désactiver ; révoquer sessions | Mot de passe temporaire délivré par canal sécurisé |
| V35 Rôles et délégations | /administration/roles • Administrateur | Matrice permissions actions ressources ; colonnes des pouvoirs « urgence », « modèles », « prestations » ; portée ; début fin délégation | Comparer droits effectifs ; enregistrer après confirmation ; consulter historique | Prévenir escalade hors pouvoir de délégation |
| V36 Référentiels | /administration/referentiels • Administrateur fonctionnel | Arbre site bâtiment niveau emplacement ; zones de diffusion ; catégories, langues, équipements, types de prestation, jours non ouvrés | Ajouter modifier désactiver ; contrôler unicité et références utilisées | RDC et six étages présents dans jeu pilote |
| V37 Imports | /administration/imports • Administrateur habilité | Type, modèle CSV, dépôt, simulation, lignes valides invalides et bilan | Télécharger modèle ; simuler ; corriger ; confirmer ; exporter erreurs | Aucune écriture lors simulation ; doublons explicités |
| V38 Journal et traçabilité | /administration/journaux • Auditeur DSI | Date, acteur, rôle, pouvoir étendu, action, objet, version, langue, motif et corrélation | Filtrer ; ouvrir événement ; exporter selon permission | Secrets et données sensibles masqués ; aucune édition |
| V39 Paramètres | /administration/parametres • DSI administrateur | Onglets Identité, Diffusion, Fonctionnement et audio, Salles, Conservation, Canaux, Sources dynamiques P1 et Intégrations | Modifier valeur autorisée ; tester connexion ou source ; voir résultat masqué | Changement sensible confirmé ; secrets écrits sans relecture |
| V40 Supervision et incidents | /supervision • DSI | Santé API base files conversions stockage lecteurs dalles sauvegardes collecte des sources ; incidents ; canaux de version du lecteur P1 | Ouvrir diagnostic ; assigner acquitter clore incident ; consulter procédure ; déployer ou revenir en arrière sur un groupe | Indiquer date du dernier contrôle et absence de mesure |
| V41 Exports et traitements | /traitements • Utilisateur habilité | Type, demandeur, date, progression, résultat, expiration et erreur | Télécharger si droits encore présents ; relancer si échec ; annuler attente | Lien expiré et export supprimé gérés ; pas de fichier public |
| V42 Aide et accès indisponible | /aide • Tous selon contexte | Guides par rôle ; pages 403 404 erreur serveur session expirée | Retour accueil ; réessayer ; copier identifiant de diagnostic non sensible | Aucune pile technique ni existence de document caché |

## 28 Vues des terminaux

### T01 Lecteur paysage

Cadre 1920 × 1080 px ; bandeau institutionnel 120 px, zone centrale 840 px et pied 120 px comme base à adapter. Afficher titre, corps, visuel, date, agenda du jour et QR selon modèle, dans la langue de la version en cours de rotation avec indicateur discret de langue. Réserver une marge sûre de 5 %. Prévoir états diffusion normale, alerte plein écran, téléchargement initial, cache vide, contenu expiré, panne réseau, hors plage de fonctionnement et appairage. Aucun contrôle d'administration visible au public.

### T02 Lecteur portrait

Cadre 1080 × 1920 px ; mêmes informations avec empilement vertical et QR lisible. Interdire simple étirement du modèle paysage. Le texte est recomposé par langue ; si débordement, bloquer validation de la version concernée ou créer plusieurs diapositives. Tester le rendu aux résolutions réellement retenues.

### T03 Panneau de salle

Cadre paysage 1280 × 800 px proposé, avec variante portrait. Nom et capacité, heure serveur, état en texte et couleur, réunion autorisée, fin, prochaine disponibilité et prochaines réservations. Confidentiel : seulement Réservée et créneau. Hors ligne : dernier contact et Statut non actualisé. Réservation rapide et confirmation de présence sont des variantes P1 avec authentification ; aucune annulation anonyme.

### T04 Appairage et maintenance

Écran non appairé avec code temporaire, expiration et instructions pour administrateur ; code renouvelable sans exposer secret durable. Variante terminal révoqué avec message neutre. Diagnostic technique accessible seulement à un opérateur authentifié, jamais par raccourci public non protégé.

### T05 Orientation (P1)

Cadre paysage ou portrait selon l'emplacement, destiné à l'accueil et aux paliers. Directions et services par étage, plan de niveau schématique, salles du niveau et réunions du jour autorisées (PUB-09), en français et en anglais alternés. Généré depuis les référentiels de REF-01 sans saisie manuelle ; aucun nom de personne sauf validation explicite par le service chargé de la communication. Variante « Bienvenue » (ACC-02) affichant le nom de la délégation attendue, issu d'une réservation confirmée non confidentielle, avec expiration automatique à la fin de la réunion. États : normal, sans réunion, hors ligne avec date, hors plage.

Les règles de production de T05 et du message de bienvenue sont fixées par ACC-01 à ACC-03 (chapitre 11).

## 29 Spécifications des documents PDF

Les PDF ci-dessous sont les documents générés par la future application. Ils doivent être maquettés séparément du présent cahier. Format A4 portrait par défaut, paysage seulement pour tableaux larges ; marges 15 à 20 mm ; police incorporable, corps 10 à 11 pt minimum. En-tête : identité CEEAC validée, titre, référence ; pied : pagination n sur total, date de génération et classification. Aucune signature, cachet ou approbation officielle inventée. Les logos et mentions sont fournis puis validés.

Règles communes : mêmes filtres et périmètres que la vue source ; données figées à l'instant de génération ; mention du fuseau ; tableaux avec en-têtes répétés ; lignes non tronquées ; titres solidaires du paragraphe ; états vides et pagination testés. Une pièce confidentielle ne devient pas publique lors de son export. Les fichiers sont privés et leur téléchargement réautorisé. Prévoir Aperçu, Générer, Traitement, Prêt, Échec et Expiré dans V41.

| PDF | Origine | Contenu et règles |
| --- | --- | --- |
| PDF01 Confirmation de réservation | V28 | Référence, statut, organisateur autorisé, salle, étage, début fin, fuseau, capacité, participants en nombre, confidentialité, équipements, ressources mobiles et prestations confirmées ou en attente ; mention d'information des participants externes (GOV-02). Historique synthétique des validations. Une réservation en attente porte Demande non confirmée. QR vers fiche authentifiée et jamais vers les données brutes |
| PDF02 Planning des salles | V25 | Date et filtres, salles, créneaux, temps de préparation et indisponibilités. Version publique sans titres sensibles ni organisateurs ; version interne selon droits. Tableau quotidien ou hebdomadaire ; A4 paysage si nécessaire. Réunions confidentielles libellées Réservée |
| PDF03 Fiche de préparation de réunion | V32 | Référence, lieu, créneau et temps de montage, capacité attendue, matériel, interprétation, visioconférence, ressources mobiles et tâches assignées. Tableau prestation responsable échéance statut ; liste de contrôle d'ouverture et de remise en état si SAL-14 est activé. Aucun lien de réunion privé dans une version destinée à affichage |
| PDF04 Bon de programmation de campagne | V21 V24 | Version de contenu et langues, auteur, validateur par langue, date de décision, classification, cibles, créneaux, fuseau, priorité, ordre de rotation et date de fin. Miniatures lisibles par langue et liste détaillée des écrans en annexe. Le titre Bon ne constitue pas une signature électronique |
| PDF05 Rapport de diffusion | V24 V33 | Période, campagne et version, cibles prévues, reçus, activations déclarées, captures disponibles, états de dalle ou Non mesuré, absences de contact et incidents. Tableau écran horodatage statut erreur. Définitions des métriques et limites de preuve ; aucune estimation du nombre de personnes ayant lu |
| PDF06 Inventaire des équipements | V07 V30 | Code, type, emplacement, groupe, orientation, résolution, lecteur, version, plage de fonctionnement, canal de pilotage et dernier contact ; pour salles capacité, équipements et ressources mobiles. Séparer fiche technique et inventaire synthétique ; exclure jetons secrets et paramètres réseau sensibles du rapport général |
| PDF07 Bilan utilisation des salles | V33 | Période, horaires d'ouverture retenus, fermetures exclues, heures réservées, annulations, non-présentations déclarées et prestations. Taux réservé égale minutes réservées confirmées sur minutes disponibles après fermetures, avec règles de dédoublement. Présence réelle affichée seulement si SAL-10 est activé ; sinon Présence non mesurée |
| PDF08 Rapport incidents et interventions | V40 | Référence, détection, équipement, impact, responsable, événements, actions, rétablissement et clôture. Distinguer durée d'indisponibilité mesurée et délai d'intervention déclaré. Masquer secrets et données de diagnostic sensibles |
| PDF09 Extrait du journal | V38 | Filtres, période, auteur de l'export, date serveur, acteur, rôle, pouvoir étendu, objet, action et motif autorisés. Mention Extrait et non journal intégral. Volume limité ou export asynchrone ; données sensibles filtrées et action d'export elle-même journalisée |
| PDF10 Fiche documentaire et historique | V14 | Référence, titre, propriétaire, classification, version courante, dates, résumé et historique des versions et décisions autorisées ; synthèse des accusés de lecture si DOC-08 est activé. Ne pas fusionner automatiquement un original bureautique en PDF signé ; la conversion est une copie de consultation identifiée |

## 30 Parcours cliquables et règles de formulaire

| Parcours | Enchaînement | Variantes |
| --- | --- | --- |
| P01 Publier une annonce | V04 → V17 → V18 (FR puis EN) → V19 par langue → V21 → V22 → V24 → T01 | Deux comptes distincts pour auteur et validateur ; retour motivé sur une langue, révision puis approbation ; version EN non approuvée ignorée en rotation. Générer PDF04 et PDF05 |
| P02 Consulter un document | V13 → V14 → téléchargement autorisé | QR interne vers V01 puis retour V14 ; droits perdus avant téléchargement ; document retiré |
| P03 Réserver une salle | V25 → V27 → V28 → V29 si validation manuelle → V06 → PDF01 | Collision concurrente, refus, déplacement, annulation, escalade HAB-04 au suppléant ; dates conservées dans le formulaire |
| P04 Réserver une série | V27 → aperçu des occurrences → conflits → correction → confirmation → V28 | Modifier une seule occurrence puis toutes les futures ; ne pas annuler silencieusement les anciennes |
| P05 Diffuser une urgence | V23 → aperçu et confirmation → T01/T02 → V24 → prolongation tracée → révocation ou expiration → reprise du programme normal | Lecteur hors ligne et écran hors plage signalés sans fausse confirmation |
| P06 Installer un lecteur | V07 → V08 → V09 → T04 → T01 → V40 | Code expiré, échec média, saturation cache, coupure réseau, redémarrage, extinction et rallumage programmés, révocation |
| P07 Préparer une réunion | V28 → V32 → confirmation des prestations → PDF03 → T03 → agenda T01 (PUB-09) | Réunion confidentielle masquée dans panneau, agenda et planning public ; déclaration de non-présentation par le gestionnaire |
| P08 Administrer et auditer | V34 → V35 (pouvoirs étendus) → confirmation droits → V38 → PDF09 | Délégation ou pouvoir expiré, tentative interdite et désactivation avec révocation des sessions |
| P09 Accueillir une délégation (P1) | V28 → création du message de bienvenue → V19 → T05 à l'accueil et à l'étage → expiration à la fin de la réunion | Réservation confidentielle : aucun message possible ; réunion déplacée : message recompilé |

Champs requis : symbole visuel plus libellé accessible ; validation immédiate des formats et validation métier à la soumission. Réservation : salle, début, fin, objet interne et confidentialité obligatoires ; campagne : version approuvée, au moins une langue approuvée, cibles, début et fin obligatoires ; contenu : titre, classification, propriétaire et au moins une version linguistique complète obligatoires. Les limites de taille sont affichées avant dépôt. Les formats de date suivent la langue sans ambiguïté ; fuseau visible sur toute programmation.

Confirmations distinctes : annuler une réservation, déclarer une non-présentation, suspendre une campagne, publier ou prolonger une urgence, révoquer un terminal, modifier une plage de fonctionnement avec dérogation, désactiver un compte, modifier une délégation ou un pouvoir étendu et supprimer une version non utilisée. Chaque boîte précise objet, portée, conséquence et motif requis. Le bouton final exprime l'action réelle ; aucun bouton générique Oui sans contexte.

## 31 Jeu de données pour la maquette

Jeu fictif à identifier comme démonstration : site Libreville, bâtiment Siège, RDC et étages 1 à 6 ; 14 écrans de démonstration, deux par niveau, dont un écran d'orientation à l'accueil ; 4 salles fictives de capacités 12, 20, 40 et 100 ; 2 ressources mobiles ; 8 profils humains correspondant aux rôles du chapitre 4 et un profil technique de lecteur. Trois des profils portent un pouvoir étendu (urgence, modèles, prestations) et un suppléant est désigné pour chaque validateur. Ces nombres servent au prototype et ne représentent pas un inventaire réel.

Prévoir au moins 12 documents répartis en public interne restreint ; 8 contenus couvrant brouillon soumis approuvé programmé diffusé expiré archivé et retourné, dont au moins 4 avec versions française et anglaise et un dont la version anglaise est non approuvée ; 5 campagnes dont deux concurrentes ; une alerte prolongée ; un lecteur déconnecté, un écran hors plage, un cache saturé et une analyse de média en échec. Ajouter une réunion confidentielle, une série avec conflit, une demande en attente escaladée au suppléant, une réservation déclarée non honorée, une prestation impossible, un export expiré et une notification pointant vers un objet devenu inaccessible.

Employer noms et adresses fictifs, aucune donnée personnelle réelle, aucun mot de passe de production. Pour les captures de maquette, fixer une horloge de démonstration dans la plage de fonctionnement et afficher le fuseau. Les chiffres des tableaux de bord doivent correspondre aux objets du scénario, même dans un prototype. Les fichiers de démonstration restent distincts des données de production.

## 32 Matrice de couverture de conception

| Domaine et exigences | Vues | PDF et parcours |
| --- | --- | --- |
| GOV et objectifs | V04 V33 V39 | PDF05 PDF07 |
| HAB SEC et identité | V01 V02 V03 V34 V35 V38 | PDF09 P08 |
| REF et parc | V07 V08 V09 V10 V36 V37 | PDF06 P06 |
| DOC et fichiers | V11 V12 V13 V14 V41 | PDF10 P02 |
| PUB et composition, dont PUB-08 à PUB-12 | V15 V16 V17 V18 V19 V20 V21 V22 V23 V24 | PDF04 PDF05 P01 P05 |
| ACC accueil et orientation (P1) | T05 V28 V19 | P09 |
| SAL et prestations, dont SAL-12 à SAL-15 | V25 V26 V27 V28 V29 V30 V31 V32 T03 | PDF01 PDF02 PDF03 PDF07 P03 P04 P07 |
| NOT TAB et UX | V03 V04 V05 V06 V33 V42 | P01 à P09 selon rôle |
| PLY et continuité, dont PLY-08 à PLY-12 | T01 T02 T04 V07 V08 V24 V40 | PDF05 PDF08 P06 ; recette R25 R26 |
| TECH CONT EXP SEC-07 et DATA API | V39 V40 V41 et preuves techniques hors maquette | PDF08 et recette R14 R18 R19 à R27 |

Cette matrice couvre les familles ; le concepteur doit la détailler pour chaque exigence numérotée et chaque transition. Les contraintes SQL, restauration, analyse antivirus, concurrence et pilotage matériel requièrent des tests d'implémentation ; la maquette ne peut pas les démontrer.

## 33 Recette de la maquette et transmission au développement

La maquette est recevable si les 42 vues web, les 5 vues terminal (T05 étiquetée P1) et les 10 modèles PDF sont inventoriés, si les 9 parcours (P09 étiqueté P1) sont navigables, si les rôles et pouvoirs étendus donnent accès aux seules actions autorisées et si les variantes d'erreur critiques sont représentées. Fournir les versions bureau tablette mobile pour les parcours agent ; bureau et tablette pour administration ; formats paysage et portrait pour affichage, en français et en anglais pour T01, T02 et T05.

Présenter au minimum un état normal, vide, chargement, erreur et interdit par famille de vue, plus les états métier spécifiques : retour éditorial sur une langue, conflit réservation, escalade de validation, terminal hors ligne, écran hors plage, document en quarantaine, export expiré, non-présentation déclarée et réunion confidentielle. Les états techniques communs peuvent être factorisés en composants ; leurs occurrences dans les parcours restent documentées.

Pour chaque PDF, livrer une page type et un exemple multipage lorsque la liste peut dépasser une page ; tester texte long, caractères accentués, liste vide, plusieurs salles, plages horaires, plusieurs langues et classification. Les métadonnées, totaux et références doivent correspondre aux données de la vue source. Vérifier les versions publique et interne du planning.

Le dossier de transmission contient dimensions et espacements, jetons de style, composants et variantes, règles responsive, champs et validations, routes, permissions et pouvoirs étendus, transitions, schémas de données attendus et correspondance API. Étiqueter chaque composant fonctionnel P0 P1 P2. La validation graphique de la maîtrise d'ouvrage ne supprime aucune exigence de sécurité ou de recette technique.

# Annexes

## A Manifeste de diffusion minimal

Le manifeste contient `manifest_id`, `device_id`, `version`, `generated_at`, `valid_until`, `timezone`, `operating_window`, `audio_policy`, `signature`, `assets`, `agenda`, `fallback` et `schedule`. Chaque ressource porte un identifiant, une empreinte SHA-256, une taille, un type, une langue et une référence de téléchargement contrôlée. Chaque entrée du calendrier référence une version linguistique de contenu, sa période et son rang de rotation. L'agenda liste les occurrences du jour autorisées pour la zone (PUB-09), sans donnée confidentielle. Le schéma interdit tout champ supplémentaire non prévu et exclut les métadonnées confidentielles. L'exemple ci-dessous est illustratif ; le schéma définitif est publié dans le contrat OpenAPI.

```json
{
  "manifest_id": "0b6f…",
  "device_id": "dev-rdc-accueil-01",
  "version": 412,
  "generated_at": "2026-11-03T07:55:00Z",
  "valid_until": "2026-11-04T07:55:00Z",
  "timezone": "Africa/Libreville",
  "operating_window": {
    "days": "MON-FRI", "on": "08:00", "off": "18:00", "wake_before_min": 5
  },
  "audio_policy": { "enabled": true, "max_volume": 40 },
  "assets": [
    { "id": "ast-91", "sha256": "e3b0…", "size": 2483021,
      "type": "image/webp", "lang": "fr", "href": "/device/assets/ast-91" }
  ],
  "schedule": [
    { "content_version": "cv-77-fr", "priority": 50, "rotation_rank": 1,
      "from": "2026-11-03T08:00:00Z", "to": "2026-11-07T17:00:00Z", "duration_s": 12 },
    { "content_version": "cv-77-en", "priority": 50, "rotation_rank": 2,
      "from": "2026-11-03T08:00:00Z", "to": "2026-11-07T17:00:00Z", "duration_s": 12 }
  ],
  "agenda": [
    { "room": "Salle 4.02", "label": "Réservée",
      "start": "2026-11-03T09:00:00Z", "end": "2026-11-03T10:30:00Z" }
  ],
  "fallback": { "content_version": "fb-rdc-03", "valid_until": "2026-12-31T17:00:00Z" },
  "signature": { "alg": "Ed25519", "key_id": "mk-2026-11", "value": "…" }
}
```

## B Scénario complet de bout en bout

Une direction dépose une note interne. Le fichier est analysé, puis une prévisualisation est produite. Un contributeur prépare une annonce en français et en anglais, avec un QR code vers la consultation authentifiée, et cible uniquement des écrans de zone interne. Un validateur distinct approuve chaque version linguistique. Le diffuseur programme la campagne avec la rotation français puis anglais. Les lecteurs autorisés récupèrent un manifeste limité à leur périmètre, pendant leur plage de fonctionnement. À l'expiration, ils retirent l'annonce. Les journaux permettent de retrouver l'auteur, les décisions par langue, les cibles et le dernier reçu de lecture déclaré.

## C Dictionnaire des niveaux de preuve

| Terme | Définition |
| --- | --- |
| Approuvé | Version linguistique acceptée par une personne habilitée |
| Programmé | Version inscrite dans un calendrier valide |
| Reçu | Manifeste ou ressource dont le terminal a accusé réception |
| Activé | Programme déclaré actif par le lecteur |
| Lecture déclarée | Événement de rendu remonté par le logiciel |
| Rendu capturé | Capture du rendu logiciel disponible (PLY-11) ; ne prouve pas l'allumage de la dalle |
| Écran visible | État physique confirmé par le canal de pilotage (PLY-09) ou par observation ; jamais déduit du heartbeat ; « Non mesuré » en l'absence de retour matériel |

## D Décisions d'arbitrage et harmonisations de la fusion

### D.1 Décisions d'arbitrage du 3 novembre 2026

| N° | Question | Décision | Exigences impactées |
| --- | --- | --- | --- |
| Q1 | Rôles manquants (urgence, conception de modèles, prestations) | Fusion avec des rôles existants sous forme de pouvoirs étendus nominatifs : « urgence » → Diffuseur ; « modèles » → Administrateur fonctionnel ; « prestations » → Gestionnaire de salle (ce dernier à confirmer) | § 4, HAB-03, V15, V16, V23, V32, V34, V35 |
| Q2 | Multilinguisme des contenus | P0, français et anglais activés au lancement ; portugais et espagnol activables sans développement | § 3.4, PUB-04, PUB-08, V17 à V21, T01, T02, R24, § 22 |
| Q3 | Statut « non honorée » | Déclaré manuellement par le gestionnaire en P0 ; passage automatique à l'activation de SAL-10 | SAL-03, SAL-12, V28, V33, PDF07, API |
| Q4 | Plage de fonctionnement des écrans | De 8 h à 18 h en semaine, éteint les jours non ouvrés ; dérogation par écran avec motif | PLY-08, REF-02, V07, V08, V39, § 20 |
| Q5 | Politique sonore | Audio autorisé à l'accueil de 8 h à 18 h avec un volume plafond ; muet ailleurs ; sous-titres obligatoires | PLY-10, DOC-01, UX-02, § 20 |
| Q6 | Cadre de protection des données | Textes internes de la Commission seuls, au titre de son statut d'organisation internationale ; convention imposant ce cadre à tout hébergeur tiers | GOV-02, SEC-04, DOC-06, SAL-08, § 20 |

### D.2 Harmonisations de la fusion (v3.0)

Ces harmonisations résolvent les écarts entre les documents sources. Elles sont soumises à la validation de la maîtrise d'ouvrage au même titre que les autres propositions.

| N° | Écart constaté | Règle retenue en v3.0 |
| --- | --- | --- |
| H1 | Priorités P1/P2/P3 (31 octobre) et P0/P1/P2 (3 novembre) | Échelle unique P0/P1/P2 ; P1 → P0, P2 → P1, P3 → P2 |
| H2 | Classifications PUBLIC / INTERNE AUTORISÉ / CONFIDENTIEL et public / interne / restreint | Public, interne, restreint ; « CONFIDENTIEL » devient « restreint », toujours exclu des écrans collectifs (DOC-04) |
| H3 | RPO 24 h / RTO 8 h (31 octobre) et RPO 1 h / RTO 4 h (v2.1) | Cible RPO 1 h / RTO 4 h ; plancher 24 h / 8 h admis seulement par décision formelle de la DSI si l'archivage WAL n'est pas disponible au pilote |
| H4 | Prise en charge P1 sous 2 h et P2 sous 1 jour (31 octobre) et 1 h / 4 h / 2 jours (v2.1) | Délais de la v2.1 retenus ; garantie corrective de 12 mois reprise du 31 octobre (EXP-03) |
| H5 | 10 écrans extensibles à 100 et charge de référence de 100 lecteurs | 10 écrans = hypothèse budgétaire ; 100 lecteurs = charge de recette synthétique ; aucune refonte pour passer de l'une à l'autre |
| H6 | États éditoriaux « en révision », « rejeté », « retiré » (31 octobre) | Cycle PUB-01 de la v2.1 ; « rejeté » = retour au brouillon avec motif ; « retiré » = suspension ou dépublication |
| H7 | Laravel « version supportée » et stockage S3 (31 octobre) ; Laravel 13 (v2.1) | Laravel 13 et PostgreSQL 18 ; stockage objet compatible S3 ou volume local isolé |
| H8 | Lecteur « Chromium/Electron ou équivalent » et application React kiosque | PLY-01 : application React exécutée dans un Chromium supporté, un conteneur Electron ou équivalent, avec watchdog |
| H9 | Calendrier S1–S20 et enveloppe de 17 à 21 semaines | Plan de référence de 20 semaines, dans l'enveloppe de 17 à 21 semaines (§ 22.1) |
| H10 | Jalons J0–J5 et phases v2.1 | Jalons rattachés aux phases (§ 22.1) ; J2 MVP = lots Socle, Affichage et Documents |
| H11 | Accueil protocolaire F10 (drapeaux, langues) et ACC-02 | ACC-02 enrichi : drapeaux issus d'une bibliothèque validée par le Protocole, langues, validation protocolaire |
| H12 | Sécurité « OWASP ASVS 5.0 » et pentest (31 octobre) sans exigence numérotée en v2.1 | Nouvelle exigence SEC-07 et scénario R27 |
| H13 | Recettes RT03 (échéances) et RT07 (redémarrage) sans équivalent exact en v2.1 | Nouveaux scénarios R25 et R26 |
| H14 | API d'intégration SI F13 et accusés de réception d'alerte F08 | Nouvelle exigence API-07 ; PUB-06 complété (réception et activation journalisées par lecteur) ; entité `alert_receipts` |

### D.3 Journal des versions

| Version | Date | Évolution |
| --- | --- | --- |
| 1.0 (.docx) | 31/10/2026 | Cahier des charges et étude technique et financière initiaux |
| 1.0 (.md) | 03/11/2026 | Réécriture avec exigences numérotées par famille et socle React / Laravel 13 / PostgreSQL 18 |
| 2.0 | 03/11/2026 | Ajout des spécifications de maquette : 42 vues, 4 vues terminal, 10 PDF, 8 parcours |
| 2.1 | 03/11/2026 | 31 exigences issues de l'audit et décisions Q1 à Q6 ; T05 et P09 |
| **3.0** | **03/11/2026** | **Fusion en document unique ; harmonisations H1 à H14 ; budget, cas d'utilisation, jalons et garantie réintégrés ; SEC-07, API-07, R25 à R27 ajoutés ; identifiants normalisés `FAMILLE-NN` ; index et table de correspondance** |

## E Index des exigences

| Famille | Domaine |
| --- | --- |
| GOV | Gouvernance, objectifs, protection des données, conduite du changement |
| HAB | Habilitations et séparation des tâches |
| REF | Référentiels et administration |
| DOC | Médiathèque et documents |
| PUB | Publication et programmation |
| PLY | Lecteurs et affichage |
| SAL | Salles et réservations |
| ACC | Accueil, protocole et orientation |
| NOT, TAB | Notifications et tableaux de bord |
| UX | Ergonomie et accessibilité |
| TECH | Socle technique et déploiement |
| DATA | Modèle de données |
| API | Contrats des API |
| SEC | Sécurité |
| PERF, CONT | Performances et continuité |
| INF | Infrastructure et équipements |
| QUAL | Qualité et recette |
| LIV | Livrables |
| EXP | Exploitation et maintenance |

Le document compte 106 exigences numérotées (86 P0, 16 P1, 4 P2).

| Exigence | Priorité | Chapitre | Objet |
| --- | --- | --- | --- |
| GOV-01 | P0 | 1 | Le succès du système est mesuré sur les trois premiers mois suivant la généralisation à l'aide des indicateurs… |
| HAB-01 | P0 | 4 | Combiner rôle, structure, site, zone, classification et propriété de l'objet pour chaque autorisation |
| HAB-02 | P0 | 4 | Un auteur ne valide pas sa propre publication |
| HAB-03 | P0 | 4 | Les pouvoirs « urgence » (Diffuseur), « modèles » (Administrateur fonctionnel) et « prestations » (Gestionnaire de… |
| HAB-04 | P0 | 4 | Délai de validation paramétrable par type d'objet : 2 jours ouvrés pour un contenu, 24 heures pour une réservation à… |
| REF-01 | P0 | 6 | Gérer les sites, bâtiments, niveaux, emplacements, zones de diffusion, structures (département, direction, service,… |
| REF-02 | P0 | 6 | Affecter chaque écran à un emplacement, une orientation, une résolution et une plage de fonctionnement (PLY-08) |
| REF-03 | P0 | 6 | Import CSV avec modèle téléchargeable, simulation préalable sans écriture, erreurs ligne par ligne et bilan |
| DOC-01 | P0 | 7 | Déposer des PDF, des images JPEG/PNG/WebP, des vidéos MP4 et des fichiers bureautiques DOCX/PPTX/XLSX à télécharger |
| DOC-02 | P0 | 7 | Contrôler l'extension, la signature MIME, la taille, l'analyse antivirus et l'intégrité SHA-256 |
| DOC-03 | P0 | 7 | Conserver titre, résumé, catégorie, service propriétaire, auteur, langue, mots-clés, classification, version, dates… |
| DOC-04 | P0 | 7 | Trois classifications : public, interne et restreint |
| DOC-05 | P0 | 7 | Un QR code public ouvre uniquement un contenu public |
| DOC-06 | P0 | 7 | La dépublication arrête la diffusion et invalide les manifestes concernés |
| DOC-07 | P0 | 7 | Routes documentaires du contrat d'API : GET /documents (liste paginée dans le périmètre), GET /documents/{id} (fiche… |
| DOC-08 | P1 | 7 | Accusé de lecture des circulaires internes : pour un document interne diffusé à une liste de structures, le… |
| DOC-09 | P2 | 7 | Diffusion par courriel d'un document publié à une liste de structures, via la file de NOT-02, avec un lien authentifié |
| PUB-01 | P0 | 8 | États : brouillon → soumis → approuvé → programmé → diffusé → expiré → archivé |
| PUB-02 | P0 | 8 | Chaque transition conserve l'auteur, le rôle, la date serveur, le motif et la version |
| PUB-03 | P0 | 8 | Créer des modèles par orientation (16:9 paysage et portrait) avec zones texte, image, vidéo, horloge, agenda,… |
| PUB-04 | P0 | 8 | Une campagne définit les cibles, les langues et leur ordre de rotation (PUB-08), la période, les jours, les… |
| PUB-05 | P0 | 8 | Résolution déterministe de la programmation : alerte active autorisée d'abord, puis priorité numérique décroissante,… |
| PUB-06 | P0 | 8 | Une alerte comporte un message court, une zone, un début, une expiration et un auteur titulaire du pouvoir « urgence… |
| PUB-07 | P0 | 8 | Distinguer les niveaux programmé, manifeste reçu, contenu chargé, lecture déclarée et, si PLY-09 est actif, écran… |
| PUB-08 | P0 | 8 | Un contenu porte une à quatre versions linguistiques (français, anglais, portugais, espagnol) qui partagent le même… |
| PUB-09 | P0 | 8 | La zone agenda d'un modèle est alimentée par les réservations confirmées du jour, filtrées selon le bâtiment ou le… |
| PUB-10 | P1 | 8 | Composants dynamiques : heure serveur, météo de Libreville, actualités du site institutionnel de la Commission (flux… |
| PUB-11 | P1 | 8 | Bibliothèque de contenus de repli par zone, gérée dans V22 |
| PUB-12 | P2 | 8 | Mode événement : programme dédié qui bascule plusieurs zones pendant un sommet, un conseil des ministres ou une… |
| PLY-01 | P0 | 9 | Fournir une application React dédiée au mode kiosque, exécutée sur un lecteur administré (navigateur Chromium… |
| PLY-02 | P0 | 9 | Appairage par code à usage unique valable 10 minutes : un administrateur associe le terminal à un écran |
| PLY-03 | P0 | 9 | Le backend produit un manifeste signé et versionné contenant la liste des ressources, les empreintes, la validité,… |
| PLY-04 | P0 | 9 | Cache local persistant (IndexedDB et Cache Storage), contrôle du quota et réserve proposée de 20 % |
| PLY-05 | P0 | 9 | Hors ligne, le lecteur continue uniquement les contenus préchargés et encore valides |
| PLY-06 | P0 | 9 | Heartbeat toutes les 30 secondes ; terminal « suspect » après 90 secondes et « indisponible » après 5 minutes sans… |
| PLY-07 | P0 | 9 | Synchronisation toutes les 30 secondes au plus, avec temporisation aléatoire et téléchargement conditionnel par ETag |
| PLY-08 | P0 | 9 | Plage de fonctionnement par écran ou par groupe : de 8 h à 18 h en semaine, éteint les jours non ouvrés du… |
| PLY-09 | P1 | 9 | Retour d'état physique de la dalle (alimentation, entrée active, température, erreurs) par le canal de PLY-08 |
| PLY-10 | P0 | 9 | Politique sonore : écrans muets par défaut |
| PLY-11 | P1 | 9 | Capture à distance du rendu logiciel, à la demande depuis V08 |
| PLY-12 | P1 | 9 | Mise à jour applicative du lecteur par canal de version (pilote, stable), avec builds signés et vérifiés avant… |
| SAL-01 | P0 | 10 | Chaque salle a un code, un nom, un niveau, une capacité, une localisation, des photos, des équipements, des… |
| SAL-02 | P0 | 10 | Recherche par date, intervalle, capacité et équipement |
| SAL-03 | P0 | 10 | États : brouillon, en attente, confirmée, refusée, annulée, en cours, terminée et non honorée |
| SAL-04 | P0 | 10 | Intervalles semi-ouverts \[début, fin\[ : deux créneaux adjacents sont admis si les temps de préparation le permettent |
| SAL-05 | P0 | 10 | Empêcher les chevauchements au niveau de PostgreSQL par une contrainte EXCLUDE GiST sur la salle et l'intervalle… |
| SAL-06 | P0 | 10 | Réservations récurrentes bornées (proposé : 6 mois et 100 occurrences au maximum) |
| SAL-07 | P0 | 10 | Une modification d'horaire, de salle ou de capacité relance les contrôles et, si nécessaire, la validation |
| SAL-08 | P0 | 10 | Saisir l'objet, l'organisateur, les participants internes ou externes, la confidentialité et les prestations… |
| SAL-09 | P0 | 10 | Le panneau affiche : libre, occupée ou indisponible, heure de fin et prochaines disponibilités |
| SAL-10 | P1 | 10 | Confirmation de présence par l'organisateur authentifié ou par code temporaire |
| SAL-11 | P1 | 10 | Synchronisation avec un calendrier externe via un connecteur adapté au fournisseur réellement utilisé |
| SAL-12 | P0 | 10 | En P0, le statut « non honorée » est déclaré manuellement par le gestionnaire de la salle, après l'heure de début,… |
| SAL-13 | P1 | 10 | Ressources mobiles réservables (matériel de visioconférence mobile, cabines d'interprétation, vidéoprojecteurs) avec… |
| SAL-14 | P1 | 10 | Liste de contrôle d'ouverture et de remise en état d'une salle, liée à l'occurrence et imprimable dans PDF03 |
| SAL-15 | P2 | 10 | Type de prestation « pause-café et restauration », avec nombre de participants et heure de service, traité comme… |
| ACC-01 | P1 | 11 | La vue d'orientation T05 est produite par le même lecteur que T01 et T02, à partir d'un modèle dédié |
| ACC-02 | P1 | 11 | Un message de bienvenue est un contenu type créé en un clic depuis V28 pour une réservation confirmée et non… |
| ACC-03 | P2 | 11 | La gestion complète des visiteurs (pré-enregistrement, badges, notification de l'hôte) est hors périmètre et fera… |
| NOT-01 | P0 | 12 | Notifications internes pour : soumission, retour, approbation, rappel et escalade de validation (HAB-04), expiration… |
| NOT-02 | P0 | 12 | Courriels via le SMTP institutionnel s'il est disponible, avec files de traitement, reprises exponentielles, limite… |
| NOT-03 | P1 | 12 | Notifications push du navigateur (application web progressive) pour les agents sur mobile : confirmation ou refus… |
| TAB-01 | P0 | 12 | Tableaux de bord adaptés au rôle : contenus à valider, validations en retard ou escaladées, campagnes en cours,… |
| TAB-02 | P0 | 12 | Export CSV et PDF des inventaires, réservations et bilans de diffusion selon les droits |
| UX-01 | P0 | 13 | Design sobre conforme à une charte validée, responsive pour l'administration sur ordinateur et tablette,… |
| UX-02 | P0 | 13 | Sur les écrans collectifs, tester la lisibilité à la distance réelle, le contraste, la taille et la densité, pour… |
| UX-03 | P0 | 13 | Chaque vue prévoit les états chargement, vide, erreur, accès interdit et déconnexion |
| TECH-01 | P0 | 15 | Documenter les extensions PHP exigées par Composer et par les fonctions retenues, notamment pdo_pgsql, mbstring,… |
| TECH-02 | P0 | 15 | Environnements de développement, d'intégration, de préproduction (recette) et de production séparés, sans aucun… |
| TECH-03 | P0 | 15 | Chaîne CI : installation reproductible, vérifications statiques, tests, build frontend, audit de sécurité, artefact… |
| TECH-04 | P0 | 15 | Migrations selon la stratégie « ajout, puis transition, puis retrait » ; sauvegarde avant tout changement sensible |
| DATA-01 | P0 | 16 | Index sur le statut et l'échéance des campagnes, la classification et le propriétaire des documents, la salle et les… |
| DATA-02 | P0 | 16 | Contrôles SQL : fin après début, capacité positive, codes uniques, références obligatoires, une seule version active… |
| DATA-03 | P0 | 16 | Toutes les entités du tableau sont livrées avec leurs migrations, index et contraintes dès le lot Socle, même… |
| API-01 | P0 | 17 | Base /api/v1, JSON UTF-8, dates ISO 8601 avec fuseau, instants normalisés en UTC |
| API-02 | P0 | 17 | Réponse de liste : data et meta avec pagination |
| API-03 | P0 | 17 | POST /reservations reçoit room_id, start_at, end_at, confidentiality, subject, participant_count et, en option,… |
| API-04 | P0 | 17 | Modification avec If-Match ou expected_version |
| API-05 | P0 | 17 | La SPA utilise des cookies HttpOnly, Secure et SameSite adaptés au déploiement, une protection CSRF et une liste… |
| API-06 | P0 | 17 | Toutes les routes du tableau font partie du contrat OpenAPI livré avec le lot Socle |
| API-07 | P1 | 17 | API d'intégration sécurisée pour l'agenda, l'intranet, les RH et d'autres applications de la Commission : comptes de… |
| SEC-01 | P0 | 18 | TLS pour toutes les communications, terminaux compris |
| SEC-02 | P0 | 18 | Policies Laravel sur chaque objet et chaque téléchargement, avec tests d'accès horizontaux et verticaux |
| SEC-03 | P0 | 18 | Conversion documentaire dans un worker isolé sans accès réseau, avec plafonds de mémoire, de CPU et de durée… |
| SEC-04 | P0 | 18 | Journal en ajout seul au niveau applicatif : connexions, refus, changements de droits et de pouvoirs étendus,… |
| SEC-05 | P0 | 18 | Ne jamais journaliser mots de passe, jetons, liens signés complets ou contenu intégral des documents |
| SEC-06 | P0 | 18 | Séparer le réseau des lecteurs (VLAN dédié) de celui des serveurs et n'autoriser que les flux nécessaires, sortants… |
| SEC-07 | P0 | 18 | Vérifier la sécurité selon le référentiel OWASP ASVS 5.0, niveau 2 au minimum pour les fonctions d'administration et… |
| GOV-02 | P0 | 18 | Cadre de protection des données : le traitement des données personnelles (participants internes et externes,… |
| PERF-01 | P0 | 19 | Séparer les téléchargements de médias des requêtes métier |
| CONT-01 | P0 | 19 | Sauvegarde quotidienne, complète ou différentielle, documentée ; archivage WAL assez fréquent pour atteindre le RPO… |
| CONT-02 | P0 | 19 | Restaurer périodiquement dans un environnement isolé et mesurer le RPO et le RTO : un exercice avant la mise en… |
| INF-01 | P0 | 20 | Le quantitatif final comprend écrans, lecteurs, panneaux, supports, câbles, prises, commutation, licences… |
| INF-02 | P0 | 20 | Relever pour chaque emplacement : plans, visibilité, ensoleillement, distance de lecture, orientation, flux de… |
| QUAL-01 | P0 | 21 | Tests unitaires sur les règles de calendrier, les droits, les pouvoirs étendus et la rotation linguistique |
| QUAL-02 | P0 | 21 | À la réception : aucun défaut bloquant ou critique ouvert, et aucune vulnérabilité critique ou élevée exploitable… |
| LIV-01 | P0 | 22 | Livrer les sources complètes du frontend, du backend, du lecteur et du panneau dans un dépôt versionné, les… |
| LIV-02 | P0 | 22 | Livrer les manuels administrateur, contributeur, validateur, agent et exploitant DSI, la procédure de publication,… |
| LIV-03 | P0 | 22 | Les données de démonstration sont marquées comme telles et ne sont jamais injectées en production sans accord |
| EXP-01 | P0 | 23 | Superviser : sondes HTTP, erreurs API, saturation disque, CPU, mémoire, connexions à la base, retard des files,… |
| EXP-02 | P0 | 23 | Journaliser les déploiements, changements de configuration, mises à jour et opérations sur les terminaux |
| EXP-03 | P0 | 23 | Garantie corrective de 12 mois après la réception définitive, puis contrat d'assistance reconductible |
| EXP-04 | P0 | 23 | Former séparément la DSI (administrateurs et exploitants), les contributeurs et validateurs, les gestionnaires de… |
| EXP-05 | P0 | 23 | Le cycle de vie des versions du socle est inscrit au contrat de maintenance |
| EXP-06 | P1 | 23 | Grille de coût total sur cinq ans renseignée au cadrage : matériel (écrans, lecteurs, panneaux, supports),… |
| GOV-03 | P1 | 23 | Conduite du changement : charte éditoriale validée par le service chargé de la communication, correspondants… |

## F Correspondance avec les documents sources

### F.1 Cahier des charges du 31 octobre 2026

| Ancien identifiant | Objet | Exigences v3.0 |
| --- | --- | --- |
| F01 | Utilisateurs et organigramme | HAB-01, HAB-03, REF-01, V34, V35 |
| F02 | Écrans, sites et groupes | REF-01, REF-02, PLY-02, V07 à V10 |
| F03 | Bibliothèque des médias | DOC-01 à DOC-03 |
| F04 | Éditeur de compositions | PUB-03, V15, V16 |
| F05 | Circuit de validation | PUB-01, PUB-02, HAB-02, HAB-04 |
| F06 | Programmation | PUB-04, PUB-05 |
| F07 | Lecteur autonome | PLY-01 à PLY-07, PLY-12 |
| F08 | Alertes urgentes | PUB-06, HAB-03, V23 |
| F09 | Supervision | PLY-06, PLY-09, EXP-01, V40 |
| F10 | Accueil et protocole | ACC-01, ACC-02, PUB-08 |
| F11 | Réunions et salles | SAL-01 à SAL-15, PUB-09 |
| F12 | Tableaux de bord | TAB-01, TAB-02, V33 |
| F13 | API et intégration SI | API-07, SAL-11 |
| F14 | Multilingue | PUB-08, § 3.4 |
| F15 | Maintenance et audit | SEC-04, CONT-01, CONT-02, PLY-12 |
| NF01 | Disponibilité 99,5 % | GOV-01, § 19 |
| NF02 | Console < 3 s au p95 | § 19 (parcours web) |
| NF03 | Alerte < 60 s sur 95 % | PUB-06, § 19 |
| NF04 | Lecture hors ligne ≥ 24 h | PLY-05, § 19 |
| NF05 | 10 écrans extensibles à 100 | § 19, H5 |
| NF06 | Sauvegarde, RPO et RTO | CONT-01, CONT-02, H3 |
| NF07 | Full HD / 4K, portrait / paysage | PLY-01, § 19 |
| NF08 | Audit, UTC, NTP | SEC-04, Conventions |
| RT01 à RT10 | Recette | R01, R02, R25, R06, R04 et R15, R02 et SEC-04, R26, R14, R15, R27 |
| UC01 à UC06 | Cas d'utilisation | UC01 à UC06 (§ 5), complétés par UC07 et UC08 |
| J0 à J5 | Jalons | § 22.1 |

### F.2 Étude technique et financière du 31 octobre 2026

| Section de l'étude | Chapitre v3.0 |
| --- | --- |
| Résumé exécutif, contexte et justification | § 1.1, § 1.2 |
| Objectifs et indicateurs | § 1.3 (GOV-01) |
| Périmètre institutionnel | § 3.2, § 3.3 |
| Architecture de référence | § 14, § 15 |
| Infrastructure et prérequis | § 20.2 |
| Organisation de la gouvernance | § 2.1 |
| Cybersécurité et continuité | § 18, § 19 |
| Hypothèses financières | § 24 |
| Scénarios comparatifs | § 20.1 |
| Calendrier indicatif | § 22.1 |
| Risques et arbitrages | § 25.1 |
| Recommandation et décision attendue | § 25.3 |

### F.3 Cahiers des charges v1.0, v2.0 et v2.1

La numérotation des chapitres a changé : les chapitres 1 à 22 de la v2.1 sont répartis dans les parties I à IV et les annexes, et les chapitres 23 à 30 deviennent les chapitres 26 à 33. Les identifiants d'exigences sont inchangés, à l'exception du tiret ajouté (`HAB 01` devient `HAB-01`). Les exigences ACC-01 à ACC-03 passent du chapitre 25 au chapitre 11. Les mentions « v2.1 » sont retirées du corps du texte ; l'historique est conservé dans l'annexe D.3.

## G Références et glossaire

### G.1 Références

Documentation officielle consultée le 3 novembre 2026. Ces références décrivent les technologies ; les versions exactes des dépendances seront qualifiées dans la matrice de compatibilité du projet. Les objectifs de performance, d'infrastructure et de service sont des propositions de conception, pas des valeurs garanties par ces éditeurs.

- Laravel 13 — Notes de version et prérequis PHP : https://laravel.com/docs/13.x/releases
- Laravel 13 — Déploiement : https://laravel.com/docs/13.x/deployment
- PostgreSQL 18 — Documentation : https://www.postgresql.org/docs/18/
- PostgreSQL 18 — Types intervalle et contraintes d'exclusion : https://www.postgresql.org/docs/18/rangetypes.html
- React — Documentation de référence : https://react.dev/
- Vite — Prérequis et compilation : https://vite.dev/guide/
- OpenAPI 3.1 — Spécification : https://spec.openapis.org/oas/v3.1.0
- W3C — WCAG 2.2 : https://www.w3.org/TR/WCAG22/
- OWASP — Application Security Verification Standard 5.0 : https://asvs.owasp.org/
- Xibo — Documentation CMS et lecteur (comparateur fonctionnel) : https://account.xibosignage.com/docs/
- Direction générale des impôts du Gabon — TVA : https://dgi.ga/imposition-des-personnes-morales/taxes-sur-le-chiffre-daffaires/tva/

### G.2 Glossaire

| Terme | Définition |
| --- | --- |
| API | Interface de programmation applicative |
| ASVS | Application Security Verification Standard de l'OWASP |
| CEC | Protocole de commande HDMI des écrans |
| CMS | Système de gestion de contenus |
| Dalle | Écran physique piloté par un lecteur |
| GED | Gestion électronique des documents |
| Lecteur | Terminal (mini-PC ou équivalent) qui exécute l'application kiosque et affiche le programme |
| Manifeste | Description signée du programme qu'un lecteur doit diffuser |
| MFA | Authentification multifacteur |
| Outbox | Table d'événements écrite dans la transaction métier puis distribuée de façon fiable |
| PWA | Application web progressive, avec capacités locales |
| RPO | Perte de données maximale visée |
| RTO | Délai de restauration visé |
| SBOM | Nomenclature des composants logiciels |
| SPA | Application web exécutée dans le navigateur |
| SSO | Authentification unique |
| VLAN | Réseau local virtuel isolé |
| WAL | Journal transactionnel de PostgreSQL |

## H Grille de validation du cahier des charges

| Instance | Vérification attendue | Visa et date |
| --- | --- | --- |
| Maîtrise d'ouvrage | Périmètre, priorités, règles métier, budget indicatif, décisions et harmonisations de l'annexe D | À renseigner |
| DSI | Architecture, sécurité, hébergement, exploitation et cadre de protection des données | À renseigner |
| Communication | Circuit éditorial, multilinguisme et affichage public | À renseigner |
| Protocole | Accueil des délégations, messages de bienvenue et drapeaux | À renseigner |
| Gestionnaires de salles | Réservation, confidentialité, non-présentation et prestations | À renseigner |
| Services financiers | Enveloppe budgétaire et traitement fiscal | À renseigner |
