FROM php:8.3-fpm

# Install required packages
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev \
    libxml2-dev libzip-dev zip unzip git \
    libicu-dev g++ mariadb-client curl && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install gd intl mysqli pdo pdo_mysql zip opcache && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer (optional but useful)
RUN curl -sS https://getcomposer.org/installer | php && \
    mv composer.phar /usr/local/bin/composer

# Set working directory
WORKDIR /var/www/html/moodle

# Copy Moodle source code
COPY moodle/ /var/www/html/moodle/

# Copy PHP configuration
COPY php-config/moodle.ini /usr/local/etc/php/conf.d/moodle.ini

# Create Moodle data directory and fix permissions
RUN mkdir -p /var/www/moodledata && \
    chown -R www-data:www-data /var/www/html/moodle /var/www/moodledata && \
    chmod -R 755 /var/www/html/moodle /var/www/moodledata

EXPOSE 9000

CMD ["php-fpm"]
