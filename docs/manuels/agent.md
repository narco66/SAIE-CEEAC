# Manuel Agent

## À qui s’adresse ce manuel

Ce manuel s’adresse aux agents de la Commission de la CEEAC titulaires du rôle « Agent ». Ce rôle permet de consulter le tableau de bord, la bibliothèque de documents et les salles, et de demander des réservations de salle. Il ne permet ni de publier, ni de valider, ni de gérer les salles.

## Se connecter

Voir [Premiers pas](premiers-pas.md) pour la connexion, le second facteur, le compte et les notifications.

## Ce que vous trouvez dans le menu

- **Vue générale** : **Tableau de bord**, **Tâches & validations**, **Notifications**.
- **Publication** : **Documents**.
- **Espaces** : **Salles & réunions**, **Calendrier des salles**, **Mes réservations**.
- **Pilotage** : **Exports & traitements** (vos documents PDF générés).

La barre **Rechercher dans SAIE…** (raccourci Ctrl K) propose, dès deux caractères saisis, les documents et les salles que vous êtes autorisé à voir. Toutes les heures sont exprimées en heure de Libreville (UTC+1).

## Consulter la bibliothèque de documents

1. Ouvrez **Documents** dans le menu.
2. Saisissez un mot dans le champ **Rechercher dans les métadonnées et le texte des PDF…** : la recherche porte sur les métadonnées et sur le texte des PDF.
3. Affinez si besoin avec les filtres **Classification**, **Statut**, **Catégorie** ou la case **Expire bientôt**.
4. Lisez le tableau : **Référence & titre**, **Propriétaire**, **Classification**, **Version**, **Mis à jour**, **Statut**, **Diffusion**.
5. Cliquez sur le titre d’un document pour ouvrir sa fiche.

Les compteurs en haut de page (documents publiés, internes, expirant sous 30 jours, en analyse) ne comptent que les documents que vous avez le droit de voir.

## Consulter une fiche document

1. Depuis la bibliothèque, cliquez sur le titre du document.
2. Le bloc **Résumé** indique la référence, le propriétaire, l’auteur, la catégorie, la classification, la langue, les mots-clés, la validité, la date de publication, l’autorisation de diffusion sur écran et les **Publics**.
3. Dans le bloc **Version courante**, cliquez sur **Télécharger** pour obtenir le fichier. Pour un PDF, **Aperçu** l’affiche dans la page (**Masquer l’aperçu** pour le refermer).
4. Le bouton **Fiche PDF10** produit une fiche descriptive du document au format PDF.
5. Le bloc **QR code** affiche le lien QR lorsque le document est publié. Pour un document interne, le lien demande de s’authentifier avant la consultation.
6. Le bouton **Bibliothèque** vous ramène à la liste.

### Ce qui vous est masqué

- **Documents restreints** : ils ne sont jamais listés pour un agent qui n’en est pas l’auteur.
- **Documents internes destinés à certaines structures** : si la ligne **Publics** de la fiche cite des structures, seuls les agents de ces structures voient le document. Sans structure citée (« Tous les agents habilités »), tout agent habilité le voit.
- **Documents non publiés** : un brouillon ou un document dépublié n’est pas visible.
- **Versions remplacées** : dans l’**Historique des versions**, seule la version courante est téléchargeable ; les autres portent la mention « Réservée au propriétaire ».
- **Accusés de lecture** : ils ne sont visibles que du propriétaire du document.

Un document expiré reste consultable, avec la mention « Ce document est expiré depuis le … ». Pour un document non public, chaque téléchargement est journalisé ; pour un document interne destiné à des structures, l’ouverture du fichier est enregistrée comme accusé de lecture.

## Réserver une salle

Vous pouvez lancer l’assistant **Nouvelle réservation** de plusieurs façons :

- depuis **Mes réservations** ou **Calendrier des salles**, bouton **Nouvelle réservation** ;
- depuis **Salles & réunions**, bouton **Réserver** sur la ligne d’une salle ;
- depuis la fiche d’une salle, bouton **Réserver cette salle** ;
- depuis le calendrier, en cliquant sur un créneau libre (la salle et l’heure sont alors préremplies).

