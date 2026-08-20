# ---------------------------------------------------------------------------
# imel.id mail server — Workerman SMTP/IMAP + outbound relay worker.
# Build context is the repository root so shared/ can be copied in.
# ---------------------------------------------------------------------------
FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libonig-dev git unzip \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_pgsql pcntl sockets mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
        echo 'memory_limit = 512M'; \
        echo 'date.timezone = Asia/Jakarta'; \
    } > /usr/local/etc/php/conf.d/imel.ini

WORKDIR /app/mailserver

COPY mailserver/composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY shared/ /app/shared/
COPY mailserver/src/ ./src/

RUN mkdir -p /storage

# 25 inbound SMTP, 587 submission, 143 IMAP
EXPOSE 25 143 587

CMD ["php", "src/server.php", "start"]
