# Manuel Gestionnaire de salle

## À qui s’adresse ce manuel

Ce manuel s’adresse aux agents titulaires du rôle **Gestionnaire de salle**. Le gestionnaire administre les salles qui lui sont affectées : il valide ou refuse les demandes de réservation, règle les conditions de réservation de chaque salle, programme les fermetures et constate les réunions non honorées. Avec le pouvoir étendu « prestations », il traite aussi les prestations de réunion (installation, restauration, etc.) et tient les listes de contrôle.

Un gestionnaire n’agit que sur les salles dont il est désigné gestionnaire. Les autres salles restent consultables, mais ses actions y sont refusées.

## Se connecter

Voir [Premiers pas](premiers-pas.md) pour la connexion, le second facteur, le compte et les notifications.

Les entrées de menu utiles au gestionnaire se trouvent dans le groupe **Espaces** : **Salles & réunions**, **Calendrier des salles**, **Réservations à valider** et, si vous détenez le pouvoir « prestations », **Prestations**. Les demandes à traiter apparaissent aussi dans **Tâches & validations**.

Toutes les dates et heures sont exprimées en heure de Libreville (UTC+1).

## Traiter les réservations à valider

Une salle en mode **Validation par le gestionnaire** place chaque nouvelle demande **En attente**. Vous recevez la notification « Réservation à valider ».

1. Dans le menu, choisissez **Réservations à valider**. Seules les demandes portant sur vos salles sont listées.
2. Pour chaque demande, consultez la **Salle**, le **Créneau**, l’**Organisateur**, l’**Ancienneté**, la colonne **Blocage jusqu’à** (date à laquelle la demande sera libérée faute de décision) et la colonne **Échéance HAB-04** (date d’escalade au suppléant).
3. Cliquez sur l’objet de la demande pour ouvrir la fiche complète de la réservation si besoin.

### Approuver

1. Cliquez sur **Approuver**.
2. Confirmez avec **Approuver** dans la fenêtre.

Toutes les occurrences en attente de la réservation sont confirmées et l’organisateur est notifié.

### Refuser

1. Cliquez sur **Refuser**.
2. Saisissez le **Motif du refus** (5 caractères au minimum). Il est communiqué à l’organisateur et inscrit au journal.
3. Confirmez avec **Refuser la demande**.

Les occurrences en attente sont refusées et le créneau est libéré.

### Déplacer

1. Cliquez sur **Déplacer**.
2. Choisissez la **Salle**, la **Date** (pour une occurrence seule), le **Début** et la **Fin**, puis saisissez le **Motif du déplacement** (5 caractères au minimum).
3. Validez avec **Déplacer**. L’organisateur est notifié.

Les mêmes boutons **Approuver** et **Refuser** figurent en haut de la fiche d’une réservation qui contient des occurrences en attente.

### Délai de maintien d’une demande et escalade

- Maintien : une demande non traitée reste bloquée pendant le **Délai de blocage d’une demande** fixé dans les règles de la salle. Passé ce délai, elle est automatiquement refusée avec le motif « Délai de validation dépassé : créneau libéré », et l’organisateur reçoit la notification « Demande de réservation expirée ».
- Rappel : à la moitié du délai de validation, vous recevez « Rappel : validation en attente ».
- Escalade : 24 heures après la demande, la validation est escaladée ; vous-même et votre suppléant recevez « Validation escaladée au suppléant ». En l’absence de suppléant, les administrateurs fonctionnels sont prévenus. La colonne **Échéance HAB-04** affiche alors **Escaladée**.

Faites désigner un suppléant par l’administrateur fonctionnel avant vos absences.

## Consulter et régler la fiche d’une salle

1. Dans le menu, choisissez **Salles & réunions**, puis **Voir** sur la salle concernée.
2. La fiche comporte les onglets **Présentation**, **Disponibilités**, **Équipements**, **Règles**, **Fermetures**, **Listes de contrôle (P1)** et **Aperçu du panneau**.

### Règles de réservation

