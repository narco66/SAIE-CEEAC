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
- Chaîne CI : exécutée et verte sur GitHub Actions (6 novembre).
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

- **6 novembre, audit — lot R3 (salles et accueil)** :
  - déplacer une réunion vers une salle à validation manuelle exige l’accord du gestionnaire de cette salle, qui reçoit la tâche et la notification ;
  - « cette occurrence et les suivantes » est appliqué en une seule transaction : un conflit laisse la série inchangée ;
  - refus, délai dépassé, non-présentation et libération automatique libèrent les ressources mobiles et annulent les prestations ouvertes ;
  - un participant muni du code de présence confirme la présence depuis la fiche ;
  - les bornes d’orientation n’affichent que les bienvenues en cours, qui les ciblent, d’une réunion confirmée et non confidentielle ;
  - le statut d’une visite suit son message (« Planifiée ») et sa réunion (« Terminée », « Annulée »).

- **6 novembre, audit — lot R4 (lecteur et livraison)** :
  - alertes, agenda, panneau et orientation appliqués dès la vérification du manifeste, sans attendre les médias ; médias des deux derniers manifestes autorisés ;
  - délais maximaux sur tous les appels du lecteur, heartbeat indépendant de la synchronisation ;
  - manifeste expiré ou plus ancien que la version active refusé ;
  - dérogation « jours non ouvrés » transmise au lecteur ;
  - retour à la version de base du lecteur quand le canal n’a plus de version cible ;
  - hors ligne, agenda, panneau et bienvenues retirés à l’expiration de l’autorisation ;
  - plus de bouton public de réappairage sur un terminal révoqué ; l’agent de dalle exige l’origine du lecteur ; une erreur de rendu affiche le repli neutre ;
  - médias servis par Nginx après autorisation de l’API ; purge des anciens manifestes et des fichiers d’exports échus ; étapes de `saie:tick` isolées.

- **6 novembre, audit — lot R6 (interface et accessibilité)** :
  - focus clavier visible et contrasté partout, y compris dans les barres de recherche ; bordures de champ à 3:1 ; libellés, en-têtes, badges et messages d’au moins 12 px ;
  - toutes les fenêtres modales gardent le focus, se ferment avec Échap et le rendent à l’élément d’origine ; Échap ferme aussi les menus ;
  - quitter un formulaire modifié par la navigation interne demande confirmation ;
  - progression affichée pendant le dépôt d’un média ou d’un document (la reprise d’un envoi interrompu n’est pas prise en charge) ;
  - clé d’idempotence liée à l’intention : un double clic ne crée plus de doublon ;
  - PDF : corps et tableaux à 10 pt au moins ; la confirmation d’une réunion confidentielle est classée « restreint ».

- **6 novembre, audit — lot R5 (exploitation et continuité)** :
  - pile Docker : archivage continu des WAL (RPO ≤ 1 h), sauvegarde nocturne de la base et du stockage avec empreintes, état affiché dans la supervision ;
  - supervision des files mesurée sur le pilote réel (Redis en production) ;
  - un lecteur indisponible ouvre un incident notifié aux gestionnaires des incidents ;
  - conversions exécutées dans un worker dédié, sans accès à Internet, mémoire et processus plafonnés, LibreOffice et ffmpeg installés ;
  - mots de passe du superutilisateur et du propriétaire de la base en secrets Docker, jamais transmis aux conteneurs applicatifs.
- **Restent à faire en exploitation** : figer les images Docker par version et empreinte au moment de la publication ; copie hors site chiffrée des volumes de sauvegarde par l’outil de la DSI ; premier exercice de restauration sur la pile Docker (chemin du répertoire de données et RTO à consigner) ; politique de mise à jour du navigateur des kiosques.

- **6 novembre, audit — lot R7 (référentiels, plages, concurrence)** :
  - import : un code d’emplacement ou de niveau n’est cherché que dans son parent ; un niveau homonyme dans deux bâtiments est signalé « ambigu » (colonne `building_code` pour lever l’ambiguïté) ;
  - un groupe d’écrans peut porter une plage de fonctionnement, héritée par ses écrans sans plage propre ;
  - une modification de campagne, de contenu, de modèle ou d’occurrence sans la version lue est refusée (428).

- **6 novembre, après la synchronisation GitHub** :
  - CI de nouveau exécutable (le fichier du workflow était un YAML invalide) et verte sur tous ses jobs, y compris la suite de bout en bout ;
  - vidéos : la version transcodée en H.264 est réellement diffusée ; une vidéo illisible et non transcodable est refusée au dépôt ;
  - lecteurs : jetons à durée de vie de 90 jours, renouvelés automatiquement ; rotation de la clé de signature sans réappairage ; intégrité de toute la version publiée (SRI) ;
  - Policies Laravel pour les documents, médias, contenus et réservations (SEC-02), appliquées par les contrôleurs ;
  - sources dynamiques protégées du rebinding DNS, taille limitée pendant le transfert ;
  - vingt anomalies mineures de l'audit corrigées (voir l'historique Git) ;
  - accessibilité : contraste du texte ambre et taille des boutons de pagination conformes (axe WCAG 2.2 AA sur les 17 pages contrôlées).

