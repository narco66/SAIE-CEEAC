# Limites restantes

État au 5 novembre 2026.

## Poste de développement

- **PHP par défaut de Laragon en 8.3.** Le projet exige PHP 8.4 (`composer.json`) : sélectionner `php-8.4.7` dans Laragon, sinon `artisan` s’arrête au contrôle de plateforme de Composer.

## Fonctions dépendantes du matériel ou d’accès externes

- Pilotage physique des dalles (PLY-08, PLY-09) : l’agent local `player/agent/panel-agent.mjs` est fourni (HDMI-CEC, commandes RS-232 ou API constructeur) mais n’a été essayé qu’en pilote `simulate` ; à valider sur le matériel retenu au pilote.
- Conversion bureautique et transcodage vidéo : implémentés, actifs seulement si LibreOffice et ffmpeg sont installés sur le serveur (`SAIE_SOFFICE_PATH`, `SAIE_FFMPEG_PATH`) ; sinon les bureautiques restent téléchargeables et les vidéos doivent être déposées en H.264/AAC.
- Antivirus : le pilote `clamd` est éprouvé contre un démon de test (`ClamdScannerTest`), pas encore contre un ClamAV réel.
- SSO OIDC/LDAP et connecteurs d’agenda Exchange/Google : non fournis (paramètres présents et désactivés). L’export iCalendar couvre l’usage de base de SAL-11.
- Notifications push : exigent des clés VAPID propres à l’environnement (`php artisan saie:vapid`), l’activation du canal en V39 et une page servie en HTTPS.
- Mode événement (PUB-12) : il remplace le programme ordinaire mais ne modifie pas la plage de fonctionnement des écrans ; une session tardive exige d’élargir la plage des écrans concernés (V08).

## Vérifications restant à exécuter

- Images de production (`infra/php.Dockerfile`, `infra/web.Dockerfile`) et `infra/docker-compose.yml` : jamais construits ni lancés sur ce poste (Docker absent). La configuration Nginx a été validée et essayée sur Nginx 1.28 ; la construction des images, leur contenu et le preflight dans le conteneur sont contrôlés par le job `infra` de la CI, à son premier passage.
- Premier passage de la chaîne CI : le dépôt git ne contient pas encore le code (un seul fichier versionné).
- Charge (R15) et restauration (R14) mesurées sur ce poste (voir `docs/tests/rapport.md`) : à refaire sur l’environnement cible avec les volumes réels, l’archivage WAL et 10 lecteurs physiques.
- Mesures non réalisables sur ce poste : arrêt du SMTP institutionnel, audit d’accessibilité avec lecteur d’écran, ASVS niveau 2 et test d’intrusion, coupure électrique réelle.

## Défauts relevés à la rédaction des manuels (5 novembre 2026)

La rédaction des manuels a imposé une lecture complète des écrans et des règles. Elle a fait apparaître les écarts ci-dessous. Ils sont signalés par la lecture du code et **restent à confirmer par un test avant correction**, sauf mention « vérifié ».

Défauts corrigés, chacun prouvé par un test qui échouait avant le correctif :
- **5 novembre, rédaction des manuels** :
  - la recherche globale montrait les titres de contenus hors du périmètre de l’utilisateur ;
  - les messages de validation s’affichaient sous forme de clés brutes ;
  - les motifs de refus métier étaient masqués par un message générique ;
  - une demande de réservation expirée gardait sa validation ouverte.
- **5 novembre, priorité 1** :
  - auto-attribution de rôles et de pouvoirs, et élargissement des permissions d’un rôle qu’on détient ;
  - aucun déblocage de compte ni réinitialisation du second facteur possible par un administrateur (actions **Débloquer** et **Réinitialiser le second facteur** ajoutées, motivées, journalisées) ;
  - listes de contrôle des prestations hors du périmètre du pouvoir « prestations » ;
  - secrets imbriqués écrits au journal (filtrage désormais récursif) ;
  - média plus classifié que son contenu accepté, et **média interne diffusé sur un écran public**. La règle est appliquée à la soumission et à la compilation du programme.
  - alerte « tâche sans suppléant » envoyée à d’anciens administrateurs.

### Priorité 1 — décisions à prendre (aucune correction faite)

