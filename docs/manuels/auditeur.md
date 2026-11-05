# Manuel Auditeur

## À qui s’adresse ce manuel

Ce manuel s’adresse aux agents titulaires du rôle « Auditeur ». L’auditeur contrôle a posteriori l’usage du système : il consulte le journal d’audit, les rapports et les indicateurs, et produit des extraits et des rapports. Son accès est en **lecture seule** : il ne peut modifier ni supprimer aucune entrée du journal, et il n’intervient pas dans la publication, la diffusion ou les réservations.

## Se connecter

Voir [Premiers pas](premiers-pas.md) pour la connexion, le second facteur, le compte et les notifications.

## Ce que vous trouvez dans le menu

- **Vue générale** : **Tableau de bord**, **Tâches & validations**, **Notifications**.
- **Pilotage** : **Rapports**, **Exports & traitements**.
- **Administration** : **Journal d’audit**.

Toutes les dates et heures sont exprimées en heure de Libreville (UTC+1).

## Consulter le journal d’audit

Le journal enregistre les actions effectuées dans le système : qui a agi, avec quel rôle et éventuellement quel pouvoir étendu, sur quel objet, pour quel motif et avec quel résultat. Les entrées les plus récentes apparaissent en premier, 50 par page.

1. Ouvrez **Journal d’audit** dans le menu.
2. Lisez le tableau. Chaque ligne présente :
   - **Date** ;
   - **Acteur** : le nom de l’agent, ou « Système » pour un traitement automatique, ou « Lecteur » pour un lecteur d’écran ;
   - **Rôle** et **Pouvoir** (pouvoir étendu éventuellement utilisé : Urgence, Modèles, Prestations) ;
   - **Action** : libellé en clair, avec en dessous le code de l’action ;
   - **Objet** : référence ou nom de l’objet, avec en dessous son type ;
   - **Version** et **Langue** lorsqu’elles s’appliquent ;
   - **Motif** saisi par l’acteur ;
   - **Résultat** : « Succès », « Refusé » ou « Échec » ;
   - **Corrélation** : début de l’identifiant qui relie l’action à une requête.
3. Utilisez la pagination en bas du tableau pour remonter dans le temps.

## Filtrer le journal

1. Renseignez un ou plusieurs filtres :
   - **Du** et **Au** : période (jours entiers, heure de Libreville) ;
   - **Acteur** : tout ou partie du nom ;
   - **Action (préfixe)** : début du code de l’action, par exemple « reservation » pour toutes les actions sur les réservations, ou « content.approved » pour les approbations de contenus ; la liste proposée reprend les grandes familles ;
   - **Type d’objet** : saisissez le type exactement tel qu’il apparaît sous l’objet dans la colonne **Objet** ;
   - **Résultat** : **Tous**, « Succès », « Refusé » ou « Échec » ;
   - **Pouvoir étendu** : **Tous**, « Urgence », « Modèles » ou « Prestations ».
2. Cliquez sur **Filtrer**.
3. Cliquez sur **Réinitialiser** pour effacer tous les filtres.

Conseils :

- Pour repérer les tentatives refusées (accès hors droits, auto-validation par un auteur), filtrez sur le résultat « Refusé ».
- Pour suivre l’usage des pouvoirs étendus, filtrez sur **Pouvoir étendu**.
- Pour retrouver toutes les actions liées à une même requête, comparez la colonne **Corrélation**.

## Consulter le détail d’une action

1. Sur la ligne voulue, cliquez sur **Détail**.
2. La fenêtre « Événement du journal » présente : date, acteur, rôle, pouvoir étendu, objet, identifiant d’objet, version, langue, motif, résultat, identifiant de corrélation complet et adresse IP.
3. Les blocs **Avant** et **Après** montrent les valeurs de l’objet avant et après l’action, lorsqu’elles ont été enregistrées. Un tiret signifie qu’aucune valeur n’a été conservée pour cette action.
4. Fermez la fenêtre pour revenir à la liste.

