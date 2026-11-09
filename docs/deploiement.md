# Déploiement

## Prérequis vérifiés (TECH-01)

- PHP 8.4 (exigé par les dépendances verrouillées) avec `pdo_pgsql`, `mbstring`, `openssl` (Ed25519), `intl`, `fileinfo`, `zip`, `gd`, `redis` et OPcache. L’image `infra/php.Dockerfile` échoue si l’une manque.
- PostgreSQL 18 ; extension `btree_gist` (installée par la migration, extension « de confiance » ne nécessitant pas de superutilisateur) et `pgcrypto`.
- Node.js 22 LTS pour construire `frontend` et `player`.
- Production : Nginx (ou équivalent) avec TLS, Redis, superviseur de processus pour `queue:work` et `schedule:work`, ClamAV (`clamd`).

## Environnements (TECH-02)

| Variable | Développement | Production |
| --- | --- | --- |
| `APP_ENV` / `APP_DEBUG` | `local` / `true` | `production` / `false` |
| `SAIE_DEMO_MODE` | `true` | `false` (jeu de démonstration jamais injecté) |
| `SAIE_MFA_ENFORCED` | `false` | `true` |
| `SAIE_AV_DRIVER` | `signature` (test EICAR seulement) | `clamd` |
| `QUEUE_CONNECTION`, `CACHE_STORE` | `database` | `redis` |
| `SESSION_SECURE_COOKIE` | `false` | `true` |
| `SAIE_MANIFEST_KEY_PATH` | générée automatiquement | **obligatoire**, fournie par la DSI, hors dépôt |

Clé de signature des manifestes (production) :

```bash
openssl genpkey -algorithm ed25519 -out /etc/saie/manifest-ed25519.pem && chmod 600 /etc/saie/manifest-ed25519.pem
# SAIE_MANIFEST_KEY_PATH=/etc/saie/manifest-ed25519.pem ; SAIE_MANIFEST_KEY_ID=mk-2026-11
```

Une rotation de clé impose un réappairage des lecteurs (la clé publique est remise à l’appairage).

Clés VAPID des notifications push (NOT-03) : générer une paire propre à chaque environnement, ne jamais réutiliser celle d’un poste de développement.

```bash
php artisan saie:vapid   # copie SAIE_VAPID_PUBLIC_KEY et SAIE_VAPID_PRIVATE_KEY dans le .env de production, hors dépôt
```

Changer la paire invalide les abonnements push déjà enregistrés.

## Mise en production

Procédure pas à pas, contrôles et liste de vérification d’exploitation : [exploitation/mise-en-service.md](exploitation/mise-en-service.md).

La pile de référence est `infra/docker-compose.yml` : PostgreSQL 18, Redis, ClamAV, PHP-FPM (`api`), `worker`, `scheduler` et Nginx (`web`) en HTTPS.

- **Images.** Elles sont construites depuis la racine du dépôt. Chaque Dockerfile a sa liste blanche (`infra/*.Dockerfile.dockerignore`) : aucun `.env`, aucune clé, aucune donnée ni dépendance de développement n’entre dans une image.
- **Configuration.** Elle est montée à l’exécution :
  - `infra/.env`, sur le modèle de production `infra/.env.example` ;
  - `infra/secrets/manifest-ed25519.pem`, la clé de signature ;
  - `infra/certs/`, le certificat TLS.
- **Démarrage des conteneurs PHP.** Ils mettent en cache la configuration, les routes et les événements à partir de l’environnement réel. OPcache tourne sans revalidation, puisque le code est figé dans l’image.
- **Nginx.**
  - Il redirige tout le HTTP vers HTTPS, n’accepte que TLS 1.2 et 1.3, et envoie HSTS, une CSP par application et les en-têtes de sécurité sur chaque emplacement.
  - Il sert les versions du lecteur publiées par canal depuis le volume `player-releases`, sous `/player/releases/`.
- **Contrôle d’ouverture.** `php artisan saie:preflight`, ou le service `preflight` du profil `deploy`, refuse la mise en service tant qu’un contrôle bloquant échoue : débogage actif, HTTP, démonstration active ou données fictives présentes, second facteur non imposé, antivirus factice ou injoignable, clé de signature absente ou mal protégée, valeurs « À_FOURNIR » restantes, migrations en attente…
- **Validation en CI.** Le job `infra` de la CI construit les deux images, vérifie leur contenu, la validité du Compose et de la configuration Nginx, et que le preflight refuse une configuration incomplète.

## Retour arrière (TECH-03)

Sauvegarde de la base avant chaque migration sensible (voir `exploitation/sauvegarde-restauration.md`), déploiement de l’artefact précédent, `php artisan migrate:rollback --step=N` uniquement pour des migrations non destructives. Les lecteurs conservent leur dernier programme valide pendant l’opération.

## Remarque sur ce poste de développement

`.env` et `phpunit.xml` visent l’instance PostgreSQL 18 de Laragon (port 5433), qui exige un rôle `saie` créé par un superutilisateur (`CREATE ROLE saie LOGIN PASSWORD '…' CREATEDB;`). Utiliser PHP 8.4 (`php-8.4.7` dans Laragon) : la version 8.3 proposée par défaut est refusée par Composer.
