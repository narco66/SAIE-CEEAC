#!/bin/sh
# Démarrage des conteneurs PHP (api, worker, scheduler) : la configuration, les routes et les événements sont mis en cache
# à partir des variables d'environnement réelles du conteneur, puis la commande demandée est lancée.
set -e

if [ -f artisan ]; then
    php artisan config:cache --no-interaction >/dev/null
    php artisan route:cache --no-interaction >/dev/null
    php artisan event:cache --no-interaction >/dev/null
fi

exec docker-php-entrypoint "$@"
