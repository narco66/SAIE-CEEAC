# SAIE-CEEAC

Système d’affichage institutionnel électronique, de diffusion documentaire et de gestion des salles de la Commission de la CEEAC (cahier des charges v3.0).

| Composant | Dossier | Technologie |
| --- | --- | --- |
| API métier `/api/v1`, workers, planificateur | `backend/` | Laravel 13, PHP 8.4, PostgreSQL 18 |
| Interface d’administration (V01–V42) | `frontend/` | React 19, TypeScript, Vite 8, TanStack Query, React Router |
| Lecteur kiosque : écrans T01/T02, panneau de salle T03, appairage T04, orientation T05 | `player/` | React 19, TypeScript, Vite, IndexedDB + Cache Storage, pdf.js, Ed25519 (tweetnacl) |
| Documentation | `docs/` | Architecture, API (OpenAPI 3.1), déploiement, lecteur, exploitation, conformité |
| Infrastructure | `infra/` | Docker Compose, Nginx, Dockerfiles |

## Démarrage local (Windows / Laragon)

Prérequis : PHP 8.4 avec `pdo_pgsql`, `intl`, `fileinfo`, `zip`, `gd`, `openssl` ; Composer 2 ; Node.js 22 LTS ; PostgreSQL 18 ; Mailpit (fourni par Laragon).

```powershell
# 1. Base de données (une fois, en superutilisateur PostgreSQL 18 — port 5433 sur ce poste)
psql -h 127.0.0.1 -p 5433 -U postgres -c "CREATE ROLE saie LOGIN PASSWORD 'saie' CREATEDB;"
psql -h 127.0.0.1 -p 5433 -U saie -d postgres -c "CREATE DATABASE saie_ceeac ENCODING 'UTF8' TEMPLATE template0;"
psql -h 127.0.0.1 -p 5433 -U saie -d postgres -c "CREATE DATABASE saie_ceeac_test ENCODING 'UTF8' TEMPLATE template0;"

# 2. Backend
cd backend
composer install
copy .env.example .env      # puis ajuster DB_* ; en local SAIE_DEMO_MODE=true, SAIE_MFA_ENFORCED=false
php artisan key:generate
php artisan migrate --seed  # référentiel + jeu de démonstration (environnement local uniquement)

# 3. Lancer les services (un terminal par ligne, depuis la racine du dépôt)
cd backend;  php artisan serve --port=8000
cd backend;  php artisan queue:work --queue=default,notifications,media,exports
cd backend;  php artisan schedule:work
cd frontend; npm install; npm run dev        # http://localhost:5173 (proxy /api → :8000)
cd player;   npm install; npm run dev        # http://localhost:5174 (lecteur)
```

Comptes de démonstration : générés au seed, avec un mot de passe aléatoire écrit dans `backend/storage/app/private/demo-accounts.txt` (non versionné). Liste et rôles : [docs/demonstration.md](docs/demonstration.md).

Enrôler un lecteur : ouvrir `http://localhost:5174` (il affiche un code à 6 caractères), puis dans l’administration « Écrans & lecteurs › Enrôler un lecteur ». Voir [docs/lecteur.md](docs/lecteur.md).

## Tests

```powershell
cd backend; vendor\bin\phpunit           # tests unitaires et fonctionnels (PostgreSQL 18 réel, base saie_ceeac_test)
cd player; npm test                      # résolveur de programme du lecteur
cd frontend; npx tsc --noEmit            # typage
cd frontend; npx playwright test         # parcours de bout en bout (API, frontend et lecteur démarrés)
```

## Documentation

- [Architecture](docs/architecture.md) · [Déploiement](docs/deploiement.md) · [API OpenAPI](docs/api/openapi.json) (`cd backend; php artisan saie:openapi` le régénère)
- [Manuels utilisateur par rôle](docs/manuels/premiers-pas.md) · [Lecteurs : installation, enrôlement et pilotage des dalles](docs/lecteur.md)
- [Mise en service et exploitation](docs/exploitation/mise-en-service.md) · [Sauvegarde et restauration](docs/exploitation/sauvegarde-restauration.md) · [Dictionnaire de données](docs/donnees/dictionnaire.md)
- [Décisions et hypothèses](docs/decisions.md) · [Sources consultées](docs/inventaire-sources.md)
- [Matrice de conformité](docs/conformite.md) · [Rapport de tests](docs/tests/rapport.md) · [Limites restantes](docs/limites.md)
