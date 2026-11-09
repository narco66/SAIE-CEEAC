# Manuel contributeur

## À qui s’adresse ce manuel

Ce manuel s’adresse aux agents qui ont le rôle **Contributeur** : ils composent les annonces multilingues destinées aux écrans, déposent les fichiers de la médiathèque, publient les documents de leur structure, préparent l’accueil des délégations et peuvent réserver une salle. Le contributeur ne valide jamais ses propres contenus et ne programme pas leur diffusion : ces étapes relèvent du validateur éditorial et du diffuseur.

Vos menus sont : **Tableau de bord**, **Tâches & validations**, **Notifications**, **Contenus**, **Médiathèque**, **Documents**, **Modèles** (en consultation), **Salles & réunions**, **Calendrier des salles**, **Mes réservations**, **Accueil & protocole** et **Exports & traitements**. Les heures saisies et affichées sont celles de Libreville (UTC+1).

## Se connecter

Voir [Premiers pas](premiers-pas.md) pour la connexion, le second facteur, le compte et les notifications.

## Composer un contenu multilingue

Un contenu est une annonce affichable sur les écrans. Il est composé dans un assistant en cinq étapes : **Informations**, **Composition**, **Langues**, **Vérification**, **Soumission**. Vous pouvez passer d’une étape à l’autre avec **Continuer** et **Retour**, ou en cliquant sur le nom de l’étape.

### Étape 1 — Informations

1. Ouvrez **Contenus**, puis **Nouveau contenu**.
2. Saisissez le **Titre du contenu** (titre interne de l’annonce, 200 caractères au plus).
3. Choisissez la **Catégorie** (obligatoire).
4. Vérifiez le **Propriétaire** : par défaut, votre structure.
5. Choisissez la **Classification** (voir ci-dessous).
6. Réglez la **Priorité**, de 1 à 100 ; 50 correspond à une priorité normale.
7. Indiquez, si besoin, le **Début de validité** (facultatif).
8. Pour une annonce temporaire, renseignez l’**Expiration** : elle est obligatoire et doit être dans le futur. L’heure proposée par défaut est 18:00. Pour une annonce sans fin, cochez **Contenu permanent (sans date d’expiration)**.

**Ce que la classification implique pour la diffusion.** Chaque zone d’écrans accepte une classification maximale :

| Classification | Où le contenu peut être diffusé |
| --- | --- |
| **Public** | Sur les écrans de toutes les zones, y compris les zones ouvertes au public. Son QR code s’ouvre sans authentification. |
| **Interne** | Uniquement sur les écrans des zones internes ; les écrans des zones publiques sont écartés lors de la programmation. Son QR code exige une authentification. |
| **Restreint** | Sur aucun écran collectif. Un contenu restreint ne peut pas être soumis à validation. |

### Étape 2 — Composition

1. Choisissez un **Modèle** : **Sans modèle** (mise en page par défaut, 16:9) ou l’un des modèles proposés, en paysage 16:9 ou en portrait 9:16. L’orientation du modèle fixe les limites de longueur des textes (voir l’étape 3).
2. Pour ajouter une image, une vidéo ou un PDF, cliquez sur **Choisir un média**. Seuls les médias à l’état **Prêt** sont proposés ; les fichiers bureautiques ne sont pas diffusables sur écran. Un contenu porte **6 médias au plus**. Sans média, le message est composé de texte seul.
3. Pour un PDF, cliquez sur les pages à diffuser (50 pages au plus) et réglez la **Durée par page (secondes)**, entre 5 et 120 secondes (12 par défaut).
4. Pour retirer un média, utilisez l’icône **Retirer ce média** sur sa ligne.
5. Pour afficher un QR code, cochez **Afficher un QR code**, puis :
   - choisissez éventuellement un **Document lié** : seuls les documents publiés, non restreints, sont proposés. Sans document, le QR renvoie vers la fiche du contenu ;
   - saisissez si besoin le **Libellé du QR** (60 caractères au plus).

