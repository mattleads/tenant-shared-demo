# The baseline: php-fpm behind nginx, a fresh kernel per request.
FROM php:8.4-fpm-alpine

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql redis intl opcache zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/app.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/fpm/www.conf /usr/local/etc/php-fpm.d/zz-app.conf

ENV APP_ENV=prod APP_DEBUG=0
WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && bin/console cache:warmup --no-interaction \
 && chown -R www-data:www-data var
