# Lecteurs : installation, enrôlement et fonctionnement hors connexion

## Matériel et navigateur (PLY-01)

Mini-PC administré (8 Go RAM, SSD 64 Go, décodage matériel H.264), Ethernet sur le VLAN des lecteurs (SEC-06), Chromium/Chrome stable maintenu à jour (EXP-02). Démarrage automatique en kiosque :

```
chrome --kiosk --noerrdialogs --disable-session-crashed-bubble --disable-infobars --autoplay-policy=no-user-gesture-required \
       --check-for-update-interval=31536000 --overscroll-history-navigation=0 --disable-pinch https://saie.ceeac.local/player/
```

- **Autoplay** : les vidéos sont lancées muettes (autorisé par tous les navigateurs) ; l’audio n’est activé qu’à l’accueil, de 8 h à 18 h, avec le volume plafond de la zone (PLY-10). Le drapeau `--autoplay-policy` est requis pour le son.
- **Codecs** : H.264/AAC (MP4) et WebM VP9 ; tester chaque famille de matériel au pilote. Le transcodage côté serveur n’a lieu que si ffmpeg est installé et déclaré (`SAIE_FFMPEG_PATH`) ; sinon, déposer les vidéos en H.264/AAC.
- **Stockage** : le lecteur demande le stockage persistant (`navigator.storage.persist()`) ; en mode kiosque sur profil dédié, Chrome l’accorde. La capacité réelle est remontée dans le heartbeat (`storage.usage/quota`). Réserve de 20 %, éviction des ressources non référencées ; si le navigateur ne garantit pas la persistance, prévoir un agent natif de cache (PLY-04, décision au pilote).
- **Chien de garde** : le lecteur se recharge si le rendu est figé 60 s ou après 5 erreurs en une minute. Le redémarrage du processus et du poste relève du superviseur du système (tâche planifiée Windows / systemd `Restart=always`).
- **Extinction de la dalle** (PLY-08) : hors plage, le lecteur affiche un écran noir et espace sa synchronisation à 5 minutes. Si l’agent local de pilotage est installé (voir ci-dessous), il éteint puis rallume physiquement la dalle et remonte son état (PLY-09) ; sinon, l’état reste « Non mesuré ».

## Pilotage des dalles (PLY-08, PLY-09)

Un agent local, `player/agent/panel-agent.mjs`, est installé sur le mini-PC à côté du navigateur kiosque :
- il écoute uniquement `127.0.0.1:7071` ;
- le lecteur l’appelle pour allumer ou éteindre la dalle aux bornes de la plage de fonctionnement ;
- il lit aussi l’état de la dalle et le transmet à chaque heartbeat.

Il exige Node.js 22.

| Pilote (`SAIE_PANEL_DRIVER`) | Usage | Prérequis |
| --- | --- | --- |
| `cec` | HDMI-CEC (adresse logique de l’écran : 0) | paquet `libcec` (`cec-client`), dalle et câble compatibles CEC |
| `command` | RS-232 ou API du constructeur | `SAIE_PANEL_ON_CMD`, `SAIE_PANEL_OFF_CMD`, `SAIE_PANEL_STATUS_CMD` ; cette dernière doit afficher `on`, `off` ou `standby` |
| `simulate` | essais sans matériel | — |

Mise en place, par lecteur :

1. Copier `panel-agent.mjs` sur le poste, par exemple dans `/opt/saie/`.
2. Le lancer comme service redémarré automatiquement, avec l’origine du lecteur en variable d’environnement. Exemple systemd :

   ```ini
   [Service]
   Environment=SAIE_PANEL_DRIVER=cec
   Environment=SAIE_PLAYER_ORIGIN=https://saie.ceeac.local
   ExecStart=/usr/bin/node /opt/saie/panel-agent.mjs
   Restart=always
   ```

   Sous Windows : tâche planifiée au démarrage, avec les mêmes variables.

   `SAIE_PLAYER_ORIGIN` est obligatoire (sauf en simulation) : l’agent refuse de démarrer sans elle et rejette toute commande venant d’une autre origine.
3. Vérifier localement : `curl http://127.0.0.1:7071/panel` renvoie le pilote et l’état (`on`, `standby`…). Puis `curl -X POST -H "Content-Type: application/json" -d '{"power":"off"}' http://127.0.0.1:7071/panel` éteint la dalle.
4. Dans l’administration (fiche écran, V08), renseigner le **Canal de pilotage de la dalle** :
   - **HDMI-CEC**, **RS-232** ou **API constructeur** : l’état réel s’affiche alors dans la fiche et dans la supervision ;
   - sans canal, il reste « Non mesuré ».