Un média **Restreint** ne peut pas illustrer un contenu public : il est signalé par la mention **Média restreint**.

### Étape 3 — Langues

1. Les onglets **FR**, **EN**, **PT** et **ES** correspondent aux langues. Une langue désactivée par l’administration porte la mention **Inactif** et ne peut pas être saisie.
2. Pour chaque langue à diffuser, saisissez le **Titre** (obligatoire) et, si besoin, le **Texte**.
3. Respectez les limites affichées sous chaque champ :

| Orientation du modèle | Titre | Texte |
| --- | --- | --- |
| Paysage (16:9, et **Sans modèle**) | 90 caractères | 420 caractères |
| Portrait (9:16) | 70 caractères | 320 caractères |

4. Contrôlez l’aperçu à droite. Il mesure le rendu réel dans le modèle : si le texte ne tient pas, le message **Le texte déborde du modèle.** apparaît et la soumission sera refusée. Raccourcissez le titre ou le texte jusqu’à voir **La version … tient dans le modèle.**
5. **Sous-titres** : si le contenu comporte une vidéo, chaque langue exige un fichier de sous-titres WebVTT (une vidéo est diffusée sur écran muet). Cliquez sur **Déposer un .vtt** (ou **Remplacer**), choisissez le fichier, puis **enregistrez le brouillon** pour l’associer. Le fichier est analysé comme tout dépôt ; la langue ne peut être soumise qu’une fois ses sous-titres déclarés sains.

Chaque langue est validée séparément. Une langue non approuvée est ignorée à la diffusion.

### Étape 4 — Vérification

La liste de contrôle récapitule ce qui bloquerait la soumission : métadonnées, expiration, classification, état des médias, compatibilité de classification des médias, longueur et débordement de chaque langue, sous-titres, enregistrement du brouillon. Chaque ligne marquée à corriger renvoie à une étape précédente. Les mêmes contrôles sont refaits au moment de la soumission.

### Enregistrer le brouillon

1. Cliquez sur **Enregistrer le brouillon** à tout moment. Au premier enregistrement, au moins une langue doit avoir un titre.
2. Le contenu reçoit une référence ; le message **Brouillon enregistré** le confirme. Les enregistrements suivants affichent **Modifications enregistrées** et le numéro de version.
3. Quand tout est enregistré, le bouton indique **Enregistré**. Si vous fermez la page avec des modifications non enregistrées, le navigateur vous avertit.

## Soumettre un contenu, langue par langue

1. Enregistrez le brouillon : la soumission est impossible tant que des modifications ne sont pas enregistrées.
2. Allez à l’étape **Soumission**. Les langues en **Brouillon** ou **À corriger** sont listées.
3. Cochez les langues prêtes : vous pouvez soumettre une langue sans attendre les autres.
4. Cliquez sur **Soumettre** (le bouton indique le nombre de langues retenues). Le message **Soumission enregistrée** confirme l’envoi.
5. Pour chaque langue, une carte indique le validateur désigné et la date de **Décision attendue avant le**.

## Suivre l’état d’un contenu

Dans **Contenus**, les onglets classent vos contenus par statut. Dans l’éditeur, le panneau **État par langue** donne le statut de chaque version, le panneau **Validations** les échéances, escalades et suppléants, et le panneau **Historique** la chronologie complète.

| Statut du contenu | Signification |
| --- | --- |
| **Brouillon** | En cours de rédaction ; aucune langue soumise ni approuvée. |
| **En attente** | Au moins une langue est soumise à un validateur. |
| **Approuvé** | Au moins une langue est approuvée : le contenu peut être programmé par un diffuseur. |
| **Programmé** | Le contenu figure dans une campagne programmée. |
| **En diffusion** | Le contenu figure dans une campagne en cours. |
| **Expiré** | La date d’expiration est passée : le contenu n’est plus diffusé ni modifiable. |
| **Archivé** | Le contenu est retiré (par exemple, le message de bienvenue d’une réunion annulée) : il n’est plus modifiable. |