- **Participants des réunions standard** (nom, organisation) : visibles de tout agent qui peut voir les salles. À arbitrer au titre de GOV-02 : restreindre à l’organisateur, aux participants et aux gestionnaires ?
- **Permission « Générer des exports »** (`exports.create`) : non contrôlée côté serveur, seule la permission propre à chaque export compte. L’exiger partout priverait les agents de leur confirmation PDF01. Proposition : l’exiger pour les extractions de masse (CSV, rapports PDF02, PDF05 à PDF09), pas pour les fiches individuelles (PDF01, PDF03, PDF04, PDF10).
- **Versions du lecteur** : publication et retrait relèvent de la permission de gestion des incidents. Une permission dédiée est à créer si ces deux responsabilités sont séparées à la DSI.

### Priorité 2 — fonctions qui ne se comportent pas comme annoncé

- **Validations**
  - Escalade d’une réservation au suppléant du gestionnaire : le suppléant est notifié, mais il ne voit pas la demande et ne peut pas la traiter.
  - Une validation éditoriale sans validateur éligible est créée « non assignée » et n’apparaît dans les tâches de personne.
  - La liste « nouveau validateur » n’est pas filtrée par habilitation, et le suppléant n’est pas contrôlé (langue, auteur).
- **Paramètres et données**
  - 12 des 15 paramètres de la page Paramètres (V39) sont enregistrés mais sans effet. Seuls la mention institutionnelle, le canal push et l’API d’intégration sont lus. Exemples : durée de maintien d’une demande, durée de diapositive par défaut, nom de l’institution.
  - Aucun écran ne permet de marquer une réunion « prioritaire ». Cette donnée protège pourtant de la libération automatique.
  - Référentiel des types de prestation : l’option « couverts et heure exigés » ne se règle pas, et le code reste modifiable alors que les périmètres du pouvoir « prestations » s’y réfèrent.
  - Les bornes des paramètres numériques ne sont contrôlées que dans le navigateur.
- **QR codes**
  - Le QR d’un document dépublié puis republié reste inactif.
  - Un contenu dupliqué garde le QR du contenu d’origine.
- **Réservations et visites**
  - La vérification de disponibilité (étape 3) n’applique pas les règles de la salle (durée, délai, horaires) : le refus n’arrive qu’à la confirmation.
  - Le code de présence ne peut être saisi nulle part.
  - Annuler une série n’archive que le message de bienvenue de l’occurrence choisie.
  - Le statut d’une visite ne quitte jamais « Message à valider ».
  - Déplacer une réunion ne recale pas l’échéance de ses prestations.
- **Contenus et documents**
  - La notification « expiration prochaine » dépend d’une fenêtre d’une minute.
  - Une nouvelle version d’un document publié le rend indisponible au téléchargement pendant l’analyse.
  - L’archivage d’un contenu existe côté serveur mais sans bouton.
- **Journal et exports**
  - L’extrait PDF09/CSV ne reprend que le filtre « action ».
  - Le filtre « type d’objet » est sensible à la casse.
- **Lecteur** : une capture de rendu est annoncée conservée 10 minutes, alors que le serveur la garde 7 jours.

### Priorité 3 — libellés et textes

- **Jargon visible des utilisateurs** :
  - codes d’exigence (« (PLY-12) », « Échéance HAB-04 », « (P1) ») ;
  - termes techniques (« Manifeste reçu », « heartbeat », « index.html ») ;
  - commandes de développeur dans la section « Versions du lecteur ».
- **Libellés P1 périmés** : « Sources dynamiques (P1) », « fonction désactivée par défaut ».
- **Valeurs brutes affichées** :
  - statuts en anglais dans le PDF03 ;
  - gravité « major » sur le tableau de bord ;
  - trois types de notification sans libellé (`reservation.reminder`, `reservation.auto_released`, `approval.escalated`).
- **Écarts entre menus et pages** : « Traitements » au lieu de « Exports & traitements » ; « Programmation » ouvre la page « Campagnes ».
- **Boutons et aides trompeurs** :
  - **Confirmation PDF01** est proposé à des tiers, qui sont ensuite refusés ;
  - l’aide de l’export iCalendar d’une salle annonce que les réunions confidentielles restent masquées, alors que l’organisateur et le gestionnaire voient leur objet ;
  - le message de fermeture d’une salle renvoie vers « À valider ».
- **Assistant d’alerte** : le message « Aucune cible » omet les écrans individuels, et l’assistant ne borne pas la durée à 240 minutes comme le serveur.

## Points d’exploitation

- Les comptes de démonstration partagent un mot de passe généré ; il ne doit jamais servir hors d’un poste de développement.
- Le simulateur `saie:demo-fleet` maintient artificiellement le contact des lecteurs fictifs pour les démonstrations ; il est refusé en production.
