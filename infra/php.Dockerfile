# Image applicative Laravel 13 / PHP 8.4 (TECH-01 : la construction échoue si une extension obligatoire manque).
# Construction depuis la racine du dépôt : docker build -f infra/php.Dockerfile .   (liste blanche : php.Dockerfile.dockerignore)
# Image de base figée par la pile (SAIE_IMAGE_PHP, étiquette@sha256) ; la valeur par défaut ne sert qu'à une construction manuelle.
ARG PHP_IMAGE=php:8.4-fpm-bookworm
FROM ${PHP_IMAGE} AS app
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libpng-dev libjpeg-dev libwebp-dev unzip git \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip gd opcache bcmath \
 && pecl install redis && docker-php-ext-enable redis \
 && php -r 'foreach (["pdo_pgsql","mbstring","openssl","intl","fileinfo","zip","gd","redis","Zend OPcache"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "Extension manquante: $e\n"); exit(1); } }' \
 && rm -rf /var/lib/apt/lists/*
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY infra/php/saie.ini /usr/local/etc/php/conf.d/zz-saie.ini
COPY infra/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY infra/php/zz-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf
COPY --chmod=0755 infra/php/entrypoint.sh /usr/local/bin/saie-entrypoint
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY backend/ .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
 && php artisan package:discover --ansi \
 && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

USER www-data
ENTRYPOINT ["saie-entrypoint"]
CMD ["php-fpm"]

# Worker de conversion (DOC-01, SEC-03) : mêmes sources, avec LibreOffice sans interface et ffmpeg. Exécuté isolé
# (réseau interne sans sortie, ressources plafonnées) par le service « worker-media » de docker-compose.yml.
FROM app AS media
USER root
RUN apt-get update && apt-get install -y --no-install-recommends libreoffice-writer-nogui libreoffice-calc-nogui libreoffice-impress-nogui ffmpeg fonts-dejavu-core  && rm -rf /var/lib/apt/lists/*
USER www-data