Le journal est en lecture seule : aucune entrée ne peut être modifiée ni supprimée depuis l’interface.

## Produire un extrait du journal (PDF09 ou CSV)

1. Dans **Journal d’audit**, appliquez si besoin vos filtres, puis cliquez sur **Extrait PDF09 / CSV**.
2. Dans la fenêtre « Extrait du journal », choisissez le **Format** : PDF (document paginé) ou CSV (pour un tableur).
3. Vérifiez la **Période** : elle reprend les dates **Du** et **Au** de vos filtres ; sans dates, elle couvre les 30 derniers jours. Vous pouvez choisir une période prédéfinie (7, 30 ou 90 derniers jours, 12 derniers mois) ou personnalisée.
4. Cliquez sur **Lancer la génération**.
5. Suivez la progression, puis cliquez sur **Télécharger** quand l’extrait est prêt. Vous pouvez aussi fermer la fenêtre et le retrouver dans **Exports & traitements**.

Contenu de l’extrait : date, acteur, rôle, pouvoir étendu, action, objet, motif et résultat, avec en en-tête la période, les filtres appliqués, l’auteur de l’extrait et la date de génération. Points d’attention :

- seul le filtre **Action (préfixe)** est reporté dans l’extrait ; les filtres **Acteur**, **Type d’objet**, **Résultat** et **Pouvoir étendu** ne le sont pas ;
- un extrait contient au plus 2 000 lignes ; au-delà, l’en-tête porte la mention « (limite atteinte) » : réduisez la période ;
- l’extrait n’est pas le journal intégral ; la génération de l’extrait est elle-même inscrite au journal.

## Consulter les rapports et indicateurs

1. Ouvrez **Rapports**.
2. Choisissez la **Période** (prédéfinie ou dates **Du** / **Au**) et, si besoin, un **Niveau** du bâtiment, puis cliquez sur **Appliquer**. Par défaut, la page couvre les 30 derniers jours.
3. Lisez les chiffres :
   - **Disponibilité lecteurs (moyenne)**, **Activations déclarées**, **Taux d’occupation réservé**, **Incidents** ;
   - **Lectures déclarées par jour** (le lien **Afficher les données du graphique** donne les chiffres sous forme de tableau) ;
   - **Disponibilité par niveau** ;
   - **Occupation réservée des salles** : ouverture nette, réservé confirmé, taux, annulations, réunions non honorées, prestations ;
   - **Publication** : contenus créés, versions approuvées, retours pour correction, campagnes publiées ;
   - **Indicateurs de succès (GOV-01)** : valeur, cible, période et base de calcul de chaque indicateur.
4. Lisez le bloc **Niveaux de preuve** avant d’interpréter les chiffres.

Règles de lecture :

- Une valeur qui ne peut pas être calculée est affichée « non disponible » (ou « n.d. ») : elle n’est jamais remplacée par une estimation.
- Une activation déclarée par un lecteur ne constitue pas une preuve de vue humaine.
- Le taux d’occupation des salles reflète des réservations confirmées, pas une présence constatée. La mention qui l’accompagne précise si la présence est mesurée, et sur combien de salles.

## Générer un rapport périodique

1. Dans **Rapports**, cliquez sur **Générer un rapport**.
2. Étape **Périmètre** : choisissez le **Type de document** et la période, puis **Continuer**. Selon vos habilitations, vous pouvez notamment produire « PDF05 Rapport de diffusion », « PDF07 Bilan d’utilisation des salles » et « PDF09 Extrait du journal ».
3. Étape **Format** : choisissez PDF ou CSV, puis **Continuer**.
4. Étape **Aperçu** : vérifiez la liste des champs inclus, puis cliquez sur **Lancer la génération**.
5. Étape **Génération** : suivez la progression, téléchargez le fichier, puis cliquez sur **Terminer**.

