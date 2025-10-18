FROM php:8.3-fpm

# Install required PHP and system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev \
    libxml2-dev libzip-dev zip unzip git \
    libicu-dev g++ mariadb-client && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install gd intl mysqli pdo pdo_mysql zip opcache && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Set working directory to Moodle root
WORKDIR /var/www/html

# Copy Moodle source (compose mounts volume, but needed for permissions)
COPY ./moodle /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
