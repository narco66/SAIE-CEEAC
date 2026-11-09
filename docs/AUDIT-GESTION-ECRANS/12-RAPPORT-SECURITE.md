# 12 — Rapport de sécurité du module (§18, §14)

## Constats corrigés

| Réf. | Constat initial | Correction | Test |
|---|---|---|---|
| C-D1 | Périmètre neutralisé pour un écran désactivé ou un groupe vide (modification, réactivation, appairage, révocation hors zone) | Cibles résolues quel que soit le statut ; groupe vide géré par sa structure seulement | `ScreenModuleIntegrityTest` |
| A-D1, C-D6 | Déplacement d'un écran hors du périmètre | Nouveau contrôle dans la transaction | idem |
| A-D12, C-D13 | Liste et fiche des écrans visibles hors périmètre | Lecture bornée | idem |
| C-D4 | Incidents et plans de maintenance sans périmètre | Filtre et contrôle par écran | idem |
| C-D5 | Exports d'inventaire et d'incidents sans périmètre | Réunion des périmètres des permissions détenues | `ScreenInventoryTest`, `FleetReportsTest` |
| C-D3 | Cibles de campagne ou d'alerte inexistantes acceptées | `TARGET_NOT_FOUND` | `ScreenModuleIntegrityTest` |
| B-D1 | Commande réputée traitée sans confirmation | File de commandes avec accusés, expiration | `RemoteCommandsTest` |
| — | Commande hors capacités | Refus journalisé `COMMAND_UNAVAILABLE` | `RemoteCommandsTest` |
| — | Accusé d'une commande par un autre lecteur | Refus `COMMAND_NOT_FOUND`, état inchangé | `RemoteCommandsTest::test_commands_are_restricted_by_permission_scope_and_device` |

## Mécanismes en place (vérifiés)

- **Lecteurs** : jeton individuel (empreinte SHA-256 en base, expiration, rotation), révocation immédiate (401), manifeste signé Ed25519 avec anti-rejeu (version et validité), médias vérifiés par empreinte, jeton d'appairage chiffré en base pendant sa remise (le constat B-D9 de l'audit était un faux positif).
- **Commandes** : permission dédiée `devices.command`, périmètre, motif pour les commandes perturbantes, identifiant de transaction, journalisation de chaque étape.
- **QR d'écran** : jamais public ; authentification puis habilitation sur l'écran.
- **Import CSV** : mêmes règles que la saisie, contrôle de périmètre ligne par ligne, atomique.
- **Agent local** : écoute `127.0.0.1` uniquement, origine du lecteur exigée pour toute commande.
- **Groupes dynamiques** : réservés aux habilitations sans restriction (les critères pourraient viser tout le parc).

## Scénario F (recette)

`screens-scenarios.spec.ts › scénario F` : un agent sans droit reçoit 403 sur la liste, le tableau de bord, la création, l'archivage et la supervision ; un auditeur reçoit 403 sur la modification d'un écran, l'envoi d'une commande et la création d'un profil ; un jeton de lecteur forgé reçoit 401.

## Risques et décisions ouverts

| Point | Situation | Proposition |
|---|---|---|
| `/referentials` (A-D11) | Accessible à tout compte connecté : annuaire interne (nom et structure des agents), salles, emplacements | Accepter (nécessaire aux formulaires de tous les profils) ou scinder l'annuaire derrière une permission |
| Demande de code d'appairage | Ouverte sans authentification (limitée à 10 par minute) : nécessaire au premier démarrage d'un lecteur | Accepter ; filtrage réseau du VLAN des lecteurs recommandé |
| Jeton du lecteur | En clair dans le stockage du navigateur kiosque | Inhérent à une application web ; poste kiosque verrouillé (session dédiée) |
| Requêtes du lecteur | Ni signature ni nonce par requête (jeton porteur sur TLS) | Accepter sous TLS ; signature des requêtes à étudier si le réseau n'est pas maîtrisé |
| Second facteur | Rendu obligatoire pour le nouveau rôle « Responsable du parc » | — |
