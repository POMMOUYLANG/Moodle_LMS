FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git unzip libpng-dev libjpeg-dev libfreetype6-dev libxml2-dev libzip-dev \
    libicu-dev g++ libxslt1-dev libwebp-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd mysqli pdo pdo_mysql zip intl opcache soap xsl exif \
    && rm -rf /var/lib/apt/lists/*

# Enable recommended PHP settings for Moodle
RUN echo "zend.exception_ignore_args=1" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "max_input_vars=5000" >> /usr/local/etc/php/conf.d/moodle.ini

# Set working directory
WORKDIR /var/www/html

# Fix permissions for Moodle
RUN chown -R www-data:www-data /var/www/html
