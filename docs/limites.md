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
- **5 novembre, priorité 2 (premier lot)** :
  - le suppléant d’un gestionnaire voit et tranche une demande de réservation escaladée ;
  - le QR d’un document republié fonctionne de nouveau ;
  - un contenu dupliqué reçoit son propre QR ;
  - la vérification de disponibilité applique les règles de la salle et des prestations, comme la création ;
  - l’annulation d’une série archive tous ses messages de bienvenue ;
  - déplacer une réunion recale l’échéance de ses prestations ;
  - la notification « expiration prochaine » part une fois, dans les deux jours précédant l’échéance.
- **6 novembre, priorité 2 (deuxième lot)** :
  - les paramètres sans effet ont été retirés de la page Paramètres (V39) ; les dix restants agissent réellement (en-tête des écrans, plage et volume par défaut, durée de diapositive proposée, valeurs initiales des nouvelles salles, canal courriel, push, API), et leurs bornes sont contrôlées par le serveur ;
  - le gestionnaire de la salle déclare ou retire la priorité d’une réunion depuis sa fiche (motif obligatoire, journalisé) ; l’organisateur ne peut plus se l’attribuer ;
  - types de prestation : l’option « couverts et heure exigés » se règle, et le code est figé après création ;
  - une validation sans validateur éligible est signalée aux administrateurs fonctionnels, apparaît dans leurs tâches, puis est affectée automatiquement dès qu’un validateur est habilité ;
  - la liste « nouveau validateur » et le suppléant retenu sont limités aux validateurs habilités pour la langue et la structure, hors auteur.
- **6 novembre, priorité 2 (troisième lot)** :
  - la nouvelle version d’un document publié n’interrompt plus son téléchargement : la version précédente reste servie jusqu’à ce que la nouvelle soit déclarée saine ;
  - bouton **Archiver** sur la fiche d’un contenu ;
  - l’extrait PDF09/CSV reprend tous les filtres du journal, et le filtre « type d’objet » ne dépend plus de la casse ;
  - la conservation des captures de rendu est annoncée à 7 jours, comme le serveur l’applique.

- **6 novembre, audit — lot R1 (défauts bloquants de sécurité)** :
  - le serveur impose le second facteur (rôles sensibles et titulaires d’un pouvoir étendu) et le changement du mot de passe provisoire : seul « Mon compte » reste accessible d’ici là ;
  - l’adresse de connexion d’un compte qui détient des droits privilégiés que l’on n’a pas ne peut plus être changée ; tout changement d’adresse ou réinitialisation du second facteur prévient le titulaire ; la réactivation exige un motif ;
  - un média restreint n’est plus visible des seuls titulaires de la consultation de la médiathèque ; le fichier d’un document n’est servi par la médiathèque qu’à ses gestionnaires ; reclasser un document reclasse son fichier.

- **6 novembre, audit — lot R2 (sécurité majeure et séparation des fonctions)** :
  - les réponses porteuses d’un secret affiché une fois (second facteur, jetons de comptes de service) ne sont plus conservées par l’idempotence, les anciennes sont effacées ; la portée d’une clé couvre le chemin complet ;
  - un code du second facteur n’est accepté qu’une fois ; ses échecs comptent dans le verrouillage ; la limite de connexion vise l’utilisateur en attente ; les routes authentifiées sont limitées en débit ;
  - un changement ou une réinitialisation du mot de passe ferme toutes les sessions et invalide « se souvenir de moi » ;
  - le site et la zone d’une affectation bornent les campagnes, alertes, replis, écrans et groupes ; prolonger ou révoquer une alerte exige son périmètre « urgence » ; les pouvoirs « urgence » et « prestations » exigent un périmètre explicite ;
  - un co-rédacteur ou un soumissionnaire ne peut ni valider le contenu ni être désigné pour le faire ;
  - fiche PDF03 et bon PDF04 soumis à un contrôle d’objet ; une visite n’est modifiée que dans le périmètre de son auteur ;
  - pile Docker : l’application se connecte avec un compte à moindre privilège (`saie_app`) qui ne peut ni modifier le schéma ni altérer le journal ; le contrôle `preflight` le vérifie.

### Priorité 1 — décisions à prendre (aucune correction faite)

- **Participants des réunions standard** (nom, organisation) : visibles de tout agent qui peut voir les salles. À arbitrer au titre de GOV-02 : restreindre à l’organisateur, aux participants et aux gestionnaires ?
- **Permission « Générer des exports »** (`exports.create`) : non contrôlée côté serveur, seule la permission propre à chaque export compte. L’exiger partout priverait les agents de leur confirmation PDF01. Proposition : l’exiger pour les extractions de masse (CSV, rapports PDF02, PDF05 à PDF09), pas pour les fiches individuelles (PDF01, PDF03, PDF04, PDF10).
- **Versions du lecteur** : publication et retrait relèvent de la permission de gestion des incidents. Une permission dédiée est à créer si ces deux responsabilités sont séparées à la DSI.

### Priorité 2 — fonctions qui ne se comportent pas comme annoncé

- **Réservations et visites**
  - Le code de présence ne peut être saisi nulle part.
  - Le statut d’une visite ne quitte jamais « Message à valider ».

### Priorité 3 — libellés et textes

- **Jargon visible des utilisateurs** :
  - codes d’exigence (« (PLY-12) », « Échéance HAB-04 », « (P1) ») ;
  - termes techniques (« Manifeste reçu », « heartbeat », « index.html ») ;
  - commandes de développeur dans la section « Versions du lecteur ».
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