L’assistant compte quatre étapes : **Besoin**, **Créneau**, **Vérification**, **Confirmation**. Votre saisie est conservée si une erreur survient.

### Étape 1 — Besoin

1. Renseignez **Objet interne**, **Date**, **Participants**, **Début** et **Fin**.
2. Cochez **Réunion confidentielle : le panneau affichera uniquement « Réservée »** si l’objet ne doit pas être affiché (voir plus bas).
3. Complétez si besoin la **Description**.
4. Pour une série, choisissez une **Récurrence** (voir « Séries récurrentes »).
5. Cochez les **Prestations** souhaitées (voir « Prestations »).
6. Cochez les **Ressources mobiles (P1)** nécessaires, s’il en existe.
7. Pour des invités extérieurs, cliquez sur **Ajouter un participant externe** et saisissez au moins le nom. Ne saisissez que le nom et l’organisation nécessaires à l’accueil.
8. Cliquez sur **Continuer**.

### Étape 2 — Créneau

1. Choisissez une salle dans la liste. Les salles libres sur le créneau apparaissent en premier ; une salle indisponible ne peut pas être sélectionnée ; une salle trop petite pour le nombre de participants n’est pas proposée.
2. Chaque salle affiche ses horaires, sa durée minimale et maximale, ses temps de préparation et de remise en état et son mode : « confirmation immédiate » ou « sur validation ».
3. Cliquez sur **Vérifier la disponibilité**.

### Étape 3 — Vérification

1. Le système contrôle le créneau sans rien enregistrer et affiche « Disponible » ou le nombre de conflits.
2. En cas de conflit sur une réservation simple, des **Créneaux alternatifs** peuvent être proposés : une autre salle au même horaire (« même horaire ») ou la même salle à une autre heure (« horaire décalé »). Cliquez sur l’un d’eux pour le reprendre.
3. Si aucune alternative n’existe, modifiez la date ou l’horaire avec **Retour**.
4. Cliquez sur **Confirmer la réservation** (salle à confirmation immédiate) ou **Soumettre la demande** (salle soumise à validation du gestionnaire).
5. Si une règle de la salle n’est pas respectée, le message s’affiche à ce moment ; utilisez **Corriger la saisie** pour revenir au champ concerné.

### Étape 4 — Confirmation

1. L’écran indique « Réservation confirmée » ou « Demande enregistrée » avec la référence.
2. Cliquez sur **Confirmation PDF01** pour obtenir le justificatif, ou sur **Ouvrir la réservation** pour la fiche.
3. Une demande en attente porte la mention « Demande non confirmée — en attente de validation » : le créneau est bloqué jusqu’à la décision du gestionnaire.

### Capacité, durée, horaires et délais

Chaque salle a ses propres règles, visibles dans l’onglet **Règles** de sa fiche (menu **Salles & réunions**, bouton **Voir**) :

- **Capacité** : le nombre de participants ne peut pas dépasser la capacité de la salle.
- **Durée** : la réunion doit durer entre la durée minimale et la durée maximale de la salle.
- **Horaires d’ouverture** : la réunion doit commencer et finir le même jour, dans les horaires de la salle.
- **Délai maximal de réservation** : on ne peut pas réserver plus d’un certain nombre de jours à l’avance.
- **Temps de préparation et remise en état** : la salle est bloquée avant et après chaque réunion. Deux réunions ne peuvent pas se chevaucher, temps de préparation compris. La colonne **Blocage salle** de la fiche réservation indique la période réellement bloquée.
- **Délai de blocage d’une demande** : dans une salle soumise à validation, une demande non traitée dans ce délai est libérée automatiquement ; vous recevez la notification « Demande de réservation expirée ».

### Séries récurrentes

