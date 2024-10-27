FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
      git \
      curl \
      libzip-dev \
    && rm -rf /var/lib/apt/lists/*

RUN \
    docker-php-ext-install zip sockets && \
    pecl install redis apcu && \
    docker-php-ext-enable redis apcu

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
ENV APP_ENV=prod

COPY bin/ /app/bin
COPY config/ /app/config
COPY public/ /app/public
COPY src/ /app/src
COPY templates/ /app/templates
COPY composer.* /app/
COPY symfony.lock /app/
COPY .env /app/

RUN cd /app && ls /app/ && composer install --prefer-dist --no-dev
