# 04 — Architecture technique du module « Gestion des écrans »

## 1. Les trois objets distingués (§23)

| Objet | Table | Rôle |
|---|---|---|
| Écran physique | `screens` | Identité, fiche administrative et technique, emplacement, statut (en service, maintenance, désactivé, archivé), profil |
| Lecteur (Player) | `devices` | Appairage, jeton individuel, capacités déclarées, télémétrie, file de commandes |
| Terminal de diffusion | association `devices.screen_id` (un seul lecteur actif par écran, index unique partiel) + manifeste signé | Programme, configuration, alertes, preuves de diffusion |

Remplacer une dalle (nouvel écran) ou un lecteur (réappairage avec `replace`) ne fait perdre ni l'identité ni l'historique de l'autre objet.

## 2. Composants

```
Navigateur (React/Vite)  ──HTTPS + session Sanctum──▶  API Laravel 13  ──▶  PostgreSQL 18
                                                         │  ▲
SAIE Player (application web kiosque) ──HTTPS + jeton────┘  │ battement 15–60 s, manifeste signé Ed25519,
   │  cache vérifié (IndexedDB, empreintes)                  │ accusés de diffusion, accusés de commandes
   └──127.0.0.1──▶ agent local (panel-agent.mjs) : veille/réveil de la dalle (HDMI-CEC ou commande), redémarrage
File Laravel (worker) : exports, notifications, conversions ; planificateur `saie:tick` chaque minute
```

## 3. Flux principaux

| Flux | Mécanisme |
|---|---|
| Enrôlement | Code à usage unique de 10 min affiché par le lecteur ; jeton remis une fois, chiffré en base pendant la remise, rotation à mi-vie |
| Programme | Manifeste compilé par écran (`ManifestBuilder`), signé Ed25519 ; ETag ; téléchargement différentiel vérifié par SHA-256 ; bascule atomique |
| Configuration (§7) | Profil effectif (écran, sinon groupe) dans le manifeste avec empreinte ; le lecteur confirme l'empreinte appliquée et liste les paramètres non pris en charge |
| Supervision (§10) | Battement : capacités, état de lecture, caractéristiques, configuration appliquée, erreurs datées ; `saie:tick` : statut réseau, incidents automatiques, historique des états, groupes dynamiques, escalades, expiration des commandes |
| Commandes (§11) | `device_commands` : en attente → envoyée (au battement) → reçue → exécutée/échouée (compte rendu du lecteur) ; expirée sinon ; relances confirmées au retour du lecteur |
| Temps réel | Rafraîchissement HTTP : supervision 15 s, suivi d'une commande 5 s, battement 30 s par défaut. Aucun WebSocket/MQTT (nouvelle infrastructure, non autorisée) |

## 4. Services ajoutés ou étendus

| Service | Responsabilité |
|---|---|
| `RemoteCommands` | Catalogue, disponibilité selon les capacités, remise, accusés idempotents, expiration, annulation |
| `DynamicGroups` | Critères des groupes dynamiques, recalcul des membres |
| `FleetOverview` | Indicateurs et répartitions du tableau de bord, tendances, vue par niveau |
| `FleetReports` | Rapports PDF11 à PDF15 |
| `IncidentPolicy` | Échéances par gravité, responsable par défaut, escalade, statistiques |
| `ScreenHistory` | Historique unifié d'un écran |
| `CampaignService`, `ManifestBuilder`, `Access`, `ExportService`, `SaieTick`, `ApplyRetention` | Étendus (périmètre, écran neutralisé, configuration, nouveaux états, purges) |

## 5. Choix structurants

- **Aucun module concurrent** : tout est construit sur les tables et services existants (`screens`, `devices`, `group_members`, `incidents`, moteur de campagnes).
- **États calculés, jamais présumés** : « en diffusion » exige une lecture déclarée récente d'un lecteur joignable ; une commande n'est « exécutée » que sur compte rendu ; une configuration n'est « appliquée » que sur empreinte confirmée.
- **Capacités déclarées** : le serveur ne propose et n'accepte que les commandes que le lecteur déclare savoir exécuter (RG-006).
- **Périmètre partout** : lecture et écriture bornées par site, zone, niveau et groupe de l'habilitation (`Access::screenScope`).
