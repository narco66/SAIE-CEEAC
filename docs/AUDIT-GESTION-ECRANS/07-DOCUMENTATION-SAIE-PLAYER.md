# 07 — SAIE Player

## Plateforme cible

Le SAIE Player est une **application web** (`player/`, React + Vite, service worker, IndexedDB) exécutée dans un navigateur Chromium en **mode kiosque**. C'est la seule plateforme développée et testée (Chromium sous Windows, par Playwright). **Aucune compatibilité Android, Windows natif, Linux natif ou Smart TV n'est annoncée** : elle n'a été ni développée ni testée.

Un **agent local facultatif** (`player/agent/panel-agent.mjs`, Node.js, écoute uniquement `127.0.0.1:7071`) pilote la dalle : pilote `cec` (HDMI-CEC via `cec-client`), `command` (commandes système : RS-232, API du constructeur) ou `simulate` (essais). Il n'accepte que l'origine du lecteur. Le redémarrage de l'appareil n'est proposé que si `SAIE_REBOOT_CMD` est défini.

## Protocole

1. **Enrôlement** : le lecteur demande un code (`POST /device/pairing-codes`), l'affiche, puis interroge son état avec un secret de sondage ; un administrateur saisit le code ; le lecteur récupère une seule fois son jeton.
2. **Synchronisation** (30 s par défaut, réglable par profil) : `GET /device/manifest` avec ETag ; vérification de la signature Ed25519 ; téléchargement des médias manquants vérifiés par SHA-256 ; bascule atomique ; accusés `manifest_received`, `content_loaded`, `activated`.
3. **Battement** (30 s par défaut, 15–60 s par profil) : version, plateforme, résolution, stockage, erreurs datées, lecture en cours, inventaire du cache, **capacités**, **diffusion arrêtée**, **caractéristiques** (définition, cœurs, mémoire, langue, fuseau, tactile) et **configuration appliquée** (empreinte, paramètres appliqués et non pris en charge). Réponse : heure du serveur et **commandes** à exécuter.
4. **Commandes** : pour chaque commande, accusé `received`, exécution, puis `executed` ou `failed` avec compte rendu. Relance de l'application et redémarrage : exécution confirmée **au retour** du lecteur (preuve du redémarrage). Une commande déjà traitée n'est jamais réexécutée.
5. **Accusés de diffusion** : file locale persistante, envoi différé dédoublonné ; « lecture déclarée » à la fin de chaque créneau réussi, agrégée par tranches de 10 minutes.

## Capacités déclarées

| Capacité | Commandes | Condition |
|---|---|---|
| `sync`, `refresh`, `restart_player`, `stop_playback`, `resume_playback`, `screenshot`, `logs` | Synchroniser, actualiser, redémarrer le lecteur, arrêter et reprendre la diffusion, capturer, journaux | Toujours (application web) |
| `panel_power` | Mettre en veille, sortir de veille ; paramètre « veille hors plage » | Agent local joignable et canal de pilotage déclaré sur l'écran |
| `reboot_device` | Redémarrer l'appareil | Agent local avec `SAIE_REBOOT_CMD` |
| `panel_picture` | Luminosité, contraste | **Jamais déclarée actuellement** (aucun pilote d'image) |

## Fonctionnement hors ligne (§23)

Le programme vérifié et ses médias restent en cache ; la lecture continue pendant la validité de l'autorisation du manifeste (24 h en zone publique, 1 h en zone interne, réglables) ; un bandeau signale la perte de contact ; les tentatives de reconnexion s'espacent jusqu'au délai du profil ; à la reprise, accusés et événements différés sont transmis. Démontré par le parcours de recette B/G (`screens-scenarios.spec.ts`).

## Limites connues

- La capture d'écran copie le rendu de la page (sans les images vidéo) : elle ne prouve pas l'affichage physique.
- Le « stockage » est le quota du navigateur, pas le disque ; MAC, système et firmware ne sont pas accessibles à une application web.
- L'identité matérielle est un identifiant aléatoire conservé par le navigateur : effacer les données du navigateur impose un réappairage.
