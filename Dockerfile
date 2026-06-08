FROM php:8.2-apache

# Build cache invalidator - change this to force fresh build
ARG BUILD_DATE=2026-06-08-03
RUN echo "Build date: $BUILD_DATE" > /build-date.txt

# Enable mod_rewrite
RUN a2enmod rewrite

# Install dependencies for SQLite + Composer
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PDO SQLite
RUN docker-php-ext-install pdo pdo_sqlite

# Set document root
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Enable .htaccess
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copy app - cache invalidated by BUILD_DATE
COPY . /var/www/html/
RUN echo "Build date: $BUILD_DATE" >> /var/www/html/build-info.txt

# Install PHP dependencies
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Create data directory for SQLite
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data

EXPOSE 80
