FROM php:8.3-apache

# Install required packages and PHP extensions for Moodle
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev \
    libxml2-dev libzip-dev zip unzip git \
    libicu-dev g++ mariadb-client curl && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install gd intl mysqli pdo pdo_mysql zip opcache && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite ssl headers

# Set the document root to Moodle
ENV APACHE_DOCUMENT_ROOT /var/www/html

# Configure Apache to use the new document root
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Copy PHP configuration (optional)
COPY php-config/moodle.ini /usr/local/etc/php/conf.d/moodle.ini
COPY docker/start-moodle.sh /usr/local/bin/start-moodle.sh
RUN chmod +x /usr/local/bin/start-moodle.sh

# Fix permissions for Moodle.
RUN mkdir -p /var/moodledata && \
    chown -R www-data:www-data /var/www/html /var/moodledata && \
    chmod -R 755 /var/www/html /var/moodledata

EXPOSE 80
CMD ["/usr/local/bin/start-moodle.sh"]
