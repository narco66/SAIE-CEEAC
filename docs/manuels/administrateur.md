# Manuel administrateur

## À qui s’adresse ce manuel

Ce manuel s’adresse aux deux rôles d’administration du SAIE-CEEAC :
- l’**Administrateur fonctionnel**, qui gère les comptes, les rôles et délégations, les référentiels, les imports et les modèles d’affichage ;
- l’**Administrateur technique DSI**, qui gère les comptes, le parc d’écrans et de lecteurs, la supervision, les versions du lecteur et les paramètres techniques.

Chaque section indique le rôle concerné. Un menu, un onglet ou un bouton absent signifie que votre rôle ne donne pas le droit correspondant ; le serveur revérifie toujours vos droits.

| Section | Administrateur fonctionnel | Administrateur technique DSI |
| --- | --- | --- |
| Utilisateurs : comptes, sessions, désactivation | Oui | Oui |
| Utilisateurs : rôles et pouvoirs étendus | Oui | Consultation |
| Rôles & délégations | Oui | Consultation |
| Référentiels, Imports | Oui | Non |
| Modèles | Oui, avec le pouvoir « Modèles » | Non |
| Écrans & lecteurs, appairage | Consultation | Oui |
| Groupes de diffusion | Consultation | Oui |
| Supervision et versions du lecteur | Non | Oui |
| Paramètres fonctionnels | Oui | Oui |
| Paramètres techniques, sources dynamiques, intégrations | Consultation | Oui |
| Journal d’audit | Non (rôle Auditeur) | Non (rôle Auditeur) |
| Exports & traitements | Oui | Oui |

L’administration des salles (fermetures, équipements, validation) est décrite dans le [manuel du gestionnaire de salle](gestionnaire-salle.md). Les commandes d’installation et d’exploitation du serveur figurent dans [Mise en service et exploitation](../exploitation/mise-en-service.md).

## Se connecter

Voir [Premiers pas](premiers-pas.md) pour la connexion, le second facteur, le compte et les notifications.

Le second facteur est obligatoire pour les deux rôles d’administration, pour le Validateur éditorial et pour tout titulaire d’un pouvoir étendu en cours (urgence, modèles, prestations) lorsque le serveur l’impose : la ligne **Second facteur imposé** du panneau **Environnement**, dans **Paramètres**, l’indique. Tant qu’il n’est pas activé, ou tant qu’un mot de passe provisoire n’a pas été changé, le serveur refuse toute autre action : seule la page **Mon compte** est accessible.

## Gérer les utilisateurs

*Concerne : les deux rôles pour les comptes ; l’Administrateur fonctionnel seul pour les rôles et les pouvoirs étendus.*

Menu **Administration › Utilisateurs**. La liste se filtre par **Statut**, **Rôle** et **Structure**. La mention « Second facteur non activé » signale un compte qui ne l’a pas encore configuré.

### Créer un compte

1. Choisissez **Nouvel utilisateur**.
2. Renseignez **Nom complet** et **Adresse professionnelle** (obligatoires), puis si besoin **Structure**, **Fonction / titre**, **Suppléant (HAB-04)** et **Langue d’interface**.
3. Validez par **Créer le compte**.

Aucun mot de passe n’est saisi ni affiché : un lien personnel de définition du mot de passe est envoyé à l’adresse professionnelle. Une adresse déjà utilisée est refusée. Un compte issu de l’annuaire garde un nom et une adresse non modifiables ici.

Vous ne pouvez pas modifier l’adresse de connexion d’un compte qui détient des droits d’administration, de validation, de diffusion ou un pouvoir étendu que vous n’avez pas : le refus est inscrit au journal. Lorsqu’une adresse est modifiée, l’ancienne adresse est prévenue et les sessions du compte sont fermées.

### Désigner un suppléant

1. Ouvrez le compte par **Détail**, puis **Modifier**.
2. Choisissez le **Suppléant (HAB-04)**, puis **Enregistrer**.

Désignez un suppléant pour chaque validateur et chaque gestionnaire de salle. Une validation non traitée à son échéance (2 jours ouvrés pour un contenu, 24 heures pour une réservation) est escaladée au suppléant. Sans suppléant, les administrateurs fonctionnels reçoivent la notification « Tâche escaladée sans suppléant » et le **Tableau de bord** affiche le nombre de tâches sans suppléant. Un suppléant n’est retenu que s’il est lui-même habilité pour la langue et la structure du contenu.