1. Ouvrez l’onglet **Règles**, puis **Modifier** (bouton présent seulement sur vos salles).
2. Réglez les champs :
   - **Capacité** (nombre de places) ;
   - **État** : **Réservable** ou **Inactive** (une salle inactive ne peut plus être réservée) ;
   - **Ouverture** et **Fermeture** : horaires pendant lesquels une réunion peut se tenir ;
   - **Préparation (min)** et **Remise en état (min)** : de 0 à 240 minutes, bloqués avant et après chaque réunion ;
   - **Durée minimale (min)** (15 à 600) et **Durée maximale (min)** (15 à 720, au moins égale à la durée minimale) ;
   - **Délai maximal (jours)** : jusqu’à combien de jours à l’avance on peut réserver (1 à 365) ;
   - **Délai de blocage (heures)** : durée de maintien d’une demande en attente (1 à 168) ;
   - **Mode de validation** : **Confirmation automatique** ou **Validation par le gestionnaire** ;
   - **Libération automatique (SAL-10)** : à cocher pour libérer les salles dont la présence n’est pas confirmée (voir plus bas).
3. Cliquez sur **Enregistrer**.

Si vous réduisez la capacité alors que des réservations futures la dépassent, la fenêtre **Réduction de capacité** les liste. Ces réservations sont conservées : prévenez les organisateurs ou déplacez-les, puis confirmez avec **Confirmer la réduction**.

### Équipements

1. Ouvrez l’onglet **Équipements**, puis **Modifier**.
2. Cochez les équipements fixes de la salle et indiquez leur quantité.
3. Cliquez sur **Enregistrer**.

### Fermetures et blocages

1. Ouvrez l’onglet **Fermetures**.
2. Dans **Programmer une fermeture**, saisissez la **Date de début**, l’**Heure de début**, la **Date de fin**, l’**Heure de fin**, la **Nature** (**Technique (maintenance)** ou **Institutionnelle**) et le **Motif** (5 caractères au minimum).
3. Cliquez sur **Programmer la fermeture**.

Pendant une fermeture, la salle apparaît **Indisponible** et aucune nouvelle réservation n’est acceptée. Les réservations existantes qui chevauchent la fermeture ne sont pas annulées : l’application vous indique leur nombre, et il vous revient de les déplacer ou de les annuler. Pour rouvrir la période, choisissez **Supprimer** sur la ligne de la fermeture et confirmez avec **Supprimer la fermeture**.

### Listes de contrôle

L’onglet **Listes de contrôle (P1)** rappelle que les listes d’ouverture et de remise en état sont tenues réunion par réunion depuis **Prestations** (bouton **Ouvrir les prestations**, si vous détenez le pouvoir « prestations »).

## Consulter le calendrier des salles

1. Dans le menu, choisissez **Calendrier des salles**.
2. Choisissez la **Vue** (**Vue jour**, **Vue semaine**, **Vue mois**, **Vue liste**), et en vue semaine les **Jours affichés** (5 jours ouvrés ou 7 jours).
3. Filtrez par **Niveau** ou par capacité minimale ; naviguez avec les flèches et revenez à **Aujourd’hui**.
4. Lisez la légende : **Libre**, **Préparation / remise en état (hachuré)**, **Réservé**, **En attente de validation**, **Indisponible (fermeture)**.
5. Cliquez sur un créneau occupé pour ouvrir la réservation ; **Planning PDF02** produit un planning imprimable.

Sur vos salles, les demandes en attente sont visibles même pour les réunions d’autres organisateurs. Les réunions confidentielles apparaissent sous la mention « Réservée » pour les autres utilisateurs.

## Déclarer une réunion non honorée

Lorsque vous constatez qu’une réunion confirmée ne s’est pas tenue :

1. Ouvrez la fiche de la réservation (depuis le calendrier ou la fiche de la salle).
2. Dans la section **Occurrences**, sur la ligne concernée, cliquez sur **Déclarer non honorée**. Le bouton n’apparaît qu’une fois l’heure de début passée.
3. Saisissez le **Motif (constat effectué)** (5 caractères au minimum).
4. Confirmez avec **Déclarer non honorée**.

