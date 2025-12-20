FROM php:8.2-apache

# Install system dependencies and Composer
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql \
    && a2enmod rewrite \
    && a2enmod headers \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files and install dependencies
COPY composer.json /var/www/html/
RUN composer install --no-dev --optimize-autoloader

# Copy source files (this won't overwrite vendor/)
COPY src/ /var/www/html/src/
COPY index.php /var/www/html/
COPY api.php /var/www/html/
COPY .htaccess /var/www/html/
COPY assets/ /var/www/html/assets/

# Copy Apache configuration
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf

# Copy PHP configuration
RUN echo "upload_max_filesize = 10M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 10M" >> /usr/local/etc/php/conf.d/uploads.ini

# Expose port
EXPOSE 80

CMD ["apache2-foreground"]