Si aucun validateur n’est habilité pour la langue ou la structure d’un contenu soumis, vous recevez « Validation sans validateur habilité » et la tâche apparaît dans **Tâches & validations** avec l’état « Sans validateur ». Attribuez le rôle « Validateur éditorial » pour cette langue et cette structure : la validation est alors affectée automatiquement, dans la minute, au validateur habilité, qui en est notifié.

### Attribuer un rôle daté et motivé

1. Ouvrez le compte par **Détail**, onglet **Affectations de rôles**.
2. Dans **Attribuer un rôle**, choisissez le **Rôle**.
3. Restreignez si besoin le périmètre : **Structure (périmètre)** (vide : toutes les structures) et **Zone de diffusion** (vide : toutes les zones).
4. Pour un **Validateur éditorial**, cochez les **Langues de validation**.
5. Indiquez **Début** (vide : immédiat, heure de Libreville) et **Fin** (vide : sans échéance).
6. Saisissez le **Motif** (5 caractères minimum, inscrit au journal), puis **Attribuer**.

Chaque affectation affiche son état : **Actif**, **À venir**, **Échu** ou **Révoqué**. Pour retirer un rôle avant son échéance : **Révoquer**, motif, puis **Révoquer le rôle**. Les droits issus du rôle cessent immédiatement.

### Attribuer un pouvoir étendu

Trois pouvoirs nominatifs complètent un rôle ; la permission correspondante n’agit que pendant la durée du pouvoir :

| Pouvoir | Rôle requis | Ouvre le droit de |
| --- | --- | --- |
| **Urgence** | Diffuseur | publier, prolonger et révoquer des alertes |
| **Modèles** | Administrateur fonctionnel | créer et archiver des modèles |
| **Prestations** | Gestionnaire de salle | traiter les prestations |

1. Ouvrez le compte par **Détail**, onglet **Pouvoirs étendus**.
2. Choisissez le **Pouvoir**. Un encadré indique si l’utilisateur détient le rôle requis ; sans ce rôle, le serveur refusera.
3. Indiquez **Début** (vide : immédiat) et **Fin**, obligatoire et dans le futur : un pouvoir étendu est toujours daté.
4. Pour **Urgence**, cochez si besoin les **Zones autorisées (vide : toutes les zones du rôle)**. Pour **Prestations**, cochez si besoin les **Types de prestation (vide : tous)**.
5. Saisissez le **Motif**, puis **Attribuer le pouvoir**.

Pour y mettre fin avant l’échéance : **Révoquer**, motif, puis **Révoquer le pouvoir**.

### Vérifier les droits effectifs d’un compte

Onglet **Droits effectifs** : rôles actifs, pouvoirs en vigueur, langues de validation et liste des permissions réellement ouvertes, regroupées par domaine.

### Désactiver, réactiver, débloquer, couper les sessions

1. Ouvrez le compte par **Détail**.
2. Choisissez l’action :
   - **Révoquer les sessions** puis **Révoquer toutes les sessions** : l’utilisateur est déconnecté partout et doit se reconnecter ;
   - **Désactiver**, **Motif**, puis **Désactiver et couper les sessions** : les sessions sont fermées et l’utilisateur ne peut plus se connecter ; ses actions passées restent au journal ;
   - **Réactiver**, saisissez le **Motif**, puis **Réactiver** : l’utilisateur peut de nouveau se connecter, ses rôles encore valides s’appliquent aussitôt et un verrouillage éventuel est levé.
   - **Débloquer** (proposé quand la fiche indique « Verrouillé jusqu’au … ») : saisissez le **Motif** après avoir vérifié l’identité de la personne. Le compteur d’échecs est remis à zéro et la personne peut se reconnecter immédiatement.
   - **Réinitialiser le second facteur** (proposé quand il est activé) : à utiliser quand la personne a perdu son téléphone et ses codes de secours. Vérifiez d’abord son identité, puis saisissez le **Motif**. Son application et ses codes cessent de fonctionner et ses sessions sont fermées et elle est prévenue par courriel ; elle configure un nouveau second facteur à sa prochaine connexion.

