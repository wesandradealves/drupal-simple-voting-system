ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm-trixie

ARG APP_UID=1000
ARG APP_GID=1000
ARG SASS_VERSION=1.103.1

RUN set -eux; \
    groupmod -o -g "${APP_GID}" www-data; \
    usermod -o -u "${APP_UID}" -g "${APP_GID}" www-data; \
    mkdir -p /var/www/html /usr/local/var/log /usr/local/var/run /tmp/composer; \
    chown -R www-data:www-data /var/www /usr/local/var/log /usr/local/var/run /tmp/composer

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        default-mysql-client \
        git \
        libavif-dev \
        libfreetype-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
    ; \
    docker-php-ext-configure gd \
        --with-avif \
        --with-freetype \
        --with-jpeg=/usr \
        --with-webp \
    ; \
    docker-php-ext-install -j"$(nproc)" gd pdo_mysql zip; \
    pecl install xdebug-3.5.3; \
    docker-php-ext-enable xdebug; \
    pecl clear-cache; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

RUN set -eux; \
    curl -fsSL -o /tmp/dart-sass.tar.gz \
        "https://github.com/sass/dart-sass/releases/download/${SASS_VERSION}/dart-sass-${SASS_VERSION}-linux-x64.tar.gz"; \
    mkdir -p /opt; \
    tar -xzf /tmp/dart-sass.tar.gz -C /opt; \
    rm /tmp/dart-sass.tar.gz; \
    ln -s /opt/dart-sass/sass /usr/local/bin/sass; \
    sass --version

COPY --from=composer/composer:2.10.2-bin /composer /usr/local/bin/composer
COPY --from=axllent/mailpit:v1.31.0 /mailpit /usr/local/bin/mailpit

COPY conf.d/ /usr/local/etc/php/conf.d/

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /var/www/html