1. Dans **Récurrence**, choisissez **Quotidienne**, **Hebdomadaire** ou **Mensuelle**, puis l’intervalle (de 1 à 12).
2. Pour une série hebdomadaire, cochez au moins un jour.
3. Choisissez la fin de série : **Après un nombre d’occurrences** (100 au maximum) ou **À une date** (6 mois au plus après la première occurrence).
4. Choisissez le comportement **En cas de conflit sur une occurrence** :
   - **Tout ou rien : aucune occurrence n’est créée** si une seule date est en conflit ;
   - **Partiel : créer uniquement les occurrences disponibles**.
5. À l’étape **Vérification**, l’aperçu liste chaque occurrence avec « Disponible » ou « Conflit ». Vous pouvez encore changer de mode à ce stade.
6. En mode partiel, l’écran final liste les occurrences non créées.

Une série mensuelle se répète le même jour du mois que la première occurrence. Toutes les occurrences doivent respecter les règles de la salle (horaires, durée, délai) : si une seule date les enfreint, la demande entière est refusée, quel que soit le mode choisi.

### Prestations

1. Cochez une prestation (la liste est tenue par l’administration) et précisez votre besoin dans le champ qui apparaît.
2. Pour **Pause-café et restauration**, deux champs supplémentaires sont obligatoires :
   - **Nombre de couverts** (prérempli avec le nombre de participants, modifiable) ;
   - **Heure de service**, qui doit se situer pendant la réunion, entre l’heure de début (incluse) et l’heure de fin (exclue).
3. Pour une série, l’heure de service doit tomber pendant chacune des occurrences.

Le suivi des prestations apparaît dans la fiche réservation : « À traiter », « Acceptée », « Précision demandée », « Prête », « Impossible » ou « Annulée ».

## Réunions confidentielles

Lorsque vous cochez la case de confidentialité :

- le panneau de salle, les agendas des écrans et le planning affichent seulement « Réservée » ;
- les autres agents voient le créneau occupé, sans objet ni organisateur, et ne peuvent pas ouvrir la fiche ;
- le fichier d’agenda exporté par un tiers porte le titre « Réservée » ;
- aucun message de bienvenue n’est possible ;
- la recherche par objet dans **Mes réservations** ne fonctionne pas : recherchez par référence.

Vous seul (et les gestionnaires de la salle) voyez l’objet, la description et les participants.

## Suivre « Mes réservations »

1. Ouvrez **Mes réservations** : seules les réservations dont vous êtes l’organisateur y figurent.
2. Choisissez l’onglet **À venir**, **Passées** ou **Récurrentes**.
3. Recherchez par **Référence ou objet…**.
4. Le tableau indique la salle, le créneau, le statut (« En attente », « Confirmée », « Refusée », « Annulée », « En cours », « Terminée », « Non honorée »), la confidentialité et les prestations.
5. Cliquez sur **Ouvrir** pour la fiche, ou sur **Annuler** pour annuler une occurrence à venir.

## Gérer une réservation depuis sa fiche

### Modifier une occurrence

1. Dans le tableau **Occurrences**, cliquez sur **Modifier** (possible seulement avant le début, pour une occurrence en attente ou confirmée).
2. Pour une série, choisissez **Cette occurrence uniquement** ou **Cette occurrence et les suivantes** (même salle et même horaire, chaque date conservée).
3. Ajustez **Salle**, **Date**, **Début**, **Fin**.
4. Cliquez sur **Enregistrer la modification**.

Tous les contrôles sont rejoués. En cas d’échec, la réservation initiale est conservée. Pour « Cette occurrence et les suivantes », la modification est appliquée à toutes les dates ou à aucune : si une seule date est en conflit, la série entière reste inchangée. Dans une salle soumise à validation, un changement de salle ou d’horaire repasse l’occurrence « En attente » et le gestionnaire de cette salle reçoit la demande à valider.

### Annuler une occurrence ou une série

1. Cliquez sur **Annuler** sur la ligne de l’occurrence.
2. Pour une série, choisissez la **Portée de l’annulation** : **Cette occurrence**, **Cette occurrence et les suivantes** ou **Toute la série**.
3. Saisissez le **Motif de l’annulation** (5 caractères minimum).
4. Confirmez. Le créneau est libéré et les prestations et ressources associées sont annulées.

### Confirmer la présence