Un compte ne se supprime pas : il se désactive. Vous ne pouvez ni désactiver, ni débloquer, ni réinitialiser votre propre compte, et vous ne pouvez pas vous attribuer à vous-même un rôle, un pouvoir étendu ou des permissions supplémentaires sur un rôle que vous détenez : un autre administrateur doit le faire.

## Gérer les rôles et les délégations

*Concerne : l’Administrateur fonctionnel. L’Administrateur technique DSI consulte la page sans pouvoir la modifier.*

Menu **Administration › Rôles & délégations**.

### Modifier la matrice des permissions

1. Dans **Matrice des permissions**, cochez ou décochez les cases de la colonne du rôle. Les colonnes « Pouvoir » indiquent les droits ouverts par un pouvoir étendu (mention **Ouvert**).
2. En tête de colonne, choisissez **Enregistrer** (ou **Annuler** pour abandonner).
3. Vérifiez les permissions **Ajoutées** et **Retirées**, saisissez le **Motif**, puis **Enregistrer la matrice**.

Le changement s’applique immédiatement à tous les titulaires du rôle (leur nombre figure en tête de colonne). En dehors de l’administrateur fonctionnel, nul ne peut accorder un droit qu’il ne détient pas lui-même ; le serveur refuse et journalise la tentative.

### Consulter les pouvoirs en vigueur

La section **Pouvoirs étendus en vigueur** liste, pour chaque pouvoir, son rôle de rattachement et ses titulaires avec leur échéance. L’attribution se fait depuis la fiche utilisateur.

### Comparer les droits effectifs de deux utilisateurs

1. Dans **Comparer les droits effectifs**, choisissez **Utilisateur A** et **Utilisateur B**.
2. Laissez **Différences uniquement** coché pour ne voir que les écarts, ou décochez-le pour la liste complète.

## Gérer les référentiels

*Concerne : l’Administrateur fonctionnel.*

Menu **Administration › Référentiels**. Onglets : **Sites & emplacements**, **Zones de diffusion**, **Structures**, **Catégories**, **Langues**, **Équipements**, **Types de prestation**, **Jours non ouvrés**.

### Ajouter ou modifier un élément

1. Ouvrez l’onglet voulu.
2. Choisissez **Ajouter** (ou, dans l’arborescence, **Ajouter un site**, puis sur chaque ligne **Bâtiment**, **Niveau**, **Emplacement** ; pour les structures, **Ajouter une structure** puis **Sous-structure**).
3. Renseignez les champs. Un code déjà utilisé est refusé.
4. Validez par **Ajouter** ou **Enregistrer**. Chaque modification est tracée au journal (avant / après).

Points d’attention :
- chaque emplacement est rattaché à une **Zone de diffusion** ; la **Classification maximale** de la zone (**Public** ou **Interne**) borne ce qui peut y être diffusé, et **Audio autorisé** et **Volume maximal (0–100)** encadrent le son ;
- dans **Langues**, activer le portugais ou l’espagnol suffit à les proposer dans la saisie et la diffusion ;
- les **Jours non ouvrés** sont exclus des calculs de disponibilité des salles et des délais ;
- dans **Types de prestation**, l’option **Couverts et heure exigés** impose au demandeur le nombre de couverts et l’heure de service (elle est active d’office pour **Pause-café et restauration**). Le **Code** d’un type ne se modifie plus après sa création, car les périmètres du pouvoir « prestations » y font référence.

### Désactiver plutôt que supprimer

Aucun élément de référentiel ne se supprime. Pour retirer un élément : **Désactiver**, puis confirmer. Il n’est plus proposé pour les nouvelles saisies ; les objets qui l’utilisent le conservent et l’historique reste intact. **Réactiver** le rend de nouveau disponible. Les jours non ouvrés ne se désactivent pas : corrigez-les par modification.

## Importer un référentiel

*Concerne : l’Administrateur fonctionnel.*

Menu **Administration › Imports**. L’import ne supprime jamais rien : chaque ligne crée ou met à jour l’élément correspondant à son code.

