# Déploiement

## Prérequis vérifiés (TECH-01)

- PHP 8.4 (8.3 minimum pour Laravel 13) avec `pdo_pgsql`, `mbstring`, `openssl` (Ed25519), `intl`, `fileinfo`, `zip`, `gd`. Le `Dockerfile` échoue si l’une manque.
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

```bash
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force            # migrations additives ; jamais migrate:fresh en production (TECH-04)
php artisan db:seed --class=ReferenceSeeder --force   # référentiel minimal, rejouable
php artisan saie:create-admin dsi@ceeac-eccas.org "Administrateur DSI" --role=admin_tech
php artisan config:cache && php artisan route:cache
cd ../frontend && npm ci && npm run build          # → frontend/dist
cd ../player && npm ci && NODE_ENV=production npm run build   # → player/dist servi sous /player/
```

Configuration Nginx de référence : `infra/nginx.conf` (frontend, lecteur et API sous un même domaine, API-05). Lancement intégral par conteneurs : `infra/docker-compose.yml`.

Processus à superviser : `php artisan queue:work --queue=default,notifications,media,exports`, `php artisan schedule:work` (ou cron `* * * * * php artisan schedule:run`).

## Retour arrière (TECH-03)

Sauvegarde de la base avant chaque migration sensible (voir `exploitation/sauvegarde-restauration.md`), déploiement de l’artefact précédent, `php artisan migrate:rollback --step=N` uniquement pour des migrations non destructives. Les lecteurs conservent leur dernier programme valide pendant l’opération.

## Remarque sur ce poste de développement

`.env` et `phpunit.xml` visent l’instance PostgreSQL 18 de Laragon (port 5433), qui exige un rôle `saie` créé par un superutilisateur (`CREATE ROLE saie LOGIN PASSWORD '…' CREATEDB;`). Utiliser PHP 8.4 (`php-8.4.7` dans Laragon) : la version 8.3 proposée par défaut est refusée par Composer.