Certaines salles appliquent la libération automatique : la ligne **Présence** du bloc **Résumé** l’indique (« libération automatique 15 min après le début sans confirmation »). Dans ces salles, une réunion confirmée dont la présence n’est pas confirmée 15 minutes après le début est déclarée « Non honorée » et la salle est libérée. Les réunions à priorité institutionnelle en sont exemptées.

1. Ouvrez la fiche de la réservation, de 15 minutes avant le début jusqu’à la fin de la réunion.
2. Cliquez sur **Confirmer la présence** sur la ligne de l’occurrence.
3. Validez dans la fenêtre : la réunion est marquée comme tenue et la salle n’est pas libérée.

La notification « Votre réunion commence dans 15 minutes » vous le rappelle. Dans ces salles, la colonne **Statut** vous affiche aussi un **Code de présence** propre à chaque occurrence ; il n’est visible que de l’organisateur.

### Ajouter à l’agenda et justificatif

- **Ajouter à l’agenda** télécharge un fichier d’agenda à importer dans votre messagerie. Il n’y a pas de synchronisation : après une modification, téléchargez-le de nouveau.
- **Confirmation PDF01** produit le justificatif de la réservation (réservé à l’organisateur). Une demande en attente y figure avec la mention « Demande non confirmée ».

### Ordre du jour

1. Dans le bloc **Ordre du jour**, cliquez sur **Modifier**.
2. Ajoutez des points avec **Ajouter un point** (30 au maximum) : horaire, intitulé, intervenant.
3. Pour joindre un document, saisissez au moins deux caractères dans **Rechercher un document de la bibliothèque**, puis cliquez sur **Associer**.
4. Cliquez sur **Enregistrer**.

## Consulter le calendrier des salles

1. Ouvrez **Calendrier des salles**.
2. Choisissez la **Vue** : **Vue jour**, **Vue semaine**, **Vue mois** ou **Vue liste**. En vue semaine, **Jours affichés** permet 5 jours ouvrés ou 7 jours.
3. Naviguez avec les flèches de période ou revenez à **Aujourd’hui**.
4. Filtrez par **Niveau** ou par capacité minimale (**Capacité min.**).
5. Les créneaux occupés portent « Réservé », « En attente » ou « Indisponible » ; les temps de préparation sont inclus. Les réunions confidentielles et les demandes en attente des autres agents apparaissent « Réservée », sans objet.
6. Cliquez sur un créneau libre pour réserver, ou sur une réunion visible pour ouvrir sa fiche.
7. **Planning PDF02** produit le planning de la période affichée, en **Version publique** (sans titres sensibles ni organisateurs), via **Générer le PDF**.

## Recevoir et traiter les notifications

1. La cloche en haut de l’écran affiche le nombre de notifications non lues ; cliquez sur une notification pour ouvrir l’objet, ou sur **Voir toutes les notifications**.
2. Dans **Notifications**, choisissez **Toutes** ou **Non lues**, puis **Ouvrir** ou **Marquer lu** ; **Tout marquer lu** traite toute la liste.

Pour vos réservations, vous pouvez recevoir notamment : « Réservation confirmée », « Demande de réservation enregistrée », « Réservation refusée », « Réservation annulée », « Votre réservation a été déplacée », « Demande de réservation expirée », « Réservation déclarée non honorée », « Salle libérée automatiquement », « Votre réunion commence dans 15 minutes ».

## Retrouver vos documents PDF

Si un PDF tarde, l’application le signale et le fichier apparaît ensuite dans **Exports & traitements**. Les fichiers prêts expirent automatiquement : cliquez sur **Relancer** pour en générer un nouveau.

## Règles à connaître