1. Choisissez le **Type de référentiel**.
2. Téléchargez le **Modèle CSV** et remplissez-le (séparateur « ; » ou « , », encodage UTF-8, 5 Mo maximum).
3. Déposez le fichier ou choisissez **Choisir un fichier**.
4. Choisissez **Simuler (aucune écriture)**. Le **Bilan de la simulation** compte les lignes **Créations**, **Mises à jour**, **Inchangées**, **Invalides** et **Doublons**, avec les erreurs ligne par ligne (filtres **Toutes**, **Valides**, **Invalides**, **Doublons**).
5. S’il reste des lignes invalides, choisissez **Exporter les erreurs (CSV)**, corrigez le fichier et simulez de nouveau.
6. Quand aucune ligne n’est invalide, choisissez **Confirmer l’import**.

**Confirmer l’import** reste indisponible tant que le fichier présent n’a pas été simulé sans erreur. La section **Historique** liste les 30 derniers imports confirmés.

## Gérer les modèles d’affichage

*Concerne : l’Administrateur fonctionnel titulaire du pouvoir « Modèles » en cours de validité.*

Menu **Publication › Modèles**. Sans le pouvoir, la page est en consultation seule. Onglets : **Paysage 16:9**, **Portrait 9:16**, **Archivés**.

1. Choisissez **Nouveau modèle**, renseignez **Nom**, **Orientation** et **Usage**, puis **Créer et ouvrir l’éditeur**.
2. Ajoutez des blocs depuis la palette, placez-les sur le canevas, puis **Enregistrer le modèle** (ou **Abandonner les modifications**).
3. Pour créer une variante : **Dupliquer**. Pour retirer un modèle : **Archiver**.

Un modèle archivé n’est plus proposé pour de nouveaux contenus, mais les versions déjà approuvées qui l’utilisent restent diffusables ; il ne peut plus être modifié.

## Gérer les écrans et les lecteurs

*Concerne : l’Administrateur technique DSI. L’Administrateur fonctionnel consulte le parc.*

Menu **Espaces › Écrans & lecteurs**. L’écran physique et le lecteur qui l’anime sont deux objets distincts.

### Inventorier un écran

1. Choisissez **Nouvel écran**.
2. Renseignez **Code** (majuscules, chiffres et tirets), **Nom**, **Type d’écran**, **Emplacement**, **Orientation** et **Résolution** ; pour un **Panneau de salle**, la **Salle associée**.
3. Validez par **Créer l’écran**.

**Exporter l’inventaire** lance la génération de l’inventaire des équipements, à récupérer dans **Exports & traitements**.

### Régler le fonctionnement d’un écran

1. Ouvrez la fiche de l’écran, onglet **Fonctionnement**.
2. Réglez la plage : **Jours de fonctionnement**, **Allumage**, **Extinction** (après l’allumage), **Réveil anticipé (minutes)** (0 à 30) et, si besoin, **Fonctionner aussi les jours fériés**. La plage par défaut est du lundi au vendredi, de 8 h à 18 h.
3. Réglez le son : **Audio autorisé sur cet écran (sous réserve de la zone)**.
4. Choisissez le **Canal de pilotage de la dalle** : **Aucun (non piloté)**, **HDMI-CEC**, **RS-232** ou **API constructeur**. Sans canal, l’état de la dalle reste « Non mesuré ».
5. Choisissez **Enregistrer le fonctionnement**. Si la plage change, saisissez le **Motif de la dérogation à la plage de fonctionnement**, puis **Appliquer**.

Le lecteur applique les réglages avec son prochain manifeste ; hors plage, l’écran passe en veille. Les dérogations sont visibles dans l’onglet **Historique**.

### Suivre le lecteur d’un écran

Onglet **Lecteur** : état réseau, dernier contact, version applicative, stockage, écart d’horloge, manifeste actif, erreurs déclarées et inventaire du cache. Le jeton du lecteur n’est jamais affiché.

- **Canal de version (PLY-12)** : choisissez **Stable** ou **Pilote (nouvelles versions en premier)**. Le choix est journalisé.
- **Capture de rendu** : choisissez **Demander une capture**. La demande part au prochain contact du lecteur ; **Actualiser** recharge l’état. La date **Disponible jusqu’au** indique jusqu’à quand l’image reste consultable. Une capture montre ce que le lecteur rend : elle ne prouve ni l’allumage de la dalle ni qu’une personne a vu le contenu.

