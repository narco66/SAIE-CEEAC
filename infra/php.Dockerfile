# Image applicative Laravel 13 / PHP 8.4 (TECH-01 : le build échoue si une extension obligatoire manque).
FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libpng-dev libjpeg-dev libwebp-dev unzip git \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip gd opcache bcmath \
 && pecl install redis && docker-php-ext-enable redis \
 && php -r 'foreach (["pdo_pgsql","mbstring","openssl","intl","fileinfo","zip","gd"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "Extension manquante: $e\n"); exit(1); } }' \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev && chown -R www-data:www-data storage bootstrap/cache
USER www-data
