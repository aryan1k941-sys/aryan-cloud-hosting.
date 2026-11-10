
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip \
    libzip-dev \
    libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
