# Manuel Validateur éditorial

## À qui s’adresse ce manuel

Ce manuel s’adresse aux agents titulaires du rôle « Validateur éditorial ». Le validateur relit les contenus destinés aux écrans de la Commission et, pour chaque langue (français, anglais, portugais, espagnol), décide de les approuver, de les retourner pour correction ou de réaffecter la décision. Une version approuvée devient immuable et peut ensuite être programmée par un diffuseur. Le validateur ne crée pas de contenu et ne programme pas la diffusion.

Le rôle permet aussi de consulter les documents et les salles et de réserver une salle : pour ces tâches, reportez-vous au [Manuel Agent](agent.md).

## Se connecter

Voir [Premiers pas](premiers-pas.md) pour la connexion, le second facteur, le compte et les notifications.

## Comprendre la validation par langue

- Un contenu comporte une version par langue. **Chaque langue est validée séparément** : approuver la version FR ne valide pas la version EN.
- Vous ne pouvez valider que les langues pour lesquelles vous êtes habilité. Votre habilitation précise ces langues ; si elle n’en précise aucune, elle couvre les quatre.
- Votre habilitation peut aussi être limitée à certaines structures : vous ne validez alors que les contenus dont la structure propriétaire entre dans votre périmètre.
- **Séparation auteur / validateur** : vous ne pouvez jamais valider un contenu dont vous êtes l’auteur.
- Statuts d’une version linguistique : « Brouillon », « Soumis », « Approuvé », « À corriger ».

## Comment vous êtes désigné

Quand un auteur soumet une langue, l’application désigne automatiquement un validateur parmi les agents actifs titulaires du rôle « Validateur éditorial », autres que l’auteur, habilités pour cette langue et dont le périmètre couvre la structure propriétaire du contenu. Entre plusieurs candidats, elle retient dans l’ordre :

1. celui dont l’habilitation cite **explicitement** la langue (avant ceux habilités par défaut pour toutes les langues) ;
2. celui dont le **périmètre est le plus proche**, c’est-à-dire couvrant le moins de structures (un périmètre sans restriction passe en dernier) ;
3. celui qui a la **charge** la plus faible, c’est-à-dire le moins de validations ouvertes ;
4. à égalité, l’ordre alphabétique du **nom**.

Le validateur désigné reçoit la notification « Nouvelle validation assignée ». Son suppléant éventuel est enregistré avec la demande.

## Consulter « Tâches & validations »

1. Ouvrez **Tâches & validations** dans le menu. Le nombre de tâches ouvertes apparaît à côté du libellé.
2. En haut de page, cliquez sur une carte pour filtrer : **À traiter**, **En retard**, **Escaladées**. La carte **Traitées ce mois** donne le nombre de vos décisions du mois (elle ne filtre pas).
3. Utilisez **Rechercher une tâche…** pour retrouver une tâche par son objet ou sa référence.
4. Lisez le tableau :
   - **Objet** : titre du contenu, type et langue concernée (FR, EN, PT ou ES) ;
   - **Action attendue** : par exemple « Valider la version EN », avec la mention « en tant que suppléant » si la tâche vous parvient par escalade ;
   - **Émetteur** : l’auteur qui a soumis ;
   - **Échéance** : date limite, en rouge si elle est dépassée ;
   - **État** : « À traiter », « En retard » ou « Escaladée ».
5. Cliquez sur **Ouvrir** pour accéder à l’écran de validation de la langue concernée.

Vous pouvez aussi ouvrir **Contenus**, puis, dans le menu d’actions d’une ligne, choisir **Valider la version FR** (ou EN, PT, ES). Depuis la fiche d’un contenu, le bouton **Valider** mène au même écran.

## Ouvrir une tâche et examiner la version

1. Les onglets de langue en haut de l’écran indiquent le statut de chaque version. Cliquez sur la langue à examiner.
2. Lisez le titre et le texte, puis le rendu simulé dans le modèle d’affichage.
3. Vérifiez les informations : **Classification**, **Cibles proposées**, **Version** (numéro de révision), **Validité**, **Modèle**, **Médias**.
4. Si une version de cette langue a déjà été approuvée, l’écran présente côte à côte la **Dernière version approuvée** et la **Version soumise** : comparez-les.
5. Un avertissement signale le cas échéant que le texte déborde du modèle.
6. Consultez à droite le bloc **Échéance** (validateur désigné, date limite, suppléant) et la **Chronologie** des événements de cette langue.
7. Le bouton **Ouvrir la fiche** affiche la fiche complète du contenu.

## Approuver une langue

1. Dans **Votre décision**, choisissez **Approuver la version**.
2. Relisez l’encadré récapitulatif (langue et numéro de révision).
3. Cliquez sur **Approuver**.

Un instantané immuable de la version est créé ; les autres langues ne changent pas. L’auteur reçoit la notification « Version approuvée ». La version peut désormais être programmée par un diffuseur.

## Retourner pour correction

1. Dans **Votre décision**, choisissez **Retourner pour correction**.
2. Saisissez le **Motif du retour** : il est obligatoire, doit compter au moins 5 caractères et sera transmis tel quel à l’auteur. Soyez précis (passage à corriger, raison).
3. Cliquez sur **Retourner pour correction**.

La version passe « À corriger » ; l’auteur reçoit la notification « Version retournée pour correction » et retrouve votre motif dans ses tâches. Les autres langues ne changent pas. Après correction, l’auteur soumet une nouvelle révision, qui fait l’objet d’une nouvelle demande de validation.

## Réaffecter la décision

