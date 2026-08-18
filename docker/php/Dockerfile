FROM php:8.2-fpm-alpine

# git and unzip are what Composer needs to install packages from source.
RUN apk add --no-cache git unzip \
    && docker-php-ext-install pdo_mysql opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# The source is bind-mounted from the host instead of copied in: this image is
# the development environment, not a deployable artefact. A production image
# would copy the code, install without dev dependencies and drop Composer.