### Révoquer un lecteur

1. Onglet **Lecteur**, choisissez **Révoquer le lecteur**.
2. Saisissez le **Motif de la révocation**, puis **Révoquer définitivement**.

Le lecteur ne reçoit plus de manifeste. S’il est hors ligne, il continue à lire son cache jusqu’à l’expiration de l’autorisation en cours. Les lecteurs successifs d’un écran restent visibles dans l’onglet **Historique**.

## Enrôler un lecteur

*Concerne : l’Administrateur technique DSI.*

Au démarrage, un lecteur non appairé affiche un code temporaire de 6 caractères, à usage unique, valable 10 minutes.

1. Dans **Écrans & lecteurs**, choisissez **Enrôler un lecteur** (ou, depuis l’onglet **Lecteur** d’un écran sans lecteur, **Appairer un lecteur**).
2. Étape **Code** : saisissez les 6 caractères, puis **Continuer**.
3. Étape **Association** : choisissez l’écran inventorié devant lequel le lecteur est installé (**Filtrer les écrans** aide à le trouver), puis **Continuer**.
4. Étape **Affectation** : vérifiez l’écran. S’il a déjà un lecteur actif, cochez **Remplacer le lecteur actif** : l’ancien lecteur est révoqué. Choisissez **Associer le lecteur**.
5. Étape **Synchronisation** : le lecteur récupère son identité et télécharge son premier manifeste ; il ne bascule qu’après téléchargement complet et vérification. **Appairer un autre lecteur** relance l’assistant.

## Gérer les groupes de diffusion

*Concerne : l’Administrateur technique DSI. L’Administrateur fonctionnel consulte les groupes.*

Menu **Espaces › Groupes de diffusion**. Un groupe est un ensemble nommé d’écrans, utilisé comme cible de campagnes et d’alertes.

1. Choisissez **Nouveau groupe** (ou **Modifier** sur un groupe existant).
2. Renseignez **Code** (non modifiable ensuite), **Nom** et **Description**.
3. Cochez les écrans, présentés par niveau (**Tout ajouter** / **Tout retirer** par niveau).
4. Validez par **Créer le groupe** ou **Enregistrer**.

**Cibles effectives** affiche les écrans réellement visés. **Supprimer** retire le regroupement sans supprimer les écrans ; la suppression est refusée tant qu’une campagne programmée, active ou suspendue cible le groupe.

## Superviser la plateforme

*Concerne : l’Administrateur technique DSI.*

Menu **Pilotage › Supervision**. La page s’actualise toutes les minutes.

### Lire les contrôles de santé

La section **Contrôles de santé** présente l’API, la base de données, les files de traitement, les conversions et analyses, le stockage, les lecteurs, les dalles, les sauvegardes et la collecte des sources dynamiques. Chaque contrôle porte un état (**OK**, **Avertissement**, **Échec**) et la date de son **Dernier contrôle**, ou la mention « Absence de mesure ». La fraîcheur des sauvegardes n’est pas mesurée par l’application. Suivent la liste des **Lecteurs actifs** (écart d’horloge signalé au-delà de ±5 s) et la **Répartition des versions**.

### Traiter les incidents

Un lecteur sans contact depuis 5 minutes ouvre automatiquement un incident de gravité « Majeur », passé à « Résolu » au retour du contact. Les autres incidents se déclarent :

1. Choisissez **Déclarer un incident**.
2. Renseignez **Intitulé**, **Gravité** (**Critique**, **Majeur**, **Mineur**) et, si besoin, **Écran concerné**, **Impact** et **Description**, puis **Déclarer**.

Pour faire avancer un incident, ouvrez-le par **Ouvrir**, puis dans **Agir sur l’incident** :

| Action | Possible quand l’incident est |
| --- | --- |
| **Assigner** | Ouvert ou Acquitté |
| **Acquitter** | Ouvert |
| **Résoudre** | Ouvert ou Acquitté |
| **Clore** | Résolu |
| **Commenter** | Ouvert, Acquitté ou Résolu |

