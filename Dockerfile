FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    libicu-dev \
    g++ \
    mariadb-client \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install intl \
    && docker-php-ext-install mysqli \
    && docker-php-ext-install pdo \
    && docker-php-ext-install pdo_mysql \
    && docker-php-ext-install zip \
    && docker-php-ext-install opcache

# Set working directory
WORKDIR /var/www/html/moodle

# Copy Moodle code
COPY moodle/ /var/www/html/moodle/

# Copy PHP configuration
COPY php-config/moodle.ini /usr/local/etc/php/conf.d/moodle.ini

# Create Moodle data directory and fix permissions
RUN mkdir -p /var/www/moodledata && \
    chown -R www-data:www-data /var/www/html/moodle /var/www/moodledata && \
    chmod -R 755 /var/www/html/moodle /var/www/moodledata

# Expose PHP-FPM port
EXPOSE 9000

# Run PHP-FPM
CMD ["php-fpm"]