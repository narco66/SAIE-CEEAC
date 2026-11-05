# Manuel Diffuseur

## À qui s’adresse ce manuel

Ce manuel s’adresse aux agents titulaires du rôle **Diffuseur**. Le diffuseur programme sur les écrans de la Commission les contenus déjà approuvés par les validateurs : il compose les campagnes, choisit les écrans, fixe le calendrier et la priorité, suit la diffusion et gère les contenus de repli. Avec le pouvoir étendu « urgence », il peut aussi déclencher des alertes urgentes.

Le diffuseur ne rédige ni ne valide les contenus : il ne peut programmer que des versions approuvées.

## Se connecter

Voir [Premiers pas](premiers-pas.md) pour la connexion, le second facteur, le compte et les notifications.

Les entrées de menu utiles au diffuseur se trouvent dans les groupes **Publication** (**Programmation**, **Programme & repli**) et **Espaces** (**Écrans & lecteurs**, **Groupes de diffusion**, **Alertes urgentes**).

Toutes les dates et heures sont saisies et affichées en heure de Libreville (UTC+1, sans heure d’été).

## Consulter la programmation

1. Dans le menu, choisissez **Programmation**. La page **Campagnes** s’ouvre.
2. Filtrez par statut à l’aide des onglets : **Toutes**, **Brouillons**, **Programmées**, **En cours**, **Suspendues**, **Expirées**.
3. Recherchez une campagne dans le champ « Rechercher par nom ou référence… ».
4. Basculez entre la **Vue liste** et la **Vue calendrier** avec les deux boutons placés à droite de la recherche. La vue calendrier présente une semaine ; naviguez avec les flèches et revenez à la semaine courante avec **Cette semaine**.

Pour chaque campagne, la liste indique la **Priorité**, les **Langues · rotation** (par exemple FR → EN), la **Période**, le **Statut** (avec le motif en cas de suspension) et le nombre d’**Écrans**. Une campagne en mode événement porte la mention **Mode événement**. La dernière colonne indique combien de lecteurs ont reçu la programmation : ce chiffre ne représente pas une audience.

Le menu d’actions en bout de ligne (bouton à trois points) propose, selon le statut :
- **Ouvrir le suivi de diffusion** ;
- **Modifier** (brouillon ou campagne suspendue) ;
- **Publier / programmer** (brouillon) ;
- **Suspendre…** (campagne programmée ou en cours) ;
- **Reprendre** (campagne suspendue) ;
- **Bon de programmation (PDF04)**, document récapitulatif de la campagne.

## Programmer une campagne

1. Sur la page **Campagnes**, choisissez **Programmer une campagne**. L’assistant comporte cinq étapes : **Version approuvée**, **Langues**, **Cibles**, **Calendrier**, **Vérification**. Passez d’une étape à l’autre avec **Continuer** et **Retour**.

### Étape 1 — Version approuvée

2. Saisissez le **Nom de la campagne** (obligatoire).
3. Dans la liste des contenus approuvés, cliquez sur **Ajouter** pour chaque contenu à diffuser. Un contenu classé « restreint » porte la mention **Non diffusable** et ne peut pas être ajouté. Une campagne compte au plus 50 contenus.
4. Dans la **Liste de lecture**, réglez l’ordre de passage avec les flèches (monter, descendre) et retirez un contenu avec la corbeille. L’ordre de la liste est l’ordre de passage.
5. Vous pouvez fixer une durée propre à un contenu (de 10 à 600 secondes). Laissée vide, elle reprend la durée de diapositive de la campagne.

### Étape 2 — Langues et ordre de rotation

6. Cochez les langues à diffuser.
7. Dans **Ordre de rotation**, classez les langues avec les flèches (par exemple français puis anglais). Les versions passent dans cet ordre.
8. Si une langue n’est pas approuvée pour un contenu, l’assistant l’indique (« Ignorée pour : … ») : cette langue sera simplement sautée pour ce contenu. Aucune version non approuvée n’est jamais diffusée.

### Étape 3 — Cibles

9. Choisissez les cibles dans les onglets **Groupes**, **Écrans**, **Zones** et **Niveaux**. Vous pouvez les combiner ; les cibles retenues s’affichent en haut de l’étape et se retirent avec la croix.
10. Un écran visé plusieurs fois (par exemple par un groupe et par sa zone) n’est compté qu’une fois.

### Étape 4 — Calendrier

11. Choisissez le **Type de programme** : **Campagne ordinaire** ou **Mode événement (sommet, conseil des ministres, session statutaire)** (voir plus bas).
12. Renseignez le **Début** et la **Fin** (date et heure, obligatoires).
13. Cochez les **Jours de diffusion** (au moins un).
14. Facultatif : fixez un créneau avec **Créneau — début** et **Créneau — fin**. Renseignez les deux bornes ou aucune ; sans créneau, la campagne est diffusée toute la journée.
15. Fixez la **Priorité**, de 1 à 99 (50 par défaut).
16. Fixez la **Durée de diapositive (secondes)**, de 10 à 300 (12 par défaut).

