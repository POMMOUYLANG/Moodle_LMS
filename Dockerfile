FROM php:8.3-fpm

# Install system dependencies and PHP extensions required by Moodle
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y \
    git unzip zip libpng-dev libjpeg-dev libfreetype6-dev \
    libxml2-dev libzip-dev libicu-dev g++ default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd intl mysqli pdo pdo_mysql zip opcache soap xmlrpc \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy Composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create moodledata directory with correct permissions
RUN mkdir -p /var/moodledata && chown -R www-data:www-data /var/moodledata

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