Une **Note (facultative)** accompagne chaque action ; elle est obligatoire pour **Commenter**. L’**Historique** de l’incident conserve chaque étape. Un incident clos n’accepte plus aucune action.

Le bouton **Rapport d’incidents (PDF08)** génère le rapport sur une période, en PDF ou CSV.

### Publier une version du lecteur

La section **Versions du lecteur** indique, pour les canaux **Pilote** et **Stable**, la version cible et le nombre de lecteurs à jour. Une version passe toujours par le canal pilote avant le canal stable. La préparation des fichiers de la version sur le serveur est décrite dans [Mise en service et exploitation](../exploitation/mise-en-service.md).

1. **Publier sur le canal pilote** : choisissez **Publier une version**, saisissez **Version**, **Empreinte SHA-256 de index.html** (fournie lors de la préparation de la version) et, si besoin, **Notes de version**, puis **Publier**. Seuls les lecteurs du canal pilote la reçoivent.
2. **Observer** les lecteurs pilotes (onglet **Lecteur** des écrans concernés, supervision).
3. **Promouvoir en stable** : sur la ligne de la version pilote, choisissez **Promouvoir en stable**, puis **Publier**. Tous les lecteurs du canal stable la reçoivent à leur prochaine synchronisation.
4. **Retirer** en cas d’anomalie : choisissez **Retirer**, saisissez le **Motif (anomalie constatée)**, puis **Retirer la version**. Le canal revient immédiatement à la version active précédente (ou, à défaut, à la version embarquée).

## Régler les paramètres

*Concerne : les deux rôles pour les paramètres fonctionnels ; l’Administrateur technique DSI seul pour les paramètres marqués **Technique**, les sources dynamiques et les comptes de service.*

Menu **Administration › Paramètres**. Onglets : **Identité**, **Diffusion**, **Fonctionnement et audio**, **Salles**, **Canaux**, **Sources dynamiques**, **Intégrations**. Le panneau **Environnement** rappelle, en lecture seule, la configuration du serveur.

Effet de chaque paramètre :

- **Nom de l’institution** et **Mention institutionnelle** : en-tête des écrans.
- **Durée par défaut d’une diapositive** (10 à 300 s) : valeur proposée à la création d’une campagne.
- **Plage de fonctionnement par défaut** : écrans sans plage propre.
- **Volume audio maximal** (0 à 100 %) : plafond général ; le plafond de la zone s’applique s’il est plus bas.
- **Durée de maintien d’une demande en attente** (1 à 168 h) et **Libération automatique des salles sans confirmation de présence** : valeurs données aux nouvelles salles, réglables ensuite salle par salle.
- **Envoi de courriels** : désactivé, aucun courriel ne part ; les notifications restent visibles dans l’application.
- **Notifications push** et **API d’intégration externe** : activation des canaux correspondants.

Les bornes sont contrôlées par le serveur : une valeur hors bornes est refusée.

### Modifier un paramètre

1. Sur la ligne du paramètre, choisissez **Modifier**.
2. Saisissez la nouvelle valeur, puis **Enregistrer**.
3. Vérifiez la nouvelle valeur, saisissez le **Motif**, puis **Appliquer le changement**.

L’ancienne et la nouvelle valeur sont inscrites au journal avec le motif. Une ligne **Lecture seule** signale un paramètre que votre rôle ne peut pas modifier. Une valeur secrète est affichée masquée avec la mention **Secret masqué** : elle n’est jamais relue ; pour la changer, saisissez une nouvelle valeur.

### Activer les notifications push

Onglet **Canaux**, paramètre **Notifications push** : passez-le à **Activé**. Le canal ne fonctionne que si la DSI a aussi installé les clés du serveur (voir [Mise en service et exploitation](../exploitation/mise-en-service.md)). Chaque utilisateur s’abonne ensuite depuis **Mon compte**.

### Déclarer une source dynamique

Les sources dynamiques (horloge, compte à rebours, flux RSS, flux JSON, météo) sont collectées par le serveur, mises en cache, puis transmises aux écrans : les lecteurs ne contactent jamais ces adresses.