### Étape 5 — Vérification

17. L’assistant calcule les écrans réellement ciblés. Il affiche :
    - le nombre d’**écrans effectifs** après dédoublonnage ;
    - les écrans **Exclu** : un écran situé dans une zone qui n’accepte pas la classification des contenus (par exemple un contenu interne dans une zone publique) est écarté automatiquement ;
    - les écrans hors ligne ou non appairés, sur lesquels la diffusion ne peut pas être garantie ;
    - les **conflits** : autres campagnes programmées sur les mêmes écrans pendant la même période, avec leur priorité et la conséquence (« même priorité : rotation ordonnée appliquée », « prioritaire sur cette campagne » ou « cette campagne est prioritaire »).
18. Si l’assistant affiche **Aucun écran compatible**, la programmation sera refusée : changez de cibles ou de contenus.

### Enregistrer ou programmer

19. **Enregistrer en brouillon** conserve la campagne sans la diffuser. Vous pouvez y revenir par **Modifier**.
20. **Programmer** (à la dernière étape) publie la campagne. Elle passe au statut **Programmée** si son début est à venir, ou **En cours** si son début est déjà atteint. Vous êtes ensuite dirigé vers le suivi de diffusion.

Si la programmation est refusée lors d’une première création, la campagne est tout de même enregistrée en brouillon et l’assistant vous ramène à l’étape à corriger.

## Suspendre et reprendre une campagne

Pour suspendre :
1. Dans la liste, ouvrez le menu d’actions de la campagne et choisissez **Suspendre…**.
2. Saisissez le **Motif de la suspension** (5 caractères au minimum ; il est inscrit au journal).
3. Confirmez avec **Suspendre la diffusion**.

La campagne est retirée des écrans : ceux-ci reprennent les autres programmations ou le contenu de repli. Le motif reste visible dans la liste.

Pour reprendre :
1. Ouvrez le menu d’actions de la campagne suspendue et choisissez **Reprendre**, ou ouvrez-la par **Modifier** et terminez par **Reprendre la diffusion**.
2. La reprise vérifie de nouveau les versions approuvées, les cibles et les dates, comme une première programmation.

Pour modifier une campagne programmée ou en cours, suspendez-la d’abord, modifiez-la, puis reprenez-la.

## Mode événement

Le mode événement sert aux périodes exceptionnelles (sommet, conseil des ministres, session statutaire).

1. Dans l’assistant, à l’étape **Calendrier**, choisissez **Mode événement (sommet, conseil des ministres, session statutaire)**.
2. Fixez une date de fin : la période ne peut pas dépasser 31 jours.
3. Terminez l’assistant comme pour une campagne ordinaire.

Pendant sa période :
- le mode événement remplace tout le programme ordinaire des écrans ciblés, quelle que soit la priorité des campagnes ordinaires ;
- seules les alertes urgentes passent avant lui ;
- si plusieurs modes événement visent le même écran au même moment, la règle de priorité s’applique entre eux ;
- à la date de fin, le programme ordinaire reprend automatiquement, sans intervention ;
- il ne modifie pas la plage de fonctionnement des écrans : un écran en dehors de sa plage reste en veille.

## Suivre la diffusion

1. Ouvrez le nom d’une campagne, ou choisissez **Ouvrir le suivi de diffusion** dans le menu d’actions.
2. La page présente une synthèse puis, pour chaque écran ciblé, les niveaux de preuve successifs :
   - **Programmé** : la campagne fait partie de la programmation de l’écran ;
   - la réception de cette programmation par le lecteur ;
   - **Téléchargé** : les contenus sont présents sur le lecteur ;
   - **Activé** : le lecteur déclare avoir mis la programmation en service ;
   - **Lecture déclarée** : le lecteur déclare avoir affiché la campagne ;
   - **Rendu capturé** : une capture d’écran existe ;
   - **Écran visible** : état de la dalle, connu seulement si l’écran dispose d’un canal de contrôle.
3. **Non mesuré** dans la colonne **Écran visible** signifie que l’écran n’a aucun canal de contrôle de la dalle : on ne sait pas s’il est allumé. Ce n’est pas une erreur.
4. Cochez **Incidents seulement** pour n’afficher que les écrans en difficulté.
5. **Actualiser** recharge la page ; **CSV** et **Rapport PDF05** produisent le rapport de diffusion.

Ces preuves sont déclarées par le lecteur. Elles ne prouvent ni l’allumage de l’écran ni qu’une personne a vu le message, et aucune estimation d’audience n’est produite.

## Programme & repli

### Simuler ce qu’affiche un écran