L’occurrence passe au statut **Non honorée** et l’organisateur est notifié. Cette déclaration manuelle ne mesure aucune présence.

## Confirmation de présence et libération automatique

Sur une salle où la **Libération automatique (SAL-10)** est activée :

- l’organisateur confirme la présence depuis la fiche de la réservation, ou à l’aide du code de présence affiché sur sa fiche ;
- vous pouvez aussi confirmer vous-même avec **Confirmer la présence**, sur la ligne de l’occurrence ;
- la confirmation est possible de 15 minutes avant le début jusqu’à la fin de la réunion ;
- l’organisateur reçoit un rappel 15 minutes avant le début ;
- sans confirmation 15 minutes après le début, la salle est libérée automatiquement : l’occurrence passe à **Non honorée** avec le motif « Libération automatique : présence non confirmée 15 minutes après le début », et l’organisateur reçoit « Salle libérée automatiquement » ;
- les réunions prioritaires (fiche de réservation : **Priorité institutionnelle** « Oui ») ne sont jamais libérées automatiquement.

Si la règle est désactivée, la non-présentation se déclare uniquement à la main.

## Traiter les prestations

Cette section concerne les gestionnaires titulaires du pouvoir étendu « prestations ». Ce pouvoir est nominatif et daté : il est attribué par un administrateur pour une période limitée et peut être restreint à certains types de prestation. Hors de cette période, l’entrée **Prestations** disparaît du menu. Chaque action est inscrite au journal au titre de ce pouvoir.

Lorsqu’une réservation comporte une prestation de votre ressort, vous recevez « Nouvelle demande de prestation ».

### La file des prestations

1. Dans le menu, choisissez **Prestations**. La page **Prestations de réunion** s’ouvre.
2. Filtrez par **État** : **À traiter**, **Acceptée**, **Précision demandée**, **Prête**, **Impossible**, **Annulée**. Par défaut, la liste montre **Toutes (hors annulées)**.
3. Chaque ligne présente la **Réunion** (salle, nombre de participants, créneau), le **Type**, le **Besoin**, l’**Échéance**, le **Responsable** et l’**État**. L’échéance est fixée deux heures avant le début de la réunion ; une prestation non clôturée après l’échéance est marquée **En retard**.
4. Pour une réunion confidentielle, l’objet est masqué (« Réunion confidentielle »).

### Changer l’état d’une prestation

- **Accepter** : vous prenez la prestation en charge ; vous devenez son responsable.
- **Demander une précision** : saisissez la **Question à l’organisateur**, puis **Envoyer la demande**.
- **Déclarer prête** : la prestation est réalisée.
- **Impossible** : saisissez le **Motif**, puis **Déclarer impossible**.
- **Rouvrir** : remet une prestation prête ou impossible à l’état **Acceptée**.

La question et le motif sont obligatoires (500 caractères au plus) ; ils sont transmis à l’organisateur. L’organisateur est notifié à chaque changement d’état. Une prestation **Annulée** (réunion annulée) ne peut plus être traitée.

### Restauration

Pour une prestation de restauration, l’organisateur doit indiquer le nombre de couverts et l’heure de service, et cette heure doit tomber pendant la réunion. Ces informations s’affichent dans la colonne **Type** (par exemple « 25 couverts · service à 12:30 ») et sur la fiche de préparation.

### Listes de contrôle par occurrence

1. Sur la ligne de la prestation, cliquez sur **Liste de contrôle** (le compteur indique les points faits sur le total).
2. La liste comporte deux parties : **Ouverture (avant la réunion)** et **Remise en état (après la réunion)**.
3. Ajoutez un point avec le champ « Nouveau point de contrôle » puis **Ajouter**.
4. Cochez les points effectués et ajoutez au besoin une « Observation ».
5. Cliquez sur **Enregistrer les constats**.

Chaque point coché est horodaté et attribué à son auteur lors de l’enregistrement ; il ne peut plus être décoché. La liste est propre à chaque occurrence de réunion.

