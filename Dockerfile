FROM php:8.3-fpm

# Install system dependencies in separate layers
RUN apt-get update && apt-get install -y --no-install-recommends \
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
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configure GD extension
RUN docker-php-ext-configure gd --with-freetype --with-jpeg

# Install PHP extensions
RUN docker-php-ext-install \
    gd \
    intl \
    mysqli \
    pdo \
    pdo_mysql \
    zip \
    opcache

WORKDIR /var/www/html/moodle

# Copy application files
COPY moodle/ ./

# Copy PHP configuration
COPY php-config/moodle.ini /usr/local/etc/php/conf.d/

# Create and set permissions for moodledata
RUN mkdir -p /var/www/moodledata \
    && chown -R www-data:www-data /var/www/html/moodle /var/www/moodledata \
    && chmod -R 755 /var/www/html/moodle /var/www/moodledata

EXPOSE 9000

CMD ["php-fpm"]