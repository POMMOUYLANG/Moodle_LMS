FROM php:8.3-fpm

# Install required dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev libxml2-dev libzip-dev zip unzip git \
    libicu-dev g++ mariadb-client nginx && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install gd intl mysqli pdo pdo_mysql zip opcache && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy Moodle code (mounted in docker-compose)
WORKDIR /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