Chaque modèle de dalle se teste au pilote, avec un cycle complet d’extinction et de rallumage (R25, partie matérielle). Certaines dalles ignorent le CEC en veille profonde.

## Versions du lecteur et capture de rendu

- **Versions** (PLY-12) :
  - chaque lecteur suit le canal **Stable** ou **Pilote**, choisi sur sa fiche (onglet **Lecteur**) ;
  - la version cible de chaque canal est portée par le manifeste signé ;
  - la publication, la promotion et le retrait se font en V40 (voir [mise en service, § 5](exploitation/mise-en-service.md#5-publier-une-version-du-lecteur-ply-12)).
- **Capture de rendu** (PLY-11) :
  - **Demander une capture** (fiche écran, onglet **Lecteur**) ;
  - le lecteur photographie son affichage et le transmet une seule fois ;
  - l’image est conservée 7 jours, puis supprimée automatiquement.

## Enrôlement (PLY-02, P06)

1. Ouvrir l’URL du lecteur sur le terminal : il affiche un **code à 6 caractères** valable 10 minutes, renouvelé automatiquement.
2. Dans l’administration : *Écrans & lecteurs › Enrôler un lecteur*, saisir le code et choisir l’écran (remplacement explicite si l’écran a déjà un lecteur actif).
3. Le lecteur récupère une seule fois son jeton individuel et la clé publique de signature, puis télécharge son premier manifeste. Le jeton n’est jamais affiché dans l’interface.

Révocation : fiche écran › *Révoquer le lecteur* (motif obligatoire). L’API refuse immédiatement le terminal ; le contenu déjà en cache reste lisible jusqu’à l’expiration de l’autorisation en cours (24 h public, 1 h interne), puis le lecteur affiche un message neutre, sans aucune action proposée au public. Pour réappairer un terminal révoqué, un technicien de la DSI utilise au clavier la combinaison de maintenance **Ctrl + Alt + Maj + P**, qui efface l’identité du lecteur et affiche un nouveau code.

## Cycle de diffusion

- Synchronisation toutes les 30 s (± 5 s) avec `If-None-Match` ; 5 min hors plage, réveil 5 min avant l’ouverture.
- Le manifeste n’est accepté qu’après vérification de sa signature Ed25519, de son destinataire et de sa validité ; un manifeste expiré ou d’une version antérieure à la version active est refusé (aucun retour arrière par rejeu). Toutes les ressources du programme sont téléchargées et leur SHA-256 vérifié **avant** la bascule, qui se fait en une seule écriture IndexedDB. En cas d’échec, la version précédente reste active.
- Les **alertes**, l’agenda des réunions, le panneau de salle et l’orientation du nouveau manifeste s’appliquent dès sa vérification, sans attendre le téléchargement des médias : une alerte paraît en moins d’une minute même pendant le chargement d’une vidéo.
- Chaque appel au serveur a un délai maximal (20 s ; téléchargement d’un média : proportionnel à sa taille, une minute au moins) ; heartbeat et synchronisation sont indépendants. Les médias des deux derniers manifestes restent téléchargeables. En production, l’API autorise chaque téléchargement puis délègue l’envoi du fichier à Nginx (`X-Accel-Redirect`).
- Hors ligne : poursuite des seuls contenus préchargés dont l’autorisation n’a pas expiré ; à l’expiration de l’autorisation publique (24 h), l’agenda, les réunions du panneau et les messages de bienvenue cessent d’être affichés ; bandeau « Mode hors connexion » après 90 s sans contact ; le panneau de salle affiche « Statut non actualisé » et n’accepte aucune réservation.
- Événements différés (`manifest_received`, `content_loaded`, `activated`, `played`, `alert_received`, `alert_activated`, `offline`, `online`, `error`) stockés localement avec un identifiant unique, envoyés au heartbeat suivant et dédoublonnés par le serveur.
- Horloge : l’écart avec l’heure serveur est mesuré à chaque heartbeat, stocké, et appliqué au calcul du programme ; il est affiché dans la supervision.

## Modes d’affichage

Le type d’écran défini dans le parc détermine la vue : `display` (T01 paysage / T02 portrait, rotation des langues approuvées), `room_panel` (T03 : libre / occupée / indisponible, « Réservée » pour une réunion confidentielle), `orientation` (T05 : niveaux, structures et salles issus des référentiels, alternance FR/EN, variante « Bienvenue »).