1. Onglet **Sources dynamiques**, choisissez **Déclarer une source**.
2. Renseignez **Code** (minuscules, chiffres, tiret ou souligné ; non modifiable ensuite), **Nom**, **Type** et **Fréquence de collecte (minutes)** (entre 5 et 1 440).
3. Pour un flux RSS, JSON ou météo, saisissez l’**Adresse HTTPS** : seules les adresses HTTPS publiques sont acceptées, les adresses internes ou réservées sont refusées. Complétez les champs propres au type.
4. Laissez **Source active (collectée et diffusée)** décochée pour l’instant, puis **Enregistrer**.
5. Choisissez **Tester** : le résultat « Collecte réussie » ou « Collecte en échec » s’affiche, et la colonne **Dernière collecte** est mise à jour.
6. Si le test réussit, **Modifier**, cochez **Source active (collectée et diffusée)**, puis **Enregistrer**.

Une source inactive n’est plus diffusée.

### Ouvrir l’API d’intégration à un autre système

1. Onglet **Intégrations**, section **Comptes de service (API-07)**, choisissez **Créer un compte**.
2. Renseignez **Nom du compte**, **Description** et au moins une des **Portées** : **Réunions du jour autorisées**, **État du parc**, **Annonces publiques diffusées**.
3. Saisissez le motif (système demandeur, référence de la demande), puis **Créer le compte**.
4. Le jeton d’accès s’affiche une seule fois : **Copier**, transmettez-le par un canal sûr, puis **J’ai copié le jeton**.
5. Vérifiez que le paramètre **API d’intégration externe (P1, API-07)** du même onglet est **Activé**. Tant qu’il est désactivé, tous les jetons sont refusés.

Pour remplacer un jeton : **Renouveler** ; l’ancien cesse immédiatement de fonctionner et le nouveau s’affiche une seule fois. Pour couper l’accès : **Révoquer** ; l’action est définitive. Chaque opération exige un motif. La colonne **Dernier usage** indique l’activité de chaque compte.

## Consulter le journal d’audit

*Concerne : ni l’un ni l’autre des rôles d’administration par défaut ; le journal relève du rôle Auditeur.*

Le menu **Administration › Journal d’audit** n’apparaît qu’aux titulaires du rôle Auditeur. Le journal trace les actions (acteur, rôle, pouvoir étendu, objet, motif, résultat), masque toujours les secrets et est en lecture seule : aucune entrée ne peut être modifiée ni supprimée. Voir le [manuel de l’auditeur](auditeur.md).

## Exports et traitements

*Concerne : les deux rôles.*

Menu **Pilotage › Exports & traitements** (ou **Mes exports** dans le menu du profil).

1. Dans **Demande rapide**, choisissez **Type**, **Format** et **Période**, puis **Demander**. Les documents liés à un objet (réservation, campagne, document) se demandent depuis la fiche de l’objet.
2. Suivez la progression dans **Mes exports**.
3. Quand l’état est **Prêt**, choisissez **Télécharger**.

**Annuler** arrête un export en attente ; **Relancer** régénère un export en échec, expiré ou annulé. Un fichier prêt expire 72 heures après sa génération. Seuls les types autorisés par vos rôles sont proposés.

## Règles à connaître

- Motif obligatoire : désactivation d’un compte, attribution ou révocation d’un rôle ou d’un pouvoir, modification de la matrice, dérogation à la plage d’un écran, révocation d’un lecteur, retrait d’une version, modification d’un paramètre et opérations sur les comptes de service exigent un motif d’au moins 5 caractères, inscrit au journal.
- Tout est daté : un rôle peut avoir une fin, un pouvoir étendu en a toujours une. À l’échéance, les droits cessent sans action de votre part.
- Un pouvoir étendu exige son rôle : Urgence avec Diffuseur, Modèles avec Administrateur fonctionnel, Prestations avec Gestionnaire de salle.
- Rien ne se supprime dans les référentiels ni dans les comptes : on désactive.
- Un lecteur révoqué hors ligne continue à lire son cache jusqu’à l’expiration de son autorisation en cours.
- Une version du lecteur passe par le canal pilote avant le canal stable ; le retrait ramène le canal à la version précédente.
- Les jetons (comptes de service) et les valeurs secrètes ne sont jamais réaffichés.
- Un compte verrouillé après 5 échecs de connexion se débloque seul au bout de 15 minutes, ou immédiatement par **Débloquer**.
- Nul ne modifie ses propres droits : attribution de rôle ou de pouvoir à soi-même et modification des permissions d’un rôle détenu sont refusées et journalisées.

