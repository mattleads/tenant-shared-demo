# FrankenPHP serves the same code in classic mode or in worker mode: only FRANKENPHP_CONFIG changes.
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_pgsql redis intl opcache zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/app.ini /usr/local/etc/php/conf.d/app.ini

ENV APP_ENV=prod APP_DEBUG=0 SERVER_NAME=:80 SERVER_ROOT=/app/public
WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && bin/console cache:warmup --no-interaction \
 && chown -R www-data:www-data var
