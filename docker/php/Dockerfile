FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libpq-dev \
        libsqlite3-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_sqlite pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN groupadd --gid 1000 app \
    && useradd --uid 1000 --gid app --create-home --shell /bin/bash app

WORKDIR /var/www/html

USER app

EXPOSE 8000