- **6 novembre — contrat OpenAPI (C-38, E-07, API-02, API-06)** :
  - corps de requête des opérations d’écriture générés depuis les règles de validation du serveur (`saie:openapi`, lecture statique des contrôleurs) ; référentiels décrits type par type ;
  - schémas de réponse pour toutes les routes du tableau du chapitre 17 (`App\Support\OpenApi\ResourceSchemas`), pouvoir étendu en `x-power`, codes d’erreur métier en `x-error-codes`, codes HTTP propres à chaque action (304, 410, 428…) ;
  - test de contrat : chaque réponse de `/api/v1` produite par la suite PHPUnit est validée contre `docs/api/openapi.json` (code déclaré et schéma), et le contrat versionné doit correspondre aux routes ;
  - exemples réels anonymisés (`docs/api/examples.json`, régénérés par `SAIE_CONTRACT_RECORD=1 php artisan test`) ;
  - écarts révélés et corrigés : erreurs sans `request_id` (QR, compte en attente de MFA ou de changement de mot de passe) ; réponses de création sans les valeurs par défaut de la base (version initiale nulle d’une réservation, mode de validation et durées d’une salle, priorité d’un contenu, plage d’un groupe).
- **6 novembre — concurrence optimiste (API-04)** : la version attendue est réservée atomiquement à l'écriture (mise à jour conditionnelle), ce qui ferme la fenêtre entre contrôle et enregistrement ; salles, écrans, groupes d'écrans, documents, médias et comptes portent désormais une version et se modifient avec `If-Match` (428 sans version, 409 si elle est périmée), y compris depuis l'interface.
- **Restent ouverts sur le contrat** : 87 des 185 opérations n’ont pas d’exemple (non exercées par la suite) ; les opérations hors chapitre 17 ont un schéma de réponse générique (enveloppe `data` ou `data` + `meta`) ; une règle de validation dynamique (objet `Rule`, closure) n’est pas traduite et laisse le schéma permissif ; le frontend n’est pas généré depuis le contrat. Annulation et non-présentation portent sur une occurrence (`/occurrences/{id}/cancel`, `/no-show`), et non sur la réservation comme l’écrit le tableau du chapitre 17.

- **6 novembre — audit et mise à niveau des 22 modules** (`docs/AUDIT-MISE-A-NIVEAU-SAIE-CEEAC/`) :
  - preuve de diffusion durable (`campaign_transmissions`), renouvellement du manifeste sans fausse activation, lecture déclarée en fin de créneau avec durée, « dernière synchronisation » exacte, file d'événements du lecteur sérialisée ;
  - alerte critique affichée hors plage de fonctionnement (réglable, décision à confirmer) ;
  - protection hiérarchique des comptes d'administration, rôle « Agent » fermé aux droits d'administration, connexion sans énumération, plafond par adresse IP, périmètre sur l'appairage et la révocation ;
  - fiche matérielle et état opérationnel des écrans, statut « en maintenance », incidents catégorisés et affectés à un intervenant habilité et prévenu ;
  - antivirus en échec sûr en production, bureautique refusé à la soumission, zones de modèle bornées ;
  - corrigés dans « Priorité 3 » ci-dessous : codes d'exigence et « heartbeat » visibles sur les écrans relevés, les 3 types de notification sans libellé, l'intitulé « Programmation ».
  - **second passage (soir)** : début de validité des contenus appliqué, avertissement d'orientation, corbeille des médias et des documents sûre, doublons signalés, abonnement iCalendar personnel révocable, incidents automatiques (stockage, horloge, synchronisation), téléchargements reprenables, relance à distance du lecteur, terminaux non autorisés tracés, titres de page et focus accessibles ;
  - **troisième passage (nuit)** : rôles supplémentaires, verrouillage progressif, circuit de validation à plusieurs niveaux, double validation des alertes et des documents, conservation et archivage du journal, export Excel et rapports cloisonnés, paramètres étendus, métriques et journal des erreurs, maintenance préventive, listes de lecture réutilisables, droits des médias, présentations diffusées en PDF, annuaire et borne d'orientation tactile. Restent externes : test d'intrusion, ClamAV réel, synchronisation entrante de l'agenda ;
  - Écarts restants et décisions D1 à D7 : `docs/AUDIT-MISE-A-NIVEAU-SAIE-CEEAC/02-MATRICE-ECARTS-FONCTIONNELS-TECHNIQUES.md` et `06-PLAN-MISE-A-NIVEAU.md`.

### Priorité 1 — décisions à prendre

Tranchées le 7 novembre (décision D7, voir `decisions.md`) et appliquées : participants réservés aux personnes concernées, « Générer des exports » exigé pour les extractions de masse, permission dédiée aux versions du lecteur.

### Priorité 2 — fonctions qui ne se comportent pas comme annoncé


### Priorité 3 — libellés et textes

Corrigés le 7 novembre : codes d’exigence retirés des écrans, « manifeste » et « heartbeat » remplacés par « programme » et « signal », commandes de développeur remplacées par un renvoi au guide de mise en service, statuts et gravités en français dans les PDF03 et PDF08, gravité traduite sur le tableau de bord, menus « Exports & traitements » et « Campagnes » cohérents, confirmation de réservation proposée aux seules personnes qui peuvent l’obtenir, message de fermeture nommant les réservations touchées, assistant d’alerte borné à la durée maximale du serveur et mentionnant les écrans individuels.

Décision validée le 7 novembre : la confirmation de réservation (PDF01) suit la vue complète de la réservation. Le suppléant saisi d’une escalade, qui voit déjà toute la réservation, peut donc aussi l’obtenir.

## Points d’exploitation

- Les comptes de démonstration partagent un mot de passe généré ; il ne doit jamais servir hors d’un poste de développement.
- Le simulateur `saie:demo-fleet` maintient artificiellement le contact des lecteurs fictifs pour les démonstrations ; il est refusé en production.
