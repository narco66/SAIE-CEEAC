# 01 — Rapport d'audit initial du module « Gestion des écrans de diffusion »

Date : 8 novembre 2026. Référentiel : spécification fonctionnelle détaillée v1.0 (24 sections).
Méthode : trois revues indépendantes en lecture seule du code (backend, frontend, lecteur) et de la base locale, chaque constat étant appuyé sur un fichier et une ligne, une route ou une colonne. Le détail figure dans la [matrice de conformité](02-MATRICE-CONFORMITE.md).

## 1. Environnement constaté

| Élément | Constat |
|---|---|
| Backend | Laravel 13, PHP 8.5 (Laragon), `backend/` |
| Base | PostgreSQL 18, port 5433, base `saie_ceeac` (88 tables) ; base de test `saie_ceeac_test` |
| Frontend | React + Vite + TypeScript, `frontend/` (pas de Tailwind : feuilles CSS du design system `app.css`, `maquette.css`) |
| Lecteur (SAIE Player) | Application web kiosque, `player/` (Vite, service worker, IndexedDB), agent local optionnel `player/agent` |
| Temps réel | Pas de WebSocket ni de MQTT : battements de cœur HTTP toutes les 30 s, supervision rafraîchie toutes les 60 s |
| Files | Queue Laravel en base (`jobs`), planificateur `saie:tick` chaque minute |
| Tests | PHPUnit (245 tests), Playwright (43 parcours) |

**Sauvegarde préalable :** `backend/storage/app/private/backups/saie_ceeac-avant-module-ecrans-20261108-0738.dump` (format custom, SHA-256 dans le fichier `.sha256`). Elle a été restaurée dans une base temporaire, et les volumes de chaque table ont été comparés. Seule `cache_locks` diffère, d'une ligne : c'est un verrou éphémère du planificateur. La base temporaire a ensuite été supprimée.

## 2. Existant

Le module n'est pas à créer : il existe déjà un socle solide, qui doit être conservé et étendu.

- **Écrans** (`screens`) : inventaire avec code, nom, type, localisation (`locations` → `floors` → `buildings` → `sites`), zone de diffusion, orientation, résolution, données matérielles saisies, plage de fonctionnement et garantie.
- **Lecteurs** (`devices`), distincts des écrans. Ils disposent :
  - d'un appairage par code à usage unique valable 10 minutes, avec rotation du jeton et révocation ;
  - d'un manifeste signé (Ed25519, anti-rejeu) et d'une synchronisation différentielle (ETag, Range) ;
  - d'un cache vérifié par empreinte, d'un fonctionnement hors ligne et de canaux de version (pilote ou stable).
- **Supervision** :
  - battements de cœur et télémétrie échantillonnée ;
  - accusés de diffusion (`device_receipts`) et captures d'écran ;
  - incidents automatiques de perte de contact ;
  - plans de maintenance préventive.
- **Groupes** (`screen_groups`, `group_members`) statiques, ciblés par les campagnes.
- **Programmation** : moteur de campagnes, listes de lecture, alertes prioritaires avec retour automatique au programme.

## 3. Bilan chiffré

| | Exigences | Conformes | Partielles | Absentes | Défectueuses |
|---|---|---|---|---|---|
| Total | 266 | 75 (28 %) | 125 (47 %) | 53 (20 %) | 13 (5 %) |

## 4. Constats majeurs

### 4.1 Sécurité (P0)
1. **Contrôle de périmètre neutralisé.** Il est inopérant pour un écran désactivé ou un groupe vide, car la résolution des cibles ne retient que les écrans actifs ou en maintenance. Un gestionnaire limité à une zone peut alors modifier ou réactiver un écran hors de sa zone, appairer ou révoquer un lecteur, ou modifier un groupe.
2. **Déplacement d'un écran.** Le périmètre n'est pas vérifié à nouveau après le changement d'emplacement.
3. **Incidents, plans de maintenance, exports d'inventaire et d'incidents.** Aucun ne tient compte du périmètre de l'habilitation.
4. **Cibles de campagne.** Leur existence n'est pas vérifiée.
5. **`GET /referentials`.** Cette route n'exige aucune permission.
6. **Jeton d'appairage.** Il reste en clair en base jusqu'à son retrait, au plus 10 minutes.

### 4.2 Règles métier (P0)
- **RG-005 / RG-013** : un écran désactivé reçoit toujours son programme, et l'état « archivé » n'existe pas.
- **RG-012** : un incident peut être clos sans résultat de traitement.
- **Gravité « moyen »** : elle est impossible en base, la contrainte n'admettant que critique, majeur et mineur.
- **Anomalies non détectées** : un écran ayant un incident déclaré à la main n'apparaît pas « en anomalie ».
- **Anomalies persistantes** : une erreur passée du lecteur maintient l'écran « en anomalie ».
- **Totaux du tableau de bord incohérents** : 19 écrans inventoriés contre 21 en base, et la carte « écrans connectés » ouvre la liste inverse.

### 4.3 Fonctions absentes (P0 et P1)
- **Commandes à distance.** Il n'y a qu'un emplacement unique `pending_command`, limité au rechargement, effacé dès la remise et jamais acquitté par le lecteur. Il manque :
  - la file de commandes, l'identifiant de transaction et le cycle d'états ;
  - les capacités du lecteur ;
  - les commandes de synchronisation, de redémarrage, d'arrêt et de reprise, de veille et de consultation des journaux.
- **Supervision** :
  - pas d'état « en diffusion » confirmé, ni « en veille » ;
  - pas d'historique des états ;
  - seuils non configurables ;
  - pas d'alerte pour échec de lecture, lecteur arrêté ou version obsolète.
- **Inventaire** :
  - champs administratifs et techniques manquants (date d'acquisition, fournisseur, référence de commande, technologie, consommation, MAC, description restituée) ;
  - pas d'archivage, d'import d'écrans, de QR code par écran, d'impression de fiche, ni d'historique des déplacements ;
  - pas de rattachement d'un écran d'affichage à une salle.
- **Configuration.** Il n'y a pas de profil de configuration réutilisable ni d'accusé d'application.
- **Groupes.** Les groupes dynamiques n'existent pas.
- **Rapports** absents : écrans hors ligne, commandes exécutées, fin de garantie, inventaire par bâtiment ou étage. La disponibilité et la maintenance n'existent qu'en JSON.
- **Profils.** Les rôles « Responsable du parc » et « Superviseur » n'existent pas, et le périmètre ne peut pas être limité à un étage ou à un groupe.

## 5. Limites matérielles identifiées dès l'audit

Le lecteur est une application web exécutée dans un navigateur en mode kiosque. Sans agent local, elle ne peut pas :
- éteindre ou rallumer la dalle (veille matérielle) ;
- régler la luminosité ou le contraste ;
- redémarrer le système ;
- lire le disque, le processeur ou la température ;
- capturer la sortie vidéo réelle.

Ces fonctions seront implémentées derrière des **capacités déclarées par le lecteur** : une commande non prise en charge sera refusée par le serveur et présentée comme indisponible dans l'interface, avec son motif. Leur validation sur matériel réel restera « en attente ».
