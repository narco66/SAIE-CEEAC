# Lecteurs : installation, enrôlement et fonctionnement hors connexion

## Matériel et navigateur (PLY-01)

Mini-PC administré (8 Go RAM, SSD 64 Go, décodage matériel H.264), Ethernet sur le VLAN des lecteurs (SEC-06), Chromium/Chrome stable maintenu à jour (EXP-02). Démarrage automatique en kiosque :

```
chrome --kiosk --noerrdialogs --disable-session-crashed-bubble --disable-infobars --autoplay-policy=no-user-gesture-required \
       --check-for-update-interval=31536000 --overscroll-history-navigation=0 --disable-pinch https://saie.ceeac.local/player/
```

- **Autoplay** : les vidéos sont lancées muettes (autorisé par tous les navigateurs) ; l’audio n’est activé qu’à l’accueil, de 8 h à 18 h, avec le volume plafond de la zone (PLY-10). Le drapeau `--autoplay-policy` est requis pour le son.
- **Codecs** : H.264/AAC (MP4) et WebM VP9 ; tester chaque famille de matériel au pilote. Aucune conversion n’est faite côté serveur.
- **Stockage** : le lecteur demande le stockage persistant (`navigator.storage.persist()`) ; en mode kiosque sur profil dédié, Chrome l’accorde. La capacité réelle est remontée dans le heartbeat (`storage.usage/quota`). Réserve de 20 %, éviction des ressources non référencées ; si le navigateur ne garantit pas la persistance, prévoir un agent natif de cache (PLY-04, décision au pilote).
- **Chien de garde** : le lecteur se recharge si le rendu est figé 60 s ou après 5 erreurs en une minute. Le redémarrage du processus et du poste relève du superviseur du système (tâche planifiée Windows / systemd `Restart=always`).
- **Extinction de la dalle** (PLY-08) : hors plage, le lecteur affiche un écran noir et espace sa synchronisation à 5 minutes ; la commande HDMI-CEC / RS-232 requiert un agent local non fourni (état « Non mesuré » dans les vues, PLY-09 P1).

## Enrôlement (PLY-02, P06)

1. Ouvrir l’URL du lecteur sur le terminal : il affiche un **code à 6 caractères** valable 10 minutes, renouvelé automatiquement.
2. Dans l’administration : *Écrans & lecteurs › Enrôler un lecteur*, saisir le code et choisir l’écran (remplacement explicite si l’écran a déjà un lecteur actif).
3. Le lecteur récupère une seule fois son jeton individuel et la clé publique de signature, puis télécharge son premier manifeste. Le jeton n’est jamais affiché dans l’interface.

Révocation : fiche écran › *Révoquer le lecteur* (motif obligatoire). L’API refuse immédiatement le terminal ; le contenu déjà en cache reste lisible jusqu’à l’expiration de l’autorisation en cours (24 h public, 1 h interne), puis le lecteur affiche un message neutre avec possibilité de réappairage.

## Cycle de diffusion

- Synchronisation toutes les 30 s (± 5 s) avec `If-None-Match` ; 5 min hors plage, réveil 5 min avant l’ouverture.
- Le manifeste n’est accepté qu’après vérification de sa signature Ed25519 et de son destinataire ; toutes les ressources sont téléchargées et leur SHA-256 vérifié **avant** la bascule, qui se fait en une seule écriture IndexedDB. En cas d’échec, la version précédente reste active.
- Hors ligne : poursuite des seuls contenus préchargés dont l’autorisation n’a pas expiré ; bandeau « Mode hors connexion » après 90 s sans contact ; le panneau de salle affiche « Statut non actualisé » et n’accepte aucune réservation.
- Événements différés (`manifest_received`, `content_loaded`, `activated`, `played`, `alert_received`, `alert_activated`, `offline`, `online`, `error`) stockés localement avec un identifiant unique, envoyés au heartbeat suivant et dédoublonnés par le serveur.
- Horloge : l’écart avec l’heure serveur est mesuré à chaque heartbeat, stocké, et appliqué au calcul du programme ; il est affiché dans la supervision.

## Modes d’affichage

Le type d’écran défini dans le parc détermine la vue : `display` (T01 paysage / T02 portrait, rotation des langues approuvées), `room_panel` (T03 : libre / occupée / indisponible, « Réservée » pour une réunion confidentielle), `orientation` (T05 : niveaux, structures et salles issus des référentiels, alternance FR/EN, variante « Bienvenue »).