Chaque langue a son propre statut : **Brouillon**, **Soumis**, **Approuvé** ou **À corriger**. Une langue retournée par le validateur apparaît dans l’onglet **À corriger** et porte la mention **Retour à corriger** dans la liste.

Vous recevez une notification à chaque décision (**Version approuvée** ou **Version retournée pour correction**), et une notification **Expiration prochaine** environ deux jours avant l’expiration d’un contenu soumis ou approuvé.

## Corriger un contenu après un retour

1. Ouvrez **Tâches & validations** : la tâche **Corriger la version …** mène directement à l’éditeur. Vous pouvez aussi passer par **Contenus**, onglet **À corriger**, puis **Ouvrir l’édition** dans le menu d’actions de la ligne.
2. Lisez le motif : il s’affiche sous **Retour du validateur :** à l’étape **Langues**, et dans **Dernier motif de retour**.
3. Corrigez la langue concernée, puis **Enregistrer le brouillon**.
4. À l’étape **Soumission**, cochez la langue et cliquez sur **Soumettre**.

**Modifier un contenu déjà approuvé ou en validation.** Toute modification du texte d’une langue crée une nouvelle révision de cette langue, à revalider. Toute modification du média, de la classification, du modèle, de l’expiration ou du QR crée une nouvelle révision de **toutes** les langues. Les validations en cours sont annulées. Les campagnes continuent d’utiliser la dernière version approuvée jusqu’à la nouvelle approbation.

## Dupliquer un contenu

1. Dans **Contenus**, ouvrez le menu d’actions de la ligne et choisissez **Dupliquer**.
2. La copie est créée en brouillon, sous une nouvelle référence, avec le titre suivi de « (copie) » ; vous en êtes l’auteur et toutes ses langues repartent en **Brouillon**.
3. L’éditeur de la copie s’ouvre : vérifiez notamment les dates de validité et d’expiration avant de la soumettre.

## Ce qui se passe après la soumission

1. Chaque langue soumise est confiée à un validateur éditorial **distinct de l’auteur**, habilité pour cette langue et pour la structure propriétaire. Le validateur est désigné automatiquement.
2. L’échéance de décision est de deux jours ouvrés. Une validation non traitée à temps peut être escaladée au suppléant du validateur.
3. Le validateur approuve la version ou la retourne avec un motif obligatoire. Une version approuvée devient immuable.
4. Un diffuseur programme ensuite les versions approuvées sur les écrans compatibles avec la classification.

**Vous ne pouvez jamais valider votre propre contenu**, même si vous détenez par ailleurs le rôle de validateur : l’action **Valider** ne vous est pas proposée et toute tentative est refusée et journalisée.

## Médiathèque

### Déposer un fichier

1. Ouvrez **Médiathèque**, puis **Importer des fichiers**.
2. Glissez-déposez le fichier ou cliquez sur **Parcourir…**.
3. Complétez **Titre**, **Classification** (obligatoire), **Langue** et **Catégorie**.
4. Cliquez sur **Déposer**. Ne fermez pas la fenêtre pendant l’envoi.
5. La fenêtre **Analyse du fichier** suit les contrôles. À la fin, **Fichier sain et prêt** s’affiche ; cliquez sur **Ouvrir la fiche** pour consulter le média.

| Type | Formats acceptés | Taille maximale |
| --- | --- | --- |
| Image | JPEG, PNG, WebP | 50 Mo |
| Vidéo | MP4, WebM | 500 Mo |
| PDF | PDF | 50 Mo |
| Bureautique | DOCX, PPTX, XLSX | 50 Mo |

Un fichier bureautique n’est pas diffusable sur écran : il sert aux documents. Le dépôt peut aussi être refusé si le quota de stockage de votre structure est atteint.

### États d’analyse