- Une réservation ne peut pas chevaucher une autre réservation de la même salle, temps de préparation et de remise en état compris.
- Le nombre de participants ne peut pas dépasser la capacité de la salle.
- Durée, horaires d’ouverture et délai maximal à l’avance sont propres à chaque salle (onglet **Règles**).
- Un créneau passé ou déjà commencé ne peut pas être réservé.
- Une série ne peut dépasser 100 occurrences ni 6 mois.
- En mode **Tout ou rien**, un seul conflit empêche toute la série.
- Seule une occurrence en attente ou confirmée, et pas encore commencée, peut être modifiée ou annulée.
- Un motif est obligatoire pour annuler.
- La présence ne se confirme que de 15 minutes avant le début jusqu’à la fin.
- Seul l’organisateur obtient la **Confirmation PDF01** et le **Code de présence**.
- Une salle soumise à validation ne confirme rien immédiatement : la demande reste « En attente » jusqu’à la décision ou l’expiration du délai de blocage.

## En cas de problème

| Message affiché | Que faire |
|---|---|
| « Le créneau demandé n’est pas disponible. » | Choisissez un créneau alternatif proposé, une autre salle ou un autre horaire. |
| « Au moins une occurrence de la série est en conflit : aucune occurrence n’a été créée. » | Passez en mode **Partiel** ou modifiez les dates de la série. |
| « Une réservation concurrente vient d’être confirmée sur ce créneau. Votre saisie est conservée. » | Un autre agent a réservé au même moment : choisissez une alternative. |
| « La salle accueille au plus … personnes. » | Réduisez le nombre de participants ou choisissez une salle plus grande. |
| « La durée doit être comprise entre … et … minutes. » | Ajustez **Début** ou **Fin**, ou choisissez une autre salle. |
| « Réservation possible au plus … jours à l’avance. » | Réservez plus tard ou choisissez une autre salle. |
| « Le créneau sort des horaires d’ouverture de la salle (…). » | Placez la réunion dans les horaires indiqués. |
| « Le créneau demandé est déjà passé. » ou « Ce créneau est déjà commencé. » | Choisissez une heure future. |
| « Une série ne peut dépasser 6 mois. » ou « Entre 1 et 100 occurrences. » | Raccourcissez la série. |
| « Indiquez le nombre de couverts. » ou « Indiquez l’heure de service. » | Complétez les champs de **Pause-café et restauration**. |
| « L’heure doit se situer pendant la réunion (…). » | Choisissez une heure de service comprise dans la réunion. |
| « La ressource … est déjà réservée sur ce créneau. » | Décochez la ressource ou changez d’horaire. |
| « Cette salle n’est pas réservable. » | La salle est inactive : choisissez-en une autre. |
| « Aucune salle active n’accueille … participant(s). Réduisez le nombre de participants ou contactez un gestionnaire. » | Réduisez le nombre de participants ou contactez un gestionnaire de salle. |
| « Le nouveau créneau n’est pas disponible. La réservation initiale est conservée. » | Votre réservation n’a pas changé : essayez un autre créneau. |
| « L’objet a été modifié par un autre utilisateur. Rechargez la version courante. » | Fermez la fenêtre, puis recommencez la modification. |
| « La présence se confirme de 15 minutes avant le début jusqu’à la fin de la réunion. » | Réessayez dans la plage indiquée. |
| « Aucune occurrence annulable. » | L’occurrence est déjà annulée, refusée ou commencée. |
| « Objet absent ou inaccessible » | Le document ou la réservation n’existe plus, est confidentiel ou n’entre pas dans votre périmètre. |
| « Seul l’organisateur, ou le titulaire du code de présence, peut confirmer. » | Seul l’organisateur de la réunion, le gestionnaire de la salle ou un participant muni du code de présence confirme la présence. Demandez le code à l’organisateur, puis utilisez **Confirmer avec un code**. |
| « Vous n’êtes pas autorisé à effectuer cette action. » | L’action est réservée à l’organisateur ou au gestionnaire. |
| « … téléchargement et aperçu indisponibles tant que l’analyse n’est pas saine. » | Le fichier est en cours d’analyse antivirus : réessayez plus tard. |
| « Cet export n’est plus disponible : relancez sa génération. » | Cliquez sur **Relancer** dans **Exports & traitements**. |
| « La cible n’est plus accessible avec vos droits actuels. » | L’objet de la notification n’est plus visible pour vous. |
| « Connexion au serveur impossible. Vérifiez le réseau puis réessayez. » | Vérifiez votre connexion réseau. |