1. Dans le menu, choisissez **Programme & repli**. La page **Programme et prévisualisation** s’ouvre.
2. Choisissez l’**Écran**, la **Date** et l’**Heure**, puis **Recalculer** si besoin.
3. Le résultat indique le mode appliqué (**Programme ordinaire**, **Alerte prioritaire**, **Contenu de repli** ou **Hors plage — veille**), la règle appliquée et la liste de lecture résolue.
4. La **Piste horaire** montre le résultat toutes les 30 minutes, de 08:00 à 18:00. Cliquez sur un horaire pour l’afficher en détail.

### Gérer les contenus de repli

Le contenu de repli est diffusé dans une zone lorsqu’aucun programme n’est valide. Sans repli, les écrans affichent l’écran institutionnel par défaut.

1. Dans la section **Contenus de repli**, choisissez **Ajouter un repli**.
2. Choisissez la **Zone d’affichage**, le **Contenu public approuvé**, la **Version approuvée (langue)** et la date **Valide jusqu’au (Africa/Libreville)**.
3. Validez avec **Ajouter**.
4. Pour retirer un repli, choisissez **Désactiver** sur sa ligne et confirmez. Les écrans de la zone utilisent alors le repli actif suivant, ou l’écran institutionnel par défaut.

Seules les versions publiques approuvées peuvent servir de repli.

## Alertes urgentes

Les alertes complètent les dispositifs de sécurité ; elles ne remplacent jamais les alarmes réglementaires ni les consignes des services compétents.

### Le pouvoir « urgence »

Déclencher, prolonger ou lever une alerte exige le pouvoir étendu « urgence ». Ce pouvoir est nominatif et daté : il est attribué à une personne par un administrateur, pour une période limitée, et il peut être restreint à certaines zones. Sans lui, la page **Centre des alertes** est en consultation seule. Lorsqu’il est actif, la page affiche « Votre pouvoir « urgence » est actif jusqu’au … ». À l’échéance, les boutons de déclenchement disparaissent.

Chaque publication, prolongation et levée est inscrite au journal avec votre identité et votre motif.

### Déclencher une alerte

1. Dans le menu, choisissez **Alertes urgentes**.
2. Choisissez un modèle (**Évacuation**, **Incendie**, **Incident technique**, **Message prioritaire**) ou **Déclencher une alerte**.
3. Étape **Message** : choisissez le **Niveau** (critique, avertissement, information, exercice), saisissez le **Titre** (120 caractères au plus) et le **Message** (280 caractères au plus). Vous pouvez cocher **Ajouter une traduction anglaise (affichée en alternance)**.
4. Étape **Portée** : choisissez **Tous les écrans** ou **Sélection** (niveaux, zones, groupes ou écrans). Fixez la **Durée avant expiration automatique (minutes)** : 30 minutes par défaut, 5 minutes au minimum, 240 minutes au maximum. L’assistant affiche le nombre d’écrans ciblés, en ligne, hors ligne ou suspects, et hors plage de fonctionnement.
5. Étape **Aperçu** : vérifiez le rendu plein écran.
6. Étape **Confirmation** : saisissez la **Justification** (10 caractères au minimum), cochez la case de confirmation, puis **Publier l’alerte**.
7. Étape **Suivi** : suivez la réception, puis **Terminer**.

L’alerte interrompt immédiatement les programmes ordinaires sur les écrans ciblés, y compris un mode événement. Elle expire automatiquement à l’heure prévue ; les programmes reprennent alors d’eux-mêmes.

### Prolonger, lever, suivre

- **Prolonger** : choisissez la **Durée supplémentaire** (de 5 à 120 minutes) et le **Motif de la prolongation**. La durée totale d’une alerte ne peut pas dépasser 240 minutes depuis son début.
- **Lever l’alerte** : saisissez le **Motif de la levée** puis **Lever l’alerte maintenant**. Les programmes ordinaires reprennent sur les écrans connectés.
- **Suivi de réception** : pour chaque écran, l’état réseau, la plage de fonctionnement et les heures de réception et d’activation. L’**Historique** conserve toutes les alertes, avec leur auteur, leur durée et leurs prolongations.

### Lecteurs hors ligne

Un lecteur déconnecté ne reçoit ni l’alerte, ni sa prolongation, ni sa levée. Un lecteur qui avait reçu l’alerte avant de se déconnecter continue de l’afficher jusqu’à sa reconnexion ou jusqu’à son expiration. Un écran hors de sa plage de fonctionnement est en veille et n’affiche pas l’alerte. L’application signale ces écrans sans jamais afficher de fausse confirmation.

## Consulter les écrans

