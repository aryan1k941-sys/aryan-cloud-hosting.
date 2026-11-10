FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends unzip libzip-dev \
    && docker-php-ext-install pdo_sqlite zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY index.php /var/www/html/index.php
RUN mkdir -p /var/www/html/data /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/data /var/www/html/uploads \
    && chmod -R 775 /var/www/html/data /var/www/html/uploads
EXPOSE 80