Les données sont figées à l’instant de la génération. Le format CSV utilise le point-virgule comme séparateur et l’encodage UTF-8.

## Gérer vos exports et leur expiration

1. Ouvrez **Exports & traitements** (ou **Mes exports** dans le menu de votre profil).
2. Le bloc **Demande rapide** permet de demander directement un document périodique : choisissez **Type**, **Format** et période, puis cliquez sur **Demander**.
3. Le tableau **Mes exports** liste vos seules demandes, avec leur progression, leur **Résultat** (« En attente », « Traitement », « Prêt », « Échec », « Expiré », « Annulé ») et leur **Expiration**. La page s’actualise automatiquement tant qu’un traitement est en cours ; sinon, utilisez **Actualiser**.
4. Selon l’état, cliquez sur **Télécharger** (export prêt), **Annuler** (export en attente) ou **Relancer** (export en échec, expiré ou annulé).

Expiration : un fichier prêt reste téléchargeable pendant 72 heures après sa génération, puis il est supprimé et l’export passe « Expiré ». Cliquez alors sur **Relancer** pour le générer de nouveau, avec les données du moment.

À chaque téléchargement, vos droits sont vérifiés de nouveau et le téléchargement est inscrit au journal. Personne d’autre que vous ne voit vos exports.

## Ce qui est masqué

- **Secrets** : mots de passe, jetons d’accès, secrets du second facteur, codes de secours et autres données secrètes sont retirés avant l’enregistrement dans le journal. Ils n’apparaissent ni dans le tableau, ni dans le détail **Avant** / **Après**, ni dans les extraits.
- **Contenus volumineux** : le texte intégral de certains objets et les données techniques transmises ne sont pas conservés dans le détail.
- **Rapports généraux** : les jetons, secrets et paramètres réseau sensibles en sont exclus.

## Règles à connaître

- Le journal est consultable uniquement avec le droit de consultation du journal ; il est en lecture seule pour tous.
- Chaque refus d’accès est lui-même inscrit au journal avec le résultat « Refusé ».
- Les extraits et rapports suivent les mêmes droits que les écrans dont ils proviennent : vous ne pouvez générer que les types autorisés pour vos habilitations.
- Un extrait du journal est limité à 2 000 lignes.
- Un export prêt expire 72 heures après sa génération.
- Seul un export en attente peut être annulé ; seul un export en échec, expiré ou annulé peut être relancé.

## En cas de problème

| Message affiché | Que faire |
|---|---|
| « Aucun événement » — « Aucune entrée du journal ne correspond à ces filtres. » | Élargissez la période ; pour **Type d’objet**, recopiez exactement le type affiché dans la colonne **Objet**. |
| « Vos habilitations ne permettent pas de générer ce document. » | Ce type d’export n’est pas ouvert à votre rôle. |
| « Aucun rapport périodique n’est autorisé pour vos habilitations. » | Contactez l’administrateur si vous pensez que vos droits sont incomplets. |
| « Cet export n’est plus disponible : relancez sa génération. » | L’export a expiré : cliquez sur **Relancer**. |
| « Seul un export en attente peut être annulé. » | Le traitement a déjà commencé ou est terminé. |
| « Cet export ne peut pas être relancé. » | L’export est prêt ou en cours : téléchargez-le ou attendez la fin. |
| « Format non disponible pour ce document. » | Choisissez un autre format proposé. |
| « Accès non autorisé » | La page ou l’action ne relève pas de votre rôle. |
| « Vous n’êtes pas autorisé à effectuer cette action. » | Même cause : l’action est réservée à un autre rôle. |
| « Connexion au serveur impossible. Vérifiez le réseau puis réessayez. » | Vérifiez votre connexion réseau. |
| « Une erreur interne est survenue. Communiquez l’identifiant de diagnostic au support. » | Notez l’identifiant de diagnostic affiché et transmettez-le au support. |
