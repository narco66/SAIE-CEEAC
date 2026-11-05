# Premiers pas

Ce tronc commun s’adresse à tous les utilisateurs du SAIE-CEEAC, quel que soit leur rôle. Il décrit :
- la connexion et le second facteur ;
- la gestion de votre compte ;
- les notifications et la recherche ;
- l’aide en ligne.

Les menus que vous voyez dépendent de vos rôles : une entrée absente signifie que vous n’avez pas le droit correspondant.

## Se connecter

1. Ouvrez l’adresse du SAIE-CEEAC communiquée par la DSI.
2. Saisissez votre **Identifiant professionnel** (votre adresse professionnelle) et votre **Mot de passe**, puis **Se connecter**.
3. Si votre compte utilise un second facteur, saisissez le **Code de vérification** à 6 chiffres affiché par votre application d’authentification, ou l’un de vos codes de secours, puis **Vérifier**.

Après 5 échecs, le compte est verrouillé 15 minutes. Toutes les connexions et tous les refus sont journalisés. Si votre session expire, l’application vous le signale ; reconnectez-vous pour reprendre là où vous étiez.

### Premier accès

Votre compte est créé par un administrateur. Vous recevez à votre adresse professionnelle un lien pour définir votre mot de passe : aucun mot de passe ne vous est communiqué en clair. Le premier administrateur technique reçoit un mot de passe initial, qu’il doit changer à la première connexion.

Si une **Action de sécurité requise** s’affiche, vous devez d’abord :
- définir un nouveau mot de passe, si l’administrateur l’a demandé ;
- activer le second facteur, obligatoire pour les **administrateurs** (technique et fonctionnel) et les **validateurs**.

Les autres pages restent inaccessibles tant que ce n’est pas fait.

### Mot de passe oublié

1. Sur la page de connexion, choisissez **Mot de passe oublié ?**.
2. Saisissez votre **Adresse professionnelle**, puis **Envoyer le lien**.
3. Ouvrez le lien reçu par courriel, saisissez le **Nouveau mot de passe** et sa **Confirmation**, puis **Enregistrer le mot de passe**.

Un mot de passe valide comporte **12 caractères au minimum**, **au moins une majuscule et une minuscule** et **au moins un chiffre**. L’écran indique en direct les règles satisfaites.

## Activer le second facteur

1. Ouvrez votre profil (en haut à droite), puis **Mon compte**.
2. Dans **Second facteur (TOTP)**, choisissez **Configurer le second facteur**.
3. Scannez le QR code avec une application d’authentification (application TOTP sur votre téléphone professionnel). Si vous ne pouvez pas scanner, saisissez la clé affichée sous le QR code.
4. Saisissez le **Code de vérification** à 6 chiffres affiché par l’application, puis **Activer le second facteur**.
5. Les **Codes de secours** s’affichent alors, une seule fois. Recopiez-les en lieu sûr (**Copier les codes**), puis confirmez **J’ai conservé mes codes**. Chaque code ne sert qu’une fois. Il remplace l’application si vous n’y avez plus accès.

En cas de perte du téléphone et des codes de secours, adressez-vous à l’administrateur technique de la DSI.

## Gérer votre compte

Ouvrez **Mon compte** depuis le menu de votre profil. Vous y trouverez :

- **Identité** : nom, adresse, fonction, structure, suppléant et dernière connexion. Ces informations sont modifiées par l’administration, pas par vous.
- **Rôles et pouvoirs** : vos rôles actifs, leurs dates de fin, vos langues de validation et vos pouvoirs étendus (urgence, modèles, prestations).
- **Langue et notifications** :
  - choisissez votre **Langue d’interface** et l’envoi par courriel ;
  - vous pouvez couper le courriel pour certains événements : la notification dans l’application reste toujours visible ;
  - terminez par **Enregistrer les préférences**.
- **Mot de passe** : **Mot de passe actuel**, **Nouveau mot de passe**, **Confirmation**, puis **Changer le mot de passe**.
- **Sessions actives** : vos connexions en cours. **Révoquer** ferme une session ouverte ailleurs, par exemple sur un poste partagé.
- **Notifications push** : quand l’administration a activé ce canal, **Activer sur ce navigateur** affiche les alertes du navigateur, même application fermée. Les événements concernés sont listés à l’écran, et le contenu d’une réunion confidentielle n’y figure jamais.

## Retrouver ce qui vous concerne

- **Tableau de bord** : la situation du jour selon vos rôles.
- **Tâches & validations** : ce qui attend une action de votre part. Le nombre en attente s’affiche à côté du menu ; **Ouvrir** mène directement à l’écran de traitement.
- **Notifications** : la cloche en haut de page ou le menu **Notifications**. **Ouvrir** mène à l’objet concerné, **Marquer lu** et **Tout marquer lu** vident la liste.

  Une notification ne donne jamais plus de droits que les vôtres : si l’objet est devenu inaccessible entre-temps, l’application le dit au lieu de l’afficher.
- **Recherche globale** : **Rechercher dans SAIE…** en haut de page, ou **Ctrl K**. Elle couvre contenus, documents, salles et écrans, en ne montrant que ce que vous avez le droit de voir.

## Aide et assistance

Le menu **Aide & documentation** présente :
- les guides correspondant à vos rôles ;
- les **Raccourcis clavier** (toute l’application est utilisable au clavier ; le premier élément de chaque page est un lien « Aller au contenu ») ;
- l’explication des messages d’erreur (**Comprendre les messages d’erreur**).

En cas d’erreur, l’application affiche un **Identifiant de diagnostic**. Il ne contient aucune donnée personnelle. **Copier l’identifiant**, puis transmettez-le au support de la DSI avec une description de ce que vous faisiez.

Pour quitter l’application, utilisez **Se déconnecter** dans le menu de votre profil, en particulier sur un poste partagé.

## Manuels par rôle

| Rôle | Manuel |
| --- | --- |
| Agent | [agent.md](agent.md) |
| Contributeur (dont accueil et protocole) | [contributeur.md](contributeur.md) |
| Validateur éditorial | [validateur.md](validateur.md) |
| Diffuseur | [diffuseur.md](diffuseur.md) |
| Gestionnaire de salle | [gestionnaire-salle.md](gestionnaire-salle.md) |
| Administrateur fonctionnel et administrateur technique DSI | [administrateur.md](administrateur.md) |
| Auditeur | [auditeur.md](auditeur.md) |
| Exploitant DSI | [mise en service et exploitation](../exploitation/mise-en-service.md), [lecteurs et dalles](../lecteur.md), [sauvegarde et restauration](../exploitation/sauvegarde-restauration.md) |