| État | Signification |
| --- | --- |
| **Quarantaine** | Le fichier est déposé ; l’analyse va démarrer. |
| **Analyse** | Vérification du type réel du fichier, calcul de son empreinte, contrôle antivirus, puis miniature et métadonnées. |
| **Prêt** | Le fichier est sain : il peut être utilisé dans un contenu ou un document. |
| **Rejeté** | Le contrôle a échoué ; la raison figure dans **Détail d’analyse**. |
| **Corbeille** | Le média a été retiré de la médiathèque. |

Un fichier est notamment rejeté si son contenu réel ne correspond pas à son extension, si l’antivirus détecte une menace, si une image est illisible ou si un fichier bureautique contient un programme exécutable. Aucun aperçu ni téléchargement n’est possible avant l’état **Prêt**. Un fichier rejeté ne peut pas être corrigé : déposez un fichier sain.

### Gérer un média

Depuis la fiche du média :
- **Enregistrer** les métadonnées modifiées. La classification d’un média utilisé ne peut changer qu’après révision des contenus concernés ;
- **Nouvelle version** : le fichier de remplacement doit être du même type (une image remplace une image) ; il est analysé à son tour ;
- **Utilisations** : liste des contenus et documents qui référencent le média.

### Mettre un média à la corbeille

1. Sur la fiche, cliquez sur **Mettre à la corbeille**, puis confirmez.
2. Si le média est utilisé par un contenu ou un document, la suppression est refusée et la liste des éléments à réviser s’affiche sous **Ce média est utilisé.**
3. Un média mis à la corbeille n’est plus proposé dans la médiathèque. Les versions approuvées déjà diffusées ne sont pas affectées.

## Documents

### Déposer un document

1. Ouvrez **Documents**, puis **Déposer un document**.
2. Glissez-déposez le fichier ou cliquez sur **Parcourir…** : PDF, DOCX, PPTX, XLSX ou image, 50 Mo au plus.
3. Complétez les métadonnées : **Titre** (obligatoire), **Résumé**, **Classification** (obligatoire), **Catégorie**, **Structure propriétaire**, **Langue**, **Mots-clés** (séparés par des virgules, 20 au plus), **Valide à partir du** et **Expire le**.
4. Pour un document interne ou restreint, cochez si besoin les **Publics destinataires (accusés de lecture)**.
5. Cliquez sur **Déposer**. Le document est créé en **Brouillon** et son fichier est analysé.

**Qui peut lire le document une fois publié :**
- **Public** : tous les agents ;
- **Interne** : tous les agents si aucun public n’est choisi ; sinon, les seuls agents des structures destinataires ;
- **Restreint** : uniquement l’auteur et les personnes habilitées à gérer les documents de la structure propriétaire. Un document restreint n’est jamais diffusé sur écran ni par QR.

### Publier

1. Ouvrez la fiche du document et attendez que le fichier soit à l’état **Prêt**.
2. Cliquez sur **Publier**. Le bouton reste inactif tant que le fichier n’est pas déclaré sain ou si le document est expiré.
3. À la publication, le QR code du document est créé, sauf pour un document restreint (**Aucun QR pour un document restreint.**). Pour un document interne, le lien ouvre l’authentification avant la consultation.

Pour modifier les métadonnées, utilisez **Modifier**, puis **Enregistrer**. Changer la classification d’un document publié modifie immédiatement ses droits d’accès.

### Déposer une nouvelle version

1. Sur la fiche, cliquez sur **Nouvelle version**.
2. Choisissez le **Fichier**, du même type que la version courante (un PDF remplace un PDF), et ajoutez si besoin des **Notes de version**.
3. Cliquez sur **Déposer la version**. La version précédente est archivée et reste accessible au seul propriétaire, dans **Historique des versions**.
4. Pendant l’analyse de la nouvelle version, le téléchargement et l’aperçu sont indisponibles.

### Dépublier

1. Cliquez sur **Dépublier**.
2. Saisissez le **Motif de la dépublication** (5 caractères au moins ; il est inscrit au journal), puis **Dépublier**.
3. La diffusion s’arrête : les liens QR sont désactivés et les écrans ne référencent plus le document. La fiche et l’historique restent consultables.

