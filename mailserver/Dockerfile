FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    git \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql pcntl sockets \
    && rm -rf /var/lib/apt/lists/*

# Configure PHP
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory-limit.ini && \
    echo "upload_max_filesize = 10M" >> /usr/local/etc/php/conf.d/memory-limit.ini && \
    echo "post_max_size = 10M" >> /usr/local/etc/php/conf.d/memory-limit.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first
COPY composer.json ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy application files
COPY src/ ./

# Copy DKIM keys
COPY dkim/ /app/dkim/

# Copy SSL certificates
COPY ssl/ /app/ssl/

# Expose ports
EXPOSE 25 143 587 993

# Run the mail server
CMD ["php", "server.php", "start"]