1. Dans le menu, choisissez **Écrans & lecteurs**. La page **Parc des écrans** s’ouvre.
2. Les compteurs en tête (connectés, suspects, indisponibles, non appairés) servent aussi de filtres.
3. Filtrez par **Niveau**, **Zone**, **Groupe**, **État réseau** ou **Type d’écran**, ou recherchez un code ; **Réinitialiser** efface les filtres.
4. Ouvrez un écran pour consulter ses onglets **Identité**, **Lecteur**, **Programme**, **Fonctionnement** et **Historique**. L’onglet **Programme** montre ce que l’écran diffuse et les campagnes qui le ciblent dans les 7 prochains jours.

Un lecteur est **Connecté** s’il s’est manifesté depuis 90 secondes au plus, **Suspect** au-delà, et **Indisponible** après 5 minutes sans contact. La modification des écrans et l’appairage des lecteurs relèvent de l’administrateur technique.

## Gérer les groupes de diffusion

1. Dans le menu, choisissez **Groupes de diffusion**.
2. **Cibles effectives** affiche les écrans d’un groupe et leur état réseau.
3. Pour créer un groupe, choisissez **Nouveau groupe**, saisissez le **Code** (non modifiable ensuite), le **Nom** et, si besoin, la **Description**, cochez les écrans (par niveau, **Tout ajouter** / **Tout retirer**), puis **Créer le groupe**.
4. **Modifier** permet de changer le nom, la description et les écrans ; **Supprimer** retire le groupe.

Un groupe ciblé par une campagne active ne peut pas être supprimé.

## Règles à connaître

- Ordre de résolution sur un écran, à chaque instant : d’abord les alertes actives ; puis, s’il existe, le mode événement, qui écarte le programme ordinaire ; puis la priorité la plus élevée. Seules les campagnes de priorité la plus élevée sont diffusées ; à priorité égale, elles alternent en rotation ordonnée. Sans programme valide, l’écran affiche le contenu de repli de sa zone.
- Une campagne n’est diffusée que dans sa période, ses jours et son créneau, et seulement pendant la plage de fonctionnement de l’écran.
- Seules les versions approuvées sont diffusées. Un contenu restreint n’est jamais diffusable ; un contenu archivé, expiré ou suspendu bloque la programmation.
- Une campagne passe automatiquement à **Expirée** à sa date de fin, y compris si elle était suspendue.
- Une campagne compte au plus 50 contenus et 4 langues ; la priorité va de 1 à 99 ; la durée de diapositive va de 10 à 300 secondes.
- Le mode événement dure 31 jours au plus et le programme ordinaire reprend seul à sa fin.
- Une alerte dure 240 minutes au plus, prolongations comprises, et expire automatiquement.
- Tout motif de suspension, de levée ou de prolongation compte au moins 5 caractères ; la justification d’une alerte au moins 10.

## En cas de problème

| Message | Que faire |
|---|---|
| « Seule une campagne en brouillon ou suspendue peut être programmée. » | La campagne est déjà programmée ou en cours : rien à faire, ou suspendez-la pour la modifier. |
| « Suspendez la campagne avant de la modifier. » | Suspendez la campagne depuis la liste, modifiez-la, puis reprenez-la. |
| « La date de fin de la campagne est dépassée. » | Modifiez la date de fin à l’étape **Calendrier**, puis programmez de nouveau. |
| « Une campagne exige au moins un contenu, une langue et une cible. » | Complétez l’étape signalée par l’assistant. |
| « Le contenu … n’est pas diffusable. » | Le contenu est restreint, archivé, expiré ou suspendu : retirez-le de la liste de lecture. |
| « Le contenu … n’a aucune version approuvée dans les langues de la campagne. » | Ajoutez une langue approuvée pour ce contenu ou retirez-le. |
| « Aucun écran ciblé n’accepte la classification de ces contenus. » | Choisissez d’autres cibles, ou des contenus publics. |
| « Un mode événement dure au plus 31 jours : le programme ordinaire doit reprendre. » | Raccourcissez la période à l’étape **Calendrier**. |
| « L’objet a été modifié par un autre utilisateur. Rechargez la version courante. » | Cliquez sur **Recharger la version courante**, puis refaites vos modifications. |
| « Un contenu de repli doit être public et approuvé (PUB-05). » | Choisissez une version publique approuvée. |
| « Suppression impossible : des campagnes actives ciblent ce groupe. » | Retirez le groupe de ces campagnes ou attendez leur fin. |
| « Cette alerte n’est plus active. » | L’alerte a expiré ou a été levée : déclenchez-en une nouvelle si nécessaire. |
| « Durée maximale d’une alerte dépassée. » | Choisissez une prolongation plus courte ou déclenchez une nouvelle alerte. |
| « Vous n’êtes pas autorisé à effectuer cette action. » | Votre pouvoir « urgence » a peut-être expiré ou ne couvre pas les zones choisies : contactez l’administrateur fonctionnel. |
| **À compléter avant de continuer** | Corrigez les points listés sous ce titre dans l’étape en cours. |