### Accusés de lecture

Le panneau **Accusés de lecture**, visible du seul propriétaire, indique qui a ouvert la version courante. Les ouvertures sont enregistrées pour un document interne doté de publics destinataires ; aucun suivi nominatif n’existe pour un document public.

### Diffuser par courriel

1. Sur la fiche d’un document **publié**, cliquez sur **Diffuser par courriel**.
2. Cochez les **Structures destinataires** et rédigez si besoin un **Message d’accompagnement** (1 000 caractères au plus).
3. Cliquez sur **Envoyer**. Le message **Document diffusé** indique le nombre de destinataires et, le cas échéant, d’agents écartés.

Ce qu’il faut savoir :
- chaque agent actif des structures choisies reçoit une notification et un courriel contenant un **lien vers la fiche**, ouvert après authentification ;
- **aucune pièce jointe** n’est envoyée ;
- les agents **non habilités** à lire le document sont **écartés** ;
- un document **restreint** est annoncé **sans son titre** ;
- le panneau **Diffusions par courriel** de la fiche conserve l’historique : date, nombre de destinataires, agents écartés, structures et auteur de l’envoi.

## Accueil & protocole

### Préparer une visite

1. Ouvrez **Accueil & protocole**, puis **Préparer une visite**.
2. **Délégation** : saisissez le **Nom de la délégation** (obligatoire), l’**Institution** et le **Nombre de visiteurs**.
3. **Réservation** : choisissez une réunion. Seules les réservations **confirmées**, **non confidentielles** et à venir sont proposées. Choisissez **Aucune réservation** pour enregistrer une visite sans message d’accueil.
4. **Message** :
   - avec une réservation, cochez les **Langues** du message (au moins une) et les **Drapeaux des États membres** à afficher. Le texte de bienvenue est généré dans chaque langue retenue ;
   - sans réservation, saisissez les **Notes (itinéraire, contacts, besoins particuliers)**.
5. **Écrans** : l’écran indique les écrans visés, ceux de l’accueil (rez-de-chaussée) et de l’étage de la salle.
6. **Validation** : cliquez sur **Créer le message** (ou **Enregistrer la visite** sans réservation).

### Faire valider le message de bienvenue

1. Après création, cliquez sur **Ouvrir le message** : le message est un contenu public, en brouillon, que vous pouvez relire et corriger dans l’éditeur.
2. Soumettez chaque langue comme tout contenu. **La validation est obligatoire** : le message n’est jamais diffusé sans approbation.
3. Sa validité commence deux heures avant la réunion et le message **expire à la fin de la réunion**. Si la réunion est déplacée, ces dates suivent automatiquement ; si elle est annulée, le message est archivé.

Aucun message de bienvenue n’est possible pour une réunion confidentielle ou non confirmée.

## Réservations et modèles

- **Mes réservations**, puis **Nouvelle réservation** : vous pouvez réserver une salle comme tout agent (voir le [manuel de l’agent](agent.md)).
- **Modèles** : consultation seule. La création et la modification des modèles exigent le pouvoir « modèles ».

## Règles à connaître

Une action est refusée dans les cas suivants :
- **Soumission d’un contenu** :
  - contenu restreint ;
  - contenu temporaire sans date d’expiration ;
  - média qui n’est pas à l’état **Prêt** ;
  - média plus classifié que le contenu (média interne dans un contenu public, média restreint dans un contenu public ou interne) ;
  - titre manquant ou texte trop long ;
  - débordement du modèle ;
  - vidéo sans sous-titres dans la langue soumise ;
  - contenu expiré ou archivé ;
  - langue qui n’est ni en **Brouillon** ni **À corriger**.
- **Modification d’un contenu** : contenu expiré ou archivé (lecture seule), ou contenu d’une structure hors de votre périmètre.
- **Validation** : un auteur ne valide jamais sa propre publication.
- **Médias** :
  - type non autorisé ou taille dépassée ;
  - quota de la structure atteint ;
  - nouvelle version d’un autre type ;
  - mise à la corbeille ou changement de classification d’un média utilisé.
