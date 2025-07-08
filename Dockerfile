FROM php:8.3-cli

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    COMPOSER_NO_INTERACTION=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /app/var/cache /app/var/log /tmp/phpstan/cache \
    && chmod -R 777 /app/var /tmp/phpstan

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./

RUN composer update --prefer-dist

COPY . .

RUN rm -f .env.dist

RUN composer dump-autoload --optimize --classmap-authoritative \
    && composer phpstan \
    && composer cs-check \
    && chown -R www-data:www-data /app/var /tmp/phpstan

USER www-data

CMD ["php", "bin/console"]