1. Dans **Votre décision**, choisissez **Réaffecter**.
2. Choisissez le **Nouveau validateur**. La liste ne propose que les validateurs habilités pour la langue, compétents pour la structure du contenu et distincts de l’auteur. Si elle est vide (« Aucun autre validateur habilité »), personne d’autre ne peut prendre la décision.
3. Ajoutez si besoin un **Commentaire** (facultatif).
4. Cliquez sur **Réaffecter**.

Le nouveau validateur est notifié (« Validation réaffectée ») et l’échéance est conservée.

## Échéances, rappel et escalade

- **Échéance** : chaque demande doit être traitée dans un délai de 2 jours ouvrés après la soumission. Les week-ends et les jours non ouvrés du référentiel ne comptent pas.
- **Rappel** : à la moitié du délai, si la demande est toujours ouverte, le validateur désigné reçoit « Rappel : validation en attente ».
- **Escalade** : à l’échéance, la demande est escaladée ; le validateur désigné et son suppléant reçoivent « Validation escaladée au suppléant ». La tâche apparaît alors aussi chez le suppléant, avec la mention « en tant que suppléant », et passe à l’état « Escaladée ».
- **Sans suppléant** : si aucun suppléant n’est désigné, les administrateurs fonctionnels reçoivent « Tâche escaladée sans suppléant ». Un suppléant qui n’est pas lui-même habilité pour la langue ou la structure du contenu n’est pas retenu.

Tant que la demande est ouverte, la décision reste possible, même après l’échéance. Votre suppléant figure dans **Mon compte** (ligne **Suppléant**). Pour le désigner ou le changer, adressez-vous à l’administrateur chargé des comptes utilisateurs.

## Quand une demande disparaît de vos tâches

- Une autre personne habilitée a déjà décidé.
- L’auteur a modifié le texte de la langue, ou le média, la classification ou l’expiration du contenu : la demande en cours est annulée et une nouvelle révision devra être soumise.
- Le contenu a été archivé : ses demandes ouvertes sont annulées.

## Archivage

Votre rôle comporte le droit d’archiver les contenus. L’interface web actuelle ne propose toutefois pas de bouton d’archivage des contenus. Les contenus archivés se consultent dans **Contenus**, onglet **Archivé**. Un contenu archivé ou expiré ne peut plus être soumis à validation.

## Suivre vos notifications

Les principales notifications du validateur sont : « Nouvelle validation assignée », « Validation réaffectée », « Rappel : validation en attente », « Validation escaladée au suppléant ». Cliquez sur une notification pour ouvrir directement l’écran de validation. Marquer une notification comme lue ne vaut pas validation : ouvrez-la pour agir.

## Règles à connaître

- **Un auteur ne peut pas valider sa propre publication.** L’écran l’indique d’emblée par l’encadré « Décision non autorisée ». Chaque tentative refusée est inscrite au journal d’audit.
- **Chaque langue est décidée séparément**, par un validateur habilité pour cette langue.
- **Le périmètre compte** : un contenu dont la structure propriétaire sort de votre périmètre ne peut pas être décidé par vous.
- **Seule une version « Soumis » attend une décision.** Une version « Brouillon », « Approuvé » ou « À corriger » affiche « Aucune décision n’est attendue ».
- **Vous décidez sur une révision précise.** Si l’auteur a soumis une révision plus récente entre-temps, votre décision est refusée : rechargez la page.
- **Un retour exige un motif** d’au moins 5 caractères.
- **Une réaffectation** exige un validateur habilité pour la langue, distinct de l’auteur.
- **Une version approuvée est immuable.** Toute modification ultérieure par l’auteur crée une nouvelle révision à revalider ; les diffusions en cours continuent d’utiliser la dernière version approuvée.
- Votre identité, votre rôle et l’heure serveur sont inscrits dans l’historique de chaque décision.

## En cas de problème

| Message affiché | Que faire |
|---|---|
| Encadré « Décision non autorisée » : « Vous êtes l’auteur de ce contenu… » | Vous ne pouvez pas décider. Un autre validateur habilité doit le faire. |
| Encadré « Décision non autorisée » : « Vous n’êtes pas habilité à valider la version … de ce contenu, ou vos droits de validation ont expiré. » | Choisissez une langue pour laquelle vous êtes habilité, ou demandez la mise à jour de votre habilitation à l’administrateur. |
| « Un auteur ne peut pas valider sa propre publication. » | Vous êtes l’auteur : un autre validateur habilité doit décider. |
| « Vous n’êtes pas habilité pour cette langue. » | Demandez à l’administrateur d’ajouter cette langue à votre habilitation, ou laissez un validateur habilité décider. |
| « Contenu hors de votre périmètre. » | Le contenu appartient à une structure hors de votre périmètre de validation. |
| « Vous n’êtes pas autorisé à effectuer cette action. » | L’action dépasse vos droits : adressez-vous à l’administrateur fonctionnel. |
| « Une révision plus récente a été soumise. Rechargez la page. » | Cliquez sur **Recharger la révision courante**, relisez la nouvelle révision, puis décidez. |
| « Cette version n’est pas en attente de validation. » | Une décision a déjà été prise ou l’auteur a repris sa version. Rechargez la page. |
| « Un motif précis est obligatoire pour un retour. » | Saisissez un motif d’au moins 5 caractères. |
| « Le validateur choisi n’est pas habilité pour cette langue. » | Choisissez un autre validateur, habilité pour la langue et distinct de l’auteur. |
| « Objet absent ou inaccessible » | Le contenu n’existe plus ou n’entre pas dans votre périmètre. |
| « La cible n’est plus accessible avec vos droits actuels. » | La notification renvoie vers un contenu que vous ne pouvez plus ouvrir. |
| « Vous n’avez aucune action en attente. » | Aucune tâche ne vous est attribuée pour l’instant. |
| « Connexion au serveur impossible. Vérifiez le réseau puis réessayez. » | Vérifiez votre connexion réseau. |