- **Documents** :
  - publication d’un fichier non sain ou d’un document expiré ;
  - diffusion par courriel d’un document non publié ;
  - diffusion à des structures dont aucun agent n’est habilité ;
  - dépublication sans motif.
- **Accueil** : message de bienvenue pour une réunion confidentielle ou non confirmée.

Toutes vos actions (création, soumission, dépôt, publication, dépublication, diffusion) sont inscrites au journal d’audit.

## En cas de problème

| Message affiché | Que faire |
| --- | --- |
| « Le titre est obligatoire. » / « Choisissez une catégorie. » | Complétez l’étape **Informations**. |
| « La date d’expiration est obligatoire pour une annonce temporaire. » | Renseignez l’**Expiration** ou cochez **Contenu permanent (sans date d’expiration)**. |
| « Saisissez au moins une version linguistique (titre) avant le premier enregistrement. » | Saisissez au moins un titre à l’étape **Langues**. |
| « Le texte de la version … déborde du modèle : raccourcissez-le. » | Raccourcissez le titre ou le texte jusqu’à ce que l’aperçu tienne dans le modèle. |
| « Une vidéo diffusée sur écran muet exige des sous-titres en … » | Déposez un fichier .vtt pour cette langue, enregistrez, puis soumettez. |
| « Seuls les fichiers WebVTT (.vtt) sont acceptés. » | Choisissez un fichier de sous-titres au format WebVTT. |
| « Un média n’est pas déclaré sain : soumission impossible. » / « Le fichier de sous-titres n’est pas encore déclaré sain. » | Attendez la fin de l’analyse ; si le fichier est **Rejeté**, remplacez-le. |
| « Le média « … » est plus classifié que le contenu (… pour un contenu …) : il ne peut pas l’illustrer. » | Retirez ce média, choisissez-en un de classification égale ou inférieure, ou relevez la classification du contenu. |
| « Un contenu restreint est exclu de tous les écrans collectifs. » | Changez la classification si le contenu doit être affiché, ou ne le soumettez pas. |
| **Conflit de version.** | Quelqu’un a modifié le contenu entre-temps : cliquez sur **Recharger la version courante**, puis refaites vos modifications. |
| « Lecture seule : vous ne pouvez pas modifier ce contenu (statut ou périmètre). » | Le contenu est expiré, archivé ou hors de votre périmètre : dupliquez-le si vous devez le réutiliser. |
| « Fichier trop volumineux … » / « Type … non autorisé. … » | Réduisez le fichier ou convertissez-le dans un format accepté. |
| « Le quota de stockage de la structure est atteint. » | Mettez à la corbeille les médias inutilisés ou contactez l’administrateur fonctionnel. |
| « Une nouvelle version doit conserver le même type de média. » | Déposez un fichier du même type, ou créez un nouveau média ou document. |
| « Ce média est utilisé et ne peut pas être supprimé. » | Révisez d’abord les contenus et documents listés. |
| « Le fichier n’est pas déclaré sain : publication impossible. » | Attendez l’état **Prêt** ou déposez une nouvelle version saine. |
| « Le document est expiré. » | Modifiez la date **Expire le** avant de publier. |
| « Aucun agent actif de ces structures n’est habilité à lire ce document. » | Choisissez d’autres structures ou revoyez la classification et les publics du document. |
| « Aucun message de bienvenue n’est possible pour une réunion confidentielle. » | Enregistrez la visite sans message. |
| « Vous n’êtes pas autorisé à effectuer cette action. » | L’action dépasse vos droits ou votre périmètre ; adressez-vous au propriétaire de l’objet ou à l’administrateur fonctionnel. |

Si une erreur affiche un **Identifiant de diagnostic**, copiez-le et transmettez-le au support de la DSI (voir [Premiers pas](premiers-pas.md)).
