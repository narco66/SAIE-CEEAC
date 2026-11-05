# Guide d’administration

## Comptes et habilitations (V34, V35)

- **Créer un compte** : *Administration › Utilisateurs › Nouvel utilisateur*. Aucun mot de passe n’est affiché : un lien de définition est envoyé à l’adresse professionnelle. Premier compte : `php artisan saie:create-admin`.
- **Attribuer un rôle** : rôle, périmètre organique (structure et ses sous-structures ; vide = toute l’institution), langues pour un validateur, début et fin, motif. Un rôle expiré cesse immédiatement d’ouvrir des droits.
- **Pouvoirs étendus** (HAB-03) : « urgence » (Diffuseur), « modèles » (Administrateur fonctionnel), « prestations » (Gestionnaire de salle, avec types de prestation). Fin obligatoire ; refusé si l’utilisateur n’a pas le rôle de rattachement.
- **Suppléant** : à désigner pour chaque validateur et gestionnaire ; une validation non traitée à l’échéance (2 jours ouvrés pour un contenu, 24 h pour une réservation) est escaladée au suppléant. Les tâches sans suppléant sont signalées sur le tableau de bord de l’administrateur fonctionnel.
- **Désactiver** : coupe toutes les sessions et bloque tout nouveau traitement ; l’historique est conservé.
- **MFA** : obligatoire pour les administrateurs et validateurs lorsque `SAIE_MFA_ENFORCED=true` ; l’enrôlement est imposé à la première connexion (application TOTP + codes de secours).

## Référentiels et imports (V36, V37)

Sites, bâtiments, niveaux (RDC et étages 1 à 6), emplacements, zones de diffusion (classification maximale public/interne, audio), structures, catégories, langues (activer le portugais ou l’espagnol sans développement), équipements, types de prestation, jours non ouvrés. Les objets utilisés se **désactivent**, ils ne se suppriment pas.

Import CSV : télécharger le modèle, **Simuler** (aucune écriture, erreurs ligne par ligne, doublons signalés), corriger, puis **Confirmer**. L’import est idempotent sur le code métier et ne supprime jamais rien.

## Parc et diffusion

- Écrans : emplacement, orientation, résolution, plage de fonctionnement (8 h–18 h en semaine par défaut ; dérogation avec motif), audio, canal de pilotage.
- Groupes : ciblage croisé étage/usage ; suppression refusée si une campagne active en dépend.
- Contenus de repli par zone : un contenu public approuvé, avec date de fin (*Programme & repli*).
- Alertes : réservées aux titulaires du pouvoir « urgence », justification obligatoire, expiration 30 min par défaut, prolongation et levée tracées ; le centre d’alertes affiche les lecteurs hors ligne pour lesquels la réception n’est pas garantie.

## Supervision (V40) et journal (V38)

La supervision présente l’état de l’API, de la base, des files, des analyses, du stockage, des lecteurs et des dalles, chacun avec sa date de contrôle ou la mention « Non mesuré ». Les lecteurs sans contact depuis 5 minutes ouvrent automatiquement un incident, résolu au retour du contact. Le journal est consultable par le rôle Auditeur ; il ne peut être ni modifié ni supprimé, y compris par l’application.

## Paramètres (V39)

Les paramètres techniques (plage par défaut, volume, conservation, canaux, intégrations) exigent la permission « paramètres techniques » ; chaque modification demande un motif et est journalisée. Les fonctions P1 (sources dynamiques, SSO, calendrier externe, API d’intégration) sont présentes mais désactivées.
