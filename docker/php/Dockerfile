FROM composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS composer
FROM php:8.4-fpm-alpine@sha256:a49524d3cec4a77d1807d0878e5bb38146116ae5489ec60c367aee53bb9b0699

RUN apk add --no-cache icu-libs libpq libzip fcgi \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev \
    && docker-php-ext-install -j1 pdo_pgsql intl zip bcmath opcache \
    && apk del .build-deps

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-jakawi-club.ini
COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zzz-jakawi-club.conf

WORKDIR /var/www/html
CMD ["php-fpm"]