### Fiche de préparation PDF03

Cliquez sur **PDF03** sur la ligne de la prestation, ou sur la ligne d’une occurrence dans la fiche de réservation. La fiche reprend la réunion, le lieu, le créneau, l’heure de montage (début de la préparation), la fin de remise en état, le nombre de participants, le matériel de la salle, les ressources mobiles, les prestations et la liste de contrôle. Le document est produit en quelques instants puis s’ouvre dès qu’il est prêt.

## Exporter le calendrier d’une salle

1. Ouvrez la fiche de la salle.
2. Cliquez sur **Exporter (.ics)**.
3. Importez le fichier obtenu dans votre agenda.

Le fichier contient les réservations en attente, confirmées et en cours de la semaine écoulée et des 8 semaines à venir. C’est un instantané : il ne se met pas à jour tout seul et les modifications faites dans votre agenda ne reviennent pas dans le SAIE. Pour les salles que vous gérez, l’objet des réunions confidentielles y figure : ne transmettez pas ce fichier.

Pour une seule réservation, le bouton **Ajouter à l’agenda** de la fiche de réservation produit un fichier du même type.

## Règles à connaître

- Une réunion bloque la salle de la durée de **Préparation** avant à la durée de **Remise en état** après : deux réunions ne peuvent pas se chevaucher sur ces périodes.
- Une réservation doit respecter les horaires d’ouverture, les durées minimale et maximale, le délai maximal et la capacité de la salle. Une salle **Inactive** n’est pas réservable.
- Une demande en attente est libérée automatiquement à la fin du délai de blocage de la salle ; l’escalade au suppléant intervient 24 heures après la demande.
- La non-présentation ne se déclare qu’après l’heure de début, et seulement pour une réunion confirmée, en cours ou terminée.
- La libération automatique intervient 15 minutes après le début sans confirmation de présence, uniquement si la salle l’a activée et jamais pour une réunion prioritaire.
- Une fermeture n’annule pas les réservations existantes.
- Les motifs (refus, déplacement, non-présentation, fermeture) comptent au moins 5 caractères et sont inscrits au journal.

## En cas de problème

| Message | Que faire |
|---|---|
| « Aucune occurrence en attente de validation. » | La demande a déjà été traitée ou a expiré : actualisez la liste. |
| « Le motif du refus est obligatoire. » | Saisissez un motif d’au moins 5 caractères. |
| « Un déplacement administratif exige un motif. » | Renseignez le **Motif du déplacement**. |
| « Le nouveau créneau n’est pas disponible. La réservation initiale est conservée. » | Choisissez un autre créneau ou une autre salle ; consultez le calendrier. |
| « Le créneau sort des horaires d’ouverture de la salle (…). » | Choisissez un créneau dans les horaires ou modifiez-les dans l’onglet **Règles**. |
| « La durée doit être comprise entre … et … minutes. » | Ajustez le créneau, ou les durées dans l’onglet **Règles**. |
| « Seule une réunion confirmée peut être déclarée non honorée. » | L’occurrence est en attente, annulée ou déjà déclarée : vérifiez son statut. |
| « La non-présentation ne peut être déclarée qu’après l’heure de début. » | Attendez l’heure de début de la réunion. |
| « La présence se confirme de 15 minutes avant le début jusqu’à la fin de la réunion. » | Confirmez pendant cette période. |
| « Cette réduction de capacité affecte des réservations futures. Confirmez pour continuer. » | Consultez la liste affichée, prévenez les organisateurs, puis **Confirmer la réduction**. |
| « Cette prestation est déjà clôturée. » | Utilisez **Rouvrir** pour la reprendre. |
| « Salle hors de votre périmètre. » | Vous n’êtes pas le gestionnaire désigné de cette salle : la décision revient à son gestionnaire ou à l’administrateur fonctionnel. |
| « Vous n’êtes pas autorisé à effectuer cette action. » | Votre pouvoir « prestations » a expiré ou ne couvre pas ce type, ou l’action dépasse vos droits : contactez l’administrateur fonctionnel. |
