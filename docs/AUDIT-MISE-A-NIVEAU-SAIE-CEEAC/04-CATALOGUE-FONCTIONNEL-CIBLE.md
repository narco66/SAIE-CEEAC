# 04 — Catalogue fonctionnel cible

Le catalogue décrit la cible fonctionnelle du SAIE-CEEAC : modules, acteurs, règles métier et dépendances. Pour chaque module, la colonne **Réalisé** donne l'état au 6 novembre 2026 ; les écarts sont référencés dans la matrice (`02-…`).

## Acteurs (rôles actuels et rôles cibles)

| Rôle actuel (`config/saie.php`) | Rôle du prompt couvert | Rôles cibles à créer (décision ROL-01) |
|---|---|---|
| `admin_tech` : administrateur technique DSI | Administrateur DSI, administrateur technique | Technicien de maintenance (incidents et supervision, sans gestion des comptes) |
| `admin_fonc` : administrateur fonctionnel | Super administrateur (sans droits techniques : séparation volontaire) | — |
| `diffuseur` | Responsable communication (programmation, alertes avec le pouvoir « urgence ») | Gestionnaire des contenus |
| `validateur` | Validateur | Responsable de direction (validation d'un premier niveau, PUB-04) |
| `contributeur` | Contributeur | — |
| `gestionnaire_salle` | Gestionnaire des salles | — |
| `auditeur` | Auditeur | — |
| `agent` | Utilisateur en consultation | Lecture seule stricte (sans réservation) |

**Pouvoirs étendus** (nominatifs, datés, avec périmètre) : « urgence » (alertes), « modèles » (modèles institutionnels), « prestations ».

**Règles transverses** :

- refus par défaut ;
- périmètre par structure, site et zone ;
- séparation auteur / validateur ;
- aucune auto-attribution de droits ;
- aucune action sur un compte qui détient des droits supérieurs ;
- MFA obligatoire pour les rôles sensibles ;
- tout refus est journalisé.

## Modules

| # | Module | Fonctions cibles | Acteurs | Règles métier principales | Dépendances | Réalisé |
|---|---|---|---|---|---|---|
| 1 | Tableau de bord | Indicateurs réels du parc (6 états), campagnes, tâches, salles, alertes, incidents, répartition par bâtiment et niveau, contenus en attente, frise du jour, indicateurs de diffusion ; filtres par site et bâtiment | Tous, selon leurs droits | Aucun chiffre écrit en dur ; aucune affirmation non mesurée | 2, 3, 8, 16, 20 | Oui (filtres : non) |
| 2 | Lieux | Organisation → site → bâtiment → niveau → zone → emplacement → écran ; types d'emplacement ; plans de niveau ; équipements par emplacement ; import CSV | `admin_fonc` | Code unique dans son parent ; zone de diffusion = classification maximale | — | Partiel (PAR-04) |
| 3 | Parc d'écrans | Fiche d'identité et matérielle, affectation, plage de fonctionnement (écran > groupe > défaut), statut administratif (actif, maintenance, désactivé), état opérationnel calculé, groupes, canal de pilotage | `admin_tech`, `diffuseur` (groupes) | Écran en maintenance toujours ciblé ; écran désactivé exclu ; « lecteur joignable » n'est jamais « diffuse » | 2, 4 | Oui |
| 4 | Lecteurs | Appairage par code temporaire, jeton individuel avec rotation, canal de version, cache vérifié, télémétrie, capture, révocation ; redémarrage à distance ; détection des terminaux non autorisés | `admin_tech` | Un lecteur actif par écran ; périmètre appliqué ; preuves conservées après révocation | 3, 17 | Oui (sauf PLY-R1, PLY-R2) |
| 5 | Médiathèque | Dépôt en quarantaine, analyse, miniatures, versions, classification, corbeille contrôlée ; étiquettes, droits d'usage, doublons | `contributeur` | Aucun média publiable avant l'état « sain » ; média jamais plus classifié que son contenu ; bureautique jamais diffusé | Antivirus, convertisseurs | Oui (PUB-08 ouvert) |
| 6 | Modèles | Éditeur multi-zones (texte, image, vidéo, horloge, bandeau, météo, QR, compte à rebours, agenda), orientation, aperçu, modèles institutionnels | `admin_fonc` + pouvoir « modèles » | Zones dans le cadre ; marge de sécurité | 5 | Oui |
| 7 | Listes de lecture | Éléments ordonnés avec durée, rotation, langues ; listes réutilisables ; glisser-déposer | `diffuseur` | Liste portée par la campagne (décision C2) | 5, 8 | Partiel (PUB-07) |
| 8 | Campagnes | Ciblage écran, groupe, zone ou niveau ; dates, jours, créneaux ; priorité ; mode événement ; conflits ; publication atomique sur versions approuvées ; suspension et reprise ; calendrier | `diffuseur` | Seules les versions approuvées sont diffusées ; périmètre de l'habilitation | 5, 14 | Oui |
| 9 | Alertes | Message court, niveau, cibles, début, expiration de 30 min prolongeable (240 min au plus), révocation, accusés, plein écran, retour automatique ; affichage hors plage des alertes critiques | `diffuseur` + pouvoir « urgence » | Toute prolongation est tracée ; un lecteur déconnecté est signalé, jamais déclaré atteint | 3, 4 | Oui (PUB-05 : décision) |
| 10 | Salles | Référentiel, capacité, équipements, réservation (récurrence, validation, conflits garantis par la base), panneau de porte, iCalendar, prestations, présence | Tous (réservation) ; `gestionnaire_salle` | Intervalles `[début, fin[` avec tampons ; réunion confidentielle affichée « Réservée » | 2 | Oui (SAL-01, SAL-02 ouverts) |
| 11 | Orientation | Vue T05 générée depuis les référentiels, messages de bienvenue ; annuaire avec recherche ; borne tactile ; plans et itinéraires | Visiteurs ; `contributeur` (visites) | Aucun nom de personne sans validation ; aucune réunion confidentielle | 2, 10 | Partiel (ORI-01) |
| 12 | Communication institutionnelle | Communiqués, annonces, actualités, messages de la Présidence par catégorie ; objet « événement » | `contributeur`, `validateur`, `diffuseur` | Même circuit que les contenus | 5, 8, 14 | Partiel |
| 13 | Documents | Bibliothèque, versions, audience, plein texte, accusés de lecture, diffusion par courriel, QR ; validation avant publication ; corbeille | `contributeur` | La version précédente reste servie jusqu'à ce que la nouvelle soit saine | 5, 19 | Oui (PUB-06 ouvert) |
| 14 | Circuit de validation | Soumission, approbation, retour motivé, réaffectation, échéances, escalade, suppléant ; circuits à plusieurs niveaux par catégorie | `validateur` | Aucun rédacteur ne valide son contenu ; validateur habilité pour la langue et la structure | 15, 19 | Partiel (PUB-04) |
| 15 | Habilitations | Comptes, rôles datés, périmètres, pouvoirs, MFA, sessions, déblocage, réinitialisation | `admin_fonc`, `admin_tech` | Voir « Règles transverses » | — | Oui (ROL-01 : décision) |
| 16 | Supervision | État des services, files, sauvegarde, lecteurs, dalles, sources ; incidents automatiques ; versions | `admin_tech` | Jamais « OK » sans mesure | 3, 4, 18 | Oui |
| 17 | Preuves de diffusion | Niveaux prévu → transmis → reçu → chargé → activé → lecture déclarée (avec durée) → écran visible (matériel) ; PDF05 | `diffuseur`, `auditeur` | Le niveau « vu » n'est jamais estimé ; la preuve survit à la purge des manifestes | 4, 8 | Oui |
| 18 | Incidents et maintenance | Déclaration, catégorie, nature corrective ou préventive, gravité, affectation notifiée, diagnostic, résolution, clôture ; incidents automatiques ; plan préventif | `admin_tech` | Intervenant actif et habilité | 16, 19 | Oui (INC-03 ouvert) |
| 19 | Notifications | Interne, courriel (reprises), push ; préférences ; dédoublonnage ; traçabilité | Tous | L'échec d'un courriel n'annule jamais l'opération métier | — | Oui |
| 20 | Rapports | PDF01 à PDF10, CSV ; indicateurs ; filtres temporels ; filtres par site et bâtiment ; XLSX | `auditeur`, gestionnaires | Droits revérifiés à la génération et au téléchargement | 17, 10 | Oui (RAP-01, RAP-02 ouverts) |
| 21 | Paramétrage | Identité, diffusion, plage et volume, salles, canaux, API ; rétention, formats, seuils, lecteur | `admin_fonc`, `admin_tech` | Bornes contrôlées par le serveur | — | Partiel (PAR-S1) |
| 22 | Administration technique | Journal, contrôle de mise en production, sauvegarde et restauration, versions du lecteur, OpenAPI | `admin_tech`, `auditeur` | Journal en ajout seul | — | Oui (ADM-01 : décision) |
