FROM php:8.2-apache

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

# Copy app
COPY . /var/www/html/

# Install PHP dependencies
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Create data directory for SQLite
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data

EXPOSE 80
