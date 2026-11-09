# 14 — Limitations et dépendances

## Implémenté — validation matérielle en attente

Le code est en place et testé avec l'agent local en **mode simulation** uniquement ; aucune validation sur matériel réel n'a été possible.

| Fonction | Dépendance | Pour valider |
|---|---|---|
| Mise en veille et sortie de veille (§11) ; veille hors plage (§7) | Agent local + pilote HDMI-CEC (`cec-client`) ou commande RS-232/API du constructeur | Installer l'agent sur un lecteur réel, déclarer le canal de pilotage sur l'écran, lancer `screens-commands.spec.ts` avec l'agent en mode `cec` |
| Redémarrer l'appareil (§11) | Agent local avec `SAIE_REBOOT_CMD` et droit système | Idem, vérifier la confirmation au retour du lecteur |
| Luminosité, contraste (§7.1) | Pilote d'image de la dalle (API constructeur, RS-232) **non développé** : paramètres signalés « non pris en charge » | Spécifier le matériel retenu |
| Température, mémoire, disque, processeur (§10.2) | Remontés par l'agent local v1.1.0 ; vérifiés sur le poste de développement Windows seulement : charge processeur non mesurée sous Windows, température lue uniquement sur capteur Linux `thermal_zone0` ; processus de lecture non mesuré | Installer l'agent sur le lecteur cible (Linux) et contrôler la fiche |
| MAC, système du lecteur (§9.1) | Remontés par l'agent local ; firmware non mesuré (saisissable sur la fiche de l'écran) | Idem |
| Capture de la sortie vidéo réelle (§21.1) | Capture logicielle du rendu (sans les vidéos) | Capture matérielle (carte d'acquisition ou API du constructeur) |

Diagnostic préalable : `node agent/diagnose.mjs` sur le poste du lecteur (lecture seule) indique ce qui peut être validé et comment. Exécuté le 8 novembre 2026 sur le poste de développement : agent joignable, disque, mémoire, MAC et système mesurés ; pilote de dalle en simulation, charge processeur non mesurée (Windows), température absente (pas de capteur Linux).

## Livré désactivé (décision DSI)

| Option | Paramètre (onglet « Sécurité des lecteurs ») | Effet à l'activation |
|---|---|---|
| Liste blanche d'enrôlement | `devices.enroll_cidrs` (vide) | Seuls les réseaux listés peuvent demander un code d'appairage |
| Requêtes signées et anti-rejeu | `devices.signed_requests` (non) | Toute requête de lecteur doit être signée ; un lecteur d'ancienne version est refusé |
| Jeton chiffré au repos | `devices.secure_storage` (non) | Chaque lecteur chiffre son jeton au battement suivant |
| Annuaire réservé | `referentials.restricted` (non) | La liste des comptes n'est plus transmise aux agents sans droit de gestion |
| Promotion automatique des versions | `releases.auto_promote` (non) | Une version pilote saine part en stable (vague de 25 %) |
| Rôle « Administrateur SAIE » | (attribution) | Profil unique d'administration, à attribuer nominativement |

## Non réalisé (décision ou dépendance)

| Point | Raison |
|---|---|
| Temps réel par WebSocket ou MQTT (§17.2) | Remplacé par un flux SSE natif (lot 25) : Reverb 1.12.0 exige guzzlehttp/psr7 ^2.6, incompatible avec Guzzle 8 / psr7 3.2 du projet ; l'utilisateur a refusé de rétrograder Guzzle. Chaque flux occupe un processus PHP 25 s : prévoir les processus PHP-FPM en conséquence. En développement sous Windows, le flux est servi par un second processus (`php artisan serve --port=8001`, proxy Vite) |
| Orientation appliquée par le lecteur (A-D16) | Rotation logicielle disponible par profil (lot 21) ; sinon l'orientation est portée par la dalle ou le système hôte |
| Fuseau horaire par site (A-D17) | Fait (lot 24) pour les lecteurs, la plage et la surveillance ; les heures saisies dans l'administration restent affichées en heure de Libreville |
| Plans d'étage réels (§4.3) | Mécanisme en place (plan déposé par niveau, écrans positionnés) ; aucun plan fourni : les vues affichent les écrans par emplacement |
| Promotion automatique d'une version (§21.3) | Vagues manuelles et suivi par lecteur en place ; pas de promotion automatique sur indicateurs |
| Plateformes Android, Windows natif, Linux natif, Smart TV | Non développées, non testées : seule l'application web Chromium kiosque est livrée |
| Battements historisés un sur dix environ (B-D10) | Choix de volumétrie : dernier état complet dans `devices`, échantillon toutes les 5 min dans `device_telemetry` |

## Décisions attendues (maîtrise d'ouvrage / DSI)

1. Accès de tout compte connecté à `/referentials` (annuaire interne).
2. Introduction d'un serveur temps réel.
3. Choix du matériel de pilotage des dalles (HDMI-CEC ou RS-232/API) pour valider veille, réveil et redémarrage.
4. Fourniture des plans d'étage.

## Environnement de test

- Les parcours de bout en bout utilisant des comptes à second facteur obligatoire ont tourné contre une API lancée avec `SAIE_MFA_ENFORCED=false` dans l'environnement de ce seul processus ; `.env` n'a pas été modifié et l'API a été remise en configuration normale ensuite.
- Le parcours « lecteur kiosque » historique (`player.spec.ts`) ne s'exécute qu'en semaine aux heures de bureau à Libreville ; les scénarios de recette du module utilisent des écrans actifs 7 j/7.
