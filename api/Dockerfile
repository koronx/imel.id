# ---------------------------------------------------------------------------
# imel.id REST API — PHP 8.2 + Apache. Build context is the repository root
# so the shared library can be copied alongside the application.
# ---------------------------------------------------------------------------
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_pgsql mbstring opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Apache serves the front controller from api/public
ENV APACHE_DOCUMENT_ROOT=/var/www/app/api/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/imel.conf \
    && a2enconf imel

RUN { \
        echo 'upload_max_filesize = 25M'; \
        echo 'post_max_size = 60M'; \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 60'; \
        echo 'expose_php = Off'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.validate_timestamps = 1'; \
        echo 'opcache.revalidate_freq = 2'; \
    } > /usr/local/etc/php/conf.d/imel.ini

WORKDIR /var/www/app
COPY shared/ ./shared/
COPY api/ ./api/

RUN mkdir -p /storage && chown -R www-data:www-data /var/www/app /storage

EXPOSE 80

HEALTHCHECK --interval=20s --timeout=5s --start-period=30s --retries=5 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/api/health") ? 0 : 1);'