## En cas de problème

| Message ou situation | Que faire |
| --- | --- |
| « Vous n’êtes pas autorisé à effectuer cette action. » ou « Accès interdit » | Votre rôle ne donne pas ce droit. Vérifiez vos **Droits effectifs** ou adressez-vous à l’administrateur fonctionnel. |
| « Ce pouvoir est une extension du rôle « … » que l’utilisateur ne détient pas. » | Attribuez d’abord le rôle requis, puis le pouvoir. |
| « Un administrateur ne peut pas modifier ses propres droits : un autre administrateur doit le faire. » | Vous avez tenté de vous attribuer un rôle ou un pouvoir, ou de modifier un rôle que vous détenez : demandez-le à un autre administrateur. |
| « Cette opération sur votre propre compte doit être faite par un autre administrateur. » | Le déblocage ou la réinitialisation de votre propre compte est fait par un autre administrateur. |
| « Vous ne pouvez pas désactiver votre propre compte. » | Faites désactiver le compte par un autre administrateur. |
| Un utilisateur a perdu son application d’authentification | S’il a encore ses codes de secours, il se connecte avec l’un d’eux. Sinon, après vérification de son identité, utilisez **Réinitialiser le second facteur** sur sa fiche. |
| « Colonnes obligatoires absentes : … » | Repartez du **Modèle CSV** du type choisi. |
| « Le fichier contient des lignes invalides : corrigez-les avant confirmation. » | **Exporter les erreurs (CSV)**, corrigez, simulez de nouveau. |
| « Corrigez les champs signalés (un code déjà utilisé est refusé). » dans un référentiel | Corrigez le champ signalé ; si le code existe déjà, modifiez l’élément existant ou choisissez un autre code. |
| « Une dérogation à la plage de fonctionnement exige un motif. » | Saisissez un motif dans la confirmation. |
| « Ce code a expiré. Le lecteur affiche automatiquement un nouveau code : saisissez-le. » | Saisissez le nouveau code affiché par le lecteur. |
| « Code inconnu : vérifiez les caractères affichés sur le lecteur (pas de confusion entre O et 0, I et 1). » | Ressaisissez le code. |
| « Ce code a déjà servi. … » | Redémarrez l’assistant d’appairage du lecteur pour obtenir un nouveau code. |
| « Cet écran a déjà un lecteur actif » | Cochez **Remplacer le lecteur actif** si le remplacement est voulu. |
| « Ce lecteur est déjà révoqué. » | Aucune action : appairez un nouveau lecteur si nécessaire. |
| Suppression d’un groupe refusée | Retirez le groupe des campagnes citées ou attendez leur fin. |
| « Action impossible dans l’état actuel de l’incident. » | Rechargez la page : un autre administrateur a fait évoluer l’incident. |
| « Une version passe d’abord par le canal pilote avant le canal stable … » | Publiez d’abord la version sur le canal pilote. |
| « Seules les adresses HTTPS publiques sont autorisées. », « Adresse interne ou réservée refusée … », « Hôte introuvable. » | Utilisez une adresse HTTPS publique et joignable. |
| « Collecte en échec » au test d’une source | Vérifiez l’adresse et les champs propres au type, puis testez de nouveau. |
| Un système externe reçoit des refus avec un jeton valide | Vérifiez que **API d’intégration externe (P1, API-07)** est **Activé** et que le compte n’est pas révoqué. |
| **Conflit de version** dans l’éditeur de modèle | Un autre administrateur a enregistré le modèle : **Recharger la version courante** (vos modifications locales sont perdues). |
| « Cet export n’est plus disponible : relancez sa génération. » | Choisissez **Relancer**. |
| « Trop de tentatives. Réessayez dans quelques minutes. » à la connexion | Attendez 15 minutes avant de réessayer. |

En cas d’erreur inexpliquée, transmettez l’**Identifiant de diagnostic** au support de la DSI (voir [Premiers pas](premiers-pas.md)).
